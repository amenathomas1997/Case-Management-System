<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
$user = require_login();
require_perm('clients');

$q = trim(is_string($_GET['q'] ?? null) ? $_GET['q'] : '');
// Percent, underscore and backslash are literal text, not wildcards (4.27.0), as on the Tasks page.
$like = '%' . strtr($q, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
$results = [];
$capped = [];   // categories with more matches than are shown

if (mb_strlen($q) >= 2) {
    // One row past the 25 shown tells us the list was cut short, so the page
    // can say so instead of presenting 25 as the total.
    $run = function (string $label, string $sql, array $params) use (&$results, &$capped) {
        $stmt = db()->prepare($sql . ' LIMIT 26');
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        if (count($rows) > 25) { $capped[$label] = true; $rows = array_slice($rows, 0, 25); }
        if ($rows) $results[$label] = $rows;
    };
    // Caseload-scoped users only see their own clients' records.
    $scoped = caseload_scoped();
    $scopeClients = $scoped ? ' AND assigned_to = ?' : '';
    $scopeJoined = $scoped ? ' AND c.assigned_to = ?' : '';
    $scopeParam = $scoped ? [(int)$user['id']] : [];

    $run('Clients',
        "SELECT id, name AS title, CONCAT_WS(' · ', status, counselor_name) AS sub, CONCAT('client.php?id=', id) AS url
         FROM clients WHERE (name LIKE ? ESCAPE '!' OR email LIKE ? ESCAPE '!' OR phone LIKE ? ESCAPE '!' OR counselor_name LIKE ? ESCAPE '!')" . $scopeClients . ' ORDER BY name',
        array_merge([$like, $like, $like, $like], $scopeParam));
    $run('Client notes',
        "SELECT n.id, CONCAT(c.name, ': ', n.subject) AS title, n.note_type AS sub, CONCAT('client.php?id=', n.client_id, '&tab=notes') AS url
         FROM client_notes n JOIN clients c ON c.id = n.client_id WHERE (n.subject LIKE ? ESCAPE '!' OR n.body LIKE ? ESCAPE '!')" . $scopeJoined . " ORDER BY n.created_at DESC",
        array_merge([$like, $like], $scopeParam));
    if (can('referrals')) {
        $run('Referrals',
            "SELECT id, CONCAT(client_name, ' — ', reason) AS title, CONCAT_WS(' · ', status, counselor_name) AS sub, CONCAT('referrals.php?view=', id) AS url
             FROM referrals WHERE (client_name LIKE ? ESCAPE '!' OR reason LIKE ? ESCAPE '!' OR body LIKE ? ESCAPE '!' OR counselor_name LIKE ? ESCAPE '!')"
             // Same rule as the Referrals page and the app: a converted referral shows
             // only to the client's current instructor, whoever it was assigned to.
             . ($scoped ? " AND ((referrals.status <> 'Converted' AND (referrals.assigned_to = ? OR referrals.assigned_to IS NULL))"
                 . " OR (referrals.status = 'Converted' AND EXISTS (SELECT 1 FROM clients sc WHERE sc.id = referrals.client_id AND sc.assigned_to = ?)))" : '')
             . ' ORDER BY created_at DESC',
            array_merge([$like, $like, $like, $like], $scoped ? [(int)$user['id'], (int)$user['id']] : []));
    }
    $run('Training tracks',
        "SELECT t.id, CONCAT(c.name, ': ', t.title) AS title, CONCAT('Auth ', t.auth_number, ' · ', t.status) AS sub, CONCAT('track.php?id=', t.id) AS url
         FROM training_tracks t JOIN clients c ON c.id = t.client_id WHERE (t.title LIKE ? ESCAPE '!' OR t.auth_number LIKE ? ESCAPE '!')" . $scopeJoined . ' ORDER BY t.created_at DESC',
        array_merge([$like, $like], $scopeParam));
    $run('SMART goals',
        "SELECT g.id, CONCAT(c.name, ': ', SUBSTRING(g.specific_goal, 1, 80)) AS title, g.status AS sub, CONCAT('track.php?id=', g.track_id, '#goals') AS url
         FROM smart_goals g JOIN training_tracks t ON t.id = g.track_id JOIN clients c ON c.id = t.client_id
         WHERE (g.specific_goal LIKE ? ESCAPE '!' OR g.measurable_metric LIKE ? ESCAPE '!')" . $scopeJoined . ' ORDER BY g.created_at DESC',
        array_merge([$like, $like], $scopeParam));
    $run('Documents',
        "SELECT d.id, CONCAT(c.name, ': ', d.title) AS title, d.category AS sub, CONCAT('client.php?id=', d.client_id, '&tab=documents') AS url
         FROM client_documents d JOIN clients c ON c.id = d.client_id WHERE (d.title LIKE ? ESCAPE '!' OR d.orig_name LIKE ? ESCAPE '!')" . $scopeJoined . ' ORDER BY d.created_at DESC',
        array_merge([$like, $like], $scopeParam));
    if (can('billing')) {
        $run('Invoices',
            "SELECT i.id, CONCAT(i.invoice_number, ' — ', c.name) AS title, CONCAT(i.status, ' · $', FORMAT(i.total_amount, 2)) AS sub,
                    CONCAT('invoices.php?id=', i.id, '#inv-', i.id) AS url
             FROM invoices i JOIN training_tracks t ON t.id = i.track_id JOIN clients c ON c.id = t.client_id
             WHERE (i.invoice_number LIKE ? ESCAPE '!' OR i.payment_ref LIKE ? ESCAPE '!')" . $scopeJoined . ' ORDER BY i.created_at DESC',
            array_merge([$like, $like], $scopeParam));
    }
}

page_header($q !== '' ? 'Search results' : 'Search', $user, '');
?>
<h1>Search</h1>
<form method="get" action="search.php" role="search" aria-label="Search results filter" class="card">
  <div class="field">
    <label for="q">Search clients, notes, referrals, tracks, goals, documents, and invoices</label>
    <?php // The box takes focus only when there is nothing yet to report; after a
          // search, focus goes to the outcome summary below so it is heard first. ?>
    <input type="search" id="q" name="q" value="<?= h($q) ?>" required minlength="2"<?= $q === '' ? ' autofocus' : '' ?>>
  </div>
  <button type="submit" class="btn btn-primary">Search</button>
</form>

<?php if ($q !== ''):
  $shown = array_sum(array_map('count', $results));
  if (mb_strlen($q) < 2) $summary = 'Enter at least 2 characters to search.';
  elseif (!$results) $summary = 'No results for “' . $q . '”.';
  else $summary = ($capped ? 'More than ' . $shown . ' results' : $shown . ' result' . ($shown === 1 ? '' : 's'))
      . ' for “' . $q . '” in ' . count($results) . ' ' . (count($results) === 1 ? 'category' : 'categories')
      . ': ' . implode(', ', array_keys($results)) . '.'; ?>
  <p id="search-summary" tabindex="-1"><strong><?= h($summary) ?></strong></p>
  <script>document.addEventListener('DOMContentLoaded',function(){var s=document.getElementById('search-summary');if(s)s.focus();});</script>
<?php endif; ?>

<?php foreach ($results as $label => $rows): ?>
<section class="card" aria-labelledby="s-<?= h(str_replace(' ', '-', $label)) ?>">
  <h2 id="s-<?= h(str_replace(' ', '-', $label)) ?>"><?= h($label) ?> (<?= isset($capped[$label]) ? 'first ' . count($rows) . ' shown, more match' : count($rows) ?>)</h2>
  <?php if (isset($capped[$label])): ?>
  <p class="muted">Only the first <?= count($rows) ?> matches are listed<?= $label === 'Clients' ? ' (by name)' : ' (newest first)' ?>. Add more words to your search to find the rest.</p>
  <?php endif; ?>
  <ul>
  <?php foreach ($rows as $r): ?>
    <li><a href="<?= h($r['url']) ?>"><?= h($r['title']) ?></a><?= $r['sub'] ? ' <span class="muted">— ' . h($r['sub']) . '</span>' : '' ?></li>
  <?php endforeach; ?>
  </ul>
</section>
<?php endforeach; ?>
<?php page_footer();
