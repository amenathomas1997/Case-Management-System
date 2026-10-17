<?php
// Administration: the agency-wide settings that used to sit beside the personal
// settings in My Account (account.php) — team and permission groups, sign-in and
// updates, forms and email, data retention and housekeeping, and the AI settings.
// Every section here needs the admin permission; personal settings stay in My Account.
require __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/scheduled_reports.php';
require_once __DIR__ . '/inc/health_checks.php';
require __DIR__ . '/inc/layout.php';
csrf_check();
$user = require_login();
require_perm('admin');

/* Check the vendor manifest for a newer version */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['check_updates'])) {
    $uc = update_check();
    if ($uc['ok']) {
        flash(version_compare((string)$uc['latest'], APP_VERSION, '>')
            ? 'A newer version is available: v' . $uc['latest'] . '.'
            : 'You are up to date (v' . APP_VERSION . ').');
    } elseif (update_source() === 'off') {
        // Off is a choice, not a failure (4.19.0): say how to turn it on.
        flash('Checking for updates is off on this site. To check this system\'s GitHub releases, and install them with one button, set "Where to check for new versions" to github on Settings, Updates.', 'error');
    } else {
        flash('The update server could not be reached. Try again later.', 'error');
    }
    redirect('admin.php#updates-h');
}

/* Record-retention setting */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_note_lock'])) {
    // Days after which a case note is locked (3.0.36; owner decision: 30). Later changes are addenda.
    $rawNl = $_POST['note_lock_days'] ?? '';
    $nl = is_string($rawNl) && preg_match('/^\d{1,3}$/', $rawNl) ? (int)$rawNl : -1;
    if (!in_array($nl, [0, 7, 14, 30, 60, 90], true)) {
        flash('The note lock was not changed: choose Off, 7, 14, 30, 60 or 90 days.', 'error');
        redirect('admin.php#notelock-h');
    }
    set_setting('note_lock_days', (string)$nl);
    audit($user['id'], 'note_lock.set', 'settings', null, $nl . ' days');
    flash($nl > 0 ? "Notes now lock {$nl} days after they are written." : 'Notes no longer lock; they can be edited at any time. Earlier versions are still kept.');
    redirect('admin.php#notelock-h');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_retention'])) {
    // Only the offered periods (or the period already stored, so an older
    // setting can be saved again unchanged); anything else is refused, never
    // stored as something other than what the audit log and message say.
    $rawRy = $_POST['retention_years'] ?? '';
    $ry = is_string($rawRy) && preg_match('/^\d{1,2}$/', $rawRy) ? (int)$rawRy : -1;
    $curRy = (int)(setting('retention_years') ?? '0');
    if (!in_array($ry, [0, 5, 6, 7, 10], true) && !($ry === $curRy && $ry >= 0 && $ry <= 25)) {
        flash('Record retention was not changed: choose Off, 5, 6, 7 or 10 years.', 'error');
        redirect('admin.php#retention-h');
    }
    set_setting('retention_years', (string)$ry);
    audit($user['id'], 'retention.set', 'settings', null, $ry . ' years');
    flash($ry > 0 ? "Retention set: clients closed for {$ry}+ years are archived nightly." : 'Retention automation is off.');
    redirect('admin.php#retention-h');
}

/* Billing details printed on every invoice, and the private-pay fee rule (3.0.34) */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_billing'])) {
    $in = fn(string $k): string => trim(is_string($_POST[$k] ?? null) ? $_POST[$k] : '');
    $v = ['vendor_number' => $in('billing_vendor_number'), 'tax_id' => $in('billing_tax_id'), 'remit_to' => $in('billing_remit_to'),
        'phone' => $in('billing_phone'), 'email' => strtolower($in('billing_email')), 'terms_days' => $in('billing_terms_days'),
        // The fee fields moved to Pricing rules (4.2.0); a post that still carries them is accepted.
        'private_rate' => isset($_POST['billing_private_rate']) ? $in('billing_private_rate') : number_format(billing_settings()['private_rate'], 2, '.', ''),
        'private_threshold' => isset($_POST['billing_private_threshold']) ? str_replace(',', '', $in('billing_private_threshold')) : number_format(billing_settings()['private_threshold'], 2, '.', '')];
    $bErrs = [];
    if (mb_strlen($v['vendor_number']) > 40) $bErrs['billing_vendor_number'] = 'Keep the vendor number to 40 characters or fewer.';
    if ($v['tax_id'] !== '' && !preg_match('/^[0-9-]{9,11}$/', $v['tax_id'])) $bErrs['billing_tax_id'] = 'Enter the tax ID (EIN) as 9 digits, with or without the hyphen, such as 12-3456789, or leave it blank.';
    if (mb_strlen($v['remit_to']) > 300) $bErrs['billing_remit_to'] = 'Keep the remit-to address to 300 characters or fewer.';
    if (mb_strlen($v['phone']) > 30 || !preg_match('/^[0-9+() \-]*$/', $v['phone'])) $bErrs['billing_phone'] = 'Enter the billing phone using only digits, spaces, plus, hyphens and parentheses (30 characters at most), or leave it blank.';
    if ($v['email'] !== '' && (!filter_var($v['email'], FILTER_VALIDATE_EMAIL) || strlen($v['email']) > 190)) $bErrs['billing_email'] = 'Enter the billing email in the form name@example.com, or leave it blank.';
    if (!ctype_digit($v['terms_days']) || (int)$v['terms_days'] > 365) $bErrs['billing_terms_days'] = 'Enter the payment terms as a whole number of days from 0 to 365, such as 30.';
    if (!is_numeric($v['private_rate']) || (float)$v['private_rate'] <= 0 || (float)$v['private_rate'] > 999.99) $bErrs['billing_private_rate'] = 'Enter the private-pay hourly fee as an amount more than $0, such as 17.50.';
    if (!is_numeric($v['private_threshold']) || (float)$v['private_threshold'] <= 0) $bErrs['billing_private_threshold'] = 'Enter the monthly income below which training is free, such as 2000.';
    if ($bErrs) { keep_input(); keep_field_errors($bErrs); redirect('admin.php#billing-h'); }
    $v['private_rate'] = number_format((float)$v['private_rate'], 2, '.', '');
    $v['private_threshold'] = number_format((float)$v['private_threshold'], 2, '.', '');
    foreach ($v as $k => $val) set_setting('billing_' . $k, $val);
    audit($user['id'], 'settings.billing', 'settings', null, 'vendor ' . ($v['vendor_number'] ?: '(none)') . ', terms ' . $v['terms_days'] . ' days, private pay ' . $v['private_rate'] . '/hour below ' . $v['private_threshold'] . '/month free');
    flash('Billing details saved. They print on every invoice from now on.');
    redirect('admin.php#billing-h');
}

/* Support phone number shown to clients and referrers on the public pages */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_support_phone'])) {
    $phone = trim(is_string($_POST['support_phone'] ?? null) ? $_POST['support_phone'] : '');
    // Loose: digits, spaces, plus, hyphens and parentheses, so an extension or a
    // country code can be written the way the agency writes it. Blank clears it.
    if (mb_strlen($phone) > 30 || !preg_match('/^[0-9+() \-]*$/', $phone)) {
        keep_input();
        keep_field_errors(['support_phone' => 'Enter the support phone number using only digits, spaces, plus, hyphens and parentheses (30 characters at most), or leave it blank to show none. Nothing was changed.']);
        redirect('admin.php#support-phone-h');
    }
    set_setting('support_phone', $phone);
    audit($user['id'], 'settings.support_phone', 'settings', null, $phone !== '' ? $phone : '(cleared)');
    flash($phone !== '' ? 'Support phone number saved: ' . $phone . '.' : 'Support phone number cleared: the public pages show none.');
    redirect('admin.php#support-phone-h');
}

/* Assign a user to a permission group */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_group'])) {
    $gid = (int)($_POST['group_id'] ?? 0) ?: null;
    $uid = (int)($_POST['uid'] ?? 0);
    try {
        change_user_access($uid, (int)$user['id'], ['group_id' => $gid], function (PDO $db) use ($gid, $uid): void {
            $db->prepare('UPDATE users SET group_id = ? WHERE id = ?')->execute([$gid, $uid]);
        });
    } catch (DomainException $e) { flash($e->getMessage(), 'error'); redirect('admin.php#team-h'); }
    audit($user['id'], 'user.group', 'user', (int)($_POST['uid'] ?? 0), $gid ? 'group #' . $gid : 'legacy role behavior');
    flash('Group updated — takes effect on that user\'s next page load.');
    redirect('admin.php#team-h');
}

/* Create a permission group */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_group'])) {
    $gname = trim(is_string($_POST['group_name'] ?? null) ? $_POST['group_name'] : '');
    $gdesc = trim(is_string($_POST['group_description'] ?? null) ? $_POST['group_description'] : '');
    $gperms = array_values(array_intersect((array)($_POST['group_perms'] ?? []), array_keys(PERMISSION_KEYS)));
    $gErrs = [];
    if ($gname === '') $gErrs['group_name'] = 'Enter a name for the group.';
    elseif (mb_strlen($gname) > 80) $gErrs['group_name'] = 'The group name is too long (80 characters maximum).';
    if (mb_strlen($gdesc) > 255) $gErrs['group_description'] = 'The description is too long (255 characters maximum).';
    if (!$gperms) $gErrs['group_perms'] = 'Tick at least one permission.';
    if ($gErrs) {
        keep_input();   // the ticked permissions too (old_in_list)
        keep_field_errors($gErrs);
    } else {
        try {
            db()->prepare('INSERT INTO `groups` (name, description, perms, own_caseload_only) VALUES (?,?,?,?)')
                ->execute([$gname, $gdesc ?: null, implode(';', $gperms), !empty($_POST['group_scoped']) ? 1 : 0]);
            audit($user['id'], 'group.add', 'group', (int)db()->lastInsertId(), $gname);
            flash('Group created.');
        } catch (PDOException $e) {
            keep_input();   // the ticked permissions too (old_in_list)
            if ((int)($e->errorInfo[1] ?? 0) === 1062) {
                keep_field_errors(['group_name' => 'A group with that name already exists. Choose another name.']);
            } else {
                error_log('admin.php add_group failed: ' . get_class($e) . ' ' . $e->getCode());
                flash('The group could not be created. Nothing was saved; what you typed is kept. Please try again.', 'error');
            }
        }
    }
    redirect('admin.php#create-group-h');
}

/* Set a team member's weekly available hours */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_hours'])) {
    $hoursRaw = trim(is_string($_POST['weekly_hours'] ?? null) ? $_POST['weekly_hours'] : '');
    $hoursUid = (int)($_POST['uid'] ?? 0);
    if (!is_numeric($hoursRaw) || (float)$hoursRaw < 0 || (float)$hoursRaw > 80) {
        // Never save a cleared or out-of-range box as 0 or 80 without saying so;
        // the message is on that person's row, with what was typed refilled.
        keep_input();
        keep_field_errors(['wh-' . $hoursUid => 'Enter weekly available hours as a number from 0 to 80. Nothing was changed.']);
        redirect('admin.php#team-h');
    }
    $weeklyHours = (float)$hoursRaw;
    db()->prepare('UPDATE users SET weekly_hours = ? WHERE id = ?')->execute([$weeklyHours, $hoursUid]);
    audit($user['id'], 'user.hours', 'user', $hoursUid, $weeklyHours . ' hrs/week');
    flash('Weekly hours updated.');
    redirect('admin.php#team-h');
}

/* Add a new user */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_user'])) {
    $name = trim(is_string($_POST['name'] ?? null) ? $_POST['name'] : '');
    $email = strtolower(trim(is_string($_POST['email'] ?? null) ? $_POST['email'] : ''));
    $pw = $_POST['password'] ?? '';
    $role = in_array($_POST['role'] ?? '', ['admin','instructor'], true) ? $_POST['role'] : 'instructor';
    // A new account is placed in a permission group, so it gets exactly what
    // that group allows (the seeded Instructors group: their own caseload).
    // "Legacy (by role)" stays available only when chosen on purpose: a legacy
    // staff account opens every client and billing.
    $rawGroup = is_string($_POST['group_id'] ?? null) ? $_POST['group_id'] : '';
    $newGroup = null;
    if (ctype_digit($rawGroup) && (int)$rawGroup > 0) {
        $gq = db()->prepare('SELECT id, perms FROM `groups` WHERE id = ?');
        $gq->execute([(int)$rawGroup]);
        $newGroup = $gq->fetch() ?: null;
    }
    // Each field is checked on its own and its message tied to it.
    $uErrs = [];
    if ($rawGroup !== '0' && !$newGroup) $uErrs['nu_group'] = 'Choose the permission group this person belongs to.';
    // With a group, the stored role follows it, so clearing the group later
    // never hands the account more than the group allowed.
    if ($newGroup) $role = in_array('admin', array_map('trim', explode(';', (string)$newGroup['perms'])), true) ? 'admin' : 'instructor';
    if ($name === '') $uErrs['nu_name'] = 'Enter the person\'s full name.';
    elseif (mb_strlen($name) > 120) $uErrs['nu_name'] = 'The full name is too long (120 characters maximum).';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $uErrs['nu_email'] = 'Enter a valid email address, for example name@agency.org.';
    // 183 so the sign-in limiter keys ('verify:' plus the address) fit login_attempts.email.
    elseif (mb_strlen($email) > 183) $uErrs['nu_email'] = 'The email address is too long (183 characters maximum).';
    // Empty: an invitation is emailed instead, so nobody types a password for someone else (3.0.55).
    $invite = !is_string($pw) || $pw === '';
    if (!$invite && strlen($pw) < 10) $uErrs['nu_password'] = 'Enter a temporary password of at least 10 characters, or leave it empty to email an invitation.';
    if ($uErrs) {
        keep_input();
        keep_field_errors($uErrs);
    } else {
        try {
            db()->prepare('INSERT INTO users (name, email, password_hash, role, group_id) VALUES (?,?,?,?,?)')
                ->execute([$name, $email, password_hash($invite ? bin2hex(random_bytes(24)) : $pw, PASSWORD_DEFAULT), $role, $newGroup ? (int)$newGroup['id'] : null]);
            $newUid = (int)db()->lastInsertId();
            audit($user['id'], 'user.create', 'user', $newUid, "{$name} <{$email}> role={$role} group="
                . ($newGroup ? (int)$newGroup['id'] : 'legacy'));
            if ($invite) {
                $invSent = staff_invite_send($newUid, (int)$user['id']);
                flash($invSent ? "User {$name} added, and an invitation was emailed to {$email} to choose a password (it works for 7 days)."
                    : "User {$name} added, but the invitation could not be emailed. Use Send invitation again on their user page.", $invSent ? 'success' : 'error');
            } else {
                flash("User {$name} added.");
            }
        } catch (PDOException $e) {
            keep_input();
            if ((int)($e->errorInfo[1] ?? 0) === 1062) {
                keep_field_errors(['nu_email' => 'A team member with that email address already exists.']);
            } else {
                error_log('admin.php add_user failed: ' . get_class($e) . ' ' . $e->getCode());
                flash('The user could not be added. Nothing was saved; the name and email you typed are kept. Please try again.', 'error');
            }
        }
    }
    redirect('admin.php#add-user-h');
}

/* Scheduled report emails (cron/monthly_reports.php reads these settings) */
/* Counselor progress reports: wait for a narrative (the default) or send automatically. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_m365'])) {
    $on = !empty($_POST['m365_offboard']);
    set_setting('m365_offboard', $on ? '1' : '0');
    audit($user['id'], 'settings.m365_offboard', 'settings', null, $on ? 'on' : 'off');
    flash($on ? 'Each night, staff whose Microsoft 365 account is disabled are switched off here (administrators are reported, not switched off).' : 'Microsoft 365 departures are no longer checked.');
    redirect('admin.php#people-h');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_fiscal_year'])) {
    $fy = (int)($_POST['fiscal_year_start'] ?? 0);
    if ($fy < 1 || $fy > 12) { flash('Choose the month your fiscal year starts.', 'error'); redirect('admin.php#fiscal-h'); }
    set_setting('fiscal_year_start', (string)$fy);
    audit($user['id'], 'settings.fiscal_year', 'settings', null, 'starts in ' . date('F', mktime(0, 0, 0, $fy, 1)));
    flash('The fiscal year now starts in ' . date('F', mktime(0, 0, 0, $fy, 1)) . '. Reports use it for Fiscal year to date and Last fiscal year.');
    redirect('admin.php#fiscal-h');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_report_sending'])) {
    $auto = ($_POST['reports_auto_send'] ?? '') === '1';
    set_setting('reports_auto_send', $auto ? '1' : '0');
    audit($user['id'], 'settings.reports_auto_send', 'settings', null, $auto ? 'automatic' : 'wait for a narrative');
    flash($auto ? 'Due progress reports will be emailed to counselors automatically.' : 'Due progress reports now wait: each one opens a task for the instructor to add a narrative and send it.');
    redirect('admin.php#report-sending-h');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_scheduled_reports'])) {
    $srOutcomes = !empty($_POST['sr_outcomes']);
    $srFunder = !empty($_POST['sr_funder']);
    $srBusiness = !empty($_POST['sr_business']);
    $srRaw = trim(is_string($_POST['sr_recipients'] ?? null) ? $_POST['sr_recipients'] : '');
    $srParsed = scheduled_reports_parse_recipients($srRaw);
    $srDayRaw = is_string($_POST['sr_day'] ?? null) ? $_POST['sr_day'] : '';
    $srDay = preg_match('/^\d{1,2}$/', $srDayRaw) ? (int)$srDayRaw : 0;
    $srPeriod = is_string($_POST['sr_period'] ?? null) ? $_POST['sr_period'] : '';
    $srErrs = [];
    if ($srParsed['invalid']) $srErrs['sr_recipients'] = 'These are not valid email addresses: ' . implode(', ', array_map(fn($v) => mb_substr($v, 0, 60), $srParsed['invalid'])) . '. Enter one address per line, for example director@agency.org.';
    elseif (($srOutcomes || $srFunder || $srBusiness) && !$srParsed['valid']) $srErrs['sr_recipients'] = 'Enter at least one email address to send the reports to, or turn the reports off.';
    elseif (count($srParsed['valid']) > 20) $srErrs['sr_recipients'] = 'Enter at most 20 email addresses.';
    if ($srDay < 1 || $srDay > 28) $srErrs['sr_day'] = 'Choose a day of the month from 1 to 28.';
    if (!isset(SCHEDULED_REPORT_PERIODS[$srPeriod])) $srErrs['sr_period'] = 'Choose the period each report covers.';
    if ($srErrs) {
        keep_input();
        keep_field_errors($srErrs);
        redirect('admin.php#scheduled-h');
    }
    set_setting('scheduled_reports_outcomes_enabled', $srOutcomes ? '1' : '0');
    set_setting('scheduled_reports_funder_enabled', $srFunder ? '1' : '0');
    set_setting('scheduled_reports_business_enabled', $srBusiness ? '1' : '0');
    set_setting('scheduled_reports_recipients', implode(',', $srParsed['valid']));
    set_setting('scheduled_reports_day', (string)$srDay);
    set_setting('scheduled_reports_period', $srPeriod);
    audit($user['id'], 'settings.scheduled_reports', 'settings', null,
        'outcomes=' . ($srOutcomes ? 'on' : 'off') . ' funder=' . ($srFunder ? 'on' : 'off') . ' day=' . $srDay . ' period=' . $srPeriod . ' to ' . count($srParsed['valid']) . ' address(es)');
    flash(($srOutcomes || $srFunder)
        ? 'Scheduled reports saved: ' . implode(' and ', array_filter([$srOutcomes ? 'the outcome report' : '', $srFunder ? 'the funder outcome report' : ''])) . ' will be emailed on day ' . $srDay . ' of each month for the ' . strtolower(SCHEDULED_REPORT_PERIODS[$srPeriod]) . '.'
        : 'Scheduled reports saved: both reports are off, so nothing will be emailed.');
    redirect('admin.php#scheduled-h');
}

$allGroups = db()->query('SELECT * FROM `groups` ORDER BY name')->fetchAll();

page_header('Administration', $user, 'admin');
?>
<h1>Administration</h1>
<p class="muted">Agency-wide settings for administrators. Your own password, sign-in methods, display settings and calendar address are in <a href="account.php">My Account</a>.</p>
<?php // One line from System health: the scheduled jobs, which stop silently when they stop.
$jobsBehind = array_values(array_filter(health_job_rows(true), fn($j) => !$j['ok'])); ?>
<p><?= $jobsBehind ? '<strong>' . (count($jobsBehind) === 1 ? '1 scheduled job needs' : count($jobsBehind) . ' scheduled jobs need') . ' attention</strong> (' . h(implode(', ', array_column($jobsBehind, 'name'))) . '). ' : 'All scheduled jobs are running. ' ?><a href="system_health.php">Open System health</a> · <a href="compliance.php#staff-h">Staff agreements and training</a> · <a href="client_import.php">Import clients and authorizations</a> · <a href="settings.php">Settings: email, addresses, sign-in, time zone and branding</a> · <a href="upgrade.php">Database upgrade</a><?php $schemaHave = db_schema_version(); if ($schemaHave === null || version_compare($schemaHave, DB_SCHEMA_VERSION, '<')): ?> <strong>The database needs an upgrade.</strong><?php endif; ?></p>
<nav aria-label="Administration sections">
  <ul class="tab-nav">
    <li><a href="#people-h">People &amp; permissions</a></li>
    <li><a href="#signin-h">Sign-in &amp; security</a></li>
    <li><a href="#forms-h">Forms &amp; email</a></li>
    <li><a href="#billing-h">Billing details</a></li>
    <li><a href="#data-h">Data</a></li>
    <li><a href="#scheduled-h">Scheduled reports</a></li>
    <li><a href="#ai-h">AI</a></li>
  </ul>
</nav>

<section class="card" aria-labelledby="people-h">
  <h2 id="people-h">People &amp; permissions</h2>
  <p class="muted">Quick group and hours changes live here — for everything else (edit details, password resets, two-factor (2FA) sign-in recovery, caseload transfer, deactivate or delete), open <a href="users.php">User management</a>.</p>
  <form method="post" action="admin.php#people-h" class="inline-form"><?= csrf_field() ?>
    <label><input type="checkbox" name="m365_offboard" value="1"<?= setting('m365_offboard') === '1' ? ' checked' : '' ?>> Each night, switch off staff whose Microsoft 365 account is disabled (needs the User.Read.All permission in Microsoft Entra)</label>
    <button type="submit" name="save_m365" value="1" class="btn btn-secondary btn-sm">Save</button>
  </form>
  <h3 id="add-user-h">Add a team member</h3>
  <?php $nuRefill = old_in('add_user') !== ''; // refill only after this form was sent
  // Preselected: what was chosen before an error, else the seeded own-caseload Instructors group.
  $nuGroup = $nuRefill ? old_in('group_id') : (string)(array_column($allGroups, 'id', 'name')['Instructors'] ?? ''); ?>
  <form method="post" action="admin.php" class="stacked" novalidate>
    <?= csrf_field() ?>
    <?= form_error_summary(['nu_name' => 'Full name', 'nu_email' => 'Email address', 'nu_password' => 'Temporary password', 'nu_group' => 'Permission group']) ?>
    <div class="field">
      <label for="nu_name">Full name *</label>
      <input type="text" id="nu_name" name="name" required value="<?= h($nuRefill ? old_in('name') : '') ?>" <?= field_aria('nu_name') ?>>
      <?= field_error('nu_name') ?>
    </div>
    <div class="field">
      <label for="nu_email">Email address *</label>
      <input type="email" id="nu_email" name="email" required value="<?= h($nuRefill ? old_in('email') : '') ?>" <?= field_aria('nu_email') ?>>
      <?= field_error('nu_email') ?>
    </div>
    <div class="field">
      <label for="nu_password">Temporary password (leave empty to email them an invitation instead; otherwise at least 10 characters)</label>
      <?php if ($nuRefill): ?><span class="hint" id="nu_password-again">For safety a typed password is never kept after an error: type it again.</span><?php endif; ?>
 <input type="text" id="nu_password" name="password" minlength="10" autocomplete="off" <?= field_aria('nu_password', $nuRefill ? 'nu_password-again' : '') ?>>
      <?= field_error('nu_password') ?>
    </div>
    <div class="field">
      <label for="nu_group">Permission group *</label>
      <span class="hint" id="nu_group-hint">Decides which areas this person can open. "Own caseload only" groups see just the clients assigned to them.</span>
      <select id="nu_group" name="group_id" required <?= field_aria('nu_group', 'nu_group-hint') ?>>
        <option value="">— Choose a group —</option>
        <?php foreach ($allGroups as $g): ?>
        <option value="<?= (int)$g['id'] ?>" <?= $nuGroup === (string)(int)$g['id'] ? 'selected' : '' ?>><?= h($g['name']) ?><?= $g['own_caseload_only'] ? ' (own caseload only)' : '' ?></option>
        <?php endforeach; ?>
        <option value="0" <?= $nuGroup === '0' ? 'selected' : '' ?>>Legacy (by role below)</option>
      </select>
      <?= field_error('nu_group') ?>
    </div>
    <div class="field">
      <label for="nu_role">Legacy role</label>
      <span class="hint" id="nu_role-hint">Used only with "Legacy (by role below)": staff then see every client and billing, admins everything. Both can provide any service, including counseling to Pre-ETS students.</span>
      <select id="nu_role" name="role" aria-describedby="nu_role-hint">
        <option value="instructor">Staff (instructor/counselor)</option>
        <option value="admin" <?= $nuRefill && old_in('role') === 'admin' ? 'selected' : '' ?>>Admin</option>
      </select>
    </div>
    <button type="submit" name="add_user" value="1" class="btn btn-primary">Add user</button>
  </form>

  <h3 id="team-h">Team members</h3>
  <?php $teamRows = db()->query('SELECT id, name, email, role, group_id, weekly_hours FROM users WHERE is_active = 1 ORDER BY name')->fetchAll();
  $hoursRefill = old_in('set_hours') !== '' ? old_in('uid') : '';
  if ($teamRows) echo form_error_summary(array_column(array_map(fn($r) => ['wh-' . (int)$r['id'], 'Weekly available hours for ' . $r['name']], $teamRows), 1, 0)); ?>
  <div class="table-scroll" tabindex="0" role="region" aria-label="Team members">
    <table>
      <caption class="visually-hidden">Team members</caption>
      <thead><tr><th scope="col">Name</th><th scope="col">Email</th><th scope="col">Role</th><th scope="col">Permission group</th><th scope="col">Available hours per week</th></tr></thead>
      <tbody>
      <?php foreach ($teamRows as $u): ?>
        <tr>
          <th scope="row"><?= h($u['name']) ?></th><td><?= h($u['email']) ?></td><td><?= h($u['role']) ?></td>
          <td>
            <form method="post" action="admin.php" class="inline-form"><?= csrf_field() ?>
              <input type="hidden" name="uid" value="<?= (int)$u['id'] ?>">
              <label class="visually-hidden" for="gr-<?= (int)$u['id'] ?>">Permission group for <?= h($u['name']) ?></label>
              <select id="gr-<?= (int)$u['id'] ?>" name="group_id">
                <option value="0">— Legacy (by role) —</option>
                <?php foreach ($allGroups as $g): ?>
                <option value="<?= (int)$g['id'] ?>" <?= (int)($u['group_id'] ?? 0) === (int)$g['id'] ? 'selected' : '' ?>><?= h($g['name']) ?></option>
                <?php endforeach; ?>
              </select>
              <button type="submit" name="set_group" value="1" class="btn btn-secondary btn-sm">Save<span class="visually-hidden"> permission group for <?= h($u['name']) ?></span></button>
            </form>
          </td>
          <td>
            <form method="post" action="admin.php" class="inline-form"><?= csrf_field() ?>
              <input type="hidden" name="uid" value="<?= (int)$u['id'] ?>">
              <label class="visually-hidden" for="wh-<?= (int)$u['id'] ?>">Weekly available hours for <?= h($u['name']) ?></label>
              <input type="number" id="wh-<?= (int)$u['id'] ?>" name="weekly_hours" step="0.5" min="0" max="80"
                     value="<?= h($hoursRefill === (string)(int)$u['id'] ? old_in('weekly_hours') : rtrim(rtrim(number_format((float)$u['weekly_hours'], 2), '0'), '.')) ?>" size="4"
                     <?= field_aria('wh-' . (int)$u['id']) ?>>
              <button type="submit" name="set_hours" value="1" class="btn btn-secondary btn-sm">Save<span class="visually-hidden"> weekly hours for <?= h($u['name']) ?></span></button>
              <?= field_error('wh-' . (int)$u['id']) ?>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <p class="hint">Available hours feed the <a href="capacity.php">capacity planning</a> view. Counselor portal access is managed under <a href="#forms-h">Forms &amp; email</a>.</p>

  <h3 id="groups">Permission groups</h3>
  <p class="muted">A group decides which areas its members can open. "Own caseload only" limits client records to those assigned to the member. Users on "Legacy (by role)" behave as before: admins see everything, instructors see everything except administration.</p>
  <div class="table-scroll" tabindex="0" role="region" aria-labelledby="groups">
    <table>
      <caption class="visually-hidden">Permission groups</caption>
      <thead><tr><th scope="col">Group</th><th scope="col">Permissions</th><th scope="col">Caseload</th></tr></thead>
      <tbody>
      <?php foreach ($allGroups as $g): ?>
        <tr>
          <th scope="row"><?= h($g['name']) ?><?= $g['description'] ? '<br><span class="muted">' . h($g['description']) . '</span>' : '' ?></th>
          <td><?= h(implode(' · ', array_map(fn($p) => PERMISSION_KEYS[$p] ?? $p, array_filter(explode(';', $g['perms']))))) ?></td>
          <td><?= $g['own_caseload_only'] ? 'Own caseload only' : 'All clients' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <h3 id="create-group-h">Create a group</h3>
  <?php // After an error, the ticked choices come back as they were (3.3.7).
  $groupRefill = old_in('add_group') !== '';
  $keptPerms = $groupRefill ? old_in_list('group_perms') : [];
  $firstPerm = array_key_first(PERMISSION_KEYS); ?>
  <form method="post" action="admin.php" class="stacked" novalidate>
    <?= csrf_field() ?>
    <?= form_error_summary(['group_name' => 'Group name', 'group_description' => 'Description', 'group_perms' => 'Permissions'], ['group_perms' => 'gp-' . $firstPerm]) ?>
    <div class="field"><label for="group_name">Group name *</label>
      <input type="text" id="group_name" name="group_name" required value="<?= h($groupRefill ? old_in('group_name') : '') ?>" <?= field_aria('group_name') ?>>
      <?= field_error('group_name') ?></div>
    <div class="field"><label for="group_description">Description</label>
      <input type="text" id="group_description" name="group_description" value="<?= h($groupRefill ? old_in('group_description') : '') ?>" <?= field_aria('group_description') ?>>
      <?= field_error('group_description') ?></div>
    <fieldset <?= has_field_error('group_perms') ? 'aria-describedby="err-group_perms"' : '' ?>>
      <legend>Permissions *</legend>
      <?= field_error('group_perms') ?>
      <?php foreach (PERMISSION_KEYS as $key => $label): ?>
      <div class="field"><label><input type="checkbox" id="gp-<?= h($key) ?>" name="group_perms[]" value="<?= h($key) ?>" <?= in_array($key, $keptPerms, true) ? 'checked' : '' ?>> <?= h($label) ?></label></div>
      <?php endforeach; ?>
    </fieldset>
    <div class="field"><label><input type="checkbox" name="group_scoped" value="1" <?= $groupRefill && old_in('group_scoped') === '1' ? 'checked' : '' ?>> Own caseload only (members see just their assigned clients)</label></div>
    <button type="submit" name="add_group" value="1" class="btn btn-primary">Create group</button>
  </form>
</section>

<section class="card" aria-labelledby="signin-h">
  <h2 id="signin-h">Sign-in &amp; security</h2>
  <h3 id="federation">Microsoft sign-in (federation)</h3>
  <p class="muted">Let the team sign in with their Microsoft work accounts instead of a password and emailed code.</p>
  <p><a class="btn btn-secondary" href="federation.php">Set up Microsoft Entra ID federation</a></p>
  <h3 id="updates-h">Software updates</h3>
  <?php
  $updLatest = setting('update_latest');
  $updChecked = update_checked_words();
  $updAttempted = update_checked_words('update_attempted');
  // A check from before this version was installed says nothing about now (4.19.1).
  $updFresh = $updLatest !== null && version_compare($updLatest, APP_VERSION, '>=');
  $updNewer = $updLatest !== null && version_compare($updLatest, APP_VERSION, '>');
  $updFailed = $updAttempted !== '' && strtotime((string)setting('update_attempted')) > strtotime((string)(setting('update_checked') ?? '1970-01-01')) + 5;
  ?>
  <p>
    This system is <strong>v<?= h(APP_VERSION) ?></strong>.
    <?php if (update_source() === 'off'): ?><span class="muted">Checking for updates is off (Settings, Updates).</span>
    <?php elseif ($updNewer): ?><span class="badge badge-pending">Update available: v<?= h($updLatest) ?></span>
      <?php if (setting('update_notes')): ?><br><span class="muted"><?= h(setting('update_notes')) ?></span><?php endif; ?>
    <?php elseif ($updFresh && $updChecked !== ''): ?><span class="muted">Up to date (last checked <?= h($updChecked) ?>).</span>
    <?php else: ?><span class="muted">Not checked since this version was installed.</span><?php endif; ?>
    <?php if ($updFailed): ?><br><strong>The last check, <?= h($updAttempted) ?>, could not reach the update server.</strong><?php endif; ?>
  </p>
  <form method="post" action="admin.php" class="inline-form"><?= csrf_field() ?>
    <button type="submit" name="check_updates" value="1" class="btn btn-secondary btn-sm">Check for updates</button>
  </form>
  <?php if (update_source() === 'github'): // 4.18.0 ?>
  <p><a class="btn <?= $updNewer ? 'btn-primary' : 'btn-secondary' ?>" href="update.php"><?= $updNewer ? 'Install the update' : 'Software update' ?></a></p>
  <?php endif; ?>
</section>

<section class="card" aria-labelledby="forms-h">
  <h2 id="forms-h">Forms &amp; email</h2>
  <div class="page-actions">
    <a class="btn btn-secondary" href="referral_settings.php">Referral form settings</a>
    <a class="btn btn-secondary" href="email_templates.php">Email templates</a>
    <a class="btn btn-secondary" href="wording.php">Public wording</a>
    <a class="btn btn-secondary" href="counselors.php">Counselor portal access</a>
  </div>
  <h3 id="support-phone-h">Support phone number</h3>
  <?php $curPhone = old_in('save_support_phone') !== '' ? old_in('support_phone') : (string)(setting('support_phone') ?? ''); ?>
  <form method="post" action="admin.php" class="stacked" novalidate><?= csrf_field() ?>
    <?= form_error_summary(['support_phone' => 'Support phone number']) ?>
    <div class="field">
      <label for="support_phone">Support phone number</label>
      <span class="hint" id="support_phone-hint">Shown to clients, families, guardians and referrers on the public pages (the client portal, the public referral form and consent and survey links) as the number to call for help. Digits, spaces, plus, hyphens and parentheses only, up to 30 characters. Leave it blank to show none.</span>
      <input type="text" id="support_phone" name="support_phone" inputmode="tel" autocomplete="off" maxlength="30" value="<?= h($curPhone) ?>" <?= field_aria('support_phone', 'support_phone-hint') ?>>
      <?= field_error('support_phone') ?>
    </div>
    <button type="submit" name="save_support_phone" value="1" class="btn btn-secondary btn-sm">Save support phone number</button>
  </form>
</section>

<section class="card" aria-labelledby="billing-h">
  <h2 id="billing-h">Billing details</h2>
  <p class="muted">Printed on every invoice, in the PDF and the accessible version. VR payment systems match payments on the vendor number and the remit-to address. An agency with its own vendor number, terms or billing address uses those instead: see <a href="funding_agencies.php">Funding agencies</a>.</p>
  <?php $bs = billing_settings(); $bv = fn(string $k, string $cur) => old_in('save_billing') !== '' ? old_in('billing_' . $k) : $cur; ?>
  <form method="post" action="admin.php#billing-h" class="stacked" novalidate><?= csrf_field() ?>
    <?= form_error_summary(['billing_vendor_number' => 'Vendor number', 'billing_tax_id' => 'Tax ID', 'billing_remit_to' => 'Remit-to address', 'billing_phone' => 'Billing phone',
        'billing_email' => 'Billing email', 'billing_terms_days' => 'Payment terms', 'billing_private_rate' => 'Private-pay hourly fee', 'billing_private_threshold' => 'Free below this monthly income']) ?>
    <div class="grid grid-2">
      <div class="field"><label for="billing_vendor_number">VR vendor or provider number</label>
        <input type="text" id="billing_vendor_number" name="billing_vendor_number" maxlength="40" autocomplete="off" value="<?= h($bv('vendor_number', $bs['vendor_number'])) ?>" <?= field_aria('billing_vendor_number') ?>><?= field_error('billing_vendor_number') ?></div>
      <div class="field"><label for="billing_tax_id">Tax ID (EIN)</label>
        <input type="text" id="billing_tax_id" name="billing_tax_id" maxlength="11" autocomplete="off" value="<?= h($bv('tax_id', $bs['tax_id'])) ?>" <?= field_aria('billing_tax_id') ?>><?= field_error('billing_tax_id') ?></div>
    </div>
    <div class="field"><label for="billing_remit_to">Remit-to address</label>
      <textarea id="billing_remit_to" name="billing_remit_to" rows="3" maxlength="300" <?= field_aria('billing_remit_to') ?>><?= h($bv('remit_to', $bs['remit_to'])) ?></textarea><?= field_error('billing_remit_to') ?></div>
    <div class="grid grid-2">
      <div class="field"><label for="billing_phone">Billing phone</label>
        <input type="text" id="billing_phone" name="billing_phone" inputmode="tel" maxlength="30" autocomplete="off" value="<?= h($bv('phone', $bs['phone'])) ?>" <?= field_aria('billing_phone') ?>><?= field_error('billing_phone') ?></div>
      <div class="field"><label for="billing_email">Billing email</label>
        <input type="email" id="billing_email" name="billing_email" maxlength="190" autocomplete="off" value="<?= h($bv('email', $bs['email'])) ?>" <?= field_aria('billing_email') ?>><?= field_error('billing_email') ?></div>
    </div>
    <div class="field"><label for="billing_terms_days">Payment terms (days after the invoice is submitted)</label>
      <input type="text" inputmode="numeric" id="billing_terms_days" name="billing_terms_days" maxlength="3" value="<?= h($bv('terms_days', (string)$bs['terms_days'])) ?>" <?= field_aria('billing_terms_days') ?>><?= field_error('billing_terms_days') ?></div>
    <p>The personal rate, the sliding fee and the other pricing rules are on <a href="pricing.php">Pricing rules</a>.</p>
    <button type="submit" name="save_billing" value="1" class="btn btn-secondary btn-sm">Save billing details</button>
  </form>
</section>

<section class="card" aria-labelledby="data-h">
  <h2 id="data-h">Data</h2>
  <div class="page-actions">
    <a class="btn btn-secondary" href="trash.php">Trash (restore deleted items)</a>
    <a class="btn btn-secondary" href="audit.php">Audit log</a>
    <a class="btn btn-secondary" href="client_merge.php">Merge duplicate clients</a>
    <a class="btn btn-secondary" href="clients.php?archived=1">Archived clients</a>
    <a class="btn btn-secondary" href="outcomes.php">Annual outcome report</a>
    <a class="btn btn-secondary" href="vendors.php">Vendor agreements</a>
    <a class="btn btn-secondary" href="staff_agreements.php">Staff agreements</a>
    <a class="btn btn-secondary" href="staff_training.php">Staff training</a>
    <a class="btn btn-secondary" href="credentials.php?all=1">Staff credentials</a>
    <a class="btn btn-secondary" href="staff_report.php">Staff compliance report</a>
    <a class="btn btn-secondary" href="sms.php">Text messages</a>
    <a class="btn btn-secondary" href="phone_apps.php">Phone apps</a>
    <a class="btn btn-secondary" href="qbo.php">QuickBooks</a>
    <a class="btn btn-secondary" href="funding_agencies.php">Funding agencies</a>
    <a class="btn btn-secondary" href="pricing.php">Pricing rules</a>
    <a class="btn btn-secondary" href="lists.php">Lists and time limits</a>
    <a class="btn btn-secondary" href="privacy_incidents.php">Privacy incidents</a>
  </div>
  <h3 id="notelock-h">Case note lock</h3>
  <form method="post" action="admin.php" class="inline-form"><?= csrf_field() ?>
    <label for="note_lock_days">Lock case notes for editing after</label>
    <select id="note_lock_days" name="note_lock_days">
      <?php $curNl = note_lock_days();
      foreach ([0 => 'Off', 7 => '7 days', 14 => '14 days', 30 => '30 days', 60 => '60 days', 90 => '90 days'] as $nd => $nlab): ?>
      <option value="<?= $nd ?>" <?= $curNl === $nd ? 'selected' : '' ?>><?= $nlab ?></option>
      <?php endforeach; ?>
    </select>
    <button type="submit" name="save_note_lock" value="1" class="btn btn-secondary btn-sm">Save</button>
  </form>
  <p class="hint">A locked note is corrected with an addendum. Every earlier version of an edited note is kept.</p>
  <h3 id="retention-h">Record retention</h3>
  <form method="post" action="admin.php" class="inline-form"><?= csrf_field() ?>
    <label for="retention_years">Record retention: auto-archive clients closed for</label>
    <select id="retention_years" name="retention_years">
      <?php $curRet = (int)(setting('retention_years') ?? '0');
      $retOpts = [0 => 'Off', 5 => '5 years', 6 => '6 years', 7 => '7 years', 10 => '10 years'];
      // A period stored by an earlier release is shown as it is, not as "Off".
      if (!isset($retOpts[$curRet])) { $retOpts[$curRet] = $curRet . ($curRet === 1 ? ' year' : ' years'); ksort($retOpts); }
      foreach ($retOpts as $ry => $rl): ?>
      <option value="<?= $ry ?>" <?= $curRet === $ry ? 'selected' : '' ?>><?= $rl ?></option>
      <?php endforeach; ?>
    </select>
    <button type="submit" name="save_retention" value="1" class="btn btn-secondary btn-sm">Save</button>
  </form>
  <p class="hint">Archived records are hidden from daily lists but kept intact until you export and purge them from the archived-clients page.</p>
</section>

<section class="card" aria-labelledby="scheduled-h">
  <h2 id="scheduled-h">Scheduled reports</h2>
  <p class="muted">Email the agency-wide outcome report and the funder outcome report (every funding agency) automatically once a month, as plain text. The figures are the same as on the report pages, so no sign-in is needed to read them. Only people who may see agency-wide figures should be listed.</p>
  <?php $srCfg = scheduled_reports_config();
  $srRefill = old_in('save_scheduled_reports') !== '';
  $srSentOut = setting('scheduled_reports_sent_outcomes'); $srSentFun = setting('scheduled_reports_sent_funder'); ?>
  <form method="post" action="admin.php" class="stacked" novalidate><?= csrf_field() ?>
    <?= form_error_summary(['sr_recipients' => 'Recipients', 'sr_day' => 'Day of the month', 'sr_period' => 'Period']) ?>
    <fieldset>
      <legend>Reports to send</legend>
      <div class="field"><label><input type="checkbox" name="sr_outcomes" value="1" <?= ($srRefill ? old_in('sr_outcomes') === '1' : $srCfg['outcomes']) ? 'checked' : '' ?>> Outcome report (clients served, hours by category and program, closures, rehabilitation rate)</label></div>
      <div class="field"><label><input type="checkbox" name="sr_funder" value="1" <?= ($srRefill ? old_in('sr_funder') === '1' : $srCfg['funder']) ? 'checked' : '' ?>> Funder outcome report (one summary per funding agency)</label></div>
      <div class="field"><label><input type="checkbox" name="sr_business" value="1" <?= ($srRefill ? old_in('sr_business') === '1' : $srCfg['business']) ? 'checked' : '' ?>> Business summary (referrals, clients served, sessions, no-shows, hours, closures, compared with the period before and last year; totals only)</label></div>
    </fieldset>
    <div class="field">
      <label for="sr_recipients">Recipients</label>
      <span class="hint" id="sr_recipients-hint">One email address per line (commas also work). Each address receives its own copy.</span>
      <textarea id="sr_recipients" name="sr_recipients" rows="3" <?= field_aria('sr_recipients', 'sr_recipients-hint') ?>><?= h($srRefill ? old_in('sr_recipients') : implode("\n", $srCfg['recipients'])) ?></textarea>
      <?= field_error('sr_recipients') ?>
    </div>
    <div class="field">
      <label for="sr_day">Day of the month</label>
      <span class="hint" id="sr_day-hint">1 to 28, so every month has the day. The reports go out with the morning automated run on that day; a run missed that day is caught up on the next run that month.</span>
      <?php $srDaySel = $srRefill ? old_in('sr_day') : (string)$srCfg['day']; ?>
      <select id="sr_day" name="sr_day" <?= field_aria('sr_day', 'sr_day-hint') ?>>
        <?php for ($d = 1; $d <= 28; $d++): ?><option value="<?= $d ?>" <?= $srDaySel === (string)$d ? 'selected' : '' ?>><?= $d ?></option><?php endfor; ?>
      </select>
      <?= field_error('sr_day') ?>
    </div>
    <div class="field">
      <label for="sr_period">Period each report covers</label>
      <span class="hint" id="sr_period-hint">Previous month sends every month; previous quarter sends once a quarter, in the month after it ends.</span>
      <?php $srPeriodSel = $srRefill ? old_in('sr_period') : $srCfg['period']; ?>
      <select id="sr_period" name="sr_period" <?= field_aria('sr_period', 'sr_period-hint') ?>>
        <?php foreach (SCHEDULED_REPORT_PERIODS as $k => $label): ?><option value="<?= h($k) ?>" <?= $srPeriodSel === $k ? 'selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?>
      </select>
      <?= field_error('sr_period') ?>
    </div>
    <button type="submit" name="save_scheduled_reports" value="1" class="btn btn-primary">Save scheduled reports</button>

  </form>
  <p class="hint">Last sent: outcome report <?= $srSentOut !== null ? 'for ' . h($srSentOut) : 'never' ?>; funder outcome report <?= $srSentFun !== null ? 'for ' . h($srSentFun) : 'never' ?>; business summary <?= ($srSentBus = setting('scheduled_reports_sent_business')) !== null ? 'for ' . h($srSentBus) : 'never' ?>. Each send is recorded in the <a href="audit.php">audit log</a> as “report.scheduled”.</p>
  <h3 id="fiscal-h">Fiscal year</h3>
  <form method="post" action="admin.php#fiscal-h" class="inline-form"><?= csrf_field() ?>
    <label for="fiscal_year_start">Fiscal year starts in</label>
    <select id="fiscal_year_start" name="fiscal_year_start"><?php for ($fm = 1; $fm <= 12; $fm++): ?><option value="<?= $fm ?>"<?= fiscal_year_start_month() === $fm ? ' selected' : '' ?>><?= date('F', mktime(0, 0, 0, $fm, 1)) ?></option><?php endfor; ?></select>
    <button type="submit" name="save_fiscal_year" value="1" class="btn btn-secondary btn-sm">Save fiscal year</button>
  </form>
  <h3 id="report-sending-h">Counselor progress reports</h3>
  <form method="post" action="admin.php#report-sending-h" class="stacked"><?= csrf_field() ?>
    <div class="field"><label><input type="checkbox" name="reports_auto_send" value="1"<?= (setting('reports_auto_send') ?? '0') === '1' ? ' checked' : '' ?>> Email due progress reports to counselors automatically, without review</label>
      <span class="hint" id="auto-send-hint">Unticked (recommended): each due report opens a task for the client's instructor, who adds a short narrative and sends it from the track's Send report page.</span></div>
    <button type="submit" name="save_report_sending" value="1" class="btn btn-secondary btn-sm">Save report sending</button>
  </form>
</section>

<section class="card" aria-labelledby="ai-h">
  <h2 id="ai-h">AI</h2>
  <p class="muted">Turn the AI assistant on or off, choose its provider and review its use.</p>
  <p><a class="btn btn-secondary" href="ai.php">AI assistant settings</a></p>
</section>
<?php page_footer();
