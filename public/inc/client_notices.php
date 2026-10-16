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

/**
 * A session was marked No Show: email the client's counselor (owner decision 2026-10-16:
 * the only event counselors are emailed about). Only a confirmed counselor with a working
 * portal link, once per session; the email gives the date and the portal link, and the
 * client is named only behind the portal's sign-in.
 */
function counselor_noshow_notice(int $sessionId): bool {
    try {
        $q = db()->prepare('SELECT s.start_at, c.counselor_name, c.counselor_email, c.counselor_verified FROM scheduled_sessions s
            JOIN clients c ON c.id = s.client_id WHERE s.id = ? AND s.status = \'No Show\'');
        $q->execute([$sessionId]);
        $s = $q->fetch();
        if (!$s || (int)$s['counselor_verified'] !== 1 || !filter_var($s['counselor_email'] ?? '', FILTER_VALIDATE_EMAIL)) return false;
        $t = db()->prepare('SELECT token FROM vrc_tokens WHERE counselor_email = ? AND (expires_at IS NULL OR expires_at > NOW())');
        $t->execute([strtolower((string)$s['counselor_email'])]);
        $token = $t->fetchColumn();
        if (!$token) return false;
        $key = 'noshow-' . $sessionId;
        $seen = db()->prepare('SELECT COUNT(*) FROM login_attempts WHERE email = ?');
        $seen->execute([$key]);
        if ((int)$seen->fetchColumn() > 0) return false;
        [$subj, $body] = email_template('counselor_noshow', ['name' => (string)$s['counselor_name'],
            'date' => date('l, F j, Y', strtotime((string)$s['start_at'])), 'link' => cfg('base_url') . '/vrc.php?t=' . $token]);
        if (!send_app_mail((string)$s['counselor_email'], $subj, $body)) return false;
        db()->prepare("INSERT INTO login_attempts (ip, email, success) VALUES ('', ?, 1)")->execute([$key]);
        return true;
    } catch (Throwable $e) {
        error_log('counselor_noshow_notice: ' . get_class($e) . ' ' . $e->getCode());
        return false;
    }
}

/**
 * Email the client their session(s) when booked ('booked', a calendar invite) or cancelled
 * ('cancelled', a calendar cancellation that removes it), 3.0.41. One email per action for one
 * client; each session has its own calendar ID, so a later cancellation finds it. Only a
 * current client with a confirmed email who has not asked for phone or post is emailed.
 * Returns true when an email went.
 */
function client_session_notice(array $sessionIds, string $kind): bool {
    $sessionIds = array_values(array_unique(array_map('intval', $sessionIds)));
    if (!$sessionIds || !in_array($kind, ['booked', 'cancelled', 'moved'], true)) return false;
    try {
        $q = db()->prepare('SELECT s.*, c.name AS client_name, c.email, c.email_verified, c.a11y_contact, c.preferred_language, c.archived_at, c.status AS client_status
            FROM scheduled_sessions s JOIN clients c ON c.id = s.client_id WHERE s.id IN (' . implode(',', array_fill(0, count($sessionIds), '?')) . ') ORDER BY s.start_at');
        $q->execute($sessionIds);
        $rows = $q->fetchAll();
        if (!$rows || count(array_unique(array_column($rows, 'client_id'))) !== 1) return false;
        $c = $rows[0];
        if ($c['archived_at'] !== null || str_starts_with((string)$c['client_status'], 'Closed') || (int)$c['email_verified'] !== 1
            || !filter_var($c['email'] ?? '', FILTER_VALIDATE_EMAIL) || in_array($c['a11y_contact'] ?? '', ['Phone', 'Postal mail'], true)) return false;
        $es = client_lang($c) === 'es';
        $method = $kind === 'cancelled' ? 'CANCEL' : 'REQUEST';
        [$ics, $lines] = client_sessions_ics($rows, (string)$c['email'], $method, $es);
        $contact = trim((string)(cfg('support_email') ?: cfg('mail_from')));
        [$subj, $body] = email_template_for_client(['booked' => 'session_booked_client', 'cancelled' => 'session_cancelled_client', 'moved' => 'session_moved_client'][$kind], $c,
            ['name' => (string)$c['client_name'], 'sessions' => implode("\n", $lines), 'contact' => $contact]);
        return send_app_mail_attach((string)$c['email'], $subj, $body,
            [['name' => $kind === 'cancelled' ? 'cancel.ics' : 'invite.ics', 'mime' => 'text/calendar; method=' . $method, 'bytes' => $ics]]);
    } catch (Throwable $e) {
        error_log('client_session_notice: ' . get_class($e) . ' ' . $e->getCode());
        return false;
    }
}

/**
 * An authorization is nearly used up (80% of its hours or funds): email the client's
 * counselor (owner decision 2026-10-16). As with a no-show: only a confirmed counselor with
 * a working portal link, the authorization number and the portal link, no client name.
 */
function counselor_auth_notice(int $trackId): bool {
    try {
        $q = db()->prepare('SELECT t.auth_number, c.counselor_name, c.counselor_email, c.counselor_verified FROM training_tracks t
            JOIN clients c ON c.id = t.client_id WHERE t.id = ? AND COALESCE(t.billing_mode, \'vr\') = \'vr\'');
        $q->execute([$trackId]);
        $t = $q->fetch();
        if (!$t || (int)$t['counselor_verified'] !== 1 || !filter_var($t['counselor_email'] ?? '', FILTER_VALIDATE_EMAIL)) return false;
        $v = db()->prepare('SELECT token FROM vrc_tokens WHERE counselor_email = ? AND (expires_at IS NULL OR expires_at > NOW())');
        $v->execute([strtolower((string)$t['counselor_email'])]);
        $token = $v->fetchColumn();
        if (!$token) return false;
        $r = track_rollup($trackId);
        $used = $r['authorized_hours'] > 0 ? fmt_hours($r['hours_worked']) . ' of ' . fmt_hours($r['authorized_hours']) . ' used'
            : fmt_money($r['authorized_funds'] - $r['funds_remaining']) . ' of ' . fmt_money($r['authorized_funds']) . ' used';
        [$subj, $body] = email_template('counselor_auth_low', ['name' => (string)$t['counselor_name'], 'auth' => (string)$t['auth_number'],
            'used' => $used, 'link' => cfg('base_url') . '/vrc.php?t=' . $token]);
        return send_app_mail((string)$t['counselor_email'], $subj, $body);
    } catch (Throwable $e) {
        error_log('counselor_auth_notice: ' . get_class($e) . ' ' . $e->getCode());
        return false;
    }
}

/** The calendar file for a client's sessions (one event per session, its own ID, so a later
 *  cancellation finds it) and one plain line per session. $method REQUEST, CANCEL or PUBLISH
 *  (a download from the portal). Returns [ics, lines]. */
function client_sessions_ics(array $rows, string $attendee, string $method, bool $es): array {
    $host = parse_url((string)cfg('base_url'), PHP_URL_HOST) ?: 'localhost';
    $fmt = fn(int $ts) => gmdate('Ymd\THis\Z', $ts);
    $esc = fn(string $s) => addcslashes(preg_replace('/[\r\n]+/', ' ', $s), ",;\\");
    $ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//CaseManagement//CMS//EN\r\nMETHOD:$method\r\n";
    $lines = [];
    foreach ($rows as $s) {
        $st = strtotime((string)$s['start_at']); $en = strtotime((string)$s['end_at']);
        $remote = !empty($s['is_remote']);
        $url = $remote && preg_match('~^https?://\S+$~i', trim((string)$s['meeting_url'])) ? trim((string)$s['meeting_url']) : '';
        $where = $remote ? ($es ? 'en línea' : 'online') . ($url !== '' ? ' (' . $url . ')' : '') : trim((string)$s['location']);
        $lines[] = '- ' . ($es ? when_es($st) : date('l, F j \a\t g:i A T', $st)) . ($where !== '' ? ', ' . $where : '');
        $ics .= "BEGIN:VEVENT\r\nUID:cms-client-session-" . (int)$s['id'] . '@' . $host . "\r\nDTSTAMP:" . $fmt(time()) . "\r\n"
            // SEQUENCE grows with each move (ics_sequence, 3.0.43), so a calendar takes the newest.
        . 'SEQUENCE:' . ((int)($s['ics_sequence'] ?? 0) + ($method === 'CANCEL' ? 1 : 0)) . "\r\nDTSTART:" . $fmt($st) . "\r\nDTEND:" . $fmt($en) . "\r\n"
            . 'SUMMARY:' . $esc(($es ? 'Capacitación con ' : 'Training with ') . brand('short')) . "\r\n"
            . ($where !== '' ? 'LOCATION:' . $esc($remote && $url !== '' ? $url : $where) . "\r\n" : '')
            . ($url !== '' ? 'URL:' . preg_replace('/[\r\n]+/', '', $url) . "\r\n" : '')
            . 'ORGANIZER;CN="' . str_replace('"', '', brand('short')) . '":mailto:' . $esc((string)cfg('mail_from')) . "\r\n"
            . 'ATTENDEE;ROLE=REQ-PARTICIPANT:mailto:' . $esc($attendee) . "\r\n"
            . 'STATUS:' . ($method === 'CANCEL' ? 'CANCELLED' : 'CONFIRMED') . "\r\nEND:VEVENT\r\n";
    }
    $ics .= "END:VCALENDAR\r\n";
    return [$ics, $lines];
}
