<?php
// Annual / period outcome report: clients served, hours by RSA category and
// program, closures and rehabilitation rate — the numbers agencies ask for
// at contract renewal. CSV export included.
require __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/document.php';
require_once __DIR__ . '/inc/report_period.php';
require __DIR__ . '/inc/layout.php';
$user = require_login();
require_perm('analytics');
$rt = report_table('training_tracks', (int)$user['id']);
$rh = report_table('client_status_history', (int)$user['id']);
$rs = report_table('surveys', (int)$user['id']);

// The shared period picker (remembered per user); this year when nothing is chosen.
// Any date the page could not read, or a swapped pair, is said in words on the page.
$period = report_period($user, date('Y') . '-01-01', date('Y') . '-12-31');
$from = $period['from']; $to = $period['to']; $periodNotes = $period['notes'];

/* Clients served: anyone with a logged visit in the period, by client type */
$served = db()->prepare("SELECT c.client_type, COUNT(DISTINCT c.id) n
    FROM work_logs w JOIN {$rt} t ON t.id = w.track_id JOIN clients c ON c.id = t.client_id
    WHERE w.work_date BETWEEN ? AND ? GROUP BY c.client_type");
$served->execute([$from, $to]);
$served = $served->fetchAll();
$servedTotal = array_sum(array_column($served, 'n'));

/* Hours + value by RSA category (value = hours and mileage, each line rounded to the
   cent as the invoices round it — the same expression as the totals below, so the
   category values add up to the total service value) */
$byCat = db()->prepare("SELECT COALESCE(w.rsa_category, t.rsa_category, 'Uncategorized') AS cat,
        COUNT(DISTINCT t.client_id) clients, COALESCE(SUM(w.hours),0) hrs,
        COALESCE(SUM(ROUND(w.hours * COALESCE(w.hourly_rate, t.hourly_rate), 2) + ROUND(w.mileage * COALESCE(w.mileage_rate, t.mileage_rate), 2)),0) val
    FROM work_logs w JOIN {$rt} t ON t.id = w.track_id
    WHERE w.work_date BETWEEN ? AND ? GROUP BY cat ORDER BY hrs DESC");
$byCat->execute([$from, $to]);
$byCat = $byCat->fetchAll();

/* Hours by program (bundle name, else the track's single category) */
$byProg = db()->prepare("SELECT COALESCE(b.name, t.rsa_category, t.service_type, 'Unlabeled') AS program,
        COUNT(DISTINCT t.client_id) clients, COALESCE(SUM(w.hours),0) hrs
    FROM work_logs w JOIN {$rt} t ON t.id = w.track_id
    LEFT JOIN service_bundles b ON b.id = t.bundle_id
    WHERE w.work_date BETWEEN ? AND ? GROUP BY program ORDER BY hrs DESC");
$byProg->execute([$from, $to]);
$byProg = $byProg->fetchAll();

/* Closures in the period from status history. Each client counts once, by their
   LAST status change in the period, and only if that change closed the case — a
   closure later corrected to another closed status, or reopened, in the same
   period counts as where it ended up. A client is counted only when the case was
   closed (moved into a Closed status from an open one) in the period, so correcting
   an earlier period's closure to another closed status is not a new closure. Rows
   carried over from a merged duplicate ('[merged] ' note) are that duplicate's
   timeline, so they are left out. */
$finalInPeriod = "DATE(h.changed_at) BETWEEN ? AND ?
    AND (h.note IS NULL OR h.note NOT LIKE '[merged] %')
    AND NOT EXISTS (SELECT 1 FROM client_status_history h2 WHERE h2.client_id = h.client_id
        AND DATE(h2.changed_at) BETWEEN ? AND ?
        AND (h2.note IS NULL OR h2.note NOT LIKE '[merged] %')
        AND (h2.changed_at > h.changed_at OR (h2.changed_at = h.changed_at AND h2.id > h.id)))
    AND EXISTS (SELECT 1 FROM client_status_history h3 WHERE h3.client_id = h.client_id
        AND DATE(h3.changed_at) BETWEEN ? AND ?
        AND (h3.note IS NULL OR h3.note NOT LIKE '[merged] %')
        AND h3.new_status LIKE 'Closed%' AND (h3.old_status IS NULL OR h3.old_status NOT LIKE 'Closed%'))";
$closures = db()->prepare("SELECT h.new_status, COUNT(*) n
    FROM {$rh} h
    WHERE h.new_status LIKE 'Closed%' AND {$finalInPeriod}
    GROUP BY h.new_status");
$closures->execute([$from, $to, $from, $to, $from, $to]);
$closures = $closures->fetchAll();
// One row per client, so the per-status counts add up to the distinct total.
$closedTotal = (int)array_sum(array_column($closures, 'n'));
$rehab = 0;
foreach ($closures as $c) if ($c['new_status'] === 'Closed - Rehabilitated') $rehab = (int)$c['n'];

/* Average total hours per client closed rehabilitated in the period (same cohort) */
$avgHours = db()->prepare("SELECT AVG(client_hours) FROM (
    SELECT h.client_id, (SELECT COALESCE(SUM(w.hours),0) FROM work_logs w
        JOIN {$rt} t ON t.id = w.track_id WHERE t.client_id = h.client_id) AS client_hours
    FROM {$rh} h
    WHERE h.new_status = 'Closed - Rehabilitated' AND {$finalInPeriod}
    GROUP BY h.client_id) x");
$avgHours->execute([$from, $to, $from, $to, $from, $to]);
$avgHours = (float)$avgHours->fetchColumn();

/* Totals */
$totals = db()->prepare("SELECT COALESCE(SUM(w.hours),0) hrs, COALESCE(SUM(w.mileage),0) mi,
        COALESCE(SUM(ROUND(w.hours * COALESCE(w.hourly_rate, t.hourly_rate), 2) + ROUND(w.mileage * COALESCE(w.mileage_rate, t.mileage_rate), 2)),0) val
    FROM work_logs w JOIN {$rt} t ON t.id = w.track_id WHERE w.work_date BETWEEN ? AND ?");
$totals->execute([$from, $to]);
$totals = $totals->fetch();
// The value above is hours and mileage. Track expenses dated in the period are billed on
// the same invoices (generate_invoice) and are in the RSA-911 export's Service Value, so
// they are shown beside it, with the total of both, and the two reports reconcile.
$exq = db()->prepare("SELECT COALESCE(SUM(e.amount),0) FROM track_expenses e JOIN {$rt} t ON t.id = e.track_id WHERE e.expense_date BETWEEN ? AND ?");
$exq->execute([$from, $to]);
$expenses = (float)$exq->fetchColumn();
$valueAll = round((float)$totals['val'] + $expenses, 2);

/* Survey results in the period */
$sv = db()->prepare("SELECT COUNT(*) n, AVG(satisfaction) sat, AVG(goals_met) goals
    FROM {$rs} surveys WHERE completed_at IS NOT NULL AND DATE(completed_at) BETWEEN ? AND ?");
$sv->execute([$from, $to]);
$sv = $sv->fetch();

/* ---- CSV export ---- */
if (isset($_GET['export'])) {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="outcomes_' . $from . '_' . $to . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    write_csv_row($out, ['OUTCOME REPORT — ' . cfg('name')]);
    write_csv_row($out, ['Period', $from . ' to ' . $to, 'Generated', date('Y-m-d'), 'By', csv_cell($user['name'])]);
    write_csv_row($out, []);
    write_csv_row($out, ['CLIENTS SERVED', $servedTotal]);
    foreach ($served as $s) write_csv_row($out, [csv_cell('  ' . ($s['client_type'] ?? 'VR')), $s['n']]);
    write_csv_row($out, ['TOTAL SERVICE HOURS', number_format((float)$totals['hrs'], 2)]);
    write_csv_row($out, ['TOTAL SERVICE VALUE (HOURS AND MILEAGE)', number_format((float)$totals['val'], 2)]);
    write_csv_row($out, ['TRACK EXPENSES', number_format($expenses, 2)]);
    write_csv_row($out, ['TOTAL VALUE INCLUDING EXPENSES', number_format($valueAll, 2)]);
    write_csv_row($out, ['CLIENTS CLOSED', $closedTotal]);
    foreach ($closures as $c) write_csv_row($out, [csv_cell('  ' . $c['new_status']), $c['n']]);
    write_csv_row($out, ['REHABILITATION RATE', $closedTotal ? round($rehab / $closedTotal * 100) . '%' : 'n/a']);
    write_csv_row($out, ['AVG HOURS PER REHABILITATED CLIENT', number_format($avgHours, 1)]);
    if ((int)$sv['n'] > 0) {
        write_csv_row($out, ['SURVEYS COMPLETED', $sv['n'], 'Avg satisfaction', round((float)$sv['sat'], 1) . '/10', 'Avg goals met', round((float)$sv['goals'], 1) . '/10']);
    }
    write_csv_row($out, []);
    write_csv_row($out, ['HOURS BY RSA CATEGORY']);
    write_csv_row($out, ['Category', 'Clients', 'Hours', 'Value']);
    foreach ($byCat as $r) write_csv_row($out, [csv_cell($r['cat']), $r['clients'], number_format((float)$r['hrs'], 2), number_format((float)$r['val'], 2)]);
    write_csv_row($out, []);
    write_csv_row($out, ['HOURS BY PROGRAM']);
    write_csv_row($out, ['Program', 'Clients', 'Hours']);
    foreach ($byProg as $r) write_csv_row($out, [csv_cell($r['program']), $r['clients'], number_format((float)$r['hrs'], 2)]);
    fclose($out);
    exit;
}

if (isset($_GET['html'])) {
    // Every section has a heading (as on the page), and an empty section says so.
    $doc = new AccessibleDocument('Outcome Report — ' . brand('org'));
    $doc->paragraph(brand('tagline'));
    $doc->paragraph('Abbreviations used: VR is Vocational Rehabilitation; RSA is the Rehabilitation Services Administration; Pre-ETS is Pre-Employment Transition Services.');
    $doc->heading('Summary');
    $doc->properties(['Period' => fmt_date($from) . ' to ' . fmt_date($to),
        'Clients served' => (string)$servedTotal, 'Total service hours' => fmt_hours((float)$totals['hrs']),
        'Total service value (hours and mileage)' => fmt_money((float)$totals['val']), 'Track expenses' => fmt_money($expenses),
        'Total value including expenses' => fmt_money($valueAll), 'Cases closed' => (string)$closedTotal,
        'Rehabilitation rate' => $closedTotal ? round($rehab / $closedTotal * 100) . '% (' . $rehab . ' rehabilitated)' : 'n/a']);
    $doc->heading('Who was served');
    if ($served) $doc->table('Clients served by client type', ['Client type', 'Clients'], array_map(fn($s) => [$s['client_type'] ?? 'VR', $s['n']], $served));
    else $doc->paragraph('No logged services in this period.');
    if ($avgHours > 0) $doc->paragraph('Average hours per rehabilitated client: ' . number_format($avgHours, 1));
    if ((int)$sv['n'] > 0) $doc->paragraph('Client satisfaction: ' . round((float)$sv['sat'], 1) . '/10 across ' . (int)$sv['n'] . ' surveys (goals met ' . round((float)$sv['goals'], 1) . '/10)');
    $doc->heading('Hours by Rehabilitation Services Administration (RSA) service category');
    if ($byCat) $doc->table('Clients, hours and value by RSA service category', ['Category', 'Clients', 'Hours', 'Value'], array_map(fn($r) => [$r['cat'], $r['clients'], number_format((float)$r['hrs'], 2), fmt_money((float)$r['val'])], $byCat));
    else $doc->paragraph('Nothing in this period.');
    $doc->heading('Hours by program');
    if ($byProg) $doc->table('Clients and hours by program', ['Program', 'Clients', 'Hours'], array_map(fn($r) => [$r['program'], $r['clients'], number_format((float)$r['hrs'], 2)], $byProg));
    else $doc->paragraph('Nothing in this period.');
    $doc->heading('Closures');
    if ($closures) $doc->properties(array_column(array_map(fn($c) => [$c['new_status'], (string)$c['n']], $closures), 1, 0));
    else $doc->paragraph('No cases closed in this period.');
    $doc->paragraph('Prepared ' . date('F j, Y') . ' by ' . $user['name'] . ' — ' . cfg('name'));
    $doc->download('outcome_report_' . $from . '_' . $to . '.html');
}

/* ---- Letterhead PDF ---- */
if (isset($_GET['pdf'])) {
    require __DIR__ . '/inc/app_pdf.php';
    // Strict false check: the string "0" is falsy, and a count of 0 must still print.
    $tx = function (?string $s): string { $r = iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $s ?? ''); return $r === false ? '' : $r; };
    $pdf = new AppPdf('P', 'mm', 'Letter');
    $pdf->SetTitle($tx('Outcome Report — ' . brand('org')));
    $pdf->SetAutoPageBreak(true, 18);
    $pdf->AddPage();
    $pdf->SetFont('Helvetica', '', 9);
    $pdf->Cell(0, 6, 'Accessible HTML version (sign-in required)', 0, 1, 'L', false, cfg('base_url') . '/outcomes.php?from=' . $from . '&to=' . $to . '&html=1');
    // Letterhead
    $pdf->SetFont('Helvetica', 'B', 18);
    $pdf->SetTextColor(30, 58, 138);
    $pdf->Cell(120, 9, $tx(brand('org')), 0, 0);
    $pdf->SetFont('Helvetica', 'B', 15);
    $pdf->SetTextColor(60, 60, 60);
    $pdf->Cell(0, 9, 'OUTCOME REPORT', 0, 1, 'R');
    $pdf->SetFont('Helvetica', '', 10);
    $pdf->Cell(120, 5, $tx(brand('tagline')), 0, 0);
    $pdf->Cell(0, 5, $tx('Period: ' . fmt_date($from) . ' to ' . fmt_date($to)), 0, 1, 'R');
    $pdf->SetDrawColor(30, 58, 138);
    $pdf->SetLineWidth(0.6);
    $pdf->Ln(2);
    $pdf->Line(15, $pdf->GetY(), 200, $pdf->GetY());
    $pdf->Ln(5);
    $pdf->SetTextColor(0, 0, 0);

    $stat = function (string $label, string $value) use ($pdf, $tx) {
        $pdf->SetFont('Helvetica', 'B', 11);
        $pdf->Cell(70, 7, $tx($label), 0, 0);
        $pdf->SetFont('Helvetica', '', 11);
        $pdf->Cell(0, 7, $tx($value), 0, 1);
    };
    $stat('Clients served', (string)$servedTotal
        . ($served ? ' (' . implode(', ', array_map(fn($s) => ($s['client_type'] ?? 'VR') . ': ' . $s['n'], $served)) . ')' : ''));
    $stat('Total service hours', number_format((float)$totals['hrs'], 2) . '  (value ' . fmt_money((float)$totals['val']) . ', hours and mileage)');
    $stat('Track expenses', fmt_money($expenses));
    $stat('Total incl. track expenses', fmt_money($valueAll));
    $stat('Cases closed', (string)$closedTotal);
    $stat('Rehabilitation rate', $closedTotal ? round($rehab / $closedTotal * 100) . '% (' . $rehab . ' rehabilitated)' : 'n/a');
    if ($avgHours > 0) $stat('Avg hours per rehabilitated client', number_format($avgHours, 1));
    if ((int)$sv['n'] > 0) $stat('Client satisfaction', round((float)$sv['sat'], 1) . '/10 across ' . (int)$sv['n'] . ' surveys (goals met ' . round((float)$sv['goals'], 1) . '/10)');
    $pdf->Ln(4);

    $tableHead = function (array $cols) use ($pdf, $tx) {
        $pdf->SetFont('Helvetica', 'B', 9);
        $pdf->SetFillColor(238, 241, 246);
        foreach ($cols as [$w, $t, $align]) $pdf->Cell($w, 7, $tx($t), 1, 0, $align, true);
        $pdf->Ln();
        $pdf->SetFont('Helvetica', '', 9);
    };
    $pdf->SetFont('Helvetica', 'B', 12);
    $pdf->SetTextColor(30, 58, 138);
    $pdf->Cell(0, 8, 'Hours by RSA service category', 0, 1);
    $pdf->SetTextColor(0, 0, 0);
    $tableHead([[95, 'Category', 'L'], [25, 'Clients', 'R'], [30, 'Hours', 'R'], [35, 'Value', 'R']]);
    foreach ($byCat as $r2) {
        $pdf->Cell(95, 6, $tx(mb_substr($r2['cat'], 0, 55)), 1, 0, 'L');
        $pdf->Cell(25, 6, (string)(int)$r2['clients'], 1, 0, 'R');
        $pdf->Cell(30, 6, number_format((float)$r2['hrs'], 2), 1, 0, 'R');
        $pdf->Cell(35, 6, $tx(fmt_money((float)$r2['val'])), 1, 1, 'R');
    }
    $pdf->Ln(4);
    $pdf->SetFont('Helvetica', 'B', 12);
    $pdf->SetTextColor(30, 58, 138);
    $pdf->Cell(0, 8, 'Hours by program', 0, 1);
    $pdf->SetTextColor(0, 0, 0);
    $tableHead([[120, 'Program', 'L'], [25, 'Clients', 'R'], [40, 'Hours', 'R']]);
    foreach ($byProg as $r2) {
        $pdf->Cell(120, 6, $tx(mb_substr($r2['program'], 0, 70)), 1, 0, 'L');
        $pdf->Cell(25, 6, (string)(int)$r2['clients'], 1, 0, 'R');
        $pdf->Cell(40, 6, number_format((float)$r2['hrs'], 2), 1, 1, 'R');
    }
    $pdf->Ln(4);
    if ($closures) {
        $pdf->SetFont('Helvetica', 'B', 12);
        $pdf->SetTextColor(30, 58, 138);
        $pdf->Cell(0, 8, 'Closures', 0, 1);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFont('Helvetica', '', 10);
        foreach ($closures as $c2) $pdf->Cell(0, 6, $tx($c2['new_status'] . ': ' . $c2['n']), 0, 1);
    }
    $pdf->Ln(4);
    $pdf->SetFont('Helvetica', 'I', 8);
    $pdf->SetTextColor(60, 60, 60);
    $pdf->Cell(0, 5, $tx('Prepared ' . date('F j, Y') . ' by ' . $user['name'] . ' — ' . cfg('name')), 0, 1);
    // Built as a string and sent with this page's own headers: FPDF's download output
    // replaces the app's Cache-Control: no-store with a cacheable one.
    $bytes = $pdf->Output('S');
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="outcome_report_' . $from . '_' . $to . '.pdf"');
    echo $bytes;
    exit;
}

page_header('Outcome report', $user, 'analytics');
?>
<h1>Outcome report</h1>
<p class="muted">The service and outcome numbers for agency contract renewals and annual reviews.</p>

<p>Showing <?= h(fmt_date($from)) ?> to <?= h(fmt_date($to)) ?>.<?php foreach ($periodNotes as $pn): ?> <?= h($pn) ?><?php endforeach; ?></p>

<?php // The downloads are buttons of this form, so they always use the period chosen
      // in it, even when it was changed without pressing Run.
$downloads = '<button type="submit" name="export" value="1" class="btn btn-secondary btn-sm">Download outcome report (CSV)<span class="visually-hidden"> for the period chosen above</span></button>'
    . '<button type="submit" name="html" value="1" class="btn btn-secondary btn-sm">Download accessible HTML report<span class="visually-hidden"> for the period chosen above</span></button>'
    . '<button type="submit" name="pdf" value="1" class="btn btn-secondary btn-sm">Download letterhead PDF<span class="visually-hidden"> for the period chosen above</span></button>'
    . (can('billing') ? '<a class="btn btn-secondary btn-sm" href="rsa_report.php?from=' . h($from) . '&amp;to=' . h($to) . '">RSA-911 case-service export</a>' : '')
    . '<a class="btn btn-secondary btn-sm" href="funder_report.php?from=' . h($from) . '&amp;to=' . h($to) . '">Per-funder report pack</a>';
echo report_period_form('outcomes.php', $period, '', $downloads); ?>

<h2 class="visually-hidden">Outcomes at a glance</h2>
<div class="grid grid-4">
  <div class="card stat"><span class="stat-num"><?= $servedTotal ?></span><span class="stat-label">Clients served</span></div>
  <div class="card stat"><span class="stat-num"><?= fmt_hours((float)$totals['hrs']) ?></span><span class="stat-label">Service hours (<?= fmt_money((float)$totals['val']) ?> for hours and mileage)</span></div>
  <div class="card stat"><span class="stat-num"><?= $closedTotal ?></span><span class="stat-label">Cases closed</span></div>
  <div class="card stat"><span class="stat-num"><?= $closedTotal ? round($rehab / $closedTotal * 100) . '%' : '<span aria-hidden="true">—</span><span class="visually-hidden">Not applicable (no cases closed)</span>' ?></span><span class="stat-label">Rehabilitation rate<?= $rehab ? ' (' . $rehab . ' rehabilitated)' : '' ?></span></div>
</div>
<p>Track expenses dated in this period (billed on the same invoices): <strong><?= fmt_money($expenses) ?></strong>, for a total value of <strong><?= fmt_money($valueAll) ?></strong> with the hours and mileage.
   Clients served counts clients with a logged session<?= can('billing') ? '; the RSA-911 export also lists a client whose only billed item in the period is a track expense' : '' ?>.</p>

<section class="card" aria-labelledby="oc-served">
  <h2 id="oc-served">Who was served</h2>
  <ul>
    <?php foreach ($served as $s): ?><li><?= h($s['client_type'] ?? 'VR') ?>: <strong><?= (int)$s['n'] ?></strong></li><?php endforeach; ?>
    <?php if (!$served): ?><li class="muted">No logged services in this period.</li><?php endif; ?>
    <?php if ($closedTotal && $avgHours > 0): ?><li>Average total hours per rehabilitated client: <strong><?= number_format($avgHours, 1) ?></strong></li><?php endif; ?>
    <?php if ((int)$sv['n'] > 0): ?><li>Outcome surveys: <strong><?= (int)$sv['n'] ?></strong> completed — satisfaction <strong><?= round((float)$sv['sat'], 1) ?>/10</strong>, goals met <strong><?= round((float)$sv['goals'], 1) ?>/10</strong></li><?php endif; ?>
  </ul>
</section>

<section class="card" aria-labelledby="oc-cat">
  <h2 id="oc-cat">Hours by <abbr title="Rehabilitation Services Administration">RSA</abbr> service category</h2>
  <div class="table-scroll" tabindex="0" role="region" aria-labelledby="oc-cat-cap">
  <table>
    <caption class="visually-hidden" id="oc-cat-cap">Clients, hours and value by RSA category</caption>
    <thead><tr><th scope="col">Category</th><th scope="col" class="num">Clients</th><th scope="col" class="num">Hours</th><th scope="col" class="num">Value</th></tr></thead>
    <tbody>
    <?php if (!$byCat): ?><tr><td colspan="4" class="muted">Nothing in this period.</td></tr><?php endif; ?>
    <?php foreach ($byCat as $r): ?>
      <tr><th scope="row"><?= h($r['cat']) ?></th><td class="num"><?= (int)$r['clients'] ?></td>
          <td class="num"><?= number_format((float)$r['hrs'], 2) ?></td><td class="num"><?= fmt_money((float)$r['val']) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</section>

<section class="card" aria-labelledby="oc-prog">
  <h2 id="oc-prog">Hours by program</h2>
  <div class="table-scroll" tabindex="0" role="region" aria-labelledby="oc-prog-cap">
  <table>
    <caption class="visually-hidden" id="oc-prog-cap">Clients and hours by billing program</caption>
    <thead><tr><th scope="col">Program</th><th scope="col" class="num">Clients</th><th scope="col" class="num">Hours</th></tr></thead>
    <tbody>
    <?php if (!$byProg): ?><tr><td colspan="3" class="muted">Nothing in this period.</td></tr><?php endif; ?>
    <?php foreach ($byProg as $r): ?>
      <tr><th scope="row"><?= h($r['program']) ?></th><td class="num"><?= (int)$r['clients'] ?></td><td class="num"><?= number_format((float)$r['hrs'], 2) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</section>

<section class="card" aria-labelledby="oc-close">
  <h2 id="oc-close">Closures</h2>
  <ul>
    <?php foreach ($closures as $c): ?><li><?= h($c['new_status']) ?>: <strong><?= (int)$c['n'] ?></strong></li><?php endforeach; ?>
    <?php if (!$closures): ?><li class="muted">No cases closed in this period.</li><?php endif; ?>
  </ul>
</section>
<?php page_footer();
