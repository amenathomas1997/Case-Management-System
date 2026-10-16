<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
$user = require_login();
require_perm('admin');

$userFilter = (int)($_GET['user'] ?? 0);
$clientFilter = (int)($_GET['client'] ?? 0);
// Action: an area (the part of the code before the dot, such as "equipment", listed
// by audit_action_subjects()) or one exact code such as "equipment.loan".
$actionRaw = is_string($_GET['action'] ?? null) ? trim($_GET['action']) : '';
$actionFilter = preg_match('/^[a-z0-9_]+(\.[a-z0-9_.]+)?$/D', $actionRaw) && strlen($actionRaw) <= 60 ? $actionRaw : '';
// A first visit (no filter in the address) shows the last 7 days; clearing the From
// date shows every date. A link that names a filter (a client's history, say) is
// shown over all dates, as before.
$defaultRange = array_intersect(['user', 'client', 'action', 'from', 'to', 'page', 'export'], array_keys($_GET)) === [];
$staff = db()->query('SELECT id, name FROM users ORDER BY name')->fetchAll();
// Every client, archived ones included: their access history stays reviewable.
$clientList = db()->query('SELECT id, name, archived_at FROM clients ORDER BY name, id')->fetchAll();

// Date range (inclusive). A date that cannot be read is named on its field and
// not applied, so the list never quietly answers a different question.
$dateErrs = [];
$range = [];
foreach (['from' => 'From', 'to' => 'To'] as $k => $label) {
    $raw = $_GET[$k] ?? '';
    $range[$k] = valid_date($raw, 2000, (int)date('Y') + 1);
    if ($range[$k] === null && (!is_string($raw) || trim($raw) !== '')) {
        $dateErrs[$k] = $label . ' date could not be read, so it was not applied. Enter a date such as ' . date('Y-m-d') . '.';
    }
}
if ($range['from'] && $range['to'] && $range['from'] > $range['to']) {
    $dateErrs['to'] = 'To date is before the From date, so the dates were not applied. Choose a To date on or after the From date.';
    $range = ['from' => null, 'to' => null];
}
if ($dateErrs) set_form_errors($dateErrs);
if ($defaultRange) $range['from'] = date('Y-m-d', strtotime('-6 days'));

$where = [];
$params = [];
if ($userFilter) { $where[] = 'a.user_id = ?'; $params[] = $userFilter; }
if ($actionFilter !== '') {
    if (str_contains($actionFilter, '.')) { $where[] = 'a.action = ?'; $params[] = $actionFilter; }
    else { $where[] = "a.action LIKE ? ESCAPE '!'"; $params[] = str_replace(['!', '_', '%'], ['!!', '!_', '!%'], $actionFilter) . '.%'; }
}
// A client's file is audited against the client itself (views of documents,
// signatures and reports, notes, edits) and against the records that belong to
// it: tracks (hours, goals added, expenses, authorizations), goals, homework,
// invoices, sessions, AT service records, referrals, tasks, incidents, device
// requests, worksite assessments, access bookings and restored trash items.
// Each child is matched through the table that still ties it to the client.
$mergedIds = [];
if ($clientFilter) {
    $byTrack = fn(string $table): string => "SELECT x.id FROM {$table} x JOIN training_tracks t ON t.id = x.track_id WHERE t.client_id = ?";
    $byClient = fn(string $table): string => "SELECT id FROM {$table} WHERE client_id = ?";
    $clientChildren = [
        'track' => $byClient('training_tracks'), 'goal' => $byTrack('smart_goals'), 'homework' => $byTrack('homework'),
        'invoice' => $byTrack('invoices'), 'session' => $byClient('scheduled_sessions'), 'at_record' => $byClient('at_records'),
        'referral' => $byClient('referrals'), 'task' => $byClient('tasks'), 'incident' => $byClient('incidents'),
        'device_request' => $byClient('device_requests'), 'worksite_assessment' => $byClient('worksite_assessments'),
        'access_booking' => $byClient('access_bookings'), 'work_logs' => $byTrack('work_logs'),
        'track_expenses' => $byTrack('track_expenses'), 'client_documents' => $byClient('client_documents'),
        'client_notes' => $byClient('client_notes'),
        // Documentation reviews (inc/workflows.php) are logged against one note as 'client_note'.
        'client_note' => $byClient('client_notes'),
    ];
    // A duplicate merged into this client was deleted, but what was done under
    // its own id stays logged there; the merge entry ("merged #N ...") names
    // it, and a merged record may itself have absorbed earlier duplicates.
    $clientIds = [$clientFilter];
    $mergeQ = db()->prepare("SELECT detail FROM audit_log WHERE action = 'client.merge' AND entity = 'client' AND entity_id = ?");
    for ($i = 0; $i < count($clientIds) && $i < 200; $i++) {
        $mergeQ->execute([$clientIds[$i]]);
        foreach ($mergeQ->fetchAll(PDO::FETCH_COLUMN) as $mergeDetail) {
            if (preg_match('/^merged #(\d+)/', (string)$mergeDetail, $m) && !in_array((int)$m[1], $clientIds, true)) $clientIds[] = (int)$m[1];
        }
    }
    $mergedIds = array_slice($clientIds, 1);
    $or = ['(a.entity = \'client\' AND a.entity_id IN (' . implode(',', array_fill(0, count($clientIds), '?')) . '))'];
    array_push($params, ...$clientIds);
    foreach ($clientChildren as $entity => $sql) {
        $or[] = "(a.entity = '{$entity}' AND a.entity_id IN ({$sql}))";
        $params[] = $clientFilter;
    }
    $where[] = '(' . implode(' OR ', $or) . ')';
}
if ($range['from']) { $where[] = 'a.created_at >= ?'; $params[] = $range['from']; }
if ($range['to']) { $where[] = 'a.created_at < DATE_ADD(?, INTERVAL 1 DAY)'; $params[] = $range['to']; }
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
$filterQs = array_filter(['user' => $userFilter ?: null, 'client' => $clientFilter ?: null,
    'action' => $actionFilter !== '' ? $actionFilter : null,
    'from' => $range['from'], 'to' => $range['to']], fn($v) => $v !== null);
// Every page link carries the dates, so a cleared From date stays cleared (an empty
// "from" in the address is what turns the 7-day default off).
$pageQs = $filterQs + ['from' => '', 'to' => ''];

/* CSV export of every entry matching the filters (administrators only, like the page). */
if (($_GET['export'] ?? '') === 'csv' && !$dateErrs) {
    $exportCap = 100000;
    $ex = db()->prepare('SELECT a.*, u.name AS user_name FROM audit_log a LEFT JOIN users u ON u.id = a.user_id'
        . $whereSql . ' ORDER BY a.id DESC LIMIT ' . ($exportCap + 1));
    $ex->execute($params);
    audit($user['id'], 'audit.export', 'audit_log', null,
        'CSV' . ($filterQs ? ' (' . http_build_query($filterQs, '', ', ') . ')' : ' (all entries)'));
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="audit_log_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    write_csv_row($out, ['When', 'Who', 'Action', 'Action code', 'Record type', 'Record #', 'Detail', 'IP address']);
    $n = 0;
    while ($e = $ex->fetch()) {
        if (++$n > $exportCap) {
            // Never cut short without saying so.
            write_csv_row($out, ['Only the newest ' . $exportCap . ' matching entries are included. Choose a narrower date range to download the rest.']);
            break;
        }
        write_csv_row($out, [$e['created_at'], csv_cell($e['user_name'] ?? 'System'),
            csv_cell(audit_action_label((string)$e['action'])), csv_cell($e['action']),
            csv_cell($e['entity'] ?? ''), $e['entity_id'] ?? '', csv_cell($e['detail'] ?? ''), csv_cell($e['ip'] ?? '')]);
    }
    fclose($out);
    exit;
}

$perPage = 200;
$cnt = db()->prepare('SELECT COUNT(*) FROM audit_log a' . $whereSql);
$cnt->execute($params);
$total = (int)$cnt->fetchColumn();
$pages = max(1, (int)ceil($total / $perPage));
$page = min($pages, max(1, (int)($_GET['page'] ?? 1)));
$stmt = db()->prepare('SELECT a.*, u.name AS user_name FROM audit_log a LEFT JOIN users u ON u.id = a.user_id'
    . $whereSql . ' ORDER BY a.id DESC LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage));
$stmt->execute($params);
$entries = $stmt->fetchAll();
$pageUrl = fn(int $p): string => 'audit.php?' . http_build_query($pageQs + ['page' => $p]);

// login_attempts doubles as the store for rate-limiter buckets (ai-12, tts-12,
// voice-12, portal-msg-7, vrc-send-3, vrcportal-<hash>, referral-portal,
// pw-reset …), written as success=1 and none holding an email address. The one
// exception is "vrc-fail-<id>" (a wrong counselor-portal code), written as
// success=0 by design so it is listed below as a counselor-portal code failure.
// Filter them in SQL so the 100-row window holds only real sign-in attempts:
// every failure (password step "login:<email>", "verify:<email>" code and "reauth:<email>"
// password re-check failures, and those counselor-portal code failures), and
// successes keyed by an email address. Any future success=1 bucket is excluded
// the same way without being listed here. The longest look-back is 24 hours
// (the daily caps on "vrc-send-<id>" and "portal-up-<id>"), so the 30-day prune
// in cron/daily_alerts.php never removes a row a limiter still counts.
$logins = db()->query("SELECT * FROM login_attempts
    WHERE (success = 0 OR email LIKE '%@%') AND email NOT LIKE 'pw-reset:%'
    ORDER BY id DESC LIMIT 100")->fetchAll();

/** The kind of record an entry is about, in words rather than a table name (3.1.3). */
function audit_entity_label(string $entity): string {
    static $names = [
        'client' => 'Client record', 'training_track' => 'Training track', 'track' => 'Training track', 'work_log' => 'Hours entry',
        'client_note' => 'Case note', 'note' => 'Case note', 'client_documents' => 'Client document', 'client_document' => 'Client document',
        'invoice' => 'Invoice', 'equipment' => 'Equipment item', 'user' => 'Team member', 'referral' => 'Referral',
        'access_booking' => 'Accommodation booking', 'incident' => 'Incident', 'contract' => 'Contract', 'cohort' => 'Cohort',
        'at_record' => 'AT record', 'task' => 'Task', 'message' => 'Message', 'scheduled_session' => 'Scheduled session',
        'session' => 'Scheduled session', 'homework' => 'Homework', 'smart_goal' => 'Goal', 'goal' => 'Goal', 'track_expense' => 'Expense',
        'device_request' => 'Device request', 'worksite_assessment' => 'Worksite assessment', 'consent_form' => 'Consent form',
        'kb_article' => 'Knowledge base article', 'setting' => 'Setting', 'group' => 'Permission group', 'template' => 'Template',
    ];
    if ($entity === '') return '';
    return $names[$entity] ?? ucfirst(str_replace('_', ' ', $entity));
}
/** The areas an action code starts with (the part before the dot), in words; the
 *  action filter lists them, and audit_action_label() names each entry with them. */
function audit_action_subjects(): array {
    return [
        'ai' => 'AI assistant', 'at' => 'Assistive technology services', 'assessment' => 'Assessment',
        'assessment_template' => 'Assessment template', 'auth' => 'Authorization', 'booking' => 'Booking',
        'bundle' => 'Service bundle', 'calfeed' => 'Calendar subscription', 'client' => 'Client', 'cohort' => 'Cohort',
        'competency' => 'Competency', 'consent' => 'Consent form', 'contract' => 'Contract', 'curriculum' => 'Curriculum',
        'device' => 'Device procurement', 'document' => 'Document', 'documentation' => 'Documentation',
        'email_template' => 'Email template',
        'equipment' => 'Equipment', 'evalreport' => 'Evaluation report', 'expense' => 'Expense',
        'federation' => 'Microsoft sign-in settings', 'followup' => 'Follow-up', 'goal' => 'Goal',
        'goal_template' => 'Goal template', 'group' => 'Permission group', 'homework' => 'Homework', 'hours' => 'Hours',
        'incident' => 'Incident', 'instrument' => 'Assessment instrument', 'invoice' => 'Invoice', 'kb' => 'Knowledge base',
        'login' => 'Sign-in', 'message' => 'Message', 'note' => 'Case note', 'passkey' => 'Passkey', 'password' => 'Password',
        'portal' => 'Client portal', 'portal_request' => 'Client portal request', 'reauth' => 'Password re-check',
        'referral' => 'Referral', 'referral_form' => 'Referral form settings', 'report' => 'Report',
        'retention' => 'Record retention', 'session' => 'Session', 'signature' => 'Signature', 'task' => 'Task',
        'totp' => 'Authenticator app', 'track' => 'Training track', 'trash' => 'Trash', 'user' => 'Team member',
        'vrcportal' => 'Counselor portal', 'worksite' => 'Worksite',
        'release' => 'Release of information', 'privacy_incident' => 'Privacy incident', 'vendor' => 'Vendor agreement', 'note_lock' => 'Case note lock',
    ];
}
/** Plain wording for a stored action code such as "equipment.loan" (display only;
 *  the stored codes are unchanged). Unknown codes are shown as stored. */
function audit_action_label(string $code): string {
    $subjects = audit_action_subjects();
    static $verbs = [
        'add' => 'added', 'create' => 'created', 'update' => 'updated', 'edit' => 'edited', 'save' => 'saved',
        'delete' => 'deleted', 'remove' => 'removed', 'send' => 'sent', 'view' => 'viewed', 'status' => 'status changed',
        'toggle' => 'turned on or off', 'issue' => 'issued', 'return' => 'returned', 'ask' => 'question asked',
        'draft' => 'draft written', 'ai.draft' => 'AI draft written', 'risk' => 'risk check', 'config' => 'settings changed',
        'enable' => 'turned on', 'disable' => 'turned off', 'regen' => 'address created or replaced',
        'lockout' => 'locked after repeated failures', 'sso_fail' => 'Microsoft sign-in failed',
        'passkey_replay' => 'passkey response refused as a possible replay', 'revoke' => 'access revoked',
        'revoke_app' => 'mobile app signed out', 'reset' => 'reset', 'reset_link' => 'reset link sent',
        'clear_lockout' => 'lockout cleared', 'calfeed_clear' => 'calendar subscription address turned off', 'sso_bind' => 'linked to a Microsoft account', 'provision' => 'created by Microsoft sign-in',
        'transfer' => 'caseload transferred', 'group' => 'permission group changed', 'hours' => 'weekly hours changed',
        'change' => 'changed', 'test' => 'connection tested', 'log' => 'logged', 'complete' => 'completed', 'done' => 'completed',
        'assign' => 'assigned', 'reassign' => 'reassigned', 'convert' => 'converted to a client', 'review' => 'reviewed',
        'download' => 'downloaded', 'upload' => 'uploaded', 'purge' => 'permanently removed', 'restore' => 'restored',
        'archive' => 'archived', 'unarchive' => 'returned from the archive', 'export' => 'exported', 'merge' => 'merged',
        'amend' => 'amended', 'scored' => 'scored', 'loan' => 'loaned', 'reserve' => 'reserved', 'redact' => 'redacted',
        'schedule' => 'scheduled', 'snooze' => 'snoozed', 'skip' => 'skipped', 'request' => 'requested',
        'open' => 'opened',
        'assess' => 'risk assessed', 'person' => 'affected person added', 'notice' => 'notice recorded', 'followup' => 'follow-up recorded',
        'close' => 'closed', 'reopen' => 'reopened',
        'addendum' => 'addendum added', 'hold' => 'hold placed or lifted', 'privacy_notice' => 'privacy notice given',
        'purge_auto' => 'emptied automatically after 30 days', 'paper' => 'paper copy recorded', 'withdraw' => 'withdrawn', 'set' => 'set',
    ];
    $dot = strpos($code, '.');
    if ($dot === false || !isset($subjects[substr($code, 0, $dot)])) return $code;
    $rest = substr($code, $dot + 1);
    return $subjects[substr($code, 0, $dot)] . ': ' . ($verbs[$rest] ?? str_replace(['_', '.'], ' ', $rest));
}

/** Plain wording for a sign-in attempt's key: an email address, or a limiter key. */
function audit_login_label(string $key): string {
    if (preg_match('/^vrc-fail-(\d+)$/', $key, $m)) return 'Counselor portal code failure (counselor link #' . $m[1] . ')';
    if (str_starts_with($key, 'login:')) return substr($key, 6);
    if (str_starts_with($key, 'verify:')) return 'Sign-in code: ' . substr($key, 7);
    if ($key === 'verify-anon') return 'Sign-in code sent with no sign-in pending (counted against the sender only)';
    if (str_starts_with($key, 'reauth:')) return 'Password re-check: ' . substr($key, 7);
    return $key;
}

page_header('Audit log', $user, 'admin', [['admin.php', 'Administration']]);
?>
<h1>Audit log</h1>

<?php /* No novalidate: a half-typed date is sent blank, which the server cannot tell
   from an empty field, so the browser's own check must stop it (session-forms.js
   only guards POST forms). */ ?>
<form method="get" action="audit.php" class="stacked" role="search" aria-label="Filter audit log">
  <?= form_error_summary(['from' => 'From date', 'to' => 'To date']) ?>
  <div class="field">
    <label for="user">Team member</label>
    <select id="user" name="user">
      <option value="0">Everyone</option>
      <?php foreach ($staff as $s): ?>
      <option value="<?= (int)$s['id'] ?>" <?= $userFilter === (int)$s['id'] ? 'selected' : '' ?>><?= h($s['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="field">
    <label for="action">Action</label>
    <select id="action" name="action">
      <option value="">Any action</option>
      <?php $actionAreas = audit_action_subjects(); asort($actionAreas);
      // An exact code reached from a link stays selected rather than silently widening.
      if ($actionFilter !== '' && !isset($actionAreas[$actionFilter])): ?>
      <option value="<?= h($actionFilter) ?>" selected><?= h(audit_action_label($actionFilter)) ?></option>
      <?php endif; ?>
      <?php foreach ($actionAreas as $aKey => $aLabel): ?>
      <option value="<?= h($aKey) ?>" <?= $actionFilter === $aKey ? 'selected' : '' ?>><?= h($aLabel) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="field">
    <label for="client">Client record</label>
    <p class="hint" id="client-hint">Views of their documents, signatures and reports, and changes to their file and to its linked records: tracks, hours, goals, homework, invoices, sessions, AT services, referrals, tasks, incidents, device requests, bookings and documentation reviews of their notes. Entries made under a duplicate record later merged into this one are included. Entries for a linked record that has since been deleted (such as a voided draft invoice) are not matched.</p>
    <select id="client" name="client" aria-describedby="client-hint">
      <option value="0">Any record</option>
      <?php foreach ($clientList as $c): ?>
      <option value="<?= (int)$c['id'] ?>" <?= $clientFilter === (int)$c['id'] ? 'selected' : '' ?>><?= h($c['name']) ?><?= $c['archived_at'] ? ' (archived)' : '' ?> — #<?= (int)$c['id'] ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="field">
    <label for="from">From date</label>
    <span class="hint" id="from-hint">The log opens on the last 7 days. Clear this date to see every date.</span>
    <input type="date" id="from" name="from" value="<?= h($range['from'] ?? (is_string($_GET['from'] ?? null) ? $_GET['from'] : '')) ?>" <?= field_aria('from', 'from-hint') ?>>
    <?= field_error('from') ?>
  </div>
  <div class="field">
    <label for="to">To date <span class="hint" id="to-hint">included</span></label>
    <input type="date" id="to" name="to" value="<?= h($range['to'] ?? (is_string($_GET['to'] ?? null) ? $_GET['to'] : '')) ?>" <?= field_aria('to', 'to-hint') ?>>
    <?= field_error('to') ?>
  </div>
  <div class="page-actions">
    <button type="submit" class="btn btn-secondary btn-sm">Apply filters</button>
    <button type="submit" name="export" value="csv" class="btn btn-secondary btn-sm">Download matching entries (CSV)</button>
    <?php if (!$defaultRange): ?><a href="audit.php" class="btn btn-secondary btn-sm">Reset filters to the last 7 days</a><?php endif; ?>
    <?php if ($range['from'] || $range['to']): ?><a href="<?= h('audit.php?' . http_build_query(array_diff_key($filterQs, ['from' => 1, 'to' => 1]) + ['from' => '', 'to' => ''])) ?>" class="btn btn-secondary btn-sm">Show every date</a><?php endif; ?>
  </div>
</form>

<section class="card" aria-labelledby="act-h">
  <h2 id="act-h">Activity</h2>
  <p id="act-count"><?= $total
      ? 'Showing entries ' . (($page - 1) * $perPage + 1) . ' to ' . (($page - 1) * $perPage + count($entries)) . ' of ' . $total . ', newest first' . ($filterQs ? ', matching the filters' : '') . '.'
      : ($filterQs ? 'No activity matches these filters.' : 'No activity recorded yet.') ?>
     <?= $mergedIds ? ' Includes entries made under the merged duplicate record' . (count($mergedIds) === 1 ? ' #' : 's #') . implode(', #', $mergedIds) . '.' : '' ?></p>
  <?php if ($entries): ?>
  <div class="table-scroll" tabindex="0" role="region" aria-labelledby="act-cap">
  <table>
    <caption class="visually-hidden" id="act-cap">Audit log, page <?= $page ?> of <?= $pages ?>: when, who, action, record, detail and IP address</caption>
    <thead><tr><th scope="col">When</th><th scope="col">Who</th><th scope="col">Action</th><th scope="col">Record</th><th scope="col">Detail</th><th scope="col">IP address</th></tr></thead>
    <tbody>
    <?php foreach ($entries as $e): ?>
      <tr>
        <td><?= fmt_datetime($e['created_at']) ?></td>
        <td><?= h($e['user_name'] ?? 'System') ?></td>
        <td><?= h(audit_action_label((string)$e['action'])) ?></td>
        <td><?= h(trim(audit_entity_label((string)($e['entity'] ?? '')) . ' #' . ($e['entity_id'] ?? ''), ' #')) ?></td>
        <td><?= h($e['detail'] ?? '') ?></td>
        <td class="muted"><?= h($e['ip'] ?? '') ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
  <?php if ($pages > 1): ?>
  <nav aria-label="Audit log pages" class="page-actions">
    <?php if ($page > 1): ?><a href="<?= h($pageUrl($page - 1)) ?>">Newer entries (page <?= $page - 1 ?>)</a><?php endif; ?>
    <span>Page <?= $page ?> of <?= $pages ?></span>
    <?php if ($page < $pages): ?><a href="<?= h($pageUrl($page + 1)) ?>">Older entries (page <?= $page + 1 ?>)</a><?php endif; ?>
  </nav>
  <?php endif; ?>
</section>

<section class="card" aria-labelledby="login-h">
  <h2 id="login-h">Sign-in attempts (latest 100)</h2>
  <div class="table-scroll" tabindex="0" role="region" aria-labelledby="login-cap">
  <table>
    <caption class="visually-hidden" id="login-cap">Sign-in attempts: when, email or check, result and IP address</caption>
    <thead><tr><th scope="col">When</th><th scope="col">Email or check</th><th scope="col">Result</th><th scope="col">IP address</th></tr></thead>
    <tbody>
    <?php foreach ($logins as $l): ?>
      <tr>
        <td><?= fmt_datetime($l['attempted_at']) ?></td>
        <td><?= h(audit_login_label((string)$l['email'])) ?></td>
        <td><?= $l['success'] ? '<span class="badge badge-closed">Success</span>' : '<span class="badge badge-hold">Failed</span>' ?></td>
        <td class="muted"><?= h($l['ip']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</section>
<?php page_footer();
