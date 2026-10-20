<?php
// QuickBooks Online (4.21.0, administrators): connect the company, send submitted invoices now, see which
// could not be sent and why, and disconnect (inc/qbo.php).
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
csrf_check();
$user = require_login();
require_perm('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['qbo_sync'])) {
    [$ok, $bad] = qbo_sync_all();
    audit((int)$user['id'], 'qbo.sync', 'settings', null, "$ok ok, $bad problems");
    flash($bad ? "$ok invoices sent or checked; $bad could not be (listed below)." : "$ok invoices sent to, or checked against, QuickBooks.", $bad ? 'error' : 'success');
    redirect('qbo.php');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['qbo_since'])) {
    $since = valid_date(is_string($_POST['since'] ?? null) ? $_POST['since'] : '', 2000, (int)date('Y'));
    // 4.30.0: tied to the date field, with what was typed kept.
    if ($since === null) { keep_input(); keep_field_errors(['since' => 'Enter the first date as a full date.']); redirect('qbo.php#since'); }
    set_setting('qbo_since', $since);
    audit((int)$user['id'], 'qbo.since', 'settings', null, $since);
    flash('Invoices submitted on or after ' . fmt_date($since) . ' go to QuickBooks.');
    redirect('qbo.php');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['qbo_disconnect'])) {
    $c = qbo_connection(); $app = qbo_app();
    if ($c && $app) {
        try { qbo_http('POST', $app['revoke'], ['Accept: application/json', 'Content-Type: application/json', 'Authorization: Basic ' . base64_encode($app['id'] . ':' . $app['secret'])], (string)json_encode(['token' => (string)meeting_open((string)$c['refresh_token'])])); }
        catch (Throwable $e) { error_log('qbo revoke: ' . get_class($e)); }
    }
    db()->exec('DELETE FROM qbo_connection');
    audit((int)$user['id'], 'qbo.disconnect', 'settings', null, (string)($c['company_name'] ?? ''));
    flash('QuickBooks is disconnected. Invoices already there stay there.');
    redirect('qbo.php');
}

$app = qbo_app();
$c = qbo_connection();
$problems = [];
$waiting = 0;
try {
    $problems = db()->query("SELECT id, invoice_number, qbo_error FROM invoices WHERE qbo_error IS NOT NULL AND status IN ('Submitted','Paid') ORDER BY id DESC LIMIT 50")->fetchAll();
    $wq = db()->prepare("SELECT COUNT(*) FROM invoices WHERE qbo_id IS NULL AND status IN ('Submitted','Paid') AND COALESCE(submitted_at, DATE(created_at)) >= ?");
    $wq->execute([(string)(setting('qbo_since') ?? date('Y-m-d'))]);
    $waiting = (int)$wq->fetchColumn();
} catch (PDOException $e) {}
page_header('QuickBooks Online', $user, 'admin', [['admin.php', 'Administration']]);
?>
<h1>QuickBooks Online</h1>
<p>Submitted invoices go to your QuickBooks company each morning, each to its customer (the funding agency, or for private pay the client, named only if you chose that on Settings), with lines for hours, mileage and expenses. VR invoices carry the authorization number, not the client's name. Payments recorded in QuickBooks come back here, and payments recorded here go there.</p>
<?php if (!$app): ?>
<p><strong>Not set up yet.</strong> At developer.intuit.com, create an app for QuickBooks Online Accounting, add the redirect address <code><?= h(rtrim((string)cfg('base_url'), '/')) ?>/qbo_connect.php</code>, and enter its Client ID and Client secret on <a href="settings.php">Settings</a>, QuickBooks Online.</p>
<?php elseif (!$c): ?>
<form method="post" action="qbo_connect.php" class="inline-form"><?= csrf_field() ?>
  <button type="submit" class="btn btn-primary">Connect QuickBooks</button>
</form>
<?php else: ?>
<p>Connected<?= $c['company_name'] ? ' to <strong>' . h((string)$c['company_name']) . '</strong>' : '' ?><?= $app['sandbox'] ? ' (sandbox)' : '' ?>. <?= $waiting ?> submitted invoices are waiting to be sent. Last sent or checked: <?= setting('qbo_synced_at') ? h(fmt_datetime((string)setting('qbo_synced_at'))) : 'not yet' ?>.<?= $c['refresh_expires_at'] && strtotime((string)$c['refresh_expires_at']) < time() + 14 * 86400 ? ' <strong>The connection must be renewed by ' . h(fmt_date((string)$c['refresh_expires_at'])) . ': connect again before then.</strong>' : '' ?></p>
<form method="post" action="qbo.php" class="inline-form" novalidate><?= csrf_field() ?>
  <?= form_error_summary(['since' => 'Send invoices submitted on or after']) ?>
  <label for="since">Send invoices submitted on or after <?= required_mark() ?></label>
  <input type="date" id="since" name="since" required aria-required="true" value="<?= h(old_in('since', (string)(setting('qbo_since') ?? date('Y-m-d')))) ?>" <?= field_aria('since') ?>><?= field_error('since') ?>
  <button type="submit" name="qbo_since" value="1" class="btn btn-secondary btn-sm">Save the date</button>
</form>
<p>Older invoices are left out, so invoices already in your books (or already paid) are not added again. Invoices voided or denied here are voided there too, and payments go both ways.</p>
<form method="post" action="qbo.php" class="inline-form"><?= csrf_field() ?>
  <button type="submit" name="qbo_sync" value="1" class="btn btn-primary">Send invoices now</button>
  <button type="submit" name="qbo_disconnect" value="1" class="btn btn-secondary" onclick="return confirm('Disconnect QuickBooks? Invoices already there stay there.');">Disconnect</button>
</form>
<?php endif; ?>
<section class="card" aria-labelledby="qp-h">
  <h2 id="qp-h">Invoices that could not be sent (<?= count($problems) ?>)</h2>
  <?php if (!$problems): ?><p>None.</p><?php else: ?>
  <ul><?php foreach ($problems as $p): ?><li><a href="invoices.php?id=<?= (int)$p['id'] ?>"><?= h((string)$p['invoice_number']) ?></a>: <?= h((string)$p['qbo_error']) ?></li><?php endforeach; ?></ul>
  <?php endif; ?>
</section>
<?php page_footer();
