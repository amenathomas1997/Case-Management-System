<?php
declare(strict_types=1);
// Referrals from VR agencies in other states (4.1.0). Each funding agency keeps its own
// details (funding_agencies, matched to tracks and invoices by name): state, usual time zone,
// the email domains its authorizations come from, the vendor number it gave you, its payment
// terms and its billing address. Clients carry a state and time zone, and everything a client
// reads (reminders, calendar invitations, the portal, letters) shows times in their zone.

/** States, the District of Columbia and the territories, by postal code. */
const US_STATES = [
    'AL' => 'Alabama', 'AK' => 'Alaska', 'AZ' => 'Arizona', 'AR' => 'Arkansas', 'CA' => 'California', 'CO' => 'Colorado',
    'CT' => 'Connecticut', 'DE' => 'Delaware', 'DC' => 'District of Columbia', 'FL' => 'Florida', 'GA' => 'Georgia', 'HI' => 'Hawaii',
    'ID' => 'Idaho', 'IL' => 'Illinois', 'IN' => 'Indiana', 'IA' => 'Iowa', 'KS' => 'Kansas', 'KY' => 'Kentucky', 'LA' => 'Louisiana',
    'ME' => 'Maine', 'MD' => 'Maryland', 'MA' => 'Massachusetts', 'MI' => 'Michigan', 'MN' => 'Minnesota', 'MS' => 'Mississippi',
    'MO' => 'Missouri', 'MT' => 'Montana', 'NE' => 'Nebraska', 'NV' => 'Nevada', 'NH' => 'New Hampshire', 'NJ' => 'New Jersey',
    'NM' => 'New Mexico', 'NY' => 'New York', 'NC' => 'North Carolina', 'ND' => 'North Dakota', 'OH' => 'Ohio', 'OK' => 'Oklahoma',
    'OR' => 'Oregon', 'PA' => 'Pennsylvania', 'RI' => 'Rhode Island', 'SC' => 'South Carolina', 'SD' => 'South Dakota',
    'TN' => 'Tennessee', 'TX' => 'Texas', 'UT' => 'Utah', 'VT' => 'Vermont', 'VA' => 'Virginia', 'WA' => 'Washington',
    'WV' => 'West Virginia', 'WI' => 'Wisconsin', 'WY' => 'Wyoming',
    'AS' => 'American Samoa', 'GU' => 'Guam', 'MP' => 'Northern Mariana Islands', 'PR' => 'Puerto Rico', 'VI' => 'U.S. Virgin Islands',
];

/** The time zones a client can be in, with the names people use for them. */
const US_TIME_ZONES = [
    'America/New_York' => 'Eastern', 'America/Chicago' => 'Central', 'America/Denver' => 'Mountain',
    'America/Phoenix' => 'Arizona (Mountain, no daylight saving)', 'America/Los_Angeles' => 'Pacific', 'America/Anchorage' => 'Alaska',
    'Pacific/Honolulu' => 'Hawaii', 'America/Puerto_Rico' => 'Atlantic (Puerto Rico, Virgin Islands)',
    'Pacific/Guam' => 'Chamorro (Guam, Northern Mariana Islands)', 'Pacific/Pago_Pago' => 'Samoa',
];

/** The zone most of a state's people are in; a client elsewhere in the state is changed on their record. */
const STATE_TIME_ZONES = [
    'AL' => 'America/Chicago', 'AK' => 'America/Anchorage', 'AZ' => 'America/Phoenix', 'AR' => 'America/Chicago', 'CA' => 'America/Los_Angeles',
    'CO' => 'America/Denver', 'CT' => 'America/New_York', 'DE' => 'America/New_York', 'DC' => 'America/New_York', 'FL' => 'America/New_York',
    'GA' => 'America/New_York', 'HI' => 'Pacific/Honolulu', 'ID' => 'America/Denver', 'IL' => 'America/Chicago', 'IN' => 'America/New_York',
    'IA' => 'America/Chicago', 'KS' => 'America/Chicago', 'KY' => 'America/New_York', 'LA' => 'America/Chicago', 'ME' => 'America/New_York',
    'MD' => 'America/New_York', 'MA' => 'America/New_York', 'MI' => 'America/New_York', 'MN' => 'America/Chicago', 'MS' => 'America/Chicago',
    'MO' => 'America/Chicago', 'MT' => 'America/Denver', 'NE' => 'America/Chicago', 'NV' => 'America/Los_Angeles', 'NH' => 'America/New_York',
    'NJ' => 'America/New_York', 'NM' => 'America/Denver', 'NY' => 'America/New_York', 'NC' => 'America/New_York', 'ND' => 'America/Chicago',
    'OH' => 'America/New_York', 'OK' => 'America/Chicago', 'OR' => 'America/Los_Angeles', 'PA' => 'America/New_York', 'RI' => 'America/New_York',
    'SC' => 'America/New_York', 'SD' => 'America/Chicago', 'TN' => 'America/Chicago', 'TX' => 'America/Chicago', 'UT' => 'America/Denver',
    'VT' => 'America/New_York', 'VA' => 'America/New_York', 'WA' => 'America/Los_Angeles', 'WV' => 'America/New_York', 'WI' => 'America/Chicago',
    'WY' => 'America/Denver', 'AS' => 'Pacific/Pago_Pago', 'GU' => 'Pacific/Guam', 'MP' => 'Pacific/Guam', 'PR' => 'America/Puerto_Rico',
    'VI' => 'America/Puerto_Rico',
];

/** Track funding names that are not agencies. */
const NOT_AGENCIES = ['private pay', 'no charge'];

/** <option>s from value => label, with an empty first option. Plain labels, nothing extra announced. */
function kv_options(array $options, ?string $selected, string $emptyLabel, bool $englishLabels = false): string {
    $out = '<option value="">' . h($emptyLabel) . '</option>';
    // English names on a Spanish page are marked, so a screen reader reads them in English.
    $lang = $englishLabels && function_exists('page_lang') && page_lang() !== 'en' ? ' lang="en"' : '';
    foreach ($options as $v => $label) {
        $out .= '<option value="' . h((string)$v) . '"' . ((string)$v === (string)$selected ? ' selected' : '') . $lang . '>' . h((string)$label) . '</option>';
    }
    return $out;
}

/** A postal code we know, upper-cased, or null. */
function us_state(?string $code): ?string {
    $c = strtoupper(trim((string)$code));
    return isset(US_STATES[$c]) ? $c : null;
}

/** A time zone from the list, or null. */
function us_zone(?string $tz): ?string {
    $t = trim((string)$tz);
    return isset(US_TIME_ZONES[$t]) ? $t : null;
}

/** An agency name as compared: trimmed, single spaces, lower case. */
function agency_key(string $name): string {
    return mb_strtolower(trim((string)preg_replace('/\s+/u', ' ', $name)));
}

/** Every agency row, by agency_key (one query per request). Empty before migration 4.1.0. */
function agencies_all(bool $fresh = false): array {
    static $cache = null;
    if ($cache !== null && !$fresh) return $cache;
    $cache = [];
    try {
        foreach (db()->query('SELECT * FROM funding_agencies ORDER BY name')->fetchAll() as $a) $cache[agency_key((string)$a['name'])] = $a;
    } catch (PDOException $e) {
        $cache = [];
    }
    return $cache;
}

/** The agency row for a name on a track or invoice, or null. */
function agency_find(?string $name): ?array {
    if ($name === null || trim($name) === '') return null;
    return agencies_all()[agency_key($name)] ?? null;
}

/**
 * The name to store for a funding agency typed on a track: an agency already listed keeps its
 * own spelling ("iowa department for the blind" is stored as listed); a new name is stored as
 * typed and appears on Funding agencies for an administrator to fill in.
 */
function agency_canonical(string $name): string {
    $clean = trim((string)preg_replace('/\s+/u', ' ', $name));
    if ($clean === '' || in_array(agency_key($clean), NOT_AGENCIES, true)) return $clean;
    $a = agency_find($clean);
    return $a ? (string)$a['name'] : $clean;
}

/** The agency whose authorizations come from this email domain (or a subdomain of one listed). */
function agency_for_domain(string $domain): ?string {
    $domain = strtolower(trim($domain));
    if ($domain === '') return null;
    foreach (agencies_all() as $a) {
        if ((int)$a['is_active'] !== 1) continue;
        foreach (array_filter(array_map(fn($d) => strtolower(trim($d)), explode(',', (string)$a['email_domains']))) as $d) {
            if ($domain === $d || str_ends_with($domain, '.' . $d)) return (string)$a['name'];
        }
    }
    return null;
}

/** Days to pay for an invoice to this agency: its own terms, else Billing details. */
function agency_terms_days(?string $agency): int {
    $a = agency_find($agency);
    return $a && $a['terms_days'] !== null ? (int)$a['terms_days'] : billing_settings()['terms_days'];
}

/** Business days to contact a new referral from this agency (4.23.0): its own, else 3. */
function agency_contact_days(?string $agency): int {
    $a = agency_find($agency);
    return $a && ($a['contact_days'] ?? null) !== null ? (int)$a['contact_days'] : 3;
}

/** Business days from accepting a referral to its first assessment (4.23.0): the agency's own, else 10. */
function agency_assess_days(?string $agency): int {
    $a = agency_find($agency);
    return $a && ($a['assess_days'] ?? null) !== null ? (int)$a['assess_days'] : 10;
}

/** Whether this agency set its own referral deadline ('contact_days' or 'assess_days'). */
function agency_sets_deadline(?string $agency, string $field): bool {
    $a = agency_find($agency);
    return $a !== null && ($a[$field] ?? null) !== null;
}

/** The funding agency a referral came from: the counselor's agency as listed, else the one their email domain belongs to. */
function referral_agency(array $ref): ?string {
    $a = agency_find((string)($ref['counselor_agency'] ?? ''));
    if ($a) return (string)$a['name'];
    $email = (string)($ref['counselor_email'] ?? '');
    return str_contains($email, '@') ? agency_for_domain(substr($email, strrpos($email, '@') + 1)) : null;
}

/** The vendor number this agency gave you, else the one in Billing details. */
function agency_vendor_number(?string $agency): string {
    $a = agency_find($agency);
    return $a && trim((string)$a['vendor_number']) !== '' ? trim((string)$a['vendor_number']) : billing_settings()['vendor_number'];
}

/** The hourly rate you charge this agency (4.2.0), or null when none is set. */
function agency_hourly_rate(?string $agency): ?float {
    $a = agency_find($agency);
    return $a && $a['hourly_rate'] !== null && (float)$a['hourly_rate'] > 0 ? (float)$a['hourly_rate'] : null;
}

/** The mileage rate you charge this agency (4.2.0), or null when none is set. */
function agency_mileage_rate(?string $agency): ?float {
    $a = agency_find($agency);
    return $a && $a['mileage_rate'] !== null ? (float)$a['mileage_rate'] : null;
}

/** The agency's billing address lines (printed under its name on an invoice). */
function agency_bill_to_lines(?string $agency): array {
    $a = agency_find($agency);
    return $a ? array_values(array_filter(array_map('trim', explode("\n", str_replace("\r", '', (string)$a['bill_to']))), fn($l) => $l !== '')) : [];
}

/**
 * A client's time zone when it differs from the site's, else null (times are shown as they are):
 * the zone picked on their record, else their state's usual zone. Only a picked zone is stored,
 * so a client who moves state follows the new state.
 */
function client_zone(?array $client): ?string {
    // ...else their office's zone (4.22.0), when they belong to one.
    $tz = us_zone($client['time_zone'] ?? $client['client_time_zone'] ?? null)
        ?? state_default_zone($client['state'] ?? $client['client_state'] ?? null)
        ?? (function_exists('office_zone_for') ? office_zone_for($client) : null);
    return $tz !== null && $tz !== date_default_timezone_get() ? $tz : null;
}

/** Run $fn with PHP's dates in $tz (only formatting: timestamps are made outside it). */
function zone_format(?string $tz, callable $fn): mixed {
    if ($tz === null || $tz === date_default_timezone_get()) return $fn();
    $old = date_default_timezone_get();
    date_default_timezone_set($tz);
    try { return $fn(); } finally { date_default_timezone_set($old); }
}

/** date() in a client's zone. */
function zone_date(?string $tz, string $format, int $ts): string {
    return (string)zone_format($tz, fn() => date($format, $ts));
}

/** A Spanish date and time in a client's zone (time_es names the zone: "2:00 p. m. EDT"). */
function zone_when_es(?string $tz, int $ts, bool $withTime = true): string {
    return (string)zone_format($tz, fn() => when_es($ts, $withTime));
}

/** "Eastern time, 1 hour ahead of the office" (said on staff pages for a client elsewhere). */
function zone_difference_text(string $tz): string {
    $here = (new DateTime('now'))->getOffset();
    $there = (new DateTime('now', new DateTimeZone($tz)))->getOffset();
    $hours = ($there - $here) / 3600;
    $n = rtrim(rtrim(number_format(abs($hours), 1, '.', ''), '0'), '.');
    $name = (US_TIME_ZONES[$tz] ?? $tz) . ' time';
    if ($hours == 0) return $name . ', the same as the office now';
    return $name . ', ' . $n . ' hour' . ($n === '1' ? '' : 's') . ($hours > 0 ? ' ahead of' : ' behind') . ' the office';
}

/** A state's usual time zone. */
function state_default_zone(?string $state): ?string {
    $s = us_state($state);
    return $s !== null ? STATE_TIME_ZONES[$s] : null;
}

/** Record a client's state from a referral (their times then follow the state's usual zone). */
function client_apply_state(int $clientId, ?string $state): void {
    $s = us_state($state);
    if ($s === null) return;
    try {
        db()->prepare('UPDATE clients SET state = ? WHERE id = ?')->execute([$s, $clientId]);
    } catch (PDOException $e) {
        error_log('client_apply_state: ' . $e->getCode());
    }
}
