<?php
// 30-day trash: deleted work logs and documents can be restored here.
// The nightly cron purges entries older than 30 days (and their stored files).
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
csrf_check();
$user = require_login();
require_perm('admin');

// After a restore or purge the admin returns to the page they were working on
// (the list clamps a page that no longer exists to the last one).
$backPage = max(1, (int)($_POST['page'] ?? 1));
$back = 'trash.php' . ($backPage > 1 ? '?page=' . $backPage : '');

/* ---- Restore ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['restore'])) {
    try {
        if ($row = restore_trash((int)($_POST['trash_id'] ?? 0))) {
            // A work log whose track rate changed while it was in the trash comes
            // back at the current rate (restore_trash() reports it as repriced).
            $repriced = !empty($row['repriced']);
            audit($user['id'], 'trash.restore', $row['entity'], (int)$row['entity_id'], $row['label'] . ($repriced ? '; re-priced to current rate' : ''));
            flash('Restored: ' . $row['label'] . ($repriced ? '. It was re-priced to the track\'s current rate, as the rate was changed while it was in the trash.' : ''));
        } else {
            flash('That item is no longer in the trash — it may already have been restored or removed.', 'error');
        }
    } catch (Throwable $e) {
        // Class and code only: a database message can quote the record's contents.
        error_log('Trash restore failed: ' . get_class($e) . ' (code ' . $e->getCode() . ')');
        flash('Could not restore. The client or track may no longer exist, or the original record may already be restored.', 'error');
    }
    redirect($back);
}

/* ---- Purge one entry now ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['purge'])) {
    try {
        if ($row = purge_trash((int)($_POST['trash_id'] ?? 0))) {
            audit($user['id'], 'trash.purge', $row['entity'], (int)$row['entity_id'], $row['label']);
            flash('Permanently deleted: ' . $row['label']);
        } else {
            flash('That item is no longer in the trash — it may already have been restored or removed.', 'error');
        }
    } catch (Throwable $e) {
        error_log('Trash purge failed: ' . get_class($e) . ' (code ' . $e->getCode() . ')');
        flash('Could not delete that item, and nothing was removed. Please try again.', 'error');
    }
    redirect($back);
}

// 200 items a page, newest first, with every page reachable: the oldest
// items are the next to be purged, so none may be hidden past the first page.
$perPage = 200;
$total = (int)db()->query('SELECT COUNT(*) FROM trash')->fetchColumn();
$pages = max(1, (int)ceil($total / $perPage));
$page = min($pages, max(1, (int)($_GET['page'] ?? 1)));
$rows = db()->query('SELECT t.*, u.name AS deleted_by_name,
        DATEDIFF(DATE_ADD(t.deleted_at, INTERVAL 30 DAY), NOW()) AS days_left
    FROM trash t LEFT JOIN users u ON u.id = t.deleted_by
    ORDER BY t.deleted_at DESC, t.id DESC LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage))->fetchAll();
$first = ($page - 1) * $perPage + 1;
$last = $first + count($rows) - 1;
$pager = function (string $where) use ($page, $pages): string {
    if ($pages < 2) return '';
    return '<nav class="page-actions" aria-label="Trash pages, ' . $where . '">'
        . ($page > 1 ? '<a class="btn btn-secondary btn-sm" href="trash.php?page=' . ($page - 1) . '">Newer items<span class="visually-hidden"> (page ' . ($page - 1) . ' of ' . $pages . ')</span></a>' : '')
        . ($page < $pages ? '<a class="btn btn-secondary btn-sm" href="trash.php?page=' . ($page + 1) . '">Older items, purged soonest<span class="visually-hidden"> (page ' . ($page + 1) . ' of ' . $pages . ')</span></a>' : '')
        . '</nav>';
};

page_header('Trash' . ($pages > 1 ? ' (page ' . $page . ' of ' . $pages . ')' : ''), $user, 'admin', [['admin.php', 'Administration']]);
?>
<h1>Trash</h1>
<p class="muted">Deleted work logs and documents wait here for 30 days before being removed for good by the nightly job.</p>
<?php if ($pages > 1): ?>
<p class="alert alert-error">Showing items <?= $first ?>–<?= $last ?> of <?= number_format($total) ?>, newest first. The oldest items, which are purged soonest, are on the last page.</p>
<?php endif; ?>
<?= $pager('above the list') ?>

<div class="table-scroll" tabindex="0" role="region" aria-labelledby="trash-h">
<table>
  <caption class="visually-hidden" id="trash-h">Deleted items awaiting purge<?= $pages > 1 ? ' (' . $first . '–' . $last . ' of ' . number_format($total) . ')' : '' ?></caption>
  <thead><tr><th scope="col">Item</th><th scope="col">Deleted</th><th scope="col">Auto-purge</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead>
  <tbody>
  <?php if (!$rows): ?><tr><td colspan="4" class="muted">The trash is empty.</td></tr><?php endif; ?>
  <?php foreach ($rows as $r): $itemName = $r['label'] ?: ($r['entity'] . ' #' . $r['entity_id']); // also names the row's buttons ?>
    <tr>
      <th scope="row"><?= h($itemName) ?></th>
      <td class="muted"><?= fmt_datetime($r['deleted_at']) ?> by <?= h($r['deleted_by_name'] ?? 'system') ?></td>
      <td><?= max(0, (int)$r['days_left']) ?> day<?= (int)$r['days_left'] === 1 ? '' : 's' ?> left</td>
      <td>
        <form method="post" action="trash.php" class="inline-form"><?= csrf_field() ?>
          <input type="hidden" name="trash_id" value="<?= (int)$r['id'] ?>">
          <input type="hidden" name="page" value="<?= $page ?>">
          <button type="submit" name="restore" value="1" class="btn btn-secondary btn-sm">Restore<span class="visually-hidden"> <?= h($itemName) ?></span></button>
          <button type="submit" name="purge" value="1" class="btn btn-danger btn-sm"
            onclick="return confirm('Permanently delete this item? This cannot be undone.');">Delete forever<span class="visually-hidden"> <?= h($itemName) ?></span></button>
        </form>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?= $pager('below the list') ?>
<?php page_footer();
