<?php
// Admin user management: edit accounts, recover locked-out users, transfer
// caseloads, deactivate, and (when a user has no case history) delete.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
csrf_check();
$user = require_login();
require_perm('admin');

$groups = db()->query('SELECT id, name, perms FROM `groups` ORDER BY name')->fetchAll();

/** Counts of this user's footprint in case records and the audit trail (blocks hard delete). */
function user_history(int $uid): array {
    $checks = [
        'clients assigned' => "SELECT COUNT(*) FROM clients WHERE assigned_to = ?",
        'notes written' => "SELECT COUNT(*) FROM client_notes WHERE author_id = ? OR updated_by = ?",
        'hours logged' => "SELECT COUNT(*) FROM work_logs WHERE created_by = ?",
        'sessions' => "SELECT COUNT(*) FROM scheduled_sessions WHERE user_id = ?",
        'assessments' => "SELECT COUNT(*) FROM tech_assessments WHERE created_by = ?",
        'documents' => "SELECT COUNT(*) FROM client_documents WHERE uploaded_by = ?",
        'invoices' => "SELECT COUNT(*) FROM invoices WHERE created_by = ?",
        'expenses' => "SELECT COUNT(*) FROM track_expenses WHERE created_by = ?",
        'referrals assigned' => "SELECT COUNT(*) FROM referrals WHERE assigned_to = ?",
        'tasks' => "SELECT COUNT(*) FROM tasks WHERE assigned_to = ? OR created_by = ? OR done_by = ?",
        'equipment loans' => "SELECT COUNT(*) FROM equipment_loans WHERE created_by = ?",
        'status changes' => "SELECT COUNT(*) FROM client_status_history WHERE changed_by = ?",
        'amendments' => "SELECT COUNT(*) FROM auth_amendments WHERE changed_by = ?",
        // Attribution on records that have no foreign key to users: deleting the
        // account would leave these pointing at nobody.
        'incidents reported' => "SELECT COUNT(*) FROM incidents WHERE reported_by = ?",
        'messages sent' => "SELECT COUNT(*) FROM messages WHERE staff_id = ?",
        'scored assessments' => "SELECT COUNT(*) FROM client_scored_assessments WHERE assessor_id = ?",
        'worksite assessments' => "SELECT COUNT(*) FROM worksite_assessments WHERE assessor_id = ?",
        'competency ratings' => "SELECT COUNT(*) FROM client_competency WHERE assessed_by = ?",
        'goal progress entries' => "SELECT COUNT(*) FROM goal_progress WHERE recorded_by = ?",
        'homework assignments' => "SELECT COUNT(*) FROM homework WHERE created_by = ?",
        'device follow-ups' => "SELECT COUNT(*) FROM equipment_followups WHERE completed_by = ?",
        'equipment service entries' => "SELECT COUNT(*) FROM equipment_maintenance WHERE performed_by = ?",
        'device requests' => "SELECT COUNT(*) FROM device_requests WHERE requested_by = ?",
        'group sessions' => "SELECT COUNT(*) FROM cohort_sessions WHERE staff_id = ?",
        'cohorts coordinated' => "SELECT COUNT(*) FROM cohorts WHERE coordinator_id = ?",
        'accommodation bookings' => "SELECT COUNT(*) FROM access_bookings WHERE created_by = ?",
        'knowledge-base articles' => "SELECT COUNT(*) FROM kb_articles WHERE created_by = ?",
        'AT service records' => "SELECT COUNT(*) FROM at_records WHERE created_by = ? OR staff_id = ?",
        // audit_log has no foreign key and audit.php names the person from the
        // users row: deleting the account would relabel their sign-ins and
        // record views as "System" and drop them from the filter.
        'audit log entries' => "SELECT COUNT(*) FROM audit_log WHERE user_id = ?",
    ];
    $out = [];
    foreach ($checks as $label => $sql) {
        try {
            $s = db()->prepare($sql);
            $s->execute(array_fill(0, substr_count($sql, '?'), $uid));
        } catch (PDOException $e) {
            // A table from a migration this install has not applied yet holds no history.
            if ((string)$e->getCode() !== '42S02') throw $e;
            continue;
        }
        if (($n = (int)$s->fetchColumn()) > 0) $out[$label] = $n;
    }
    return $out;
}

$editId = (int)($_REQUEST['id'] ?? 0);

/* ---------- POST actions ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $uid = (int)($_POST['uid'] ?? 0);
    $t = db()->prepare('SELECT u.*, g.perms AS group_perms FROM users u LEFT JOIN `groups` g ON g.id = u.group_id WHERE u.id = ?');
    $t->execute([$uid]);
    $target = $t->fetch();
    if (!$target) { flash('User not found.', 'error'); redirect('users.php'); }
    $isSelf = $uid === (int)$user['id'];

    if (isset($_POST['save_user'])) {
        $name = trim(is_string($_POST['name'] ?? null) ? $_POST['name'] : '');
        $email = strtolower(trim(is_string($_POST['email'] ?? null) ? $_POST['email'] : ''));
        $role = in_array($_POST['role'] ?? '', ['admin', 'instructor'], true) ? $_POST['role'] : $target['role'];
        $gid = (int)($_POST['group_id'] ?? 0) ?: null;
        $hoursRaw = trim((string)($_POST['weekly_hours'] ?? $target['weekly_hours']));
        $active = !empty($_POST['is_active']) ? 1 : 0;
        // Each field is checked against its column, so nothing is cut short or
        // quietly replaced (a cleared hours box would otherwise save as 0).
        $errs = [];
        if ($name === '') $errs['name'] = 'Enter the person\'s full name.';
        elseif (mb_strlen($name) > 120) $errs['name'] = 'The name is too long (120 characters maximum).';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errs['email'] = 'Enter a valid email address.';
        // 183, not the column's 190: the sign-in limiter keys ('login:', 'verify:'
        // plus the address) must fit login_attempts.email, or the account could
        // never sign in with a password. An older address left unchanged is not
        // refused here, so the rest of that account can still be edited.
        elseif (mb_strlen($email) > 183 && $email !== strtolower((string)$target['email'])) $errs['email'] = 'The email address is too long (183 characters maximum).';
        if (!is_numeric($hoursRaw) || (float)$hoursRaw < 0 || (float)$hoursRaw > 80) {
            $errs['weekly_hours'] = 'Enter weekly available hours as a number from 0 to 80.';
        }
        if ($errs) {
            keep_input();
            keep_field_errors($errs);
            redirect('users.php?id=' . $uid);
        }
        $hours = (float)$hoursRaw;
        if ($isSelf && (!$active || $role !== 'admin' && $target['role'] === 'admin')) {
            keep_input();
            flash('You cannot deactivate or demote your own account — ask another administrator.', 'error');
            redirect('users.php?id=' . $uid);
        }
        $emailChanged = $email !== strtolower($target['email']);
        try {
            change_user_access($uid, (int)$user['id'], ['role' => $role, 'group_id' => $gid, 'is_active' => $active],
                function (PDO $db) use ($name, $email, $role, $gid, $hours, $active, $uid, $emailChanged): void {
            $db->prepare('UPDATE users SET name = ?, email = ?, role = ?, group_id = ?, weekly_hours = ?, is_active = ?'
                . ($emailChanged ? ', entra_oid = NULL' : '') . ' WHERE id = ?')
                ->execute([$name, $email, $role, $gid, $hours, $active, $uid]);
            // A reset link or sign-in code already emailed (to the old address, or
            // to someone being deactivated) must not outlive the change.
            if ($emailChanged || !$active) {
                $db->prepare('UPDATE password_resets SET used = 1 WHERE user_id = ? AND used = 0')->execute([$uid]);
                $db->prepare('UPDATE login_otps SET consumed = 1 WHERE user_id = ? AND consumed = 0')->execute([$uid]);
            }
                });
        } catch (DomainException $e) {
            keep_input();
            flash($e->getMessage(), 'error'); redirect('users.php?id=' . $uid);
        } catch (PDOException $e) {
            keep_input();
            if ((int)($e->errorInfo[1] ?? 0) === 1062) {
                keep_field_errors(['email' => 'Another user already has that email address.']);
            } else {
                error_log('users.php save_user failed: ' . get_class($e) . ' ' . $e->getCode());
                flash('The changes could not be saved. Nothing was changed; your entries are kept. Please try again.', 'error');
            }
            redirect('users.php?id=' . $uid);
        }
        if (!$active) {
            db()->prepare('DELETE FROM api_tokens WHERE user_id = ?')->execute([$uid]);
        }
        audit($user['id'], 'user.update', 'user', $uid,
            "{$name} <{$email}> role={$role} group=" . ($gid ?? 'legacy') . ' active=' . $active
            . ($emailChanged ? ' (email changed; Microsoft sign-in link reset)' : '')
            . ($emailChanged || !$active ? '; unused reset links and sign-in codes cancelled' : ''));
        // A Microsoft account is never linked again automatically: the person
        // signs in with a password, then links it from My Account.
        flash('User updated.' . ($emailChanged && !empty($target['entra_oid'])
                ? ' The email changed, so their Microsoft sign-in link was removed. "Sign in with Microsoft" will not work for them until they sign in with their password'
                  . ' (if they have none, use "Email password reset link" below) and choose "Link Microsoft account" under My Account.'
                : '')
            . ($emailChanged ? ' Any password reset link or sign-in code sent to the old address no longer works.' : '')
            . (!$active ? ' They were signed out of the mobile app.' : ''));
        redirect('users.php?id=' . $uid);
    }

    if (isset($_POST['send_reset'])) {
        $token = bin2hex(random_bytes(32));
        db()->prepare('UPDATE password_resets SET used = 1 WHERE user_id = ? AND used = 0')->execute([$uid]);
        db()->prepare('INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?,?, DATE_ADD(NOW(), INTERVAL 30 MINUTE))')
            ->execute([$uid, hash('sha256', $token)]);
        $sent = send_app_mail($target['email'], 'Reset your password',
            "Hello {$target['name']},\n\nAn administrator sent you this link to set a new password for " . cfg('name') . ":\n\n"
            . cfg('base_url') . '/reset.php?t=' . $token
            . "\n\nThe link expires in 30 minutes.\n");
        audit($user['id'], 'user.reset_link', 'user', $uid);
        flash($sent ? "A password reset link was emailed to {$target['email']} (valid 30 minutes)."
                    : 'The reset email could not be sent.', $sent ? 'success' : 'error');
        redirect('users.php?id=' . $uid);
    }

    if (isset($_POST['reset_2fa'])) {
        // A reset usually answers a lost or stolen device, so the mobile app
        // sessions go too (an app sign-in can have used the authenticator code).
        // They apply together.
        db()->beginTransaction();
        try {
            db()->prepare('UPDATE users SET totp_enabled = 0, totp_secret = NULL, totp_last_step = NULL WHERE id = ?')->execute([$uid]);
            $pk = db()->prepare('DELETE FROM passkeys WHERE user_id = ?');
            $pk->execute([$uid]);
            $app = db()->prepare('DELETE FROM api_tokens WHERE user_id = ?');
            $app->execute([$uid]);
            db()->commit();
        } catch (Throwable $e) {
            if (db()->inTransaction()) db()->rollBack();
            error_log('users.php reset_2fa failed: ' . get_class($e) . ' ' . $e->getCode());
            flash('The sign-in methods could not be reset; nothing was changed. Please try again.', 'error');
            redirect('users.php?id=' . $uid);
        }
        send_app_mail($target['email'], 'Your sign-in methods were reset',
            "Hello {$target['name']},\n\nAn administrator reset your authenticator app and passkeys for " . cfg('name') . ", and signed you out of the mobile app.\n"
            . "You can sign in with your password and an emailed code, then set them up again under My Account.\n"
            . "If you did not expect this, contact your administrator.\n");
        audit($user['id'], 'user.reset_2fa', 'user', $uid, 'TOTP cleared, ' . $pk->rowCount() . ' passkey(s) removed, ' . $app->rowCount() . ' app session(s) signed out');
        // Browser sessions are tied to the password, so only a new password ends them.
        flash("Authenticator app and passkeys were reset for {$target['name']}, and they were signed out of the mobile app ({$app->rowCount()} session(s)). Email sign-in codes still work."
            . ' A browser where they are already signed in stays signed in until it times out; to end those too, use "Email password reset link" so they set a new password.');
        redirect('users.php?id=' . $uid);
    }

    if (isset($_POST['clear_calfeed'])) {
        // Stops a leaked or unwanted calendar address without deactivating the
        // account; the person can create a new one under My Account.
        $d = db()->prepare('UPDATE users SET cal_token = NULL WHERE id = ? AND cal_token IS NOT NULL');
        $d->execute([$uid]);
        if ($d->rowCount() !== 1) {
            flash($target['name'] . ' has no calendar subscription address, so nothing changed.', 'error');
            redirect('users.php?id=' . $uid);
        }
        audit($user['id'], 'user.calfeed_clear', 'user', $uid, 'calendar subscription address turned off');
        if ((int)$target['is_active'] === 1) {
            send_app_mail($target['email'], 'Your calendar subscription address was turned off',
                "Hello {$target['name']},\n\nAn administrator turned off the private calendar subscription address for your " . cfg('name') . " account, so calendars subscribed to it no longer receive your sessions.\n"
                . "You can create a new address under My Account, then subscribe your calendar to it again.\n"
                . "If you did not expect this, contact your administrator.\n");
        }
        flash('The calendar subscription address for ' . $target['name'] . ' was turned off. Calendars subscribed to it stop receiving sessions.'
            . ((int)$target['is_active'] === 1 ? ' They were emailed, and can create a new address under My Account.' : ''));
        redirect('users.php?id=' . $uid);
    }

    if (isset($_POST['revoke_app'])) {
        $d = db()->prepare('DELETE FROM api_tokens WHERE user_id = ?');
        $d->execute([$uid]);
        audit($user['id'], 'user.revoke_app', 'user', $uid, $d->rowCount() . ' app session(s)');
        flash('Signed ' . $target['name'] . ' out of the mobile app everywhere (' . $d->rowCount() . ' session(s)).');
        redirect('users.php?id=' . $uid);
    }

    // One phone at a time (3.0.39): sign it out, or report it lost so it wipes itself.
    if (isset($_POST['device_signout']) || isset($_POST['device_lost'])) {
        $lost = isset($_POST['device_lost']);
        $ok = app_device_action((int)($_POST['device_id'] ?? 0), $uid, $lost, (int)$user['id']);
        flash($ok ? ($lost ? 'Reported lost. The next time that phone connects, the app removes everything it keeps and signs out.' : 'That phone is signed out of the app.')
            : 'That phone was already signed out or reported lost.', $ok ? 'success' : 'error');
        redirect('users.php?id=' . $uid . '#phones-h');
    }

    if (isset($_POST['clear_lockout'])) {
        // 'login:' is the sign-in key; the bare email is the app sign-in's and earlier releases'.
        $te = strtolower($target['email']);
        $d = db()->prepare("DELETE FROM login_attempts WHERE success = 0 AND email IN (?, ?, ?, ?)");
        $d->execute(['login:' . $te, $te, 'verify:' . $te, 'reauth:' . $te]);
        audit($user['id'], 'user.clear_lockout', 'user', $uid);
        flash('Sign-in lockout cleared for ' . $target['name'] . ' — they can try again right away.');
        redirect('users.php?id=' . $uid);
    }

    if (isset($_POST['transfer_caseload'])) {
        $toId = (int)($_POST['to_user'] ?? 0);
        $to = db()->prepare('SELECT id, name, email, role, group_id FROM users WHERE id = ? AND is_active = 1');
        $to->execute([$toId]);
        $toUser = $to->fetch();
        $toAccess = $toUser ? resolve_perms($toUser) : ['perms' => [], 'scoped' => false];
        // The new owner must be able to open the clients they receive; a user
        // without the Clients permission would hold records nobody can work.
        if (!$toUser || $toId === $uid || !in_array('clients', $toAccess['perms'], true)) {
            flash('Choose a different, active user who has the Clients permission to transfer the caseload to.', 'error');
            redirect('users.php?id=' . $uid);
        }
        // Open referrals go only to someone who can open them (the referral page
        // and the app refuse any other assignee); otherwise they, and the tasks
        // about them, go back to the unassigned referral queue.
        $toReferrals = in_array('referrals', $toAccess['perms'], true);
        // Someone limited to their own caseload receives only the sessions for
        // clients now on it: the Schedule page, the app and the calendar feed hide
        // the others from them and they could not complete them, so those stay
        // here, listed for the administrator to rebook.
        $toScoped = (bool)$toAccess['scoped'];
        $movedSessions = [];
        $leftSessions = [];
        $refsMoved = 0;
        $refsQueued = 0;
        $tasksQueued = 0;
        // All the reassignments must apply together — a partial transfer would
        // strand records (e.g. clients moved but their open sessions left behind).
        db()->beginTransaction();
        try {
            $c = db()->prepare('UPDATE clients SET assigned_to = ? WHERE assigned_to = ?');
            $c->execute([$toId, $uid]);
            $moved = $c->rowCount();
            if ($toReferrals) {
                // Open referrals move; a converted one follows its client's owner (the
                // clients just moved), so scoped access to it goes with the client.
                $r = db()->prepare("UPDATE referrals SET assigned_to = ? WHERE assigned_to = ?
                    AND (status <> 'Converted' OR client_id IN (SELECT id FROM clients WHERE assigned_to = ?))");
                $r->execute([$toId, $uid, $toId]);
                $refsMoved = $r->rowCount();
            } else {
                $tq = db()->prepare("UPDATE tasks SET assigned_to = NULL WHERE assigned_to = ? AND status = 'Open'
                    AND referral_id IN (SELECT id FROM referrals WHERE status <> 'Converted')");
                $tq->execute([$uid]);
                $tasksQueued = $tq->rowCount();
                $r = db()->prepare("UPDATE referrals SET assigned_to = NULL WHERE assigned_to = ? AND status <> 'Converted'");
                $r->execute([$uid]);
                $refsQueued = $r->rowCount();
                $r = db()->prepare("UPDATE referrals SET assigned_to = ? WHERE assigned_to = ?
                    AND status = 'Converted' AND client_id IN (SELECT id FROM clients WHERE assigned_to = ?)");
                $r->execute([$toId, $uid, $toId]);
                $refsMoved = $r->rowCount();
            }
            db()->prepare("UPDATE tasks SET assigned_to = ? WHERE assigned_to = ? AND status = 'Open'")->execute([$toId, $uid]);
            $sq = db()->prepare("SELECT s.id, s.start_at, s.end_at, c.name AS client_name, c.assigned_to AS client_owner
                FROM scheduled_sessions s JOIN clients c ON c.id = s.client_id
                WHERE s.user_id = ? AND s.status = 'Scheduled' AND s.start_at >= NOW()
                ORDER BY s.start_at, s.id FOR UPDATE");
            $sq->execute([$uid]);
            foreach ($sq->fetchAll() as $s) {
                if ($toScoped && (int)$s['client_owner'] !== $toId) $leftSessions[] = $s;
                else $movedSessions[] = $s;
            }
            if ($movedSessions) {
                $ids = array_map(fn($s) => (int)$s['id'], $movedSessions);
                // reminder_sent goes back to 0, so the new instructor still gets the
                // day-before reminder when the old one had already been sent it.
                db()->prepare('UPDATE scheduled_sessions SET user_id = ?, reminder_sent = 0 WHERE id IN ('
                    . implode(',', array_fill(0, count($ids), '?')) . ')')->execute(array_merge([$toId], $ids));
            }
            db()->commit();
        } catch (Throwable $e) {
            if (db()->inTransaction()) db()->rollBack();
            error_log('users.php transfer_caseload failed: ' . get_class($e) . ' ' . $e->getCode());
            flash('The caseload transfer could not be completed; no changes were made. Please try again.', 'error');
            redirect('users.php?id=' . $uid);
        }
        audit($user['id'], 'user.transfer', 'user', $uid, "caseload -> {$toUser['name']} ({$moved} client(s), {$refsMoved} referral(s)"
            . ($refsQueued || $tasksQueued ? ", {$refsQueued} open referral(s) and {$tasksQueued} task(s) about them returned to the queue" : '')
            . ', ' . count($movedSessions) . ' upcoming session(s) moved'
            . ($leftSessions ? ', ' . count($leftSessions) . ' left to rebook' : '') . ')');

        // Calendar invitations went to the old instructor, and no invitation id is
        // kept to withdraw or re-issue them, so both people are told in plain text
        // (date, time and the client's first name only).
        $when = fn(array $s): string => date('D, M j, Y g:i A', strtotime((string)$s['start_at'])) . ' to ' . date('g:i A', strtotime((string)$s['end_at']));
        $listed = function (array $sessions, bool $withClient) use ($when): string {
            $lines = [];
            foreach (array_slice($sessions, 0, 50) as $s) {
                $lines[] = '- ' . $when($s) . ($withClient ? ', ' . explode(' ', trim((string)$s['client_name']))[0] : '');
            }
            if (count($sessions) > 50) $lines[] = '- and ' . (count($sessions) - 50) . ' more on the Schedule page';
            return implode("\n", $lines);
        };
        $sentNew = $sentOld = false;
        if ($movedSessions) {
            $n = count($movedSessions);
            $sentNew = send_app_mail($toUser['email'], 'Upcoming sessions were moved to you',
                "Hello {$toUser['name']},\n\nAn administrator moved {$n} upcoming session" . ($n === 1 ? '' : 's') . " from {$target['name']} to you in " . cfg('name') . ":\n\n"
                . $listed($movedSessions, true) . "\n\n"
                . "The full details are on your Schedule page: " . cfg('base_url') . "/schedule.php\n"
                . "Calendar invitations for these sessions went to {$target['name']}, so they are not in your calendar yet: add them, or subscribe to your calendar address under My Account.\n");
            notify_user($toId, $n . ' upcoming session' . ($n === 1 ? ' was' : 's were') . ' moved to you from ' . $target['name'] . '.', 'schedule.php');
            $sentOld = send_app_mail($target['email'], 'Your upcoming sessions were moved to another instructor',
                "Hello {$target['name']},\n\nAn administrator moved {$n} of your upcoming session" . ($n === 1 ? '' : 's') . " to {$toUser['name']} in " . cfg('name') . ".\n"
                . "Remove them from your calendar if they are there; {$toUser['name']} now has them:\n\n"
                . $listed($movedSessions, false) . "\n");
        }

        $msg = "Moved {$moved} client(s), "
            . ($toReferrals ? 'open referrals, ' : '')
            . 'open tasks, and ' . count($movedSessions) . " upcoming session(s) from {$target['name']} to {$toUser['name']}.";
        if ($refsQueued || $tasksQueued) {
            $msg .= " Because {$toUser['name']} has no access to referrals, {$refsQueued} open referral(s) and {$tasksQueued} task(s) about open referrals went back to the unassigned queue instead.";
        }
        if ($movedSessions) {
            $msg .= $sentNew ? " {$toUser['name']} was emailed the list of sessions they received."
                : " The email listing the sessions could not be sent to {$toUser['name']}: tell them, and point them to their Schedule page.";
            $msg .= $sentOld ? " {$target['name']} was emailed a notice that they were moved." : " The notice to {$target['name']} could not be emailed.";
        }
        flash($msg);
        if ($leftSessions) {
            // The transfer worked; this is what is still to do, so it is not an error.
            $shown = array_map(fn($s) => $when($s) . ' (' . $s['client_name'] . ')', array_slice($leftSessions, 0, 10));
            flash(count($leftSessions) . " upcoming session(s) stayed with {$target['name']}, because {$toUser['name']} sees only their own caseload and those clients are not on it."
                . ' Rebook them on the Schedule page with someone who can see the client: ' . implode('; ', $shown)
                . (count($leftSessions) > 10 ? '; and ' . (count($leftSessions) - 10) . ' more.' : '.'), 'info');
        }
        redirect('users.php?id=' . $uid);
    }

    if (isset($_POST['delete_user'])) {
        if ($isSelf) { flash('You cannot delete your own account.', 'error'); redirect('users.php?id=' . $uid); }
        $history = user_history($uid);
        if ($history) {
            flash('This user has case history or audit-log activity (' . implode(', ', array_map(fn($k, $v) => "$v $k", array_keys($history), $history))
                . '), so the account can only be deactivated — deleting it would orphan those records and lose who did what in the audit log. Transfer the caseload, then deactivate.', 'error');
            redirect('users.php?id=' . $uid);
        }
        try {
            change_user_access($uid, (int)$user['id'], ['is_active' => 0], function (PDO $db) use ($uid): void {
                $db->prepare('DELETE FROM passkeys WHERE user_id = ?')->execute([$uid]);
                $db->prepare('DELETE FROM users WHERE id = ?')->execute([$uid]);
            });
        } catch (DomainException $e) { flash($e->getMessage(), 'error'); redirect('users.php?id=' . $uid); }
        audit($user['id'], 'user.delete', 'user', $uid, $target['name'] . ' <' . $target['email'] . '>');
        flash('User ' . $target['name'] . ' was deleted.');
        redirect('users.php');
    }
    redirect('users.php');
}

/* ---------- Data for the page ---------- */
$rows = db()->query("SELECT u.*, g.name AS group_name, g.perms AS group_perms,
        (SELECT COUNT(*) FROM clients c WHERE c.assigned_to = u.id) AS caseload,
        (SELECT COUNT(*) FROM api_tokens a WHERE a.user_id = u.id AND a.expires_at > NOW()) AS app_sessions,
        (SELECT COUNT(*) FROM passkeys p WHERE p.user_id = u.id) AS passkey_count,
        (SELECT MAX(l.created_at) FROM audit_log l WHERE l.user_id = u.id AND l.action = 'login') AS last_login
    FROM users u LEFT JOIN `groups` g ON g.id = u.group_id
    ORDER BY u.is_active DESC, u.name")->fetchAll();

$edit = null;
foreach ($rows as $r) if ((int)$r['id'] === $editId) $edit = $r;
$editHistory = $edit ? user_history((int)$edit['id']) : [];
// Recent failures per counter, each against its own limit: password sign-in
// (login.php / app, 8), code entry ('verify:', 8), password re-check ('reauth:', 5).
$lockout = 0;
$lockedOut = [];
if ($edit) {
    $le = strtolower($edit['email']);
    $lq = db()->prepare("SELECT email, COUNT(*) AS n FROM login_attempts WHERE success = 0 AND attempted_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE) AND email IN (?, ?, ?, ?) GROUP BY email");
    $lq->execute(['login:' . $le, $le, 'verify:' . $le, 'reauth:' . $le]);
    $counts = array_change_key_case(array_map('intval', $lq->fetchAll(PDO::FETCH_KEY_PAIR)), CASE_LOWER);
    $lockout = array_sum($counts);
    // Password sign-in failures: 'login:<email>' (web), or the bare email (app, earlier releases).
    if (($counts['login:' . $le] ?? 0) + ($counts[$le] ?? 0) >= 8) $lockedOut[] = 'password sign-in locked';
    if (($counts['verify:' . $le] ?? 0) >= 8) $lockedOut[] = 'code entry locked';
    if (($counts['reauth:' . $le] ?? 0) >= 5) $lockedOut[] = 'password re-check locked';
}

page_header('Users', $user, 'admin', [['admin.php', 'Administration']]);
?>
<h1>User management</h1>
<p class="muted">Edit accounts, recover locked-out users, hand a caseload to someone else, and deactivate or delete accounts.
   Adding a team member, quick group and hours changes and permission groups are on <a href="admin.php#people-h">Administration</a>.</p>

<?php if ($edit): ?>
<section class="card" aria-label="Edit user <?= h($edit['name']) ?>">
  <h2>Edit: <?= h($edit['name']) ?><?= (int)$edit['is_active'] === 0 ? ' <span class="badge badge-hold">Deactivated</span>' : '' ?></h2>
  <p class="muted">
    Last sign-in: <?= $edit['last_login'] ? fmt_datetime($edit['last_login']) : 'never' ?> ·
    Caseload: <?= (int)$edit['caseload'] ?> client(s) ·
    App sessions: <?= (int)$edit['app_sessions'] ?> ·
    Passkeys: <?= (int)$edit['passkey_count'] ?> ·
    Authenticator app: <?= $edit['totp_enabled'] ? 'on' : 'off' ?> ·
    Calendar subscription address: <?= (string)$edit['cal_token'] !== '' ? 'on' : 'off' ?>
    <?= $lockedOut ? ' · <span class="field-error"><strong>Locked out by failed attempts: ' . h(implode(', ', $lockedOut)) . '</strong></span>' : '' ?>
  </p>

  <?= form_error_summary(['name' => 'Full name', 'email' => 'Email address', 'weekly_hours' => 'Weekly available hours'],
        ['name' => 'u_name', 'email' => 'u_email', 'weekly_hours' => 'u_hours']) ?>
  <form method="post" action="users.php" class="stacked" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="uid" value="<?= (int)$edit['id'] ?>">
    <p class="hint">Fields marked * are required.</p>
    <div class="grid grid-2">
      <div class="field"><label for="u_name">Full name <?= required_mark() ?></label>
        <input type="text" id="u_name" name="name" required maxlength="120" value="<?= h(old_in('name', $edit['name'])) ?>" <?= field_aria('name') ?>><?= field_error('name') ?></div>
      <div class="field"><label for="u_email">Email address <?= required_mark() ?> <span class="hint">changing it removes any Microsoft sign-in link; they re-link from My Account after signing in with a password</span></label>
        <input type="email" id="u_email" name="email" required value="<?= h(old_in('email', $edit['email'])) ?>" <?= field_aria('email') ?>><?= field_error('email') ?></div>
      <?php // A rejected save (guard error) refills every field, not just name/email.
            $refilling = old_in('save_user') !== '';
            $selGroup = $refilling ? old_in('group_id') : (string)($edit['group_id'] ?? '');
            $checkedActive = $refilling ? old_in('is_active') === '1' : (bool)$edit['is_active']; ?>
      <div class="field"><label for="u_role">Legacy role <span class="hint">used only when no permission group is set</span></label>
        <select id="u_role" name="role"><?= select_options(['admin', 'instructor'], old_in('role', $edit['role'])) ?></select></div>
      <div class="field"><label for="u_group">Permission group</label>
        <select id="u_group" name="group_id">
          <option value="">Legacy (by role)</option>
          <?php foreach ($groups as $g): ?>
          <option value="<?= (int)$g['id'] ?>" <?= (int)$selGroup === (int)$g['id'] ? 'selected' : '' ?>><?= h($g['name']) ?></option>
          <?php endforeach; ?>
        </select></div>
      <div class="field"><label for="u_hours">Weekly available hours <?= required_mark() ?></label>
        <span class="hint" id="u_hours-hint">A number from 0 to 80, in half-hour steps (enter 0 for none).</span>
        <input type="number" id="u_hours" name="weekly_hours" required min="0" max="80" step="0.5" value="<?= h(old_in('weekly_hours', (string)(float)$edit['weekly_hours'])) ?>" <?= field_aria('weekly_hours', 'u_hours-hint') ?>><?= field_error('weekly_hours') ?></div>
      <div class="field"><label><input type="checkbox" name="is_active" value="1" <?= $checkedActive ? 'checked' : '' ?>>
        Active <span class="hint">unchecking blocks sign-in immediately and signs the app out</span></label></div>
    </div>
    <button type="submit" name="save_user" value="1" class="btn btn-primary">Save changes</button>
  </form>

  <h3 id="phones-h">Phones signed in to the app</h3>
  <?= app_devices_html(app_devices((int)$edit['id']), 'users.php', ['uid' => (int)$edit['id']]) ?>

  <h3>Account recovery</h3>
  <form method="post" action="users.php" class="inline-form"><?= csrf_field() ?>
    <input type="hidden" name="uid" value="<?= (int)$edit['id'] ?>">
    <button type="submit" name="send_reset" value="1" class="btn btn-secondary"
      onclick="return confirm(<?= h(json_encode('Email ' . $edit['email'] . ' a password reset link?', JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP)) ?>);">Email password reset link</button>
    <button type="submit" name="reset_2fa" value="1" class="btn btn-secondary"
      onclick="return confirm('Reset their authenticator app, remove all passkeys and sign them out of the mobile app? They will be emailed, and email codes keep working.');">Reset authenticator &amp; passkeys</button>
    <button type="submit" name="revoke_app" value="1" class="btn btn-secondary"
      onclick="return confirm('Sign this user out of the mobile app on every device?');">Sign out of mobile app</button>
    <?php if ((string)$edit['cal_token'] !== ''): ?>
    <button type="submit" name="clear_calfeed" value="1" class="btn btn-secondary"
      onclick="return confirm('Turn off their calendar subscription address? Calendars subscribed to it stop receiving sessions; they can create a new one under My Account.');">Turn off calendar address</button>
    <?php endif; ?>
    <?php if ($lockout > 0): ?>
    <button type="submit" name="clear_lockout" value="1" class="btn btn-secondary">Clear sign-in lockout</button>
    <?php endif; ?>
  </form>

  <h3>Caseload transfer</h3>
  <p class="hint" id="to_user-hint">Open referrals go only to someone with access to referrals; otherwise they return to the unassigned referral queue. Someone limited to their own caseload receives only the sessions for clients they receive; any others stay here and are listed for you to rebook. Both people are emailed about the sessions that move.</p>
  <form method="post" action="users.php" class="inline-form"><?= csrf_field() ?>
    <input type="hidden" name="uid" value="<?= (int)$edit['id'] ?>">
    <label for="to_user">Move their <?= (int)$edit['caseload'] ?> client(s), open referrals, open tasks, and upcoming sessions to</label>
    <select id="to_user" name="to_user" aria-describedby="to_user-hint">
      <option value="">— Choose a user —</option>
      <?php foreach ($rows as $r): if ((int)$r['id'] === (int)$edit['id'] || !$r['is_active']) continue;
        $rAccess = resolve_perms($r);
        if (!in_array('clients', $rAccess['perms'], true)) continue; ?>
      <option value="<?= (int)$r['id'] ?>"<?= in_array('referrals', $rAccess['perms'], true) ? '' : ' data-noref="1"' ?><?= $rAccess['scoped'] ? ' data-scoped="1"' : '' ?>><?= h($r['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <?php // The confirmation names both people, read from the list as it is now, and
          // says what the chosen person's access means for referrals and sessions. ?>
    <button type="submit" name="transfer_caseload" value="1" class="btn btn-secondary"
      onclick="var s=document.getElementById('to_user');if(!s.value)return true;var o=s.options[s.selectedIndex],to=o.text;return confirm(<?= h(json_encode('Move ' . (int)$edit['caseload'] . ' client(s), open referrals, open tasks and upcoming sessions from ' . $edit['name'] . ' to ', JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP)) ?>+to+'?'+(o.getAttribute('data-noref')?'\n\n'+to+' has no access to referrals, so open referrals go back to the unassigned referral queue instead.':'')+(o.getAttribute('data-scoped')?'\n\n'+to+' sees only their own caseload, so sessions for clients not on it stay here, to be rebooked.':'')+'\n\nThis cannot be undone in one step: moving them back later would also move '+to+'\'s own caseload.');">Transfer<span class="visually-hidden"> <?= h($edit['name']) ?>'s caseload</span></button>
  </form>

  <h3>Delete</h3>
  <?php if ($editHistory): ?>
  <p class="muted">This account has case history or audit-log activity (<?= h(implode(', ', array_map(fn($k, $v) => "$v $k", array_keys($editHistory), $editHistory))) ?>),
     so it can only be <strong>deactivated</strong> — deleting it would orphan those records and lose who did what in the audit log. Transfer the caseload above, then uncheck Active.</p>
  <?php elseif ((int)$edit['id'] === (int)$user['id']): ?>
  <p class="muted">You cannot delete your own account.</p>
  <?php else: ?>
  <form method="post" action="users.php" class="inline-form"><?= csrf_field() ?>
    <input type="hidden" name="uid" value="<?= (int)$edit['id'] ?>">
    <button type="submit" name="delete_user" value="1" class="btn btn-danger"
      onclick="return confirm(<?= h(json_encode('Permanently delete ' . $edit['name'] . '? They have no case history or audit-log activity, so nothing else is affected. This cannot be undone.', JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP)) ?>);">Delete this user permanently</button>
  </form>
  <?php endif; ?>
</section>
<?php endif; ?>

<h2 id="users-list-h">All users (<?= count($rows) ?>)</h2>
<div class="table-scroll" tabindex="0" role="region" aria-labelledby="users-list-h">
<table>
  <caption class="visually-hidden">All user accounts</caption>
  <thead><tr>
    <th scope="col">Name</th><th scope="col">Access</th><th scope="col" class="num">Caseload</th>
    <th scope="col">Last sign-in</th><th scope="col">Status</th><th scope="col"><span class="visually-hidden">Actions</span></th>
  </tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr>
      <th scope="row"><?= h($r['name']) ?><br><span class="muted"><?= h($r['email']) ?></span></th>
      <td><?= h($r['group_name'] ?? 'Legacy: ' . $r['role']) ?><br>
          <span class="muted"><?= $r['totp_enabled'] ? 'authenticator' : '' ?><?= $r['passkey_count'] ? ($r['totp_enabled'] ? ' · ' : '') . (int)$r['passkey_count'] . ' passkey(s)' : '' ?></span></td>
      <td class="num"><?= (int)$r['caseload'] ?></td>
      <td class="muted"><?= $r['last_login'] ? fmt_datetime($r['last_login']) : 'never' ?></td>
      <td><?= $r['is_active'] ? status_badge('Active') : '<span class="badge badge-hold">Deactivated</span>' ?></td>
      <td><a class="btn btn-secondary btn-sm" href="users.php?id=<?= (int)$r['id'] ?>">Manage<span class="visually-hidden"> user <?= h($r['name']) ?></span></a></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php page_footer();
