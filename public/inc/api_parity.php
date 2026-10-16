<?php
declare(strict_types=1);
/*
 * App parity actions (3.0.22): tasks, alerts, client portal requests, training
 * tracks, guardian consent, the intake pipeline, status history and the invoice
 * list; session check-in (3.0.28). Included by api.php (API_PARITY_ACTIONS) after sign-in and the
 * $API_ACTION_PERMS check, in api.php's scope ($action, $input, $userId, out(),
 * fail(), can(), caseload_scoped(), api_require_client(), db(), audit()).
 *
 * Each action follows its website page: the shared helpers are called where the
 * page uses one (inc/tasks.php, inc/task_filters.php, complete_task_by_key(),
 * email_template(), send_app_mail(), track_program_label(), unbilled_totals()),
 * and logic that lives inline in a page is repeated here line for line, with the
 * page named, so permissions, caseload scope, validation, audit entries and email
 * stay the same. Every branch ends with out() or fail().
 */

require_once __DIR__ . '/task_filters.php';

$parityUser = ['id' => $userId];
$parityPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
$parityRequirePost = function () use ($parityPost): void {
    if (!$parityPost) fail('Use POST for changes.', 405);
};

/** One task row as the app reads it (tasks.php's list query, alias t). */
function parity_task_row(array $t): array {
    return [
        'id' => (int)$t['id'],
        'title' => (string)$t['title'],
        'details' => $t['details'] !== null && $t['details'] !== '' ? (string)$t['details'] : null,
        'client_id' => $t['client_id'] !== null ? (int)$t['client_id'] : null,
        'client_name' => $t['client_name'],
        'referral_id' => $t['referral_id'] !== null ? (int)$t['referral_id'] : null,
        'referral_name' => $t['referral_id'] !== null ? ($t['referral_name'] ?? ('#' . (int)$t['referral_id'])) : null,
        'due_date' => $t['due_date'],
        // Days until due (negative: overdue), by the server's date, as tasks.php's due_note().
        'days_left' => $t['due_date'] !== null ? (int)$t['days_left'] : null,
        'status' => (string)$t['status'],
        'assigned_to' => $t['assigned_to'] !== null ? (int)$t['assigned_to'] : null,
        'assigned_name' => $t['assigned_name'],
        'automatic' => !empty($t['auto_key']),
        'age_days' => (int)$t['age_days'],
        'created_at' => (string)$t['created_at'],
        'done_at' => $t['done_at'],
        // As the page's links: a client or referral is opened only with that permission.
        'can_open_client' => $t['client_id'] !== null && can('clients'),
        'can_open_referral' => $t['referral_id'] !== null && can('referrals'),
    ];
}

/** A task the user can see (task_visibility_sql), with the page's joined names; null otherwise. */
function parity_visible_task(int $id, array $user): ?array {
    $params = [];
    $visibility = task_visibility_sql($user, $params);
    $q = db()->prepare("SELECT t.*, c.name AS client_name, r.client_name AS referral_name, u.name AS assigned_name,
            DATEDIFF(t.due_date, CURDATE()) AS days_left, DATEDIFF(CURDATE(), DATE(t.created_at)) AS age_days
        FROM tasks t LEFT JOIN clients c ON c.id = t.client_id LEFT JOIN referrals r ON r.id = t.referral_id
        LEFT JOIN users u ON u.id = t.assigned_to WHERE t.id = ? AND " . $visibility);
    $q->execute(array_merge([$id], $params));
    return $q->fetch() ?: null;
}

/** portal_requests.php's statuses and type labels. */
const PARITY_PREQ_STATUSES = ['New', 'In Progress', 'Done', 'Declined'];
const PARITY_PREQ_TYPES = ['appointment' => 'Appointment', 'reschedule' => 'Reschedule', 'cancel' => 'Cancellation', 'device' => 'Device help', 'other' => 'Request'];

/** client.php's guardian consent state for one client (Demographics). */
function parity_consent_state(int $clientId): array {
    $consents = db()->prepare('SELECT * FROM consent_forms WHERE client_id = ? ORDER BY sent_at DESC, id DESC');
    $consents->execute([$clientId]);
    $consents = $consents->fetchAll();
    $consentDays = (int)(setting('consent_validity_days') ?: 365);
    $latestSigned = null; $pendingConsent = null;
    foreach ($consents as $f) {
        if ($f['signed_at'] && ($latestSigned === null || $f['signed_at'] > $latestSigned['signed_at'])) $latestSigned = $f;
        if (!$f['signed_at'] && $pendingConsent === null) $pendingConsent = $f;
    }
    $consentExpires = $latestSigned ? date('Y-m-d', strtotime($latestSigned['signed_at'] . ' +' . $consentDays . ' days')) : null;
    $consentExpired = $consentExpires !== null && $consentExpires < date('Y-m-d');
    $consentWithdrawn = $latestSigned !== null && !empty($latestSigned['withdrawn_at']);
    $canRequest = $consentWithdrawn || $consentExpires === null || $consentExpires <= date('Y-m-d', strtotime('+30 days')) || $pendingConsent !== null;
    $state = $latestSigned === null ? ($pendingConsent ? 'Requested' : 'None')
        : ($consentWithdrawn ? 'Withdrawn' : ($consentExpired ? 'Expired' : 'Signed'));
    return [
        'state' => $state,
        'signed_name' => $latestSigned['signed_name'] ?? null,
        'signed_at' => $latestSigned['signed_at'] ?? null,
        'withdrawn_at' => $consentWithdrawn ? $latestSigned['withdrawn_at'] : null,
        'expires' => $consentExpires,
        'pending_sent_at' => $pendingConsent['sent_at'] ?? null,
        'can_request' => $canRequest,
        // The page's summary wording for the request form.
        'request_label' => $pendingConsent ? 'Resend the guardian consent request by email'
            : ($latestSigned ? 'Request a new guardian consent by email' : 'Request guardian consent by email'),
    ];
}

/** The form choices of track_edit.php for a client (its bundles by client type). */
function parity_track_choices(array $client, ?array $track): array {
    $clientType = $client['client_type'] ?? 'VR';
    $bundles = [];
    foreach (bundles_for($clientType) as $b) {
        $bundles[] = ['id' => (int)$b['id'], 'name' => $b['name'], 'categories' => bundle_categories($b), 'available' => true];
    }
    // The track's own bundle when it is no longer offered stays listed (not choosable anew).
    if ($track && !empty($track['bundle_id']) && !in_array((int)$track['bundle_id'], array_column($bundles, 'id'), true)
        && ($kept = bundle((int)$track['bundle_id']))) {
        array_unshift($bundles, ['id' => (int)$kept['id'], 'name' => $kept['name'] . ' (' . ($kept['active'] ? 'not offered for ' . $clientType . ' clients' : 'retired') . ' — kept on this track until you choose another)',
            'categories' => bundle_categories($kept), 'available' => false]);
    }
    return [
        'service_types' => SERVICE_TYPES,
        'statuses' => TRACK_STATUSES,
        'cadences' => [['value' => 'monthly', 'label' => 'Monthly (default)'], ['value' => 'biweekly', 'label' => 'Every two weeks'],
            ['value' => 'weekly', 'label' => 'Weekly'], ['value' => 'none', 'label' => 'Off — send manually only']],
        'rsa_categories' => rsa_all_categories(),
        'bundles' => $bundles,
        'client_type' => $clientType,
    ];
}

/** A training track's editable values as the app's form reads them. */
function parity_track_values(array $t): array {
    return [
        'id' => (int)$t['id'], 'client_id' => (int)$t['client_id'], 'title' => (string)$t['title'],
        'service_type' => (string)($t['service_type'] ?? 'Assistive Technology'), 'rsa_category' => $t['rsa_category'],
        'bundle_id' => !empty($t['bundle_id']) ? (int)$t['bundle_id'] : null, 'status' => (string)$t['status'],
        'report_cadence' => (string)($t['report_cadence'] ?? 'monthly'), 'auth_number' => (string)($t['auth_number'] ?? ''),
        'funding_agency' => (string)($t['funding_agency'] ?? ''), 'authorized_hours' => (float)$t['authorized_hours'],
        'hourly_rate' => (float)$t['hourly_rate'], 'mileage_rate' => (float)($t['mileage_rate'] ?? 0),
        'start_date' => $t['start_date'], 'expiration_date' => $t['expiration_date'],
        'program' => track_program_label($t),
    ];
}

/** A number typed in the app, read as track_edit.php reads its fields ((float) of the text);
 *  something that is not a number at all is refused rather than taken as 0. */
function parity_track_number(array $src, string $key, string $label, ?float $default): ?float {
    if (!array_key_exists($key, $src)) return $default;
    $raw = $src[$key];
    if (is_string($raw)) $raw = trim($raw);
    if ($raw === '' || $raw === null) return 0.0;
    if (is_bool($raw) || !is_numeric($raw)) fail($label . ' must be a number.', 422);
    return (float)$raw;
}

/** workflows.php's waitlist statuses. */
const PARITY_WAITLIST_STATUSES = ['Waiting', 'Contacted', 'Ready', 'Closed'];

/** One waitlist row as the page lists it (a referral with its entry, or none yet). */
function parity_waitlist_item(array $r): array {
    return [
        'referral_id' => (int)$r['id'], 'client_name' => (string)$r['client_name'],
        'waitlisted' => $r['referral_id'] !== null,
        // The page shows "Not waitlisted" for a referral with no entry yet.
        'status' => $r['status'] ?? 'Not waitlisted',
        'received_at' => substr((string)$r['received_at'], 0, 10),
        'followup_date' => $r['followup_date'] ?? null,
        // 'notes' is the entry's free text (its Client availability field), the app's name for it.
        'notes' => (string)($r['availability'] ?? ''), 'availability' => (string)($r['availability'] ?? ''),
        'expertise' => (string)($r['expertise'] ?? ''), 'languages' => (string)($r['languages'] ?? ''), 'areas' => (string)($r['areas'] ?? ''),
        'modality' => (string)($r['modality'] ?? 'Both'), 'version' => (int)($r['version'] ?? 0),
    ];
}

switch ($action) {

/* ---------- Tasks (tasks.php, inc/tasks.php, inc/task_filters.php) ---------- */

case 'tasks': {
    $filters = task_filters($_GET);
    $params = [];
    $visibility = task_visibility_sql($parityUser, $params);
    $sql = "SELECT t.*, c.name AS client_name, r.client_name AS referral_name, u.name AS assigned_name,
            DATEDIFF(t.due_date, CURDATE()) AS days_left, DATEDIFF(CURDATE(), DATE(t.created_at)) AS age_days
        FROM tasks t
        LEFT JOIN clients c ON c.id = t.client_id
        LEFT JOIN referrals r ON r.id = t.referral_id
        LEFT JOIN users u ON u.id = t.assigned_to
        WHERE " . $visibility;
    $sql .= task_filter_sql($filters, $params);
    $sql .= " ORDER BY t.status = 'Done', t.due_date IS NULL, t.due_date, t.created_at LIMIT 300";
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    $isAdmin = can('admin');
    out(['ok' => true, 'tasks' => array_map('parity_task_row', $rows), 'filters' => $filters,
        'truncated' => count($rows) === 300, 'today' => date('Y-m-d'),
        // As the page's form: only an administrator chooses who a task is for; a client is
        // linked only with the clients permission.
        'can_assign' => $isAdmin, 'can_link_client' => can('clients'),
        'staff' => $isAdmin ? array_map(fn($s) => ['id' => (int)$s['id'], 'name' => $s['name']],
            db()->query('SELECT id, name FROM users WHERE is_active = 1 ORDER BY name')->fetchAll()) : []]);
}

case 'save_task': {
    $parityRequirePost();
    $isAdmin = can('admin');
    $taskId = (int)($input['id'] ?? 0);
    // tasks.php's rules: a title is required, a due date must be a real day from 2000
    // to five years ahead (api_date's range) or blank. The column holds 190 characters;
    // the app is told rather than having its text cut.
    $title = api_text($input, 'title', 190, 'The task title');
    if ($title === '') fail('The task needs a title: say what needs doing.', 422);
    $due = api_date($input, 'due_date', 'Due date');
    $details = api_text($input, 'details', API_TEXT_BYTES, 'Details', true);
    $clientId = (int)($input['client_id'] ?? 0) ?: null;
    // On an edit, a left-out client keeps the task's link, and an unchanged link is not
    // re-checked (a task delegated about another caseload's client stays editable).
    $keepLink = false;
    if ($taskId > 0) {
        $cur = db()->prepare('SELECT client_id FROM tasks WHERE id = ?');
        $cur->execute([$taskId]);
        $currentClient = $cur->fetchColumn();
        $currentClient = $currentClient === false || $currentClient === null ? null : (int)$currentClient;
        if (!array_key_exists('client_id', $input)) $clientId = $currentClient;
        $keepLink = $clientId !== null && $clientId === $currentClient;
    }
    if ($clientId !== null && !$keepLink) {
        // Linking a task to a client reveals that client (tasks.php: require_perm + scope check).
        if (!can('clients')) fail('Your account does not have access to client records, so a task cannot be linked to a client.', 403);
        api_require_client($clientId, $userId);
    }
    $assignee = (int)($input['assigned_to'] ?? 0) ?: null;
    if (!$isAdmin) $assignee = $userId; // non-admins assign to themselves, as on the website
    elseif ($assignee !== null) {
        $au = db()->prepare('SELECT id FROM users WHERE id = ? AND is_active = 1');
        $au->execute([$assignee]);
        if (!$au->fetchColumn()) fail('Choose an active staff member to assign the task to.', 422);
    }
    if ($taskId === 0) {
        if (!add_task($title, $details !== '' ? $details : null, $clientId, null, $due, $assignee, $userId)) {
            fail('The task could not be saved. Please try again.', 500);
        }
        $newId = (int)db()->lastInsertId();
        audit($userId, 'task.add', 'task', null, $title);
        out(['ok' => true, 'id' => $newId, 'message' => 'Task added.']);
    }
    // Editing (the app's addition: the website adds and completes tasks but has no edit
    // form): only an open task the user can see under the website's visibility rule, and
    // not an automatic one, whose title and due date the system set and which completes
    // itself. A non-administrator keeps the task's assignment as it is.
    $task = parity_visible_task($taskId, $parityUser);
    if (!$task) fail('That task no longer exists or is not yours to change.', 404);
    if ($task['status'] !== 'Open') fail('Only an open task can be edited. Reopen it first.', 409);
    if (!empty($task['auto_key'])) fail('Automatic tasks are set by the system and cannot be edited. Complete it when the work is done.', 409);
    if (!$isAdmin) $assignee = $task['assigned_to'] !== null ? (int)$task['assigned_to'] : null;
    // A caseload-scoped user's task about another caseload's client keeps that client.
    if ($clientId === null && $task['client_id'] !== null && !can('clients')) $clientId = (int)$task['client_id'];
    $up = db()->prepare("UPDATE tasks SET title = ?, details = ?, client_id = ?, due_date = ?, assigned_to = ? WHERE id = ? AND status = 'Open'");
    $up->execute([$title, $details !== '' ? $details : null, $clientId, $due, $assignee, $taskId]);
    audit($userId, 'task.edit', 'task', $taskId, $title);
    out(['ok' => true, 'id' => $taskId, 'message' => 'Task saved.']);
}

case 'complete_task': {
    $parityRequirePost();
    $taskId = (int)($input['id'] ?? 0);
    $reopen = !empty($input['reopen']);
    // set_task_done() applies the website's visibility on the write, and changes only an
    // Open task to Done (or a Done one back), so a second tap rewrites nothing.
    if (!set_task_done($taskId, $parityUser, !$reopen)) {
        fail('The task was unavailable or already in that state.', 409);
    }
    out(['ok' => true, 'message' => $reopen ? 'Task reopened.' : 'Task completed.']);
}

/* ---------- Alerts (notifications.php) ---------- */

case 'notifications': {
    $uc = db()->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL');
    $uc->execute([$userId]);
    $unread = (int)$uc->fetchColumn();
    if (!empty($_GET['count_only'])) out(['ok' => true, 'unread' => $unread]);
    // The page's order and size: unread first, newest first within each, 100 at most.
    $rows = db()->prepare('SELECT id, message, link, created_at, read_at FROM notifications WHERE user_id = ? ORDER BY read_at IS NULL DESC, created_at DESC LIMIT 100');
    $rows->execute([$userId]);
    $items = [];
    foreach ($rows->fetchAll() as $n) {
        $items[] = ['id' => (int)$n['id'], 'message' => (string)$n['message'], 'link' => $n['link'] !== null && $n['link'] !== '' ? (string)$n['link'] : null,
            'created_at' => (string)$n['created_at'], 'unread' => $n['read_at'] === null];
    }
    out(['ok' => true, 'notifications' => $items, 'unread' => $unread]);
}

case 'mark_notifications_read': {
    $parityRequirePost();
    // As the page: only the alerts the app listed (ids, comma-separated, at most 100), so
    // newer ones that arrived meanwhile stay unread; "all" marks every unread alert.
    $all = !empty($input['all']);
    $ids = array_slice(array_values(array_unique(array_filter(array_map('intval',
        explode(',', is_string($input['ids'] ?? null) ? $input['ids'] : (is_int($input['ids'] ?? null) ? (string)$input['ids'] : ''))), fn($i) => $i > 0))), 0, 100);
    $marked = 0;
    if ($all) {
        $mr = db()->prepare('UPDATE notifications SET read_at = NOW() WHERE user_id = ? AND read_at IS NULL');
        $mr->execute([$userId]);
        $marked = $mr->rowCount();
    } elseif ($ids) {
        $mr = db()->prepare('UPDATE notifications SET read_at = NOW() WHERE user_id = ? AND read_at IS NULL AND id IN ('
            . implode(',', array_fill(0, count($ids), '?')) . ')');
        $mr->execute(array_merge([$userId], $ids));
        $marked = $mr->rowCount();
    } else {
        fail('Choose the alerts to mark as read.', 422);
    }
    $left = db()->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL');
    $left->execute([$userId]);
    $left = (int)$left->fetchColumn();
    out(['ok' => true, 'marked' => $marked, 'unread' => $left,
        'message' => $marked . ' alert' . ($marked === 1 ? '' : 's') . ' marked as read.'
            . ($left ? ' ' . $left . ' unread alert' . ($left === 1 ? ' remains.' : 's remain.') : '')]);
}

/* ---------- Client self-service requests (portal_requests.php) ---------- */

case 'portal_requests': {
    $scoped = caseload_scoped();
    $showDone = !empty($_GET['all']);
    $scopeSql = $scoped ? ' AND c.assigned_to = ?' : '';
    $scopeArg = $scoped ? [$userId] : [];
    $st = db()->prepare("SELECT pr.*, c.name AS client_name, c.id AS cid, s.start_at
        FROM portal_requests pr JOIN clients c ON c.id = pr.client_id
        LEFT JOIN scheduled_sessions s ON s.id = pr.session_id
        WHERE 1=1" . ($showDone ? '' : " AND pr.status IN ('New','In Progress')") . $scopeSql . '
        ORDER BY pr.status = \'New\' DESC, pr.created_at DESC LIMIT 301');
    $st->execute($scopeArg);
    $rows = $st->fetchAll();
    $truncated = count($rows) > 300;
    $nc = db()->prepare("SELECT COUNT(*) FROM portal_requests pr JOIN clients c ON c.id = pr.client_id WHERE pr.status = 'New'" . $scopeSql);
    $nc->execute($scopeArg);
    $items = [];
    foreach (array_slice($rows, 0, 300) as $r) {
        $items[] = ['id' => (int)$r['id'], 'client_id' => (int)$r['cid'], 'client_name' => (string)$r['client_name'],
            'type' => (string)$r['type'], 'type_label' => PARITY_PREQ_TYPES[$r['type']] ?? 'Request',
            'details' => $r['details'] !== null && $r['details'] !== '' ? (string)$r['details'] : null,
            'status' => (string)$r['status'], 'created_at' => (string)$r['created_at'],
            'session_id' => $r['session_id'] !== null ? (int)$r['session_id'] : null, 'session_start' => $r['start_at']];
    }
    out(['ok' => true, 'requests' => $items, 'statuses' => PARITY_PREQ_STATUSES, 'new_count' => (int)$nc->fetchColumn(),
        'truncated' => $truncated, 'showing_all' => $showDone, 'scoped' => $scoped]);
}

case 'portal_request_update': {
    $parityRequirePost();
    // portal_requests.php's status change, step for step. (The page sets a status only:
    // a reply to the client goes through Messages, as on the website.)
    $id = (int)($input['id'] ?? 0);
    $r = db()->prepare('SELECT pr.id, pr.client_id, pr.type, pr.status, c.name AS client_name, c.assigned_to FROM portal_requests pr JOIN clients c ON c.id = pr.client_id WHERE pr.id = ?');
    $r->execute([$id]);
    $row = $r->fetch();
    if (!$row) fail('That request no longer exists.', 404);
    if (caseload_scoped() && (int)$row['assigned_to'] !== $userId) fail('That request is not on your caseload.', 403);
    $to = in_array($input['to'] ?? '', PARITY_PREQ_STATUSES, true) ? $input['to'] : null;
    if ($to === null) fail('Choose a status for the request.', 422);
    $label = PARITY_PREQ_TYPES[$row['type']] ?? 'Request';
    if ($to === $row['status']) {
        out(['ok' => true, 'changed' => false, 'message' => 'Request from ' . $row['client_name'] . ' (' . $label . ') is already ' . $to . '; nothing was changed.']);
    }
    $closed = ['Done', 'Declined'];
    db()->beginTransaction();
    try {
        db()->prepare('UPDATE portal_requests SET status = ?, handled_by = ? WHERE id = ?')->execute([$to, $userId, $id]);
        if (in_array($to, $closed, true)) {
            complete_task_by_key('preq-' . $id, $userId);
        } elseif (in_array($row['status'], $closed, true)) {
            $owner = db()->prepare('SELECT u.id FROM clients c JOIN users u ON u.id = c.assigned_to AND u.is_active = 1 WHERE c.id = ?');
            $owner->execute([(int)$row['client_id']]);
            $ownerId = $owner->fetchColumn();
            db()->prepare("UPDATE tasks SET status = 'Open', done_at = NULL, done_by = NULL, assigned_to = ? WHERE auto_key = ? AND status = 'Done'")
                ->execute([$ownerId !== false ? (int)$ownerId : null, 'preq-' . $id]);
        }
        db()->commit();
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        throw $e;
    }
    audit($userId, 'portal_request.status', 'client', (int)$row['client_id'], 'request #' . $id . ': ' . $row['status'] . ' → ' . $to);
    out(['ok' => true, 'changed' => true, 'message' => 'Request from ' . $row['client_name'] . ' (' . $label . ') set to ' . $to . '.']);
}

/* ---------- Training tracks (track_edit.php) ---------- */

case 'save_track': {
    $trackId = (int)($input['id'] ?? $_GET['id'] ?? 0);
    $track = null;
    if ($trackId) {
        $stmt = db()->prepare('SELECT * FROM training_tracks WHERE id = ?');
        $stmt->execute([$trackId]);
        $track = $stmt->fetch() ?: null;
        if (!$track) fail('Training track not found.', 404);
        $clientId = (int)$track['client_id'];
    } else {
        $clientId = (int)($input['client_id'] ?? $_GET['client_id'] ?? 0);
    }
    $cstmt = db()->prepare('SELECT id, name, client_type, assigned_to FROM clients WHERE id = ?');
    $cstmt->execute([$clientId]);
    $client = $cstmt->fetch();
    if (!$client) fail('Choose a client first.', 404);
    api_require_client($clientId, $userId);

    // GET: the form — the track's values (or the new-track defaults) and its choices.
    if (!$parityPost) {
        out(['ok' => true, 'track' => $track ? parity_track_values($track) : null, 'client_name' => $client['name']]
            + parity_track_choices($client, $track));
    }

    $errors = [];
    $title = trim((string)($input['title'] ?? ''));
    $authNumber = trim((string)($input['auth_number'] ?? ''));
    $agency = trim((string)($input['funding_agency'] ?? ''));
    // A field left out keeps the track's value on an edit (none on a new track).
    $authHours = parity_track_number($input, 'authorized_hours', 'Authorized hours', $track ? (float)$track['authorized_hours'] : 0.0);
    $rate = parity_track_number($input, 'hourly_rate', 'Hourly rate', $track ? (float)$track['hourly_rate'] : 0.0);
    $reason = trim((string)($input['amendment_reason'] ?? ''));
    if ($title === '') $errors['title'] = 'Track title is required.';
    elseif (mb_strlen($title) > 190) $errors['title'] = 'Keep the track title to 190 characters or fewer.';
    // track_edit.php's billing rules (3.0.34): left out, a track keeps its mode (a new one is VR).
    $billingMode = api_choice($input, 'billing_mode', array_keys(BILLING_MODES), 'Who pays', $track['billing_mode'] ?? 'vr');
    $isVr = $billingMode === 'vr';
    if (!$isVr) {
        if ($agency === '') $agency = $billingMode === 'private' ? 'Private pay' : 'No charge';
        if ($billingMode === 'none') $rate = 0.0;
    }
    $authAmount = array_key_exists('authorized_amount', $input)
        ? ($input['authorized_amount'] === null || $input['authorized_amount'] === '' ? null : (is_numeric($input['authorized_amount']) ? round((float)$input['authorized_amount'], 2) : -1.0))
        : ($track && $track['authorized_amount'] !== null ? (float)$track['authorized_amount'] : null);
    if (!$isVr) $authAmount = null;
    if ($authAmount !== null && ($authAmount < 0 || $authAmount > 99999999.99)) $errors['authorized_amount'] = 'Enter the authorized dollar amount in dollars and cents, or leave it out to use the authorized hours at the hourly rate.';
    if ($isVr && $authNumber === '') $errors['auth_number'] = 'Service authorization number is required for a VR or agency track. For a client who pays, or free training, choose Private pay or No charge.';
    elseif (mb_strlen($authNumber) > 80) $errors['auth_number'] = 'Keep the authorization number to 80 characters or fewer.';
    if ($agency === '') $errors['funding_agency'] = 'Funding agency is required.';
    elseif (mb_strlen($agency) > 190) $errors['funding_agency'] = 'Keep the funding agency to 190 characters or fewer.';
    if ($isVr && $authHours <= 0) $errors['authorized_hours'] = 'Authorized hours must be greater than zero.';
    elseif ($authHours < 0) $errors['authorized_hours'] = 'Authorized hours cannot be negative.';
    elseif ($authHours > 99999.99) $errors['authorized_hours'] = 'Authorized hours must be 99,999.99 or fewer.';
    if ($billingMode !== 'none' && $rate <= 0) $errors['hourly_rate'] = 'Hourly rate must be greater than zero.';
    elseif ($rate > 999999.99) $errors['hourly_rate'] = 'Hourly rate must be 999,999.99 or less.';
    if (mb_strlen($reason) > 255) $errors['amendment_reason'] = 'Keep the amendment reason to 255 characters or fewer.';
    // A choice left out keeps the track's value (on an edit) or the website's default;
    // one that is not on the list is refused (the page falls back silently, the app is told).
    $status = api_choice($input, 'status', TRACK_STATUSES, 'Status', $track['status'] ?? 'Active');
    $serviceType = api_choice($input, 'service_type', SERVICE_TYPES, 'Service type', $track['service_type'] ?? 'Assistive Technology');
    $currentBundleId = $track ? ((int)($track['bundle_id'] ?? 0) ?: null) : null;
    $bundleId = ($track && !array_key_exists('bundle_id', $input)) ? $currentBundleId : ((int)($input['bundle_id'] ?? 0) ?: null);
    if ($bundleId !== null) {
        $chosen = bundle($bundleId);
        $keeping = $bundleId === $currentBundleId;
        if (!$chosen || (!$keeping && (!$chosen['active']
            || ($chosen['client_type'] !== 'Any' && $chosen['client_type'] !== ($client['client_type'] ?? 'VR'))))) {
            $errors['bundle_id'] = 'That service bundle is not available for this client type.';
            $bundleId = null;
        }
    }
    $rsaCategory = api_choice($input, 'rsa_category', rsa_all_categories(), 'RSA service category',
        $track && !array_key_exists('service_type', $input) ? ($track['rsa_category'] ?? rsa_default_for($serviceType)) : rsa_default_for($serviceType));
    // Left out on an edit = keep the track's dates (blank clears them, as on the page).
    $startRaw = trim((string)(($track && !array_key_exists('start_date', $input)) ? ($track['start_date'] ?? '') : ($input['start_date'] ?? '')));
    $expRaw = trim((string)(($track && !array_key_exists('expiration_date', $input)) ? ($track['expiration_date'] ?? '') : ($input['expiration_date'] ?? '')));
    $start = $startRaw !== '' ? $startRaw : null;
    $exp = $expRaw !== '' ? $expRaw : null;
    $maxYear = (int)date('Y') + 5;
    if ($start && !valid_date($start, 2000, $maxYear)) $errors['start_date'] = 'Use a valid start date (YYYY-MM-DD) with a year from 2000 to ' . $maxYear . '.';
    if ($exp && !valid_date($exp, 2000, $maxYear)) $errors['expiration_date'] = 'Use a valid expiration date (YYYY-MM-DD) with a year from 2000 to ' . $maxYear . '.';
    if ($start && $exp && $exp < $start) $errors['expiration_date'] = 'Expiration date must be after the start date.';
    // Left out on an edit = keep the track's mileage rate (never re-price to $0 by omission).
    $mileageRaw = trim((string)(($track && !array_key_exists('mileage_rate', $input)) ? ($track['mileage_rate'] ?? '') : ($input['mileage_rate'] ?? '')));
    $mileageRate = $mileageRaw === '' ? 0.0 : (float)$mileageRaw;
    if ($mileageRaw !== '' && (!is_numeric($mileageRaw) || $mileageRate < 0 || $mileageRate > 999.999)) {
        $errors['mileage_rate'] = 'Enter the mileage rate in dollars per mile, from 0 to 999.999 (0 when travel is not billable).';
    }
    if ($errors) {
        // Every problem, by field, as the page's error summary lists them.
        out(['ok' => false, 'error' => implode(' ', $errors), 'errors' => $errors], 422);
    }
    $cadence = api_choice($input, 'report_cadence', ['none', 'weekly', 'biweekly', 'monthly'], 'Automatic progress reports', $track['report_cadence'] ?? 'monthly');
    $vals = [$title, $serviceType, $rsaCategory, $bundleId, $status, $authNumber, $agency, $authHours, $rate, $mileageRate, $start, $exp, $cadence];

    if (!$track) {
        // The same form sent twice (a retry after a lost reply) creates the track once: the
        // website keys this on its form's idem_key in the session; the app has no session, so
        // an identical track created for this client in the last two minutes is the first one.
        $dup = db()->prepare('SELECT id FROM training_tracks WHERE client_id = ? AND title = ? AND auth_number = ? AND created_at > DATE_SUB(NOW(), INTERVAL 2 MINUTE) ORDER BY id DESC LIMIT 1');
        $dup->execute([$clientId, $title, $authNumber]);
        if ($firstId = (int)$dup->fetchColumn()) {
            out(['ok' => true, 'id' => $firstId, 'duplicate' => true, 'message' => 'This track was already created (the form was sent twice), so it was saved once.']);
        }
        db()->prepare('INSERT INTO training_tracks (client_id, title, service_type, rsa_category, bundle_id, status, auth_number, funding_agency, authorized_hours, hourly_rate, mileage_rate, start_date, expiration_date, report_cadence, billing_mode, authorized_amount) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute(array_merge([$clientId], $vals, [$billingMode, $authAmount]));
        $newTrackId = (int)db()->lastInsertId();
        audit($userId, 'track.create', 'track', $newTrackId, $title . ' (auth ' . $authNumber . ')');
        out(['ok' => true, 'id' => $newTrackId, 'message' => 'Training track created.']);
    }

    // An edit: track_edit.php's transaction, amendment record, alert resets, re-pricing of
    // unbilled and Draft-invoice work, Draft header update and re-totalling, line for line.
    $id = (int)$track['id'];
    db()->beginTransaction();
    try {
        $locked = db()->prepare('SELECT * FROM training_tracks WHERE id=? FOR UPDATE');
        $locked->execute([$id]);
        $track = $locked->fetch();
        if (!$track) throw new DomainException('Training track no longer exists.');
        $hoursChanged = round((float)$track['authorized_hours'], 2) !== round($authHours, 2);
        $rateChanged = round((float)$track['hourly_rate'], 2) !== round($rate, 2);
        $mileageChanged = round((float)$track['mileage_rate'], 3) !== round($mileageRate, 3);
        $expChanged = ($track['expiration_date'] ?? null) !== $exp;
        $authNoChanged = (string)$track['auth_number'] !== $authNumber;
        $agencyChanged = (string)$track['funding_agency'] !== $agency;
        $changes = [];
        foreach (['auth_number' => $authNumber, 'funding_agency' => $agency, 'hourly_rate' => $rate, 'mileage_rate' => $mileageRate,
                  'authorized_hours' => $authHours, 'expiration_date' => $exp, 'start_date' => $start, 'bundle_id' => $bundleId,
                  'rsa_category' => $rsaCategory, 'service_type' => $serviceType, 'status' => $status,
                  'report_cadence' => $cadence, 'title' => $title] as $col => $new) {
            $changed = match ($col) {
                'hourly_rate' => $rateChanged, 'mileage_rate' => $mileageChanged, 'authorized_hours' => $hoursChanged,
                default => (string)($track[$col] ?? '') !== (string)($new ?? ''),
            };
            if ($changed) $changes[] = $col . ' ' . ($track[$col] ?? '—') . '->' . ($new ?? '—');
        }
        $repriced = 0;
        $draftIds = [];
        if ($rateChanged || $mileageChanged || $authNoChanged || $agencyChanged) {
            $dq = db()->prepare("SELECT id FROM invoices WHERE track_id = ? AND status = 'Draft' FOR UPDATE");
            $dq->execute([$id]);
            $draftIds = array_map('intval', $dq->fetchAll(PDO::FETCH_COLUMN));
        }
        $onDraft = $draftIds ? ' OR invoice_id IN (' . implode(',', $draftIds) . ')' : '';
        if ($rateChanged) {
            $rp = db()->prepare('UPDATE work_logs SET hourly_rate = ? WHERE track_id = ? AND (is_billed = 0' . $onDraft . ')');
            $rp->execute([$rate, $id]);
            $repriced = $rp->rowCount();
        }
        if ($mileageChanged) {
            $rp = db()->prepare('UPDATE work_logs SET mileage_rate = ? WHERE track_id = ? AND (is_billed = 0' . $onDraft . ')');
            $rp->execute([$mileageRate, $id]);
            $repriced = max($repriced, $rp->rowCount());
        }
        $headerUpdated = 0;
        if ($draftIds && ($authNoChanged || $agencyChanged)) {
            $hu = db()->prepare('UPDATE invoices SET auth_number = ?, funding_agency = ? WHERE id IN (' . implode(',', $draftIds) . ")
                AND status = 'Draft' AND auth_number IS NOT NULL");
            $hu->execute([$authNumber, $agency]);
            $headerUpdated = $hu->rowCount();
        }
        $amended = $hoursChanged || $rateChanged || $expChanged || $authNoChanged || $mileageChanged;
        if ($amended) {
            db()->prepare('INSERT INTO auth_amendments (track_id, old_hours, new_hours, old_rate, new_rate, old_expiration, new_expiration, old_auth_number, new_auth_number, old_mileage_rate, new_mileage_rate, reason, changed_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$id,
                    $hoursChanged ? $track['authorized_hours'] : null, $hoursChanged ? $authHours : null,
                    $rateChanged ? $track['hourly_rate'] : null, $rateChanged ? $rate : null,
                    $expChanged ? $track['expiration_date'] : null, $expChanged ? $exp : null,
                    $authNoChanged ? $track['auth_number'] : null, $authNoChanged ? $authNumber : null,
                    $mileageChanged ? $track['mileage_rate'] : null, $mileageChanged ? $mileageRate : null,
                    $reason !== '' ? $reason : null, $userId]);
        }
        if ($expChanged) {
            db()->prepare("DELETE FROM sent_alerts WHERE track_id = ? AND alert_key IN ('exp7','exp14','exp30')")->execute([$id]);
        }
        if ($hoursChanged || $rateChanged || $mileageChanged) {
            db()->prepare("DELETE FROM sent_alerts WHERE track_id = ? AND alert_key IN ('pct80','funds80')")->execute([$id]);
        }
        if ($hoursChanged || $rateChanged || $expChanged || $mileageChanged || $authNoChanged || $agencyChanged) {
            audit($userId, 'auth.amend', 'track', $id,
                ($hoursChanged ? "hours {$track['authorized_hours']}->{$authHours} " : '')
                . ($rateChanged ? "rate {$track['hourly_rate']}->{$rate} " : '')
                . ($mileageChanged ? "mileage {$track['mileage_rate']}->{$mileageRate} " : '')
                . ($expChanged ? "exp {$track['expiration_date']}->{$exp} " : '')
                . ($authNoChanged ? "auth# {$track['auth_number']}->{$authNumber} " : '')
                . ($agencyChanged ? "agency {$track['funding_agency']}->{$agency} " : '')
                . ($repriced ? "; {$repriced} unbilled or draft log(s) re-priced" : '')
                . ($draftIds && $repriced ? '; draft invoice(s) #' . implode(', #', $draftIds) . ' re-priced' : '')
                . ($headerUpdated ? "; {$headerUpdated} draft invoice header(s) updated" : ''));
        }
        $vals[] = $id;
        db()->prepare('UPDATE training_tracks SET title=?, service_type=?, rsa_category=?, bundle_id=?, status=?, auth_number=?, funding_agency=?, authorized_hours=?, hourly_rate=?, mileage_rate=?, start_date=?, expiration_date=?, report_cadence=? WHERE id=?')->execute($vals);
        db()->prepare('UPDATE training_tracks SET billing_mode = ?, authorized_amount = ? WHERE id = ?')->execute([$billingMode, $authAmount, $id]);
        $retotalled = 0;
        if ($draftIds && ($rateChanged || $mileageChanged)) {
            $headerSet = ($rateChanged ? ', i.hourly_rate = ?' : '') . ($mileageChanged ? ', i.mileage_rate = ?' : '');
            $headerArgs = array_merge($rateChanged ? [$rate] : [], $mileageChanged ? [$mileageRate] : []);
            $rt = db()->prepare('UPDATE invoices i
                JOIN (SELECT w.invoice_id,
                        COALESCE(SUM(ROUND(w.hours * COALESCE(w.hourly_rate, t.hourly_rate), 2)),0) ha,
                        COALESCE(SUM(ROUND(w.mileage * COALESCE(w.mileage_rate, t.mileage_rate), 2)),0) ma
                    FROM work_logs w JOIN training_tracks t ON t.id = w.track_id
                    WHERE w.invoice_id IN (' . implode(',', $draftIds) . ') GROUP BY w.invoice_id) s ON s.invoice_id = i.id
                SET i.hours_amount = s.ha, i.mileage_amount = s.ma, i.total_amount = s.ha + s.ma + i.expenses_amount' . $headerSet
                . " WHERE i.status = 'Draft'");
            $rt->execute($headerArgs);
            $retotalled = $rt->rowCount();
        }
        db()->commit();
    } catch (DomainException $e) {
        if (db()->inTransaction()) db()->rollBack();
        fail($e->getMessage(), 404);
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        throw $e;
    }
    audit($userId, 'track.update', 'track', $id, $changes ? implode('; ', $changes) : 'no changes (' . $title . ')');
    $saved = db()->prepare('SELECT * FROM training_tracks WHERE id = ?');
    $saved->execute([$id]);
    out(['ok' => true, 'id' => $id, 'amended' => $amended, 'repriced' => $repriced, 'retotalled' => $retotalled, 'headers_updated' => $headerUpdated,
        'track' => parity_track_values($saved->fetch()),
        'message' => 'Training track updated.' . ($amended ? ' The authorization change was recorded in the amendment history.' : '')
            . ($repriced ? ' ' . $repriced . ' work log entr' . ($repriced === 1 ? 'y' : 'ies') . ' not yet invoiced or on a draft invoice now use' . ($repriced === 1 ? 's' : '') . ' the new rate.' : '')
            . ($retotalled ? ' ' . ($retotalled === 1 ? '1 draft invoice was' : $retotalled . ' draft invoices were') . ' re-totalled at the new rate.' : '')
            . ($headerUpdated ? ' ' . ($headerUpdated === 1 ? '1 draft invoice now shows' : $headerUpdated . ' draft invoices now show') . ' the new authorization number and funding agency.' : '')]);
}

/* ---------- Guardian consent (client.php, compliance.php) ---------- */

case 'request_consent': {
    $clientId = (int)($input['client_id'] ?? $_GET['client_id'] ?? 0);
    api_require_client($clientId, $userId);
    $cq = db()->prepare('SELECT id, name, guardian_name, guardian_email FROM clients WHERE id = ?');
    $cq->execute([$clientId]);
    $client = $cq->fetch();
    if (!$client) fail('Client not found.', 404);
    // GET: the consent as the client page shows it, whether it offers a request, and the
    // guardian details it fills the form with.
    if (!$parityPost) {
        out(['ok' => true, 'consent' => parity_consent_state($clientId),
            'guardian_name' => $client['guardian_name'] ?? '', 'guardian_email' => $client['guardian_email'] ?? '']);
    }
    // Each request emails someone outside the agency: at most 10 an hour per staff member.
    if (api_throttle_count('consent-mail-' . $userId, 3600) >= 10) fail('Too many consent requests in the last hour. Wait a while, then try again.', 429);
    api_throttle_record('consent-mail-' . $userId);
    // client.php's request_consent handler, step for step.
    $gName = is_string($input['guardian_name'] ?? null) ? trim($input['guardian_name']) : '';
    $gEmail = strtolower(is_string($input['guardian_email'] ?? null) ? trim($input['guardian_email']) : '');
    $cErrs = [];
    if ($gName === '') $cErrs['guardian_name'] = 'Enter the guardian\'s name.';
    elseif (mb_strlen($gName) > 120) $cErrs['guardian_name'] = 'Keep the guardian name to 120 characters or fewer.';
    if ($gEmail === '') $cErrs['guardian_email'] = 'Enter the guardian\'s email address.';
    elseif (!filter_var($gEmail, FILTER_VALIDATE_EMAIL)) $cErrs['guardian_email'] = 'Enter the guardian\'s email address in the form name@example.com.';
    elseif (strlen($gEmail) > 190) $cErrs['guardian_email'] = 'Keep the guardian email to 190 characters or fewer.';
    if ($cErrs) out(['ok' => false, 'error' => implode(' ', $cErrs), 'errors' => $cErrs], 422);
    $token = bin2hex(random_bytes(24));
    [$subj, $body] = email_template_for_client('consent_request', $client, ['name' => $gName, 'client' => $client['name'], 'link' => cfg('base_url') . '/consent.php?t=' . $token]);
    // As client.php: the client row is locked from the insert to the clean-up, so two
    // requests sent at once (two devices, or the app and the website) run one after
    // the other instead of each emailing a link and deleting the other's, which left
    // the guardian with two emails and no working link.
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('SELECT id FROM clients WHERE id = ? FOR UPDATE')->execute([$clientId]);
        // consent_seen (optional): the newest unsigned request the app showed (0 for
        // none). A request sent since then is not replaced unseen.
        $seen = $input['consent_seen'] ?? null;
        if (is_int($seen) || (is_string($seen) && ctype_digit($seen))) {
            $nq = $pdo->prepare('SELECT id, guardian_name, sent_at FROM consent_forms WHERE client_id = ? AND signed_at IS NULL ORDER BY sent_at DESC, id DESC LIMIT 1');
            $nq->execute([$clientId]);
            $newest = $nq->fetch();
            if ($newest && (int)$newest['id'] !== (int)$seen) {
                $pdo->rollBack();
                out(['ok' => false, 'error' => 'A consent form was already emailed to ' . $newest['guardian_name'] . ' at ' . fmt_datetime($newest['sent_at'])
                    . ' (from another device, or by a colleague), so this one was not sent, and that link still works. Refresh, then send again if a new one is needed.'], 409);
            }
        }
        $pdo->prepare('INSERT INTO consent_forms (client_id, guardian_name, guardian_email, token) VALUES (?,?,?,?)')
            ->execute([$clientId, $gName, $gEmail, $token]);
        $newFormId = (int)$pdo->lastInsertId();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    if (!send_app_mail($gEmail, $subj, $body)) {
        // Nothing was delivered: drop the new link and leave any earlier one working.
        $pdo->rollBack();
        fail('The consent email could not be sent, so nothing was changed — any consent link sent earlier still works. Please try again later.', 502);
    }
    // The savepoint keeps the sent link's row if the follow-up fails.
    try {
        $pdo->exec('SAVEPOINT consent_sent');
        $pdo->prepare('DELETE FROM consent_forms WHERE client_id = ? AND signed_at IS NULL AND id <> ?')->execute([$clientId, $newFormId]);
        $pdo->prepare('UPDATE clients SET guardian_name = ?, guardian_email = ? WHERE id = ?')->execute([$gName, $gEmail, $clientId]);
        $pdo->commit();
    } catch (Throwable $e) {
        error_log('Consent follow-up failed: ' . get_class($e) . ' (code ' . $e->getCode() . ')');
        try {
            $pdo->exec('ROLLBACK TO SAVEPOINT consent_sent');
            $pdo->commit();
        } catch (Throwable $e2) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Consent link store failed: ' . get_class($e2) . ' (code ' . $e2->getCode() . ')');
            audit($userId, 'consent.request_failed', 'client', $clientId, 'to ' . $gEmail . '; emailed, but the link could not be activated');
            fail("The consent form was emailed to {$gName}, but its link could not be activated, so it will not open. Send it again; any consent link sent earlier still works.", 500);
        }
        audit($userId, 'consent.request', 'client', $clientId, 'to ' . $gEmail . '; guardian details not saved to the record');
        // Sent, and its link works: answered as a success that says what did not finish,
        // never "nothing was saved", which would invite a second request.
        out(['ok' => true, 'partial' => true, 'message' => "The consent form was emailed to {$gName}, and its link works. Do not send it again. Saving the guardian's details to the client record did not finish, so an earlier unsigned consent link may also still work."]);
    }
    audit($userId, 'consent.request', 'client', $clientId, 'to ' . $gEmail);
    out(['ok' => true, 'message' => "Consent form emailed to {$gName}."]);
}

case 'consents_due': {
    // compliance.php's "Consents & releases to renew", same query, rules and order.
    $scoped = caseload_scoped();
    $scopeSql = $scoped ? ' AND c.assigned_to = ?' : '';
    $scopeArg = $scoped ? [$userId] : [];
    $today = date('Y-m-d');
    $soon = date('Y-m-d', strtotime('+30 days'));
    $validDays = (int)(setting('consent_validity_days') ?: 365);
    $cf = db()->prepare("SELECT c.id AS cid, c.name AS client,
            s.guardian_name AS signed_guardian, s.signed_at, s.withdrawn_at,
            p.guardian_name AS pending_guardian, p.sent_at AS pending_sent_at
        FROM clients c
        LEFT JOIN consent_forms s ON s.id = (SELECT x.id FROM consent_forms x WHERE x.client_id = c.id AND x.signed_at IS NOT NULL
            ORDER BY x.signed_at DESC, x.id DESC LIMIT 1)
        LEFT JOIN consent_forms p ON p.id = (SELECT y.id FROM consent_forms y WHERE y.client_id = c.id AND y.signed_at IS NULL
            ORDER BY y.sent_at DESC, y.id DESC LIMIT 1)
        WHERE c.archived_at IS NULL AND c.status NOT LIKE 'Closed%' AND (s.id IS NOT NULL OR p.id IS NOT NULL)" . $scopeSql);
    $cf->execute($scopeArg);
    $consentRisk = [];
    $unsignedBefore = date('Y-m-d', strtotime('-14 days'));
    foreach ($cf->fetchAll() as $f) {
        $f['kind'] = null;
        $f['guardian_name'] = $f['signed_at'] ? $f['signed_guardian'] : $f['pending_guardian'];
        if ($f['signed_at'] && $f['withdrawn_at']) {
            $f['kind'] = 'Withdrawn'; $f['when'] = substr($f['withdrawn_at'], 0, 10);
        } elseif ($f['signed_at']) {
            $exp = date('Y-m-d', strtotime($f['signed_at'] . ' +' . $validDays . ' days'));
            if ($exp <= $soon) { $f['kind'] = $exp < $today ? 'Expired' : 'Expiring'; $f['when'] = $exp; }
        } elseif ($f['pending_sent_at'] < $unsignedBefore) {
            $f['kind'] = 'Unsigned'; $f['when'] = $f['pending_sent_at'];
        }
        if ($f['kind'] !== null) $consentRisk[] = $f;
    }
    $noConsent = fn(array $f): int => in_array($f['kind'], ['Expired', 'Withdrawn'], true) ? 0 : 1;
    usort($consentRisk, fn($a, $b) => [$noConsent($a), $a['when']] <=> [$noConsent($b), $b['when']]);
    $items = [];
    foreach ($consentRisk as $f) {
        $items[] = ['client_id' => (int)$f['cid'], 'client' => (string)$f['client'], 'guardian_name' => (string)($f['guardian_name'] ?? ''),
            'kind' => $f['kind'], 'label' => $f['kind'] === 'Unsigned' ? 'Not signed' : $f['kind'], 'date' => substr((string)$f['when'], 0, 10),
            'pending_sent_at' => $f['kind'] !== 'Unsigned' && $f['pending_sent_at'] ? substr((string)$f['pending_sent_at'], 0, 10) : null,
            'action' => $f['pending_sent_at'] ? 'Resend consent request' : ($f['kind'] === 'Withdrawn' ? 'Request new consent' : 'Renew consent')];
    }
    out(['ok' => true, 'consents' => $items, 'validity_days' => $validDays]);
}

/* ---------- Intake pipeline (pipeline.php) ---------- */

case 'pipeline': {
    $scoped = caseload_scoped();
    $referrals = [];
    if (can('referrals')) {
        $rq = db()->prepare("SELECT r.*, u.name AS assigned_name FROM referrals r LEFT JOIN users u ON u.id = r.assigned_to
            WHERE r.status IN ('New','Reviewed')" . ($scoped ? ' AND (r.assigned_to = ? OR r.assigned_to IS NULL)' : '') . " ORDER BY r.created_at");
        $rq->execute($scoped ? [$userId] : []);
        foreach ($rq->fetchAll() as $r) {
            $d = max(0, (int)floor((time() - strtotime($r['created_at'])) / 86400));
            $referrals[] = ['id' => (int)$r['id'], 'name' => (string)$r['client_name'], 'status' => (string)$r['status'],
                'days_in_stage' => $d, 'over_30' => $d > 30, 'assigned_name' => $r['assigned_name']];
        }
    }
    $cq = db()->prepare("SELECT c.id, c.name, c.status, c.created_at, u.name AS instructor,
            (SELECT MAX(changed_at) FROM client_status_history h WHERE h.client_id = c.id
                AND (h.note IS NULL OR h.note NOT LIKE '[merged] %')) AS stage_since
        FROM clients c LEFT JOIN users u ON u.id = c.assigned_to
        WHERE c.archived_at IS NULL" . ($scoped ? ' AND c.assigned_to = ?' : '') . " ORDER BY c.name");
    $cq->execute($scoped ? [$userId] : []);
    $waiting = ['Pending Assessment', 'Waitlist', 'On Hold'];
    $stages = ['Pending Assessment' => [], 'Waitlist' => [], 'Active Training' => [], 'On Hold' => []];
    $closed = [];
    foreach ($cq->fetchAll() as $c) {
        $since = $c['stage_since'] ?? $c['created_at'];
        $days = max(0, (int)floor((time() - strtotime($since)) / 86400));
        $row = ['id' => (int)$c['id'], 'name' => (string)$c['name'], 'status' => (string)$c['status'], 'days_in_stage' => $days,
            // Waiting stages over 30 days are flagged; a long stay in Active Training is normal.
            'over_30' => in_array($c['status'], $waiting, true) && $days > 30, 'instructor' => $c['instructor']];
        if (isset($stages[$c['status']])) $stages[$c['status']][] = $row;
        elseif (str_starts_with($c['status'], 'Closed') && strtotime($since) > strtotime('-90 days')) $closed[] = $row;
    }
    $stageList = [];
    foreach ($stages as $name => $list) $stageList[] = ['name' => $name, 'clients' => $list];
    out(['ok' => true, 'can_see_referrals' => can('referrals'), 'referrals' => $referrals, 'stages' => $stageList, 'closed' => $closed]);
}

/* ---------- Status history (client.php's history tab) ---------- */

case 'client_history': {
    $clientId = (int)($_GET['client_id'] ?? $input['client_id'] ?? 0);
    api_require_client($clientId, $userId);
    $history = db()->prepare('SELECT h.*, u.name AS changed_by_name FROM client_status_history h LEFT JOIN users u ON u.id = h.changed_by WHERE h.client_id = ? ORDER BY h.changed_at DESC');
    $history->execute([$clientId]);
    $items = [];
    foreach ($history->fetchAll() as $h) {
        $items[] = ['id' => (int)$h['id'], 'changed_at' => (string)$h['changed_at'], 'old_status' => $h['old_status'], 'new_status' => (string)$h['new_status'],
            'changed_by' => $h['changed_by_name'] ?? 'System', 'note' => $h['note'] !== null && $h['note'] !== '' ? (string)$h['note'] : null];
    }
    out(['ok' => true, 'history' => $items]);
}

/* ---------- Invoices, read only (invoices.php's list) ---------- */

case 'invoices': {
    $scoped = caseload_scoped();
    $scopeCond = $scoped ? ' AND c.assigned_to = ?' : '';
    $scopeArg = $scoped ? [$userId] : [];
    $statusFilter = is_string($_GET['status'] ?? null) && in_array($_GET['status'], ['Draft', 'Submitted', 'Paid', 'Denied', 'Void'], true) ? $_GET['status'] : '';
    $listFrom = " FROM invoices i JOIN training_tracks t ON t.id = i.track_id JOIN clients c ON c.id = t.client_id
        WHERE 1=1" . $scopeCond;
    $filter = $statusFilter !== '' ? ' AND i.status = ?' : '';
    $params = array_merge($scopeArg, $statusFilter !== '' ? [$statusFilter] : []);
    $perPage = 300;
    $cq = db()->prepare('SELECT COUNT(*)' . $listFrom . $filter);
    $cq->execute($params);
    $invTotal = (int)$cq->fetchColumn();
    $pages = max(1, (int)ceil($invTotal / $perPage));
    $page = is_string($_GET['page'] ?? null) && ctype_digit($_GET['page']) ? min(max(1, (int)$_GET['page']), $pages) : 1;
    $stmt = db()->prepare("SELECT i.*, t.title AS track_title, COALESCE(i.auth_number, t.auth_number) AS auth_number,
            COALESCE(i.funding_agency, t.funding_agency) AS funding_agency, c.name AS client_name, c.id AS client_id,
            DATEDIFF(CURDATE(), i.submitted_at) AS days_outstanding" . $listFrom . $filter
        . ' ORDER BY i.created_at DESC, i.id DESC LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage));
    $stmt->execute($params);
    $items = [];
    foreach ($stmt->fetchAll() as $i) {
        $total = round((float)$i['total_amount'], 2);
        // Payments and write-offs are running totals on the invoice (3.0.34, inc/billing.php).
        $paid = $i['paid_amount'] !== null ? round((float)$i['paid_amount'], 2) : null;
        $balance = in_array($i['status'], ['Submitted', 'Paid'], true) ? invoice_balance($i) : ($i['status'] === 'Draft' ? $total : 0.0);
        $items[] = ['id' => (int)$i['id'], 'invoice_number' => (string)$i['invoice_number'], 'status' => (string)$i['status'],
            'client_id' => (int)$i['client_id'], 'client_name' => (string)$i['client_name'], 'track_title' => (string)$i['track_title'],
            'auth_number' => $i['auth_number'], 'funding_agency' => $i['funding_agency'],
            'hours' => (float)$i['hours'], 'total_amount' => $total, 'paid_amount' => $paid, 'balance' => $balance,
            'short_paid' => in_array($i['status'], ['Submitted', 'Paid'], true) && $paid !== null && $balance > 0,
            'written_off' => round((float)$i['written_off'], 2), 'due_date' => $i['due_date'], 'closed_reason' => $i['closed_reason'],
            'days_outstanding' => $i['status'] === 'Submitted' && $i['days_outstanding'] !== null ? (int)$i['days_outstanding'] : null,
            'created_at' => substr((string)$i['created_at'], 0, 10), 'submitted_at' => $i['submitted_at'], 'paid_at' => $i['paid_at'],
            'payment_ref' => $i['payment_ref'], 'can_open_client' => can('clients')];
    }
    // The page's outstanding-by-agency summary and unbilled figure, same scope.
    $ag = db()->prepare("SELECT COALESCE(i.funding_agency, t.funding_agency) AS funding_agency, COUNT(*) n,
            SUM(i.total_amount - COALESCE(i.paid_amount, 0) - i.written_off) amt, MAX(DATEDIFF(CURDATE(), i.submitted_at)) oldest
        FROM invoices i JOIN training_tracks t ON t.id = i.track_id JOIN clients c ON c.id = t.client_id
        WHERE " . INVOICE_OPEN_SQL . $scopeCond . "
        GROUP BY COALESCE(i.funding_agency, t.funding_agency) ORDER BY amt DESC");
    $ag->execute($scopeArg);
    $aging = array_map(fn($a) => ['funding_agency' => (string)($a['funding_agency'] ?? ''), 'count' => (int)$a['n'],
        'amount' => round((float)$a['amt'], 2), 'oldest_days' => $a['oldest'] !== null ? (int)$a['oldest'] : null], $ag->fetchAll());
    out(['ok' => true, 'invoices' => $items, 'status' => $statusFilter, 'total' => $invTotal, 'page' => $page, 'pages' => $pages,
        'outstanding' => $aging, 'unbilled' => round((float)unbilled_totals($scoped ? $userId : null)['total'], 2)]);
}

/* ---------- Referral waitlist (workflows.php?view=waitlist, inc/workflows.php) ---------- */

case 'waitlist': {
    require_once __DIR__ . '/workflows.php';
    if (!workflows_ready()) fail('Apply migration 3.0.16 to enable workflows.', 503);
    // The page's query (workflows.php, inline), repeated here: open referrals, oldest
    // first, closed waitlist entries last; a caseload-scoped user sees unassigned
    // referrals and their own.
    $q = db()->prepare("SELECT r.id, r.client_name, r.created_at AS received_at, w.* FROM referrals r LEFT JOIN referral_waitlist w ON w.referral_id = r.id
        WHERE r.status <> 'Converted'" . (caseload_scoped() ? ' AND (r.assigned_to IS NULL OR r.assigned_to = ?)' : '') . " ORDER BY COALESCE(w.status,'') = 'Closed', r.created_at, r.id");
    $q->execute(caseload_scoped() ? [$userId] : []);
    out(['ok' => true, 'items' => array_map('parity_waitlist_item', $q->fetchAll()),
        'statuses' => PARITY_WAITLIST_STATUSES, 'modalities' => ['In person', 'Remote', 'Both']]);
}

case 'waitlist_update': {
    $parityRequirePost();
    require_once __DIR__ . '/workflows.php';
    if (!workflows_ready()) fail('Apply migration 3.0.16 to enable workflows.', 503);
    // workflow_waitlist_save() does the page's validation, caseload check, version
    // check, task completion and audit (referral.waitlist). The page's form sends every
    // field; the app may send only what it changes, so a field left out keeps the
    // entry's saved value, and a left-out version is the saved one (the app has no
    // stale copy to protect) — a version it does send is checked as on the page.
    $referralId = (int)($input['referral_id'] ?? 0);
    if ($referralId <= 0) fail('Choose a referral.', 422);
    $cur = db()->prepare('SELECT * FROM referral_waitlist WHERE referral_id = ?');
    $cur->execute([$referralId]);
    $saved = $cur->fetch() ?: [];
    $in = ['referral_id' => $referralId, 'version' => array_key_exists('version', $input) ? (int)$input['version'] : (int)($saved['version'] ?? 0)];
    // 'notes' is the app's name for the entry's free text, the Client availability field.
    if (array_key_exists('notes', $input) && !array_key_exists('availability', $input)) $input['availability'] = $input['notes'];
    foreach (['status' => 'Waiting', 'modality' => 'Both', 'expertise' => '', 'languages' => '', 'areas' => '', 'availability' => '', 'followup_date' => ''] as $k => $default) {
        $in[$k] = array_key_exists($k, $input) ? (is_scalar($input[$k]) || $input[$k] === null ? (string)$input[$k] : $input[$k]) : (string)($saved[$k] ?? $default);
    }
    try {
        workflow_waitlist_save($in, $userId);
    } catch (DomainException $e) {
        $msg = $e->getMessage();
        fail($msg, str_starts_with($msg, 'Someone else saved') ? 409 : ($msg === 'Referral is unavailable on your queue.' ? 404 : ($msg === 'Referral access is required.' ? 403 : 422)));
    }
    $row = db()->prepare('SELECT r.id, r.client_name, r.created_at AS received_at, w.* FROM referrals r JOIN referral_waitlist w ON w.referral_id = r.id WHERE r.id = ?');
    $row->execute([$referralId]);
    $item = parity_waitlist_item($row->fetch());
    out(['ok' => true, 'item' => $item, 'message' => 'Waitlist entry saved for ' . $item['client_name'] . '. No client was reassigned and no session was booked.']);
}

/* ---------- Documentation review queue (workflows.php?view=review) ---------- */

case 'doc_review': {
    require_once __DIR__ . '/workflows.php';
    if (!workflows_ready()) fail('Apply migration 3.0.16 to enable workflows.', 503);
    // workflow_review_notes(): the newest 1,000 notes the user can see (caseload-scoped
    // as the page), those with unresolved flags. The fingerprint is the note text as
    // flagged, which a review must quote (workflow_acknowledge_note).
    $items = [];
    foreach (workflow_review_notes($userId) as $n) {
        $items[] = ['client_id' => (int)$n['client_id'], 'client_name' => (string)$n['client_name'], 'note_id' => (int)$n['id'],
            'subject' => trim((string)$n['subject']) !== '' ? (string)$n['subject'] : null, 'flagged_for' => implode('; ', $n['flags']), 'flags' => $n['flags'],
            'created_at' => (string)$n['created_at'], 'fingerprint' => workflow_note_fingerprint($n)];
    }
    out(['ok' => true, 'items' => $items]);
}

/* ---------- Session check-in (3.0.28) ---------- */

case 'check_in_session': {
    $parityRequirePost();
    // POST {session_id}: records when the client arrived. The session must be one the
    // schedule action lists (Scheduled, and on the user's caseload when scoped) and
    // its start within the check-in window: from 30 minutes before it to 12 hours
    // after (a late arrival, or a session checked in only once it is over). The
    // first check-in is kept: a second answers 409 with that time.
    $sid = (int)($input['session_id'] ?? 0);
    $st = db()->prepare("SELECT s.id, s.start_at, s.checked_in_at, c.name AS client_name FROM scheduled_sessions s JOIN clients c ON c.id = s.client_id
        WHERE s.id = ? AND s.status = 'Scheduled'" . (caseload_scoped() ? ' AND c.assigned_to = ?' : ''));
    $st->execute(caseload_scoped() ? [$sid, $userId] : [$sid]);
    $sess = $st->fetch();
    if (!$sess) fail('Session not found or already closed.', 404);
    if ($sess['checked_in_at'] !== null) fail('Already checked in at ' . date('g:i A', strtotime((string)$sess['checked_in_at'])) . '.', 409);
    $start = strtotime((string)$sess['start_at']);
    if ($start > time() + 30 * 60) fail('That session starts at ' . date('g:i A', $start) . ' on ' . date('M j', $start) . '; check-in opens 30 minutes before it.', 422);
    if ($start < time() - 12 * 3600) fail('That session was scheduled more than 12 hours ago, so it can no longer be checked in. Record its outcome instead.', 422);
    // Claim: two taps, or a colleague checking in meanwhile, record the first arrival only.
    $claim = db()->prepare('UPDATE scheduled_sessions SET checked_in_at = NOW() WHERE id = ? AND checked_in_at IS NULL');
    $claim->execute([$sid]);
    $at = db()->prepare('SELECT checked_in_at FROM scheduled_sessions WHERE id = ?');
    $at->execute([$sid]);
    $checkedIn = (string)$at->fetchColumn();
    if ($claim->rowCount() !== 1) fail('Already checked in at ' . date('g:i A', strtotime($checkedIn)) . '.', 409);
    audit($userId, 'session.checkin', 'session', $sid, 'mobile');
    out(['ok' => true, 'checked_in_at' => $checkedIn, 'message' => $sess['client_name'] . ' checked in at ' . date('g:i A', strtotime($checkedIn)) . '.']);
}

default:
    fail('Unknown action.', 404);
}
