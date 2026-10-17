<?php
declare(strict_types=1);
// Pricing rules (4.2.0), set by an administrator on Pricing rules (pricing.php) so any AT
// business can run its own. The defaults are the owner's rules (2026-10-16):
// - an open VR case is billed at the VR rate (the rate on the agency's authorization);
// - otherwise training is free below a monthly income, and at the personal rate above it;
// - for a youth, a parent's income is used, not the youth's own;
// - only people referring themselves are asked;
// - free or personal-rate training for someone with an open VR case is allowed, with a warning.
// The personal rate and the income limit are the existing Billing details settings.

const PRICING_CONFLICT_CHOICES = ['warn' => 'Warn, and save it', 'block' => 'Refuse to save it', 'off' => 'Say nothing'];
const PRICING_ASK_CHOICES = ['self' => 'People referring themselves', 'all' => 'Everyone except VR counselors and agencies'];
const VR_CASE_ANSWERS = ['yes' => 'Yes', 'no' => 'No', 'unsure' => 'Not sure'];

/** Every pricing rule, with the defaults filled in. */
function pricing_settings(): array {
    $b = billing_settings();
    $conflict = (string)(setting('pricing_vr_conflict') ?? 'warn');
    $ask = (string)(setting('pricing_ask') ?? 'self');
    $age = setting('pricing_youth_age');
    return [
        'personal_rate' => $b['private_rate'],
        'sliding_on' => (setting('pricing_sliding_on') ?? '1') === '1',
        'free_below' => $b['private_threshold'],
        'vr_first' => (setting('pricing_vr_first') ?? '1') === '1',
        'youth_age' => $age !== null && ctype_digit($age) && (int)$age <= 30 ? (int)$age : 18,
        'vr_conflict' => isset(PRICING_CONFLICT_CHOICES[$conflict]) ? $conflict : 'warn',
        'ask' => isset(PRICING_ASK_CHOICES[$ask]) ? $ask : 'self',
        // A new track with no mileage rate typed (and no agency rate) uses this (4.6.0); null: $0.
        'default_mileage' => is_numeric($dm = (string)(setting('pricing_default_mileage') ?? '')) && (float)$dm >= 0 && (float)$dm <= 999.999 ? round((float)$dm, 3) : null,
    ];
}

/** The mileage rate for a new track with none typed: the agency's (VR), else the organisation's default, else 0. */
function default_mileage_for(string $mode, string $agency): float {
    if ($mode === 'none') return 0.0;
    if ($mode === 'vr' && ($a = agency_mileage_rate($agency)) !== null) return $a;
    return pricing_settings()['default_mileage'] ?? 0.0;
}

/** Whether a referrer in this role answers the income and VR case questions. */
function pricing_asks_role(string $role): bool {
    return $role === 'self' || (pricing_settings()['ask'] === 'all' && !in_array($role, ['counselor', 'agency'], true));
}

/**
 * The fee quoted on a referral: null when it is billed to VR (an open VR case, with the
 * VR-first rule on), 0.00 when free, else the personal hourly rate.
 */
function pricing_quote(string $vrCase, bool $belowIncome): ?float {
    $p = pricing_settings();
    if ($p['vr_first'] && $vrCase === 'yes') return null;
    if ($p['sliding_on'] && $belowIncome) return 0.00;
    return $p['personal_rate'];
}

/**
 * Whether a client has an open VR case: an active VR or agency track whose authorization has
 * not expired, or, while they have never had a VR track, a referral that said so. A VR track
 * that is finished or expired no longer counts, nor does the referral after it.
 * $exceptTrack: the track being edited, which does not count as the open case itself.
 */
function client_open_vr_case(int $clientId, ?int $exceptTrack = null): bool {
    $q = db()->prepare("SELECT 1 FROM training_tracks WHERE client_id = ? AND status = 'Active' AND COALESCE(billing_mode, 'vr') = 'vr'
        AND (expiration_date IS NULL OR expiration_date >= CURDATE()) AND id <> ? LIMIT 1");
    $q->execute([$clientId, (int)$exceptTrack]);
    if ($q->fetchColumn()) return true;
    $any = db()->prepare("SELECT 1 FROM training_tracks WHERE client_id = ? AND COALESCE(billing_mode, 'vr') = 'vr' AND id <> ? LIMIT 1");
    $any->execute([$clientId, (int)$exceptTrack]);
    if ($any->fetchColumn()) return false;
    try {
        $r = db()->prepare('SELECT vr_case_open FROM referrals WHERE client_id = ? AND vr_case_open IS NOT NULL ORDER BY id DESC LIMIT 1');
        $r->execute([$clientId]);
        return $r->fetchColumn() === 'yes';
    } catch (PDOException $e) {
        return false;   // before migration 4.2.0
    }
}

/**
 * Free or personal-rate training set up for someone with an open VR case. Returns null when
 * there is nothing to say, else ['block' => bool, 'message' => string] by the Pricing rules
 * setting. Only a new track, or one changed to free or personal, is checked, and only while
 * it is active: an existing personal track can always be closed, cancelled or renamed.
 */
function pricing_mode_conflict(int $clientId, string $mode, ?array $track = null, string $status = 'Active'): ?array {
    if (!in_array($mode, ['private', 'none'], true) || $status !== 'Active') return null;
    if ($track && (string)($track['billing_mode'] ?? 'vr') === $mode) return null;
    $p = pricing_settings();
    if (!$p['vr_first'] || $p['vr_conflict'] === 'off') return null;
    if (!client_open_vr_case($clientId, $track ? (int)$track['id'] : null)) return null;
    $what = $mode === 'none' ? 'free' : 'at the personal rate';
    return ['block' => $p['vr_conflict'] === 'block',
        'message' => 'This client has an open VR case, which is billed at the VR rate. ' . ($p['vr_conflict'] === 'block'
            ? 'Training ' . $what . ' is not allowed for them: choose VR or agency authorization.'
            : 'This training is ' . $what . ' anyway; check that is right.')];
}
