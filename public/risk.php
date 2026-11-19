<?php
// Outcome risk flags: computes engagement/progress signals for active clients
// (days since last session/note, nearest authorization expiry, open goals and
// average progress) and has the AI prioritize which clients are at risk of not
// completing and why, with a suggested action. Operational, explainable — NOT
// clinical prediction. Client names are tokenized (never sent) and restored in
// the output. Governed by ai_run(); needs the 'analytics' permission.
require __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/report_period.php';
require_once __DIR__ . '/inc/risk_ai.php';   // the rows, signals and prompt shared with the app (4.34.0)
require __DIR__ . '/inc/layout.php';
csrf_check();
$user = require_login();
require_perm('analytics');
// The shared period picker (remembered per user): the sessions held in the period are
// one more signal (none in the period is a gap, whatever the last date). This quarter
// when nothing is chosen.
$qStart = risk_quarter_start();
$period = report_period($user, $qStart, date('Y-m-d'));

if (!ai_feature_enabled('risk')) {
    page_header('Risk flags', $user, 'ai_tools');
    echo '<div class="card"><h1>Outcome risk flags</h1><p class="muted">This AI tool is turned off. An administrator can enable it under AI assistant settings.</p></div>';
    page_footer();
    exit;
}

$scoped = caseload_scoped();
// At most 80 clients fit one assessment: those with no session or the oldest last session are
// taken (inc/risk_ai.php); the table then lists them by name.
[$rows, $totalActive] = risk_ai_rows($scoped ? (int)$user['id'] : null, $period['from'], $period['to']);
usort($rows, fn($x, $y) => strcasecmp((string)$x['name'], (string)$y['name']));
$capped = $totalActive > count($rows);

$flags = null;
$cutOff = false;   // the AI stopped at its length limit, so the list is incomplete
$daysSince = fn(?string $d): ?int => risk_days_since($d);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate'])) {
    if (!$rows) { flash('No active clients to assess.', 'error'); }
    else {
        $names = [];
        $ctx = '';
        foreach ($rows as $r) {
            $names[(int)$r['id']] = $r['name'];
            $ctx .= risk_ai_line($r);
        }
        $res = ai_run('risk', risk_ai_system(), risk_ai_prompt($period['from'], $period['to'], $ctx), 1500, null, (int)$user['id']);
        audit($user['id'], 'ai.risk', 'user', (int)$user['id'], count($rows) . ' active clients assessed (client names tokenized)');
        if ($res['ok']) {
            $flags = preg_replace_callback('/\bC(\d+)\b/', fn($m) => $names[(int)$m[1]] ?? $m[0], $res['text']);
            // Stopped at the length limit: the last item may end part-way, and the
            // clients that would have followed were never assessed.
            $cutOff = !empty($res['truncated']);
            if ($cutOff) flash('The risk flags below are incomplete: the AI stopped before finishing the list, so the clients after the last item were not assessed. Check them in the Signals table.', 'error');
            else flash('Risk flags are ready below, under the heading "Risk flags (AI triage)".');
        }
        else flash($res['error'], 'error');
    }
}

page_header('Risk flags', $user, 'ai_tools');
?>
<p><a href="ai_tools.php"><span aria-hidden="true">&larr; </span>AI tools</a></p>
<h1>Outcome risk flags</h1>
<p class="muted">Operational triage of active clients from engagement and progress signals. Client names are tokenized before sending and restored in the result. This is a prompt to look closer, not a prediction.</p>

<?= report_period_form('risk.php', $period) ?>
<?php if ($period['notes']): ?><p><?= h(implode(' ', $period['notes'])) ?></p><?php endif; ?>
<p>Sessions in the period are counted from <?= h(fmt_date($period['from'])) ?> to <?= h(fmt_date($period['to'])) ?>; the other signals are as of today.</p>
<?php if ($capped): ?>
<p>Only <?= count($rows) ?> of the <?= $totalActive ?> clients in active training fit one assessment: those with no session yet or the longest time since their last session are included and listed below.</p>
<?php endif; ?>
<form method="post" action="risk.php"><?= csrf_field() ?>
  <button type="submit" name="generate" value="1" class="btn btn-primary">Assess <?= count($rows) ?><?= $capped ? ' of ' . $totalActive : '' ?> active client<?= ($capped ? $totalActive : count($rows)) === 1 ? '' : 's' ?></button>
</form>

<?php if ($flags !== null): ?>
<section class="card" aria-labelledby="fl-h" style="margin-top:1rem">
  <h2 id="fl-h">Risk flags (AI triage)<?= $cutOff ? ' (incomplete)' : '' ?></h2>
  <?php if ($cutOff): ?><p class="field-error"><strong>Incomplete:</strong> the AI stopped before finishing this list. The last item may be cut off, and clients after it were not assessed: check them in the Signals table below.</p><?php endif; ?>
  <?= plain_text_html($flags) ?>
  <p class="hint">Verify against each client's record before acting.</p>
</section>
<?php endif; ?>

<section class="card" aria-labelledby="sig-h">
  <h2 id="sig-h">Signals</h2>
  <div class="table-scroll" tabindex="0" role="region" aria-labelledby="sig-cap">
  <table>
    <caption class="visually-hidden" id="sig-cap">Engagement and progress signals per active client</caption>
    <thead><tr><th scope="col">Client</th><th scope="col">Days since last session</th><th scope="col" class="num">Sessions in period</th><th scope="col">Days since last note</th><th scope="col">Authorization expires</th><th scope="col" class="num">Open goals</th><th scope="col" class="num">Average progress</th></tr></thead>
    <tbody>
    <?php if (!$rows): ?><tr><td colspan="7" class="muted">No active clients.</td></tr><?php endif; ?>
    <?php $days = fn(?int $n, string $never): string => $n === null ? $never : $n . ($n === 1 ? ' day' : ' days'); ?>
    <?php foreach ($rows as $r): $ls = $daysSince($r['last_session']); $ln = $daysSince($r['last_note']); ?>
      <tr>
        <td><a href="client.php?id=<?= (int)$r['id'] ?>"><?= h($r['name']) ?></a></td>
        <td><?= $days($ls, 'No session yet') ?></td>
        <td class="num"><?= (int)$r['period_sessions'] ?></td>
        <td><?= $days($ln, 'No note yet') ?></td>
        <td><?= $r['next_exp'] ? fmt_date($r['next_exp']) : 'No expiry date' ?></td>
        <td class="num"><?= (int)$r['open_goals'] ?></td>
        <td class="num"><?= $r['avg_progress'] !== null ? (int)$r['avg_progress'] . '%' : 'No open goals' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</section>
<?php page_footer();
