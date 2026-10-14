<?php
// "Ask your caseload": a plain-language question answered over the user's own
// (caseload-scoped) clients, goals and recent notes. Privacy design: clients are
// sent to the AI as opaque tokens (C<id>), never by name; the answer's tokens
// are substituted back to real names locally, so the provider never learns who
// anyone is. Read-only — the AI never runs queries, it only summarizes the
// snapshot we assemble. Governed by ai_run().
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
csrf_check();
$user = require_login();
require_perm('clients');

if (!ai_feature_enabled('ask')) {
    page_header('Ask your caseload', $user, 'ai_tools');
    echo '<div class="card"><h1>Ask your caseload</h1><p class="muted">This AI tool is turned off. An administrator can enable it under AI assistant settings.</p></div>';
    page_footer();
    exit;
}

$question = trim(is_string($_POST['question'] ?? null) ? $_POST['question'] : '');
$answer = null;
$truncated = false;
$cutOff = false;   // the AI stopped at its length limit, so the answer is incomplete
$asking = $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ask']);
if ($asking && $question === '') {
    set_form_errors(['question' => 'Type a question to ask.']);
    flash('Type a question to ask.', 'error');
    $asking = false;
} elseif ($asking && ($qLen = mb_strlen($question)) > 2000) {
    // Same limit as the app's caseload question; refused, never cut short.
    set_form_errors(['question' => 'Shorten the question to 2,000 characters or fewer (it has ' . number_format($qLen) . ').']);
    flash('Your question is too long. Shorten it to 2,000 characters or fewer.', 'error');
    $asking = false;
}
if ($asking) {
    $scoped = caseload_scoped();
    $sql = "SELECT c.id, c.name, c.status, c.client_type,
            (SELECT a.primary_disability FROM tech_assessments a WHERE a.client_id = c.id ORDER BY a.assessment_date DESC LIMIT 1) AS disability,
            (SELECT MAX(n.created_at) FROM client_notes n WHERE n.client_id = c.id) AS last_note
        FROM clients c WHERE c.status NOT LIKE 'Closed%'" . ($scoped ? ' AND c.assigned_to = ?' : '') . ' ORDER BY c.name LIMIT 80';
    $st = db()->prepare($sql);
    $st->execute($scoped ? [(int)$user['id']] : []);
    $clients = $st->fetchAll();
    if (count($clients) >= 80) $truncated = true;

    // Build the token map up front so free-text fields (disability, goal text)
    // can have ANY caseload client's name swapped for their token before anything
    // is sent — names typed into those fields otherwise reach the provider.
    $names = [];
    foreach ($clients as $c) $names[(int)$c['id']] = (string)$c['name'];
    $nameMap = [];
    foreach ($names as $cid => $nm) {
        $tok = 'C' . $cid; $nm = trim($nm);
        if ($nm === '') continue;
        // Full name always (privacy), plus each component so a first-name-only mention
        // ("How is Maria doing?") is tokenized too. Components need >=3 chars so short
        // names don't garble unrelated words. First client wins a shared component.
        if (!isset($nameMap[$nm])) $nameMap[$nm] = $tok;
        foreach (preg_split('/\s+/', $nm) as $part) {
            $part = trim($part);
            if (mb_strlen($part) >= 3 && !isset($nameMap[$part])) $nameMap[$part] = $tok;
        }
    }
    uksort($nameMap, fn($a, $b) => mb_strlen($b) <=> mb_strlen($a)); // longest first
    $san = function (string $s) use ($nameMap): string {
        if ($nameMap) $s = str_ireplace(array_keys($nameMap), array_values($nameMap), $s);
        return ai_redact($s, null); // also strips emails / phones / SSNs
    };

    $ctx = '';
    foreach ($clients as $c) {
        $cid = (int)$c['id'];
        $line = "C{$cid}: status {$c['status']}, type {$c['client_type']}";
        if (!empty($c['disability'])) $line .= ", disability " . mb_strimwidth($san((string)$c['disability']), 0, 60, '…');
        if (!empty($c['last_note'])) $line .= ", last note " . substr((string)$c['last_note'], 0, 10);
        // Active goals (brief)
        $gq = db()->prepare("SELECT sg.specific_goal, sg.status, sg.progress_pct FROM smart_goals sg JOIN training_tracks t ON t.id = sg.track_id WHERE t.client_id = ? AND sg.status <> 'Achieved' LIMIT 5");
        $gq->execute([$cid]);
        foreach ($gq->fetchAll() as $g) $line .= "\n    goal [{$g['status']} {$g['progress_pct']}%]: " . mb_strimwidth($san((string)$g['specific_goal']), 0, 90, '…');
        $ctx .= $line . "\n";
        if (mb_strlen($ctx) > 12000) { $truncated = true; break; }
    }

    $safeQuestion = $san($question);
    // Snapshot dates are YYYY-MM-DD (redaction leaves that shape alone), and the
    // model needs today's date to answer "in the last month" questions.
    $res = ai_run('ask',
        ai_system_base() . ' You answer questions about a caseload from the data snapshot provided. Refer to clients only by their token '
            . '(e.g. C12) exactly as given — never invent names. Answer only from the snapshot; if the data does not contain the answer, say so. '
            . 'When listing clients, use their tokens, one client per line. Be concise.' . ai_plain_text_rule(),
        "Today's date: " . date('Y-m-d') . "\nQuestion: {$safeQuestion}\n\nCaseload snapshot:\n{$ctx}", 1200, null, (int)$user['id']);
    audit($user['id'], 'ai.ask', 'user', (int)$user['id'], 'caseload question (client names tokenized)');
    if ($res['ok']) {
        // Substitute tokens back to real names for display (provider never saw them).
        $answer = preg_replace_callback('/\bC(\d+)\b/', fn($m) => $names[(int)$m[1]] ?? $m[0], $res['text']);
        // Stopped at the length limit: a list may end part-way, with clients missing.
        $cutOff = !empty($res['truncated']);
        if ($cutOff) flash('The answer below is incomplete: the AI stopped before finishing it, so anything after the last line is missing. Narrow the question (for example to one goal or status) and ask again.', 'error');
        else flash('The answer is ready below, under the heading "Answer".');
    } else { flash($res['error'], 'error'); }
}

page_header('Ask your caseload', $user, 'ai_tools');
?>
<p><a href="ai_tools.php">&larr; AI tools</a></p>
<h1>Ask your caseload</h1>
<p class="muted">Ask a plain-language question about your clients, goals and notes. Client names are never sent to the AI — they're tokenized and restored in the answer. Read-only.</p>

<form method="post" action="ask.php" class="stacked"><?= csrf_field() ?>
  <div class="field">
    <label for="question">Your question</label>
    <span class="hint" id="question-hint">Up to 2,000 characters.</span>
    <textarea id="question" name="question" rows="3" placeholder="e.g. Which clients have goals under 25% with no note in the last month?" <?= field_aria('question', 'question-hint') ?>><?= h($question) ?></textarea><?= field_error('question') ?>
  </div>
  <button type="submit" name="ask" value="1" class="btn btn-primary">Ask</button>
</form>

<?php if ($answer !== null): ?>
<section class="card" aria-labelledby="ans-h" style="margin-top:1rem">
  <h2 id="ans-h">Answer<?= $cutOff ? ' (incomplete)' : '' ?></h2>
  <?php if ($cutOff): ?><p class="field-error"><strong>Incomplete:</strong> the AI stopped before finishing this answer, so clients after the last line are missing. Narrow the question and ask again.</p><?php endif; ?>
  <?= plain_text_html($answer) ?>
  <?php if ($truncated): ?><p class="hint">Note: only part of the caseload fit in one question — narrow the question for larger caseloads.</p><?php endif; ?>
  <p class="hint">AI can be wrong — verify against the records before acting.</p>
</section>
<?php endif; ?>
<?php page_footer();
