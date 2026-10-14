<?php
// Outcome risk flags: computes engagement/progress signals for active clients
// (days since last session/note, nearest authorization expiry, open goals and
// average progress) and has the AI prioritize which clients are at risk of not
// completing and why, with a suggested action. Operational, explainable — NOT
// clinical prediction. Client names are tokenized (never sent) and restored in
// the output. Governed by ai_run(); needs the 'analytics' permission.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
csrf_check();
$user = require_login();
require_perm('analytics');

if (!ai_feature_enabled('risk')) {
    page_header('Risk flags', $user, 'ai_tools');
    echo '<div class="card"><h1>Outcome risk flags</h1><p class="muted">This AI tool is turned off. An administrator can enable it under AI assistant settings.</p></div>';
    page_footer();
    exit;
}

$scoped = caseload_scoped();
$from = " FROM clients c WHERE c.status = 'Active Training'" . ($scoped ? ' AND c.assigned_to = ?' : '');
$params = $scoped ? [(int)$user['id']] : [];
$ct = db()->prepare('SELECT COUNT(*)' . $from);
$ct->execute($params);
$totalActive = (int)$ct->fetchColumn();
// At most 80 clients fit one assessment. When there are more, the ones with no
// session or the oldest last session (the likeliest to be at risk) are taken,
// rather than an alphabetical slice; the table then lists them by name. The last
// session is the last one held: a future-dated log (a session completed early)
// would otherwise make a disengaged client look recently seen.
$sql = "SELECT c.id, c.name,
        (SELECT MAX(w.work_date) FROM work_logs w JOIN training_tracks t ON t.id = w.track_id WHERE t.client_id = c.id AND w.work_date <= CURDATE()) AS last_session,
        (SELECT MAX(n.created_at) FROM client_notes n WHERE n.client_id = c.id) AS last_note,
        (SELECT MIN(t.expiration_date) FROM training_tracks t WHERE t.client_id = c.id AND t.status = 'Active' AND t.expiration_date IS NOT NULL) AS next_exp,
        (SELECT COUNT(*) FROM smart_goals sg JOIN training_tracks t ON t.id = sg.track_id WHERE t.client_id = c.id AND sg.status <> 'Achieved') AS open_goals,
        (SELECT ROUND(AVG(sg.progress_pct)) FROM smart_goals sg JOIN training_tracks t ON t.id = sg.track_id WHERE t.client_id = c.id AND sg.status <> 'Achieved') AS avg_progress"
    . $from . ' ORDER BY last_session, c.name LIMIT 80';
$st = db()->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll();
usort($rows, fn($x, $y) => strcasecmp((string)$x['name'], (string)$y['name']));
$capped = $totalActive > count($rows);

$flags = null;
$cutOff = false;   // the AI stopped at its length limit, so the list is incomplete
$today = new DateTime('today');
$daysSince = function (?string $d) use ($today): ?int {
    if (!$d) return null;
    return (int)$today->diff(new DateTime(substr($d, 0, 10)))->days;
};
// Authorization expiry as how far away it is ("in 21d" / "expired 12d ago"),
// relative like the other signals and as the app sends it; the date itself is
// not needed for triage, so it does not leave for the provider.
$expiry = function (?string $d) use ($today): string {
    if (!$d) return 'none';
    $days = (int)$today->diff(new DateTime(substr($d, 0, 10)))->format('%r%a');
    return $days >= 0 ? "in {$days}d" : 'expired ' . -$days . 'd ago';
};

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate'])) {
    if (!$rows) { flash('No active clients to assess.', 'error'); }
    else {
        $names = [];
        $ctx = '';
        foreach ($rows as $r) {
            $cid = (int)$r['id']; $names[$cid] = $r['name'];
            $ls = $daysSince($r['last_session']); $ln = $daysSince($r['last_note']);
            $ctx .= "C{$cid}: last session " . ($ls === null ? 'never' : "{$ls}d ago")
                . ", last note " . ($ln === null ? 'never' : "{$ln}d ago")
                . ", nearest auth expiry " . $expiry($r['next_exp'])
                . ", {$r['open_goals']} open goals"
                . ($r['avg_progress'] !== null ? " avg {$r['avg_progress']}% progress" : '') . "\n";
        }
        $res = ai_run('risk',
            ai_system_base() . ' You are flagging vocational-rehabilitation clients at operational risk of not completing, from the engagement '
                . 'and progress signals below. Risk indicators include: long gaps since the last session or note; an authorization expiring soon while '
                . 'open goals remain at low progress; and stalled progress. Prioritize the highest-risk clients first, refer to each by their token '
                . '(e.g. C12), give the specific reason, and suggest one concrete action. Use only the signals provided. This is operational triage, '
                . 'not a clinical or guaranteed prediction. Give a numbered list with one client per number: the token, the reason, then the action.'
                . ai_plain_text_rule(),
            "Today's date: " . $today->format('Y-m-d') . "\nActive-client signals:\n{$ctx}", 1500, null, (int)$user['id']);
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

<?php if ($capped): ?>
<p id="cap-note">Only <?= count($rows) ?> of the <?= $totalActive ?> clients in active training fit one assessment: those with no session yet or the longest time since their last session are included and listed below.</p>
<?php endif; ?>
<form method="post" action="risk.php"><?= csrf_field() ?>
  <button type="submit" name="generate" value="1" class="btn btn-primary"<?= $capped ? ' aria-describedby="cap-note"' : '' ?>>Assess <?= count($rows) ?><?= $capped ? ' of ' . $totalActive : '' ?> active client<?= ($capped ? $totalActive : count($rows)) === 1 ? '' : 's' ?></button>
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
    <thead><tr><th scope="col">Client</th><th scope="col">Days since last session</th><th scope="col">Days since last note</th><th scope="col">Authorization expires</th><th scope="col" class="num">Open goals</th><th scope="col" class="num">Average progress</th></tr></thead>
    <tbody>
    <?php if (!$rows): ?><tr><td colspan="6" class="muted">No active clients.</td></tr><?php endif; ?>
    <?php $days = fn(?int $n, string $never): string => $n === null ? $never : $n . ($n === 1 ? ' day' : ' days'); ?>
    <?php foreach ($rows as $r): $ls = $daysSince($r['last_session']); $ln = $daysSince($r['last_note']); ?>
      <tr>
        <td><a href="client.php?id=<?= (int)$r['id'] ?>"><?= h($r['name']) ?></a></td>
        <td><?= $days($ls, 'No session yet') ?></td>
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
