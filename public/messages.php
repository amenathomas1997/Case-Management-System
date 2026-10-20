<?php
// Staff message center: secure two-way threads with clients (via their portal)
// and with VR counselors (via their portal). Caseload-scoped. Staff replies here
// appear on the other side's portal; inbound messages are marked read on open.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
csrf_check();
$user = require_login();
require_perm('clients');

$scoped = caseload_scoped();
$scopeAnd = $scoped ? ' AND c.assigned_to = ?' : '';
$scopeArgs = $scoped ? [(int)$user['id']] : [];

/** Guard: this client is on the user's caseload. */
function msg_client_or_deny(int $clientId, array $user): array {
    $q = db()->prepare('SELECT id, name, assigned_to, archived_at, counselor_email, counselor_verified FROM clients WHERE id = ?');
    $q->execute([$clientId]); $c = $q->fetch();
    if (!$c) { flash('Client not found.', 'error'); redirect('messages.php'); }
    if (caseload_scoped() && (int)$c['assigned_to'] !== (int)$user['id']) { flash('That client is not on your caseload.', 'error'); redirect('messages.php'); }
    return $c;
}

/* ---- POST: staff reply ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reply'])) {
    $cid = (int)($_POST['client_id'] ?? 0);
    $channel = in_array($_POST['channel'] ?? '', ['client', 'counselor'], true) ? $_POST['channel'] : 'client';
    // Line breaks counted as the browser counts them (one character each).
    $body = str_replace("\r\n", "\n", trim(is_string($_POST['body'] ?? null) ? $_POST['body'] : ''));
    $c = msg_client_or_deny($cid, $user);
    // A file with the reply (4.24.0): a new upload, or one of the client's documents; either is then shared
    // with whoever the conversation is with.
    $replyFile = is_array($_FILES['reply_file'] ?? null) && (int)($_FILES['reply_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE ? $_FILES['reply_file'] : null;
    $attachDoc = ctype_digit((string)($_POST['attach_doc'] ?? '')) && (int)$_POST['attach_doc'] > 0 ? client_document((int)$_POST['attach_doc'], $cid) : null;
    if (ctype_digit((string)($_POST['attach_doc'] ?? '')) && (int)$_POST['attach_doc'] > 0 && !$attachDoc) { keep_input(); keep_field_errors(['attach_doc' => 'That document is no longer on the client\'s record.']); redirect('messages.php?client=' . $cid . '&channel=' . $channel); }
    if ($replyFile && $attachDoc) { keep_input(); keep_field_errors(['reply_file' => 'Attach one file: upload a new one or choose one of the client\'s documents, not both.']); redirect('messages.php?client=' . $cid . '&channel=' . $channel); }
    $hasFile = $replyFile !== null || $attachDoc !== null;
    if ($body === '' && !$hasFile) { keep_input(); keep_field_errors(['body' => 'Type a message first.']); redirect('messages.php?client=' . $cid . '&channel=' . $channel); }
    // Refused, never cut: the thread keeps at most 5,000 characters a message.
    if (mb_strlen($body) > 5000) {
        keep_input();
        keep_field_errors(['body' => 'Shorten the message to 5,000 characters or fewer, or send it in two parts.']);
        redirect('messages.php?client=' . $cid . '&channel=' . $channel);
    }
    // Neither portal shows a retention-archived client, so a reply could never be read.
    if ($c['archived_at'] !== null) {
        keep_input();
        flash('Not sent: ' . $c['name'] . '\'s record is archived, so neither the client portal nor the counselor portal shows it any more. Reach them another way.', 'error');
        redirect('messages.php?client=' . $cid . '&channel=' . $channel);
    }
    // The same message sent twice (Enter pressed again while it loads) is posted once.
    $msgKey = web_idem_key($_POST);
    if (web_once_get($msgKey) !== null) {
        flash('This message was already sent (the form was sent twice), so it was sent once.', 'info');
        redirect('messages.php?client=' . $cid . '&channel=' . $channel);
    }
    $docId = $attachDoc ? (int)$attachDoc['id'] : null;
    if ($replyFile) {
        $saved = exchange_save_upload($replyFile, $cid, '', 'staff', (int)$user['id'], 'Other');
        if ($saved['error'] !== null) { keep_input(); keep_field_errors(['reply_file' => $saved['error'] . ' Choose the file again.']); redirect('messages.php?client=' . $cid . '&channel=' . $channel); }
        $docId = (int)$saved['id'];
    }
    $newMsg = post_message($cid, $channel, 'staff', (int)$user['id'], $body !== '' ? $body : 'Sent a file.');
    web_once_set($msgKey, $newMsg);
    if ($docId) {
        message_attach($newMsg, $docId);
        document_set_shared($docId, $cid, $channel === 'client' ? 'portal' : 'counselor', true, (int)$user['id']);
    }
    audit($user['id'], 'message.send', 'client', $cid, $channel . ($docId ? ', with document #' . $docId : ''));
    // Also as a text (4.17.0), when asked and the client agreed to texts.
    if ($channel === 'client' && !empty($_POST['by_text'])) {
        // A file alone is said as such: the text points to the portal, where the file is.
        $t = sms_to_client($cid, (brand('short') ?: cfg('name')) . ': ' . ($body !== '' ? $body : (client_lang($cid) === 'es' ? 'Le enviamos un archivo. Ábralo en su portal.' : 'We sent you a file. Open your portal to see it.')), 'message', null, (int)$user['id']);
        if ($t['ok']) db()->prepare("UPDATE messages SET via = 'text' WHERE id = ?")->execute([$newMsg]);
        flash($t['ok'] ? ($t['status'] === 'held' ? 'Text: ' . $t['message'] : 'Also sent as a text message.') : 'Not sent as a text: ' . $t['message'], $t['ok'] ? 'success' : 'error');
    }
    // A short email with the portal link (no message text), at most once a day (3.0.40).
    $noticed = message_notice($cid, $channel);
    // Only promise delivery when the other side has a live portal link to read it on.
    // The counselor portal lists only clients whose counselor email staff have
    // confirmed (counselor_verified = 1), so the message shows there only then.
    if ($channel === 'client') {
        $link = db()->prepare('SELECT 1 FROM portal_tokens WHERE client_id = ? AND (expires_at IS NULL OR expires_at > NOW())');
    } else {
        $link = db()->prepare('SELECT 1 FROM vrc_tokens v JOIN clients c ON c.counselor_email = v.counselor_email
            WHERE c.id = ? AND c.counselor_verified = 1 AND (v.expires_at IS NULL OR v.expires_at > NOW())');
    }
    $link->execute([$cid]);
    if ($link->fetchColumn()) {
        flash('Message sent — it will appear on the ' . ($channel === 'client' ? "client's" : "counselor's") . ' portal.'
            . ($noticed ? ' They were emailed that a message is waiting.' : ''));
    } elseif ($channel === 'counselor' && trim((string)$c['counselor_email']) !== '' && (int)$c['counselor_verified'] === 0) {
        flash('Message saved, but ' . $c['name'] . '\'s counselor email has not been confirmed, so the counselor portal does not show this client yet. Confirm the counselor on the client\'s edit page.', 'error');
    } elseif ($channel === 'client') {
        flash('Message saved, but this client has no active portal link, so they cannot read it yet. Send them a portal link from their client record.', 'error');
    } else {
        flash('Message saved, but this client\'s counselor has no active portal link, so they cannot read it yet. An administrator can send one from the counselor portal page.', 'error');
    }
    redirect('messages.php?client=' . $cid . '&channel=' . $channel);
}

$openCid = (int)($_GET['client'] ?? 0);
// Open conversation with no client chosen (4.30.0): the list is aria-required, not required (Chrome
// calls a required list invalid before anything is sent), so the page says what is missing.
if (isset($_GET['client']) && $openCid === 0) set_form_errors(form_errors() + ['open_client' => 'Choose a client, then choose Open conversation.']);
$openChannel = in_array($_GET['channel'] ?? '', ['client', 'counselor'], true) ? $_GET['channel'] : 'client';

/* ---- Thread view ---- */
if ($openCid) {
    $c = msg_client_or_deny($openCid, $user);
    // Mark inbound (non-staff) messages in this thread as read.
    db()->prepare("UPDATE messages SET read_at = NOW() WHERE client_id = ? AND channel = ? AND sender <> 'staff' AND read_at IS NULL")
        ->execute([$openCid, $openChannel]);
    $msgs = db()->prepare('SELECT m.*, u.name AS staff_name, d.title AS doc_title FROM messages m LEFT JOIN users u ON u.id = m.staff_id LEFT JOIN client_documents d ON d.id = m.document_id AND d.client_id = m.client_id WHERE m.client_id = ? AND m.channel = ? ORDER BY m.created_at');
    $docChoices = db()->prepare('SELECT id, title, created_at FROM client_documents WHERE client_id = ? ORDER BY created_at DESC LIMIT 100');
    $docChoices->execute([$openCid]);
    $docChoices = $docChoices->fetchAll();
    $msgs->execute([$openCid, $openChannel]);
    $msgs = $msgs->fetchAll();

    // The title names the client and the conversation, so tabs and windows for
    // different threads can be told apart; a refused reply is marked as an error.
    $chanWho = $openChannel === 'client' ? 'client' : 'VR counselor';
    page_header((form_errors() ? 'Error — ' : '') . 'Messages: ' . $c['name'] . ' (' . $chanWho . ' conversation)', $user, 'messages');
    ?>
    <p><a href="messages.php"><span aria-hidden="true">&larr; </span>All conversations</a> <span aria-hidden="true">·</span> <a href="client.php?id=<?= $openCid ?>"><?= h($c['name']) ?></a></p>
    <h1>Messages<span aria-hidden="true"> — </span><span class="visually-hidden">: </span><?= h($c['name']) ?></h1>
    <?php // The open channel is shown by more than the button fill (which the vision
    // palettes flatten): a check mark and bold text, plus the visible heading below. ?>
    <div class="page-actions" role="group" aria-label="Conversation channel">
      <?php foreach (['client' => 'Client', 'counselor' => 'Counselor'] as $chKey => $chLabel): $isOpen = $openChannel === $chKey; ?>
      <a class="btn btn-<?= $isOpen ? 'primary' : 'secondary' ?> btn-sm" href="messages.php?client=<?= $openCid ?>&amp;channel=<?= $chKey ?>"<?= $isOpen ? ' aria-current="true" style="font-weight:800"' : '' ?>><?= $isOpen ? '<span aria-hidden="true">&#10003; </span>' : '' ?><?= $chLabel ?></a>
      <?php endforeach; ?>
    </div>
    <section class="card">
      <h2 id="thread-h">Conversation with the <?= $chanWho ?></h2>
      <?php if (!$msgs): ?><p class="muted">No messages yet. Start the conversation below.</p><?php else: ?>
      <?php /* A focusable, labelled scroll region (keyboard users can scroll it) that
               opens at the newest message. Each message names its sender first for
               screen readers; role="list" keeps the list semantics list-style:none removes in Safari. */ ?>
      <div class="msg-thread" id="msg-thread" tabindex="0" role="region" aria-labelledby="thread-h">
        <ol class="msg-list" role="list">
        <?php foreach ($msgs as $m): $who = $m['sender'] === 'staff' ? ($m['staff_name'] ?? 'Staff') : ucfirst($m['sender']); ?>
          <li class="msg <?= $m['sender'] === 'staff' ? 'out' : 'in' ?>">
            <span class="visually-hidden"><?= h($who) ?>:</span>
            <div class="msg-body"><?= nl2br(h($m['body'])) ?><?php if (!empty($m['doc_title'])): ?><br>File: <a href="download.php?doc=<?= (int)$m['document_id'] ?>"<?= $m['sender'] === 'staff' ? ' style="color:#fff"' : '' ?>><?= h((string)$m['doc_title']) ?></a><?php endif; ?></div>
            <div class="msg-meta"><span aria-hidden="true"><?= h($who) ?> · </span><?= fmt_datetime($m['created_at']) ?><?= ($m['via'] ?? 'portal') === 'text' ? ', by text' : '' ?></div>
          </li>
        <?php endforeach; ?>
        </ol>
      </div>
      <script>(function () { var t = document.getElementById('msg-thread'); if (t) t.scrollTop = t.scrollHeight; })();</script>
      <?php endif; ?>
      <?php // The field and button name who the reply goes to. No maxlength: a browser
      // would cut a long paste silently; the server refuses it with the text kept. ?>
      <form method="post" action="messages.php" class="stacked" enctype="multipart/form-data"><?= csrf_field() ?><?= web_idem_field() ?>
        <?= form_error_summary(['body' => 'Reply to the ' . $chanWho, 'reply_file' => 'Attach a new file', 'attach_doc' => 'Or attach one of their documents']) ?>
        <input type="hidden" name="client_id" value="<?= $openCid ?>"><input type="hidden" name="channel" value="<?= h($openChannel) ?>">
        <?php // Said before the reply is typed: the counselor portal does not show a client
        // whose counselor email is still unconfirmed, so the counselor would not see it. ?>
        <?php $unconfirmed = $openChannel === 'counselor' && trim((string)$c['counselor_email']) !== '' && (int)$c['counselor_verified'] === 0; ?>
        <?php if ($unconfirmed): ?>
        <p class="alert alert-info"><strong>Not yet visible to the counselor:</strong> <?= h($c['name']) ?>'s counselor email has not been confirmed, so the counselor portal does not show this client. Replies are saved here and appear there once the counselor is confirmed on <a href="client_edit.php?id=<?= $openCid ?>">the client's edit page</a>.</p>
        <?php endif; ?>
        <label for="body">Reply to the <?= $chanWho ?> <span class="hint">Up to 5,000 characters, or leave it empty to send just a file. It appears on the <?= $openChannel === 'client' ? "client's" : "counselor's" ?> portal.</span></label>
        <textarea id="body" name="body" rows="3" <?= field_aria('body') ?>><?= h(old_in('body')) ?></textarea><?= field_error('body') ?>
        <?php if ($openChannel === 'client' && sms_enabled()): $smsWhy = sms_client_blocked(sms_client($openCid)); // 4.17.0 ?>
        <?php if ($smsWhy === null): ?><div class="field"><label><input type="checkbox" name="by_text" value="1"<?= old_in('by_text') === '1' ? ' checked' : '' ?>> Also send it as a text message <span class="hint">up to 1,600 characters; sent from 8 a.m. to 8 p.m. their time</span></label></div>
        <?php else: ?><p class="muted">Not by text: <?= h($smsWhy) ?></p><?php endif; ?>
        <?php endif; ?>
        <div class="field"><label for="reply_file">Attach a new file (optional; it is shared with the <?= $chanWho ?>)</label>
          <input type="file" id="reply_file" name="reply_file" accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.rtf,.csv,.png,.jpg,.jpeg" <?= field_aria('reply_file') ?>><?= field_error('reply_file') ?></div>
        <?php if ($docChoices): ?>
        <div class="field"><label for="attach_doc">Or attach one of their documents (it is shared with the <?= $chanWho ?>)</label>
          <select id="attach_doc" name="attach_doc" <?= field_aria('attach_doc') ?>><option value="">None</option><?php foreach ($docChoices as $dc): ?><option value="<?= (int)$dc['id'] ?>"<?= old_in('attach_doc') === (string)$dc['id'] ? ' selected' : '' ?>><?= h($dc['title'] . ', ' . fmt_date($dc['created_at'])) ?></option><?php endforeach; ?></select><?= field_error('attach_doc') ?></div>
        <?php endif; ?>
        <button type="submit" name="reply" value="1" class="btn btn-primary">Send to the <?= $chanWho ?></button>
      </form>
    </section>
    <style>
    .msg-thread{margin-bottom:.8rem;max-height:60vh;overflow-y:auto}
    .msg-list{display:flex;flex-direction:column;gap:.5rem;margin:0;padding:0;list-style:none}
    .msg{max-width:80%;padding:.5rem .7rem;border-radius:var(--radius)}
    .msg-body{max-width:70ch}
    .msg.in{align-self:flex-start;background:var(--bg);border:1px solid var(--line)}
    /* A fixed navy, not the configurable brand colour: white text 10.4:1 and the
       time line 8.8:1 (AAA) whatever brand an administrator picks. */
    .msg.out{align-self:flex-end;background:#1e3a8a;color:#fff}
    .msg-meta{font-size:.75rem;color:var(--ink-soft);margin-top:.25rem}
    .msg.out .msg-meta{color:#e5ecff}
    </style>
    <?php
    page_footer();
    exit;
}

/* ---- Conversation list ---- */
$sql = "SELECT m.client_id, m.channel, c.name AS client_name,
        MAX(m.created_at) AS last_at,
        SUM(m.sender <> 'staff' AND m.read_at IS NULL) AS unread,
        SUBSTRING_INDEX(MAX(CONCAT(m.created_at, 0x1f, m.sender, 0x1f, COALESCE(m.staff_id, 0), 0x1f, m.body)), 0x1f, -1) AS last_body,
        SUBSTRING_INDEX(SUBSTRING_INDEX(MAX(CONCAT(m.created_at, 0x1f, m.sender, 0x1f, COALESCE(m.staff_id, 0), 0x1f, m.body)), 0x1f, 2), 0x1f, -1) AS last_sender,
        SUBSTRING_INDEX(SUBSTRING_INDEX(MAX(CONCAT(m.created_at, 0x1f, m.sender, 0x1f, COALESCE(m.staff_id, 0), 0x1f, m.body)), 0x1f, 3), 0x1f, -1) AS last_staff_id
    FROM messages m JOIN clients c ON c.id = m.client_id
    WHERE 1=1$scopeAnd
    GROUP BY m.client_id, m.channel, c.name
    ORDER BY unread DESC, last_at DESC LIMIT 200";
$st = db()->prepare($sql);
$st->execute($scopeArgs);
$threads = $st->fetchAll();
// The counts cover every conversation in scope, not only the 200 listed.
$cnt = db()->prepare("SELECT COUNT(DISTINCT m.client_id, m.channel) AS threads, COALESCE(SUM(m.sender <> 'staff' AND m.read_at IS NULL), 0) AS unread
    FROM messages m JOIN clients c ON c.id = m.client_id WHERE 1=1$scopeAnd");
$cnt->execute($scopeArgs);
$counts = $cnt->fetch();
$threadTotal = (int)$counts['threads'];
// Any client's conversation can be opened from here, including one past the list's limit.
$pick = db()->prepare('SELECT c.id, c.name FROM clients c WHERE c.archived_at IS NULL' . $scopeAnd . ' ORDER BY c.name');
$pick->execute($scopeArgs);
$pickClients = $pick->fetchAll();
// "You" only for the viewer's own messages; a colleague's reply shows their name.
$staffNames = db()->query('SELECT id, name FROM users')->fetchAll(PDO::FETCH_KEY_PAIR);
$lastSenderLabel = function (array $t) use ($staffNames, $user): string {
    if ($t['last_sender'] !== 'staff') return '';
    $sid = (int)$t['last_staff_id'];
    return ($sid === (int)$user['id'] ? 'You' : ($staffNames[$sid] ?? 'Staff')) . ': ';
};
$totalUnread = (int)$counts['unread'];

page_header('Messages', $user, 'messages');
?>
<h1>Messages</h1>
<p class="muted">Secure two-way conversations with clients and counselors<?= $scoped ? ' on your caseload' : '' ?>. <?= $totalUnread ?> unread.</p>

<?php if ($pickClients): ?>
<?= form_error_summary(['open_client' => 'Client']) ?>
<form method="get" action="messages.php" class="page-actions" aria-label="Open a conversation" novalidate>
  <label for="open_client">Client <?= required_mark() ?></label>
  <select id="open_client" name="client" aria-required="true" <?= field_aria('open_client') ?>>
    <option value="">— Choose a client —</option>
    <?php foreach ($pickClients as $pc): ?><option value="<?= (int)$pc['id'] ?>"><?= h($pc['name']) ?></option><?php endforeach; ?>
  </select><?= field_error('open_client') ?>
  <label for="open_channel">Conversation</label>
  <select id="open_channel" name="channel"><option value="client">Client</option><option value="counselor">Counselor</option></select>
  <button type="submit" class="btn btn-secondary btn-sm">Open conversation</button>
</form>
<?php endif; ?>
<?php if ($threadTotal > count($threads)): ?><p class="muted">Showing <?= count($threads) ?> of <?= $threadTotal ?> conversations (unread first, then the most recent). Choose a client above to open any other conversation.</p><?php endif; ?>

<div class="table-scroll" tabindex="0" role="region" aria-label="Conversations">
<table>
  <caption class="visually-hidden">Message conversations</caption>
  <thead><tr><th scope="col">Client</th><th scope="col">Channel</th><th scope="col">Last message</th><th scope="col">When</th></tr></thead>
  <tbody>
  <?php if (!$threads): ?><tr><td colspan="4" class="muted">No conversations yet.</td></tr><?php endif; ?>
  <?php foreach ($threads as $t): ?>
    <tr>
      <?php // The link names the channel and unread count: a client with both threads has two links otherwise named alike. ?>
      <td><a href="messages.php?client=<?= (int)$t['client_id'] ?>&amp;channel=<?= h($t['channel']) ?>"><?= h($t['client_name']) ?><span class="visually-hidden"> — <?= $t['channel'] === 'client' ? 'client' : 'counselor' ?> conversation<?= (int)$t['unread'] > 0 ? ', ' . (int)$t['unread'] . ' unread' : '' ?></span></a>
        <?= (int)$t['unread'] > 0 ? ' <span class="badge badge-pending" aria-hidden="true">' . (int)$t['unread'] . ' new</span>' : '' ?></td>
      <td><?= $t['channel'] === 'client' ? 'Client' : 'Counselor' ?></td>
      <td><?= h($lastSenderLabel($t) . mb_strimwidth((string)$t['last_body'], 0, 60, '…')) ?></td>
      <td><?= fmt_datetime($t['last_at']) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php page_footer();
