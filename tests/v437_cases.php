<?php
// Included by full_schema.php after v436_cases.php, against its synthetic database only:
// 4.37.0, services, equipment and reports in the apps (plan-4370 S1-S27, the server parts S1-S11,
// S13-S19, S21, S23-S25 and S27). Each block is one item. Every request goes through api.php's own
// input parsing, with its HTTP status, and each helper the website pages now share is checked
// through its page too. The AI provider, the mail service and Zoom are never called: the AI
// transport is substituted (as v434), mail goes to a sink folder (the staging copy's "mail off"),
// and CMS_TEST_MEETINGS answers as Zoom would. Synthetic people and records only; every setting,
// group and the temporary core this file changes are put back at its end.
$GLOBALS['CURRENT_PERMS'] = array_keys(PERMISSION_KEYS); $GLOBALS['CURRENT_SCOPED'] = false;
$db->exec('UPDATE users SET group_id=NULL WHERE id=1');
$settings437 = $db->query('SELECT name, value FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR);
$db->exec("DELETE FROM login_attempts");
putenv('CMS_TEST_SESSION_ADD=' . json_encode(['credential_version' => credential_version($db->query('SELECT * FROM users WHERE id=1')->fetch())]));
$adminName437 = (string)$db->query('SELECT name FROM users WHERE id=1')->fetchColumn();
$key437 = fn(): string => 'v437-' . bin2hex(random_bytes(8));
// Audit entries written by this file only (after its first row), so earlier files' entries never count.
$auditFrom437 = (int)$db->query('SELECT COALESCE(MAX(id), 0) FROM audit_log')->fetchColumn();
$audits437 = fn(string $action, string $like = '%'): int => (int)$db->query('SELECT COUNT(*) FROM audit_log WHERE id > ' . $auditFrom437 . ' AND action=' . $db->quote($action) . ' AND detail LIKE ' . $db->quote($like))->fetchColumn();
$one437 = fn(string $sql) => $db->query($sql)->fetchColumn();

// The substituted AI transport and the mail sink, in the temporary core every request and page loads.
$originalCore437 = file_get_contents($tmp . '/core.php');
$aiSource437 = file_get_contents($root . '/public/inc/ai.php');
$aiStart437 = strpos($aiSource437, 'function ai_http('); $aiEnd437 = $aiStart437 === false ? false : strpos($aiSource437, "/**\n * Send a system", $aiStart437);
if ($aiStart437 === false || $aiEnd437 === false) throw new RuntimeException('Test harness: could not locate ai_http() in inc/ai.php. Refusing to continue: the real network transport would be used.');
$replyFile437 = $tmp . '/ai437_reply.json'; $sentFile437 = $tmp . '/ai437_request.json';
$stub437 = 'function ai_http(string $url,array $headers,string $body): array { file_put_contents(' . var_export($sentFile437, true) . ', $body); $r = json_decode((string)@file_get_contents(' . var_export($replyFile437, true) . '), true) ?: ["content" => "Synthetic reply", "finish" => "stop"]; return [200,["choices"=>[["message"=>["content"=>$r["content"]],"finish_reason"=>$r["finish"]]]],""]; }' . "\n\n";
file_put_contents($tmp . '/ai437.php', substr($aiSource437, 0, $aiStart437) . $stub437 . substr($aiSource437, $aiEnd437));
$sink437 = $tmp . '/mail437'; @mkdir($sink437, 0700);
$core437 = swap_required(var_export($root . '/public/inc', true) . " . '/ai.php'", var_export($tmp . '/ai437.php', true), $originalCore437, 'the temporary core.php (AI transport stub)');
$core437 = swap_required("\n\$GLOBALS['CMS_PRIVATE_DIR'] = ", "\n\$CONFIG['mail_disabled'] = true; \$CONFIG['mail_sink'] = " . var_export($sink437, true) . ";\n\$GLOBALS['CMS_PRIVATE_DIR'] = ", $core437, 'the temporary core.php (mail sink)');
file_put_contents($tmp . '/core.php', $core437);
$reply437 = fn(string $content, string $finish = 'stop') => file_put_contents($replyFile437, json_encode(['content' => $content, 'finish' => $finish]));
$sent437 = function () use ($sentFile437): string { $b = json_decode((string)@file_get_contents($sentFile437), true); return is_array($b) ? (string)json_encode($b['messages'] ?? $b, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : ''; };
$mails437 = fn(): array => array_map('file_get_contents', glob($sink437 . '/*.txt') ?: []);
foreach (['ai_enabled' => '1', 'ai_api_key' => $secrets['ai'], 'ai_provider' => 'openai', 'ai_redact' => '1', 'ai_feat_draft' => '1', 'ai_feat_kb' => '1', 'ai_feat_translate' => '1',
          'ai_feat_remediate' => '1', 'ai_feat_recommend' => '1', 'ai_feat_progress' => '1', 'ai_feat_voice' => '1'] as $k => $v)
    $db->prepare('INSERT INTO settings(`name`,value) VALUES (?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)')->execute([$k, $v]);

// Any request with its HTTP status.
file_put_contents($tmp . '/v437_req.php', '<?php register_shutdown_function(function () { fwrite(STDERR, "\nV437STATUS:" . (int)http_response_code()); }); $_GET = json_decode($argv[1], true); $_SERVER["HTTP_AUTHORIZATION"] = "Bearer " . str_repeat("a", 64); $_SERVER["REQUEST_METHOD"] = $argv[2]; require ' . var_export($tmp . '/api.php', true) . ';');
$req437 = function (string $method, string $action, array $body = [], array $query = []) use ($tmp): array {
    putenv('CMS_TEST_INPUT=' . json_encode($body === [] ? new stdClass() : $body));
    $p = proc_open([PHP_BINARY, '-d', 'sendmail_path=/usr/bin/true', $tmp . '/v437_req.php', json_encode(['action' => $action] + $query), $method], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); proc_close($p);
    $status = preg_match('/V437STATUS:(\d+)/', $err, $m) ? (int)$m[1] : 0;
    $d = json_decode($out, true);
    if (!is_array($d)) throw new RuntimeException("$method $action: $err $out");
    if (($d['error'] ?? '') === 'Something went wrong on the server. Nothing was saved.') throw new RuntimeException("$method $action crashed: $err");
    return [$status, $d];
};
$get437 = function (string $action, array $query = []) use ($req437): array {
    [$st, $d] = $req437('GET', $action, [], $query);
    if ($st !== 200 || empty($d['ok'])) throw new RuntimeException("GET $action: $st " . json_encode($d));
    return $d;
};
$post437 = function (string $action, array $body = []) use ($req437): array {
    [$st, $d] = $req437('POST', $action, $body);
    if ($st !== 200 || empty($d['ok'])) throw new RuntimeException("POST $action: $st " . json_encode($d));
    return $d;
};
$page437 = function (string $name, array $query, ?array $post = null) use ($renderPage, $tmp): array {
    foreach (glob($tmp . '/sess_*') ?: [] as $f) @unlink($f);
    $res = $renderPage($name, $query, $post);
    // A page may never print a warning or error while it does what this file checks.
    if ($res[2] !== '') throw new RuntimeException("$name.php wrote to stderr: " . $res[2]);
    return $res;
};
$flash437 = function () use ($tmp): string {
    $files = glob($tmp . '/sess_*') ?: [];
    usort($files, fn($a, $b) => filemtime($b) <=> filemtime($a) ?: strcmp($b, $a));
    return $files ? (string)file_get_contents($files[0]) : '';
};
// Groups for user 1: caseload-scoped staff, and staff without one permission each.
$group437 = function (string $name, string $perms, int $scoped = 0) use ($db): int { $db->prepare('INSERT INTO `groups` (name, perms, own_caseload_only) VALUES (?,?,?)')->execute([$name, $perms, $scoped]); return (int)$db->lastInsertId(); };
$gScoped437 = $group437('V437 scoped', 'clients;schedule;referrals;equipment;procurement;reports;programs', 1);
$gNoEquip437 = $group437('V437 no equipment', 'clients;schedule');
$gNoReports437 = $group437('V437 no reports', 'clients;procurement;equipment');
$gStaff437 = $group437('V437 staff', 'clients;schedule;referrals;equipment;procurement;reports;programs');
$as437 = fn(?int $g) => $db->exec('UPDATE users SET group_id=' . ($g === null ? 'NULL' : $g) . ' WHERE id=1');
$db->exec("INSERT INTO users (name,email,password_hash,role) VALUES ('V437 Synthetic Instructor','v437-instructor@example.invalid','test','instructor')"); $u437 = (int)$db->lastInsertId();
$client437 = function (string $name, array $cols = []) use ($db): int {
    $cols = ['name' => $name] + $cols + ['status' => 'Active Training', 'assigned_to' => 1, 'state' => 'IA'];
    $db->prepare('INSERT INTO clients (' . implode(',', array_keys($cols)) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')')->execute(array_values($cols));
    return (int)$db->lastInsertId();
};
$track437 = function (int $client, array $cols = []) use ($db): int {
    $cols = $cols + ['client_id' => $client, 'title' => 'V437 synthetic track', 'auth_number' => 'V437-AUTH', 'funding_agency' => 'State VR', 'authorized_hours' => 40, 'hourly_rate' => 50, 'status' => 'Active'];
    $db->prepare('INSERT INTO training_tracks (' . implode(',', array_keys($cols)) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')')->execute(array_values($cols));
    return (int)$db->lastInsertId();
};
$item437 = function (string $name, string $status = 'Available', array $cols = []) use ($db): int {
    $cols = ['name' => $name, 'status' => $status] + $cols + ['category' => 'Other'];
    $db->prepare('INSERT INTO equipment (' . implode(',', array_keys($cols)) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')')->execute(array_values($cols));
    return (int)$db->lastInsertId();
};
$c437 = $client437('V437 Synthetic Client', ['email' => 'v437-client@example.invalid', 'email_verified' => 1, 'counselor_name' => 'V437 Synthetic Counselor',
    'counselor_email' => 'v437-counselor@example.invalid', 'counselor_verified' => 1, 'ipe_goal' => 'Synthetic data entry clerk']);
$cOther437 = $client437('V437 Other Caseload Client', ['assigned_to' => $u437]);

try {
/* ---- S1: equipment status ---- */
$eq = $item437('V437 Synthetic Magnifier');
$db->prepare("INSERT INTO tasks (title, auto_key, status) VALUES ('V437 service due', ?, 'Open')")->execute(['equip-maint-' . $eq . '-1']); $taskEq = (int)$db->lastInsertId();
$r = $post437('set_equipment_status', ['equipment_id' => $eq, 'to' => 'Repair']);
check($r['message'] === 'Status updated.' && $r['status'] === 'Repair' && $r['item']['status'] === 'Repair' && $audits437('equipment.status', 'Available to Repair (mobile)') === 1,
    'S1: Available to Repair says "Status updated." and audits equipment.status "Available to Repair (mobile)"');
$r = $post437('set_equipment_status', ['equipment_id' => $eq, 'to' => 'Repair']);
check($r['message'] === 'Status is already Repair.' && $r['changed'] === false, 'S1: the same change again answers "Status is already Repair." (no second audit)');
$db->prepare("INSERT INTO equipment_reservations (equipment_id, client_id, status, reserved_by) VALUES (?,?,'Waitlist',1)")->execute([$eq, $c437]);
$r = $post437('set_equipment_status', ['equipment_id' => $eq, 'to' => 'Available']);
check($r['promoted'] === true && str_ends_with($r['message'], 'The item is now held for the client who has waited longest on its waitlist.')
    && $one437("SELECT status FROM equipment_reservations WHERE equipment_id=$eq") === 'Reserved', 'S1: back in service promotes the waitlisted client, with the website\'s sentence');
$db->prepare("INSERT INTO equipment_reservations (equipment_id, client_id, status, reserved_by) VALUES (?,?,'Waitlist',?)")->execute([$eq, $cOther437, $u437]);
$d = $get437('equipment_detail', ['id' => $eq]);
check($d['holds'] === 2 && $d['status_actions'] === ['Repair', 'Retired'] && $d['on_loan'] === false && $d['in_workflow'] === false && $d['manageable'] === true
    && str_starts_with($d['retire_text'], '2 reservations and waitlist entries for this item will be cancelled'), 'S1: equipment_detail sends holds, the website\'s moves and the confirmation sentence');
[$st, $d] = $req437('POST', 'set_equipment_status', ['equipment_id' => $eq, 'to' => 'Retired']);
check($st === 409 && !empty($d['needs_confirm']) && $d['retire']['holds'] === 2 && $one437("SELECT status FROM equipment WHERE id=$eq") === 'Available',
    'S1: Retired without confirm_retire is 409 needs_confirm with the holds, and nothing changes');
$notesBefore = (int)$one437("SELECT COUNT(*) FROM notifications WHERE user_id=$u437");
$r = $post437('set_equipment_status', ['equipment_id' => $eq, 'to' => 'Retired', 'confirm_retire' => true]);
check(str_contains($r['message'], '2 reservations were cancelled because the item is retired.') && (int)$one437("SELECT COUNT(*) FROM equipment_reservations WHERE equipment_id=$eq AND status='Cancelled'") === 2
    && $one437("SELECT status FROM tasks WHERE id=$taskEq") === 'Done' && (int)$one437("SELECT COUNT(*) FROM notifications WHERE user_id=$u437") - $notesBefore === 1
    && $audits437('equipment.status', 'Available to Retired; 2 reservations cancelled (mobile)') === 1 && $r['item']['book_value'] == 0,
    'S1: retiring with confirm cancels both holds, closes the service task, tells the other client\'s instructor (never the person retiring it), audits the count and the item\'s book value is 0');
[$st, $d] = $req437('POST', 'set_equipment_status', ['equipment_id' => $eq, 'to' => 'Available']);
check($st === 409 && str_starts_with($d['error'], 'This item is retired, so it was not put back into service here.'), 'S1: Retired to Available is 409 with the reuse sentence');
$eqLoan = $item437('V437 Synthetic Loaner', 'On Loan');
$db->prepare("INSERT INTO equipment_loans (equipment_id, client_id, loaned_at) VALUES (?,?,NOW())")->execute([$eqLoan, $cOther437]);
[$st, $d] = $req437('POST', 'set_equipment_status', ['equipment_id' => $eqLoan, 'to' => 'Repair']);
check($st === 409 && $d['error'] === 'This item is on loan, so its status was not changed. Mark it returned first.', 'S1: an item on loan is 409 with the loan sentence');
[$st, $d] = $req437('POST', 'set_equipment_status', ['equipment_id' => $eqLoan, 'to' => 'Lost']);
check($st === 422 && isset($d['errors']['to']), 'S1: to "Lost" is 422 on errors.to');
[$st, $d] = $req437('POST', 'set_equipment_status', ['equipment_id' => 999999, 'to' => 'Repair']);
check($st === 404, 'S1: an unknown item is 404');
$as437($gScoped437);
[$st, $d] = $req437('POST', 'set_equipment_status', ['equipment_id' => $eqLoan, 'to' => 'Repair']);
$dd = $get437('equipment_detail', ['id' => $eqLoan]);
$as437($gNoEquip437);
[$st2] = $req437('POST', 'set_equipment_status', ['equipment_id' => $eqLoan, 'to' => 'Repair']);
$as437(null);
check($st === 403 && $d['error'] === 'This item is managed by the assigned staff.' && $dd['manageable'] === false && $dd['status_actions'] === [] && $st2 === 403,
    'S1: an item lent to another caseload is 403 "managed by the assigned staff" for a scoped user; without the equipment permission 403');
[$st, $d] = $req437('GET', 'set_equipment_status', ['equipment_id' => $eq, 'to' => 'Repair']);
check($st === 405, 'S1: set_equipment_status needs a POST');
$eqWeb = $item437('V437 Web Item');
[$code, , $err] = $page437('equipment', [], ['set_status' => '1', 'equipment_id' => (string)$eqWeb, 'to' => 'Repair']);
check($one437("SELECT status FROM equipment WHERE id=$eqWeb") === 'Repair' && str_contains($flash437(), 'Status updated.') && $audits437('equipment.status', 'Available to Repair') === 1,
    'S1: equipment.php\'s Save gives the same row, flash and audit (shared helper)' . ($err ? ": $err" : ''));
[$code, $out, $err] = $page437('equipment', ['retire' => (string)$eqWeb]);
check($code === 0 && $err === '' && str_contains($out, 'Retire V437 Web Item?') && str_contains($out, 'No reservations or waitlist entries are affected.'), 'S1: equipment.php\'s confirmation page reads the shared preview' . ($err ? ": $err" : ''));

/* ---- S2: retired items listed on request ---- */
$list = $get437('equipment');
$listAll = $get437('equipment', ['include_retired' => 1]);
$last = end($listAll['equipment']);
check(!in_array($eq, array_map('intval', array_column($list['equipment'], 'id')), true) && $last['status'] === 'Retired' && $last['retired'] === true
    && !isset($list['equipment'][0]['retired']), 'S2: without include_retired no retired row (as 4.36.0); with it retired rows come last, marked retired');
[$st, $d] = $req437('POST', 'loan_equipment', ['equipment_id' => $eq, 'client_id' => $c437, 'idem_key' => $key437()]);
check($st >= 400 && $st < 500 && empty($d['ok']), 'S2: lending a retired item is still refused');

/* ---- S3: purchase date, useful life and AT type ---- */
$r = $post437('add_equipment', ['name' => 'V437 Synthetic Display', 'purchase_date' => date('Y-m-d', strtotime('-1 year')), 'useful_life_months' => '48', 'at_type' => 'Vision', 'idem_key' => $key437()]);
$row = $db->query('SELECT * FROM equipment WHERE id=' . (int)$r['id'])->fetch();
check($row['purchase_date'] === date('Y-m-d', strtotime('-1 year')) && (int)$row['useful_life_months'] === 48 && $row['at_type'] === 'Vision', 'S3: add_equipment stores the purchase date, useful life and AT type');
[$st, $d] = $req437('POST', 'add_equipment', ['name' => 'V437 Bad life', 'useful_life_months' => '0']);
[$st2, $d2] = $req437('POST', 'add_equipment', ['name' => 'V437 Bad date', 'purchase_date' => date('Y-m-d', strtotime('+1 day'))]);
[$st3, $d3] = $req437('POST', 'add_equipment', ['name' => str_repeat('n', 191)]);
check($st === 422 && $d['errors']['useful_life_months'] === 'Useful life: enter a whole number of months from 1 to 600, or leave it blank for 36.'
    && $st2 === 422 && $d2['errors']['purchase_date'] === 'Purchase date cannot be in the future.' && $st3 === 422 && $d3['errors']['name'] === 'Item name must be 190 characters or fewer.',
    'S3: life 0, a future purchase date and a 191-character name are refused on their fields with the website\'s sentences');
$r = $post437('add_equipment', ['name' => 'V437 Old app item', 'condition' => 'Good', 'at_type' => 'Not a type', 'idem_key' => $key437()]);
$row = $db->query('SELECT * FROM equipment WHERE id=' . (int)$r['id'])->fetch();
check((int)$row['useful_life_months'] === 36 && $row['at_type'] === null && $row['item_condition'] === 'Good', 'S3: a 4.36.0-style body saves with life 36; an unknown AT type is NULL (guessed from the category)');
[$code, , $err] = $page437('equipment', [], ['add_item' => '1', 'name' => 'V437 Web bad life', 'useful_life_months' => '700']);
check(!$one437("SELECT COUNT(*) FROM equipment WHERE name='V437 Web bad life'") && str_contains($flash437(), 'Useful life: enter a whole number of months from 1 to 600'), 'S3: equipment.php refuses the same (shared equipment_input)' . ($err ? ": $err" : ''));
$fo = $get437('form_options');
check(count($fo['lists']['at_types']) === 10 && $fo['lists']['at_types'] === AT_ACT_TYPES, 'S3: form_options.lists.at_types holds the ten AT Act types');

/* ---- S4: delivery, deny or cancel, and the justification letter ---- */
$newReq = function (string $name) use ($db, $c437): int { $db->prepare("INSERT INTO device_requests (client_id, requested_by, device_name, category, status) VALUES (?,1,?,'Other','Ordered')")->execute([$c437, $name]); return (int)$db->lastInsertId(); };
$dr = $newReq('V437 Synthetic Braille Display');
$r = $post437('device_request_update', ['id' => $dr, 'op' => 'deliver', 'add_to_inventory' => true, 'serial' => 'V437-SER']);
check($r['message'] === 'Delivery recorded and the device added to the loaner inventory.' && (int)$one437("SELECT COUNT(*) FROM equipment WHERE notes='From device request #$dr' AND serial='V437-SER' AND item_condition='New'") === 1
    && $audits437('device.deliver', 'added to the loaner inventory (mobile)') === 1, 'S4: deliver with the box adds one inventory item with the marker, and says so');
$db->exec("UPDATE device_requests SET status='Ordered' WHERE id=$dr");
$r = $post437('device_request_update', ['id' => $dr, 'op' => 'deliver', 'add_to_inventory' => 1]);
check(str_contains($r['message'], 'already in the loaner inventory from this request') && (int)$one437("SELECT COUNT(*) FROM equipment WHERE notes='From device request #$dr'") === 1, 'S4: delivered again: "already in the loaner inventory", no second item');
$dr2 = $newReq('V437 Synthetic Tablet');
$as437($group437('V437 procurement only', 'clients;procurement'));
$r = $post437('device_request_update', ['id' => $dr2, 'op' => 'deliver', 'add_to_inventory' => true]);
$as437(null);
check($r['message'] === 'Delivery recorded.' && !$one437("SELECT COUNT(*) FROM equipment WHERE notes='From device request #$dr2'"), 'S4: the box without the equipment permission adds nothing: "Delivery recorded."');
$dr3 = $newReq('V437 Synthetic Laptop');
$r = $post437('device_request_update', ['id' => $dr3, 'op' => 'deliver']);
check($r['message'] === 'Delivery recorded. The device was not added to the loaner inventory.', 'S4: not ticked: "…was not added to the loaner inventory."');
$dr4 = $newReq('V437 Synthetic Reader');
$r = $post437('device_request_update', ['id' => $dr4, 'op' => 'status', 'to' => 'Denied', 'notes' => 'V437 funding refused']);
check($r['message'] === 'Status updated to Denied.' && $r['notes'] === 'V437 funding refused' && $one437("SELECT notes FROM device_requests WHERE id=$dr4") === 'V437 funding refused', 'S4: deny with a reason stores it, "Status updated to Denied."');
$post437('device_request_update', ['id' => $dr4, 'op' => 'status', 'to' => 'Requested']);
$r = $post437('device_request_update', ['id' => $dr4, 'op' => 'status', 'to' => 'Cancelled']);
check($one437("SELECT notes FROM device_requests WHERE id=$dr4") === 'V437 funding refused' && $r['message'] === 'Status updated to Cancelled.', 'S4: cancel without the notes key keeps the stored note');
$post437('device_request_update', ['id' => $dr4, 'op' => 'status', 'to' => 'Requested']);
[$st, $d] = $req437('POST', 'device_request_update', ['id' => $dr4, 'op' => 'status', 'to' => 'Denied', 'notes' => str_repeat('r', 501)]);
check($st === 422 && $d['errors']['notes'] === 'Reason: use 500 characters or fewer.', 'S4: a 501-character reason is 422 on errors.notes');
$post437('device_request_update', ['id' => $dr4, 'op' => 'status', 'to' => 'Denied', 'notes' => 5]);
check($one437("SELECT notes FROM device_requests WHERE id=$dr4") === 'V437 funding refused', 'S4: a notes value that is not text keeps the stored note');
$post437('device_request_update', ['id' => $dr4, 'op' => 'status', 'to' => 'Requested']);
$l = $get437('device_letter', ['id' => $dr4]);
check($l['title'] === 'Assistive Technology Funding Justification — V437 Synthetic Client' && str_contains($l['text'], 'Justification of need') && str_contains($l['text'], DEVICE_JUSTIFICATION_DEFAULT)
    && str_contains($l['html'], '<h1>Assistive Technology Funding Justification') && str_ends_with($l['file'], '.html') && $audits437('device.letter', '(mobile)') === 1,
    'S4: device_letter gives the title, the default justification as text and HTML, and audits device.letter');
$as437($gNoEquip437);
[$st] = $req437('GET', 'device_letter', ['id' => $dr4]);
$as437(null);
check($st === 403, 'S4: device_letter needs the procurement permission');
[$code, $out, $err] = $page437('procurement', ['letter' => (string)$dr4]);
check(str_contains($out, 'Assistive Technology Funding Justification — V437 Synthetic Client') && str_contains($out, 'Justification of need'), 'S4: procurement.php\'s letter download is the shared document' . ($err ? ": $err" : ''));
$dr5 = $newReq('V437 Web Delivered');
[$code, , $err] = $page437('procurement', [], ['rid' => (string)$dr5, 'record_delivery' => '1', 'add_to_inventory' => '1']);
check((int)$one437("SELECT COUNT(*) FROM equipment WHERE notes='From device request #$dr5'") === 1 && str_contains($flash437(), 'Delivery recorded and the device added to the loaner inventory.'),
    'S4: procurement.php\'s delivery adds to the inventory with the same flash (shared helper)' . ($err ? ": $err" : ''));

/* ---- S5: Draft justification with AI ---- */
$reply437('[CLIENT] needs the device to read print at work.');
$r = $post437('ai_draft', ['type' => 'justification', 'client_id' => $c437, 'device_name' => 'V437 Synthetic CCTV', 'category' => 'Other', 'text' => 'Typed first line.']);
check($r['text'] === "Typed first line.\nV437 Synthetic Client needs the device to read print at work." && $r['draft'] === 'V437 Synthetic Client needs the device to read print at work.'
    && $r['shortened'] === false && $r['truncated'] === false && str_contains($sent437(), 'Already written (do not repeat it)') && str_contains($sent437(), 'Vocational goal (IPE): ')
    && $audits437('ai.draft', 'justification (mobile)') === 1, 'S5: the draft goes below the typed text with [CLIENT] restored; the prompt has the IPE goal and the typed text; audited');
$reply437(str_repeat('This sentence is long enough. ', 140));
$r = $post437('ai_draft', ['type' => 'justification', 'client_id' => $c437, 'device_name' => 'V437 Synthetic CCTV', 'text' => '']);
check(mb_strlen($r['text']) <= 4000 && $r['shortened'] === true && str_ends_with($r['text'], '.'), 'S5: a draft over 4,000 characters is cut at a sentence end and says shortened');
[$st, $d] = $req437('POST', 'ai_draft', ['type' => 'justification', 'client_id' => $c437, 'device_name' => '']);
check($st === 422 && $d['errors']['device_name'] === 'Device / technology: enter the device first.', 'S5: no device is 422 on errors.device_name');
$as437($gNoEquip437);
[$st] = $req437('POST', 'ai_draft', ['type' => 'justification', 'client_id' => $c437, 'device_name' => 'X']);
$as437(null);
check($st === 403, 'S5: without the procurement permission 403');
$db->exec("UPDATE settings SET value='0' WHERE `name`='ai_feat_draft'");
[$st, $d] = $req437('POST', 'ai_draft', ['type' => 'justification', 'client_id' => $c437, 'device_name' => 'X']);
$db->exec("UPDATE settings SET value='1' WHERE `name`='ai_feat_draft'");
check($st === 400 && str_starts_with($d['error'], 'That AI feature is turned off.'), 'S5: with drafting off it is refused with the feature-off sentence (as every ai_draft type)');
[$st, $d] = $req437('POST', 'add_device_request', ['client_id' => $c437, 'device_name' => 'V437 Long', 'justification' => str_repeat('j', 4001), 'idem_key' => $key437()]);
check($st === 422 && $d['errors']['justification'] === 'Justification of need: use 4,000 characters or fewer (you entered 4,001).', 'S5: add_device_request refuses 4,001 characters with the website\'s sentence');
$reply437('Web draft for [CLIENT].');
[$code, , $err] = $page437('procurement', [], ['draft_justification' => '1', 'client_id' => (string)$c437, 'device_name' => 'V437 Web CCTV', 'category' => 'Other', 'justification' => '']);
check(str_contains($flash437(), 'Justification drafted — review and edit it, then create the request.') && str_contains($flash437(), 'Web draft for V437 Synthetic Client.')
    && $audits437('ai.draft', 'device justification') === 1, 'S5: procurement.php\'s "Draft justification with AI" fills the field through device_justification_ai()' . ($err ? ": $err" : ''));

/* ---- S6: the recommendation context ---- */
$db->prepare("UPDATE clients SET a11y_screen_reader=1 WHERE id=?")->execute([$c437]);
$db->prepare("INSERT INTO worksite_assessments (client_id, job_title, job_tasks) VALUES (?,'V437 Synthetic Clerk','Data entry')")->execute([$c437]); $wa437 = (int)$db->lastInsertId();
$reply437('1. Screen reader');
$r = $post437('ai_recommend', ['client_id' => $c437]);
check(str_contains($r['context'], 'Uses a screen reader.') && str_contains($r['context'], 'Job title: V437 Synthetic Clerk') && $r['truncated'] === false && $r['context'] === recommend_context($db->query("SELECT * FROM clients WHERE id=$c437")->fetch()),
    'S6: ai_recommend sends recommend.php\'s context (screen reader, job title) and says truncated');
[$code, $out, $err] = $page437('recommend', ['client' => (string)$c437]);
check(str_contains($out, 'Uses a screen reader.') && str_contains($out, 'Job title: V437 Synthetic Clerk'), 'S6: recommend.php shows the same context (shared recommend_context)' . ($err ? ": $err" : ''));

/* ---- S7: the formal AT evaluation report ---- */
$db->prepare("INSERT INTO tech_assessments (client_id, assessment_date, primary_disability, primary_tech, hardware_details, software_version, summary, created_by) VALUES (?,?,?,?,?,?,?,1)")
    ->execute([$c437, date('Y-m-d', strtotime('-3 days')), 'Low vision', 'Screen Reader', 'V437 laptop', 'V437 reader 2026', 'V437 findings']); $as437id = (int)$db->lastInsertId();
$e = $get437('eval_report', ['assessment_id' => $as437id]);
check(count($e['sections']) === 4 && $e['sections'][0]['key'] === 'background' && $e['sections'][0]['default'] === true && str_contains($e['sections'][0]['text'], fmt_date(date('Y-m-d', strtotime('-3 days'))))
    && $e['to'] === 'v437-counselor@example.invalid' && $e['counselor_verified'] === true && $e['signer'] === $adminName437 && $e['ai'] === true, 'S7: eval_report gives four prefilled sections, the confirmed counselor and the signer');
$as437($gNoReports437);
[$st] = $req437('GET', 'eval_report', ['assessment_id' => $as437id]);
$as437(null);
$db->prepare("INSERT INTO tech_assessments (client_id, assessment_date, primary_tech, created_by) VALUES (?,CURDATE(),'Screen Reader',1)")->execute([$cOther437]); $asOther = (int)$db->lastInsertId();
$as437($gScoped437);
[$st2] = $req437('GET', 'eval_report', ['assessment_id' => $asOther]);
$as437(null);
check($st === 403 && $st2 === 403, 'S7: without the reports permission 403; another caseload\'s assessment 403');
$reply437('[BACKGROUND]Drafted background for [CLIENT].[/BACKGROUND][FINDINGS]Drafted findings.[/FINDINGS][RECOMMENDATIONS]Drafted recs.[/RECOMMENDATIONS][JUSTIFICATION]Drafted why.[/JUSTIFICATION]');
$typedSections = ['findings' => 'My own findings.'];
$r = $post437('ai_eval_draft', ['assessment_id' => $as437id, 'sections' => $typedSections]);
$byKey = array_column($r['sections'], 'text', 'key');
check($r['drafted'] === ['background', 'recommendations', 'justification'] && $r['kept'] === ['findings'] && $byKey['findings'] === 'My own findings.'
    && $byKey['background'] === 'Drafted background for V437 Synthetic Client.' && str_starts_with($r['message'], 'Draft created for Background, Recommendations, Justification of need. Kept as you wrote them: Evaluation findings.'),
    'S7: ai_eval_draft drafts only the default sections, keeps the typed one, restores [CLIENT] and says so');
$r = $post437('ai_eval_draft', ['assessment_id' => $as437id, 'sections' => ['background' => 'a', 'findings' => 'b', 'recommendations' => 'c', 'justification' => 'd']]);
check($r['drafted'] === [] && str_starts_with($r['message'], 'Nothing was drafted: every section holds text you have written'), 'S7: every section typed: 200, "Nothing was drafted…"');
$good = ['background' => 'Background.', 'findings' => 'Findings.', 'recommendations' => 'Recommendations.', 'justification' => 'Justification.'];
[$st, $d] = $req437('POST', 'send_eval_report', ['assessment_id' => $as437id, 'sections' => $good, 'to' => 'v437-counselor@example.invalid', 'signature' => 'Not Me']);
[$st2, $d2] = $req437('POST', 'send_eval_report', ['assessment_id' => $as437id, 'sections' => ['findings' => 'Needs [hours] more.'] + $good, 'to' => 'v437-counselor@example.invalid', 'signature' => $adminName437]);
$db->prepare('UPDATE clients SET counselor_verified=0 WHERE id=?')->execute([$c437]);
[$st3, $d3] = $req437('POST', 'send_eval_report', ['assessment_id' => $as437id, 'sections' => $good, 'to' => 'v437-counselor@example.invalid', 'signature' => $adminName437]);
$db->prepare('UPDATE clients SET counselor_verified=1 WHERE id=?')->execute([$c437]);
check($st === 422 && $d['errors']['signature'] === 'Type your full name (' . $adminName437 . ') to sign before emailing.' && $st2 === 422 && $d2['errors']['findings'] === 'Replace the placeholder [hours] with the actual detail before emailing.'
    && $st3 === 422 && str_starts_with($d3['errors']['to'], 'The counselor email on this client has not been confirmed.'), 'S7: a wrong signature, a placeholder and an unconfirmed counselor are refused on their fields');
$mailsBefore = count($mails437());
$k = $key437();
$r = $post437('send_eval_report', ['assessment_id' => $as437id, 'sections' => $good, 'to' => 'v437-counselor@example.invalid', 'signature' => strtoupper($adminName437), 'idem_key' => $k]);
$again = $post437('send_eval_report', ['assessment_id' => $as437id, 'sections' => $good, 'to' => 'v437-counselor@example.invalid', 'signature' => strtoupper($adminName437), 'idem_key' => $k]);
$newMails = array_slice($mails437(), $mailsBefore);
check($r['message'] === "Evaluation report emailed to v437-counselor@example.invalid and logged on the client's file." && count($newMails) === 1 && str_contains($newMails[0], '.pdf, AT_Evaluation_')
    && (int)$one437("SELECT COUNT(*) FROM client_notes WHERE client_id=$c437 AND subject='AT evaluation report emailed'") === 1 && $audits437('evalreport.send', 'to v437-counselor@example.invalid (mobile)') === 1
    && !empty($again['repeat']) && $again['message'] === $r['message'], 'S7: send_eval_report mails the PDF and HTML once, notes and audits it; the same idem_key answers the first message');
$h = $post437('eval_report', ['assessment_id' => $as437id, 'sections' => $good, 'html' => true]);
check(str_contains($h['html'], 'Draft — not signed.') && str_contains($h['html'], 'Findings.') && str_ends_with($h['file'], '.html') && $audits437('evalreport.download', 'html (mobile)') === 1, 'S7: the accessible HTML to share is the unsigned report, audited');
[$code, $out, $err] = $page437('eval_report', ['assessment' => (string)$as437id]);
check($code === 0 && $err === '' && str_contains($out, 'Low vision') && str_contains($out, EVAL_AI_HINT), 'S7: eval_report.php still renders through the shared helpers' . ($err ? ": $err" : ''));
$webSend = ['email_report' => '1', 'signature' => $adminName437] + $good;
[$code, $out, $err] = $page437('eval_report', ['assessment' => (string)$as437id], $webSend + ['recipient' => 'not-an-address']);
check(str_contains($out, 'Enter a valid email address, for example name@agency.org.') && str_contains($out, 'id="err-recipient"'), 'S7: eval_report.php ties the address error to its Email to field (to mapped to recipient)');
$mailsBefore = count($mails437());
[$code, $out, $err] = $page437('eval_report', ['assessment' => (string)$as437id], $webSend + ['recipient' => 'v437-web@example.invalid']);
check(count($mails437()) === $mailsBefore + 1 && str_contains($flash437(), 'Evaluation report emailed to v437-web@example.invalid and logged on the client') && $out === '',
    'S7: eval_report.php emails the signed report and redirects with the logged sentence');
$db->exec("CREATE TRIGGER v437_eval_note BEFORE INSERT ON client_notes FOR EACH ROW BEGIN IF NEW.subject = 'AT evaluation report emailed' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Synthetic note failure'; END IF; END");
try {
    [$code, , $err2] = $renderPage('eval_report', ['assessment' => (string)$as437id], $webSend + ['recipient' => 'v437-web2@example.invalid']);
    check(str_contains($flash437(), 'so do not send it again. It could not be logged on the client'), 'S7: eval_report.php warns when the report went but could not be logged');
    $k = $key437();
    $u1 = $post437('send_eval_report', ['assessment_id' => $as437id, 'sections' => $good, 'to' => 'v437-web3@example.invalid', 'signature' => $adminName437, 'idem_key' => $k]);
    $u2 = $post437('send_eval_report', ['assessment_id' => $as437id, 'sections' => $good, 'to' => 'v437-web3@example.invalid', 'signature' => $adminName437, 'idem_key' => $k]);
    check($u1['logged'] === false && str_contains($u1['message'], 'could not be logged') && !empty($u2['repeat']) && $u2['logged'] === false && $u2['message'] === $u1['message'],
        'S7: send_eval_report says when it was not logged, and its repeat keeps saying so (never "logged")');
} finally { $db->exec('DROP TRIGGER IF EXISTS v437_eval_note'); }
check(person_name_key("  Synthetic   NAME ") === person_name_key('synthetic name') && str_contains((string)file_get_contents($root . '/public/api.php'), 'return person_name_key($s);'), 'S7: api_name_key() is person_name_key(), the website\'s name rule');

/* ---- S8: worksite AI and report ---- */
$reply437('Screen magnifier at the desk.');
$r = $post437('ai_worksite', ['id' => $wa437, 'job_title' => 'V437 Clerk', 'job_tasks' => 'Typing', 'barriers' => 'Small print', 'recommendations' => 'Typed rec.']);
check($r['text'] === "Typed rec.\nScreen magnifier at the desk." && $r['message'] === 'Recommendations drafted. Read and edit them, then save the assessment.' && $one437("SELECT recommendations FROM worksite_assessments WHERE id=$wa437") === null
    && $audits437('ai.draft', 'worksite recommendations (mobile)') === 1, 'S8: ai_worksite merges below typed text, saves nothing, audited');
$rep = $get437('worksite_report', ['id' => $wa437]);
check(str_contains($rep['text'], '[Recommendations to be completed]') && str_contains($rep['text'], 'Essential job tasks') && str_contains($rep['html'], 'Job Accommodation Recommendations') && $audits437('worksite.report', '(mobile)') === 1,
    'S8: worksite_report gives the headings and "[Recommendations to be completed]", audited');
$db->prepare("INSERT INTO worksite_assessments (client_id, job_title) VALUES (?,'V437 other')")->execute([$cOther437]); $waOther = (int)$db->lastInsertId();
$as437($gScoped437);
[$st] = $req437('GET', 'worksite_report', ['id' => $waOther]);
$as437(null);
$db->exec("UPDATE settings SET value='0' WHERE `name`='ai_feat_draft'");
[$st2] = $req437('POST', 'ai_worksite', ['id' => $wa437]);
$db->exec("UPDATE settings SET value='1' WHERE `name`='ai_feat_draft'");
check($st === 403 && $st2 === 422, 'S8: another caseload\'s worksite 403; drafting off 422');
[$code, $out, $err] = $page437('accommodations', ['report' => (string)$wa437]);
check(str_contains($out, '[Recommendations to be completed]') && str_contains($out, 'Job Accommodation Recommendations — V437 Synthetic Client'), 'S8: accommodations.php\'s report is the shared document' . ($err ? ": $err" : ''));

/* ---- S9: goals, case summary and Format with AI ---- */
$reply437('1. Goal one');
$r = $post437('ai_draft', ['type' => 'goals', 'assessment_id' => $as437id]);
check(str_contains($sent437(), 'Hardware: ') && str_contains($sent437(), 'Keep them realistic') && $audits437('ai.draft', 'goal suggestions (mobile)') === 1, 'S9: goals from an assessment send hardware and the website\'s prompt');
$db->prepare("INSERT INTO client_notes (client_id, note_type, subject, body, author_id) VALUES (?, 'Case Note', 'Portal: request', 'V437 portal text', NULL)")->execute([$c437]);
for ($i = 0; $i < 12; $i++) $db->prepare("INSERT INTO client_notes (client_id, note_type, subject, body, author_id) VALUES (?, 'Case Note', ?, 'b', 1)")->execute([$c437, 'V437 note ' . $i]);
$reply437('Summary');
$post437('ai_draft', ['type' => 'case', 'client_id' => $c437]);
$sentCase = $sent437();
check(str_contains($sentCase, 'Assessments:') && !str_contains($sentCase, 'portal text') && preg_match_all('/\\[Case Note\\] \\[CLIENT\\] note \\d+:/', $sentCase) === 10
    && str_contains($sentCase, 'note 11:') && !str_contains($sentCase, 'note 0:') && !str_contains($sentCase, 'note 1:'),
    'S9: the prompt the API sent has the assessments and the 10 newest notes (names masked), never the portal note');
[$st, $d] = $req437('POST', 'ai_draft', ['type' => 'format', 'client_id' => $c437, 'format' => 'SOAP', 'body' => '', 'sections' => ['', '', '', '']]);
check($st === 422 && $d['errors']['body'] === 'Type the session notes first, then format them.', 'S9: format with nothing typed is 422 on errors.body');
$reply437('[S1]Subj[/S1][S2]Obj[/S2][S3][/S3][S4]Plan[/S4]');
$r = $post437('ai_draft', ['type' => 'format', 'client_id' => $c437, 'format' => 'SOAP', 'body' => 'rough notes', 'sections' => ['', '', 'kept assess', '']]);
check($r['sections'] === ['Subj', 'Obj', 'kept assess', 'Plan'] && str_starts_with($r['message'], 'Formatted as SOAP — review each section'), 'S9: the reply split into four; an empty section keeps what was typed');
$reply437('No tags at all');
[$st, $d] = $req437('POST', 'ai_draft', ['type' => 'format', 'client_id' => $c437, 'format' => 'BIRP', 'body' => 'rough']);
check($st === 422 && str_starts_with($d['error'], 'The AI reply could not be split into BIRP sections'), 'S9: a reply that cannot be split is 422 and nothing changes');
$reply437('1. Web goal');
[$code, $out, $err] = $page437('client', ['id' => (string)$c437], ['ai_do' => 'goals', 'assessment_id' => (string)$as437id]);
check(str_contains($sent437(), 'Hardware: ') && str_contains($out, 'AI goal suggestions (draft)'), 'S9: client.php\'s goals use the shared assessment_goals_ai()' . ($err ? ": $err" : ''));

/* ---- S10: record progress per goal ---- */
$t437 = $track437($c437);
$db->prepare("INSERT INTO smart_goals (track_id, specific_goal, measurable_metric, status, progress_pct) VALUES (?,'V437 Read a page aloud','Metric',?,?)")->execute([$t437, 'In Progress', 20]); $g1 = (int)$db->lastInsertId();
$db->prepare("INSERT INTO smart_goals (track_id, specific_goal, measurable_metric, status, progress_pct) VALUES (?,'V437 Achieved goal','Metric','Achieved',100)")->execute([$t437]); $g2 = (int)$db->lastInsertId();
$reply437('## Review');
$pr = $post437('ai_progress', ['client_id' => $c437]);
$ids = array_column($pr['goals'], 'id');
check(in_array($g1, $ids, true) && !in_array($g2, $ids, true) && $pr['goals'][array_search($g1, $ids)]['track_id'] === $t437 && $pr['goals'][array_search($g1, $ids)]['pct'] === 20, 'S10: ai_progress lists the active goals with track and percent');
[$st, $d] = $req437('POST', 'update_goal', ['id' => $g2, 'status' => 'In Progress']);
check($st === 409 && $d['error'] === 'This goal was 100% complete while Achieved. Enter how complete it is now that it is In Progress again, or choose Record again to keep it at 100%.', 'S10: reopening an Achieved goal without a percent is 409 with the website\'s full sentence');
$goalAudits = $audits437('goal.status', 'In Progress 40% (mobile)');
$r = $post437('update_goal', ['id' => $g1, 'status' => 'In Progress', 'progress_pct' => 40]);
check($r['message'] === 'Progress recorded for goal "V437 Read a page aloud": 40% (In Progress).' && $audits437('goal.status', 'In Progress 40% (mobile)') === $goalAudits + 1, 'S10: update_goal answers the website\'s sentence, audited "(mobile)"');
[$code, , $err] = $page437('progress_review', ['client' => (string)$c437], ['apply_progress' => '1', 'goal_id' => (string)$g1, 'goal_status' => 'In Progress', 'progress_pct' => '55']);
check((int)$one437("SELECT progress_pct FROM smart_goals WHERE id=$g1") === 55 && str_contains($flash437(), 'Progress recorded for goal'), 'S10: progress_review.php records through goal_progress_record()' . ($err ? ": $err" : ''));

/* ---- S11: attachment and templates ---- */
$pdf = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";
putenv('CMS_TEST_VIRUS=clean');
[$st, $d] = $req437('POST', 'add_assessment', ['client_id' => $c437, 'assessment_date' => date('Y-m-d'), 'primary_tech' => 'Screen Reader', 'attachment' => ['name' => 'tool.exe', 'data' => base64_encode('MZ')], 'idem_key' => $key437()]);
[$st2, $d2] = $req437('POST', 'add_assessment', ['client_id' => $c437, 'assessment_date' => date('Y-m-d'), 'primary_tech' => 'Screen Reader', 'attachment' => ['name' => 'fake.pdf', 'data' => base64_encode("\x89PNG\r\n\x1a\nxxxx")], 'idem_key' => $key437()]);
check($st === 422 && $d['errors']['attachment'] === 'Attachment type not allowed. Use PDF, Word, text, or image files.' && $st2 === 422 && str_starts_with($d2['errors']['attachment'], 'That file is not really a .pdf file'),
    'S11: an .exe and a PNG named .pdf are refused on errors.attachment with the website\'s sentences');
$post437('add_assessment', ['client_id' => $c437, 'assessment_date' => date('Y-m-d'), 'primary_tech' => 'Screen Reader', 'attachment' => ['name' => 'V437 report.pdf', 'data' => base64_encode($pdf)], 'idem_key' => $key437()]);
putenv('CMS_TEST_VIRUS');
$list = $get437('assessments', ['client_id' => $c437]);
$withAtt = array_values(array_filter($list['assessments'], fn($a) => $a['attachment_name'] === 'V437 report.pdf'));
$dl = $get437('download_assessment_attachment', ['id' => (int)$withAtt[0]['id']]);
check(count($withAtt) === 1 && base64_decode($dl['data']) === $pdf && $dl['mime'] === 'application/pdf' && $audits437('document.view', 'assessment attachment: V437 report.pdf (mobile)') === 1,
    'S11: a real PDF is stored, assessments names it, and the download returns it, audited');
$as437($gScoped437);
$db->prepare("UPDATE tech_assessments SET attachment_path='x.pdf', attachment_name='x.pdf' WHERE id=?")->execute([$asOther]);
[$st] = $req437('GET', 'download_assessment_attachment', ['id' => $asOther]);
$as437(null);
$db->exec("INSERT INTO assessment_templates (name, primary_tech, summary) VALUES ('V437 Template','Magnification','V437 template summary')");
$tp = $get437('assessment_templates');
check($st === 403 && in_array('V437 Template', array_column($tp['templates'], 'name'), true), 'S11: another caseload\'s attachment 403; assessment_templates lists the templates');
file_put_contents($tmp . '/v437 web report.pdf', $pdf);
putenv('CMS_TEST_FILES=' . json_encode(['attachment' => $tmp . '/v437 web report.pdf'])); putenv('CMS_TEST_VIRUS=clean');
[$code, , $err] = $page437('client', ['id' => (string)$c437, 'tab' => 'assessments'], ['add_assessment' => '1', 'assessment_date' => date('Y-m-d'), 'primary_tech' => 'Screen Reader']);
putenv('CMS_TEST_FILES'); putenv('CMS_TEST_VIRUS');
$webPath = (string)$one437("SELECT attachment_path FROM tech_assessments WHERE client_id=$c437 AND attachment_name='v437 web report.pdf'");
check($webPath !== '' && file_get_contents(cfg('upload_dir') . '/' . $webPath) === $pdf && str_contains($flash437(), 'Tech assessment saved.'),
    'S11: client.php stores an uploaded attachment through tech_assessment_attachment_store()' . ($err ? ": $err" : ''));

/* ---- S13: amendment request sent and answered ---- */
$k = $key437();
$r = $post437('record_amendment', ['track_id' => $t437, 'requested_on' => date('Y-m-d'), 'sent_to' => 'V437 Synthetic Counselor', 'hours' => '10', 'letter' => 'V437 letter', 'idem_key' => $k]);
$again = $post437('record_amendment', ['track_id' => $t437, 'requested_on' => date('Y-m-d'), 'sent_to' => 'V437 Synthetic Counselor', 'hours' => '10', 'letter' => 'V437 letter', 'idem_key' => $k]);
$reqId = (int)$r['id'];
check(str_starts_with($r['message'], 'Recorded: amendment requested.') && (int)$one437("SELECT COUNT(*) FROM amendment_requests WHERE track_id=$t437") === 1
    && $one437("SELECT status FROM tasks WHERE auto_key='amend-$reqId'") === 'Open' && $audits437('auth.amend_request', '%10%') >= 1 && $r['history'][0]['awaiting'] === true
    && !empty($again['repeat']) && $again['id'] === $reqId && $again['message'] === $r['message'], 'S13: record_amendment keeps one request, a note and a follow-up task; the same idem_key answers the first reply');
$tOther437 = $track437($cOther437);
$crossed = $post437('record_amendment', ['track_id' => $tOther437, 'requested_on' => date('Y-m-d'), 'hours' => '10', 'idem_key' => $k]);
check(!empty($crossed['repeat']) && $crossed['id'] === $reqId && array_column($crossed['history'], 'id') === [$reqId], 'S13: a repeat rebuilds the history from the stored request\'s own track, whatever track_id it names');
[$st, $d] = $req437('POST', 'record_amendment', ['track_id' => $t437, 'requested_on' => date('Y-m-d'), 'idem_key' => $key437()]);
[$st2, $d2] = $req437('POST', 'record_amendment', ['track_id' => $t437, 'requested_on' => date('Y-m-d', strtotime('+1 day')), 'hours' => '5', 'idem_key' => $key437()]);
check($st === 422 && isset($d['errors']['hours']) && $st2 === 422 && isset($d2['errors']['requested_on']), 'S13: nothing asked for is errors.hours; tomorrow is errors.requested_on');
$r = $post437('amendment_reply', ['track_id' => $t437, 'request_id' => $reqId, 'status' => 'Approved', 'replied_on' => date('Y-m-d'), 'note' => 'ok']);
check($r['needs_change'] === true && str_starts_with($r['reason_prefill'], 'VR approved the amendment requested ') && $one437("SELECT status FROM tasks WHERE auto_key='amend-$reqId'") === 'Done'
    && $r['message'] === 'Reply recorded: approved. Now enter the approved hours, dollars or end date on the track.' && $r['history'][0]['reason_prefill'] === $r['reason_prefill'],
    'S13: an approved reply closes the task, says needs_change with the reason to fill in (also on the history row)');
[$st, $d] = $req437('POST', 'amendment_reply', ['track_id' => $t437, 'request_id' => $reqId, 'status' => 'Approved', 'replied_on' => date('Y-m-d')]);
[$st2, $d2] = $req437('POST', 'amendment_reply', ['track_id' => $t437, 'request_id' => $reqId, 'status' => '', 'replied_on' => date('Y-m-d')]);
check($st === 409 && $d['error'] === 'That request already has a reply.' && $st2 === 422 && isset($d2['errors']['status']), 'S13: a second reply is 409; no reply chosen is errors.status');
$reply437('AI letter for [CLIENT].');
$am = $post437('amendment', ['track' => $t437, 'ai_draft' => 1, 'letter' => 'My own edited letter.']);
$am2 = $post437('amendment', ['track' => $t437, 'ai_draft' => 1]);
check($am['letter'] === 'My own edited letter.' && $am['ai_draft_text'] === 'AI letter for V437 Synthetic Client.' && str_starts_with($am['ai_note'], 'Your letter is unchanged.')
    && $am2['letter'] === 'AI letter for V437 Synthetic Client.' && $am2['ai_draft_text'] === null && count($am['history']) === 1 && $am['reply_statuses'] === AMENDMENT_REPLY_STATUSES,
    'S13: with an edited letter the AI draft comes back separately and the letter is unchanged; history and reply choices are sent');
[$code, $out, $err] = $page437('amendment_request', ['track' => (string)$t437], ['ai_draft' => '1', 'track' => (string)$t437, 'letter' => '']);
check(str_contains($out . $flash437(), 'The AI draft is in the letter box below') && str_contains($out, 'AI letter for V437 Synthetic Client.'), 'S13: amendment_request.php drafts through amendment_ai_draft()' . ($err ? ": $err" : ''));

/* ---- S14: letters ---- */
$lc = $client437('V437 Letter Client');
$ls = $get437('letters', ['client_id' => $lc, 'key' => 'welcome']);
check(count($ls['letters']) === 7 && $ls['title'] === 'Welcome letter' && str_contains($ls['body'], '[not on file]') && $ls['prefers_spanish'] === false, 'S14: letters gives the seven letters and a body with "[not on file]"');
$k = $key437();
$r = $post437('save_letter', ['client_id' => $lc, 'how' => 'doc', 'key' => 'welcome', 'title' => 'V437 Welcome', 'body' => 'Dear client.', 'lang' => 'en', 'to_portal' => true, 'idem_key' => $k]);
$again = $post437('save_letter', ['client_id' => $lc, 'how' => 'doc', 'key' => 'welcome', 'title' => 'V437 Welcome', 'body' => 'Dear client.', 'lang' => 'en', 'to_portal' => true, 'idem_key' => $k]);
check($r['message'] === 'The letter was saved to the client\'s documents, and the client can read it in their portal.' && $one437('SELECT category FROM client_documents WHERE id=' . (int)$r['document_id']) === 'Resource'
    && (int)$one437("SELECT COUNT(*) FROM client_documents WHERE client_id=$lc") === 1 && $audits437('letter.save', '%in the portal) (mobile)') === 1
    && $again['message'] === 'This letter was already saved to the client\'s documents (the form was sent twice), so it was saved once.', 'S14: save_letter to the portal is a Resource, audited; the same idem_key saves once with the website\'s repeat sentence');
$kn = $key437();
$n1 = $post437('save_letter', ['client_id' => $lc, 'how' => 'note', 'key' => 'welcome', 'title' => 'V437 Note letter', 'body' => 'Note body.', 'idem_key' => $kn]);
$n2 = $post437('save_letter', ['client_id' => $lc, 'how' => 'note', 'key' => 'welcome', 'title' => 'V437 Note letter', 'body' => 'Note body.', 'idem_key' => $kn]);
check(is_int($n1['note_id']) && $n2['note_id'] === $n1['note_id'] && $n2['message'] === 'This letter was already kept as a case note (the form was sent twice), so it was kept once.',
    'S14: a note letter\'s repeat answers its note_id and the website\'s repeat sentence');
$nt = $post437('save_letter', ['client_id' => $lc, 'how' => 'note', 'key' => 'no_show', 'title' => '', 'body' => 'Blank title.', 'idem_key' => $key437()]);
check($one437('SELECT subject FROM client_notes WHERE id=' . (int)$nt['note_id']) === 'Letter: Missed appointment', 'S14: a blank title with the letter\'s key uses the letter\'s name');
[$st, $d] = $req437('POST', 'save_letter', ['client_id' => $lc, 'how' => 'doc', 'title' => 'x', 'body' => '  ', 'idem_key' => $key437()]);
[$st2, $d2] = $req437('POST', 'save_letter', ['client_id' => $lc, 'how' => 'fax', 'title' => str_repeat('t', 151), 'body' => 'b', 'idem_key' => $key437()]);
check((int)$one437("SELECT COUNT(*) FROM client_notes WHERE client_id=$lc AND subject='Letter: V437 Note letter' AND note_type='Case Note'") === 1 && $audits437('letter.note', 'V437 Note letter (mobile)') === 1
    && $st === 422 && str_starts_with($d['errors']['body'], 'The letter is empty.') && $st2 === 422 && isset($d2['errors']['how'], $d2['errors']['title']), 'S14: a case note letter; an empty body, a bad how and a long title are refused on their fields');
$reply437('Dear [CLIENT], welcome from [STAFF].');
$r = $post437('ai_letter', ['client_id' => $c437, 'key' => 'welcome', 'body' => '', 'edited' => false]);
$r2 = $post437('ai_letter', ['client_id' => $c437, 'key' => 'welcome', 'body' => 'Mine', 'edited' => true]);
check($r['text'] === 'Dear V437 Synthetic Client, welcome from ' . $adminName437 . '.' && $r['separate'] === false && $r2['separate'] === true && str_contains($sent437(), '[CLIENT]') && str_contains($sent437(), '[STAFF]') && !str_contains($sent437(), $adminName437),
    'S14: ai_letter restores the names; an edited letter gets a separate draft; the client and the sender (the instructor) never reach the provider');
$as437($gScoped437);
[$st] = $req437('GET', 'letters', ['client_id' => $cOther437]);
$as437(null);
check($st === 403, 'S14: another caseload\'s client is 403');
$reply437('Web letter for [CLIENT].');
[$code, $out, $err] = $page437('letters', ['client' => (string)$c437, 'letter' => 'welcome'], ['ai_letter' => '1', 'client' => (string)$c437, 'letter' => 'welcome', 'body' => '', 'title' => 'Welcome letter']);
check(str_contains($out, 'Web letter for V437 Synthetic Client.') && $audits437('ai.draft', 'letter: Welcome letter') === 1, 'S14: letters.php\'s "Draft the letter with AI" uses letter_ai_draft()' . ($err ? ": $err" : ''));
[$code, , $err] = $page437('letters', [], ['save_note' => '1', 'client' => (string)$c437, 'letter' => 'welcome', 'body' => 'Web note', 'title' => 'V437 Web note']);
check((int)$one437("SELECT COUNT(*) FROM client_notes WHERE client_id=$c437 AND subject='Letter: V437 Web note'") === 1, 'S14: letters.php keeps a note through letter_save()' . ($err ? ": $err" : ''));

/* ---- S15: releases, consent and the privacy notice ---- */
$rel = ['client_id' => $c437, 'signer_name' => 'V437 Signer', 'recipient_type' => cms_list_items('release_recipient_types')[0], 'recipient_name' => 'V437 Employer', 'purpose' => 'Synthetic', 'info' => [cms_list_items('release_info_items')[0]]];
$r = $post437('request_release', $rel + ['method' => 'Paper', 'signed_on' => date('Y-m-d'), 'idem_key' => $key437()]);
check(str_starts_with($r['message'], 'Paper release recorded for V437 Employer. It is valid until ') && $audits437('release.paper') >= 1
    && (int)$one437("SELECT COUNT(*) FROM client_notes WHERE client_id=$c437 AND subject='Release of information recorded'") >= 1, 'S15: a paper release is recorded signed, noted and audited, "valid until"');
[$st, $d] = $req437('POST', 'request_release', $rel + ['method' => 'Paper', 'signed_on' => date('Y-m-d', strtotime('-800 days')), 'idem_key' => $key437()]);
check($st === 422 && $d['errors']['signed_on'] === 'A release signed on that date has already expired. Ask for a new one.', 'S15: an expired paper date is refused on signed_on');
$cl = $get437('client', ['id' => $c437]);
$paperRow = current(array_filter($cl['releases'], fn($x) => $x['id'] === (int)$r['id']));
check($paperRow['can_withdraw'] === true && str_contains($paperRow['text'], 'V437 Employer') && isset($cl['portal_link']['confirm_text'], $cl['privacy_notice']['text']) && $cl['privacy_notice_methods'] === PRIVACY_NOTICE_METHODS,
    'S15: the client reply carries each release\'s line and can_withdraw, the privacy notice, its methods and the portal link');
$post437('withdraw_release', ['client_id' => $c437, 'release_id' => (int)$r['id']]);
[$st, $d] = $req437('POST', 'withdraw_release', ['client_id' => $c437, 'release_id' => (int)$r['id']]);
check($audits437('release.withdraw', 'release #' . (int)$r['id']) === 1 && $st === 409, 'S15: withdraw_release is audited; again 409');
$db->prepare("INSERT INTO consent_forms (client_id, guardian_name, guardian_email, token, sent_at, signed_at, signed_name) VALUES (?,'V437 Guardian','g@example.invalid',?,NOW(),NOW(),'V437 Guardian')")->execute([$c437, bin2hex(random_bytes(24))]); $cf = (int)$db->lastInsertId();
$cs = $get437('request_consent', ['client_id' => $c437]);
[$st, $d] = $req437('POST', 'withdraw_consent', ['client_id' => $c437, 'consent_id' => $cf + 1000]);
$ok = $post437('withdraw_consent', ['client_id' => $c437, 'consent_id' => $cf]);
[$st2, $d2] = $req437('POST', 'withdraw_consent', ['client_id' => $c437, 'consent_id' => $cf]);
check($cs['signed_id'] === $cf && $st === 409 && str_starts_with($d['error'], 'The guardian consent on this record has changed since the page was loaded') && $audits437('consent.withdraw', 'consent #' . $cf) === 1
    && $st2 === 409 && $d2['error'] === 'That consent was already recorded as withdrawn.', 'S15: request_consent names signed_id; a stale id is 409; withdrawn once, audited; again 409');
[$st, $d] = $req437('POST', 'record_privacy_notice', ['client_id' => $c437, 'how' => '']);
$pn = $post437('record_privacy_notice', ['client_id' => $c437, 'how' => 'In person']);
check($st === 422 && $d['errors']['how'] === 'Choose how the privacy notice was given.' && $pn['message'] === 'Recorded: privacy notice given (in person).' && $pn['privacy_notice']['how'] === 'In person',
    'S15: the privacy notice needs a method (errors.how); recorded with the website\'s sentence');
[$code, , $err] = $page437('client', ['id' => (string)$c437], ['record_notice' => '1', 'notice_how' => 'By email']);
check($one437("SELECT privacy_notice_how FROM clients WHERE id=$c437") === 'By email' && str_contains($flash437(), 'Recorded: privacy notice given (by email).'), 'S15: client.php records it through privacy_notice_record()' . ($err ? ": $err" : ''));

/* ---- S16: documents ---- */
[$st, $d] = $req437('POST', 'upload_document', ['client_id' => $c437, 'filename' => 'photo.heic', 'data_b64' => base64_encode('heic'), 'idem_key' => $key437()]);
check($st === 422 && $d['errors']['file'] === 'That file type is not allowed. Use PDF, Office, text, or image files. Save the photo as a JPEG or PDF, then upload it again.',
    'S16: a .heic upload (a 4.36.0 app\'s queued photo too) is 422 with the website\'s sentence and how to send it as a JPEG or PDF');
$up = $post437('upload_document', ['client_id' => $c437, 'filename' => 'V437 notes.txt', 'data_b64' => base64_encode('plain text'), 'share_portal' => 1, 'idem_key' => $key437()]);
check((int)$one437('SELECT portal_shared FROM client_documents WHERE id=' . (int)$up['id']) === 1 && (int)$one437('SELECT counselor_shared FROM client_documents WHERE id=' . (int)$up['id']) === 0, 'S16: share_portal shares it; share_counselor absent is off');
$db->prepare("INSERT INTO client_documents (client_id, category, title, orig_name, path) VALUES (?,'Authorization','V437 Auth','a.pdf','a.pdf')")->execute([$c437]); $authDoc = (int)$db->lastInsertId();
$as437($gStaff437);
$docs = $get437('documents', ['client_id' => $c437]);
[$st, $d] = $req437('POST', 'delete_document', ['id' => $authDoc]);
$del = $post437('delete_document', ['id' => (int)$up['id']]);
$as437(null);
$canDel = array_column($docs['documents'], 'can_delete', 'id');
check($canDel[$authDoc] === false && $canDel[(int)$up['id']] === true && $st === 403 && $d['error'] === 'Only an administrator can delete an authorization document.'
    && $del['message'] === 'Document moved to the trash — an administrator can restore it for 30 days.' && !$one437('SELECT COUNT(*) FROM client_documents WHERE id=' . (int)$up['id']) && $audits437('document.delete', 'doc #' . (int)$up['id'] . ' (to trash) (mobile)') === 1,
    'S16: can_delete per row; an Authorization by a non-administrator 403 with the website\'s sentence; another goes to the trash, audited');
[$code, , $err] = $page437('client', ['id' => (string)$c437], ['del_document' => '1', 'doc_id' => (string)$authDoc]);
check(!$one437("SELECT COUNT(*) FROM client_documents WHERE id=$authDoc") && str_contains($flash437(), 'Document moved to the trash'), 'S16: client.php deletes through document_delete() (an administrator)' . ($err ? ": $err" : ''));

/* ---- S17: the portal link ---- */
$closed = $client437('V437 Closed Client', ['status' => 'Closed - Other', 'a11y_contact' => 'Phone', 'email' => 'v437-closed@example.invalid']);
$cc = $get437('client', ['id' => $closed]);
check(str_contains($cc['portal_link']['confirm_text'], 'read-only') && str_contains($cc['portal_link']['confirm_text'], 'prefers contact by phone') && $cc['portal_link']['state'] === 'none', 'S17: a closed client\'s question says read-only and the phone preference');
check(str_contains(portal_link_sent_message(['status' => 'Active'], 'a@example.invalid'), 'every six months'), 'S17: the sent message says every six months');
$db->prepare('INSERT INTO portal_tokens (client_id, token, expires_at) VALUES (?,?,DATE_ADD(NOW(), INTERVAL 30 DAY))')->execute([$c437, bin2hex(random_bytes(24))]);
check($get437('client', ['id' => $c437])['portal_link']['state'] === 'active', 'S17: an active link says active');
$rv = $post437('revoke_portal_link', ['client_id' => $c437]);
[$st, $d] = $req437('POST', 'revoke_portal_link', ['client_id' => $c437]);
check(str_starts_with($rv['message'], 'Portal link turned off.') && !$one437("SELECT COUNT(*) FROM portal_tokens WHERE client_id=$c437") && $audits437('portal.revoke', 'mobile') === 1
    && $st === 409 && $d['error'] === 'This client has no portal link to turn off.', 'S17: revoke removes the link, audited; again 409');

/* ---- S18: review and the timeline ---- */
$ref = function (string $status) use ($db): int { $db->prepare("INSERT INTO referrals (reason, subject, body, counselor_name, counselor_agency, counselor_email, counselor_phone, client_name, status) VALUES ('r','s','b','c','a','e@example.invalid','1','V437 Referral Person',?)")->execute([$status]); return (int)$db->lastInsertId(); };
$rNew = $ref('New'); $rConv = $ref('Converted');
$a = $post437('review_referral', ['id' => $rNew]);
$b = $post437('review_referral', ['id' => $rConv]);
check($a['changed'] === true && $a['message'] === 'Referral marked as reviewed.' && $b['changed'] === false && $b['message'] === 'Nothing was changed: this referral is no longer New (its status is now Converted).', 'S18: review_referral says the website\'s sentences');
$tl = $get437('client_timeline', ['client_id' => $c437]);
check($tl['total'] === count($tl['events']) && $tl['events'][0]['date'] >= end($tl['events'])['date'] && $tl['events'][0]['month'] === date('F Y', strtotime($tl['events'][0]['date'])) && in_array('note', array_column($tl['events'], 'kind'), true)
    && in_array('Technology assessment recorded (Screen Reader)', array_column($tl['events'], 'text'), true) && is_array($tl['status_days'])
    && (int)$one437("SELECT COUNT(*) FROM audit_log WHERE action='client.view' AND entity_id=$c437 AND detail='timeline'") >= 1, 'S18: client_timeline lists events newest first and records the view');
$as437($gScoped437);
[$st] = $req437('GET', 'client_timeline', ['client_id' => $cOther437]);
$as437(null);
check($st === 403, 'S18: another caseload\'s timeline 403');
$rWeb = $ref('New');
[$code, , $err] = $page437('referrals', [], ['id' => (string)$rWeb, 'mark_reviewed' => '1']);
check($one437("SELECT status FROM referrals WHERE id=$rWeb") === 'Reviewed' && str_contains($flash437(), 'Referral marked as reviewed.'), 'S18: referrals.php reviews through referral_mark_reviewed()' . ($err ? ": $err" : ''));

/* ---- S19: the referral filters and the contact date ---- */
$rMine = $ref('New'); $db->exec("UPDATE referrals SET assigned_to=1 WHERE id=$rMine");
$db->prepare("INSERT INTO tasks (title, auto_key, status, due_date) VALUES ('contact', ?, 'Open', ?)")->execute(['ref-contact-' . $rMine, date('Y-m-d', strtotime('-1 day'))]);
$me = $get437('referrals', ['who' => 'me']);
$un = $get437('referrals', ['who' => 'unassigned']);
$all = $get437('referrals');
$mineRow = current(array_filter($me['referrals'], fn($x) => (int)$x['id'] === $rMine));
$assignedOf = fn(array $rows): array => array_map(fn($x) => $one437('SELECT assigned_to FROM referrals WHERE id=' . (int)$x['id']), $rows);
check($me['referrals'] && !array_filter($assignedOf($me['referrals']), fn($a) => (int)$a !== 1) && $un['referrals'] && !array_filter($assignedOf($un['referrals']), fn($a) => $a !== null)
    && $mineRow['contact_overdue'] === true && str_starts_with($mineRow['contact_text'], 'Contact overdue since ') && $all['total'] >= $me['total'] + $un['total'] && $all['limit'] === 100 && str_starts_with($all['heading'], 'All referrals ('),
    'S19: who=me and unassigned filter; an overdue contact task says "Contact overdue since"; the default is still all');

/* ---- S21: Pre-ETS cohorts ---- */
$co = $post437('add_cohort', ['name' => 'V437 Cohort', 'coordinator_id' => $u437, 'start_date' => date('Y-m-d'), 'end_date' => date('Y-m-d', strtotime('+30 days')), 'notes' => 'V437 notes', 'idem_key' => $key437()]);
$coRow = $db->query('SELECT * FROM cohorts WHERE id=' . (int)$co['id'])->fetch();
check((int)$coRow['coordinator_id'] === $u437 && $coRow['notes'] === 'V437 notes' && $coRow['end_date'] !== null, 'S21: add_cohort stores the coordinator, dates and notes');
$db->exec("INSERT INTO users (name,email,password_hash,role,is_active) VALUES ('V437 Inactive','v437-inactive@example.invalid','test','instructor',0)"); $uInactive = (int)$db->lastInsertId();
[$st, $d] = $req437('POST', 'add_cohort', ['name' => 'V437 Bad', 'coordinator_id' => $uInactive, 'idem_key' => $key437()]);
[$st2, $d2] = $req437('POST', 'add_cohort', ['name' => 'V437 Bad', 'start_date' => date('Y-m-d'), 'end_date' => date('Y-m-d', strtotime('-1 day')), 'idem_key' => $key437()]);
[$st3, $d3] = $req437('POST', 'add_cohort', ['name' => 'V437 Bad', 'notes' => str_repeat('n', 2001), 'idem_key' => $key437()]);
[$st4, $d4] = $req437('POST', 'add_cohort', ['name' => 'V437 Long school', 'school' => str_repeat('s', 191), 'idem_key' => $key437()]);
check($st4 === 422 && $d4['errors']['school'] === 'School / district: use 190 characters or fewer.' && !$one437("SELECT COUNT(*) FROM cohorts WHERE name='V437 Long school'"), 'S21: the app\'s school over 190 characters is refused on errors.school, as before');
$old = $post437('add_cohort', ['name' => 'V437 Old app cohort', 'idem_key' => $key437()]);
check($st === 422 && $d['errors']['coordinator_id'] === 'Choose an active staff member as the coordinator.' && $st2 === 422 && $d2['errors']['end_date'] === 'The end date cannot be before the start date.'
    && $st3 === 422 && $d3['errors']['notes'] === 'Notes: use 2,000 characters or fewer.' && (int)$one437('SELECT coordinator_id FROM cohorts WHERE id=' . (int)$old['id']) === 1,
    'S21: an inactive coordinator, an end before the start and long notes are refused on their fields; a 4.36.0 body makes the caller coordinator');
$coId = (int)$co['id'];
$db->prepare('INSERT INTO cohort_members (cohort_id, client_id) VALUES (?,?)')->execute([$coId, $c437]);
$lg = $post437('log_cohort_session', ['cohort_id' => $coId, 'session_date' => date('Y-m-d'), 'activity' => 'Workplace Readiness', 'duration_min' => 30, 'present' => [$c437], 'idem_key' => $key437()]);
$sid = (int)$lg['id'];
$coD = $get437('cohort', ['id' => $coId]);
check(str_ends_with($lg['message'], 'If anything is wrong, delete it under Recent sessions and log it again.') && $coD['coordinator_name'] === 'V437 Synthetic Instructor' && $coD['sessions'][0]['present_names'] === ['V437 Synthetic Client']
    && $coD['sessions'][0]['can_delete'] === true && $coD['active'] === true, 'S21: the log message, the coordinator\'s name and who was present are sent');
$del = $post437('delete_cohort_session', ['cohort_id' => $coId, 'session_id' => $sid]);
$del2 = $post437('delete_cohort_session', ['cohort_id' => $coId, 'session_id' => $sid]);
check(str_starts_with($del['message'], 'Session deleted: Workplace Readiness') && !$one437("SELECT COUNT(*) FROM cohort_attendance WHERE session_id=$sid") && $audits437('cohort.session_delete', "session #$sid:%present: client #$c437 (mobile)") === 1
    && $del2['changed'] === false && $del2['message'] === 'That session was already deleted, so nothing changed.', 'S21: delete removes the session and attendance, audits who was present; again "already deleted"');
$ar = $post437('set_cohort_active', ['cohort_id' => $coId, 'active' => false]);
$ar2 = $post437('set_cohort_active', ['cohort_id' => $coId, 'active' => 0]);
$archived = $get437('cohorts', ['show' => 'archived']);
check($ar['message'] === 'Cohort archived.' && (int)$one437("SELECT active FROM cohorts WHERE id=$coId") === 0 && $ar2['message'] === 'That cohort is already archived.' && in_array($coId, array_map('intval', array_column($archived['cohorts'], 'id')), true)
    && $audits437('cohort.archive', 'V437 Cohort (mobile)') === 1, 'S21: archive, again "already archived", listed under show=archived');
$ch = $get437('cohort_choices');
check($ch['activities'] === PREETS_ACTIVITIES && in_array('V437 Synthetic Instructor', array_column($ch['coordinators'], 'name'), true) && !in_array('V437 Inactive', array_column($ch['coordinators'], 'name'), true), 'S21: cohort_choices gives the active staff and the activities');
[$code, , $err] = $page437('programs', [], ['add_cohort' => '1', 'name' => 'V437 Web cohort', 'notes' => str_repeat('n', 2001)]);
check(!$one437("SELECT COUNT(*) FROM cohorts WHERE name='V437 Web cohort'") && str_contains($flash437() . json_encode($flash437()), 'Notes: use 2,000 characters or fewer.'), 'S21: programs.php refuses long notes through cohort_input()' . ($err ? ": $err" : ''));
[$code, $out, $err] = $page437('programs', []);
check(str_contains($out, 'Notes (optional; up to 2,000 characters)'), 'S21: the New cohort form has the Notes field' . ($err ? ": $err" : ''));

/* ---- S23: the knowledge base ---- */
$reply437('No knowledge-base article covers this, so this answer is general knowledge, not agency procedure. Use a screen reader.');
$kb = $post437('ai_kb', ['question' => 'zzv437 qqv437', 'general' => 1]);
[$st, $d] = $req437('POST', 'ai_kb', ['question' => 'zzv437 qqv437', 'general' => 0]);
[$st2, $d2] = $req437('POST', 'ai_kb', ['question' => '']);
check($kb['general'] === true && $kb['sources'] === [] && str_contains($sent437(), 'general knowledge') && $st === 422 && $d['steps'] === KB_NO_MATCH_STEPS && isset($d['errors']['question'])
    && $st2 === 422 && $d2['errors']['question'] === 'Type a question to ask.', 'S23: general gives the general answer; without it 422 with the steps; empty "Type a question to ask."');

/* ---- S24: translate ---- */
$reply437('Nyob zoo');
$tr = $post437('ai_translate', ['content' => 'Hello', 'lang' => 'Hmong']);
[$st, $d] = $req437('POST', 'ai_translate', ['content' => 'Hello', 'lang' => 'Hm0ng']);
[$st2, $d2] = $req437('POST', 'ai_translate', ['content' => '', 'lang' => 'Spanish']);
check($tr['text'] === 'Nyob zoo' && $st === 422 && $d['errors']['language'] === 'The language name can use only letters, spaces, hyphens, apostrophes, periods and parentheses (40 characters maximum).'
    && $st2 === 422 && $d2['errors']['content'] === 'Paste the text to translate.', 'S24: a typed language works; a bad name and empty text are refused on their fields');
[$code, $out, $err] = $page437('translate', [], ['go' => '1', 'lang' => 'Other (type below)', 'other' => 'Hm0ng', 'content' => 'x']);
check($code === 0 && str_contains($out, 'The language name can use only letters, spaces, hyphens, apostrophes, periods and parentheses (40 characters maximum).') && str_contains($out, 'id="err-other"'),
    'S24: translate.php shows the language error on its Other language field (translate_input_errors())' . ($err ? ": $err" : ''));

/* ---- S25: remediation ---- */
$db->prepare("UPDATE clients SET a11y_format='Large print' WHERE id=?")->execute([$c437]);
$reply437('Remediated');
$rm = $post437('ai_remediate', ['content' => 'Some text', 'mode' => 'plain', 'client_id' => $c437]);
[$st, $d] = $req437('POST', 'ai_remediate', ['content' => '']);
$as437($gScoped437);
[$st2] = $req437('POST', 'ai_remediate', ['content' => 'x', 'client_id' => $cOther437]);
$as437(null);
check(str_contains($sent437(), 'The reader prefers this format: Large print.') && $rm['format'] === 'Large print' && $st === 422 && $d['errors']['content'] === 'Paste some content first.' && $st2 === 403,
    'S25: client_id targets the preferred format; empty content is the website\'s sentence; another caseload 403');
[$code, $out, $err] = $page437('remediate', ['client' => (string)$c437], ['go' => '1', 'mode' => 'plain', 'content' => 'Web text']);
check(str_contains($sent437(), 'The reader prefers this format: Large print.') && str_contains($out, 'Large print'), 'S25: remediate.php uses remediate_prompt()' . ($err ? ": $err" : ''));

/* ---- S27: voice booking, remote with a meeting link ---- */
$db->exec("INSERT INTO settings(name,value) VALUES ('cfg.meet.zoom_client_id','synthetic-zoom-id'),('cfg.meet.zoom_client_secret','synthetic-zoom-secret') ON DUPLICATE KEY UPDATE value=VALUES(value)");
$keepMeet437 = $db->query('SELECT * FROM meeting_accounts')->fetchAll();
$db->exec('DELETE FROM meeting_accounts');
$vc = $client437('V437 Voice Client');
$when = date('Y-m-d', strtotime('+9 days')) . ' 06:15';   // a time no earlier file books
$reply437(json_encode(['action' => 'create', 'create' => 'session', 'client' => 'V437 Voice Client', 'start_at' => $when, 'duration' => 60, 'remote' => true]));
$v = $post437('voice_command', ['transcript' => 'book V437 Voice Client in three days at 10 remote']);
check(($v['action'] ?? '') === 'confirm' && !empty($v['params']['remote']) && !isset($v['params']['make_link']) && str_contains($v['speak'], 'It is a remote session.'), 'S27: a spoken remote session is confirmed as remote (no account connected, so no link)');
$b = $post437('voice_command', ['do' => 'schedule_session'] + $v['params']);
$sess = $db->query("SELECT * FROM scheduled_sessions WHERE client_id=$vc")->fetch();
check((int)$sess['is_remote'] === 1 && $sess['meeting_url'] === null && str_contains($b['speak'], 'It is a remote session.'), 'S27: booked with is_remote 1 and said so');
$db->exec("DELETE FROM scheduled_sessions WHERE client_id=$vc");
meeting_store(1, 'zoom', ['access_token' => 'v437-zoom-access', 'refresh_token' => 'v437-zoom-refresh', 'expires_in' => 3600], 'v437-zoom@example.invalid');
putenv('CMS_TEST_MEETINGS=' . json_encode(['https://api.zoom.us/v2/users/me/meetings' => ['status' => 201, 'body' => ['join_url' => 'https://zoom.us/j/4370000001']]]));
$v = $post437('voice_command', ['transcript' => 'book V437 Voice Client in three days at 10 in Zoom']);
$b = $post437('voice_command', ['do' => 'schedule_session'] + $v['params']);
$sess = $db->query("SELECT * FROM scheduled_sessions WHERE client_id=$vc")->fetch();
check(($v['params']['make_link'] ?? '') === 'zoom' && $sess['meeting_url'] === 'https://zoom.us/j/4370000001' && str_contains($b['speak'], 'A Zoom link was made in your account.') && $audits437('meeting.create', '%(voice)') === 1,
    'S27: with Zoom connected the link is made in the speaker\'s account, audited meeting.create');
$db->exec("DELETE FROM scheduled_sessions WHERE client_id=$vc");
putenv('CMS_TEST_MEETINGS=' . json_encode(['https://api.zoom.us/v2/users/me/meetings' => ['status' => 503, 'body' => null]]));
$b = $post437('voice_command', ['do' => 'schedule_session'] + $v['params']);
$sess = $db->query("SELECT * FROM scheduled_sessions WHERE client_id=$vc")->fetch();
check($sess && $sess['meeting_url'] === null && str_contains($b['speak'], 'The session is booked without a link'), 'S27: Zoom failing books the session without a link and says so');
putenv('CMS_TEST_MEETINGS');
$db->exec("DELETE FROM scheduled_sessions WHERE client_id=$vc");
$db->exec('DELETE FROM meeting_accounts');
foreach ($keepMeet437 as $row) { $cols = array_keys($row); $db->prepare('INSERT INTO meeting_accounts (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')')->execute(array_values($row)); }
check(str_contains((string)file_get_contents($root . '/public/schedule.php'), 'session_make_meeting('), 'S27: schedule.php makes its links through session_make_meeting()');

/* ---- An AI draft is audited only when the AI was actually asked (as eval_report.php) ---- */
$fill = $db->prepare("INSERT INTO login_attempts (ip, email, success) VALUES ('', 'ai-1', 1)");
for ($i = 0; $i < 60; $i++) $fill->execute();
$beforeAi = $audits437('ai.draft');
[$st, $d] = $req437('POST', 'ai_letter', ['client_id' => $c437, 'key' => 'welcome']);
[$st2, $d2] = $req437('POST', 'ai_draft', ['type' => 'justification', 'client_id' => $c437, 'device_name' => 'V437 limited']);
$db->exec("DELETE FROM login_attempts WHERE email = 'ai-1'");
check(str_contains((string)$d['error'], 'AI usage limit') && str_contains((string)$d2['error'], 'AI usage limit') && $audits437('ai.draft') === $beforeAi,
    'ai_letter and the justification draft refused before the AI is asked write no ai.draft audit entry');

/* ---- Every new POST action: another caseload's record, and no permission ---- */
$db->prepare("INSERT INTO client_releases (client_id, signer_name, recipient_type, recipient_name, info_scope, purpose, method, created_by) VALUES (?,'V437 S','Employer','V437 R','x','y','Email',1)")->execute([$cOther437]); $relOther = (int)$db->lastInsertId();
$db->prepare("INSERT INTO consent_forms (client_id, guardian_name, guardian_email, token, sent_at, signed_at, signed_name) VALUES (?,'V437 G','g2@example.invalid',?,NOW(),NOW(),'V437 G')")->execute([$cOther437, bin2hex(random_bytes(24))]); $cfOther = (int)$db->lastInsertId();
$db->prepare("INSERT INTO client_documents (client_id, category, title, orig_name, path) VALUES (?,'Other','V437 other doc','o.txt','o.txt')")->execute([$cOther437]); $docOther = (int)$db->lastInsertId();
$db->prepare('INSERT INTO portal_tokens (client_id, token, expires_at) VALUES (?,?,DATE_ADD(NOW(), INTERVAL 30 DAY))')->execute([$cOther437, bin2hex(random_bytes(24))]);
$db->prepare("INSERT INTO amendment_requests (track_id, client_id, requested_on, hours_requested, created_by) VALUES (?,?,CURDATE(),5,1)")->execute([$tOther437, $cOther437]); $arOther = (int)$db->lastInsertId();
$db->exec("INSERT INTO cohorts (name, active) VALUES ('V437 Other cohort', 1)"); $coOther = (int)$db->lastInsertId();
$db->prepare('INSERT INTO cohort_members (cohort_id, client_id) VALUES (?,?)')->execute([$coOther, $cOther437]);
$db->prepare("INSERT INTO cohort_sessions (cohort_id, session_date, activity, duration_min, staff_id) VALUES (?,CURDATE(),'Other',30,?)")->execute([$coOther, $u437]); $sessOther = (int)$db->lastInsertId();
$crossCaseload = [
    ['withdraw_release', ['client_id' => $cOther437, 'release_id' => $relOther]], ['withdraw_consent', ['client_id' => $cOther437, 'consent_id' => $cfOther]],
    ['record_privacy_notice', ['client_id' => $cOther437, 'how' => 'In person']], ['delete_document', ['id' => $docOther]],
    ['revoke_portal_link', ['client_id' => $cOther437]], ['save_letter', ['client_id' => $cOther437, 'how' => 'note', 'title' => 't', 'body' => 'b', 'idem_key' => $key437()]],
    ['ai_letter', ['client_id' => $cOther437, 'key' => 'welcome']], ['record_amendment', ['track_id' => $tOther437, 'requested_on' => date('Y-m-d'), 'hours' => '1', 'idem_key' => $key437()]],
    ['amendment_reply', ['track_id' => $tOther437, 'request_id' => $arOther, 'status' => 'Denied', 'replied_on' => date('Y-m-d')]],
    ['send_eval_report', ['assessment_id' => $asOther, 'sections' => $good, 'to' => 'x@example.invalid', 'signature' => $adminName437, 'idem_key' => $key437()]],
    ['set_cohort_active', ['cohort_id' => $coOther, 'active' => false]], ['delete_cohort_session', ['cohort_id' => $coOther, 'session_id' => $sessOther]],
];
$as437($gScoped437);
$refused = [];
foreach ($crossCaseload as [$act, $body]) { [$st] = $req437('POST', $act, $body); if ($st !== 403) $refused[] = "$act $st"; }
$as437(null);
check($refused === [] && $one437("SELECT withdrawn_at FROM client_releases WHERE id=$relOther") === null && $one437("SELECT withdrawn_at FROM consent_forms WHERE id=$cfOther") === null
    && (int)$one437("SELECT COUNT(*) FROM client_documents WHERE id=$docOther") === 1 && (int)$one437("SELECT COUNT(*) FROM portal_tokens WHERE client_id=$cOther437") === 1
    && $one437("SELECT status FROM amendment_requests WHERE id=$arOther") === 'Awaiting reply' && (int)$one437("SELECT COUNT(*) FROM cohort_sessions WHERE id=$sessOther") === 1
    && (int)$one437("SELECT active FROM cohorts WHERE id=$coOther") === 1, 'Every new POST action refuses another caseload\'s record (403) and changes nothing' . ($refused ? ': ' . implode(', ', $refused) : ''));
$gSchedOnly437 = $group437('V437 schedule only', 'schedule');
$as437($gSchedOnly437);
$refused = [];
foreach ($crossCaseload as [$act, $body]) { [$st, $d] = $req437('POST', $act, $body); if ($st !== 403 || !str_starts_with((string)$d['error'], 'Your account does not have access')) $refused[] = "$act $st"; }
$as437(null);
check($refused === [], 'Every new POST action needs its permission (403 without it)' . ($refused ? ': ' . implode(', ', $refused) : ''));

/* ---- Shared helpers the pages now call ---- */
foreach (['equipment' => ['equipment_set_status(', 'equipment_retire_preview(', 'equipment_input('], 'procurement' => ['device_delivery_inventory(', 'device_letter_doc(', 'device_justification_ai(', 'device_close_note('],
          'recommend' => ['recommend_context', 'recommend_ai('], 'eval_report' => ['eval_ai_draft(', 'eval_send('], 'accommodations' => ['worksite_report_doc('],
          'client' => ['case_summary_context(', 'assessment_goals_ai(', 'note_format_ai(', 'tech_assessment_attachment_check(', 'consent_withdraw(', 'privacy_notice_record(', 'document_delete(', 'portal_link_revoke(', 'portal_link_confirm_text('],
          'progress_review' => ['goal_progress_record('], 'amendment_request' => ['amendment_ai_draft(', 'amendment_row_text('], 'track_edit' => ['amendment_reason_prefill('],
          'letters' => ['letter_save(', 'letter_ai_draft('], 'referrals' => ['referral_mark_reviewed(', 'referral_contact_due_sql(', 'referral_who_sql('],
          'programs' => ['cohort_input(', 'cohort_session_delete(', 'cohort_set_active('], 'kb_ask' => ['kb_general_answer(', 'kb_question_error('],
          'translate' => ['translate_input_errors('], 'remediate' => ['remediate_prompt(', 'remediate_errors('], 'schedule' => ['session_make_meeting(']] as $page => $calls) {
    $src = (string)file_get_contents($root . '/public/' . $page . '.php');
    foreach ($calls as $c) check(str_contains($src, $c), "Shared: $page.php calls $c");
}
$apiSrc = (string)file_get_contents($root . '/public/api.php');
check(!str_contains($apiSrc, "'Current AT: '") && !str_contains($apiSrc, "fail('Provide content.')") && !str_contains($apiSrc, "\$acts = ['Job Exploration")
    && !preg_match("/case 'ai_draft'.*?LIMIT 8.*?case 'equipment_detail'/s", $apiSrc), 'Shared: api.php holds no copy of the recommendation, remediation, activity or case-summary logic');
} finally {
    // Put back the temporary core, every setting this file changed, the user's group and the groups.
    file_put_contents($tmp . '/core.php', $originalCore437);
    @unlink($replyFile437);
    putenv('CMS_TEST_MEETINGS'); putenv('CMS_TEST_VIRUS');
    $db->exec('UPDATE users SET group_id=NULL WHERE id=1');
    $db->exec('DELETE FROM settings');
    $restore437 = $db->prepare('INSERT INTO settings (name, value) VALUES (?, ?)');
    foreach ($settings437 as $name => $value) $restore437->execute([$name, $value]);
    $db->exec('DELETE FROM `groups` WHERE name LIKE ' . $db->quote('V437 %'));
    putenv('CMS_TEST_SESSION_ADD');
}
check($db->query('SELECT name, value FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR) == $settings437 && file_get_contents($tmp . '/core.php') === $originalCore437,
    'v437: every setting and the temporary core are as they were before this file');
