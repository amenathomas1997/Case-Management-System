<?php
declare(strict_types=1);
// The iPhone app's 4.0.0 action: marking a Draft invoice submitted (the invoice list, tasks,
// alerts and client requests were already served by inc/api_parity.php). It keeps the
// website's rules. Called from api.php for an action it does not handle; null means "not mine".

/** The permission each action needs. */
const API_MORE_PERMS = ['submit_invoice' => 'billing', 'form_options' => '', 'agreements' => '', 'sign_agreement' => '', 'agreement_copy' => ''];

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
            return ['ok' => true, 'lists' => $lists];
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
