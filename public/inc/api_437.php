<?php
declare(strict_types=1);
// The apps' 4.37.0 actions: services, equipment and reports the website already had. An item's
// status changed (inc/equipment_status.php), a device request's justification letter
// (inc/portal_devices.php), the formal AT evaluation report (inc/eval_report.php), worksite AI and
// the employer's report (inc/report_ai.php), assessment templates and attachments
// (inc/tech_assessments.php), amendment requests sent and answered (inc/amendments.php), letters
// (inc/portal_history.php), releases, consent and the privacy notice (inc/records_privacy.php), a
// document to the trash (inc/file_exchange.php), the portal link turned off (inc/client_notices.php),
// the client's timeline (inc/client_timeline.php) and Pre-ETS cohorts (inc/programs.php). Called from
// api.php for an action it does not handle; null means "not mine". Each action calls the helper its
// website page calls, so the rules, sentences, audit and notifications are the same; scope checks
// are the page's. The create and send actions are in API_IDEM_ACTIONS.

/** Each action's permission. */
const API_437_PERMS = [
    'set_equipment_status' => 'equipment',
    'device_letter' => 'procurement',
    'eval_report' => 'reports', 'ai_eval_draft' => 'reports', 'send_eval_report' => 'reports',
    'ai_worksite' => 'clients', 'worksite_report' => 'clients', 'assessment_templates' => 'clients', 'download_assessment_attachment' => 'clients',
    'record_amendment' => 'clients', 'amendment_reply' => 'clients', 'letters' => 'clients', 'ai_letter' => 'clients', 'save_letter' => 'clients',
    'withdraw_release' => 'clients', 'withdraw_consent' => 'clients', 'record_privacy_notice' => 'clients', 'delete_document' => 'clients',
    'revoke_portal_link' => 'clients', 'client_timeline' => 'clients',
    'cohort_choices' => 'programs', 'delete_cohort_session' => 'programs', 'set_cohort_active' => 'programs',
];

/** The actions that only read (GET; eval_report also answers a POST that asks for its HTML). */
const API_437_READS = ['device_letter', 'eval_report', 'worksite_report', 'assessment_templates', 'download_assessment_attachment', 'letters', 'client_timeline', 'cohort_choices'];

/** A whole-number id sent as a number or digits; 0 otherwise. */
function api_437_id(mixed $v): int {
    return is_int($v) ? $v : (is_string($v) && ctype_digit(trim($v)) ? (int)trim($v) : 0);
}

/** One equipment item as equipment_detail sends it: the row, its value as a number, the book value
 *  (0 once retired, as equipment.php) and, for a caseload-scoped user, a device request's note
 *  without the client's name. Null when it is not on file. */
function api_equipment_item(int $eid): ?array {
    $e = db()->prepare('SELECT * FROM equipment WHERE id = ?'); $e->execute([$eid]);
    $item = $e->fetch();
    if (!$item) return null;
    // Items added from a delivered device request before 3.0.8 carry "From device request #N for
    // <client name>" in their notes. Equipment is organisation-wide, so a caseload-scoped user gets
    // the note without the name.
    if (caseload_scoped() && $item['notes'] !== null) {
        $item['notes'] = preg_replace('/^(From device request #\d+) for .*$/s', '$1', (string)$item['notes']);
    }
    // Straight-line depreciated book value (0-life = non-depreciating); 0 once retired, as equipment.php.
    $item['book_value'] = $item['status'] === 'Retired' ? 0.0 : equip_book_value($item['value'] !== null ? (float)$item['value'] : null,
        $item['purchase_date'] ?? null, (int)($item['useful_life_months'] ?? 36));
    return api_numeric_fields($item, ['value']);
}

/** An amendment request row as the apps read it. */
function api_437_amendment_row(array $a): array {
    return ['id' => (int)$a['id'], 'requested_on' => (string)$a['requested_on'], 'status' => (string)$a['status'], 'text' => amendment_row_text($a),
        'awaiting' => $a['status'] === 'Awaiting reply', 'needs_change' => amendment_needs_change($a),
        'reason_prefill' => in_array($a['status'], ['Approved', 'Partly approved'], true) ? amendment_reason_prefill($a) : null];
}

/** What the amendment reply adds for recording the request and its reply (4.37.0): the history, the
 *  waiting sentence, the reply choices, who it goes to by default and today. $track needs
 *  counselor_name, counselor_email and counselor_verified. */
function api_amendment_history_reply(int $trackId, array $track): array {
    $email = trim((string)($track['counselor_email'] ?? ''));
    $verified = $email !== '' && (int)($track['counselor_verified'] ?? 0) === 1;
    $waiting = amendment_waiting_text($trackId);
    return ['history' => array_map('api_437_amendment_row', amendment_history($trackId)), 'waiting_text' => $waiting !== '' ? $waiting : null,
        'reply_statuses' => AMENDMENT_REPLY_STATUSES, 'sent_to_default' => trim(($track['counselor_name'] ?? '') . ($verified ? ' <' . $email . '>' : '')), 'today' => date('Y-m-d')];
}

/** The evaluation report's sections as the apps read them. */
function api_437_eval_sections(array $a, array $fields): array {
    $defaults = eval_defaults($a);
    $out = [];
    foreach (EVAL_SECTIONS as $k => $label) $out[] = ['key' => $k, 'label' => $label, 'text' => (string)$fields[$k], 'default' => eval_same_text((string)$fields[$k], $defaults[$k])];
    return $out;
}

/** One tech assessment for the report, in the caller's scope (as eval_report.php's scope check). */
function api_437_eval_load(array $input, int $userId): array {
    $aid = api_437_id($input['assessment_id'] ?? $_GET['assessment_id'] ?? 0);
    $a = eval_load($aid);
    if (!$a) fail('Assessment not found.', 404);
    api_require_client((int)$a['cid'], $userId);
    return $a;
}

/** The posted sections ({background, findings, recommendations, justification}); a section not sent
 *  keeps its prefilled text. */
function api_437_eval_posted(array $input): array {
    $sec = is_array($input['sections'] ?? null) ? $input['sections'] : [];
    return array_filter(array_intersect_key($sec, EVAL_SECTIONS), 'is_string');
}

/** One worksite assessment, as accommodations.php loads it, in the caller's scope. */
function api_437_worksite(int $id, int $userId): array {
    $s = db()->prepare('SELECT wa.*, c.name AS client_name, c.assigned_to, u.name AS assessor
        FROM worksite_assessments wa JOIN clients c ON c.id = wa.client_id LEFT JOIN users u ON u.id = wa.assessor_id WHERE wa.id = ?');
    $s->execute([$id]);
    $wa = $s->fetch();
    if (!$wa) fail('This worksite assessment no longer exists. It may have been deleted on the website. Go back to the list and refresh it.', 404);
    api_require_client((int)$wa['client_id'], $userId);
    return $wa;
}

/** A training track for an amendment, in the caller's scope, with the client and counselor. */
function api_437_track(int $trackId, int $userId): array {
    $tq = db()->prepare('SELECT t.*, c.id AS cid, c.name AS client, c.counselor_name, c.counselor_email, c.counselor_agency, c.counselor_verified
        FROM training_tracks t JOIN clients c ON c.id = t.client_id WHERE t.id = ?');
    $tq->execute([$trackId]);
    $track = $tq->fetch();
    if (!$track) fail('Training track not found.', 404);
    api_require_client((int)$track['cid'], $userId);
    return $track;
}

/** The client's privacy notice as the apps read it. */
function api_437_privacy_notice(int $clientId): array {
    $q = db()->prepare('SELECT privacy_notice_at, privacy_notice_how FROM clients WHERE id = ?');
    $q->execute([$clientId]);
    $c = $q->fetch() ?: ['privacy_notice_at' => null, 'privacy_notice_how' => null];
    return ['given_at' => $c['privacy_notice_at'] !== null ? (string)$c['privacy_notice_at'] : null, 'how' => $c['privacy_notice_how'] !== null ? (string)$c['privacy_notice_how'] : null,
        'text' => privacy_notice_text($c)];
}

/** What the client reply adds (4.37.0): each release's line and whether it can be withdrawn, the privacy
 *  notice and its methods, the portal link's state and confirmation, and the consent shown. */
function api_437_client_extra(int $cid): array {
    $cq = db()->prepare('SELECT id, name, email, status, a11y_contact FROM clients WHERE id = ?');
    $cq->execute([$cid]);
    $client = $cq->fetch() ?: ['id' => $cid, 'name' => '', 'email' => '', 'status' => '', 'a11y_contact' => ''];
    $state = portal_link_state($cid);
    $consent = consent_latest_signed($cid);
    return [
        'privacy_notice' => api_437_privacy_notice($cid), 'privacy_notice_methods' => PRIVACY_NOTICE_METHODS,
        'portal_link' => $state + ['confirm_text' => portal_link_confirm_text($client)],
        'consent_signed' => $consent ? ['id' => (int)$consent['id'], 'signed_name' => (string)$consent['signed_name'], 'signed_at' => (string)$consent['signed_at'],
            'withdrawn' => $consent['withdrawn_at'] !== null] : null,
        'signed_id' => $consent ? (int)$consent['id'] : null,
    ];
}

/** The repeat sentence for a letter saved twice (letters.php's). */
function api_437_letter_repeat(string $how): string {
    return $how === 'note' ? 'This letter was already kept as a case note (the form was sent twice), so it was kept once.'
        : 'This letter was already saved to the client\'s documents (the form was sent twice), so it was saved once.';
}

/**
 * A repeated send or create (the same idem_key, after a lost reply): api_idem_end keeps only the first
 * reply's short values, so the longer message and lists are rebuilt from the database and the app
 * shows what it showed the first time. Called by the retry guard (inc/api_parity.php) after
 * api_436_repeat; other actions get $prev unchanged.
 */
function api_437_repeat(string $action, array $input, int $userId, array $prev): array {
    if ($action === 'send_eval_report') {
        // The first reply's recipient and whether it was logged on the file (both kept with the reply).
        $to = is_string($prev['to'] ?? null) ? $prev['to'] : strtolower(trim(is_string($input['to'] ?? null) ? $input['to'] : ''));
        $logged = !array_key_exists('logged', $prev) || !empty($prev['logged']);
        return ['ok' => true, 'to' => $to, 'logged' => $logged, 'message' => $logged ? eval_sent_message($to) : eval_sent_unlogged_message($to), 'repeat' => true];
    }
    if ($action === 'record_amendment') {
        // The history is the stored request's own track, and only when that track is still the caller's.
        $id = (int)($prev['id'] ?? 0);
        $base = ['ok' => true, 'id' => $id, 'message' => 'Recorded: amendment requested. The letter is on the track as a case note, and a follow-up task is due in 10 business days.', 'repeat' => true];
        $q = db()->prepare('SELECT t.id AS track_id, c.id AS cid, c.counselor_name, c.counselor_email, c.counselor_verified FROM amendment_requests a
            JOIN training_tracks t ON t.id = a.track_id JOIN clients c ON c.id = t.client_id WHERE a.id = ? AND a.created_by = ?');
        $q->execute([$id, $userId]);
        $t = $q->fetch();
        if (!$t || !api_client_scope_ok((int)$t['cid'], $userId)) return $base;
        return $base + api_amendment_history_reply((int)$t['track_id'], $t);
    }
    if ($action === 'save_letter') {
        $how = ($prev['how'] ?? ($input['how'] ?? 'doc')) === 'note' ? 'note' : 'doc';
        return ['ok' => true, 'how' => $how, 'document_id' => $prev['document_id'] ?? null, 'note_id' => $prev['note_id'] ?? null, 'message' => api_437_letter_repeat($how), 'repeat' => true];
    }
    return $prev;
}

function api_437(string $action, array $input, int $userId): ?array {
    if (!array_key_exists($action, API_437_PERMS)) return null;
    $perm = API_437_PERMS[$action];
    if (!can($perm)) {
        // Held back only until required agreements or training are done (4.16.0), as api.php says it.
        if (!empty($GLOBALS['ACCESS_HOLD']) && in_array($perm, (array)($GLOBALS['PERMS_BEFORE_HOLD'] ?? []), true)) {
            return ['ok' => false, 'error' => 'Client records open once you finish: ' . implode(', ', $GLOBALS['ACCESS_HOLD']) . '. Open My agreements and My training.', 'status' => 403];
        }
        return ['ok' => false, 'error' => 'Your account does not have access to that area. Ask an administrator if you need it.', 'status' => 403];
    }
    $post = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
    if (!in_array($action, API_437_READS, true) && !$post) return ['ok' => false, 'error' => 'Use POST for changes.', 'status' => 405];
    $uq = db()->prepare('SELECT id, name, email FROM users WHERE id = ?');
    $uq->execute([$userId]);
    $user = $uq->fetch() ?: ['id' => $userId, 'name' => '', 'email' => ''];
    $user['id'] = $userId;
    $q = fn(string $k) => $input[$k] ?? $_GET[$k] ?? null;

    switch ($action) {
        /* ---- S1: an item's status (equipment.php's Save, through inc/equipment_status.php) ---- */
        case 'set_equipment_status': {
            $eid = api_437_id($input['equipment_id'] ?? 0);
            $item = api_equipment_item($eid);
            if (!$item) return ['ok' => false, 'error' => 'Item not found.', 'status' => 404];
            $to = is_string($input['to'] ?? null) ? $input['to'] : '';
            if (!in_array($to, EQUIPMENT_STATUS_MOVES, true)) return api_435_fields(['to' => 'Choose Available, Repair or Retired.']);
            // As the website's row: an item on loan to another caseload's client is managed by its staff.
            $facts = equipment_status_facts($eid, $item, $userId);
            if (!$facts['manageable']) return ['ok' => false, 'error' => 'This item is managed by the assigned staff.', 'status' => 403];
            $r = equipment_set_status($eid, $to, $userId, api_bool($input['confirm_retire'] ?? false), 'mobile');
            if (!empty($r['needs_confirm'])) {
                $pv = equipment_retire_preview($eid);
                return ['ok' => false, 'error' => 'Confirm retiring ' . $item['name'] . ' first.', 'needs_confirm' => true,
                    'retire' => ['holds' => (int)($pv['holds'] ?? 0), 'text' => (string)($pv['text'] ?? '')], 'status' => 409];
            }
            if (!$r['ok']) return ['ok' => false, 'error' => $r['error'], 'status' => (int)$r['code']];
            $fresh = api_equipment_item($eid);
            return ['ok' => true, 'status' => $r['status'], 'changed' => $r['changed'], 'cancelled' => $r['cancelled'], 'promoted' => $r['promoted'],
                'message' => $r['message'], 'item' => $fresh] + equipment_status_facts($eid, $fresh, $userId);
        }

        /* ---- S4: the funding justification letter (procurement.php's ?letter=) ---- */
        case 'device_letter': {
            $id = api_437_id($q('id'));
            $s = db()->prepare('SELECT dr.*, c.name AS client_name FROM device_requests dr JOIN clients c ON c.id = dr.client_id WHERE dr.id = ?');
            $s->execute([$id]);
            $r = $s->fetch();
            if (!$r) return ['ok' => false, 'error' => 'This device request no longer exists. It may have been deleted on the website. Go back to the list and refresh it.', 'status' => 404];
            api_require_client((int)$r['client_id'], $userId);
            $l = device_letter_doc($r, $user);
            audit($userId, 'device.letter', 'device_request', $id, '(mobile)');
            return ['ok' => true, 'title' => $l['title'], 'text' => $l['text'], 'html' => $l['doc']->html(), 'file' => $l['file']];
        }

        /* ---- S7: the formal AT evaluation report (eval_report.php, through inc/eval_report.php) ---- */
        case 'eval_report': {
            $a = api_437_eval_load($input, $userId);
            $fields = eval_fields($a, api_437_eval_posted($input));
            if ($post && api_bool($input['html'] ?? false)) {
                // The unsigned accessible copy to share, with the sections as edited (website's Download accessible HTML).
                audit($userId, 'evalreport.download', 'client', (int)$a['cid'], 'html (mobile)');
                return ['ok' => true, 'html' => eval_html($a, $fields, (string)$user['name'])->html(), 'file' => eval_file_base($a) . '.html'];
            }
            $verified = (int)($a['counselor_verified'] ?? 0) === 1;
            return ['ok' => true, 'assessment' => ['id' => (int)$a['id'], 'date' => (string)$a['assessment_date'], 'client_id' => (int)$a['cid'], 'client' => (string)$a['client_name']],
                'sections' => api_437_eval_sections($a, $fields), 'to' => $verified ? (string)($a['counselor_email'] ?? '') : '', 'counselor_verified' => $verified,
                'signer' => (string)$user['name'], 'release_warning' => release_warning((int)$a['cid'], 'VR counselor and agency', (string)($a['counselor_email'] ?? '')),
                'ai' => ai_feature_enabled('draft'), 'ai_hint' => EVAL_AI_HINT];
        }
        case 'ai_eval_draft': {
            $a = api_437_eval_load($input, $userId);
            if (!ai_feature_enabled('draft')) return ['ok' => false, 'error' => 'That AI feature is turned off. An administrator can enable it under AI assistant settings.', 'status' => 422];
            $res = eval_ai_draft($a, eval_fields($a, api_437_eval_posted($input)), $userId, 'mobile');
            if (!$res['ok']) return ['ok' => false, 'error' => api_ai_error($res['error']), 'status' => 502];
            return ['ok' => true, 'sections' => api_437_eval_sections($a, $res['sections']), 'drafted' => $res['drafted'], 'kept' => $res['kept'], 'missing' => $res['missing'],
                'message' => $res['message'], 'truncated' => $res['truncated']];
        }
        case 'send_eval_report': {
            $a = api_437_eval_load($input, $userId);
            $r = eval_send($a, eval_fields($a, api_437_eval_posted($input)), is_string($input['to'] ?? null) ? $input['to'] : '', is_string($input['signature'] ?? null) ? $input['signature'] : '', $user, 'mobile');
            if (isset($r['errors'])) return api_435_fields($r['errors']);
            if (!$r['ok']) return ['ok' => false, 'error' => $r['error'], 'status' => (int)$r['code']];
            return ['ok' => true, 'to' => $r['to'], 'message' => $r['message'], 'logged' => !$r['warning']];
        }

        /* ---- S8: worksite recommendations and the employer's report (accommodations.php) ---- */
        case 'ai_worksite': {
            $wa = api_437_worksite(api_437_id($input['id'] ?? 0), $userId);
            if (!ai_feature_enabled('draft')) return ['ok' => false, 'error' => 'That AI feature is turned off. An administrator can enable it under AI assistant settings.', 'status' => 422];
            $in = fn(string $k): string => is_string($input[$k] ?? null) ? trim($input[$k]) : '';
            $cr = db()->prepare('SELECT * FROM clients WHERE id = ?');
            $cr->execute([(int)$wa['client_id']]);
            $res = worksite_ai_recommendations(['job_title' => $in('job_title'), 'job_tasks' => $in('job_tasks'), 'barriers' => $in('barriers')], $cr->fetch() ?: [], $userId);
            if (!$res['ok']) return ['ok' => false, 'error' => api_ai_error((string)$res['error']), 'status' => 502];
            audit($userId, 'ai.draft', 'client', (int)$wa['client_id'], 'worksite recommendations (mobile)');
            // Nothing is saved: the draft goes below what was typed, for the app to save with the assessment.
            return ['ok' => true, 'text' => ai_merge_draft($in('recommendations'), $res['text']), 'draft' => $res['text'], 'truncated' => $res['truncated'],
                'message' => 'Recommendations drafted. Read and edit them, then save the assessment.'];
        }
        case 'worksite_report': {
            $wa = api_437_worksite(api_437_id($q('id')), $userId);
            $rep = worksite_report_doc($wa, $user);
            audit($userId, 'worksite.report', 'worksite_assessment', (int)$wa['id'], '(mobile)');
            return ['ok' => true, 'title' => $rep['title'], 'text' => $rep['text'], 'html' => $rep['doc']->html(), 'file' => $rep['file'],
                'release_warning' => release_warning((int)$wa['client_id'], 'Employer')];
        }

        /* ---- S11: assessment templates and the report attachment (client.php, download.php) ---- */
        case 'assessment_templates':
            return ['ok' => true, 'templates' => array_map(fn($t) => ['id' => (int)$t['id'], 'name' => (string)$t['name'], 'primary_tech' => (string)$t['primary_tech'],
                'hardware_details' => (string)($t['hardware_details'] ?? ''), 'software_version' => (string)($t['software_version'] ?? ''), 'summary' => (string)($t['summary'] ?? '')], assessment_templates_list())];
        case 'download_assessment_attachment': {
            $s = db()->prepare('SELECT attachment_path, attachment_name, client_id FROM tech_assessments WHERE id = ?');
            $s->execute([api_437_id($q('id'))]);
            $a = $s->fetch();
            if (!$a || !$a['attachment_path']) return ['ok' => false, 'error' => 'Attachment not found. It may have been deleted, or moved to the trash.', 'status' => 404];
            api_require_client((int)$a['client_id'], $userId);
            $path = cfg('upload_dir') . '/' . basename((string)$a['attachment_path']);
            if (!is_file($path)) return ['ok' => false, 'error' => 'The file is missing on the server. Ask an administrator to check the uploads folder.', 'status' => 404];
            audit($userId, 'document.view', 'client', (int)$a['client_id'], 'assessment attachment: ' . $a['attachment_name'] . ' (mobile)');
            $mime = ['pdf' => 'application/pdf', 'doc' => 'application/msword', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'txt' => 'text/plain', 'rtf' => 'application/rtf', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg'][strtolower(pathinfo($path, PATHINFO_EXTENSION))] ?? 'application/octet-stream';
            return ['ok' => true, 'name' => (string)$a['attachment_name'], 'mime' => $mime, 'data' => base64_encode((string)file_get_contents($path))];
        }

        /* ---- S13: an amendment request recorded as sent, and its reply (amendment_request.php) ---- */
        case 'record_amendment': {
            $tid = api_437_id($input['track_id'] ?? 0);
            $track = api_437_track($tid, $userId);
            $in = [];
            foreach (['requested_on', 'sent_to', 'hours', 'amount', 'new_end', 'letter'] as $k) $in[$k] = is_string($input[$k] ?? null) ? $input[$k] : (is_int($input[$k] ?? null) || is_float($input[$k] ?? null) ? (string)$input[$k] : '');
            if (mb_strlen(trim($in['sent_to'])) > 190) return api_435_fields(['sent_to' => 'Sent to: use 190 characters or fewer.']);
            [$errs, $newId] = amendment_record($tid, $in, $userId);
            if ($errs) return api_435_fields($errs);
            return ['ok' => true, 'id' => $newId, 'message' => 'Recorded: amendment requested. The letter is on the track as a case note, and a follow-up task is due in 10 business days.']
                + api_amendment_history_reply($tid, $track);
        }
        case 'amendment_reply': {
            $tid = api_437_id($input['track_id'] ?? 0);
            $track = api_437_track($tid, $userId);
            $rid = api_437_id($input['request_id'] ?? 0);
            $status = is_string($input['status'] ?? null) ? $input['status'] : '';
            $on = is_string($input['replied_on'] ?? null) ? $input['replied_on'] : '';
            $err = amendment_reply($rid, $tid, $status, $on, is_string($input['note'] ?? null) ? $input['note'] : '', $userId);
            if ($err === 'Choose the reply.') return api_435_fields(['status' => $err]);
            if ($err !== null && str_starts_with($err, 'Enter the date')) return api_435_fields(['replied_on' => $err]);
            if ($err !== null) return ['ok' => false, 'error' => $err, 'status' => 409];
            $rq = db()->prepare('SELECT * FROM amendment_requests WHERE id = ?');
            $rq->execute([$rid]);
            $row = $rq->fetch() ?: [];
            $needs = in_array($status, ['Approved', 'Partly approved'], true);
            return ['ok' => true, 'request_id' => $rid, 'needs_change' => $needs,
                'message' => 'Reply recorded: ' . strtolower($status) . '.' . ($needs ? ' Now enter the approved hours, dollars or end date on the track.' : ''),
                'reason_prefill' => $needs && $row ? amendment_reason_prefill($row) : ''] + api_amendment_history_reply($tid, $track);
        }

        /* ---- S14: letters (letters.php, through inc/portal_history.php) ---- */
        case 'letters': {
            $cid = api_437_id($q('client_id'));
            api_require_client($cid, $userId);
            $key = is_string($q('key')) && isset(LETTER_TEMPLATES[$q('key')]) ? (string)$q('key') : array_key_first(LETTER_TEMPLATES);
            return ['ok' => true, 'letters' => array_map(fn($k, $t) => ['key' => $k, 'name' => $t[0]], array_keys(LETTER_TEMPLATES), LETTER_TEMPLATES),
                'key' => $key, 'title' => LETTER_TEMPLATES[$key][0], 'body' => letter_render($key, $cid), 'lang' => 'en',
                'prefers_spanish' => client_lang($cid) === 'es', 'ai' => ai_feature_enabled('draft')];
        }
        case 'ai_letter': {
            $cid = api_437_id($input['client_id'] ?? 0);
            api_require_client($cid, $userId);
            $key = is_string($input['key'] ?? null) && isset(LETTER_TEMPLATES[$input['key']]) ? $input['key'] : '';
            if ($key === '') return api_435_fields(['key' => 'Choose a letter.']);
            if (!ai_feature_enabled('draft')) return ['ok' => false, 'error' => 'That AI feature is turned off. An administrator can enable it under AI assistant settings.', 'status' => 422];
            $typed = trim(str_replace("\r\n", "\n", is_string($input['body'] ?? null) ? $input['body'] : ''));
            // A changed letter is kept, and the draft comes back on its own (letters.php's rule).
            $edited = array_key_exists('edited', $input) ? api_bool($input['edited']) && $typed !== '' : ($typed !== '' && $typed !== trim(letter_render($key, $cid)));
            $res = letter_ai_draft($cid, $key, $userId);
            if (!empty($res['asked'])) audit($userId, 'ai.draft', 'client', $cid, 'letter: ' . LETTER_TEMPLATES[$key][0] . ($res['ok'] ? '' : ' (AI call failed; nothing drafted)') . ' (mobile)');
            if (!$res['ok']) return ['ok' => false, 'error' => api_ai_error((string)$res['error']), 'status' => 502];
            return ['ok' => true, 'text' => $res['text'], 'separate' => $edited, 'truncated' => $res['truncated'],
                'message' => $edited ? 'Your letter is unchanged. The AI draft is in a separate box below it — review it, and use it only if you want it.'
                    : 'The AI draft is in the letter box below — review and edit it before saving.'];
        }
        case 'save_letter': {
            $cid = api_437_id($input['client_id'] ?? 0);
            api_require_client($cid, $userId);
            $how = is_string($input['how'] ?? null) ? $input['how'] : '';
            $key = is_string($input['key'] ?? null) && isset(LETTER_TEMPLATES[$input['key']]) ? $input['key'] : '';
            $title = is_string($input['title'] ?? null) ? trim($input['title']) : '';
            $lang = is_string($input['lang'] ?? null) && $input['lang'] !== '' ? $input['lang'] : 'en';
            $errs = [];
            if (!in_array($how, ['doc', 'note'], true)) $errs['how'] = 'Choose Save to the client\'s documents or Keep as a case note.';
            if (mb_strlen($title) > 150) $errs['title'] = 'Title: use 150 characters or fewer.';
            if (!in_array($lang, ['en', 'es'], true)) $errs['lang'] = 'Language of the letter: choose English or Spanish.';
            if ($errs) return api_435_fields($errs);
            $r = letter_save($cid, $how, $key, $title, is_string($input['body'] ?? null) ? $input['body'] : '', $lang, api_bool($input['to_portal'] ?? false), $userId, 'mobile');
            if (!$r['ok']) return $r['field'] !== null ? api_435_fields([$r['field'] => $r['error']]) : ['ok' => false, 'error' => $r['error'], 'status' => 500];
            return ['ok' => true, 'how' => $r['how'], 'document_id' => $r['how'] === 'doc' ? $r['id'] : null, 'note_id' => $r['how'] === 'note' ? $r['id'] : null, 'message' => $r['message']];
        }

        /* ---- S15: releases, consent and the privacy notice (client.php, inc/records_privacy.php) ---- */
        case 'withdraw_release': {
            $cid = api_437_id($input['client_id'] ?? 0);
            api_require_client($cid, $userId);
            if (!release_withdraw(api_437_id($input['release_id'] ?? 0), $cid, $userId)) return ['ok' => false, 'error' => 'That release was already withdrawn, or is not on this client.', 'status' => 409];
            return ['ok' => true, 'message' => 'Release recorded as withdrawn. Nothing more is shared under it.'];
        }
        case 'withdraw_consent': {
            $cid = api_437_id($input['client_id'] ?? 0);
            api_require_client($cid, $userId);
            $r = consent_withdraw($cid, api_437_id($input['consent_id'] ?? 0), $userId);
            if (!$r['ok']) return ['ok' => false, 'error' => $r['error'], 'status' => 409];
            return ['ok' => true, 'message' => $r['message']];
        }
        case 'record_privacy_notice': {
            $cid = api_437_id($input['client_id'] ?? 0);
            api_require_client($cid, $userId);
            $r = privacy_notice_record($cid, is_string($input['how'] ?? null) ? $input['how'] : '', $userId, 'mobile');
            if (!$r['ok']) return api_435_fields(['how' => $r['error']]);
            return ['ok' => true, 'message' => $r['message'], 'privacy_notice' => api_437_privacy_notice($cid)];
        }

        /* ---- S16: a document to the trash (client.php, inc/file_exchange.php) ---- */
        case 'delete_document': {
            $dq = db()->prepare('SELECT client_id FROM client_documents WHERE id = ?');
            $id = api_437_id($input['id'] ?? 0);
            $dq->execute([$id]);
            $cid = (int)$dq->fetchColumn();
            if ($cid === 0) return ['ok' => false, 'error' => 'That document is no longer on file. It may already be in the trash.', 'status' => 404];
            api_require_client($cid, $userId);
            $r = document_delete($id, $cid, $user, 'mobile');
            if (!$r['ok']) return ['ok' => false, 'error' => $r['error'], 'status' => (int)$r['code']];
            return ['ok' => true, 'message' => $r['message']];
        }

        /* ---- S17: the portal link turned off (client.php, inc/client_notices.php) ---- */
        case 'revoke_portal_link': {
            $cid = api_437_id($input['client_id'] ?? 0);
            api_require_client($cid, $userId);
            $r = portal_link_revoke($cid, $userId, 'mobile');
            if (!$r['ok']) return ['ok' => false, 'error' => $r['error'], 'status' => 409];
            return ['ok' => true, 'message' => $r['message']];
        }

        /* ---- S18: the client's timeline (client_timeline.php) ---- */
        case 'client_timeline': {
            $cid = api_437_id($q('client_id'));
            api_require_client($cid, $userId);
            record_client_view($userId, $cid, 'timeline');
            $sd = client_status_days($cid);
            $st = db()->prepare('SELECT status FROM clients WHERE id = ?'); $st->execute([$cid]);
            $events = array_map(function (array $e): array {
                $link = (string)($e['link'] ?? '');
                $kind = match (true) {
                    str_contains($link, 'tab=history') => 'status', str_starts_with($link, 'track.php') => 'track', str_contains($link, 'tab=assessments') => 'assessment',
                    str_contains($link, '#releases-h') => 'release', str_starts_with($link, 'signatures.php') => 'signature', str_contains($link, '#after-h') => 'closure',
                    str_contains($link, 'tab=notes') => 'note', default => 'event',
                };
                return ['date' => substr((string)$e['at'], 0, 10), 'month' => date('F Y', strtotime((string)$e['at'])), 'kind' => $kind, 'text' => (string)$e['what']];
            }, client_timeline($cid, $userId));
            return ['ok' => true,
                'status_days' => array_map(fn($s, $d) => ['status' => (string)$s, 'days' => (int)$d, 'text' => $s . ': ' . $d . ' day' . ((int)$d === 1 ? '' : 's')], array_keys($sd['days']), $sd['days']),
                'status_summary' => $sd['days'] ? $sd['total'] . ' day' . ($sd['total'] === 1 ? '' : 's') . ' since the record was created on ' . fmt_date($sd['since']) . '; now ' . $st->fetchColumn() . '.' : 'No status history.',
                'events' => $events, 'total' => count($events)];
        }

        /* ---- S21: Pre-ETS cohorts (programs.php, through inc/programs.php) ---- */
        case 'cohort_choices':
            return ['ok' => true, 'coordinators' => cohort_coordinator_choices(), 'activities' => PREETS_ACTIVITIES];
        case 'delete_cohort_session': {
            $coId = api_437_id($input['cohort_id'] ?? 0);
            if (!cohort_accessible($coId, $userId)) return ['ok' => false, 'error' => 'Group not found or unrestricted caseload access is required.', 'status' => 403];
            $r = cohort_session_delete($coId, api_437_id($input['session_id'] ?? 0), $userId, 'mobile');
            return ['ok' => true, 'changed' => $r['changed'], 'message' => $r['message']];
        }
        case 'set_cohort_active': {
            $coId = api_437_id($input['cohort_id'] ?? 0);
            if (!cohort_accessible($coId, $userId)) return ['ok' => false, 'error' => 'Group not found or unrestricted caseload access is required.', 'status' => 403];
            if (!array_key_exists('active', $input)) return api_435_fields(['active' => 'Choose whether to archive or reactivate the cohort.']);
            $r = cohort_set_active($coId, api_bool($input['active']), $userId, 'mobile');
            return ['ok' => true, 'changed' => $r['changed'], 'message' => $r['message']];
        }
    }
    return null;
}
