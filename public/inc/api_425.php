<?php
declare(strict_types=1);
// The apps' 4.25.0 actions: what the website gained in 4.22.0 to 4.24.0, for the iPhone and Android apps.
// Documents shared with the client or counselor, documents to sign (by email, or in person on the phone
// with a typed name and a drawn signature), and release of information requests. Called from api.php for
// an action it does not handle; null means "not mine". Website rules apply throughout.

const API_425_PERMS = ['share_document' => 'clients', 'signatures' => 'clients', 'signature' => 'clients', 'send_signature' => 'clients',
    'start_signature' => 'clients', 'sign_in_person' => 'clients', 'withdraw_signature' => 'clients', 'request_release' => 'clients',
    'release_choices' => 'clients', 'referral_deadlines' => 'referrals'];

/** A client's documents to sign, as the apps list them. */
function api_425_signature_rows(int $clientId): array {
    return array_map(fn($s) => ['id' => (int)$s['id'], 'kind' => (string)$s['kind'], 'title' => (string)$s['title'], 'status' => signature_status($s),
        'method' => (string)($s['method'] ?? 'Email'), 'signer_name' => (string)$s['signer_name'], 'signer_email' => (string)$s['signer_email'],
        'sent_at' => $s['sent_at'], 'signed_at' => $s['signed_at'], 'signed_name' => $s['signed_name'], 'has_drawn_signature' => !empty($s['signature_path'])], client_signatures($clientId));
}

/**
 * Sent again after a lost reply (4.27.0): start_signature, send_signature and request_release
 * carry the app's idem_key. The first result's id is kept for 24 hours as a marker row
 * (login_attempts, as api_throttle_record), keyed on the action, the user, the client and the
 * key, so the same key from the same user answers that id again and nothing is created or
 * emailed twice. A named lock holds a second copy arriving at the same moment until the first
 * has answered (the lock ends with the request).
 */
function api_425_idem_marker(string $action, int $userId, int $cid, array $input): ?string {
    $k = is_string($input['idem_key'] ?? null) ? trim($input['idem_key']) : '';
    if (!preg_match('/^[A-Za-z0-9-]{8,64}$/', $k)) return null;
    $m = 'idem425:' . substr(hash('sha256', $action . '|' . $userId . '|' . $cid . '|' . strtoupper($k)), 0, 40);
    try { db()->prepare('SELECT GET_LOCK(?, 10)')->execute([$m]); } catch (Throwable $e) { /* the marker alone still answers later copies */ }
    return $m;
}
function api_425_idem_seen(?string $marker): ?int {
    if ($marker === null) return null;
    $q = db()->prepare('SELECT email FROM login_attempts WHERE email LIKE ? AND attempted_at > DATE_SUB(NOW(), INTERVAL 1 DAY) ORDER BY id DESC LIMIT 1');
    $q->execute([$marker . ':%']);
    $e = $q->fetchColumn();
    return $e === false ? null : (int)substr((string)$e, strlen($marker) + 1);
}
function api_425_idem_keep(?string $marker, int $id): void {
    if ($marker !== null && $id > 0) api_throttle_record($marker . ':' . $id);
}

function api_425(string $action, array $input, int $userId): ?array {
    if (!array_key_exists($action, API_425_PERMS)) return null;
    if (!can(API_425_PERMS[$action])) return ['ok' => false, 'error' => "Your account doesn't have access to that.", 'status' => 403];
    $post = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
    $needPost = !in_array($action, ['signatures', 'signature', 'release_choices', 'referral_deadlines'], true);
    if ($needPost && !$post) return ['ok' => false, 'error' => 'Use POST for changes.', 'status' => 405];
    $cid = (int)($input['client_id'] ?? $_GET['client_id'] ?? 0);
    if ($action !== 'referral_deadlines' && $action !== 'release_choices') {
        if ($cid <= 0) return ['ok' => false, 'error' => 'Which client? Send client_id.', 'status' => 422];
        if (!api_client_scope_ok($cid, $userId)) return ['ok' => false, 'error' => 'That client is not on your caseload.', 'status' => 403];
    }
    $client = null;
    if ($cid > 0) {
        $q = db()->prepare('SELECT * FROM clients WHERE id = ?');
        $q->execute([$cid]);
        $client = $q->fetch() ?: null;
        if (!$client) return ['ok' => false, 'error' => 'Client not found.', 'status' => 404];
    }
    switch ($action) {
        case 'share_document': {
            $with = in_array($input['with'] ?? '', ['portal', 'counselor'], true) ? $input['with'] : null;
            if ($with === null) return ['ok' => false, 'error' => 'Share with whom? Send with: portal or counselor.', 'status' => 422];
            $docId = (int)($input['document_id'] ?? 0);
            if (!client_document($docId, $cid)) return ['ok' => false, 'error' => 'That document is no longer on the client\'s record.', 'status' => 404];
            document_set_shared($docId, $cid, $with, !empty($input['on']), $userId);
            $d = client_document($docId, $cid);
            return ['ok' => true, 'portal_shared' => doc_portal_visible($d), 'counselor_shared' => (int)$d['counselor_shared'] === 1];
        }
        case 'signatures':
            return ['ok' => true, 'signatures' => api_425_signature_rows($cid), 'kinds' => SIGN_KINDS,
                'open_loans' => array_values(array_map(fn($l) => ['id' => (int)$l['id'], 'name' => (string)$l['name'], 'serial' => $l['serial'], 'loaned_at' => $l['loaned_at']], signature_open_loans($cid)))];
        case 'signature': {
            $s = client_signature((int)($_GET['id'] ?? $input['id'] ?? 0), $cid);
            if (!$s) return ['ok' => false, 'error' => 'That document is no longer on the client\'s record.', 'status' => 404];
            return ['ok' => true, 'signature' => ['id' => (int)$s['id'], 'title' => (string)$s['title'], 'body' => (string)$s['body'], 'lang' => $s['lang'],
                'status' => signature_status($s), 'method' => (string)$s['method'], 'signer_role' => (string)$s['signer_role'], 'signer_name' => (string)$s['signer_name'],
                'signed_at' => $s['signed_at'], 'signed_name' => $s['signed_name'], 'org' => brand('org')]];
        }
        case 'send_signature':
        case 'start_signature': {
            if (!empty($client['archived_at'])) return ['ok' => false, 'error' => 'This client is archived; nothing can be sent.', 'status' => 409];
            $inPerson = $action === 'start_signature';
            $idem = api_425_idem_marker($action, $userId, $cid, $input);
            if (($seenId = api_425_idem_seen($idem)) !== null && ($s = client_signature($seenId, $cid))) {
                if (!$inPerson) return ['ok' => true, 'id' => $seenId, 'repeat' => true];
                return ['ok' => true, 'id' => $seenId, 'repeat' => true, 'title' => (string)$s['title'], 'body' => (string)$s['body'], 'lang' => $s['lang'], 'signer_name' => (string)$s['signer_name'], 'org' => brand('org'),
                    'sent_at' => $s['sent_at'], 'sign_by' => date('Y-m-d H:i:s', strtotime((string)$s['sent_at']) + 2 * 86400)];
            }
            $loans = signature_open_loans($cid);
            [$errs, $v] = signature_input(array_map(fn($x) => is_scalar($x) ? (string)$x : '', $input), $loans, $inPerson);
            if ($errs) return ['ok' => false, 'error' => implode(' ', $errs), 'fields' => $errs, 'status' => 422];
            if ($inPerson) {
                $id = signature_start_in_person($client, $v, $loans, $userId);
                api_425_idem_keep($idem, $id);
                $s = client_signature($id, $cid);
                // sent_at: when the server started it (4.26.0): the two days to sign it count from here.
                return ['ok' => true, 'id' => $id, 'title' => (string)$s['title'], 'body' => (string)$s['body'], 'lang' => $s['lang'], 'signer_name' => (string)$s['signer_name'], 'org' => brand('org'),
                    'sent_at' => $s['sent_at'], 'sign_by' => date('Y-m-d H:i:s', strtotime((string)$s['sent_at']) + 2 * 86400)];
            }
            // Each request emails someone outside the agency: at most 10 an hour per staff
            // member, as consent requests (4.27.0).
            if (api_throttle_count('signature-mail-' . $userId, 3600) >= 10) return ['ok' => false, 'error' => 'Too many documents sent to sign in the last hour. Wait a while, then try again.', 'status' => 429];
            api_throttle_record('signature-mail-' . $userId);
            $sent = signature_send($client, $v, $loans, $userId);
            if ($sent['error']) return ['ok' => false, 'error' => $sent['error'], 'status' => 502];
            api_425_idem_keep($idem, (int)$sent['id']);
            return ['ok' => true, 'id' => (int)$sent['id']];
        }
        case 'sign_in_person': {
            $s = client_signature((int)($input['id'] ?? 0), $cid);
            if (!$s) return ['ok' => false, 'error' => 'That document is no longer on the client\'s record.', 'status' => 404];
            $typed = trim(is_string($input['typed_name'] ?? null) ? $input['typed_name'] : '');
            $png = null;
            if (is_string($input['signature_b64'] ?? null) && $input['signature_b64'] !== '') {
                $png = base64_decode($input['signature_b64'], true);
                if ($png === false) return ['ok' => false, 'error' => 'The drawn signature could not be read. Clear it and sign again.', 'status' => 422];
            }
            $signedAt = is_string($input['signed_at'] ?? null) ? $input['signed_at'] : null;   // 4.26.0: signed with no signal
            // A signature sent again after a lost reply (or twice from the offline queue) is already recorded: not an error.
            if (!empty($s['signed_at']) && $s['signed_name'] !== null && signature_name_matches((string)$s['signer_name'], $typed)) return ['ok' => true, 'repeat' => true, 'signatures' => api_425_signature_rows($cid)];
            $why = signature_sign_in_person($s, $typed, $png, $userId, $signedAt);
            // A Spanish document is refused in Spanish (4.29.0), as the website's signing page.
            if ($why !== null) return ['ok' => false, 'error' => function_exists('signature_reason_in') ? signature_reason_in($why, (string)($s['lang'] ?? ''), (string)($s['signer_name'] ?? '')) : $why, 'status' => 422];
            notify_admin_users('Signed in person: ' . $s['title'] . ' for ' . $client['name'], 'signatures.php?client=' . $cid);
            return ['ok' => true, 'signatures' => api_425_signature_rows($cid)];
        }
        case 'withdraw_signature':
            $wres = signature_withdraw_result((int)($input['id'] ?? 0), $cid, $userId);
            // A document signed meanwhile (or on the phone with no signal) stays signed (4.27.0).
            if ($wres === 'signed') return ['ok' => false, 'signed' => true, 'error' => 'This document is already signed.', 'status' => 409];
            if ($wres !== 'withdrawn') return ['ok' => false, 'error' => 'That document was already withdrawn.', 'status' => 409];
            return ['ok' => true, 'signatures' => api_425_signature_rows($cid)];
        case 'release_choices':
            return ['ok' => true, 'recipient_types' => array_values(cms_list_items('release_recipient_types')), 'info_items' => array_values(cms_list_items('release_info_items')),
                'validity_days' => release_validity_days()];
        case 'request_release': {
            if (!empty($client['archived_at'])) return ['ok' => false, 'error' => 'This client is archived; nothing can be sent.', 'status' => 409];
            $idem = api_425_idem_marker($action, $userId, $cid, $input);
            if (($seenId = api_425_idem_seen($idem)) !== null) {
                $still = db()->prepare('SELECT id FROM client_releases WHERE id = ? AND client_id = ?');
                $still->execute([$seenId, $cid]);
                if ($still->fetchColumn()) return ['ok' => true, 'id' => $seenId, 'repeat' => true];
            }
            $in = array_map(fn($x) => is_scalar($x) ? (string)$x : $x, $input);
            [$errs, $v] = release_input($in, 'Email');
            if ($errs) return ['ok' => false, 'error' => implode(' ', $errs), 'fields' => $errs, 'status' => 422];
            // The same request again within two minutes (a retry after a lost reply) is not emailed twice.
            $again = db()->prepare('SELECT id FROM client_releases WHERE client_id = ? AND signer_email = ? AND recipient_name = ? AND created_by = ? AND withdrawn_at IS NULL AND sent_at > DATE_SUB(NOW(), INTERVAL 2 MINUTE) LIMIT 1');
            $again->execute([$cid, $v['signer_email'], $v['recipient_name'], $userId]);
            if ($rid = (int)$again->fetchColumn()) return ['ok' => true, 'id' => $rid];
            // At most 10 release requests an hour per staff member, as consent requests (4.27.0).
            if (api_throttle_count('release-mail-' . $userId, 3600) >= 10) return ['ok' => false, 'error' => 'Too many release requests in the last hour. Wait a while, then try again.', 'status' => 429];
            api_throttle_record('release-mail-' . $userId);
            $sent = release_send($cid, $v, $userId);
            if ($sent['error']) return ['ok' => false, 'error' => $sent['error'], 'status' => 502];
            api_425_idem_keep($idem, (int)$sent['id']);
            // The email's outcome unknown (4.27.0): the request is kept, and staff are told it may have gone.
            if (!empty($sent['unknown'])) return ['ok' => true, 'id' => (int)$sent['id'], 'unknown' => true, 'message' => (string)$sent['notice']];
            return ['ok' => true, 'id' => (int)$sent['id']];
        }
        case 'referral_deadlines':
            $rep = referral_deadline_report(date('Y-m-d', strtotime('-90 days')), date('Y-m-d'), caseload_scoped() ? $userId : null);
            unset($rep['rows']); // the totals only: names stay on the website's report
            return ['ok' => true] + $rep;
    }
    return null;
}
