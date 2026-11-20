<?php
declare(strict_types=1);
// Accommodation bookings (4.38.0): moved from access_bookings.php, so the page and the apps'
// bookings, add_booking and booking_status read the cost, set a status, say the sentences and
// audit the same way.

const BOOKING_STATUSES = ['Requested', 'Confirmed', 'Completed', 'Cancelled'];
const BOOKING_COST_HINT = 'Enter the estimated cost in dollars and cents, for example 125.50 (no more than 99,999,999.99), or leave it blank.';

/** When a booking is needed, as spoken in labels and messages. */
function booking_when(?string $neededAt): string {
    return $neededAt ? date('D M j, Y g:i A', strtotime($neededAt)) : 'no date set';
}

/** The estimated cost as typed: a dollar sign, spaces and thousands commas are dropped (the apps
 *  send "$1,250.50"; the page's number box never does), then at most 8 whole digits and 2 decimals.
 *  The amount as text, null when blank, false when it cannot be read (say BOOKING_COST_HINT). */
function booking_cost_in(mixed $raw): string|false|null {
    if (is_int($raw) || is_float($raw)) $raw = (string)$raw;
    if (!is_string($raw)) return false;
    $v = str_replace(['$', ' '], '', trim($raw));
    if ($v === '') return null;
    if (str_contains($v, ',')) {
        if (!preg_match('/^\d{1,3}(,\d{3})+(\.\d{1,2})?$/', $v)) return false;
        $v = str_replace(',', '', $v);
    }
    return preg_match('/^\d{1,8}(\.\d{1,2})?$/', $v) ? $v : false;
}

/** The sentence after a booking is made: "{Label} booking created for {client}: {when}." ($b: service_type, needed_at). */
function booking_created_message(array $b, string $client): string {
    return booking_type_label((string)$b['service_type']) . ' booking created for ' . $client . ': ' . booking_when($b['needed_at'] ?? null) . '.';
}

/**
 * A booking's status set (Cancelled moves it to the cancelled list, where it can be set back), in the
 * caller's caseload. Audited "{old} → {new}" even when it did not change, as the website always has.
 * Returns ok, the HTTP status the API answers with, and the sentence.
 */
function booking_set_status(int $id, string $to, array $user, string $auditTail = ''): array {
    $b = db()->prepare('SELECT ab.id, ab.service_type, ab.needed_at, ab.status, c.name AS client_name, c.assigned_to FROM access_bookings ab JOIN clients c ON c.id = ab.client_id WHERE ab.id = ?');
    $b->execute([$id]);
    $row = $b->fetch();
    if (!$row) return ['ok' => false, 'status' => 404, 'message' => 'Booking not found.'];
    if (caseload_scoped() && (int)$row['assigned_to'] !== (int)$user['id']) return ['ok' => false, 'status' => 403, 'message' => 'Not your caseload.'];
    if (!in_array($to, BOOKING_STATUSES, true)) return ['ok' => false, 'status' => 422, 'message' => 'Choose a status for the booking.'];
    db()->prepare('UPDATE access_bookings SET status = ? WHERE id = ?')->execute([$to, $id]);
    audit((int)$user['id'], 'booking.status', 'access_booking', $id, $row['status'] . ' → ' . $to . $auditTail);
    // Name the booking and the change, and say where a cancelled one went.
    return ['ok' => true, 'status' => 200, 'message' => $row['service_type'] . ' booking for ' . $row['client_name'] . ' (' . booking_when($row['needed_at']) . ') set to ' . $to . '.'
        . ($to === 'Cancelled' ? ' It is now listed under "Show cancelled", where it can be restored.' : '')];
}
