<?php
declare(strict_types=1);
// Lists and time limits an administrator sets on screen (4.4.0, Administration, Lists and
// time limits: lists.php), so any AT business can use its own. Each starts as the built-in
// constant it replaces; a value removed from a list stays on the records that already have it.

// The built-in lists that once lived in their own pages.
const INCIDENT_TYPES = ['Injury', 'Near miss', 'Equipment failure', 'Behavioral', 'Property damage', 'Medical', 'Safety concern', 'Other'];
const BOOKING_TYPES = ['Interpreter', 'CART captioning', 'Transportation', 'Note-taker', 'Other'];
const COMPETENCY_LEVELS = [0 => 'Not started', 1 => 'Introduced', 2 => 'Practicing', 3 => 'Proficient', 4 => 'Mastered'];

/**
 * key => [label, kind, name of the built-in constant, group] (constant() is resolved only for
 * the list asked for, so a module loaded on its own needs only its own constant). kind: 'list' (one item per line), 'map' (item | the
 * words an email uses, per line) or 'scale' (the labels of a fixed 0 to 4 scale).
 */
function cms_list_defs(): array {
    return [
        'vision_statuses' => ['Vision statuses (client record and outcome report)', 'list', 'VISION_STATUSES', 'clients'],
        'closure_reasons' => ['Why a case is closed', 'list', 'CLOSURE_REASONS', 'clients'],
        'referral_close_reasons' => ['Why a referral is closed, and the words the referrer\'s email uses (Reason | words)', 'map', 'REFERRAL_CLOSE_REASONS', 'clients'],
        'score_labels' => ['Scored assessment levels, 0 to 4 (templates can still set their own)', 'scale', 'SCORE_LABELS_DEFAULT', 'services'],
        'competency_levels' => ['Curriculum competency levels, 0 to 4', 'scale', 'COMPETENCY_LEVELS', 'services'],
        'device_request_categories' => ['Device request categories', 'list', 'DR_CATEGORIES', 'equipment'],
        'incident_types' => ['Incident types', 'list', 'INCIDENT_TYPES', 'services'],
        'booking_types' => ['Access booking types', 'list', 'BOOKING_TYPES', 'services'],
        'release_recipient_types' => ['Who a release of information can go to', 'list', 'RELEASE_RECIPIENT_TYPES', 'privacy'],
        'release_info_items' => ['What a release of information can cover', 'list', 'RELEASE_INFO_ITEMS', 'privacy'],
    ];
}

/** key => [label, built-in, lowest, highest]. A '' days kind is a comma list of days. */
const CMS_NUMBERS = [
    'auth_warn_pct' => ['Warn when an authorization has used this percent of its hours or funds', 80, 1, 100],
    'auth_soon_days' => ['An authorization is "expiring soon" this many days before it ends', 30, 1, 365],
    'loan_due_days' => ['A new equipment loan is due back after this many days', 60, 1, 730],
    'counselor_link_days' => ['A new counselor portal link lasts this many days', 180, 7, 730],
    'sign_link_days' => ['A consent or release signing link lasts this many days', 60, 1, 365],
    'consent_valid_days' => ['A signed consent lasts this many days', 365, 30, 3650],
    'release_valid_days' => ['A signed release of information lasts this many days', 365, 30, 3650],
];

/** key => [label, built-in days]. */
const CMS_DAY_LISTS = [
    'auth_expiry_alert_days' => ['Alert this many days before an authorization ends (comma between)', [7, 14, 30]],
    'closure_followup_days' => ['Closure follow-ups this many days after a case closes', [30, 60, 90]],
    'device_followup_days' => ['Device check-ins this many days after a loan or purchase', [30, 60, 90]],
];

/** A list's items (for 'map': item => words; for 'scale': 0..4 => label). */
function cms_list(string $key): array {
    $def = cms_list_defs()[$key] ?? null;
    if ($def === null) return [];
    $def[2] = defined($def[2]) ? constant($def[2]) : [];
    $stored = function_exists('setting') ? setting('list_' . $key) : null;
    $v = is_string($stored) && $stored !== '' ? json_decode($stored, true) : null;
    $ok = is_array($v) && $v !== [] && count(array_filter($v, 'is_string')) === count($v) && match ($def[1]) {
        'scale' => array_keys($v) === array_keys($def[2]),
        'map' => !array_is_list($v),
        default => array_is_list($v),
    };
    return $ok ? $v : $def[2];
}

/** The plain item names of a list (a map's reasons). */
function cms_list_items(string $key): array {
    $l = cms_list($key);
    $def = cms_list_defs()[$key] ?? null;
    return $def && $def[1] === 'map' ? array_map('strval', array_keys($l)) : array_values(array_map('strval', $l));
}

/** A number setting, within its range. */
/** The setting each number is kept in (two kept their names from before 4.4.0). */
function cms_number_setting(string $key): string {
    return ['consent_valid_days' => 'consent_validity_days', 'release_valid_days' => 'release_validity_days'][$key] ?? 'num_' . $key;
}

function cms_number(string $key): int {
    $def = CMS_NUMBERS[$key] ?? null;
    if ($def === null) return 0;
    $v = function_exists('setting') ? setting(cms_number_setting($key)) : null;
    return $v !== null && ctype_digit($v) && (int)$v >= $def[2] && (int)$v <= $def[3] ? (int)$v : $def[1];
}

/** A days list, smallest first. */
function cms_days(string $key): array {
    $def = CMS_DAY_LISTS[$key] ?? null;
    if ($def === null) return [];
    $v = function_exists('setting') ? setting('days_' . $key) : null;
    $days = $v !== null ? cms_parse_days($v) : null;
    return $days ?: $def[1];
}

/** "7, 14, 30" as [7, 14, 30], or null when it is not a list of 1 to 6 whole days from 1 to 730. */
function cms_parse_days(string $s): ?array {
    $parts = array_values(array_filter(array_map('trim', explode(',', $s)), fn($p) => $p !== ''));
    if (!$parts || count($parts) > 6) return null;
    $out = [];
    foreach ($parts as $p) { if (!ctype_digit($p) || (int)$p < 1 || (int)$p > 730) return null; $out[] = (int)$p; }
    $out = array_values(array_unique($out));
    sort($out);
    return $out;
}

/**
 * The text an administrator typed for a list, checked: [value to store, null] or [null, what is
 * wrong]. One item per line; a map line is "Item | words".
 */
function cms_list_parse(string $key, string $text): array {
    $def = cms_list_defs()[$key];
    $def[2] = defined($def[2]) ? constant($def[2]) : [];
    $lines = array_values(array_filter(array_map('trim', explode("\n", str_replace("\r", '', $text))), fn($l) => $l !== ''));
    if (!$lines) return [null, 'Give at least one item, or leave the built-in list.'];
    if (count($lines) > 100) return [null, 'Keep the list to 100 items.'];
    $out = [];
    foreach ($lines as $i => $line) {
        if ($def[1] === 'map') {
            [$item, $words] = array_map('trim', explode('|', $line, 2)) + [1 => ''];
            if ($item === '' || $words === '') return [null, 'Line ' . ($i + 1) . ': write the reason, a | and the words the email uses.'];
            if (mb_strlen($item) > 80 || mb_strlen($words) > 200) return [null, 'Line ' . ($i + 1) . ' is too long (80 characters for the reason, 200 for the words).'];
            if (isset($out[$item])) return [null, $item . ' is listed twice.'];
            $out[$item] = $words;
        } else {
            if (mb_strlen($line) > 80) return [null, 'Line ' . ($i + 1) . ' is longer than 80 characters.'];
            if (in_array($line, $out, true)) return [null, $line . ' is listed twice.'];
            $out[] = $line;
        }
    }
    if ($def[1] === 'scale') {
        if (count($out) !== count($def[2])) return [null, 'Give exactly ' . count($def[2]) . ' levels, from the lowest to the highest.'];
        $out = array_combine(array_keys($def[2]), $out);
    }
    return [$out, null];
}

/** A list as the text box shows it. */
function cms_list_text(string $key): string {
    $def = cms_list_defs()[$key];
    $l = cms_list($key);
    return implode("\n", $def[1] === 'map' ? array_map(fn($k, $v) => $k . ' | ' . $v, array_keys($l), $l) : array_values($l));
}
