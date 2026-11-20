<?php
// Letters (3.0.51): the organisation's own letter wording with merge fields, written for one
// client, checked and edited, then saved to their documents (or portal), kept as a note, or
// downloaded as an accessible HTML page. Administrators change each letter's wording here.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
csrf_check();
$user = require_login();
require_perm('clients');
$in = fn(string $k) => $_POST[$k] ?? $_GET[$k] ?? null;
$key = is_string($in('letter')) && isset(LETTER_TEMPLATES[$in('letter')]) ? $in('letter') : '';
$edit = can('admin') && is_string($_GET['edit'] ?? null) && isset(LETTER_TEMPLATES[$_GET['edit']]) ? $_GET['edit'] : '';
$cid = (int)($in('client') ?? 0);
$client = null;
if ($cid) {
    $c = db()->prepare('SELECT id, name, assigned_to FROM clients WHERE id = ?');
    $c->execute([$cid]);
    $client = $c->fetch() ?: null;
    if (!$client) { flash('Client not found.', 'error'); redirect('letters.php'); }
    scope_check_client($client, $user);
}

// An administrator changes a letter's wording (blank = the built-in wording).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_wording']) && can('admin')) {
    $wk = is_string($_POST['wording_key'] ?? null) && isset(LETTER_TEMPLATES[$_POST['wording_key']]) ? $_POST['wording_key'] : '';
    if ($wk === '') redirect('letters.php');
    $body = trim(str_replace("\r\n", "\n", (string)($_POST['wording'] ?? '')));
    if (mb_strlen($body) > 8000) { keep_input(); keep_field_errors(['wording' => 'Keep a letter to 8,000 characters or fewer.']); redirect('letters.php?edit=' . $wk . '#wording-h'); }
    set_setting('letter_' . $wk, $body);
    audit($user['id'], 'letter.wording', 'settings', null, $wk);
    flash($body === '' ? LETTER_TEMPLATES[$wk][0] . ' uses the built-in wording again.' : LETTER_TEMPLATES[$wk][0] . ' wording saved.');
    redirect('letters.php?edit=' . $wk . '#wording-h');
}

// "Draft the letter with AI" (4.37.0, inc/portal_history.php, shared with the apps' ai_letter). Nothing
// is saved: an unchanged letter is replaced by the draft; a letter that was changed is kept, and the
// draft goes in a separate box below it (as amendment_request.php).
$aiLetterAlt = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $client && $key !== '' && isset($_POST['ai_letter']) && ai_feature_enabled('draft')) {
    $typedLetter = trim(str_replace("\r\n", "\n", is_string($_POST['body'] ?? null) ? $_POST['body'] : ''));
    $edited = $typedLetter !== '' && $typedLetter !== trim(letter_render($key, $cid));
    $res = letter_ai_draft($cid, $key, (int)$user['id']);
    // Recorded only when the facts reached the provider (as eval_report.php).
    if (!empty($res['asked'])) audit($user['id'], 'ai.draft', 'client', $cid, 'letter: ' . LETTER_TEMPLATES[$key][0] . ($res['ok'] ? '' : ' (AI call failed; nothing drafted)'));
    $GLOBALS['OLD_INPUT'] = array_filter($_POST, 'is_string');
    if (!$res['ok']) flash($res['error'], 'error');
    elseif ($edited) {
        $aiLetterAlt = $res['text'];
        flash('Your letter is unchanged. The AI draft is in a separate box below it — review it, and use it only if you want it.' . (!empty($res['truncated']) ? ' Incomplete: the AI stopped before the end. Review it closely.' : ''));
    } else {
        $GLOBALS['OLD_INPUT']['body'] = $res['text'];
        flash('The AI draft is in the letter box below — review and edit it before saving.' . (!empty($res['truncated']) ? ' Incomplete: the AI stopped before the end. Review it closely.' : ''));
    }
}

// A letter written for a client: saved, kept as a note, or downloaded.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $client && $key !== '' && (isset($_POST['save_doc']) || isset($_POST['save_note']) || isset($_POST['download']))) {
    $title = trim((string)($_POST['title'] ?? '')) ?: LETTER_TEMPLATES[$key][0];
    $body = trim(str_replace("\r\n", "\n", (string)($_POST['body'] ?? '')));
    // Said on the field, with the title kept (4.28.0).
    if ($body === '') { keep_input(); keep_field_errors(['body' => 'The letter is empty. Write the letter, or open it again to start from the filled-in wording.']); redirect('letters.php?client=' . $cid . '&letter=' . $key . '#draft-h'); }
    // The same Save sent twice (4.28.0) files one document, and shares it once.
    $letterKey = web_idem_key($_POST);
    if (isset($_POST['save_doc']) && ($firstDoc = web_once_get($letterKey)) !== null) {
        flash('This letter was already saved to the client\'s documents (the form was sent twice), so it was saved once.', 'info');
        redirect('client.php?id=' . $cid . '&tab=documents');
    }
    $letterLang = ($_POST['letter_lang'] ?? '') === 'es' ? 'es' : 'en';
    if (isset($_POST['download'])) {
        $title = mb_substr($title, 0, 150);
        // The page is marked with the letter's language (4.30.0), so a letter written in Spanish is
        // read with a Spanish voice; the built-in wording is English.
        $html = plain_document_html($title, $body, $letterLang);
        audit($user['id'], 'letter.download', 'client', $cid, $title);
        header('Content-Type: text/html; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . letter_file_name($title) . '"');
        echo $html;
        exit;
    }
    // Saved or kept by the helper the apps' save_letter shares (inc/portal_history.php, 4.37.0).
    if (isset($_POST['save_note'])) {
        // Kept once when the form is sent twice (4.29.0), as Save to documents.
        $noteKey = $letterKey !== null ? $letterKey . '-NOTE' : null;
        if (web_once_get($noteKey) !== null) {
            flash('This letter was already kept as a case note (the form was sent twice), so it was kept once.', 'info');
            redirect('client.php?id=' . $cid . '&tab=notes');
        }
        $saved = letter_save($cid, 'note', $key, $title, $body, $letterLang, false, (int)$user['id']);
        web_once_set($noteKey, (int)$saved['id']);
        flash($saved['message']);
        redirect('client.php?id=' . $cid . '&tab=notes');
    }
    $toPortal = !empty($_POST['to_portal']);
    $saved = letter_save($cid, 'doc', $key, $title, $body, $letterLang, $toPortal, (int)$user['id']);
    if (!$saved['ok']) {
        // The typed letter is kept, so nothing is lost.
        keep_input();
        flash($saved['error'], 'error');
        redirect('letters.php?client=' . $cid . '&letter=' . $key . '#draft-h');
    }
    web_once_set($letterKey, (int)$saved['id']);
    flash($saved['message']);
    redirect('client.php?id=' . $cid . '&tab=documents');
}

page_header('Letters', $user, 'clients', $client ? [['client.php?id=' . $cid, $client['name']]] : []);
?>
<h1><?= $client ? 'Write a letter to or about ' . h($client['name']) : 'Letters' ?></h1>
<?php if ($client): ?>
<form method="get" action="letters.php" class="inline-form"><input type="hidden" name="client" value="<?= $cid ?>">
  <label for="letter">Letter</label>
  <select id="letter" name="letter"><?php foreach (LETTER_TEMPLATES as $k => [$name]): ?><option value="<?= h($k) ?>"<?= $k === $key ? ' selected' : '' ?>><?= h($name) ?></option><?php endforeach; ?></select>
  <button type="submit" class="btn btn-secondary btn-sm">Open the letter</button>
</form>
<?php if ($key !== ''): ?>
<section class="card" aria-labelledby="draft-h">
  <h2 id="draft-h"><?= h(LETTER_TEMPLATES[$key][0]) ?></h2>
  <p>Filled in from the record. Check it and change anything before saving; "[not on file]" marks details the record does not have.</p>
  <form method="post" action="letters.php" class="stacked" novalidate><?= csrf_field() ?><?= web_idem_field() ?>
    <?= form_error_summary(['body' => 'Letter']) ?>
    <input type="hidden" name="client" value="<?= $cid ?>"><input type="hidden" name="letter" value="<?= h($key) ?>">
    <div class="field"><label for="title">Title</label><input type="text" id="title" name="title" maxlength="150" value="<?= h(old_in('title', LETTER_TEMPLATES[$key][0])) ?>"></div>
    <?php // The letter's language (4.30.0): the built-in wording is English. For a client who prefers
    // Spanish, staff who write the letter in Spanish choose Spanish, and the saved letter is marked so.
    $prefEs = client_lang($cid) === 'es';
    $letterLang = old_in('letter_lang', 'en') === 'es' ? 'es' : 'en'; ?>
    <div class="field"><label for="letter_lang">Language of the letter <span class="hint"><?= $prefEs ? 'this client prefers Spanish; the wording below is in English, so choose Spanish if you write it in Spanish' : 'the wording below is in English' ?></span></label>
      <select id="letter_lang" name="letter_lang"><option value="en"<?= $letterLang === 'en' ? ' selected' : '' ?>>English</option><option value="es" lang="es"<?= $letterLang === 'es' ? ' selected' : '' ?>>Español</option></select></div>
    <div class="field"><label for="body">Letter <?= required_mark() ?></label><textarea id="body" name="body" rows="18" style="width:100%" required lang="<?= $letterLang ?>" <?= field_aria('body') ?>><?= h(has_field_error('body') ? old_in('body') : old_in('body', letter_render($key, $cid))) ?></textarea><?= field_error('body') ?></div>
    <script>(function(){var l=document.getElementById('letter_lang'),b=document.getElementById('body');if(l&&b)l.addEventListener('change',function(){b.lang=l.value;});})();</script>
    <div class="field"><label><input type="checkbox" name="to_portal" value="1"<?= old_in('to_portal') === '1' ? ' checked' : '' ?>> Let the client read it in their portal</label></div>
    <?php if ($aiLetterAlt !== null): // The letter above was changed, so it is kept; the AI draft is offered beside it (4.37.0). ?>
    <div class="field"><label for="ai-letter">AI draft <span class="hint">your letter above is unchanged; use this only if you want it</span></label>
      <textarea id="ai-letter" rows="12" style="width:100%"><?= h($aiLetterAlt) ?></textarea></div>
    <p><button type="button" class="btn btn-secondary btn-sm" id="use-ai-letter">Use the AI draft as the letter</button>
      <button type="button" class="btn btn-secondary btn-sm" id="restore-letter" hidden>Restore my letter</button>
      <span id="ai-letter-status" role="status" class="muted"></span></p>
    <script>(function () {
      var letter = document.getElementById('body'), ai = document.getElementById('ai-letter'),
          use = document.getElementById('use-ai-letter'), restore = document.getElementById('restore-letter'),
          s = document.getElementById('ai-letter-status'), mine = null;
      use.addEventListener('click', function () {
        if (mine === null || letter.value !== ai.value) mine = letter.value;
        letter.value = ai.value; restore.hidden = false;
        s.textContent = 'The AI draft is now the letter. Your own letter can be restored with "Restore my letter".';
        letter.focus();
      });
      restore.addEventListener('click', function () {
        if (mine !== null) letter.value = mine;
        restore.hidden = true; s.textContent = 'Your own letter is back in the letter box.';
        letter.focus();
      });
    })();</script>
    <?php endif; ?>
    <p><button type="submit" name="save_doc" value="1" class="btn btn-primary">Save to the client's documents</button>
      <button type="submit" name="save_note" value="1" class="btn btn-secondary">Keep as a case note</button>
      <button type="submit" name="download" value="1" class="btn btn-secondary">Download to print or send</button>
      <?php // Last, so Enter still saves. The draft replaces an unchanged letter, or comes in a box of its own (4.37.0). ?>
      <?php if (ai_feature_enabled('draft')): ?><button type="submit" name="ai_letter" value="1" class="btn btn-secondary" formnovalidate>Draft the letter with AI</button><?php endif; ?></p>
  </form>
</section>
<?php endif; ?>
<?php else: ?>
<p>Open a client's record and choose Write a letter. The letters below are filled in from that client's record.</p>
<?php endif; ?>
<?php if (can('admin')): ?>
<section class="card" aria-labelledby="wording-h">
  <h2 id="wording-h">Letter wording</h2>
  <ul>
    <?php foreach (LETTER_TEMPLATES as $k => [$name]): ?>
    <li><a href="letters.php?edit=<?= h($k) ?><?= $cid ? '&amp;client=' . $cid : '' ?>#wording-h"><?= h($name) ?></a><?= trim((string)setting('letter_' . $k)) !== '' ? ' (your own wording)' : '' ?></li>
    <?php endforeach; ?>
  </ul>
  <?php if ($edit !== ''): ?>
  <h3>Change the wording: <?= h(LETTER_TEMPLATES[$edit][0]) ?></h3>
  <p>Fields filled in from the record: <?= h(implode(' ', LETTER_FIELDS)) ?></p>
  <form method="post" action="letters.php" class="stacked"><?= csrf_field() ?>
    <?= form_error_summary(['wording' => 'Wording']) ?>
    <input type="hidden" name="wording_key" value="<?= h($edit) ?>">
    <div class="field"><label for="wording">Wording (leave it empty to use the built-in wording)</label>
      <textarea id="wording" name="wording" rows="16" style="width:100%" <?= field_aria('wording') ?>><?= h(has_field_error('wording') ? old_in('wording') : letter_template($edit)) ?></textarea><?= field_error('wording') ?></div>
    <button type="submit" name="save_wording" value="1" class="btn btn-primary">Save wording</button>
  </form>
  <?php endif; ?>
</section>
<?php endif; ?>
<?php page_footer();
