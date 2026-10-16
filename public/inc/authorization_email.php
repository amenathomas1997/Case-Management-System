<?php
declare(strict_types=1);
// Authorization emails read into new tracks (3.0.48). VR agencies send a plain-text
// "Authorization … has been Approved" notice (with a link staff cannot open), and the
// counselor forwards it. The details are read from its "Label: value" lines, nothing is
// sent to AI, and staff confirm the client and every field on the track form before the
// track is saved.

/** Agencies known by the sending address's domain, when no existing track names one. */
const AUTH_EMAIL_AGENCIES = ['blind.state.ia.us' => 'Iowa Department for the Blind'];

/**
 * The details of an authorization email, or null when it is not one (no authorization
 * number). Keys: auth_number, client_name (as "First Middle Last"), client_name_raw,
 * service_category, amount, hours, approved_on, start_date, end_date, counselor_name,
 * counselor_email, from_email, agency, not_approved (true when it says denied, cancelled …).
 */
function authorization_email_parse(string $text): ?array {
    $text = str_replace(["\r\n", "\r", "\u{00A0}"], ["\n", "\n", ' '], $text);
    $field = function (string $labels) use ($text): string {
        return preg_match('/^[ \t>*]*(?:' . $labels . ')[ \t]*[:#][ \t]*(\S[^\n]*)$/mi', $text, $m) ? trim($m[1]) : '';
    };
    $auth = $field('Authori[sz]ation\s*(?:Number|No\.?|#)|Auth\.?\s*(?:Number|No\.?|#)');
    if ($auth === '' && preg_match('/Authori[sz]ation\s+([A-Z0-9][A-Z0-9-]{3,})\s+has\s+been/i', $text, $m)) $auth = $m[1];
    $auth = preg_match('/^[\s#]*([^\s,;]+)/', $auth, $am) ? mb_substr(rtrim($am[1], '.'), 0, 80) : '';
    if ($auth === '' || !preg_match('/\d/', $auth)) return null;

    $raw = mb_substr($field('Client\s*Name|Consumer\s*Name|Participant\s*Name|Client'), 0, 190);
    $name = $raw;
    if (str_contains($raw, ',')) { [$last, $rest] = array_map('trim', explode(',', $raw, 2)); $name = trim($rest . ' ' . $last); }
    $money = function (string $s): ?float { $s = str_replace(['$', ',', ' '], '', $s); return is_numeric($s) ? round((float)$s, 2) : null; };
    $date = function (string $s): ?string {
        $s = trim(preg_replace('/^(?:Mon|Tues?|Wed(?:nes)?|Thu(?:rs)?|Fri|Sat(?:ur)?|Sun)[a-z]*,?\s*/i', '', $s) ?? '');
        if ($s === '') return null;
        $d = date_parse($s);
        if ($d['error_count'] > 0 || !$d['year'] || !$d['month'] || !$d['day'] || !checkdate($d['month'], $d['day'], $d['year'])) return null;
        return sprintf('%04d-%02d-%02d', $d['year'], $d['month'], $d['day']);
    };
    // The headers of the original message (the last From: block before the details, so a
    // notice forwarded twice still gives the agency's sender and the counselor it went to).
    $detailAt = preg_match('/Authori[sz]ation\s*(?:Number|No\.?|#)\s*[:#]/i', $text, $dm, PREG_OFFSET_CAPTURE) ? $dm[0][1] : strlen($text);
    $head = substr($text, 0, $detailAt);
    $blockAt = preg_match_all('/^[ \t>*]*From[ \t]*:/mi', $head, $fm, PREG_OFFSET_CAPTURE) ? end($fm[0])[1] : 0;
    $block = substr($head, $blockAt);
    $hdr = fn(string $label): string => preg_match('/^[ \t>*]*' . $label . '[ \t]*:[ \t]*(\S[^\n]*)$/mi', $block, $m) ? trim($m[1]) : '';
    $to = $hdr('To'); $from = $hdr('From');
    $email = fn(string $s): string => preg_match('/[A-Z0-9._%+\'-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', $s, $m) ? strtolower($m[0]) : '';
    // The counselor is the first addressee who is not staff (the person who forwarded it) or
    // the CMS mailbox. Several addressees are separated by semicolons.
    $counselorEmail = ''; $counselorName = '';
    $mailbox = strtolower((string)(function_exists('graph_cfg') ? (graph_cfg()['sender'] ?? '') : ''));
    $st = db()->prepare('SELECT COUNT(*) FROM users WHERE LOWER(email) = ?');
    foreach (preg_split('/;/', $to) ?: [] as $one) {
        $addr = $email($one);
        if ($addr === '' || $addr === $mailbox) continue;
        $st->execute([$addr]);
        if ((int)$st->fetchColumn() > 0) continue;
        $counselorEmail = $addr;
        $counselorName = trim((string)preg_replace('/<[^>]*>|\[[^\]]*\]|mailto:\S+|"/', '', $one));
        if (str_contains($counselorName, '@')) $counselorName = '';
        // "Last, First" as Outlook writes it.
        if (substr_count($counselorName, ',') === 1) { [$cl, $cf] = array_map('trim', explode(',', $counselorName)); $counselorName = trim($cf . ' ' . $cl); }
        break;
    }
    $fromEmail = $email($from);
    $hours = $field('Authori[sz]ed\s*Hours|Hours|Units');
    return [
        'auth_number' => $auth,
        'client_name' => mb_substr($name, 0, 190),
        'client_name_raw' => $raw,
        'service_category' => mb_substr($field('Service\s*Category|Service|Service\s*Type'), 0, 190),
        'amount' => $money($field('Authori[sz]ation\s*Amount') ?: $field('Authori[sz]ed\s*Amount') ?: $field('Total\s*Amount') ?: $field('Amount')),
        'hours' => $money($hours),
        'approved_on' => $date($hdr('Sent') ?: $hdr('Date')),
        'start_date' => $date($field('Start\s*Date|Begin\s*Date|Service\s*Start(?:\s*Date)?|From\s*Date')),
        'end_date' => $date($field('End\s*Date|Expiration\s*Date|Service\s*End(?:\s*Date)?|To\s*Date|Through')),
        'counselor_name' => mb_substr($counselorName, 0, 120),
        'counselor_email' => mb_substr($counselorEmail, 0, 190),
        'from_email' => $fromEmail,
        'agency' => authorization_email_agency($counselorEmail !== '' ? $counselorEmail : $fromEmail),
        'approved' => preg_match('/has\s+been\s+approved/i', $text) === 1,
        'not_approved' => preg_match('/has been\s+(denied|cancell?ed|rejected|closed|voided)/i', $text) === 1,
    ];
}

/** The funding agency for an address: the one most used on tracks of clients whose counselor shares its domain, else a known agency. */
function authorization_email_agency(string $addr): string {
    $domain = strtolower(substr((string)strrchr($addr, '@'), 1));
    if ($domain === '') return '';
    $q = db()->prepare("SELECT t.funding_agency FROM training_tracks t JOIN clients c ON c.id = t.client_id
        WHERE LOWER(c.counselor_email) LIKE ? AND t.funding_agency NOT IN ('Private pay', 'No charge') GROUP BY t.funding_agency ORDER BY COUNT(*) DESC LIMIT 1");
    $q->execute(['%@' . $domain]);
    $a = $q->fetchColumn();
    // An agency listed with this domain (Funding agencies, 4.1.0), then the built-in one.
    return $a !== false ? (string)$a : (agency_for_domain($domain) ?? AUTH_EMAIL_AGENCIES[$domain] ?? '');
}

/** Clients whose first and last names match the name on the email (a middle name or initial is ignored). */
function authorization_email_clients(string $name, string $counselorEmail = ''): array {
    $tok = fn(string $s): array => array_values(array_filter(preg_split('/[^\p{L}\'-]+/u', mb_strtolower($s)) ?: [], fn($w) => mb_strlen($w) > 1));
    $want = $tok($name);
    if (count($want) < 2) return [];
    $first = $want[0]; $last = end($want);
    $q = db()->prepare('SELECT c.id, c.name, c.status, c.dob, c.archived_at, c.assigned_to, c.counselor_name, c.counselor_email, u.name AS instructor
        FROM clients c LEFT JOIN users u ON u.id = c.assigned_to WHERE LOWER(c.name) LIKE ? ORDER BY c.archived_at IS NOT NULL, c.name');
    $q->execute(['%' . $last . '%']);
    $out = [];
    foreach ($q->fetchAll() as $c) {
        $have = $tok((string)$c['name']);
        if ($have && $have[0] === $first && end($have) === $last) {
            $c['same_counselor'] = $counselorEmail !== '' && strcasecmp((string)$c['counselor_email'], $counselorEmail) === 0;
            $out[] = $c;
        }
    }
    usort($out, fn($a, $b) => (int)$b['same_counselor'] <=> (int)$a['same_counselor']);
    return $out;
}

/** Tracks that already carry this authorization number. */
function authorization_email_tracks(string $authNumber): array {
    $q = db()->prepare('SELECT t.id, t.title, t.client_id, t.authorized_amount, c.name AS client_name, c.assigned_to FROM training_tracks t JOIN clients c ON c.id = t.client_id WHERE t.auth_number = ?');
    $q->execute([$authNumber]);
    return $q->fetchAll();
}

/**
 * Keep an authorization email for staff to act on. A mailbox message is kept once
 * ($messageKey); a pasted one that repeats a waiting row reuses it. The client is matched
 * when exactly one record fits. Returns the row id.
 */
function authorization_email_store(array $p, string $text, string $source, ?string $messageKey, ?int $actor): int {
    if ($source === 'Pasted') {
        $q = db()->prepare("SELECT id FROM incoming_authorizations WHERE auth_number = ? AND status = 'New' ORDER BY id DESC LIMIT 1");
        $q->execute([$p['auth_number']]);
        if ($id = $q->fetchColumn()) return (int)$id;
    }
    $matches = array_values(array_filter(authorization_email_clients($p['client_name'], $p['counselor_email']), fn($c) => empty($c['archived_at'])));
    $clientId = count($matches) === 1 ? (int)$matches[0]['id'] : null;
    db()->prepare('INSERT INTO incoming_authorizations (auth_number, client_name, service_category, amount, hours, approved_on, start_date, end_date, counselor_name, counselor_email,
            agency, email_text, source, message_key, client_id, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)')
        ->execute([$p['auth_number'], $p['client_name'] ?: null, $p['service_category'] ?: null, $p['amount'], $p['hours'], $p['approved_on'], $p['start_date'], $p['end_date'],
            $p['counselor_name'] ?: null, $p['counselor_email'] ?: null, $p['agency'] ?: null, mb_substr($text, 0, 100000), $source, $messageKey, $clientId, $actor]);
    return (int)db()->lastInsertId();
}

/** One waiting authorization email. */
function incoming_authorization(int $id): ?array {
    $q = db()->prepare('SELECT * FROM incoming_authorizations WHERE id = ?');
    $q->execute([$id]);
    return $q->fetch() ?: null;
}

/**
 * The new-track form's starting values for an authorization email and a client: the
 * service category as title and RSA category, the number, agency and amount, the start
 * date (the approval date when the email gives none), and hours from the amount at the
 * client's last VR hourly rate when the email gives no hours.
 */
function authorization_track_prefill(array $a, int $clientId): array {
    $last = db()->prepare("SELECT hourly_rate, mileage_rate, funding_agency FROM training_tracks WHERE client_id = ? AND billing_mode = 'vr' ORDER BY id DESC LIMIT 1");
    $last->execute([$clientId]);
    $lt = $last->fetch() ?: [];
    $rate = (float)($lt['hourly_rate'] ?? 0);
    $hours = $a['hours'] !== null ? (float)$a['hours'] : ($a['amount'] !== null && $rate > 0 ? floor((float)$a['amount'] / $rate * 4) / 4 : null);
    $cat = (string)($a['service_category'] ?? '');
    $pre = [
        'title' => $cat !== '' ? $cat : 'Authorization ' . $a['auth_number'],
        'billing_mode' => 'vr',
        'auth_number' => (string)$a['auth_number'],
        'funding_agency' => (string)($a['agency'] ?: ($lt['funding_agency'] ?? '')),
        'authorized_amount' => $a['amount'] !== null ? number_format((float)$a['amount'], 2, '.', '') : '',
        'authorized_hours' => $hours !== null ? (string)$hours : '',
        'hourly_rate' => $rate > 0 ? number_format($rate, 2, '.', '') : '',
        'start_date' => (string)($a['start_date'] ?? $a['approved_on'] ?? ''),
        'expiration_date' => (string)($a['end_date'] ?? ''),
        'incoming_auth' => (string)(int)$a['id'],
    ];
    if ($lt && (float)($lt['mileage_rate'] ?? 0) > 0) $pre['mileage_rate'] = (string)(float)$lt['mileage_rate'];
    if (in_array($cat, rsa_all_categories(), true)) $pre['rsa_category'] = $cat;
    return $pre;
}

/** Mark an authorization email handled (a track was created, or it was dismissed) and close its task. */
function incoming_authorization_handled(int $id, string $status, ?int $trackId, int $actor, ?int $clientId = null): bool {
    $u = db()->prepare("UPDATE incoming_authorizations SET status = ?, track_id = ?, handled_by = ?, handled_at = NOW() WHERE id = ? AND status = 'New'" . ($clientId !== null ? ' AND client_id = ?' : ''));
    $u->execute(array_merge([$status, $trackId, $actor, $id], $clientId !== null ? [$clientId] : []));
    if ($u->rowCount() !== 1) return false;
    complete_task_by_key('incauth-' . $id, $actor);
    audit($actor, 'authorization.email', 'incoming_authorization', $id, $status . ($trackId ? ' (track #' . $trackId . ')' : ''));
    return true;
}
