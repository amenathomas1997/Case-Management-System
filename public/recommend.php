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
    echo '<div class="card"><h1>AT recommendations</h1><p class="muted">The AT recommendation engine is turned off. An administrator can enable it under AI assistant settings.</p><p><a href="client.php?id=' . $cid . '"><span aria-hidden="true">&larr; </span>Back to ' . h($client['name']) . '</a></p></div>';
    page_footer();
    exit;
}

// The context and prompt are shared with the apps' ai_recommend (inc/recommend.php, 4.37.0).

$recommendations = null;
// Only a string is accepted as posted context (an array never reaches the prompt or h()).
$postedContext = is_string($_POST['context'] ?? null) ? $_POST['context'] : null;
$context = $postedContext ?? recommend_context($client);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate'])) {
    $ctx = trim((string)$postedContext);
    if ($ctx === '') { set_form_errors(['context' => 'Add some context first.']); }
    else {
        $res = recommend_ai($client, $ctx, (int)$user['id']);
        audit($user['id'], 'ai.draft', 'client', $cid, 'AT recommendations');
        if ($res['ok']) { $recommendations = $res['text']; flash('Recommendations drafted — review, then create a device request for any you want to pursue.'); }
        else flash($res['error'], 'error');
    }
    $context = $postedContext ?? $context;
}

page_header('AT recommendations', $user, 'clients');
?>
<p><a href="client.php?id=<?= $cid ?>"><span aria-hidden="true">&larr; </span><?= h($client['name']) ?></a></p>
<h1>AI assistive-technology recommendations</h1>
<p class="muted">Draft suggestions from the AI (artificial intelligence) assistant, based on the client's disability, goals and job tasks. Staff review everything; nothing is ordered automatically.
   <?= ai_redaction_on() ? 'Direct identifiers are stripped before sending to the AI provider.' : '' ?></p>

<form method="post" action="recommend.php?client=<?= $cid ?>" class="stacked"><?= csrf_field() ?>
  <div class="field">
    <?= form_error_summary(['context' => 'Context sent to the AI']) ?>
    <label for="context">Context sent to the AI <span class="hint">edit to add or remove detail</span></label>
    <textarea id="context" name="context" rows="10" <?= field_aria('context') ?>><?= h($context) ?></textarea><?= field_error('context') ?>
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
    <p class="hint">Fields marked * are required.</p>
    <div class="grid grid-4">
      <div class="field"><label for="device_name">Device / technology <?= required_mark() ?></label><input type="text" id="device_name" name="device_name" required maxlength="190"></div>
      <div class="field"><label for="category">Category</label><select id="category" name="category">
        <?php // The managed list (Lists and time limits), as procurement.php and the apps use (4.37.0); only the
        // shown names spell out the abbreviations. ?>
        <?php foreach (cms_list_items('device_request_categories') as $cv): $cl = ['Magnifier / CCTV' => 'Video magnifier (CCTV, closed-circuit television)', 'AAC Device' => 'Communication device (AAC, augmentative and alternative communication)'][$cv] ?? $cv; ?>
        <option value="<?= h($cv) ?>"<?= $cv === 'Other' ? ' selected' : '' ?>><?= h($cl) ?></option>
        <?php endforeach; ?></select></div>
    </div>
    <div class="field"><label for="justification">Justification <span class="hint">paste the relevant recommendation and rationale (4,000 characters maximum)</span></label>
      <textarea id="justification" name="justification" rows="3" maxlength="4000"></textarea></div>
    <button type="submit" name="add_request" value="1" class="btn btn-secondary">Create device request</button>
    <?php // The draft is made by procurement.php through device_justification_ai() (inc/report_ai.php, 4.37.0), and
    // its device request form comes back filled in. ?>
    <?php if (ai_feature_enabled('draft')): ?><button type="submit" name="draft_justification" value="1" class="btn btn-secondary" formnovalidate>Draft justification with AI</button><?php endif; ?>
  </form>
</section>
<?php endif; ?>
<?php endif; ?>
<?php page_footer();
