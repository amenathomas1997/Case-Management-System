<?php
declare(strict_types=1);
// Referral deadlines report (4.25.0): how each referral received in a period met the deadlines its agency
// sets (Funding agencies: business days to contact, and to hold the first assessment once accepted; 3 and 10
// when none is set). Measured by the tasks the deadlines create: "Contact new referral" (ref-contact-<referral>)
// and "Schedule initial assessment" (assess-<client>), done on or before their due date, or not. A referral
// attached to an existing client is judged by its "Follow up the new referral" task (reref-<referral>, 4.28.0).

/**
 * The report for referrals received from $from to $to: totals, by agency, and each referral. $scopeUser limits
 * it to referrals assigned to that person or converted to one of their clients.
 */
function referral_deadline_report(string $from, string $to, ?int $scopeUser = null): array {
    $sql = "SELECT r.id, r.client_name, r.counselor_agency, r.counselor_email, r.created_at, r.status, r.client_id,
            ct.due_date AS contact_due, ct.status AS contact_status, ct.done_at AS contact_done,
            at.due_date AS assess_due, at.status AS assess_status, at.done_at AS assess_done
        FROM referrals r
        LEFT JOIN tasks ct ON ct.auto_key = CONCAT('ref-contact-', r.id)
        LEFT JOIN tasks rt ON rt.auto_key = CONCAT('reref-', r.id)
        LEFT JOIN tasks at0 ON r.client_id IS NOT NULL AND at0.auto_key = CONCAT('assess-', r.client_id) AND at0.created_at >= r.created_at
        LEFT JOIN clients c ON c.id = r.client_id
        WHERE DATE(r.created_at) BETWEEN ? AND ?" . ($scopeUser !== null
            // The rule referral_in_scope() uses (4.28.0): a converted referral belongs to whoever holds its
            // client now; an open one to the person it is assigned to.
            ? " AND ((r.status = 'Converted' AND c.assigned_to = ?) OR (r.status <> 'Converted' AND r.assigned_to = ?))" : '') . ' ORDER BY r.created_at DESC';
    // A referral attached to an existing client (4.28.0) is judged by its own follow-up task
    // (reref-<referral>), never by the assessment task of an earlier referral for that client.
    $sql = str_replace('at.due_date AS assess_due, at.status AS assess_status, at.done_at AS assess_done',
        'IF(rt.id IS NOT NULL, rt.due_date, at0.due_date) AS assess_due, IF(rt.id IS NOT NULL, rt.status, at0.status) AS assess_status, IF(rt.id IS NOT NULL, rt.done_at, at0.done_at) AS assess_done', $sql);
    $q = db()->prepare($sql);
    $q->execute($scopeUser !== null ? [$from, $to, $scopeUser, $scopeUser] : [$from, $to]);
    $today = date('Y-m-d');
    $judge = function (?string $due, ?string $status, ?string $done) use ($today): string {
        if ($due === null) return 'none';
        if ($status === 'Done') return $done !== null && substr($done, 0, 10) <= $due ? 'on_time' : 'late';
        return $due < $today ? 'overdue' : 'waiting';
    };
    $t = ['referrals' => 0, 'contacted_on_time' => 0, 'contacted_late' => 0, 'waiting' => 0, 'overdue_now' => 0, 'assessed_on_time' => 0, 'assessed_late' => 0, 'assess_overdue' => 0];
    $by = [];
    $rows = [];
    foreach ($q->fetchAll() as $r) {
        $agency = referral_agency($r) ?? (trim((string)$r['counselor_agency']) !== '' ? trim((string)$r['counselor_agency']) : 'No agency');
        // A referral closed without service (declined, unreachable, not for us) closes its contact task too:
        // it is not counted as contacted, on time or late.
        $closed = $r['status'] === 'Closed';
        $contact = $closed ? 'closed' : $judge($r['contact_due'], $r['contact_status'], $r['contact_done']);
        $assess = $closed ? 'closed' : $judge($r['assess_due'], $r['assess_status'], $r['assess_done']);
        $t['referrals']++;
        $t['contacted_on_time'] += $contact === 'on_time' ? 1 : 0;
        $t['contacted_late'] += $contact === 'late' ? 1 : 0;
        $t['waiting'] += $contact === 'waiting' ? 1 : 0;
        $t['overdue_now'] += $contact === 'overdue' ? 1 : 0;
        $t['assessed_on_time'] += $assess === 'on_time' ? 1 : 0;
        $t['assessed_late'] += $assess === 'late' ? 1 : 0;
        $t['assess_overdue'] += $assess === 'overdue' ? 1 : 0;
        $b = &$by[$agency];
        $b ??= ['agency' => $agency, 'referrals' => 0, 'contact_judged' => 0, 'contact_on_time' => 0, 'assess_judged' => 0, 'assess_on_time' => 0];
        $b['referrals']++;
        if (in_array($contact, ['on_time', 'late', 'overdue'], true)) { $b['contact_judged']++; $b['contact_on_time'] += $contact === 'on_time' ? 1 : 0; }
        if (in_array($assess, ['on_time', 'late', 'overdue'], true)) { $b['assess_judged']++; $b['assess_on_time'] += $assess === 'on_time' ? 1 : 0; }
        unset($b);
        $rows[] = ['id' => (int)$r['id'], 'client_name' => (string)$r['client_name'], 'agency' => $agency, 'received' => substr((string)$r['created_at'], 0, 10),
            'contact_due' => $r['contact_due'], 'contact' => $contact, 'contact_done' => $r['contact_done'] ? substr((string)$r['contact_done'], 0, 10) : null,
            'assess_due' => $r['assess_due'], 'assess' => $assess, 'assess_done' => $r['assess_done'] ? substr((string)$r['assess_done'], 0, 10) : null];
    }
    $pct = fn(int $a, int $b) => $b > 0 ? (int)round(100 * $a / $b) : null;
    $byAgency = array_values(array_map(fn($b) => ['agency' => $b['agency'], 'referrals' => $b['referrals'],
        'contact_on_time_pct' => $pct($b['contact_on_time'], $b['contact_judged']), 'assess_on_time_pct' => $pct($b['assess_on_time'], $b['assess_judged'])], $by));
    usort($byAgency, fn($a, $b) => $b['referrals'] <=> $a['referrals'] ?: strcmp($a['agency'], $b['agency']));
    return $t + ['by_agency' => $byAgency, 'rows' => $rows];
}

/** Words for a deadline judgement. */
const DEADLINE_WORDS = ['closed' => 'Closed without service', 'on_time' => 'On time', 'late' => 'Late', 'overdue' => 'Overdue', 'waiting' => 'Not due yet', 'none' => 'No deadline'];
