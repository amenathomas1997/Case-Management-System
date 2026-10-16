<?php
// Spreadsheet downloads (3.0.50): the everyday lists as CSV files that sort and filter in
// Excel with a screen reader, each with its own page's permission and caseload limit.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
$user = require_login();
$key = is_string($_GET['list'] ?? null) ? $_GET['list'] : '';
if ($key !== '') {
    if (!list_export_allowed($key)) { flash('That list is not available to your account.', 'error'); redirect('exports.php'); }
    [$headers, $rows] = list_export_rows($key, (int)$user['id']);
    list_export_send($key, (int)$user['id'], $headers, $rows);
}
page_header('Spreadsheet downloads', $user, 'analytics');
?>
<h1>Spreadsheet downloads</h1>
<p>Each list downloads as a CSV file for Excel<?= caseload_scoped() ? ', limited to your caseload' : '' ?>. Every download is recorded in the audit log.</p>
<ul>
  <?php foreach (LIST_EXPORTS as $k => [$label, , $what]): if (!list_export_allowed($k)) continue; ?>
  <li><a href="exports.php?list=<?= h($k) ?>">Download <?= h(mb_strtolower($label)) ?> (CSV)</a>: <?= h($what) ?></li>
  <?php endforeach; ?>
</ul>
<?php page_footer();
