<?php
// Data quality (3.0.49): records to tidy before a report is due — likely duplicates, closed
// clients with active tracks, Active Training without a track, missing key details, hours
// with no service category, and minors with no consent requested. Caseload-scoped; read-only.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
$user = require_login();
require_perm('clients');
$checks = data_quality_checks(caseload_scoped() ? (int)$user['id'] : null);
$total = array_sum(array_map(fn($c) => count($c[3]), $checks));
page_header('Data quality', $user, 'analytics');
?>
<h1>Data quality</h1>
<p><?= $total ? $total . ' item' . ($total === 1 ? '' : 's') . ' to check' : 'Nothing to check: the records are tidy' ?><?= caseload_scoped() ? ' on your caseload' : '' ?>.</p>
<?php foreach ($checks as [$key, $heading, $what, $rows]): ?>
<section class="card" aria-labelledby="dq-<?= $key ?>">
  <h2 id="dq-<?= $key ?>"><?= h($heading) ?> (<?= count($rows) ?>)</h2>
  <?php if (!$rows): ?><p>None.</p><?php else: ?>
  <p><?= h($what) ?></p>
  <ul>
    <?php foreach ($rows as $r): ?>
    <li><a href="<?= h(isset($r['link']) && can('admin') ? $r['link'] : 'client.php?id=' . (int)$r['client_id']) ?>"><?= h($r['name']) ?></a>: <?= h((string)$r['detail']) ?></li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</section>
<?php endforeach; ?>
<?php page_footer();
