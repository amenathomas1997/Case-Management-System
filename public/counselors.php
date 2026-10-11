<?php
// Staff view: manage VR counselor portal access.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
csrf_check();
$user = require_login();
require_perm('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_portal'])) {
    $email = strtolower(trim(is_string($_POST['email'] ?? null) ? $_POST['email'] : ''));
    $name = trim(is_string($_POST['name'] ?? null) ? $_POST['name'] : '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        flash('A valid counselor email is required.', 'error');
        redirect('counselors.php');
    }
    // A counselor email that came in through the public referral form is unconfirmed
    // (counselor_verified = 0) until staff confirm it on the client's edit page: a link
    // is never sent to an address that is only on unconfirmed clients.
    $ver = db()->prepare('SELECT COUNT(*) AS n, COALESCE(SUM(counselor_verified = 1), 0) AS ok FROM clients WHERE counselor_email = ? AND archived_at IS NULL');
    $ver->execute([$email]);
    $ver = $ver->fetch();
    if ((int)$ver['n'] > 0 && (int)$ver['ok'] === 0) {
        flash('No link was sent: ' . $email . ' has not been confirmed as the counselor on any client. Confirm the counselor on the client\'s edit page first.', 'error');
        redirect('counselors.php');
    }
    if ($name === '') {
        // Counselors can be recorded on a client with an email but no name: keep the
        // name an earlier link was sent under, else greet them as "Counselor" (as the app does).
        $kn = db()->prepare('SELECT counselor_name FROM vrc_tokens WHERE counselor_email = ?');
        $kn->execute([$email]);
        $name = trim((string)$kn->fetchColumn()) ?: 'Counselor';
    }
    $token = bin2hex(random_bytes(24));
    // Email first and store the new link only once it has gone out: storing it
    // replaces (and so revokes) the counselor's current link.
    $sent = send_app_mail($email, 'Your ' . brand('short') . ' counselor portal',
        "Hello {$name},\n\nYou can now view your clients' progress with " . brand('org') . " — authorizations, hours used, and goal status — at your private portal:\n\n"
        . cfg('base_url') . '/vrc.php?t=' . $token
        . "\n\nEach new browser session asks for a 6-digit code emailed to you, to protect client information. Keep this link private; if it's ever lost, we can send a new one (which replaces this one).\n");
    if (!$sent) {
        flash("The email to {$name} could not be sent, so nothing was changed: their current portal link, if they have one, still works. Try again later.", 'error');
        redirect('counselors.php');
    }
    db()->prepare('INSERT INTO vrc_tokens (counselor_email, counselor_name, token, expires_at) VALUES (?,?,?, DATE_ADD(NOW(), INTERVAL 180 DAY))
        ON DUPLICATE KEY UPDATE token = VALUES(token), counselor_name = VALUES(counselor_name), created_at = NOW(), expires_at = DATE_ADD(NOW(), INTERVAL 180 DAY)')
        ->execute([$email, $name, $token]);
    audit($user['id'], 'vrcportal.send', 'counselor', null, $email);
    flash("Portal link emailed to {$name}. Any previous link no longer works.");
    redirect('counselors.php');
}

/* ---- Turn off a counselor's portal link (e.g. they have left, or the link was shared) ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['revoke_portal'])) {
    $email = strtolower(trim(is_string($_POST['email'] ?? null) ? $_POST['email'] : ''));
    $del = db()->prepare('DELETE FROM vrc_tokens WHERE counselor_email = ?');
    $del->execute([$email]);
    if ($del->rowCount() > 0) {
        audit($user['id'], 'vrcportal.revoke', 'counselor', null, $email);
        // vrc.php looks the link up on every request, so even an open portal session ends now.
        flash("Portal link turned off for {$email}. It no longer opens, even in a portal session that is already open; send a new link when they need access again.");
    } else {
        flash('That counselor has no portal link to turn off.', 'error');
    }
    redirect('counselors.php');
}

$counselors = db()->query("SELECT counselor_email AS email, MAX(counselor_name) AS name, MAX(counselor_agency) AS agency, COUNT(*) AS clients
    FROM clients WHERE counselor_email IS NOT NULL AND counselor_email <> '' AND archived_at IS NULL AND counselor_verified = 1
      AND (counselor_agency IS NULL OR counselor_agency NOT LIKE 'Self%')
    GROUP BY counselor_email ORDER BY name")->fetchAll();
$tokens = [];
// 'live' uses the same test as vrc.php, so a link shown as active really opens.
foreach (db()->query('SELECT counselor_email, counselor_name, created_at, last_used_at, expires_at, (expires_at IS NULL OR expires_at > NOW()) AS live FROM vrc_tokens') as $t) {
    $tokens[strtolower($t['counselor_email'])] = $t;
}
// A counselor no longer on any client keeps a live link until it expires, so list
// them too: otherwise that link could never be turned off here.
$listed = array_flip(array_map(fn($c) => strtolower($c['email']), $counselors));
foreach ($tokens as $em => $t) {
    if (!isset($listed[$em]) && $t['live']) $counselors[] = ['email' => $t['counselor_email'], 'name' => $t['counselor_name'], 'agency' => null, 'clients' => 0];
}

page_header('Counselor portal', $user, 'referrals');
?>
<h1>VR counselor portal access</h1>
<p class="muted">Give counselors a private, read-only view of their own clients' authorizations, hours, goal progress, and report history. Access requires their link plus an emailed code.</p>

<div class="table-scroll" tabindex="0" role="region" aria-label="Counselor portal access"><table>
  <caption class="visually-hidden">VR counselors and their portal access</caption>
  <thead><tr><th scope="col">Counselor</th><th scope="col">Agency</th><th scope="col" class="num">Clients</th><th scope="col">Portal</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead>
  <tbody>
  <?php if (!$counselors): ?><tr><td colspan="5" class="muted">No counselors on file yet.</td></tr><?php endif; ?>
  <?php foreach ($counselors as $c): $tk = $tokens[strtolower($c['email'])] ?? null; $who = ($c['name'] ?? '') !== '' ? $c['name'] : $c['email']; ?>
    <tr>
      <td><?= h($c['name']) ?><br><span class="muted"><?= h($c['email']) ?></span></td>
      <td><?= h($c['agency'] ?? '—') ?></td>
      <td class="num"><?= (int)$c['clients'] ?></td>
      <td><?php if (!$tk): ?><span class="muted">Not invited</span>
        <?php elseif ($tk['live']): ?><span class="badge badge-closed">Active</span><br><span class="muted"><?= $tk['expires_at'] ? 'Expires ' . fmt_date($tk['expires_at']) : 'Does not expire' ?> · <?= $tk['last_used_at'] ? 'Last used ' . fmt_date($tk['last_used_at']) : 'Never opened' ?></span>
        <?php else: ?><span class="badge badge-hold">Expired</span><br><span class="muted">Expired <?= fmt_date($tk['expires_at']) ?>; the counselor needs a new link</span><?php endif; ?></td>
      <td>
        <form method="post" action="counselors.php" class="inline-form"><?= csrf_field() ?>
          <input type="hidden" name="email" value="<?= h($c['email']) ?>">
          <input type="hidden" name="name" value="<?= h($c['name']) ?>">
          <button type="submit" name="send_portal" value="1" class="btn btn-secondary btn-sm"
            onclick="return confirm(<?= h(json_encode('Email a portal link to ' . $who . '? Any previous link stops working.', JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP)) ?>);">
            <?= !$tk ? 'Send portal link' : ($tk['live'] ? 'Resend / reset link' : 'Send a new link') ?><span class="visually-hidden"> for <?= h($who) ?></span></button>
        </form>
        <?php if ($tk && $tk['live']): ?>
        <form method="post" action="counselors.php" class="inline-form"><?= csrf_field() ?>
          <input type="hidden" name="email" value="<?= h($c['email']) ?>">
          <button type="submit" name="revoke_portal" value="1" class="btn btn-secondary btn-sm"
            onclick="return confirm(<?= h(json_encode('Turn off the portal link for ' . $who . '? It stops working at once, and they need a new link to get back in.', JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP)) ?>);">
            Turn off link<span class="visually-hidden"> for <?= h($who) ?></span></button>
        </form>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
<?php page_footer();
