<?php
// Vendor agreements (administrators, 3.0.36): the outside services that handle client
// information and whether a business associate agreement is signed with each. A record
// only: owner decision 2026-10-16, nothing is turned off by what is recorded here.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
csrf_check();
$user = require_login();
require_perm('admin');

const VENDOR_AGREEMENT_STATUSES = ['Not signed', 'Requested', 'Signed', 'Not needed'];

$ready = true;
try { db()->query('SELECT 1 FROM vendor_agreements LIMIT 1'); } catch (PDOException $e) { $ready = false; }

if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['save_vendor']) || isset($_POST['add_vendor']))) {
    $str = fn(string $k): string => is_string($_POST[$k] ?? null) ? trim($_POST[$k]) : '';
    $vid = (int)($_POST['vendor_id'] ?? 0);
    $suffix = $vid > 0 ? '_' . $vid : '';
    $errs = [];
    $status = $str('agreement_status');
    if (!in_array($status, VENDOR_AGREEMENT_STATUSES, true)) $errs['agreement_status' . $suffix] = 'Choose an agreement status.';
    $signed = $str('signed_on') === '' ? null : valid_date($str('signed_on'), 2000, (int)date('Y'));
    if ($str('signed_on') !== '' && $signed === null) $errs['signed_on' . $suffix] = 'Enter the date signed with a four-digit year, or leave it blank.';
    $renews = $str('renews_on') === '' ? null : valid_date($str('renews_on'), 2000, (int)date('Y') + 20);
    if ($str('renews_on') !== '' && $renews === null) $errs['renews_on' . $suffix] = 'Enter the renewal date with a four-digit year, or leave it blank.';
    $notes = $str('notes');
    if (mb_strlen($notes) > 500) $errs['notes' . $suffix] = 'Keep the notes to 500 characters or fewer.';
    if ($vid === 0) {
        if ($str('vendor') === '' || mb_strlen($str('vendor')) > 120) $errs['vendor'] = 'Name the vendor (120 characters or fewer).';
        if ($str('service') === '' || mb_strlen($str('service')) > 255) $errs['service'] = 'Say what the vendor does for you (255 characters or fewer).';
        if (mb_strlen($str('client_info')) > 255) $errs['client_info'] = 'Keep the client information to 255 characters or fewer.';
    }
    if ($errs) {
        keep_input();
        keep_field_errors($errs);
        redirect('vendors.php' . ($vid > 0 ? '#vendor-' . $vid : '#add-h'));
    }
    if ($vid > 0) {
        $u = db()->prepare('UPDATE vendor_agreements SET agreement_status = ?, signed_on = ?, renews_on = ?, notes = ?, updated_by = ?, updated_at = NOW() WHERE id = ?');
        $u->execute([$status, $signed, $renews, $notes !== '' ? $notes : null, $user['id'], $vid]);
        $name = db()->prepare('SELECT vendor FROM vendor_agreements WHERE id = ?');
        $name->execute([$vid]);
        $vendorName = (string)$name->fetchColumn();
        audit($user['id'], 'vendor.save', 'settings', null, $vendorName . ': ' . $status);
        flash('Saved: ' . $vendorName . ', ' . mb_strtolower($status) . '.');
        redirect('vendors.php#vendor-' . $vid);
    }
    try {
        db()->prepare('INSERT INTO vendor_agreements (vendor, service, client_info, agreement_status, signed_on, renews_on, notes, updated_by, updated_at) VALUES (?,?,?,?,?,?,?,?,NOW())')
            ->execute([$str('vendor'), $str('service'), $str('client_info'), $status, $signed, $renews, $notes !== '' ? $notes : null, $user['id']]);
    } catch (PDOException $e) {
        if ((int)($e->errorInfo[1] ?? 0) !== 1062) throw $e;
        keep_input();
        keep_field_errors(['vendor' => 'A vendor with that name is already listed.']);
        redirect('vendors.php#add-h');
    }
    audit($user['id'], 'vendor.add', 'settings', null, $str('vendor') . ': ' . $status);
    flash('Added ' . $str('vendor') . '.');
    redirect('vendors.php');
}

$vendors = $ready ? db()->query('SELECT v.*, u.name AS updated_by_name FROM vendor_agreements v LEFT JOIN users u ON u.id = v.updated_by ORDER BY v.vendor')->fetchAll() : [];
$unsigned = count(array_filter($vendors, fn($v) => in_array($v['agreement_status'], ['Not signed', 'Requested'], true)));

page_header('Vendor agreements', $user, 'admin', [['admin.php', 'Administration']]);
?>
<h1>Vendor agreements</h1>
<p>The outside services that handle client information, and whether a business associate agreement (BAA) is signed with each. This page is a record: nothing in the system is turned on or off by it.</p>
<?php if (!$ready): ?>
<p class="alert alert-info">This page needs migration 3.0.36. Run it, then reload.</p>
<?php else: ?>
<p><?= $unsigned === 0 ? 'Every listed vendor has an agreement or does not need one.' : $unsigned . ' of ' . count($vendors) . ' vendors have no signed agreement.' ?></p>
<?php foreach ($vendors as $v): $vid = (int)$v['id']; $sfx = '_' . $vid; $mine = old_in('vendor_id') === (string)$vid; ?>
<section class="card" aria-labelledby="vendor-<?= $vid ?>">
  <h2 id="vendor-<?= $vid ?>" tabindex="-1"><?= h($v['vendor']) ?>: <?= h($v['agreement_status']) ?></h2>
  <p><?= h($v['service']) ?>.<?= $v['client_info'] !== '' ? ' Client information: ' . h($v['client_info']) . '.' : '' ?></p>
  <?php if ($v['updated_at']): ?><p class="muted">Last updated <?= fmt_datetime($v['updated_at']) ?><?= $v['updated_by_name'] ? ' by ' . h($v['updated_by_name']) : '' ?>.</p><?php endif; ?>
  <form method="post" action="vendors.php" class="stacked" novalidate><?= csrf_field() ?>
    <input type="hidden" name="vendor_id" value="<?= $vid ?>">
    <div class="field"><label for="st<?= $sfx ?>">Agreement status<span class="visually-hidden">: <?= h($v['vendor']) ?></span></label>
      <select id="st<?= $sfx ?>" name="agreement_status"><?= select_options(VENDOR_AGREEMENT_STATUSES, $mine ? old_in('agreement_status') : $v['agreement_status']) ?></select><?= field_error('agreement_status' . $sfx) ?></div>
    <div class="field"><label for="so<?= $sfx ?>">Date signed</label>
      <input type="date" id="so<?= $sfx ?>" name="signed_on" value="<?= h($mine ? old_in('signed_on') : (string)$v['signed_on']) ?>" <?= field_aria('signed_on' . $sfx) ?>><?= field_error('signed_on' . $sfx) ?></div>
    <div class="field"><label for="ro<?= $sfx ?>">Renewal or review date</label>
      <input type="date" id="ro<?= $sfx ?>" name="renews_on" value="<?= h($mine ? old_in('renews_on') : (string)$v['renews_on']) ?>" <?= field_aria('renews_on' . $sfx) ?>><?= field_error('renews_on' . $sfx) ?></div>
    <div class="field"><label for="no<?= $sfx ?>">Notes</label>
      <input type="text" id="no<?= $sfx ?>" name="notes" maxlength="500" value="<?= h($mine ? old_in('notes') : (string)$v['notes']) ?>" <?= field_aria('notes' . $sfx) ?>><?= field_error('notes' . $sfx) ?></div>
    <button type="submit" name="save_vendor" value="1" class="btn btn-secondary">Save<span class="visually-hidden"> <?= h($v['vendor']) ?></span></button>
  </form>
</section>
<?php endforeach; ?>
<section class="card" aria-labelledby="add-h">
  <h2 id="add-h">Add a vendor</h2>
  <form method="post" action="vendors.php" class="stacked" novalidate><?= csrf_field() ?>
    <?= form_error_summary(['vendor' => 'Vendor', 'service' => 'What they do', 'client_info' => 'Client information', 'agreement_status' => 'Agreement status',
        'signed_on' => 'Date signed', 'renews_on' => 'Renewal date', 'notes' => 'Notes'], ['vendor' => 'nv_vendor', 'service' => 'nv_service', 'client_info' => 'nv_info',
        'agreement_status' => 'nv_status', 'signed_on' => 'nv_signed', 'renews_on' => 'nv_renews', 'notes' => 'nv_notes']) ?>
    <?php $new = old_in('vendor_id') === '' || old_in('vendor_id') === '0'; $o = fn(string $k): string => $new ? old_in($k) : ''; ?>
    <p class="hint">Fields marked * are required.</p>
    <div class="field"><label for="nv_vendor">Vendor <span aria-hidden="true">*</span><span class="visually-hidden"> (required)</span></label>
      <input type="text" id="nv_vendor" name="vendor" maxlength="120" value="<?= h($o('vendor')) ?>" <?= field_aria('vendor') ?>><?= field_error('vendor') ?></div>
    <div class="field"><label for="nv_service">What they do for you <span aria-hidden="true">*</span><span class="visually-hidden"> (required)</span></label>
      <input type="text" id="nv_service" name="service" maxlength="255" value="<?= h($o('service')) ?>" <?= field_aria('service') ?>><?= field_error('service') ?></div>
    <div class="field"><label for="nv_info">Client information they handle</label>
      <input type="text" id="nv_info" name="client_info" maxlength="255" value="<?= h($o('client_info')) ?>" <?= field_aria('client_info') ?>><?= field_error('client_info') ?></div>
    <div class="field"><label for="nv_status">Agreement status</label>
      <select id="nv_status" name="agreement_status"><?= select_options(VENDOR_AGREEMENT_STATUSES, $o('agreement_status') ?: 'Not signed') ?></select><?= field_error('agreement_status') ?></div>
    <div class="field"><label for="nv_signed">Date signed</label><input type="date" id="nv_signed" name="signed_on" value="<?= h($o('signed_on')) ?>" <?= field_aria('signed_on') ?>><?= field_error('signed_on') ?></div>
    <div class="field"><label for="nv_renews">Renewal or review date</label><input type="date" id="nv_renews" name="renews_on" value="<?= h($o('renews_on')) ?>" <?= field_aria('renews_on') ?>><?= field_error('renews_on') ?></div>
    <div class="field"><label for="nv_notes">Notes</label><input type="text" id="nv_notes" name="notes" maxlength="500" value="<?= h($o('notes')) ?>" <?= field_aria('notes') ?>><?= field_error('notes') ?></div>
    <button type="submit" name="add_vendor" value="1" class="btn btn-primary">Add vendor</button>
  </form>
</section>
<?php endif; ?>
<?php page_footer();
