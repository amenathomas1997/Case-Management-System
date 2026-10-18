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
            $loans = signature_open_loans($cid);
            [$errs, $v] = signature_input(array_map(fn($x) => is_scalar($x) ? (string)$x : '', $input), $loans, $inPerson);
            if ($errs) return ['ok' => false, 'error' => implode(' ', $errs), 'fields' => $errs, 'status' => 422];
            if ($inPerson) {
                $id = signature_start_in_person($client, $v, $loans, $userId);
                $s = client_signature($id, $cid);
                return ['ok' => true, 'id' => $id, 'title' => (string)$s['title'], 'body' => (string)$s['body'], 'lang' => $s['lang'], 'signer_name' => (string)$s['signer_name'], 'org' => brand('org')];
            }
            $sent = signature_send($client, $v, $loans, $userId);
            if ($sent['error']) return ['ok' => false, 'error' => $sent['error'], 'status' => 502];
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
            $why = signature_sign_in_person($s, $typed, $png, $userId);
            if ($why !== null) return ['ok' => false, 'error' => $why, 'status' => 422];
            notify_admin_users('Signed in person: ' . $s['title'] . ' for ' . $client['name'], 'signatures.php?client=' . $cid);
            return ['ok' => true, 'signatures' => api_425_signature_rows($cid)];
        }
        case 'withdraw_signature':
            if (!signature_withdraw((int)($input['id'] ?? 0), $cid, $userId)) return ['ok' => false, 'error' => 'That document was already withdrawn.', 'status' => 409];
            return ['ok' => true, 'signatures' => api_425_signature_rows($cid)];
        case 'release_choices':
            return ['ok' => true, 'recipient_types' => array_values(cms_list_items('release_recipient_types')), 'info_items' => array_values(cms_list_items('release_info_items')),
                'validity_days' => release_validity_days()];
        case 'request_release': {
            if (!empty($client['archived_at'])) return ['ok' => false, 'error' => 'This client is archived; nothing can be sent.', 'status' => 409];
            $in = array_map(fn($x) => is_scalar($x) ? (string)$x : $x, $input);
            [$errs, $v] = release_input($in, 'Email');
            if ($errs) return ['ok' => false, 'error' => implode(' ', $errs), 'fields' => $errs, 'status' => 422];
            // The same request again within two minutes (a retry after a lost reply) is not emailed twice.
            $again = db()->prepare('SELECT id FROM client_releases WHERE client_id = ? AND signer_email = ? AND recipient_name = ? AND created_by = ? AND withdrawn_at IS NULL AND sent_at > DATE_SUB(NOW(), INTERVAL 2 MINUTE) LIMIT 1');
            $again->execute([$cid, $v['signer_email'], $v['recipient_name'], $userId]);
            if ($rid = (int)$again->fetchColumn()) return ['ok' => true, 'id' => $rid];
            $sent = release_send($cid, $v, $userId);
            if ($sent['error']) return ['ok' => false, 'error' => $sent['error'], 'status' => 502];
            return ['ok' => true, 'id' => (int)$sent['id']];
        }
        case 'referral_deadlines':
            $rep = referral_deadline_report(date('Y-m-d', strtotime('-90 days')), date('Y-m-d'), caseload_scoped() ? $userId : null);
            unset($rep['rows']); // the totals only: names stay on the website's report
            return ['ok' => true] + $rep;
    }
    return null;
}
