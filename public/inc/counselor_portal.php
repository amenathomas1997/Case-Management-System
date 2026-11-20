<?php
declare(strict_types=1);
// The VR counselor portal link (4.38.0): moved from counselors.php, so the page and the apps'
// send_vrc_portal, revoke_vrc_portal and counselors send the same email, keep the same 60-second
// guard, say the same sentences and audit the same way, a failure after sending included.

/** The portal email: subject and plain-text body with the counselor's private link. */
function vrc_portal_email(string $name, string $token): array {
    return ['subject' => 'Your ' . brand('short') . ' counselor portal',
        'body' => "Hello {$name},\n\nYou can now view your clients' progress with " . brand('org') . " — authorizations, hours used, and goal status — at your private portal:\n\n"
            . cfg('base_url') . '/vrc.php?t=' . $token
            . "\n\nEach new browser session asks for a 6-digit code emailed to you, to protect client information. Keep this link private; if it's ever lost, we can send a new one (which replaces this one).\n"];
}

/**
 * Email a counselor a new portal link and store it once it has gone out (storing it replaces, and so
 * revokes, the link before it). A link is never sent to an address that is only on clients whose
 * counselor email is unconfirmed, nor twice within a minute. Returns ok, the HTTP status the API
 * answers with, the flash kind, the sentence, the field it belongs to (email) and, once sent, when
 * the new link expires. $auditTail ends the audit detail (" (mobile)" from the apps).
 */
function vrc_portal_send(string $email, string $name, array $user, string $auditTail = ''): array {
    $email = strtolower(trim($email));
    $name = trim($name);
    $uid = (int)$user['id'];
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return ['ok' => false, 'status' => 422, 'kind' => 'error', 'message' => 'A valid counselor email is required.', 'field' => 'email'];
    // A counselor email that came in through the public referral form is unconfirmed
    // (counselor_verified = 0) until staff confirm it on the client's edit page: a link
    // is never sent to an address that is only on unconfirmed clients.
    $ver = db()->prepare('SELECT COUNT(*) AS n, COALESCE(SUM(counselor_verified = 1), 0) AS ok FROM clients WHERE counselor_email = ? AND archived_at IS NULL');
    $ver->execute([$email]);
    $ver = $ver->fetch();
    if ((int)$ver['n'] > 0 && (int)$ver['ok'] === 0) {
        return ['ok' => false, 'status' => 422, 'kind' => 'error', 'field' => 'email',
            'message' => 'No link was sent: ' . $email . ' has not been confirmed as the counselor on any client. Confirm the counselor on the client\'s edit page first.'];
    }
    if ($name === '') {
        // Counselors can be recorded on a client with an email but no name: keep the
        // name an earlier link was sent under, else greet them as "Counselor".
        $kn = db()->prepare('SELECT counselor_name FROM vrc_tokens WHERE counselor_email = ?');
        $kn->execute([$email]);
        $name = trim((string)$kn->fetchColumn()) ?: 'Counselor';
    }
    // Each send replaces (revokes) the link before it, so a second send within a minute
    // (a double press, or a resubmit after a slow email) would kill the link just
    // emailed. The website and the apps share the guard and its key (it ends in digits,
    // so audit.php hides it from the sign-in attempts table).
    $portalKey = 'vrc-portal-' . sprintf('%u', crc32($email));
    $recent = db()->prepare('SELECT COUNT(*) FROM login_attempts WHERE email = ? AND attempted_at > DATE_SUB(NOW(), INTERVAL 60 SECOND)');
    $recent->execute([$portalKey]);
    if ((int)$recent->fetchColumn() >= 1) {
        return ['ok' => false, 'status' => 429, 'kind' => 'error',
            'message' => 'A portal link was just sent to ' . $name . ' (' . $email . '); the link in that email is the one that works. Wait a minute before sending another.'];
    }
    db()->prepare('INSERT INTO login_attempts (ip, email, success) VALUES (?,?,1)')->execute([client_ip(), $portalKey]);
    $token = bin2hex(random_bytes(24));
    // Email first and store the new link only once it has gone out.
    $mail = vrc_portal_email($name, $token);
    if (!send_app_mail($email, $mail['subject'], $mail['body'])) {
        // Nothing went out, so an immediate retry is allowed.
        db()->prepare('DELETE FROM login_attempts WHERE email = ?')->execute([$portalKey]);
        return ['ok' => false, 'status' => 500, 'kind' => 'error',
            'message' => "The email to {$name} could not be sent, so nothing was changed: their current portal link, if they have one, still works. Try again later."];
    }
    try {
        db()->prepare('INSERT INTO vrc_tokens (counselor_email, counselor_name, token, expires_at) VALUES (?,?,?, DATE_ADD(NOW(), INTERVAL ' . cms_number('counselor_link_days') . ' DAY))
            ON DUPLICATE KEY UPDATE token = VALUES(token), counselor_name = VALUES(counselor_name), created_at = NOW(), expires_at = DATE_ADD(NOW(), INTERVAL ' . cms_number('counselor_link_days') . ' DAY), last_used_at = NULL')
            ->execute([$email, $name, $token]);
    } catch (Throwable $e) {
        // The email has gone, so this is not "nothing was changed": say exactly what happened.
        error_log('Counselor portal link store failed: ' . get_class($e) . ' (code ' . $e->getCode() . ')');
        // The emailed link does not open, so a new one may be sent at once.
        try { db()->prepare('DELETE FROM login_attempts WHERE email = ?')->execute([$portalKey]); } catch (Throwable $ignored) {}
        audit($uid, 'vrcportal.send_failed', 'counselor', null, $email . ': emailed, but the link could not be activated' . $auditTail);
        return ['ok' => false, 'status' => 500, 'kind' => 'error',
            'message' => "The portal email went to {$name} ({$email}), but its link could not be activated, so it will not open. Send a new link; any link sent before this one still works."];
    }
    audit($uid, 'vrcportal.send', 'counselor', null, $email . $auditTail);
    $ex = db()->prepare('SELECT expires_at FROM vrc_tokens WHERE counselor_email = ?');
    $ex->execute([$email]);
    $expires = $ex->fetchColumn();
    return ['ok' => true, 'status' => 200, 'kind' => 'success', 'message' => "Portal link emailed to {$name}. Any previous link no longer works.",
        'expires_at' => $expires !== false && $expires !== null ? (string)$expires : null];
}

/** Turn off a counselor's portal link (they have left, or the link was shared). vrc.php looks the link
 *  up on every request, so even an open portal session ends now. */
function vrc_portal_revoke(string $email, array $user, string $auditTail = ''): array {
    $email = strtolower(trim($email));
    $del = db()->prepare('DELETE FROM vrc_tokens WHERE counselor_email = ?');
    $del->execute([$email]);
    if ($del->rowCount() > 0) {
        audit((int)$user['id'], 'vrcportal.revoke', 'counselor', null, $email . $auditTail);
        return ['ok' => true, 'status' => 200, 'kind' => 'success',
            'message' => "Portal link turned off for {$email}. It no longer opens, even in a portal session that is already open; send a new link when they need access again."];
    }
    return ['ok' => false, 'status' => 404, 'kind' => 'error', 'message' => 'That counselor has no portal link to turn off.'];
}

/** The portal column's lines for a vrc_tokens row with live (or null when never invited):
 *  "Expires {date}" / "Does not expire" and "Last used {date}" / "Never opened" for a live link,
 *  "Expired {date}; the counselor needs a new link" for one that has expired. */
function vrc_portal_status_lines(?array $tk): array {
    if (!$tk) return [];
    if ($tk['live']) return [$tk['expires_at'] ? 'Expires ' . fmt_date($tk['expires_at']) : 'Does not expire', $tk['last_used_at'] ? 'Last used ' . fmt_date($tk['last_used_at']) : 'Never opened'];
    return ['Expired ' . fmt_date($tk['expires_at']) . '; the counselor needs a new link'];
}
