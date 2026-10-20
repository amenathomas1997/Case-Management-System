<?php
// Outcome survey responses (3.0.47): every survey answered in a period, by email link or
// entered by staff from a phone call, with the averages, and a CSV. Caseload-scoped.
require __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/report_period.php';
require __DIR__ . '/inc/layout.php';
$user = require_login();
require_perm('analytics');
$rs = report_table('surveys', (int)$user['id']);

$period = report_period($user, date('Y') . '-01-01', date('Y') . '-12-31');
$from = $period['from']; $to = $period['to'];
$channel = in_array($_GET['channel'] ?? '', ['Email', 'Phone'], true) ? $_GET['channel'] : '';

$q = db()->prepare("SELECT s.*, c.name AS client_name, c.preferred_language, COALESCE(u.name, '') AS instructor FROM {$rs} s JOIN clients c ON c.id = s.client_id
    LEFT JOIN users u ON u.id = c.assigned_to
    WHERE s.completed_at IS NOT NULL AND DATE(s.completed_at) BETWEEN ? AND ?" . ($channel !== '' ? ' AND s.channel = ?' : '') . ' ORDER BY s.completed_at DESC');
$q->execute($channel !== '' ? [$from, $to, $channel] : [$from, $to]);
$rows = $q->fetchAll();
$n = count($rows);
/** The language a survey was answered in (4.30.0): the language of the page the client answered
 *  on, kept since 4.30.0 (surveys.answer_lang); for an older emailed survey, the client's
 *  preferred language. A phone survey typed by staff without a recorded language is the page's. */
$answerLang = function (array $r): string {
    $kept = $r['answer_lang'] ?? null;
    if ($kept === 'es' || $kept === 'en') return $kept;
    return ($r['channel'] ?? 'Email') === 'Email' ? ((($r['preferred_language'] ?? '') === 'es') ? 'es' : 'en') : 'en';
};
$avg = fn(string $k) => $n ? round(array_sum(array_map(fn($r) => (int)$r[$k], $rows)) / $n, 1) : null;
$pending = db()->prepare("SELECT COUNT(*) FROM {$rs} s WHERE s.completed_at IS NULL AND DATE(s.sent_at) BETWEEN ? AND ?");
$pending->execute([$from, $to]);
$unanswered = (int)$pending->fetchColumn();

if (isset($_GET['export'])) {
    audit((int)$user['id'], 'report.export', 'client', null, 'Survey responses CSV ' . $from . ' to ' . $to);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="survey_responses_' . $from . '_' . $to . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    write_csv_row($out, ['Answered', 'Client', 'Instructor', 'How', 'Satisfaction (1 to 10)', 'Goals met (1 to 10)', 'Comments']);
    foreach ($rows as $r) write_csv_row($out, [substr((string)$r['completed_at'], 0, 10), csv_cell($r['client_name']), csv_cell($r['instructor']), $r['channel'] ?? 'Email',
        (int)$r['satisfaction'], (int)$r['goals_met'], csv_cell((string)$r['comments'])]);
    fclose($out);
    exit;
}

page_header('Survey responses', $user, 'analytics');
$filter = '<label for="channel">Answered by</label><select id="channel" name="channel">'
    . '<option value="">Email or phone</option><option value="Email"' . ($channel === 'Email' ? ' selected' : '') . '>Email</option>'
    . '<option value="Phone"' . ($channel === 'Phone' ? ' selected' : '') . '>Phone</option></select>';
$download = '<button type="submit" name="export" value="1" class="btn btn-secondary btn-sm">Download responses (CSV)<span class="visually-hidden"> for the period chosen above</span></button>';
?>
<h1>Survey responses</h1>
<p>Showing <?= h(fmt_date($from)) ?> to <?= h(fmt_date($to)) ?>.<?php foreach ($period['notes'] as $pn): ?> <?= h($pn) ?><?php endforeach; ?></p>
<?= report_period_form('survey_responses.php', $period, $filter, $download) ?>
<p><?= $n ?> response<?= $n === 1 ? '' : 's' ?><?= $n ? ': average satisfaction ' . $avg('satisfaction') . ' of 10, goals met ' . $avg('goals_met') . ' of 10' : '' ?>.
  <?= $unanswered ?> survey<?= $unanswered === 1 ? '' : 's' ?> sent in this period not answered yet.</p>
<?php if ($rows): ?>
<div class="table-scroll" tabindex="0" role="region" aria-labelledby="sr-cap">
<table>
  <caption class="visually-hidden" id="sr-cap">Outcome survey responses</caption>
  <thead><tr><th scope="col">Client</th><th scope="col">Answered</th><th scope="col">How</th><th scope="col" class="num">Satisfaction</th><th scope="col" class="num">Goals met</th><th scope="col">Comments</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr><th scope="row"><a href="client.php?id=<?= (int)$r['client_id'] ?>#after-h"><?= h($r['client_name']) ?></a></th>
      <td><?= fmt_date($r['completed_at']) ?></td><td><?= h($r['channel'] ?? 'Email') ?></td>
      <td class="num"><?= (int)$r['satisfaction'] ?></td><td class="num"><?= (int)$r['goals_met'] ?></td><td><?php $cm = (string)$r['comments']; echo $cm !== '' && $answerLang($r) === 'es' ? '<span lang="es">' . h($cm) . '</span>' : h($cm); ?></td></tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>
<?php page_footer();
