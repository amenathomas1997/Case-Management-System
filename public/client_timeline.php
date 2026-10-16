<?php
// A client's timeline (3.0.50): case events newest first under month headings, and the
// time spent in each status. For staff who can open the client.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
$user = require_login();
require_perm('clients');
$id = (int)($_GET['id'] ?? 0);
$c = db()->prepare('SELECT id, name, assigned_to, status FROM clients WHERE id = ?');
$c->execute([$id]);
$client = $c->fetch();
if (!$client) { flash('Client not found.', 'error'); redirect('clients.php'); }
scope_check_client($client, $user);
record_client_view((int)$user['id'], $id, 'timeline');
$events = client_timeline($id);
$sd = client_status_days($id);
page_header('Timeline — ' . $client['name'], $user, 'clients', [['client.php?id=' . $id, $client['name']]]);
?>
<h1>Timeline for <?= h($client['name']) ?></h1>
<p><a href="client.php?id=<?= $id ?>">Back to <?= h($client['name']) ?></a></p>
<section class="card" aria-labelledby="tl-status">
  <h2 id="tl-status">Time in each status</h2>
  <?php if (!$sd['days']): ?><p>No status history.</p><?php else: ?>
  <p><?= $sd['total'] ?> day<?= $sd['total'] === 1 ? '' : 's' ?> since the record was created on <?= fmt_date($sd['since']) ?>; now <?= h($client['status']) ?>.</p>
  <ul><?php foreach ($sd['days'] as $st => $d): ?><li><?= h($st) ?>: <?= $d ?> day<?= $d === 1 ? '' : 's' ?></li><?php endforeach; ?></ul>
  <?php endif; ?>
</section>
<section class="card" aria-labelledby="tl-events">
  <h2 id="tl-events">Events (<?= count($events) ?>), newest first</h2>
  <?php if (!$events): ?><p>Nothing recorded yet.</p><?php endif; ?>
  <?php $month = null; foreach ($events as $e): $m = date('F Y', strtotime($e['at'])); if ($m !== $month): if ($month !== null) echo '</ul>'; $month = $m; ?>
  <h3><?= h($m) ?></h3><ul>
  <?php endif; ?>
    <li><?= fmt_date(substr($e['at'], 0, 10)) ?>: <?= $e['link'] ? '<a href="' . h($e['link']) . '">' . h($e['what']) . '</a>' : h($e['what']) ?></li>
  <?php endforeach; if ($month !== null) echo '</ul>'; ?>
</section>
<?php page_footer();
