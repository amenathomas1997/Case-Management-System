<?php
// Staff view: manage VR counselor portal access.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
csrf_check();
$user = require_login();
require_perm('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_portal'])) {
    // 4.38.0: the checks, the 60-second guard, the email and the store are inc/counselor_portal.php's,
    // shared with the apps' send_vrc_portal.
    $res = vrc_portal_send(is_string($_POST['email'] ?? null) ? $_POST['email'] : '', is_string($_POST['name'] ?? null) ? $_POST['name'] : '', $user);
    flash($res['message'], $res['kind']);
    redirect('counselors.php');
}

/* ---- Replace a counselor on every one of their clients in one step (3.0.45) ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['replace_counselor'])) {
    $oldEmail = strtolower(trim(is_string($_POST['email'] ?? null) ? $_POST['email'] : ''));
    $in = fn(string $k): string => trim(is_string($_POST[$k] ?? null) ? $_POST[$k] : '');
    $newEmail = strtolower($in('new_email'));
    $newName = $in('new_name'); $newAgency = $in('new_agency'); $newPhone = $in('new_phone');
    $key = 'new_' . sprintf('%u', crc32($oldEmail));
    // 4.29.0: each error on its own box (the ids the form gives them), all found at once,
    // and announced by the form's error summary.
    $errs = [];
    if ($newName === '' || mb_strlen($newName) > 120) $errs[$key . '-n'] = 'Enter the new counselor\'s name (120 characters or fewer).';
    if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL) || mb_strlen($newEmail) > 190) $errs[$key . '-e'] = 'Enter the new counselor\'s email address.';
    elseif ($newEmail === $oldEmail) $errs[$key . '-e'] = 'The new email is the same as the current one.';
    if (mb_strlen($newAgency) > 190) $errs[$key . '-a'] = 'Keep the agency to 190 characters.';
    if (mb_strlen($newPhone) > 40) $errs[$key . '-p'] = 'Keep the phone to 40 characters.';
    if ($errs) { keep_input(); keep_field_errors($errs); redirect('counselors.php#' . $key . '-form'); }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $q = $pdo->prepare('SELECT id, name, counselor_name, counselor_agency FROM clients WHERE LOWER(counselor_email) = ? AND archived_at IS NULL FOR UPDATE');
        $q->execute([$oldEmail]);
        $moved = $q->fetchAll();
        $oldName = $moved[0]['counselor_name'] ?? $oldEmail;
        $up = $pdo->prepare('UPDATE clients SET counselor_name = ?, counselor_email = ?, counselor_agency = ?, counselor_phone = ?, counselor_verified = 1 WHERE id = ?');
        $note = $pdo->prepare("INSERT INTO client_notes (client_id, note_type, subject, body, author_id) VALUES (?, 'Case Note', 'VR counselor changed', ?, ?)");
        $bill = $pdo->prepare("UPDATE invoices i JOIN training_tracks t ON t.id = i.track_id SET i.bill_to = ? WHERE t.client_id = ? AND i.status = 'Draft'");
        foreach ($moved as $c) {
            $agency = $newAgency !== '' ? $newAgency : (string)($c['counselor_agency'] ?? '');
            $up->execute([$newName, $newEmail, $agency !== '' ? $agency : null, $newPhone !== '' ? $newPhone : null, (int)$c['id']]);
            $note->execute([(int)$c['id'], 'VR counselor changed from ' . $oldName . ' (' . $oldEmail . ') to ' . $newName . ' (' . $newEmail . ').', $user['id']]);
            // 4.28.0: re-addressed the way generate_invoice() addresses each draft (agency billing
            // address for VR, the client for private pay), by the shared helper in inc/billing.php.
            if (function_exists('draft_invoices_readdress')) draft_invoices_readdress((int)$c['id']);
            else $bill->execute([implode("\n", array_filter([$newName, $agency, $newEmail], fn($v) => $v !== '')), (int)$c['id']]);
        }
        // The old counselor's portal link no longer opens.
        $pdo->prepare('DELETE FROM vrc_tokens WHERE counselor_email = ?')->execute([$oldEmail]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    audit($user['id'], 'vrcportal.replace', 'counselor', null, $oldEmail . ' → ' . $newEmail . ': ' . count($moved) . ' client(s)');
    flash(count($moved) . ' client' . (count($moved) === 1 ? '' : 's') . ' moved from ' . $oldName . ' to ' . $newName . '. Their records and draft invoices now name ' . $newName
        . ', and ' . $oldName . '\'s portal link was turned off. Send ' . $newName . ' a portal link below.');
    redirect('counselors.php');
}

/* ---- Turn off a counselor's portal link (e.g. they have left, or the link was shared) ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['revoke_portal'])) {
    $res = vrc_portal_revoke(is_string($_POST['email'] ?? null) ? $_POST['email'] : '', $user);
    flash($res['message'], $res['kind']);
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
        <?php elseif ($tk['live']): ?><span class="badge badge-closed">Active</span><br><span class="muted"><?= h(implode(' · ', vrc_portal_status_lines($tk))) ?></span>
        <?php else: ?><span class="badge badge-hold">Expired</span><br><span class="muted"><?= h(implode(' · ', vrc_portal_status_lines($tk))) ?></span><?php endif; ?></td>
      <td>
        <form method="post" action="counselors.php" class="inline-form"><?= csrf_field() ?>
          <input type="hidden" name="email" value="<?= h($c['email']) ?>">
          <input type="hidden" name="name" value="<?= h($c['name']) ?>">
          <button type="submit" name="send_portal" value="1" class="btn btn-secondary btn-sm"
            onclick="return confirm(<?= h(json_encode('Email a portal link to ' . $who . '? Any previous link stops working.', JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP)) ?>);">
            <?= !$tk ? 'Send portal link' : ($tk['live'] ? 'Resend / reset link' : 'Send a new link') ?><span class="visually-hidden"> for <?= h($who) ?></span></button>
        </form>
        <?php if ((int)$c['clients'] > 0): $rk = 'new_' . sprintf('%u', crc32(strtolower($c['email']))); $rMine = old_in('email') === $c['email'] && old_in('replace_counselor') !== ''; ?>
        <?php $rLabels = [$rk . '-n' => "New counselor's name", $rk . '-e' => "New counselor's email", $rk . '-a' => 'Agency', $rk . '-p' => 'Phone'];
              $rErr = (bool)array_intersect_key(form_errors(), $rLabels); ?>
        <details id="<?= $rk ?>-form"<?= $rErr ? ' open' : '' ?>><summary>Replace with a new counselor<span class="visually-hidden"> for <?= h($who) ?></span></summary>
          <form method="post" action="counselors.php" class="stacked" novalidate><?= csrf_field() ?>
            <input type="hidden" name="email" value="<?= h($c['email']) ?>">
            <?= form_error_summary($rLabels) ?>
            <p class="hint">Moves all <?= (int)$c['clients'] ?> of <?= h($who) ?>'s clients to the new counselor, adds a note to each, updates draft invoices and turns off <?= h($who) ?>'s portal link.</p>
            <div class="field"><label for="<?= $rk ?>-n">New counselor's name</label><input type="text" id="<?= $rk ?>-n" name="new_name" maxlength="120" value="<?= $rMine ? h(old_in('new_name')) : '' ?>" <?= field_aria($rk . '-n') ?>><?= field_error($rk . '-n') ?></div>
            <div class="field"><label for="<?= $rk ?>-e">New counselor's email</label><input type="email" id="<?= $rk ?>-e" name="new_email" maxlength="190" value="<?= $rMine ? h(old_in('new_email')) : '' ?>" <?= field_aria($rk . '-e') ?>><?= field_error($rk . '-e') ?></div>
            <div class="field"><label for="<?= $rk ?>-a">Agency <span class="hint">leave blank to keep <?= h($c['agency'] ?? 'the current one') ?></span></label><input type="text" id="<?= $rk ?>-a" name="new_agency" maxlength="190" value="<?= $rMine ? h(old_in('new_agency')) : '' ?>" <?= field_aria($rk . '-a') ?>><?= field_error($rk . '-a') ?></div>
            <div class="field"><label for="<?= $rk ?>-p">Phone</label><input type="tel" id="<?= $rk ?>-p" name="new_phone" maxlength="40" value="<?= $rMine ? h(old_in('new_phone')) : '' ?>" <?= field_aria($rk . '-p') ?>><?= field_error($rk . '-p') ?></div>
            <button type="submit" name="replace_counselor" value="1" class="btn btn-secondary btn-sm" onclick="return confirm(<?= h(json_encode('Move all of ' . $who . '\'s clients to the new counselor? Their portal link stops working.', JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP)) ?>);">Replace counselor<span class="visually-hidden"> for <?= h($who) ?></span></button>
          </form>
        </details>
        <?php endif; ?>
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
