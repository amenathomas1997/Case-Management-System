<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
$user = require_login();
require_perm('analytics');
$rt = report_table('training_tracks', (int)$user['id']);
$rg = report_table('smart_goals', (int)$user['id']);
$rc = report_table('clients', (int)$user['id']);
$ri = report_table('invoices', (int)$user['id']);
$rs = report_table('surveys', (int)$user['id']);

/* Billable value by month: this month and the 11 before it, each from the 1st
   (a cut-off part-way through a month would show the oldest month as partial).
   Value = hours and mileage at the logged rates plus track expenses — what the
   invoices bill (generate_invoice), each line's hours and mileage amounts rounded
   to the cent as the invoice rounds them — so it reconciles with the invoice table. */
$since = "DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 11 MONTH), '%Y-%m-01')";
$byMonth = db()->query("SELECT ym, SUM(hrs_part) hrs, SUM(amt_part) amt FROM (
        SELECT DATE_FORMAT(w.work_date, '%Y-%m') ym, w.hours hrs_part,
            ROUND(w.hours * COALESCE(w.hourly_rate, t.hourly_rate), 2) + ROUND(w.mileage * COALESCE(w.mileage_rate, t.mileage_rate), 2) amt_part
        FROM work_logs w JOIN {$rt} t ON t.id = w.track_id
        WHERE w.work_date >= {$since}
        UNION ALL
        SELECT DATE_FORMAT(e.expense_date, '%Y-%m'), 0, e.amount
        FROM track_expenses e JOIN {$rt} t ON t.id = e.track_id
        WHERE e.expense_date >= {$since}
    ) x GROUP BY ym ORDER BY ym")->fetchAll();
$maxAmt = max(1.0, ...array_map(fn($r) => (float)$r['amt'], $byMonth ?: [['amt' => 1]]));

/* Hours & revenue by funding agency (value includes track expenses, as invoiced).
   Billed lines count under the agency snapshotted on their invoice, as on the agency
   summary, so editing a track's agency later does not move work already invoiced;
   unbilled lines, and older invoices, use the track's. */
$byAgency = db()->query("SELECT funding_agency, SUM(hrs_part) hrs, SUM(amt_part) amt, COUNT(DISTINCT track_id) tracks FROM (
        SELECT COALESCE(i.funding_agency, t.funding_agency) funding_agency, t.id track_id, w.hours hrs_part,
            ROUND(w.hours * COALESCE(w.hourly_rate, t.hourly_rate), 2) + ROUND(w.mileage * COALESCE(w.mileage_rate, t.mileage_rate), 2) amt_part
        FROM work_logs w JOIN {$rt} t ON t.id = w.track_id LEFT JOIN invoices i ON i.id = w.invoice_id
        UNION ALL
        SELECT COALESCE(i.funding_agency, t.funding_agency), t.id, 0, e.amount
        FROM track_expenses e JOIN {$rt} t ON t.id = e.track_id LEFT JOIN invoices i ON i.id = e.invoice_id
    ) x GROUP BY funding_agency ORDER BY amt DESC")->fetchAll();

/* Goal achievement */
$goalStats = db()->query("SELECT status, COUNT(*) n FROM {$rg} smart_goals GROUP BY status")->fetchAll();
$goalTotal = array_sum(array_column($goalStats, 'n'));
$goalAchieved = 0;
foreach ($goalStats as $g) if ($g['status'] === 'Achieved') $goalAchieved = (int)$g['n'];

/* Closure outcomes */
$closures = db()->query("SELECT status, COUNT(*) n FROM {$rc} clients WHERE status LIKE 'Closed%' GROUP BY status")->fetchAll();
$closedTotal = array_sum(array_column($closures, 'n'));
$rehab = 0;
foreach ($closures as $c) if ($c['status'] === 'Closed - Rehabilitated') $rehab = (int)$c['n'];

/* Payment summary */
// Outstanding is what a sent invoice still owes after payments and write-offs (3.0.34);
// denied and void invoices owe nothing (their work was released to be billed again).
$pay = db()->query("SELECT status, COUNT(*) n, COALESCE(SUM(total_amount),0) amt, COALESCE(SUM(COALESCE(paid_amount, 0)),0) paid,
    COALESCE(SUM(CASE WHEN status IN ('Submitted','Paid') THEN GREATEST(total_amount - COALESCE(paid_amount, 0) - written_off, 0) ELSE 0 END),0) outstanding
    FROM {$ri} invoices GROUP BY status ORDER BY FIELD(status, 'Draft', 'Submitted', 'Paid', 'Denied', 'Void')")->fetchAll();

/* Average hours per achieved goal. Hours and goals are aggregated in
   separate subqueries — joining both tables directly would repeat every
   work log once per goal and inflate the figure. */
$avgRow = db()->query("SELECT AVG(track_hours) avg_hours FROM (
    SELECT t.id,
        (SELECT COALESCE(SUM(w.hours),0) FROM work_logs w WHERE w.track_id = t.id)
        / NULLIF((SELECT COUNT(*) FROM smart_goals g WHERE g.track_id = t.id AND g.status = 'Achieved'), 0) AS track_hours
    FROM {$rt} t
    WHERE EXISTS (SELECT 1 FROM smart_goals g2 WHERE g2.track_id = t.id AND g2.status = 'Achieved')
      AND EXISTS (SELECT 1 FROM work_logs w2 WHERE w2.track_id = t.id)) x")->fetch();

/* Every report page, each listed only when this account can open it (the same
   permission checks those pages make). */
$reports = array_filter([
    ['outcomes.php', 'Outcome report', 'Clients served, hours by RSA category and program, closures and the rehabilitation rate for a period, as agencies ask for at contract renewal; exports to CSV.', can('analytics')],
    ['funder_report.php', 'Funder outcome report', 'One report per funding agency with clients served, hours, goal achievement, device retention, assessment score gains and closures; as a page, letterhead PDF or CSV.', can('analytics')],
    ['agency_report.php', 'Agency billing summary', 'Billing totals per funding agency for a date range, for quarterly reconciliation with state VR.', can('billing')],
    ['rsa_report.php', 'RSA-911 case-service export', 'One row per client served in a period with the case-service fields state VR asks for, to preview on screen or download as CSV.', can('billing')],
    ['utilization.php', 'Staff billable utilization', "Each staff member's billable hours and value for a period against their expected capacity, with a utilization rate.", can('analytics')],
    ['capacity.php', 'Capacity planning', 'Committed session hours against available hours per instructor, plus authorized hours not yet scheduled on active tracks.', can('schedule')],
    ['risk.php', 'Outcome risk flags', 'Uses the AI assistant to rank active clients who may be at risk of not completing, with the reason and a suggested action.', can('analytics') && function_exists('ai_feature_enabled') && ai_feature_enabled('risk')],
], fn(array $r): bool => $r[3]);

page_header('Business analytics', $user, 'analytics');
?>
<h1>Business analytics</h1>
<?php if ($reports): ?>
<section class="card" aria-labelledby="reports-h">
  <h2 id="reports-h">Reports</h2>
  <ul>
    <?php foreach ($reports as [$rHref, $rLabel, $rWhat]): ?>
    <li><a href="<?= h($rHref) ?>"><?= h($rLabel) ?></a>: <?= h($rWhat) ?></li>
    <?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>

<div class="grid grid-4">
  <div class="card stat"><span class="stat-num"><?= $goalTotal ? round($goalAchieved / $goalTotal * 100) : 0 ?>%</span>
    <span class="stat-label">SMART goals achieved (<?= $goalAchieved ?> of <?= $goalTotal ?>)</span></div>
  <div class="card stat"><span class="stat-num"><?= $closedTotal ? round($rehab / $closedTotal * 100) : 0 ?>%</span>
    <span class="stat-label">Closures rehabilitated (<?= $rehab ?> of <?= $closedTotal ?>)</span></div>
  <div class="card stat"><span class="stat-num"><?= $avgRow['avg_hours'] ? number_format((float)$avgRow['avg_hours'], 1) : '<span aria-hidden="true">—</span><span class="visually-hidden">Not applicable (no achieved goals with logged hours)</span>' ?></span>
    <span class="stat-label">Avg training hours per achieved goal</span></div>
  <div class="card stat"><span class="stat-num"><?= fmt_money((float)array_sum(array_map(fn($p) => $p['status'] !== 'Draft' ? (float)$p['outstanding'] : 0, $pay))) ?></span>
    <span class="stat-label">Invoiced, not yet paid</span></div>
</div>

<section class="card" aria-labelledby="rev-h">
  <h2 id="rev-h">Billable value by month (last 12 months)</h2>
  <p class="muted">Value is hours and mileage at the rates logged plus billable track expenses, as invoiced. The current month is month to date.</p>
  <?php if (!$byMonth): ?><p class="muted">No logged hours or expenses yet.</p>
  <?php else: ?>
  <div class="table-scroll" tabindex="0" role="region" aria-labelledby="rev-h-cap">
  <table>
    <caption class="visually-hidden" id="rev-h-cap">Billable hours and value per month, with each month's value as a share of the highest month</caption>
    <thead><tr><th scope="col">Month</th><th scope="col" class="num">Hours</th><th scope="col" class="num">Value</th><th scope="col" style="width:40%;"><span class="visually-hidden">Share of the highest month</span></th></tr></thead>
    <tbody>
    <?php foreach ($byMonth as $m): $pct = round((float)$m['amt'] / $maxAmt * 100); ?>
      <tr>
        <th scope="row"><?= h(date('M Y', strtotime($m['ym'] . '-01'))) ?></th>
        <td class="num"><?= number_format((float)$m['hrs'], 1) ?></td>
        <td class="num"><?= fmt_money((float)$m['amt']) ?></td>
        <td><span class="visually-hidden"><?= $pct ?>%</span><div aria-hidden="true" style="background:var(--brand);height:1rem;border-radius:4px;width:<?= max(2, $pct) ?>%;"></div></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
</section>

<div class="grid grid-2">
  <section class="card" aria-labelledby="agency-h">
    <h2 id="agency-h">By funding agency</h2>
    <p class="muted">All time. Value includes billable track expenses.</p>
    <?php if (!$byAgency): ?><p class="muted">No data yet.</p>
    <?php else: ?>
    <div class="table-scroll" tabindex="0" role="region" aria-labelledby="agency-h-cap">
    <table>
      <caption class="visually-hidden" id="agency-h-cap">Tracks, hours and billable value (including track expenses) by funding agency</caption>
      <thead><tr><th scope="col">Agency</th><th scope="col" class="num">Tracks</th><th scope="col" class="num">Hours</th><th scope="col" class="num">Value</th></tr></thead>
      <tbody>
      <?php foreach ($byAgency as $a): ?>
        <tr><th scope="row"><?= h($a['funding_agency']) ?></th><td class="num"><?= (int)$a['tracks'] ?></td>
            <td class="num"><?= number_format((float)$a['hrs'], 1) ?></td><td class="num"><?= fmt_money((float)$a['amt']) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
  </section>

  <section class="card" aria-labelledby="pay-h">
    <h2 id="pay-h">Invoice status</h2>
    <?php if (!$pay): ?><p class="muted">No invoices yet.</p>
    <?php else: ?>
    <div class="table-scroll" tabindex="0" role="region" aria-labelledby="pay-h-cap">
    <table>
      <caption class="visually-hidden" id="pay-h-cap">Number of invoices by status, with the amount billed, the amount actually received and the amount still owed</caption>
      <thead><tr><th scope="col">Status</th><th scope="col" class="num">Invoices</th><th scope="col" class="num">Billed</th><th scope="col" class="num">Received</th><th scope="col" class="num">Outstanding</th></tr></thead>
      <tbody>
      <?php foreach ($pay as $p): ?>
        <tr><th scope="row"><?= h($p['status']) ?></th><td class="num"><?= (int)$p['n'] ?></td><td class="num"><?= fmt_money((float)$p['amt']) ?></td>
          <td class="num"><?= fmt_money((float)$p['paid']) ?></td>
          <td class="num"><?= $p['status'] === 'Draft' ? 'Not yet sent' : (in_array($p['status'], ['Denied', 'Void'], true) ? 'Closed' : fmt_money((float)$p['outstanding'])) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
    <?php if (can('billing')): ?><p><a href="invoices.php">Open the invoices list</a></p><?php endif; ?>
  </section>
</div>

<?php
$surveyStats = db()->query("SELECT COUNT(*) n, AVG(satisfaction) sat, AVG(goals_met) gm,
    SUM(satisfaction >= 9) promoters, SUM(satisfaction <= 6) detractors FROM {$rs} surveys WHERE completed_at IS NOT NULL")->fetch();
$surveySent = (int)db()->query("SELECT COUNT(*) FROM {$rs} surveys")->fetchColumn();
$npsOverall = (int)$surveyStats['n'] > 0 ? round(((int)$surveyStats['promoters'] - (int)$surveyStats['detractors']) / (int)$surveyStats['n'] * 100) : null;
// Monthly satisfaction + NPS trend over the last 12 months: this month and the 11
// before it, each from the 1st, as the billable-by-month chart ($since).
$trend = db()->query("SELECT DATE_FORMAT(completed_at,'%Y-%m') ym, COUNT(*) n, AVG(satisfaction) sat,
        SUM(satisfaction >= 9) promoters, SUM(satisfaction <= 6) detractors
    FROM {$rs} surveys WHERE completed_at IS NOT NULL AND completed_at >= {$since}
    GROUP BY ym ORDER BY ym")->fetchAll();
?>
<section class="card" aria-labelledby="survey-h">
  <h2 id="survey-h">Client outcome surveys</h2>
  <?php if (!(int)$surveyStats['n']): ?>
    <p class="muted"><?= $surveySent ? $surveySent . ' survey(s) sent, none completed yet.' : 'Surveys are emailed automatically when a client is closed as Rehabilitated.' ?></p>
  <?php else: ?>
    <div class="grid grid-4">
      <div class="stat"><span class="stat-num"><?= number_format((float)$surveyStats['sat'], 1) ?>/10</span><span class="stat-label">Average satisfaction</span></div>
      <div class="stat"><span class="stat-num"><?= $npsOverall === null ? '—' : ($npsOverall > 0 ? '+' : '') . $npsOverall ?></span><span class="stat-label">Net Promoter Score</span></div>
      <div class="stat"><span class="stat-num"><?= (int)$surveyStats['n'] ?></span><span class="stat-label">Responses</span></div>
      <div class="stat"><span class="stat-num"><?= $surveySent ? round((int)$surveyStats['n'] / $surveySent * 100) : 0 ?>%</span><span class="stat-label">Response rate</span></div>
    </div>
    <?php if (count($trend) > 1): ?>
    <h3>Satisfaction &amp; NPS trend (last 12 months)</h3>
    <p class="muted">NPS = % promoters (9–10) minus % detractors (0–6). Bars show average satisfaction out of 10.</p>
    <div class="nps-trend" aria-hidden="true">
      <?php foreach ($trend as $m): $sat = (float)$m['sat']; $nps = (int)$m['n'] > 0 ? round(((int)$m['promoters'] - (int)$m['detractors']) / (int)$m['n'] * 100) : 0; ?>
      <div class="nps-col">
        <span class="nps-nps <?= $nps >= 0 ? 'pos' : 'neg' ?>"><?= ($nps > 0 ? '+' : '') . $nps ?></span>
        <span class="nps-bar" style="height:<?= (int)round($sat / 10 * 90) ?>px"></span>
        <span class="nps-lbl"><?= h(date('M', strtotime($m['ym'] . '-01'))) ?></span>
      </div>
      <?php endforeach; ?>
    </div>
    <details>
      <summary>Show as table</summary>
    <table>
      <caption>Monthly average satisfaction and Net Promoter Score (last 12 months)</caption>
      <thead><tr><th scope="col">Month</th><th scope="col">Average satisfaction out of 10</th><th scope="col">Net Promoter Score</th><th scope="col">Responses</th></tr></thead>
      <tbody>
      <?php foreach ($trend as $m): $nps = (int)$m['n'] > 0 ? round(((int)$m['promoters'] - (int)$m['detractors']) / (int)$m['n'] * 100) : 0; ?>
        <tr><th scope="row"><?= h(date('F Y', strtotime($m['ym'] . '-01'))) ?></th>
          <td><?= number_format((float)$m['sat'], 1) ?></td>
          <td><?= ($nps > 0 ? '+' : '') . $nps ?></td>
          <td><?= (int)$m['n'] ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </details>
    <style>
    .nps-trend{display:flex;flex-wrap:wrap;gap:.5rem;align-items:flex-end;padding:.5rem 0;min-height:140px}
    .nps-col{display:flex;flex-direction:column;align-items:center;justify-content:flex-end;gap:.2rem;min-width:2.4rem}
    .nps-bar{width:1.4rem;background:var(--brand);border-radius:3px 3px 0 0;min-height:3px}
    .nps-nps{font-size:.85rem;font-weight:600}.nps-nps.pos{color:var(--brand)}.nps-nps.neg{color:var(--danger,#991b1b)}
    .nps-lbl{font-size:.85rem;color:var(--muted,inherit)}
    </style>
    <?php endif; ?>
  <?php endif; ?>
</section>

<section class="card" aria-labelledby="goal-h">
  <h2 id="goal-h">Goal status breakdown</h2>
  <div class="table-scroll" tabindex="0" role="region" aria-labelledby="goal-h-cap">
  <table>
    <caption class="visually-hidden" id="goal-h-cap">Number of goals by status</caption>
    <thead><tr><th scope="col">Status</th><th scope="col" class="num">Goals</th></tr></thead>
    <tbody>
    <?php foreach ($goalStats as $g): ?>
      <tr><th scope="row"><?= status_badge($g['status']) ?></th><td class="num"><?= (int)$g['n'] ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$goalStats): ?><tr><td colspan="2" class="muted">No goals recorded yet.</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
</section>
<?php page_footer();
