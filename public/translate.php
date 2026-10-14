<?php
// On-demand translation: translate a document, report or message into another
// language. Content tool — the text is sent to the provider as pasted (no
// client-record redaction), logged as non-redacted.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
csrf_check();
$user = require_login();

if (!ai_feature_enabled('translate')) {
    page_header('Translate', $user, 'ai_tools');
    echo '<div class="card"><h1>Translate</h1><p class="muted">This AI tool is turned off. An administrator can enable it under AI assistant settings.</p></div>';
    page_footer();
    exit;
}

const TRANSLATE_LANGS = ['Spanish', 'Arabic', 'Chinese (Simplified)', 'Vietnamese', 'Russian', 'French', 'Haitian Creole', 'Somali', 'Nepali', 'Korean', 'Tagalog', 'Other (type below)'];

// Most pasted text accepted per request (it is sent to the AI provider). Every
// provider's reply is capped at 4,096 tokens (ai_generate), and a translation into
// a non-Latin script can take three or more tokens for each token of English, so
// longer text would come back cut off: it is refused and split by the user instead.
const TRANSLATE_MAX_CHARS = 6000;

$output = null;
$truncated = false;
$outLang = ''; $outDir = 'auto';
$content = is_string($_POST['content'] ?? null) ? $_POST['content'] : '';
// The language name becomes part of the AI instruction, so it is either one of
// the listed languages or a typed name limited to letters (with their accents
// and marks), spaces, hyphens, apostrophes, periods and parentheses — no line
// breaks or other punctuation.
$lang = in_array($_POST['lang'] ?? '', TRANSLATE_LANGS, true) ? $_POST['lang'] : 'Spanish';
$other = trim(is_string($_POST['other'] ?? null) ? $_POST['other'] : '');
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['go'])) {
    $target = $lang === 'Other (type below)' ? $other : $lang;
    $errs = [];
    if (trim($content) === '') $errs['content'] = 'Paste the text to translate.';
    elseif (($cLen = mb_strlen($content)) > TRANSLATE_MAX_CHARS) $errs['content'] = 'That is too much text to translate at once: shorten it to 6,000 characters or fewer (it has ' . number_format($cLen) . '), or split it into parts and translate each one.';
    if ($lang === 'Other (type below)') {
        if ($other === '') $errs['other'] = 'Type the language to translate into.';
        elseif (!preg_match('/^[\p{L}\p{M} ()\'’.-]{1,40}$/u', $other)) $errs['other'] = 'The language name can use only letters, spaces, hyphens, apostrophes, periods and parentheses (40 characters maximum).';
    }
    if ($errs) {
        set_form_errors($errs);
        flash(implode(' ', $errs), 'error');
    }
    elseif (!ai_rate_limit((int)$user['id'])) { flash('You have reached the AI usage limit for now. Please wait a few minutes.', 'error'); }
    else {
        // Translations can need more tokens than the source text (non-Latin
        // scripts especially), so the budget is three times the input's, within
        // the cap (which the longest accepted text reaches).
        $started = microtime(true);
        $res = ai_generate(
            ai_system_base() . ' You are a professional translator. Translate the user\'s content into ' . $target
                . '. Preserve meaning, tone and formatting; keep any [bracketed] placeholders unchanged; do not add commentary. Output only the translation.',
            $content, ai_output_tokens($content, 3.0));
        // Outcome, provider time and cut-off are logged as ai_run() logs them.
        ai_log_usage('translate', mb_strlen($content), $res['ok'] ? mb_strlen($res['text']) : 0, false, (int)$user['id'],
            (bool)$res['ok'], (int)round((microtime(true) - $started) * 1000), !empty($res['truncated']));
        if ($res['ok']) {
            $output = $res['text'];
            // Unknown typed languages get lang="" (language unknown) rather than inheriting English.
            $outLang = translate_lang_code($target);
            $outDir = in_array($outLang, TRANSLATE_RTL, true) ? 'rtl' : 'auto';
            $truncated = !empty($res['truncated']);
            if ($truncated) flash('The translation below is incomplete: the AI stopped before the end of your text. Split the text into smaller parts and translate each one.', 'error');
            else flash('Translated — review before use.');
        }
        else flash($res['error'], 'error');
    }
}

page_header('Translate', $user, 'ai_tools');
?>
<p><a href="ai_tools.php"><span aria-hidden="true">&larr; </span>AI tools</a></p>
<h1>On-demand translation</h1>
<p class="muted"><strong>The text you paste is sent to the AI provider as-is.</strong> Review machine translations before sending them to a client.</p>

<form method="post" action="translate.php" class="stacked"><?= csrf_field() ?>
  <div class="grid grid-2">
    <div class="field"><label for="lang">Translate into</label><select id="lang" name="lang"><?= select_options(TRANSLATE_LANGS, $lang) ?></select></div>
    <div class="field"><label for="other">Other language</label>
      <span class="hint" id="other-hint">Only when “Other” is chosen above. Letters, spaces, hyphens, apostrophes, periods and parentheses; up to 40 characters.</span>
      <input type="text" id="other" name="other" value="<?= h($other) ?>" maxlength="40" <?= field_aria('other', 'other-hint') ?>><?= field_error('other') ?></div>
  </div>
  <div class="field"><label for="content">Text to translate</label>
    <span class="hint" id="content-hint">Up to 6,000 characters (about two pages); split longer text into parts.</span>
    <textarea id="content" name="content" rows="10" <?= field_aria('content', 'content-hint') ?>><?= h($content) ?></textarea><?= field_error('content') ?></div>
  <button type="submit" name="go" value="1" class="btn btn-primary">Translate</button>
</form>

<?php if ($output !== null): ?>
<section class="card" aria-labelledby="out-h" style="margin-top:1rem">
  <h2 id="out-h">Translation<?= $truncated ? ' (incomplete)' : '' ?></h2>
  <?php if ($truncated): ?><p class="field-error"><strong>Incomplete:</strong> this stops before the end of your text. Split the text and translate each part.</p><?php endif; ?>
  <p style="white-space:pre-wrap" lang="<?= h($outLang) ?>" dir="<?= h($outDir) ?>"><?= h($output) ?></p>
</section>
<?php endif; ?>
<?php page_footer();
