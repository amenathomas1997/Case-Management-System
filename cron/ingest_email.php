<?php
declare(strict_types=1);
// Email-to-client-file: forward any email to this installation's own Graph sender
// mailbox (the 'sender' in the 'graph' block of the private config.php) with the
// client's full name in the subject, and it becomes a note on their file.
// A client, guardian or counselor who replies without a name in the subject is
// filed by address only when that address belongs to exactly one client AND the
// message body names that client. Mail from a known client contact (an archived
// client's included) that cannot be filed moves to the "Needs review (CMS)"
// folder and the administrators are told once. Automatic replies (out-of-office,
// bounces) are never filed. Only senders whose address Microsoft 365
// authenticated are trusted (see sender_verified()). Filed messages move to the
// "Filed to CMS" folder.
// Cron: */15 * * * *
if (PHP_SAPI !== 'cli') exit('CLI only.');

// Where the application's inc/ directory is: an optional cron_config.php beside
// this script (it returns the path) wins, then the production path, then the
// repository layout. Nothing needs editing here for a different install.
foreach ([
    is_file(__DIR__ . '/cron_config.php') ? (string)(require __DIR__ . '/cron_config.php') : '',
    dirname(__DIR__, 2) . '/public_html/casemanagement/inc',
    dirname(__DIR__) . '/public/inc',
] as $inc) {
    $inc = rtrim($inc, '/');
    if ($inc !== '' && is_file($inc . '/core.php')) { require $inc . '/core.php'; break; }
}
if (!function_exists('db')) {
    fwrite(STDERR, "CMS cron: the application's inc/core.php was not found. Create cron_config.php beside this script containing: <?php return '/full/path/to/your/web/docroot/inc';\n");
    exit(1);
}

$g = graph_cfg();
if (!$g) { echo "Graph not configured.\n"; exit(1); }
$sender = '/users/' . rawurlencode($g['sender']);

// Mailbox folders by name; a missing one is created when first needed.
[$fc, $folders] = graph_api('GET', $sender . '/mailFolders?$top=100&$select=id,displayName');
$folderIds = [];
foreach (($folders['value'] ?? []) as $f) $folderIds[(string)($f['displayName'] ?? '')] ??= $f['id'] ?? null;
$folderId = function (string $name) use (&$folderIds, $sender): ?string {
    if (empty($folderIds[$name])) {
        [, $created] = graph_api('POST', $sender . '/mailFolders', ['displayName' => $name]);
        $folderIds[$name] = $created['id'] ?? null;
    }
    return $folderIds[$name] ?: null;
};
$filedId = $folderId('Filed to CMS');
if (!$filedId) { echo "Could not create the \"Filed to CMS\" folder.\n"; exit(1); }
$reviewFolder = 'Needs review (CMS)';

$clients = db()->query('SELECT id, name, email, guardian_email, counselor_email, counselor_verified, assigned_to, archived_at FROM clients')->fetchAll();

// Internal staff may file a note onto ANY client. A client / guardian /
// counselor email may file ONLY onto that client's own record. Without this
// per-client association check, any known sender (staff, client, counselor, or
// guardian) could put a different client's name in the subject and forge a note
// on an unrelated client's file.
$staffSenders = []; $activeUserIds = [];
foreach (db()->query('SELECT id, email FROM users WHERE is_active = 1') as $r) {
    $staffSenders[strtolower(trim((string)$r['email']))] = true;
    $activeUserIds[(int)$r['id']] = true;
}
// Which current (not archived) clients each client / guardian / counselor
// address belongs to. Addresses of archived clients are kept apart: their mail
// is never filed automatically, but it is still a known contact writing in, so
// it goes to the review folder instead of being left unnoticed in the inbox. A
// counselor address staff have not confirmed yet (it came from the public
// referral form, where anyone can type any address) is treated the same way:
// it never files onto the client's record by itself.
$clientsByAddress = []; $archivedAddresses = []; $counselorAddresses = [];
foreach ($clients as $cl) {
    foreach (['email', 'guardian_email', 'counselor_email'] as $col) {
        $addr = strtolower(trim((string)($cl[$col] ?? '')));
        if ($addr === '') continue;
        if ($col === 'counselor_email' && !empty($cl['counselor_verified'])) $counselorAddresses[$addr] = true;
        if (!empty($cl['archived_at']) || ($col === 'counselor_email' && empty($cl['counselor_verified']))) $archivedAddresses[$addr] = true;
        else $clientsByAddress[$addr][(int)$cl['id']] = $cl;
    }
}

// Ask Graph for the plain-text body (html_body_text() covers a server that
// still answers with HTML), plus the headers sender_verified() needs. Newest
// first, a few pages deep, so mail that cannot be filed and stays in the inbox
// cannot crowd a new reply out of the window.
$msgs = [];
$path = $sender . '/mailFolders/inbox/messages?$top=25&$orderby=receivedDateTime%20desc&$select=id,subject,from,receivedDateTime,bodyPreview,body,internetMessageHeaders,hasAttachments';
for ($page = 0; $page < 4 && $path !== ''; $page++) {
    [$mc, $list] = graph_api('GET', $path, null, ['Prefer: outlook.body-content-type="text"']);
    if ($mc < 200 || $mc >= 300 || !is_array($list)) {
        if (!$msgs) { echo "Could not read the inbox (HTTP {$mc}).\n"; exit(1); }
        break;
    }
    foreach ((array)($list['value'] ?? []) as $m) $msgs[] = $m;
    $next = (string)($list['@odata.nextLink'] ?? '');
    $path = str_starts_with($next, 'https://graph.microsoft.com/v1.0/') ? substr($next, strlen('https://graph.microsoft.com/v1.0')) : '';
}
$filed = 0; $skipped = 0; $unverified = 0; $failed = 0; $review = 0; $auto = 0; $authorizations = 0;
// client_notes.body is TEXT (65,535 bytes); the header lines take the rest.
$maxBodyBytes = 60000;
foreach ($msgs as $m) {
    try {
        $subject = (string)($m['subject'] ?? '');
        $from = $m['from']['emailAddress'] ?? [];
        $fromAddr = strtolower(trim($from['address'] ?? ''));
        if ($fromAddr === '') { $skipped++; continue; }
        $headers = (array)($m['internetMessageHeaders'] ?? []);
        // Out-of-office replies and bounces to the system's own mail are not
        // case notes; they stay in the inbox.
        if (auto_reply($headers)) { $auto++; continue; }
        $isStaff = !empty($staffSenders[$fromAddr]);
        $owners = $isStaff ? [] : ($clientsByAddress[$fromAddr] ?? []);

        $content = (string)($m['body']['content'] ?? $m['bodyPreview'] ?? '');
        $bodyText = strtolower((string)($m['body']['contentType'] ?? 'text')) === 'html' ? html_body_text($content) : $content;
        $bodyText = str_replace(["\r\n", "\r", "\u{00A0}"], ["\n", "\n", ' '], $bodyText);
        $bodyText = trim(preg_replace("/\n{3,}/", "\n\n", preg_replace('/[ \t]+\n/', "\n", $bodyText)));

        // An approved-authorization email (3.0.48), forwarded by staff or sent by a client's
        // confirmed counselor: kept on Incoming authorizations for staff to start the track,
        // with a task for the matched client's instructor (or the administrators).
        if (($isStaff || isset($counselorAddresses[$fromAddr])) && !empty($m['id'])
            && ($auth = authorization_email_parse($subject . "\n" . $bodyText)) !== null
            // Only an approval notice, and a number not already on a track: a reply quoting a
            // progress report (which shows "Authorization #:") is filed as a note as before.
            && !empty($auth['approved']) && !authorization_email_tracks($auth['auth_number'])) {
            // Sent straight to the mailbox by the counselor: they are the counselor.
            if ($auth['counselor_email'] === '' && isset($counselorAddresses[$fromAddr])) $auth['counselor_email'] = $fromAddr;
            if (!sender_verified($headers, $fromAddr)) { $unverified++; continue; }
            $authKey = hash('sha256', 'auth:' . $g['sender'] . ':' . $m['id']);
            $seen = db()->prepare('SELECT id FROM incoming_authorizations WHERE message_key = ?');
            $seen->execute([$authKey]);
            if (!$seen->fetchColumn()) {
                $incId = authorization_email_store($auth, $subject . "\n\n" . $bodyText, 'Mailbox', $authKey, null);
                $inc = incoming_authorization($incId);
                $owner = null;
                if ($inc && $inc['client_id']) {
                    $oq = db()->prepare('SELECT assigned_to FROM clients WHERE id = ?');
                    $oq->execute([(int)$inc['client_id']]);
                    $owner = (int)$oq->fetchColumn() ?: null;
                    if ($owner !== null && !isset($activeUserIds[$owner])) $owner = null;
                }
                add_task('New authorization ' . $auth['auth_number'] . ': start the track',
                    'An approved authorization email arrived. Open Incoming authorizations, check the client and details, and start the track.',
                    $inc && $inc['client_id'] ? (int)$inc['client_id'] : null, null, business_days_from(2), $owner, null, 'incauth-' . $incId);
                $note = 'A new authorization email (' . $auth['auth_number'] . ') is waiting on Incoming authorizations.';
                if ($owner !== null) notify_user($owner, $note, 'authorizations.php?id=' . $incId);
                else notify_admin_users($note, 'authorizations.php?id=' . $incId);
            }
            [$moveCode] = graph_api('POST', $sender . '/messages/' . rawurlencode($m['id']) . '/move', ['destinationId' => $filedId]);
            if ($moveCode < 200 || $moveCode >= 300) { $skipped++; continue; }
            $authorizations++;
            continue;
        }

        // Match all names before deciding. The first row is not an identity:
        // different clients can share a name, or a subject can name two people.
        $namedMatches = array_values(array_filter($clients,
            fn($cl) => names_client((string)$cl['name'], $subject)));
        $match = null;
        $assoc = false;
        if ($namedMatches) {
            $candidates = array_values(array_filter($namedMatches,
                fn($cl) => $isStaff || isset($owners[(int)$cl['id']])));
            if (count($candidates) === 1 && empty($candidates[0]['archived_at'])) {
                $match = $candidates[0];
                $assoc = true;
            }
        } else {
            // No subject match: a unique current contact and a matching body are
            // both needed. Never infer a client from a shared counselor address.
            $only = count($owners) === 1 ? reset($owners) : null;
            $match = ($only && names_client((string)$only['name'], $bodyText)) ? $only : null;
            $assoc = $match !== null;
        }
        if (!$match || !$assoc) {
            // Unknown senders (and staff mail with no client name) stay in the inbox.
            if (!$owners && !($isStaff && $namedMatches) && empty($archivedAddresses[$fromAddr])) { $skipped++; continue; }
            // A verified client contact (of a current or an archived client) whose
            // message cannot be placed: move it to the review folder and tell the
            // administrators once. No client name or message content goes into
            // the notification.
            if (!sender_verified($headers, $fromAddr)) { $unverified++; continue; }
            $reviewId = empty($m['id']) ? null : $folderId($reviewFolder);
            if (!$reviewId) { $skipped++; continue; }
            [$moveCode] = graph_api('POST', $sender . '/messages/' . rawurlencode($m['id']) . '/move', ['destinationId' => $reviewId]);
            if ($moveCode < 200 || $moveCode >= 300) { $skipped++; continue; }
            notify_admin_users('An email could not be filed to a client record automatically. It is in the "' . $reviewFolder . '" folder of the CMS mailbox.');
            $review++;
            continue;
        }
        if (!sender_verified($headers, $fromAddr)) { $unverified++; continue; }

        if (strlen($bodyText) > $maxBodyBytes) {
            // Cut on a character boundary, by bytes, and say so in the note.
            $bodyText = mb_strcut($bodyText, 0, $maxBodyBytes, 'UTF-8')
                . "\n\n[This email was too long to store in full. The complete message is in the \"Filed to CMS\" mailbox folder.]";
        }
        // Only the text is filed: the note says when the email carried attachments
        // (an authorization letter, a report), so whoever reads the record knows a
        // document is waiting in the mailbox folder.
        if (!empty($m['hasAttachments'])) {
            $bodyText .= "\n\n[This email had attachments. They were not added to the record; they are in the \"Filed to CMS\" mailbox folder.]";
        }
        // Persist before moving. A stable key makes a failed move safe to retry
        // without creating another case note, while a failed INSERT leaves mail in the inbox.
        if (empty($m['id'])) { $skipped++; continue; }
        $digest = hash('sha256', $g['sender'] . ':' . $m['id']);
        $idem = substr($digest, 0, 8) . '-' . substr($digest, 8, 4) . '-' . substr($digest, 12, 4)
            . '-' . substr($digest, 16, 4) . '-' . substr($digest, 20, 12);
        // Graph gives the time in UTC ("2026-10-09T23:40:00Z"); the note shows it in
        // the application's own time zone, like every other time in the record.
        $received = (string)($m['receivedDateTime'] ?? '');
        try {
            if ($received !== '') $received = (new DateTimeImmutable($received))
                ->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('M j, Y g:i A T');
        } catch (Throwable $e) {
            // Not a date Graph would send: keep the value as given.
        }
        $ins = db()->prepare('INSERT INTO client_notes (client_id, note_type, subject, body, author_id, idem_key) VALUES (?,?,?,?,NULL,?) ON DUPLICATE KEY UPDATE id = id');
        $ins->execute([(int)$match['id'], 'Case Note',
                '[Email] ' . mb_substr($subject, 0, 240),
                'From: ' . mb_substr((string)($from['name'] ?? ''), 0, 200) . ' <' . ($from['address'] ?? '') . ">\nReceived: " . $received . "\n\n" . $bodyText, $idem]);
        // A client, guardian or counselor wrote in: tell the case worker (or, for
        // an unassigned client, the administrators) once, when the note is created.
        if (!$isStaff && $ins->rowCount() === 1) {
            $msg = 'New email from a client contact filed on ' . $match['name'] . "'s record";
            $link = 'client.php?id=' . (int)$match['id'];
            // A deactivated case worker cannot sign in to see it: tell the administrators.
            if (!empty($match['assigned_to']) && isset($activeUserIds[(int)$match['assigned_to']])) notify_user((int)$match['assigned_to'], $msg, $link);
            else notify_admin_users($msg, $link);
        }
        [$moveCode] = graph_api('POST', $sender . '/messages/' . rawurlencode($m['id']) . '/move', ['destinationId' => $filedId]);
        if ($moveCode < 200 || $moveCode >= 300) { $skipped++; continue; }
        $filed++;
    } catch (Throwable $e) {
        // One message that cannot be stored must not stop the rest of the batch;
        // it stays in the inbox for the next run. No message content is logged.
        $failed++;
        error_log('ingest_email: a message could not be filed (' . get_class($e) . ' ' . $e->getCode() . ')');
    }
}
echo "Filed {$filed} email(s) to client records; {$skipped} left in inbox (unknown sender or no client name match)"
    . ($authorizations ? "; {$authorizations} authorization email(s) kept on Incoming authorizations" : '')
    . ($review ? "; {$review} from client contacts moved to \"{$reviewFolder}\" for an administrator" : '')
    . ($auto ? "; {$auto} automatic replies left in inbox" : '')
    . ($unverified ? "; {$unverified} left in inbox because Microsoft 365 could not verify the sender" : '')
    . ($failed ? "; {$failed} could not be stored and will be retried" : '') . ".\n";
// The inbox was read and filed: the heartbeat is recorded even when a message could
// not be stored (it is retried next run, and the exit status reports it).
record_heartbeat('ingest_email');
if ($failed) exit(1);

/**
 * Whether Microsoft 365 vouched for the From address. The From header alone is
 * whatever the sender typed, so it is trusted only when the message was sent
 * from inside the organisation (Exchange's own AuthAs stamp, which it strips
 * from incoming mail) or the receiving server's authentication result — the
 * first Authentication-Results header, the one Exchange added — shows a
 * composite-authentication or DMARC pass for the From domain.
 */
function sender_verified(array $headers, string $fromAddr): bool {
    $first = [];
    foreach ($headers as $h) {
        $name = strtolower(trim((string)($h['name'] ?? '')));
        if ($name !== '' && !isset($first[$name])) $first[$name] = strtolower(trim((string)($h['value'] ?? '')));
    }
    if (($first['x-ms-exchange-organization-authas'] ?? '') === 'internal') return true;
    $results = $first['authentication-results'] ?? '';
    if ($results === '') return false;
    if (preg_match('/(^|[\s;])compauth=pass(?=[\s;]|$)/', $results)) return true;
    $domain = substr((string)strrchr($fromAddr, '@'), 1);
    return $domain !== '' && preg_match('/(^|[\s;])dmarc=pass\b[^;]*\bheader\.from=' . preg_quote($domain, '/') . '(?=[\s;]|$)/', $results) === 1;
}

/** Whether $text names the client: a whole-name, case-insensitive match, with
 * letter boundaries so "Ann Lee" doesn't match "Ann Leeds". */
function names_client(string $name, string $text): bool {
    $name = trim($name);
    return $name !== '' && preg_match('/(?<![\p{L}])' . preg_quote($name, '/') . '(?![\p{L}])/iu', $text) === 1;
}

/** Whether the message is an automatic reply (out-of-office, delivery report,
 * list or bulk mail) by its standard headers (RFC 3834 and common variants). */
function auto_reply(array $headers): bool {
    foreach ($headers as $h) {
        $name = strtolower(trim((string)($h['name'] ?? '')));
        $value = strtolower(trim((string)($h['value'] ?? '')));
        if ($name === 'auto-submitted' && $value !== '' && $value !== 'no') return true;
        if ($name === 'x-autoreply' || $name === 'x-autorespond') return true;
        if ($name === 'precedence' && in_array($value, ['auto_reply', 'bulk', 'junk', 'list'], true)) return true;
    }
    return false;
}

/** Plain text of an HTML body: drop head/style/script blocks, turn line breaks
 * and block boundaries into new lines, strip the tags, and decode entities LAST
 * so escaped text such as "&lt;20/200" or "&lt;jo@example.com&gt;" is kept
 * instead of being mistaken for a tag. */
function html_body_text(string $html): string {
    $html = preg_replace('~<(head|style|script|title)\b[^>]*>.*?</\1\s*>~is', '', $html) ?? '';
    $html = preg_replace('~<(br|hr)\b[^>]*>|</?(p|div|tr|li|h[1-6]|blockquote|table)\b[^>]*>~i', "\n", $html) ?? '';
    $html = preg_replace('~</t[dh]\s*>~i', ' ', $html) ?? '';
    return html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
}
