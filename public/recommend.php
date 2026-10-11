<?php
// AI assistive-technology recommendation engine. From a client's disability,
// functional limitations, goals and job tasks, the AI suggests specific AT with
// rationale; staff review and can push a pick into the procurement pipeline.
// Draft-only, governed by ai_run() (feature toggle + identifier redaction + logging).
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
csrf_check();
$user = require_login();
require_perm('clients');

$cid = (int)($_GET['client'] ?? 0);
$cstmt = db()->prepare('SELECT * FROM clients WHERE id = ?');
$cstmt->execute([$cid]);
$client = $cstmt->fetch();
if (!$client) { flash('Client not found.', 'error'); redirect('clients.php'); }
scope_check_client($client, $user);

if (!ai_feature_enabled('recommend')) {
    page_header('AT recommendations', $user, 'clients');
    echo '<div class="card"><h1>AT recommendations</h1><p class="muted">The AT recommendation engine is turned off. An administrator can enable it under AI assistant settings.</p><p><a href="client.php?id=' . $cid . '">&larr; Back to ' . h($client['name']) . '</a></p></div>';
    page_footer();
    exit;
}

// Assemble a default context from what the system knows (staff can edit before sending).
function recommend_context(array $client): string {
    $cid = (int)$client['id'];
    $ctx = '';
    $a = db()->prepare("SELECT primary_disability, primary_tech, hardware_details, software_version, summary FROM tech_assessments WHERE client_id = ? ORDER BY assessment_date DESC LIMIT 1");
    $a->execute([$cid]); $as = $a->fetch();
    $ctx .= 'Primary disability / functional limitations: ' . ($as['primary_disability'] ?? '[not recorded]') . "\n";
    if (!empty($as['summary'])) $ctx .= 'Assessment summary: ' . mb_strimwidth((string)$as['summary'], 0, 600, '…') . "\n";
    if (!empty($as['primary_tech'])) $ctx .= 'Technology already tried/used: ' . $as['primary_tech'] . "\n";
    if (!empty($client['a11y_primary_tech'])) $ctx .= 'Current assistive technology: ' . $client['a11y_primary_tech'] . "\n";
    if (!empty($client['a11y_screen_reader'])) $ctx .= "Uses a screen reader.\n";
    // Active goals
    $g = db()->prepare("SELECT sg.specific_goal FROM smart_goals sg JOIN training_tracks t ON t.id = sg.track_id WHERE t.client_id = ? AND sg.status <> 'Achieved' LIMIT 8");
    $g->execute([$cid]); $goals = $g->fetchAll(PDO::FETCH_COLUMN);
    if ($goals) $ctx .= "Goals:\n - " . implode("\n - ", $goals) . "\n";
    // Latest worksite job tasks, if any
    $w = db()->prepare('SELECT job_title, job_tasks FROM worksite_assessments WHERE client_id = ? ORDER BY updated_at DESC LIMIT 1');
    $w->execute([$cid]); $ws = $w->fetch();
    if ($ws) { if (!empty($ws['job_title'])) $ctx .= 'Job title: ' . $ws['job_title'] . "\n"; if (!empty($ws['job_tasks'])) $ctx .= 'Essential job tasks: ' . mb_strimwidth((string)$ws['job_tasks'], 0, 500, '…') . "\n"; }
    return trim($ctx);
}

$recommendations = null;
// Only a string is accepted as posted context (an array never reaches the prompt or h()).
$postedContext = is_string($_POST['context'] ?? null) ? $_POST['context'] : null;
$context = $postedContext ?? recommend_context($client);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate'])) {
    $ctx = trim((string)$postedContext);
    if ($ctx === '') { flash('Add some context first.', 'error'); }
    else {
        $res = ai_run('recommend',
            ai_system_base() . ' You are an assistive technology (AT) specialist supporting a vocational rehabilitation provider. '
                . 'From the disability, functional limitations, goals and any job tasks provided, recommend specific assistive technologies. '
                . 'For each recommendation give: the AT category and one or two concrete example products; a one-line rationale tied to the '
                . 'functional need; and whether a hands-on trial or formal evaluation is advisable. Order by priority. Be concrete and realistic, '
                . 'never invent facts about the person, and where key information is missing say what to assess. Output a short numbered list, '
                . 'one recommendation per number.' . ai_plain_text_rule(),
            "Recommend assistive technology for this client:\n\n" . $ctx, 1600, $client, (int)$user['id']);
        audit($user['id'], 'ai.draft', 'client', $cid, 'AT recommendations');
        if ($res['ok']) { $recommendations = $res['text']; flash('Recommendations drafted — review, then create a device request for any you want to pursue.'); }
        else flash($res['error'], 'error');
    }
    $context = $postedContext ?? $context;
}

page_header('AT recommendations', $user, 'clients');
?>
<p><a href="client.php?id=<?= $cid ?>">&larr; <?= h($client['name']) ?></a></p>
<h1>AI assistive-technology recommendations</h1>
<p class="muted">Draft suggestions from the AI (artificial intelligence) assistant, based on the client's disability, goals and job tasks. Staff review everything; nothing is ordered automatically.
   <?= ai_redaction_on() ? 'Direct identifiers are stripped before sending to the AI provider.' : '' ?></p>

<form method="post" action="recommend.php?client=<?= $cid ?>" class="stacked"><?= csrf_field() ?>
  <div class="field">
    <label for="context">Context sent to the AI <span class="hint">edit to add or remove detail</span></label>
    <textarea id="context" name="context" rows="10"><?= h($context) ?></textarea>
  </div>
  <button type="submit" name="generate" value="1" class="btn btn-primary">Generate recommendations</button>
</form>

<?php if ($recommendations !== null): ?>
<section class="card" aria-labelledby="rec-h" style="margin-top:1rem">
  <h2 id="rec-h">Recommendations (AI draft)</h2>
  <?= plain_text_html($recommendations) ?>
</section>

<?php if (can('procurement')): ?>
<section class="card" aria-labelledby="dr-h">
  <h2 id="dr-h">Create a device request</h2>
  <p class="muted">Turn a recommendation into a procurement request for <?= h($client['name']) ?>.</p>
  <form method="post" action="procurement.php" class="stacked"><?= csrf_field() ?>
    <input type="hidden" name="client_id" value="<?= $cid ?>">
    <div class="grid grid-4">
      <div class="field"><label for="device_name">Device / technology *</label><input type="text" id="device_name" name="device_name" required maxlength="190"></div>
      <div class="field"><label for="category">Category</label><select id="category" name="category">
        <?php // The values are the stored categories; only the shown names spell out the abbreviations. ?>
        <?php foreach (['Braille Display' => 'Braille Display', 'Screen Reader' => 'Screen Reader', 'Magnifier / CCTV' => 'Video magnifier (CCTV, closed-circuit television)',
            'AAC Device' => 'Communication device (AAC, augmentative and alternative communication)', 'Tablet / Phone' => 'Tablet / Phone', 'Computer' => 'Computer',
            'Hearing / Amplification' => 'Hearing / Amplification', 'Switch / Access' => 'Switch / Access', 'Software License' => 'Software License', 'Other' => 'Other'] as $cv => $cl): ?>
        <option value="<?= h($cv) ?>"<?= $cv === 'Other' ? ' selected' : '' ?>><?= h($cl) ?></option>
        <?php endforeach; ?></select></div>
    </div>
    <div class="field"><label for="justification">Justification <span class="hint">paste the relevant recommendation and rationale (4,000 characters maximum)</span></label>
      <textarea id="justification" name="justification" rows="3" maxlength="4000"></textarea></div>
    <button type="submit" name="add_request" value="1" class="btn btn-secondary">Create device request</button>
  </form>
</section>
<?php endif; ?>
<?php endif; ?>
<?php page_footer();
