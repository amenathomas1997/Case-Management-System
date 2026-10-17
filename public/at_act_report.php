<?php
// AT Act report (4.21.0): device demonstrations, short-term device loans and device reuse for a federal
// fiscal year, in the categories of the AT Act Annual Progress Report (inc/at_act.php), on screen or as
// a spreadsheet. Loans missing their purpose, borrower or decision are listed to fill in.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
csrf_check();
$user = require_login();
require_perm('analytics');
$fy = isset($_GET['fy']) && ctype_digit((string)$_GET['fy']) && (int)$_GET['fy'] >= 2000 && (int)$_GET['fy'] <= 2100 ? (int)$_GET['fy'] : at_act_fy();

// Fill in a loan's missing details (equipment permission, as lending needs).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_loan']) && can('equipment')) {
    $lid = (int)$_POST['save_loan'];
    $l = db()->prepare('SELECT client_id FROM equipment_loans WHERE id = ?');
    $l->execute([$lid]);
    $cid = $l->fetchColumn();
    if ($cid !== false) {
        scope_check_client_id((int)$cid, $user);
        $pick = fn(string $k, array $allowed) => is_string($_POST[$k . '_' . $lid] ?? null) && isset($allowed[$_POST[$k . '_' . $lid]]) ? $_POST[$k . '_' . $lid] : null;
        db()->prepare('UPDATE equipment_loans SET purpose = COALESCE(?, purpose), borrower_type = COALESCE(?, borrower_type), decision = COALESCE(?, decision) WHERE id = ?')
            ->execute([$pick('purpose', LOAN_PURPOSES), $pick('borrower', BORROWER_TYPES), $pick('decision', AT_DECISIONS), $lid]);
        audit((int)$user['id'], 'equipment.loan_details', 'client', (int)$cid, 'loan #' . $lid);
        flash('Loan details saved.');
    }
    redirect('at_act_report.php?fy=' . $fy . '#gaps-h');
}

$r = at_act_report($fy, caseload_scoped() ? (int)$user['id'] : null);
$label = fn(array $map, string $k) => $k === '' ? 'Not recorded' : ($map[$k] ?? $k);

if (isset($_GET['download'])) {
    audit((int)$user['id'], 'report.at_act', 'settings', null, 'FY ' . $fy);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="at-act-report-fy' . $fy . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    write_csv_row($out, ['Section', 'Measure', 'Category', 'Count']);
    foreach ($r['demos']['by_type'] as $k => $n) write_csv_row($out, ['Device demonstration', 'Demonstrations by AT type', $k, $n]);
    foreach ($r['demos']['participants'] as $k => $n) write_csv_row($out, ['Device demonstration', 'Participants', ['individual' => 'People with disabilities', 'family' => 'Family members or guardians'][$k] ?? 'Professionals: ' . DEMO_PROFESSIONALS[$k], $n]);
    foreach ($r['demos']['decision'] as $k => $n) write_csv_row($out, ['Device demonstration', 'Decisions', $label(AT_DECISIONS, (string)$k), $n]);
    foreach ($r['loans']['by_type'] as $k => $n) write_csv_row($out, ['Short-term device loan', 'Devices loaned by AT type', $k, $n]);
    foreach ($r['loans']['by_purpose'] as $k => $n) write_csv_row($out, ['Short-term device loan', 'Purpose', $label(LOAN_PURPOSES, (string)$k), $n]);
    foreach ($r['loans']['by_borrower'] as $k => $n) write_csv_row($out, ['Short-term device loan', 'Borrowers', $label(BORROWER_TYPES, (string)$k), $n]);
    foreach ($r['loans']['decision'] as $k => $n) write_csv_row($out, ['Short-term device loan', 'Decisions (loans to help decide)', $label(AT_DECISIONS, (string)$k), $n]);
    foreach ($r['reuse']['by_type'] as $k => $n) write_csv_row($out, ['Device reutilization', 'Devices reused by AT type', $k, $n]);
    write_csv_row($out, ['Device reutilization', 'Savings (estimated new cost)', 'All', number_format($r['reuse']['savings'], 2, '.', '')]);
    fclose($out);
    exit;
}

// Loans in the year still missing something the report needs.
$gq = db()->prepare("SELECT l.id, l.loaned_at, l.returned_at, l.purpose, l.borrower_type, l.decision, e.name AS device, c.name AS client_name, c.assigned_to
    FROM equipment_loans l JOIN equipment e ON e.id = l.equipment_id JOIN clients c ON c.id = l.client_id
    WHERE l.loaned_at BETWEEN ? AND ? AND (l.purpose IS NULL OR l.borrower_type IS NULL OR (l.purpose = 'decision' AND l.decision IS NULL AND l.returned_at IS NOT NULL))"
    . (caseload_scoped() ? ' AND c.assigned_to = ' . (int)$user['id'] : '') . ' ORDER BY l.loaned_at LIMIT 100');
$gq->execute([$r['from'], $r['to']]);
$gaps = $gq->fetchAll();

page_header('AT Act report', $user, 'analytics', [['analytics.php', 'Reports']]);
$table = function (string $caption, array $rows, ?array $map = null) use ($label): string {
    ob_start(); ?>
  <table class="table"><caption><?= h($caption) ?></caption>
    <thead><tr><th scope="col">Category</th><th scope="col">Count</th></tr></thead>
    <tbody><?php foreach ($rows as $k => $n): ?><tr><th scope="row"><?= h($map ? $label($map, (string)$k) : (string)$k) ?></th><td><?= (int)$n ?></td></tr><?php endforeach; ?></tbody>
  </table>
<?php return (string)ob_get_clean();
};
?>
<h1>AT Act report, federal fiscal year <?= $fy ?></h1>
<p><?= h(fmt_date($r['from'])) ?> to <?= h(fmt_date($r['to'])) ?>: device demonstrations, short-term device loans and device reuse, in the categories of the AT Act Annual Progress Report.<?= caseload_scoped() ? ' Loans are your own clients\' only.' : '' ?>
  <a href="at_act_report.php?fy=<?= $fy - 1 ?>">Fiscal year <?= $fy - 1 ?></a><?= $fy < at_act_fy() ? ' · <a href="at_act_report.php?fy=' . ($fy + 1) . '">Fiscal year ' . ($fy + 1) . '</a>' : '' ?>
  · <a class="btn btn-secondary btn-sm" href="at_act_report.php?fy=<?= $fy ?>&amp;download=1">Download as a spreadsheet (CSV)</a></p>

<section class="card" aria-labelledby="demo-h">
  <h2 id="demo-h">Device demonstrations (<?= $r['demos']['total'] ?>)</h2>
  <p>Recorded under AT services, Device demonstrations.</p>
  <?= $table('Demonstrations by AT type', $r['demos']['by_type']) ?>
  <?= $table('Participants', ['People with disabilities' => $r['demos']['participants']['individual'], 'Family members or guardians' => $r['demos']['participants']['family']] + array_combine(array_map(fn($k) => 'Professionals: ' . DEMO_PROFESSIONALS[$k], array_keys(DEMO_PROFESSIONALS)), array_map(fn($k) => $r['demos']['participants'][$k], array_keys(DEMO_PROFESSIONALS)))) ?>
  <?= $table('What was decided', $r['demos']['decision'], AT_DECISIONS) ?>
</section>

<section class="card" aria-labelledby="loan-h">
  <h2 id="loan-h">Short-term device loans (<?= $r['loans']['total'] ?>)</h2>
  <p><?= $r['loans']['still_out'] ?> of them are still out.</p>
  <?= $table('Devices loaned by AT type', $r['loans']['by_type']) ?>
  <?= $table('Why they were lent', $r['loans']['by_purpose'], LOAN_PURPOSES) ?>
  <?= $table('Who borrowed them', $r['loans']['by_borrower'], BORROWER_TYPES) ?>
  <?= $table('What was decided, for loans to help decide', $r['loans']['decision'], AT_DECISIONS) ?>
</section>

<section class="card" aria-labelledby="reuse-h">
  <h2 id="reuse-h">Device reuse (<?= $r['reuse']['total'] ?>)</h2>
  <p>Devices redeployed through AT services, Device reuse. Savings, at what they would cost new: <?= h(fmt_money($r['reuse']['savings'])) ?>.</p>
  <?= $table('Devices reused by AT type', $r['reuse']['by_type']) ?>
</section>

<section class="card" aria-labelledby="gaps-h">
  <h2 id="gaps-h" tabindex="-1">Loans to complete (<?= count($gaps) ?>)</h2>
  <?php if ($gaps && !can('equipment')): ?><p><?= count($gaps) ?> loans this year are missing details. Someone with equipment access completes them here.</p><?php $gaps = []; ?>
  <?php elseif (!$gaps): ?><p>None: every loan this year says why it was lent and who borrowed it, and every returned loan to help decide says what was decided.</p>
  <?php else: ?>
  <p>Fill in what each loan is missing. A device's AT type is set when it is added, or guessed from its category.</p>
  <?php foreach ($gaps as $g): $gid = (int)$g['id']; ?>
  <form method="post" action="at_act_report.php?fy=<?= $fy ?>" class="stacked"><?= csrf_field() ?>
    <fieldset><legend><?= h($g['device']) ?>, lent to <?= h($g['client_name']) ?> on <?= h(fmt_date($g['loaned_at'])) ?></legend>
    <?php if ($g['purpose'] === null): ?><div class="field"><label for="p-<?= $gid ?>">Why it was lent</label><select id="p-<?= $gid ?>" name="purpose_<?= $gid ?>"><option value="">Not recorded</option><?php foreach (LOAN_PURPOSES as $k => $v): ?><option value="<?= $k ?>"><?= h($v) ?></option><?php endforeach; ?></select></div><?php endif; ?>
    <?php if ($g['borrower_type'] === null): ?><div class="field"><label for="b-<?= $gid ?>">Who borrowed it</label><select id="b-<?= $gid ?>" name="borrower_<?= $gid ?>"><option value="">Not recorded</option><?php foreach (BORROWER_TYPES as $k => $v): ?><option value="<?= $k ?>"><?= h($v) ?></option><?php endforeach; ?></select></div><?php endif; ?>
    <?php if ($g['decision'] === null && ($g['purpose'] === null || $g['purpose'] === 'decision')): ?><div class="field"><label for="d-<?= $gid ?>">What was decided, if lent to help decide</label><select id="d-<?= $gid ?>" name="decision_<?= $gid ?>"><option value="">Not recorded</option><?php foreach (AT_DECISIONS as $k => $v): ?><option value="<?= $k ?>"><?= h($v) ?></option><?php endforeach; ?></select></div><?php endif; ?>
    <button type="submit" name="save_loan" value="<?= $gid ?>" class="btn btn-secondary btn-sm">Save<span class="visually-hidden"> — <?= h($g['device']) ?></span></button>
    </fieldset>
  </form>
  <?php endforeach; ?>
  <?php endif; ?>
</section>
<?php page_footer();
