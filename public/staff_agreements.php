<?php
// Staff agreements (administrators, 4.15.0): write the agreements staff sign themselves, turn
// them on or off, and see who has signed each. Changing an agreement's text asks everyone to sign
// again, unless it is marked as a small correction. ?signature= shows one signed copy.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
csrf_check();
$user = require_login();
require_perm('admin');

$ready = true;
try { db()->query('SELECT 1 FROM staff_agreements LIMIT 1'); } catch (PDOException $e) { $ready = false; }

if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_agreement'])) {
    $aid = (int)($_POST['agreement_id'] ?? 0);
    $title = is_string($_POST['title'] ?? null) ? trim($_POST['title']) : '';
    $body = is_string($_POST['body'] ?? null) ? str_replace("\r", '', trim($_POST['body'])) : '';
    $active = !empty($_POST['active']) ? 1 : 0;
    $conf = !empty($_POST['counts_as_confidentiality']) ? 1 : 0;
    $kind = ($_POST['kind'] ?? '') === 'exit' ? 'exit' : 'staff';
    if ($kind === 'exit') $conf = 0; // the exit acknowledgement never stands for the confidentiality agreement
    $small = !empty($_POST['small_correction']);
    $sfx = $aid > 0 ? '_' . $aid : '';
    $errs = [];
    if ($title === '' || mb_strlen($title) > 190) $errs['title' . $sfx] = 'Give the agreement a title (190 characters or fewer).';
    if ($body === '' || strlen($body) > 200000) $errs['body' . $sfx] = 'Write the agreement text (up to about 200,000 characters).';
    // The starting text says to edit it first; it is never sent to staff as it is.
    if ($active && str_contains($body, 'Edit this text before turning the agreement on.')) $errs['body' . $sfx] = 'Edit the starting text, and remove its first line, before turning the agreement on.';
    if (!$errs && $aid > 0 && $small) {
        $q = db()->prepare('SELECT title, body FROM staff_agreements WHERE id = ?');
        $q->execute([$aid]);
        $was = $q->fetch();
        // A correction keeps the same lines, headings and list items, and changes at most 40 characters;
        // anything more changes the meaning someone signed, so everyone signs again.
        if ($was && ($was['title'] !== $title || !staff_agreement_small_change((string)$was['body'], $body))) {
            $errs['body' . $sfx] = 'This is more than a small correction (the title, a line, a heading or a list item changed, or over 40 characters), so everyone must sign again. Untick "small correction" to save it as a new version.';
        }
    }
    if ($errs) { keep_input(); keep_field_errors($errs); redirect('staff_agreements.php' . ($aid > 0 ? '?edit=' . $aid . '#agreement-' . $aid : '?new=1#new-h')); }
    db()->beginTransaction();
    try {
        // Only one agreement stands for the confidentiality agreement.
        if ($conf) db()->prepare('UPDATE staff_agreements SET counts_as_confidentiality = 0 WHERE id <> ?')->execute([$aid]);
        if ($aid > 0) {
            $q = db()->prepare('SELECT * FROM staff_agreements WHERE id = ? FOR UPDATE');
            $q->execute([$aid]);
            $old = $q->fetch();
            if (!$old) { db()->rollBack(); flash('That agreement no longer exists.', 'error'); redirect('staff_agreements.php'); }
            $changed = $old['title'] !== $title || $old['body'] !== $body;
            $version = (int)$old['version'] + ($changed && !$small ? 1 : 0);
            // When it was turned on, and when its text last changed: who must sign by when depends on them.
            $turnedOn = $active && !(int)$old['active'];
            $bumped = $version > (int)$old['version'];
            db()->prepare('UPDATE staff_agreements SET title = ?, body = ?, version = ?, active = ?, counts_as_confidentiality = ?, updated_by = ?, updated_at = NOW()'
                    . ($turnedOn ? ', activated_at = NOW()' : '') . ($bumped ? ', version_at = NOW()' : '') . ' WHERE id = ?')
                ->execute([$title, $body, $version, $active, $conf, $user['id'], $aid]);
            // Every change of text is kept: what it said before, what it says now, and who changed it.
            if ($changed) {
                db()->prepare('INSERT INTO staff_agreement_revisions (agreement_id, version, old_title, old_body, new_title, new_body, small_correction, changed_by) VALUES (?,?,?,?,?,?,?,?)')
                    ->execute([$aid, $version, $old['title'], $old['body'], $title, $body, $small ? 1 : 0, $user['id']]);
            }
            // A new version of the confidentiality agreement: the date it was last signed no longer
            // stands, so the yearly reminders and Compliance agree that everyone signs again.
            if ($version > (int)$old['version'] && $conf) db()->exec('UPDATE users SET confidentiality_signed_on = NULL');
            $what = $changed ? ($small ? 'text corrected (no new signatures asked)' : 'text changed: version ' . $version . ', everyone signs again') : 'settings saved';
        } else {
            db()->prepare('INSERT INTO staff_agreements (title, body, kind, active, counts_as_confidentiality, updated_by, activated_at) VALUES (?,?,?,?,?,?,' . ($active ? 'NOW()' : 'NULL') . ')')
                ->execute([$title, $body, $kind, $active, $conf, $user['id']]);
            $aid = (int)db()->lastInsertId();
            $what = 'added';
        }
        db()->commit();
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        throw $e;
    }
    audit($user['id'], 'agreement.save', 'settings', null, $title . ': ' . $what . ($active ? ', on' : ', off'));
    flash($title . ': ' . $what . '. ' . ($active ? 'Staff are asked to sign it.' : 'It is off, so nobody is asked to sign it yet.'));
    redirect('staff_agreements.php#agreement-' . $aid);
}

/* ---- One signed copy ---- */
if ($ready && isset($_GET['signature'])) {
    $q = db()->prepare('SELECT s.*, u.name AS user_name FROM staff_agreement_signatures s LEFT JOIN users u ON u.id = s.user_id WHERE s.id = ?');
    $q->execute([(int)$_GET['signature']]);
    $sig = $q->fetch();
    if (!$sig) { flash('That signed copy was not found.', 'error'); redirect('staff_agreements.php'); }
    audit($user['id'], 'agreement.view_copy', 'user', (int)$sig['user_id'], $sig['title'] . ' signed by ' . $sig['signed_name']);
    page_header($sig['title'] . ' signed by ' . $sig['signed_name'], $user, 'admin', [['admin.php', 'Administration'], ['staff_agreements.php', 'Staff agreements']]);
    ?>
<h1><?= h($sig['title']) ?>, signed by <?= h((string)($sig['user_name'] ?? $sig['signed_name'])) ?></h1>
<p>Signed as <strong><?= h($sig['signed_name']) ?></strong> on <?= fmt_datetime($sig['signed_at']) ?>, version <?= (int)$sig['version'] ?>, from the <?= h($sig['via']) ?><?= $sig['sign_ip'] ? ' (address ' . h($sig['sign_ip']) . ')' : '' ?>. This is the text exactly as it read when signed.</p>
<?php $fixes = staff_agreement_corrections_after((int)$sig['agreement_id'], (int)$sig['version'], (string)$sig['signed_at']); if ($fixes): ?>
<p>Small corrections were made to the current text after this was signed: <?= h(implode('; ', array_map(fn($f) => fmt_datetime($f['changed_at']) . ($f['name'] ? ' by ' . $f['name'] : ''), $fixes))) ?>. The copy below is unchanged.</p>
<?php endif; ?>
<section class="card" aria-label="Agreement text"><?= staff_agreement_html((string)$sig['body'], 2) ?></section>
<?php
    page_footer();
    exit;
}

$agreements = $ready ? db()->query('SELECT a.*, u.name AS updated_by_name FROM staff_agreements a LEFT JOIN users u ON u.id = a.updated_by ORDER BY a.active DESC, a.title')->fetchAll() : [];
$staff = $ready ? db()->query('SELECT id, name FROM users WHERE is_active = 1 ORDER BY name')->fetchAll() : [];
// The exit acknowledgement concerns only people asked to sign it (active or since switched off).
$leavers = [];
try { $leavers = $ready ? db()->query('SELECT id, name FROM users WHERE exit_requested_at IS NOT NULL ORDER BY name')->fetchAll() : []; } catch (PDOException $e) {}
$latest = $ready ? staff_agreement_latest() : [];
$editing = (int)($_GET['edit'] ?? 0);

page_header('Staff agreements', $user, 'admin', [['admin.php', 'Administration']]);
?>
<h1>Staff agreements</h1>
<p>Agreements each staff member reads and signs themselves, on the website or the phone apps, by typing their name. A signed copy keeps the exact text signed. Staff are asked again a year after signing, or when you change the text. The agreement marked as the confidentiality agreement also records the date Compliance follows.</p>
<?php if (!$ready): ?>
<p class="alert alert-info">This page needs migration 4.15.0. Run Database upgrade, then reload.</p>
<?php else: ?>
<p><a class="btn btn-secondary" href="staff_agreements.php?new=1#new-h">Write a new agreement</a></p>
<?php foreach ($agreements as $a): $aid = (int)$a['id']; $sfx = '_' . $aid;
    $signedNow = 0; $rows = [];
    $who = ($a['kind'] ?? 'staff') === 'exit' ? $leavers : $staff;
    foreach ($who as $s) {
        $l = $latest[(int)$s['id']][$aid] ?? null;
        $current = $l && (int)$l['version'] === (int)$a['version'] && strtotime(substr((string)$l['signed_at'], 0, 10) . ' +' . STAFF_RENEWAL_DAYS . ' days') >= strtotime(date('Y-m-d'));
        if ($current) $signedNow++;
        $rows[] = [$s, $l, $current];
    } ?>
<section class="card" aria-labelledby="agreement-<?= $aid ?>">
  <h2 id="agreement-<?= $aid ?>" tabindex="-1"><?= h($a['title']) ?>: <?= $a['active'] ? 'on' : 'off' ?>, version <?= (int)$a['version'] ?></h2>
  <?php if (($a['kind'] ?? 'staff') === 'exit'): ?><p>The exit acknowledgement: asked only of someone leaving, when an administrator asks them to sign it from their user page.</p><?php endif; ?>
  <p><?= $a['active'] ? $signedNow . ' of ' . count($who) . (($a['kind'] ?? 'staff') === 'exit' ? ' people asked' : ' active staff') . ' have signed the current version.' : 'Off: nobody is asked to sign it.' ?><?= $a['counts_as_confidentiality'] ? ' This is the confidentiality agreement.' : '' ?><?= $a['updated_by_name'] ? ' Last changed ' . fmt_datetime($a['updated_at']) . ' by ' . h($a['updated_by_name']) . '.' : '' ?></p>
  <?php if ($a['active']): ?>
  <h3>Who has signed</h3>
  <ul>
  <?php foreach ($rows as [$s, $l, $current]): ?>
    <li><?= h($s['name']) ?>: <?php if ($current): ?>signed <?= fmt_date($l['signed_at']) ?><?php elseif ($l): ?><strong>needs to sign again</strong> (last signed <?= fmt_date($l['signed_at']) ?>, version <?= (int)$l['version'] ?>)<?php else: ?><strong>not signed</strong><?php endif; ?><?php if ($l): ?>. <a href="staff_agreements.php?signature=<?= (int)$l['id'] ?>">Signed copy<span class="visually-hidden"> by <?= h($s['name']) ?></span></a><?php endif; ?></li>
  <?php endforeach; ?>
  </ul>
  <?php endif; ?>
  <?php if ($editing !== $aid): ?>
  <p><a href="staff_agreements.php?edit=<?= $aid ?>#agreement-<?= $aid ?>">Edit <?= h($a['title']) ?></a></p>
  <?php else: $mine = old_in('agreement_id') === (string)$aid && old_in('save_agreement') !== '';
    // After an error the boxes show what was ticked, not what is stored (4.28.0).
    $tick = fn(string $f, bool $stored): bool => $mine ? old_in($f) === '1' : $stored; ?>
  <h3>Edit</h3>
  <form method="post" action="staff_agreements.php" class="stacked" novalidate><?= csrf_field() ?>
    <?= form_error_summary(['title' . $sfx => 'Title', 'body' . $sfx => 'Agreement text'], ['title' . $sfx => 't' . $sfx, 'body' . $sfx => 'b' . $sfx]) ?>
    <p class="muted">Fields marked with an asterisk (<span aria-hidden="true">*</span><span class="visually-hidden">asterisk</span>) are required.</p>
    <input type="hidden" name="agreement_id" value="<?= $aid ?>">
    <input type="hidden" name="kind" value="<?= h($a['kind'] ?? 'staff') ?>">
    <div class="field"><label for="t<?= $sfx ?>">Title <?= required_mark() ?></label>
      <input type="text" id="t<?= $sfx ?>" name="title" maxlength="190" required aria-required="true" value="<?= h($mine ? old_in('title') : $a['title']) ?>" <?= field_aria('title' . $sfx) ?>><?= field_error('title' . $sfx) ?></div>
    <div class="field"><label for="b<?= $sfx ?>">Agreement text <?= required_mark() ?> <span class="hint">a line starting with # is a heading, a line starting with - is a list item</span></label>
      <textarea id="b<?= $sfx ?>" name="body" rows="18" required aria-required="true" <?= field_aria('body' . $sfx) ?>><?= h($mine ? old_in('body') : $a['body']) ?></textarea><?= field_error('body' . $sfx) ?></div>
    <div class="field"><label><input type="checkbox" name="small_correction" value="1" <?= $tick('small_correction', false) ? 'checked' : '' ?>> This change is only a small correction, such as a typo, so nobody is asked to sign again <span class="hint">the same lines, and at most 40 characters different; the change is kept in the history</span></label></div>
    <div class="field"><label><input type="checkbox" name="counts_as_confidentiality" value="1" <?= $tick('counts_as_confidentiality', (bool)$a['counts_as_confidentiality']) ? 'checked' : '' ?>> This is the confidentiality agreement (signing it records the date Compliance follows)</label></div>
    <div class="field"><label><input type="checkbox" name="active" value="1" <?= $tick('active', (bool)$a['active']) ? 'checked' : '' ?>> On: ask staff to sign it</label></div>
    <button type="submit" name="save_agreement" value="1" class="btn btn-primary">Save<span class="visually-hidden"> <?= h($a['title']) ?></span></button>
  </form>
  <?php endif; ?>
</section>
<?php endforeach; ?>
<?php if (isset($_GET['new'])): ?>
<section class="card" aria-labelledby="new-h">
  <h2 id="new-h" tabindex="-1">Write a new agreement</h2>
  <?php // After an error, "Who signs it", On and Confidentiality are as chosen (4.28.0): a reset to
        // All staff would turn an exit acknowledgement into one every staff member is asked to sign.
  $again = old_in('save_agreement') !== '' && old_in('agreement_id') === '0';
  $newKind = $again && old_in('kind') === 'exit' ? 'exit' : 'staff'; ?>
  <form method="post" action="staff_agreements.php" class="stacked" novalidate><?= csrf_field() ?>
    <?= form_error_summary(['title' => 'Title', 'body' => 'Agreement text'], ['title' => 'nt', 'body' => 'nb']) ?>
    <p class="muted">Fields marked with an asterisk (<span aria-hidden="true">*</span><span class="visually-hidden">asterisk</span>) are required.</p>
    <input type="hidden" name="agreement_id" value="0">
    <div class="field"><label for="nt">Title <?= required_mark() ?></label>
      <input type="text" id="nt" name="title" maxlength="190" required aria-required="true" value="<?= h(old_in('title')) ?>" <?= field_aria('title') ?>><?= field_error('title') ?></div>
    <div class="field"><label for="nb">Agreement text <?= required_mark() ?> <span class="hint">a line starting with # is a heading, a line starting with - is a list item</span></label>
      <textarea id="nb" name="body" rows="18" required aria-required="true" <?= field_aria('body') ?>><?= h(old_in('body')) ?></textarea><?= field_error('body') ?></div>
    <fieldset><legend>Who signs it</legend>
      <div class="field"><label><input type="radio" name="kind" value="staff" <?= $newKind === 'staff' ? 'checked' : '' ?>> All staff</label></div>
      <div class="field"><label><input type="radio" name="kind" value="exit" <?= $newKind === 'exit' ? 'checked' : '' ?>> Someone leaving (an exit acknowledgement)</label></div>
    </fieldset>
    <div class="field"><label><input type="checkbox" name="counts_as_confidentiality" value="1" <?= $again && old_in('counts_as_confidentiality') === '1' ? 'checked' : '' ?>> This is the confidentiality agreement (signing it records the date Compliance follows)</label></div>
    <div class="field"><label><input type="checkbox" name="active" value="1" <?= $again && old_in('active') === '1' ? 'checked' : '' ?>> On: ask staff to sign it now</label></div>
    <button type="submit" name="save_agreement" value="1" class="btn btn-primary">Add the agreement</button>
  </form>
</section>
<?php endif; ?>
<?php endif; ?>
<?php page_footer();
