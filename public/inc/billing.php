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
function invoice_due_date(string $date): string {
    return date('Y-m-d', strtotime($date . ' +' . billing_settings()['terms_days'] . ' days'));
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
    $st = db()->prepare("UPDATE invoices SET status = 'Submitted', submitted_at = ?, due_date = ? WHERE id = ? AND status = 'Draft'");
    $st->execute([$date, invoice_due_date($date), $id]);
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
        $pdo->prepare('SELECT id FROM invoices WHERE id = ? FOR UPDATE')->execute([(int)$p['invoice_id']]);
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
