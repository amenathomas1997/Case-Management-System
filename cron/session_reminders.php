<?php
declare(strict_types=1);
// Session reminders: email client + instructor the day before a scheduled session.
// Run it morning and evening: the evening run reminds sessions booked for
// tomorrow after the morning run, and retries any reminder that failed then.
// reminder_sent stops a session being reminded twice. Cron: 15 7,17 * * *
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
// is still reminded, so the session can be cancelled.
$sessions = db()->query("SELECT s.*, c.name AS client_name, c.email AS client_email,
        (c.archived_at IS NULL AND c.status NOT LIKE 'Closed%') AS client_current,
        u.id AS active_staff_id, u.name AS staff_name, u.email AS staff_email,
        (u.id IS NOT NULL AND (g.id IS NULL OR g.own_caseload_only = 0 OR c.assigned_to = u.id)) AS staff_sees_client,
        a.name AS assignee_name, a.email AS assignee_email
    FROM scheduled_sessions s
    JOIN clients c ON c.id = s.client_id
    LEFT JOIN users u ON u.id = s.user_id AND u.is_active = 1
    LEFT JOIN `groups` g ON g.id = u.group_id
    LEFT JOIN users a ON a.id = c.assigned_to AND a.is_active = 1
    WHERE s.status = 'Scheduled' AND s.reminder_sent = 0
      AND DATE(s.start_at) = DATE_ADD(CURDATE(), INTERVAL 1 DAY)")->fetchAll();

$sent = 0; $failed = [];
$mark = db()->prepare('UPDATE scheduled_sessions SET reminder_sent = 1 WHERE id = ?');
foreach ($sessions as $s) {
    // With the time zone (e.g. "2:00 PM CDT"): a remote client may be elsewhere.
    $when = date('l, F j \a\t g:i A T', strtotime($s['start_at']));
    // A remote session says so and carries its join link (http(s) links only).
    $url = trim((string)($s['meeting_url'] ?? ''));
    $where = !empty($s['is_remote'])
        ? "\nWhere: online" . (preg_match('~^https?://\S+$~i', $url) ? "\nJoin link: {$url}" : '')
        : ($s['location'] ? "\nWhere: {$s['location']}" : '');
    $current = (bool)($s['client_current'] ?? true);
    $staffSees = !empty($s['staff_sees_client']);
    $allSent = true; $anyAddress = false; $badAddress = false;
    if ($s['client_email'] && $current) {
        $anyAddress = true;
        if (!filter_var($s['client_email'], FILTER_VALIDATE_EMAIL)) {
            // An address that cannot be emailed is skipped; the session is left
            // unmarked and reported below so someone contacts the client directly.
            $allSent = false; $badAddress = true;
        } else {
            [$subj, $body] = email_template('session_reminder_client', [
                'name' => $s['client_name'], 'when' => $when, 'where' => $where,
                'instructor' => ($staffSees ? $s['staff_name'] : null) ?? brand('short'),
            ]);
            $allSent = send_app_mail($s['client_email'], $subj, $body) && $allSent;
        }
    }
    $staffBody = "Session reminder.\n\nClient: {$s['client_name']}\nWhen: {$when}{$where}\n"
        . ($current ? '' : "\nThis client is closed or archived, so the client was not reminded. Cancel the session on the Schedule page if it no longer applies.\n");
    $staffSubject = 'Tomorrow: ' . $s['client_name'] . ' — ' . date('g:i A T', strtotime($s['start_at']));
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
            : notify_admins("Tomorrow's session for {$s['client_name']} is booked with staff who no longer have this client",
                $staffBody . "\n" . $stranded)) && $allSent;
    } elseif ($s['user_id'] && !$s['active_staff_id']) {
        // The session still belongs to a deactivated (or removed) account, so no
        // staff member would hear about it: the administrators are asked to move it.
        // Any one administrator can act on it, so one successful email is enough.
        $anyAddress = true;
        $allSent = notify_admins("Tomorrow's session for {$s['client_name']} belongs to a deactivated account — reassign it",
            "A session tomorrow is still assigned to a staff account that is no longer active, so no staff member was reminded about it.\n\n"
            . "Client: {$s['client_name']}\nWhen: {$when}{$where}\n\n"
            . "Transfer that account's caseload on its Users page (this moves its upcoming sessions too): " . cfg('base_url') . '/users.php?id=' . (int)$s['user_id'] . "\n"
            . "Or cancel the session on the Schedule page and book it again with an active instructor: " . cfg('base_url') . "/schedule.php\n") && $allSent;
    }
    // Mark only when every addressed recipient was sent a reminder. This job
    // looks only at tomorrow's sessions, so a failed reminder is retried only by
    // a later run on the same day (the evening run) — the failures are reported
    // below instead of being left to look like a quiet success.
    if ($allSent) {
        $mark->execute([(int)$s['id']]);
        if ($anyAddress) $sent++;
    } else {
        $failed[] = $s['client_name'] . ' (' . date('g:i A', strtotime($s['start_at']))
            . ($badAddress ? ', the client email address is not valid' : '') . ')';
    }
}
echo "Reminders sent for {$sent} session(s).\n";
if ($failed) {
    echo count($failed) . " session reminder(s) could not be sent.\n";
    notify_admin_users('Reminders for tomorrow could not be emailed for ' . count($failed) . ' session(s): '
        . implode(', ', $failed) . '. Contact those clients directly.', 'schedule.php');
    exit(1);
}
