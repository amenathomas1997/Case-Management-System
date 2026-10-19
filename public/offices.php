<?php
// Offices (administrators, 4.22.0): the organisation's locations, each with its address, phone and
// time zone (inc/offices.php). Staff are placed in an office on their user page, clients on their
// edit page; an office is closed rather than deleted, so its history stays.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
csrf_check();
$user = require_login();
require_perm('admin');
$str = fn(string $k): string => is_string($_POST[$k] ?? null) ? trim($_POST[$k]) : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_office'])) {
    $id = (int)($_POST['office_id'] ?? 0);
    $sfx = $id ? '_' . $id : '';
    $errs = [];
    $name = $str('name');
    if ($name === '' || mb_strlen($name) > 120) $errs['name' . $sfx] = 'Name: give the office a name of up to 120 characters.';
    $dup = db()->prepare('SELECT id FROM offices WHERE name = ? AND id <> ?');
    $dup->execute([$name, $id]);
    if ($name !== '' && $dup->fetchColumn()) $errs['name' . $sfx] = 'Name: another office already has that name.';
    if (mb_strlen($str('address')) > 255) $errs['address' . $sfx] = 'Address: keep it to 255 characters.';
    if (mb_strlen($str('phone')) > 40) $errs['phone' . $sfx] = 'Phone: keep it to 40 characters.';
    $tz = $str('time_zone') === '' ? null : us_zone($str('time_zone'));
    if ($str('time_zone') !== '' && $tz === null) $errs['time_zone' . $sfx] = 'Time zone: choose one from the list.';
    if ($errs) { keep_input(); keep_field_errors($errs); redirect('offices.php' . ($id ? '#o-' . $id : '#add-h')); }
    $vals = [$name, $str('address') ?: null, $str('phone') ?: null, $tz];
    if ($id) db()->prepare('UPDATE offices SET name = ?, address = ?, phone = ?, time_zone = ? WHERE id = ?')->execute(array_merge($vals, [$id]));
    else db()->prepare('INSERT INTO offices (name, address, phone, time_zone) VALUES (?,?,?,?)')->execute($vals);
    audit((int)$user['id'], 'office.save', 'settings', null, $name);
    flash('Saved ' . $name . '.');
    redirect('offices.php');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_active'])) {
    $id = (int)$_POST['set_active'];
    $on = ($_POST['active'] ?? '') === '1' ? 1 : 0;
    db()->prepare('UPDATE offices SET is_active = ? WHERE id = ?')->execute([$on, $id]);
    audit((int)$user['id'], $on ? 'office.reopen' : 'office.close', 'settings', null, '#' . $id);
    flash($on ? 'The office is open again.' : 'The office is closed. Its staff and clients keep it on their records until they are moved.');
    redirect('offices.php');
}

$counts = [];
try {
    foreach (db()->query("SELECT o.id, (SELECT COUNT(*) FROM users u WHERE u.office_id = o.id AND u.is_active = 1) AS staff,
        (SELECT COUNT(*) FROM clients c WHERE c.office_id = o.id AND c.archived_at IS NULL AND c.status NOT LIKE 'Closed%') AS clients FROM offices o")->fetchAll() as $r) $counts[(int)$r['id']] = $r;
} catch (PDOException $e) {}
$form = function (?array $o): string {
    $id = (int)($o['id'] ?? 0);
    $sfx = $id ? '_' . $id : '';
    $mine = old_in('office_id') === (string)$id;
    $v = fn(string $k) => h($mine ? old_in($k) : (string)($o[$k] ?? ''));
    $zoneNow = $mine ? old_in('time_zone') : (string)($o['time_zone'] ?? '');
    ob_start(); ?>
  <form method="post" action="offices.php" class="stacked" novalidate><?= csrf_field() ?><input type="hidden" name="office_id" value="<?= $id ?>">
    <?php // The refused form's own errors, listed once (4.27.0); each message already names its field. ?>
    <?= $mine ? form_error_summary(['name' . $sfx => '', 'address' . $sfx => '', 'phone' . $sfx => '', 'time_zone' . $sfx => ''], ['name' . $sfx => 'on' . $sfx, 'address' . $sfx => 'oa' . $sfx, 'phone' . $sfx => 'op' . $sfx, 'time_zone' . $sfx => 'oz' . $sfx]) : '' ?>
    <div class="field"><label for="on<?= $sfx ?>">Name <?= required_mark() ?></label><input type="text" id="on<?= $sfx ?>" name="name" maxlength="120" value="<?= $v('name') ?>" <?= field_aria('name' . $sfx) ?>><?= field_error('name' . $sfx) ?></div>
    <div class="field"><label for="oa<?= $sfx ?>">Address</label><input type="text" id="oa<?= $sfx ?>" name="address" maxlength="255" value="<?= $v('address') ?>" <?= field_aria('address' . $sfx) ?>><?= field_error('address' . $sfx) ?></div>
    <div class="field"><label for="op<?= $sfx ?>">Phone</label><input type="tel" id="op<?= $sfx ?>" name="phone" maxlength="40" value="<?= $v('phone') ?>" <?= field_aria('phone' . $sfx) ?>><?= field_error('phone' . $sfx) ?></div>
    <div class="field"><label for="oz<?= $sfx ?>">Time zone, for clients who have none of their own</label>
      <select id="oz<?= $sfx ?>" name="time_zone" <?= field_aria('time_zone' . $sfx) ?>><?= kv_options(US_TIME_ZONES, $zoneNow, 'The organisation\'s') ?></select><?= field_error('time_zone' . $sfx) ?></div>
    <button type="submit" name="save_office" value="1" class="btn btn-primary">Save<span class="visually-hidden"> <?= $id ? h((string)$o['name']) : 'the new office' ?></span></button>
  </form>
<?php return (string)ob_get_clean();
};
page_header('Offices', $user, 'admin', [['admin.php', 'Administration']]);
?>
<h1>Offices</h1>
<p>Your organisation's locations. Staff are placed in an office on their user page and clients on their edit page; the client list and the Schedule can then show one office. A client whose state is not on their record gets reminders and texts in their office's time zone. With no offices here, nothing about offices appears anywhere.</p>
<?php foreach (offices_all() as $o): $c = $counts[(int)$o['id']] ?? ['staff' => 0, 'clients' => 0]; ?>
<section class="card" aria-labelledby="o-<?= (int)$o['id'] ?>">
  <h2 id="o-<?= (int)$o['id'] ?>" tabindex="-1"><?= h((string)$o['name']) ?><?= (int)$o['is_active'] ? '' : ' (closed)' ?>: <?= (int)$c['staff'] ?> staff, <?= (int)$c['clients'] ?> current clients</h2>
  <?= $form($o) ?>
  <form method="post" action="offices.php" class="inline-form"><?= csrf_field() ?><input type="hidden" name="active" value="<?= (int)$o['is_active'] ? '0' : '1' ?>">
    <button type="submit" name="set_active" value="<?= (int)$o['id'] ?>" class="btn btn-secondary btn-sm"><?= (int)$o['is_active'] ? 'Close this office' : 'Open it again' ?><span class="visually-hidden"> — <?= h((string)$o['name']) ?></span></button>
  </form>
</section>
<?php endforeach; ?>
<section class="card" aria-labelledby="add-h">
  <h2 id="add-h" tabindex="-1">Add an office</h2>
  <?= $form(null) ?>
</section>
<?php page_footer();
