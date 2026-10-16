<?php
declare(strict_types=1);

// Who may submit the public referral form (community_referral.php); the
// administrator can hide kinds on referral_settings.php.
const REFERRER_ROLES = [
    'counselor' => 'Vocational Rehabilitation (VR) counselor',
    'self' => 'I am referring myself',
    'school' => 'School staff / Teacher of the Visually Impaired',
    'medical' => 'Medical provider (eye doctor, occupational therapist, clinic)',
    'family' => 'Family member or caregiver',
    'agency' => 'Community agency or program',
    'other' => 'Employer / other',
];

const REFERRAL_PURPOSES = ['New referral', 'Additional service', 'Plan amendment'];
const REFERRAL_SERVICES = ['Not sure', 'Assessment', 'Rehab Technology', 'Assistive technology training', 'Rehab teaching', 'Counseling', 'Pre-ETS', 'Device assistance', 'Other'];
// Every referral source asks for assistive-technology services only, one or
// more at a time. REFERRAL_SERVICES is kept so older stored values still display.
// Current public submissions; the broader catalog remains for historical records.
const REFERRAL_AT_NOTE_TYPES = ['Initial Intake','Tech Assessment Request','Rehab Technology','Assistive Technology Training Request','Low Vision Device Request','Screen Reader Training Request','Magnification Training Request','Workplace Assessment Request','Device Trial / Loan Request','Device Setup Request','Device Repair / Support Request','Device Funding Assistance Request','Plan Amendment','Other'];
const REFERRAL_TECH_SERVICES = ['Assessment', 'Assistive technology training', 'Demonstration', 'Recommendation'];
// Plain-language explanation shown after each built-in service (and after an
// administrator's in-place rename of one), so a family member can tell them apart.
const REFERRAL_TECH_SERVICE_HINTS = [
    'Assessment' => 'we meet the person to find out which tools could help',
    'Assistive technology training' => 'we teach the person to use a device or app',
    'Demonstration' => 'we show devices so the person can try them',
    'Recommendation' => 'we write down which tools to get',
];
// Sizes of the referrals columns the chosen English labels are stored in
// (referral_purpose varchar(40); requested_service varchar(80), the ticked
// services joined with '; '; specific_needs TEXT, in bytes). The settings page
// keeps the administrator's lists within them.
const REFERRAL_PURPOSE_MAX = 40;
const REFERRAL_SERVICES_MAX = 80;
const REFERRAL_NEEDS_MAX_BYTES = 65535;
const REFERRAL_NEEDS = ['Screen readers', 'Magnification', 'Braille devices', 'Assistive technology for daily living', 'Low vision devices', 'Workplace assessment', 'Device trial / loan', 'Device setup', 'Device repair / technical support', 'Device funding assistance', 'Other assistive technology needs'];

/* ---------- Administrator-configurable form (Referral form settings) ----------
 * Stored as JSON in settings.referral_form. Any missing or malformed part falls
 * back to the built-in lists above, so the public form always works. */
const REFERRAL_ROLE_KEYS = ['counselor', 'self', 'school', 'medical', 'family', 'agency', 'other'];
const REFERRAL_DEFAULT_HINTS = [
    // One line an item: the form reads a multi-line hint as a list.
    'purpose' => "New referral: someone new to us.\nAdditional service: an existing client needs another service.\nPlan amendment: change services already planned (for example, more hours).",
    'services' => 'Assistive technology means devices, apps and software that help a person with a disability do everyday tasks, such as screen readers, magnifiers and braille displays. Select every service you are asking for.',
    'needs' => 'Select all that apply, even if they span more than one service. You may leave these blank if you are not sure. Describe any other needs below.',
];
function referral_form_config(): array {
    static $cfg = null;
    if ($cfg !== null) return $cfg;
    $raw = function_exists('setting') ? (string)setting('referral_form') : '';
    $j = $raw !== '' ? json_decode($raw, true) : null;
    $j = is_array($j) ? $j : [];
    $list = function ($v, array $default): array {
        if (!is_array($v) || $v === []) return array_map(fn($x) => ['en' => $x, 'es' => ''], $default);
        $out = [];
        foreach ($v as $o) {
            // 'builtin' (written by referral_settings.php) names the built-in option a
            // renamed one stands for; '' marks an option that stands for none.
            if (is_array($o) && isset($o['en']) && is_string($o['en']) && trim($o['en']) !== '') $out[] = ['en' => trim($o['en']), 'es' => trim((string)($o['es'] ?? ''))]
                + (is_string($o['builtin'] ?? null) ? ['builtin' => $o['builtin']] : []);
        }
        return $out ?: array_map(fn($x) => ['en' => $x, 'es' => ''], $default);
    };
    $roles = array_values(array_filter((array)($j['roles'] ?? []), fn($r) => is_string($r) && in_array($r, REFERRAL_ROLE_KEYS, true)));
    $hint = function (string $k) use ($j): array {
        $h = $j['hints'][$k] ?? null;
        $en = is_array($h) && is_string($h['en'] ?? null) && trim($h['en']) !== '' ? trim($h['en']) : REFERRAL_DEFAULT_HINTS[$k];
        $es = is_array($h) && is_string($h['es'] ?? null) ? trim($h['es']) : '';
        return ['en' => $en, 'es' => $es];
    };
    $cfg = [
        'purposes' => $list($j['purposes'] ?? null, REFERRAL_PURPOSES),
        'services' => $list($j['services'] ?? null, REFERRAL_TECH_SERVICES),
        'needs' => isset($j['needs']) && is_array($j['needs']) && $j['needs'] === [] ? [] : $list($j['needs'] ?? null, REFERRAL_NEEDS),
        'roles' => $roles ?: REFERRAL_ROLE_KEYS,
        'min_services' => (isset($j['min_services']) && (int)$j['min_services'] === 0) ? 0 : 1,
        'hints' => ['purpose' => $hint('purpose'), 'services' => $hint('services'), 'needs' => $hint('needs')],
        'customized' => $j !== [],
    ];
    return $cfg;
}
/** English labels of one option list: 'purposes', 'services' or 'needs'. */
function referral_options(string $kind): array {
    return array_map(fn($o) => $o['en'], referral_form_config()[$kind] ?? []);
}
/** Spanish labels an administrator entered, for register_translations(). */
function referral_form_translations(): array {
    $cfg = referral_form_config();
    $map = [];
    foreach (['purposes', 'services', 'needs'] as $k) foreach ($cfg[$k] as $o) if ($o['es'] !== '') $map[$o['en']] = $o['es'];
    foreach ($cfg['hints'] as $h) if ($h['es'] !== '') $map[$h['en']] = $h['es'];
    return $map;
}

/** Validate the new form without silently converting malformed selections to defaults. */
function referral_request_input(array $input): array {
    $errors = [];
    $purpose = $input['referral_purpose'] ?? 'New referral';
    $service = $input['requested_service'] ?? 'Not sure';
    $needs = $input['specific_needs'] ?? [];
    $cfg = referral_form_config();
    if (!is_string($purpose) || !in_array($purpose, referral_options('purposes'), true)) $errors['referral_purpose'] = 'Please choose a referral purpose.';
    // One or more technology services, stored joined with '; ' in catalog order.
    $picked = $input['requested_services'] ?? [];
    $serviceList = referral_options('services');
    if (!is_array($picked) || count($picked) > count($serviceList)) {
        $errors['requested_services'] = 'Please choose at least one service from the list.';
        $picked = [];
    } else {
        // A service no longer offered (renamed or removed while the form was
        // open) is unticked and named in the message; the valid ticks are kept.
        $offered = array_values(array_filter($picked, fn($x) => is_string($x) && in_array($x, $serviceList, true)));
        $withdrawn = count($offered) !== count($picked);
        $picked = $offered;
        if ($withdrawn) {
            $errors['requested_services'] = 'One of the services you chose is no longer offered, so it was unticked. '
                . ($picked === [] ? 'Please choose at least one service from the list.' : 'Please check your choices, then send the referral again.');
        } elseif ($picked === [] && $cfg['min_services'] > 0) {
            $errors['requested_services'] = 'Please choose at least one service from the list.';
        }
    }
    $service = implode('; ', array_values(array_filter($serviceList, fn($x) => in_array($x, $picked, true))));
    // Lists saved before the settings page checked these sizes could still
    // overflow the columns: refuse here rather than fail when saving.
    if (!isset($errors['referral_purpose']) && mb_strlen($purpose) > REFERRAL_PURPOSE_MAX) {
        $errors['referral_purpose'] = 'This referral purpose cannot be sent. Please choose another one, or contact us.';
    }
    if (!isset($errors['requested_services']) && mb_strlen($service) > REFERRAL_SERVICES_MAX) {
        $errors['requested_services'] = 'Too many services were chosen to send together. Please choose fewer, and describe the others in your message.';
    }
    $needList = referral_options('needs');
    if (!is_array($needs) || count($needs) > max(1, count($needList))) {
        $errors['specific_needs'] = 'Please choose specific needs from the list.';
        $needs = [];
    } else {
        foreach ($needs as $need) {
            if (!is_string($need) || !in_array($need, $needList, true)) $errors['specific_needs'] = 'Please choose specific needs from the list.';
        }
    }
    $needs = array_values(array_filter($needList, fn($need) => in_array($need, $needs, true)));
    return ['purpose'=>$purpose, 'service'=>$service, 'needs'=>$needs, 'errors'=>$errors];
}

/** The plain-language hint for one offered service ('' for an administrator's new option). */
function referral_service_hint(string $label): string {
    return REFERRAL_TECH_SERVICE_HINTS[referral_builtin_label($label, 'services', REFERRAL_TECH_SERVICES)] ?? '';
}

/** The built-in label an administrator's option stands for: itself when it is
 *  a built-in label, else the built-in label recorded for it when the settings
 *  page saw it renamed in place; '' when it is a new option. Lets renamed
 *  options keep their meaning. A list saved before that record existed falls
 *  back to the position, but only while no option was added or removed (any
 *  deletion shifts the positions), and never to a built-in still offered. */
function referral_builtin_label(string $label, string $kind, array $builtins): string {
    if (in_array($label, $builtins, true)) return $label;
    $options = referral_form_config()[$kind] ?? [];
    $configured = array_column($options, 'en');
    $pos = array_search($label, $configured, true);
    if ($pos === false) return '';
    $saved = $options[$pos]['builtin'] ?? null;
    if (is_string($saved)) return in_array($saved, $builtins, true) && !in_array($saved, $configured, true) ? $saved : '';
    if (count($configured) !== count($builtins) || !isset($builtins[$pos]) || in_array($builtins[$pos], $configured, true)) return '';
    return $builtins[$pos];
}

/** Keep note_type usable for older integrations; the three new fields are authoritative. */
function referral_request_note_type(string $purpose, string $service): string {
    $purposeKind = referral_builtin_label($purpose, 'purposes', REFERRAL_PURPOSES);
    if ($purposeKind === 'Plan amendment') return 'Plan Amendment';
    // A counselor's multi-service request ('A; B') is a technology referral.
    if (str_contains($service, '; ')) return 'Rehab Technology';
    // Older stored service labels (REFERRAL_SERVICES) map by their text first,
    // so a literal 'Pre-ETS' or 'Counseling' is never re-read as a renamed option.
    $serviceKind = $service === '' ? ''
        : (in_array($service, REFERRAL_SERVICES, true) ? $service : referral_builtin_label($service, 'services', REFERRAL_TECH_SERVICES));
    return match ($serviceKind) {
        'Demonstration', 'Recommendation' => 'Rehab Technology',
        'Assessment' => 'Tech Assessment Request',
        'Rehab Technology' => 'Rehab Technology',
        'Assistive technology training' => 'Assistive Technology Training Request',
        'Rehab teaching' => 'Rehab Teaching Request',
        'Counseling' => 'Counseling Request',
        'Pre-ETS' => 'Pre-ETS Request',
        // No service ticked (allowed when services are optional) reads as 'Not sure'.
        default => $purposeKind === 'New referral' && in_array($service, ['', 'Not sure'], true) ? 'Initial Intake' : 'Other',
    };
}

function referral_is_preets(array $ref): bool {
    // Historical explicit Pre-ETS requests keep their meaning. A school is a
    // referral source, not a service selection for a new AT referral.
    if (($ref['requested_service'] ?? '') === 'Pre-ETS'
        || ($ref['note_type'] ?? '') === 'Pre-ETS Request'
        || !empty($ref['preets_services'])) return true;
    return empty($ref['referral_purpose']) && ($ref['referral_source'] ?? '') === 'school';
}

/** Plain text for intake notes, notifications and older mobile clients. */
function referral_request_summary(array $ref): string {
    if (empty($ref['referral_purpose'])) return '';
    return 'Referral purpose: ' . $ref['referral_purpose']
        . "\nService requested: " . (($ref['requested_service'] ?? '') ?: 'None selected')
        . "\nSpecific needs: " . (($ref['specific_needs'] ?? '') ?: 'None selected');
}

/** Assign only a live queue item; conversion and assignment serialize on the same row. */
function assign_open_referral(int $id, ?int $assigneeId, int $actor): array {
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $q = $pdo->prepare('SELECT * FROM referrals WHERE id = ? FOR UPDATE');
        $q->execute([$id]);
        $ref = $q->fetch();
        if (!$ref) throw new DomainException('Referral not found.');
        if ($ref['status'] === 'Converted') throw new DomainException('This referral has been converted. Change the assigned instructor on the client record instead.');
        if ($ref['status'] === 'Closed') throw new DomainException('This referral is closed. Reopen it first to assign it.');
        if (caseload_scoped() && $ref['assigned_to'] !== null && (int)$ref['assigned_to'] !== $actor) {
            throw new DomainException('That referral is assigned to someone else.');
        }
        $pdo->prepare('UPDATE referrals SET assigned_to = ? WHERE id = ?')->execute([$assigneeId, $id]);
        $pdo->prepare("UPDATE tasks SET assigned_to = ? WHERE auto_key = ? AND status = 'Open'")
            ->execute([$assigneeId, 'ref-contact-' . $id]);
        $pdo->commit();
        return $ref;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/** Fit the intake note's columns without losing the original, retained referral. */
function referral_intake_note(array $ref): array {
    $body = "Reason: {$ref['reason']}\nNote type: {$ref['note_type']}\nSubmitted by: {$ref['counselor_name']} ({$ref['counselor_agency']}, {$ref['counselor_email']}, {$ref['counselor_phone']})"
        . (referral_request_summary($ref) !== '' ? "\n" . referral_request_summary($ref) : '')
        . (!empty($ref['guardian_name']) ? "\nParent/guardian: {$ref['guardian_name']} ({$ref['guardian_phone']})" : '')
        . (!empty($ref['preets_services']) ? "\nPre-ETS services requested: {$ref['preets_services']}" : '')
        . (!empty($ref['relationship']) ? "\nRelationship: {$ref['relationship']}" : '')
        . "\n\n{$ref['body']}";
    if (strlen($body) > 65535) {
        $suffix = "\n\n[Shortened to fit this intake note. The full text is retained in referral #" . (int)$ref['id'] . '.]';
        $body = mb_strcut($body, 0, 65535 - strlen($suffix), 'UTF-8') . $suffix;
    }
    return ['subject'=>mb_substr('[Referral] ' . $ref['subject'], 0, 255), 'body'=>$body];
}
