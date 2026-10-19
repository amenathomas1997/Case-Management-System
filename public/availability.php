<?php
// My availability (4.20.0): the hours a staff member takes bookings, which clients see as open
// times in the portal when self-booking is on (inc/self_booking.php). Administrators also turn
// self-booking on and set its rules here, and can keep anyone's hours (?user=).
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
csrf_check();
$user = require_login();
$isAdmin = can('admin');
$who = $isAdmin && isset($_REQUEST['user']) ? (int)$_REQUEST['user'] : (int)$user['id'];
// An administrator can also keep the hours of someone whose account is switched off (they take no bookings meanwhile).
$wq = db()->prepare('SELECT id, name, is_active FROM users WHERE id = ?' . ($isAdmin ? '' : ' AND is_active = 1'));
$wq->execute([$who]);
$person = $wq->fetch();
if (!$person) { flash('That staff member was not found.', 'error'); redirect('availability.php'); }
$back = 'availability.php' . ($who !== (int)$user['id'] ? '?user=' . $who : '');
$str = fn(string $k): string => is_string($_POST[$k] ?? null) ? trim($_POST[$k]) : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_hours'])) {
    // The same Add sent twice (4.29.0) adds the hours once, instead of answering the second with "overlap".
    $hoursKey = web_idem_key($_POST);
    if (web_once_get($hoursKey) !== null) { flash('Hours added. (The form was sent twice, so they were added once.)', 'info'); redirect($back); }
    $err = availability_add($who, $str('weekday'), $str('from'), $str('to'), $str('mode') !== 'in_person', $str('location'));
    if ($err !== null) { keep_input(); keep_field_errors([$err[0] => $err[1]]); redirect($back . '#add-h'); }
    web_once_set($hoursKey, $who);
    audit((int)$user['id'], 'availability.add', 'user', $who, $str('weekday') . ' ' . $str('from') . '-' . $str('to'));
    flash('Hours added.');
    redirect($back);
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['remove_hours'])) {
    $d = db()->prepare('DELETE FROM staff_availability WHERE id = ? AND user_id = ?');
    $d->execute([(int)$_POST['remove_hours'], $who]);
    // From an older page, for hours already removed: said, not reported as done (4.29.0).
    if (!$d->rowCount()) { flash('Nothing was removed: those hours were already removed.', 'error'); redirect($back); }
    audit((int)$user['id'], 'availability.remove', 'user', $who, '#' . (int)$_POST['remove_hours']);
    flash('Hours removed. Sessions already booked in them stay booked.');
    redirect($back);
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_self_booking']) && $isAdmin) {
    $num = fn(string $k, int $min, int $max) => ctype_digit($str($k)) && (int)$str($k) >= $min && (int)$str($k) <= $max ? (int)$str($k) : null;
    $vals = ['self_booking_minutes' => $num('minutes', 15, 240), 'self_booking_notice_hours' => $num('notice', 1, 336), 'self_booking_days' => $num('days', 1, 90), 'self_booking_max' => $num('max', 1, 10)];
    $bad = array_filter(['minutes' => [$vals['self_booking_minutes'], 'Session length: a whole number of minutes from 15 to 240.'], 'notice' => [$vals['self_booking_notice_hours'], 'Hours ahead: a whole number from 1 to 336.'],
        'days' => [$vals['self_booking_days'], 'Days ahead: a whole number from 1 to 90.'], 'max' => [$vals['self_booking_max'], 'Sessions booked ahead: a whole number from 1 to 10.']], fn($x) => $x[0] === null);
    if ($bad) { keep_input(); keep_field_errors(array_map(fn($x) => $x[1], $bad)); redirect('availability.php#rules-h'); }
    set_setting('self_booking', !empty($_POST['self_booking_on']) ? 'on' : 'off');
    foreach ($vals as $k => $v) set_setting($k, (string)$v);
    audit((int)$user['id'], 'settings.self_booking', 'settings', null, (setting('self_booking') === 'on' ? 'on' : 'off') . ', ' . $vals['self_booking_minutes'] . ' min');
    flash(setting('self_booking') === 'on' ? 'Clients can now book open times in the portal.' : 'Self-booking is off: the portal shows no open times.');
    redirect('availability.php#rules-h');
}

$hours = availability_for($who);
$cfg = self_booking_settings();
$title = $who === (int)$user['id'] ? 'My availability' : 'Availability: ' . $person['name'];
page_header($title, $user, 'account', [['account.php', 'My Account']]);
?>
<h1><?= h($title) ?></h1>
<?php if (!(int)$person['is_active']): ?><p><strong>This account is switched off, so its clients cannot book these hours until it is switched on again.</strong></p><?php endif; ?>
<p>The hours you take sessions. <?= $cfg['on'] ? 'Your clients see the open times in these hours in their portal and can book one: a time is open when you have no session then, your Outlook calendar is free (if you connected it on My Account), it is at least ' . $cfg['notice_hours'] . ' hours ahead, and the client\'s authorization covers it.' : 'Self-booking is off, so clients do not see them yet' . ($isAdmin ? ' (turn it on below).' : '; an administrator turns it on.') ?></p>

<section class="card" aria-labelledby="hours-h">
  <h2 id="hours-h">Hours (<?= count($hours) ?>)</h2>
  <?php if (!$hours): ?><p>None yet.</p><?php else: ?>
  <ul>
    <?php foreach ($hours as $a): ?>
    <li><form method="post" action="availability.php" class="inline-form"><?= csrf_field() ?><input type="hidden" name="user" value="<?= $who ?>">
      <?= h(availability_words($a)) ?>
      <button type="submit" name="remove_hours" value="<?= (int)$a['id'] ?>" class="btn btn-secondary btn-sm">Remove<span class="visually-hidden"> <?= h(availability_words($a)) ?></span></button></form></li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</section>

<section class="card" aria-labelledby="add-h">
  <h2 id="add-h" tabindex="-1">Add hours</h2>
  <form method="post" action="availability.php" class="stacked" novalidate><?= csrf_field() ?><?= web_idem_field() ?><input type="hidden" name="user" value="<?= $who ?>">
    <?php // The messages already start with their field's name ("From: ..."), so the summary adds none (4.29.0). ?>
    <?= form_error_summary(['weekday' => '', 'from' => '', 'to' => '', 'mode' => '', 'location' => '']) ?>
    <div class="field"><label for="weekday">Day</label>
      <select id="weekday" name="weekday" <?= field_aria('weekday') ?>><?php foreach (SELF_BOOKING_DAYS as $dk => $dn): ?><option value="<?= $dk ?>"<?= old_in('weekday', '1') === (string)$dk ? ' selected' : '' ?>><?= $dn ?></option><?php endforeach; ?></select><?= field_error('weekday') ?></div>
    <div class="field"><label for="from">From</label><input type="time" id="from" name="from" value="<?= h(old_in('from', '09:00')) ?>" <?= field_aria('from') ?>><?= field_error('from') ?></div>
    <div class="field"><label for="to">To</label><input type="time" id="to" name="to" value="<?= h(old_in('to', '12:00')) ?>" <?= field_aria('to') ?>><?= field_error('to') ?></div>
    <div class="field"><label for="mode">How</label>
      <select id="mode" name="mode" <?= field_aria('mode') ?>><option value="online"<?= old_in('mode') !== 'in_person' ? ' selected' : '' ?>>Online</option><option value="in_person"<?= old_in('mode') === 'in_person' ? ' selected' : '' ?>>In person</option></select><?= field_error('mode') ?></div>
    <div class="field"><label for="location">Where, for in person</label><input type="text" id="location" name="location" maxlength="190" value="<?= h(old_in('location')) ?>" <?= field_aria('location') ?>><?= field_error('location') ?></div>
    <button type="submit" name="add_hours" value="1" class="btn btn-primary">Add these hours</button>
  </form>
</section>

<?php if ($isAdmin): ?>
<section class="card" aria-labelledby="rules-h">
  <h2 id="rules-h" tabindex="-1">Client self-booking (administrators)</h2>
  <form method="post" action="availability.php" class="stacked" novalidate><?= csrf_field() ?>
    <?= form_error_summary(['minutes' => 'Session length', 'notice' => 'Hours ahead', 'days' => 'Days ahead', 'max' => 'Sessions booked ahead']) ?>
    <div class="field"><label><input type="checkbox" name="self_booking_on" value="1" <?= $cfg['on'] ? 'checked' : '' ?>> Clients can book open times with their own instructor in the portal</label></div>
    <div class="field"><label for="minutes">Session length, minutes</label><input type="number" id="minutes" name="minutes" min="15" max="240" value="<?= h(old_in('minutes', (string)$cfg['minutes'])) ?>" <?= field_aria('minutes') ?>><?= field_error('minutes') ?></div>
    <div class="field"><label for="notice">At least this many hours ahead</label><input type="number" id="notice" name="notice" min="1" max="336" value="<?= h(old_in('notice', (string)$cfg['notice_hours'])) ?>" <?= field_aria('notice') ?>><?= field_error('notice') ?></div>
    <div class="field"><label for="days">Up to this many days ahead</label><input type="number" id="days" name="days" min="1" max="90" value="<?= h(old_in('days', (string)$cfg['days'])) ?>" <?= field_aria('days') ?>><?= field_error('days') ?></div>
    <div class="field"><label for="max">Sessions a client can have booked ahead this way</label><input type="number" id="max" name="max" min="1" max="10" value="<?= h(old_in('max', (string)$cfg['max'])) ?>" <?= field_aria('max') ?>><?= field_error('max') ?></div>
    <button type="submit" name="save_self_booking" value="1" class="btn btn-secondary">Save</button>
  </form>
  <p>Everyone's hours: <?php $staff = db()->query('SELECT u.id, u.name, COUNT(a.id) n FROM users u LEFT JOIN staff_availability a ON a.user_id = u.id WHERE u.is_active = 1 GROUP BY u.id, u.name ORDER BY u.name')->fetchAll(); ?>
    <?= implode(', ', array_map(fn($s) => '<a href="availability.php?user=' . (int)$s['id'] . '">' . h($s['name']) . '</a> (' . (int)$s['n'] . ')', $staff)) ?>.</p>
</section>
<?php endif; ?>
<?php page_footer();
