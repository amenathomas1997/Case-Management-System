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
    $title = mb_substr($title, 0, 150);
    $html = plain_document_html($title, $body);
    if (isset($_POST['download'])) {
        audit($user['id'], 'letter.download', 'client', $cid, $title);
        header('Content-Type: text/html; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . (trim((string)preg_replace('/[^A-Za-z0-9-]+/', '-', strtolower($title)), '-') ?: 'letter') . '.html"');
        echo $html;
        exit;
    }
    if (isset($_POST['save_note'])) {
        db()->prepare("INSERT INTO client_notes (client_id, note_type, subject, body, author_id) VALUES (?, 'Case Note', ?, ?, ?)")->execute([$cid, mb_substr('Letter: ' . $title, 0, 255), $body, $user['id']]);
        audit($user['id'], 'letter.note', 'client', $cid, $title);
        flash('The letter was kept as a case note.');
        redirect('client.php?id=' . $cid . '&tab=notes');
    }
    $toPortal = !empty($_POST['to_portal']);
    try {
        $docId = client_document_save_html($cid, $toPortal ? 'Resource' : 'Other', $title . ', ' . date('F j, Y'), (trim((string)preg_replace('/[^A-Za-z0-9-]+/', '-', strtolower($title)), '-') ?: 'letter') . '.html', $html, (int)$user['id']);
    } catch (Throwable $e) {
        error_log('letters.php: letter not saved: ' . get_class($e));
        $docId = 0;
    }
    if (!$docId) {
        // The typed letter is kept, so nothing is lost.
        keep_input();
        flash('The letter could not be saved. Your wording is kept below; try again, or download it.', 'error');
        redirect('letters.php?client=' . $cid . '&letter=' . $key . '#draft-h');
    }
    web_once_set($letterKey, (int)$docId);
    audit($user['id'], 'letter.save', 'client', $cid, $title . ' (doc #' . $docId . ($toPortal ? ', in the portal' : '') . ')');
    flash($docId ? 'The letter was saved to the client\'s documents' . ($toPortal ? ', and the client can read it in their portal.' : '.') : 'The letter could not be saved.', $docId ? 'success' : 'error');
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
  <form method="post" action="letters.php" class="stacked"><?= csrf_field() ?><?= web_idem_field() ?>
    <?= form_error_summary(['body' => 'Letter']) ?>
    <input type="hidden" name="client" value="<?= $cid ?>"><input type="hidden" name="letter" value="<?= h($key) ?>">
    <div class="field"><label for="title">Title</label><input type="text" id="title" name="title" maxlength="150" value="<?= h(old_in('title', LETTER_TEMPLATES[$key][0])) ?>"></div>
    <div class="field"><label for="body">Letter</label><textarea id="body" name="body" rows="18" style="width:100%" <?= field_aria('body') ?>><?= h(has_field_error('body') ? old_in('body') : old_in('body', letter_render($key, $cid))) ?></textarea><?= field_error('body') ?></div>
    <div class="field"><label><input type="checkbox" name="to_portal" value="1"> Let the client read it in their portal</label></div>
    <p><button type="submit" name="save_doc" value="1" class="btn btn-primary">Save to the client's documents</button>
      <button type="submit" name="save_note" value="1" class="btn btn-secondary">Keep as a case note</button>
      <button type="submit" name="download" value="1" class="btn btn-secondary">Download to print or send</button></p>
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
