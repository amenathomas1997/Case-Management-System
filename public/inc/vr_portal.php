<?php
declare(strict_types=1);
// The VR portal tracker (4.23.0): VR agencies run their own vendor portals (AWARE and others), where a
// provider accepts each authorization and enters each invoice as a payment request. This keeps what has
// been done there: each authorization's state in the portal (training_tracks.portal_*) and each invoice's
// payment request (invoices.portal_*), so nothing waits unaccepted, unentered or unpaid unnoticed.
// Paid is still recorded on Invoices as before. A caseload-limited account sees and changes only its
// own clients' (as on Invoices). An authorization accepted under one number is listed again when its number
// changes (a renewal or an amendment): each new number is accepted in the portal too.

/** An authorization's state in the agency's portal. */
const AUTH_PORTAL_STATES = ['accepted' => 'Accepted in the portal', 'returned' => 'Returned by the agency'];
/** A payment request's state in the agency's portal. */
const PAYREQ_STATES = ['entered' => 'Entered in the portal', 'rejected' => 'Rejected by the agency'];

/** The first day the tracker counts: earlier authorizations and invoices were dealt with before it. */
function vr_portal_since(): string {
    $d = (string)(setting('vr_portal_since') ?? '');
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? $d : '2000-01-01';
}

/** The tracker's lists, each a list of rows, limited to one agency when $agency is given and to one caseload when $scopeUser is. */
function vr_portal_lists(?string $agency = null, ?int $scopeUser = null): array {
    $since = vr_portal_since();
    // 4.28.0: an agency matches every spelling of it on file, including a name it had before a rename.
    [$agCond, $agArgs] = $agency !== null ? agency_match_sql('x.funding_agency', $agency) : ['', []];
    $ag = ($agency !== null ? ' AND ' . $agCond : '') . ($scopeUser !== null ? ' AND c.assigned_to = ?' : '');
    $p = fn(array $base) => [...$base, ...$agArgs, ...($scopeUser !== null ? [$scopeUser] : [])];
    $q = function (string $sql, array $params): array { $s = db()->prepare($sql); $s->execute($params); return $s->fetchAll(); };
    $tracks = "SELECT x.id, x.title, x.auth_number, x.funding_agency, x.start_date, x.expiration_date, x.portal_status, x.portal_at, x.portal_note, x.portal_auth, c.id AS client_id, c.name AS client_name, c.vr_case_number
        FROM training_tracks x JOIN clients c ON c.id = x.client_id WHERE x.status = 'Active' AND COALESCE(x.billing_mode, 'vr') = 'vr' AND TRIM(x.auth_number) <> '' AND c.archived_at IS NULL";
    $invoices = "SELECT x.id, x.invoice_number, x.funding_agency, x.auth_number, x.vendor_number, x.period_start, x.period_end, x.hours, x.hourly_rate, x.mileage, x.mileage_amount, x.expenses_amount, x.total_amount,
        x.submitted_at, x.portal_status, x.portal_ref, x.portal_at, x.portal_note, c.name AS client_name, c.vr_case_number
        FROM invoices x JOIN training_tracks t ON t.id = x.track_id JOIN clients c ON c.id = t.client_id WHERE x.status = 'Submitted' AND x.bill_mode = 'vr'";
    return [
        'accept' => $q($tracks . " AND ((x.portal_status IS NULL AND x.created_at >= ?) OR (x.portal_status = 'accepted' AND x.portal_auth IS NOT NULL AND x.portal_auth <> x.auth_number))$ag ORDER BY x.created_at", $p([$since . ' 00:00:00'])),
        'returned' => $q($tracks . " AND x.portal_status = 'returned'$ag ORDER BY x.portal_at", $p([])),
        'enter' => $q($invoices . " AND x.portal_status IS NULL AND x.submitted_at >= ?$ag ORDER BY x.submitted_at, x.invoice_number", $p([$since])),
        'rejected' => $q($invoices . " AND x.portal_status = 'rejected'$ag ORDER BY x.portal_at", $p([])),
        'waiting' => $q($invoices . " AND x.portal_status = 'entered'$ag ORDER BY x.portal_at", $p([])),
    ];
}

/** Record an authorization's state in the portal, for the number it has now. True when it changed. */
function vr_portal_set_track(int $trackId, string $state, string $note, int $userId, ?int $scopeUser = null): bool {
    if (!isset(AUTH_PORTAL_STATES[$state])) return false;
    $s = db()->prepare("UPDATE training_tracks x JOIN clients c ON c.id = x.client_id SET x.portal_status = ?, x.portal_at = CURDATE(), x.portal_note = ?, x.portal_auth = x.auth_number
        WHERE x.id = ? AND COALESCE(x.billing_mode, 'vr') = 'vr'" . ($scopeUser !== null ? ' AND c.assigned_to = ?' : ''));
    $s->execute([$state, $note !== '' ? mb_substr($note, 0, 255) : null, $trackId, ...($scopeUser !== null ? [$scopeUser] : [])]);
    if ($s->rowCount() !== 1) return false;
    audit($userId, 'portal.auth_' . $state, 'track', $trackId, $note);
    return true;
}

/** Record an invoice's payment request in the portal. True when it changed. */
function vr_portal_set_invoice(int $invoiceId, string $state, string $ref, string $note, int $userId, ?int $scopeUser = null): bool {
    if (!isset(PAYREQ_STATES[$state])) return false;
    // Entering keeps a request number typed before when none is typed now.
    $s = db()->prepare("UPDATE invoices i JOIN training_tracks t ON t.id = i.track_id JOIN clients c ON c.id = t.client_id
        SET i.portal_status = ?, i.portal_at = CURDATE(), i.portal_ref = COALESCE(?, i.portal_ref), i.portal_note = ?
        WHERE i.id = ? AND i.status = 'Submitted' AND i.bill_mode = 'vr'" . ($scopeUser !== null ? ' AND c.assigned_to = ?' : ''));
    $s->execute([$state, $ref !== '' ? mb_substr($ref, 0, 80) : null, $note !== '' ? mb_substr($note, 0, 255) : null, $invoiceId, ...($scopeUser !== null ? [$scopeUser] : [])]);
    if ($s->rowCount() !== 1) return false;
    audit($userId, 'portal.payreq_' . $state, 'invoice', $invoiceId, trim($ref . ' ' . $note));
    return true;
}
