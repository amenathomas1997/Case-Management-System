<?php
declare(strict_types=1);
// Keeping clients and counselors informed (3.0.40): portal links that stay open while they
// are used and warn before they lapse, short "you have a new message" notices, and a
// notice when a portal request is closed. Notices carry a link and no health details.
// The website, the app's API and the daily job all use these.

/** Days a portal link stays open after it was last used (clients and counselors). */
const PORTAL_LINK_DAYS = 180;
/** Days before a link lapses that its holder and staff are warned. */
const PORTAL_LINK_WARN_DAYS = 14;

/**
 * Email a client a new portal link (replacing any earlier one once it has gone).
 * Returns ['error' => ?string, 'sent_to' => string]. The same checks as client.php.
 */
function portal_link_send(int $clientId, ?int $actor, string $how = ''): array {
    $c = db()->prepare('SELECT * FROM clients WHERE id = ?');
    $c->execute([$clientId]);
    $client = $c->fetch();
    if (!$client) return ['error' => 'Client not found.', 'sent_to' => ''];
    if ($client['archived_at'] !== null) return ['error' => 'This client\'s record is archived, so a portal link would not open. Restore the record first.', 'sent_to' => ''];
    if (!filter_var($client['email'] ?? '', FILTER_VALIDATE_EMAIL)) return ['error' => 'Add an email address to the client profile first.', 'sent_to' => ''];
    if ((int)$client['email_verified'] !== 1) {
        return ['error' => 'The portal link was not sent: ' . $client['email'] . ' came from the public referral form and has not been confirmed with the client. Confirm it on the Edit profile page (tick Confirmed with the client), then send the link.', 'sent_to' => ''];
    }
    $token = bin2hex(random_bytes(24));
    [$subj, $body] = email_template_for_client('portal_link', $client, ['name' => $client['name'], 'link' => cfg('base_url') . '/portal.php?t=' . $token]);
    if (!send_app_mail($client['email'], $subj, $body)) {
        return ['error' => 'The portal email could not be sent, so nothing was changed — any link the client already has still works. Please try again later.', 'sent_to' => ''];
    }
    try {
        db()->prepare('INSERT INTO portal_tokens (client_id, token, expires_at) VALUES (?,?, DATE_ADD(NOW(), INTERVAL ' . PORTAL_LINK_DAYS . ' DAY))
            ON DUPLICATE KEY UPDATE token = VALUES(token), created_at = NOW(), expires_at = DATE_ADD(NOW(), INTERVAL ' . PORTAL_LINK_DAYS . ' DAY), expiry_warned_at = NULL')
            ->execute([$clientId, $token]);
    } catch (Throwable $e) {
        error_log('Portal link store failed: ' . get_class($e) . ' (code ' . $e->getCode() . ')');
        audit($actor, 'portal.send_failed', 'client', $clientId, 'emailed, but the link could not be activated');
        return ['error' => 'The portal email went to ' . $client['email'] . ', but its link could not be activated, so it will not open. Send a new portal link; any link sent before this one still works.', 'sent_to' => $client['email']];
    }
    audit($actor, 'portal.send', 'client', $clientId, $how !== '' ? $how : null);
    return ['error' => null, 'sent_to' => (string)$client['email']];
}

/** A portal link was just used: it stays open for another PORTAL_LINK_DAYS. */
function portal_link_touch(string $table, string $where, $value): void {
    if (!in_array($table, ['portal_tokens', 'vrc_tokens'], true) || !in_array($where, ['token', 'id'], true)) return;
    try {
        db()->prepare("UPDATE `$table` SET last_used_at = NOW(), expiry_warned_at = NULL,
            expires_at = GREATEST(COALESCE(expires_at, NOW()), DATE_ADD(NOW(), INTERVAL " . PORTAL_LINK_DAYS . " DAY)) WHERE `$where` = ?")->execute([$value]);
    } catch (PDOException $e) {
        // mig_3.0.40 not run yet: record the use as before.
        db()->prepare("UPDATE `$table` SET last_used_at = NOW() WHERE `$where` = ?")->execute([$value]);
    }
}

/**
 * "Email me a new link" on the dead-link page: sends only to the client's own confirmed
 * address on file, and the answer never says whether the address is known. Limited to
 * three tries an hour from one internet address.
 */
function portal_link_self_service(string $email): void {
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $rl = db()->prepare("SELECT COUNT(*) FROM login_attempts WHERE email = 'portal-newlink' AND ip = ? AND attempted_at > DATE_SUB(NOW(), INTERVAL 60 MINUTE)");
    $rl->execute([$ip]);
    if ((int)$rl->fetchColumn() >= 3) return;
    db()->prepare("INSERT INTO login_attempts (ip, email, success) VALUES (?, 'portal-newlink', 1)")->execute([$ip]);
    $email = strtolower(trim($email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return;
    // Only someone who was given a portal before, with exactly one matching record.
    $q = db()->prepare('SELECT c.id FROM clients c JOIN portal_tokens p ON p.client_id = c.id
        WHERE LOWER(c.email) = ? AND c.email_verified = 1 AND c.archived_at IS NULL');
    $q->execute([$email]);
    $ids = $q->fetchAll(PDO::FETCH_COLUMN);
    if (count($ids) !== 1) return;
    portal_link_send((int)$ids[0], null, 'requested by the client from the expired-link page');
}

/**
 * Tell a client or a counselor that staff sent them a message: a short email with the
 * portal link (no message text), at most once a day per conversation.
 * $channel 'client' or 'counselor'. Returns true when an email went.
 */
function message_notice(int $clientId, string $channel): bool {
    try {
        $c = db()->prepare('SELECT c.*, p.token AS portal_token FROM clients c LEFT JOIN portal_tokens p ON p.client_id = c.id
            AND (p.expires_at IS NULL OR p.expires_at > NOW()) WHERE c.id = ?');
        $c->execute([$clientId]);
        $client = $c->fetch();
        if (!$client) return false;
        $key = 'msgnotice-' . $channel . '-' . $clientId;
        $seen = db()->prepare("SELECT COUNT(*) FROM login_attempts WHERE email = ? AND attempted_at > DATE_SUB(NOW(), INTERVAL 1 DAY)");
        $seen->execute([$key]);
        if ((int)$seen->fetchColumn() > 0) return false;
        if ($channel === 'client') {
            if (!$client['portal_token'] || (int)$client['email_verified'] !== 1 || !filter_var($client['email'] ?? '', FILTER_VALIDATE_EMAIL)) return false;
            [$subj, $body] = email_template_for_client('message_notice', $client, ['name' => $client['name'],
                'link' => cfg('base_url') . '/portal.php?t=' . $client['portal_token'] . (client_lang($client) === 'es' ? '&lang=es' : '')]);
            $to = (string)$client['email'];
        } else {
            if ((int)($client['counselor_verified'] ?? 0) !== 1 || !filter_var($client['counselor_email'] ?? '', FILTER_VALIDATE_EMAIL)) return false;
            $t = db()->prepare('SELECT token FROM vrc_tokens WHERE counselor_email = ? AND (expires_at IS NULL OR expires_at > NOW())');
            $t->execute([strtolower((string)$client['counselor_email'])]);
            $vt = $t->fetchColumn();
            if (!$vt) return false;
            [$subj, $body] = email_template('message_notice', ['name' => (string)$client['counselor_name'], 'link' => cfg('base_url') . '/vrc.php?t=' . $vt]);
            $to = (string)$client['counselor_email'];
        }
        if (!send_app_mail($to, $subj, $body)) return false;
        db()->prepare("INSERT INTO login_attempts (ip, email, success) VALUES ('', ?, 1)")->execute([$key]);
        return true;
    } catch (Throwable $e) {
        error_log('message_notice: ' . get_class($e) . ' ' . $e->getCode());
        return false; // a notice never stops the message itself
    }
}

/** Email a client that their portal request was answered (the outcome stays in the portal). */
function request_closed_notice(int $requestId): bool {
    try {
        $q = db()->prepare('SELECT pr.status, c.*, p.token AS portal_token FROM portal_requests pr JOIN clients c ON c.id = pr.client_id
            LEFT JOIN portal_tokens p ON p.client_id = c.id AND (p.expires_at IS NULL OR p.expires_at > NOW()) WHERE pr.id = ?');
        $q->execute([$requestId]);
        $r = $q->fetch();
        if (!$r || !$r['portal_token'] || (int)$r['email_verified'] !== 1 || !filter_var($r['email'] ?? '', FILTER_VALIDATE_EMAIL)) return false;
        [$subj, $body] = email_template_for_client('request_closed', $r, ['name' => $r['name'],
            'link' => cfg('base_url') . '/portal.php?t=' . $r['portal_token'] . (client_lang($r) === 'es' ? '&lang=es' : '') . '#req-h']);
        return send_app_mail((string)$r['email'], $subj, $body);
    } catch (Throwable $e) {
        error_log('request_closed_notice: ' . get_class($e) . ' ' . $e->getCode());
        return false;
    }
}
