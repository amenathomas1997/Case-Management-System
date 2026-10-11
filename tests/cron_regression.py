"""Exercise backup failure handling and reminder delivery using local test doubles."""
from pathlib import Path
import gzip
import json
import os
import subprocess
import tempfile

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
$marks=0; $calls=0; $admin=[]; $notices=[]; $to=[];
class FakeResult {
    function fetchAll() {
        // OWNER_INACTIVE: the session's owner was deactivated, so the active-staff join found nobody.
        // REASSIGNED: the owner is active but limited to their own caseload, and the
        // client now belongs to someone else (ASSIGNEE_EMAIL, or nobody when empty).
        if (getenv('OWNER_INACTIVE'))
            return [['id'=>1,'user_id'=>2,'active_staff_id'=>null,'staff_sees_client'=>0,'client_name'=>'Synthetic client','client_email'=>'client@example.invalid','staff_email'=>null,'staff_name'=>null,'assignee_name'=>null,'assignee_email'=>null,'start_at'=>'2026-01-01 09:00:00','location'=>null]];
        if (getenv('REASSIGNED'))
            return [['id'=>1,'user_id'=>2,'active_staff_id'=>2,'staff_sees_client'=>0,'client_name'=>'Synthetic client','client_email'=>'client@example.invalid','staff_email'=>'former@example.invalid','staff_name'=>'Former staff','assignee_name'=>'Current staff','assignee_email'=>getenv('ASSIGNEE_EMAIL') ?: null,'start_at'=>'2026-01-01 09:00:00','location'=>'Synthetic home address']];
        return [['id'=>1,'user_id'=>2,'active_staff_id'=>2,'staff_sees_client'=>1,'client_name'=>'Synthetic client','client_email'=>getenv('CLIENT_EMAIL') ?: 'client@example.invalid','staff_email'=>'staff@example.invalid','staff_name'=>'Synthetic staff','assignee_name'=>'Synthetic staff','assignee_email'=>'staff@example.invalid','start_at'=>'2026-01-01 09:00:00','location'=>null]];
    }
    function execute($args) { $GLOBALS['marks']++; }
}
class FakeDB { function query($sql) { return new FakeResult; } function prepare($sql) { return new FakeResult; } }
function db() { return new FakeDB; }
function cfg($key) { return 'https://example.invalid'; }
function email_template($name,$args) { return ['test','synthetic reminder']; }
function brand($key) { return 'Test'; }
function send_app_mail($to,$subject,$body) { $GLOBALS['to'][]=$to; $results=json_decode(getenv('MAIL_RESULTS'),true); return $results[$GLOBALS['calls']++]; }
function notify_admins($subject,$body) { $GLOBALS['admin'][]=$subject; $results=json_decode(getenv('MAIL_RESULTS'),true); return $results[$GLOBALS['calls']++]; }
function notify_admin_users($message,$link=null) { $GLOBALS['notices'][]=$message; }
register_shutdown_function(function() { echo json_encode(['marks'=>$GLOBALS['marks'],'calls'=>$GLOBALS['calls'],'admin'=>$GLOBALS['admin'],'notices'=>$GLOBALS['notices'],'to'=>$GLOBALS['to']]); });
''')
    def reminders(results, owner_inactive=False, reassigned=False, assignee='', client_email=''):
        env = {**os.environ, 'MAIL_RESULTS': json.dumps(results), 'OWNER_INACTIVE': '1' if owner_inactive else '',
               'REASSIGNED': '1' if reassigned else '', 'ASSIGNEE_EMAIL': assignee, 'CLIENT_EMAIL': client_email}
        run = subprocess.run(['php', str(path / 'reminders.php')], env=env, capture_output=True, text=True)
        return run.returncode, json.loads(run.stdout[run.stdout.index('{'):])
    for results in ([False, True], [True, False], [False, False], [True, True]):
        code, state = reminders(results)
        assert state['calls'] == 2 and state['marks'] == int(all(results)) and state['admin'] == []
        assert (code == 0) == all(results)
        assert len(state['notices']) == (0 if all(results) else 1)
    print('PASS: Both reminder recipients are attempted; a failed reminder is left unmarked, reported to administrators and exits non-zero')
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
    assert len(state['notices']) == 1 and 'not valid' in state['notices'][0]
    print('PASS: An invalid client address is skipped, the instructor is still reminded, and the session is left unmarked and reported')

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
        };
        return [200,['value'=>[['id'=>'stable-message','subject'=>$message['subject'],'receivedDateTime'=>'2026-10-09T23:40:00Z','from'=>['emailAddress'=>['address'=>$message['from'],'name'=>'Synthetic sender']],
            'internetMessageHeaders'=>$message['headers'],'body'=>$message['body']]]]];
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
