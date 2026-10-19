"""Exercise backup failure handling, reminder delivery, email filing, the off-site
backup and the watchdog using local test doubles (no database, mail or network)."""
from pathlib import Path
import gzip
import hashlib
import json
import re
import os
import subprocess
import tempfile
import time

root = Path(__file__).resolve().parents[1]
with tempfile.TemporaryDirectory(prefix='cms-cron-test-') as tmp:
    path = Path(tmp)
    binaries = path / 'bin'
    binaries.mkdir()
    dump = binaries / 'mysqldump'
    # Records each call's arguments; SETTINGS_EXIT fails only the settings-table dump.
    dump.write_text('#!/bin/sh\nprintf "%s\\n" "$*" >> "$DUMP_LOG"\nprintf "synthetic SQL\\n"\n'
                    'case "$*" in *" settings") exit "${SETTINGS_EXIT:-0}";; esac\nexit "${DUMP_EXIT:-0}"\n')
    dump.chmod(0o700)
    date = binaries / 'date'
    date.write_text('#!/bin/sh\nif [ "$1" = "+%u" ]; then echo 2; else /bin/date "$@"; fi\n')
    date.chmod(0o700)
    script = path / 'backup.sh'
    # The script is copied unchanged: every location it touches comes from the
    # CMS_* settings it documents, set here to the temporary directory. Inherited
    # CMS_* values are dropped so a shell (or a production cron environment) can
    # never point this test at a real private directory or database.
    script.write_text((root / 'cron/backup.sh').read_text())
    assert not (path / 'backup.conf').exists()
    base_env = {k: v for k, v in os.environ.items() if not k.startswith('CMS_')}
    dump_log = path / 'dump_calls.log'
    env = {**base_env, 'PATH': str(binaries) + ':' + os.environ['PATH'], 'DUMP_EXIT': '1', 'DUMP_LOG': str(dump_log),
           'CMS_PRIVATE_DIR': str(path), 'CMS_DB_NAME': 'synthetic_db', 'CMS_DB_CNF': str(path / 'db.cnf')}
    result = subprocess.run(['bash', str(script)], env=env, capture_output=True)
    assert result.returncode != 0 and not list((path / 'backups').glob('*'))
    print('PASS: Failed dump leaves no apparent fresh backup or temporary file')
    env['DUMP_EXIT'] = '0'
    env['SETTINGS_EXIT'] = '1'
    result = subprocess.run(['bash', str(script)], env=env, capture_output=True)
    assert result.returncode != 0 and not list((path / 'backups').glob('*'))
    print('PASS: A failed settings-table dump also leaves no backup or temporary file')
    env['SETTINGS_EXIT'] = '0'
    dump_log.unlink()
    result = subprocess.run(['bash', str(script)], env=env, check=True, capture_output=True, text=True)
    files = list((path / 'backups').glob('cms_*.sql.gz'))
    assert len(files) == 1 and gzip.decompress(files[0].read_bytes()) == b'synthetic SQL\nsynthetic SQL\n'
    assert files[0].stat().st_mode & 0o077 == 0
    assert result.stdout.strip() == 'Backup complete: ' + files[0].name
    print('PASS: Successful dump is published atomically with private permissions')
    calls = dump_log.read_text().splitlines()
    assert len(calls) == 2
    assert '--ignore-table=synthetic_db.settings' in calls[0] and calls[0].endswith(' synthetic_db')
    assert calls[1].endswith(' synthetic_db settings') and "name NOT LIKE '%secret%'" in calls[1] and "name NOT LIKE '%api_key%'" in calls[1]
    print('PASS: The settings table is dumped separately without the stored AI key and Entra client secret')
    (path / 'config.php').write_text("<?php return ['db' => ['dsn' => 'mysql:host=localhost;dbname=from_config_db', 'user' => 'u', 'pass' => 'p']];\n")
    dump_log.unlink()
    named = {k: v for k, v in env.items() if k != 'CMS_DB_NAME'}
    subprocess.run(['bash', str(script)], env=named, check=True, capture_output=True, text=True)
    assert dump_log.read_text().splitlines()[0].endswith(' from_config_db')
    (path / 'config.php').unlink()
    for extra in (path / 'backups').glob('cms_*.sql.gz'):
        if extra != files[0]: extra.unlink()
    print('PASS: Without CMS_DB_NAME the backup dumps the database named in config.php')
    if not Path('/home/cmsuser/casemanagement_private').exists():
        unconfigured = {k: v for k, v in env.items() if not k.startswith('CMS_')}
        result = subprocess.run(['bash', str(script)], env=unconfigured, capture_output=True, text=True)
        assert result.returncode != 0 and 'backup.conf' in result.stderr
        assert len(list((path / 'backups').glob('cms_*.sql.gz'))) == 1
        print('PASS: Without backup.conf or CMS_PRIVATE_DIR the backup refuses to run and says why')

    source = (root / 'cron/session_reminders.php').read_text()
    start = source.index('foreach ([')
    end = source.index('$sessions =', start)
    source = source[:start] + "require __DIR__ . '/mail_fixture.php';\n" + source[end:]
    (path / 'reminders.php').write_text(source)
    (path / 'mail_fixture.php').write_text(r'''<?php
$marks=0; $calls=0; $admin=[]; $notices=[]; $links=[]; $to=[]; $mails=[];
class FakeResult {
    function fetchAll() {
        // One session row as the reminder query returns it.
        $base=['id'=>1,'client_id'=>5,'user_id'=>2,'active_staff_id'=>2,'staff_sees_client'=>1,'client_name'=>'Synthetic client',
            'client_email'=>getenv('CLIENT_EMAIL') ?: 'client@example.invalid','client_email_verified'=>1,'client_current'=>1,
            'staff_email'=>'staff@example.invalid','staff_name'=>'Synthetic staff','assignee_name'=>'Synthetic staff','assignee_email'=>'staff@example.invalid',
            'start_at'=>'2026-01-01 09:00:00','location'=>null,'is_remote'=>0,'meeting_url'=>null];
        // OWNER_INACTIVE: the session's owner was deactivated, so the active-staff join found nobody.
        // REASSIGNED: the owner is active but limited to their own caseload, and the
        // client now belongs to someone else (ASSIGNEE_EMAIL, or nobody when empty).
        if (getenv('OWNER_INACTIVE'))
            return [array_replace($base,['active_staff_id'=>null,'staff_sees_client'=>0,'client_email'=>'client@example.invalid','staff_email'=>null,'staff_name'=>null,'assignee_name'=>null,'assignee_email'=>null])];
        if (getenv('REASSIGNED'))
            return [array_replace($base,['staff_sees_client'=>0,'client_email'=>'client@example.invalid','staff_email'=>'former@example.invalid','staff_name'=>'Former staff','assignee_name'=>'Current staff','assignee_email'=>getenv('ASSIGNEE_EMAIL') ?: null,'location'=>'Synthetic home address'])];
        switch (getenv('SCENARIO')) {
            // A client closed or archived since the session was booked.
            case 'closed': return [array_replace($base,['client_current'=>0])];
            // Remote sessions: one with an https join link, one whose link has another scheme.
            case 'remote': return [array_replace($base,['is_remote'=>1,'meeting_url'=>'https://example.invalid/m','client_email'=>'remote1@example.invalid']),
                array_replace($base,['id'=>2,'client_id'=>6,'is_remote'=>1,'meeting_url'=>'javascript:alert(1)','client_email'=>'remote2@example.invalid'])];
            // A client email copied from the public referral form and not yet confirmed.
            case 'unconfirmed': return [array_replace($base,['client_email_verified'=>0])];
            // A session later today: booked after the last run, or moved to this instructor.
            case 'today': return [array_replace($base,['start_at'=>date('Y-m-d').' 23:59:00'])];
        }
        return [$base];
    }
    function execute($args) { $GLOBALS['marks']++; }
}
// The per-recipient columns (4.27.0) are reported missing, so this checks the whole-session
// fallback; tests/v427_portal_cases.php checks per-recipient reminders on a real database.
class FakeDB { function query($sql) { if (str_contains($sql, 'client_reminded_at')) throw new Exception('no column'); return new FakeResult; } function prepare($sql) { return new FakeResult; } }
function db() { return new FakeDB; }
function cfg($key) { return 'https://example.invalid'; }
// The built-in wording says "tomorrow"; {day} and the other placeholders are shown so the test can read them.
function email_template($name,$args) { return ['Reminder: your session tomorrow', "Hello {$args['name']}, see you tomorrow.\nWhen: {$args['when']}{$args['where']}\nDay: {$args['day']}\nJoin: {$args['join_link']}\n"]; }
function brand($key) { return 'Test'; }
function record_heartbeat($job) { $GLOBALS['heartbeats'][]=$job; }
function client_zone($c) { return null; }
function cms_number($k) { return ['session_reminder_days' => 1, 'at_reminder_days' => 7][$k] ?? 0; }
function sms_enabled() { return false; }
function zone_format($tz,$fn) { return $fn(); }
function zone_date($tz,$f,$ts) { return date($f,$ts); }
function send_app_mail($to,$subject,$body) { $GLOBALS['to'][]=$to; $GLOBALS['mails'][]=['to'=>$to,'subject'=>$subject,'body'=>$body]; $results=json_decode(getenv('MAIL_RESULTS'),true); return $results[$GLOBALS['calls']++]; }
function send_app_mail_outcome($to,$subject,$body) { return send_app_mail($to,$subject,$body) ? 'sent' : 'refused'; }
function notify_admins($subject,$body) { $GLOBALS['admin'][]=$subject; $results=json_decode(getenv('MAIL_RESULTS'),true); return $results[$GLOBALS['calls']++]; }
function notify_admin_users($message,$link=null) { $GLOBALS['notices'][]=$message; $GLOBALS['links'][]=$link; }
register_shutdown_function(function() { echo json_encode(['marks'=>$GLOBALS['marks'],'calls'=>$GLOBALS['calls'],'admin'=>$GLOBALS['admin'],'notices'=>$GLOBALS['notices'],'links'=>$GLOBALS['links'],'to'=>$GLOBALS['to'],'mails'=>$GLOBALS['mails']]); });
''')
    def reminders(results, owner_inactive=False, reassigned=False, assignee='', client_email='', scenario=''):
        env = {**os.environ, 'MAIL_RESULTS': json.dumps(results), 'OWNER_INACTIVE': '1' if owner_inactive else '',
               'REASSIGNED': '1' if reassigned else '', 'ASSIGNEE_EMAIL': assignee, 'CLIENT_EMAIL': client_email, 'SCENARIO': scenario}
        run = subprocess.run(['php', str(path / 'reminders.php')], env=env, capture_output=True, text=True)
        assert run.stderr == '', run.stderr
        return run.returncode, json.loads(run.stdout[run.stdout.index('{'):])
    for results in ([False, True], [True, False], [False, False], [True, True]):
        code, state = reminders(results)
        assert state['calls'] == 2 and state['marks'] == int(all(results)) and state['admin'] == []
        assert (code == 0) == all(results)
        assert len(state['notices']) == (0 if all(results) else 1)
        # The alert names the client, so it links to that client's record, which the
        # retention purge matches (never the bare Schedule page, which it does not).
        assert state['links'] == ([] if all(results) else ['client.php?id=5'])
    print('PASS: Both reminder recipients are attempted; a failed reminder is left unmarked, reported to administrators against the client\'s record and exits non-zero')
    for results in ([True, True], [True, False]):
        code, state = reminders(results, owner_inactive=True)
        assert state['calls'] == 2 and len(state['admin']) == 1 and 'deactivated' in state['admin'][0]
        assert state['marks'] == int(all(results)) and (code == 0) == all(results)
    print('PASS: A session owned by a deactivated account still reminds the client and asks administrators to reassign it')
    code, state = reminders([True, True], reassigned=True, assignee='current@example.invalid')
    assert code == 0 and state['marks'] == 1 and state['admin'] == []
    assert state['to'] == ['client@example.invalid', 'current@example.invalid'] and 'former@example.invalid' not in state['to']
    code, state = reminders([True, True], reassigned=True)
    assert code == 0 and state['marks'] == 1 and len(state['admin']) == 1 and 'former@example.invalid' not in state['to']
    print('PASS: After a reassignment the former caseload-limited instructor is not emailed; the current case worker (or the administrators) is')
    code, state = reminders([True], client_email='not an address')
    assert state['to'] == ['staff@example.invalid'] and state['marks'] == 0 and code != 0
    assert len(state['notices']) == 1 and 'not valid' in state['notices'][0] and state['links'] == ['client.php?id=5']
    print('PASS: An invalid client address is skipped, the instructor is still reminded, and the session is left unmarked and reported')
    code, state = reminders([True], scenario='closed')
    assert code == 0 and state['marks'] == 1 and state['to'] == ['staff@example.invalid']
    assert 'closed or archived' in state['mails'][0]['body'] and state['notices'] == []
    print('PASS: A client closed or archived since the booking is not emailed; the instructor is still reminded and told why')
    code, state = reminders([True] * 4, scenario='remote')
    sent_to = {m['to']: m['body'] for m in state['mails']}
    assert code == 0 and state['marks'] == 2 and set(sent_to) == {'remote1@example.invalid', 'remote2@example.invalid', 'staff@example.invalid'}
    assert 'Join link: https://example.invalid/m' in sent_to['remote1@example.invalid'] and 'Join: https://example.invalid/m' in sent_to['remote1@example.invalid']
    assert 'Where: online' in sent_to['remote2@example.invalid'] and not any('javascript:' in m['body'] for m in state['mails'])
    print('PASS: A remote session reminder carries its https join link; a link with any other scheme is never sent')
    code, state = reminders([True], scenario='unconfirmed')
    assert code == 0 and state['marks'] == 1 and state['to'] == ['staff@example.invalid']
    assert 'has not been confirmed' in state['mails'][0]['body']
    assert len(state['notices']) == 1 and 'not confirmed' in state['notices'][0] and state['links'] == ['client.php?id=5']
    print('PASS: A client email from the public referral form that staff have not confirmed is not emailed; the instructor and the administrators are told')
    code, state = reminders([True, True], scenario='today')
    client_mail, staff_mail = state['mails']
    assert code == 0 and state['marks'] == 1 and client_mail['to'] == 'client@example.invalid'
    assert 'today' in client_mail['subject'] and 'tomorrow' not in (client_mail['subject'] + client_mail['body']).lower() and 'Day: today' in client_mail['body']
    assert staff_mail['subject'].startswith('Today: ')
    print("PASS: A session later today (booked after the last run) is reminded as today's, never called tomorrow's")

    source = (root / 'cron/ingest_email.php').read_text()
    start = source.index('foreach ([')
    end = source.index('$g = graph_cfg();', start)
    source = source[:start] + "require __DIR__ . '/ingest_fixture.php';\n" + source[end:]
    (path / 'ingest.php').write_text(source)
    (path / 'ingest_fixture.php').write_text(r'''<?php
// The application's own time zone, as inc/core.php sets it.
date_default_timezone_set('America/Chicago');
$events=[];
class FakeIngestResult {
    function fetchAll() { $clients = [['id'=>1,'name'=>'Synthetic Client','email'=>'client@example.invalid','guardian_email'=>null,'counselor_email'=>'counselor@example.invalid','counselor_verified'=>1,'assigned_to'=>7,'archived_at'=>null],
                                  ['id'=>2,'name'=>'Other Person','email'=>null,'guardian_email'=>null,'counselor_email'=>'counselor@example.invalid','counselor_verified'=>1,'assigned_to'=>null,'archived_at'=>null],
                                  ['id'=>3,'name'=>'Former Client','email'=>'client@example.invalid','guardian_email'=>null,'counselor_email'=>null,'counselor_verified'=>1,'assigned_to'=>7,'archived_at'=>'2025-01-01 00:00:00'],
                                  // A counselor address typed on the public referral form, not yet confirmed by staff.
                                  ['id'=>5,'name'=>'Referred Person','email'=>null,'guardian_email'=>null,'counselor_email'=>'unconfirmed@example.invalid','counselor_verified'=>0,'assigned_to'=>7,'archived_at'=>null]];
        if (getenv('DUPLICATE_NAME')) $clients[]=['id'=>4,'name'=>'Synthetic Client','email'=>'second@example.invalid','guardian_email'=>null,'counselor_email'=>'counselor@example.invalid','counselor_verified'=>1,'assigned_to'=>7,'archived_at'=>null];
        return $clients;
    }
    function execute($args) {
        $GLOBALS['events'][]=['insert',$args[4],$args[0],$args[3]];
        if (getenv('INSERT_FAIL')) throw new RuntimeException('Synthetic storage failure');
    }
    function rowCount() { return 1; }
}
class FakeIngestDB {
    // OWNER_INACTIVE: the client's case worker (user 7) has been deactivated.
    function query($sql) { return str_contains($sql,'FROM users') ? array_merge([['id'=>3,'email'=>'staff@example.invalid']], getenv('OWNER_INACTIVE') ? [] : [['id'=>7,'email'=>'worker@example.invalid']]) : new FakeIngestResult; }
    function prepare($sql) {
        if (!str_contains($sql,'ON DUPLICATE KEY UPDATE')) throw new RuntimeException('Missing replay protection');
        return new FakeIngestResult;
    }
}
function db() { return new FakeIngestDB; }
function graph_cfg() { return ['sender'=>'mailbox@example.invalid']; }
// Authorization emails (3.0.48) are covered by tests/authorization_email_cases.php; none here is one.
function authorization_email_parse($text) { return str_contains($text, 'Authorization Number:') ? ['auth_number'=>'X'] : null; }
function authorization_email_tracks($n) { return []; }
function record_heartbeat($job) { $GLOBALS['heartbeats'][]=$job; }
function notify_user($id,$message,$link=null) { $GLOBALS['events'][]=['notify',$id]; }
function notify_admin_users($message,$link=null) { $GLOBALS['events'][]=['notify','admins']; }
function graph_api($method,$url,$body=null,$headers=[]) {
    if (str_ends_with($url,'/move')) { $GLOBALS['events'][]=['move',$body['destinationId']]; return [(int)(getenv('MOVE_CODE') ?: 500),[]]; }
    if ($method==='POST' && str_ends_with($url,'/mailFolders')) return [201,['id'=>'review-folder','displayName'=>$body['displayName']]];
    if (str_contains($url,'/inbox/')) {
        $GLOBALS['events'][]=['prefer',implode(' ',$headers)];
        $internal=[['name'=>'X-MS-Exchange-Organization-AuthAs','value'=>'Internal']];
        $passed=[['name'=>'Authentication-Results','value'=>'spf=pass smtp.mailfrom=example.invalid; dkim=pass header.d=example.invalid;dmarc=pass action=none header.from=example.invalid;compauth=pass reason=100']];
        $outOfOffice=array_merge($passed,[['name'=>'Auto-Submitted','value'=>'auto-replied']]);
        $forged=[['name'=>'Authentication-Results','value'=>'spf=fail smtp.mailfrom=example.invalid; dkim=none header.d=none;dmarc=fail action=none header.from=example.invalid;compauth=fail reason=000'],
                 ['name'=>'Authentication-Results','value'=>'dmarc=pass header.from=example.invalid']];
        $message=match (getenv('SCENARIO') ?: 'staff') {
            'staff' => ['subject'=>'Synthetic Client progress','from'=>'staff@example.invalid','headers'=>$internal,'body'=>['contentType'=>'text','content'=>'Synthetic note']],
            'staff_archived' => ['subject'=>'Former Client progress','from'=>'staff@example.invalid','headers'=>$internal,'body'=>['contentType'=>'text','content'=>'Synthetic note']],
            'staff_two_names' => ['subject'=>'Synthetic Client and Other Person','from'=>'staff@example.invalid','headers'=>$internal,'body'=>['contentType'=>'text','content'=>'Synthetic note']],
            'client_named' => ['subject'=>'Synthetic Client progress','from'=>'client@example.invalid','headers'=>$passed,'body'=>['contentType'=>'text','content'=>'Synthetic note']],
            'forged_staff' => ['subject'=>'Synthetic Client progress','from'=>'staff@example.invalid','headers'=>$forged,'body'=>['contentType'=>'text','content'=>'Forged note']],
            'no_headers' => ['subject'=>'Synthetic Client progress','from'=>'staff@example.invalid','headers'=>[],'body'=>['contentType'=>'text','content'=>'Unverifiable note']],
            'client_reply' => ['subject'=>'Re: Reminder: your session tomorrow','from'=>'client@example.invalid','headers'=>$passed,
                'body'=>['contentType'=>'html','content'=>'<html><head><style>P {margin-top:0;}</style></head><body><div>This is synthetic client. I need to move<br>tomorrow&#39;s session.</div><div>Vision is &lt;20/200 &mdash; ask &lt;jo@example.invalid&gt;</div></body></html>']],
            'client_no_name' => ['subject'=>'Re: Reminder: your session tomorrow','from'=>'client@example.invalid','headers'=>$passed,'body'=>['contentType'=>'text','content'=>'I need to move it.']],
            'out_of_office' => ['subject'=>'Automatic reply: Synthetic Client report','from'=>'client@example.invalid','headers'=>$outOfOffice,'body'=>['contentType'=>'text','content'=>'I am out of the office. Synthetic Client']],
            'shared_counselor' => ['subject'=>'Re: progress','from'=>'counselor@example.invalid','headers'=>$passed,'body'=>['contentType'=>'text','content'=>'Synthetic Client is doing well.']],
            'unconfirmed_counselor' => ['subject'=>'Referred Person progress','from'=>'unconfirmed@example.invalid','headers'=>$passed,'body'=>['contentType'=>'text','content'=>'Referred Person is doing well.']],
            'long' => ['subject'=>'Synthetic Client long thread','from'=>'staff@example.invalid','headers'=>$internal,'body'=>['contentType'=>'text','content'=>str_repeat("\u{2019}ab",30000)]],
            'attachment' => ['subject'=>'Synthetic Client authorization','from'=>'staff@example.invalid','headers'=>$internal,'body'=>['contentType'=>'text','content'=>'Please see the attached letter.'],'attachments'=>true],
        };
        return [200,['value'=>[['id'=>'stable-message','subject'=>$message['subject'],'receivedDateTime'=>'2026-10-09T23:40:00Z','from'=>['emailAddress'=>['address'=>$message['from'],'name'=>'Synthetic sender']],
            'internetMessageHeaders'=>$message['headers'],'body'=>$message['body'],'hasAttachments'=>$message['attachments'] ?? false]]]];
    }
    return [200,['value'=>[['id'=>'folder','displayName'=>'Filed to CMS']]]];
}
register_shutdown_function(function() { echo "\nEVENTS:" . json_encode($GLOBALS['events']); });
''')
    def ingest(scenario='staff', **extra):
        result = subprocess.run(['php', str(path / 'ingest.php')], env={**os.environ, 'SCENARIO': scenario, 'INSERT_FAIL': '', **extra}, capture_output=True, text=True)
        events = json.loads(result.stdout.split('EVENTS:')[1])
        assert events[0] == ['prefer', 'Prefer: outlook.body-content-type="text"']
        return result, events[1:]
    keys = []
    for fail in ('', '', '1'):
        result, events = ingest(INSERT_FAIL=fail)
        assert [e[0] for e in events] == (['insert'] if fail else ['insert', 'move'])
        assert (result.returncode != 0) == bool(fail)
        keys.append(events[0][1])
    assert len(set(keys)) == 1 and len(keys[0]) == 36
    print('PASS: Email is saved before moving, storage failures prevent moves, and retries reuse a stable key')
    for scenario in ('forged_staff', 'no_headers'):
        result, events = ingest(scenario)
        assert events == [] and result.returncode == 0 and 'could not verify the sender' in result.stdout
    print('PASS: A From address Microsoft 365 did not authenticate is never filed (a forged later header is ignored)')
    result, events = ingest('client_reply', MOVE_CODE='200')
    assert [e[0] for e in events] == ['insert', 'notify', 'move'] and events[0][2] == 1 and events[1] == ['notify', 7]
    assert events[2] == ['move', 'folder']
    body = events[0][3]
    assert 'margin-top' not in body and "I need to move\ntomorrow's session." in body
    assert 'Vision is <20/200' in body and '<jo@example.invalid>' in body
    print('PASS: A verified reply from the only client at that address that names the client in the body is filed, as readable text, and the case worker is told')
    assert 'Received: Oct 9, 2026 6:40 PM CDT' in body and 'T23:40:00Z' not in body
    print('PASS: The received time of a filed email is shown in the application time zone, not raw UTC')
    result, events = ingest('client_reply', MOVE_CODE='200', OWNER_INACTIVE='1')
    assert [e[0] for e in events] == ['insert', 'notify', 'move'] and events[1] == ['notify', 'admins']
    print('PASS: When the case worker has been deactivated, the administrators are told instead')
    for scenario in ('client_no_name', 'shared_counselor'):
        result, events = ingest(scenario, MOVE_CODE='200')
        assert events == [['move', 'review-folder'], ['notify', 'admins']] and result.returncode == 0
        result, events = ingest(scenario)
        assert events == [['move', 'review-folder']] and result.returncode == 0
    print('PASS: Client-contact mail that names no client (or comes from an address several clients share) is never filed by address; it goes to review and administrators are told once')
    result, events = ingest('out_of_office', MOVE_CODE='200')
    assert events == [] and result.returncode == 0 and 'automatic replies' in result.stdout
    print('PASS: An automatic reply is never filed as a case note')
    result, events = ingest('long', MOVE_CODE='200')
    body = events[0][3]
    assert [e[0] for e in events] == ['insert', 'move'] and len(body.encode('utf-8')) <= 65535
    assert body.endswith('mailbox folder.]') and body.encode('utf-8').decode('utf-8') == body
    print('PASS: A long multi-byte email is cut by bytes to fit the note and says it was shortened')

    for scenario, extra in [('staff', {'DUPLICATE_NAME':'1'}), ('staff_two_names', {}), ('staff_archived', {})]:
        result, events = ingest(scenario, MOVE_CODE='200', **extra)
        assert events == [['move', 'review-folder'], ['notify', 'admins']] and result.returncode == 0
    print('PASS: Duplicate names, multi-client subjects and archived-client staff mail go to review without a case-note insert')
    result, events = ingest('client_named', MOVE_CODE='200', DUPLICATE_NAME='1')
    assert [e[0] for e in events] == ['insert', 'notify', 'move'] and events[0][2] == 1
    print('PASS: A verified contact associated with only one of two same-name clients files to their own record')
    result, events = ingest('unconfirmed_counselor', MOVE_CODE='200')
    assert events == [['move', 'review-folder'], ['notify', 'admins']] and result.returncode == 0
    print('PASS: Mail from a counselor address staff have not confirmed is never filed; it goes to review')
    result, events = ingest('attachment', MOVE_CODE='200')
    assert [e[0] for e in events] == ['insert', 'move'] and 'had attachments' in events[0][3] and events[0][3].endswith('"Filed to CMS" mailbox folder.]')
    result, events = ingest('staff', MOVE_CODE='200')
    assert 'attachments' not in events[0][3]
    print('PASS: A filed email that had attachments says so in its note, and where they are; one without says nothing')

    # Weekly off-site backup: settings from the environment, mail recorded (never sent).
    # Each attachment is opened here as the backup contact would: it must be an
    # AES-256 ZIP that cannot be read without the password.
    source = (root / 'cron/weekly_offsite.php').read_text()
    start = source.index('foreach ([')
    end = source.index('$dir = dirname(', start)
    (path / 'offsite.php').write_text(source[:start] + "require __DIR__ . '/offsite_fixture.php';\n" + source[end:])
    (path / 'offsite_fixture.php').write_text(r'''<?php
$sent=[];
function cfg($key) { return ['upload_dir'=>getenv('OFFSITE_UPLOADS'),'backup_email'=>'backup@example.invalid','backup_password'=>getenv('OFFSITE_PASSWORD') ?: null][$key] ?? null; }
function send_app_mail($to,$subject,$body) { $GLOBALS['sent'][]=['to'=>$to,'subject'=>$subject,'attachments'=>[]]; return getenv('NOTICE_FAILS') !== '1'; }
function send_app_mail_attach($to,$subject,$body,$attachments) {
    static $call=0;
    $record=['to'=>$to,'subject'=>$subject,'attachments'=>[]];
    foreach ($attachments as $a) {
        $file=tempnam(sys_get_temp_dir(),'cms-offsite-check-'); file_put_contents($file,$a['bytes']);
        $zip=new ZipArchive(); $info=['name'=>$a['name'],'entries'=>[]];
        if ($zip->open($file)===true) {
            for ($i=0;$i<$zip->numFiles;$i++) { $st=$zip->statIndex($i); $info['entries'][]=['name'=>$st['name'],'aes256'=>($st['encryption_method'] ?? 0)===ZipArchive::EM_AES_256]; }
            $info['readable_without_password']=@$zip->getFromIndex(0)!==false;
            $zip->setPassword((string)getenv('OFFSITE_PASSWORD'));
            $info['dump_sha1']=sha1((string)$zip->getFromIndex(0));
            $zip->close();
        }
        unlink($file); $record['attachments'][]=$info;
    }
    $GLOBALS['sent'][]=$record;
    $results=json_decode(getenv('ATTACH_RESULTS') ?: '[true]',true);
    return $results[$call++] ?? true;
}
function notify_admins($subject,$body) { $GLOBALS['sent'][]=['to'=>'administrators','subject'=>$subject,'attachments'=>[]]; return true; }
function record_heartbeat($job) { $GLOBALS['heartbeats'][]=$job; }
function graph_cfg() { return null; }
// 3.0.53: the daily run keeps when the last full copy went, and checks the backup after it.
function setting($k) { return $k === 'offsite_full_at' ? (getenv('OFFSITE_LAST_FULL') ?: null) : null; }
function set_setting($k, $v) { $GLOBALS['settings'][$k]=$v; }
function backup_check() { return ['ok'=>true,'detail'=>'checked']; }
register_shutdown_function(function() { echo "\nSENT:" . json_encode($GLOBALS['sent']); });
''')
    private = path / 'offsite_private'
    (private / 'uploads').mkdir(parents=True)
    (private / 'backups').mkdir()
    (private / 'uploads' / 'synthetic-document.txt').write_text('Synthetic uploaded document')
    dump = private / 'backups' / 'cms_20260101_021500.sql.gz'
    dump.write_bytes(gzip.compress(b'synthetic SQL dump'))
    def offsite(password, **extra):
        env = {**os.environ, 'OFFSITE_UPLOADS': str(private / 'uploads'), 'OFFSITE_PASSWORD': password, 'NOTICE_FAILS': '', 'ATTACH_RESULTS': '', **extra}
        run = subprocess.run(['php', str(path / 'offsite.php')], env=env, capture_output=True, text=True)
        output, sent = run.stdout.split('SENT:')
        return run.returncode, output, json.loads(sent)
    code, output, sent = offsite('')
    assert code == 1 and 'Notice sent.' in output and len(sent) == 1 and sent[0]['attachments'] == [] and 'NOT sent' in sent[0]['subject']
    code, output, sent = offsite('', NOTICE_FAILS='1')
    assert code == 1 and 'Notice sent' not in output and 'could NOT be emailed' in output
    print('PASS: Without a backup password only a notice is sent, never the dump, and the run says whether the notice itself went out')
    code, output, sent = offsite('synthetic-backup-pass')
    attached = [a for m in sent for a in m['attachments']]
    assert code == 0 and len(attached) == 1 and attached[0]['name'].endswith('_with_uploads.zip')
    assert attached[0]['entries'] and all(e['aes256'] for e in attached[0]['entries']) and not attached[0]['readable_without_password']
    assert attached[0]['dump_sha1'] == hashlib.sha1(dump.read_bytes()).hexdigest()
    assert {e['name'] for e in attached[0]['entries']} == {dump.name, 'uploads/synthetic-document.txt'}
    assert sorted(p.name for p in (private / 'backups').iterdir()) == [dump.name]
    print('PASS: The off-site backup is one AES-256 ZIP of the database and the uploads, unreadable without the password, and leaves no temporary archive')
    code, output, sent = offsite('synthetic-backup-pass', ATTACH_RESULTS='[false, true]')
    attached = [a for m in sent for a in m['attachments']]
    assert code == 0 and [a['name'] for a in attached] == ['cms_20260101_021500_with_uploads.zip', dump.name + '.zip']
    assert [e['name'] for e in attached[1]['entries']] == [dump.name] and all(e['aes256'] for e in attached[1]['entries'])
    assert any(m['to'] == 'administrators' and 'not included' in m['subject'] for m in sent)
    print('PASS: When the archive with the uploads cannot be sent, the encrypted database goes on its own and the administrators are warned')
    # The newest "dump" cannot be read into an archive, so encryption fails both
    # times: only the notice goes out, never a plaintext attachment.
    unreadable = private / 'backups' / 'cms_20991231_021500.sql.gz'
    unreadable.mkdir()
    code, output, sent = offsite('synthetic-backup-pass')
    unreadable.rmdir()
    assert code == 1 and 'encryption failed' in output and 'Notice sent.' in output
    assert all(m['attachments'] == [] for m in sent) and any('could not be encrypted' in m['subject'] for m in sent)
    assert sorted(p.name for p in (private / 'backups').iterdir()) == [dump.name]
    print('PASS: When the archive cannot be encrypted nothing is attached; only the notice is sent and the run fails')

    # Watchdog: the site check fails here (a file:// address, so nothing leaves the
    # machine); the checks below follow the backup alert.
    source = (root / 'cron/watchdog.php').read_text()
    start = source.index('foreach ([')
    end = source.index('$privateDir =', start)
    (path / 'watchdog.php').write_text(source[:start] + "require __DIR__ . '/watchdog_fixture.php';\n" + source[end:])
    (path / 'watchdog_fixture.php').write_text(r'''<?php
$sent=[];
function cfg($key) { return ['upload_dir'=>getenv('WATCH_UPLOADS'),'base_url'=>'file:///nonexistent-cms-watchdog','backup_email'=>'backup@example.invalid'][$key] ?? null; }
class FakeWatchDB { function query($sql) { if (getenv('DB_DOWN')) throw new RuntimeException('Database connection failed (2002)'); return true; } }
function db() { return new FakeWatchDB; }
function send_app_mail($to,$subject,$body) { $GLOBALS['sent'][]=$subject; return true; }
function set_setting($name,$value) { if (getenv('DB_DOWN')) throw new RuntimeException('Database connection failed (2002)'); $GLOBALS['settings'][$name]=$value; }
register_shutdown_function(function() { echo "\nSENT:" . json_encode($GLOBALS['sent']) . "\nSETTINGS:" . json_encode($GLOBALS['settings'] ?? []); });
''')
    watch = path / 'watch_private'
    (watch / 'uploads').mkdir(parents=True)
    (watch / 'backups').mkdir()
    old_dump = watch / 'backups' / 'cms_20260101_021500.sql.gz'
    old_dump.write_bytes(gzip.compress(b'synthetic SQL dump'))
    stale = time.time() - 72 * 3600
    os.utime(old_dump, (stale, stale))
    def watchdog(**extra):
        env = {**os.environ, 'WATCH_UPLOADS': str(watch / 'uploads'), 'DB_DOWN': '', **extra}
        run = subprocess.run(['php', str(path / 'watchdog.php')], env=env, capture_output=True, text=True)
        output, rest = run.stdout.split('SENT:')
        sent, settings = rest.split('SETTINGS:')
        return output, json.loads(sent), json.loads((watch / 'watchdog_state.json').read_text()), json.loads(settings)
    output, sent, state, settings = watchdog()
    assert 'CMS health alert: backup' in sent and 'backup' in state
    # The heartbeat health.php reads is written on every completed run, however many
    # alerts went out, as an ISO 8601 time; a run whose database is down writes none.
    assert re.match(r'^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$', settings.get('heartbeat_watchdog', ''))
    output, sent, state, settings = watchdog()
    assert 'CMS health alert: backup' not in sent and 'backup' in state
    os.utime(old_dump, None)
    output, sent, state, settings = watchdog()
    assert 'backup' not in state and 'No database backup' not in output
    output, sent, state, settings = watchdog(DB_DOWN='1')
    assert 'CMS health alert: db' in sent and 'heartbeat_watchdog' not in settings
    print('PASS: The watchdog alerts once about a stale backup (not again within six hours), clears it when a fresh one appears, reports an unreachable database, and writes its heartbeat only when the database answered')

    # Scheduled report periods and recipients (inc/scheduled_reports.php): pure
    # functions, run with a stub for the one formatter they use.
    (path / 'periods.php').write_text(r'''<?php
function fmt_date($d) { return date('M j, Y', strtotime($d)); }
require getenv('CMS_ROOT') . '/public/inc/scheduled_reports.php';
$out = [];
foreach ([['last_month','2026-10-15'],['last_month','2026-01-03'],['last_quarter','2026-10-01'],['last_quarter','2026-11-28'],['last_quarter','2026-02-10']] as [$p,$d]) $out[] = scheduled_report_period($p, $d);
$out[] = scheduled_reports_parse_recipients("Director@agency.org, not-an-address\nfinance@agency.org;director@agency.org");
echo json_encode($out);
''')
    run = subprocess.run(['php', str(path / 'periods.php')], env={**os.environ, 'CMS_ROOT': str(root)}, capture_output=True, text=True)
    assert run.returncode == 0, run.stderr
    periods = json.loads(run.stdout)
    assert [(p['from'], p['to'], p['stamp']) for p in periods[:5]] == [
        ('2026-09-01', '2026-09-30', '2026-09'), ('2025-12-01', '2025-12-31', '2025-12'),
        ('2026-07-01', '2026-09-30', '2026-Q3'), ('2026-07-01', '2026-09-30', '2026-Q3'), ('2025-10-01', '2025-12-31', '2025-Q4')]
    assert periods[5] == {'valid': ['director@agency.org', 'finance@agency.org'], 'invalid': ['not-an-address']}
    print('PASS: Scheduled reports cover the whole previous month or quarter, with one stamp per period, and recipients are validated and de-duplicated')
