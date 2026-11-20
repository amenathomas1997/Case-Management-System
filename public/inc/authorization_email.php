<?php
declare(strict_types=1);
// Authorization emails read into new tracks (3.0.48). VR agencies send a plain-text
// "Authorization … has been Approved" notice (with a link staff cannot open), and the
// counselor forwards it. The details are read from its "Label: value" lines, nothing is
// sent to AI, and staff confirm the client and every field on the track form before the
// track is saved.

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
    // Else an agency listed with this domain on Funding agencies (4.1.0; no built-in list since 4.3.0).
    return $a !== false ? (string)$a : (agency_for_domain($domain) ?? '');
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
 * when exactly one record fits. $sender (4.27.0) is the address Microsoft 365 authenticated
 * for a mailbox message: a counselor address read from the email's text counts as
 * confirmed only when it is that sender (authorization_counselor_confirmed()). Returns the row id.
 */
function authorization_email_store(array $p, string $text, string $source, ?string $messageKey, ?int $actor, ?string $sender = null): int {
    if ($source === 'Pasted') {
        $q = db()->prepare("SELECT id FROM incoming_authorizations WHERE auth_number = ? AND status = 'New' ORDER BY id DESC LIMIT 1");
        $q->execute([$p['auth_number']]);
        if ($id = $q->fetchColumn()) return (int)$id;
    }
    $matches = array_values(array_filter(authorization_email_clients($p['client_name'], $p['counselor_email']), fn($c) => empty($c['archived_at'])));
    $clientId = count($matches) === 1 ? (int)$matches[0]['id'] : null;
    $vals = [$p['auth_number'], $p['client_name'] ?: null, $p['service_category'] ?: null, $p['amount'], $p['hours'], $p['approved_on'], $p['start_date'], $p['end_date'],
        $p['counselor_name'] ?: null, $p['counselor_email'] ?: null, $p['agency'] ?: null, mb_substr($text, 0, 100000), $source, $messageKey, $clientId, $actor];
    $sender = $source === 'Mailbox' && $sender !== null && filter_var(trim($sender), FILTER_VALIDATE_EMAIL) ? mb_substr(strtolower(trim($sender)), 0, 190) : null;
    try {
        db()->prepare('INSERT INTO incoming_authorizations (auth_number, client_name, service_category, amount, hours, approved_on, start_date, end_date, counselor_name, counselor_email,
                agency, email_text, source, message_key, client_id, created_by, sender_email) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)')
            ->execute(array_merge($vals, [$sender]));
    } catch (PDOException $e) {
        if ((string)$e->getCode() !== '42S22') throw $e; // before migration 4.27.0 (no sender_email): never confirmed
        db()->prepare('INSERT INTO incoming_authorizations (auth_number, client_name, service_category, amount, hours, approved_on, start_date, end_date, counselor_name, counselor_email,
                agency, email_text, source, message_key, client_id, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)')
            ->execute($vals);
    }
    return (int)db()->lastInsertId();
}

/** Whether a waiting email's counselor address is confirmed (4.27.0): only when Microsoft 365
 *  authenticated it as the sender (the counselor wrote to the mailbox). An address read from
 *  the text of a pasted or forwarded email is anyone's to type. */
function authorization_counselor_confirmed(array $a): bool {
    $c = strtolower(trim((string)($a['counselor_email'] ?? '')));
    $s = strtolower(trim((string)($a['sender_email'] ?? '')));
    return ($a['source'] ?? '') === 'Mailbox' && $c !== '' && $s !== '' && $c === $s;
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

/* ---- Incoming authorizations page (4.38.0): moved from authorizations.php, so the page and the
   apps' incoming_authorizations, incoming_authorization, read_authorization_email,
   start_authorization_track and dismiss_authorization share one copy. ---- */

/** The email, if this user may see it: a caseload-scoped user sees an email matched to their own
 *  client, and an unmatched one only when they pasted it. */
function incoming_authorization_for(int $id, array $user): ?array {
    $a = incoming_authorization($id);
    if (!$a) return null;
    if (caseload_scoped()) {
        // An email not yet matched is the scoped user's own to place when they pasted it.
        if (!$a['client_id']) return (int)$a['created_by'] === (int)$user['id'] ? $a : null;
        $c = db()->prepare('SELECT assigned_to FROM clients WHERE id = ?');
        $c->execute([(int)$a['client_id']]);
        if ((int)$c->fetchColumn() !== (int)$user['id']) return null;
    }
    return $a;
}

/** The waiting (New) emails this user may see, newest first, 200 at most; each with matched_name. */
function incoming_authorizations_waiting(array $user): array {
    $scoped = caseload_scoped();
    $wq = db()->prepare("SELECT a.*, c.name AS matched_name FROM incoming_authorizations a LEFT JOIN clients c ON c.id = a.client_id WHERE a.status = 'New'"
        . ($scoped ? ' AND (c.assigned_to = ? OR (a.client_id IS NULL AND a.created_by = ?))' : '') . ' ORDER BY a.created_at DESC LIMIT 200');
    $wq->execute($scoped ? [(int)$user['id'], (int)$user['id']] : []);
    return $wq->fetchAll();
}

/** A waiting row after its number: "{name or 'no client name'}[ (not matched to a client yet)][, $amount], received {date}". */
function incoming_authorization_row_rest(array $w): string {
    return (string)($w['matched_name'] ?? $w['client_name'] ?? 'no client name') . ($w['matched_name'] ? '' : ' (not matched to a client yet)')
        . ($w['amount'] !== null ? ', ' . fmt_money((float)$w['amount']) : '') . ', received ' . fmt_date($w['created_at']);
}

/** A waiting row as the page lists it: "{auth_number}: …". */
function incoming_authorization_row_text(array $w): string {
    return $w['auth_number'] . ': ' . incoming_authorization_row_rest($w);
}

/**
 * What the review shows for an email: its status sentence (once handled), whether it says it was not
 * approved, the tracks already holding its number (in scope), its details, the client choices
 * (matches, then "another client"), the client preset, the other clients and the counselor offer.
 * The *_rows keys hold the rows the page links from.
 */
function incoming_authorization_review(array $a, array $user): array {
    $scoped = caseload_scoped();
    $uid = (int)$user['id'];
    $p = authorization_email_parse((string)$a['email_text']) ?? ['not_approved' => false];
    $matches = authorization_email_clients((string)$a['client_name'], (string)$a['counselor_email']);
    $already = array_values(array_filter(authorization_email_tracks((string)$a['auth_number']), fn($t) => !$scoped || (int)$t['assigned_to'] === $uid));
    $matches = array_values(array_filter($matches, fn($m) => empty($m['archived_at']) && (!$scoped || (int)$m['assigned_to'] === $uid)));
    $preset = $a['client_id'] ? (int)$a['client_id'] : (count($matches) === 1 ? (int)$matches[0]['id'] : 0);
    $yn = fn($v) => $v === null || $v === '' ? 'not given' : (string)$v;
    $alreadyAmounts = fn(array $t): string => $t['authorized_amount'] !== null && $a['amount'] !== null && (float)$t['authorized_amount'] !== (float)$a['amount']
        ? ' (authorized ' . fmt_money((float)$t['authorized_amount']) . ' there, ' . fmt_money((float)$a['amount']) . ' in this email: if it changed, record an amendment on the track)' : '';
    $details = [
        ['Client name in the email', $yn($a['client_name'])],
        ['Service category', $yn($a['service_category'])],
        ['Amount', $a['amount'] !== null ? fmt_money((float)$a['amount']) : 'not given'],
        ['Hours', $a['hours'] !== null ? fmt_hours((float)$a['hours']) : 'not given (worked out from the amount at the client\'s last hourly rate, if there is one)'],
        ['Approved', $a['approved_on'] ? fmt_date($a['approved_on']) : 'not given'],
        ['Dates', $a['start_date'] || $a['end_date'] ? ($a['start_date'] ? fmt_date($a['start_date']) : 'no start') . ' to ' . ($a['end_date'] ? fmt_date($a['end_date']) : 'no end date') : 'not given (the approval date is used as the start)'],
        ['Counselor', trim(($a['counselor_name'] ?? '') . ($a['counselor_email'] ? ', ' . $a['counselor_email'] : '')) ?: 'not given'],
        ['Funding agency', $yn($a['agency'])],
        ['Received', fmt_date($a['created_at']) . ', ' . ($a['source'] === 'Mailbox' ? 'forwarded to the CMS mailbox' : 'pasted')],
    ];
    $matchText = fn(array $m): string => $m['name'] . ' (' . $m['status'] . ($m['dob'] ? ', born ' . fmt_date($m['dob']) : '') . ($m['instructor'] ? ', ' . $m['instructor'] . '\'s caseload' : '')
        . ($m['same_counselor'] ? ', same counselor' : '') . ')';
    $oq = db()->prepare('SELECT id, name FROM clients WHERE archived_at IS NULL' . ($scoped ? ' AND assigned_to = ?' : '') . ' ORDER BY name LIMIT 2000');
    $oq->execute($scoped ? [$uid] : []);
    $counselor = null;
    if ($a['counselor_email']) {
        // 4.27.0: pre-ticked only when the counselor sent it themselves and the preset client has no counselor email.
        $confirmed = authorization_counselor_confirmed($a);
        $pm = array_values(array_filter($matches, fn($m) => (int)$m['id'] === $preset));
        $counselor = ['text' => trim(($a['counselor_name'] ?: '') . ' ' . $a['counselor_email']), 'confirmed' => $confirmed,
            'preticked' => $confirmed && $preset && $pm && trim((string)$pm[0]['counselor_email']) === ''];
    }
    return [
        'status_text' => $a['status'] !== 'New' ? $a['status'] . ($a['track_id'] ? ': open the track.' : '.') : null,
        'not_approved' => !empty($p['not_approved']),
        'already' => array_map(fn($t) => 'Already on a track: ' . $t['title'] . ' for ' . $t['client_name'] . $alreadyAmounts($t) . '.', $already),
        'already_rows' => array_map(fn($t) => $t + ['amounts_text' => $alreadyAmounts($t)], $already),
        'details' => $details,
        'matches' => array_map(fn($m) => [(int)$m['id'], $matchText($m)], $matches),
        'match_rows' => $matches,
        'preset_client_id' => $preset,
        'other_clients' => array_map(fn($o) => [(int)$o['id'], (string)$o['name']], $oq->fetchAll()),
        'counselor' => $counselor,
    ];
}

/** "Read the email": a pasted email stored as New. errors on email_text, or the id (null with the
 *  page's sentence when its client is not on a scoped user's caseload). */
function incoming_authorization_paste(string $text, array $user, string $auditTail = ''): array {
    $text = trim($text);
    $p = $text !== '' ? authorization_email_parse($text) : null;
    if (!$p) return ['ok' => false, 'errors' => ['email_text' => $text === '' ? 'Paste the authorization email first.' : 'No authorization number was found in that text. Paste the whole email, including the line "Authorization Number:".']];
    $id = authorization_email_store($p, $text, 'Pasted', null, (int)$user['id']);
    if (!incoming_authorization_for($id, $user)) {
        return ['ok' => true, 'id' => null, 'message' => 'Authorization ' . $p['auth_number'] . ' was read, but its client is not on your caseload. An administrator can place it.'];
    }
    audit((int)$user['id'], 'authorization.email', 'incoming_authorization', $id, 'pasted ' . $p['auth_number'] . $auditTail);
    return ['ok' => true, 'id' => $id, 'message' => null];
}

/** The new-track form's heading for a track started from an email (track_edit.php's). */
function incoming_authorization_heading(string $clientName, string $authNumber): string {
    return 'New training track for ' . $clientName . ', filled in from authorization ' . $authNumber . ': check each field before saving';
}

/**
 * "Start the track from this authorization": the client chosen (not archived, in scope) is recorded on
 * the email; the counselor is recorded when asked (never replacing a different one; confirmed only
 * as authorization_counselor_confirmed() says); then the track form's prefill. $a must be New.
 * notices are the page's information sentences, in order.
 */
function incoming_authorization_start(array $a, int $clientId, bool $setCounselor, array $user, string $auditTail = ''): array {
    $uid = (int)$user['id'];
    $c = db()->prepare('SELECT id, name, assigned_to, counselor_name, counselor_email FROM clients WHERE id = ? AND archived_at IS NULL');
    $c->execute([$clientId]);
    $client = $c->fetch();
    if (!$client || (caseload_scoped() && (int)$client['assigned_to'] !== $uid)) return ['ok' => false, 'errors' => ['client_id' => 'Choose the client this authorization is for.']];
    db()->prepare('UPDATE incoming_authorizations SET client_id = ? WHERE id = ?')->execute([$clientId, (int)$a['id']]);
    $counselorKept = $setCounselor && trim((string)$client['counselor_email']) !== '' && strcasecmp(trim((string)$client['counselor_email']), (string)$a['counselor_email']) !== 0;
    $unconfirmed = false;
    if ($setCounselor && $a['counselor_email'] && !$counselorKept) {
        // 4.27.0: confirmed only when the counselor sent it to the mailbox themselves; an address read
        // from the email's text is recorded unconfirmed (an already-confirmed same address stays confirmed).
        $confirmed = authorization_counselor_confirmed($a) ? 1 : 0;
        db()->prepare('UPDATE clients SET counselor_verified = IF(LOWER(COALESCE(counselor_email, \'\')) = LOWER(?), GREATEST(counselor_verified, ?), ?), counselor_name = ?, counselor_email = ? WHERE id = ?')
            ->execute([$a['counselor_email'], $confirmed, $confirmed, $a['counselor_name'] ?: $client['counselor_name'], $a['counselor_email'], $clientId]);
        audit($uid, 'client.counselor', 'client', $clientId, 'from authorization ' . $a['auth_number'] . ': ' . ($client['counselor_email'] ?: 'none') . ' -> ' . $a['counselor_email'] . ($confirmed ? '' : ' (unconfirmed)') . $auditTail);
        $unconfirmed = !$confirmed && strcasecmp(trim((string)$client['counselor_email']), (string)$a['counselor_email']) !== 0;
    }
    return ['ok' => true, 'client' => $client, 'notices' => incoming_authorization_start_notices($a, $client, $counselorKept, $unconfirmed),
        'counselor_kept' => $counselorKept, 'counselor_unconfirmed' => $unconfirmed, 'prefill' => authorization_track_prefill($a, $clientId),
        'heading' => incoming_authorization_heading((string)$client['name'], (string)$a['auth_number'])];
}

/** The page's information sentences after a start, in order: the client's different counselor was
 *  kept ($client as it was before the start), or the email's counselor was recorded unconfirmed. */
function incoming_authorization_start_notices(array $a, array $client, bool $kept, bool $unconfirmed): array {
    $out = [];
    if ($kept) $out[] = $client['name'] . ' already has a different VR counselor (' . $client['counselor_email'] . '), so it was not changed. Change it on the client\'s edit page if the counselor has changed.';
    if ($unconfirmed) $out[] = $a['counselor_email'] . ' is recorded as ' . $client['name'] . '\'s VR counselor, unconfirmed: no report goes to it until it is confirmed on the client\'s edit page.';
    return $out;
}

/** "Dismiss": the email is handled without a track. $a must be New. */
function incoming_authorization_dismiss(array $a, array $user): array {
    $ok = incoming_authorization_handled((int)$a['id'], 'Dismissed', null, (int)$user['id']);
    return $ok ? ['ok' => true, 'message' => 'Authorization ' . $a['auth_number'] . ' dismissed.'] : ['ok' => false, 'message' => 'That authorization email was already handled.'];
}
