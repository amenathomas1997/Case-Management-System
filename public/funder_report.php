<?php
// Funder outcomes report pack: one report per funding agency bundling the
// numbers a funder wants at renewal — clients served, hours by RSA category,
// goal achievement, device retention (from the follow-up checks), assessment
// score gains, and closures. HTML (accessible), letterhead PDF, and CSV.
require __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/document.php';
require_once __DIR__ . '/inc/report_period.php';
require_once __DIR__ . '/inc/scheduled_reports.php';
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
$agencies = funder_agencies($rt, $ri);
$agency = is_string($_GET['agency'] ?? null) ? trim($_GET['agency']) : (string)($agencies[0]['funding_agency'] ?? '');
// 4.29.0: an old name (from a bookmark or an earlier link) is the agency as it is called today,
// as listed in the picker (funder_agencies() lists each agency once).
if ($agency !== '') $agency = agency_resolve($agency);

// funder_metrics() and funder_stat_rows() live in inc/scheduled_reports.php, shared
// with the scheduled monthly funder email, so the two always agree.

$m = $agency !== '' ? funder_metrics($agency, $from, $to, $rt) : null;
[$retentionPct, $goalPct] = $m ? funder_pcts($m) : [null, null];

/* ---- CSV ---- */
if ($m && isset($_GET['export'])) {
    // Every bulk export is recorded (3.0.36 privacy follow-up).
    audit((int)$user['id'], 'report.export', 'client', null, 'Funder report CSV ' . $agency . ' ' . $from . ' to ' . $to);
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
// Built for the letterhead PDF too (4.30.0): the PDF links to a kept copy of it that the funder
// opens without a staff sign-in.
if ($m && (isset($_GET['html']) || isset($_GET['pdf']))) {
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
    if (isset($_GET['html'])) $doc->download('funder_report_' . $from . '_' . $to . '.html');
}

/* ---- Letterhead PDF ---- */
if ($m && isset($_GET['pdf'])) {
    require __DIR__ . '/inc/app_pdf.php';
    // Strict false check: the string "0" is falsy, and a count of 0 must still print.
    $tx = fn(?string $s): string => doc_pdf_text($s);
    $pdf = new AppPdf('P', 'mm', 'Letter');
    $pdf->describe('Funder Outcome Report — ' . $agency, 'Funder outcome report from ' . brand('org') . ' for ' . $agency . ', ' . fmt_date($from) . ' to ' . fmt_date($to));
    $pdf->SetAutoPageBreak(true, 18);
    $pdf->AddPage();
    // The PDF is untagged: its first line links to the accessible HTML copy, which the funder
    // can open without a staff sign-in (4.30.0).
    $pdf->accessibleCopyLine(doc_link_create($doc->html(false), 'report', null, 'funder report ' . $agency . ' ' . $from . ' to ' . $to),
        cfg('base_url') . '/funder_report.php?agency=' . urlencode($agency) . '&from=' . $from . '&to=' . $to . '&html=1');
    // Letterhead, one column at a time (4.30.0), so it is read in order.
    $xh = $pdf->GetX(); $y0 = $pdf->GetY();
    $pdf->SetFont('Helvetica', 'B', 18); $pdf->SetTextColor(...brand_rgb());
    $pdf->Cell(120, 9, $tx(brand('org')), 0, 2);
    $pdf->SetFont('Helvetica', '', 10); $pdf->SetTextColor(60, 60, 60);
    $pdf->Cell(120, 5, $tx($agency), 0, 2);
    $yLeft = $pdf->GetY();
    $pdf->SetXY($xh + 120, $y0);
    $pdf->SetFont('Helvetica', 'B', 14);
    $pdf->Cell(0, 9, 'FUNDER OUTCOME REPORT', 0, 2, 'R');
    $pdf->SetFont('Helvetica', '', 10);
    $pdf->Cell(0, 5, $tx('Period: ' . fmt_date($from) . ' to ' . fmt_date($to)), 0, 2, 'R');
    $pdf->SetY(max($yLeft, $pdf->GetY()));
    $pdf->SetDrawColor(...brand_rgb()); $pdf->SetLineWidth(0.6); $pdf->Ln(2);
    $pdf->Line(15, $pdf->GetY(), 200, $pdf->GetY()); $pdf->Ln(5); $pdf->SetTextColor(0, 0, 0);
    foreach (funder_stat_rows($agency, $from, $to, $m, $retentionPct, $goalPct) as $r) {
        $pdf->SetFont('Helvetica', 'B', 11); $pdf->Cell(78, 7, $tx($r[0]), 0, 0);
        $pdf->SetFont('Helvetica', '', 11); $pdf->MultiCell(0, 7, $tx($r[1]), 0, 'L');
    }
    $pdf->Ln(3);
    $pdf->SetFont('Helvetica', 'B', 12); $pdf->SetTextColor(...brand_rgb());
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
    audit((int)$user['id'], 'report.export', 'client', null, 'Funder report PDF ' . $agency . ' ' . $from . ' to ' . $to);
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="funder_report_' . $from . '_' . $to . '.pdf"');
    echo $bytes;
    exit;
}

// AI narrative summary from this agency's figures (3.0.47; counts only).
$aiNarrative = null;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['ai_narrative']) && $m) {
    csrf_check();
    $facts = 'Funding agency: ' . $agency . "\nPeriod: " . fmt_date($from) . ' to ' . fmt_date($to) . "\n"
        . implode("\n", array_map(fn($r) => $r[0] . ': ' . $r[1], funder_stat_rows($agency, $from, $to, $m, $retentionPct, $goalPct)));
    $nr = report_ai_narrative('report to the funding agency ' . $agency, $facts, (int)$user['id']);
    if ($nr['ok']) { $aiNarrative = $nr['text']; audit($user['id'], 'ai.draft', 'report', null, 'funder report narrative'); } else flash($nr['error'], 'error');
}
page_header('Funder report', $user, 'analytics');
?>
<style>.kv{display:grid;grid-template-columns:auto 1fr;gap:.25rem 1rem;margin:0}.kv dt{font-weight:600}.kv dd{margin:0}</style>
<h1>Funder outcome report</h1>
<p class="muted">One renewal-ready report per funding agency: clients served, hours by category, goal achievement, device retention, assessment gains, and closures. Only VR work counts; private-pay and no-charge tracks are left out.</p>

<?php
// 4.30.0: plain agency names (no counts read on every arrow press). Agencies known only from
// invoices, with no current tracks, are grouped under "Billed work only"; the track count is
// said on the page after Run.
$agencyOpt = fn(array $a) => '<option value="' . h($a['funding_agency']) . '"' . ($a['funding_agency'] === $agency ? ' selected' : '') . '>' . h($a['funding_agency']) . '</option>';
$withTracks = array_values(array_filter($agencies, fn($a) => (int)$a['n'] > 0));
$billedOnly = array_values(array_filter($agencies, fn($a) => (int)$a['n'] === 0));
$agencySelect = '<label for="agency">Funding agency</label><select id="agency" name="agency">'
    . implode('', array_map($agencyOpt, $withTracks))
    . ($billedOnly ? '<optgroup label="Billed work only">' . implode('', array_map($agencyOpt, $billedOnly)) . '</optgroup>' : '')
    . '</select>';
$agencyTracks = null;
foreach ($agencies as $a) if ($a['funding_agency'] === $agency) $agencyTracks = (int)$a['n'];
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

<?= report_ai_narrative_card('funder_report.php?agency=' . rawurlencode($agency) . '&from=' . rawurlencode($from) . '&to=' . rawurlencode($to), $aiNarrative, 'this funder report') ?>
<section class="card" aria-labelledby="fr-sum">
  <h2 id="fr-sum">Summary — <?= h($agency) ?></h2>
  <?php if ($agencyTracks !== null): ?><p><?= $agencyTracks === 0 ? 'No current training tracks: billed work only.' : $agencyTracks . ' current training track' . ($agencyTracks === 1 ? '' : 's') . '.' ?></p><?php endif; ?>
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
