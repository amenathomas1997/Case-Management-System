<?php
// Agency scorecard (4.25.0, analytics): one VR agency on one page for a period — referrals and the deadlines
// met, clients served and hours, authorizations (in use now, and those that ended with hours unused), billing
// (invoiced, paid, how fast it pays, what is open, what waits in its portal) and outcomes at closure. For
// contract renewals and agency meetings. Caseload-scoped through report_table().
require __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/report_period.php';
require __DIR__ . '/inc/layout.php';
$user = require_login();
require_perm('analytics');
$period = report_period($user, fiscal_year_of(date('Y-m-d'))[0], date('Y-m-d'));
$from = $period['from']; $to = $period['to'];
$names = array_values(array_map(fn($a) => (string)$a['name'], agencies_all()));
$agencyIn = is_string($_GET['agency'] ?? null) ? agency_canonical(trim($_GET['agency'])) : ($names[0] ?? '');
$rt = report_table('training_tracks', (int)$user['id']);
$ri = report_table('invoices', (int)$user['id']);
$one = function (string $sql, array $p) { $q = db()->prepare($sql); $q->execute($p); return $q->fetch() ?: []; };

$card = null;
if ($agencyIn !== '') {
    // Every spelling on file that names this agency (an alias, an old name, other spacing or case), 4.27.0:
    // each count matches all of them, not only the exact name.
    // 4.28.0: a name the agency had before a rename on Funding agencies counts too (agency_resolve()).
    $isAgency = fn(string $v): bool => agency_key(agency_resolve($v)) === agency_key(agency_resolve($agencyIn));
    $spell = [];
    foreach (db()->query("SELECT DISTINCT funding_agency FROM training_tracks WHERE funding_agency IS NOT NULL AND funding_agency <> ''
        UNION SELECT DISTINCT funding_agency FROM invoices WHERE funding_agency IS NOT NULL AND funding_agency <> ''")->fetchAll(PDO::FETCH_COLUMN) as $v) {
        if ($isAgency((string)$v)) $spell[] = (string)$v;
    }
    if (!$spell) $spell = [$agencyIn];
    $inA = implode(',', array_fill(0, count($spell), '?'));
    $ref = referral_deadline_report($from, $to, caseload_scoped() ? (int)$user['id'] : null);
    $refA = array_values(array_filter($ref['rows'], fn($r) => $isAgency((string)$r['agency'])));
    $count = fn(string $k, string $v) => count(array_filter($refA, fn($r) => $r[$k] === $v));
    // Billed hours count under the agency on their invoice (as rsa_report.php does), unbilled ones under the track's.
    $served = $one("SELECT COUNT(DISTINCT t.client_id) AS clients, COALESCE(SUM(w.hours), 0) AS hours FROM work_logs w JOIN $rt t ON t.id = w.track_id
        LEFT JOIN invoices i ON i.id = w.invoice_id
        WHERE COALESCE(i.funding_agency, t.funding_agency) IN ($inA) AND w.work_date BETWEEN ? AND ?", array_merge($spell, [$from, $to]));
    $active = $one("SELECT COUNT(*) AS n, COALESCE(SUM(t.authorized_hours), 0) AS hours, COALESCE(SUM((SELECT COALESCE(SUM(w.hours), 0) FROM work_logs w WHERE w.track_id = t.id)), 0) AS used,
        COALESCE(SUM(t.expiration_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)), 0) AS ending FROM $rt t WHERE t.funding_agency IN ($inA) AND t.status = 'Active'", $spell);
    $unused = unused_totals(array_values(array_filter(unused_authorizations($from, $to, $rt), fn($r) => $isAgency((string)$r['funding_agency']))))['All'] ?? null;
    $bill = $one("SELECT COUNT(*) AS n, COALESCE(SUM(i.total_amount), 0) AS invoiced FROM $ri i WHERE i.funding_agency IN ($inA) AND i.status IN ('Submitted', 'Paid') AND i.submitted_at BETWEEN ? AND ?", array_merge($spell, [$from, $to]));
    $paid = $one("SELECT COUNT(*) AS n, COALESCE(SUM(COALESCE(i.paid_amount, i.total_amount)), 0) AS paid, AVG(DATEDIFF(i.paid_at, i.submitted_at)) AS days FROM $ri i
        WHERE i.funding_agency IN ($inA) AND i.status = 'Paid' AND i.paid_at BETWEEN ? AND ? AND i.submitted_at IS NOT NULL", array_merge($spell, [$from, $to]));
    $open = $one("SELECT COUNT(*) AS n, COALESCE(SUM(i.total_amount - COALESCE(i.paid_amount, 0) - i.written_off), 0) AS owed, COALESCE(SUM(i.portal_status = 'entered'), 0) AS in_portal,
        COALESCE(SUM(i.portal_status = 'rejected'), 0) AS rejected, COALESCE(SUM(DATEDIFF(CURDATE(), i.submitted_at) > ?), 0) AS past_terms FROM $ri i WHERE i.funding_agency IN ($inA) AND i.status = 'Submitted'", array_merge([agency_terms_days($agencyIn)], $spell));
    $out = $one("SELECT COUNT(*) AS n, COALESCE(SUM(k.employed = 1), 0) AS employed, COALESCE(SUM(k.employed IS NOT NULL), 0) AS asked,
        COALESCE(SUM(k.at_recommended), 0) AS rec, COALESCE(SUM(k.at_acquired), 0) AS got FROM client_closures k
        WHERE DATE(k.closed_at) BETWEEN ? AND ? AND EXISTS (SELECT 1 FROM $rt t WHERE t.client_id = k.client_id AND t.funding_agency IN ($inA))", array_merge([$from, $to], $spell));
    $pct = fn($a, $b) => (int)$b > 0 ? (int)round(100 * (int)$a / (int)$b) . '%' : 'n/a';
    $card = [
        'Referrals' => [
            ['Referrals received', (string)count($refA)],
            ['Contacted on time', $count('contact', 'on_time') . ' of ' . ($count('contact', 'on_time') + $count('contact', 'late') + $count('contact', 'overdue'))],
            ['First assessment on time', $count('assess', 'on_time') . ' of ' . ($count('assess', 'on_time') + $count('assess', 'late') + $count('assess', 'overdue'))],
            ['Deadlines', 'contact within ' . agency_contact_days($agencyIn) . ' business days, assess within ' . agency_assess_days($agencyIn)],
        ],
        'Services' => [
            ['Clients served', (string)(int)$served['clients']],
            ['Hours of service', number_format((float)$served['hours'], 2)],
        ],
        'Authorizations' => [
            ['In use now', (int)$active['n'] . ', ' . number_format((float)$active['used'], 2) . ' of ' . number_format((float)$active['hours'], 2) . ' hours used'],
            ['Ending in the next 30 days', (string)(int)$active['ending']],
            ['Ended in the period with hours unused', $unused ? number_format($unused['hours_unused'], 2) . ' hours, ' . fmt_money($unused['value_unused']) . ', of ' . $unused['tracks'] . ' authorizations' : 'None ended'],
        ],
        'Billing' => [
            ['Invoiced', (int)$bill['n'] . ' invoices, ' . fmt_money((float)$bill['invoiced'])],
            ['Paid', (int)$paid['n'] . ' invoices, ' . fmt_money((float)$paid['paid']) . ($paid['days'] !== null ? ', in ' . (int)round((float)$paid['days']) . ' days on average' : '')],
            ['Open now', (int)$open['n'] . ' invoices, ' . fmt_money((float)$open['owed']) . ' owed; ' . (int)$open['past_terms'] . ' past its ' . agency_terms_days($agencyIn) . '-day terms'],
            ['In its portal', (int)$open['in_portal'] . ' waiting to be paid, ' . (int)$open['rejected'] . ' rejected'],
        ],
        'Outcomes at closure' => [
            ['Cases closed', (string)(int)$out['n']],
            ['Employed at closure', $pct($out['employed'], $out['asked'])],
            ['Devices', (int)$out['got'] . ' acquired of ' . (int)$out['rec'] . ' recommended'],
        ],
    ];
}

if ($card && isset($_GET['download'])) {
    audit((int)$user['id'], 'report.export', 'settings', null, 'Agency scorecard CSV ' . $agencyIn . ' ' . $from . ' to ' . $to);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="agency_scorecard_' . $from . '_' . $to . '.csv"');
    $o = fopen('php://output', 'w');
    fwrite($o, "\xEF\xBB\xBF");
    write_csv_row($o, ['Agency', csv_cell($agencyIn), 'Period', $from . ' to ' . $to]);
    foreach ($card as $section => $rows) foreach ($rows as [$k, $v]) write_csv_row($o, [csv_cell($section), csv_cell($k), csv_cell($v)]);
    exit;
}
$pick = '<label for="agency">Agency</label><select id="agency" name="agency">' . implode('', array_map(fn($n) => '<option value="' . h($n) . '"' . ($n === $agencyIn ? ' selected' : '') . '>' . h($n) . '</option>', $names)) . '</select>';
$download = $card ? '<a class="btn btn-secondary btn-sm" href="agency_scorecard.php?' . h(http_build_query(['agency' => $agencyIn, 'from' => $from, 'to' => $to, 'download' => 1])) . '">Download as a spreadsheet</a>' : '';
page_header('Agency scorecard', $user, 'analytics', [['analytics.php', 'Reports']]);
?>
<h1>Agency scorecard<?= $agencyIn !== '' ? ': ' . h($agencyIn) : '' ?></h1>
<?php if (!$names): ?><p>Add your funding agencies on Administration, Funding agencies, first.</p><?php else: ?>
<p>From <?= h(fmt_date($from)) ?> to <?= h(fmt_date($to)) ?>.<?php foreach ($period['notes'] as $n) echo ' ' . h($n); ?></p>
<?= report_period_form('agency_scorecard.php', $period, $pick, $download) ?>
<?php foreach ($card ?? [] as $section => $rows): $sid = 'sc-' . preg_replace('/[^a-z]+/', '-', strtolower($section)); ?>
<section class="card" aria-labelledby="<?= $sid ?>"><h2 id="<?= $sid ?>"><?= h($section) ?></h2>
  <dl class="props"><?php foreach ($rows as [$k, $v]): ?><dt><?= h($k) ?></dt><dd><?= h($v) ?></dd><?php endforeach; ?></dl>
</section>
<?php endforeach; ?>
<?php endif; ?>
<?php page_footer();
