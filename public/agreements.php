<?php
// My agreements (4.15.0): the agreements this staff member is asked to sign, read and signed
// here by typing their name (inc/staff_agreements.php). ?id= opens one to read and sign;
// ?copy= shows a copy they signed, exactly as it read then.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
csrf_check();
$user = require_login();
$uid = (int)$user['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sign_agreement'])) {
    $aid = (int)($_POST['agreement_id'] ?? 0);
    $typed = is_string($_POST['signed_name'] ?? null) ? $_POST['signed_name'] : '';
    $agreed = !empty($_POST['agree']);
    if (!$agreed) {
        $_SESSION['agreement_typed'] = mb_substr($typed, 0, 120);
        keep_field_errors(['agree' => 'Tick that you have read the agreement and agree to it.']);
        redirect('agreements.php?id=' . $aid);
    }
    [$sid, $err] = staff_agreement_sign($uid, $aid, $typed, (int)($_POST['version'] ?? 0), 'website', is_string($_POST['text_hash'] ?? null) ? $_POST['text_hash'] : '');
    if ($err !== null) {
        $_SESSION['agreement_typed'] = mb_check_encoding($typed, 'UTF-8') ? mb_substr($typed, 0, 120) : '';
        keep_field_errors(['signed_name' => $err]);
        redirect('agreements.php?id=' . $aid);
    }
    unset($_SESSION['agreement_typed']);
    flash('Signed. A copy is kept with your account, and you are asked again in a year or if the text changes.');
    redirect('agreements.php');
}

$status = staff_agreement_status($uid);

/* ---- A copy this person signed ---- */
if (isset($_GET['copy'])) {
    $q = db()->prepare('SELECT * FROM staff_agreement_signatures WHERE id = ? AND user_id = ?');
    $q->execute([(int)$_GET['copy'], $uid]);
    $copy = $q->fetch();
    if (!$copy) { flash('That signed copy was not found.', 'error'); redirect('agreements.php'); }
    page_header($copy['title'] . ' (signed copy)', $user, 'account', [['account.php', 'My Account'], ['agreements.php', 'My agreements']]);
    ?>
<h1><?= h($copy['title']) ?>: your signed copy</h1>
<p>Signed by <strong><?= h($copy['signed_name']) ?></strong> on <?= fmt_datetime($copy['signed_at']) ?>, version <?= (int)$copy['version'] ?>. This is the text exactly as it read when you signed.</p>
<?php $fixes = staff_agreement_corrections_after((int)$copy['agreement_id'], (int)$copy['version'], (string)$copy['signed_at']); if ($fixes): ?>
<p>Small corrections were made to the current text after you signed: <?= h(implode('; ', array_map(fn($f) => fmt_datetime($f['changed_at']) . ($f['name'] ? ' by ' . $f['name'] : ''), $fixes))) ?>. Your copy above is unchanged.</p>
<?php endif; ?>
<section class="card" aria-label="Agreement text"><?= staff_agreement_html((string)$copy['body'], 2) ?></section>
<?php
    page_footer();
    exit;
}

/* ---- One agreement to read and sign ---- */
if (isset($_GET['id'])) {
    $row = null;
    foreach ($status as $r) if ((int)$r['id'] === (int)$_GET['id']) $row = $r;
    if (!$row) { flash('That agreement is no longer asked for.', 'error'); redirect('agreements.php'); }
    $typed = (string)($_SESSION['agreement_typed'] ?? '');
    page_header($row['title'], $user, 'account', [['account.php', 'My Account'], ['agreements.php', 'My agreements']]);
    ?>
<h1><?= h($row['title']) ?></h1>
<p><?= h($row['why']) ?>.</p>
<section class="card" aria-labelledby="text-h">
  <h2 id="text-h">The agreement</h2>
  <?= staff_agreement_html((string)$row['body']) ?>
</section>
<?php if ($row['state'] === 'signed'): ?>
<p>You have signed this version. <a href="agreements.php?copy=<?= (int)$row['signature_id'] ?>">Read your signed copy</a>.</p>
<?php else: ?>
<section class="card" aria-labelledby="sign-h">
  <h2 id="sign-h">Sign</h2>
  <form method="post" action="agreements.php" class="stacked" novalidate><?= csrf_field() ?>
    <?= form_error_summary(['agree' => 'Agreement', 'signed_name' => 'Your full name'], ['agree' => 'agree', 'signed_name' => 'signed_name']) ?>
    <input type="hidden" name="agreement_id" value="<?= (int)$row['id'] ?>">
    <input type="hidden" name="version" value="<?= (int)$row['version'] ?>">
    <input type="hidden" name="text_hash" value="<?= h(staff_agreement_hash((string)$row['title'], (string)$row['body'])) ?>">
    <div class="field"><label><input type="checkbox" id="agree" name="agree" value="1" <?= field_aria('agree') ?>> I have read this agreement and agree to it</label><?= field_error('agree') ?></div>
    <div class="field"><label for="signed_name">Type your full name to sign <span class="hint">as it is on your account: <?= h($user['name']) ?></span></label>
      <input type="text" id="signed_name" name="signed_name" maxlength="120" autocomplete="name" value="<?= h($typed) ?>" <?= field_aria('signed_name') ?>><?= field_error('signed_name') ?></div>
    <button type="submit" name="sign_agreement" value="1" class="btn btn-primary">Sign the agreement</button>
  </form>
</section>
<?php endif; ?>
<?php
    page_footer();
    exit;
}

/* ---- The list ---- */
$needed = array_filter($status, fn($r) => $r['state'] !== 'signed');
page_header('My agreements', $user, 'account', [['account.php', 'My Account']]);
?>
<h1>My agreements</h1>
<?php if (!$status): ?>
<p>There are no agreements for you to sign.</p>
<?php else: ?>
<p><?= $needed ? count($needed) . ' of ' . count($status) . ' need your signature.' : 'Every agreement is signed and current.' ?></p>
<ul>
<?php foreach ($status as $r): ?>
  <li><a href="agreements.php?id=<?= (int)$r['id'] ?>"><?= h($r['title']) ?></a>: <?= $r['state'] === 'needed' ? '<strong>needs your signature</strong> (' . h(mb_strtolower($r['why'])) . ')' : h($r['why']) ?><?php if ($r['signature_id']): ?>. <a href="agreements.php?copy=<?= (int)$r['signature_id'] ?>">Signed copy<span class="visually-hidden"> of <?= h($r['title']) ?></span></a><?php endif; ?></li>
<?php endforeach; ?>
</ul>
<?php endif; ?>
<?php page_footer();
