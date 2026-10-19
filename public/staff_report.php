<?php
// Staff compliance report (administrators, 4.16.0): for each staff member, every agreement
// signed (with when and the version), every training passed, credentials and their expiry,
// and the exit acknowledgement; on screen or as a spreadsheet (CSV) for an audit or a funder.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
csrf_check();
$user = require_login();
require_perm('admin');

$inactive = !empty($_GET['inactive']);
$people = db()->query('SELECT id, name, email, is_active, created_at, exit_requested_at FROM users' . ($inactive ? '' : ' WHERE is_active = 1') . ' ORDER BY name')->fetchAll();
$agreeLatest = staff_agreement_latest();
$trainLatest = training_latest();
$hold = access_hold_mode();

/** One row per person and requirement: [person id, name, kind, item, status, date, detail]. */
$rows = [];
foreach ($people as $p) {
    $pid = (int)$p['id'];
    foreach (staff_agreement_status($pid, $agreeLatest) as $a) {
        $rows[] = [$pid, $p['name'], ($a['kind'] ?? 'staff') === 'exit' ? 'Exit acknowledgement' : 'Agreement', $a['title'],
            ['signed' => 'Signed', 'due' => 'Renewal due', 'needed' => 'Not signed'][$a['state']], $a['signed_at'] ? substr((string)$a['signed_at'], 0, 10) : '',
            $a['state'] === 'needed' ? $a['why'] : 'version ' . (int)$a['version'] . ($a['renew_by'] ? ', renew by ' . $a['renew_by'] : '')];
    }
    foreach (training_status($pid, $trainLatest) as $t) {
        $rows[] = [$pid, $p['name'], 'Training', $t['title'], ['done' => 'Passed', 'due' => 'Renewal due', 'needed' => 'Not passed'][$t['state']],
            $t['done_at'] ? substr((string)$t['done_at'], 0, 10) : '', $t['state'] === 'needed' ? $t['why'] : 'version ' . (int)$t['version'] . ($t['renew_by'] ? ', renew by ' . $t['renew_by'] : '')];
    }
    foreach (credentials_for($pid) as $c) {
        [$st, $words] = credential_state($c);
        $rows[] = [$pid, $p['name'], 'Credential', $c['name'], ['current' => 'Current', 'soon' => 'Expiring soon', 'expired' => 'Expired'][$st], (string)($c['expires_on'] ?? ''), $words];
    }
    if (!(int)$p['is_active']) $rows[] = [$pid, $p['name'], 'Account', 'Switched off', 'Inactive', '', ''];
}

if (isset($_GET['download'])) {
    audit($user['id'], 'report.staff_compliance', 'settings', null, count($people) . ' staff, ' . count($rows) . ' rows' . ($inactive ? ', with inactive accounts' : ''));
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="staff-compliance-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // so spreadsheets read accented names correctly
    // The shared writer (inc/security.php) runs every cell through csv_cell() (4.28.0), so a credential
    // name a staff member typed (for example one starting with =) is never read as a formula.
    write_csv_row($out, ['Staff member', 'Kind', 'Item', 'Status', 'Date', 'Detail']);
    foreach ($rows as $r) write_csv_row($out, array_slice($r, 1));
    fclose($out);
    exit;
}

page_header('Staff compliance report', $user, 'admin', [['admin.php', 'Administration']]);
$outstanding = count(array_filter($rows, fn($r) => in_array($r[4], ['Not signed', 'Not passed', 'Expired'], true)));
?>
<h1>Staff compliance report</h1>
<p>Every staff member's agreements, training, credentials and exit acknowledgement, with dates and versions. <?= $outstanding ? $outstanding . ' items are outstanding.' : 'Nothing is outstanding.' ?> Client records are held <?= ['off' => 'for nobody', 'new' => 'for new staff until they sign and pass', 'all' => 'for anyone with something outstanding past ' . access_hold_days() . ' days'][$hold] ?> (Staff training).</p>
<p><a class="btn btn-secondary" href="staff_report.php?download=1<?= $inactive ? '&amp;inactive=1' : '' ?>">Download as a spreadsheet (CSV)</a>
   <a href="staff_report.php<?= $inactive ? '' : '?inactive=1' ?>"><?= $inactive ? 'Show active staff only' : 'Include switched-off accounts' ?></a></p>
<?php foreach ($people as $p): $mine = array_filter($rows, fn($r) => $r[0] === (int)$p['id']); ?>
<section class="card" aria-labelledby="p-<?= (int)$p['id'] ?>">
  <h2 id="p-<?= (int)$p['id'] ?>"><?= h($p['name']) ?><?= (int)$p['is_active'] ? '' : ' (switched off)' ?></h2>
  <?php if (!$mine): ?><p>Nothing is asked of them yet.</p><?php else: ?>
  <div class="table-scroll" tabindex="0" role="region" aria-labelledby="sr-cap-<?= (int)$p['id'] ?>"><table class="table">
    <caption class="visually-hidden" id="sr-cap-<?= (int)$p['id'] ?>"><?= h($p['name']) ?>: agreements, training and credentials</caption>
    <thead><tr><th scope="col">Item</th><th scope="col">Status</th><th scope="col">Date</th><th scope="col">Detail</th></tr></thead>
    <tbody>
    <?php foreach ($mine as $r): ?>
      <tr><th scope="row"><?= h($r[2] . ': ' . $r[3]) ?></th><td><?= in_array($r[4], ['Not signed', 'Not passed', 'Expired'], true) ? '<strong>' . h($r[4]) . '</strong>' : h($r[4]) ?></td><td><?= $r[5] !== '' ? fmt_date($r[5]) : '—' ?></td><td><?= h($r[6]) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</section>
<?php endforeach; ?>
<?php page_footer();
