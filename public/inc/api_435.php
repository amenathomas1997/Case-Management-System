<?php
declare(strict_types=1);
// The apps' 4.35.0 actions: field casework the website already had. A group session booked and its
// attendance recorded (inc/session_group.php), times offered for a client's request
// (inc/request_offers.php), the outcome survey and the follow-up checks after closure
// (inc/outcome_followups.php), and the remembered caseload of the client list
// (inc/client_list.php). Called from api.php for an action it does not handle; null means "not
// mine". Each action calls the helper its website page calls, so the rules, the sentences and the
// audit are the same; scope checks are the page's (api_require_client, and caseload-scoped staff
// see only their own clients' rows). The create and send actions are in API_IDEM_ACTIONS.

const API_435_PERMS = ['group_choices' => 'schedule', 'schedule_group' => 'schedule', 'group_session' => 'schedule', 'complete_group' => 'schedule',
    'offer_times' => 'clients', 'send_survey' => 'clients', 'phone_survey' => 'clients', 'closure_followup' => 'clients', 'clients_filter' => 'clients'];

/** The actions that only read (GET); offer_times reads on GET and offers on POST. */
const API_435_READS = ['group_choices', 'group_session'];

/** The app's value as the website's form posts it: text or a number as text; true or false only
 *  for a tick box ($tick), as "1" or "". null when it cannot be read (a boolean where a number or
 *  text belongs, a list), so the caller refuses it on its field instead of changing it. */
function api_435_form_value(mixed $v, bool $tick = false): ?string {
    if ($v === null) return '';
    if (is_bool($v)) return $tick ? ($v ? '1' : '') : null;
    if (is_int($v) || is_float($v)) return (string)$v;
    return is_string($v) ? $v : null;
}

/** The labels of schedule_group's shared fields, for a value of the wrong kind. */
const API_435_BOOKING_LABELS = ['date' => 'Date', 'time' => 'Start time', 'duration' => 'Duration (hours)', 'location' => 'Location', 'notes' => 'Notes',
    'is_remote' => 'Remote', 'meeting_url' => 'Meeting link', 'staff_id' => 'Instructor'];

/** The follow-up questions, as the website's form asks them. */
const API_435_FOLLOWUP_QUESTIONS = ['still_employed' => 'Still employed?', 'still_using_tech' => 'Still using the technology?',
    'independent' => 'Living or working independently?', 'needs_service' => 'Needs more service?'];

/**
 * A repeated create (the same idem_key, after a lost reply): api_idem_end keeps only the first
 * reply's short values, so schedule_group, complete_group and offer_times rebuild their lists and
 * message from the database, and the app behaves as it did on the first reply. Called by the
 * retry guard (inc/api_parity.php) before any action runs; other actions get $prev unchanged.
 */
function api_435_repeat(string $action, array $input, int $userId, array $prev): array {
    if ($action === 'schedule_group') {
        $uid = preg_replace('/[^a-f0-9]/', '', (string)($prev['group_uid'] ?? ''));
        $rows = db()->prepare('SELECT s.id, s.track_id, c.name AS client_name, u.name AS staff_name FROM scheduled_sessions s JOIN clients c ON c.id = s.client_id
            LEFT JOIN users u ON u.id = s.user_id WHERE s.group_uid = ? ORDER BY s.id');
        $rows->execute([$uid]);
        $rows = $rows->fetchAll();
        $noTrack = array_values(array_map(fn($r) => (string)$r['client_name'], array_filter($rows, fn($r) => $r['track_id'] === null)));
        return ['ok' => true, 'group_uid' => $uid, 'sessions_created' => count($rows), 'no_track' => $noTrack, 'invite_sent' => !empty($prev['invite_sent']),
            'message' => session_group_book_message(count($rows), $noTrack, !empty($prev['invite_sent']), (string)($rows[0]['staff_name'] ?? '')), 'repeat' => true];
    }
    if ($action === 'complete_group') {
        $uid = preg_replace('/[^a-f0-9]/', '', is_string($input['group_uid'] ?? null) ? $input['group_uid'] : (string)($prev['group_uid'] ?? ''));
        $scope = caseload_scoped() ? ' AND c.assigned_to = ' . $userId : '';
        $q = db()->prepare("SELECT s.id, s.client_id, s.status, s.start_at, s.work_log_id, s.note_pending, c.name AS client_name FROM scheduled_sessions s
            JOIN clients c ON c.id = s.client_id WHERE s.group_uid = ?" . $scope . ' ORDER BY c.name, s.id');
        $q->execute([$uid]);
        $rows = $q->fetchAll();
        $done = array_values(array_filter($rows, fn($r) => $r['status'] === 'Completed'));
        $noShow = array_values(array_map(fn($r) => (string)$r['client_name'], array_filter($rows, fn($r) => $r['status'] === 'No Show')));
        $still = array_values(array_map(fn($r) => (string)$r['client_name'], array_filter($rows, fn($r) => $r['status'] === 'Scheduled')));
        $logged = count(array_filter($done, fn($r) => $r['work_log_id'] !== null));
        $startAt = (string)($rows[0]['start_at'] ?? '');
        return ['ok' => true, 'group_uid' => $uid, 'completed' => count($done), 'hours_logged_for' => $logged, 'no_show' => $noShow, 'refused' => [], 'already_logged' => [],
            'still_scheduled' => $still,
            'message' => 'Group session of ' . fmt_datetime($startAt) . ': ' . (count($done) > 0 ? 'completed for ' . count($done) . ' client(s) — hours logged for ' . $logged . '.' : 'nobody was recorded as attending.')
                . ($noShow ? ' Recorded as No Show, with no hours logged: ' . implode(', ', $noShow) . '.' : '')
                . ($still ? ' Still Scheduled: ' . implode(', ', $still) . '.' : ''),
            'notes_to_write' => array_values(array_map(fn($r) => ['session_id' => (int)$r['id'], 'client_id' => (int)$r['client_id'], 'client_name' => (string)$r['client_name'],
                'note_subject' => sessnote_subject((string)$r['start_at'])], array_filter($done, fn($r) => (int)($r['note_pending'] ?? 0) === 1))),
            'repeat' => true];
    }
    if ($action === 'offer_times') {
        $id = (int)($input['id'] ?? 0);
        $c = db()->prepare('SELECT c.name FROM portal_requests pr JOIN clients c ON c.id = pr.client_id WHERE pr.id = ?');
        $c->execute([$id]);
        $res = ['offered' => (int)($prev['offered'] ?? count(request_offers($id))), 'emailed' => !empty($prev['emailed']), 'refused' => []];
        return ['ok' => true, 'offered' => $res['offered'], 'refused' => [], 'emailed' => $res['emailed'],
            'offered_times' => array_map(fn($o) => ['start_at' => (string)$o['start_at']], request_offers($id)),
            'message' => offer_times_message($res, (string)$c->fetchColumn()), 'repeat' => true];
    }
    return $prev;
}

/** A list of ids sent as an array, or as "1,2,3". */
function api_435_ids(mixed $v): array {
    if (is_string($v)) $v = explode(',', $v);
    if (!is_array($v)) return [];
    return array_values(array_unique(array_filter(array_map(fn($x) => is_int($x) || (is_string($x) && ctype_digit(trim($x))) ? (int)$x : 0, $v))));
}

/** A refusal with its field errors: the first sentence is the summary. */
function api_435_fields(array $errors, int $status = 422, array $extra = []): array {
    return ['ok' => false, 'error' => (string)reset($errors)] + $extra + ['errors' => $errors, 'status' => $status];
}

function api_435(string $action, array $input, int $userId): ?array {
    if (!array_key_exists($action, API_435_PERMS)) return null;
    if (!can(API_435_PERMS[$action])) {
        // Held back only until required agreements or training are done (4.16.0), as api.php says it.
        if (!empty($GLOBALS['ACCESS_HOLD']) && in_array(API_435_PERMS[$action], (array)($GLOBALS['PERMS_BEFORE_HOLD'] ?? []), true)) {
            return ['ok' => false, 'error' => 'Client records open once you finish: ' . implode(', ', $GLOBALS['ACCESS_HOLD']) . '. Open My agreements and My training.', 'status' => 403];
        }
        return ['ok' => false, 'error' => 'Your account does not have access to that area. Ask an administrator if you need it.', 'status' => 403];
    }
    $post = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
    if (!in_array($action, API_435_READS, true) && $action !== 'offer_times' && !$post) return ['ok' => false, 'error' => 'Use POST for changes.', 'status' => 405];
    $uq = db()->prepare('SELECT * FROM users WHERE id = ?');
    $uq->execute([$userId]);
    $actor = $uq->fetch() ?: ['id' => $userId, 'name' => 'Staff'];
    $scopeUser = caseload_scoped() ? $userId : null;

    switch ($action) {
        case 'group_choices': {
            // schedule.php's group form: the bookable clients in scope, each with their active
            // tracks newest first, and the active staff to run it.
            $bookable = schedule_bookable_statuses();
            $cq = db()->prepare('SELECT id, name FROM clients c WHERE status IN (' . implode(',', array_fill(0, count($bookable), '?')) . ')' . ($scopeUser !== null ? ' AND c.assigned_to = ?' : '') . ' ORDER BY name');
            $cq->execute(array_merge($bookable, $scopeUser !== null ? [$scopeUser] : []));
            $tq = db()->prepare("SELECT id, title FROM training_tracks WHERE client_id = ? AND status = 'Active' ORDER BY created_at DESC, id DESC");
            $clients = [];
            foreach ($cq->fetchAll() as $c) {
                $tq->execute([(int)$c['id']]);
                $clients[] = ['id' => (int)$c['id'], 'name' => (string)$c['name'], 'tracks' => array_map(fn($t) => ['id' => (int)$t['id'], 'title' => (string)$t['title']], $tq->fetchAll())];
            }
            $staff = array_map(fn($s) => ['id' => (int)$s['id'], 'name' => (string)$s['name']], db()->query('SELECT id, name FROM users WHERE is_active = 1 ORDER BY name')->fetchAll());
            return ['ok' => true, 'clients' => $clients, 'staff' => $staff, 'default_staff_id' => $userId];
        }

        case 'schedule_group': {
            $clientIds = api_435_ids($input['client_ids'] ?? []);
            // Caseload-scoped staff send only their own clients, as schedule.php's scope check.
            foreach ($clientIds as $cid) api_require_client($cid, $userId);
            $src = [];
            $typeErrs = [];
            foreach (API_435_BOOKING_LABELS as $k => $label) {
                $v = api_435_form_value($input[$k] ?? null, $k === 'is_remote');
                if ($v === null) { $typeErrs[$k] = $label . ': this value could not be read. Enter it again.'; $v = ''; }
                $src[$k] = $v;
            }
            // Each client's track is sent by client id ({"12": 34}); a plain list cannot say whose it is.
            $sentTracks = $input['tracks'] ?? [];
            if ($sentTracks === null) $sentTracks = [];
            if (!is_array($sentTracks) || ($sentTracks && array_is_list($sentTracks))) {
                return api_435_fields(['tracks' => 'Training tracks: send each client\'s track by client id, for example {"12": 34}.']);
            }
            [$inErrors, $f] = session_booking_input($src, '', $actor);
            $inErrors = $typeErrs + $inErrors;
            $picks = [];
            foreach ($clientIds as $cid) {
                $tv = $sentTracks[$cid] ?? null;
                if ($tv !== null && !is_int($tv) && !(is_string($tv) && ctype_digit($tv))) return api_435_fields(['track_' . $cid => 'Training track: choose one of this client\'s active training tracks.']);
                $picks[$cid] = (int)($tv ?? 0);
            }
            $hash = is_string($input['authorization_ack_hash'] ?? null) && $input['authorization_ack_hash'] !== '' ? $input['authorization_ack_hash'] : null;
            $gb = session_group_book($clientIds, $picks, $f, $hash, $actor, 'mobile', $inErrors);
            if ($gb['duplicate']) return ['ok' => false, 'duplicate' => true, 'error' => $gb['message'], 'status' => 409];
            if ($gb['errors']) {
                // The warnings to approve, as schedule_session refuses them: the booking goes ahead
                // when the app sends this hash back as authorization_ack_hash.
                if (isset($gb['errors']['authorization_ack']) && count($gb['errors']) === 1 && $gb['hash'] !== null) {
                    return ['ok' => false, 'error' => schedule_review_prefix($gb['authorization']) . implode(' ', $gb['warnings'])
                            . ($hash !== null ? ' These warnings are not the ones you approved, so nothing was booked. Review them and approve again to book.'
                                : ' Review the tracks and explicitly approve the warnings before booking.'),
                        'authorization_warnings' => $gb['warnings'], 'authorization_hash' => $gb['hash'], 'status' => 422];
                }
                return api_435_fields($gb['errors']);
            }
            return ['ok' => true, 'group_uid' => $gb['group_uid'], 'sessions_created' => count($gb['ids']), 'no_track' => $gb['no_track'],
                'invite_sent' => $gb['invite_sent'], 'message' => $gb['message']];
        }

        case 'group_session': {
            $uid = preg_replace('/[^a-f0-9]/', '', is_string($_GET['group_uid'] ?? $input['group_uid'] ?? null) ? (string)($_GET['group_uid'] ?? $input['group_uid']) : '');
            $members = $uid !== '' ? session_group_members($uid, $scopeUser) : [];
            if (!$members) return ['ok' => false, 'error' => 'That group session is no longer scheduled.', 'status' => 404];
            $first = $members[0];
            $started = strtotime((string)$first['start_at']) <= time();
            return ['ok' => true, 'group_uid' => $uid, 'start_at' => (string)$first['start_at'], 'end_at' => (string)$first['end_at'], 'started' => $started,
                // As the website's group card (shown for two or more): one member alone is recorded on its own.
                'instructor' => (string)($first['staff_name'] ?? ''), 'can_record' => $started && count($members) >= 2,
                'default_hours' => session_group_default_hours($members),
                'members' => array_map(fn($m) => ['session_id' => (int)$m['id'], 'client_id' => (int)$m['client_id'], 'client_name' => (string)$m['client_name'],
                    'track_id' => $m['track_id'] !== null ? (int)$m['track_id'] : null, 'track_title' => $m['track_title'] !== null ? (string)$m['track_title'] : null], $members)];
        }

        case 'complete_group': {
            $uid = preg_replace('/[^a-f0-9]/', '', is_string($input['group_uid'] ?? null) ? $input['group_uid'] : '');
            $hoursRaw = api_435_form_value($input['hours'] ?? '');
            $hours = $hoursRaw === null ? false : session_group_hours_input($hoursRaw);
            if ($hours === false) return api_435_fields(['hours' => SESSION_GROUP_HOURS_ERROR]);
            // The website records a group from its card, shown only with two or more clients waiting
            // (schedule.php): with one on this user's list, that session is recorded on its own.
            $visible = $uid !== '' ? session_group_members($uid, $scopeUser) : [];
            if (!$visible) return ['ok' => false, 'error' => 'That group session is no longer scheduled.', 'status' => 404];
            if (count($visible) < 2) return ['ok' => false, 'error' => 'Only one client of this group session is still waiting for an outcome' . ($scopeUser !== null ? ' on your caseload' : '')
                . ', so it is not recorded as a group. Record ' . $visible[0]['client_name'] . '\'s session on its own.', 'status' => 422];
            $gc = session_group_complete($uid, api_435_ids($input['listed'] ?? []), api_435_ids($input['attended'] ?? []), $hours, $actor, 'mobile');
            if ($gc['error'] !== null) {
                $why = ['ok' => false, 'error' => $gc['error'], 'status' => 422];
                if ($gc['not_started'] > 0 && !$gc['refused'] && !$gc['already_logged']) $why['not_started'] = true;
                return $why;
            }
            return ['ok' => true, 'group_uid' => $uid, 'completed' => $gc['done'], 'hours_logged_for' => $gc['logged'], 'no_show' => array_values(array_map(fn($s) => (string)$s['client_name'], $gc['no_shows'])),
                'refused' => $gc['refused'], 'already_logged' => $gc['already_logged'], 'message' => $gc['message'],
                'notes_to_write' => array_map(fn($s) => ['session_id' => (int)$s['id'], 'client_id' => (int)$s['client_id'], 'client_name' => (string)$s['client_name'],
                    'note_subject' => sessnote_subject((string)$s['start_at'])], $gc['done_sessions'])];
        }

        case 'offer_times': {
            $id = (int)($input['id'] ?? $_GET['id'] ?? 0);
            $r = db()->prepare('SELECT pr.*, c.name AS client_name, c.assigned_to FROM portal_requests pr JOIN clients c ON c.id = pr.client_id WHERE pr.id = ?');
            $r->execute([$id]);
            $req = $r->fetch();
            if (!$req) return ['ok' => false, 'error' => 'That request no longer exists.', 'status' => 404];
            if ($scopeUser !== null && (int)$req['assigned_to'] !== $scopeUser) return ['ok' => false, 'error' => 'That request is not on your caseload.', 'status' => 403];
            $canOffer = in_array($req['type'], OFFER_REQUEST_TYPES, true) && !in_array($req['status'], ['Done', 'Declined'], true);
            if (!$post) {
                $def = offer_form_defaults($req, $userId);
                $tracks = array_map(fn($t) => ['id' => (int)$t['id'], 'title' => (string)$t['title']], $def['tracks']);
                return ['ok' => true,
                    'request' => ['id' => (int)$req['id'], 'type' => (string)$req['type'], 'type_label' => ['appointment' => 'Appointment', 'reschedule' => 'Reschedule'][$req['type']] ?? 'Request',
                        'client_name' => (string)$req['client_name'], 'status' => (string)$req['status']],
                    'can_offer' => $canOffer,
                    'staff' => array_map(fn($s) => ['id' => (int)$s['id'], 'name' => (string)$s['name']], db()->query('SELECT id, name FROM users WHERE is_active = 1 ORDER BY name')->fetchAll()),
                    'defaults' => ['staff_id' => $def['staff_id'], 'minutes' => $def['minutes'], 'remote' => $def['remote'], 'location' => $def['location'],
                        'track_id' => $req['type'] === 'appointment' && $tracks ? $tracks[0]['id'] : null],
                    'lengths' => [30, 45, 60, 90, 120, 180], 'keeps_length' => $def['keeps_length'],
                    'tracks' => $req['type'] === 'appointment' ? $tracks : [],
                    'offered' => array_map(fn($o) => ['start_at' => (string)$o['start_at'], 'staff_name' => (string)($o['staff_name'] ?? '')], request_offers($id))];
            }
            // portal_requests.php's offer_times, step for step: each unreadable time on its own field.
            // A list, or one comma-separated text as client_ids may be ("Y-m-d H:i" holds no comma).
            $times = $input['times'] ?? [];
            if (is_string($times)) $times = explode(',', $times);
            if (!is_array($times)) return api_435_fields(['time1' => 'This time could not be read. Enter a date and a time.']);
            $times = array_values($times);
            foreach ([0, 1, 2] as $ti) if (isset($times[$ti]) && !is_string($times[$ti])) return api_435_fields(['time' . ($ti + 1) => 'This time could not be read. Enter a date and a time.']);
            $starts = [];
            $timeErrs = [];
            foreach ([1, 2, 3] as $n) {
                $v = trim(is_string($times[$n - 1] ?? null) ? $times[$n - 1] : '');
                if ($v === '') continue;
                $ts = strtotime($v);
                if ($ts === false) { $timeErrs['time' . $n] = 'This time could not be read. Enter a date and a time.'; continue; }
                $starts[] = $ts;
            }
            if (count(array_filter($times, fn($t) => is_string($t) && trim($t) !== '')) > 3) $timeErrs['time1'] ??= 'Offer at most three times.';
            if ($timeErrs) return api_435_fields($timeErrs);
            $staff = (int)($input['staff_id'] ?? 0);
            $su = db()->prepare('SELECT COUNT(*) FROM users WHERE id = ? AND is_active = 1');
            $su->execute([$staff]);
            if (!(int)$su->fetchColumn()) return api_435_fields(['staff_id' => 'Choose who will run the session.']);
            if (($why = offer_staff_problem($staff, (int)$req['client_id'])) !== null) return api_435_fields(['staff_id' => $why]);
            $res = offer_times_save($id, $starts, (int)($input['minutes'] ?? 60), $staff, (int)($input['track_id'] ?? 0) ?: null, in_array($input['remote'] ?? null, [true, 1, '1'], true),
                is_string($input['location'] ?? null) ? $input['location'] : '', $userId);
            if ($res['error'] !== null) {
                $field = offer_times_error_field($res);
                if ($field === null) return ['ok' => false, 'error' => $res['error'], 'refused' => $res['refused'] ?? [], 'status' => 422];
                return api_435_fields([['staff' => 'staff_id', 'times' => 'time1'][$field] ?? $field => $res['error']], 422, ['refused' => $res['refused'] ?? []]);
            }
            return ['ok' => true, 'offered' => $res['offered'], 'refused' => $res['refused'], 'emailed' => !empty($res['emailed']),
                'message' => offer_times_message($res, (string)$req['client_name'])];
        }

        case 'send_survey':
        case 'phone_survey':
        case 'closure_followup': {
            $cid = (int)($input['client_id'] ?? 0);
            if ($cid <= 0) return ['ok' => false, 'error' => 'Which client? Send client_id.', 'status' => 422];
            api_require_client($cid, $userId);
            if ($action === 'send_survey') {
                // client.php's "Email the survey" (inc/outcome_followups.php).
                $sent = outcome_survey_send_now($cid, $userId);
                return match ($sent['status']) {
                    'sent', 'unknown' => ['ok' => true, 'status' => $sent['status'], 'message' => $sent['message']],
                    'pending' => ['ok' => false, 'already_sent' => true, 'error' => $sent['message'], 'status' => 409],
                    default => ['ok' => false, 'error' => $sent['message'], 'status' => 422],
                };
            }
            if ($action === 'phone_survey') {
                $ratings = [];
                foreach (['satisfaction' => 'Satisfaction', 'goals_met' => 'Goals met'] as $rk => $rl) {
                    $rv = api_435_form_value($input[$rk] ?? '');
                    if ($rv === null || ($rv !== '' && !preg_match('/^\s*\d+(\.0+)?\s*$/', $rv))) return api_435_fields([$rk => $rl . ': enter a rating from 1 to 10.']);
                    $ratings[$rk] = (int)$rv;
                }
                $err = survey_record_phone($cid, $ratings['satisfaction'], $ratings['goals_met'],
                    is_string($input['comments'] ?? null) ? $input['comments'] : '', $userId);
                if ($err !== null) return api_435_fields([$err => ($err === 'satisfaction' ? 'Satisfaction' : 'Goals met') . ': enter a rating from 1 to 10.']);
                return ['ok' => true, 'message' => 'Survey answers recorded.'];
            }
            // closure_followup: the answers yes, no or "" (Not asked), as the website's form sends them.
            $notes = is_string($input['notes'] ?? null) ? trim($input['notes']) : '';
            if (mb_strlen($notes) > 1000) return api_435_fields(['notes' => 'Notes are too long (1,000 characters maximum).']);
            // Each answer is "yes", "no" or "" (Not asked), as both apps and the website's form send it; anything else is refused.
            foreach (API_435_FOLLOWUP_QUESTIONS as $qk => $ql) {
                $av = $input[$qk] ?? '';
                if (!in_array($av, ['yes', 'no', ''], true)) return api_435_fields([$qk => $ql . ' Choose Yes, No or Not asked.']);
            }
            $fq = db()->prepare('SELECT id FROM closure_followups WHERE id = ? AND client_id = ?');
            $fq->execute([(int)($input['followup_id'] ?? 0), $cid]);
            if (!$fq->fetchColumn()) return ['ok' => false, 'error' => 'That follow-up check is not on this client\'s record. Reload the client and try again.', 'status' => 404];
            $yn = fn(string $k) => closure_followup_yes_no($input[$k] ?? '');
            $err = closure_followup_record((int)($input['followup_id'] ?? 0), $cid, $yn('still_employed'), $yn('still_using_tech'), $yn('independent'), $yn('needs_service'), $notes, $userId);
            if ($err !== null) return ['ok' => false, 'error' => $err, 'status' => 409];
            return ['ok' => true, 'message' => closure_followup_message($yn('needs_service')), 'task_added' => $yn('needs_service') === 1];
        }

        case 'clients_filter': {
            // The caseload the client list opens on, remembered as the website's filter form
            // remembers it (clients.php): the same setting, so the website and the apps agree.
            $user = ['id' => $userId];
            $who = $input['who'] ?? null;
            if (!is_string($who) || !in_array($who, array_column(client_list_who_choices($user), 0), true)) {
                return api_435_fields(['who' => 'Caseload: choose one of the listed caseloads.']);
            }
            if ((setting(client_list_who_key($user)) ?? '') !== $who) set_setting(client_list_who_key($user), $who);
            return ['ok' => true, 'who' => $who];
        }
    }
    return null;
}
