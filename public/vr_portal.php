<?php
// VR portal tracker (4.23.0, billing): what is still to do in the VR agencies' own vendor portals
// (inc/vr_portal.php). Authorizations to accept there, invoices to enter there as payment requests,
// requests the agency rejected, and requests waiting to be paid; with a download of the invoices to
// enter, in the order the portals ask for their details.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
csrf_check();
$user = require_login();
require_perm('billing');
$uid = (int)$user['id'];
// A caseload-limited account sees and changes only its own clients' (as on Invoices).
$scopeUser = caseload_scoped() ? $uid : null;
$str = fn(string $k): string => is_string($_POST[$k] ?? null) ? trim($_POST[$k]) : '';
$agencyIn = is_string($_GET['agency'] ?? null) && trim($_GET['agency']) !== '' ? trim($_GET['agency']) : null;
$back = 'vr_portal.php' . ($agencyIn !== null ? '?agency=' . rawurlencode($agencyIn) : '');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['track_state'])) {
    $tid = (int)($_POST['track_id'] ?? 0);
    $state = $str('track_state');
    $note = $str('note');
    if ($state === 'returned' && $note === '') {
        keep_input(); keep_field_errors(['note_t' . $tid => 'What the agency asked for: say why it was returned.']);
        flash('Not saved: say what the agency asked for when it returned the authorization.', 'error');
        redirect($back . '#accept-h');
    }
    $done = vr_portal_set_track($tid, $state, $note, $uid, $scopeUser);
    flash($done ? 'Saved: ' . strtolower(AUTH_PORTAL_STATES[$state]) . '.' : 'That authorization could not be updated.', $done ? 'success' : 'error');
    redirect($back . '#' . ($state === 'returned' ? 'returned-h' : 'accept-h'));
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['invoice_state'])) {
    $iid = (int)($_POST['invoice_id'] ?? 0);
    $state = $str('invoice_state');
    $ref = $str('portal_ref');
    $note = $str('note');
    $errs = [];
    if (mb_strlen($ref) > 80) $errs['ref_i' . $iid] = 'Payment request number: keep it to 80 characters.';
    if ($state === 'rejected' && $note === '') $errs['note_i' . $iid] = 'Why it was rejected: say what the agency gave as the reason.';
    if ($errs) { keep_input(); keep_field_errors($errs); flash('Not saved: ' . implode(' ', $errs), 'error'); redirect($back . '#' . ($state === 'rejected' ? 'waiting-h' : 'enter-h')); }
    $done = vr_portal_set_invoice($iid, $state, $ref, $note, $uid, $scopeUser);
    flash($done ? 'Saved: ' . strtolower(PAYREQ_STATES[$state]) . '.' : 'That invoice could not be updated.', $done ? 'success' : 'error');
    redirect($back . '#' . ($state === 'rejected' ? 'rejected-h' : 'enter-h'));
}

$lists = vr_portal_lists($agencyIn, $scopeUser);
if (isset($_GET['download'])) {
    audit($uid, 'report.export', 'invoice', null, 'VR portal: invoices to enter (' . count($lists['enter']) . ' rows)');
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="payment_requests_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    write_csv_row($out, ['Agency', 'Vendor number', 'Authorization number', 'VR case number', 'Client', 'Invoice number', 'Service from', 'Service to', 'Hours', 'Hourly rate', 'Mileage', 'Mileage amount', 'Expenses', 'Total', 'Submitted']);
    // Every cell through csv_cell(): a name typed on a public form never runs as a spreadsheet formula.
    foreach ($lists['enter'] as $r) write_csv_row($out, array_map('csv_cell', [$r['funding_agency'], $r['vendor_number'] ?: agency_vendor_number($r['funding_agency']), $r['auth_number'], $r['vr_case_number'], $r['client_name'], $r['invoice_number'],
        $r['period_start'], $r['period_end'], $r['hours'], $r['hourly_rate'], $r['mileage'], $r['mileage_amount'], $r['expenses_amount'], $r['total_amount'], $r['submitted_at']]));
    exit;
}

$agencyNames = array_values(array_unique(array_merge(array_map(fn($a) => (string)$a['name'], array_filter(agencies_all(), fn($a) => (int)$a['is_active'] === 1)), $agencyIn !== null ? [$agencyIn] : [])));
sort($agencyNames);
$portalLink = function (?string $agency): string {
    $a = agency_find($agency);
    $url = trim((string)($a['portal_url'] ?? ''));
    return $url !== '' ? ' <a href="' . h($url) . '" target="_blank" rel="noopener noreferrer">' . h(trim((string)($a['portal_name'] ?? '')) ?: 'Portal') . '<span class="visually-hidden"> for ' . h((string)$agency) . ' (opens a new tab)</span></a>' : '';
};
$trackLine = fn(array $r): string => h($r['client_name']) . ', authorization ' . h($r['auth_number']) . ' from ' . h($r['funding_agency'])
    . ($r['start_date'] ? ', ' . h(fmt_date($r['start_date'])) . ' to ' . h(fmt_date($r['expiration_date'])) : '');
$invLine = fn(array $r): string => 'Invoice ' . h($r['invoice_number']) . ' for ' . h($r['client_name']) . ', ' . h(fmt_money((float)$r['total_amount'])) . ' to ' . h($r['funding_agency'])
    . ', authorization ' . h((string)$r['auth_number']) . ($r['period_start'] ? ', services ' . h(fmt_date($r['period_start'])) . ' to ' . h(fmt_date($r['period_end'])) : '');
$hasErr = fn(string $k) => has_field_error($k);

page_header('VR portal', $user, 'invoices', [['invoices.php', 'Invoices']]);
?>
<h1>VR portal</h1>
<p>What is still to do in the VR agencies' own vendor portals: authorizations to accept, invoices to enter as payment requests, and requests to follow up. Mark each one here once it is done there; record payment on Invoices as before. It counts authorizations added and invoices submitted from <?= h(fmt_date(vr_portal_since())) ?>.</p>
<form method="get" action="vr_portal.php" class="page-actions" role="search" aria-label="Choose an agency">
  <label for="agency">Agency</label>
  <select id="agency" name="agency"><option value="">All agencies</option><?php foreach ($agencyNames as $n): ?><option value="<?= h($n) ?>"<?= $n === $agencyIn ? ' selected' : '' ?>><?= h($n) ?></option><?php endforeach; ?></select>
  <button type="submit" class="btn btn-secondary btn-sm">Show</button>
</form>

<section class="card" aria-labelledby="accept-h">
<h2 id="accept-h" tabindex="-1">Authorizations to accept (<?= count($lists['accept']) ?>)</h2>
<?php if (!$lists['accept']): ?><p>None. Every new authorization has been accepted in its portal.</p><?php else: ?>
<ul>
<?php foreach ($lists['accept'] as $r): $tid = (int)$r['id']; ?>
  <li id="t-<?= $tid ?>"><?= $r['portal_status'] === 'accepted' ? 'New number (was ' . h((string)$r['portal_auth']) . '): ' : '' ?><?= can('clients') ? '<a href="track.php?id=' . $tid . '">' . $trackLine($r) . '</a>' : $trackLine($r) ?><?= $portalLink($r['funding_agency']) ?>
    <form method="post" action="<?= h($back) ?>" class="inline-form"><?= csrf_field() ?><input type="hidden" name="track_id" value="<?= $tid ?>">
      <button type="submit" name="track_state" value="accepted" class="btn btn-secondary btn-sm">Accepted<span class="visually-hidden">: <?= h($r['client_name']) ?>, authorization <?= h($r['auth_number']) ?></span></button></form>
    <details<?= $hasErr('note_t' . $tid) ? ' open' : '' ?>><summary>Returned by the agency<span class="visually-hidden">: <?= h($r['client_name']) ?>, authorization <?= h($r['auth_number']) ?></span></summary>
    <form method="post" action="<?= h($back) ?>" class="stacked" novalidate><?= csrf_field() ?><input type="hidden" name="track_id" value="<?= $tid ?>">
      <div class="field"><label for="note_t<?= $tid ?>">What the agency asked for <?= required_mark() ?></label>
        <input type="text" id="note_t<?= $tid ?>" name="note" maxlength="255" value="<?= h(old_in('track_id') === (string)$tid ? old_in('note') : '') ?>" <?= field_aria('note_t' . $tid) ?>><?= field_error('note_t' . $tid) ?></div>
      <button type="submit" name="track_state" value="returned" class="btn btn-secondary btn-sm">Save as returned</button></form></details></li>
<?php endforeach; ?>
</ul><?php endif; ?>
</section>

<?php if ($lists['returned']): ?>
<section class="card" aria-labelledby="returned-h">
<h2 id="returned-h" tabindex="-1">Authorizations returned by the agency (<?= count($lists['returned']) ?>)</h2>
<ul>
<?php foreach ($lists['returned'] as $r): $tid = (int)$r['id']; ?>
  <li id="t-<?= $tid ?>"><?= can('clients') ? '<a href="track.php?id=' . $tid . '">' . $trackLine($r) . '</a>' : $trackLine($r) ?>. Returned <?= h(fmt_date($r['portal_at'])) ?>: <?= h((string)$r['portal_note']) ?><?= $portalLink($r['funding_agency']) ?>
    <form method="post" action="<?= h($back) ?>" class="inline-form"><?= csrf_field() ?><input type="hidden" name="track_id" value="<?= $tid ?>">
      <button type="submit" name="track_state" value="accepted" class="btn btn-secondary btn-sm">Accepted now<span class="visually-hidden">: <?= h($r['client_name']) ?>, authorization <?= h($r['auth_number']) ?></span></button></form></li>
<?php endforeach; ?>
</ul>
</section>
<?php endif; ?>

<section class="card" aria-labelledby="enter-h">
<h2 id="enter-h" tabindex="-1">Invoices to enter as payment requests (<?= count($lists['enter']) ?>)</h2>
<?php if (!$lists['enter']): ?><p>None. Every submitted VR invoice has been entered in its portal.</p><?php else: ?>
<p><a href="<?= h($back . ($agencyIn !== null ? '&' : '?') . 'download=1') ?>">Download these for entering (spreadsheet)</a></p>
<ul>
<?php foreach ($lists['enter'] as $r): $iid = (int)$r['id']; $mine = old_in('invoice_id') === (string)$iid; ?>
  <li id="i-<?= $iid ?>"><?= $invLine($r) ?><?= $portalLink($r['funding_agency']) ?>
    <form method="post" action="<?= h($back) ?>" class="inline-form" novalidate><?= csrf_field() ?><input type="hidden" name="invoice_id" value="<?= $iid ?>">
      <label for="ref_i<?= $iid ?>">Payment request number, if the portal gave one</label>
      <input type="text" id="ref_i<?= $iid ?>" name="portal_ref" maxlength="80" value="<?= h($mine ? old_in('portal_ref') : '') ?>" <?= field_aria('ref_i' . $iid) ?>><?= field_error('ref_i' . $iid) ?>
      <button type="submit" name="invoice_state" value="entered" class="btn btn-secondary btn-sm">Entered<span class="visually-hidden">: invoice <?= h($r['invoice_number']) ?></span></button></form></li>
<?php endforeach; ?>
</ul><?php endif; ?>
</section>

<?php if ($lists['rejected']): ?>
<section class="card" aria-labelledby="rejected-h">
<h2 id="rejected-h" tabindex="-1">Payment requests rejected (<?= count($lists['rejected']) ?>)</h2>
<ul>
<?php foreach ($lists['rejected'] as $r): $iid = (int)$r['id']; ?>
  <li id="i-<?= $iid ?>"><?= $invLine($r) ?>. Rejected <?= h(fmt_date($r['portal_at'])) ?>: <?= h((string)$r['portal_note']) ?><?= $portalLink($r['funding_agency']) ?>
    <form method="post" action="<?= h($back) ?>" class="inline-form"><?= csrf_field() ?><input type="hidden" name="invoice_id" value="<?= $iid ?>">
      <button type="submit" name="invoice_state" value="entered" class="btn btn-secondary btn-sm">Entered again<span class="visually-hidden">: invoice <?= h($r['invoice_number']) ?></span></button></form></li>
<?php endforeach; ?>
</ul>
</section>
<?php endif; ?>

<section class="card" aria-labelledby="waiting-h">
<h2 id="waiting-h" tabindex="-1">Waiting to be paid (<?= count($lists['waiting']) ?>)</h2>
<?php if (!$lists['waiting']): ?><p>None.</p><?php else: ?>
<ul>
<?php foreach ($lists['waiting'] as $r): $iid = (int)$r['id']; $days = (int)floor((time() - strtotime((string)$r['portal_at'])) / 86400); ?>
  <li id="i-<?= $iid ?>"><?= $invLine($r) ?>. Entered <?= h(fmt_date($r['portal_at'])) ?><?= $r['portal_ref'] ? ' as request ' . h($r['portal_ref']) : '' ?>, <?= $days ?> day<?= $days === 1 ? '' : 's' ?> ago<?= $days > agency_terms_days($r['funding_agency']) ? ' <strong>(past its payment terms)</strong>' : '' ?>.
    <details<?= $hasErr('note_i' . $iid) ? ' open' : '' ?>><summary>Rejected by the agency<span class="visually-hidden">: invoice <?= h($r['invoice_number']) ?></span></summary>
    <form method="post" action="<?= h($back) ?>" class="stacked" novalidate><?= csrf_field() ?><input type="hidden" name="invoice_id" value="<?= $iid ?>">
      <div class="field"><label for="note_i<?= $iid ?>">The agency's reason <?= required_mark() ?></label>
        <input type="text" id="note_i<?= $iid ?>" name="note" maxlength="255" value="<?= h(old_in('invoice_id') === (string)$iid ? old_in('note') : '') ?>" <?= field_aria('note_i' . $iid) ?>><?= field_error('note_i' . $iid) ?></div>
      <button type="submit" name="invoice_state" value="rejected" class="btn btn-secondary btn-sm">Save as rejected</button></form></details></li>
<?php endforeach; ?>
</ul><?php endif; ?>
</section>
<?php page_footer();
