<?php
declare(strict_types=1);
// The apps' 4.36.0 actions: the daily workspace and safety the website already had. A privacy
// incident reported (inc/records_privacy.php), the person's own hours (inc/self_booking.php), and
// a task deleted (administrators) or several completed at once (inc/tasks.php). Called from
// api.php for an action it does not handle; null means "not mine". Each action calls the helper
// its website page calls, so the rules, the sentences, the audit and the notifications are the
// same; scope checks are the page's. The create actions are in API_IDEM_ACTIONS.

/** Each action's permission; '' is any signed-in staff member, as the page's require_login(). */
const API_436_PERMS = ['privacy_incident_choices' => 'clients', 'report_privacy_incident' => 'clients',
    'availability' => '', 'add_availability' => '', 'remove_availability' => '', 'delete_task' => '', 'complete_tasks' => ''];

/** The actions that only read (GET). */
const API_436_READS = ['privacy_incident_choices', 'availability'];

/** A yes/no sent by the apps: true, 1, "1" or "true" is yes; false, 0, "0", "false", "" or nothing is no. */
function api_bool(mixed $v): bool {
    if (is_bool($v)) return $v;
    if (is_int($v)) return $v === 1;
    return is_string($v) && in_array(strtolower(trim($v)), ['1', 'true'], true);
}

/** The caller's phones as the apps read them (4.36.0): the website's list (inc/records_privacy.php),
 *  with the phone making the request marked. Sign out of that one with Sign out, not here. */
function api_account_devices(int $userId, int $thisTokenId): array {
    return array_map(function (array $d) use ($thisTokenId): array {
        $this_phone = (int)$d['id'] === $thisTokenId;
        $buttons = app_device_buttons($d);
        return ['id' => (int)$d['id'], 'name' => app_device_name($d), 'signed_in_at' => (string)$d['created_at'],
            'last_used_at' => $d['last_used_at'] !== null ? (string)$d['last_used_at'] : null,
            'live' => !isset($d['live']) || (int)$d['live'] === 1, 'wipe_requested' => !empty($d['wipe_requested_at']), 'wiped' => !empty($d['wiped_at']),
            'this_phone' => $this_phone, 'can_sign_out' => !$this_phone && $buttons['sign_out'], 'can_report_lost' => !$this_phone && $buttons['report_lost'],
            'state' => app_device_state($d), 'text' => app_device_line($d)];
    }, app_devices($userId));
}
/** The person's own hours as the apps read them (availability.php's list). */
function api_436_hours(int $userId): array {
    return array_map(fn(array $a): array => ['id' => (int)$a['id'], 'weekday' => (int)$a['weekday'],
        'from' => substr((string)$a['start_time'], 0, 5), 'to' => substr((string)$a['end_time'], 0, 5), 'remote' => (bool)(int)$a['is_remote'],
        'location' => $a['location'] !== null && $a['location'] !== '' ? (string)$a['location'] : null, 'text' => availability_words($a)], availability_for($userId));
}

/**
 * A repeated create (the same idem_key, after a lost reply): api_idem_end keeps only the first
 * reply's short values, so the longer message and lists are rebuilt from the database and the app
 * shows what it showed the first time. Called by the retry guard (inc/api_parity.php) after
 * api_435_repeat; other actions get $prev unchanged.
 */
function api_436_repeat(string $action, array $input, int $userId, array $prev): array {
    if ($action === 'device_action') {
        $lost = !empty($prev['lost']);
        $tokId = (int)($GLOBALS['tok']['id'] ?? 0);
        return ['ok' => true, 'lost' => $lost, 'message' => app_device_action_message(true, $lost), 'devices' => api_account_devices($userId, $tokId), 'repeat' => true];
    }
    if ($action === 'report_privacy_incident') {
        $id = (int)($prev['id'] ?? 0);
        $q = db()->prepare('SELECT notice_deadline FROM privacy_incidents WHERE id = ? AND reported_by = ?');
        $q->execute([$id, $userId]);
        $deadline = (string)$q->fetchColumn();
        if ($deadline === '') return $prev;
        return ['ok' => true, 'id' => $id, 'notice_deadline' => $deadline, 'message' => privacy_incident_reported_message($deadline), 'repeat' => true];
    }
    if ($action === 'add_availability') {
        return ['ok' => true, 'message' => 'Hours added.', 'hours' => api_436_hours($userId), 'repeat' => true];
    }
    return $prev;
}

function api_436(string $action, array $input, int $userId): ?array {
    if (!array_key_exists($action, API_436_PERMS)) return null;
    $perm = API_436_PERMS[$action];
    if ($perm !== '' && !can($perm)) {
        // Held back only until required agreements or training are done (4.16.0), as api.php says it.
        if (!empty($GLOBALS['ACCESS_HOLD']) && in_array($perm, (array)($GLOBALS['PERMS_BEFORE_HOLD'] ?? []), true)) {
            return ['ok' => false, 'error' => 'Client records open once you finish: ' . implode(', ', $GLOBALS['ACCESS_HOLD']) . '. Open My agreements and My training.', 'status' => 403];
        }
        return ['ok' => false, 'error' => 'Your account does not have access to that area. Ask an administrator if you need it.', 'status' => 403];
    }
    $post = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
    if (!in_array($action, API_436_READS, true) && !$post) return ['ok' => false, 'error' => 'Use POST for changes.', 'status' => 405];
    $user = ['id' => $userId];
    // As privacy_incidents.php's $ready: before migration 3.0.38 there is nowhere to keep a report.
    if (in_array($action, ['privacy_incident_choices', 'report_privacy_incident'], true)) {
        try { db()->query('SELECT 1 FROM privacy_incidents LIMIT 1'); db()->query('SELECT 1 FROM privacy_incident_clients LIMIT 1'); }
        catch (PDOException $e) {
            return ['ok' => false, 'error' => 'Privacy incidents cannot be reported yet: this server needs migration 3.0.38. Ask an administrator to run it, then try again. Nothing was sent.', 'status' => 503];
        }
    }

    switch ($action) {
        case 'privacy_incident_choices':
            // privacy_incidents.php's form: the kinds, and the clients in scope (a caseload-scoped user's own).
            return ['ok' => true, 'kinds' => PRIVACY_INCIDENT_KINDS,
                'clients' => array_map(fn($c) => ['id' => (int)$c['id'], 'name' => (string)$c['name']], privacy_incident_client_choices($user)),
                'today' => date('Y-m-d'), 'notice_days' => PRIVACY_NOTICE_DAYS];

        case 'report_privacy_incident': {
            // As privacy_incidents.php: checked, saved in one transaction, audited ("<kind> (mobile)")
            // and every administrator told, with the incident's link.
            $in = [];
            foreach (['kind', 'discovered_on', 'occurred_on', 'description', 'info_involved', 'client_id'] as $k) {
                $v = $input[$k] ?? '';
                $in[$k] = is_string($v) ? $v : (is_int($v) ? (string)$v : '');
            }
            $r = privacy_incident_report($in, $user, 'mobile');
            if (isset($r['errors'])) return api_435_fields($r['errors']);
            return ['ok' => true, 'id' => $r['id'], 'notice_deadline' => $r['deadline'], 'message' => $r['message']];
        }

        case 'availability': {
            // Always the caller's own hours (availability.php without ?user=).
            $uq = db()->prepare('SELECT id, name, is_active FROM users WHERE id = ?');
            $uq->execute([$userId]);
            $person = $uq->fetch() ?: ['id' => $userId, 'name' => '', 'is_active' => 1];
            return ['ok' => true, 'self_booking_on' => self_booking_settings()['on'], 'intro' => availability_intro($person, true, can('admin')),
                'hours' => api_436_hours($userId), 'days' => array_map(fn($k, $v) => ['value' => (string)$k, 'label' => $v], array_keys(SELF_BOOKING_DAYS), SELF_BOOKING_DAYS)];
        }

        case 'add_availability': {
            // Own hours only: a "user" input is never read.
            $in = [];
            foreach (['weekday', 'from', 'to', 'mode', 'location'] as $k) $in[$k] = $input[$k] ?? '';
            $err = availability_add_for($userId, $in, $userId);
            if ($err !== null) return api_435_fields([$err[0] => $err[1]]);
            return ['ok' => true, 'message' => 'Hours added.', 'hours' => api_436_hours($userId)];
        }

        case 'remove_availability': {
            $id = $input['id'] ?? 0;
            $id = is_int($id) ? $id : (is_string($id) && ctype_digit($id) ? (int)$id : 0);
            if (!availability_remove($userId, $id, $userId)) return ['ok' => false, 'error' => 'Nothing was removed: those hours were already removed.', 'status' => 409];
            return ['ok' => true, 'message' => 'Hours removed. Sessions already booked in them stay booked.', 'hours' => api_436_hours($userId)];
        }

        case 'delete_task': {
            // As tasks.php: administrators only, permanent, audited task.delete with the title.
            if (!can('admin')) return ['ok' => false, 'error' => 'Only an administrator can delete a task.', 'status' => 403];
            $id = $input['id'] ?? 0;
            $id = is_int($id) ? $id : (is_string($id) && ctype_digit($id) ? (int)$id : 0);
            if (!task_delete($id, $userId)) return ['ok' => false, 'error' => 'That task no longer exists. It may already have been deleted.', 'status' => 404];
            return ['ok' => true, 'message' => 'Task deleted.'];
        }

        case 'complete_tasks': {
            // As tasks.php's "Complete several tasks at once": at most 200, each through the task's visibility.
            $n = tasks_bulk_done(api_435_ids($input['task_ids'] ?? []), $user);
            if ($n === 0) return ['ok' => false, 'error' => tasks_done_message(0), 'status' => 422];
            return ['ok' => true, 'completed' => $n, 'message' => tasks_done_message($n)];
        }
    }
    return null;
}
