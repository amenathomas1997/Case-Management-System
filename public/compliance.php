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

// Every section's data, from the helper the apps' compliance action uses too (inc/compliance_data.php, 4.36.0).
$cd = compliance_data($user);
[$scoped, $today, $warnPct, $validDays] = [$cd['scoped'], $cd['today'], $cd['warn_pct'], $cd['valid_days']];
[$authRisk, $reportsDue, $equipDue, $fuDue, $fuDueTotal, $loansOverdue, $contractsRisk] = [$cd['auth_risk'], $cd['reports_due'], $cd['equip_due'], $cd['fu_due'], $cd['fu_due_total'], $cd['loans_overdue'], $cd['contracts_risk']];
[$consentRisk, $releaseRisk, $noRelease, $minorsNoConsent, $noNotice] = [$cd['consent_risk'], $cd['release_risk'], $cd['no_release'], $cd['minors_no_consent'], $cd['no_notice']];

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
  <div class="card stat"><span class="stat-num"><?= can('equipment') ? count($equipDue) : '<span aria-hidden="true">—</span><span class="visually-hidden">Not shown (needs equipment access)</span>' ?></span><span class="stat-label">Equipment service due</span></div>
  <div class="card stat"><span class="stat-num"><?= count(array_filter($authRisk, fn($t) => $t['expired'])) ?></span><span class="stat-label">Expired authorizations</span></div>
  <div class="card stat"><span class="stat-num"><?= count($consentRisk) ?></span><span class="stat-label">Consents to renew</span></div>
  <div class="card stat"><span class="stat-num"><?= count($releaseRisk) + count($noRelease) + count($minorsNoConsent) ?></span><span class="stat-label">Releases and consents to request</span></div>
  <div class="card stat"><span class="stat-num"><?= $fuDueTotal ?></span><span class="stat-label">Device follow-ups due</span></div>
  <div class="card stat"><span class="stat-num"><?= can('equipment') ? count($loansOverdue) : '<span aria-hidden="true">—</span><span class="visually-hidden">Not shown (needs equipment access)</span>' ?></span><span class="stat-label">Overdue equipment loans</span></div>
</div>

<section class="card" aria-labelledby="auth-h">
  <h2 id="auth-h">Authorizations at risk (<?= count($authRisk) ?>)</h2>
  <p class="muted">Open clients' active authorizations at <?= $warnPct ?>%+ of authorized hours, or of authorized funds (hours, mileage and expenses together), or expiring within <?= cms_number('auth_soon_days') ?> days.</p>
  <?php if (!$authRisk): ?><p class="muted">Nothing needs attention right now.</p><?php else: ?>
  <div class="table-scroll" tabindex="0" role="region" aria-label="Authorizations at risk">
  <table>
    <caption class="visually-hidden">Authorizations at risk</caption>
    <thead><tr><th scope="col">Client / track</th><th scope="col">Auth #</th><th scope="col">Hours and funds used</th><th scope="col">Expiration</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead>
    <tbody>
    <?php foreach ($authRisk as $t): $r = $t['rollup']; ?>
      <tr>
        <td><a href="track.php?id=<?= (int)$t['id'] ?>"><?= h($t['title']) ?><span class="visually-hidden"> for <?= h($t['client']) ?></span></a><br><span class="muted"><?= h($t['client']) ?></span></td>
        <td><?= h($t['auth_number']) ?></td>
        <td><?= fmt_hours($r['hours_worked']) ?> / <?= fmt_hours($r['authorized_hours']) ?>
          <strong<?= $t['pct'] >= 100 ? ' class="field-error"' : '' ?>> (<?= $t['pct'] ?>%)</strong>
          <div class="progress-wrap"><progress class="hours" max="100" value="<?= min(100, $t['pct']) ?>" aria-label="Authorized hours used"><?= $t['pct'] ?>%</progress></div>
          <?php if ($r['authorized_funds'] > 0): // Why a track under 80% of its hours is listed: its funds are nearly used. ?>
          <span class="muted">Funds:</span> <?= fmt_money((float)$r['authorized_funds'] - (float)$r['funds_remaining']) ?> of <?= fmt_money((float)$r['authorized_funds']) ?>
          <strong<?= $t['funds_pct'] >= 100 ? ' class="field-error"' : '' ?>> (<?= $t['funds_pct'] ?>%<?= $t['funds_pct'] >= 100 ? ', used up' : '' ?>)</strong>,
          <?= fmt_money((float)$r['funds_remaining']) ?> remaining
          <?php endif; ?></td>
        <td><?= fmt_date($t['expiration_date'], true) ?>
          <?= $t['expired'] ? '<br><strong class="field-error">Expired</strong>' : ($t['exp_soon'] ? '<br><span class="badge badge-pending">Expiring soon</span>' : '') ?></td>
        <td><?php $waitA = amendment_waiting_text((int)$t['id']); if ($waitA !== ''): ?><?= h($waitA) ?>. <?php endif; ?><a class="btn btn-secondary btn-sm" href="amendment_request.php?track=<?= (int)$t['id'] ?>"><?= $waitA !== '' ? 'Record the reply' : 'Request amendment' ?><span class="visually-hidden"> for <?= h($t['title']) ?> (<?= h($t['client']) ?>)</span></a></td>
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
        <td><a href="track.php?id=<?= (int)$t['id'] ?>"><?= h($t['title']) ?><span class="visually-hidden"> for <?= h($t['client']) ?></span></a><br><span class="muted"><?= h($t['client']) ?></span></td>
        <td><?= h(report_cadence_label((string)$t['report_cadence'])) ?></td>
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

<section class="card" aria-labelledby="fu-h">
  <h2 id="fu-h">Device follow-ups due (<?= $fuDueTotal ?>)</h2>
  <p class="muted">Post-loan checks that a device is still in use, due today or earlier. Record each one on the <a href="followups.php#due-h">Device follow-ups page</a>.</p>
  <?php if (!$fuDue): ?><p class="muted">No follow-ups are due.</p><?php else: ?>
  <?php if ($fuDueTotal > count($fuDue)): ?><p>Showing the first <?= count($fuDue) ?> of <?= $fuDueTotal ?> checks due, oldest first.</p><?php endif; ?>
  <ul>
  <?php foreach ($fuDue as $f): ?>
    <li><a href="client.php?id=<?= (int)$f['cid'] ?>"><?= h($f['client_name']) ?></a> — <?= h($f['equipment_name']) ?>, <?= (int)$f['interval_days'] ?>-day check ·
      <?= $f['due_date'] < $today ? '<strong class="field-error">overdue since ' . fmt_date($f['due_date']) . '</strong>' : 'due today' ?></li>
  <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</section>

<?php if (can('equipment')): ?>
<section class="card" aria-labelledby="loans-h">
  <h2 id="loans-h">Overdue equipment loans (<?= count($loansOverdue) ?>)</h2>
  <p class="muted">Items out on loan past their return date. Record returns on the <a href="equipment.php?status=overdue#inv-h">Equipment &amp; loans page, filtered to overdue returns</a>.</p>
  <?php if (!$loansOverdue): ?><p class="muted">No loans are overdue.</p><?php else: ?>
  <ul>
  <?php foreach ($loansOverdue as $l): ?>
    <li><a href="equipment.php?scan=<?= (int)$l['id'] ?>"><?= h($l['name']) ?></a> —
      <?= $l['borrower_id'] !== null ? 'with <a href="client.php?id=' . (int)$l['borrower_id'] . '">' . h($l['borrower']) . '</a>' : '<span class="muted">with a client outside your caseload</span>' ?> ·
      <strong class="field-error">overdue since <?= fmt_date($l['due_date']) ?></strong></li>
  <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</section>
<?php endif; ?>

<?php if (can('billing')): ?>
<section class="card" aria-labelledby="ct-h">
  <h2 id="ct-h">Contracts at risk (<?= count($contractsRisk) ?>)</h2>
  <p class="muted">Active contracts at 90%+ spent, already ended or ending within 30 days, or with an overdue deliverable.</p>
  <?php if (!$contractsRisk): ?><p class="muted">Nothing needs attention right now.</p><?php else: ?>
  <ul>
  <?php foreach ($contractsRisk as $c): ?>
    <li><a href="contracts.php?id=<?= (int)$c['id'] ?>"><?= h($c['name']) ?></a> —
      <?= fmt_money((float)$c['spent']) ?> of <?= fmt_money((float)$c['amount']) ?> (<strong<?= $c['pct'] >= 100 ? ' class="field-error"' : '' ?>><?= $c['pct'] ?>%</strong>)
      <?= $c['ended'] ? ' · <strong class="field-error">ended ' . fmt_date($c['end_date']) . '</strong>' : ($c['end_soon'] ? ' · <span class="badge badge-pending">ends ' . fmt_date($c['end_date']) . '</span>' : '') ?>
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
        <td><?php $k = $f['kind']; echo '<span class="badge ' . ($k === 'Expired' || $k === 'Withdrawn' || $k === 'Adult' ? 'badge-hold' : ($k === 'Expiring' ? 'badge-pending' : 'badge-active')) . '">' . h($k === 'Unsigned' ? 'Not signed' : ($k === 'Adult' ? 'Signed by a guardian before the client turned 18' : $k)) . '</span>'; ?></td>
        <td><?= ['Unsigned' => 'sent ', 'Expired' => 'expired ', 'Withdrawn' => 'withdrawn ', 'Expiring' => 'expires ', 'Adult' => 'client turned 18 on '][$f['kind']] . fmt_date($f['when']) ?>
          <?= $f['kind'] !== 'Unsigned' && $f['pending_sent_at'] ? '<br><span class="muted">renewal request sent ' . fmt_date(substr($f['pending_sent_at'], 0, 10)) . ', not yet signed</span>' : '' ?></td>
        <td><a class="btn btn-secondary btn-sm" href="client.php?id=<?= (int)$f['cid'] ?>#demo-h"><?= 'Open client page to ' . ($f['pending_sent_at'] ? 'resend consent request' : ($f['kind'] === 'Withdrawn' ? 'request new consent' : ($f['kind'] === 'Adult' ? "request the client's own consent" : 'renew consent'))) ?><span class="visually-hidden"> for <?= h($f['client']) ?></span></a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
</section>
<section class="card" aria-labelledby="release-h">
  <h2 id="release-h">Releases of information (<?= count($releaseRisk) + count($noRelease) + count($minorsNoConsent) ?>)</h2>
  <p class="muted">An adult client's release is valid for <?= release_validity_days() ?> days. Listed: the newest release for each recipient when it expires within 30 days, has expired or was withdrawn, and open clients with no current release or guardian consent. Request one from the client's page.</p>
  <?php if (!$releaseRisk && !$noRelease && !$minorsNoConsent): ?><p class="muted">No releases need attention.</p><?php endif; ?>
  <?php if ($minorsNoConsent): ?>
  <h3>Minors and Pre-ETS students with no guardian consent requested (<?= count($minorsNoConsent) ?>)</h3>
  <ul>
  <?php foreach ($minorsNoConsent as $c): ?>
    <li><a href="client.php?id=<?= (int)$c['cid'] ?>&amp;consent=1#consent-request"><?= h($c['client']) ?></a><?= $c['dob'] ? ', born ' . fmt_date($c['dob']) : '' ?><?= $c['client_type'] === 'Pre-ETS' ? ', Pre-ETS' : '' ?></li>
  <?php endforeach; ?>
  </ul>
  <?php endif; ?>
  <?php if ($releaseRisk): ?>
  <h3>Expiring, expired or withdrawn (<?= count($releaseRisk) ?>)</h3>
  <ul>
  <?php foreach ($releaseRisk as $r): ?>
    <li><a href="client.php?id=<?= (int)$r['client_id'] ?>#releases-h"><?= h($r['client']) ?></a>: <?= h($r['recipient_name']) ?> (<?= h($r['recipient_type']) ?>),
      <?= ['Withdrawn' => 'withdrawn ', 'Expired' => 'expired ', 'Expiring' => 'expires ', 'Adult' => 'signed by a guardian; the client turned 18 on '][$r['kind']] . fmt_date($r['when']) ?>.</li>
  <?php endforeach; ?>
  </ul>
  <?php endif; ?>
  <?php if ($noRelease): ?>
  <h3>No release or consent on file (<?= count($noRelease) ?>)</h3>
  <ul>
  <?php foreach ($noRelease as $c): ?>
    <li><a href="client.php?id=<?= (int)$c['cid'] ?>#releases-h"><?= h($c['client']) ?></a></li>
  <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</section>
<?php if ($noNotice): ?>
<section class="card" aria-labelledby="notice-h">
  <h2 id="notice-h">Privacy notice not recorded (<?= count($noNotice) ?>)</h2>
  <details><summary>Show the <?= count($noNotice) ?> open client<?= count($noNotice) === 1 ? '' : 's' ?></summary>
  <ul><?php foreach ($noNotice as $c): ?><li><a href="client.php?id=<?= (int)$c['cid'] ?>#records-h"><?= h($c['client']) ?></a></li><?php endforeach; ?></ul>
  </details>
</section>
<?php endif; ?>
<?php if (can('admin')): $staffDue = staff_compliance_list(); // 3.0.55 ?>
<section class="card" aria-labelledby="staff-h">
  <h2 id="staff-h">Staff agreements, training and unused accounts (<?= count($staffDue) ?>)</h2>
  <?php if (!$staffDue): ?><p>Every active account has a current confidentiality agreement and privacy training, and has signed in recently.</p><?php else: ?>
  <ul><?php foreach ($staffDue as $sd): ?><li><a href="users.php?id=<?= (int)$sd['id'] ?>"><?= h($sd['name']) ?></a>: <?= h(implode('; ', $sd['notes'])) ?></li><?php endforeach; ?></ul>
  <?php endif; ?>
</section>
<?php endif; ?>
<?php page_footer();
