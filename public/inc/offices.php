<?php
declare(strict_types=1);
// Offices within one organisation (4.22.0, owner request 2026-10-17): each with its own address,
// phone and time zone. Staff and clients belong to an office; the client list and the Schedule
// filter by it; a client with no state or time zone of their own follows their office's zone
// (client_zone()). With no offices set up, nothing about offices is shown anywhere.

/** Every office (or only the active ones), by name. */
function offices_all(bool $activeOnly = false, bool $fresh = false): array {
    static $cache = null;
    if ($fresh) $cache = null;
    try { $cache ??= db()->query('SELECT * FROM offices ORDER BY name')->fetchAll(); }
    catch (PDOException $e) { return []; } // before migration 4.22.0
    return $activeOnly ? array_values(array_filter($cache, fn($o) => (int)$o['is_active'] === 1)) : $cache;
}

/** Whether the organisation uses offices (any active one). */
function offices_on(): bool {
    return offices_all(true) !== [];
}

/** One office, by id, or null. */
function office_get(?int $id): ?array {
    if (!$id) return null;
    foreach (offices_all() as $o) if ((int)$o['id'] === $id) return $o;
    return null;
}

/** An office id as chosen on a form: an active office's id, or null for none. */
function office_input(mixed $raw, ?int $current = null): ?int {
    if (is_int($raw)) $raw = (string)$raw;
    if (!is_string($raw) || !ctype_digit($raw)) return null;
    $o = office_get((int)$raw);
    // A closed office may stay where it already is (its select still lists it), but is not newly chosen.
    return $o && ((int)$o['is_active'] === 1 || (int)$o['id'] === (int)$current) ? (int)$o['id'] : null;
}

/** A client just added by $userId starts in that person's office, if it is open and the client has none. */
function office_place_new_client(int $clientId, int $userId): void {
    if (!offices_on()) return;
    db()->prepare('UPDATE clients c JOIN users u ON u.id = ? JOIN offices o ON o.id = u.office_id AND o.is_active = 1 SET c.office_id = u.office_id WHERE c.id = ? AND c.office_id IS NULL')->execute([$userId, $clientId]);
}

/** <option>s for an office select: "No office" and each active office (plus $current if it was closed since). */
function office_options(?int $current, string $none = 'No office'): string {
    $out = '<option value="">' . h($none) . '</option>';
    foreach (offices_all() as $o) {
        if ((int)$o['is_active'] !== 1 && (int)$o['id'] !== (int)$current) continue;
        $out .= '<option value="' . (int)$o['id'] . '"' . ((int)$o['id'] === (int)$current ? ' selected' : '') . '>' . h((string)$o['name']) . ((int)$o['is_active'] === 1 ? '' : ' (closed)') . '</option>';
    }
    return $out;
}

/** The time zone of a client's office, or null. */
function office_zone_for(?array $client): ?string {
    $oid = (int)($client['office_id'] ?? $client['client_office_id'] ?? 0);
    $o = $oid ? office_get($oid) : null;
    return $o ? us_zone($o['time_zone'] ?? null) : null;
}
