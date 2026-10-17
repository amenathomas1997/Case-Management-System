<?php
declare(strict_types=1);
// Voice without the AI service, and more voice commands (4.0.0). Fixed requests (go to a
// section, search, open a client, the questions, hearing messages and alerts, contact
// details, billing answers, expiring authorizations, cancel or no-show a session) are
// understood here, on the server, with nothing sent to an AI provider. Only free-form
// requests (a note's wording, a new goal) still go to the provider, when one is on.

/**
 * Whether the voice assistant is on. Its own switch (AI assistant page): it no longer needs
 * the AI service. Until an administrator sets it, it follows whether AI is on, so a site that
 * never turned AI on gets no new button on every page.
 */
function voice_available(): bool {
    $v = setting('ai_feat_voice');
    return $v === null ? (function_exists('ai_enabled') && ai_enabled()) : $v !== '0';
}

/** Whether words heard as a client's name could be one: not a pronoun or filler, and at most four words. */
function voice_name_ok(string $name): bool {
    $n = trim($name);
    if ($n === '' || count(preg_split('/\s+/u', $n)) > 4) return false;
    return !preg_match('/^(?:my|the|this|that|his|her|their|our|your|a|an|it|next|today|todays|tomorrow|tomorrows|all|every|each|them|him|me|that client|this client)$/u', $n);
}

/**
 * The request as one of the fixed commands, in the same shape the AI interpreter gives
 * (action, section, query, client, ask, create), or null when it needs the AI.
 */
function voice_builtin_match(string $said, array $sections): ?array {
    $t = mb_strtolower(trim((string)preg_replace('/[.?!,]+$/u', '', trim($said))));
    $t = (string)preg_replace('/^(please|hey|ok|okay|can you|could you|would you)\s+/u', '', $t);
    $t = (string)preg_replace('/\s+(please|for me)$/u', '', $t);
    if ($t === '') return null;
    $poss = fn(string $s) => trim((string)preg_replace("/['’]s$|s['’]$/u", '', trim($s)));
    if (preg_match('/^(help|what can you do|what do you do)$/u', $t)) return ['action' => 'help'];
    // Sessions: cancel, or mark a no-show.
    // Only these exact shapes, with a real name: "cancel my next appointment" or "add a note
    // that John was a no show" go to the AI (or are not understood), never to a session.
    if (preg_match("/^cancel (?:the |my )?(?:next )?(?:session|appointment) (?:with|for) (.+)$/u", $t, $m)
        || preg_match("/^cancel (.+?)(?:['’]s)? (?:next )?(?:session|appointment)$/u", $t, $m)) {
        return voice_name_ok($poss($m[1])) ? ['action' => 'create', 'create' => 'cancel_session', 'client' => $poss($m[1])] : null;
    }
    if (preg_match("/^(?:mark|record) (.+?)(?:['’]s)? (?:session |appointment )?(?:as )?(?:a )?no[ -]?show$/u", $t, $m)
        || preg_match('/^(?:mark|record) (?:a )?no[ -]?show (?:for|with) (.+)$/u', $t, $m)) {
        return voice_name_ok($poss($m[1])) ? ['action' => 'create', 'create' => 'no_show', 'client' => $poss($m[1])] : null;
    }
    // Questions answered aloud.
    $asks = [
        '/^(?:what are |read (?:me )?|check |any |are there any |do i have (?:any )?)?(?:my )?(?:new |unread )?messages$/u' => 'unread_messages',
        '/^(?:what are |read (?:me )?|check |any |are there any |do i have (?:any )?)?(?:my )?(?:new |unread )?(?:alerts|notifications)$/u' => 'unread_alerts',
        '/^(?:any |are there )?new referrals$|^(?:what are the )?new referrals$/u' => 'new_referrals',
        '/^(?:any |open )?(?:client|portal) requests$|^(?:what )?(?:client )?requests (?:are )?(?:open|waiting)$/u' => 'open_requests',
        '/^(?:what(?:\'s| is) |how much (?:is )?|is there (?:any )?(?:work )?)?(?:any )?(?:work )?(?:is )?(?:unbilled|not (?:yet )?billed|ready to bill|not invoiced)(?: work)?(?: yet)?$/u' => 'billing_unbilled',
        '/^(?:what are |any |show |list )?(?:the |my )?(?:unpaid|outstanding|short[ -]?paid) invoices$|^who owes(?: us)?(?: money)?$|^what is owed$|^accounts receivable$|^\baging\b(?: report)?$/u' => 'invoices_unpaid',
        '/^(?:which |what |any )?(?:authori[sz]ations? (?:are )?(?:expiring|ending|running out|about to expire)(?: soon)?|expiring authori[sz]ations?)$/u' => 'auths_expiring',
        '/^(?:what(?:\'s| is) )?my next (?:appointment|session)$/u' => 'next_appointment',
        '/^(?:what(?:\'s| is) )?(?:on )?my schedule (?:for )?today$|^(?:my )?appointments today$|^what do i have today$/u' => 'schedule_today',
        '/^(?:what are |show |read )?(?:my )?(?:appointments|sessions|schedule) (?:for |this )?(?:the )?week$/u' => 'schedule_week',
        '/^(?:what )?tasks (?:are )?due(?: today)?$|^my tasks$/u' => 'tasks_due',
        '/^how many clients(?: do i have)?$|^my caseload$/u' => 'caseload',
        '/^(?:what |which |any )?(?:device )?follow[ -]?ups? (?:are )?due$/u' => 'followups_due',
    ];
    foreach ($asks as $re => $type) if (preg_match($re, $t)) return ['action' => 'ask', 'ask' => $type];
    $personAsks = [
        "/^(?:what(?:'s| is) )?(?:the )?(?:phone|number|phone number|email|contact details?|contact info(?:rmation)?) (?:for|of) (.+)$/u" => 'client_contact',
        "/^(?:what(?:'s| is) )?(.+?)['’]s (?:phone|number|phone number|email|contact details?|contact info(?:rmation)?)$/u" => 'client_contact',
        "/^(?:what(?:'s| is) )?(.+?)['’]s status$/u" => 'client_status',
        "/^when is (.+?)['’]s next (?:session|appointment)$|^(?:what(?:'s| is) )?my next (?:appointment|session) with (.+)$/u" => 'client_next_session',
        "/^how many hours (?:does|has) (.+?) (?:have )?left$|^(.+?)['’]s (?:remaining hours|hours left|authori[sz]ation)$/u" => 'auth_remaining',
        "/^read (?:me )?(.+?)['’]s (?:latest |last |most recent )?note$/u" => 'read_notes',
        "/^(?:what are|read (?:me )?) (.+?)['’]s goals$/u" => 'read_goals',
        "/^(?:what(?:'s| is)|read (?:me )?) (.+?)['’]s homework$/u" => 'read_homework',
    ];
    foreach ($personAsks as $re => $type) if (preg_match($re, $t, $m)) {
        $who = trim((string)(array_values(array_filter(array_slice($m, 1), fn($x) => $x !== ''))[0] ?? ''));
        if ($who !== '') return ['action' => 'ask', 'ask' => $type, 'client' => $poss($who)];
    }
    // Moving around.
    if (preg_match('/^(?:search(?: for)?|find|look up)\s+(.+)$/u', $t, $m)) return ['action' => 'search', 'query' => trim($m[1])];
    if (preg_match('/^(?:go to|open|show(?: me)?|take me to|switch to)\s+(?:the\s+|my\s+)?(.+?)(?:\s+page|\s+list|\s+screen)?$/u', $t, $m)) {
        $sec = trim($m[1]);
        if (isset($sections[$sec])) return ['action' => 'navigate', 'section' => $sec];
        if (isset($sections[rtrim($sec, 's')])) return ['action' => 'navigate', 'section' => rtrim($sec, 's')];
        if (preg_match('/^(?:open|show(?: me)?|pull up)\s/u', $t)) return ['action' => 'open_client', 'client' => $poss((string)preg_replace("/\s+(?:record|file|profile)$/u", '', $sec))];
    }
    if (preg_match('/^pull up\s+(.+)$/u', $t, $m)) return ['action' => 'open_client', 'client' => $poss((string)preg_replace("/\s+(?:record|file|profile)$/u", '', $m[1]))];
    return null;
}

/** The new spoken answers. Returns ['speak', 'show'?, 'label'?] or null for a type this does not answer. */
function voice_answer_more(string $type, string $clientName, array $user): ?array {
    $uid = (int)$user['id'];
    $scoped = caseload_scoped();
    $scopeC = $scoped ? ' AND c.assigned_to = ' . $uid : '';
    switch ($type) {
        case 'unread_messages': {
            if (!can('clients')) return ['speak' => "You don't have access to messages."];
            $q = db()->query("SELECT m.body, m.channel, c.name FROM messages m JOIN clients c ON c.id = m.client_id WHERE m.sender <> 'staff' AND m.read_at IS NULL" . $scopeC . ' ORDER BY m.created_at LIMIT 5');
            $rows = $q->fetchAll();
            if (!$rows) return ['speak' => 'You have no unread messages.'];
            $parts = array_map(fn($r) => 'From ' . ($r['channel'] === 'counselor' ? $r['name'] . "'s counselor" : $r['name']) . ': ' . mb_strimwidth(trim((string)$r['body']), 0, 220, '…'), $rows);
            return ['speak' => count($rows) . ' unread message' . (count($rows) === 1 ? '' : 's') . (count($rows) === 5 ? ' or more' : '') . '. ' . implode(' ', $parts) . ' To reply, open messages.', 'show' => 'messages.php', 'label' => 'Go to messages'];
        }
        case 'unread_alerts': {
            $q = db()->prepare('SELECT message FROM notifications WHERE user_id = ? AND read_at IS NULL ORDER BY created_at DESC LIMIT 5');
            $q->execute([$uid]);
            $rows = $q->fetchAll(PDO::FETCH_COLUMN);
            if (!$rows) return ['speak' => 'You have no unread alerts.'];
            return ['speak' => count($rows) . ' unread alert' . (count($rows) === 1 ? '' : 's') . (count($rows) === 5 ? ' or more' : '') . ': ' . implode('. ', $rows) . '.', 'show' => 'notifications.php', 'label' => 'Go to alerts'];
        }
        case 'new_referrals': {
            if (!can('referrals')) return ['speak' => "You don't have access to referrals."];
            $q = db()->prepare("SELECT client_name, created_at FROM referrals WHERE status = 'New'" . ($scoped ? ' AND (assigned_to = ? OR assigned_to IS NULL)' : '') . ' ORDER BY created_at LIMIT 5');
            $q->execute($scoped ? [$uid] : []);
            $rows = $q->fetchAll();
            if (!$rows) return ['speak' => 'There are no new referrals.'];
            return ['speak' => count($rows) . ' new referral' . (count($rows) === 1 ? '' : 's') . (count($rows) === 5 ? ' or more' : '') . ': ' . implode('; ', array_map(fn($r) => $r['client_name'] . ', received ' . date('F j', strtotime($r['created_at'])), $rows)) . '.', 'show' => 'referrals.php', 'label' => 'Go to referrals'];
        }
        case 'open_requests': {
            if (!can('clients')) return ['speak' => "You don't have access to client requests."];
            $rows = db()->query("SELECT pr.type, c.name FROM portal_requests pr JOIN clients c ON c.id = pr.client_id WHERE pr.status IN ('New','In Progress')" . $scopeC . ' ORDER BY pr.created_at LIMIT 5')->fetchAll();
            if (!$rows) return ['speak' => 'There are no open client requests.'];
            $words = ['appointment' => 'an appointment', 'reschedule' => 'a new time', 'cancel' => 'a cancellation', 'device' => 'device help', 'records' => 'a copy of their records', 'correction' => 'a correction'];
            return ['speak' => count($rows) . ' open client request' . (count($rows) === 1 ? '' : 's') . ': ' . implode('; ', array_map(fn($r) => $r['name'] . ' asked for ' . ($words[$r['type']] ?? 'help'), $rows)) . '.', 'show' => 'portal_requests.php', 'label' => 'Go to client requests'];
        }
        case 'client_contact': {
            $client = voice_find_client($clientName, $uid, $ambiguous, $sameName);
            if (!empty($ambiguous)) return ['speak' => voice_which_client($clientName, !empty($sameName))];
            if (!$client) return ['speak' => "I couldn't find that client on your caseload."];
            $q = db()->prepare('SELECT phone, email, counselor_name, counselor_phone, counselor_email FROM clients WHERE id = ?');
            $q->execute([(int)$client['id']]);
            $c = $q->fetch();
            $say = fn(?string $p) => $p ? implode(' ', str_split((string)preg_replace('/\D/', '', $p))) : '';
            $bits = [];
            $bits[] = $client['name'] . "'s phone is " . ($c['phone'] ? $say($c['phone']) : 'not on file') . ($c['email'] ? ', email ' . $c['email'] : '');
            if ($c['counselor_name'] || $c['counselor_phone'] || $c['counselor_email']) $bits[] = 'Their counselor ' . ($c['counselor_name'] ?: '') . ($c['counselor_phone'] ? ', phone ' . $say($c['counselor_phone']) : '') . ($c['counselor_email'] ? ', email ' . $c['counselor_email'] : '');
            return ['speak' => implode('. ', $bits) . '.', 'show' => 'client.php?id=' . (int)$client['id'], 'label' => 'Go to ' . $client['name'] . "'s record"];
        }
        case 'billing_unbilled': {
            if (!can('billing')) return ['speak' => "You don't have access to billing."];
            $r = db()->query("SELECT COALESCE(SUM(w.hours), 0) h, COALESCE(SUM(ROUND(w.hours * COALESCE(w.hourly_rate, t.hourly_rate), 2) + ROUND(w.mileage * COALESCE(w.mileage_rate, t.mileage_rate), 2)), 0) v,
                COUNT(DISTINCT t.id) tracks FROM work_logs w JOIN training_tracks t ON t.id = w.track_id JOIN clients c ON c.id = t.client_id WHERE w.is_billed = 0 AND t.billing_mode <> 'none'" . $scopeC)->fetch();
            if ((float)$r['h'] <= 0 && (float)$r['v'] <= 0) return ['speak' => 'Nothing is waiting to be billed.'];
            return ['speak' => voice_say_hours((float)$r['h']) . ((float)$r['h'] == 1.0 ? ' hour' : ' hours') . ' worth ' . fmt_money((float)$r['v']) . ' are not invoiced yet, on ' . (int)$r['tracks'] . ' track' . ((int)$r['tracks'] === 1 ? '' : 's') . '.', 'show' => 'invoices.php', 'label' => 'Go to invoices'];
        }
        case 'invoices_unpaid': {
            if (!can('billing')) return ['speak' => "You don't have access to billing."];
            $r = db()->query("SELECT COUNT(*) n, COALESCE(SUM(GREATEST(i.total_amount - COALESCE(i.paid_amount, 0) - i.written_off, 0)), 0) owed,
                COALESCE(SUM(CASE WHEN COALESCE(i.submitted_at, i.created_at) < DATE_SUB(CURDATE(), INTERVAL 60 DAY) THEN GREATEST(i.total_amount - COALESCE(i.paid_amount, 0) - i.written_off, 0) ELSE 0 END), 0) old,
                COALESCE(SUM(COALESCE(i.paid_amount, 0) > 0), 0) part FROM invoices i JOIN training_tracks t ON t.id = i.track_id JOIN clients c ON c.id = t.client_id
                WHERE i.status = 'Submitted' AND i.total_amount - COALESCE(i.paid_amount, 0) - i.written_off > 0" . $scopeC)->fetch();
            if ((int)$r['n'] === 0) return ['speak' => 'No invoices are waiting for payment.'];
            return ['speak' => (int)$r['n'] . ' invoice' . ((int)$r['n'] === 1 ? ' is' : 's are') . ' waiting for payment, ' . fmt_money((float)$r['owed']) . ' in all'
                . ((int)$r['part'] ? ', ' . (int)$r['part'] . ' of them part paid' : '') . ((float)$r['old'] > 0 ? '. ' . fmt_money((float)$r['old']) . ' of it is more than 60 days old' : '') . '.', 'show' => 'invoices.php', 'label' => 'Go to invoices'];
        }
        case 'auths_expiring': {
            $rows = db()->query("SELECT c.name, t.expiration_date FROM training_tracks t JOIN clients c ON c.id = t.client_id WHERE t.status = 'Active' AND t.billing_mode = 'vr'
                AND t.expiration_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL " . cms_number('auth_soon_days') . " DAY)" . $scopeC . ' ORDER BY t.expiration_date LIMIT 6')->fetchAll();
            $soonDays = cms_number('auth_soon_days');
            if (!$rows) return ['speak' => 'No authorizations expire in the next ' . $soonDays . ' days.'];
            return ['speak' => count($rows) . ' authorization' . (count($rows) === 1 ? '' : 's') . ' expire in the next ' . $soonDays . ' days: ' . implode('; ', array_map(fn($r) => $r['name'] . ' on ' . date('F j', strtotime($r['expiration_date'])), $rows)) . '.', 'show' => 'compliance.php', 'label' => 'Go to compliance'];
        }
    }
    return null;
}

/** The session a cancel or no-show request means: the next one ahead (cancel), or the latest one already started today (no-show). */
function voice_session_for(int $clientId, int $uid, string $create): ?array {
    // Group sessions are changed on the schedule, for the whole group, never by voice.
    $base = "SELECT s.id, s.start_at, s.user_id, u.name AS instructor FROM scheduled_sessions s LEFT JOIN users u ON u.id = s.user_id WHERE s.client_id = ? AND s.status = 'Scheduled' AND s.group_uid IS NULL";
    $sql = $create === 'no_show'
        ? $base . ' AND s.start_at <= NOW() AND s.start_at >= DATE_SUB(NOW(), INTERVAL 2 DAY) ORDER BY s.user_id = ? DESC, s.start_at DESC LIMIT 1'
        : $base . ' AND s.start_at > NOW() ORDER BY s.user_id = ? DESC, s.start_at LIMIT 1';
    $q = db()->prepare($sql);
    $q->execute([$clientId, $uid]);
    return $q->fetch() ?: null;
}
