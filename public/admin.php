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
    $url = ($GLOBALS['CONFIG']['app']['update_url'] ?? null) ?: 'https://cms.example.org/updates.json';
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15]);
    $resp = curl_exec($ch);
    curl_close($ch);
    $m = is_string($resp) ? json_decode($resp, true) : null;
    if (is_array($m) && preg_match('/^\d+(\.\d+){1,3}$/', (string)($m['latest'] ?? ''))) {
        set_setting('update_latest', (string)$m['latest']);
        set_setting('update_notes', mb_substr((string)($m['notes'] ?? ''), 0, 500));
        set_setting('update_checked', date('Y-m-d H:i'));
        flash(version_compare((string)$m['latest'], APP_VERSION, '>')
            ? 'A newer version is available: v' . $m['latest'] . '.'
            : 'You are up to date (v' . APP_VERSION . ').');
    } else {
        flash('The update server could not be reached. Try again later.', 'error');
    }
    redirect('admin.php#updates-h');
}

/* Record-retention setting */
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
    if (!is_string($pw) || strlen($pw) < 10) $uErrs['nu_password'] = 'Enter a temporary password of at least 10 characters.';
    if ($uErrs) {
        keep_input();
        keep_field_errors($uErrs);
    } else {
        try {
            db()->prepare('INSERT INTO users (name, email, password_hash, role, group_id) VALUES (?,?,?,?,?)')
                ->execute([$name, $email, password_hash($pw, PASSWORD_DEFAULT), $role, $newGroup ? (int)$newGroup['id'] : null]);
            audit($user['id'], 'user.create', 'user', (int)db()->lastInsertId(), "{$name} <{$email}> role={$role} group="
                . ($newGroup ? (int)$newGroup['id'] : 'legacy'));
            flash("User {$name} added.");
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
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_scheduled_reports'])) {
    $srOutcomes = !empty($_POST['sr_outcomes']);
    $srFunder = !empty($_POST['sr_funder']);
    $srRaw = trim(is_string($_POST['sr_recipients'] ?? null) ? $_POST['sr_recipients'] : '');
    $srParsed = scheduled_reports_parse_recipients($srRaw);
    $srDayRaw = is_string($_POST['sr_day'] ?? null) ? $_POST['sr_day'] : '';
    $srDay = preg_match('/^\d{1,2}$/', $srDayRaw) ? (int)$srDayRaw : 0;
    $srPeriod = is_string($_POST['sr_period'] ?? null) ? $_POST['sr_period'] : '';
    $srErrs = [];
    if ($srParsed['invalid']) $srErrs['sr_recipients'] = 'These are not valid email addresses: ' . implode(', ', array_map(fn($v) => mb_substr($v, 0, 60), $srParsed['invalid'])) . '. Enter one address per line, for example director@agency.org.';
    elseif (($srOutcomes || $srFunder) && !$srParsed['valid']) $srErrs['sr_recipients'] = 'Enter at least one email address to send the reports to, or turn both reports off.';
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
<p><?= $jobsBehind ? '<strong>' . (count($jobsBehind) === 1 ? '1 scheduled job needs' : count($jobsBehind) . ' scheduled jobs need') . ' attention</strong> (' . h(implode(', ', array_column($jobsBehind, 'name'))) . '). ' : 'All scheduled jobs are running. ' ?><a href="system_health.php">Open System health</a></p>
<nav aria-label="Administration sections">
  <ul class="tab-nav">
    <li><a href="#people-h">People &amp; permissions</a></li>
    <li><a href="#signin-h">Sign-in &amp; security</a></li>
    <li><a href="#forms-h">Forms &amp; email</a></li>
    <li><a href="#data-h">Data</a></li>
    <li><a href="#scheduled-h">Scheduled reports</a></li>
    <li><a href="#ai-h">AI</a></li>
  </ul>
</nav>

<section class="card" aria-labelledby="people-h">
  <h2 id="people-h">People &amp; permissions</h2>
  <p class="muted">Quick group and hours changes live here — for everything else (edit details, password resets, two-factor (2FA) sign-in recovery, caseload transfer, deactivate or delete), open <a href="users.php">User management</a>.</p>
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
      <label for="nu_password">Temporary password * <span class="hint">At least 10 characters — ask them to change it after first sign-in</span></label>
      <?php if ($nuRefill): ?><span class="hint" id="nu_password-again">For safety a typed password is never kept after an error: type it again.</span><?php endif; ?>
      <input type="text" id="nu_password" name="password" required minlength="10" autocomplete="off" <?= field_aria('nu_password', $nuRefill ? 'nu_password-again' : '') ?>>
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
  $updChecked = setting('update_checked');
  $updNewer = $updLatest !== null && version_compare($updLatest, APP_VERSION, '>');
  ?>
  <p>
    This system is <strong>v<?= h(APP_VERSION) ?></strong>.
    <?php if ($updNewer): ?><span class="badge badge-pending">Update available: v<?= h($updLatest) ?></span>
      <?php if (setting('update_notes')): ?><br><span class="muted"><?= h(setting('update_notes')) ?></span><?php endif; ?>
    <?php elseif ($updChecked): ?><span class="muted">Up to date (last checked <?= h($updChecked) ?>).</span><?php endif; ?>
  </p>
  <form method="post" action="admin.php" class="inline-form"><?= csrf_field() ?>
    <button type="submit" name="check_updates" value="1" class="btn btn-secondary btn-sm">Check for updates</button>
  </form>
</section>

<section class="card" aria-labelledby="forms-h">
  <h2 id="forms-h">Forms &amp; email</h2>
  <div class="page-actions">
    <a class="btn btn-secondary" href="referral_settings.php">Referral form settings</a>
    <a class="btn btn-secondary" href="email_templates.php">Email templates</a>
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

<section class="card" aria-labelledby="data-h">
  <h2 id="data-h">Data</h2>
  <div class="page-actions">
    <a class="btn btn-secondary" href="trash.php">Trash (restore deleted items)</a>
    <a class="btn btn-secondary" href="audit.php">Audit log</a>
    <a class="btn btn-secondary" href="client_merge.php">Merge duplicate clients</a>
    <a class="btn btn-secondary" href="clients.php?archived=1">Archived clients</a>
    <a class="btn btn-secondary" href="outcomes.php">Annual outcome report</a>
  </div>
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
  <p class="hint">Last sent: outcome report <?= $srSentOut !== null ? 'for ' . h($srSentOut) : 'never' ?>; funder outcome report <?= $srSentFun !== null ? 'for ' . h($srSentFun) : 'never' ?>. Each send is recorded in the <a href="audit.php">audit log</a> as “report.scheduled”.</p>
</section>

<section class="card" aria-labelledby="ai-h">
  <h2 id="ai-h">AI</h2>
  <p class="muted">Turn the AI assistant on or off, choose its provider and review its use.</p>
  <p><a class="btn btn-secondary" href="ai.php">AI assistant settings</a></p>
</section>
<?php page_footer();
