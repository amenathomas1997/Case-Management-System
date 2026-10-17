<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
csrf_check();
$user = require_login();
require_perm('referrals');

/* Caseload-scoped staff see referrals assigned to them, plus unassigned ones
   still waiting in the queue. A converted referral is a copy of a client's
   intake, so once converted it is visible only to whoever the client is
   assigned to now (the client can be reassigned after conversion). */
function referral_in_scope(array $ref, array $user): bool {
    if (!caseload_scoped()) return true;
    if ($ref['status'] === 'Converted') {
        if (empty($ref['client_id'])) return false;
        $owner = db()->prepare('SELECT assigned_to FROM clients WHERE id = ?');
        $owner->execute([(int)$ref['client_id']]);
        return (int)$owner->fetchColumn() === (int)$user['id'];
    }
    if ($ref['assigned_to'] !== null && (int)$ref['assigned_to'] === (int)$user['id']) return true;
    return $ref['assigned_to'] === null;
}
/** Whether a staff account may work the referral queue (and so be assigned a referral). */
function has_referrals_perm(array $userRow): bool {
    static $byAccess = []; // one permission lookup per group/role, not per user
    $key = ($userRow['group_id'] ?? '') . '|' . ($userRow['role'] ?? '');
    return $byAccess[$key] ??= in_array('referrals', resolve_perms($userRow)['perms'], true);
}

// Actions: mark reviewed, convert to client
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['id'] ?? 0);
    $stmt = db()->prepare('SELECT * FROM referrals WHERE id = ?');
    $stmt->execute([$id]);
    $ref = $stmt->fetch();
    if (!$ref) { flash('Referral not found.', 'error'); redirect('referrals.php'); }
    if (!referral_in_scope($ref, $user)) {
        flash('That referral is assigned to someone else.', 'error');
        redirect('referrals.php');
    }

    /* Close an open referral with a reason; the referrer is emailed the outcome (3.0.35). */
    if (isset($_POST['close_referral'])) {
        $reason = is_string($_POST['close_reason'] ?? null) ? $_POST['close_reason'] : '';
        $note = is_string($_POST['close_note'] ?? null) ? $_POST['close_note'] : '';
        $res = referral_close($id, $reason, $note, (int)$user['id']);
        if ($res['error'] !== null) {
            keep_input();
            keep_field_errors([isset(cms_list('referral_close_reasons')[$reason]) ? 'close_note' : 'close_reason' => $res['error']]);
            redirect('referrals.php?view=' . $id . '#close-h');
        }
        flash('Referral ' . referral_ref($id) . ' closed: ' . $reason . '.' . ($res['emailed'] ? ' The referrer was emailed the outcome (no client details).' : ' No outcome email was sent: the referral has no usable email address, or the email could not be sent.'));
        redirect('referrals.php');
    }
    if (isset($_POST['reopen_referral'])) {
        $why = referral_reopen($id, (int)$user['id']);
        flash($why ?? 'Referral ' . referral_ref($id) . ' reopened as New.', $why ? 'error' : 'success');
        redirect('referrals.php?view=' . $id);
    }
    /* Attach to a client already on file (a repeat referral): no new record (3.0.35). */
    if (isset($_POST['attach_referral'])) {
        require_perm('clients');
        $cid = (int)($_POST['client_id'] ?? 0);
        $cl = db()->prepare('SELECT id, name, assigned_to FROM clients WHERE id = ?');
        $cl->execute([$cid]);
        $target = $cl->fetch();
        if (!$target || (caseload_scoped() && (int)($target['assigned_to'] ?? 0) !== (int)$user['id'])) { flash('That client record is not one you can attach to.', 'error'); redirect('referrals.php?view=' . $id); }
        $res = referral_attach($id, $cid, (int)$user['id']);
        if ($res['error'] !== null) { flash($res['error'], 'error'); redirect('referrals.php?view=' . $id); }
        flash('Referral ' . referral_ref($id) . ' attached to ' . $target['name'] . '\'s record' . ($res['reopened'] ? ', which was reopened to Pending Assessment' : '') . '. Its intake note is on the record, and a follow-up task was created.');
        redirect('client.php?id=' . $cid);
    }

    if (isset($_POST['assign'])) {
        $assigneeId = (int)($_POST['assigned_to'] ?? 0);
        if ($assigneeId === 0) {
            try { $ref = assign_open_referral($id, null, (int)$user['id']); }
            catch (DomainException $e) { flash($e->getMessage(), 'error'); redirect('referrals.php?view=' . $id); }
            audit($user['id'], 'referral.assign', 'referral', $id, 'unassigned');
            flash('Referral unassigned.');
        } else {
            $u = db()->prepare('SELECT id, name, email, role, group_id FROM users WHERE id = ? AND is_active = 1');
            $u->execute([$assigneeId]);
            $assignee = $u->fetch();
            if ($assignee && !has_referrals_perm($assignee)) {
                // They could not open the referral, and the email would hand them its details.
                flash($assignee['name'] . ' does not have access to the referral queue, so the referral was not assigned. Choose someone with referral access.', 'error');
            } elseif ($assignee) {
                try { $ref = assign_open_referral($id, $assigneeId, (int)$user['id']); }
                catch (DomainException $e) { flash($e->getMessage(), 'error'); redirect('referrals.php?view=' . $id); }
                // The same trail the app leaves: who handed the referral (and its details) to whom.
                audit($user['id'], 'referral.assign', 'referral', $id, 'to #' . $assigneeId);
                if ((int)$assignee['id'] !== (int)$user['id'] && (int)($ref['assigned_to'] ?? 0) !== $assigneeId) {
                    notify_user((int)$assignee['id'], 'Referral assigned to you: ' . $ref['client_name'], 'referrals.php?view=' . $id);
                    // The email carries no names or other text typed into the public
                    // form (as the new-referral email): they are read after signing in.
                    send_app_mail($assignee['email'],
                        'A referral was assigned to you (#' . $id . ')',
                        "{$user['name']} assigned you referral #{$id}.\n\n"
                        . "The person's and the referrer's details are on the referrals page. Review it here: " . cfg('base_url') . '/referrals.php?view=' . $id . "\n");
                }
                flash('Referral assigned to ' . $assignee['name'] . '.');
            } else {
                flash('That staff member was not found.', 'error');
            }
        }
        redirect('referrals.php?view=' . $id);
    }

    if (isset($_POST['mark_reviewed'])) {
        $rv = db()->prepare("UPDATE referrals SET status = 'Reviewed' WHERE id = ? AND status = 'New'");
        $rv->execute([$id]);
        if ($rv->rowCount() === 1) {
            audit($user['id'], 'referral.review', 'referral', $id);
            flash('Referral marked as reviewed.');
        } else {
            // A stale page: someone else reviewed or converted it meanwhile.
            $cur = db()->prepare('SELECT status FROM referrals WHERE id = ?');
            $cur->execute([$id]);
            flash('Nothing was changed: this referral is no longer New (its status is now ' . ($cur->fetchColumn() ?: 'unknown') . ').', 'error');
        }
        redirect('referrals.php?view=' . $id);
    }

    if (isset($_POST['convert'])) {
        db()->beginTransaction();
        // Re-read under a row lock so a double submit can't create two clients.
        $lock = db()->prepare("SELECT * FROM referrals WHERE id = ? AND status <> 'Converted' FOR UPDATE");
        $lock->execute([$id]);
        $ref = $lock->fetch();
        if (!$ref) {
            db()->rollBack();
            flash('That referral was already converted.', 'error');
            redirect('referrals.php');
        }
        // The assignment may have changed while this request waited for the row lock.
        if (!referral_in_scope($ref, $user)) {
            db()->rollBack();
            flash('That referral is assigned to someone else.', 'error');
            redirect('referrals.php');
        }
        $isPreETS = referral_is_preets($ref);
        // Only a VR counselor's details become the client's counselor of record:
        // the counselor_email drives report delivery and the counselor portal,
        // so a family member's or self-referrer's email must not land there.
        $isCounselor = in_array($ref['referral_source'] ?? 'counselor', ['counselor', ''], true);
        // Scoped staff own what they convert, when they can open client records.
        // Otherwise the new client follows the referral's assignee (who also gets
        // the assessment task below), so a caseload-scoped assignee can open the
        // client the task points to.
        $clientAssignee = caseload_scoped() && can('clients') ? (int)$user['id'] : null;
        if ($clientAssignee === null && $ref['assigned_to'] !== null) {
            // ... only when that assignee can open client records too; otherwise
            // the client starts unassigned and the task goes to the converter (or,
            // when the converter cannot open client records either, stays
            // unassigned for staff who can).
            $au = db()->prepare('SELECT * FROM users WHERE id = ? AND is_active = 1');
            $au->execute([(int)$ref['assigned_to']]);
            $auRow = $au->fetch();
            $clientAssignee = $auRow && in_array('clients', resolve_perms($auRow)['perms'], true) ? (int)$auRow['id'] : null;
        }
        // The counselor's email was typed into the public form by whoever sent it.
        // It is kept on the client but unconfirmed (counselor_verified = 0: no
        // monthly progress reports, counselor portal or email filing use it) unless
        // the converter confirms it is genuine, as the app's conversion does; staff
        // confirm it later on the client's edit page.
        $counselorEmail = $isCounselor ? (trim((string)($ref['counselor_email'] ?? '')) ?: null) : null;
        $counselorConfirmed = $counselorEmail !== null && !empty($_POST['confirm_counselor_email']);
        $counselorEmailHeld = $counselorEmail !== null && !$counselorConfirmed;
        // The person's email receives session reminders: only one valid address is copied.
        $clientEmail = trim((string)($ref['client_email'] ?? ''));
        $clientEmailHeld = $clientEmail !== '' && !filter_var($clientEmail, FILTER_VALIDATE_EMAIL);
        // Stored as confirmed (owner decision, 2026-10-15): reminders, surveys and the
        // portal link use the address the referral gave, without a confirmation step.
        $clientEmailCopied = $clientEmail !== '' && !$clientEmailHeld;
        // A confirmed counselor address is stored as verified, so reports and the
        // counselor portal reach it without a second confirmation on the edit page.
        db()->prepare('INSERT INTO clients (name, dob, email, email_verified, phone, address, status, client_type, school, assigned_to, counselor_name, counselor_email, counselor_verified, counselor_phone, counselor_agency)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([
            $ref['client_name'], $ref['client_dob'], $clientEmailCopied ? $clientEmail : null, 1, $ref['client_phone'], $ref['client_address'],
            'Pending Assessment', $isPreETS ? 'Pre-ETS' : 'VR', $ref['school'] ?? null,
            $clientAssignee,
            $isCounselor ? $ref['counselor_name'] : null, $counselorEmail, $counselorConfirmed ? 1 : 0,
            $isCounselor ? $ref['counselor_phone'] : null, $isCounselor ? $ref['counselor_agency'] : null,
        ]);
        $clientId = (int)db()->lastInsertId();
        apply_referral_language($clientId, $ref);
        client_apply_state($clientId, $ref['client_state'] ?? null);   // 4.1.0: their state and its time zone   // Spanish referral form → Spanish client emails
        if (!empty($ref['guardian_name'])) {
            db()->prepare('UPDATE clients SET guardian_name = ?, guardian_phone = ? WHERE id = ?')
                ->execute([$ref['guardian_name'], $ref['guardian_phone'] ?? null, $clientId]);
        }
        record_status_change($clientId, null, 'Pending Assessment', $user['id'], 'Created from referral #' . $id);
        $intake = referral_intake_note($ref);
        db()->prepare('INSERT INTO client_notes (client_id, note_type, subject, body, author_id) VALUES (?,?,?,?,?)')->execute([
            $clientId, 'Intake', $intake['subject'], $intake['body'], $user['id'],
        ]);
        // The converted referral follows the client's owner, so it stops being an
        // "unassigned" row other scoped staff could still read.
        db()->prepare("UPDATE referrals SET status = 'Converted', client_id = ?, assigned_to = ? WHERE id = ?")->execute([$clientId, $clientAssignee, $id]);
        db()->commit();
        complete_task_by_key('ref-contact-' . $id, (int)$user['id']);
        // Same active-staff rule as the client: a deactivated assignee would
        // leave the task where no active instructor sees it.
        // A converter who cannot open client records never holds the task either:
        // it then stays unassigned, where staff with client access pick it up.
        add_task('Schedule initial assessment: ' . $ref['client_name'],
            'The referral was converted — get the first assessment on the calendar.',
            $clientId, null, date('Y-m-d', strtotime('+14 days')),
            $clientAssignee ?? (can('clients') ? (int)$user['id'] : null), (int)$user['id'], 'assess-' . $clientId);
        audit($user['id'], 'referral.convert', 'client', $clientId, 'from referral #' . $id
            . ($counselorConfirmed ? '; counselor email confirmed by converter' : ($counselorEmailHeld ? '; counselor email kept unconfirmed' : ''))
            . ($clientEmailCopied ? '; client email copied' : ''));
        flash('Referral converted — client record created with an intake note.'
            . ($counselorEmailHeld ? ' The counselor\'s email from the referral form is marked not yet confirmed: no progress report, counselor portal link or email filing uses it until someone confirms it on the client\'s edit page.' : '')
            . ($clientEmailHeld ? ' The email given for the person is not one valid address, so it was left off the client record (it stays on the referral).' : '')
            . ($clientAssignee === null && !can('clients') ? ' Your account cannot open client records, so the new client and its assessment task are unassigned for a colleague with client access to pick up.' : ''));
        // Staff without client-record access stay on the referral they converted
        // (a caseload-scoped converter can no longer open it once it is converted
        // into a client they do not hold, so they go back to the queue).
        redirect(can('clients') ? 'client.php?id=' . $clientId
            : (caseload_scoped() ? 'referrals.php' : 'referrals.php?view=' . $id));
    }
    redirect('referrals.php');
}

$viewId = (int)($_GET['view'] ?? 0);
$view = null;
if ($viewId) {
    $stmt = db()->prepare('SELECT r.*, u.name AS assigned_name FROM referrals r LEFT JOIN users u ON u.id = r.assigned_to WHERE r.id = ?');
    $stmt->execute([$viewId]);
    $view = $stmt->fetch();
    if ($view && !referral_in_scope($view, $user)) {
        flash('That referral is assigned to someone else.', 'error');
        redirect('referrals.php');
    }
}

/* Possible duplicates for the viewed referral: existing clients or other open
   referrals sharing a similar name, or the same phone/email. */
$dupes = [];
$hiddenDupes = 0;
if ($view) {
    $lastName = trim((string)strrchr(' ' . trim($view['client_name']), ' '));
    // An empty name must not turn the LIKE into "match everyone", and the name
    // (typed by a member of the public) is matched literally: % and _ in it are
    // not wildcards.
    $nameLike = $lastName !== '' ? '%' . strtr($lastName, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%' : '';
    // Every possible match is counted, but names are shown only for records the
    // viewer could open anyway: clients need the clients permission (and, for
    // caseload-scoped staff, their own caseload); referrals must be in scope.
    // The rest are reported as a count, so the warning is never lost.
    $c = db()->prepare("SELECT id, name, status, assigned_to FROM clients WHERE (name LIKE ? ESCAPE '!'
        OR (phone IS NOT NULL AND phone <> '' AND phone = ?)
        OR (email IS NOT NULL AND email <> '' AND email = ?)) LIMIT 20");
    $c->execute([$nameLike, $view['client_phone'] ?? '', $view['client_email'] ?? '']);
    foreach ($c->fetchAll() as $d) {
        $visible = can('clients') && (!caseload_scoped() || (int)($d['assigned_to'] ?? 0) === (int)$user['id']);
        if (!$visible) { $hiddenDupes++; continue; }
        if (count($dupes) < 5) $dupes[] = ['label' => 'Existing client: ' . $d['name'] . ' (' . $d['status'] . ')', 'url' => 'client.php?id=' . (int)$d['id'], 'sr' => 'client #' . (int)$d['id'], 'client_id' => (int)$d['id'], 'name' => $d['name']];
    }
    $r2 = db()->prepare("SELECT id, client_name, status, created_at, assigned_to, client_id FROM referrals WHERE id <> ?
        AND (client_name LIKE ? ESCAPE '!' OR (client_phone IS NOT NULL AND client_phone <> '' AND client_phone = ?)
             OR (client_email IS NOT NULL AND client_email <> '' AND client_email = ?)) LIMIT 20");
    $r2->execute([(int)$view['id'], $nameLike, $view['client_phone'] ?? '', $view['client_email'] ?? '']);
    $refShown = 0;
    foreach ($r2->fetchAll() as $d) {
        if (!referral_in_scope($d, $user)) { $hiddenDupes++; continue; }
        if ($refShown++ < 5) $dupes[] = ['label' => 'Other referral: ' . $d['client_name'] . ' (' . $d['status'] . ', ' . fmt_date($d['created_at']) . ')', 'url' => 'referrals.php?view=' . (int)$d['id'], 'sr' => 'referral #' . (int)$d['id']];
    }
}

// Only staff who can work the referral queue are offered as assignees.
$staff = array_values(array_filter(db()->query('SELECT id, name, role, group_id FROM users WHERE is_active = 1 ORDER BY name')->fetchAll(), 'has_referrals_perm'));

$who = $_GET['who'] ?? '';
// Open (New or Reviewed) referrals by default, so finished work cannot push
// waiting referrals off the list.
$statusShow = in_array($_GET['status'] ?? '', ['open', 'converted', 'closed', 'all'], true) ? $_GET['status'] : 'open';
$sql = 'SELECT r.*, u.name AS assigned_name FROM referrals r LEFT JOIN users u ON u.id = r.assigned_to WHERE 1=1';
$params = [];
if ($statusShow === 'open') $sql .= " AND r.status IN ('New','Reviewed')";
elseif ($statusShow === 'converted') $sql .= " AND r.status = 'Converted'";
elseif ($statusShow === 'closed') $sql .= " AND r.status = 'Closed'";
if ($who === 'me') { $sql .= ' AND r.assigned_to = ?'; $params[] = $user['id']; }
elseif ($who === 'unassigned') { $sql .= ' AND r.assigned_to IS NULL'; }
if (caseload_scoped()) {
    // Same rule as referral_in_scope(): a converted referral shows only to the client's current instructor.
    $sql .= " AND ((r.status <> 'Converted' AND (r.assigned_to = ? OR r.assigned_to IS NULL))
        OR (r.status = 'Converted' AND EXISTS (SELECT 1 FROM clients c WHERE c.id = r.client_id AND c.assigned_to = ?)))";
    array_push($params, $user['id'], $user['id']);
}
// The list shows at most 200 rows; count every match so the page can say so.
$cnt = db()->prepare('SELECT COUNT(*) FROM (' . $sql . ') matched');
$cnt->execute($params);
$matchCount = (int)$cnt->fetchColumn();
$sql .= ' ORDER BY r.created_at DESC LIMIT 200';
$stmt = db()->prepare($sql);
$stmt->execute($params);
$referrals = $stmt->fetchAll();

// An open referral is named in the title, so tabs opened from notifications differ.
page_header($view ? 'Referral #' . (int)$view['id'] . ': ' . $view['client_name'] . ' — Referrals' : 'Referrals', $user, 'referrals');
?>
<h1>Referrals</h1>
<p class="muted">Submitted through the <a href="community_referral.php">public referral form</a>. · <a class="tap" href="counselors.php">Manage counselor portal access</a></p>

<?php if ($view): ?>
<section class="card" aria-labelledby="view-heading">
  <h2 id="view-heading">Referral #<?= (int)$view['id'] ?>: <?= h($view['subject']) ?> <?= status_badge($view['status']) ?></h2>
  <?php if ($dupes || $hiddenDupes): ?>
  <div class="alert alert-error" role="alert">
    <strong>Possible duplicate — check before converting:</strong>
    <ul style="margin:.4rem 0 0;">
      <?php foreach ($dupes as $d): ?>
      <li><a href="<?= h($d['url']) ?>"><?= h($d['label']) ?><span class="visually-hidden">, <?= h($d['sr']) ?></span></a>
        <?php if (!empty($d['client_id']) && in_array($view['status'], ['New', 'Reviewed'], true)): // a repeat referral joins the record already on file ?>
        <form method="post" action="referrals.php" class="inline-form"><?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= (int)$view['id'] ?>"><input type="hidden" name="client_id" value="<?= (int)$d['client_id'] ?>">
          <button type="submit" name="attach_referral" value="1" class="btn btn-secondary btn-sm"
            onclick="return confirm(<?= h(json_encode('Attach this referral to ' . $d['name'] . '\'s existing record instead of creating a new one? A closed record is reopened to Pending Assessment.', JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP)) ?>);">Attach to this client<span class="visually-hidden">: <?= h($d['name']) ?></span></button>
        </form>
        <?php endif; ?></li>
      <?php endforeach; ?>
      <?php if ($hiddenDupes): ?>
      <li><?= $hiddenDupes ?> possible match<?= $hiddenDupes === 1 ? '' : 'es' ?> among records you cannot open. Ask an administrator to check before converting, so the person does not end up with two records.</li>
      <?php endif; ?>
    </ul>
  </div>
  <?php endif; ?>
  <dl class="props">
    <dt>Reference</dt><dd><?= h(referral_ref((int)$view['id'])) ?></dd>
    <dt>Received</dt><dd><?= fmt_datetime($view['created_at']) ?></dd>
    <?php if ($view['status'] === 'Closed'): ?>
    <dt>Closed</dt><dd><?= fmt_datetime($view['closed_at']) ?>: <?= h((string)$view['closed_reason']) ?><?= $view['closed_note'] ? ' (' . h($view['closed_note']) . ')' : '' ?>.
      <?= $view['outcome_emailed_at'] ? 'The referrer was emailed the outcome on ' . fmt_datetime($view['outcome_emailed_at']) . '.' : 'No outcome email was sent.' ?></dd>
    <?php endif; ?>
    <dt>Reason</dt><dd><?= h($view['reason']) ?></dd>
    <?php if (!empty($view['referral_purpose'])): ?>
    <dt>Referral purpose</dt><dd><?= h($view['referral_purpose']) ?></dd>
    <dt>Service requested</dt><dd><?= h(($view['requested_service'] ?? '') ?: 'None selected') ?></dd>
    <dt>Specific needs</dt><dd><?= h(($view['specific_needs'] ?? '') ?: 'None selected') ?></dd>
    <?php else: ?>
    <dt>Original referral type</dt><dd><?= h($view['note_type']) ?></dd>
    <?php endif; ?>
    <?php $src = $view['referral_source'] ?? 'counselor';
    // Only one valid address becomes a mailto link: stored '?cc=' or list text must not add recipients.
    $refMail = filter_var((string)$view['counselor_email'], FILTER_VALIDATE_EMAIL)
        ? '<a href="mailto:' . h($view['counselor_email']) . '">' . h($view['counselor_email']) . '</a>' : h($view['counselor_email']); ?>
    <?php if ($src === 'self'): ?>
    <dt>Source</dt><dd><strong>Self-referral</strong></dd>
    <dt>Income</dt><dd><?= h($view['income_bracket'] ?? '—') ?></dd>
    <?php if (!empty($view['vr_case_open'])): // 4.2.0 ?><dt>Open VR case</dt><dd><?= h(VR_CASE_ANSWERS[$view['vr_case_open']] ?? $view['vr_case_open']) ?></dd><?php endif; ?>
    <dt>Fee determination</dt><dd><strong><?= $view['quoted_fee'] === null ? (($view['vr_case_open'] ?? '') === 'yes' ? 'The VR rate, billed to their VR agency' : 'Not quoted') : ((float)$view['quoted_fee'] > 0 ? '$' . number_format((float)$view['quoted_fee'], 2) . ' per hour of training' : 'Free') ?></strong></dd>
    <?php elseif ($src !== 'counselor'): ?>
    <dt>Source</dt><dd><strong><?= h(ucfirst($src)) ?> referral</strong></dd>
    <?php if (!empty($view['vr_case_open']) || $view['quoted_fee'] !== null): // asked by the Pricing rules (4.2.0) ?>
    <dt>Open VR case</dt><dd><?= h(VR_CASE_ANSWERS[$view['vr_case_open'] ?? ''] ?? 'Not asked') ?></dd>
    <dt>Fee determination</dt><dd><strong><?= $view['quoted_fee'] === null ? (($view['vr_case_open'] ?? '') === 'yes' ? 'The VR rate, billed to their VR agency' : 'Not quoted') : ((float)$view['quoted_fee'] > 0 ? '$' . number_format((float)$view['quoted_fee'], 2) . ' per hour of training' : 'Free') ?></strong><?= $view['income_bracket'] ? ' (' . h($view['income_bracket']) . ')' : '' ?></dd>
    <?php endif; ?>
    <dt>Referred by</dt><dd><?= h($view['counselor_name']) ?> — <?= h($view['counselor_agency']) ?><br>
      <?= $refMail ?> · <?= h($view['counselor_phone']) ?></dd>
    <?php if ($src === 'school'): ?>
      <dt>School</dt><dd><?= h($view['school'] ?? '—') ?><?= $view['grade_level'] ? ' · Grade ' . h($view['grade_level']) : '' ?></dd>
      <dt>Parent/guardian</dt><dd><?= h($view['guardian_name'] ?? '—') ?><?= $view['guardian_phone'] ? ' · ' . h($view['guardian_phone']) : '' ?></dd>
      <?php if (!empty($view['preets_services'])): ?><dt>Pre-ETS services</dt><dd><?= h($view['preets_services']) ?></dd><?php endif; ?>
    <?php endif; ?>
    <?php if ($src === 'family'): ?>
      <dt>Relationship</dt><dd><?= h($view['relationship'] ?? '—') ?></dd>
      <dt>Contact first</dt><dd><?= ($view['contact_first'] ?? '') === 'referrer' ? 'The referrer' : 'The client' ?></dd>
    <?php endif; ?>
    <dt>Consent</dt><dd><?= $view['consent_confirmed'] ? 'Client/guardian aware and agreed to contact' : ($src === 'school' ? 'Via guardian contact' : '<strong class="field-error">Not confirmed</strong>') ?></dd>
    <?php else: ?>
    <dt>Counselor</dt><dd><?= h($view['counselor_name']) ?> — <?= h($view['counselor_agency']) ?><br>
      <?= $refMail ?> · <?= h($view['counselor_phone']) ?></dd>
    <?php endif; ?>
    <dt>Client</dt><dd><?= h($view['client_name']) ?><?= $view['client_dob'] ? ' (date of birth ' . fmt_date($view['client_dob']) . ')' : '' ?><br>
      <?= trim((string)($view['client_phone'] ?? '')) !== '' ? tel_link($view['client_phone']) : '' ?> <?= h($view['client_email'] ?? '') ?><br><?= trim((string)($view['client_address'] ?? '')) !== '' ? directions_link($view['client_address']) : '' ?><?= !empty($view['client_state']) ? '<br>' . h(US_STATES[$view['client_state']] ?? $view['client_state']) : '' ?></dd>
  </dl>
  <h3>Referral notes</h3>
  <p style="white-space: pre-wrap;"><?= h($view['body']) ?></p>

  <h3>Assignment</h3>
  <?php if ($view['status'] === 'Converted'): ?>
  <p>This referral follows the assigned instructor on the client record.</p>
  <?php else: ?>
  <p>Currently assigned to: <strong><?= h($view['assigned_name'] ?? 'Unassigned') ?></strong></p>
  <form method="post" action="referrals.php" class="inline-form" onsubmit="return confirm('Assign this referral? Its details are emailed to the chosen instructor straight away.');">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= (int)$view['id'] ?>">
    <label for="assigned_to" class="visually-hidden">Assign this referral to</label>
    <select id="assigned_to" name="assigned_to">
      <option value="0">— Unassigned —</option>
      <?php foreach ($staff as $s): ?>
      <option value="<?= (int)$s['id'] ?>" <?= (int)($view['assigned_to'] ?? 0) === (int)$s['id'] ? 'selected' : '' ?>><?= h($s['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <button type="submit" name="assign" value="1" class="btn btn-secondary btn-sm">Assign</button>
  </form>

  <?php endif; ?>

  <div class="page-actions">
    <?php if ($view['status'] === 'New'): ?>
    <form method="post" action="referrals.php" class="inline-form" onsubmit="return confirm('Mark this referral as reviewed? This cannot be undone.');"><?= csrf_field() ?>
      <input type="hidden" name="id" value="<?= (int)$view['id'] ?>">
      <button type="submit" name="mark_reviewed" value="1" class="btn btn-secondary">Mark as reviewed</button>
    </form>
    <?php endif; ?>
    <?php if ($view['status'] === 'Closed'): ?>
    <form method="post" action="referrals.php" class="inline-form"><?= csrf_field() ?>
      <input type="hidden" name="id" value="<?= (int)$view['id'] ?>">
      <button type="submit" name="reopen_referral" value="1" class="btn btn-secondary">Reopen this referral</button>
    </form>
    <?php elseif ($view['status'] !== 'Converted'): ?>
    <form method="post" action="referrals.php" class="inline-form"><?= csrf_field() ?>
      <input type="hidden" name="id" value="<?= (int)$view['id'] ?>">
      <?php if (in_array($view['referral_source'] ?? 'counselor', ['counselor', ''], true) && trim((string)$view['counselor_email']) !== ''): ?>
      <?php // Anyone can send the public form as a "counselor": the address is kept
      // unconfirmed, and used for reports only once someone has checked it. ?>
      <div class="field">
        <label><input type="checkbox" name="confirm_counselor_email" value="1" aria-describedby="confirm-ce-hint"> I have confirmed that <?= h($view['counselor_email']) ?> belongs to this person's VR counselor</label>
        <p class="hint" id="confirm-ce-hint">This email was typed into the public referral form and has not been verified. Once confirmed, it receives monthly progress reports and can be invited to the counselor portal. Check it against the authorization paperwork or the agency first; leave this unticked to keep it on the client record marked not yet confirmed, and confirm it later on the client's edit page.</p>
      </div>
      <?php endif; ?>
      <button type="submit" name="convert" value="1" class="btn btn-primary"
        onclick="return confirm(<?= h(json_encode('Create a client record for ' . $view['client_name'] . ' from this referral? This cannot be undone.', JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP)) ?>);">Convert to client<span class="visually-hidden">: <?= h($view['client_name']) ?></span></button>
    </form>
    <?php elseif (can('clients') && $view['client_id']): ?>
      <a class="btn btn-primary" href="client.php?id=<?= (int)$view['client_id'] ?>">Open client record</a>
    <?php endif; ?>
  </div>
  <?php if (in_array($view['status'], ['New', 'Reviewed'], true)): // not becoming a client: closed with a reason ?>
  <details<?= has_field_error('close_reason') || has_field_error('close_note') ? ' open' : '' ?>>
    <summary id="close-h">Close without creating a client</summary>
    <form method="post" action="referrals.php" class="stacked" novalidate><?= csrf_field() ?>
      <input type="hidden" name="id" value="<?= (int)$view['id'] ?>">
      <?= form_error_summary(['close_reason' => 'Reason', 'close_note' => 'Note']) ?>
      <div class="field"><label for="close_reason">Reason</label>
        <select id="close_reason" name="close_reason" <?= field_aria('close_reason') ?>><option value="">Choose a reason</option>
          <?php foreach (array_keys(cms_list('referral_close_reasons')) as $cr): ?><option value="<?= h($cr) ?>"<?= old_in('close_reason') === $cr ? ' selected' : '' ?>><?= h($cr) ?></option><?php endforeach; ?>
        </select><?= field_error('close_reason') ?></div>
      <div class="field"><label for="close_note">Note for the record (required for Other; never emailed)</label>
        <input type="text" id="close_note" name="close_note" maxlength="255" value="<?= h(old_in('close_note')) ?>" <?= field_aria('close_note') ?>><?= field_error('close_note') ?></div>
      <p class="hint">The referrer is emailed the outcome with the reference number and a general reason, and no client details.</p>
      <button type="submit" name="close_referral" value="1" class="btn btn-danger">Close referral</button>
    </form>
  </details>
  <?php endif; ?>
</section>
<?php endif; ?>

<form method="get" action="referrals.php" class="page-actions" role="search" aria-label="Filter referrals">
  <label for="ref-status">Status</label>
  <select id="ref-status" name="status">
    <option value="open" <?= $statusShow === 'open' ? 'selected' : '' ?>>Open (New or Reviewed)</option>
    <option value="converted" <?= $statusShow === 'converted' ? 'selected' : '' ?>>Converted</option>
    <option value="closed" <?= $statusShow === 'closed' ? 'selected' : '' ?>>Closed without a client</option>
    <option value="all" <?= $statusShow === 'all' ? 'selected' : '' ?>>All statuses</option>
  </select>
  <label for="who">Show</label>
  <select id="who" name="who">
    <option value="" <?= $who === '' ? 'selected' : '' ?>>All referrals</option>
    <option value="me" <?= $who === 'me' ? 'selected' : '' ?>>Assigned to me</option>
    <option value="unassigned" <?= $who === 'unassigned' ? 'selected' : '' ?>>Unassigned</option>
  </select>
  <button type="submit" class="btn btn-secondary btn-sm">Apply filters</button>
</form>

<?php if ($matchCount > count($referrals)): ?>
<p class="alert alert-error">Showing the newest <?= count($referrals) ?> of <?= number_format($matchCount) ?> matching referrals. Narrow the filters to find older ones.</p>
<?php endif; ?>
<div class="table-scroll" tabindex="0" role="region" aria-labelledby="referrals-cap">
<table>
  <caption class="visually-hidden" id="referrals-cap"><?= ['open' => 'Open referrals', 'converted' => 'Converted referrals', 'closed' => 'Closed referrals', 'all' => 'Referrals'][$statusShow] ?>, newest first<?= $matchCount > count($referrals) ? ' (newest ' . count($referrals) . ' of ' . number_format($matchCount) . ')' : '' ?></caption>
  <thead><tr>
    <th scope="col">Received</th><th scope="col">Client</th><th scope="col">Reason</th>
    <th scope="col">Referred by</th><th scope="col">Assigned to</th><th scope="col">Status</th><th scope="col"><span class="visually-hidden">Actions</span></th>
  </tr></thead>
  <tbody>
  <?php if (!$referrals): ?>
    <tr><td colspan="7" class="muted">No referrals match this filter.</td></tr>
  <?php endif; ?>
  <?php foreach ($referrals as $r): ?>
    <tr>
      <td><?= fmt_datetime($r['created_at']) ?></td>
      <th scope="row"><?= h($r['client_name']) ?></th>
      <td><?= h($r['reason']) ?></td>
      <td><?php $rsrc = $r['referral_source'] ?? 'counselor'; ?>
          <?php if ($rsrc === 'self'): ?>
            <span class="badge badge-pending">Self-referral</span><br>
            <span class="muted"><?= $r['quoted_fee'] === null ? (($r['vr_case_open'] ?? '') === 'yes' ? 'VR rate' : 'Not quoted') : ((float)$r['quoted_fee'] > 0 ? '$' . number_format((float)$r['quoted_fee'], 2) . ' per hour' : 'Free') ?> · <?= h($r['income_bracket'] ?? '') ?></span>
          <?php elseif ($rsrc !== 'counselor'): ?>
            <span class="badge badge-active"><?= h(ucfirst($rsrc)) ?></span><br>
            <?= h($r['counselor_name']) ?> <span class="muted">· <?= h($r['counselor_agency']) ?></span>
          <?php else: ?>
            <?= h($r['counselor_name']) ?><br><span class="muted"><?= h($r['counselor_agency']) ?></span>
          <?php endif; ?></td>
      <td><?= h($r['assigned_name'] ?? '—') ?></td>
      <td><?= status_badge($r['status']) ?></td>
      <td><a class="btn btn-secondary btn-sm" href="referrals.php?view=<?= (int)$r['id'] ?>">View<span class="visually-hidden"> referral from <?= h($r['counselor_name']) ?> for <?= h($r['client_name']) ?>, received <?= h(substr((string)$r['created_at'], 0, 10)) ?>, referral #<?= (int)$r['id'] ?></span></a></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php page_footer();
