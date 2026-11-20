<?php
declare(strict_types=1);
// The apps' 4.38.0 actions: billing and administration the website already had. Several draft
// invoices submitted and one invoice's accessible document (inc/billing.php, inc/invoice_document.php),
// incoming authorization emails (inc/authorization_email.php), the booking form's choices
// (inc/session_plan.php, inc/meetings.php), and the data-quality and documentation audits
// (inc/reports_extra.php). Called from api.php for an action it does not handle; null means "not
// mine". Each action calls the helper its website page calls, so the rules, sentences and audit are
// the same; scope checks are the page's. The create and send actions are in API_IDEM_ACTIONS.

/** Each action's permission. */
const API_438_PERMS = [
    'submit_invoices' => 'billing', 'invoice_document' => 'billing',
    'incoming_authorizations' => 'clients', 'incoming_authorization' => 'clients', 'read_authorization_email' => 'clients',
    'start_authorization_track' => 'clients', 'dismiss_authorization' => 'clients',
    'data_quality' => 'clients', 'doc_audit' => 'clients',
    'booking_options' => 'schedule',
];

/** The actions that only read (GET). */
const API_438_READS = ['invoice_document', 'incoming_authorizations', 'incoming_authorization', 'booking_options', 'data_quality', 'doc_audit'];

/** The API's own sentence for an authorization email this user may not see (the website shows nothing). */
const API_438_AUTH_MISSING = 'That authorization email is not on Incoming authorizations, or it is not on your caseload.';

/** An authorization email's review as the apps read it (incoming_authorization_review(), the page's). */
function api_438_auth_review(array $a, array $user): array {
    $rv = incoming_authorization_review($a, $user);
    return ['ok' => true, 'id' => (int)$a['id'], 'auth_number' => (string)$a['auth_number'], 'status' => (string)$a['status'],
        'track_id' => $a['track_id'] !== null ? (int)$a['track_id'] : null, 'status_text' => $rv['status_text'], 'not_approved' => $rv['not_approved'],
        'already' => $rv['already'], 'details' => array_map(fn($d) => ['label' => $d[0], 'value' => $d[1]], $rv['details']),
        'matches' => array_map(fn($m) => ['id' => $m[0], 'text' => $m[1]], $rv['matches']), 'preset_client_id' => $rv['preset_client_id'],
        'other_clients' => array_map(fn($o) => ['id' => $o[0], 'name' => $o[1]], $rv['other_clients']), 'counselor' => $rv['counselor'],
        'email_text' => (string)$a['email_text']];
}

/** The track form's starting values as text, as the apps fill their forms. */
function api_438_prefill(array $prefill): array {
    return array_map(fn($v) => (string)$v, $prefill);
}

/**
 * A repeated send or create (the same idem_key, after a lost reply): api_idem_end keeps only the
 * first reply's short values, so the longer message and lists are rebuilt here and the app shows
 * what it showed the first time. Called by the retry guard (inc/api_parity.php) after
 * api_437_repeat; other actions get $prev unchanged.
 */
function api_438_repeat(string $action, array $input, int $userId, array $prev): array {
    $user = ['id' => $userId];
    if ($action === 'submit_invoices') {
        // The invoices the first send changed are Submitted now; any it refused (created after the date)
        // are still Drafts in scope, so the same sentence is rebuilt from them.
        $n = (int)($prev['changed'] ?? 0);
        $raw = is_string($input['submitted_on'] ?? null) ? trim($input['submitted_on']) : '';
        $refused = [];
        if ($raw !== '') {
            $ids = array_slice(array_values(array_unique(array_filter(array_map('intval', is_array($input['invoice_ids'] ?? null) ? $input['invoice_ids'] : [])))), 0, 200);
            $scoped = caseload_scoped();
            $q = db()->prepare("SELECT i.invoice_number, i.created_at FROM invoices i JOIN training_tracks t ON t.id = i.track_id JOIN clients c ON c.id = t.client_id
                WHERE i.id = ? AND i.status = 'Draft'" . ($scoped ? ' AND c.assigned_to = ?' : ''));
            foreach ($ids as $id) {
                $q->execute(array_merge([$id], $scoped ? [$userId] : []));
                if (($r = $q->fetch()) && billing_date_in($raw, (string)$r['created_at']) === null) $refused[] = (string)$r['invoice_number'];
            }
        }
        // Those it skipped as no longer drafts are the first reply's own list (kept with it).
        $skipped = is_string($prev['skipped_numbers'] ?? null) && $prev['skipped_numbers'] !== '' ? explode(', ', $prev['skipped_numbers']) : [];
        return ['ok' => true, 'changed' => $n, 'refused' => $refused, 'skipped' => $skipped, 'skipped_numbers' => implode(', ', $skipped),
            'message' => invoice_bulk_message($n, $refused, $skipped), 'repeat' => true];
    }
    if ($action === 'read_authorization_email') {
        $id = isset($prev['id']) && $prev['id'] !== null ? (int)$prev['id'] : null;
        // The out-of-scope sentence is longer than a kept value: it is said again from the email's number.
        $p = $id === null ? authorization_email_parse(is_string($input['email_text'] ?? null) ? trim($input['email_text']) : '') : null;
        return ['ok' => true, 'id' => $id, 'message' => $p ? 'Authorization ' . $p['auth_number'] . ' was read, but its client is not on your caseload. An administrator can place it.' : null, 'repeat' => true];
    }
    if ($action === 'start_authorization_track') {
        // Rebuilt from the first reply's own ids (the email and the client), each checked again in the
        // caller's scope; the notices from the first reply's flags. Out of scope: only the ids kept.
        $a = incoming_authorization_for((int)($prev['id'] ?? 0), $user);
        $cid = (int)($prev['client_id'] ?? 0);
        if (!$a || $cid <= 0 || !api_client_scope_ok($cid, $userId)) return ['ok' => true, 'id' => (int)($prev['id'] ?? 0), 'client_id' => $cid, 'repeat' => true];
        $cn = db()->prepare('SELECT id, name, counselor_email FROM clients WHERE id = ?');
        $cn->execute([$cid]);
        $client = $cn->fetch() ?: ['name' => '', 'counselor_email' => ''];
        return ['ok' => true, 'id' => (int)$a['id'], 'client_id' => $cid,
            'notices' => incoming_authorization_start_notices($a, $client, !empty($prev['counselor_kept']), !empty($prev['counselor_unconfirmed'])),
            'counselor_kept' => !empty($prev['counselor_kept']), 'counselor_unconfirmed' => !empty($prev['counselor_unconfirmed']),
            'prefill' => api_438_prefill(authorization_track_prefill($a, $cid)),
            'heading' => incoming_authorization_heading((string)$client['name'], (string)$a['auth_number']), 'repeat' => true];
    }
    if ($action === 'dismiss_authorization') {
        // The first reply's email, checked again in the caller's scope.
        $a = incoming_authorization_for((int)($prev['id'] ?? 0), $user);
        return ['ok' => true, 'id' => (int)($prev['id'] ?? 0), 'message' => $a ? 'Authorization ' . $a['auth_number'] . ' dismissed.' : 'That authorization email was dismissed.', 'repeat' => true];
    }
    if ($action === 'send_vrc_portal') {
        // The sentence again, with the name the link was sent under; the link itself is never kept or sent.
        $email = strtolower(trim(is_string($input['email'] ?? null) ? $input['email'] : ''));
        $name = trim(is_string($input['name'] ?? null) ? $input['name'] : '');
        if ($name === '') { $kn = db()->prepare('SELECT counselor_name FROM vrc_tokens WHERE counselor_email = ?'); $kn->execute([$email]); $name = trim((string)$kn->fetchColumn()) ?: 'Counselor'; }
        return ['ok' => true, 'message' => "Portal link emailed to {$name}. Any previous link no longer works.", 'expires_at' => $prev['expires_at'] ?? null, 'repeat' => true];
    }
    return $prev;
}

function api_438(string $action, array $input, int $userId): ?array {
    if (!array_key_exists($action, API_438_PERMS)) return null;
    $perm = API_438_PERMS[$action];
    if (!can($perm)) {
        // Held back only until required agreements or training are done (4.16.0), as api.php says it.
        if (!empty($GLOBALS['ACCESS_HOLD']) && in_array($perm, (array)($GLOBALS['PERMS_BEFORE_HOLD'] ?? []), true)) {
            return ['ok' => false, 'error' => 'Client records open once you finish: ' . implode(', ', $GLOBALS['ACCESS_HOLD']) . '. Open My agreements and My training.', 'status' => 403];
        }
        return ['ok' => false, 'error' => 'Your account does not have access to that area. Ask an administrator if you need it.', 'status' => 403];
    }
    $post = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
    if (!in_array($action, API_438_READS, true) && !$post) return ['ok' => false, 'error' => 'Use POST for changes.', 'status' => 405];
    $uq = db()->prepare('SELECT id, name, email FROM users WHERE id = ?');
    $uq->execute([$userId]);
    $user = $uq->fetch() ?: ['id' => $userId, 'name' => '', 'email' => ''];
    $user['id'] = $userId;
    $q = fn(string $k) => $input[$k] ?? $_GET[$k] ?? null;
    $scopeUid = caseload_scoped() ? $userId : null;

    switch ($action) {
        /* ---- T4: several drafts marked submitted (invoices.php's bulk_submit) ---- */
        case 'submit_invoices': {
            $ids = is_array($input['invoice_ids'] ?? null) ? array_values($input['invoice_ids']) : [];
            // The website's form never offers more than 200; the API says so instead of cutting the list.
            if (count($ids) > 200) return api_435_fields(['invoice_ids' => 'Choose at most 200 invoices at a time.']);
            $ids = array_filter($ids, fn($v) => is_int($v) || (is_string($v) && ctype_digit($v)));
            $r = invoice_bulk_submit($ids, is_string($input['submitted_on'] ?? null) ? $input['submitted_on'] : '', $user, ' (mobile)');
            if (!$r['ok']) return api_435_fields($r['errors']);
            return ['ok' => true, 'changed' => $r['changed'], 'refused' => $r['refused'], 'skipped' => $r['skipped'], 'skipped_numbers' => implode(', ', $r['skipped']), 'message' => $r['message']];
        }

        /* ---- T5: one invoice's accessible document (invoice_pdf.php?format=html) ---- */
        case 'invoice_document': {
            $ctx = invoice_document_load(api_437_id($q('invoice_id')));
            if (is_string($ctx)) return ['ok' => false, 'error' => $ctx, 'status' => 404];
            if (!api_client_scope_ok((int)$ctx['t']['client_id'], $userId)) return ['ok' => false, 'error' => 'That client is not on your caseload.', 'status' => 403];
            $doc = invoice_document($ctx, (string)$user['name']);
            $inv = $ctx['invoice'];
            audit($userId, 'invoice.download', 'invoice', (int)$inv['id'], 'invoice ' . $inv['invoice_number'] . ' (accessible HTML) (mobile)');
            return ['ok' => true, 'title' => 'Invoice ' . $inv['invoice_number'], 'text' => $doc->text(), 'html' => $doc->html(), 'file' => 'invoice_' . (int)$inv['id'] . '.html'];
        }

        /* ---- T6: incoming authorization emails (authorizations.php) ---- */
        case 'incoming_authorizations': {
            $rows = incoming_authorizations_waiting($user);
            return ['ok' => true, 'rows' => array_map(fn($w) => ['id' => (int)$w['id'], 'auth_number' => (string)$w['auth_number'],
                'text' => incoming_authorization_row_text($w), 'matched' => $w['matched_name'] !== null], $rows), 'total' => count($rows)];
        }
        case 'incoming_authorization': {
            $a = incoming_authorization_for(api_437_id($q('id')), $user);
            if (!$a) return ['ok' => false, 'error' => API_438_AUTH_MISSING, 'status' => 404];
            return api_438_auth_review($a, $user);
        }
        case 'read_authorization_email': {
            $text = is_string($input['email_text'] ?? null) ? $input['email_text'] : '';
            if (mb_strlen($text) > 100000) return api_435_fields(['email_text' => 'The authorization email is too long to read.']);
            $r = incoming_authorization_paste($text, $user, ' (mobile)');
            if (!$r['ok']) return api_435_fields($r['errors']);
            return ['ok' => true, 'id' => $r['id'], 'message' => $r['message']];
        }
        case 'start_authorization_track': {
            $a = incoming_authorization_for(api_437_id($input['id'] ?? 0), $user);
            if (!$a) return ['ok' => false, 'error' => API_438_AUTH_MISSING, 'status' => 404];
            if ($a['status'] !== 'New') return ['ok' => false, 'error' => 'That authorization email was already handled.', 'status' => 409];
            $r = incoming_authorization_start($a, api_437_id($input['client_id'] ?? 0), api_bool($input['set_counselor'] ?? false), $user, ' (mobile)');
            if (!$r['ok']) return api_435_fields($r['errors']);
            return ['ok' => true, 'id' => (int)$a['id'], 'client_id' => (int)$r['client']['id'], 'notices' => $r['notices'], 'counselor_kept' => $r['counselor_kept'],
                'counselor_unconfirmed' => $r['counselor_unconfirmed'], 'prefill' => api_438_prefill($r['prefill']), 'heading' => $r['heading']];
        }
        case 'dismiss_authorization': {
            $a = incoming_authorization_for(api_437_id($input['id'] ?? 0), $user);
            if (!$a) return ['ok' => false, 'error' => API_438_AUTH_MISSING, 'status' => 404];
            if ($a['status'] !== 'New') return ['ok' => false, 'error' => 'That authorization email was already handled.', 'status' => 409];
            $r = incoming_authorization_dismiss($a, $user);
            if (!$r['ok']) return ['ok' => false, 'error' => $r['message'], 'status' => 409];
            return ['ok' => true, 'id' => (int)$a['id'], 'message' => $r['message']];
        }

        /* ---- T7, T8: the booking form's choices (schedule.php) ---- */
        case 'booking_options':
            return ['ok' => true, 'plan_patterns' => array_map(fn($k, $l) => ['key' => $k, 'label' => $l], array_keys(PLAN_PATTERNS), PLAN_PATTERNS),
                'weekdays' => array_map(fn($n, $l) => ['n' => $n, 'label' => $l], array_keys(PLAN_WEEKDAYS), PLAN_WEEKDAYS), 'max_sessions' => PLAN_MAX_SESSIONS,
                // Only the providers set up here, as the website's form offers them.
                'meeting_providers' => array_values(array_map(fn($k) => ['key' => $k, 'label' => 'In ' . MEETING_PROVIDERS[$k]], array_filter(array_keys(MEETING_PROVIDERS), fn($k) => meeting_app($k) !== null)))];

        /* ---- T15: data quality and the documentation audit (data_quality.php, doc_audit.php) ---- */
        case 'data_quality': {
            $checks = data_quality_checks($scopeUid);
            // The merge link is website-only: the apps list the pair and open each client.
            return ['ok' => true, 'scoped' => $scopeUid !== null, 'summary' => data_quality_summary($checks, $scopeUid !== null),
                'sections' => array_map(fn($c) => ['key' => $c[0], 'heading' => $c[1], 'explanation' => $c[2],
                    'rows' => array_map(fn($r) => ['client_id' => (int)$r['client_id'], 'name' => (string)$r['name'], 'detail' => (string)$r['detail']], $c[3])], $checks)];
        }
        case 'doc_audit': {
            $d = doc_audit_findings($scopeUid);
            return ['ok' => true, 'scoped' => $scopeUid !== null, 'intro' => $d['intro'], 'active' => $d['active'], 'with_gaps' => count($d['rows']),
                'total_findings' => array_sum(array_map(fn($r) => count($r['findings']), $d['rows'])), 'rows' => $d['rows'],
                'checks' => array_map(fn($c) => implode('', $c), $d['checks']), 'footnote' => $d['footnote'], 'empty' => DOC_AUDIT_EMPTY];
        }
    }
    return null;
}
