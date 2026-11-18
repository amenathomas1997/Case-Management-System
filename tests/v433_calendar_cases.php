<?php
// Included by full_schema.php after the v432 file, against its synthetic database only:
// 4.33.0, the three kinds of calendar a staff member's sessions can go to. No calendar service is
// called: meeting_http() hands every request to a stand-in ($GLOBALS['CMS_TEST_HTTP'] in this
// process, CMS_TEST_MEETINGS for pages rendered in another), and every request is recorded, so each
// one is checked against what Microsoft's, Google's and the CalDAV standard's documentation requires.
//  - Microsoft, a personal Outlook.com account: the consumers sign-in address and the calendar-only
//    scope, the account's name from the ID token with no Graph /me, the renewal's fields, a
//    calendarView read across pages with cancelled and free events left out, an event made with a
//    transactionId and deleted, a token refused early renewed once, an expired refresh token in words.
//  - Google, a service account: a JWT the generated public key verifies, freeBusy, an event made and
//    deleted on the shared calendar, a calendar not shared explained (notFound).
//  - CalDAV: discovery through the principal and calendar home leaving tasks and the inbox out, the
//    busy-time REPORT with a repeat expanded, PUT with If-None-Match, DELETE with 404 as gone, a wrong
//    password in words; the My Account form stores the password encrypted and chooses the calendar.

$GLOBALS['CONFIG']['meet'] = ['zoom_client_id' => '', 'zoom_client_secret' => '', 'ms_client_id' => 'synthetic-ms-id', 'ms_client_secret' => 'synthetic-ms-secret', 'ms_tenant' => '', 'ms_accounts' => ''];
$db->exec("INSERT INTO settings(name,value) VALUES ('cfg.meet.ms_client_id','synthetic-ms-id'),('cfg.meet.ms_client_secret','synthetic-ms-secret') ON DUPLICATE KEY UPDATE value=VALUES(value)");
$db->exec("DELETE FROM settings WHERE name IN ('cfg.meet.zoom_client_id','cfg.meet.zoom_client_secret','cfg.meet.ms_accounts')");
$db->exec("INSERT INTO users (name,email,password_hash,role) VALUES ('V433 Synthetic Instructor','v433-instructor@example.invalid','test','instructor')"); $u433 = (int)$db->lastInsertId();
$db->exec("INSERT INTO clients(name,status,assigned_to) VALUES('V433 Synthetic Client','Active Training',$u433)"); $c433 = (int)$db->lastInsertId();
$start433 = strtotime('+5 days 14:00');
$db->exec("INSERT INTO scheduled_sessions(client_id,user_id,start_at,end_at,status) VALUES($c433,$u433,'" . date('Y-m-d H:i:s', $start433) . "','" . date('Y-m-d H:i:s', $start433 + 3600) . "','Scheduled')"); $s433 = (int)$db->lastInsertId();
$db->exec("UPDATE users SET calendar_sync=1 WHERE id=$u433");

// The stand-in: a queue of [regex on "METHOD url", [status, raw body, headers]] answered in order of first match.
$queue433 = [];
$GLOBALS['CMS_TEST_HTTP'] = function (string $method, string $url, array $headers, ?string $body) use (&$queue433): array {
    foreach ($queue433 as $i => [$match, $answer]) {
        if (preg_match($match, $method . ' ' . $url)) { unset($queue433[$i]); return $answer; }
    }
    return [599, 'no stand-in answer for ' . $method . ' ' . $url, []];
};
$json433 = fn(int $status, $data, array $headers = []) => [$status, (string)json_encode($data), $headers];
$sent433 = function (): array { $r = $GLOBALS['CMS_TEST_MEETING_REQUESTS'] ?? []; $GLOBALS['CMS_TEST_MEETING_REQUESTS'] = []; return $r; };
$form433 = function (?string $b): array { parse_str((string)$b, $f); return $f; };
$hasHeader = fn(array $r, string $h): bool => in_array($h, $r['headers'] ?? [], true);
$links433 = fn(): array => $db->query("SELECT * FROM calendar_links WHERE user_id=$u433")->fetchAll();
$busy433 = fn(): array => $db->query("SELECT start_at, end_at FROM calendar_busy WHERE user_id=$u433 ORDER BY start_at")->fetchAll(PDO::FETCH_NUM);
$b64url = fn(string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
$idToken = fn(array $claims) => $b64url('{"alg":"RS256","typ":"JWT"}') . '.' . $b64url((string)json_encode($claims)) . '.' . $b64url('synthetic-signature');
$org433 = trim((string)(brand('short') ?: cfg('name')));
$sent433();

/* ---------- Microsoft, a personal account ---------- */
// The setting decides the sign-in address and what is asked for: a work account keeps Teams.
check(str_contains(meeting_app('microsoft')['token'], '/organizations/') && meeting_app('microsoft')['scope'] === MEETING_MS_WORK_SCOPE, 'Work accounts (the default) sign in at organizations and are asked for Teams and the calendar');
$GLOBALS['CONFIG']['meet']['ms_accounts'] = 'personal';
check(str_contains(meeting_app('microsoft')['authorize'], '/consumers/oauth2/v2.0/authorize') && meeting_app('microsoft')['scope'] === 'openid email offline_access Calendars.ReadWrite',
    'Personal accounts sign in at consumers and are asked for openid, email, offline_access and Calendars.ReadWrite only (no User.Read, no Teams)');
$GLOBALS['CONFIG']['meet']['ms_accounts'] = 'any';
check(str_contains(meeting_app('microsoft')['token'], '/common/'), 'Any account signs in at common');
$GLOBALS['CONFIG']['meet']['ms_accounts'] = 'personal';
check(config_override_save('meet.ms_accounts', 'everyone', 1) !== null && config_override_save('meet.ms_accounts', 'personal', 1) === null, 'The setting takes work, personal or any, and nothing else');
// Connecting (the page, in its own process): the code is exchanged at consumers with the personal
// scope; the account's address comes from the ID token; Graph /me is never asked.
$log433 = $tmp . '/v433_requests.log'; @unlink($log433); putenv('CMS_TEST_MEETINGS_LOG=' . $log433);
putenv('CMS_TEST_MEETINGS=' . json_encode([
    'https://login.microsoftonline.com/' => ['body' => ['access_token' => 'v433-ms-access-1', 'refresh_token' => 'v433-ms-refresh-1', 'expires_in' => 3600, 'scope' => 'https://graph.microsoft.com/Calendars.ReadWrite',
        'id_token' => $idToken(['aud' => 'synthetic-ms-id', 'iss' => 'https://login.microsoftonline.com/9188040d-6c67-4c5b-b112-36a304b66dad/v2.0', 'email' => 'v433-personal@example.invalid', 'preferred_username' => 'v433-personal@example.invalid'])]],
    'https://graph.microsoft.com/v1.0/me' => ['status' => 403, 'body' => ['error' => ['code' => 'Authorization_RequestDenied', 'message' => '']]],
]));
putenv('CMS_TEST_SESSION_ADD=' . json_encode(['meeting_oauth' => ['state' => 'v433-state', 'provider' => 'microsoft']]));
$db->exec("INSERT INTO settings(name,value) VALUES ('cfg.meet.ms_accounts','personal') ON DUPLICATE KEY UPDATE value=VALUES(value)"); // the page, in its own process, reads the setting
[$code, , $err] = $renderPage('meeting_connect', ['provider' => 'microsoft', 'code' => 'v433-code', 'state' => 'v433-state']);
putenv('CMS_TEST_SESSION_ADD'); putenv('CMS_TEST_MEETINGS'); putenv('CMS_TEST_MEETINGS_LOG');
$pageReq = array_map(fn($l) => json_decode($l, true), array_filter(explode("\n", (string)@file_get_contents($log433)))); @unlink($log433);
$tokReq = $pageReq[0] ?? []; $f = $form433($tokReq['body'] ?? null);
check(str_contains($tokReq['url'] ?? '', 'https://login.microsoftonline.com/consumers/oauth2/v2.0/token') && ($f['scope'] ?? '') === 'openid email offline_access Calendars.ReadWrite'
    && ($f['client_id'] ?? '') === 'synthetic-ms-id' && ($f['client_secret'] ?? '') === 'synthetic-ms-secret' && ($f['grant_type'] ?? '') === 'authorization_code'
    && ($f['redirect_uri'] ?? '') === 'https://example.invalid/meeting_connect.php?provider=microsoft',
    'Connecting a personal account exchanges the code at consumers with the calendar-only scope and the exact return address' . ($err ? ": $err" : ''));
check(count($pageReq) === 1 && (meeting_account(1, 'microsoft')['account_email'] ?? '') === 'v433-personal@example.invalid' && meeting_open((string)meeting_account(1, 'microsoft')['refresh_token']) === 'v433-ms-refresh-1',
    'The account\'s address is taken from the ID token and Graph /me is never asked; the refresh token is kept encrypted');
$db->exec('DELETE FROM meeting_accounts WHERE user_id=1');
// This instructor's own connection, as Microsoft grants a personal account (the scope as a full address).
meeting_store($u433, 'microsoft', ['access_token' => 'v433-ms-access-1', 'refresh_token' => 'v433-ms-refresh-1', 'expires_in' => 3600, 'scope' => 'https://graph.microsoft.com/Calendars.ReadWrite'], 'v433-personal@example.invalid');
check(calendar_kind($u433) === 'microsoft' && calendar_problem($u433) === null, 'With Microsoft connected and nothing else, the person\'s calendar is the Microsoft one, and a personal account\'s scope counts as the calendar');
// Reading the calendar: calendarView across two pages, UTC, and an event made once with a transactionId.
$g = fn(string $utc) => ['dateTime' => $utc . '.0000000', 'timeZone' => 'UTC'];
$queue433 = [
    ['#^GET https://graph\.microsoft\.com/v1\.0/me/calendarView\?startDateTime=#', $json433(200, ['value' => [
        ['id' => 'other-1', 'start' => $g(gmdate('Y-m-d\TH:i:s', $start433 + 86400)), 'end' => $g(gmdate('Y-m-d\TH:i:s', $start433 + 90000)), 'showAs' => 'busy'],
        ['id' => 'other-free', 'start' => $g(gmdate('Y-m-d\TH:i:s', $start433 + 172800)), 'end' => $g(gmdate('Y-m-d\TH:i:s', $start433 + 176400)), 'showAs' => 'free'],
        ['id' => 'other-cancelled', 'start' => $g(gmdate('Y-m-d\TH:i:s', $start433 + 259200)), 'end' => $g(gmdate('Y-m-d\TH:i:s', $start433 + 262800)), 'showAs' => 'busy', 'isCancelled' => true],
    ], '@odata.nextLink' => 'https://graph.microsoft.com/v1.0/me/calendarView?page2'])],
    ['#^GET https://graph\.microsoft\.com/v1\.0/me/calendarView\?page2$#', $json433(200, ['value' => [
        ['id' => 'other-2', 'start' => $g(gmdate('Y-m-d\TH:i:s', $start433 + 345600)), 'end' => $g(gmdate('Y-m-d\TH:i:s', $start433 + 349200)), 'showAs' => 'tentative'],
    ]])],
    ['#^POST https://graph\.microsoft\.com/v1\.0/me/events$#', $json433(201, ['id' => 'EV433'])],
];
$r = calendar_sync_user($u433);
$reqs = $sent433();
$view = $reqs[0] ?? []; $post = $reqs[2] ?? []; $ev = json_decode((string)($post['body'] ?? ''), true) ?: [];
check($r['error'] === null && $r['pushed'] === 1 && $r['busy'] === 2 && count($reqs) === 3
    && str_contains((string)($view['url'] ?? ''), 'startDateTime=' . gmdate('Y-m-d\TH', time() - 86400)) && $hasHeader($view, 'Authorization: Bearer v433-ms-access-1') && $hasHeader($view, 'Prefer: outlook.timezone="UTC"'),
    'A personal account\'s calendar is read with calendarView in UTC across its pages; free and cancelled events are not busy' . ($r['error'] ? ': ' . $r['error'] : ''));
check(($ev['transactionId'] ?? '') === 'cms-' . $s433 . '-' . substr(sha1((string)json_encode(calendar_payload($db->query("SELECT s.*, c.name AS client_name FROM scheduled_sessions s JOIN clients c ON c.id=s.client_id WHERE s.id=$s433")->fetch()))), 0, 12)
    && ($ev['start']['dateTime'] ?? '') === gmdate('Y-m-d\TH:i:s', $start433) && ($ev['sensitivity'] ?? '') === 'private' && ($ev['subject'] ?? '') === $org433 . ': Session with V433',
    'The session\'s event is made once (transactionId from the session and its content), in UTC, marked private, named by the client\'s first name');
$l = $links433();
check(count($l) === 1 && $l[0]['event_id'] === 'EV433' && $l[0]['kind'] === 'microsoft' && count($busy433()) === 2, 'The link remembers the event and its kind; two busy times are kept');
// Renewal: the refresh token, the app in the body, only the scope Microsoft granted; the reply's refresh token replaces the old one.
$db->exec("UPDATE meeting_accounts SET expires_at='" . date('Y-m-d H:i:s', time() - 60) . "' WHERE user_id=$u433 AND provider='microsoft'");
$queue433 = [['#^POST https://login\.microsoftonline\.com/consumers/oauth2/v2\.0/token$#', $json433(200, ['access_token' => 'v433-ms-access-2', 'refresh_token' => 'v433-ms-refresh-2', 'expires_in' => 3599])]];
$tok = meeting_access_token($u433, 'microsoft');
$f = $form433(($sent433()[0] ?? [])['body'] ?? null);
check($tok === 'v433-ms-access-2' && ($f['grant_type'] ?? '') === 'refresh_token' && ($f['refresh_token'] ?? '') === 'v433-ms-refresh-1' && ($f['client_id'] ?? '') === 'synthetic-ms-id' && ($f['client_secret'] ?? '') === 'synthetic-ms-secret'
    && ($f['scope'] ?? '') === 'https://graph.microsoft.com/Calendars.ReadWrite' && meeting_open((string)meeting_account($u433, 'microsoft')['refresh_token']) === 'v433-ms-refresh-2',
    'An expired access token is renewed at consumers with the refresh token, the app in the body and the granted scope; the new refresh token is kept');
// A token refused before it expired (401): renewed once and the read repeated with the new token.
$queue433 = [
    ['#calendarView#', $json433(401, ['error' => ['code' => 'InvalidAuthenticationToken', 'message' => 'Access token has expired or is not yet valid.']])],
    ['#^POST https://login\.microsoftonline\.com/consumers/oauth2/v2\.0/token$#', $json433(200, ['access_token' => 'v433-ms-access-3', 'expires_in' => 3599])],
    ['#calendarView#', $json433(200, ['value' => [['id' => 'EV433', 'start' => $g(gmdate('Y-m-d\TH:i:s', $start433)), 'end' => $g(gmdate('Y-m-d\TH:i:s', $start433 + 3600)), 'showAs' => 'busy']]])],
];
$r = calendar_sync_user($u433);
$reqs = $sent433();
check($r['error'] === null && count($reqs) === 3 && str_contains($reqs[1]['url'], '/token') && $hasHeader($reqs[2], 'Authorization: Bearer v433-ms-access-3') && count($busy433()) === 0,
    'A token Microsoft refuses early is renewed once and the read repeated; our own event is not a busy time' . ($r['error'] ? ': ' . $r['error'] : ''));
// A refresh token Microsoft will not renew: said in words, the connection marked, nothing more sent.
$db->exec("UPDATE meeting_accounts SET expires_at='" . date('Y-m-d H:i:s', time() - 60) . "' WHERE user_id=$u433 AND provider='microsoft'");
$queue433 = [['#token#', $json433(400, ['error' => 'invalid_grant', 'error_description' => 'AADSTS70000: The refresh token has expired due to inactivity.'])]];
$r = calendar_sync_user($u433);
check(str_contains((string)$r['error'], 'connect Microsoft again') && meeting_refused_at(meeting_account($u433, 'microsoft')) !== null && count($sent433()) === 1,
    'An expired refresh token is reported as "connect Microsoft again" and the connection is marked as ended');
meeting_store($u433, 'microsoft', ['access_token' => 'v433-ms-access-4', 'refresh_token' => 'v433-ms-refresh-4', 'expires_in' => 3600, 'scope' => 'https://graph.microsoft.com/Calendars.ReadWrite'], 'v433-personal@example.invalid');
// Cancelling the session removes its event at once.
$db->exec("UPDATE scheduled_sessions SET status='Cancelled' WHERE id=$s433");
$queue433 = [['#^DELETE https://graph\.microsoft\.com/v1\.0/me/events/EV433$#', [204, '', []]]];
calendar_push_session($s433);
check(($sent433()[0]['method'] ?? '') === 'DELETE' && count($links433()) === 0, 'A cancelled session\'s event is deleted from the Microsoft calendar at once');
$db->exec("UPDATE scheduled_sessions SET status='Scheduled' WHERE id=$s433");
// Test the connection, on My Account (the page in its own process): the account named, with its busy count.
putenv('CMS_TEST_MEETINGS=' . json_encode(['https://graph.microsoft.com/v1.0/me/calendarView' => ['body' => ['value' => [['id' => 'x', 'start' => $g(gmdate('Y-m-d\TH:i:s', time() + 3600)), 'end' => $g(gmdate('Y-m-d\TH:i:s', time() + 7200)), 'showAs' => 'busy']]]]]));
meeting_store(1, 'microsoft', ['access_token' => 'v433-admin-access', 'refresh_token' => 'v433-admin-refresh', 'expires_in' => 3600, 'scope' => 'Calendars.ReadWrite'], 'v433-admin@example.invalid');
$db->exec("UPDATE users SET calendar_kind='microsoft' WHERE id=1");
[[$code, , $err], $sess] = $pageSession(fn() => $renderPage('account', [], ['calendar_action' => 'test']));
putenv('CMS_TEST_MEETINGS');
check(str_contains($sess, 'Connected to the Microsoft calendar of v433-admin@example.invalid') && str_contains($sess, '1 busy time in the next 7 days'), 'Test the connection names the Microsoft account and its busy count for the next 7 days' . ($err ? ": $err" : ''));
[$code, $html, $err] = $renderPage('account', []);
check(str_contains($html, 'My calendar') && str_contains($html, 'Test the connection') && str_contains($html, 'Disconnect this calendar'), 'My Account shows the connected calendar with Test the connection' . ($err ? ": $err" : ''));
$db->exec('DELETE FROM meeting_accounts WHERE user_id=1'); $db->exec('UPDATE users SET calendar_kind=NULL WHERE id=1');
$GLOBALS['CONFIG']['meet']['ms_accounts'] = '';
$db->exec("DELETE FROM settings WHERE name='cfg.meet.ms_accounts'");

/* ---------- Google, through a service account ---------- */
$pair = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
openssl_pkey_export($pair, $pem);
$public = openssl_pkey_get_details($pair)['key'];
$saJson = (string)json_encode(['type' => 'service_account', 'client_email' => 'robot@example.iam.gserviceaccount.com', 'private_key' => $pem, 'private_key_id' => 'abc']);
check(google_service_account('{"client_email":"robot@example.iam.gserviceaccount.com"}', $why) === null && str_contains((string)$why, 'private_key'), 'A key file without its private key is refused in words');
check(google_service_account($saJson) !== null, 'A whole key file is accepted');
// Connecting (the page): the calendar's name is read (one token, one GET), and the key is kept encrypted.
$db->exec("DELETE FROM meeting_accounts WHERE user_id=$u433");
$db->exec("DELETE FROM calendar_links WHERE user_id=$u433");
putenv('CMS_TEST_MEETINGS=' . json_encode([
    'https://oauth2.googleapis.com/token' => ['body' => ['access_token' => 'g-at-1', 'expires_in' => 3599, 'token_type' => 'Bearer']],
    'https://www.googleapis.com/calendar/v3/calendars/owner%40example.invalid' => ['body' => ['id' => 'owner@example.invalid', 'summary' => 'Owner\'s calendar']],
]));
[[$code, , $err], $sess] = $pageSession(fn() => $renderPage('account', [], ['calendar_action' => 'google', 'google_calendar' => 'owner@example.invalid', 'google_key' => $saJson]));
putenv('CMS_TEST_MEETINGS');
$acc = $db->query('SELECT * FROM calendar_accounts WHERE user_id=1')->fetch();
check(str_contains($sess, 'The Google calendar "Owner\'s calendar" is connected') && $acc && $acc['kind'] === 'google' && $acc['server_url'] === 'owner@example.invalid'
    && !str_contains((string)$acc['secret'], 'PRIVATE KEY') && (json_decode((string)meeting_open((string)$acc['secret']), true)['client_email'] ?? '') === 'robot@example.iam.gserviceaccount.com'
    && $db->query('SELECT calendar_kind FROM users WHERE id=1')->fetchColumn() === 'google',
    'Connecting a Google calendar on My Account reads its name, keeps the key encrypted and makes Google the person\'s calendar' . ($err ? ": $err" : ''));
// A calendar not shared with the service account, at connect time.
putenv('CMS_TEST_MEETINGS=' . json_encode([
    'https://oauth2.googleapis.com/token' => ['body' => ['access_token' => 'g-at-1', 'expires_in' => 3599]],
    'https://www.googleapis.com/calendar/v3/calendars/' => ['status' => 404, 'body' => ['error' => ['code' => 404, 'message' => 'Not Found']]],
]));
[[$code, , $err], $sess] = $pageSession(fn() => $renderPage('account', [], ['calendar_action' => 'google', 'google_calendar' => 'nobody@example.invalid', 'google_key' => $saJson]));
putenv('CMS_TEST_MEETINGS');
check(str_contains($sess, 'not shared with the service account robot@example.iam.gserviceaccount.com') && $db->query('SELECT server_url FROM calendar_accounts WHERE user_id=1')->fetchColumn() === 'owner@example.invalid',
    'A Google calendar not shared with the service account is refused with that explanation, and the earlier calendar is kept' . ($err ? ": $err" : ''));
$db->exec('DELETE FROM calendar_accounts WHERE user_id=1'); $db->exec('UPDATE users SET calendar_kind=NULL WHERE id=1');
// This instructor's own Google calendar: the JWT, freeBusy, our events listed by their mark, an event made.
calendar_account_store($u433, 'google', 'Owner\'s calendar', 'owner@example.invalid', 'robot@example.iam.gserviceaccount.com', $saJson, '');
check(calendar_kind($u433) === 'google' && calendar_problem($u433) === null, 'A connected Google calendar is the person\'s calendar kind');
$queue433 = [
    ['#^POST https://oauth2\.googleapis\.com/token$#', $json433(200, ['access_token' => 'g-at-2', 'expires_in' => 3599])],
    ['#^GET https://www\.googleapis\.com/calendar/v3/calendars/owner%40example\.invalid/events\?#', $json433(200, ['items' => []])],
    ['#^POST https://www\.googleapis\.com/calendar/v3/freeBusy$#', $json433(200, ['calendars' => ['owner@example.invalid' => ['busy' => [['start' => gmdate('Y-m-d\TH:i:s\Z', $start433 + 86400), 'end' => gmdate('Y-m-d\TH:i:s\Z', $start433 + 90000)]]]]])],
    ['#^POST https://www\.googleapis\.com/calendar/v3/calendars/owner%40example\.invalid/events$#', $json433(200, ['id' => 'gev433', 'htmlLink' => 'https://calendar.google.com/event?eid=gev433'])],
];
$r = calendar_sync_user($u433);
$reqs = $sent433();
$f = $form433($reqs[0]['body'] ?? null);
$jwtOk = false;
if (isset($f['assertion'])) {
    [$h, $p, $sig] = array_pad(explode('.', (string)$f['assertion']), 3, '');
    $claims = json_decode((string)base64_decode(strtr($p, '-_', '+/')), true) ?: [];
    $jwtOk = ($f['grant_type'] ?? '') === 'urn:ietf:params:oauth:grant-type:jwt-bearer' && ($claims['iss'] ?? '') === 'robot@example.iam.gserviceaccount.com'
        && ($claims['scope'] ?? '') === 'https://www.googleapis.com/auth/calendar' && ($claims['aud'] ?? '') === 'https://oauth2.googleapis.com/token' && ($claims['exp'] ?? 0) - ($claims['iat'] ?? 0) === 3600
        && openssl_verify("$h.$p", (string)base64_decode(strtr($sig, '-_', '+/')), $public, OPENSSL_ALGO_SHA256) === 1;
}
check($jwtOk, 'The service account signs in with an RS256 JWT the generated public key verifies, asking for the calendar scope' . ($r['error'] ? ': ' . $r['error'] : ''));
$list = $reqs[1] ?? []; $fb = json_decode((string)($reqs[2]['body'] ?? ''), true) ?: []; $ev = json_decode((string)($reqs[3]['body'] ?? ''), true) ?: [];
check($r['error'] === null && str_contains((string)($list['url'] ?? ''), 'privateExtendedProperty=cms_calendar%3D1') && str_contains((string)($list['url'] ?? ''), 'singleEvents=true') && $hasHeader($list, 'Authorization: Bearer g-at-2')
    && ($fb['items'][0]['id'] ?? '') === 'owner@example.invalid' && str_starts_with((string)($fb['timeMin'] ?? ''), gmdate('Y-m-d\TH', time() - 86400)) && count($busy433()) === 1,
    'Our events are listed by their private mark and the person\'s busy times come from freeBusy, both with the bearer token');
check(($ev['summary'] ?? '') === $org433 . ': Session with V433' && ($ev['start']['dateTime'] ?? '') === gmdate('Y-m-d\TH:i:s\Z', $start433) && ($ev['visibility'] ?? '') === 'private'
    && ($ev['extendedProperties']['private']['cms_calendar'] ?? '') === '1' && ($ev['extendedProperties']['private']['cms_session'] ?? '') === (string)$s433 && !isset($ev['attendees'])
    && ($links433()[0]['event_id'] ?? '') === 'gev433' && ($links433()[0]['kind'] ?? '') === 'google',
    'The event is made on the shared calendar, private, marked as ours, with no guests (a service account cannot invite)');
// The access token is cached (encrypted) and used again without a new sign-in; our own event, listed back, is not a busy time.
$queue433 = [
    ['#/events\?#', $json433(200, ['items' => [['id' => 'gev433', 'status' => 'confirmed', 'start' => ['dateTime' => gmdate('Y-m-d\TH:i:s\Z', $start433)], 'end' => ['dateTime' => gmdate('Y-m-d\TH:i:s\Z', $start433 + 3600)]]]])],
    ['#freeBusy#', $json433(200, ['calendars' => ['owner@example.invalid' => ['busy' => [['start' => gmdate('Y-m-d\TH:i:s\Z', $start433), 'end' => gmdate('Y-m-d\TH:i:s\Z', $start433 + 3600)]]]]])],
];
$r = calendar_sync_user($u433);
$reqs = $sent433();
check($r['error'] === null && count($reqs) === 2 && $hasHeader($reqs[0], 'Authorization: Bearer g-at-2') && count($busy433()) === 0
    && meeting_open((string)$db->query("SELECT access_token FROM calendar_accounts WHERE user_id=$u433")->fetchColumn()) === 'g-at-2',
    'The cached access token is used again (kept encrypted); the busy time that is our own session is not counted');
// A token Google refuses (401): renewed once and the call repeated.
$queue433 = [
    ['#/events\?#', $json433(401, ['error' => ['code' => 401, 'message' => 'Invalid Credentials']])],
    ['#^POST https://oauth2\.googleapis\.com/token$#', $json433(200, ['access_token' => 'g-at-3', 'expires_in' => 3599])],
    ['#/events\?#', $json433(200, ['items' => []])],
    ['#freeBusy#', $json433(200, ['calendars' => ['owner@example.invalid' => ['busy' => []]]])],
];
$r = calendar_sync_user($u433);
$reqs = $sent433();
check($r['error'] === null && count($reqs) === 4 && str_contains($reqs[1]['url'], 'oauth2.googleapis.com/token') && $hasHeader($reqs[2], 'Authorization: Bearer g-at-3'),
    'A token Google refuses is renewed once with a new JWT and the call repeated' . ($r['error'] ? ': ' . $r['error'] : ''));
// A calendar no longer shared: freeBusy says notFound, explained.
$queue433 = [
    ['#/events\?#', $json433(200, ['items' => []])],
    ['#freeBusy#', $json433(200, ['calendars' => ['owner@example.invalid' => ['errors' => [['domain' => 'global', 'reason' => 'notFound']]]]])],
];
$r = calendar_sync_user($u433);
$sent433();
check(str_contains((string)$r['error'], 'notFound') && str_contains((string)$r['error'], 'not shared with the service account robot@example.iam.gserviceaccount.com'), 'A calendar not shared with the service account is explained (notFound)');
// Cancelling removes the event; an event already gone (404) counts as removed.
$db->exec("UPDATE scheduled_sessions SET status='Cancelled' WHERE id=$s433");
$queue433 = [['#^DELETE https://www\.googleapis\.com/calendar/v3/calendars/owner%40example\.invalid/events/gev433$#', $json433(404, ['error' => ['code' => 404, 'message' => 'Not Found']])]];
calendar_push_session($s433);
check(($sent433()[0]['method'] ?? '') === 'DELETE' && count($links433()) === 0, 'A cancelled session\'s event is deleted from the Google calendar, and one already gone counts as removed');
$db->exec("UPDATE scheduled_sessions SET status='Scheduled' WHERE id=$s433");
calendar_account_forget($u433);

/* ---------- CalDAV ---------- */
$ms = fn(string $inner) => [207, '<?xml version="1.0"?><d:multistatus xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">' . $inner . '</d:multistatus>', []];
$resp = fn(string $href, string $props) => '<d:response><d:href>' . $href . '</d:href><d:propstat><d:prop>' . $props . '</d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response>';
$homeXml = $resp('/calendars/owner@example.invalid/', '<d:resourcetype><d:collection/></d:resourcetype>')
    . $resp('/calendars/owner@example.invalid/calendar/', '<d:resourcetype><d:collection/><c:calendar/></d:resourcetype><d:displayname>Work</d:displayname><c:supported-calendar-component-set><c:comp name="VEVENT"/><c:comp name="VTODO"/></c:supported-calendar-component-set>')
    . $resp('/calendars/owner@example.invalid/tasks/', '<d:resourcetype><d:collection/><c:calendar/></d:resourcetype><d:displayname>Tasks</d:displayname><c:supported-calendar-component-set><c:comp name="VTODO"/></c:supported-calendar-component-set>')
    . $resp('/calendars/owner@example.invalid/inbox/', '<d:resourcetype><d:collection/><c:schedule-inbox/></d:resourcetype><d:displayname>Inbox</d:displayname>');
$dav = ['kind' => 'caldav', 'user_id' => $u433, 'server_url' => 'https://example.invalid:2080', 'username' => 'owner@example.invalid', 'password' => 'synthetic-app-password', 'calendar_url' => ''];
$basicDav = 'Authorization: Basic ' . base64_encode('owner@example.invalid:synthetic-app-password');
$queue433 = [
    ['#^PROPFIND https://example\.invalid:2080/$#', $ms($resp('/', '<d:current-user-principal><d:href>/principals/owner@example.invalid/</d:href></d:current-user-principal><d:resourcetype><d:collection/></d:resourcetype>'))],
    ['#^PROPFIND https://example\.invalid:2080/principals/owner@example\.invalid/$#', $ms($resp('/principals/owner@example.invalid/', '<c:calendar-home-set><d:href>/calendars/owner@example.invalid/</d:href></c:calendar-home-set><d:resourcetype><d:principal/></d:resourcetype>'))],
    ['#^PROPFIND https://example\.invalid:2080/calendars/owner@example\.invalid/$#', $ms($homeXml)],
];
$why = '';
try { $found = caldav_discover($dav); } catch (RuntimeException $e) { $found = null; $why = $e->getMessage(); }
$reqs = $sent433();
check($found === [['https://example.invalid:2080/calendars/owner@example.invalid/calendar/', 'Work']] && $hasHeader($reqs[0], 'Depth: 0') && $hasHeader($reqs[0], $basicDav) && $hasHeader($reqs[2], 'Depth: 1'),
    'CalDAV discovery follows current-user-principal and calendar-home-set with Basic auth, and offers only the calendar that holds events (not the tasks calendar, not the inbox)' . ($why ? ": $why" : ''));
$queue433 = [['#^PROPFIND #', [401, '', []]]];
try { caldav_discover($dav); $why = ''; } catch (RuntimeException $e) { $why = $e->getMessage(); }
$sent433();
check($why === 'The calendar server did not accept that user name and password.', 'A wrong password is reported in plain words');
// Connecting on My Account (the page): with one event calendar found it is chosen at once, and the password is kept encrypted.
putenv('CMS_TEST_MEETINGS=' . json_encode([
    'https://example.invalid:2080/principals/' => ['status' => 207, 'raw' => $ms($resp('/principals/owner@example.invalid/', '<c:calendar-home-set><d:href>/calendars/owner@example.invalid/</d:href></c:calendar-home-set>'))[1]],
    'https://example.invalid:2080/calendars/' => ['status' => 207, 'raw' => $ms($homeXml)[1]],
    'https://example.invalid:2080/' => ['status' => 207, 'raw' => $ms($resp('/', '<d:current-user-principal><d:href>/principals/owner@example.invalid/</d:href></d:current-user-principal>'))[1]],
]));
[[$code, , $err], $sess] = $pageSession(fn() => $renderPage('account', [], ['calendar_action' => 'caldav_find', 'caldav_url' => 'https://example.invalid:2080', 'caldav_user' => 'owner@example.invalid', 'caldav_password' => 'synthetic-app-password']));
putenv('CMS_TEST_MEETINGS');
$acc = $db->query('SELECT * FROM calendar_accounts WHERE user_id=1')->fetch();
check(str_contains($sess, 'The calendar "Work" is connected') && $acc && $acc['kind'] === 'caldav' && $acc['calendar_url'] === 'https://example.invalid:2080/calendars/owner@example.invalid/calendar/'
    && $acc['secret'] !== 'synthetic-app-password' && meeting_open((string)$acc['secret']) === 'synthetic-app-password' && $db->query('SELECT calendar_kind FROM users WHERE id=1')->fetchColumn() === 'caldav',
    'Connecting a CalDAV calendar on My Account finds the one event calendar, keeps the app password encrypted and makes CalDAV the person\'s calendar' . ($err ? ": $err" : ''));
[$code, $html, $err] = $renderPage('account', []);
check(str_contains($html, 'CalDAV (iCloud, Nextcloud, cPanel, Fastmail): Work') && !str_contains($html, 'synthetic-app-password'), 'My Account names the connected CalDAV calendar and never shows the password' . ($err ? ": $err" : ''));
$db->exec('DELETE FROM calendar_accounts WHERE user_id=1'); $db->exec('UPDATE users SET calendar_kind=NULL WHERE id=1');
// This instructor's own CalDAV calendar: the busy-time REPORT (a repeat expanded), PUT with If-None-Match, update, DELETE.
calendar_account_store($u433, 'caldav', 'Work', 'https://example.invalid:2080', 'owner@example.invalid', 'synthetic-app-password', 'https://example.invalid:2080/calendars/owner@example.invalid/calendar/');
check(calendar_kind($u433) === 'caldav' && calendar_problem($u433) === null, 'A connected CalDAV calendar is the person\'s calendar kind');
$weekly0 = strtotime('+1 day 2 hours', $start433); // a weekly meeting, in the server's zone, starting the day after the session
$weekly = date('Ymd\THis', $weekly0);
$icsOther = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:other@example.invalid\r\nDTSTART;TZID=" . date_default_timezone_get() . ":$weekly\r\nDURATION:PT1H\r\nRRULE:FREQ=WEEKLY;COUNT=3\r\nSUMMARY:Staff meeting\r\nEND:VEVENT\r\nEND:VCALENDAR";
$icsFree = "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nUID:free@example.invalid\r\nDTSTART:" . gmdate('Ymd\THis\Z', $start433 + 172800) . "\r\nDTEND:" . gmdate('Ymd\THis\Z', $start433 + 176400) . "\r\nTRANSP:TRANSPARENT\r\nEND:VEVENT\r\nEND:VCALENDAR";
$icsCancelled = "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nUID:gone@example.invalid\r\nDTSTART:" . gmdate('Ymd\THis\Z', $start433 + 259200) . "\r\nDTEND:" . gmdate('Ymd\THis\Z', $start433 + 262800) . "\r\nSTATUS:CANCELLED\r\nEND:VEVENT\r\nEND:VCALENDAR";
$reportXml = $ms($resp('/calendars/owner@example.invalid/calendar/other.ics', '<d:getetag>"1"</d:getetag><c:calendar-data>' . htmlspecialchars($icsOther) . '</c:calendar-data>')
    . $resp('/calendars/owner@example.invalid/calendar/free.ics', '<c:calendar-data>' . htmlspecialchars($icsFree) . '</c:calendar-data>')
    . $resp('/calendars/owner@example.invalid/calendar/gone.ics', '<c:calendar-data>' . htmlspecialchars($icsCancelled) . '</c:calendar-data>'));
$queue433 = [
    ['#^REPORT https://example\.invalid:2080/calendars/owner@example\.invalid/calendar/$#', $reportXml],
    ['#^PUT https://example\.invalid:2080/calendars/owner@example\.invalid/calendar/session-[0-9a-f]{16}\.ics$#', [201, '', ['etag' => '"abc"']]],
];
$r = calendar_sync_user($u433);
$reqs = $sent433();
$report = $reqs[0] ?? []; $put = $reqs[1] ?? [];
check($r['error'] === null && ($report['method'] ?? '') === 'REPORT' && $hasHeader($report, 'Depth: 1') && $hasHeader($report, $basicDav)
    && str_contains((string)($report['body'] ?? ''), '<c:comp-filter name="VEVENT"><c:time-range start="' . gmdate('Ymd\THis\Z', time() - 86400)),
    'The busy times come from a calendar-query REPORT for the window, with the saved password' . ($r['error'] ? ': ' . $r['error'] : ''));
$busy = $busy433();
check(count($busy) === 3 && $busy[0][0] === date('Y-m-d H:i:s', $weekly0) && $busy[2][0] === date('Y-m-d H:i:s', strtotime('+14 days', $weekly0)) && $busy[0][1] === date('Y-m-d H:i:s', $weekly0 + 3600),
    'A weekly event is expanded into its three busy times; a transparent event and a cancelled one are not busy');
$l = $links433();
check(($put['method'] ?? '') === 'PUT' && $hasHeader($put, 'If-None-Match: *') && $hasHeader($put, 'Content-Type: text/calendar; charset=utf-8') && $hasHeader($put, $basicDav)
    && str_contains((string)$put['body'], 'DTSTART:' . gmdate('Ymd\THis\Z', $start433)) && str_contains((string)$put['body'], 'SUMMARY:' . ics_escape($org433) . ': Session with V433') && str_contains((string)$put['body'], 'CLASS:PRIVATE')
    && str_contains((string)$put['body'], 'UID:' . basename((string)$put['url'], '.ics') . '@example.invalid')
    && count($l) === 1 && $l[0]['event_id'] === $put['url'] && $l[0]['kind'] === 'caldav',
    'The session is PUT as a private .ics with If-None-Match: *, and the link remembers its address');
// The session moves: the same file is written again (no If-None-Match), with the new time.
$db->exec("UPDATE scheduled_sessions SET start_at=DATE_ADD(start_at, INTERVAL 1 HOUR), end_at=DATE_ADD(end_at, INTERVAL 1 HOUR) WHERE id=$s433");
$queue433 = [['#^PUT ' . preg_quote($l[0]['event_id'], '#') . '$#', [204, '', ['etag' => '"def"']]]];
calendar_push_session($s433);
$put = $sent433()[0] ?? [];
check(($put['method'] ?? '') === 'PUT' && !$hasHeader($put, 'If-None-Match: *') && str_contains((string)$put['body'], 'DTSTART:' . gmdate('Ymd\THis\Z', $start433 + 3600)) && count($links433()) === 1,
    'A moved session rewrites its own .ics file with the new time');
// Our own event, read back, is not a busy time; a wrong password while reading is said in words.
$icsOurs = "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nUID:" . basename((string)$put['url'], '.ics') . "@example.invalid\r\nDTSTART:" . gmdate('Ymd\THis\Z', $start433 + 3600) . "\r\nDTEND:" . gmdate('Ymd\THis\Z', $start433 + 7200) . "\r\nCLASS:PRIVATE\r\nEND:VEVENT\r\nEND:VCALENDAR";
$queue433 = [['#^REPORT #', $ms($resp('/calendars/owner@example.invalid/calendar/' . basename((string)$put['url']), '<c:calendar-data>' . htmlspecialchars($icsOurs) . '</c:calendar-data>'))]];
$r = calendar_sync_user($u433);
$sent433();
check($r['error'] === null && count($busy433()) === 0 && count($links433()) === 1, 'Our own .ics file is known by its address and is not a busy time');
$queue433 = [['#^REPORT #', [401, '', []]]];
$r = calendar_sync_user($u433);
$sent433();
check(str_contains((string)$r['error'], 'did not accept the saved user name and password'), 'A password the server no longer accepts is reported in plain words');
// Test the connection: the calendar named, with its busy count.
$queue433 = [['#^REPORT #', $reportXml]];
$said = calendar_test($u433);
$sent433();
check(str_contains($said, 'the calendar "Work" at example.invalid') && str_contains($said, '1 busy time in the next 7 days'), 'Test the connection names the CalDAV calendar and its busy count (only the first week of the repeat falls in the next 7 days): ' . $said);
// Turning sync off removes the event; a file already gone (404) counts as removed.
$queue433 = [['#^DELETE ' . preg_quote($l[0]['event_id'], '#') . '$#', [404, '', []]]];
$n = calendar_stop($u433, $left);
check(($sent433()[0]['method'] ?? '') === 'DELETE' && $n === 1 && $left === 0 && count($links433()) === 0 && (int)$db->query("SELECT calendar_sync FROM users WHERE id=$u433")->fetchColumn() === 0,
    'Turning sync off deletes the .ics file; one already gone (404) counts as removed');

// Put back what the earlier files left.
unset($GLOBALS['CMS_TEST_HTTP']);
$GLOBALS['CMS_TEST_MEETING_REQUESTS'] = [];
$db->exec("DELETE FROM scheduled_sessions WHERE client_id=$c433");
$db->exec("DELETE FROM clients WHERE id=$c433");
$db->exec("DELETE FROM users WHERE id=$u433");
$GLOBALS['CONFIG']['meet'] = ['zoom_client_id' => 'synthetic-zoom-id', 'zoom_client_secret' => 'synthetic-zoom-secret', 'ms_client_id' => 'synthetic-ms-id', 'ms_client_secret' => 'synthetic-ms-secret', 'ms_tenant' => ''];
