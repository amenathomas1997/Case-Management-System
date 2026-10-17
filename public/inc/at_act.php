<?php
declare(strict_types=1);
// The AT Act report (4.21.0, owner request 2026-10-17): device demonstrations, short-term device loans
// and device reuse in the categories State AT Programs report to the federal NATADS system (the Annual
// Progress Report), for a federal fiscal year (October 1 to September 30). Loans record why the device
// was lent, who borrowed it and what was decided; each device has an AT type; demonstrations are an AT
// services record of their own (at_catalog 'demo').

/** The AT Act device types, as the Annual Progress Report groups them. */
const AT_ACT_TYPES = [
    'Vision', 'Hearing', 'Speech communication', 'Learning, cognition and developmental', 'Mobility, seating and positioning',
    'Daily living', 'Environmental adaptations', 'Vehicle modification and transportation', 'Computers and related', 'Recreation, sports and leisure',
];
/** Why a device was lent: the Annual Progress Report's four purposes. */
const LOAN_PURPOSES = ['decision' => 'To help decide which device to get', 'loaner' => 'As a loaner while a device is repaired or funding is awaited',
    'accommodation' => 'As a short-term accommodation', 'training' => 'For training, self-education or professional development'];
/** The professionals who take part in demonstrations, as the report counts them. */
const DEMO_PROFESSIONALS = ['education' => 'Education', 'employment' => 'Employment', 'health' => 'Health, allied health or rehabilitation', 'community' => 'Community living', 'technology' => 'Technology'];
/** Who borrowed it, or took part in a demonstration. */
const BORROWER_TYPES = ['individual' => 'A person with a disability', 'family' => 'A family member or guardian', 'education' => 'Someone from education',
    'employment' => 'Someone from employment', 'health' => 'Someone from health or rehabilitation', 'community' => 'Someone from community living', 'technology' => 'A technology professional'];
/** What was decided after a demonstration or a loan to help decide. */
const AT_DECISIONS = ['meets' => 'The device meets their needs', 'does_not_meet' => 'The device does not meet their needs', 'not_made' => 'No decision yet'];

/** A device's AT type: the one chosen for it, else a guess from its category, else Computers and related. */
function at_act_type(array $equipment): string {
    $t = (string)($equipment['at_type'] ?? '');
    if (in_array($t, AT_ACT_TYPES, true)) return $t;
    $c = strtolower((string)($equipment['category'] ?? '') . ' ' . (string)($equipment['name'] ?? ''));
    foreach ([
        'Vision' => ['screen reader', 'magnif', 'braille', 'cctv', 'video magnifier', 'low vision', 'vision', 'ocr', 'reading machine'],
        'Hearing' => ['hearing', 'amplif', 'caption', 'fm system', 'alerting'],
        'Speech communication' => ['aac', 'speech', 'communication'],
        'Mobility, seating and positioning' => ['wheelchair', 'mobility', 'seating', 'cane', 'walker'],
        'Daily living' => ['daily living', 'kitchen', 'talking', 'label'],
        'Environmental adaptations' => ['environmental', 'smart home', 'switch'],
    ] as $type => $words) foreach ($words as $w) if (str_contains($c, $w)) return $type;
    return 'Computers and related';
}

/** The federal fiscal year a date falls in: October 2025 to September 2026 is FY 2026. */
function at_act_fy(?string $date = null): int {
    $ts = strtotime($date ?? 'today');
    return (int)date('Y', $ts) + ((int)date('n', $ts) >= 10 ? 1 : 0);
}

/** A fiscal year's first and last day. */
function at_act_range(int $fy): array {
    return [($fy - 1) . '-10-01', $fy . '-09-30'];
}

/**
 * The report for a fiscal year: ['demos' => [...], 'loans' => [...], 'reuse' => [...]], each with its
 * counts by type, purpose, participant type and decision, ready to show or download.
 */
function at_act_report(int $fy, ?int $scopeUser = null): array {
    [$from, $to] = at_act_range($fy);
    $zero = fn(array $keys) => array_fill_keys($keys, 0);
    // Short-term loans begun in the year: not the permanent redeployments and repair loaners AT services
    // issues (counted under reuse, or not loans at all); for a caseload-limited account, its own clients'.
    $q = db()->prepare("SELECT l.*, e.category, e.name, e.at_type FROM equipment_loans l JOIN equipment e ON e.id = l.equipment_id JOIN clients c ON c.id = l.client_id
        WHERE l.loaned_at BETWEEN ? AND ? AND (l.notes IS NULL OR l.notes NOT LIKE 'AT service #%')" . ($scopeUser ? ' AND c.assigned_to = ' . (int)$scopeUser : ''));
    $q->execute([$from, $to]);
    $loans = ['total' => 0, 'by_type' => $zero(AT_ACT_TYPES), 'by_purpose' => $zero(array_keys(LOAN_PURPOSES)) + ['' => 0], 'by_borrower' => $zero(array_keys(BORROWER_TYPES)) + ['' => 0],
        'decision' => $zero(array_keys(AT_DECISIONS)) + ['' => 0], 'still_out' => 0];
    foreach ($q->fetchAll() as $l) {
        $loans['total']++;
        $loans['by_type'][at_act_type($l)]++;
        $loans['by_purpose'][isset(LOAN_PURPOSES[$l['purpose'] ?? '']) ? $l['purpose'] : '']++;
        $loans['by_borrower'][isset(BORROWER_TYPES[$l['borrower_type'] ?? '']) ? $l['borrower_type'] : '']++;
        // A decision is asked only of loans made to help decide.
        if (($l['purpose'] ?? '') === 'decision') $loans['decision'][isset(AT_DECISIONS[$l['decision'] ?? '']) ? $l['decision'] : '']++;
        if ($l['returned_at'] === null) $loans['still_out']++;
    }
    // Demonstrations held in the year (AT services, kind 'demo').
    $demos = ['total' => 0, 'by_type' => $zero(AT_ACT_TYPES), 'participants' => ['individual' => 0, 'family' => 0] + $zero(array_keys(DEMO_PROFESSIONALS)), 'decision' => $zero(array_keys(AT_DECISIONS)) + ['' => 0]];
    try {
        foreach (db()->query("SELECT details, status FROM at_records WHERE kind = 'demo' AND status = 'Completed'")->fetchAll() as $r) {
            $d = json_decode((string)$r['details'], true) ?: [];
            $day = (string)($d['demo_date'] ?? '');
            if ($day < $from || $day > $to) continue;
            $demos['total']++;
            $type = in_array($d['at_type'] ?? '', AT_ACT_TYPES, true) ? $d['at_type'] : 'Computers and related';
            $demos['by_type'][$type]++;
            foreach (['individual' => 'individuals', 'family' => 'family_members'] + array_combine(array_keys(DEMO_PROFESSIONALS), array_map(fn($k) => 'pro_' . $k, array_keys(DEMO_PROFESSIONALS))) as $k => $f) $demos['participants'][$k] += max(0, (int)($d[$f] ?? 0));
            $dec = array_search((string)($d['decision'] ?? ''), AT_DECISIONS, true);
            $demos['decision'][$dec !== false ? $dec : '']++;
        }
    } catch (PDOException $e) {}
    // Devices reused: redeployed in the year, with what they would have cost new.
    $reuse = ['total' => 0, 'by_type' => $zero(AT_ACT_TYPES), 'savings' => 0.0];
    try {
        // Dated by the redeployment itself (its loan), not by the record's last edit.
        $q = db()->query("SELECT r.details, r.updated_at, e.category, e.name, e.at_type, e.value FROM at_records r LEFT JOIN equipment e ON e.id = r.equipment_id
            WHERE r.kind = 'reuse' AND r.status = 'Redeployed'");
        $ld = db()->prepare('SELECT loaned_at FROM equipment_loans WHERE id = ?');
        foreach ($q->fetchAll() as $r) {
            $d = json_decode((string)$r['details'], true) ?: [];
            $day = substr((string)$r['updated_at'], 0, 10);
            if (!empty($d['loan_id'])) { $ld->execute([(int)$d['loan_id']]); $day = (string)($ld->fetchColumn() ?: $day); }
            if ($day < $from || $day > $to) continue;
            $reuse['total']++;
            $reuse['by_type'][at_act_type($r)]++;
            // Savings are what the device would cost new: its value, else the estimate on the reuse record.
            $reuse['savings'] += (float)($r['value'] ?? 0) ?: (float)($d['estimated_value'] ?? 0);
        }
    } catch (PDOException $e) {}
    return ['fy' => $fy, 'from' => $from, 'to' => $to, 'loans' => $loans, 'demos' => $demos, 'reuse' => $reuse];
}
