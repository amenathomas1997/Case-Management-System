<?php
declare(strict_types=1);
// Session reminders: email client + instructor the day before a scheduled session.
// Each run reminds every session not yet reminded that starts between now and the
// end of tomorrow, so a session booked after the last run (or moved to another
// instructor, which sets reminder_sent back to 0), or one a missed run skipped, is
// reminded by the next run, whatever hour the host's clock runs it at. Run it
// morning and evening; reminder_sent stops a session being reminded twice.
// Cron: 15 7,17 * * *
if (PHP_SAPI !== 'cli') exit('CLI only.');

// Where the application's inc/ directory is: an optional cron_config.php beside
// this script (it returns the path) wins, then the production path, then the
// repository layout. Nothing needs editing here for a different install.
foreach ([
    is_file(__DIR__ . '/cron_config.php') ? (string)(require __DIR__ . '/cron_config.php') : '',
    '/home/cmsuser/public_html/casemanagement/inc',
    dirname(__DIR__) . '/public/inc',
] as $inc) {
    $inc = rtrim($inc, '/');
    if ($inc !== '' && is_file($inc . '/core.php')) { require $inc . '/core.php'; break; }
}
if (!function_exists('db')) {
    fwrite(STDERR, "CMS cron: the application's inc/core.php was not found. Create cron_config.php beside this script containing: <?php return '/full/path/to/your/web/docroot/inc';\n");
    exit(1);
}

// Staff are joined only while active: a deactivated account whose sessions were
// not yet transferred must not be emailed client details. The client is still
// reminded, and the administrators are asked to transfer or rebook the session.
// A staff member limited to their own caseload is emailed only while the client
// is still assigned to them (the rule the Schedule page and the calendar feed
// apply): after a reassignment the reminder goes to the client's current case
// worker instead, or to the administrators when there is none.
// A client who has since been closed or archived is not emailed; the instructor
// is still reminded, so the session can be cancelled. A client email copied from
// the public referral form (email_verified = 0) is not emailed either until staff
// confirm it: the instructor's reminder and the administrators' alerts say so.
// One run at a time: a manual run overlapping the scheduled one would otherwise email
// every client and instructor twice (reminder_sent is set only after sending).
if ((int)db()->query("SELECT GET_LOCK(CONCAT('cms_session_reminders_', MD5(DATABASE())), 0)")->fetchColumn() !== 1) {
    echo "Another session reminders run is still working; this run sent nothing.\n";
    exit(0);
}
$sessions = db()->query("SELECT s.*, c.name AS client_name, c.email AS client_email, c.email_verified AS client_email_verified,
        (c.archived_at IS NULL AND c.status NOT LIKE 'Closed%') AS client_current,
        u.id AS active_staff_id, u.name AS staff_name, u.email AS staff_email,
        (u.id IS NOT NULL AND (g.id IS NULL OR g.own_caseload_only = 0 OR c.assigned_to = u.id)) AS staff_sees_client,
        a.name AS assignee_name, a.email AS assignee_email, pt.token AS portal_token
    FROM scheduled_sessions s
    JOIN clients c ON c.id = s.client_id
    LEFT JOIN portal_tokens pt ON pt.client_id = c.id AND (pt.expires_at IS NULL OR pt.expires_at > NOW())
    LEFT JOIN users u ON u.id = s.user_id AND u.is_active = 1
    LEFT JOIN `groups` g ON g.id = u.group_id
    LEFT JOIN users a ON a.id = c.assigned_to AND a.is_active = 1
    WHERE s.status = 'Scheduled' AND s.reminder_sent = 0
      AND s.start_at > NOW() AND s.start_at < DATE_ADD(CURDATE(), INTERVAL 2 DAY)")->fetchAll();

$sent = 0; $failed = []; $unconfirmed = [];
$mark = db()->prepare('UPDATE scheduled_sessions SET reminder_sent = 1 WHERE id = ?');
// An email template that does not use {day} says "tomorrow": a client must never
// be told that a session today is tomorrow's.
$sameDay = fn(string $text): string => (string)preg_replace_callback('/\btomorrow\b/i',
    fn(array $m): string => ctype_upper($m[0][0]) ? 'Today' : 'today', $text);
// The contact address the public pages' footer gives.
$contact = trim((string)(cfg('support_email') ?: cfg('mail_from')));
if (!filter_var($contact, FILTER_VALIDATE_EMAIL)) $contact = '';
// The built-in wording told the client to "call your instructor" without a number.
// It is replaced with the ways the client can actually reach us: their portal (when
// they have a working link) and the contact address.
$rescheduleOld = 'If you need to reschedule, please reply to this email or call your instructor.';
foreach ($sessions as $s) {
    $startTs = strtotime($s['start_at']);
    // Most sessions are tomorrow's; one booked or moved after the last run can be today's.
    $day = date('Y-m-d', $startTs) === date('Y-m-d') ? 'today' : 'tomorrow';
    // With the time zone (e.g. "2:00 PM CDT"): a remote client may be elsewhere.
    $when = date('l, F j \a\t g:i A T', $startTs);
    // A remote session says so and carries its join link (http(s) links only).
    $url = trim((string)($s['meeting_url'] ?? ''));
    $joinLink = !empty($s['is_remote']) && preg_match('~^https?://\S+$~i', $url) ? $url : '';
    $where = !empty($s['is_remote'])
        ? "\nWhere: online" . ($joinLink !== '' ? "\nJoin link: {$joinLink}" : '')
        : ($s['location'] ? "\nWhere: {$s['location']}" : '');
    $current = (bool)($s['client_current'] ?? true);
    $staffSees = !empty($s['staff_sees_client']);
    $allSent = true; $anyAddress = false; $badAddress = false; $emailHeld = false;
    if ($s['client_email'] && $current) {
        if (empty($s['client_email_verified'])) {
            // Typed on the public referral form by whoever sent it and not yet
            // confirmed by staff: nothing is sent to it. The instructor's reminder
            // and the administrators' alert below ask for it to be confirmed.
            $emailHeld = true;
        } elseif (!filter_var($s['client_email'], FILTER_VALIDATE_EMAIL)) {
            // An address that cannot be emailed is skipped; the session is left
            // unmarked and reported below so someone contacts the client directly.
            $anyAddress = true;
            $allSent = false; $badAddress = true;
        } else {
            $anyAddress = true;
            // {when} and {where} keep their English labels for templates written
            // before 3.0.21; {date}, {time}, {location} and {join_link} are the bare
            // values, so a body written in another language can carry its own labels.
            // The instructor is named only when one is visible to this client; the
            // organization's name is never given as the instructor.
            $instructor = $staffSees ? trim((string)($s['staff_name'] ?? '')) : '';
            $portalToken = (string)($s['portal_token'] ?? '');
            $portalLink = preg_match('/^[a-f0-9]{48}$/D', $portalToken) ? cfg('base_url') . '/portal.php?t=' . $portalToken : '';
            [$subj, $body] = email_template('session_reminder_client', [
                'name' => $s['client_name'], 'when' => $when, 'where' => $where,
                // A marker for now: the line is dropped below, and prose elsewhere in an
                // administrator's own wording reads "your instructor" rather than a gap.
                'instructor' => $instructor !== '' ? $instructor : "\u{1F}instructor\u{1F}",
                'day' => $day, 'date' => date('l, F j', $startTs), 'time' => date('g:i A T', $startTs),
                'location' => empty($s['is_remote']) ? (string)($s['location'] ?? '') : '', 'join_link' => $joinLink,
                'portal_link' => $portalLink, 'contact' => $contact,
            ]);
            // No instructor to name: the "Instructor:" line is left out, not left blank.
            if ($instructor === '') {
                $body = (string)preg_replace("/^[ \t]*Instructor:[ \t]*\x{1F}instructor\x{1F}[ \t]*\R/mu", '', $body);
                $body = str_replace("\u{1F}instructor\u{1F}", 'your instructor', $body);
                $subj = str_replace("\u{1F}instructor\u{1F}", 'your instructor', $subj);
            }
            $reschedule = $portalLink !== ''
                ? 'Need a different time? Use your portal: ' . $portalLink . ($contact !== '' ? "\nOr email us at " . $contact . '.' : '')
                : 'Need a different time? Reply to this email' . ($contact !== '' ? ' or email us at ' . $contact : '') . '.';
            $body = str_replace($rescheduleOld, $reschedule, $body);
            if ($day === 'today') { $subj = $sameDay($subj); $body = $sameDay($body); }
            $allSent = send_app_mail($s['client_email'], $subj, $body) && $allSent;
        }
    }
    $staffBody = "Session reminder.\n\nClient: {$s['client_name']}\nWhen: {$when}{$where}\n"
        . ($current ? '' : "\nThis client is closed or archived, so the client was not reminded. Cancel the session on the Schedule page if it no longer applies.\n")
        . ($emailHeld ? "\nThe client was not emailed this reminder: their email address came from the public referral form and has not been confirmed. "
            . "Confirm it on the client's edit page, and remind the client another way this time.\n" : '');
    $staffSubject = ucfirst($day) . ': ' . $s['client_name'] . ' — ' . date('g:i A T', $startTs);
    if ($s['staff_email'] && $staffSees) {
        $anyAddress = true;
        $allSent = send_app_mail($s['staff_email'], $staffSubject, $staffBody) && $allSent;
    } elseif ($s['active_staff_id'] && !$staffSees) {
        // Still booked with a staff member who no longer has this client on their
        // caseload (the client was reassigned): they are not sent client details.
        $anyAddress = true;
        $stranded = "This session is still booked with {$s['staff_name']}, who no longer has this client on their caseload. "
            . "Cancel it on the Schedule page and book it again with the right instructor if it should go ahead: " . cfg('base_url') . "/schedule.php\n";
        $allSent = ($s['assignee_email']
            ? send_app_mail($s['assignee_email'], $staffSubject, $staffBody . "\n" . $stranded)
            : notify_admins(ucfirst($day) . "'s session for {$s['client_name']} is booked with staff who no longer have this client",
                $staffBody . "\n" . $stranded)) && $allSent;
    } elseif ($s['user_id'] && !$s['active_staff_id']) {
        // The session still belongs to a deactivated (or removed) account, so no
        // staff member would hear about it: the administrators are asked to move it.
        // Any one administrator can act on it, so one successful email is enough.
        $anyAddress = true;
        $allSent = notify_admins(ucfirst($day) . "'s session for {$s['client_name']} belongs to a deactivated account — reassign it",
            "A session {$day} is still assigned to a staff account that is no longer active, so no staff member was reminded about it.\n\n"
            . "Client: {$s['client_name']}\nWhen: {$when}{$where}\n\n"
            . "Transfer that account's caseload on its Users page (this moves its upcoming sessions too): " . cfg('base_url') . '/users.php?id=' . (int)$s['user_id'] . "\n"
            . "Or cancel the session on the Schedule page and book it again with an active instructor: " . cfg('base_url') . "/schedule.php\n") && $allSent;
    }
    // Mark only when every addressed recipient was sent a reminder. A failed
    // reminder is retried by each later run until the session starts, and the
    // failures are reported below instead of being left to look like a quiet success.
    $label = $s['client_name'] . ' (' . $day . ' at ' . date('g:i A', $startTs);
    if ($allSent) {
        $mark->execute([(int)$s['id']]);
        if ($anyAddress) $sent++;
        if ($emailHeld) $unconfirmed[] = [(int)$s['client_id'], $label . ')'];
    } else {
        $failed[] = [(int)$s['client_id'], $label . ($badAddress ? ', the client email address is not valid' : '')
            . ($emailHeld ? ', the client email address has not been confirmed' : '') . ')'];
    }
}
echo "Reminders sent for {$sent} session(s).\n";
// One alert per session, linked to its client's record (never the bare Schedule
// page): the alert names the client, and purging that record (client.php) removes
// the notifications that link to it.
foreach ($unconfirmed as [$clientId, $label]) {
    notify_admin_users('Session reminder not emailed to ' . $label . ': the client\'s email address came from the public referral form and is not confirmed yet. '
        . 'Confirm it on the client\'s edit page.', 'client.php?id=' . $clientId);
}
if ($unconfirmed) echo count($unconfirmed) . " client(s) were not emailed: their email address has not been confirmed (the administrators were told).\n";
// The run itself completed: its heartbeat is recorded even when a reminder email
// failed (those are reported below, and the mail failure is noted for System health).
record_heartbeat('session_reminders');
if ($failed) {
    echo count($failed) . " session reminder(s) could not be sent.\n";
    foreach ($failed as [$clientId, $label]) {
        notify_admin_users('A session reminder could not be emailed for ' . $label . '. Contact the client directly.', 'client.php?id=' . $clientId);
    }
    exit(1);
}
