<?php
// Authorization & compliance dashboard: authorizations nearing their limit or
// expiration, tracks due for a progress report, and equipment due for service —
// the things that quietly cost a VR provider money if missed.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
$user = require_login();
// This dashboard surfaces client names, authorization numbers, counselor and
// guardian details, so the whole page requires the clients permission like every
// other client-data page; a user without it is refused even if they manage
// equipment or contracts. (Those sections are then shown only to users who also
// have the equipment or billing permission.)
require_perm('clients');

$scoped = caseload_scoped();
$scopeSql = $scoped ? ' AND c.assigned_to = ?' : '';
$scopeArg = $scoped ? [(int)$user['id']] : [];
$today = date('Y-m-d');
$soon = date('Y-m-d', strtotime('+30 days'));

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
    $expSoon = $t['expiration_date'] && $t['expiration_date'] <= $soon;
    $expired = $t['expiration_date'] && $t['expiration_date'] < $today;
    if ($pct >= 80 || $fundsPct >= 80 || $expSoon) {
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
$minDays = ['weekly' => 7, 'biweekly' => 14, 'monthly' => 28];
$cad = db()->prepare("SELECT t.id, t.title, t.auth_number, t.report_cadence, t.last_report_at, t.start_date, t.created_at,
        c.id AS cid, c.name AS client, c.counselor_email
    FROM training_tracks t JOIN clients c ON c.id = t.client_id
    WHERE t.status = 'Active' AND t.report_cadence <> 'none'
      AND c.archived_at IS NULL AND c.status NOT LIKE 'Closed%'" . $scopeSql);
$cad->execute($scopeArg);
$reportsDue = [];
foreach ($cad->fetchAll() as $t) {
    $gap = $minDays[$t['report_cadence']] ?? 28;
    $since = $t['last_report_at'] ?: max((string)$t['start_date'], substr((string)$t['created_at'], 0, 10));
    $due = date('Y-m-d', strtotime($since . ' +' . $gap . ' days'));
    if ($t['report_cadence'] === 'monthly' && substr($due, 8, 2) !== '01') {
        $due = min(date('Y-m-d', strtotime('first day of next month', strtotime($due))),
            date('Y-m-d', strtotime($since . ' +45 days')));
    }
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
            $c['pct'] = $pct; $c['end_soon'] = $endSoon;
            $contractsRisk[] = $c;
        }
    }
}

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
$cf = db()->prepare("SELECT c.id AS cid, c.name AS client,
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
foreach ($cf->fetchAll() as $f) {
    $f['kind'] = null;
    $f['guardian_name'] = $f['signed_at'] ? $f['signed_guardian'] : $f['pending_guardian'];
    if ($f['signed_at'] && $f['withdrawn_at']) {
        $f['kind'] = 'Withdrawn'; $f['when'] = substr($f['withdrawn_at'], 0, 10);
    } elseif ($f['signed_at']) {
        $exp = date('Y-m-d', strtotime($f['signed_at'] . ' +' . $validDays . ' days'));
        if ($exp <= $soon) { $f['kind'] = $exp < $today ? 'Expired' : 'Expiring'; $f['when'] = $exp; }
    } elseif ($f['pending_sent_at'] < $unsignedBefore) {
        $f['kind'] = 'Unsigned'; $f['when'] = $f['pending_sent_at'];
    }
    if ($f['kind'] !== null) $consentRisk[] = $f;
}
// Consents no longer in force (expired or withdrawn) first.
$noConsent = fn(array $f): int => in_array($f['kind'], ['Expired', 'Withdrawn'], true) ? 0 : 1;
usort($consentRisk, fn($a, $b) => [$noConsent($a), $a['when']] <=> [$noConsent($b), $b['when']]);

page_header('Compliance', $user, 'compliance');
?>
<h1>Authorization &amp; compliance</h1>
<div class="page-actions">
  <a class="btn btn-secondary btn-sm" href="doc_audit.php">Documentation audit</a>
  <?php if (can('billing')): ?><a class="btn btn-secondary btn-sm" href="contracts.php">Grants &amp; contracts</a><?php endif; ?>
</div>
<p class="muted">Things to act on before they cost time or money<?= $scoped ? ' (your caseload)' : '' ?>.</p>

<div class="grid grid-4">
  <div class="card stat"><span class="stat-num"><?= count($authRisk) ?></span><span class="stat-label">Authorizations at risk</span></div>
  <div class="card stat"><span class="stat-num"><?= count($reportsDue) ?></span><span class="stat-label">Reports due</span></div>
  <div class="card stat"><span class="stat-num"><?= can('equipment') ? count($equipDue) : '—' ?></span><span class="stat-label">Equipment service due</span></div>
  <div class="card stat"><span class="stat-num"><?= count(array_filter($authRisk, fn($t) => $t['expired'])) ?></span><span class="stat-label">Expired authorizations</span></div>
  <div class="card stat"><span class="stat-num"><?= count($consentRisk) ?></span><span class="stat-label">Consents to renew</span></div>
</div>

<section class="card" aria-labelledby="auth-h">
  <h2 id="auth-h">Authorizations at risk (<?= count($authRisk) ?>)</h2>
  <p class="muted">Open clients' active authorizations at 80%+ of authorized hours, or of authorized funds (hours, mileage and expenses together), or expiring within 30 days.</p>
  <?php if (!$authRisk): ?><p class="muted">Nothing needs attention right now.</p><?php else: ?>
  <div class="table-scroll" tabindex="0" role="region" aria-label="Authorizations at risk">
  <table>
    <caption class="visually-hidden">Authorizations at risk</caption>
    <thead><tr><th scope="col">Client / track</th><th scope="col">Auth #</th><th scope="col">Hours and funds used</th><th scope="col">Expiration</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead>
    <tbody>
    <?php foreach ($authRisk as $t): $r = $t['rollup']; ?>
      <tr>
        <td><a href="track.php?id=<?= (int)$t['id'] ?>"><?= h($t['title']) ?></a><br><span class="muted"><?= h($t['client']) ?></span></td>
        <td><?= h($t['auth_number']) ?></td>
        <td><?= fmt_hours($r['hours_worked']) ?> / <?= fmt_hours($r['authorized_hours']) ?>
          <strong<?= $t['pct'] >= 100 ? ' class="field-error"' : '' ?>> (<?= $t['pct'] ?>%)</strong>
          <div class="progress-wrap"><progress class="hours" max="100" value="<?= min(100, $t['pct']) ?>" aria-label="Authorized hours used"><?= $t['pct'] ?>%</progress></div>
          <?php if ($r['authorized_funds'] > 0): // Why a track under 80% of its hours is listed: its funds are nearly used. ?>
          <span class="muted">Funds:</span> <?= fmt_money((float)$r['authorized_funds'] - (float)$r['funds_remaining']) ?> of <?= fmt_money((float)$r['authorized_funds']) ?>
          <strong<?= $t['funds_pct'] >= 100 ? ' class="field-error"' : '' ?>> (<?= $t['funds_pct'] ?>%<?= $t['funds_pct'] >= 100 ? ', used up' : '' ?>)</strong>,
          <?= fmt_money((float)$r['funds_remaining']) ?> remaining
          <?php endif; ?></td>
        <td><?= $t['expiration_date'] ? fmt_date($t['expiration_date']) : '—' ?>
          <?= $t['expired'] ? '<br><strong class="field-error">Expired</strong>' : ($t['exp_soon'] ? '<br><span class="badge badge-pending">Expiring soon</span>' : '') ?></td>
        <td><a class="btn btn-secondary btn-sm" href="amendment_request.php?track=<?= (int)$t['id'] ?>">Request amendment<span class="visually-hidden"> for <?= h($t['title']) ?> (<?= h($t['client']) ?>)</span></a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
</section>

<section class="card" aria-labelledby="rep-h">
  <h2 id="rep-h">Progress reports due (<?= count($reportsDue) ?>)</h2>
  <p class="muted">Active tracks whose scheduled progress report to the VR counselor is due or overdue.</p>
  <?php if (!$reportsDue): ?><p class="muted">All caught up.</p><?php else: ?>
  <div class="table-scroll" tabindex="0" role="region" aria-label="Reports due">
  <table>
    <caption class="visually-hidden">Progress reports due</caption>
    <thead><tr><th scope="col">Client / track</th><th scope="col">Cadence</th><th scope="col">Last report</th><th scope="col">Due</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead>
    <tbody>
    <?php foreach ($reportsDue as $t): ?>
      <tr>
        <td><a href="track.php?id=<?= (int)$t['id'] ?>"><?= h($t['title']) ?></a><br><span class="muted"><?= h($t['client']) ?></span></td>
        <td><?= h(ucfirst($t['report_cadence'])) ?></td>
        <td><?= $t['last_report_at'] ? fmt_date($t['last_report_at']) : 'never' ?></td>
        <?php // Overdue is said in words, not only by colour and weight. ?>
        <td><?= $t['due'] < $today ? '<strong class="field-error">' . fmt_date($t['due']) . ' — overdue</strong>' : fmt_date($t['due']) . ($t['due'] === $today ? ' — due today' : '') ?></td>
        <td><?php if (can('reports')): ?><a class="btn btn-secondary btn-sm" href="report.php?track=<?= (int)$t['id'] ?>">Send report<span class="visually-hidden"> for <?= h($t['title']) ?> (<?= h($t['client']) ?>)</span></a>
            <?php if (empty($t['counselor_email'])): ?><br><span class="muted">no counselor email</span><?php endif; ?><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
</section>

<?php if (can('equipment')): ?>
<section class="card" aria-labelledby="eq-h">
  <h2 id="eq-h">Equipment service due (<?= count($equipDue) ?>)</h2>
  <p class="muted">Items due for maintenance or calibration within 30 days.</p>
  <?php if (!$equipDue): ?><p class="muted">No service due.</p><?php else: ?>
  <ul>
  <?php foreach ($equipDue as $e): ?>
    <li><a href="equipment.php?scan=<?= (int)$e['id'] ?>"><?= h($e['name']) ?></a> —
      <?= $e['overdue'] ? '<strong class="field-error">overdue since ' . fmt_date($e['due']) . '</strong>' : 'due ' . fmt_date($e['due']) ?></li>
  <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</section>
<?php endif; ?>

<?php if (can('billing')): ?>
<section class="card" aria-labelledby="ct-h">
  <h2 id="ct-h">Contracts at risk (<?= count($contractsRisk) ?>)</h2>
  <p class="muted">Active contracts at 90%+ spent, ending within 30 days, or with an overdue deliverable.</p>
  <?php if (!$contractsRisk): ?><p class="muted">Nothing needs attention right now.</p><?php else: ?>
  <ul>
  <?php foreach ($contractsRisk as $c): ?>
    <li><a href="contracts.php?id=<?= (int)$c['id'] ?>"><?= h($c['name']) ?></a> —
      <?= fmt_money((float)$c['spent']) ?> of <?= fmt_money((float)$c['amount']) ?> (<strong<?= $c['pct'] >= 100 ? ' class="field-error"' : '' ?>><?= $c['pct'] ?>%</strong>)
      <?= $c['end_soon'] ? ' · <span class="badge badge-pending">ends ' . fmt_date($c['end_date']) . '</span>' : '' ?>
      <?= (int)$c['overdue_deliv'] > 0 ? ' · <strong class="field-error">' . (int)$c['overdue_deliv'] . ' overdue deliverable(s)</strong>' : '' ?></li>
  <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</section>
<?php endif; ?>

<section class="card" aria-labelledby="consent-h">
  <h2 id="consent-h">Consents &amp; releases to renew (<?= count($consentRisk) ?>)</h2>
  <p class="muted">Each open client's latest signed consent when it is within 30 days of its <?= (int)$validDays ?>-day validity, already expired, or withdrawn by the guardian — listed until a new consent is signed, with the date of any renewal request already sent — and, for clients with no signed consent, requests sent over two weeks ago that are still unsigned. Send the renewal from the client's page.</p>
  <?php if (!$consentRisk): ?><p class="muted">No consents need attention.</p><?php else: ?>
  <div class="table-scroll" tabindex="0" role="region" aria-label="Consents to renew">
  <table>
    <caption class="visually-hidden">Consents and releases needing attention</caption>
    <thead><tr><th scope="col">Client</th><th scope="col">Guardian</th><th scope="col">Status</th><th scope="col">Date</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead>
    <tbody>
    <?php foreach ($consentRisk as $f): ?>
      <tr>
        <td><a href="client.php?id=<?= (int)$f['cid'] ?>"><?= h($f['client']) ?></a></td>
        <td><?= h($f['guardian_name']) ?></td>
        <td><?php $k = $f['kind']; echo '<span class="badge ' . ($k === 'Expired' || $k === 'Withdrawn' ? 'badge-hold' : ($k === 'Expiring' ? 'badge-pending' : 'badge-active')) . '">' . h($k === 'Unsigned' ? 'Not signed' : $k) . '</span>'; ?></td>
        <td><?= ['Unsigned' => 'sent ', 'Expired' => 'expired ', 'Withdrawn' => 'withdrawn ', 'Expiring' => 'expires '][$f['kind']] . fmt_date($f['when']) ?>
          <?= $f['kind'] !== 'Unsigned' && $f['pending_sent_at'] ? '<br><span class="muted">renewal request sent ' . fmt_date(substr($f['pending_sent_at'], 0, 10)) . ', not yet signed</span>' : '' ?></td>
        <td><a class="btn btn-secondary btn-sm" href="client.php?id=<?= (int)$f['cid'] ?>#demo-h"><?= 'Open client page to ' . ($f['pending_sent_at'] ? 'resend consent request' : ($f['kind'] === 'Withdrawn' ? 'request new consent' : 'renew consent')) ?><span class="visually-hidden"> for <?= h($f['client']) ?></span></a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
</section>
<?php page_footer();
