<?php
// In-app alerts: things that happened while you were away (receipt
// confirmations, signed consents, completed surveys, assignments, messages).
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
csrf_check();
$user = require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_read'])) {
    // Only the alerts that were on the page: older ones beyond the 100 shown,
    // and any that arrived since, stay unread so they are not dismissed unseen.
    // One timestamp for the whole batch, so Undo puts back exactly these (inc/alerts.php, shared with the apps).
    $ids = alerts_ids($_POST['ids'] ?? '');
    $r = alerts_mark_read((int)$user['id'], $ids);
    if ($r['marked']) $_SESSION['alerts_undo'] = ['ids' => $r['ids'], 'stamp' => $r['stamp'], 'count' => $r['marked'], 'at' => time()];
    flash(alerts_marked_message($r['marked'], $r['left']));
    redirect('notifications.php');
}

// Undo the last "mark as read" (within 15 minutes): only alerts that batch
// marked, and are still marked from it, become unread again.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['undo_mark_read'])) {
    $undo = $_SESSION['alerts_undo'] ?? null;
    unset($_SESSION['alerts_undo']);
    $restored = 0;
    if (is_array($undo) && time() - (int)($undo['at'] ?? 0) <= 900 && !empty($undo['ids'])) {
        $restored = alerts_undo((int)$user['id'], (array)$undo['ids'], (string)$undo['stamp']);
    }
    flash(alerts_undo_message($restored), $restored ? 'success' : 'error');
    redirect('notifications.php');
}
$undoMark = $_SESSION['alerts_undo'] ?? null;
if (!is_array($undoMark) || time() - (int)($undoMark['at'] ?? 0) > 900) $undoMark = null;

$rows = db()->prepare('SELECT * FROM notifications WHERE user_id = ? ORDER BY read_at IS NULL DESC, created_at DESC LIMIT 100');
$rows->execute([(int)$user['id']]);
$rows = $rows->fetchAll();
$shownUnread = array_column(array_filter($rows, fn($r) => $r['read_at'] === null), 'id');
// The real total, so this heading agrees with the count in the navigation.
$uc = db()->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL');
$uc->execute([(int)$user['id']]);
$unread = (int)$uc->fetchColumn();

page_header('Alerts', $user, 'alerts');
?>
<h1>Alerts<?= $unread ? " ({$unread} new)" : '' ?></h1>
<p class="muted">Things that happened while you were away. Emails still go out as usual — this is the in-app copy.</p>

<?php if ($undoMark): ?>
<form method="post" action="notifications.php" class="page-actions"><?= csrf_field() ?>
  <button type="submit" name="undo_mark_read" value="1" class="btn btn-secondary">Undo: mark the <?= (int)$undoMark['count'] ?> alert<?= (int)$undoMark['count'] === 1 ? '' : 's' ?> just marked read as unread again</button>
</form>
<?php endif; ?>
<?php if ($unread > count($shownUnread)): ?>
<p>Showing the newest <?= count($shownUnread) ?> of <?= $unread ?> unread alerts. Mark these as read to list the older ones.</p>
<?php endif; ?>
<?php if ($shownUnread): ?>
<form method="post" action="notifications.php" class="page-actions"><?= csrf_field() ?>
  <input type="hidden" name="ids" value="<?= h(implode(',', array_map('intval', $shownUnread))) ?>">
  <button type="submit" name="mark_read" value="1" class="btn btn-secondary"><?= $unread > count($shownUnread)
      ? 'Mark these ' . count($shownUnread) . ' as read' : 'Mark all as read' ?></button>
</form>
<?php endif; ?>

<?php if (!$rows): ?><p class="muted">Nothing yet.</p><?php else: ?>
<ul role="list" style="list-style:none; padding-left:0;">
<?php foreach ($rows as $n): ?>
  <li class="card" style="padding:.6rem 1rem; margin-bottom:.5rem;">
    <?= $n['read_at'] === null ? '<strong>' : '' ?>
    <?php if ($n['link']): // a stand-alone link, so a full 44px target (2.5.5) ?><a href="<?= h($n['link']) ?>" style="display:inline-flex; align-items:center; min-height:2.75rem;"><?= h($n['message']) ?></a><?php else: ?><?= h($n['message']) ?><?php endif; ?>
    <?= $n['read_at'] === null ? '</strong> <span class="badge badge-active">new</span>' : '' ?>
    <br><span class="muted"><?= fmt_datetime($n['created_at']) ?></span>
  </li>
<?php endforeach; ?>
</ul>
<?php endif; ?>
<?php page_footer();
