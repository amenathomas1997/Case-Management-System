<?php
// Included by full_schema.php after v437_cases.php, against its synthetic database only:
// 4.38.0, billing and administration in the apps (plan-4380 T1-T16, the server parts). Each block
// is one item. Every request goes through api.php's own input parsing, with its HTTP status, and
// each helper the website pages now share is checked through its page too. Every money figure the
// API sends is compared with what the website page (or its CSV) shows for the same rows, formatted
// with fmt_money(). Mail goes to a sink folder (the staging copy's "mail off") and Zoom is answered
// by CMS_TEST_MEETINGS. Synthetic people and records only; every setting, group and the temporary
// core this file changes are put back at its end.
$GLOBALS['CURRENT_PERMS'] = array_keys(PERMISSION_KEYS); $GLOBALS['CURRENT_SCOPED'] = false;
$db->exec('UPDATE users SET group_id=NULL WHERE id=1');
$settings438 = $db->query('SELECT name, value FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR);
$db->exec("DELETE FROM login_attempts");
putenv('CMS_TEST_SESSION_ADD=' . json_encode(['credential_version' => credential_version($db->query('SELECT * FROM users WHERE id=1')->fetch())]));
$adminName438 = (string)$db->query('SELECT name FROM users WHERE id=1')->fetchColumn();
$key438 = fn(): string => 'v438-' . bin2hex(random_bytes(8));
// Audit entries written by this file only.
$auditFrom438 = (int)$db->query('SELECT COALESCE(MAX(id), 0) FROM audit_log')->fetchColumn();
$audits438 = fn(string $action, string $like = '%'): int => (int)$db->query('SELECT COUNT(*) FROM audit_log WHERE id > ' . $auditFrom438 . ' AND action=' . $db->quote($action) . ' AND detail LIKE ' . $db->quote($like))->fetchColumn();
$one438 = fn(string $sql) => $db->query($sql)->fetchColumn();

// The mail sink, in the temporary core every request and page loads.
$originalCore438 = file_get_contents($tmp . '/core.php');
$sink438 = $tmp . '/mail438'; @mkdir($sink438, 0700);
file_put_contents($tmp . '/core.php', swap_required("\n\$GLOBALS['CMS_PRIVATE_DIR'] = ", "\n\$CONFIG['mail_disabled'] = true; \$CONFIG['mail_sink'] = " . var_export($sink438, true) . ";\n\$GLOBALS['CMS_PRIVATE_DIR'] = ", $originalCore438, 'the temporary core.php (mail sink)'));
$mails438 = function () use ($sink438): array { $f = glob($sink438 . '/*.txt') ?: []; sort($f); return array_map('file_get_contents', $f); };
$clearMail438 = function () use ($sink438): void { foreach (glob($sink438 . '/*.txt') ?: [] as $f) @unlink($f); };

// Any request with its HTTP status.
file_put_contents($tmp . '/v438_req.php', '<?php register_shutdown_function(function () { fwrite(STDERR, "\nV438STATUS:" . (int)http_response_code()); }); $_GET = json_decode($argv[1], true); $_SERVER["HTTP_AUTHORIZATION"] = "Bearer " . str_repeat("a", 64); $_SERVER["REQUEST_METHOD"] = $argv[2]; require ' . var_export($tmp . '/api.php', true) . ';');
$req438 = function (string $method, string $action, array $body = [], array $query = []) use ($tmp): array {
    putenv('CMS_TEST_INPUT=' . json_encode($body === [] ? new stdClass() : $body));
    $p = proc_open([PHP_BINARY, '-d', 'sendmail_path=/usr/bin/true', $tmp . '/v438_req.php', json_encode(['action' => $action] + $query), $method], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); proc_close($p);
    $status = preg_match('/V438STATUS:(\d+)/', $err, $m) ? (int)$m[1] : 0;
    $d = json_decode($out, true);
    if (!is_array($d)) throw new RuntimeException("$method $action: $err $out");
    if (($d['error'] ?? '') === 'Something went wrong on the server. Nothing was saved.') throw new RuntimeException("$method $action crashed: $err");
    return [$status, $d];
};
$get438 = function (string $action, array $query = []) use ($req438): array {
    [$st, $d] = $req438('GET', $action, [], $query);
    if ($st !== 200 || empty($d['ok'])) throw new RuntimeException("GET $action: $st " . json_encode($d));
    return $d;
};
$post438 = function (string $action, array $body = []) use ($req438): array {
    [$st, $d] = $req438('POST', $action, $body);
    if ($st !== 200 || empty($d['ok'])) throw new RuntimeException("POST $action: $st " . json_encode($d));
    return $d;
};
$page438 = function (string $name, array $query, ?array $post = null) use ($renderPage, $tmp): array {
    foreach (glob($tmp . '/sess_*') ?: [] as $f) @unlink($f);
    $res = $renderPage($name, $query, $post);
    // A page may never print a warning or error while it does what this file checks.
    if ($res[2] !== '') throw new RuntimeException("$name.php wrote to stderr: " . $res[2]);
    return $res;
};
$flash438 = function () use ($tmp): string {
    $files = glob($tmp . '/sess_*') ?: [];
    usort($files, fn($a, $b) => filemtime($b) <=> filemtime($a) ?: strcmp($b, $a));
    return $files ? (string)file_get_contents($files[0]) : '';
};
$group438 = function (string $name, string $perms, int $scoped = 0) use ($db): int { $db->prepare('INSERT INTO `groups` (name, perms, own_caseload_only) VALUES (?,?,?)')->execute([$name, $perms, $scoped]); return (int)$db->lastInsertId(); };
$gScoped438 = $group438('V438 scoped', 'clients;schedule;billing;analytics;reports', 1);
$gNoBilling438 = $group438('V438 no billing', 'clients;schedule;analytics');
$gNoClients438 = $group438('V438 no clients', 'billing;schedule');
$gStaff438 = $group438('V438 staff', 'clients;schedule;billing;analytics');
$as438 = fn(?int $g) => $db->exec('UPDATE users SET group_id=' . ($g === null ? 'NULL' : $g) . ' WHERE id=1');
$db->exec("INSERT INTO users (name,email,password_hash,role,weekly_hours) VALUES ('V438 Synthetic Instructor','v438-instructor@example.invalid','test','instructor',20)"); $u438 = (int)$db->lastInsertId();
$client438 = function (string $name, array $cols = []) use ($db): int {
    $cols = ['name' => $name] + $cols + ['status' => 'Active Training', 'assigned_to' => 1, 'state' => 'IA'];
    $db->prepare('INSERT INTO clients (' . implode(',', array_keys($cols)) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')')->execute(array_values($cols));
    return (int)$db->lastInsertId();
};
$track438 = function (int $client, array $cols = []) use ($db): int {
    $cols = $cols + ['client_id' => $client, 'title' => 'V438 synthetic track', 'auth_number' => 'V438-AUTH', 'funding_agency' => 'V438 Synthetic VR', 'authorized_hours' => 40, 'hourly_rate' => 50, 'status' => 'Active'];
    $db->prepare('INSERT INTO training_tracks (' . implode(',', array_keys($cols)) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')')->execute(array_values($cols));
    return (int)$db->lastInsertId();
};
$inv438 = function (int $track, string $number, array $cols = []) use ($db): int {
    $cols = ['track_id' => $track, 'invoice_number' => $number] + $cols + ['status' => 'Draft', 'hours' => 1, 'hours_amount' => 50, 'total_amount' => 50, 'bill_mode' => 'vr'];
    $db->prepare('INSERT INTO invoices (' . implode(',', array_keys($cols)) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')')->execute(array_values($cols));
    return (int)$db->lastInsertId();
};
// The invoice ids invoices.php lists for a query, and the ones the API sends.
$pageInvoiceIds438 = function (array $query) use ($page438): array {
    [, $out] = $page438('invoices', $query);
    preg_match_all('/<tr id="inv-(\d+)">/', $out, $m);
    $ids = array_map('intval', $m[1]); sort($ids); return $ids;
};
$apiInvoiceIds438 = function (array $query) use ($get438): array {
    $ids = array_map(fn($r) => (int)$r['id'], $get438('invoices', $query)['invoices']); sort($ids); return $ids;
};
$c438 = $client438('V438 Synthetic Client', ['email' => 'v438-client@example.invalid', 'email_verified' => 1]);
$cOther438 = $client438('V438 Other Caseload Client', ['assigned_to' => $u438]);
$t438 = $track438($c438);
$tOther438 = $track438($cOther438, ['title' => 'V438 other track', 'auth_number' => 'V438-OTHER']);

try {
/* ---- T1: the invoice list's row text, balance and paging ---- */
$today = date('Y-m-d');
$draft = $inv438($t438, 'V438-INV-001', ['created_at' => '2026-10-02 09:00:00']);
$sub = $inv438($t438, 'V438-INV-002', ['status' => 'Submitted', 'total_amount' => 1250.005, 'hours_amount' => 1250.005, 'paid_amount' => 100, 'written_off' => 0.01,
    'submitted_at' => date('Y-m-d', strtotime('-10 days')), 'due_date' => date('Y-m-d', strtotime('-3 days')), 'paid_at' => date('Y-m-d', strtotime('-2 days')), 'created_at' => '2026-10-05 09:00:00']);
$paid = $inv438($t438, 'V438-INV-003', ['status' => 'Paid', 'total_amount' => 300, 'paid_amount' => 300, 'submitted_at' => '2026-10-06', 'due_date' => '2026-11-05', 'paid_at' => '2026-10-20', 'created_at' => '2026-10-06 09:00:00']);
$zeroPaid = $inv438($t438, 'V438-INV-004', ['status' => 'Submitted', 'total_amount' => 80, 'paid_amount' => 0, 'submitted_at' => $today, 'due_date' => date('Y-m-d', strtotime('+30 days')), 'created_at' => '2026-11-01 09:00:00']);
$r = $get438('invoices', ['q' => 'V438-INV-']);
$row = fn(int $id) => array_values(array_filter($r['invoices'], fn($x) => $x['id'] === $id))[0];
$subRow = $db->query("SELECT i.*, DATEDIFF(CURDATE(), i.due_date) AS days_overdue FROM invoices i WHERE id=$sub")->fetch();
[, $pageOut] = $page438('invoices', ['id' => (string)$sub]);
// The page's own row for this invoice: its owed line is compared with the API's (not the helper with itself).
$subHtml = preg_match('~<tr id="inv-' . $sub . '">(.*?)</tr>~s', $pageOut, $rm) ? $rm[1] : '';
$pageOwed = preg_match('~<br><span class="muted">([^<]*owed[^<]*)</span>~', $subHtml, $om) ? html_entity_decode($om[1], ENT_QUOTES) : null;
check($row($draft)['balance'] == 0 && $row($draft)['owed_text'] === null && $row($draft)['short_paid'] === false, 'T1: a Draft has balance 0 and no owed text (the website\'s invoice_balance())');
check($row($sub)['owed_text'] === invoice_owed_text($subRow) && $row($sub)['owed_text'] === fmt_money(invoice_balance($subRow)) . ' owed (part-paid), 3 days past due'
    && $pageOwed !== null && $row($sub)['owed_text'] === $pageOwed && $row($sub)['days_overdue'] === 3 && $row($sub)['short_paid'] === true && $row($sub)['balance'] == invoice_balance($subRow),
    'T1: a part-paid, written-off, past-due invoice says "' . $row($sub)['owed_text'] . '", the same sentence invoices.php shows for it');
check(in_array('Due ' . fmt_date('2026-11-05'), $row($paid)['date_lines'], true) && $row($paid)['date_lines'][0] === 'Created ' . fmt_date('2026-10-06') && end($row($paid)['date_lines']) === 'Last payment ' . fmt_date('2026-10-20'),
    'T1: a Paid invoice\'s date lines include Due, in the page\'s order');
check($row($zeroPaid)['short_paid'] === false && !str_contains((string)$row($zeroPaid)['owed_text'], 'part-paid') && array_key_exists('days_outstanding', $row($zeroPaid)),
    'T1: paid_amount 0.00 stored is not part-paid (the page shows no "(part-paid)"); days_outstanding is still sent for 4.37.0 iPhones');
[, $pageAll] = $page438('invoices', ['q' => 'V438-INV-']);
check(str_contains($pageAll, 'Generate invoices for all unbilled work (' . fmt_money((float)$r['unbilled']) . ')'), 'T1: unbilled equals the page\'s figure (' . fmt_money((float)$r['unbilled']) . ')');
// Paging: 301 invoices under one prefix give two pages of 300 and 1.
$ins = $db->prepare("INSERT INTO invoices (track_id, invoice_number, status, hours, hours_amount, total_amount, bill_mode, created_at) VALUES (?,?,'Draft',1,10,10,'vr',?)");
for ($i = 1; $i <= 301; $i++) $ins->execute([$t438, sprintf('V438P-%03d', $i), date('Y-m-d H:i:s', strtotime('2026-09-01') + $i * 60)]);
$p2 = $get438('invoices', ['q' => 'V438P-', 'page' => '2']);
$p1 = $get438('invoices', ['q' => 'V438P-']);
check($p2['pages'] === 2 && $p2['page'] === 2 && count($p2['invoices']) === 1 && $p2['total'] === 301 && $p2['invoices'][0]['invoice_number'] === 'V438P-001' && count($p1['invoices']) === 300,
    'T1: page 2 of 301 invoices holds the oldest one; page 1 the newest 300');
$db->exec("DELETE FROM invoices WHERE invoice_number LIKE 'V438P-%'");
$otherInv = $inv438($tOther438, 'V438-INV-OTHER', ['created_at' => '2026-10-07 09:00:00']);
$as438($gScoped438);
$scopedApi = $apiInvoiceIds438(['q' => 'V438-INV-']);
$scopedPage = $pageInvoiceIds438(['q' => 'V438-INV-']);
$as438(null);
check($scopedApi === $scopedPage && !in_array($otherInv, $scopedApi, true) && in_array($sub, $scopedApi, true), 'T1: a caseload-scoped user gets only their caseload\'s invoices, the same rows as the page');

/* ---- T2: the list filters, through invoice_list_filters() and invoice_filter_sql() ---- */
$tOld = $track438($c438, ['title' => 'V438 renamed agency track', 'funding_agency' => 'v438 synthetic  vr']);
$oldSpelling = $inv438($tOld, 'V438-INV-005', ['funding_agency' => 'v438 synthetic  vr', 'created_at' => '2026-09-15 09:00:00']);
$pct = $inv438($t438, 'V438-50%OFF', ['created_at' => '2026-10-08 09:00:00']);
$pct2 = $inv438($t438, 'V438-500', ['created_at' => '2026-10-09 09:00:00']);
foreach ([['agency' => 'V438 Synthetic VR'], ['month' => '2026-10', 'q' => 'V438-'], ['q' => 'V438-INV-00'], ['q' => 'V438 Synthetic Client'], ['q' => '50%'], ['status' => 'Submitted', 'q' => 'V438-'], ['month' => '2026-13', 'q' => 'V438-']] as $f) {
    check($apiInvoiceIds438($f) === $pageInvoiceIds438($f), 'T2: the API lists the same invoices as invoices.php for ' . json_encode($f));
}
$ag = $get438('invoices', ['agency' => 'V438 Synthetic VR']);
$oct = $get438('invoices', ['month' => '2026-10', 'q' => 'V438-']);
$lit = $get438('invoices', ['q' => '50%']);
$bad = $get438('invoices', ['month' => '2026-13', 'q' => 'V438-']);
$all = $get438('invoices', ['q' => 'V438-']);
check(in_array($oldSpelling, array_column($ag['invoices'], 'id'), true) && in_array($sub, array_column($ag['invoices'], 'id'), true) && $ag['heading'] === 'All invoices for V438 Synthetic VR'
    && $ag['filters']['agency'] === 'V438 Synthetic VR', 'T2: an agency lists its older spelling too, and the heading names it');
check(!in_array($oldSpelling, array_column($oct['invoices'], 'id'), true) && $oct['heading'] === 'All invoices created in October 2026 matching "V438-"', 'T2: month 2026-10 lists only October\'s invoices, with the page\'s heading');
check(array_column($lit['invoices'], 'id') === [$pct], 'T2: q "50%" matches literally');
check($bad['filters']['month'] === '' && array_column($bad['invoices'], 'id') === array_column($all['invoices'], 'id'), 'T2: an unreadable month (2026-13) is ignored, not refused');
$st37 = $get438('invoices', ['status' => 'Submitted']);
check($st37['status'] === 'Submitted' && $st37['requested'] === false && $st37['found'] === null && in_array('V438 Synthetic VR', $st37['agencies'], true), 'T2: a 4.37.0-style body (status only) still lists by status, with the agencies');
$one = $get438('invoices', ['id' => (string)$sub, 'status' => 'Draft']);
check($one['requested'] === true && $one['found'] === true && array_column($one['invoices'], 'id') === [$sub] && $one['heading'] === 'Requested invoice' && $one['filters']['status'] === '',
    'T2: id lists that one invoice whatever the other filters say');
$as438($gScoped438);
$miss = $get438('invoices', ['id' => (string)$otherInv]);
[, $missPage] = $page438('invoices', ['id' => (string)$otherInv]);
$as438(null);
check($miss['found'] === false && $miss['invoices'] === [] && str_contains($missPage, 'That invoice was not found, or it is not on your caseload.'), 'T2: another caseload\'s invoice is not found for a scoped user, as on the page');
check(!str_contains((string)file_get_contents($root . '/public/invoices.php'), 'function invoice_filter_sql') && str_contains((string)file_get_contents($root . '/public/inc/billing.php'), 'function invoice_filter_sql'),
    'T2: the filters live in inc/billing.php, not in invoices.php');

/* ---- T3: outstanding by agency (invoice_aging()) ---- */
$tA = $track438($c438, ['funding_agency' => 'V438 Agency A']); $tB = $track438($c438, ['funding_agency' => 'V438 Agency B']); $tC = $track438($c438, ['funding_agency' => 'V438 Agency C']);
$inv438($tA, 'V438-AG-A1', ['status' => 'Submitted', 'total_amount' => 333.33, 'paid_amount' => 111.11, 'submitted_at' => date('Y-m-d', strtotime('-40 days')), 'due_date' => date('Y-m-d', strtotime('-10 days'))]);
$inv438($tA, 'V438-AG-A2', ['status' => 'Submitted', 'total_amount' => 0.03, 'submitted_at' => date('Y-m-d', strtotime('-5 days')), 'due_date' => date('Y-m-d', strtotime('+25 days'))]);
$inv438($tB, 'V438-AG-B1', ['status' => 'Paid', 'total_amount' => 500.50, 'paid_amount' => 400.25, 'written_off' => 100.24, 'submitted_at' => date('Y-m-d', strtotime('-60 days')), 'due_date' => date('Y-m-d', strtotime('-30 days'))]);
// Settled by a write-off to the cent: nothing left, so it is not open.
$inv438($tB, 'V438-AG-B2', ['status' => 'Paid', 'total_amount' => 100.10, 'paid_amount' => 100.00, 'written_off' => 0.10, 'submitted_at' => date('Y-m-d', strtotime('-60 days')), 'due_date' => date('Y-m-d', strtotime('-30 days'))]);
$inv438($tC, 'V438-AG-C1', ['status' => 'Submitted', 'total_amount' => 2500, 'submitted_at' => $today, 'due_date' => date('Y-m-d', strtotime('+30 days'))]);
$r = $get438('invoices', []);
$aging = invoice_aging(null);
[, $pageOut] = $page438('invoices', []);
$agingHtml = substr($pageOut, (int)strpos($pageOut, 'id="aging-h"'), (int)strpos($pageOut, '</section>', (int)strpos($pageOut, 'id="aging-h"')) - (int)strpos($pageOut, 'id="aging-h"'));
preg_match_all('~<tr><td>(.*?)</td><td class="num">(\d+)(?: \((\d+) part-paid\))?</td>\s*<td class="num">(.*?)</td><td class="num">(.*?)</td><td class="num">(-?\d+)</td></tr>~s', $agingHtml, $pm, PREG_SET_ORDER);
$same = count($pm) === count($r['outstanding']) && count($aging) === count($r['outstanding']);
foreach ($r['outstanding'] as $k => $o) {
    $same = $same && $o['amount'] == round((float)$aging[$k]['amt'], 2) && $o['overdue'] == round((float)$aging[$k]['overdue'], 2) && $o['part_paid'] === (int)$aging[$k]['part_paid']
        && html_entity_decode($pm[$k][1], ENT_QUOTES) === $o['funding_agency'] && (int)$pm[$k][2] === $o['count'] && (int)($pm[$k][3] ?: 0) === $o['part_paid']
        && $pm[$k][4] === fmt_money($o['amount']) && $pm[$k][5] === fmt_money($o['overdue']) && (int)$pm[$k][6] === (int)($o['oldest_days'] ?? 0);   // the page prints an unknown age as 0
}
$byAg = array_column($r['outstanding'], null, 'funding_agency');
check($same && isset($byAg['V438 Agency A'], $byAg['V438 Agency B'], $byAg['V438 Agency C']), 'T3: every outstanding row equals invoice_aging() and the page\'s table, amount owed and past due formatted alike, in the same order');
check($byAg['V438 Agency A']['amount'] == 222.25 && $byAg['V438 Agency A']['overdue'] == 222.22 && $byAg['V438 Agency A']['part_paid'] === 1 && $byAg['V438 Agency A']['count'] === 2
    && $byAg['V438 Agency B']['count'] === 1 && $byAg['V438 Agency B']['amount'] == 0.01 && $byAg['V438 Agency B']['overdue'] == 0.01
    && $byAg['V438 Agency C']['overdue'] == 0 && $byAg['V438 Agency C']['part_paid'] === 0,
    'T3: part-paid, written off to the cent (not open) and a 0.01 remainder (open), past due and not yet due');

/* ---- T4: several drafts marked submitted (invoice_bulk_submit()) ---- */
$tB4 = $track438($c438, ['funding_agency' => 'V438 Bulk Agency']);
$b1 = $inv438($tB4, 'V438-BULK-1', ['created_at' => '2026-10-01 08:00:00', 'funding_agency' => 'V438 Bulk Agency']);
$b2 = $inv438($tB4, 'V438-BULK-2', ['created_at' => '2026-10-01 08:00:00', 'funding_agency' => 'V438 Bulk Agency']);
$b3 = $inv438($tB4, 'V438-BULK-3', ['created_at' => $today . ' 08:00:00', 'funding_agency' => 'V438 Bulk Agency']);
$on = '2026-10-20';
$k = $key438();
$r1 = $post438('submit_invoices', ['invoice_ids' => [$b1, $b2, $b3], 'submitted_on' => $on, 'idem_key' => $k]);
$r2 = $post438('submit_invoices', ['invoice_ids' => [$b1, $b2, $b3], 'submitted_on' => $on, 'idem_key' => $k]);
$expect = '2 invoices marked submitted. Not changed, because the date is before they were created: V438-BULK-3.';
check($r1['changed'] === 2 && $r1['refused'] === ['V438-BULK-3'] && $r1['message'] === $expect && $audits438('invoice.submit', 'submitted ' . $on . ' (several at once) (mobile)') === 2,
    'T4: two changed, the third refused by its number, the website\'s sentence, audited twice "(several at once) (mobile)"');
check(!empty($r2['repeat']) && $r2['message'] === $expect && $r2['changed'] === 2 && $r2['refused'] === ['V438-BULK-3'] && $audits438('invoice.submit', '%(several at once) (mobile)') === 2,
    'T4: the same idem_key again changes nothing and answers the same reply');
[$stC, $dC] = $req438('POST', 'submit_invoices', ['invoice_ids' => [$b3], 'submitted_on' => '', 'idem_key' => $k]);
check($stC === 409 && $dC['error'] === 'This was already sent once with different details, and that earlier send may have been saved. Reload to check what was saved, then make your change again.' && !isset($dC['message']) && $one438("SELECT status FROM invoices WHERE id=$b3") === 'Draft',
    'T4: the same idem_key with a different body is refused 409, and nothing changes');
check($one438("SELECT due_date FROM invoices WHERE id=$b1") === invoice_due_date($on, 'V438 Bulk Agency') && $one438("SELECT status FROM invoices WHERE id=$b3") === 'Draft',
    'T4: each changed invoice\'s due date is invoice_due_date() for its agency');
[$st, $d] = $req438('POST', 'submit_invoices', ['invoice_ids' => [], 'submitted_on' => '']);
[$st2, $d2] = $req438('POST', 'submit_invoices', ['invoice_ids' => [$b3], 'submitted_on' => date('Y-m-d', strtotime('+1 day'))]);
[$st3, $d3] = $req438('POST', 'submit_invoices', ['invoice_ids' => range(1, 201)]);
[$st4, $d4] = $req438('POST', 'submit_invoices', ['invoice_ids' => [$b3], 'submitted_on' => $on]);
check($st === 422 && $d['errors']['invoice_ids'] === 'Tick at least one draft invoice.' && $st2 === 422 && $d2['errors']['submitted_on'] === 'Enter the date they were sent as a full date, not after today.'
    && $st3 === 422 && $d3['errors']['invoice_ids'] === 'Choose at most 200 invoices at a time.'
    && $st4 === 422 && $d4['errors']['submitted_on'] === 'The date is before these invoices were created: V438-BULK-3. Choose a later date.', 'T4: none chosen, a future date, 201 ids and all refused are 422 on their fields with the website\'s sentences');
// One already submitted in the list is skipped and named; the others are submitted.
$b4 = $inv438($tB4, 'V438-BULK-4', ['created_at' => '2026-10-01 08:00:00', 'funding_agency' => 'V438 Bulk Agency']);
$sk = $post438('submit_invoices', ['invoice_ids' => [$b1, $b4], 'submitted_on' => $on, 'idem_key' => $key438()]);
check($sk['changed'] === 1 && $sk['skipped'] === ['V438-BULK-1'] && $sk['message'] === '1 invoice marked submitted. Not changed, because it is no longer a draft: V438-BULK-1.'
    && $one438("SELECT submitted_at FROM invoices WHERE id=$b1") === $on, 'T4: an already-Submitted invoice in the list is skipped and named, and left as it was');
$bOther = $inv438($tOther438, 'V438-BULK-OTHER', ['created_at' => '2026-10-01 08:00:00']);
$as438($gScoped438);
$r = $post438('submit_invoices', ['invoice_ids' => [$bOther, $b3], 'submitted_on' => '']);
$as438(null);
check($r['changed'] === 1 && $one438("SELECT status FROM invoices WHERE id=$bOther") === 'Draft' && $one438("SELECT submitted_at FROM invoices WHERE id=$b3") === $today,
    'T4: another caseload\'s draft is skipped silently for a scoped user; a blank date is today');
// The page's bulk_submit through the same helper: the same flash, due dates and audit (no "(mobile)").
$w1 = $inv438($tB4, 'V438-BULK-W1', ['created_at' => '2026-10-01 08:00:00', 'funding_agency' => 'V438 Bulk Agency']);
$w2 = $inv438($tB4, 'V438-BULK-W2', ['created_at' => $today . ' 08:00:00', 'funding_agency' => 'V438 Bulk Agency']);
$page438('invoices', [], ['bulk_submit' => '1', 'bulk_ids' => [(string)$w1, (string)$w2], 'bulk_submitted_on' => $on]);
check(str_contains($flash438(), '1 invoice marked submitted. Not changed, because the date is before they were created: V438-BULK-W2.') && $one438("SELECT due_date FROM invoices WHERE id=$w1") === invoice_due_date($on, 'V438 Bulk Agency')
    && $audits438('invoice.submit', 'submitted ' . $on . ' (several at once)') === 1, 'T4: invoices.php\'s bulk_submit gives the same sentence and due date through invoice_bulk_submit()');

/* ---- T5: the invoice's accessible document ---- */
$tDoc = $track438($c438, ['title' => 'V438 document track', 'funding_agency' => 'V438 Doc Agency', 'hourly_rate' => 33.33]);
$docInv = $inv438($tDoc, 'V438-DOC-1', ['status' => 'Submitted', 'hours' => 1.5, 'hours_amount' => 50.00, 'total_amount' => 50.00, 'paid_amount' => 20.00, 'written_off' => 0.01,
    'submitted_at' => $today, 'due_date' => date('Y-m-d', strtotime('+30 days')), 'auth_number' => 'V438-DOC-AUTH', 'funding_agency' => 'V438 Doc Agency', 'hourly_rate' => 33.33]);
$db->prepare("INSERT INTO work_logs (track_id, work_date, hours, description, hourly_rate, is_billed, invoice_id, created_by) VALUES (?, CURDATE(), 1.5, 'V438 synthetic work', 33.33, 1, ?, 1)")->execute([$tDoc, $docInv]);
$d = $get438('invoice_document', ['invoice_id' => (string)$docInv]);
[, $pageHtml] = $page438('invoice_pdf', ['invoice' => (string)$docInv, 'format' => 'html']);
$strip = fn(string $html): string => (string)preg_replace('~<nav aria-label="CMS navigation">.*?</nav>~s', '', $html);
$di = $db->query("SELECT * FROM invoices WHERE id=$docInv")->fetch();
check($d['title'] === 'Invoice V438-DOC-1' && $d['file'] === 'invoice_' . $docInv . '.html' && $strip($pageHtml) === $d['html'], 'T5: invoice_document\'s HTML is invoice_pdf.php\'s accessible HTML (without the CMS links)');
check(str_contains($d['text'], fmt_money(50.00)) && str_contains($d['text'], 'Paid: ' . fmt_money(20.00)) && str_contains($d['text'], 'Written off: ' . fmt_money(0.01)) && str_contains($d['text'], 'BALANCE DUE: ' . fmt_money(invoice_balance($di)))
    && str_contains($d['text'], 'Invoice number: V438-DOC-1') && str_contains($d['text'], fmt_money(round(1.5 * 33.33, 2))), 'T5: the text holds the total, paid, written off and balance due, and the line at the half-cent rate, formatted with fmt_money()');
check($audits438('invoice.download', 'invoice V438-DOC-1 (accessible HTML) (mobile)') === 1, 'T5: audited invoice.download "(mobile)"');
$docOther = $inv438($tOther438, 'V438-DOC-OTHER');
[$st, $dd] = $req438('GET', 'invoice_document', [], ['invoice_id' => '999999']);
$as438($gScoped438);
[$st2, $dd2] = $req438('GET', 'invoice_document', [], ['invoice_id' => (string)$docOther]);
$as438($gNoBilling438);
[$st3] = $req438('GET', 'invoice_document', [], ['invoice_id' => (string)$docInv]);
$as438(null);
check($st === 404 && $dd['error'] === 'That invoice no longer exists.' && $st2 === 403 && $dd2['error'] === 'That client is not on your caseload.' && $st3 === 403, 'T5: missing 404, another caseload 403, no billing 403');

/* ---- T6: incoming authorization emails ---- */
$authText = fn(string $n, string $client, string $extra = '') => "From: V438 Counselor <v438-counselor@example.invalid>\nSent: Monday, November 2, 2026 9:00 AM\nTo: V438 Counselor <v438-counselor@example.invalid>\nSubject: Authorization\n\n"
    . "Authorization {$n} has been approved.{$extra}\nAuthorization Number: {$n}\nClient Name: {$client}\nService Category: Rehabilitation Technology\nAuthorization Amount: 1,250.00\nStart Date: 2026-11-02\nEnd Date: 2027-02-28\n";
$cAuth = $client438('Zebedee V438auth', ['counselor_email' => null]);
[$st, $d] = $req438('POST', 'read_authorization_email', ['email_text' => '']);
[$st2, $d2] = $req438('POST', 'read_authorization_email', ['email_text' => 'Hello, nothing here']);
[$st3, $d3] = $req438('POST', 'read_authorization_email', ['email_text' => str_repeat('a', 100001)]);
check($st === 422 && $d['errors']['email_text'] === 'Paste the authorization email first.' && $st2 === 422 && str_starts_with($d2['errors']['email_text'], 'No authorization number was found in that text.')
    && $st3 === 422 && $d3['errors']['email_text'] === 'The authorization email is too long to read.', 'T6: blank, number-less and too-long emails are refused on email_text');
$k = $key438();
$p1 = $post438('read_authorization_email', ['email_text' => $authText('V438-AU-0001', 'Zebedee V438auth'), 'idem_key' => $k]);
$p2 = $post438('read_authorization_email', ['email_text' => $authText('V438-AU-0001', 'Zebedee V438auth'), 'idem_key' => $k]);
check(is_int($p1['id']) && $p2['id'] === $p1['id'] && !empty($p2['repeat']) && (int)$one438("SELECT COUNT(*) FROM incoming_authorizations WHERE auth_number='V438-AU-0001'") === 1
    && $audits438('authorization.email', 'pasted V438-AU-0001 (mobile)') === 1, 'T6: a good email is stored New and audited "pasted … (mobile)"; the same idem_key keeps one row');
$aid = $p1['id'];
$list = $get438('incoming_authorizations');
$mine = array_values(array_filter($list['rows'], fn($x) => $x['id'] === $aid))[0] ?? null;
[, $authPage] = $page438('authorizations', []);
check($mine !== null && $mine['matched'] === true && str_contains($authPage, h(substr($mine['text'], strlen('V438-AU-0001: ')))) && $mine['text'] === 'V438-AU-0001: Zebedee V438auth, ' . fmt_money(1250.00) . ', received ' . fmt_date(date('Y-m-d')),
    'T6: the waiting row is the page\'s line');
$rv = $get438('incoming_authorization', ['id' => (string)$aid]);
check($rv['auth_number'] === 'V438-AU-0001' && $rv['status'] === 'New' && $rv['not_approved'] === false && $rv['already'] === [] && $rv['preset_client_id'] === $cAuth
    && $rv['details'][2] === ['label' => 'Amount', 'value' => fmt_money(1250.00)] && $rv['matches'][0]['id'] === $cAuth && str_starts_with($rv['matches'][0]['text'], 'Zebedee V438auth (Active Training')
    && $rv['counselor'] === ['text' => 'V438 Counselor v438-counselor@example.invalid', 'confirmed' => false, 'preticked' => false] && str_contains($rv['email_text'], 'Authorization Number: V438-AU-0001'),
    'T6: the review has the page\'s details, the matched client preset and the counselor offer (unconfirmed: pasted)');
$nid = $post438('read_authorization_email', ['email_text' => $authText('V438-AU-0002', 'Nobody V438auth', ' Actually it has been denied.')])['id'];
$track438($c438, ['auth_number' => 'V438-AU-0002', 'authorized_amount' => 1000]);
$rv2 = $get438('incoming_authorization', ['id' => (string)$nid]);
check($rv2['not_approved'] === true && count($rv2['already']) === 1 && str_contains($rv2['already'][0], '(authorized ' . fmt_money(1000) . ' there, ' . fmt_money(1250) . ' in this email')
    && $rv2['matches'] === [] && $rv2['preset_client_id'] === 0, 'T6: "has been denied" is not_approved; a number already on a track is said with both amounts');
// Start with "another client": the match is recorded, the counselor unconfirmed with the page's notice and audit.
$k = $key438();
$s = $post438('start_authorization_track', ['id' => $nid, 'client_id' => $c438, 'set_counselor' => true, 'idem_key' => $k]);
$a2 = incoming_authorization($nid);
check((int)$a2['client_id'] === $c438 && $s['client_id'] === $c438 && $s['notices'] === ['v438-counselor@example.invalid is recorded as V438 Synthetic Client\'s VR counselor, unconfirmed: no report goes to it until it is confirmed on the client\'s edit page.']
    && $s['prefill'] === array_map('strval', authorization_track_prefill($a2, $c438)) && $s['heading'] === 'New training track for V438 Synthetic Client, filled in from authorization V438-AU-0002: check each field before saving'
    && $audits438('client.counselor', 'from authorization V438-AU-0002: none -> v438-counselor@example.invalid (unconfirmed) (mobile)') === 1
    && (int)$one438("SELECT counselor_verified FROM clients WHERE id=$c438") === 0, 'T6: start records the client and the counselor unconfirmed, with the page\'s notice, the prefill and the heading');
$s2 = $post438('start_authorization_track', ['id' => $nid, 'client_id' => $c438, 'set_counselor' => true, 'idem_key' => $k]);
check(!empty($s2['repeat']) && $s2['prefill'] === $s['prefill'] && $s2['heading'] === $s['heading'] && $s2['notices'] === $s['notices'] && $s2['id'] === $nid
    && $audits438('client.counselor', '%V438-AU-0002%') === 1, 'T6: the same idem_key answers the prefill, heading and notices again without recording twice');
[$stC, $dC] = $req438('POST', 'start_authorization_track', ['id' => $aid, 'client_id' => $c438, 'set_counselor' => true, 'idem_key' => $k]);
check($stC === 409 && $dC['error'] === 'This was already sent once with different details, and that earlier send may have been saved. Reload to check what was saved, then make your change again.' && !str_contains(json_encode($dC), 'V438-AU-0001') && !isset($dC['prefill'])
    && $one438("SELECT client_id FROM incoming_authorizations WHERE id=$aid") == $cAuth, 'T6: the start key reused for another email is refused 409 and says nothing of it');
$tr = $post438('save_track', ['client_id' => $c438, 'incoming_auth' => $nid] + $s['prefill'] + ['idem_key' => $key438()]);
check($one438("SELECT status FROM incoming_authorizations WHERE id=$nid") === 'Track created' && in_array($one438("SELECT status FROM tasks WHERE auto_key='incauth-$nid'"), [false, 'Done'], true),
    'T6: save_track with incoming_auth then marks the email Track created (its match is recorded now)');
[$st, $d] = $req438('POST', 'start_authorization_track', ['id' => $nid, 'client_id' => $c438]);
check($st === 409 && $d['error'] === 'That authorization email was already handled.', 'T6: starting a handled email is 409');
$arch = $client438('V438 Archived Client', ['archived_at' => date('Y-m-d H:i:s')]);
[$st, $d] = $req438('POST', 'start_authorization_track', ['id' => $aid, 'client_id' => $arch]);
check($st === 422 && $d['errors']['client_id'] === 'Choose the client this authorization is for.', 'T6: an archived client is refused on client_id');
$k = $key438();
$x1 = $post438('dismiss_authorization', ['id' => $aid, 'idem_key' => $k]);
$x2 = $post438('dismiss_authorization', ['id' => $aid, 'idem_key' => $k]);
[$stC, $dC] = $req438('POST', 'dismiss_authorization', ['id' => $nid, 'idem_key' => $k]);
check($stC === 409 && !str_contains(json_encode($dC), 'V438-AU-0002'), 'T6: the dismiss key reused for another email is refused 409 and names neither');
[$st, $d] = $req438('POST', 'dismiss_authorization', ['id' => $aid]);
check($x1['message'] === 'Authorization V438-AU-0001 dismissed.' && $x2['message'] === $x1['message'] && !empty($x2['repeat']) && $st === 409 && $d['error'] === 'That authorization email was already handled.',
    'T6: dismiss says the page\'s sentence; the same key answers it again; a new dismiss is 409');
// Scope: another caseload's matched email, an unmatched one the scoped user pasted, and an unmatched mailbox email.
$cOtherAuth = $client438('Quillon V438other', ['assigned_to' => $u438]);
$otherAid = authorization_email_store(authorization_email_parse($authText('V438-AU-0003', 'Quillon V438other')), $authText('V438-AU-0003', 'Quillon V438other'), 'Mailbox', 'v438-msg-3', null);
$mailboxAid = authorization_email_store(authorization_email_parse($authText('V438-AU-0004', 'Unknown V438person')), $authText('V438-AU-0004', 'Unknown V438person'), 'Mailbox', 'v438-msg-4', null);
$as438($gScoped438);
$pasted = $post438('read_authorization_email', ['email_text' => $authText('V438-AU-0005', 'Unknown V438person')]);
$sl = array_column($get438('incoming_authorizations')['rows'], 'id');
[$st, $d] = $req438('GET', 'incoming_authorization', [], ['id' => (string)$otherAid]);
[$stD] = $req438('POST', 'dismiss_authorization', ['id' => $otherAid]);
$as438(null);
check(!in_array($otherAid, $sl, true) && !in_array($mailboxAid, $sl, true) && in_array($pasted['id'], $sl, true) && $st === 404 && $d['error'] === 'That authorization email is not on Incoming authorizations, or it is not on your caseload.'
    && $stD === 404 && $one438("SELECT status FROM incoming_authorizations WHERE id=$otherAid") === 'New', 'T6: a scoped user sees their own pasted email only; another caseload\'s is 404 and cannot be dismissed');
$as438($gNoClients438);
[$st] = $req438('GET', 'incoming_authorizations');
$as438(null);
check($st === 403, 'T6: without the clients permission 403');
// The page's paste, start and dismiss through the same helpers.
$page438('authorizations', [], ['read_email' => '1', 'email_text' => $authText('V438-AU-0006', 'Zebedee V438auth')]);
$webAid = (int)$one438("SELECT id FROM incoming_authorizations WHERE auth_number='V438-AU-0006'");
$page438('authorizations', ['id' => (string)$webAid], ['start_track' => '1', 'id' => (string)$webAid, 'client_id' => 'other', 'other_client' => (string)$arch]);
$refusedFlash = $flash438();
$page438('authorizations', [], ['dismiss' => '1', 'id' => (string)$webAid]);
check($webAid > 0 && $audits438('authorization.email', 'pasted V438-AU-0006') === 1 && str_contains($refusedFlash, 'Choose the client this authorization is for.') && str_contains($flash438(), 'Authorization V438-AU-0006 dismissed.')
    && $one438("SELECT status FROM incoming_authorizations WHERE id=$webAid") === 'Dismissed', 'T6: authorizations.php pastes, refuses an archived client and dismisses through the helpers');
$page438('authorizations', [], ['read_email' => '1', 'email_text' => $authText('V438-AU-0007', 'Zebedee V438auth')]);
$webAid2 = (int)$one438("SELECT id FROM incoming_authorizations WHERE auth_number='V438-AU-0007'");
$page438('authorizations', ['id' => (string)$webAid2], ['start_track' => '1', 'id' => (string)$webAid2, 'client_id' => (string)$cAuth, 'set_counselor' => '1']);
$webSess = $flash438();
check((int)$one438("SELECT client_id FROM incoming_authorizations WHERE id=$webAid2") === $cAuth && str_contains($webSess, 'auth_prefill') && str_contains($webSess, 'V438-AU-0007')
    && $audits438('client.counselor', 'from authorization V438-AU-0007: none -> v438-counselor@example.invalid (unconfirmed)') === 1,
    'T6: authorizations.php starts the track through incoming_authorization_start(): the match, the counselor audit and the prefill in the session');
[, $reviewPage] = $page438('authorizations', ['id' => (string)$nid]);
check(str_contains($reviewPage, 'Authorization V438-AU-0002') && str_contains($reviewPage, 'Track created') && !str_contains((string)file_get_contents($root . '/public/authorizations.php'), 'UPDATE incoming_authorizations'),
    'T6: the page reviews through incoming_authorization_review() and holds no UPDATE of its own');

/* ---- T7: several sessions at once (session_plan()) ---- */
$cS = $client438('V438 Booking Client', ['email' => 'v438-booking@example.invalid', 'email_verified' => 1]);
$base = strtotime('next monday', strtotime('+45 days'));   // times no earlier file books
$monday = date('Y-m-d', $base);
$weekly = $post438('schedule_session', ['client_id' => $cS, 'date' => $monday, 'time' => '04:00', 'duration' => 1, 'repeat_weeks' => 3, 'idem_key' => $key438()]);
$wRows = $db->query("SELECT start_at, series_uid FROM scheduled_sessions WHERE client_id=$cS ORDER BY start_at")->fetchAll();
check($weekly['sessions_created'] === 3 && count($wRows) === 3 && $wRows[2]['start_at'] === date('Y-m-d H:i:s', strtotime('+2 week', strtotime($monday . ' 04:00:00'))) && $wRows[0]['series_uid'] !== null
    && $weekly['series_words'] === '3 weekly sessions' && str_starts_with($weekly['message'], 'Series of 3 weekly sessions scheduled.') && $audits438('session.schedule', 'series of 3 weekly sessions (mobile)') === 1,
    'T7: a 4.37.0-style weekly body books the same three sessions through session_plan(), with the website\'s words and audit');
$db->exec("DELETE FROM scheduled_sessions WHERE client_id=$cS");
$plan = ['client_id' => $cS, 'date' => $monday, 'time' => '05:30', 'duration' => 1, 'plan_pattern' => 'days', 'plan_days' => [1, 3], 'repeat_weeks' => 2];
[$st, $d] = $req438('POST', 'schedule_session', $plan + ['idem_key' => $key438()]);
[$occ] = session_plan(['plan_pattern' => 'days', 'plan_days' => [1, 3], 'repeat_weeks' => '2'], strtotime($monday . ' 05:30'), strtotime($monday . ' 06:30'), null);
check($st === 422 && isset($d['errors']['plan_ok']) && $d['error'] === 'Check the 4 sessions listed below (4 hours), then tick Book these 4 sessions and schedule again.' && $d['plan_preview']['sessions'] === session_plan_lines($occ)
    && $d['plan_preview']['hours'] == 4 && $d['plan_preview']['hash'] === session_plan_hash($occ, $cS, 1, 0, 'days') && (int)$one438("SELECT COUNT(*) FROM scheduled_sessions WHERE client_id=$cS") === 0,
    'T7: Monday and Wednesday for 2 weeks is shown first: 422 with the website\'s sentence and the sessions as session_plan_lines() says them');
$clearMail438();
$ok = $post438('schedule_session', $plan + ['plan_ok' => $d['plan_preview']['hash'], 'idem_key' => $key438()]);
$dRows = $db->query("SELECT series_uid FROM scheduled_sessions WHERE client_id=$cS")->fetchAll(PDO::FETCH_COLUMN);
// The instructor's invite (the client's own notice goes to the client's address).
$invites = array_values(array_filter($mails438(), fn($m) => str_contains($m, 'invite.ics') && !str_contains($m, 'To: v438-booking@example.invalid')));
check($ok['sessions_created'] === 4 && count($dRows) === 4 && count(array_unique($dRows)) === 1 && $dRows[0] !== null && $audits438('session.schedule', 'series of 4 sessions (on several days each week) (mobile)') === 1
    && count($invites) === 1 && str_contains($invites[0], '4 sessions (on several days each week)') && $ok['message'] === session_booked_message(4, 'days', $adminName438, true),
    'T7: sent again with plan_ok, the 4 sessions are booked as one series, audited, one invite, and the website\'s sentence');
$db->exec("DELETE FROM scheduled_sessions WHERE client_id=$cS");
[$st, $d] = $req438('POST', 'schedule_session', ['client_id' => $cS, 'date' => $monday, 'time' => '05:30', 'duration' => 1, 'plan_pattern' => 'dates', 'plan_dates' => $monday . "\n" . date('Y-m-d') . ' 12:01 AM']);   // earlier today: in the past
$many = implode("\n", array_map(fn($i) => date('Y-m-d', strtotime($monday . " +$i day")), range(0, 100)));
[$st2, $d2] = $req438('POST', 'schedule_session', ['client_id' => $cS, 'date' => $monday, 'time' => '05:30', 'duration' => 1, 'plan_pattern' => 'dates', 'plan_dates' => $many]);
check($st === 422 && $d['errors']['plan_dates'] === 'Line 2 of the dates is in the past.' && $st2 === 422 && $d2['errors']['plan_dates'] === 'Book at most 100 sessions at a time.', 'T7: a past date line and 101 dates are refused with the website\'s sentences');
$tNoEnd = $track438($cS, ['expiration_date' => null]);
[$st, $d] = $req438('POST', 'schedule_session', ['client_id' => $cS, 'track_id' => $tNoEnd, 'date' => $monday, 'time' => '05:30', 'duration' => 1, 'plan_pattern' => 'fill', 'plan_days' => [1]]);
[$st2, $d2] = $req438('POST', 'schedule_session', ['client_id' => $cS, 'track_id' => $tOther438, 'date' => $monday, 'time' => '05:30', 'duration' => 1, 'plan_pattern' => 'fill', 'plan_days' => [1]]);
check($st === 422 && $d['errors']['track_id'] === 'That authorization has no end date: use several days each week with a last date instead.' && $st2 >= 400 && (int)$one438("SELECT COUNT(*) FROM scheduled_sessions WHERE client_id=$cS") === 0,
    'T7: fill over a track with no end date is refused on track_id; another client\'s track is refused');
// A preview and an authorization warning in one reply, both approved in one send.
$tSmall = $track438($cS, ['authorized_hours' => 2, 'start_date' => $monday, 'expiration_date' => date('Y-m-d', strtotime($monday . ' +60 days'))]);
$wplan = ['client_id' => $cS, 'track_id' => $tSmall, 'date' => $monday, 'time' => '07:00', 'duration' => 1, 'plan_pattern' => 'days', 'plan_days' => [1, 2, 3], 'repeat_weeks' => 1];
[$st, $d] = $req438('POST', 'schedule_session', $wplan);
check($st === 422 && isset($d['plan_preview'], $d['authorization_warnings'], $d['authorization_hash'], $d['errors']['plan_ok'], $d['errors']['authorization_ack']), 'T7: the preview comes with the authorization warning in one reply');
$both = $post438('schedule_session', $wplan + ['plan_ok' => $d['plan_preview']['hash'], 'authorization_ack' => true, 'authorization_ack_hash' => $d['authorization_hash'], 'idem_key' => $key438()]);
check($both['sessions_created'] === 3 && $audits438('session.schedule', 'series of 3 sessions (on several days each week) (mobile) — authorization exception approved:%') === 1, 'T7: both approved in one send books the plan and records the approval');
$db->exec("DELETE FROM scheduled_sessions WHERE client_id=$cS");
// A duplicate on one date refuses the whole plan.
$db->prepare("INSERT INTO scheduled_sessions (client_id, user_id, start_at, end_at, status) VALUES (?,1,?,?,'Scheduled')")->execute([$cS, date('Y-m-d', strtotime($monday . ' +2 days')) . ' 05:30:00', date('Y-m-d', strtotime($monday . ' +2 days')) . ' 06:30:00']);
[$st, $d] = $req438('POST', 'schedule_session', $plan);
[$st2, $d2] = $req438('POST', 'schedule_session', $plan + ['plan_ok' => $d['plan_preview']['hash'] ?? '', 'authorization_ack' => true, 'authorization_ack_hash' => $d['authorization_hash'] ?? '']);
check($st2 === 422 && isset($d2['errors']['date']) && str_contains($d2['errors']['date'], 'Nothing was saved.') && (int)$one438("SELECT COUNT(*) FROM scheduled_sessions WHERE client_id=$cS") === 1, 'T7: a duplicate on one date refuses the whole plan; nothing is saved');
$db->exec("DELETE FROM scheduled_sessions WHERE client_id=$cS");
// schedule.php books the same plan with the same sentence through the shared helpers.
$webPlan = ['add_session' => '1', 'client_id' => (string)$cS, 'staff_id' => '1', 'date' => $monday, 'time' => '05:30', 'duration' => '1', 'plan_pattern' => 'days', 'plan_days' => ['1', '3'], 'repeat_weeks' => '2'];
$page438('schedule', [], $webPlan);
$webPreview = $flash438();
$page438('schedule', [], $webPlan + ['plan_ok' => session_plan_hash($occ, $cS, 1, 0, 'days')]);
check(str_contains($webPreview, 'Check the 4 sessions listed below (4 hours)') && (int)$one438("SELECT COUNT(*) FROM scheduled_sessions WHERE client_id=$cS") === 4
    && str_contains($flash438(), 'Series of 4 sessions (on several days each week) scheduled.'), 'T7: schedule.php previews and books the same 4 sessions with the same sentence');
$db->exec("DELETE FROM scheduled_sessions WHERE client_id=$cS");
$opts = $get438('booking_options');
check(array_column($opts['plan_patterns'], 'label') === array_values(PLAN_PATTERNS) && $opts['weekdays'][0] === ['n' => 1, 'label' => 'Monday'] && $opts['max_sessions'] === 100,
    'T7: booking_options sends the website\'s patterns, weekdays and limit');
[, $schedForm] = $page438('schedule', []);
$schedSrc = (string)file_get_contents($root . '/public/schedule.php');
check(str_contains($schedForm, 'name="repeat_weeks" min="1" max="26"') && str_contains($schedForm, 'up to 26 weeks, or 52 for several days each week')
    && str_contains($schedSrc, "old_in('plan_pattern', 'weekly') === 'days' ? 52 : 26") && str_contains($schedSrc, "r.max=this.value==='days'?'52':'26'"),
    'T7: the Repeat weekly box stops at 26 for a weekly repeat and switches to 52 for several days each week; its label says so');

/* ---- T8: the meeting link made in the booking ---- */
$db->exec("INSERT INTO settings(name,value) VALUES ('cfg.meet.zoom_client_id','synthetic-zoom-id'),('cfg.meet.zoom_client_secret','synthetic-zoom-secret') ON DUPLICATE KEY UPDATE value=VALUES(value)");
$keepMeet438 = $db->query('SELECT * FROM meeting_accounts')->fetchAll();
$db->exec('DELETE FROM meeting_accounts');
$opts = $get438('booking_options');
// Set up = both app settings stored (this process read its configuration before they were saved).
$setUp = (array_filter(['zoom' => 'zoom', 'microsoft' => 'ms'], fn($pre) => (string)$one438("SELECT value FROM settings WHERE name='cfg.meet.{$pre}_client_id'") !== ''
    && (string)$one438("SELECT value FROM settings WHERE name='cfg.meet.{$pre}_client_secret'") !== ''));
$setUp = array_keys($setUp);
check(array_column($opts['meeting_providers'], 'key') === $setUp && in_array(['key' => 'zoom', 'label' => 'In Zoom'], $opts['meeting_providers'], true)
    && array_column($opts['meeting_providers'], 'label') === array_map(fn($k) => 'In ' . MEETING_PROVIDERS[$k], $setUp), 'T8: booking_options lists only the providers set up here, as "In Zoom" / "In Microsoft Teams"');
$mbase = ['client_id' => $cS, 'date' => date('Y-m-d', strtotime($monday . ' +1 day')), 'time' => '20:00', 'duration' => 1, 'is_remote' => true, 'meeting_make' => 'zoom'];
[$st, $d] = $req438('POST', 'schedule_session', ['is_remote' => false] + $mbase);
[$st2, $d2] = $req438('POST', 'schedule_session', ['meeting_url' => 'https://example.invalid/meet'] + $mbase);
[$st3, $d3] = $req438('POST', 'schedule_session', $mbase);
[$st4, $d4] = $req438('POST', 'schedule_session', ['staff_id' => $u438, 'client_id' => $cOther438] + $mbase);
[$st5, $d5] = $req438('POST', 'schedule_session', ['meeting_make' => 'skype'] + $mbase);
check($st === 422 && $d['errors']['meeting_make'] === 'A Zoom link is only for a remote session: tick Remote / telepractice, or choose No.'
    && $st2 === 422 && $d2['errors']['meeting_make'] === 'A meeting link is typed above and Zoom would make another: clear the link, or choose No.'
    && $st3 === 422 && $d3['errors']['meeting_make'] === $adminName438 . ' has not connected Zoom. Connect it on My Account, Online meetings, then schedule again.'
    && $st4 === 422 && $d4['errors']['meeting_make'] === 'V438 Synthetic Instructor has not connected Zoom. They connect it on their My Account page; until then choose No, or type a link.'
    && $st5 === 422 && $d5['errors']['meeting_make'] === 'Choose Zoom or Microsoft Teams for the meeting link.' && (int)$one438("SELECT COUNT(*) FROM scheduled_sessions WHERE client_id=$cS") === 0,
    'T8: not remote, a typed link, not connected (self and another) and an unknown provider are refused with the website\'s sentences');
$as438($gStaff438);
[$st, $d] = $req438('POST', 'schedule_session', ['staff_id' => $u438, 'client_id' => $cOther438] + $mbase);
$as438(null);
check($st === 422 && $d['errors']['meeting_make'] === 'A meeting can be made only in your own Zoom account. V438 Synthetic Instructor can make it, or an administrator; otherwise choose No and type a link.',
    'T8: a non-administrator cannot make a link in another instructor\'s account');
meeting_store(1, 'zoom', ['access_token' => 'v438-zoom-access', 'refresh_token' => 'v438-zoom-refresh', 'expires_in' => 3600], 'v438-zoom@example.invalid');
putenv('CMS_TEST_MEETINGS=' . json_encode(['https://api.zoom.us/v2/users/me/meetings' => ['status' => 201, 'body' => ['join_url' => 'https://zoom.us/j/4380000001']]]));
$clearMail438();
$m = $post438('schedule_session', $mbase + ['repeat_weeks' => 3, 'idem_key' => $key438()]);
$clientMail = implode("\n", array_filter($mails438(), fn($x) => str_contains($x, 'To: v438-booking@example.invalid')));
check($clientMail !== '' && str_contains($clientMail, 'https://zoom.us/j/4380000001'), 'T8: the client\'s booking notice is sent after the meeting is made, so it carries the Zoom link');
$links = $db->query("SELECT DISTINCT meeting_url FROM scheduled_sessions WHERE client_id=$cS")->fetchAll(PDO::FETCH_COLUMN);
check($m['meeting_made'] === true && $m['meeting_url'] === 'https://zoom.us/j/4380000001' && $links === ['https://zoom.us/j/4380000001'] && $m['meeting_why'] === null
    && str_contains($m['message'], ' The Zoom link was made in your account.') && $audits438('meeting.create', 'Zoom link for 3 session(s), in ' . $adminName438 . "'s account (mobile)") === 1,
    'T8: own connected Zoom: one link on every session of the series, audited "(mobile)", and the website\'s sentence');
$db->exec("DELETE FROM scheduled_sessions WHERE client_id=$cS");
putenv('CMS_TEST_MEETINGS=' . json_encode(['https://api.zoom.us/v2/users/me/meetings' => ['status' => 503, 'body' => null]]));
$m = $post438('schedule_session', $mbase + ['idem_key' => $key438()]);
check($m['meeting_made'] === false && $m['meeting_url'] === null && is_string($m['meeting_why']) && $m['meeting_why'] !== '' && str_ends_with($m['message'], ' ' . $m['meeting_why'])
    && (int)$one438("SELECT COUNT(*) FROM scheduled_sessions WHERE client_id=$cS AND meeting_url IS NULL") === 1, 'T8: Zoom failing books the session without a link and says why');
putenv('CMS_TEST_MEETINGS');
$db->exec("DELETE FROM scheduled_sessions WHERE client_id=$cS");
$page438('schedule', [], ['add_session' => '1', 'client_id' => (string)$cS, 'staff_id' => '1', 'date' => $mbase['date'], 'time' => '20:00', 'duration' => '1', 'meeting_make' => 'zoom']);
check(str_contains(json_encode($flash438()), 'A Zoom link is only for a remote session') && str_contains((string)file_get_contents($root . '/public/schedule.php'), 'session_meeting_make_error('),
    'T8: schedule.php refuses the same through session_meeting_make_error()');
$db->exec('DELETE FROM meeting_accounts');
foreach ($keepMeet438 as $row) { $cols = array_keys($row); $db->prepare('INSERT INTO meeting_accounts (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')')->execute(array_values($row)); }

/* ---- T9: the counselor portal link (inc/counselor_portal.php) ---- */
$cV = $client438('V438 Portal Client', ['counselor_name' => 'V438 Portal Counselor', 'counselor_email' => 'v438-portal@example.invalid', 'counselor_verified' => 1]);
$client438('V438 Unconfirmed Client', ['counselor_name' => 'V438 Unconfirmed', 'counselor_email' => 'v438-unconfirmed@example.invalid', 'counselor_verified' => 0]);
$clearMail438();
$kV = $key438();
$s = $post438('send_vrc_portal', ['email' => 'v438-portal@example.invalid', 'name' => 'V438 Portal Counselor', 'idem_key' => $kV]);
$sR = $post438('send_vrc_portal', ['email' => 'v438-portal@example.invalid', 'name' => 'V438 Portal Counselor', 'idem_key' => $kV]);
$mail = $mails438()[0] ?? '';
$token = preg_match('~/vrc\.php\?t=([0-9a-f]{48})~', $mail, $tm) ? $tm[1] : '';
$expected = vrc_portal_email('V438 Portal Counselor', $token);
check($s['message'] === 'Portal link emailed to V438 Portal Counselor. Any previous link no longer works.' && substr((string)$s['expires_at'], 0, 10) === date('Y-m-d', strtotime('+' . cms_number('counselor_link_days') . ' days'))
    && $token !== '' && str_contains($mail, 'Subject: ' . $expected['subject']) && str_contains($mail, $expected['body']) && $audits438('vrcportal.send', 'v438-portal@example.invalid (mobile)') === 1,
    'T9: the API sends counselors.php\'s email (vrc_portal_email()), says the website\'s sentence and when the link expires');
$tokens = (string)$db->query("SELECT GROUP_CONCAT(token) FROM vrc_tokens")->fetchColumn();
check(!empty($sR['repeat']) && $sR['message'] === $s['message'] && $sR['expires_at'] === $s['expires_at'] && count($mails438()) === 1 && !str_contains(json_encode($sR) . json_encode($s), $token)
    && !preg_match('/[0-9a-f]{48}/', (string)$one438("SELECT GROUP_CONCAT(email) FROM login_attempts WHERE email LIKE 'idem:%'")) && $tokens !== '',
    'T9: the same idem_key answers the sentence again with no second email; the link is never in a reply or a kept marker');
[$st, $d] = $req438('POST', 'send_vrc_portal', ['email' => 'v438-portal@example.invalid', 'name' => 'V438 Portal Counselor', 'idem_key' => $key438()]);
check($st === 429 && $d['error'] === 'A portal link was just sent to V438 Portal Counselor (v438-portal@example.invalid); the link in that email is the one that works. Wait a minute before sending another.', 'T9: within a minute 429 with the website\'s sentence');
[$st, $d] = $req438('POST', 'send_vrc_portal', ['email' => 'v438-unconfirmed@example.invalid']);
[$st2, $d2] = $req438('POST', 'send_vrc_portal', ['email' => 'not-an-email']);
check($st === 422 && $d['errors']['email'] === 'No link was sent: v438-unconfirmed@example.invalid has not been confirmed as the counselor on any client. Confirm the counselor on the client\'s edit page first.'
    && $st2 === 422 && $d2['errors']['email'] === 'A valid counselor email is required.', 'T9: an unconfirmed counselor and a bad address are refused on email');
// The website sends the same email through the same helper.
$db->exec("DELETE FROM login_attempts WHERE email LIKE 'vrc-portal-%'");
$clearMail438();
$page438('counselors', [], ['send_portal' => '1', 'email' => 'v438-portal@example.invalid', 'name' => 'V438 Portal Counselor']);
$webMail = $mails438()[0] ?? '';
$webToken = preg_match('~/vrc\.php\?t=([0-9a-f]{48})~', $webMail, $tm) ? $tm[1] : '';
check(str_contains($webMail, vrc_portal_email('V438 Portal Counselor', $webToken)['body']) && str_contains($flash438(), 'Portal link emailed to V438 Portal Counselor. Any previous link no longer works.')
    && $audits438('vrcportal.send', 'v438-portal@example.invalid') === 1, 'T9: counselors.php sends the identical email and sentence');
// A store failure after the email went: 500, the failure audit, the guard cleared.
$db->exec("DELETE FROM login_attempts WHERE email LIKE 'vrc-portal-%'");
$db->exec("CREATE TRIGGER v438_vrc_fail BEFORE INSERT ON vrc_tokens FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'synthetic store failure'");
try {
    [$st, $d] = $req438('POST', 'send_vrc_portal', ['email' => 'v438-portal@example.invalid', 'name' => 'V438 Portal Counselor', 'idem_key' => $key438()]);
} finally { $db->exec('DROP TRIGGER IF EXISTS v438_vrc_fail'); }
check($st === 500 && $d['error'] === 'The portal email went to V438 Portal Counselor (v438-portal@example.invalid), but its link could not be activated, so it will not open. Send a new link; any link sent before this one still works.'
    && $audits438('vrcportal.send_failed', 'v438-portal@example.invalid: emailed, but the link could not be activated (mobile)') === 1
    && (int)$one438("SELECT COUNT(*) FROM login_attempts WHERE email = 'vrc-portal-" . sprintf('%u', crc32('v438-portal@example.invalid')) . "'") === 0,
    'T9: a failure after sending is 500 with the website\'s sentence, audited vrcportal.send_failed, and a new link may be sent at once');
$db->prepare("INSERT INTO vrc_tokens (counselor_email, counselor_name, token, expires_at, last_used_at) VALUES ('v438-expired@example.invalid','V438 Expired',?, DATE_SUB(NOW(), INTERVAL 2 DAY), NULL)")->execute([bin2hex(random_bytes(24))]);
$db->exec("UPDATE vrc_tokens SET expires_at=NULL, last_used_at=NULL WHERE counselor_email='v438-portal@example.invalid'");
$list = array_column($get438('counselors')['counselors'], null, 'email');
[, $cPage] = $page438('counselors', []);
check($list['v438-portal@example.invalid']['status_lines'] === ['Does not expire', 'Never opened'] && str_contains($cPage, 'Does not expire · Never opened'), 'T9: status lines for an active link that does not expire and was never opened, as the page');
$db->exec("UPDATE vrc_tokens SET expires_at='2030-01-02 00:00:00', last_used_at='2026-11-01 10:00:00' WHERE counselor_email='v438-portal@example.invalid'");
$db->exec("UPDATE clients SET counselor_email='v438-expired@example.invalid', counselor_name='V438 Expired', counselor_verified=1 WHERE id=$cV");
$list = array_column($get438('counselors')['counselors'], null, 'email');
check(($list['v438-portal@example.invalid']['status_lines'] ?? null) === ['Expires ' . fmt_date('2030-01-02'), 'Last used ' . fmt_date('2026-11-01')]
    && $list['v438-expired@example.invalid']['status_lines'] === ['Expired ' . fmt_date(date('Y-m-d', strtotime('-2 days'))) . '; the counselor needs a new link'], 'T9: status lines for an expiring, used link and an expired one');
$r = $post438('revoke_vrc_portal', ['email' => 'v438-expired@example.invalid']);
[$st, $d] = $req438('POST', 'revoke_vrc_portal', ['email' => 'v438-expired@example.invalid']);
check($r['message'] === 'Portal link turned off for v438-expired@example.invalid. It no longer opens, even in a portal session that is already open; send a new link when they need access again.'
    && $st === 404 && $d['error'] === 'That counselor has no portal link to turn off.' && $audits438('vrcportal.revoke', 'v438-expired@example.invalid (mobile)') === 1, 'T9: turning off a link says the website\'s sentence; again 404');
$as438($gStaff438);
[$st] = $req438('POST', 'send_vrc_portal', ['email' => 'v438-portal@example.invalid']);
$as438(null);
check($st === 403, 'T9: without the admin permission 403');
$apiSrc = (string)file_get_contents($root . '/public/api.php');
check(!str_contains($apiSrc, 'Each new browser session asks for a 6-digit code emailed to you. Keep this link private.') && str_contains($apiSrc, 'vrc_portal_send(') && str_contains((string)file_get_contents($root . '/public/counselors.php'), 'vrc_portal_send('),
    'T9: api.php holds no short copy of the email; both call vrc_portal_send()');

/* ---- T10, T11: contracts (inc/contracts.php) ---- */
$k = $key438();
$cid = $post438('add_contract', ['name' => 'V438 Synthetic Grant', 'amount' => '$12,500,000.00', 'notes' => 'V438 contract notes', 'idem_key' => $k])['id'];
check((float)$one438("SELECT amount FROM contracts WHERE id=$cid") === 12500000.0 && $one438("SELECT notes FROM contracts WHERE id=$cid") === 'V438 contract notes' && $audits438('contract.add', 'V438 Synthetic Grant (mobile)') === 1,
    'T10: "$12,500,000.00" is saved as 12500000.00 (10 digits); notes saved; audited with the name');
[$st, $d] = $req438('POST', 'add_contract', ['name' => 'V438 Bad', 'amount' => '12,50']);
[$st2, $d2] = $req438('POST', 'add_contract', ['name' => 'V438 Long notes', 'notes' => str_repeat('n', 2001)]);
$page438('contracts', [], ['add_contract' => '1', 'name' => 'V438 Web Bad', 'amount' => '12,50']);
$webBad = $flash438();
$page438('contracts', [], ['add_contract' => '1', 'name' => 'V438 Web Long', 'notes' => str_repeat('n', 2001)]);
$webLong = $flash438();
check($st === 422 && $d['errors']['amount'] === 'Budget: ' . CONTRACT_MONEY_HINT && str_contains($webBad, 'Budget: ' . CONTRACT_MONEY_HINT)
    && $st2 === 422 && $d2['errors']['notes'] === 'Notes must be 2,000 characters or fewer.' && str_contains($webLong, 'Notes must be 2,000 characters or fewer.')
    && !$one438("SELECT COUNT(*) FROM contracts WHERE name IN ('V438 Bad','V438 Long notes','V438 Web Bad','V438 Web Long')"), 'T10, T11: "12,50" and 2,001-character notes are refused on the page and the API alike');
$page438('contracts', [], ['add_contract' => '1', 'name' => 'V438 Web Grant', 'amount' => '50,000.00', 'notes' => 'V438 web notes']);
$webC = (int)$one438("SELECT id FROM contracts WHERE name='V438 Web Grant'");
[, $addForm] = $page438('contracts', []);
check($webC > 0 && $one438("SELECT notes FROM contracts WHERE id=$webC") === 'V438 web notes' && (float)$one438("SELECT amount FROM contracts WHERE id=$webC") === 50000.0
    && str_contains($addForm, 'Notes (optional; up to 2,000 characters)'), 'T11: the website\'s new-contract form has Notes and saves them');
// Percent and remaining, compared with the page.
$c10 = $post438('add_contract', ['name' => 'V438 Percent Grant', 'amount' => '10000', 'end_date' => date('Y-m-d', strtotime('-3 days')), 'idem_key' => $key438()])['id'];
$sp = $post438('contract_update', ['contract_id' => $c10, 'op' => 'add_spend', 'amount' => '2,900.00', 'spent_on' => '2026-11-02', 'memo' => 'V438 memo', 'idem_key' => $key438()]);
$det = $get438('contract', ['id' => (string)$c10]);
[, $cp] = $page438('contracts', ['id' => (string)$c10]);
check($det['pct'] === 29 && $det['pct'] === contract_pct(2900, 10000) && str_contains($cp, 'Spent (29%)') && $det['remaining'] == 7100 && str_contains($cp, fmt_money($det['remaining']))
    && $det['over_budget'] === false && $det['days_text'] === 'Ended 3 days ago' && str_contains($cp, '3 days ago') && $sp['message'] === 'Spend of ' . fmt_money(2900) . ' recorded for ' . fmt_date('2026-11-02') . '.'
    && $det['spend'][0]['by'] === $adminName438, 'T10: 2,900 of 10,000 is 29% (not 28), remaining and the end-date phrase as the page; spend says who');
$c3 = $post438('add_contract', ['name' => 'V438 Third Grant', 'amount' => '3', 'idem_key' => $key438()])['id'];
$post438('contract_update', ['contract_id' => $c3, 'op' => 'add_spend', 'amount' => '1', 'idem_key' => $key438()]);
$post438('contract_update', ['contract_id' => $c3, 'op' => 'add_spend', 'amount' => '3.01', 'idem_key' => $key438()]);
$d3 = $get438('contract', ['id' => (string)$c3]);
[, $cp3] = $page438('contracts', ['id' => (string)$c3]);
$list = array_column($get438('contracts')['contracts'], null, 'id');
check($d3['pct'] === contract_pct(4.01, 3) && str_contains($cp3, 'Spent (' . $d3['pct'] . '%)') && $d3['over_budget'] === true && $d3['remaining'] == -1.01 && str_contains($cp3, fmt_money(-1.01)) && str_contains($cp3, '(over budget)')
    && $list[$c3]['pct'] === $d3['pct'] && $list[$c10]['ends_text'] === '— ended' && $list[$c10]['open_deliverables'] === 0, 'T10: an over-budget third (4.01 of 3) is the page\'s percent and remaining; the list has pct and ends_text');
[$st, $d] = $req438('POST', 'contract_update', ['contract_id' => $c10, 'op' => 'add_spend', 'amount' => '0']);
check($st === 422 && $d['errors']['amount'] === 'Amount: enter a dollar amount above 0, for example 1250 or 1,250.00, with a period before the cents.', 'T10: a spend of 0 is refused with the website\'s sentence');
$dl = $post438('contract_update', ['contract_id' => $c10, 'op' => 'add_deliverable', 'title' => 'V438 Report', 'due_date' => '2026-01-15', 'notes' => 'V438 deliverable notes', 'idem_key' => $key438()]);
$det = $get438('contract', ['id' => (string)$c10]);
check($dl['message'] === 'Deliverable added.' && $det['deliverables'][0]['notes'] === 'V438 deliverable notes' && $det['deliverables'][0]['overdue'] === true && str_contains($cp = $page438('contracts', ['id' => (string)$c10])[1], '— overdue'),
    'T10: a deliverable\'s notes are saved and returned; overdue as the page');
$did = $dl['id'];
$same = $post438('contract_update', ['contract_id' => $c10, 'op' => 'set_deliverable', 'deliverable_id' => $did, 'status' => 'Pending']);
$chg = $post438('contract_update', ['contract_id' => $c10, 'op' => 'set_deliverable', 'deliverable_id' => $did, 'status' => 'Submitted']);
check($same['message'] === 'Deliverable updated.' && $same['changed'] === false && $chg['changed'] === true && $audits438('contract.set_deliverable') === 1 && $audits438('contract.set_deliverable', 'V438 Report → Submitted (mobile)') === 1,
    'T10: the same status changes nothing and is not audited; a change is audited once');
// T11: budget, close and reopen, deletes, the closed list.
$b1 = $post438('contract_update', ['contract_id' => $c10, 'op' => 'set_budget', 'amount' => '12,000.00', 'idem_key' => $key438()]);
$b2 = $post438('contract_update', ['contract_id' => $c10, 'op' => 'set_budget', 'amount' => '12000', 'idem_key' => $key438()]);
[$st, $d] = $req438('POST', 'contract_update', ['contract_id' => $c10, 'op' => 'set_budget', 'amount' => '']);
check($b1['message'] === 'Budget changed to $12,000.00.' && $b2['message'] === 'The budget is already $12,000.00.' && $audits438('contract.budget', '$10,000.00 → $12,000.00 (mobile)') === 1
    && $st === 422 && $d['errors']['amount'] === 'Budget: ' . CONTRACT_MONEY_HINT, 'T11: the budget changes with the audit "$10,000.00 → $12,000.00"; the same again says so; blank is refused');
$x1 = $post438('contract_update', ['contract_id' => $c10, 'op' => 'set_active', 'active' => false, 'idem_key' => $key438()]);
$x2 = $post438('contract_update', ['contract_id' => $c10, 'op' => 'set_active', 'active' => false, 'idem_key' => $key438()]);
$closed = array_column($get438('contracts', ['show' => 'closed'])['contracts'], 'id');
$active = array_column($get438('contracts')['contracts'], 'id');
check($x1['message'] === 'Contract closed.' && $x2['message'] === 'That contract is already closed.' && in_array($c10, $closed, true) && !in_array($c10, $active, true) && $audits438('contract.close', 'V438 Percent Grant (mobile)') === 1,
    'T11: close, then close again; the closed list holds it');
$x3 = $post438('contract_update', ['contract_id' => $c10, 'op' => 'set_active', 'active' => true, 'idem_key' => $key438()]);
check($x3['message'] === 'Contract reopened.' && $audits438('contract.reopen', 'V438 Percent Grant (mobile)') === 1, 'T11: reopen');
$k = $key438();
$del1 = $post438('contract_update', ['contract_id' => $c10, 'op' => 'delete_deliverable', 'deliverable_id' => $did, 'idem_key' => $k]);
$del2 = $post438('contract_update', ['contract_id' => $c10, 'op' => 'delete_deliverable', 'deliverable_id' => $did, 'idem_key' => $k]);
[$st, $d] = $req438('POST', 'contract_update', ['contract_id' => $c10, 'op' => 'delete_deliverable', 'deliverable_id' => $did, 'idem_key' => $key438()]);
$spendId = (int)$one438("SELECT id FROM contract_spend WHERE contract_id=$c10");
$ds = $post438('contract_update', ['contract_id' => $c10, 'op' => 'delete_spend', 'spend_id' => $spendId, 'idem_key' => $key438()]);
check($del1['message'] === 'Deliverable removed.' && !empty($del2['repeat']) && $del2['message'] === 'Deliverable removed.' && $st === 404 && $d['error'] === 'Deliverable not found.'
    && $audits438('contract.deliverable_delete', 'V438 Report (mobile)') === 1 && $ds['message'] === 'Spend entry removed.' && $audits438('contract.spend_delete', '$2,900.00 on 2026-11-02 — V438 memo (mobile)') === 1,
    'T11: deletes with their audits; the same idem_key answers the first reply; a new delete of a gone row is 404');
$cOtherSpend = $post438('add_contract', ['name' => 'V438 Other Grant', 'idem_key' => $key438()])['id'];
$post438('contract_update', ['contract_id' => $cOtherSpend, 'op' => 'add_spend', 'amount' => '5', 'idem_key' => $key438()]);
$otherSpend = (int)$one438("SELECT id FROM contract_spend WHERE contract_id=$cOtherSpend");
[$st] = $req438('POST', 'contract_update', ['contract_id' => $c10, 'op' => 'delete_spend', 'spend_id' => $otherSpend, 'idem_key' => $key438()]);
check($st === 404 && (int)$one438("SELECT COUNT(*) FROM contract_spend WHERE id=$otherSpend") === 1, 'T11: a spend entry of another contract is never deleted');
// The page's ops through the same helpers.
$page438('contracts', [], ['contract_id' => (string)$webC, 'set_budget' => '1', 'budget' => '60,000']);
$f1 = $flash438();
$page438('contracts', [], ['contract_id' => (string)$webC, 'toggle_contract' => '1', 'to_status' => 'Closed']);
$f2 = $flash438();
[, $closedPage] = $page438('contracts', ['closed' => '1']);
check(str_contains($f1, 'Budget changed to $60,000.00.') && str_contains($f2, 'Contract closed.') && str_contains($closedPage, 'V438 Web Grant') && $audits438('contract.budget', '$50,000.00 → $60,000.00') === 1,
    'T11: contracts.php changes the budget and closes through the same helpers');
$as438($gNoBilling438);
[$st] = $req438('GET', 'contracts');
$as438(null);
check($st === 403 && !str_contains((string)file_get_contents($root . '/public/contracts.php'), 'function contract_money_in') && str_contains((string)file_get_contents($root . '/public/contracts.php'), 'contract_set_budget('),
    'T10, T11: without billing 403; contracts.php holds no copy of the money rule');

/* ---- T12: accommodation bookings (inc/bookings.php) ---- */
$k = $key438();
$bk = $post438('add_booking', ['client_id' => $c438, 'service_type' => 'CART captioning', 'needed_at' => date('Y-m-d', strtotime('+5 days')) . 'T10:00', 'cost' => '$12,345,678.90', 'duration_min' => 90, 'idem_key' => $k]);
[$st, $d] = $req438('POST', 'add_booking', ['client_id' => $c438, 'service_type' => 'Interpreter', 'cost' => '123,456,789.00']);
check((string)$one438("SELECT cost FROM access_bookings WHERE id={$bk['id']}") === '12345678.90' && $audits438('booking.add', 'CART captioning (mobile)') === 1
    && str_starts_with($bk['message'], 'CART (real-time captioning) booking created for V438 Synthetic Client: ') && $st === 422 && $d['errors']['cost'] === BOOKING_COST_HINT,
    'T12: "$12,345,678.90" is accepted; 9 digits are refused with the website\'s sentence; the audit and the label');
$rows = array_column($get438('bookings')['bookings'], null, 'id');
check($rows[$bk['id']]['type_label'] === 'CART (real-time captioning)' && $rows[$bk['id']]['when_text'] === booking_when($one438("SELECT needed_at FROM access_bookings WHERE id={$bk['id']}")), 'T12: rows carry the label and the when text');
$cn = $post438('booking_status', ['booking_id' => $bk['id'], 'to' => 'Cancelled']);
$cancelled = array_column($get438('bookings', ['view' => 'cancelled'])['bookings'], 'id');
$upcoming = array_column($get438('bookings')['bookings'], 'id');
$rs = $post438('booking_status', ['booking_id' => $bk['id'], 'to' => 'Requested']);
check(str_ends_with($cn['message'], 'set to Cancelled. It is now listed under "Show cancelled", where it can be restored.') && in_array($bk['id'], $cancelled, true) && !in_array($bk['id'], $upcoming, true)
    && $audits438('booking.status', 'Requested → Cancelled (mobile)') === 1 && $audits438('booking.status', 'Cancelled → Requested (mobile)') === 1 && str_ends_with($rs['message'], 'set to Requested.'),
    'T12: cancelled moves to view=cancelled with the website\'s sentence; restored to Requested with the audit "Cancelled → Requested (mobile)"');
$bOther = $post438('add_booking', ['client_id' => $cOther438, 'service_type' => 'Interpreter', 'idem_key' => $key438()])['id'];
$as438($gScoped438);
$scopedRows = array_column($get438('bookings', ['view' => 'past'])['bookings'], 'id');
[$st, $d] = $req438('POST', 'booking_status', ['booking_id' => $bOther, 'to' => 'Cancelled']);
$as438(null);
check(!in_array($bOther, $scopedRows, true) && $st === 403 && $one438("SELECT status FROM access_bookings WHERE id=$bOther") === 'Requested', 'T12: a scoped user cannot see or change another caseload\'s booking');
$past = $get438('bookings', ['past' => '1']);
check($past['view'] === 'history' && !in_array($bk['id'], array_column($past['bookings'], 'id'), true), 'T12: a 4.37.0 past=1 call is the history alone, as before');
$opts = $get438('form_options');
check(($opts['booking_type_labels']['CART captioning'] ?? '') === 'CART (real-time captioning)' && ($opts['booking_type_labels']['Interpreter'] ?? '') === 'Interpreter', 'T12: form_options sends each type\'s label');
$page438('access_bookings', [], ['set_status' => '1', 'booking_id' => (string)$bk['id'], 'to' => 'Cancelled']);
check(str_contains($flash438(), 'It is now listed under \\"Show cancelled\\", where it can be restored.') || str_contains($flash438(), 'It is now listed under "Show cancelled", where it can be restored.'), 'T12: access_bookings.php says the same through booking_set_status()');
check($audits438('booking.status', 'Requested → Cancelled') === 1 && !str_contains((string)file_get_contents($root . '/public/access_bookings.php'), 'const BOOKING_TYPE_LABELS'), 'T12: the page audits through the helper and holds no label list of its own');

/* ---- T13: utilization (utilization_report()) ---- */
$db->exec("UPDATE users SET weekly_hours=20 WHERE id=1");
$db->exec("INSERT INTO users (name,email,password_hash,role,weekly_hours) VALUES ('V438 Zero Target','v438-zero@example.invalid','test','instructor',0)"); $uZero = (int)$db->lastInsertId();
$db->exec("INSERT INTO users (name,email,password_hash,role,weekly_hours,is_active) VALUES ('V438 Former','v438-former@example.invalid','test','instructor',10,0)"); $uFormer = (int)$db->lastInsertId();
$tU = $track438($c438, ['hourly_rate' => 33.33, 'mileage_rate' => 0.655]);
$tNone = $track438($c438, ['billing_mode' => 'none', 'hourly_rate' => 40]);
$wl = $db->prepare('INSERT INTO work_logs (track_id, work_date, hours, mileage, description, hourly_rate, mileage_rate, created_by, invoice_id, is_billed) VALUES (?,?,?,?,?,?,?,?,?,?)');
$uFrom = '2026-06-01'; $uTo = '2026-06-28';
$wl->execute([$tU, '2026-06-02', 1.5, 15.0, 'V438 half-cent', 33.33, 0.655, 1, null, 0]);   // 49.995 -> 50.00, 9.825 -> 9.83
$wl->execute([$tU, '2026-06-03', 0.25, 0, 'V438 quarter', 33.33, null, 1, null, 0]);         // 8.3325 -> 8.33
$wl->execute([$tNone, '2026-06-04', 2, 0, 'V438 no charge', null, null, 1, null, 0]);        // no value
$noChargeInv = $inv438($tNone, 'V438-NC-INV');
$wl->execute([$tNone, '2026-06-05', 1, 0, 'V438 no charge invoiced', 40, null, 1, $noChargeInv, 1]);   // invoiced: 40.00
$wl->execute([$tU, '2026-06-06', 1.5, 0, 'V438 zero target', 33.33, null, $uZero, null, 0]);
$wl->execute([$tU, '2026-06-07', 3, 0, 'V438 former', 33.33, null, $uFormer, null, 0]);
$u = $get438('utilization', ['from' => $uFrom, 'to' => $uTo]);
[, $upage] = $page438('utilization', ['from' => $uFrom, 'to' => $uTo]);
[, $ucsv] = $page438('utilization', ['from' => $uFrom, 'to' => $uTo, 'export' => '1']);
$csvRows = array_map('str_getcsv', array_filter(explode("\n", str_replace("\xEF\xBB\xBF", '', $ucsv))));
$csvBy = []; foreach ($csvRows as $cr) $csvBy[$cr[0]] = $cr;
$apiBy = array_column($u['rows'], null, 'name');
$admin = $apiBy[$adminName438];
$adminRow = preg_match('~<th scope="row">' . preg_quote(h($adminName438), '~') . '</th>(.*?)</tr>~s', $upage, $ur) ? $ur[1] : '';
check($admin['value'] == 108.16 && str_contains($adminRow, '<td class="num">' . fmt_money($admin['value']) . '</td>') && (float)$csvBy[$adminName438][5] == $admin['value'] && $csvBy[$adminName438][4] === $admin['util'] . '%',
    'T13: a row\'s value (half-cent rates, no-charge work, invoiced no-charge) is ' . fmt_money($admin['value']) . ' on the API, the page and the CSV');
check(str_contains($upage, '<span class="stat-num">' . $u['org_util'] . '%</span><span class="stat-label">Team utilization</span>') && str_contains($upage, fmt_money($u['total_value']))
    && $csvBy['TOTAL'][4] === $u['org_util'] . '%' && (float)$csvBy['TOTAL'][5] == $u['total_value'] && (float)$csvBy['TOTAL'][3] == $u['total_delivered']
    && $u['former_staff_value'] == round(3 * 33.33, 2) && (float)$csvBy['Former or unrecorded staff'][5] == $u['former_staff_value'] && $u['no_target_delivered'] == 1.5,
    'T13: team utilization, the total value and the former staff\'s value equal the page and its CSV');
$last = end($u['rows']);
$names = array_column($u['rows'], 'name');
check($apiBy['V438 Zero Target']['no_target'] === true && array_search('V438 Zero Target', $names, true) > array_search($adminName438, $names, true) && $last['no_target'] === true
    && $u['weeks'] == 4 && $u['footer'] === UTILIZATION_FOOTER && $u['scoped_note'] === null && $u['from'] === $uFrom, 'T13: no-target rows last; weeks, the footer, no scoped note for an unscoped user');
// at_or_above at exactly 100: 20 hours a week for 4 weeks is 80 hours.
$wl->execute([$tU, '2026-06-08', round(80 - $admin['delivered'], 2), 0, 'V438 to one hundred', 1, null, 1, null, 0]);
$u2 = $get438('utilization', ['from' => $uFrom, 'to' => $uTo]);
$a2 = array_column($u2['rows'], null, 'name')[$adminName438];
check($a2['util'] === 100 && $a2['at_or_above'] === true, 'T13: at exactly 100% the row is at or above target');
// The remembered period: a preset is remembered in the setting the page reads.
$u3 = $get438('utilization', ['period' => 'this_quarter']);
$u4 = $get438('utilization');
[, $upage4] = $page438('utilization', []);
[$qf, $qt] = report_period_dates('this_quarter');
check($u3['period'] === 'this_quarter' && $u3['from'] === $qf && $u3['to'] === $qt && $u4['from'] === $qf && $u4['period'] === 'this_quarter' && setting('report_period_1') === json_encode(['preset' => 'this_quarter'])
    && str_contains($upage4, h(fmt_date($qf) . ' to ' . fmt_date($qt))) && array_column($u3['presets'], 'key') === array_keys(REPORT_PERIOD_PRESETS), 'T13: period=this_quarter is remembered and the next call, and the page, use it');
$sw = $get438('utilization', ['from' => '2026-06-28', 'to' => '2026-06-01']);
$bd = $get438('utilization', ['from' => '2026-02-30', 'to' => '2026-06-01']);
check($sw['from'] === '2026-06-01' && $sw['notes'] === ['The From date was after the To date, so the two were swapped.'] && array_filter($bd['notes'], fn($n) => str_starts_with($n, 'The From date could not be read, so the From date now shown was used instead.')) !== [],
    'T13: from after to is swapped with the website\'s note; a bad date is a note, not a 422');
$as438($gScoped438);
$us = $get438('utilization', ['from' => $uFrom, 'to' => $uTo]);
$as438(null);
check($us['scoped_note'] === UTILIZATION_SCOPED_NOTE, 'T13: a scoped user gets the scoped note');
$apiUtil = substr($apiSrc, (int)strpos($apiSrc, "case 'utilization':"), 3000);
check(str_contains($apiUtil, 'utilization_report(') && str_contains($apiUtil, 'report_period(') && !str_contains($apiUtil, 'SUM(CASE WHEN w.invoice_id IS NULL'), 'T13: api.php\'s utilization calls utilization_report() and report_period() and holds no copy');

/* ---- T14: service profitability groups ---- */
// Its own invoices: part-paid with a write-off, and a fully paid one, on a track with a service type.
$tP = $track438($c438, ['title' => 'V438 profit track', 'service_type' => 'V438 Profit Service', 'funding_agency' => 'V438 Profit Funder']);
$inv438($tP, 'V438-PROFIT-1', ['status' => 'Submitted', 'total_amount' => 1000.10, 'paid_amount' => 600.05, 'written_off' => 0.05, 'submitted_at' => $today]);
$inv438($tP, 'V438-PROFIT-2', ['status' => 'Paid', 'total_amount' => 333.33, 'paid_amount' => 333.33, 'submitted_at' => $today]);
$db->prepare("INSERT INTO track_expenses (track_id, expense_date, description, amount) VALUES (?, CURDATE(), 'V438 profit expense', 99.99)")->execute([$tP]);
$p = $get438('at_profitability');
$pg = array_column($p['groups']['service'], null, 'label')['V438 Profit Service'] ?? null;
check($pg !== null && $pg['invoiced'] == 1333.43 && $pg['received'] == 933.38 && $pg['cost'] == 99.99 && $pg['margin'] == round(1333.43 - 99.99, 2),
    'T14: a seeded part-paid, written-off and paid track: submitted ' . fmt_money(1333.43) . ', received ' . fmt_money(933.38) . ', direct cost ' . fmt_money(99.99));
// at_services.php includes bootstrap without spaces, so it is copied as tests/at_service_cases.php copies it.
$atSrc = swap_required("require __DIR__.'/inc/bootstrap.php';", 'require ' . var_export($tmp . '/bootstrap.php', true) . ';', (string)file_get_contents($root . '/public/at_services.php'), 'public/at_services.php');
file_put_contents($tmp . '/at_page438.php', str_replace('__DIR__', var_export($root . '/public', true), $atSrc));
foreach (glob($tmp . '/sess_*') ?: [] as $f) @unlink($f);
$atProc = proc_open([PHP_BINARY, '-d', 'display_errors=stderr', $root . '/tests/full_schema.php', 'page', $tmp . '/at_page438.php', json_encode(['kind' => 'cost'])], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $atPipes);
$atPage = stream_get_contents($atPipes[1]); $atErr = stream_get_contents($atPipes[2]); fclose($atPipes[1]); fclose($atPipes[2]); proc_close($atProc);
if ($atErr !== '') throw new RuntimeException('at_services.php wrote to stderr: ' . $atErr);
$ok14 = $p['intro'] === AT_PROFIT_INTRO && $p['footer'] === AT_PROFIT_FOOTER && isset($p['groups']['service'], $p['groups']['contract']);
foreach (['service', 'contract'] as $gk) {
    $mine = at_profit_groups($p['rows'], $gk);
    $ok14 = $ok14 && array_column($p['groups'][$gk], 'label') === array_map('strval', array_keys($mine));
    foreach ($p['groups'][$gk] as $g) {
        $ok14 = $ok14 && $g['invoiced'] == round($mine[$g['label']]['invoiced'], 2) && $g['margin'] == round($mine[$g['label']]['margin'], 2)
            && str_contains($atPage, '<th scope="row">' . h($g['label']) . '</th><td>' . h(fmt_money($g['invoiced'])) . '</td><td>' . h(fmt_money($g['received'])) . '</td><td>' . h(fmt_money($g['cost'])) . '</td><td>' . h(fmt_money($g['margin'])) . '</td>');
    }
}
check($ok14 && $p['groups']['service'] !== [], 'T14: groups equal at_profit_groups() and the page\'s "By service" and "By linked contract" figures');
$as438($gNoBilling438);
[$st] = $req438('GET', 'at_profitability');
$as438(null);
check($st === 403, 'T14: without billing 403');

/* ---- T15: data quality and the documentation audit ---- */
$dq = $get438('data_quality');
$checks = data_quality_checks(null);
check(array_column($dq['sections'], 'key') === array_column($checks, 0) && $dq['summary'] === data_quality_summary($checks, false)
    && array_map(fn($s) => count($s['rows']), $dq['sections']) === array_map(fn($c) => count($c[3]), $checks) && !str_contains(json_encode($dq), 'client_merge.php'),
    'T15: data_quality sends data_quality_checks() without the merge link');
$cGap = $client438('V438 Gap Client');
$db->prepare("INSERT INTO client_notes (client_id, note_type, subject, body, author_id, created_at) VALUES (?, 'Case Note', 'V438 old', 'b', 1, DATE_SUB(NOW(), INTERVAL 200 DAY))")->execute([$cGap]);
$da = $get438('doc_audit');
$gap = array_values(array_filter($da['rows'], fn($r) => $r['client_id'] === $cGap))[0] ?? null;
[, $daPage] = $page438('doc_audit', []);
$ref = doc_audit_findings(null);
check($gap !== null && in_array('No tech assessment on file', $gap['findings'], true) && in_array('No progress note in ' . cms_number('doc_audit_activity_days') . '+ days', $gap['findings'], true)
    && $da['rows'] === $ref['rows'] && $da['active'] === $ref['active'] && $da['with_gaps'] === count($ref['rows']) && str_contains($daPage, '<span class="stat-num">' . $da['total_findings'] . '</span>')
    && $da['empty'] === 'No documentation gaps found.' && count($da['checks']) === 5, 'T15: doc_audit rows equal the page\'s for seeded gaps (no tech assessment, an old progress note)');
$as438($gScoped438);
$das = $get438('doc_audit');
$dqs = $get438('data_quality');
$as438($gNoClients438);
[$st] = $req438('GET', 'doc_audit'); [$st2] = $req438('GET', 'data_quality');
$as438(null);
check($das['scoped'] === true && !in_array($cOther438, array_column($das['rows'], 'client_id'), true) && str_ends_with($dqs['summary'], ' on your caseload.') && $st === 403 && $st2 === 403,
    'T15: a scoped user sees only their caseload; without clients 403');
check(!str_contains((string)file_get_contents($root . '/public/doc_audit.php'), '👍') && str_contains((string)file_get_contents($root . '/public/doc_audit.php'), 'doc_audit_findings('), 'T15: doc_audit.php calls doc_audit_findings() and the emoji is gone');

/* ---- T16: the organisation's name in me ---- */
$db->exec("INSERT INTO settings(name,value) VALUES ('cfg.brand.org','Synthetic Agency') ON DUPLICATE KEY UPDATE value=VALUES(value)");
$me = $get438('me');
check($me['org'] === 'Synthetic Agency' && str_contains($apiSrc, "'org' => brand('org')"), 'T16: me.org is brand(\'org\')');

/* ---- Contract and booking changes need POST (a GET with a body changes nothing) ---- */
$beforeC = (int)$one438('SELECT COUNT(*) FROM contracts'); $beforeB = (int)$one438('SELECT COUNT(*) FROM access_bookings');
$gets = [];
foreach ([['add_contract', ['name' => 'V438 GET contract']], ['contract_update', ['contract_id' => $c10, 'op' => 'set_active', 'active' => false]],
          ['add_booking', ['client_id' => $c438, 'service_type' => 'Interpreter']], ['booking_status', ['booking_id' => $bk['id'], 'to' => 'Completed']]] as [$act, $body]) {
    [$st, $d] = $req438('GET', $act, $body);
    if ($st !== 405 || $d['error'] !== 'Use POST for changes.') $gets[] = "$act $st";
}
check($gets === [] && (int)$one438('SELECT COUNT(*) FROM contracts') === $beforeC && (int)$one438('SELECT COUNT(*) FROM access_bookings') === $beforeB
    && $one438("SELECT status FROM contracts WHERE id=$c10") === 'Active' && $one438("SELECT status FROM access_bookings WHERE id={$bk['id']}") !== 'Completed',
    'add_contract, contract_update, add_booking and booking_status refuse a GET (405) and change nothing' . ($gets ? ': ' . implode(', ', $gets) : ''));

/* ---- Every new action: the permission, and POST for changes ---- */
$as438($gNoClients438);
$db->exec("UPDATE `groups` SET perms='schedule' WHERE id=$gNoClients438");
$refused = [];
foreach (['submit_invoices' => 'POST', 'invoice_document' => 'GET', 'incoming_authorizations' => 'GET', 'read_authorization_email' => 'POST', 'data_quality' => 'GET'] as $act => $method) {
    [$st, $d] = $req438($method, $act, $method === 'POST' ? ['idem_key' => $key438()] : []);
    if ($st !== 403 || !str_starts_with((string)$d['error'], 'Your account does not have access')) $refused[] = "$act $st";
}
$as438(null);
[$st] = $req438('GET', 'submit_invoices');
check($refused === [] && $st === 405, 'Every new action needs its permission (403), and a change needs POST (405)' . ($refused ? ': ' . implode(', ', $refused) : ''));
} finally {
    // Put back the temporary core, every setting this file changed, the user's group and the groups.
    file_put_contents($tmp . '/core.php', $originalCore438);
    putenv('CMS_TEST_MEETINGS');
    $db->exec('DROP TRIGGER IF EXISTS v438_vrc_fail');
    $db->exec('UPDATE users SET group_id=NULL WHERE id=1');
    $db->exec('DELETE FROM settings');
    $restore438 = $db->prepare('INSERT INTO settings (name, value) VALUES (?, ?)');
    foreach ($settings438 as $name => $value) $restore438->execute([$name, $value]);
    $db->exec('DELETE FROM `groups` WHERE name LIKE ' . $db->quote('V438 %'));
    putenv('CMS_TEST_SESSION_ADD');
}
check($db->query('SELECT name, value FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR) == $settings438 && file_get_contents($tmp . '/core.php') === $originalCore438,
    'v438: every setting and the temporary core are as they were before this file');
