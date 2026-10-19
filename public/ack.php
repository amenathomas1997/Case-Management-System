<?php
// Public acknowledgment of an emailed report (token is the authorization).
// Confirmation happens on POST, never on GET — email security scanners that
// prefetch links must not be able to "confirm receipt" by themselves.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
// A counselor has no account to sign back into. When the form's token no longer
// matches (the session lapsed while the page sat open, or cookies are blocked),
// nothing is confirmed and this page says so and asks for the button again
// (nothing was typed, so there is nothing to refill).
$csrfStale = $_SERVER['REQUEST_METHOD'] === 'POST' && empty($_SESSION['user_id'])
    && !(is_string($_POST['csrf'] ?? null) && hash_equals(csrf_token(), $_POST['csrf']));
if (!$csrfStale) csrf_check();

$rawToken = $_GET['t'] ?? '';   // token rides the query string on GET and POST; never sourced from a cookie
$token = is_string($rawToken) ? preg_replace('/[^a-f0-9]/', '', $rawToken) : '';
$ack = null;
if (strlen($token) === 48) {
    // The client and track are for the staff notification only; this public page never shows them.
    // active_owner: the assigned instructor only while their account is active.
    $stmt = db()->prepare('SELECT ra.*, c.name AS client_name, au.id AS active_owner, t.title AS track_title
        FROM report_acks ra JOIN clients c ON c.id = ra.client_id LEFT JOIN users au ON au.id = c.assigned_to AND au.is_active = 1
        LEFT JOIN training_tracks t ON t.id = ra.track_id WHERE ra.token = ? AND ra.sent_at > DATE_SUB(NOW(), INTERVAL 180 DAY)');
    $stmt->execute([$token]);
    $ack = $stmt->fetch();
}

$justConfirmed = false;
$staleNotice = $csrfStale && $ack && !$ack['acked_at'];
if (!$csrfStale && $ack && !$ack['acked_at'] && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm'])) {
    db()->beginTransaction();
    try {
        // Confirmation and its case note commit together.
        $claim = db()->prepare('UPDATE report_acks SET acked_at = NOW(), ack_ip = ? WHERE id = ? AND acked_at IS NULL');
        $claim->execute([client_ip(), $ack['id']]);
        if ($claim->rowCount() === 1) {
            db()->prepare('INSERT INTO client_notes (client_id, note_type, subject, body, author_id) VALUES (?,?,?,?,NULL)')
                ->execute([(int)$ack['client_id'], 'Case Note',
                    'VRC acknowledged report receipt',
                    "The counselor at {$ack['sent_to']} confirmed receipt of the training report sent " . fmt_datetime($ack['sent_at']) . '.']);
        }
        db()->commit();
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        throw $e;
    }
    if ($claim->rowCount() === 1) {
        // The client's instructor hears first (as with portal activity); admins when nobody
        // is assigned or the assigned account is deactivated (it would never see the alert).
        $msg = 'Report receipt confirmed for ' . $ack['client_name'] . ($ack['track_title'] ? ' (' . $ack['track_title'] . ')' : '') . ' by ' . $ack['sent_to'];
        $link = 'client.php?id=' . (int)$ack['client_id'] . '&tab=notes';
        if (!empty($ack['active_owner'])) notify_user((int)$ack['active_owner'], $msg, $link);
        else notify_admin_users($msg, $link);
    }
    $justConfirmed = true;
    $ack['acked_at'] = date('Y-m-d H:i:s');
}

// The title names the result, so it is heard first when the page loads.
page_header(!$ack ? 'Confirmation link not recognized' : ($ack['acked_at'] ? 'Receipt confirmed' : ($staleNotice ? 'Error — Report receipt confirmation' : 'Report receipt confirmation')));
?>
<div class="card auth-card">
<?php if (!$ack): ?>
  <h1>Link not recognized</h1>
  <p>This confirmation link is invalid or has been removed. If you believe this is an error, simply reply to the report email.</p>
<?php elseif ($ack['acked_at']): ?>
  <h1 id="ack-done" tabindex="-1">Receipt confirmed — thank you</h1>
  <p><?= $justConfirmed ? 'Your confirmation has been recorded on the client\'s file. No further action is needed.'
                        : 'This report was already confirmed. No further action is needed.' ?></p>
  <?php // A fresh confirmation is announced by moving focus to its heading once. ?>
  <?php if ($justConfirmed): ?><script>document.addEventListener("DOMContentLoaded",function(){var e=document.getElementById("ack-done");if(e)e.focus();});</script><?php endif; ?>
<?php else: ?>
  <h1>Confirm report receipt</h1>
  <?php if ($staleNotice): // Announced once, by moving focus to it (no alert role). ?>
  <div class="alert alert-error" id="form-errors" tabindex="-1"><span class="visually-hidden">Error: </span>This page was open for a long time, so your confirmation was not recorded yet. Choose Confirm receipt again.</div>
  <script>document.addEventListener("DOMContentLoaded",function(){var e=document.getElementById("form-errors");if(e)e.focus();});</script>
  <?php endif; ?>
  <?php // The address is masked: the link travels by email and may be forwarded.
        $sentTo = (string)$ack['sent_to']; $at = strpos($sentTo, '@');
        $maskedTo = $at > 0 ? substr($sentTo, 0, 1) . str_repeat('*', max(1, $at - 1)) . substr($sentTo, $at) : $sentTo; ?>
  <p>A training report was emailed to <strong><?= h($maskedTo) ?></strong> on <?= fmt_datetime($ack['sent_at']) ?>.
     Choose Confirm receipt to tell us you received it. The confirmation is recorded on the client's file.</p>
  <form method="post" action="ack.php?t=<?= h($token) ?>">
    <?= csrf_field() ?>
    <button type="submit" name="confirm" value="1" class="btn btn-primary">Confirm receipt</button>
  </form>
<?php endif; ?>
</div>
<?php page_footer();
