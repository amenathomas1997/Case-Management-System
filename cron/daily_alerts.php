<?php
declare(strict_types=1);
// Daily authorization alerts: expiration approaching (30/14/7 days) and 80% of hours or funds used.
// Cron: 0 7 * * *  — each alert sends once per track per threshold (sent_alerts table).
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

// One run at a time: alerts are marked only after they are emailed, so a manual
// run that overlaps the scheduled one (or a crontab line added twice) would email
// every alert twice. The lock is named for this database, so another installation
// on the same database server is not held up, and it ends with this process.
if ((int)db()->query("SELECT GET_LOCK(CONCAT('cms_daily_alerts_', MD5(DATABASE())), 0)")->fetchColumn() !== 1) {
    echo "Another daily alerts run is still working; this run sent nothing.\n";
    exit(0);
}

$alerts = [];          // ['text' => ..., 'assigned_to' => user id or null]

// The overdue-task and overdue-loan lists are a standing digest rather than
// one-off alerts, so they go out at most once per calendar day: a second run
// on the same day (manual, or a retried cron) must not mail the list again.
// The date is recorded only after the email is actually sent.
$digestKey = 'daily_alerts_digest_date';
$digestDue = setting($digestKey) !== date('Y-m-d');

// A closed or archived client's track that was left Active raises no alerts.
$tracks = db()->query("SELECT t.*, c.name AS client_name, c.assigned_to,
        DATEDIFF(t.expiration_date, CURDATE()) AS days_left
    FROM training_tracks t JOIN clients c ON c.id = t.client_id
    WHERE t.status = 'Active' AND c.archived_at IS NULL AND c.status NOT LIKE 'Closed%'")->fetchAll();

$already = [];
foreach (db()->query('SELECT track_id, alert_key FROM sent_alerts') as $row) {
    $already[$row['track_id'] . ':' . $row['alert_key']] = true;
}

// Each alert carries the sent_alerts marks it settles; they are recorded only
// after every recipient of that alert was actually emailed.
$mark = db()->prepare('INSERT IGNORE INTO sent_alerts (track_id, alert_key) VALUES (?,?)');
$unmark = db()->prepare('DELETE FROM sent_alerts WHERE track_id = ? AND alert_key = ?');

foreach ($tracks as $t) {
    $id = (int)$t['id'];
    $days = $t['days_left'];
    $label = "{$t['client_name']} — {$t['title']} (Auth {$t['auth_number']})";

    if ($days !== null && $days >= 0) {
        foreach ([7, 14, 30] as $threshold) {
            $key = 'exp' . $threshold;
            if ($days <= $threshold && empty($already["$id:$key"])) {
                // Mark every threshold this alert already covers, so a track first
                // noticed at 5 days doesn't fire the 14- and 30-day alerts too.
                $marks = [];
                foreach ([7, 14, 30] as $t2) {
                    if ($days <= $t2) $marks[] = [$id, 'exp' . $t2];
                }
                $alerts[] = ['text' => "EXPIRING in {$days} day(s) — {$label}, expires " . fmt_date($t['expiration_date']),
                             'assigned_to' => $t['assigned_to'] ? (int)$t['assigned_to'] : null, 'marks' => $marks];
                break;
            }
        }
    }

    // 80% of the authorization used: by hours, or by money, since mileage and
    // expenses spend the authorized funds without adding hours. When both are
    // reached on the same run they go out as one alert and both are marked.
    $r = track_rollup($id);
    $hoursLow = $r['authorized_hours'] > 0 && $r['pct_used'] >= 80;
    $fundsLow = $r['authorized_funds'] > 0 && $r['funds_remaining'] <= 0.2 * $r['authorized_funds'];
    $parts = [];
    $marks = [];
    if ($hoursLow && empty($already["$id:pct80"])) {
        $parts[] = "HOURS at {$r['pct_used']}% — {$label}: " . fmt_hours($r['hours_worked'])
            . ' of ' . fmt_hours($r['authorized_hours']) . ' used, ' . fmt_hours($r['hours_remaining']) . ' remaining';
    }
    if ($fundsLow && empty($already["$id:funds80"])) {
        $parts[] = ($parts ? 'FUNDS' : "FUNDS LOW — {$label}") . ': ' . fmt_money($r['funds_remaining'])
            . ' of ' . fmt_money($r['authorized_funds']) . ' remaining after hours, mileage and expenses';
    }
    if ($parts) {
        if ($hoursLow) $marks[] = [$id, 'pct80'];
        if ($fundsLow) $marks[] = [$id, 'funds80'];
        $alerts[] = ['text' => implode('; ', $parts),
            'assigned_to' => $t['assigned_to'] ? (int)$t['assigned_to'] : null, 'marks' => $marks];
    }
    // A mark follows the track's current state, not every writer: once usage
    // falls clearly back below the line (a trashed or corrected log, expense or
    // rate), the mark is cleared so the next real crossing alerts again. The
    // five-point margin keeps a track hovering at the line from re-alerting daily.
    $hoursClear = $r['authorized_hours'] <= 0 || $r['pct_used'] < 75;
    $fundsClear = $r['authorized_funds'] <= 0 || $r['funds_remaining'] > 0.25 * $r['authorized_funds'];
    if ($hoursClear && isset($already["$id:pct80"])) $unmark->execute([$id, 'pct80']);
    if ($fundsClear && isset($already["$id:funds80"])) $unmark->execute([$id, 'funds80']);
    // The client's counselor is emailed too, once per crossing (owner decision 2026-10-16;
    // portal link only). Marked only once it went, so a day without email tries again.
    if (($hoursLow || $fundsLow) && empty($already["$id:vrc80"])) {
        if (counselor_auth_notice($id)) $mark->execute([$id, 'vrc80']);
    } elseif ($hoursClear && $fundsClear && isset($already["$id:vrc80"])) {
        $unmark->execute([$id, 'vrc80']);
    }
}

// Waitlist check-ins: nobody waits silently — every ~3 weeks an open task
// reminds staff to check in with each waitlisted client. A check-in still open for
// a client who has left the waitlist (or been archived) no longer applies: it is
// closed here, or it would appear in the overdue digest every day.
db()->exec("UPDATE tasks t JOIN clients c ON c.id = t.client_id
    SET t.status = 'Done', t.done_at = NOW()
    WHERE t.status = 'Open' AND t.auto_key LIKE 'wait-%' AND (c.status <> 'Waitlist' OR c.archived_at IS NOT NULL)");
foreach (db()->query("SELECT id, name, assigned_to FROM clients WHERE status = 'Waitlist' AND archived_at IS NULL")->fetchAll() as $wc) {
    $open = db()->prepare("SELECT COUNT(*) FROM tasks WHERE client_id = ? AND auto_key LIKE 'wait-%' AND status = 'Open'");
    $open->execute([(int)$wc['id']]);
    if ((int)$open->fetchColumn() > 0) continue;
    $last = db()->prepare("SELECT MAX(done_at) FROM tasks WHERE client_id = ? AND auto_key LIKE 'wait-%'");
    $last->execute([(int)$wc['id']]);
    $lastDone = $last->fetchColumn();
    if ($lastDone !== null && strtotime((string)$lastDone) > time() - 21 * 86400) continue;
    add_task('Waitlist check-in: ' . $wc['name'],
        'This client is waiting for services — check in, update them, and move them off the waitlist if a slot opened.',
        (int)$wc['id'], null, business_days_from(3),
        $wc['assigned_to'] ? (int)$wc['assigned_to'] : null, null, 'wait-' . (int)$wc['id'] . '-' . date('Ymd'));
}

// On Hold check-ins (3.0.44), as for the Waitlist: about every three weeks, and when the
// expected return date comes, an open task asks staff to check in. A check-in still open
// for a client no longer On Hold is closed here.
try {
    db()->exec("UPDATE tasks t JOIN clients c ON c.id = t.client_id SET t.status = 'Done', t.done_at = NOW()
        WHERE t.status = 'Open' AND t.auto_key LIKE 'onhold-%' AND (c.status <> 'On Hold' OR c.archived_at IS NOT NULL)");
    foreach (db()->query("SELECT id, name, assigned_to, on_hold_reason, on_hold_until FROM clients WHERE status = 'On Hold' AND archived_at IS NULL")->fetchAll() as $oh) {
        $open = db()->prepare("SELECT COUNT(*) FROM tasks WHERE client_id = ? AND auto_key LIKE 'onhold-%' AND status = 'Open'");
        $open->execute([(int)$oh['id']]);
        if ((int)$open->fetchColumn() > 0) continue;
        $last = db()->prepare("SELECT MAX(COALESCE(done_at, created_at)) FROM tasks WHERE client_id = ? AND auto_key LIKE 'onhold-%'");
        $last->execute([(int)$oh['id']]);
        $lastAt = $last->fetchColumn();
        $returnDue = $oh['on_hold_until'] && $oh['on_hold_until'] <= date('Y-m-d') && ($lastAt === null || substr((string)$lastAt, 0, 10) < $oh['on_hold_until']);
        $sinceHold = db()->prepare("SELECT MAX(changed_at) FROM client_status_history WHERE client_id = ? AND new_status = 'On Hold'");
        $sinceHold->execute([(int)$oh['id']]);
        $from = max(strtotime((string)($lastAt ?? '1970-01-01')), strtotime((string)($sinceHold->fetchColumn() ?: '1970-01-01')));
        if (!$returnDue && $from > time() - 21 * 86400) continue;
        add_task('On Hold check-in: ' . $oh['name'],
            ($returnDue ? 'The expected return date (' . date('F j', strtotime($oh['on_hold_until'])) . ') has come. ' : 'This client has been On Hold for a while. ')
            . ($oh['on_hold_reason'] ? 'Reason: ' . $oh['on_hold_reason'] . '. ' : '') . 'Check in, and move them back to Active Training when they can continue.',
            (int)$oh['id'], null, business_days_from(3), $oh['assigned_to'] ? (int)$oh['assigned_to'] : null, null, 'onhold-' . (int)$oh['id'] . '-' . date('Ymd'));
    }
} catch (PDOException $e) {
    error_log('daily_alerts: On Hold check-ins skipped (' . $e->getCode() . ')'); // mig_3.0.44 not run yet
}

// After closure (3.0.47): a task for each 30, 60 or 90-day follow-up check that is due
// (recording the check completes it), and one reminder for an emailed outcome survey still
// unanswered after a week (the same link; marked only once it is sent).
try {
    // A case reopened since: its checks no longer apply, and their open tasks close.
    db()->exec("UPDATE tasks t JOIN closure_followups f ON t.auto_key = CONCAT('closefu-', f.id) JOIN clients c ON c.id = f.client_id
        SET t.status = 'Done', t.done_at = NOW() WHERE t.status = 'Open' AND f.status = 'Pending' AND c.status NOT LIKE 'Closed%'");
    db()->exec("UPDATE closure_followups f JOIN clients c ON c.id = f.client_id SET f.status = 'Cancelled' WHERE f.status = 'Pending' AND c.status NOT LIKE 'Closed%'");
    foreach (db()->query("SELECT f.id, f.client_id, f.interval_days, c.name, c.assigned_to FROM closure_followups f JOIN clients c ON c.id = f.client_id
        WHERE f.status = 'Pending' AND f.due_on <= CURDATE() AND c.archived_at IS NULL AND c.status LIKE 'Closed%'")->fetchAll() as $cf) {
        add_task((int)$cf['interval_days'] . '-day closure follow-up: ' . $cf['name'],
            'Call this former client: are they still employed or independent, still using their technology, and do they need more service? Record the answers in After closure on the client page.',
            (int)$cf['client_id'], null, business_days_from(3), $cf['assigned_to'] ? (int)$cf['assigned_to'] : null, null, 'closefu-' . (int)$cf['id']);
    }
    $rem = db()->query("SELECT s.id, s.token, s.client_id, c.name, c.email FROM surveys s JOIN clients c ON c.id = s.client_id
        WHERE s.completed_at IS NULL AND s.channel = 'Email' AND s.reminded_at IS NULL AND s.sent_at <= DATE_SUB(NOW(), INTERVAL 7 DAY)
          AND s.sent_at > DATE_SUB(NOW(), INTERVAL 60 DAY) AND c.archived_at IS NULL AND c.email_verified = 1 AND c.email IS NOT NULL
          AND (c.a11y_contact IS NULL OR c.a11y_contact NOT IN ('Phone', 'Postal mail'))
          AND NOT EXISTS (SELECT 1 FROM surveys s2 WHERE s2.client_id = s.client_id AND s2.completed_at IS NOT NULL AND s2.id > s.id)")->fetchAll();
    foreach ($rem as $sv) {
        if (!filter_var($sv['email'], FILTER_VALIDATE_EMAIL)) continue;
        $lang = client_lang((int)$sv['client_id']);
        [$subj, $body] = email_template_for_client('survey_invite', (int)$sv['client_id'], ['name' => $sv['name'], 'link' => cfg('base_url') . '/survey.php?t=' . $sv['token'] . ($lang === 'es' ? '&lang=es' : '')]);
        if (send_app_mail($sv['email'], 'Reminder: ' . $subj, $body)) db()->prepare('UPDATE surveys SET reminded_at = NOW() WHERE id = ?')->execute([(int)$sv['id']]);
    }
} catch (PDOException $e) {
    error_log('daily_alerts: closure follow-ups skipped (' . $e->getCode() . ')'); // mig_3.0.47 not run yet
}

// The Microsoft Graph secret (3.0.53): when it stops working no email goes out at all, so the
// administrators are told 30, 14 and 7 days before (and daily once it has passed). Old mail
// log rows are removed after 180 days.
try {
    $gd = graph_secret_days_left();
    if ($gd !== null && (in_array($gd, [30, 14, 7], true) || $gd <= 0) && setting('graph_expiry_noticed') !== date('Y-m-d')) {
        set_setting('graph_expiry_noticed', date('Y-m-d'));
        $gmsg = $gd > 0 ? 'The Microsoft Graph email secret expires in ' . $gd . ' days. Create a new secret in Microsoft Entra, then enter it and its new expiry date on System health.'
            : 'The Microsoft Graph email secret has expired, so the system cannot send email. Create a new secret in Microsoft Entra and enter it on System health.';
        notify_admin_users($gmsg, 'system_health.php#email-h');
        if ($gd > 0) notify_admins('CMS email secret expires in ' . $gd . ' days', $gmsg . "\n");
    }
} catch (Throwable $e) {
    error_log('daily_alerts: Graph expiry check skipped (' . get_class($e) . ')');
}
try { db()->exec('DELETE FROM mail_log WHERE sent_at < DATE_SUB(NOW(), INTERVAL 180 DAY)'); }
catch (Throwable $e) { error_log('daily_alerts: mail log not pruned (' . get_class($e) . ')'); }

// Turning 18 (3.0.37): a client with a guardian on file who turns 18 within two weeks
// (or did in the last month) gets one task, ever: their guardian's consent stops
// covering them, and they sign their own release of information.
foreach (db()->query("SELECT id, name, dob, assigned_to FROM clients
    WHERE archived_at IS NULL AND status NOT LIKE 'Closed%' AND dob IS NOT NULL AND COALESCE(guardian_name, '') <> ''
      AND DATE_ADD(dob, INTERVAL 18 YEAR) BETWEEN DATE_SUB(CURDATE(), INTERVAL 30 DAY) AND DATE_ADD(CURDATE(), INTERVAL 14 DAY)")->fetchAll() as $ad) {
    $key = 'turns18-' . (int)$ad['id'];
    $seen = db()->prepare('SELECT COUNT(*) FROM tasks WHERE auto_key = ?');
    $seen->execute([$key]);
    if ((int)$seen->fetchColumn() > 0) continue;
    $on = date('F j, Y', strtotime($ad['dob'] . ' +18 years'));
    add_task('Turns 18: ' . $ad['name'],
        'This client turns 18 on ' . $on . '. Their guardian\'s consent no longer covers them as an adult: ask them to sign their own release of information on the client page, and check who should now get reports and reminders.',
        (int)$ad['id'], null, business_days_from(3), $ad['assigned_to'] ? (int)$ad['assigned_to'] : null, null, $key);
}

// Privacy incidents (3.0.38): an open one whose 60-day notice deadline is two weeks away
// (or past) gets one task for an administrator. Closing the incident completes it.
try {
    $admins = effective_admin_user_ids();
    foreach (db()->query("SELECT id, kind, notice_deadline FROM privacy_incidents
        WHERE status = 'Open' AND notice_deadline <= DATE_ADD(CURDATE(), INTERVAL 14 DAY)")->fetchAll() as $pi) {
        $key = 'privinc-' . (int)$pi['id'];
        $seen = db()->prepare('SELECT COUNT(*) FROM tasks WHERE auto_key = ?');
        $seen->execute([$key]);
        if ((int)$seen->fetchColumn() > 0) continue;
        add_task('Privacy incident notice deadline: ' . $pi['kind'],
            'The notice deadline for this privacy incident is ' . date('F j, Y', strtotime($pi['notice_deadline'])) . '. Finish the risk assessment and record each notice sent, then close it: ' . cfg('base_url') . '/privacy_incidents.php?id=' . (int)$pi['id'],
            null, null, $pi['notice_deadline'] < date('Y-m-d') ? date('Y-m-d') : $pi['notice_deadline'], $admins[0] ?? null, null, $key);
    }
} catch (PDOException $e) {
    error_log('daily_alerts: privacy incident check skipped (' . $e->getCode() . ')'); // mig_3.0.38 not run yet
}

// Portal links about to lapse (3.0.40): a link unused for nearly six months stops working
// in 14 days. Its holder is emailed once (opening it renews it) and, for a client, the
// instructor gets an alert.
try {
    foreach (db()->query("SELECT p.id, p.token, p.expires_at, c.id AS cid, c.name, c.email, c.email_verified, c.preferred_language, c.assigned_to
        FROM portal_tokens p JOIN clients c ON c.id = p.client_id
        WHERE p.expiry_warned_at IS NULL AND c.archived_at IS NULL AND p.expires_at > NOW()
          AND p.expires_at <= DATE_ADD(NOW(), INTERVAL " . PORTAL_LINK_WARN_DAYS . " DAY)")->fetchAll() as $pt) {
        $date = date('F j, Y', strtotime($pt['expires_at']));
        $sent = false;
        if ((int)$pt['email_verified'] === 1 && filter_var($pt['email'] ?? '', FILTER_VALIDATE_EMAIL)) {
            [$subj, $body] = email_template_for_client('portal_expiring', $pt, ['name' => $pt['name'], 'date' => $date,
                'link' => cfg('base_url') . '/portal.php?t=' . $pt['token'] . (client_lang($pt) === 'es' ? '&lang=es' : '')]);
            $sent = send_app_mail((string)$pt['email'], $subj, $body);
        }
        if ($pt['assigned_to']) notify_user((int)$pt['assigned_to'], $pt['name'] . '\'s portal link stops working on ' . $date . ($sent ? ' (they were emailed)' : '') . '. Send a new one if they still need it.', 'client.php?id=' . (int)$pt['cid']);
        db()->prepare('UPDATE portal_tokens SET expiry_warned_at = NOW() WHERE id = ?')->execute([(int)$pt['id']]);
    }
    foreach (db()->query("SELECT id, token, expires_at, counselor_name, counselor_email FROM vrc_tokens
        WHERE expiry_warned_at IS NULL AND expires_at > NOW() AND expires_at <= DATE_ADD(NOW(), INTERVAL " . PORTAL_LINK_WARN_DAYS . " DAY)")->fetchAll() as $vt) {
        [$subj, $body] = email_template('portal_expiring', ['name' => $vt['counselor_name'], 'date' => date('F j, Y', strtotime($vt['expires_at'])),
            'link' => cfg('base_url') . '/vrc.php?t=' . $vt['token']]);
        if (filter_var($vt['counselor_email'], FILTER_VALIDATE_EMAIL)) send_app_mail((string)$vt['counselor_email'], $subj, $body);
        db()->prepare('UPDATE vrc_tokens SET expiry_warned_at = NOW() WHERE id = ?')->execute([(int)$vt['id']]);
    }
} catch (PDOException $e) {
    error_log('daily_alerts: portal link check skipped (' . $e->getCode() . ')'); // mig_3.0.40 not run yet
}

// Loan reminders to the client (3.0.42): three days before a loan is due and the day
// after it is overdue, each once (inc/portal_devices.php).
try { loan_reminders(); } catch (Throwable $e) { error_log('daily_alerts: loan reminders skipped (' . get_class($e) . ')'); }

// Equipment maintenance: open a task when a scheduled item is due (or overdue)
// for service. One open task per item at a time (idempotent via auto_key/day).
// A task is closed here once it no longer applies: a service was logged after the
// task opened (on the Equipment page or in the app, even one dated earlier, such
// as a service done last week and logged today), the service date was moved on to
// or past the day the task opened, or the item was retired or taken off its
// schedule. Otherwise a serviced item's task would stay open and appear in the
// overdue digest every day. An administrator's correction of the last service
// date (equipment.php logs it as 'Other', "Correction (not a service): …") is not
// a service: it closes the task only by moving the date on, as above.
db()->exec("UPDATE tasks t JOIN equipment e ON t.auto_key LIKE CONCAT('equip-maint-', e.id, '-%')
    SET t.status = 'Done', t.done_at = NOW()
    WHERE t.status = 'Open'
      AND (e.status = 'Retired' OR e.maintenance_interval_days IS NULL OR e.maintenance_interval_days <= 0
           OR (e.last_service_date IS NOT NULL AND e.last_service_date >= DATE(t.created_at))
           OR EXISTS (SELECT 1 FROM equipment_maintenance m WHERE m.equipment_id = e.id AND m.created_at >= t.created_at
                AND NOT (m.service_type = 'Other' AND COALESCE(m.notes, '') LIKE 'Correction (not a service)%')))");
foreach (db()->query("SELECT id, name, last_service_date, maintenance_interval_days
        FROM equipment WHERE status <> 'Retired' AND maintenance_interval_days IS NOT NULL AND maintenance_interval_days > 0")->fetchAll() as $eq) {
    $due = $eq['last_service_date']
        ? date('Y-m-d', strtotime($eq['last_service_date'] . ' +' . (int)$eq['maintenance_interval_days'] . ' days'))
        : date('Y-m-d');
    if ($due > date('Y-m-d', strtotime('+7 days'))) continue;   // not due within a week
    $open = db()->prepare("SELECT COUNT(*) FROM tasks WHERE auto_key LIKE ? AND status = 'Open'");
    $open->execute(['equip-maint-' . (int)$eq['id'] . '-%']);
    if ((int)$open->fetchColumn() > 0) continue;
    add_task('Equipment service due: ' . $eq['name'],
        'This item is scheduled for maintenance/calibration' . ($eq['last_service_date'] ? ' (last serviced ' . fmt_date($eq['last_service_date']) . ', due ' . fmt_date($due) . ')' : ' (never serviced)') . '. Log the service on the Equipment page.',
        null, null, business_days_from(3), null, null,
        'equip-maint-' . (int)$eq['id'] . '-' . date('Ymd'));
}

// Record retention: auto-archive clients closed longer than the configured
// number of years (0 or unset = off). Archived clients disappear from the
// day-to-day lists but stay intact until an admin exports and purges them.
$retYears = (int)(setting('retention_years') ?? '0');
if ($retYears > 0) {
    $arch = db()->prepare("UPDATE clients c SET c.archived_at = NOW()
        WHERE c.archived_at IS NULL AND c.status LIKE 'Closed%' AND c.hold_at IS NULL
          AND (SELECT MAX(h.changed_at) FROM client_status_history h WHERE h.client_id = c.id) < DATE_SUB(NOW(), INTERVAL ? YEAR)");
    $arch->execute([$retYears]);
    if ($arch->rowCount() > 0) {
        notify_admin_users($arch->rowCount() . ' closed client record(s) reached the ' . $retYears . '-year retention mark and were archived', 'clients.php?archived=1');
    }
}

// Overdue follow-up tasks — listed daily while open
$overdueTasks = !$digestDue ? [] : db()->query("SELECT t.title, t.due_date, t.assigned_to, u.name AS assignee, c.name AS client_name
    FROM tasks t LEFT JOIN users u ON u.id = t.assigned_to LEFT JOIN clients c ON c.id = t.client_id
    WHERE t.status = 'Open' AND t.due_date IS NOT NULL AND t.due_date < CURDATE()")->fetchAll();
foreach ($overdueTasks as $ot) {
    $alerts[] = ['text' => "TASK OVERDUE since " . fmt_date($ot['due_date']) . " — {$ot['title']}"
        . ($ot['client_name'] ? " ({$ot['client_name']})" : '')
        . ($ot['assignee'] ? " — assigned to {$ot['assignee']}" : ''),
        'assigned_to' => $ot['assigned_to'] ? (int)$ot['assigned_to'] : null, 'marks' => []];
}

// Purge trash entries older than 30 days (deleting stored files for documents).
// One entry that cannot be purged (a row locked by a restore in progress, a
// damaged payload) is retried tomorrow and never stops the alerts below.
// Key documents (Authorization, IPE, Release) and anything of a client on hold are
// kept for an administrator to purge by hand (3.0.37); each automatic purge is audited.
foreach (db()->query("SELECT id, entity, label, payload FROM trash WHERE deleted_at < DATE_SUB(NOW(), INTERVAL 30 DAY)")->fetchAll() as $tr) {
    try {
        if (!trash_auto_purge_allowed($tr)) continue;
        if (purge_trash((int)$tr['id'], true) !== null) audit(null, 'trash.purge_auto', 'trash', (int)$tr['id'], mb_substr((string)$tr['label'], 0, 200));
    } catch (Throwable $e) {
        error_log('daily_alerts: could not purge a trash entry (' . get_class($e) . ' ' . $e->getCode() . ')');
    }
}

// Housekeeping: login_attempts is also the rate-limit ledger (portal, AI, voice,
// password reset …). Limiter rows are written with success = 1 under a key that
// holds no "@", and no limiter looks back more than 24 hours (the daily caps on
// counselor-portal codes, "vrc-send-<id>", and portal uploads, "portal-up-<id>"),
// so they are dead weight after 30 days. Real sign-in rows (password attempts and
// "verify:<email>" code failures, with their IP addresses) are the only record of
// failed sign-ins that Audit log shows, so they are kept. The one limiter written
// with success = 0, "vrc-fail-<id>" (a wrong counselor-portal code), is kept the
// same way on purpose: it appears in that list as a failed code attempt. A
// failure here must never stop the alerts below from going out.
try {
    db()->exec("DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 30 DAY) AND success = 1 AND email NOT LIKE '%@%'");
} catch (Throwable $e) {
    error_log('daily_alerts: could not prune login_attempts (' . get_class($e) . ' ' . $e->getCode() . ')');
}

// Overdue equipment loans — listed daily while outstanding (these need chasing)
$overdueLoans = !$digestDue ? [] : db()->query("SELECT e.name, e.serial, c.name AS client_name, l.due_date
    FROM equipment_loans l JOIN equipment e ON e.id = l.equipment_id JOIN clients c ON c.id = l.client_id
    WHERE l.returned_at IS NULL AND l.due_date IS NOT NULL AND l.due_date < CURDATE()")->fetchAll();
foreach ($overdueLoans as $ol) {
    $alerts[] = ['text' => "EQUIPMENT OVERDUE — {$ol['name']}" . ($ol['serial'] ? " (SN {$ol['serial']})" : '')
        . " with {$ol['client_name']}, due back " . fmt_date($ol['due_date']), 'assigned_to' => null, 'marks' => []];
}

if ($alerts) {
    $header = "Authorization alerts for " . date('F j, Y') . ":\n\n- ";
    $footer = "\n\nReview your caseload: " . cfg('base_url') . "/index.php\n";
    // Administrators receive everything; the case worker assigned to a client also
    // receives that client's alerts. An alert is marked as sent once the
    // administrators (at least one of them) and its case worker were emailed, so a
    // mail outage (or a failed case worker mailbox) retries it on the next run
    // instead of swallowing it. Each administrator is emailed separately: one whose
    // email failed while another's went through is not sent the whole list again
    // tomorrow alongside the others, but is told in the app instead, and the run
    // exits non-zero. A failure on one side never stops the other side from being sent.
    $allText = implode("\n- ", array_column($alerts, 'text'));
    $adminSubject = 'Authorization alerts: ' . count($alerts) . ' item(s) need attention';
    $adminSent = false; $adminFailed = [];
    $adminList = effective_admin_user_ids();
    if ($adminList) {
        $q = db()->prepare('SELECT id, email FROM users WHERE id IN (' . implode(',', array_fill(0, count($adminList), '?')) . ')');
        $q->execute($adminList);
        foreach ($q->fetchAll() as $adm) {
            if (send_app_mail((string)$adm['email'], $adminSubject, $header . $allText . $footer)) $adminSent = true;
            else $adminFailed[] = (int)$adm['id'];
        }
    }
    if (!$adminSent) echo "The administrators' alert email failed to send — those alerts will retry on the next run.\n";
    elseif ($adminFailed) {
        foreach ($adminFailed as $aid) {
            notify_user($aid, 'The authorization alerts email for ' . date('F j, Y') . ' (' . count($alerts)
                . ' item(s)) could not be delivered to you. Review expiring authorizations and usage on the Compliance page.', 'compliance.php');
        }
        echo count($adminFailed) . " administrator alert email(s) failed to send; those administrators were notified in the app.\n";
    }

    // Assigned case workers receive only their clients' alerts. Effective
    // administrators (by role or by permission group, the same rule as
    // everywhere else) already have the full list, so they are skipped.
    $adminIds = function_exists('effective_admin_user_ids') ? array_map('intval', effective_admin_user_ids()) : null;
    $byInstructor = [];
    foreach ($alerts as $i => $a) {
        if ($a['assigned_to']) $byInstructor[$a['assigned_to']][] = $i;
    }
    $missed = [];          // alert index => true when one of its recipients was not emailed
    $instructorFailures = 0;
    $u = db()->prepare('SELECT id, email, role, group_id FROM users WHERE id = ? AND is_active = 1');
    foreach ($byInstructor as $uid => $indexes) {
        $u->execute([$uid]);
        $row = $u->fetch();
        if (!$row || (string)$row['email'] === '') continue;   // nobody else to tell; the administrators have it
        $isAdmin = $adminIds !== null ? in_array((int)$uid, $adminIds, true) : in_array('admin', resolve_perms($row)['perms'], true);
        if ($isAdmin) continue;
        $texts = array_map(fn(int $i): string => $alerts[$i]['text'], $indexes);
        if (!send_app_mail((string)$row['email'], 'Authorization alerts for your clients: ' . count($texts) . ' item(s)',
                $header . implode("\n- ", $texts) . $footer)) {
            $instructorFailures++;
            foreach ($indexes as $i) $missed[$i] = true;
        }
    }

    if ($adminSent) {
        foreach ($alerts as $i => $a) {
            if (empty($missed[$i])) foreach ($a['marks'] as [$mid, $mkey]) $mark->execute([$mid, $mkey]);
        }
        if ($digestDue) {
            try { set_setting($digestKey, date('Y-m-d')); }
            catch (Throwable $e) { error_log('daily_alerts: could not record the digest date (' . get_class($e) . ' ' . $e->getCode() . ')'); }
        }
        echo count($alerts) . " alert(s) sent to the administrators.\n";
    }
    if ($instructorFailures) echo "{$instructorFailures} case worker alert email(s) failed to send — those alerts will retry on the next run.\n";
    $runFailed = !$adminSent || $adminFailed || $instructorFailures;
} else {
    echo "No new alerts.\n";
    $runFailed = false;
}

// System health notice, once a day: the watchdog (cron/watchdog.php, every 10
// minutes) has not written its heartbeat for over 2 hours, or the newest database
// backup (cron/backup.sh) is older than 48 hours. The administrators are told by
// email so a silent cron failure is noticed; health.php reports the same checks
// to an external monitor. Recorded only after the email was sent.
try {
    $healthKey = 'daily_alerts_health_notice_date';
    if (setting($healthKey) !== date('Y-m-d')) {
        $healthProblems = [];
        $wd = setting('heartbeat_watchdog');
        $wdAt = $wd !== null ? strtotime($wd) : false;
        if ($wdAt === false) $healthProblems[] = 'The health watchdog (cron/watchdog.php) has never recorded a run. Check that its cron line is in place (every 10 minutes).';
        elseif ($wdAt < time() - 2 * 3600) $healthProblems[] = 'The health watchdog (cron/watchdog.php) has not run since ' . date('F j, Y g:i a', $wdAt) . '. Check its cron line (every 10 minutes).';
        $dumps = glob(dirname((string)cfg('upload_dir')) . '/backups/cms_*.sql.gz') ?: [];
        rsort($dumps);
        $newest = $dumps ? (int)filemtime($dumps[0]) : 0;
        if ($newest < time() - 48 * 3600) {
            $healthProblems[] = 'The nightly backup has not run since ' . ($newest > 0 ? date('F j, Y g:i a', $newest) : 'this system was installed (no backup file was found)') . '. Check the backup cron line (cron/backup.sh) and the backups directory.';
        }
        if ($healthProblems) {
            if (notify_admins('System health: ' . count($healthProblems) . ' item(s) need attention',
                    "The daily check on " . date('F j, Y') . " found:\n\n- " . implode("\n- ", $healthProblems)
                    . "\n\nThis notice is sent at most once a day while the problem lasts. The current status is at " . cfg('base_url') . "/health.php\n")) {
                set_setting($healthKey, date('Y-m-d'));
                echo count($healthProblems) . " system health item(s) emailed to the administrators.\n";
            } else {
                echo "The system health notice could not be emailed; it will be tried again on the next run.\n";
            }
        }
    }
} catch (Throwable $e) {
    error_log('daily_alerts: system health check failed (' . get_class($e) . ' ' . $e->getCode() . ')');
}

if ($runFailed) exit(1);
// Heartbeat for health.php: this run completed and everything it had to send was sent.
try { set_setting('heartbeat_daily_alerts', date('c')); }
catch (Throwable $e) { error_log('daily_alerts: could not record the heartbeat (' . get_class($e) . ' ' . $e->getCode() . ')'); }
