<?php
// Text messages (administrators, 4.17.0): how texting is set up, a test text, whether texts name
// the session's place, and the log of texts in and out with their delivery status.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
csrf_check();
$user = require_login();
require_perm('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_sms_options'])) {
    $loc = !empty($_POST['include_location']) ? 'yes' : 'no';
    set_setting('sms_include_location', $loc);
    audit($user['id'], 'settings.sms', 'settings', null, 'include location: ' . $loc);
    flash($loc === 'yes' ? 'Texts now name an in-person session\'s place.' : 'Texts no longer name the place (the time only).');
    redirect('sms.php#options-h');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_test'])) {
    $q = db()->prepare('SELECT mobile FROM users WHERE id = ?');
    $q->execute([(int)$user['id']]);
    $to = sms_number((string)$q->fetchColumn());
    if (!sms_enabled()) { flash('Set up texting first (Settings, Text messages).', 'error'); redirect('sms.php'); }
    if ($to === null) { flash('Add your mobile number on My Account first; the test goes to it.', 'error'); redirect('sms.php'); }
    db()->prepare('INSERT INTO sms_log (direction, user_id, phone, body, purpose, status, sent_by) VALUES (?,?,?,?,?,?,?)')
        ->execute(['out', (int)$user['id'], $to, 'Test text from ' . (brand('short') ?: cfg('name')) . '. Texting is working.', 'test', 'queued', (int)$user['id']]);
    $r = sms_dispatch((int)db()->lastInsertId());
    audit($user['id'], 'sms.test', 'settings', null, $r['status']);
    flash($r['ok'] ? 'Test text sent to your mobile. Its delivery shows in the log below.' : $r['message'], $r['ok'] ? 'success' : 'error');
    redirect('sms.php#log-h');
}

$base = rtrim((string)cfg('base_url'), '/');
$log = [];
try {
    $log = db()->query('SELECT l.*, c.name AS client_name, u.name AS user_name FROM sms_log l LEFT JOIN clients c ON c.id = l.client_id
        LEFT JOIN users u ON u.id = l.user_id ORDER BY l.id DESC LIMIT 200')->fetchAll();
} catch (PDOException $e) {}
$counts = [];
try { $counts = db()->query("SELECT COUNT(*) n, SUM(sms_consent_at IS NOT NULL AND sms_opted_out_at IS NULL) yes, SUM(sms_opted_out_at IS NOT NULL) stopped FROM clients WHERE archived_at IS NULL")->fetch(); } catch (PDOException $e) {}

page_header('Text messages', $user, 'admin', [['admin.php', 'Administration']]);
?>
<h1>Text messages</h1>
<p>Session reminders clients can answer (C to confirm, R to reschedule, X to ask to cancel), notices when a session is booked, moved or cancelled, new homework, two-way messages with staff, and urgent alerts to staff who choose them. Texts go only to clients who agreed to them, only from 8 a.m. to 8 p.m. in the client's time zone, and carry a first name, a time and, for an online session, its link: never a diagnosis or case details.</p>
<p><?= sms_enabled() ? 'Texting is set up.' : '<strong>Texting is not set up yet.</strong>' ?>
   <?php if ($counts): ?><?= (int)$counts['yes'] ?> of <?= (int)$counts['n'] ?> current clients have agreed to texts; <?= (int)$counts['stopped'] ?> replied STOP.<?php endif; ?></p>

<section class="card" aria-labelledby="setup-h">
  <h2 id="setup-h">Setting up texting</h2>
  <ol>
    <li>Make a Twilio account (twilio.com), buy a local phone number that can send texts, and complete its business texting registration (A2P 10DLC), which US carriers require. Describe the use as appointment reminders and customer care.</li>
    <li>In Twilio, open the number's settings. Under Messaging, set "A message comes in" to <code><?= h($base) ?>/sms_inbound.php</code> (HTTP POST).</li>
    <li>On Settings, Text messages, enter the Account SID and Auth Token (Twilio Console, Account info) and the sending number (such as +15155550100), or a Messaging Service SID (starts MG).</li>
    <li>Add your own mobile number on My Account, then send yourself a test below.</li>
    <li>Record each client's agreement to texts on their edit page (or the referral form's "OK to text"), and texts start.</li>
  </ol>
  <p>Delivery reports come back to <code><?= h($base) ?>/sms_status.php</code> on their own. Only requests signed with your Auth Token are accepted.</p>
</section>

<section class="card" aria-labelledby="options-h">
  <h2 id="options-h">What texts say</h2>
  <form method="post" action="sms.php" class="stacked"><?= csrf_field() ?>
    <div class="field"><label><input type="checkbox" name="include_location" value="1" <?= setting('sms_include_location') === 'yes' ? 'checked' : '' ?>> Name an in-person session's place in texts <span class="hint">off: the time only, which says less to anyone who sees the client's phone</span></label></div>
    <button type="submit" name="save_sms_options" value="1" class="btn btn-secondary">Save</button>
  </form>
  <form method="post" action="sms.php" class="inline-form"><?= csrf_field() ?>
    <button type="submit" name="send_test" value="1" class="btn btn-secondary">Send a test text to my mobile</button>
  </form>
</section>

<section class="card" aria-labelledby="log-h">
  <h2 id="log-h">Recent texts (<?= count($log) ?>)</h2>
  <?php if (!$log): ?><p>No texts yet.</p><?php else: ?>
  <table class="table">
    <caption class="visually-hidden">Recent texts, newest first</caption>
    <thead><tr><th scope="col">When</th><th scope="col">Who</th><th scope="col">What</th><th scope="col">Status</th></tr></thead>
    <tbody>
    <?php foreach ($log as $l): ?>
      <tr><td><?= fmt_datetime($l['created_at']) ?></td>
        <th scope="row"><?= $l['direction'] === 'in' ? 'From ' : 'To ' ?><?= $l['client_name'] ? '<a href="client.php?id=' . (int)$l['client_id'] . '">' . h($l['client_name']) . '</a>' : ($l['user_name'] ? h($l['user_name']) . ' (staff)' : 'unknown number ' . h(substr((string)$l['phone'], 0, -4) . '••••')) ?></th>
        <td><?= h(['reminder' => 'Reminder', 'booked' => 'Session booked', 'moved' => 'Session moved', 'cancelled' => 'Session cancelled', 'homework' => 'New homework', 'message' => 'Message', 'reply' => 'Reply', 'alert' => 'Staff alert', 'test' => 'Test'][$l['purpose']] ?? $l['purpose']) ?>: <?= h(mb_strimwidth((string)$l['body'], 0, 120, '…')) ?></td>
        <td><?= h(['held' => 'Waiting for 8 a.m.', 'queued' => 'Queued', 'sending' => 'Sending', 'sent' => 'Sent', 'delivered' => 'Delivered', 'undelivered' => 'Not delivered', 'failed' => 'Failed', 'blocked' => 'Not sent', 'received' => 'Received'][$l['status']] ?? $l['status']) ?><?= $l['error'] ? ': ' . h((string)$l['error']) : '' ?><?= $l['status'] === 'held' && $l['send_after'] ? ' (' . fmt_datetime($l['send_after']) . ')' : '' ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</section>
<?php page_footer();
