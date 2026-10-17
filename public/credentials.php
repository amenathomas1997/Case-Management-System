<?php
// Credentials (4.16.0): certifications and checks staff keep current, with expiry dates
// (inc/staff_credentials.php). Everyone keeps their own here; administrators see and keep
// everyone's (?user= for one person).
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
csrf_check();
$user = require_login();
$isAdmin = can('admin');
$uid = (int)$user['id'];
// Whose credentials: your own, or (administrators) anyone's.
$who = $isAdmin && isset($_GET['user']) ? (int)$_GET['user'] : ($isAdmin && isset($_POST['user_id']) ? (int)$_POST['user_id'] : $uid);
$wq = db()->prepare('SELECT id, name FROM users WHERE id = ?');
$wq->execute([$who]);
$person = $wq->fetch();
if (!$person) { flash('That staff member was not found.', 'error'); redirect('credentials.php'); }
$back = 'credentials.php' . ($who !== $uid ? '?user=' . $who : '');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['save_credential']) || isset($_POST['delete_credential']))) {
    $cid = (int)($_POST['credential_id'] ?? 0);
    if ($cid > 0) {
        // Only a credential of the person shown, and only your own unless you are an administrator.
        $own = db()->prepare('SELECT * FROM staff_credentials WHERE id = ? AND user_id = ?');
        $own->execute([$cid, $who]);
        $existing = $own->fetch();
        if (!$existing) { flash('That credential was not found.', 'error'); redirect($back); }
    }
    if (isset($_POST['delete_credential'])) {
        if ($cid <= 0) redirect($back);
        db()->prepare('DELETE FROM staff_credentials WHERE id = ?')->execute([$cid]);
        credential_close_tasks($cid);
        audit($uid, 'credential.delete', 'user', $who, $existing['name']);
        flash('Removed ' . $existing['name'] . '.');
        redirect($back);
    }
    $str = fn(string $k): string => is_string($_POST[$k] ?? null) ? trim($_POST[$k]) : '';
    $type = $str('type');
    $name = $type === 'Other' || $type === '' ? $str('other_name') : $type;
    $sfx = $cid > 0 ? '_' . $cid : '';
    $errs = [];
    if ($name === '' || mb_strlen($name) > 120) $errs['type' . $sfx] = 'Choose the credential, or name it under Other (120 characters or fewer).';
    $issued = $str('issued_on') === '' ? null : valid_date($str('issued_on'), 1950, (int)date('Y'));
    if ($str('issued_on') !== '' && $issued === null) $errs['issued_on' . $sfx] = 'Enter the date issued as a real date, not in the future.';
    $expires = $str('expires_on') === '' ? null : valid_date($str('expires_on'), 1950, (int)date('Y') + 20);
    if ($str('expires_on') !== '' && $expires === null) $errs['expires_on' . $sfx] = 'Enter the expiry date as a real date, or leave it empty if it does not expire.';
    if ($issued && $expires && $expires < $issued) $errs['expires_on' . $sfx] = 'The expiry date is before the date issued.';
    foreach (['number' => 80, 'issuer' => 120, 'notes' => 255] as $k => $max) if (mb_strlen($str($k)) > $max) $errs[$k . $sfx] = 'Keep this to ' . $max . ' characters or fewer.';
    if ($errs) { keep_input(); keep_field_errors($errs); redirect($back . ($cid > 0 ? '#cred-' . $cid : '#add-h')); }
    $vals = [$name, $str('number') ?: null, $str('issuer') ?: null, $issued, $expires, $str('notes') ?: null];
    if ($cid > 0) { db()->prepare('UPDATE staff_credentials SET name = ?, number = ?, issuer = ?, issued_on = ?, expires_on = ?, notes = ?, updated_at = NOW() WHERE id = ?')->execute(array_merge($vals, [$cid])); credential_close_tasks($cid); }
    else db()->prepare('INSERT INTO staff_credentials (name, number, issuer, issued_on, expires_on, notes, user_id, added_by) VALUES (?,?,?,?,?,?,?,?)')->execute(array_merge($vals, [$who, $uid]));
    audit($uid, 'credential.save', 'user', $who, $name . ($expires ? ', expires ' . $expires : ''));
    flash('Saved ' . $name . '.');
    redirect($back);
}

$creds = credentials_for($who);
$title = $who === $uid ? 'My credentials' : 'Credentials: ' . $person['name'];
page_header($title, $user, $isAdmin && $who !== $uid ? 'admin' : 'account', $who === $uid ? [['account.php', 'My Account']] : [['admin.php', 'Administration'], ['credentials.php?all=1', 'Credentials']]);

/** The add or edit form for one credential. */
$form = function (?array $c) use ($who): string {
    $sfx = $c ? '_' . (int)$c['id'] : '';
    $mine = old_in('credential_id') === (string)($c['id'] ?? 0);
    $v = fn(string $k, string $d) => $mine ? old_in($k) : $d;
    $types = cms_list_items('credential_types') ?: CREDENTIAL_TYPES;
    // A credential whose type was since removed from the list keeps it as a choice.
    if ($c && !in_array($c['name'], $types, true) && in_array($c['name'], CREDENTIAL_TYPES, true)) $types[] = $c['name'];
    $type = $c ? (in_array($c['name'], $types, true) ? $c['name'] : 'Other') : '';
    ob_start(); ?>
  <form method="post" action="credentials.php" class="stacked" novalidate><?= csrf_field() ?>
    <input type="hidden" name="credential_id" value="<?= (int)($c['id'] ?? 0) ?>">
    <input type="hidden" name="user_id" value="<?= $who ?>">
    <div class="field"><label for="ty<?= $sfx ?>">Credential</label>
      <select id="ty<?= $sfx ?>" name="type" <?= field_aria('type' . $sfx) ?>><?= select_options(array_merge([''], $types), $v('type', $type)) ?></select><?= field_error('type' . $sfx) ?></div>
    <div class="field"><label for="on<?= $sfx ?>">Its name, when Other is chosen</label>
      <input type="text" id="on<?= $sfx ?>" name="other_name" maxlength="120" value="<?= h($v('other_name', $type === 'Other' ? (string)$c['name'] : '')) ?>"></div>
    <div class="field"><label for="nu<?= $sfx ?>">Number <span class="hint">optional</span></label>
      <input type="text" id="nu<?= $sfx ?>" name="number" maxlength="80" value="<?= h($v('number', (string)($c['number'] ?? ''))) ?>" <?= field_aria('number' . $sfx) ?>><?= field_error('number' . $sfx) ?></div>
    <div class="field"><label for="is<?= $sfx ?>">Issued by <span class="hint">optional</span></label>
      <input type="text" id="is<?= $sfx ?>" name="issuer" maxlength="120" value="<?= h($v('issuer', (string)($c['issuer'] ?? ''))) ?>" <?= field_aria('issuer' . $sfx) ?>><?= field_error('issuer' . $sfx) ?></div>
    <div class="field"><label for="io<?= $sfx ?>">Date issued <span class="hint">optional</span></label>
      <input type="date" id="io<?= $sfx ?>" name="issued_on" value="<?= h($v('issued_on', (string)($c['issued_on'] ?? ''))) ?>" <?= field_aria('issued_on' . $sfx) ?>><?= field_error('issued_on' . $sfx) ?></div>
    <div class="field"><label for="eo<?= $sfx ?>">Expires on <span class="hint">empty if it does not expire</span></label>
      <input type="date" id="eo<?= $sfx ?>" name="expires_on" value="<?= h($v('expires_on', (string)($c['expires_on'] ?? ''))) ?>" <?= field_aria('expires_on' . $sfx) ?>><?= field_error('expires_on' . $sfx) ?></div>
    <div class="field"><label for="no<?= $sfx ?>">Notes <span class="hint">optional</span></label>
      <input type="text" id="no<?= $sfx ?>" name="notes" maxlength="255" value="<?= h($v('notes', (string)($c['notes'] ?? ''))) ?>" <?= field_aria('notes' . $sfx) ?>><?= field_error('notes' . $sfx) ?></div>
    <button type="submit" name="save_credential" value="1" class="btn btn-primary">Save<span class="visually-hidden"> <?= h((string)($c['name'] ?? 'the credential')) ?></span></button>
    <?php if ($c): ?><button type="submit" name="delete_credential" value="1" class="btn btn-secondary" onclick="return confirm('Remove <?= h(addslashes((string)$c['name'])) ?>?');">Remove<span class="visually-hidden"> <?= h((string)$c['name']) ?></span></button><?php endif; ?>
  </form>
<?php return (string)ob_get_clean();
};
?>
<h1><?= h($title) ?></h1>
<?php if ($isAdmin && isset($_GET['all'])): $due = credentials_due(); $staff = db()->query('SELECT id, name FROM users WHERE is_active = 1 ORDER BY name')->fetchAll(); ?>
<section class="card" aria-labelledby="due-h">
  <h2 id="due-h">Expired or expiring within <?= CREDENTIAL_WARN_DAYS ?> days (<?= array_sum(array_map('count', $due)) ?>)</h2>
  <?php if (!$due): ?><p>None.</p><?php else: ?><ul>
  <?php foreach ($due as $rows) foreach ($rows as $c): ?><li><a href="credentials.php?user=<?= (int)$c['user_id'] ?>"><?= h($c['user_name']) ?></a>: <?= h($c['name']) ?>, <?= h(credential_state($c)[1]) ?></li><?php endforeach; ?>
  </ul><?php endif; ?>
</section>
<section class="card" aria-labelledby="staff-h">
  <h2 id="staff-h">Each staff member</h2>
  <ul><?php foreach ($staff as $s): ?><li><a href="credentials.php?user=<?= (int)$s['id'] ?>"><?= h($s['name']) ?></a></li><?php endforeach; ?></ul>
</section>
<?php else: ?>
<p>Certifications and checks to keep current, with their expiry dates. From <?= CREDENTIAL_WARN_DAYS ?> days before one expires, it shows on your Home page and an administrator is reminded.<?= $isAdmin ? ' <a href="credentials.php?all=1">Everyone\'s credentials</a>.' : '' ?></p>
<?php if (!$creds): ?><p>None recorded yet.</p><?php endif; ?>
<?php foreach ($creds as $c): [$st, $words] = credential_state($c); ?>
<section class="card" aria-labelledby="cred-<?= (int)$c['id'] ?>">
  <h2 id="cred-<?= (int)$c['id'] ?>" tabindex="-1"><?= h($c['name']) ?>: <?= $st === 'current' ? h($words) : '<strong>' . h($words) . '</strong>' ?></h2>
  <?= $form($c) ?>
</section>
<?php endforeach; ?>
<section class="card" aria-labelledby="add-h">
  <h2 id="add-h" tabindex="-1">Add a credential</h2>
  <?= $form(null) ?>
</section>
<?php endif; ?>
<?php page_footer();
