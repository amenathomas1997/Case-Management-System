<?php
declare(strict_types=1);
// Staff accounts (3.0.55): invitations by email instead of a typed password, accounts not
// used for a while (sign-ins are in the audit log), each person's confidentiality agreement and privacy training with renewal
// dates, and what a departing person still holds, moved in one step.

/** How long a confidentiality agreement or privacy training lasts before renewal. */
const STAFF_RENEWAL_DAYS = 365;
/** An account not used for this many days is flagged. */
const STAFF_IDLE_DAYS = 60;

/** Email a new account its invitation: a link to choose a password, valid for 7 days. */
function staff_invite_send(int $userId, int $actor): bool {
    $u = db()->prepare('SELECT id, name, email FROM users WHERE id = ? AND is_active = 1');
    $u->execute([$userId]);
    $row = $u->fetch();
    if (!$row) return false;
    $token = bin2hex(random_bytes(32));
    db()->prepare('UPDATE password_resets SET used = 1 WHERE user_id = ? AND used = 0')->execute([$userId]);
    db()->prepare('INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?,?, DATE_ADD(NOW(), INTERVAL 7 DAY))')->execute([$userId, hash('sha256', $token)]);
    $sent = send_app_mail((string)$row['email'], 'Your account for ' . cfg('name'),
        "Hello {$row['name']},\n\nAn account has been made for you in " . cfg('name') . ". Use this link to choose your password and sign in:\n\n"
        . cfg('base_url') . '/reset.php?t=' . $token . "\n\nThe link works once and expires in 7 days. If you were not expecting it, you can ignore this email.\n");
    try { db()->prepare('UPDATE users SET invited_at = NOW() WHERE id = ?')->execute([$userId]); } catch (Throwable $e) {}
    audit($actor, 'user.invite', 'user', $userId, $sent ? 'invitation emailed' : 'invitation could not be emailed');
    return $sent;
}

/** What needs renewing or checking for one person: a list of short sentences. */
function staff_compliance_notes(array $u): array {
    $out = [];
    if ((int)($u['is_active'] ?? 1) !== 1) return $out; // a switched-off account needs nothing renewed
    $today = date('Y-m-d');
    foreach (['confidentiality_signed_on' => 'Confidentiality agreement', 'privacy_training_on' => 'Privacy training'] as $col => $label) {
        $d = $u[$col] ?? null;
        if (!$d) { $out[] = $label . ' not recorded'; continue; }
        $due = date('Y-m-d', strtotime($d . ' +' . STAFF_RENEWAL_DAYS . ' days'));
        if ($due < $today) $out[] = $label . ' expired ' . fmt_date($due);
        elseif ($due <= date('Y-m-d', strtotime('+30 days'))) $out[] = $label . ' due for renewal by ' . fmt_date($due);
    }
    if ((int)($u['is_active'] ?? 0) === 1) {
        $last = $u['last_login_at'] ?? null;
        if ($last === null && !empty($u['invited_at']) && strtotime((string)$u['invited_at']) < time() - 7 * 86400) $out[] = 'Invitation not used';
        elseif ($last !== null && strtotime((string)$last) < time() - STAFF_IDLE_DAYS * 86400) $out[] = 'Not signed in for ' . (int)floor((time() - strtotime((string)$last)) / 86400) . ' days';
    }
    return $out;
}

/** Active staff with something to renew or check: [user row + 'notes' => [...]]. */
function staff_compliance_list(): array {
    try {
        $rows = db()->query("SELECT u.id, u.name, u.email, u.is_active, u.invited_at, u.confidentiality_signed_on, u.privacy_training_on,
            (SELECT MAX(l.created_at) FROM audit_log l WHERE l.user_id = u.id AND l.action = 'login') AS last_login_at FROM users u WHERE u.is_active = 1 ORDER BY u.name")->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
    $out = [];
    // Agreements staff sign themselves (4.15.0): everyone's latest signatures in one query.
    $latest = [];
    try { $latest = staff_agreement_latest(); } catch (Throwable $e) {}
    $signedConf = staff_agreements_has_confidentiality();
    // Training (4.16.0) the same way, and credentials expired or expiring.
    $trained = [];
    try { $trained = training_latest(); } catch (Throwable $e) {}
    $takenPrivacy = training_has_privacy();
    $creds = credentials_due();
    foreach ($rows as $r) {
        $n = staff_compliance_notes($r);
        // With a confidentiality agreement staff sign, its own state replaces the recorded date's.
        if ($signedConf) $n = array_values(array_filter($n, fn($x) => !str_starts_with($x, 'Confidentiality agreement')));
        if ($takenPrivacy) $n = array_values(array_filter($n, fn($x) => !str_starts_with($x, 'Privacy training')));
        $n = array_merge($n, staff_agreement_notes((int)$r['id'], $latest), training_notes((int)$r['id'], $trained), credential_notes($creds[(int)$r['id']] ?? []));
        if ($n) $out[] = $r + ['notes' => $n];
    }
    return $out;
}

/** Statuses after which an AT record needs nobody (it is finished). */
const AT_RECORD_DONE = ['Resolved', 'Cancelled', 'Completed', 'Closed', 'Returned', 'Voided', 'Archived', 'Retired', 'Denied', 'Expired', 'Not suitable', 'Paid', 'Recycled', 'Redeployed', 'Released', 'Skipped', 'Superseded', 'Unavailable'];

/** What a person still holds, for the departure checklist: [label => count]. */
function staff_holdings(int $uid): array {
    $n = function (string $sql) use ($uid): int { try { $q = db()->prepare($sql); $q->execute([$uid]); return (int)$q->fetchColumn(); } catch (Throwable $e) { return 0; } };
    $done = "'" . implode("','", AT_RECORD_DONE) . "'";
    return [
        'Clients assigned' => $n('SELECT COUNT(*) FROM clients WHERE assigned_to = ? AND archived_at IS NULL'),
        'Open tasks' => $n("SELECT COUNT(*) FROM tasks WHERE assigned_to = ? AND status = 'Open'"),
        'Upcoming sessions' => $n("SELECT COUNT(*) FROM scheduled_sessions WHERE user_id = ? AND status = 'Scheduled' AND start_at >= NOW()"),
        // Licences only: a person's cost rate and skills profile are theirs, not passed on.
        'Software licences assigned' => $n("SELECT COUNT(*) FROM at_records WHERE staff_id = ? AND kind = 'license' AND status NOT IN ($done)"),
        'Equipment reservations' => $n("SELECT COUNT(*) FROM equipment_reservations WHERE reserved_by = ? AND status = 'Reserved'"),
        'Group programs coordinated' => $n('SELECT COUNT(*) FROM cohorts WHERE coordinator_id = ? AND active = 1'),
        'Access bookings' => $n("SELECT COUNT(*) FROM access_bookings WHERE created_by = ? AND status IN ('Requested','Confirmed')"),
    ];
}

/** Move what the caseload transfer does not: software licences, reservations, programs and bookings. Returns how many moved. */
function staff_move_other_holdings(int $from, int $to): int {
    $done = "'" . implode("','", AT_RECORD_DONE) . "'";
    $moved = 0;
    foreach ([
        "UPDATE at_records SET staff_id = ? WHERE staff_id = ? AND kind = 'license' AND status NOT IN ($done)",
        "UPDATE equipment_reservations SET reserved_by = ? WHERE reserved_by = ? AND status = 'Reserved'",
        'UPDATE cohorts SET coordinator_id = ? WHERE coordinator_id = ? AND active = 1',
        "UPDATE access_bookings SET created_by = ? WHERE created_by = ? AND status IN ('Requested','Confirmed')",
    ] as $sql) {
        try { $q = db()->prepare($sql); $q->execute([$to, $from]); $moved += $q->rowCount(); }
        catch (PDOException $e) { if ((string)$e->getCode() !== '42S02') throw $e; } // only a table an older database lacks is skipped
    }
    return $moved;
}

/**
 * Microsoft 365 departures (3.0.55; off unless an administrator turns on "m365_offboard"):
 * an active CMS account whose Microsoft 365 account has been disabled is switched off and
 * signed out of the app. Administrators are never switched off automatically; they are
 * reported instead. Needs the Graph app permission User.Read.All. Returns what happened.
 */
function staff_m365_sync(): array {
    if (setting('m365_offboard') !== '1' || graph_cfg() === null) return ['checked' => 0, 'off' => [], 'admins' => [], 'error' => null];
    $checked = 0; $off = []; $admins = [];
    foreach (db()->query('SELECT id, name, email, role, group_id FROM users WHERE is_active = 1')->fetchAll() as $u) {
        [$code, $data] = graph_api('GET', '/users/' . rawurlencode((string)$u['email']) . '?$select=accountEnabled');
        if ($code === 403) return ['checked' => $checked, 'off' => $off, 'admins' => $admins, 'error' => 'Microsoft refused the check: the app registration needs the User.Read.All application permission.'];
        if ($code !== 200 || !is_array($data) || !array_key_exists('accountEnabled', $data)) continue;
        $checked++;
        if ($data['accountEnabled'] !== false) continue;
        if (in_array('admin', resolve_perms($u)['perms'], true)) { $admins[] = $u['name']; continue; }
        db()->prepare('UPDATE users SET is_active = 0 WHERE id = ? AND is_active = 1')->execute([(int)$u['id']]);
        db()->prepare('DELETE FROM api_tokens WHERE user_id = ?')->execute([(int)$u['id']]);
        db()->prepare('UPDATE password_resets SET used = 1 WHERE user_id = ? AND used = 0')->execute([(int)$u['id']]);
        db()->prepare('UPDATE login_otps SET consumed = 1 WHERE user_id = ? AND consumed = 0')->execute([(int)$u['id']]);
        audit(null, 'user.deactivate', 'user', (int)$u['id'], 'disabled in Microsoft 365');
        $off[] = $u['name'];
    }
    return ['checked' => $checked, 'off' => $off, 'admins' => $admins, 'error' => null];
}
