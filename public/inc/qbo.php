<?php
declare(strict_types=1);
// QuickBooks Online (4.21.0, owner request 2026-10-17). An administrator connects the organisation's
// QuickBooks company (Administration, QuickBooks); then every submitted invoice is sent there as an
// invoice to its customer (the funding agency, or a private-pay client), with lines for hours, mileage
// and expenses, and payments recorded in QuickBooks come back as payments here. The daily job does both;
// Administration, QuickBooks, can do it at once. Tokens are kept encrypted, as the meeting tokens are.
// VR invoices carry the authorization number and period, not the client's name; private-pay invoices
// name the client only when an administrator chose that (Settings: qbo.private_names).
// Only invoices submitted on or after the day QuickBooks was connected are sent (qbo_since), so the
// books never fill with old, already-paid invoices. Customers, items and invoice ids belong to one
// company: connecting another (or the sandbox) starts them afresh.

const QBO_SCOPE = 'com.intuit.quickbooks.accounting';
const QBO_MINOR = 75;

/** The app an administrator registered at developer.intuit.com (Settings, QuickBooks Online), or null. */
function qbo_app(): ?array {
    $q = is_array($GLOBALS['CONFIG']['qbo'] ?? null) ? $GLOBALS['CONFIG']['qbo'] : [];
    if (empty($q['client_id']) || empty($q['client_secret'])) return null;
    $sandbox = ($q['sandbox'] ?? 'no') === 'yes';
    return ['id' => (string)$q['client_id'], 'secret' => (string)$q['client_secret'], 'sandbox' => $sandbox,
        'authorize' => 'https://appcenter.intuit.com/connect/oauth2', 'token' => 'https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer',
        'revoke' => 'https://developer.api.intuit.com/v2/oauth2/tokens/revoke',
        'api' => $sandbox ? 'https://sandbox-quickbooks.api.intuit.com' : 'https://quickbooks.api.intuit.com',
        'redirect' => rtrim((string)cfg('base_url'), '/') . '/qbo_connect.php'];
}

/** HTTP for QuickBooks: [status, decoded body]. Tests (command line only): CMS_TEST_QBO, a JSON map of URL prefix => reply. */
function qbo_http(string $method, string $url, array $headers, ?string $body): array {
    if (PHP_SAPI === 'cli' && ($fake = getenv('CMS_TEST_QBO')) !== false && $fake !== '') {
        $best = null;
        foreach ((array)json_decode($fake, true) as $prefix => $reply) if (str_starts_with($url, $prefix) && ($best === null || strlen($prefix) > strlen($best[0]))) $best = [$prefix, $reply];
        return $best ? [(int)($best[1]['status'] ?? 200), $best[1]['body'] ?? null] : [599, null];
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, is_string($resp) ? json_decode($resp, true) : null];
}

/** The connected company, or null (also when the sandbox setting no longer matches the company connected). */
function qbo_connection(): ?array {
    try { $c = db()->query('SELECT * FROM qbo_connection WHERE id = 1')->fetch() ?: null; }
    catch (PDOException $e) { return null; } // before migration 4.21.0
    $app = qbo_app();
    if ($c && $app && (bool)(int)$c['sandbox'] !== $app['sandbox']) return null;
    return $c;
}

/** Forget everything tied to a company: its customers, items and the invoices' ids there. */
function qbo_forget_company(): void {
    db()->exec('DELETE FROM qbo_customers');
    db()->exec("DELETE FROM settings WHERE name LIKE 'qbo_item_%'");
    db()->exec('UPDATE invoices SET qbo_id = NULL, qbo_synced_at = NULL, qbo_error = NULL, qbo_status = NULL WHERE qbo_id IS NOT NULL OR qbo_error IS NOT NULL');
    // 4.27.0: payment ids belong to that company too; another company is never asked about them.
    db()->exec('UPDATE invoice_payments SET qbo_id = NULL WHERE qbo_id IS NOT NULL');
}

/** Exchange a code (or a refresh token) for tokens. */
function qbo_token_request(array $app, array $fields): ?array {
    [$code, $data] = qbo_http('POST', $app['token'], ['Accept: application/json', 'Content-Type: application/x-www-form-urlencoded',
        'Authorization: Basic ' . base64_encode($app['id'] . ':' . $app['secret'])], http_build_query($fields));
    return $code === 200 && is_array($data) && !empty($data['access_token']) ? $data : null;
}

/** Keep tokens for the company. */
function qbo_store(array $tok, ?string $realm = null, ?string $company = null, ?int $by = null): void {
    $exp = date('Y-m-d H:i:s', time() + max(60, (int)($tok['expires_in'] ?? 3600)) - 60);
    $rexp = isset($tok['x_refresh_token_expires_in']) ? date('Y-m-d H:i:s', time() + (int)$tok['x_refresh_token_expires_in']) : null;
    if ($realm !== null) {
        // Another company (or the sandbox in place of the real one) starts afresh, from today.
        if ((string)setting('qbo_realm') !== $realm . (qbo_app()['sandbox'] ?? false ? ':sandbox' : '')) {
            qbo_forget_company();
            set_setting('qbo_realm', $realm . (qbo_app()['sandbox'] ?? false ? ':sandbox' : ''));
            set_setting('qbo_since', date('Y-m-d'));
        }
        db()->prepare('REPLACE INTO qbo_connection (id, realm_id, sandbox, company_name, access_token, refresh_token, expires_at, refresh_expires_at, connected_by) VALUES (1,?,?,?,?,?,?,?,?)')
            ->execute([$realm, (qbo_app()['sandbox'] ?? false) ? 1 : 0, $company, meeting_seal((string)$tok['access_token']), meeting_seal((string)($tok['refresh_token'] ?? '')), $exp, $rexp, $by]);
    } else {
        db()->prepare('UPDATE qbo_connection SET access_token = ?, refresh_token = ?, expires_at = ?, refresh_expires_at = COALESCE(?, refresh_expires_at) WHERE id = 1')
            ->execute([meeting_seal((string)$tok['access_token']), meeting_seal((string)($tok['refresh_token'] ?? '')), $exp, $rexp]);
    }
}

/** A working access token, renewed under a lock (QuickBooks replaces the refresh token each time). */
function qbo_access_token(): ?string {
    $app = qbo_app();
    $c = qbo_connection();
    if (!$app || !$c) return null;
    if (strtotime((string)$c['expires_at']) > time()) return meeting_open((string)$c['access_token']);
    $own = !db()->inTransaction();
    if ($own) db()->beginTransaction();
    try {
        $c = db()->query('SELECT * FROM qbo_connection WHERE id = 1 FOR UPDATE')->fetch();
        if (!$c) { if ($own) db()->rollBack(); return null; }
        if (strtotime((string)$c['expires_at']) > time()) { if ($own) db()->commit(); return meeting_open((string)$c['access_token']); }
        $tok = qbo_token_request($app, ['grant_type' => 'refresh_token', 'refresh_token' => (string)meeting_open((string)$c['refresh_token'])]);
        if ($tok) qbo_store($tok);
        if ($own) db()->commit();
        return $tok ? (string)$tok['access_token'] : null;
    } catch (Throwable $e) {
        if ($own && db()->inTransaction()) db()->rollBack();
        throw $e;
    }
}

/** A call to the company's API: [status, body]. $path starts after /v3/company/{realm}. */
function qbo_api(string $method, string $path, ?array $body = null): array {
    $app = qbo_app(); $c = qbo_connection(); $tok = qbo_access_token();
    if (!$app || !$c || $tok === null) return [0, null];
    $url = $app['api'] . '/v3/company/' . rawurlencode((string)$c['realm_id']) . $path . (str_contains($path, '?') ? '&' : '?') . 'minorversion=' . QBO_MINOR;
    return qbo_http($method, $url, ['Authorization: Bearer ' . $tok, 'Accept: application/json', 'Content-Type: application/json'], $body !== null ? (string)json_encode($body) : null);
}

/** Run a QuickBooks query ("select * from Customer where ..."): the rows of $entity. */
function qbo_query(string $q, string $entity): ?array {
    [$code, $data] = qbo_api('GET', '/query?query=' . rawurlencode($q));
    if ($code !== 200 || !is_array($data)) return null;
    return (array)($data['QueryResponse'][$entity] ?? []);
}

/** A QuickBooks string literal. */
function qbo_lit(string $s): string {
    return "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $s) . "'";
}

/** A customer name QuickBooks takes: no colon (it marks sub-customers), at most 100 characters. */
function qbo_clean_name(string $name): string {
    $name = trim(preg_replace('/\s+/', ' ', str_replace(':', ' -', $name)) ?? '');
    return mb_substr($name !== '' ? $name : 'Customer', 0, 100);
}

/** The customer to bill (found by name, active or not, or made), by id; null with $why set. */
function qbo_customer(string $name, ?string &$why = null): ?string {
    $name = qbo_clean_name($name);
    $key = sha1(mb_strtolower($name));
    $q = db()->prepare('SELECT qbo_id FROM qbo_customers WHERE name_key = ?');
    $q->execute([$key]);
    if ($id = $q->fetchColumn()) return (string)$id;
    $rows = qbo_query('select Id, Active from Customer where DisplayName = ' . qbo_lit($name) . ' and Active in (true, false)', 'Customer');
    if ($rows === null) { $why = 'QuickBooks could not be searched for the customer.'; return null; }
    $id = (string)($rows[0]['Id'] ?? '');
    if ($id === '') {
        [$code, $data] = qbo_api('POST', '/customer', ['DisplayName' => $name]);
        $id = (string)($data['Customer']['Id'] ?? '');
        if ($code !== 200 || $id === '') {
            // The name is used by a supplier or an employee there (names are unique across all three).
            $why = 'QuickBooks would not make the customer "' . $name . '": ' . mb_substr((string)($data['Fault']['Error'][0]['Detail'] ?? $data['Fault']['Error'][0]['Message'] ?? 'reply ' . $code), 0, 120)
                . ' (a supplier or employee may have the same name there).';
            return null;
        }
    }
    db()->prepare('INSERT INTO qbo_customers (name_key, display_name, qbo_id) VALUES (?,?,?) ON DUPLICATE KEY UPDATE qbo_id = VALUES(qbo_id)')->execute([$key, $name, $id]);
    return $id;
}

/** The service item a line uses ("AT services", "Mileage" or "Expenses"), found or made with a service income account. */
function qbo_item(string $name): ?string {
    $k = 'qbo_item_' . strtolower(preg_replace('/\W+/', '_', $name) ?? $name);
    if (($id = setting($k)) !== null && $id !== '') return $id;
    $rows = qbo_query('select Id from Item where Name = ' . qbo_lit($name), 'Item');
    if ($rows === null) return null;
    $id = (string)($rows[0]['Id'] ?? '');
    if ($id === '') {
        // Service income if the company has it, else its first income account.
        $acct = qbo_query("select Id from Account where AccountType = 'Income' and AccountSubType = 'ServiceFeeIncome' maxresults 1", 'Account') ?: qbo_query("select Id from Account where AccountType = 'Income' maxresults 1", 'Account');
        $aid = (string)($acct[0]['Id'] ?? '');
        if ($aid === '') return null;
        [$code, $data] = qbo_api('POST', '/item', ['Name' => $name, 'Type' => 'Service', 'IncomeAccountRef' => ['value' => $aid]]);
        $id = (string)($data['Item']['Id'] ?? '');
        if ($code !== 200 || $id === '') return null;
    }
    set_setting($k, $id);
    return $id;
}

/** Who an invoice bills: the funding agency for VR; for private pay the client, by name only when chosen. */
function qbo_bill_name(array $inv): string {
    if (($inv['bill_mode'] ?? 'vr') === 'private') {
        $named = (($GLOBALS['CONFIG']['qbo']['private_names'] ?? 'no') ?: 'no') === 'yes';
        return $named ? (string)($inv['client_name'] ?? 'Private pay client') : 'Private pay client ' . (int)($inv['client_id'] ?? 0);
    }
    return trim((string)($inv['funding_agency'] ?? '')) ?: 'VR agency';
}

/** One invoice line: quantity and rate only when they make the amount exactly (QuickBooks refuses otherwise). */
function qbo_line(float $amount, string $item, string $what, float $qty, float $rate): array {
    $amount = round($amount, 2);
    $detail = ['ItemRef' => ['value' => $item]];
    if ($qty > 0 && $rate > 0 && abs(round($qty * $rate, 2) - $amount) < 0.001) { $detail['Qty'] = $qty; $detail['UnitPrice'] = $rate; }
    elseif ($qty > 0 && $rate > 0) $what .= ': ' . rtrim(rtrim(number_format($qty, 2, '.', ''), '0'), '.') . ' at $' . rtrim(rtrim(number_format($rate, 3, '.', ''), '0'), '.') . ' (rates changed in the period)';
    return ['DetailType' => 'SalesItemLineDetail', 'Amount' => $amount, 'Description' => mb_substr($what, 0, 4000), 'SalesItemLineDetail' => $detail];
}

/** The invoice as QuickBooks takes it. */
function qbo_invoice_body(array $inv, string $customer, array $items): array {
    $lines = [];
    $period = $inv['period_start'] ? ' ' . $inv['period_start'] . ' to ' . $inv['period_end'] : '';
    $auth = $inv['auth_number'] ? ' (authorization ' . $inv['auth_number'] . ')' : '';
    $hours = (float)$inv['hours'];
    if ((float)$inv['hours_amount'] > 0) $lines[] = qbo_line((float)$inv['hours_amount'], $items['hours'], 'Services' . $period . $auth, $hours, (float)($inv['hourly_rate'] ?? 0));
    if ((float)$inv['mileage_amount'] > 0) $lines[] = qbo_line((float)$inv['mileage_amount'], $items['mileage'], 'Mileage' . $period, (float)$inv['mileage'], (float)($inv['mileage_rate'] ?? 0));
    if ((float)$inv['expenses_amount'] > 0) $lines[] = qbo_line((float)$inv['expenses_amount'], $items['expenses'], 'Expenses' . $period, 0, 0);
    $body = ['CustomerRef' => ['value' => $customer], 'DocNumber' => mb_substr((string)$inv['invoice_number'], 0, 21),
        'TxnDate' => (string)($inv['submitted_at'] ?: date('Y-m-d', strtotime((string)$inv['created_at']))),
        'Line' => $lines, 'PrivateNote' => mb_substr('From the Case Management System, invoice #' . (int)$inv['id'] . $auth, 0, 4000)];
    if (!empty($inv['due_date'])) $body['DueDate'] = (string)$inv['due_date'];
    return $body;
}

/** Record one QuickBooks error on the invoice. */
function qbo_fail(array $inv, string $why): string {
    db()->prepare('UPDATE invoices SET qbo_error = ? WHERE id = ?')->execute([mb_substr($why, 0, 255), (int)$inv['id']]);
    return $why;
}

/** Send one invoice (once: an invoice with the same number already there is used). Returns null, or what went wrong. */
function qbo_send_invoice(array $inv): ?string {
    $why = null;
    $customer = qbo_customer(qbo_bill_name($inv), $why);
    if ($customer === null) return qbo_fail($inv, (string)$why);
    // Sent before, with the reply lost: the invoice with this number is used, not made twice.
    $found = qbo_query('select Id from Invoice where DocNumber = ' . qbo_lit(mb_substr((string)$inv['invoice_number'], 0, 21)), 'Invoice');
    if ($found === null) return qbo_fail($inv, 'QuickBooks could not be searched for the invoice.');
    $id = (string)($found[0]['Id'] ?? '');
    if ($id === '') {
        $items = ['hours' => qbo_item('AT services'), 'mileage' => qbo_item('Mileage'), 'expenses' => qbo_item('Expenses')];
        if (in_array(null, $items, true)) return qbo_fail($inv, 'QuickBooks could not find or make the service items (it needs an income account).');
        // requestid: QuickBooks makes it once even if this very request is sent again.
        [$code, $data] = qbo_api('POST', '/invoice?requestid=cms-inv-' . (int)$inv['id'], qbo_invoice_body($inv, $customer, $items));
        $id = (string)($data['Invoice']['Id'] ?? '');
        if ($code !== 200 || $id === '') return qbo_fail($inv, 'QuickBooks refused the invoice (reply ' . $code . '): ' . mb_substr((string)($data['Fault']['Error'][0]['Detail'] ?? $data['Fault']['Error'][0]['Message'] ?? ''), 0, 150));
    }
    db()->prepare('UPDATE invoices SET qbo_id = ?, qbo_synced_at = NOW(), qbo_error = NULL, qbo_status = NULL WHERE id = ?')->execute([$id, (int)$inv['id']]);
    audit(null, 'qbo.invoice', 'invoice', (int)$inv['id'], 'sent to QuickBooks as #' . $id);
    return null;
}

/** What a QuickBooks payment applies to one QuickBooks invoice. */
function qbo_payment_amount(array $payment, string $invoiceQboId): float {
    $amount = 0.0;
    foreach ((array)($payment['Line'] ?? []) as $pl) foreach ((array)($pl['LinkedTxn'] ?? []) as $x) if (($x['TxnType'] ?? '') === 'Invoice' && (string)($x['TxnId'] ?? '') === $invoiceQboId) $amount += (float)($pl['Amount'] ?? 0);
    return $amount;
}

/** Remove here a payment voided or deleted in QuickBooks (4.27.0), with the invoice's totals recalculated. */
function qbo_drop_payment(array $p): void {
    $pdo = db();
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        $pdo->prepare('SELECT id FROM invoices WHERE id = ? FOR UPDATE')->execute([(int)$p['invoice_id']]);
        $pdo->prepare('DELETE FROM invoice_payments WHERE id = ? AND qbo_id = ?')->execute([(int)$p['id'], (string)$p['qbo_id']]);
        invoice_retotal((int)$p['invoice_id']);
        if ($own) $pdo->commit();
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    audit(null, 'invoice.payment_remove', 'invoice', (int)$p['invoice_id'], 'payment ' . fmt_money((float)$p['amount']) . ' of ' . $p['received_on']
        . ' removed: voided or deleted in QuickBooks (#' . $p['qbo_id'] . ')');
}

/** Bring the payments made on a sent invoice in QuickBooks here: each QuickBooks payment once, with its own date. */
function qbo_check_payments(array $inv): ?string {
    [$code, $data] = qbo_api('GET', '/invoice/' . rawurlencode((string)$inv['qbo_id']));
    if ($code === 400 || $code === 404 || (isset($data['Fault']) && !isset($data['Invoice']))) {
        db()->prepare("UPDATE invoices SET qbo_status = 'missing', qbo_error = 'It was deleted in QuickBooks, so it is no longer checked.' WHERE id = ?")->execute([(int)$inv['id']]);
        return null;
    }
    if ($code !== 200 || !isset($data['Invoice'])) return qbo_fail($inv, 'QuickBooks could not be read for this invoice (reply ' . $code . ').');
    $q = $data['Invoice'];
    $linked = [];
    foreach ((array)($q['LinkedTxn'] ?? []) as $lt) {
        if (($lt['TxnType'] ?? '') !== 'Payment' || !is_string($lt['TxnId'] ?? null)) continue;
        $linked[] = (string)$lt['TxnId'];
        $ref = 'QuickBooks payment ' . $lt['TxnId'];
        // 4.27.0: a payment recorded here and sent there carries its QuickBooks id, not this reference.
        $seen = db()->prepare('SELECT 1 FROM invoice_payments WHERE invoice_id = ? AND (qbo_id = ? OR reference = ?)');
        $seen->execute([(int)$inv['id'], (string)$lt['TxnId'], $ref]);
        if ($seen->fetchColumn()) continue;
        [$pc, $pd] = qbo_api('GET', '/payment/' . rawurlencode($lt['TxnId']));
        if ($pc !== 200 || !isset($pd['Payment'])) return qbo_fail($inv, 'A QuickBooks payment could not be read (reply ' . $pc . ').');
        $amount = qbo_payment_amount($pd['Payment'], (string)$inv['qbo_id']);
        if ($amount <= 0) continue;
        $on = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($pd['Payment']['TxnDate'] ?? '')) ? (string)$pd['Payment']['TxnDate'] : date('Y-m-d');
        $err = invoice_apply((int)$inv['id'], 'payment', round($amount, 2), $on, $ref, 'Recorded in QuickBooks', 0);
        if ($err !== null) return qbo_fail($inv, 'A QuickBooks payment could not be recorded here: ' . $err);
        db()->prepare('UPDATE invoice_payments SET qbo_id = ? WHERE invoice_id = ? AND reference = ?')->execute([(string)$lt['TxnId'], (int)$inv['id'], $ref]);
    }
    // 4.27.0: a payment that came from QuickBooks, or was sent there, for this invoice and is no longer
    // linked to it there (voided or deleted in QuickBooks) is removed here too. QuickBooks is asked
    // about each one first, so a payment still applied to this invoice is never removed.
    $gone = db()->prepare('SELECT * FROM invoice_payments WHERE invoice_id = ? AND qbo_id IS NOT NULL');
    $gone->execute([(int)$inv['id']]);
    foreach ($gone->fetchAll() as $p) {
        if (in_array((string)$p['qbo_id'], $linked, true)) continue;
        [$pc, $pd] = qbo_api('GET', '/payment/' . rawurlencode((string)$p['qbo_id']));
        // Gone only when QuickBooks says so: not found (404, or 400 with fault 610 "Object Not Found"),
        // or read back with nothing applied to this invoice. Anything else removes nothing.
        $notFound = $pc === 404 || ($pc === 400 && (string)($pd['Fault']['Error'][0]['code'] ?? '') === '610');
        $zero = $pc === 200 && isset($pd['Payment']) && qbo_payment_amount($pd['Payment'], (string)$inv['qbo_id']) <= 0;
        if ($pc === 200 && isset($pd['Payment']) && !$zero) continue;
        if (!$notFound && !$zero) return qbo_fail($inv, 'A QuickBooks payment could not be checked (reply ' . $pc . ').');
        qbo_drop_payment($p);
    }
    // Payments recorded here (not ones that came from QuickBooks) go there, each once.
    $mine = db()->prepare("SELECT * FROM invoice_payments WHERE invoice_id = ? AND kind = 'payment' AND qbo_id IS NULL AND (reference IS NULL OR reference NOT LIKE 'QuickBooks payment %')");
    $mine->execute([(int)$inv['id']]);
    foreach ($mine->fetchAll() as $p) {
        [$pc, $pd] = qbo_api('POST', '/payment?requestid=cms-pay-' . (int)$p['id'], ['CustomerRef' => $q['CustomerRef'] ?? null, 'TotalAmt' => round((float)$p['amount'], 2), 'TxnDate' => (string)$p['received_on'],
            'PaymentRefNum' => mb_substr((string)($p['reference'] ?? ''), 0, 21), 'Line' => [['Amount' => round((float)$p['amount'], 2), 'LinkedTxn' => [['TxnId' => (string)$inv['qbo_id'], 'TxnType' => 'Invoice']]]]]);
        $pid = (string)($pd['Payment']['Id'] ?? '');
        if ($pc !== 200 || $pid === '') return qbo_fail($inv, 'A payment recorded here could not be added in QuickBooks (reply ' . $pc . ').');
        db()->prepare('UPDATE invoice_payments SET qbo_id = ? WHERE id = ?')->execute([$pid, (int)$p['id']]);
        $q['Balance'] = (float)($q['Balance'] ?? 0) - (float)$p['amount'];
    }
    // 4.27.0: Paid (no longer checked) only when the payments recorded here settle it too. A zero
    // balance there that the payments here do not cover (a void, credit memo or discount) is shown to staff.
    if ((float)($q['Balance'] ?? 1) <= 0.004) {
        $now = db()->prepare('SELECT * FROM invoices WHERE id = ?');
        $now->execute([(int)$inv['id']]);
        $row = $now->fetch();
        if ($row && invoice_balance($row) > 0.004) return qbo_fail($inv, 'Settled in QuickBooks by another kind of transaction (such as a credit memo, discount or void): record it here. ' . fmt_money(invoice_balance($row)) . ' is still owed here.');
        db()->prepare("UPDATE invoices SET qbo_status = 'paid' WHERE id = ?")->execute([(int)$inv['id']]);
    }
    db()->prepare('UPDATE invoices SET qbo_synced_at = NOW(), qbo_error = NULL WHERE id = ?')->execute([(int)$inv['id']]);
    return null;
}

/** Void in QuickBooks an invoice voided or denied here after it was sent (its hours are billed again on a new invoice). */
function qbo_void_invoice(array $inv): ?string {
    [$code, $data] = qbo_api('GET', '/invoice/' . rawurlencode((string)$inv['qbo_id']));
    if ($code !== 200 || !isset($data['Invoice']['SyncToken'])) {
        if ($code === 400 || $code === 404) { db()->prepare("UPDATE invoices SET qbo_status = 'missing' WHERE id = ?")->execute([(int)$inv['id']]); return null; }
        return qbo_fail($inv, 'QuickBooks could not be read to void this invoice (reply ' . $code . ').');
    }
    [$code] = qbo_api('POST', '/invoice?operation=void', ['Id' => (string)$inv['qbo_id'], 'SyncToken' => (string)$data['Invoice']['SyncToken']]);
    if ($code !== 200) return qbo_fail($inv, 'QuickBooks did not void the invoice (reply ' . $code . '). Void it there by hand.');
    db()->prepare("UPDATE invoices SET qbo_status = 'voided', qbo_error = NULL, qbo_synced_at = NOW() WHERE id = ?")->execute([(int)$inv['id']]);
    audit(null, 'qbo.void', 'invoice', (int)$inv['id'], 'voided in QuickBooks (#' . $inv['qbo_id'] . ')');
    return null;
}

/**
 * The daily work (and Send invoices now), one run at a time: new submitted invoices sent; sent ones
 * checked for payments; ones voided or denied here voided there. Each part has its own limit, so a
 * backlog of one never starves the others. Returns [done, problems].
 */
function qbo_sync_all(int $limit = 100): array {
    if (!qbo_app() || !qbo_connection()) return [0, 0];
    if ((int)db()->query("SELECT GET_LOCK('cms_qbo_sync', 0)")->fetchColumn() !== 1) return [0, 0]; // another run is going
    $ok = 0; $bad = 0; $lim = max(1, min(1000, $limit));
    try {
        $since = (string)(setting('qbo_since') ?? date('Y-m-d'));
        $base = 'SELECT i.*, t.client_id, c.name AS client_name FROM invoices i JOIN training_tracks t ON t.id = i.track_id JOIN clients c ON c.id = t.client_id WHERE ';
        $parts = [
            // Submitted since connecting, not yet there (Paid ones too: their payments follow right after).
            ['qbo_send_invoice', $base . "i.qbo_id IS NULL AND i.status IN ('Submitted', 'Paid') AND COALESCE(i.submitted_at, DATE(i.created_at)) >= " . db()->quote($since) . ' ORDER BY i.qbo_error IS NOT NULL, i.id'],
            ['qbo_check_payments', $base . "i.qbo_id IS NOT NULL AND i.qbo_status IS NULL AND i.status IN ('Submitted', 'Paid') ORDER BY i.qbo_synced_at, i.id"],
            ['qbo_void_invoice', $base . "i.qbo_id IS NOT NULL AND i.status IN ('Void', 'Denied') AND (i.qbo_status IS NULL OR i.qbo_status NOT IN ('voided', 'missing')) ORDER BY i.id"],
        ];
        foreach ($parts as [$fn, $sql]) {
            foreach (db()->query($sql . ' LIMIT ' . $lim)->fetchAll() as $inv) {
                try { $fn($inv) === null ? $ok++ : $bad++; }
                catch (Throwable $e) { $bad++; error_log($fn . ': ' . get_class($e)); }
            }
        }
        set_setting('qbo_synced_at', date('c'));
    } finally {
        db()->query("SELECT RELEASE_LOCK('cms_qbo_sync')");
    }
    return [$ok, $bad];
}
