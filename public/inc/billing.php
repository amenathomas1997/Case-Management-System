<?php
declare(strict_types=1);
// Getting paid (3.0.34): the organisation's billing details, how each training track is
// paid for, the authorization limits work is checked against, and every change to an
// invoice after it is sent (payments, write-offs, denials and voids). The website, the
// app's API and the voice assistant all call these, so the rules are the same
// everywhere. Owner decisions (2026-10-15): work outside an authorization is refused
// until an amendment is recorded; anyone with the billing permission may write off.

const BILLING_MODES = ['vr' => 'VR or agency authorization', 'private' => 'Private pay (the client pays)', 'none' => 'No charge'];

/** An open balance on a sent invoice, as SQL on the invoices table aliased i. */
const INVOICE_OPEN_SQL = "i.status IN ('Submitted','Paid') AND i.total_amount - COALESCE(i.paid_amount, 0) - i.written_off > 0.004";

/** The organisation's billing details (Administration > Billing details) and the
 *  private-pay fee rule the public referral form explains. */
function billing_settings(): array {
    $g = fn(string $k, string $d = ''): string => (string)(setting('billing_' . $k) ?? $d);
    $terms = (int)$g('terms_days', '30');
    $rate = (float)$g('private_rate', '17.50');
    $threshold = (float)$g('private_threshold', '2000');
    return [
        'vendor_number' => $g('vendor_number'), 'tax_id' => $g('tax_id'), 'remit_to' => $g('remit_to'),
        'phone' => $g('phone'), 'email' => $g('email'),
        'terms_days' => $terms >= 0 && $terms <= 365 ? $terms : 30,
        'private_rate' => $rate > 0 ? $rate : 17.50,
        'private_threshold' => $threshold > 0 ? $threshold : 2000.0,
    ];
}

/** A track's billing mode: 'vr', 'private' or 'none'. */
function track_mode(array $track): string {
    $m = (string)($track['billing_mode'] ?? 'vr');
    return isset(BILLING_MODES[$m]) ? $m : 'vr';
}

/** Whether a track needs an hourly rate above $0 (every mode but no charge). */
function track_needs_rate(array $track): bool {
    return track_mode($track) !== 'none';
}

/**
 * Why this work cannot be logged on this track, or null when it can. A track that is
 * not Active takes no work. On a VR track the work must fall inside the authorization's
 * dates and stay within its authorized hours and dollars (track_rollup()'s limit), with
 * $addHours and $addAmount the work being added. Each refusal says what to do: record
 * the amendment on Edit track / authorization, then log the work. Call it with the
 * track row locked (SELECT ... FOR UPDATE) so two entries cannot both pass.
 */
function work_authorization_block(array $track, string $date, float $addHours, float $addAmount): ?string {
    $status = (string)($track['status'] ?? 'Active');
    if ($status !== 'Active') return 'This training track is ' . $status . ', so no work can be logged to it. Reopen it on Edit track / authorization first.';
    if (track_mode($track) !== 'vr') return null;
    $fix = ' Record the amendment on Edit track / authorization first; nothing was saved.';
    $issue = work_authorization_issue($track, $date);
    if ($issue !== null) return $issue . $fix;
    $r = track_rollup((int)$track['id']);
    $hoursAfter = round($r['hours_worked'] + $addHours, 2);
    if ($addHours > 0 && $r['authorized_hours'] > 0 && $hoursAfter > $r['authorized_hours'] + 0.0001) {
        return 'This would bring the hours to ' . fmt_hours($hoursAfter) . ' of the ' . fmt_hours($r['authorized_hours']) . ' authorized ('
            . fmt_hours(round($hoursAfter - $r['authorized_hours'], 2)) . ' over).' . $fix;
    }
    // The dollar limit applies when the authorization states one; without it, the hours
    // limit above is the authorization (mileage and expenses on top are not refused).
    $usedAfter = round($r['total_billed'] + $r['expenses_amount'] + $addAmount, 2);
    if ($addAmount > 0 && ($track['authorized_amount'] ?? null) !== null && $r['authorized_funds'] > 0 && $usedAfter > $r['authorized_funds'] + 0.004) {
        return 'This would bring the amount to ' . fmt_money($usedAfter) . ' of the ' . fmt_money($r['authorized_funds']) . ' authorized ('
            . fmt_money(round($usedAfter - $r['authorized_funds'], 2)) . ' over).' . $fix;
    }
    return null;
}

/** What is still owed on an invoice row (0 for a Draft, Denied or Void invoice). */
function invoice_balance(array $i): float {
    if (!in_array($i['status'] ?? '', ['Submitted', 'Paid'], true)) return 0.0;
    return max(0.0, round((float)$i['total_amount'] - (float)($i['paid_amount'] ?? 0) - (float)($i['written_off'] ?? 0), 2));
}

/** The due date for an invoice submitted on $date, from the payment terms. */
function invoice_due_date(string $date, ?string $agency = null): string {
    // The agency's own payment terms when it has them (4.1.0), else Billing details.
    return date('Y-m-d', strtotime($date . ' +' . agency_terms_days($agency) . ' days'));
}

/** A date typed for a submission or payment: a real date from the invoice's creation
 *  to today, or null. */
function billing_date_in(mixed $raw, string $notBefore): ?string {
    $d = valid_date(is_string($raw) ? trim($raw) : '', 2000, (int)date('Y'));
    if ($d === null || $d > date('Y-m-d') || $d < substr($notBefore, 0, 10)) return null;
    return $d;
}

/** Submit a Draft on $date: its due date follows from the terms. True when it changed. */
function invoice_submit(int $id, string $date): bool {
    $ag = db()->prepare('SELECT funding_agency FROM invoices WHERE id = ?');
    $ag->execute([$id]);
    $st = db()->prepare("UPDATE invoices SET status = 'Submitted', submitted_at = ?, due_date = ? WHERE id = ? AND status = 'Draft'");
    $st->execute([$date, invoice_due_date($date, ($a = $ag->fetchColumn()) === false ? null : (string)$a), $id]);
    return $st->rowCount() === 1;
}

/**
 * Record a payment or a write-off on a sent invoice. Returns null on success, else the
 * sentence saying why nothing was recorded. A payment or write-off can never take the
 * balance below zero; when it reaches zero the invoice is Paid. The running totals on
 * the invoice (paid_amount, written_off, paid_at, payment_ref) follow the rows.
 */
function invoice_apply(int $id, string $kind, float $amount, string $on, ?string $ref, ?string $note, int $userId): ?string {
    if (!in_array($kind, ['payment', 'writeoff'], true)) return 'Choose a payment or a write-off.';
    $amount = round($amount, 2);
    if ($amount <= 0) return 'Enter an amount greater than $0.00.';
    if ($kind === 'writeoff' && trim((string)$note) === '') return 'Give the reason for the write-off (for example, the agency paid the authorized rate only).';
    $pdo = db();
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        $q = $pdo->prepare('SELECT * FROM invoices WHERE id = ? FOR UPDATE');
        $q->execute([$id]);
        $inv = $q->fetch();
        if (!$inv || !in_array($inv['status'], ['Submitted', 'Paid'], true)) {
            if ($own) $pdo->rollBack();
            return 'Only a submitted invoice takes payments' . ($inv ? '; this one is ' . $inv['status'] . '.' : '.');
        }
        $balance = invoice_balance($inv);
        if ($amount > $balance + 0.004) {
            if ($own) $pdo->rollBack();
            return 'That is more than the ' . fmt_money($balance) . ' still owed on invoice ' . $inv['invoice_number'] . '. Nothing was recorded.';
        }
        $pdo->prepare('INSERT INTO invoice_payments (invoice_id, kind, amount, received_on, reference, note, recorded_by) VALUES (?,?,?,?,?,?,?)')
            ->execute([$id, $kind, $amount, $on, $ref !== null && $ref !== '' ? mb_substr($ref, 0, 80) : null, $note !== null && $note !== '' ? mb_substr($note, 0, 255) : null, $userId]);
        invoice_retotal($id);
        if ($own) $pdo->commit();
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    audit($userId, $kind === 'payment' ? 'invoice.payment_add' : 'invoice.write_off', 'invoice', $id, fmt_money($amount) . ' on ' . $on . ($ref ? ' ref ' . $ref : '') . ($note ? ': ' . $note : ''));
    return null;
}

/**
 * Whether the QuickBooks sync checks this invoice's payments (4.27.0): QuickBooks is connected, the
 * invoice is there, it is Submitted or Paid, and it is not settled or deleted there. With $orSettled,
 * a settled ('paid') invoice counts too, since refusing a removal puts it back in the check.
 */
function invoice_qbo_checked(array $inv, bool $orSettled = false): bool {
    if (empty($inv['qbo_id']) || !in_array($inv['status'] ?? '', ['Submitted', 'Paid'], true)) return false;
    $st = $inv['qbo_status'] ?? null;
    if (!($st === null || ($orSettled && $st === 'paid'))) return false;
    return function_exists('qbo_app') && qbo_app() !== null && qbo_connection() !== null;
}

/** Remove one payment or write-off recorded by mistake (with the reason audited). */
function invoice_payment_remove(int $paymentId, string $reason, int $userId): ?string {
    if (trim($reason) === '') return 'Say why the entry is being removed.';
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $q = $pdo->prepare('SELECT p.*, i.status, i.invoice_number FROM invoice_payments p JOIN invoices i ON i.id = p.invoice_id WHERE p.id = ? FOR UPDATE');
        $q->execute([$paymentId]);
        $p = $q->fetch();
        if (!$p) { $pdo->rollBack(); return 'That payment entry no longer exists.'; }
        $lk = $pdo->prepare('SELECT * FROM invoices WHERE id = ? FOR UPDATE');
        $lk->execute([(int)$p['invoice_id']]);
        $inv = $lk->fetch() ?: [];
        // 4.27.0: a payment that is in QuickBooks, on an invoice the sync still checks, is voided there;
        // the next sync removes it here. A settled ('paid') invoice is put back in the check first.
        // When nothing would ever remove it (not connected, the invoice not there or deleted there),
        // it is removed here as before.
        if (($p['qbo_id'] ?? null) !== null && (string)$p['qbo_id'] !== '' && $inv && invoice_qbo_checked($inv, true)) {
            if (($inv['qbo_status'] ?? null) === 'paid') $pdo->prepare('UPDATE invoices SET qbo_status = NULL WHERE id = ?')->execute([(int)$inv['id']]);
            $pdo->commit();
            return 'This payment is in QuickBooks: void it there; it is then removed here at the next sync.';
        }
        $pdo->prepare('DELETE FROM invoice_payments WHERE id = ?')->execute([$paymentId]);
        invoice_retotal((int)$p['invoice_id']);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    audit($userId, 'invoice.payment_remove', 'invoice', (int)$p['invoice_id'], ($p['kind'] === 'payment' ? 'payment ' : 'write-off ') . fmt_money((float)$p['amount'])
        . ' of ' . $p['received_on'] . ' removed: ' . mb_substr($reason, 0, 200));
    return null;
}

/** Recompute an invoice's payment totals from its rows, and Paid or Submitted from its
 *  balance. Call with the invoice locked. */
function invoice_retotal(int $id): void {
    $s = db()->prepare("SELECT COALESCE(SUM(CASE WHEN kind = 'payment' THEN amount END), 0) paid, COALESCE(SUM(CASE WHEN kind = 'writeoff' THEN amount END), 0) written,
        MAX(CASE WHEN kind = 'payment' THEN received_on END) last_on,
        (SELECT reference FROM invoice_payments WHERE invoice_id = ? AND kind = 'payment' AND reference IS NOT NULL ORDER BY received_on DESC, id DESC LIMIT 1) last_ref
        FROM invoice_payments WHERE invoice_id = ?");
    $s->execute([$id, $id]);
    $t = $s->fetch();
    db()->prepare("UPDATE invoices SET paid_amount = ?, written_off = ?, paid_at = ?, payment_ref = ?,
        status = CASE WHEN status IN ('Submitted','Paid') THEN IF(total_amount - ? - ? <= 0.004, 'Paid', 'Submitted') ELSE status END WHERE id = ?")
        ->execute([(float)$t['paid'] > 0 ? round((float)$t['paid'], 2) : null, round((float)$t['written'], 2), $t['last_on'], $t['last_ref'], (float)$t['paid'], (float)$t['written'], $id]);
}

/**
 * Close a submitted invoice as Denied (the funder refused it) or Void (sent in error).
 * It stays on record with its number and the reason; its hours, mileage and expenses
 * are released so they can be corrected and invoiced again. An invoice that has taken
 * a payment or write-off is not closed: remove those entries first.
 */
function invoice_close(int $id, string $to, string $reason, int $userId): ?string {
    if (!in_array($to, ['Denied', 'Void'], true)) return 'Choose Denied or Void.';
    if (trim($reason) === '') return 'Give the reason (for example, the denial reason on the remittance).';
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $q = $pdo->prepare('SELECT * FROM invoices WHERE id = ? FOR UPDATE');
        $q->execute([$id]);
        $inv = $q->fetch();
        if (!$inv || $inv['status'] !== 'Submitted') { $pdo->rollBack(); return 'Only a submitted invoice can be marked ' . $to . ($inv ? '; this one is ' . $inv['status'] . '.' : '.'); }
        if ((float)($inv['paid_amount'] ?? 0) > 0 || (float)$inv['written_off'] > 0) {
            $pdo->rollBack();
            return 'Invoice ' . $inv['invoice_number'] . ' has payments or write-offs recorded. Remove them first, then mark it ' . $to . '.';
        }
        $pdo->prepare('UPDATE work_logs SET is_billed = 0, invoice_id = NULL WHERE invoice_id = ?')->execute([$id]);
        $pdo->prepare('UPDATE track_expenses SET is_billed = 0, invoice_id = NULL WHERE invoice_id = ?')->execute([$id]);
        $pdo->prepare('UPDATE invoices SET status = ?, closed_reason = ? WHERE id = ?')->execute([$to, mb_substr(trim($reason), 0, 255), $id]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    audit($userId, $to === 'Denied' ? 'invoice.denied' : 'invoice.void', 'invoice', $id, $inv['invoice_number'] . ' ' . fmt_money((float)$inv['total_amount']) . ': ' . mb_substr(trim($reason), 0, 200));
    return null;
}

/**
 * The Bill to of an invoice (4.28.0), built as generate_invoice() builds it: a private-pay invoice
 * is billed to the client (name, address, and the email only once confirmed); a VR invoice to the
 * agency's billing address lines (Funding agencies), then the counselor's name and agency, and the
 * counselor's email only once confirmed (counselor_verified = 1). $client is a clients row.
 */
function invoice_bill_to(array $client, string $mode, ?string $agency): string {
    $lines = $mode === 'private'
        ? [$client['name'] ?? null, $client['address'] ?? null, (int)($client['email_verified'] ?? 0) === 1 ? ($client['email'] ?? null) : null]
        : array_merge(agency_bill_to_lines((string)$agency), [$client['counselor_name'] ?? null, $client['counselor_agency'] ?? null,
            (int)($client['counselor_verified'] ?? 0) === 1 ? ($client['counselor_email'] ?? null) : null]);
    return implode("\n", array_filter(array_map(fn($v) => trim((string)$v), $lines), fn($v) => $v !== ''));
}

/**
 * Re-address the client's Draft invoices to who they bill now (4.28.0): each Draft's Bill to is
 * rebuilt for its own bill mode (invoice_bill_to()), so a private-pay Draft stays billed to the
 * client and a VR Draft keeps its agency's billing address. Sent invoices keep what was sent.
 * Reads the client row as it is now, so call it after the client's change is written (inside the
 * same transaction is fine). Returns how many Drafts changed.
 */
function draft_invoices_readdress(int $clientId): int {
    $c = db()->prepare('SELECT name, address, email, email_verified, counselor_name, counselor_agency, counselor_email, counselor_verified FROM clients WHERE id = ?');
    $c->execute([$clientId]);
    $client = $c->fetch();
    if (!$client) return 0;
    // An older Draft with no agency of its own uses its track's (as the reports do), so it keeps the agency's billing address.
    $d = db()->prepare("SELECT i.id, i.bill_mode, COALESCE(i.funding_agency, t.funding_agency) AS funding_agency, i.bill_to FROM invoices i JOIN training_tracks t ON t.id = i.track_id WHERE t.client_id = ? AND i.status = 'Draft'");
    $d->execute([$clientId]);
    $up = db()->prepare("UPDATE invoices SET bill_to = ? WHERE id = ? AND status = 'Draft'");
    $changed = 0;
    foreach ($d->fetchAll() as $inv) {
        $billTo = invoice_bill_to($client, (string)$inv['bill_mode'] === 'private' ? 'private' : 'vr', (string)$inv['funding_agency']);
        if ((string)$inv['bill_to'] === $billTo) continue;
        $up->execute([$billTo !== '' ? $billTo : null, (int)$inv['id']]);
        $changed += $up->rowCount();
    }
    return $changed;
}

/** Old agency names (4.28.0): agency_key(old name) => the name it was renamed to, kept by Funding agencies. */
function agency_renames(): array {
    $m = json_decode((string)(setting('agency_renames') ?? ''), true);
    return is_array($m) ? array_filter($m, 'is_string') : [];
}

/** Remember that agency $old is now called $new, so reports and filters still find invoices sent under $old. */
function agency_rename_record(string $old, string $new): void {
    if (agency_key($old) === agency_key($new) || trim($old) === '') return;
    $m = agency_renames();
    // Earlier names of $old now lead to $new too; $new is no longer an old name of anything.
    foreach ($m as $k => $v) if (agency_key($v) === agency_key($old)) $m[$k] = $new;
    unset($m[agency_key($new)]);
    $m[agency_key($old)] = $new;
    set_setting('agency_renames', (string)json_encode($m, JSON_UNESCAPED_UNICODE));
}

/** The agency a name on a track or invoice means today: its current name after any rename, as listed. */
function agency_resolve(string $name): string {
    $renames = agency_renames();
    $seen = 0;
    // A name listed on Funding agencies today is that agency, even if another agency once had it.
    while (agency_find($name) === null && isset($renames[agency_key($name)]) && $seen++ < 20) $name = $renames[agency_key($name)];
    return agency_canonical($name);
}

/**
 * Every spelling on file that names this agency (4.28.0): its name, a name it had before a rename,
 * and other case or spacing, from tracks and invoices. Never empty: the agency itself at least.
 */
function agency_spellings(string $agency): array {
    $agency = trim($agency);
    if ($agency === '') return [''];
    $key = agency_key(agency_resolve($agency));
    $out = [$agency];
    foreach (db()->query("SELECT DISTINCT funding_agency FROM training_tracks WHERE funding_agency IS NOT NULL AND funding_agency <> ''
        UNION SELECT DISTINCT funding_agency FROM invoices WHERE funding_agency IS NOT NULL AND funding_agency <> ''")->fetchAll(PDO::FETCH_COLUMN) as $v) {
        if (agency_key(agency_resolve((string)$v)) === $key) $out[] = (string)$v;
    }
    return array_values(array_unique($out));
}

/** "$expr IN (?, ?, …)" for agency_spellings($agency), with its parameters. */
function agency_match_sql(string $expr, string $agency): array {
    $sp = agency_spellings($agency);
    return [$expr . ' IN (' . implode(',', array_fill(0, count($sp), '?')) . ')', $sp];
}

/**
 * 4.29.0: the SQL condition "this work is VR agency work". A billed line follows its invoice
 * (bill_mode is snapshotted when it is invoiced); an unbilled line follows its track's "Who pays".
 * Private-pay and no-charge work is never counted under a funding agency.
 * $i may be '' when the query has no invoice join (track-level counts).
 */
function vr_work_sql(string $t = 't', string $i = 'i'): string {
    $track = "COALESCE({$t}.billing_mode, 'vr') = 'vr'";
    if ($i === '') return $track;
    return "(CASE WHEN {$i}.id IS NULL THEN COALESCE({$t}.billing_mode, 'vr') ELSE COALESCE({$i}.bill_mode, 'vr') END) = 'vr'";
}

/**
 * 4.29.0: one row per agency as it is called today (agency_resolve()), from the given names,
 * so an agency renamed after some of its invoices were sent is listed once. "Private pay" and
 * "No charge" are not agencies and are left out.
 * Returns resolved name => the original names that resolve to it.
 */
function agency_resolved_groups(array $names): array {
    $out = [];
    foreach ($names as $n) {
        $n = trim((string)$n);
        if ($n === '' || in_array(agency_key($n), NOT_AGENCIES, true)) continue;
        $r = agency_resolve($n);
        $k = agency_key($r);
        if (!isset($out[$k])) $out[$k] = ['name' => $r, 'names' => []];
        $out[$k]['names'][] = $n;
    }
    $res = [];
    foreach ($out as $g) $res[$g['name']] = array_values(array_unique($g['names']));
    uksort($res, 'strcasecmp');
    return $res;
}
