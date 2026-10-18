<?php
declare(strict_types=1);
// The iPhone app's 4.0.0 action: marking a Draft invoice submitted (the invoice list, tasks,
// alerts and client requests were already served by inc/api_parity.php). It keeps the
// website's rules. Called from api.php for an action it does not handle; null means "not mine".

/** The permission each action needs. */
const API_MORE_PERMS = ['submit_invoice' => 'billing', 'form_options' => '', 'agreements' => '', 'sign_agreement' => '', 'agreement_copy' => '',
    'training' => '', 'submit_training' => '', 'credentials' => '', 'save_credential' => '', 'delete_credential' => ''];

function api_more(string $action, array $input, int $userId): ?array {
    if (!array_key_exists($action, API_MORE_PERMS)) return null;
    if (API_MORE_PERMS[$action] !== '' && !can(API_MORE_PERMS[$action])) return ['ok' => false, 'error' => "Your account doesn't have access to that.", 'status' => 403];
    $scoped = caseload_scoped();
    switch ($action) {
        // The lists set on Lists and time limits (4.5.0), so the app offers the same choices as
        // the website instead of its own copies. Names only; nothing about any client.
        case 'form_options': {
            $keys = ['client_types', 'note_types', 'doc_categories', 'service_types', 'tech_types', 'vision_statuses', 'closure_reasons',
                'device_request_categories', 'incident_types', 'booking_types', 'equipment_categories', 'equipment_conditions', 'maintenance_types',
                'competency_levels', 'score_labels', 'client_statuses', 'referral_sources'];
            $lists = [];
            foreach ($keys as $k) $lists[$k] = array_values(array_map('strval', cms_list_items($k)));
            // For a new client's form (4.25.0): the custom fields in use and the offices (empty when none).
            $cf = array_map(fn($f) => ['id' => (int)$f['id'], 'label' => (string)$f['label'], 'hint' => (string)($f['hint'] ?? ''), 'type' => (string)$f['type'],
                'options' => $f['type'] === 'yesno' ? ['Yes', 'No'] : custom_field_options($f), 'required' => (int)$f['required'] === 1, 'value' => ''], custom_fields_all(true));
            $offices = offices_on() ? array_map(fn($o) => ['id' => (int)$o['id'], 'name' => (string)$o['name']], offices_all(true)) : [];
            return ['ok' => true, 'lists' => $lists, 'custom_fields' => $cf, 'offices' => $offices];
        }
        // Staff agreements (4.15.0): this person's own, to read and sign by typing their name.
        case 'agreements': {
            $out = [];
            foreach (staff_agreement_status($userId) as $r) {
                $out[] = ['id' => (int)$r['id'], 'title' => (string)$r['title'], 'body' => (string)$r['body'], 'version' => (int)$r['version'],
                    'state' => $r['state'], 'why' => $r['why'], 'signed_at' => $r['signed_at'], 'renew_by' => $r['renew_by'],
                    'signature_id' => $r['signature_id'], 'text_hash' => staff_agreement_hash((string)$r['title'], (string)$r['body'])];
            }
            return ['ok' => true, 'agreements' => $out, 'needed' => count(array_filter($out, fn($a) => $a['state'] === 'needed'))];
        }
        case 'sign_agreement': {
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') return ['ok' => false, 'error' => 'Use POST for changes.', 'status' => 405];
            if (empty($input['agree'])) return ['ok' => false, 'error' => 'Confirm that you have read the agreement and agree to it.', 'status' => 422];
            $appHeader = (string)($_SERVER['HTTP_X_APP_VERSION'] ?? '');
            $via = str_contains($appHeader, 'Android') ? 'Android app' : ($appHeader !== '' ? 'iPhone app' : 'app');
            [$sid, $err, $changed] = staff_agreement_sign($userId, (int)($input['id'] ?? 0), is_string($input['signed_name'] ?? null) ? $input['signed_name'] : '',
                (int)($input['version'] ?? 0), $via, is_string($input['text_hash'] ?? null) ? $input['text_hash'] : '');
            // A change while it was open is 409, so the app reloads the agreement before asking again.
            if ($err !== null) return ['ok' => false, 'error' => $err, 'status' => $changed ? 409 : 422];
            return ['ok' => true, 'signature_id' => $sid, 'message' => 'Signed. A copy is kept with your account, and you are asked again in a year or if the text changes.'];
        }
        case 'agreement_copy': {
            $q = db()->prepare('SELECT id, title, body, version, signed_name, signed_at FROM staff_agreement_signatures WHERE id = ? AND user_id = ?');
            $q->execute([(int)($input['id'] ?? $_GET['id'] ?? 0), $userId]);
            $c = $q->fetch();
            if (!$c) return ['ok' => false, 'error' => 'That signed copy was not found.', 'status' => 404];
            return ['ok' => true, 'copy' => ['id' => (int)$c['id'], 'title' => $c['title'], 'body' => $c['body'], 'version' => (int)$c['version'], 'signed_name' => $c['signed_name'], 'signed_at' => $c['signed_at']]];
        }
        // Staff training (4.16.0): the reading and questions, never which answer is right.
        case 'training': {
            $out = [];
            foreach (training_status($userId) as $m) {
                [$qs] = training_parse_questions((string)$m['questions']);
                $out[] = ['id' => (int)$m['id'], 'title' => (string)$m['title'], 'body' => (string)$m['body'], 'version' => (int)$m['version'],
                    'pass_pct' => (int)$m['pass_pct'], 'required' => (bool)$m['required'], 'state' => $m['state'], 'why' => $m['why'],
                    'questions' => array_map(fn($q) => ['q' => $q['q'], 'options' => $q['options']], $qs), 'text_hash' => training_hash($m)];
            }
            return ['ok' => true, 'training' => $out, 'needed' => count(array_filter($out, fn($m) => $m['state'] === 'needed')), 'access_hold' => $GLOBALS['ACCESS_HOLD'] ?? []];
        }
        case 'submit_training': {
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') return ['ok' => false, 'error' => 'Use POST for changes.', 'status' => 405];
            $appHeader = (string)($_SERVER['HTTP_X_APP_VERSION'] ?? '');
            $via = str_contains($appHeader, 'Android') ? 'Android app' : ($appHeader !== '' ? 'iPhone app' : 'app');
            $r = training_submit($userId, (int)($input['id'] ?? 0), (int)($input['version'] ?? 0), is_string($input['text_hash'] ?? null) ? $input['text_hash'] : '',
                is_array($input['answers'] ?? null) ? $input['answers'] : [], $via);
            if ($r['error'] !== null) return ['ok' => false, 'error' => $r['error'], 'status' => $r['changed'] ? 409 : 422];
            return ['ok' => true, 'passed' => $r['passed'], 'score' => $r['score'], 'total' => $r['total'], 'wrong' => $r['wrong'],
                'message' => $r['passed'] ? 'Passed: ' . $r['score'] . ' of ' . $r['total'] . ' right.' : 'Not passed: ' . $r['score'] . ' of ' . $r['total'] . ' right. Read again, then answer the questions marked.'];
        }
        // Your own credentials (4.16.0); administrators keep everyone's on the website.
        case 'credentials': {
            return ['ok' => true, 'credentials' => array_map(fn($c) => ['id' => (int)$c['id'], 'name' => $c['name'], 'number' => $c['number'], 'issuer' => $c['issuer'],
                'issued_on' => $c['issued_on'], 'expires_on' => $c['expires_on'], 'notes' => $c['notes'], 'state' => credential_state($c)[0], 'words' => credential_state($c)[1]], credentials_for($userId)),
                'types' => cms_list_items('credential_types') ?: CREDENTIAL_TYPES];
        }
        case 'save_credential': {
            $cid = (int)($input['id'] ?? 0);
            if ($cid > 0) { $q = db()->prepare('SELECT id FROM staff_credentials WHERE id = ? AND user_id = ?'); $q->execute([$cid, $userId]); if (!$q->fetchColumn()) return ['ok' => false, 'error' => 'That credential was not found.', 'status' => 404]; }
            $s = fn(string $k): string => is_string($input[$k] ?? null) ? trim($input[$k]) : '';
            $name = $s('name');
            if ($name === '' || mb_strlen($name) > 120) return ['ok' => false, 'error' => 'Name the credential (120 characters or fewer).', 'status' => 422];
            $issued = $s('issued_on') === '' ? null : valid_date($s('issued_on'), 1950, (int)date('Y'));
            $expires = $s('expires_on') === '' ? null : valid_date($s('expires_on'), 1950, (int)date('Y') + 20);
            if (($s('issued_on') !== '' && $issued === null) || ($s('expires_on') !== '' && $expires === null)) return ['ok' => false, 'error' => 'Enter the dates as year-month-day (issued not in the future).', 'status' => 422];
            if ($issued && $expires && $expires < $issued) return ['ok' => false, 'error' => 'The expiry date is before the date issued.', 'status' => 422];
            $vals = [$name, mb_substr($s('number'), 0, 80) ?: null, mb_substr($s('issuer'), 0, 120) ?: null, $issued, $expires, mb_substr($s('notes'), 0, 255) ?: null];
            // A field the app did not send (notes, from an app that does not show them) keeps its value.
            if ($cid > 0) db()->prepare('UPDATE staff_credentials SET name = ?, number = ?, issuer = ?, issued_on = ?, expires_on = ?' . (array_key_exists('notes', $input) ? ', notes = ?' : '') . ', updated_at = NOW() WHERE id = ?')
                ->execute(array_merge(array_key_exists('notes', $input) ? $vals : array_slice($vals, 0, 5), [$cid]));
            if ($cid > 0) credential_close_tasks($cid);
            else { db()->prepare('INSERT INTO staff_credentials (name, number, issuer, issued_on, expires_on, notes, user_id, added_by) VALUES (?,?,?,?,?,?,?,?)')->execute(array_merge($vals, [$userId, $userId])); $cid = (int)db()->lastInsertId(); }
            audit($userId, 'credential.save', 'user', $userId, $name . ($expires ? ', expires ' . $expires : '') . ' (app)');
            return ['ok' => true, 'id' => $cid];
        }
        case 'delete_credential': {
            $q = db()->prepare('SELECT name FROM staff_credentials WHERE id = ? AND user_id = ?');
            $q->execute([(int)($input['id'] ?? 0), $userId]);
            $name = $q->fetchColumn();
            if ($name === false) return ['ok' => false, 'error' => 'That credential was not found.', 'status' => 404];
            db()->prepare('DELETE FROM staff_credentials WHERE id = ? AND user_id = ?')->execute([(int)$input['id'], $userId]);
            credential_close_tasks((int)$input['id']);
            audit($userId, 'credential.delete', 'user', $userId, $name . ' (app)');
            return ['ok' => true];
        }
        case 'submit_invoice': {
            $id = (int)($input['id'] ?? 0);
            $q = db()->prepare("SELECT i.id, i.invoice_number, i.created_at FROM invoices i JOIN training_tracks t ON t.id = i.track_id JOIN clients c ON c.id = t.client_id WHERE i.id = ? AND i.status = 'Draft'" . ($scoped ? ' AND c.assigned_to = ?' : ''));
            $q->execute($scoped ? [$id, $userId] : [$id]);
            $inv = $q->fetch();
            if (!$inv) return ['ok' => false, 'error' => 'That invoice is no longer a draft, or is not on your caseload.', 'status' => 409];
            $raw = trim((string)($input['submitted_on'] ?? ''));
            $on = $raw === '' ? date('Y-m-d') : billing_date_in($raw, (string)$inv['created_at']);
            if ($on === null) return ['ok' => false, 'error' => 'The date sent must be from the day the invoice was created to today.', 'status' => 422];
            if (!invoice_submit($id, $on)) return ['ok' => false, 'error' => 'That invoice was changed meanwhile.', 'status' => 409];
            audit($userId, 'invoice.submit', 'invoice', $id, 'submitted ' . $on . ' (app)');
            return ['ok' => true, 'submitted_on' => $on, 'due_date' => (string)db()->query('SELECT due_date FROM invoices WHERE id = ' . $id)->fetchColumn()];
        }
    }
    return null;
}
