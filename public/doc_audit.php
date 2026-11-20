<?php
// Documentation auditor: flags documentation gaps across the caseload — case
// notes not in BIRP format, active training logged without homework, stale
// progress notes, missing assessments, and active goals with no recent
// progress. Read-only; caseload-scoped.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
$user = require_login();
require_perm('clients');

$scoped = caseload_scoped();
// 4.38.0: the findings from doc_audit_findings() (inc/reports_extra.php), shared with the apps.
$audit = doc_audit_findings($scoped ? (int)$user['id'] : null);
$withFindings = array_map(fn($r) => [['id' => $r['client_id'], 'name' => $r['name']], $r['findings']], $audit['rows']);
$totalFindings = array_sum(array_map(fn($r) => count($r['findings']), $audit['rows']));

// Reached from Compliance, so that main-menu item is marked current.
page_header('Documentation audit', $user, 'compliance');
?>
<h1>Documentation audit</h1>
<p class="muted">Documentation gaps across active clients<?= $scoped ? ' on your caseload' : '' ?>. A prompt to tidy records, not a judgement.
  A structured case note uses one of the formats <abbr title="behavior, intervention, response, plan">BIRP</abbr> (<?= h(implode(', ', NOTE_FORMATS['BIRP'])) ?>), <abbr title="purpose, assessment, intervention, plan">PAIP</abbr> (<?= h(implode(', ', NOTE_FORMATS['PAIP'])) ?>) or <abbr title="subjective, objective, assessment, plan">SOAP</abbr> (<?= h(implode(', ', NOTE_FORMATS['SOAP'])) ?>).</p>

<div class="grid grid-4">
  <div class="card stat"><span class="stat-num"><?= (int)$audit['active'] ?></span><span class="stat-label">Active clients</span></div>
  <div class="card stat"><span class="stat-num"><?= count($withFindings) ?></span><span class="stat-label">With gaps</span></div>
  <div class="card stat"><span class="stat-num"><?= $totalFindings ?></span><span class="stat-label">Total findings</span></div>
</div>

<?php if (!$withFindings): ?>
<p class="muted"><?= h(DOC_AUDIT_EMPTY) ?></p>
<?php else: ?>
<div class="table-scroll" tabindex="0" role="region" aria-label="Documentation findings">
<table>
  <caption class="visually-hidden">Documentation findings by client</caption>
  <thead><tr><th scope="col">Client</th><th scope="col">Findings</th></tr></thead>
  <tbody>
  <?php foreach ($withFindings as [$c, $f]): ?>
    <tr>
      <th scope="row"><a href="client.php?id=<?= (int)$c['id'] ?>"><?= h($c['name']) ?></a></th>
      <td><ul style="margin:0;padding-left:1.1rem">
        <?php foreach ($f as $finding): ?><li><?= h($finding) ?></li><?php endforeach; ?>
      </ul></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>

<section class="card" aria-labelledby="rules-h" style="margin-top:1rem">
  <h2 id="rules-h">What this checks</h2>
  <ul>
    <?php foreach ($audit['checks'] as [$before, $strong, $after]): ?><li><?= h($before) ?><strong><?= h($strong) ?></strong><?= h($after) ?></li>
    <?php endforeach; ?>
  </ul>
  <p class="muted"><?= h($audit['footnote']) ?></p>
</section>
<?php page_footer();
