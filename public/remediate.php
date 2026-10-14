<?php
// Accessible content & remediation: transform staff-provided content into plain
// language, a simpler reading level, a summary, large-print formatting, or draft
// alt text for an image. Optionally targets a client's preferred format.
// Content is sent to the AI as pasted (this is a content tool, not a client
// record), so redaction is NOT applied here — the notice below says so.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
csrf_check();
$user = require_login();

if (!ai_feature_enabled('remediate')) {
    page_header('Remediation', $user, 'ai_tools');
    echo '<div class="card"><h1>Accessible content &amp; remediation</h1><p class="muted">This AI tool is turned off. An administrator can enable it under AI assistant settings.</p></div>';
    page_footer();
    exit;
}

const REMEDIATE_MODES = [
    'plain' => 'Plain language (6th–8th grade)',
    'simplify' => 'Simplify (shorter sentences)',
    'summary' => 'Summarize',
    'largeprint' => 'Format for large print / low vision',
    'alt' => 'Alt text for an image (describe it below)',
];

// Most pasted text accepted per request (it is sent to the AI provider).
const REMEDIATE_MAX_CHARS = 20000;

// Optional client target — honor their preferred format. The format is part of
// the client record, so it needs the same gate as every other client page:
// the clients permission plus the caseload scope check.
$targetFormat = '';
$targetClientId = is_scalar($_REQUEST['client'] ?? null) ? (int)$_REQUEST['client'] : 0;
if ($targetClientId > 0) {
    require_perm('clients');
    $c = db()->prepare('SELECT assigned_to, a11y_format FROM clients WHERE id = ?');
    $c->execute([$targetClientId]);
    if ($row = $c->fetch()) {
        scope_check_client($row, $user);
        $targetFormat = (string)($row['a11y_format'] ?? '');
    }
}

$output = null;
$truncated = false;
$mode = in_array($_POST['mode'] ?? '', array_keys(REMEDIATE_MODES), true) ? $_POST['mode'] : 'plain';
$content = is_string($_POST['content'] ?? null) ? $_POST['content'] : '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['go'])) {
    if (trim($content) === '') { set_form_errors(['content' => 'Paste some content first.']); flash('Paste some content first.', 'error'); }
    elseif (($cLen = mb_strlen($content)) > REMEDIATE_MAX_CHARS) {
        set_form_errors(['content' => 'Shorten the content to 20,000 characters or fewer (it has ' . number_format($cLen) . '), or split it into smaller parts.']);
        flash('That is too much text to send at once (20,000 characters maximum). Split it into smaller parts.', 'error');
    }
    else {
        $prompts = [
            'plain' => 'Rewrite the following content in plain language at roughly a 6th–8th grade reading level. Preserve every fact and all meaning; do not add or remove information.',
            'simplify' => 'Rewrite the following content with shorter sentences and simpler words, keeping all facts and meaning.',
            'summary' => 'Summarize the following content clearly and faithfully, without adding information.',
            'largeprint' => 'Reformat the following content for large-print / low-vision reading: short lines, clear headings, and bulleted lists where helpful; no tables. Keep all of the content.',
            'alt' => 'Write concise, descriptive alt text (one sentence, under 125 characters) for an image described below. Output only the alt text.',
        ];
        $sys = ai_system_base() . ' ' . $prompts[$mode];
        if ($targetFormat !== '' && $mode !== 'alt') $sys .= ' The reader prefers this format: ' . $targetFormat . '.';
        // Content tool: send as pasted (no client-record redaction). Logged as non-redacted.
        // Over the limit, the page re-renders with the pasted text and client target kept.
        if (!ai_rate_limit((int)$user['id'])) { flash('You have reached the AI usage limit for now. Please wait a few minutes, then press Transform again.', 'error'); }
        else {
            // The output budget grows with the input, so a long document is not cut short.
            $started = microtime(true);
            $res = ai_generate($sys, $content, ai_output_tokens($content));
            // Outcome, provider time and cut-off are logged as ai_run() logs them.
            ai_log_usage('remediate', mb_strlen($content), $res['ok'] ? mb_strlen($res['text']) : 0, false, (int)$user['id'],
                (bool)$res['ok'], (int)round((microtime(true) - $started) * 1000), !empty($res['truncated']));
            if ($res['ok']) {
                $output = $res['text'];
                $truncated = !empty($res['truncated']);
                if ($truncated) flash('The result below is incomplete: the AI stopped before the end of your text. Split the text into smaller parts and transform each one.', 'error');
                else flash('Done — review before use.');
            }
            else flash($res['error'], 'error');
        }
    }
}

page_header('Remediation', $user, 'ai_tools');
?>
<p><a href="ai_tools.php">&larr; AI tools</a></p>
<h1>Accessible content &amp; remediation</h1>
<p class="muted">Transform content for accessibility. <strong>The text you paste is sent to the AI provider as-is</strong> — don't paste anything you wouldn't send them.
   <?= $targetFormat !== '' ? 'Targeting the client\'s preferred format: <strong>' . h($targetFormat) . '</strong>.' : '' ?></p>

<form method="post" action="remediate.php<?= $targetClientId > 0 ? '?client=' . $targetClientId : '' ?>" class="stacked"><?= csrf_field() ?>
  <div class="field"><label for="mode">What to do</label><select id="mode" name="mode"><?php foreach (REMEDIATE_MODES as $k => $lab): ?><option value="<?= h($k) ?>" <?= $mode === $k ? 'selected' : '' ?>><?= h($lab) ?></option><?php endforeach; ?></select></div>
  <div class="field"><label for="content">Content</label>
    <span class="hint" id="content-hint">Up to 20,000 characters. For alt text, describe the image.</span>
    <textarea id="content" name="content" rows="10" <?= field_aria('content', 'content-hint') ?>><?= h($content) ?></textarea><?= field_error('content') ?></div>
  <button type="submit" name="go" value="1" class="btn btn-primary">Transform</button>
</form>

<?php if ($output !== null): ?>
<section class="card" aria-labelledby="out-h" style="margin-top:1rem">
  <h2 id="out-h">Result<?= $truncated ? ' (incomplete)' : '' ?></h2>
  <?php if ($truncated): ?><p class="field-error"><strong>Incomplete:</strong> this stops before the end of your text. Split the text and transform each part.</p><?php endif; ?>
  <?= plain_text_html($output) ?>
</section>
<?php endif; ?>
<?php page_footer();
