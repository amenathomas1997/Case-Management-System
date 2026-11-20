<?php
declare(strict_types=1);
/*
 * The Compliance page's data (4.36.0: moved verbatim from compliance.php, shared with the apps'
 * compliance and consents_due actions, which held copies of parts of it). With the caller's
 * caseload scope and the page's gates: equipment service and overdue loans only with the
 * equipment permission, contracts only with billing. compliance.php renders it unchanged.
 */

/**
 * Every section compliance.php shows, in its order: auth_risk, reports_due, equip_due, fu_due
 * (and fu_due_total), loans_overdue, contracts_risk, consent_risk, release_risk, no_release,
 * minors_no_consent, no_notice; plus valid_days, warn_pct, today and scoped.
 */
function compliance_data(array $user): array {
    $scoped = caseload_scoped();
    $scopeSql = $scoped ? ' AND c.assigned_to = ?' : '';
    $scopeArg = $scoped ? [(int)$user['id']] : [];
    $today = date('Y-m-d');
    $soon = date('Y-m-d', strtotime('+30 days'));
    // The authorization warning and "expiring soon" window (Lists and time limits, 4.4.0).
    $warnPct = cms_number('auth_warn_pct');
    $authSoon = date('Y-m-d', strtotime('+' . cms_number('auth_soon_days') . ' days'));

    /* ---- Authorizations at risk ----
       Open clients only, like the reports-due list below and cron/daily_alerts.php: a
       closed or archived client's track is never closed for them, so it would otherwise
       stay listed (and counted) as expired indefinitely. */
    $rows = db()->prepare("SELECT t.id, t.title, t.auth_number, t.expiration_date, c.id AS cid, c.name AS client
        FROM training_tracks t JOIN clients c ON c.id = t.client_id
        WHERE t.status = 'Active' AND c.archived_at IS NULL AND c.status NOT LIKE 'Closed%'" . $scopeSql . " ORDER BY t.expiration_date IS NULL, t.expiration_date");
    $rows->execute($scopeArg);
    $authRisk = [];
    foreach ($rows->fetchAll() as $t) {
        $r = track_rollup((int)$t['id']);
        // Whole percent rounded DOWN, in exact hundredths of an hour: rounding up shows
        // 99.6% as a red "100%" while hours remain, and float division can floor an
        // exact 29% to 28.
        $authC = (int)round($r['authorized_hours'] * 100);
        $pct = $authC > 0 ? intdiv((int)round($r['hours_worked'] * 100) * 100, $authC) : 0;
        // Authorized funds are also used up by mileage and expenses (track_rollup's
        // funds_remaining), so a track can run out of money well before 80% of its hours.
        // Same whole-percent-rounded-down rule, in exact cents.
        $fundsC = (int)round($r['authorized_funds'] * 100);
        $fundsPct = $fundsC > 0 ? intdiv(($fundsC - (int)round($r['funds_remaining'] * 100)) * 100, $fundsC) : 0;
        $expSoon = $t['expiration_date'] && $t['expiration_date'] <= $authSoon;
        $expired = $t['expiration_date'] && $t['expiration_date'] < $today;
        if ($pct >= $warnPct || $fundsPct >= $warnPct || $expSoon) {
            $t['pct'] = $pct; $t['funds_pct'] = $fundsPct; $t['rollup'] = $r; $t['expired'] = $expired; $t['exp_soon'] = $expSoon;
            $authRisk[] = $t;
        }
    }
    usort($authRisk, fn($a, $b) => ($b['expired'] <=> $a['expired']) ?: (max($b['pct'], $b['funds_pct']) <=> max($a['pct'], $a['funds_pct'])));

    /* ---- Reports due (per-track cadence) ----
       The same tracks and due date as the automatic reports (cron/monthly_reports.php):
       a closed or archived client's track is not listed, and before its first report a
       track is due one cadence period after it started (its start date, or the day it was
       entered if that is later) — not on the day it was created. A monthly report keeps
       the cron's 1st-of-month rhythm: it is due on the first 1st of a month at least 28
       days after the last report, or 45 days after it if that comes sooner. */
    $cad = db()->prepare("SELECT t.id, t.title, t.auth_number, t.report_cadence, t.last_report_at, t.start_date, t.created_at,
            c.id AS cid, c.name AS client, c.counselor_email
        FROM training_tracks t JOIN clients c ON c.id = t.client_id
        WHERE t.status = 'Active' AND t.report_cadence <> 'none'
          AND c.archived_at IS NULL AND c.status NOT LIKE 'Closed%'" . $scopeSql);
    $cad->execute($scopeArg);
    $reportsDue = [];
    foreach ($cad->fetchAll() as $t) {
        $since = $t['last_report_at'] ?: max((string)$t['start_date'], substr((string)$t['created_at'], 0, 10));
        $due = report_due_date((string)$t['report_cadence'], (string)$since);
        if ($due <= $today) { $t['due'] = $due; $reportsDue[] = $t; }
    }

    /* ---- Equipment service due (only if the user manages equipment) ---- */
    $equipDue = [];
    if (can('equipment')) {
        foreach (db()->query("SELECT id, name, last_service_date, maintenance_interval_days FROM equipment
                WHERE status <> 'Retired' AND maintenance_interval_days IS NOT NULL AND maintenance_interval_days > 0")->fetchAll() as $e) {
            $due = $e['last_service_date'] ? date('Y-m-d', strtotime($e['last_service_date'] . ' +' . (int)$e['maintenance_interval_days'] . ' days')) : $today;
            if ($due <= $soon) { $e['due'] = $due; $e['overdue'] = $due < $today; $equipDue[] = $e; }
        }
        usort($equipDue, fn($a, $b) => strcmp($a['due'], $b['due']));
    }

    /* ---- Device follow-ups due (the Device follow-ups page's "Due now" query: pending,
       due today or earlier, caseload-scoped by the client the device is with) ---- */
    $fuDueQ = db()->prepare("SELECT f.id, f.due_date, f.interval_days, c.name AS client_name, c.id AS cid, e.name AS equipment_name
        FROM equipment_followups f JOIN clients c ON c.id = f.client_id JOIN equipment e ON e.id = f.equipment_id
        WHERE f.status = 'Pending' AND f.due_date <= CURDATE()" . $scopeSql . ' ORDER BY f.due_date LIMIT 300');
    $fuDueQ->execute($scopeArg);
    $fuDue = $fuDueQ->fetchAll();
    $fuCnt = db()->prepare("SELECT COUNT(*) FROM equipment_followups f JOIN clients c ON c.id = f.client_id
        WHERE f.status = 'Pending' AND f.due_date <= CURDATE()" . $scopeSql);
    $fuCnt->execute($scopeArg);
    $fuDueTotal = max((int)$fuCnt->fetchColumn(), count($fuDue));

    /* ---- Overdue equipment loans (only if the user manages equipment): the Equipment
       page's rule — an open loan whose due date has passed. As there, a scoped user
       still sees the item but not a borrower outside their caseload. ---- */
    $loansOverdue = [];
    if (can('equipment')) {
        foreach (db()->query("SELECT e.id, e.name, l.due_date, c.name AS borrower, c.id AS borrower_id, c.assigned_to AS borrower_assigned
                FROM equipment e JOIN equipment_loans l ON l.equipment_id = e.id AND l.returned_at IS NULL
                LEFT JOIN clients c ON c.id = l.client_id
                WHERE l.due_date IS NOT NULL AND l.due_date < CURDATE() ORDER BY l.due_date, e.name")->fetchAll() as $l) {
            if ($scoped && $l['borrower_id'] !== null && (int)$l['borrower_assigned'] !== (int)$user['id']) {
                $l['borrower'] = null; $l['borrower_id'] = null;
            }
            $loansOverdue[] = $l;
        }
    }

    /* ---- Contracts at risk (near end, over budget, or overdue deliverables) ---- */
    $contractsRisk = [];
    if (can('billing')) {
        foreach (db()->query("SELECT c.*,
                (SELECT COALESCE(SUM(amount),0) FROM contract_spend s WHERE s.contract_id = c.id) AS spent,
                (SELECT COUNT(*) FROM contract_deliverables d WHERE d.contract_id = c.id AND d.status <> 'Accepted' AND d.due_date IS NOT NULL AND d.due_date < CURDATE()) AS overdue_deliv
            FROM contracts c WHERE c.status = 'Active'")->fetchAll() as $c) {
            // Rounded down in exact cents (float division can floor an exact 90% to 89).
            $amtC = (int)round((float)$c['amount'] * 100);
            $pct = $amtC > 0 ? intdiv((int)round((float)$c['spent'] * 100) * 100, $amtC) : 0;
            $endSoon = $c['end_date'] && $c['end_date'] <= $soon;
            if ($pct >= 90 || $endSoon || (int)$c['overdue_deliv'] > 0) {
                // A contract whose end date has passed is said to have ended, not to be ending.
                $c['pct'] = $pct; $c['end_soon'] = $endSoon; $c['ended'] = $endSoon && $c['end_date'] < $today;
                $contractsRisk[] = $c;
            }
        }
    }

    /* ---- Consents / releases expiring, expired, or still pending (compliance_consent_risk) ---- */
    $validDays = (int)(setting('consent_validity_days') ?: 365);
    $consentRisk = compliance_consent_risk($user);
    // 4.29.0: the date a client turned 18, once it has passed, or null (as release_covering() judges it).
    $adultSince = fn(?string $dob): ?string => compliance_adult_since($dob, $today);

    /* ---- Releases of information (3.0.36) ----
       Per open client: the release for each recipient that is expiring within 30 days,
       expired or withdrawn (until a newer one for that recipient is current), and the open
       clients with no current release and no guardian consent in force at all. */
    $releaseRisk = []; $noRelease = [];
    try {
        $rq = db()->prepare("SELECT r.*, c.name AS client, c.dob FROM client_releases r JOIN clients c ON c.id = r.client_id
            WHERE c.archived_at IS NULL AND c.status NOT LIKE 'Closed%' AND r.signed_at IS NOT NULL" . $scopeSql . "
            ORDER BY r.client_id, r.signed_at DESC, r.id DESC");
        $rq->execute($scopeArg);
        $seenRecipient = [];
        foreach ($rq->fetchAll() as $r) {
            $k = $r['client_id'] . '|' . $r['recipient_type'] . '|' . mb_strtolower($r['recipient_name']);
            if (isset($seenRecipient[$k])) continue; // only the newest signed release per recipient
            $seenRecipient[$k] = true;
            $st = release_status($r);
            $adult = ($r['signer_role'] ?? '') === 'Guardian' ? $adultSince($r['dob'] ?? null) : null;
            if ($st === 'Current' && $adult !== null && substr((string)$r['signed_at'], 0, 10) < $adult) { $r['kind'] = 'Adult'; $r['when'] = $adult; }
            elseif ($st === 'Withdrawn') { $r['kind'] = 'Withdrawn'; $r['when'] = substr($r['withdrawn_at'], 0, 10); }
            elseif ($st === 'Expired') { $r['kind'] = 'Expired'; $r['when'] = $r['expires_on']; }
            elseif ($r['expires_on'] !== null && $r['expires_on'] <= $soon) { $r['kind'] = 'Expiring'; $r['when'] = $r['expires_on']; }
            else continue;
            $releaseRisk[] = $r;
        }
        usort($releaseRisk, fn($a, $b) => [$a['kind'] === 'Expiring' ? 1 : 0, $a['when']] <=> [$b['kind'] === 'Expiring' ? 1 : 0, $b['when']]);
        // 4.29.0: a guardian's consent or Guardian-signed release from before the client's 18th
        // birthday no longer counts once that birthday has passed (as release_covering()).
        $minorSignedSql = fn(string $col): string => "(c.dob IS NOT NULL AND DATE_ADD(c.dob, INTERVAL 18 YEAR) <= CURDATE() AND DATE({$col}) < DATE_ADD(c.dob, INTERVAL 18 YEAR))";
        $nq = db()->prepare("SELECT c.id AS cid, c.name AS client FROM clients c
            WHERE c.archived_at IS NULL AND c.status NOT LIKE 'Closed%'" . $scopeSql . "
              AND NOT EXISTS (SELECT 1 FROM client_releases r WHERE r.client_id = c.id AND r.signed_at IS NOT NULL AND r.withdrawn_at IS NULL AND r.expires_on >= CURDATE()
                  AND NOT (COALESCE(r.signer_role, '') = 'Guardian' AND " . $minorSignedSql('r.signed_at') . "))
              AND NOT EXISTS (SELECT 1 FROM consent_forms f WHERE f.client_id = c.id AND f.signed_at IS NOT NULL AND f.withdrawn_at IS NULL
                  AND f.signed_at > DATE_SUB(NOW(), INTERVAL ? DAY) AND NOT " . $minorSignedSql('f.signed_at') . ")
            ORDER BY c.name");
        $nq->execute(array_merge($scopeArg, [$validDays]));
        $noRelease = $nq->fetchAll();
    } catch (PDOException $e) {
        $releaseRisk = []; $noRelease = []; // mig_3.0.36 not run yet
    }

    // Minors and Pre-ETS students with no guardian consent ever requested (3.0.37).
    $mq = db()->prepare("SELECT c.id AS cid, c.name AS client, c.dob, c.client_type FROM clients c
        WHERE c.archived_at IS NULL AND c.status NOT LIKE 'Closed%'" . $scopeSql . "
          AND ((c.dob IS NOT NULL AND c.dob > DATE_SUB(CURDATE(), INTERVAL 18 YEAR)) OR c.client_type = 'Pre-ETS')
          AND NOT EXISTS (SELECT 1 FROM consent_forms f WHERE f.client_id = c.id)
        ORDER BY c.name");
    $mq->execute($scopeArg);
    $minorsNoConsent = $mq->fetchAll();
    // Open clients with no privacy notice recorded (3.0.37).
    try {
        $pq = db()->prepare("SELECT c.id AS cid, c.name AS client FROM clients c WHERE c.archived_at IS NULL AND c.status NOT LIKE 'Closed%'" . $scopeSql . " AND c.privacy_notice_at IS NULL ORDER BY c.name");
        $pq->execute($scopeArg);
        $noNotice = $pq->fetchAll();
    } catch (PDOException $e) { $noNotice = []; }
    // Listed once: a minor with nothing on file is under this heading, not also under "no release".
    $minorIds = array_flip(array_map('intval', array_column($minorsNoConsent, 'cid')));
    $noRelease = array_values(array_filter($noRelease, fn($c) => !isset($minorIds[(int)$c['cid']])));

    return ['auth_risk' => $authRisk, 'reports_due' => $reportsDue, 'equip_due' => $equipDue, 'fu_due' => $fuDue, 'fu_due_total' => $fuDueTotal,
        'loans_overdue' => $loansOverdue, 'contracts_risk' => $contractsRisk, 'consent_risk' => $consentRisk, 'release_risk' => $releaseRisk,
        'no_release' => $noRelease, 'minors_no_consent' => $minorsNoConsent, 'no_notice' => $noNotice, 'valid_days' => $validDays,
        'warn_pct' => $warnPct, 'today' => $today, 'scoped' => $scoped];
}

/** The consents to renew as the apps read them (consents_due since 4.34.0, and compliance). */
function compliance_consent_items(array $consentRisk): array {
    $items = [];
    foreach ($consentRisk as $f) {
        $items[] = ['client_id' => (int)$f['cid'], 'client' => (string)$f['client'], 'guardian_name' => (string)($f['guardian_name'] ?? ''),
            'kind' => $f['kind'], 'label' => $f['kind'] === 'Unsigned' ? 'Not signed' : ($f['kind'] === 'Adult' ? 'Signed by a guardian before the client turned 18' : $f['kind']), 'date' => substr((string)$f['when'], 0, 10),
            'pending_sent_at' => $f['kind'] !== 'Unsigned' && $f['pending_sent_at'] ? substr((string)$f['pending_sent_at'], 0, 10) : null,
            'action' => $f['pending_sent_at'] ? 'Resend consent request' : ($f['kind'] === 'Withdrawn' ? 'Request new consent' : ($f['kind'] === 'Adult' ? 'Request the client\'s own consent' : 'Renew consent'))];
    }
    return $items;
}

/**
 * The Compliance page for the apps (4.36.0): every section in compliance.php's order, each row
 * with the ids it opens and its line in the page's words. The 4.35.0 keys (auth_risk,
 * reports_due, equipment_due) keep their fields. Gated sections are absent without the
 * permission, as the page leaves them out.
 */
function compliance_api_reply(array $user): array {
    $d = compliance_data($user);
    $today = $d['today'];
    $canEquipment = can('equipment');
    $canBilling = can('billing');
    $auth = array_map(function (array $t) use ($today): array {
        $r = $t['rollup'];
        $fundsOn = (float)$r['authorized_funds'] > 0;
        $used = $fundsOn ? round((float)$r['authorized_funds'] - (float)$r['funds_remaining'], 2) : null;
        $expires = $t['expiration_date'] ? ($t['expired'] ? ' Expired ' . fmt_date($t['expiration_date']) . '.' : ' Expires ' . fmt_date($t['expiration_date']) . ($t['exp_soon'] ? ', soon.' : '.')) : '';
        return ['id' => (int)$t['id'], 'client_id' => (int)$t['cid'], 'title' => $t['title'], 'client' => $t['client'], 'pct' => $t['pct'],
            'funds_pct' => $fundsOn ? $t['funds_pct'] : null, 'funds_remaining' => $fundsOn ? round((float)$r['funds_remaining'], 2) : null,
            'funds_used' => $used, 'authorized_funds' => $fundsOn ? round((float)$r['authorized_funds'], 2) : null,
            'expiration_date' => $t['expiration_date'], 'expired' => (bool)$t['expired'],
            'text' => $t['client'] . ', ' . $t['title'] . ': ' . fmt_hours((float)$r['hours_worked']) . ' of ' . fmt_hours((float)$r['authorized_hours']) . ' hours used (' . $t['pct'] . '%).'
                . ($fundsOn ? ' Funds: ' . fmt_money($used) . ' of ' . fmt_money((float)$r['authorized_funds']) . ' (' . $t['funds_pct'] . '%' . ($t['funds_pct'] >= 100 ? ', used up' : '') . '), ' . fmt_money((float)$r['funds_remaining']) . ' remaining.' : '')
                . $expires];
    }, $d['auth_risk']);
    $reports = array_map(fn(array $t): array => ['id' => (int)$t['id'], 'client_id' => (int)$t['cid'], 'title' => $t['title'], 'client' => $t['client'], 'due' => $t['due'], 'cadence' => $t['report_cadence'],
        'text' => $t['client'] . ', ' . $t['title'] . ': ' . report_cadence_label((string)$t['report_cadence']) . ', last report ' . ($t['last_report_at'] ? fmt_date($t['last_report_at']) : 'never') . ', due '
            . fmt_date($t['due']) . ($t['due'] < $today ? ' — overdue' : ($t['due'] === $today ? ' — due today' : '')) . '.'], $d['reports_due']);
    $out = ['ok' => true, 'auth_risk' => $auth, 'reports_due' => $reports];
    // equipment_due stays in every reply (empty without the equipment permission), as 4.35.0 sent it:
    // the 4.35.0 iPhone app requires the key. counts.equipment_due is null when it is not shown.
    $out['equipment_due'] = [];
    if ($canEquipment) {
        $out['equipment_due'] = array_map(fn(array $e): array => ['id' => (int)$e['id'], 'name' => $e['name'], 'due' => $e['due'], 'overdue' => (bool)$e['overdue'],
            'text' => $e['name'] . ' — ' . ($e['overdue'] ? 'overdue since ' . fmt_date($e['due']) : 'due ' . fmt_date($e['due']))], $d['equip_due']);
    }
    $out['followups_due'] = array_map(fn(array $f): array => ['id' => (int)$f['id'], 'client_id' => (int)$f['cid'], 'client' => (string)$f['client_name'], 'equipment' => (string)$f['equipment_name'],
        'interval_days' => (int)$f['interval_days'], 'due_date' => (string)$f['due_date'], 'overdue' => $f['due_date'] < $today,
        'text' => $f['client_name'] . ' — ' . $f['equipment_name'] . ', ' . (int)$f['interval_days'] . '-day check · ' . ($f['due_date'] < $today ? 'overdue since ' . fmt_date($f['due_date']) : 'due today')], $d['fu_due']);
    $out['followups_due_total'] = (int)$d['fu_due_total'];
    if ($canEquipment) {
        $out['loans_overdue'] = array_map(fn(array $l): array => ['id' => (int)$l['id'], 'name' => (string)$l['name'], 'due_date' => (string)$l['due_date'],
            'borrower' => $l['borrower_id'] !== null ? (string)$l['borrower'] : null, 'borrower_id' => $l['borrower_id'] !== null ? (int)$l['borrower_id'] : null,
            'text' => $l['name'] . ' — ' . ($l['borrower_id'] !== null ? 'with ' . $l['borrower'] : 'with a client outside your caseload') . ' · overdue since ' . fmt_date($l['due_date'])], $d['loans_overdue']);
    }
    if ($canBilling) {
        $out['contracts_risk'] = array_map(fn(array $c): array => ['id' => (int)$c['id'], 'name' => (string)$c['name'], 'spent' => round((float)$c['spent'], 2), 'amount' => round((float)$c['amount'], 2),
            'pct' => (int)$c['pct'], 'end_date' => $c['end_date'] ?: null, 'ended' => (bool)$c['ended'], 'end_soon' => (bool)$c['end_soon'], 'overdue_deliverables' => (int)$c['overdue_deliv'],
            'text' => $c['name'] . ' — ' . fmt_money((float)$c['spent']) . ' of ' . fmt_money((float)$c['amount']) . ' (' . (int)$c['pct'] . '%)'
                . ($c['ended'] ? ' · ended ' . fmt_date($c['end_date']) : ($c['end_soon'] ? ' · ends ' . fmt_date($c['end_date']) : ''))
                . ((int)$c['overdue_deliv'] > 0 ? ' · ' . (int)$c['overdue_deliv'] . ' overdue deliverable(s)' : '')], $d['contracts_risk']);
    }
    $out['consents'] = compliance_consent_items($d['consent_risk']);
    $out['releases_risk'] = array_map(fn(array $r): array => ['client_id' => (int)$r['client_id'], 'client' => (string)$r['client'], 'recipient_name' => (string)$r['recipient_name'],
        'recipient_type' => (string)$r['recipient_type'], 'kind' => $r['kind'], 'date' => substr((string)$r['when'], 0, 10),
        'text' => $r['client'] . ': ' . $r['recipient_name'] . ' (' . $r['recipient_type'] . '), '
            . ['Withdrawn' => 'withdrawn ', 'Expired' => 'expired ', 'Expiring' => 'expires ', 'Adult' => 'signed by a guardian; the client turned 18 on '][$r['kind']] . fmt_date($r['when']) . '.'], $d['release_risk']);
    $out['no_release'] = array_map(fn(array $c): array => ['client_id' => (int)$c['cid'], 'client' => (string)$c['client']], $d['no_release']);
    $out['minors_no_consent'] = array_map(fn(array $c): array => ['client_id' => (int)$c['cid'], 'client' => (string)$c['client'], 'dob' => $c['dob'] ?: null, 'pre_ets' => $c['client_type'] === 'Pre-ETS',
        'text' => $c['client'] . ($c['dob'] ? ', born ' . fmt_date($c['dob']) : '') . ($c['client_type'] === 'Pre-ETS' ? ', Pre-ETS' : '')], $d['minors_no_consent']);
    $out['no_notice'] = array_map(fn(array $c): array => ['client_id' => (int)$c['cid'], 'client' => (string)$c['client']], $d['no_notice']);
    $out['counts'] = ['auth_risk' => count($d['auth_risk']), 'reports_due' => count($d['reports_due']), 'equipment_due' => $canEquipment ? count($d['equip_due']) : null,
        'expired' => count(array_filter($d['auth_risk'], fn($t) => $t['expired'])), 'consents' => count($d['consent_risk']),
        'releases' => count($d['release_risk']) + count($d['no_release']) + count($d['minors_no_consent']),
        'followups_due' => (int)$d['fu_due_total'], 'loans_overdue' => $canEquipment ? count($d['loans_overdue']) : null];
    $out['validity_days'] = (int)$d['valid_days'];
    return $out;
}

/** The date a client turned 18, once it has passed by $today, or null: a guardian's consent or
 *  release signed before then no longer speaks for the client (4.29.0, as release_covering()). */
function compliance_adult_since(?string $dob, string $today): ?string {
    if (!$dob || strtotime($dob) === false) return null;
    $d = date('Y-m-d', strtotime($dob . ' +18 years'));
    return $d <= $today ? $d : null;
}

/** The Compliance page's "Consents & releases to renew" on its own (compliance.php through
 *  compliance_data(), and the apps' consents_due), with the caller's caseload scope. */
function compliance_consent_risk(array $user): array {
    $scoped = caseload_scoped();
    $scopeSql = $scoped ? ' AND c.assigned_to = ?' : '';
    $scopeArg = $scoped ? [(int)$user['id']] : [];
    $today = date('Y-m-d');
    $soon = date('Y-m-d', strtotime('+30 days'));
    /* ---- Consents / releases expiring, expired, or still pending ----
       Judged per client as the client page judges it: by the latest SIGNED consent and
       the newest unsigned request. A signed consent that is expiring, expired or withdrawn
       is listed whether or not a renewal has been sent (the renewal's date is shown), so
       a consent that lapses while the renewal waits is never hidden. A request is listed
       as not signed only when the client has no signed consent and it was sent over two
       weeks ago. A consent the guardian has withdrawn is not valid consent, however
       recently it was signed. Closed and archived clients are left out, as in the
       lists above: no consent is renewed for a case that is no longer open. */
    $validDays = (int)(setting('consent_validity_days') ?: 365);
    $cf = db()->prepare("SELECT c.id AS cid, c.name AS client, c.dob,
            s.guardian_name AS signed_guardian, s.signed_at, s.withdrawn_at,
            p.guardian_name AS pending_guardian, p.sent_at AS pending_sent_at
        FROM clients c
        LEFT JOIN consent_forms s ON s.id = (SELECT x.id FROM consent_forms x WHERE x.client_id = c.id AND x.signed_at IS NOT NULL
            ORDER BY x.signed_at DESC, x.id DESC LIMIT 1)
        LEFT JOIN consent_forms p ON p.id = (SELECT y.id FROM consent_forms y WHERE y.client_id = c.id AND y.signed_at IS NULL
            ORDER BY y.sent_at DESC, y.id DESC LIMIT 1)
        WHERE c.archived_at IS NULL AND c.status NOT LIKE 'Closed%' AND (s.id IS NOT NULL OR p.id IS NOT NULL)" . $scopeSql);
    $cf->execute($scopeArg);
    $consentRisk = [];
    $unsignedBefore = date('Y-m-d', strtotime('-14 days'));
    // 4.29.0: the date a client turned 18, once it has passed, or null (as release_covering() judges it):
    // a guardian's consent or release signed before then no longer speaks for the client.
    $adultSince = fn(?string $dob): ?string => compliance_adult_since($dob, $today);
    foreach ($cf->fetchAll() as $f) {
        $f['kind'] = null;
        $adult = $adultSince($f['dob'] ?? null);
        $f['guardian_name'] = $f['signed_at'] ? $f['signed_guardian'] : $f['pending_guardian'];
        if ($f['signed_at'] && $f['withdrawn_at']) {
            $f['kind'] = 'Withdrawn'; $f['when'] = substr($f['withdrawn_at'], 0, 10);
        } elseif ($f['signed_at'] && $adult !== null && substr($f['signed_at'], 0, 10) < $adult) {
            $f['kind'] = 'Adult'; $f['when'] = $adult;
        } elseif ($f['signed_at']) {
            $exp = date('Y-m-d', strtotime($f['signed_at'] . ' +' . $validDays . ' days'));
            if ($exp <= $soon) { $f['kind'] = $exp < $today ? 'Expired' : 'Expiring'; $f['when'] = $exp; }
        } elseif ($f['pending_sent_at'] < $unsignedBefore) {
            $f['kind'] = 'Unsigned'; $f['when'] = $f['pending_sent_at'];
        }
        if ($f['kind'] !== null) $consentRisk[] = $f;
    }
    // Consents no longer in force (expired or withdrawn) first.
    $noConsent = fn(array $f): int => in_array($f['kind'], ['Expired', 'Withdrawn', 'Adult'], true) ? 0 : 1;
    usort($consentRisk, fn($a, $b) => [$noConsent($a), $a['when']] <=> [$noConsent($b), $b['when']]);
    return $consentRisk;
}
