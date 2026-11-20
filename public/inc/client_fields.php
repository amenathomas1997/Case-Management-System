<?php
declare(strict_types=1);
// Client fields read the same way by the client form and the app's save_client (4.35.0):
// the preferred language, and the vision status with its age of onset (3.0.47).

/** The language the client's portal opens in and their emails are sent in: es or en. */
function client_language_input(?string $v): string {
    return $v === 'es' ? 'es' : 'en';
}

/**
 * The vision status and age of onset as sent. A status must be on the vision_statuses list, or be
 * the client's current one (a value since removed from the list stays when it is sent back
 * unchanged, Lists 4.4.0); anything else is no status. The age is a whole number from 0 to 120,
 * or blank. errors: vision_onset_age with the form's sentence.
 */
function client_vision_input(array $in, ?array $client): array {
    $visionIn = in_array($in['vision_status'] ?? '', array_merge(cms_list_items('vision_statuses'), $client && ($client['vision_status'] ?? null) !== null ? [(string)$client['vision_status']] : []), true) ? $in['vision_status'] : null;
    $raw = $in['vision_onset_age'] ?? null;
    $onsetRaw = is_int($raw) ? (string)$raw : (is_string($raw) && trim($raw) !== '' ? trim($raw) : null);
    $onsetIn = $onsetRaw !== null && ctype_digit($onsetRaw) && (int)$onsetRaw <= 120 ? (int)$onsetRaw : null;
    $errors = [];
    if ($onsetRaw !== null && $onsetIn === null) $errors['vision_onset_age'] = 'Age when vision loss began: enter a whole number of years from 0 to 120, or leave it blank.';
    return ['errors' => $errors, 'status' => $visionIn, 'onset' => $onsetIn];
}
