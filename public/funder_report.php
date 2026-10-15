<?php
// Funder outcomes report pack: one report per funding agency bundling the
// numbers a funder wants at renewal — clients served, hours by RSA category,
// goal achievement, device retention (from the follow-up checks), assessment
// score gains, and closures. HTML (accessible), letterhead PDF, and CSV.
require __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/document.php';
require_once __DIR__ . '/inc/report_period.php';
require __DIR__ . '/inc/layout.php';
$user = require_login();
require_perm('analytics');
$rt = report_table('training_tracks', (int)$user['id']);

// The shared period picker (remembered per user); this year when nothing is chosen.
$period = report_period($user, date('Y') . '-01-01', date('Y') . '-12-31');
$from = $period['from']; $to = $period['to'];

$ri = report_table('invoices', (int)$user['id']);
// The agencies on tracks now (n = how many tracks), plus those snapshotted on invoices,
// so work billed under an agency a track has since moved away from can still be selected
// (as on the agency summary).
$agencies = db()->query("SELECT funding_agency, SUM(n) n FROM (
        SELECT funding_agency, 1 n FROM {$rt} training_tracks WHERE funding_agency <> ''
        UNION ALL
        SELECT DISTINCT funding_agency, 0 FROM {$ri} inv WHERE funding_agency IS NOT NULL AND funding_agency <> ''
    ) x GROUP BY funding_agency ORDER BY funding_agency")->fetchAll();
$agency = (string)($_GET['agency'] ?? ($agencies[0]['funding_agency'] ?? ''));

/** Metrics for one funding agency over the period. */
function funder_metrics(string $agency, string $from, string $to): array {
    global $rt;
    $clientsOfAgency = "client_id IN (SELECT DISTINCT client_id FROM {$rt} training_tracks WHERE funding_agency = ?)";
    // Billed lines count under the agency snapshotted on their invoice (as agency_report.php
    // and analytics.php attribute them), so editing a track's agency later does not move
    // work already invoiced; unbilled lines, and older invoices, use the track's.
    $agencyExpr = 'COALESCE(i.funding_agency, t.funding_agency)';
    $logs = "work_logs w JOIN {$rt} t ON t.id = w.track_id LEFT JOIN invoices i ON i.id = w.invoice_id";

    $served = db()->prepare("SELECT COUNT(DISTINCT t.client_id) n FROM {$logs} WHERE {$agencyExpr} = ? AND w.work_date BETWEEN ? AND ?");
    $served->execute([$agency, $from, $to]); $servedTotal = (int)$served->fetchColumn();

    $tot = db()->prepare("SELECT COALESCE(SUM(w.hours),0) hrs, COALESCE(SUM(w.mileage),0) mi,
            COALESCE(SUM(ROUND(w.hours * COALESCE(w.hourly_rate,t.hourly_rate),2) + ROUND(w.mileage * COALESCE(w.mileage_rate,t.mileage_rate),2)),0) val
        FROM {$logs} WHERE {$agencyExpr} = ? AND w.work_date BETWEEN ? AND ?");
    $tot->execute([$agency, $from, $to]); $totals = $tot->fetch();

    // Track expenses are billed on the same invoices as the hours and mileage
    // (generate_invoice), so they are reported beside them — by expense date, under the
    // agency snapshotted on their invoice — and the total matches the agency billing
    // summary for the same agency and dates.
    $exp = db()->prepare("SELECT COALESCE(SUM(e.amount),0) FROM track_expenses e JOIN {$rt} t ON t.id = e.track_id
        LEFT JOIN invoices i ON i.id = e.invoice_id WHERE {$agencyExpr} = ? AND e.expense_date BETWEEN ? AND ?");
    $exp->execute([$agency, $from, $to]); $expenses = (float)$exp->fetchColumn();

    // Same value expression as the headline total (hours + mileage, each line rounded
    // to the cent as the invoices round it), so the category values add up to it.
    $byCat = db()->prepare("SELECT COALESCE(w.rsa_category, t.rsa_category, 'Uncategorized') cat, COUNT(DISTINCT t.client_id) clients,
            COALESCE(SUM(w.hours),0) hrs, COALESCE(SUM(ROUND(w.hours * COALESCE(w.hourly_rate,t.hourly_rate),2) + ROUND(w.mileage * COALESCE(w.mileage_rate,t.mileage_rate),2)),0) val
        FROM {$logs} WHERE {$agencyExpr} = ? AND w.work_date BETWEEN ? AND ? GROUP BY cat ORDER BY hrs DESC");
    $byCat->execute([$agency, $from, $to]); $byCat = $byCat->fetchAll();

    $goals = db()->prepare("SELECT COUNT(*) total, SUM(g.status = 'Achieved') achieved, AVG(g.progress_pct) avgpct
        FROM smart_goals g JOIN {$rt} t ON t.id = g.track_id
        WHERE t.funding_agency = ? AND g.created_at BETWEEN ? AND ?");
    $goals->execute([$agency, $from, $to . ' 23:59:59']); $goals = $goals->fetch();

    $ret = db()->prepare("SELECT COUNT(*) done, SUM(f.in_use = 1) in_use FROM equipment_followups f
        WHERE f.status = 'Completed' AND f.in_use IS NOT NULL AND f.completed_at BETWEEN ? AND ? AND f.$clientsOfAgency");
    $ret->execute([$from, $to . ' 23:59:59', $agency]); $ret = $ret->fetch();

    // Average score gain (percentage points) within the period for clients with
    // >=2 administrations of the same instrument. The sign is flipped for any
    // "lower is better" instrument so a genuine improvement always reads positive.
    $gain = db()->prepare("SELECT AVG(gain) FROM (
        SELECT ((SELECT x.pct FROM client_scored_assessments x WHERE x.client_id = sa.client_id AND x.instrument_id = sa.instrument_id AND x.assessed_on BETWEEN ? AND ? ORDER BY x.assessed_on DESC, x.id DESC LIMIT 1)
             - (SELECT y.pct FROM client_scored_assessments y WHERE y.client_id = sa.client_id AND y.instrument_id = sa.instrument_id AND y.assessed_on BETWEEN ? AND ? ORDER BY y.assessed_on ASC, y.id ASC LIMIT 1))
             * (CASE WHEN i.higher_is_better = 0 THEN -1 ELSE 1 END) AS gain,
             COUNT(*) c
        FROM client_scored_assessments sa JOIN assessment_instruments i ON i.id = sa.instrument_id
        WHERE sa.assessed_on BETWEEN ? AND ? AND sa.$clientsOfAgency
        GROUP BY sa.client_id, sa.instrument_id, i.higher_is_better HAVING c >= 2) z");
    $gain->execute([$from, $to, $from, $to, $from, $to, $agency]); $avgGain = $gain->fetchColumn();
    $avgGain = $avgGain === null ? null : (float)$avgGain;

    // Each client counts once, by their LAST status change in the period, and only
    // if that change closed the case (a closure corrected or reopened in the same
    // period counts as where it ended up) — so the per-status counts add up. Only a
    // case closed (moved into a Closed status from an open one) in the period counts,
    // so correcting an earlier period's closure is not a new closure (as outcomes.php).
    // Rows carried over from a merged duplicate ('[merged] ' note) are left out.
    $closures = db()->prepare("SELECT h.new_status, COUNT(*) n FROM client_status_history h
        WHERE h.new_status LIKE 'Closed%' AND DATE(h.changed_at) BETWEEN ? AND ? AND h.$clientsOfAgency
          AND (h.note IS NULL OR h.note NOT LIKE '[merged] %')
          AND NOT EXISTS (SELECT 1 FROM client_status_history h2 WHERE h2.client_id = h.client_id
              AND DATE(h2.changed_at) BETWEEN ? AND ?
              AND (h2.note IS NULL OR h2.note NOT LIKE '[merged] %')
              AND (h2.changed_at > h.changed_at OR (h2.changed_at = h.changed_at AND h2.id > h.id)))
          AND EXISTS (SELECT 1 FROM client_status_history h3 WHERE h3.client_id = h.client_id
              AND DATE(h3.changed_at) BETWEEN ? AND ?
              AND (h3.note IS NULL OR h3.note NOT LIKE '[merged] %')
              AND h3.new_status LIKE 'Closed%' AND (h3.old_status IS NULL OR h3.old_status NOT LIKE 'Closed%'))
        GROUP BY h.new_status");
    $closures->execute([$from, $to, $agency, $from, $to, $from, $to]); $closures = $closures->fetchAll();
    $closedTotal = (int)array_sum(array_column($closures, 'n'));
    $rehab = 0; foreach ($closures as $c) if ($c['new_status'] === 'Closed - Rehabilitated') $rehab = (int)$c['n'];

    return compact('servedTotal', 'totals', 'expenses', 'byCat', 'goals', 'ret', 'avgGain', 'closures', 'closedTotal', 'rehab');
}

$m = $agency !== '' ? funder_metrics($agency, $from, $to) : null;
$retentionPct = ($m && (int)$m['ret']['done'] > 0) ? round(100 * (int)$m['ret']['in_use'] / (int)$m['ret']['done']) : null;
$goalPct = ($m && (int)$m['goals']['total'] > 0) ? round(100 * (int)$m['goals']['achieved'] / (int)$m['goals']['total']) : null;

/** Shared row set for exports. */
function funder_stat_rows(string $agency, string $from, string $to, array $m, ?int $retentionPct, ?int $goalPct): array {
    $rows = [
        ['Funding agency', $agency],
        ['Period', fmt_date($from) . ' to ' . fmt_date($to)],
        ['Clients served', (string)$m['servedTotal']],
        ['Service hours', number_format((float)$m['totals']['hrs'], 2) . ' (' . fmt_money((float)$m['totals']['val']) . ' for hours and mileage)'],
        ['Track expenses', fmt_money($m['expenses'])],
        ['Total billable value', fmt_money(round((float)$m['totals']['val'] + $m['expenses'], 2)) . ' (hours, mileage and expenses)'],
        ['Goals achieved', $goalPct === null ? 'n/a' : $goalPct . '% (' . (int)$m['goals']['achieved'] . ' of ' . (int)$m['goals']['total'] . ', avg progress ' . round((float)$m['goals']['avgpct']) . '%)'],
        ['Device retention (still in use)', $retentionPct === null ? 'n/a' : $retentionPct . '% of ' . (int)$m['ret']['done'] . ' follow-ups'],
        // A signed number with the unit in the label: "+3" stays a number in the CSV
        // (csv_cell() passes numeric values), where "+3 points" would gain a formula guard.
        ['Avg assessment gain (points)', $m['avgGain'] === null ? 'n/a' : sprintf('%+d', (int)round($m['avgGain']))],
        ['Cases closed', (string)$m['closedTotal']],
        ['Rehabilitation rate', $m['closedTotal'] ? round($m['rehab'] / $m['closedTotal'] * 100) . '% (' . $m['rehab'] . ' rehabilitated)' : 'n/a'],
    ];
    return $rows;
}

/* ---- CSV ---- */
if ($m && isset($_GET['export'])) {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="funder_' . preg_replace('/[^A-Za-z0-9]+/', '_', $agency) . '_' . $from . '_' . $to . '.csv"');
    $out = fopen('php://output', 'w'); fwrite($out, "\xEF\xBB\xBF");
    write_csv_row($out, ['FUNDER OUTCOME REPORT — ' . cfg('name')]);
    foreach (funder_stat_rows($agency, $from, $to, $m, $retentionPct, $goalPct) as $r) write_csv_row($out, [csv_cell($r[0]), csv_cell($r[1])]);
    write_csv_row($out, []);
    write_csv_row($out, ['HOURS BY RSA CATEGORY']); write_csv_row($out, ['Category', 'Clients', 'Hours', 'Value']);
    foreach ($m['byCat'] as $r) write_csv_row($out, [csv_cell($r['cat']), $r['clients'], number_format((float)$r['hrs'], 2), number_format((float)$r['val'], 2)]);
    fclose($out); exit;
}

/* ---- Accessible HTML ---- */
if ($m && isset($_GET['html'])) {
    // Every section has a heading (as on the page), and an empty section says so.
    $doc = new AccessibleDocument('Funder Outcome Report — ' . $agency);
    $doc->paragraph(brand('org') . ' — ' . brand('tagline'));
    $props = [];
    foreach (funder_stat_rows($agency, $from, $to, $m, $retentionPct, $goalPct) as $r) $props[$r[0]] = $r[1];
    $doc->heading('Summary');
    $doc->properties($props);
    $doc->heading('Hours by Rehabilitation Services Administration (RSA) service category');
    if ($m['byCat']) {
        $doc->table('Clients, hours and value by RSA service category', ['Category', 'Clients', 'Hours', 'Value'],
            array_map(fn($r) => [$r['cat'], $r['clients'], number_format((float)$r['hrs'], 2), fmt_money((float)$r['val'])], $m['byCat']));
    } else {
        $doc->paragraph('No logged services in this period.');
    }
    $doc->heading('Closures');
    if ($m['closures']) $doc->properties(array_column(array_map(fn($c) => [$c['new_status'], (string)$c['n']], $m['closures']), 1, 0));
    else $doc->paragraph('No cases closed in this period.');
    $doc->paragraph('Prepared ' . date('F j, Y') . ' by ' . $user['name'] . ' — ' . cfg('name'));
    $doc->download('funder_report_' . $from . '_' . $to . '.html');
}

/* ---- Letterhead PDF ---- */
if ($m && isset($_GET['pdf'])) {
    require __DIR__ . '/inc/app_pdf.php';
    // Strict false check: the string "0" is falsy, and a count of 0 must still print.
    $tx = function (?string $s): string { $r = iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $s ?? ''); return $r === false ? '' : $r; };
    $pdf = new AppPdf('P', 'mm', 'Letter');
    $pdf->SetTitle($tx('Funder Outcome Report — ' . $agency));
    $pdf->SetAutoPageBreak(true, 18);
    $pdf->AddPage();
    // The PDF is untagged: its first line links to the accessible HTML version.
    $pdf->SetFont('Helvetica', '', 9);
    $pdf->Cell(0, 6, 'Accessible HTML version (sign-in required)', 0, 1, 'L', false, cfg('base_url') . '/funder_report.php?agency=' . urlencode($agency) . '&from=' . $from . '&to=' . $to . '&html=1');
    $pdf->SetFont('Helvetica', 'B', 18); $pdf->SetTextColor(30, 58, 138);
    $pdf->Cell(120, 9, $tx(brand('org')), 0, 0);
    $pdf->SetFont('Helvetica', 'B', 14); $pdf->SetTextColor(60, 60, 60);
    $pdf->Cell(0, 9, 'FUNDER OUTCOME REPORT', 0, 1, 'R');
    $pdf->SetFont('Helvetica', '', 10);
    $pdf->Cell(120, 5, $tx($agency), 0, 0);
    $pdf->Cell(0, 5, $tx('Period: ' . fmt_date($from) . ' to ' . fmt_date($to)), 0, 1, 'R');
    $pdf->SetDrawColor(30, 58, 138); $pdf->SetLineWidth(0.6); $pdf->Ln(2);
    $pdf->Line(15, $pdf->GetY(), 200, $pdf->GetY()); $pdf->Ln(5); $pdf->SetTextColor(0, 0, 0);
    foreach (funder_stat_rows($agency, $from, $to, $m, $retentionPct, $goalPct) as $r) {
        $pdf->SetFont('Helvetica', 'B', 11); $pdf->Cell(78, 7, $tx($r[0]), 0, 0);
        $pdf->SetFont('Helvetica', '', 11); $pdf->MultiCell(0, 7, $tx($r[1]), 0, 'L');
    }
    $pdf->Ln(3);
    $pdf->SetFont('Helvetica', 'B', 12); $pdf->SetTextColor(30, 58, 138);
    $pdf->Cell(0, 8, 'Hours by RSA service category', 0, 1); $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFont('Helvetica', 'B', 9); $pdf->SetFillColor(238, 241, 246);
    foreach ([[95, 'Category', 'L'], [25, 'Clients', 'R'], [30, 'Hours', 'R'], [35, 'Value', 'R']] as [$w, $t, $a]) $pdf->Cell($w, 7, $tx($t), 1, 0, $a, true);
    $pdf->Ln(); $pdf->SetFont('Helvetica', '', 9);
    foreach ($m['byCat'] as $r) {
        $pdf->Cell(95, 6, $tx(mb_substr($r['cat'], 0, 55)), 1, 0, 'L');
        $pdf->Cell(25, 6, (string)(int)$r['clients'], 1, 0, 'R');
        $pdf->Cell(30, 6, number_format((float)$r['hrs'], 2), 1, 0, 'R');
        $pdf->Cell(35, 6, $tx(fmt_money((float)$r['val'])), 1, 1, 'R');
    }
    $pdf->Ln(4); $pdf->SetFont('Helvetica', 'I', 8); $pdf->SetTextColor(60, 60, 60);
    $pdf->Cell(0, 5, $tx('Prepared ' . date('F j, Y') . ' by ' . $user['name'] . ' — ' . cfg('name')), 0, 1);
    // Built as a string and sent with this page's own headers: FPDF's download output
    // replaces the app's Cache-Control: no-store with a cacheable one.
    $bytes = $pdf->Output('S');
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="funder_report_' . $from . '_' . $to . '.pdf"');
    echo $bytes;
    exit;
}

page_header('Funder report', $user, 'analytics');
?>
<style>.kv{display:grid;grid-template-columns:auto 1fr;gap:.25rem 1rem;margin:0}.kv dt{font-weight:600}.kv dd{margin:0}</style>
<h1>Funder outcome report</h1>
<p class="muted">One renewal-ready report per funding agency: clients served, hours by category, goal achievement, device retention, assessment gains, and closures.</p>

<?php
// The count is spelled out: agencies known only from invoices have no current tracks.
$agencySelect = '<label for="agency">Funding agency</label><select id="agency" name="agency">';
foreach ($agencies as $a) {
    $n = (int)$a['n'];
    $agencySelect .= '<option value="' . h($a['funding_agency']) . '"' . ($a['funding_agency'] === $agency ? ' selected' : '') . '>' . h($a['funding_agency']) . ' — '
        . ($n === 0 ? 'no current tracks, billed work only' : $n . ' training track' . ($n === 1 ? '' : 's')) . '</option>';
}
$agencySelect .= '</select>';
// The downloads are buttons of this form, so they always use the agency and period
// currently chosen, even when those were changed without pressing Run.
$downloads = $agency === '' ? '' :
    '<button type="submit" name="html" value="1" class="btn btn-secondary btn-sm">Download accessible HTML report<span class="visually-hidden"> for the agency and period chosen above</span></button>'
    . '<button type="submit" name="pdf" value="1" class="btn btn-secondary btn-sm">Download letterhead PDF<span class="visually-hidden"> for the agency and period chosen above</span></button>'
    . '<button type="submit" name="export" value="1" class="btn btn-secondary btn-sm">Download funder report (CSV)<span class="visually-hidden"> for the agency and period chosen above</span></button>';
echo report_period_form('funder_report.php', $period, $agencySelect, $downloads);
if ($period['notes']) echo '<p>' . h(implode(' ', $period['notes'])) . '</p>';
?>

<?php if (!$agencies): ?>
  <p class="muted">No funding agencies on record yet. They come from each training track's funding agency.</p>
<?php elseif ($m): ?>
<div class="grid grid-4">
  <div class="card stat"><span class="stat-num"><?= (int)$m['servedTotal'] ?></span><span class="stat-label">Clients served</span></div>
  <div class="card stat"><span class="stat-num"><?= fmt_hours((float)$m['totals']['hrs']) ?></span><span class="stat-label">Service hours (<?= fmt_money((float)$m['totals']['val']) ?> for hours and mileage)</span></div>
  <div class="card stat"><span class="stat-num"><?= $goalPct === null ? '<span aria-hidden="true">—</span><span class="visually-hidden">Not applicable (no goals set in this period)</span>' : $goalPct . '%' ?></span><span class="stat-label">Goals achieved</span></div>
  <div class="card stat"><span class="stat-num"><?= $retentionPct === null ? '<span aria-hidden="true">—</span><span class="visually-hidden">Not applicable (no follow-up checks answered in this period)</span>' : $retentionPct . '%' ?></span><span class="stat-label">Device still in use</span></div>
</div>

<section class="card" aria-labelledby="fr-sum">
  <h2 id="fr-sum">Summary — <?= h($agency) ?></h2>
  <dl class="kv">
    <?php foreach (funder_stat_rows($agency, $from, $to, $m, $retentionPct, $goalPct) as $r): ?>
      <dt><?= h($r[0]) ?></dt><dd><?= h($r[1]) ?></dd>
    <?php endforeach; ?>
  </dl>
</section>

<section class="card" aria-labelledby="fr-cat">
  <h2 id="fr-cat">Hours by <abbr title="Rehabilitation Services Administration">RSA</abbr> category</h2>
  <div class="table-scroll" tabindex="0" role="region" aria-labelledby="fr-cat-cap">
  <table>
    <caption class="visually-hidden" id="fr-cat-cap">Clients, hours and value by RSA category for <?= h($agency) ?></caption>
    <thead><tr><th scope="col">Category</th><th scope="col" class="num">Clients</th><th scope="col" class="num">Hours</th><th scope="col" class="num">Value</th></tr></thead>
    <tbody>
    <?php if (!$m['byCat']): ?><tr><td colspan="4" class="muted">No logged services in this period.</td></tr><?php endif; ?>
    <?php foreach ($m['byCat'] as $r): ?>
      <tr><th scope="row"><?= h($r['cat']) ?></th><td class="num"><?= (int)$r['clients'] ?></td><td class="num"><?= number_format((float)$r['hrs'], 2) ?></td><td class="num"><?= fmt_money((float)$r['val']) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</section>

<section class="card" aria-labelledby="fr-close">
  <h2 id="fr-close">Closures</h2>
  <ul>
    <?php foreach ($m['closures'] as $c): ?><li><?= h($c['new_status']) ?>: <strong><?= (int)$c['n'] ?></strong></li><?php endforeach; ?>
    <?php if (!$m['closures']): ?><li class="muted">No cases closed in this period.</li><?php endif; ?>
  </ul>
</section>
<?php endif; ?>
<?php page_footer();
