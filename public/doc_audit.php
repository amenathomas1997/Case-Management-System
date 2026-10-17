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
// Only notes staff wrote count as documentation. System-written notes are left out:
// those with no author (cron monthly summaries, filed emails, client portal practice,
// guardian consent, survey and VRC acknowledgment) and the automatic notes logged by
// staff actions (report and evaluation sends, record merges, referral intake, device
// follow-ups). They would otherwise hide stale documentation, and raise "not in a
// structured format" findings no one can fix. Subjects as written in report.php,
// api.php send_report, eval_report.php, client_merge.php, referrals.php / api.php
// and followups.php / api.php.
$staffNote = "n.author_id IS NOT NULL
        AND n.subject NOT IN ('Training report emailed to VRC', 'AT evaluation report emailed', 'Duplicate record merged')
        AND n.subject NOT LIKE '[Referral] %' AND n.subject NOT LIKE 'Device no longer in use: %'";
$sql = "SELECT c.id, c.name,
        (SELECT COUNT(*) FROM client_notes n WHERE n.client_id = c.id AND {$staffNote} AND n.note_type IN ('Case Note','Progress Report') AND n.created_at > DATE_SUB(NOW(), INTERVAL " . cms_number('doc_audit_note_days') . " DAY)) AS notes_recent,
        (SELECT COUNT(*) FROM client_notes n WHERE n.client_id = c.id AND {$staffNote} AND n.note_type IN ('Case Note','Progress Report') AND n.created_at > DATE_SUB(NOW(), INTERVAL " . cms_number('doc_audit_note_days') . " DAY) AND n.note_format <> 'free') AS notes_birp,
        (SELECT MAX(n.created_at) FROM client_notes n WHERE n.client_id = c.id AND {$staffNote}) AS last_note,
        (SELECT COUNT(*) FROM work_logs w JOIN training_tracks t ON t.id = w.track_id WHERE t.client_id = c.id AND w.work_date > DATE_SUB(CURDATE(), INTERVAL " . cms_number('doc_audit_activity_days') . " DAY)) AS sessions_recent,
        (SELECT COUNT(*) FROM homework h JOIN training_tracks t ON t.id = h.track_id WHERE t.client_id = c.id) AS homework_total,
        (SELECT COUNT(*) FROM tech_assessments a WHERE a.client_id = c.id) AS assessments,
        (SELECT COUNT(*) FROM smart_goals sg JOIN training_tracks t ON t.id = sg.track_id WHERE t.client_id = c.id AND sg.status <> 'Achieved'
            AND t.status = 'Active' AND sg.created_at < DATE_SUB(NOW(), INTERVAL " . cms_number('doc_audit_activity_days') . " DAY)
            AND NOT EXISTS (SELECT 1 FROM goal_progress gp WHERE gp.goal_id = sg.id AND gp.created_at > DATE_SUB(NOW(), INTERVAL " . cms_number('doc_audit_activity_days') . " DAY))) AS goals_stale
    FROM clients c WHERE c.status = 'Active Training'" . ($scoped ? ' AND c.assigned_to = ?' : '') . ' ORDER BY c.name';
$st = db()->prepare($sql);
$st->execute($scoped ? [(int)$user['id']] : []);
$clients = $st->fetchAll();

$today = new DateTime('today');
$findingsFor = function (array $c) use ($today): array {
    $f = [];
    $nonBirp = (int)$c['notes_recent'] - (int)$c['notes_birp'];
    if ($nonBirp > 0) $f[] = $nonBirp . ' recent case note' . ($nonBirp === 1 ? '' : 's') . ' not in a structured format (BIRP/PAIP/SOAP)';
    if ((int)$c['sessions_recent'] > 0 && (int)$c['homework_total'] === 0) $f[] = 'Active training logged, but no homework assigned';
    if (empty($c['last_note'])) $f[] = 'No progress notes on file';
    elseif ((int)$today->diff(new DateTime(substr((string)$c['last_note'], 0, 10)))->days > cms_number('doc_audit_activity_days')) $f[] = 'No progress note in ' . cms_number('doc_audit_activity_days') . '+ days';
    if ((int)$c['assessments'] === 0) $f[] = 'No tech assessment on file';
    if ((int)$c['goals_stale'] > 0) $f[] = (int)$c['goals_stale'] . ' active goal' . ((int)$c['goals_stale'] === 1 ? '' : 's') . ' with no progress in ' . cms_number('doc_audit_activity_days') . ' days';
    return $f;
};

$withFindings = [];
$totalFindings = 0;
foreach ($clients as $c) { $f = $findingsFor($c); if ($f) { $withFindings[] = [$c, $f]; $totalFindings += count($f); } }

// Reached from Compliance, so that main-menu item is marked current.
page_header('Documentation audit', $user, 'compliance');
?>
<h1>Documentation audit</h1>
<p class="muted">Documentation gaps across active clients<?= $scoped ? ' on your caseload' : '' ?>. A prompt to tidy records, not a judgement.
  A structured case note uses one of the formats <abbr title="behavior, intervention, response, plan">BIRP</abbr> (<?= h(implode(', ', NOTE_FORMATS['BIRP'])) ?>), <abbr title="purpose, assessment, intervention, plan">PAIP</abbr> (<?= h(implode(', ', NOTE_FORMATS['PAIP'])) ?>) or <abbr title="subjective, objective, assessment, plan">SOAP</abbr> (<?= h(implode(', ', NOTE_FORMATS['SOAP'])) ?>).</p>

<div class="grid grid-4">
  <div class="card stat"><span class="stat-num"><?= count($clients) ?></span><span class="stat-label">Active clients</span></div>
  <div class="card stat"><span class="stat-num"><?= count($withFindings) ?></span><span class="stat-label">With gaps</span></div>
  <div class="card stat"><span class="stat-num"><?= $totalFindings ?></span><span class="stat-label">Total findings</span></div>
</div>

<?php if (!$withFindings): ?>
<p class="muted">No documentation gaps found. 👍</p>
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
    <li>Case notes (Case Note / Progress Report) in the last <?= cms_number('doc_audit_note_days') ?> days that aren't in a <strong>structured format</strong>: BIRP (<?= h(implode(', ', NOTE_FORMATS['BIRP'])) ?>), PAIP (<?= h(implode(', ', NOTE_FORMATS['PAIP'])) ?>) or SOAP (<?= h(implode(', ', NOTE_FORMATS['SOAP'])) ?>).</li>
    <li>Clients with a training session logged in the last <?= cms_number('doc_audit_activity_days') ?> days but <strong>no homework</strong> assigned.</li>
    <li>Active clients with <strong>no progress note</strong> in <?= cms_number('doc_audit_activity_days') ?>+ days.</li>
    <li>Active clients with <strong>no tech assessment</strong> on file.</li>
    <li>Goals on active tracks, open for over <?= cms_number('doc_audit_activity_days') ?> days, with <strong>no progress recorded</strong> in the last <?= cms_number('doc_audit_activity_days') ?> days.</li>
  </ul>
  <p class="muted">Only notes written by staff count. Notes the system adds by itself — monthly summaries, filed emails, client portal entries,
     consent, survey and acknowledgment records, and the automatic notes for sent reports, merges, referrals and device follow-ups — are ignored.</p>
</section>
<?php page_footer();
