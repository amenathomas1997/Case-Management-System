<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
$user = require_login();
require_perm('clients');

$scoped = caseload_scoped();
$referrals = [];
if (can('referrals')) {
    $sql = "SELECT r.*, u.name AS assigned_name FROM referrals r LEFT JOIN users u ON u.id = r.assigned_to
        WHERE r.status IN ('New','Reviewed')" . ($scoped ? ' AND (r.assigned_to = ? OR r.assigned_to IS NULL)' : '') . " ORDER BY r.created_at";
    $rq = db()->prepare($sql);
    $rq->execute($scoped ? [(int)$user['id']] : []);
    $referrals = $rq->fetchAll();
}

$cq = db()->prepare("SELECT c.*, u.name AS instructor,
        (SELECT MAX(changed_at) FROM client_status_history h WHERE h.client_id = c.id
            AND (h.note IS NULL OR h.note NOT LIKE '[merged] %')) AS stage_since
    FROM clients c LEFT JOIN users u ON u.id = c.assigned_to
    WHERE c.archived_at IS NULL" . ($scoped ? ' AND c.assigned_to = ?' : '') . " ORDER BY c.name");
$cq->execute($scoped ? [(int)$user['id']] : []);
$clients = $cq->fetchAll();

$stages = [
    'Pending Assessment' => [],
    'Waitlist' => [],
    'Active Training' => [],
    'On Hold' => [],
];
$closed = [];
foreach ($clients as $c) {
    $since = $c['stage_since'] ?? $c['created_at'];
    $c['days_in_stage'] = max(0, (int)floor((time() - strtotime($since)) / 86400));
    if (isset($stages[$c['status']])) {
        $stages[$c['status']][] = $c;
    } elseif (str_starts_with($c['status'], 'Closed') && strtotime($since) > strtotime('-90 days')) {
        $closed[] = $c;
    }
}

/** Days in the current stage. Waiting stages ($flag) over 30 days are marked in
 *  words as well as by colour and weight; in Active Training a long stay is normal. */
function days_note(int $d, bool $flag = true): string {
    $txt = $d . ' day' . ($d === 1 ? '' : 's') . ' in stage';
    return $flag && $d > 30
        ? '<strong class="field-error">' . $txt . ' — over 30 days</strong>'
        : '<span class="muted">' . $txt . '</span>';
}
const PIPELINE_WAITING_STAGES = ['Pending Assessment', 'Waitlist', 'On Hold'];

page_header('Pipeline', $user, 'pipeline');
?>
<?php // Each name link is a full 44px-high target (the lists stack one-line links). ?>
<style>.pipeline-list > li > a { display: inline-flex; align-items: center; min-height: 44px; }</style>
<h1>Intake pipeline</h1>
<p class="muted">Where every prospect and client sits, and for how long. Long stints in early stages are where revenue slips away:
   referrals and clients waiting more than 30 days (pending assessment, waitlist or on hold) are marked “over 30 days”.</p>

<div class="grid" style="grid-template-columns: repeat(auto-fit, minmax(min(15rem, 100%), 1fr));">
  <section class="card" aria-labelledby="p-ref">
    <h2 id="p-ref">Referrals awaiting action (<?= count($referrals) ?>)</h2>
    <?php if (!$referrals): ?><p class="muted">None waiting.</p><?php endif; ?>
    <ul class="pipeline-list">
    <?php foreach ($referrals as $r): $d = max(0, (int)floor((time() - strtotime($r['created_at'])) / 86400)); ?>
      <li>
        <a href="referrals.php?view=<?= (int)$r['id'] ?>"><?= h($r['client_name']) ?><span class="visually-hidden"> (referral)</span></a> <?= status_badge($r['status']) ?><br>
        <?= days_note($d) ?><?= $r['assigned_name'] ? ' · ' . h($r['assigned_name']) : ' · <strong>unassigned</strong>' ?>
      </li>
    <?php endforeach; ?>
    </ul>
  </section>

  <?php foreach ($stages as $stage => $list): ?>
  <section class="card" aria-labelledby="p-<?= h(str_replace(' ', '-', $stage)) ?>">
    <h2 id="p-<?= h(str_replace(' ', '-', $stage)) ?>"><?= h($stage) ?> (<?= count($list) ?>)</h2>
    <?php if (!$list): ?><p class="muted">No clients in this stage.</p><?php endif; ?>
    <ul class="pipeline-list">
    <?php foreach ($list as $c): ?>
      <li>
        <a href="client.php?id=<?= (int)$c['id'] ?>"><?= h($c['name']) ?><span class="visually-hidden"> (client record)</span></a><br>
        <?= days_note((int)$c['days_in_stage'], in_array($stage, PIPELINE_WAITING_STAGES, true)) ?><?= $c['instructor'] ? ' · ' . h($c['instructor']) : '' ?>
      </li>
    <?php endforeach; ?>
    </ul>
  </section>
  <?php endforeach; ?>

  <section class="card" aria-labelledby="p-closed">
    <h2 id="p-closed">Closed in last 90 days (<?= count($closed) ?>)</h2>
    <?php if (!$closed): ?><p class="muted">None.</p><?php endif; ?>
    <ul class="pipeline-list">
    <?php foreach ($closed as $c): ?>
      <li><a href="client.php?id=<?= (int)$c['id'] ?>"><?= h($c['name']) ?><span class="visually-hidden"> (client record)</span></a> <?= status_badge($c['status']) ?></li>
    <?php endforeach; ?>
    </ul>
  </section>
</div>
<?php page_footer();
