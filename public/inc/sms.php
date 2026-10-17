<?php
declare(strict_types=1);
// Text messages (4.17.0, owner request 2026-10-17): session reminders a client can answer
// (C to confirm, R to reschedule, X to ask to cancel), notices when a session is booked, moved or
// cancelled, new homework, two-way messages with staff, and urgent alerts to staff who chose them.
// Sent through Twilio (Settings, Text messages). Only to a client who agreed to texts at that
// number; STOP and START are honoured; texts wait for 8 a.m. to 8 p.m. in the client's time zone.
// Texts carry a first name, a time and a link at most: never a diagnosis or a case detail.

/** Carrier words that stop texts (US): a reply with one of them opts the number out. */
const SMS_STOP_WORDS = ['STOP', 'STOPALL', 'UNSUBSCRIBE', 'CANCEL', 'END', 'QUIT', 'REVOKE', 'OPTOUT'];
const SMS_START_WORDS = ['START', 'UNSTOP', 'YES', 'OPTIN'];
const SMS_HELP_WORDS = ['HELP', 'INFO'];
const SMS_DAY_START = 8;
const SMS_DAY_END = 20;

/** The texting service set up on Settings, Text messages, or null. */
function sms_cfg(): ?array {
    global $CONFIG;
    $s = is_array($CONFIG['sms'] ?? null) ? $CONFIG['sms'] : [];
    $sid = trim((string)($s['account_sid'] ?? ''));
    $token = trim((string)($s['auth_token'] ?? ''));
    $from = trim((string)($s['from'] ?? ''));
    if ($sid === '' || $token === '' || $from === '') return null;
    return ['sid' => $sid, 'token' => $token, 'from' => $from];
}

function sms_enabled(): bool {
    return sms_cfg() !== null;
}

/** A US phone number written any way ("(515) 555-0100", "515.555.0100", "+1 515...") as +15155550100, or null. */
function sms_number(?string $raw): ?string {
    $raw = trim((string)$raw);
    if ($raw === '') return null;
    $digits = preg_replace('/\D+/', '', $raw) ?? '';
    if (str_starts_with($raw, '+')) return strlen($digits) >= 8 && strlen($digits) <= 15 ? '+' . $digits : null;
    if (strlen($digits) === 10) return '+1' . $digits;
    if (strlen($digits) === 11 && $digits[0] === '1') return '+' . $digits;
    return null;
}

/**
 * A client whose state and time zone are both unknown could be anywhere in the US, so they are
 * texted only while it is 8 a.m. to 8 p.m. in all of these at once: between 8 a.m. in Hawaii and
 * 8 p.m. in Puerto Rico (1 to 7 p.m. Central in summer). Guam and Samoa share no such hours with the rest.
 */
const SMS_UNKNOWN_ZONES = ['America/Puerto_Rico', 'America/New_York', 'America/Chicago', 'America/Denver', 'America/Phoenix',
    'America/Los_Angeles', 'America/Anchorage', 'Pacific/Honolulu'];

/** When a text to someone in [$tz] may go: now, or 8 a.m. their time. Returns a server-time datetime string, or null for now. */
function sms_send_after(?string $tz): ?string {
    return sms_send_after_zones([$tz && in_array($tz, timezone_identifiers_list(), true) ? $tz : date_default_timezone_get()]);
}

/** The same for someone who may be in any of [$zones]: the first moment it is daytime in all of them. */
function sms_send_after_zones(array $zones): ?string {
    $daytime = function (int $ts) use ($zones): bool {
        foreach ($zones as $z) {
            $h = (int)(new DateTimeImmutable('@' . $ts))->setTimezone(new DateTimeZone($z))->format('G');
            if ($h < SMS_DAY_START || $h >= SMS_DAY_END) return false;
        }
        return true;
    };
    $now = time();
    if ($daytime($now)) return null;
    // Every US zone changes hour on the hour, so the first daytime moment is the start of an hour.
    for ($ts = intdiv($now, 3600) * 3600 + 3600, $end = $now + 3 * 86400; $ts <= $end; $ts += 3600) {
        if ($daytime($ts)) return date('Y-m-d H:i:s', $ts);
    }
    return date('Y-m-d H:i:s', intdiv($now, 3600) * 3600 + 86400); // never reached for the zones above
}

/** Today's hours, in office time, when a client with no state on record can be texted: "1 to 7 p.m.". */
function sms_unknown_window(): string {
    $hours = [];
    $midnight = strtotime('today');
    for ($i = 0; $i < 24; $i++) {
        $ts = $midnight + $i * 3600;
        $ok = true;
        foreach (SMS_UNKNOWN_ZONES as $z) {
            $h = (int)(new DateTimeImmutable('@' . $ts))->setTimezone(new DateTimeZone($z))->format('G');
            if ($h < SMS_DAY_START || $h >= SMS_DAY_END) { $ok = false; break; }
        }
        if ($ok) $hours[] = $ts;
    }
    if (!$hours) return 'no hours';
    $fmt = fn(int $ts) => str_replace([':00', 'AM', 'PM'], ['', 'a.m.', 'p.m.'], date('g:i A', $ts));
    return $fmt($hours[0]) . ' to ' . $fmt(end($hours) + 3600) . ' office time';
}

/** The zones a client may be in: their picked zone or their state's, else anywhere in the US. */
function sms_client_zones(?array $c): array {
    $tz = us_zone($c['time_zone'] ?? null) ?? state_default_zone($c['state'] ?? null);
    return $tz !== null ? [$tz] : SMS_UNKNOWN_ZONES;
}

/** A client row with what texting needs, or null. */
function sms_client(int $clientId): ?array {
    $q = db()->prepare('SELECT id, name, phone, status, archived_at, preferred_language, time_zone, state, sms_consent_at, sms_consent_source, sms_opted_out_at, assigned_to FROM clients WHERE id = ?');
    $q->execute([$clientId]);
    return $q->fetch() ?: null;
}

/** Why a client cannot be texted, or null when they can. */
function sms_client_blocked(?array $c): ?string {
    if (!$c) return 'That client was not found.';
    if (!sms_enabled()) return 'Text messages are not set up (Settings, Text messages).';
    if ($c['archived_at'] !== null || str_starts_with((string)$c['status'], 'Closed')) return 'The client\'s case is closed.';
    if (!empty($c['sms_opted_out_at'])) return 'The client replied STOP, so they get no texts until they reply START.';
    if (empty($c['sms_consent_at'])) return 'The client has not agreed to text messages (record it on their edit page).';
    if (sms_number($c['phone'] ?? '') === null) return 'The client\'s phone number cannot receive texts; check it on their edit page.';
    return null;
}

/** The first name, as texts address people. */
function sms_first_name(string $name): string {
    $n = trim(preg_replace('/\s+/', ' ', $name) ?? '');
    return explode(' ', $n)[0] ?: $n;
}

/** Send one text through the service. Returns [provider id|null, status, error|null]. */
function sms_provider_send(string $to, string $body): array {
    // Tests: CMS_TEST_SMS=ok answers as the service would; =fail refuses.
    if (($fake = getenv('CMS_TEST_SMS')) !== false && $fake !== '') {
        return $fake === 'fail' ? [null, 'failed', 'Synthetic refusal'] : ['SM' . bin2hex(random_bytes(16)), 'queued', null];
    }
    $cfg = sms_cfg();
    if (!$cfg) return [null, 'failed', 'Text messages are not set up.'];
    $fields = ['To' => $to, 'Body' => $body, 'StatusCallback' => rtrim((string)cfg('base_url'), '/') . '/sms_status.php'];
    if (str_starts_with($cfg['from'], 'MG')) $fields['MessagingServiceSid'] = $cfg['from']; else $fields['From'] = $cfg['from'];
    $ch = curl_init('https://api.twilio.com/2010-04-01/Accounts/' . rawurlencode($cfg['sid']) . '/Messages.json');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($fields),
        CURLOPT_USERPWD => $cfg['sid'] . ':' . $cfg['token']]);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $data = is_string($resp) ? json_decode($resp, true) : null;
    if ($code >= 200 && $code < 300 && is_array($data) && !empty($data['sid'])) return [(string)$data['sid'], (string)($data['status'] ?? 'queued'), null];
    return [null, 'failed', mb_substr(is_array($data) ? (string)($data['message'] ?? 'Refused') : 'No answer from the texting service', 0, 255)];
}

/**
 * Text a client: kept in the log, sent now in their daytime or held until 8 a.m. their time.
 * Returns ['ok' => bool, 'status' => sent|held|failed|blocked, 'message' => words for staff].
 */
function sms_to_client(int $clientId, string $body, string $purpose, ?int $sessionId = null, ?int $sentBy = null): array {
    $c = sms_client($clientId);
    if ($why = sms_client_blocked($c)) return ['ok' => false, 'status' => 'blocked', 'message' => $why];
    $phone = (string)sms_number($c['phone']);
    $body = mb_substr(trim($body), 0, 1600);
    $after = sms_send_after_zones(sms_client_zones($c));
    db()->prepare('INSERT INTO sms_log (direction, client_id, session_id, phone, body, purpose, status, send_after, sent_by) VALUES (?,?,?,?,?,?,?,?,?)')
        ->execute(['out', $clientId, $sessionId, $phone, $body, mb_substr($purpose, 0, 20), $after ? 'held' : 'queued', $after, $sentBy]);
    $id = (int)db()->lastInsertId();
    if ($after) return ['ok' => true, 'status' => 'held', 'message' => count(sms_client_zones($c)) > 1
        ? 'The client\'s state is not on their record, so texts go only when it is daytime everywhere in the US (' . sms_unknown_window() . '). This one goes ' . (date('Y-m-d', strtotime($after)) === date('Y-m-d') ? 'at ' . date('g:i A', strtotime($after)) : (date('Y-m-d', strtotime($after)) === date('Y-m-d', strtotime('+1 day')) ? 'tomorrow at ' . date('g:i A', strtotime($after)) : 'on ' . date('D, M j \a\t g:i A', strtotime($after)))) . ' office time. Add their state to text them in their own daytime.'
        : 'It is outside 8 a.m. to 8 p.m. where the client is, so the text goes at 8 a.m. their time.'];
    return sms_dispatch($id);
}

/** Send a logged text now (rechecking a client's consent for one that waited). */
function sms_dispatch(int $logId): array {
    $q = db()->prepare("SELECT * FROM sms_log WHERE id = ? AND direction = 'out' AND status IN ('queued','held')");
    $q->execute([$logId]);
    $row = $q->fetch();
    if (!$row) return ['ok' => false, 'status' => 'failed', 'message' => 'That text is no longer waiting.'];
    $c = $row['client_id'] !== null ? sms_client((int)$row['client_id']) : null;
    $why = $row['client_id'] !== null ? sms_client_blocked($c) : null;
    // A session text that waited is sent only if the session still stands at the time it names.
    if ($why === null && $c && $row['session_id'] !== null && in_array($row['purpose'], ['reminder', 'booked', 'moved'], true)) {
        $sq = db()->prepare("SELECT start_at FROM scheduled_sessions WHERE id = ? AND status = 'Scheduled' AND start_at > NOW()");
        $sq->execute([(int)$row['session_id']]);
        $at = $sq->fetchColumn();
        if ($at === false || !str_contains((string)$row['body'], sms_when($c, strtotime((string)$at)))) $why = 'The session was moved or cancelled before the text could go.';
    }
    if ($why !== null) {
        db()->prepare("UPDATE sms_log SET status = 'blocked', error = ?, updated_at = NOW() WHERE id = ?")->execute([mb_substr($why, 0, 255), $logId]);
        return ['ok' => false, 'status' => 'blocked', 'message' => $why];
    }
    // Claimed first, so two jobs running at once never send the same text twice.
    $claim = db()->prepare("UPDATE sms_log SET status = 'sending', updated_at = NOW() WHERE id = ? AND status IN ('queued','held')");
    $claim->execute([$logId]);
    if ($claim->rowCount() !== 1) return ['ok' => false, 'status' => 'failed', 'message' => 'That text is no longer waiting.'];
    [$sid, $status, $err] = sms_provider_send((string)$row['phone'], (string)$row['body']);
    db()->prepare('UPDATE sms_log SET status = ?, provider_sid = ?, error = ?, updated_at = NOW() WHERE id = ?')->execute([$sid ? 'sent' : 'failed', $sid, $err, $logId]);
    return $sid ? ['ok' => true, 'status' => 'sent', 'message' => 'Text sent.'] : ['ok' => false, 'status' => 'failed', 'message' => 'The texting service refused it: ' . $err];
}

/** Texts held overnight whose time has come (run by the reminder job, twice a day, and the daily job). */
function sms_flush_held(): int {
    $n = 0;
    try {
        foreach (db()->query("SELECT id FROM sms_log WHERE direction = 'out' AND status = 'held' AND send_after <= NOW() ORDER BY id LIMIT 500")->fetchAll(PDO::FETCH_COLUMN) as $id) {
            if (sms_dispatch((int)$id)['ok']) $n++;
        }
    } catch (Throwable $e) {
        error_log('sms_flush_held: ' . get_class($e));
    }
    return $n;
}

/** Text a staff member's own mobile (urgent alerts they chose). No client details, ever. */
function sms_to_staff(int $userId, string $body, string $purpose): bool {
    if (!sms_enabled()) return false;
    $q = db()->prepare('SELECT mobile, alert_sms FROM users WHERE id = ? AND is_active = 1');
    $q->execute([$userId]);
    $u = $q->fetch();
    $phone = $u ? sms_number($u['mobile']) : null;
    if (!$u || !(int)$u['alert_sms'] || $phone === null) return false;
    db()->prepare('INSERT INTO sms_log (direction, user_id, phone, body, purpose, status) VALUES (?,?,?,?,?,?)')->execute(['out', $userId, $phone, mb_substr($body, 0, 320), $purpose, 'queued']);
    return sms_dispatch((int)db()->lastInsertId())['ok'];
}

/* ---------- What texts say ---------- */

/** "Thu, Oct 22 at 2:00 PM CDT" in the client's own time zone. */
function sms_when(array $client, int $ts): string {
    if (client_lang($client) === 'es') return zone_when_es(client_zone($client), $ts);
    return zone_date(client_zone($client), 'D, M j \a\t g:i A T', $ts);
}

/** A session text: reminder, booked, moved or cancelled. First name, time and, for an online session, the link. */
function sms_session_text(array $client, array $s, string $kind): string {
    $es = client_lang($client) === 'es';
    $first = sms_first_name((string)$client['name']);
    $org = trim((string)(brand('short') ?: cfg('name')));
    $when = sms_when($client, strtotime((string)$s['start_at']));
    $url = trim((string)($s['meeting_url'] ?? ''));
    $where = !empty($s['is_remote']) ? ($es ? ' (en línea' : ' (online') . (preg_match('~^https://\S+$~i', $url) ? ': ' . $url : '') . ')'
        : (setting('sms_include_location') === 'yes' && trim((string)($s['location'] ?? '')) !== '' ? ($es ? ' en ' : ' at ') . trim((string)$s['location']) : '');
    $replies = $es ? ' Responda C para confirmar, R para cambiar la hora o X para pedir cancelarla.' : ' Reply C to confirm, R to reschedule, X to ask to cancel.';
    $stop = $es ? ' Responda STOP para no recibir mensajes.' : ' Reply STOP to stop texts.';
    return match ($kind) {
        'reminder' => ($es ? "Hola $first, le recordamos su sesión de $org el $when$where." : "Hi $first, a reminder of your $org session on $when$where.") . $replies . $stop,
        'booked' => ($es ? "Hola $first, su sesión de $org es el $when$where." : "Hi $first, your $org session is booked for $when$where.") . $stop,
        'moved' => ($es ? "Hola $first, su sesión de $org cambió al $when$where." : "Hi $first, your $org session has moved to $when$where.") . $stop,
        'cancelled' => ($es ? "Hola $first, su sesión de $org del $when fue cancelada." : "Hi $first, your $org session on $when was cancelled.") . $stop,
        default => '',
    };
}

/** Booked, moved or cancelled sessions, texted to a client who agreed (one text for the first session of a series). */
function sms_session_notice(array $sessionIds, string $kind): void {
    if (!sms_enabled() || !$sessionIds) return;
    try {
        $ids = array_values(array_unique(array_map('intval', $sessionIds)));
        $q = db()->prepare('SELECT * FROM scheduled_sessions WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY start_at');
        $q->execute($ids);
        $rows = $q->fetchAll();
        if (!$rows) return;
        $c = sms_client((int)$rows[0]['client_id']);
        if (!$c || sms_client_blocked($c)) return;
        $body = sms_session_text($c, $rows[0], $kind);
        if (count($rows) > 1 && $kind !== 'moved') $body = str_replace(['session is booked for', 'session on '], ['sessions start on', 'sessions from '], $body);
        sms_to_client((int)$c['id'], $body, $kind, (int)$rows[0]['id']);
    } catch (Throwable $e) {
        error_log('sms_session_notice: ' . get_class($e));
    }
}

/* ---------- Texts that come in ---------- */

/** Every client with this phone number (siblings, or a guardian's phone), current ones first. */
function sms_clients_by_phone(string $phone): array {
    $digits = substr(preg_replace('/\D+/', '', $phone) ?? '', -10);
    if (strlen($digits) < 10) return [];
    $q = db()->prepare("SELECT id FROM clients WHERE phone IS NOT NULL AND RIGHT(REGEXP_REPLACE(phone, '[^0-9]', ''), 10) = ? ORDER BY archived_at IS NULL DESC, id DESC");
    $q->execute([$digits]);
    return array_values(array_filter(array_map(fn($id) => sms_client((int)$id), $q->fetchAll(PDO::FETCH_COLUMN))));
}

/** The one client this phone belongs to, or null when none, or when several current clients share it. */
function sms_client_by_phone(string $phone): ?array {
    $all = sms_clients_by_phone($phone);
    $current = array_values(array_filter($all, fn($c) => $c['archived_at'] === null && !str_starts_with((string)$c['status'], 'Closed')));
    if (count($current) > 1) return null;
    return $current[0] ?? $all[0] ?? null;
}

/** The session a C, R or X answers: the client's next one that was reminded, or their next within a week. */
function sms_reply_session(int $clientId): ?array {
    $q = db()->prepare("SELECT * FROM scheduled_sessions WHERE client_id = ? AND status = 'Scheduled' AND start_at > NOW() AND start_at < DATE_ADD(NOW(), INTERVAL 8 DAY)
        ORDER BY sms_reminder_sent DESC, start_at LIMIT 1");
    $q->execute([$clientId]);
    return $q->fetch() ?: null;
}

/**
 * A text that came in: STOP or START, HELP, C/R/X to a reminder, or a message for staff.
 * Returns the reply to send back, or null for none (the service answers STOP itself).
 */
function sms_inbound(string $from, string $body, string $sid): ?string {
    $from = sms_number($from) ?? mb_substr($from, 0, 20);
    $text = trim($body);
    $word = strtoupper(preg_replace('/[^A-Za-z]/', '', $text) ?? '');
    $everyone = sms_clients_by_phone($from);
    $c = sms_client_by_phone($from);
    try {
        db()->prepare('INSERT INTO sms_log (direction, client_id, phone, body, purpose, status, provider_sid) VALUES (?,?,?,?,?,?,?)')
            ->execute(['in', $c['id'] ?? null, $from, mb_substr($text, 0, 1600), 'reply', 'received', $sid !== '' ? mb_substr($sid, 0, 64) : null]);
    } catch (PDOException $e) {
        if ((int)($e->errorInfo[1] ?? 0) === 1062) return null; // the same text delivered twice
        throw $e;
    }
    $org = trim((string)(brand('short') ?: cfg('name')));
    $contact = trim((string)(cfg('support_email') ?: cfg('mail_from')));
    if (in_array($word, SMS_STOP_WORDS, true)) {
        // STOP is for the number: every client with it, and a staff member's alert texts.
        foreach ($everyone as $one) {
            db()->prepare('UPDATE clients SET sms_opted_out_at = NOW() WHERE id = ? AND sms_opted_out_at IS NULL')->execute([(int)$one['id']]);
            audit(null, 'sms.opt_out', 'client', (int)$one['id'], 'replied ' . $word);
        }
        db()->prepare('UPDATE users SET alert_sms = 0 WHERE mobile = ?')->execute([$from]);
        // "Cancel" stops texts (a carrier rule), but a client answering a reminder with it also
        // meant the session: staff are asked to cancel it, so nothing is silently lost.
        if ($word === 'CANCEL' && $c && ($s = sms_reply_session((int)$c['id'])) && (int)$s['sms_reminder_sent'] === 1) sms_reply_request($c, $s, 'X');
        return null; // the texting service confirms the opt-out itself
    }
    if (in_array($word, SMS_START_WORDS, true)) {
        $restarted = false;
        foreach ($everyone as $one) {
            if (empty($one['sms_opted_out_at'])) continue;
            db()->prepare('UPDATE clients SET sms_opted_out_at = NULL, sms_consent_at = COALESCE(sms_consent_at, NOW()), sms_consent_source = COALESCE(sms_consent_source, ?) WHERE id = ?')
                ->execute(['Replied START', (int)$one['id']]);
            audit(null, 'sms.opt_in', 'client', (int)$one['id'], 'replied ' . $word);
            $restarted = true;
        }
        if ($restarted) return "You will get texts from $org again. Reply STOP to stop.";
        if ($word !== 'YES') return null;
    }
    if (in_array($word, SMS_HELP_WORDS, true)) return "$org: appointment texts. " . ($contact !== '' ? "Email $contact for help. " : '') . 'Reply STOP to stop texts.';
    if (!$c) {
        // Several current clients share the number: staff decide whose it is.
        notify_admin_users($everyone ? 'A text came from a phone number several clients share, so it was not filed under one of them. Read it under Administration, Text messages.'
            : 'A text came from a number that is not on any client record. Read it under Administration, Text messages.', 'sms.php');
        return null;
    }
    $cid = (int)$c['id'];
    $es = client_lang($c) === 'es';
    if (in_array($word, ['C', 'R', 'X'], true) && ($s = sms_reply_session($cid))) {
        $when = sms_when($c, strtotime((string)$s['start_at']));
        $what = ['C' => 'confirmed', 'R' => 'reschedule', 'X' => 'cancel'][$word];
        db()->prepare('UPDATE scheduled_sessions SET client_reply = ?, client_reply_at = NOW() WHERE id = ?')->execute([$what, (int)$s['id']]);
        $staff = (int)($s['user_id'] ?? 0) ?: (int)($c['assigned_to'] ?? 0);
        if ($word === 'C') {
            if ($staff) notify_user($staff, sms_first_name((string)$c['name']) . ' confirmed the session on ' . date('D, M j g:i A', strtotime((string)$s['start_at'])) . ' by text.', 'schedule.php#s-' . (int)$s['id']);
            return $es ? "Gracias, su sesión del $when está confirmada." : "Thank you, your session on $when is confirmed.";
        }
        sms_reply_request($c, $s, $word);
        return $word === 'R'
            ? ($es ? 'Gracias. Le contactaremos para elegir otra hora.' : 'Thank you. We will contact you to find a new time.')
            : ($es ? 'Gracias. Avisamos a su instructor, quien confirmará la cancelación.' : 'Thank you. We have told your instructor, who will confirm the cancellation.');
    }
    // Anything else is a message for staff, in the client's message thread (as from the portal).
    $mid = post_message($cid, 'client', 'client', null, $text);
    db()->prepare("UPDATE messages SET via = 'text' WHERE id = ?")->execute([$mid]);
    $staff = (int)($c['assigned_to'] ?? 0);
    if ($staff) notify_user($staff, 'New text message from ' . sms_first_name((string)$c['name']) . '.', 'messages.php?client=' . $cid, true);
    else notify_admin_users('New text message from a client with no assigned staff member.', 'messages.php?client=' . $cid);
    return null;
}

/** R or X to a reminder: a request for staff to act on (portal requests), never done by a text alone. */
function sms_reply_request(array $c, array $s, string $word): void {
    $when = sms_when($c, strtotime((string)$s['start_at']));
    db()->prepare('INSERT INTO portal_requests (client_id, type, session_id, details, status) VALUES (?,?,?,?,?)')
        ->execute([(int)$c['id'], $word === 'R' ? 'reschedule' : 'cancel', (int)$s['id'], ($word === 'R' ? 'Asked by text to reschedule' : 'Asked by text to cancel') . ' the session on ' . $when . '.', 'New']);
    $staff = (int)($s['user_id'] ?? 0) ?: (int)($c['assigned_to'] ?? 0);
    if ($staff) notify_user($staff, sms_first_name((string)$c['name']) . ' asked by text to ' . ($word === 'R' ? 'reschedule' : 'cancel') . ' the session on ' . date('D, M j g:i A', strtotime((string)$s['start_at'])) . '.', 'portal_requests.php', true);
    else notify_admin_users('A client with no assigned staff member asked by text to ' . ($word === 'R' ? 'reschedule' : 'cancel') . ' a session.', 'portal_requests.php');
}

/** A delivery report from the service. */
function sms_status_update(string $sid, string $status, ?string $error): void {
    if ($sid === '') return;
    $status = in_array($status, ['queued', 'sending', 'sent', 'delivered', 'undelivered', 'failed', 'read'], true) ? $status : 'sent';
    // Reports can arrive out of order: a late "sent" never replaces a final answer.
    $final = "('delivered','undelivered','failed','read')";
    db()->prepare("UPDATE sms_log SET status = ?, error = ?, updated_at = NOW() WHERE provider_sid = ? AND direction = 'out'"
        . (in_array($status, ['queued', 'sending', 'sent'], true) ? " AND status NOT IN $final" : ''))->execute([$status, $error !== null ? mb_substr($error, 0, 255) : null, $sid]);
    // Error 21610: the number replied STOP to the service itself, so it is opted out here too.
    if ($error === 'Error 21610') {
        $q = db()->prepare("SELECT client_id FROM sms_log WHERE provider_sid = ? AND client_id IS NOT NULL");
        $q->execute([$sid]);
        if ($cid = (int)$q->fetchColumn()) db()->prepare('UPDATE clients SET sms_opted_out_at = COALESCE(sms_opted_out_at, NOW()) WHERE id = ?')->execute([$cid]);
    }
}

/** Twilio's X-Twilio-Signature check: HMAC-SHA1 of the full address and the sorted fields, keyed with the auth token. */
function sms_signature_ok(string $url, array $fields, string $signature): bool {
    $cfg = sms_cfg();
    if (!$cfg || $signature === '') return false;
    ksort($fields);
    $data = $url;
    foreach ($fields as $k => $v) $data .= $k . (is_scalar($v) ? (string)$v : '');
    return hash_equals(base64_encode(hash_hmac('sha1', $data, $cfg['token'], true)), $signature);
}

/** A client's texting standing in words, for their record. */
function sms_client_words(array $c): string {
    if (!empty($c['sms_opted_out_at'])) return 'Replied STOP on ' . fmt_date((string)$c['sms_opted_out_at']) . '; they can reply START to get texts again.';
    if (!empty($c['sms_consent_at'])) return 'Agreed to texts on ' . fmt_date((string)$c['sms_consent_at']) . ($c['sms_consent_source'] ? ' (' . $c['sms_consent_source'] . ')' : '') . '.'
        . (array_key_exists('state', $c) && count(sms_client_zones($c)) > 1 ? ' Their state is not on their record, so texts go only from ' . sms_unknown_window() . '; add it to text them in their own daytime.' : '');
    return 'Has not agreed to texts.';
}

const SMS_CONSENT_SOURCES = ['In person', 'By phone', 'In writing', 'Referral form', 'Client portal'];

/**
 * Record a client's agreement to texts, as staff saved it: agreed (with how), withdrawn, or
 * cleared because the phone number changed (the agreement is for one number). A client who
 * replied STOP is not changed here: only their own START turns texts back on.
 */
function sms_consent_save(int $clientId, bool $agreed, string $source, ?string $oldPhone, ?int $by): ?string {
    $c = sms_client($clientId);
    if (!$c || !empty($c['sms_opted_out_at'])) return null;
    $source = in_array($source, SMS_CONSENT_SOURCES, true) ? $source : 'In person';
    // The agreement is for one number: a changed number clears one already recorded, even with
    // the box still ticked (it was ticked for the old number), so staff record it again once the
    // client agrees for the new one. A first agreement given with a new number is recorded.
    if ($oldPhone !== null && !empty($c['sms_consent_at']) && sms_number($oldPhone) !== sms_number((string)$c['phone'])) {
        db()->prepare('UPDATE clients SET sms_consent_at = NULL, sms_consent_source = NULL, sms_consent_by = NULL WHERE id = ?')->execute([$clientId]);
        audit($by, 'sms.consent', 'client', $clientId, 'number changed');
        return 'The phone number changed, so texts are off until you record the client\'s agreement for the new number.';
    }
    if ($agreed && empty($c['sms_consent_at'])) {
        if (sms_number((string)$c['phone']) === null) return 'The agreement to texts was not recorded: the phone number cannot receive texts.';
        db()->prepare('UPDATE clients SET sms_consent_at = NOW(), sms_consent_source = ?, sms_consent_by = ? WHERE id = ?')->execute([$source, $by, $clientId]);
        audit($by, 'sms.consent', 'client', $clientId, 'agreed to texts (' . $source . ')');
    } elseif (!$agreed && !empty($c['sms_consent_at'])) {
        db()->prepare('UPDATE clients SET sms_consent_at = NULL, sms_consent_source = NULL, sms_consent_by = NULL WHERE id = ?')->execute([$clientId]);
        audit($by, 'sms.consent', 'client', $clientId, 'texts withdrawn');
    }
    return null;
}
