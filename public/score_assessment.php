<?php
// Administer or view a structured, scored assessment for a client.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
csrf_check();
$user = require_login();
require_perm('clients');

$cid = (int)($_GET['client'] ?? 0);
$cstmt = db()->prepare('SELECT * FROM clients WHERE id = ?');
$cstmt->execute([$cid]);
$client = $cstmt->fetch();
if (!$client) { flash('Client not found.', 'error'); redirect('clients.php'); }
scope_check_client($client, $user);

/* View a completed scored assessment */
if (!empty($_GET['scored'])) {
    $sa = db()->prepare('SELECT sa.*, i.name AS instrument, i.scale_min, i.scale_max, i.scale_labels, u.name AS assessor FROM client_scored_assessments sa
        JOIN assessment_instruments i ON i.id = sa.instrument_id LEFT JOIN users u ON u.id = sa.assessor_id
        WHERE sa.id = ? AND sa.client_id = ?');
    $sa->execute([(int)$_GET['scored'], $cid]);
    $rec = $sa->fetch();
    if (!$rec) { flash('Assessment not found.', 'error'); redirect('client.php?id=' . $cid . '&tab=assessments'); }
    $its = db()->prepare('SELECT it.label, si.score FROM client_scored_assessment_items si
        JOIN assessment_instrument_items it ON it.id = si.item_id WHERE si.scored_id = ? ORDER BY it.sort, it.id');
    $its->execute([(int)$rec['id']]);
    $items = $its->fetchAll();
    $scaleOpts = instrument_scale($rec);
    $pct = round((float)$rec['pct']);
    // The percentage runs from the lowest possible total (items x the scale's
    // lowest rating), so a scale that does not start at 0 shows its whole range.
    $recMin = (int)$rec['scale_min'] < (int)$rec['scale_max'] ? (int)$rec['scale_min'] : 0;
    $recLow = count($items) * $recMin;
    // Named for the instrument and client, so two open tabs can be told apart.
    page_header('Scored assessment: ' . $rec['instrument'] . ' — ' . $client['name'], $user, 'clients');
    ?>
    <p><a href="client.php?id=<?= $cid ?>&tab=assessments"><span aria-hidden="true">&larr; </span><?= h($client['name']) ?></a></p>
    <h1><?= h($rec['instrument']) ?></h1>
    <p class="muted"><?= h($client['name']) ?> · <?= fmt_date($rec['assessed_on']) ?> · by <?= h($rec['assessor'] ?? 'Unknown') ?></p>
    <div class="progress-wrap card">
      <label for="sa-score" class="progress-caption"><strong>Score:</strong> <?= (int)$rec['total_score'] ?> <?= $recLow === 0 ? 'of ' . (int)$rec['max_score'] : 'on a range of ' . $recLow . ' to ' . (int)$rec['max_score'] ?> (<?= $pct ?>%)</label>
      <progress id="sa-score" class="hours" max="100" value="<?= $pct ?>"><?= $pct ?>%</progress>
    </div>
    <table><caption class="visually-hidden">Ratings for <?= h($rec['instrument']) ?>, <?= fmt_date($rec['assessed_on']) ?></caption>
    <thead><tr><th scope="col">Skill</th><th scope="col">Score</th></tr></thead><tbody>
    <?php foreach ($items as $it): ?><tr><th scope="row"><?= h($it['label']) ?></th><td><?= h($scaleOpts[(int)$it['score']] ?? $it['score']) ?></td></tr><?php endforeach; ?>
    </tbody></table>
    <?php if ($rec['notes']): ?><h2>Notes</h2><p style="white-space:pre-wrap"><?= h($rec['notes']) ?></p><?php endif; ?>
    <?php page_footer(); exit;
}

/* Administer: choose instrument, then rate each item */
$iid = (int)($_GET['instrument'] ?? 0);
$instrument = null;
$saving = $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_scored']);
if ($iid) {
    // Ratings already typed are saved even if the instrument was retired while
    // they were being entered; only new sessions need an active instrument.
    $iq = db()->prepare('SELECT * FROM assessment_instruments WHERE id = ?' . ($saving ? '' : ' AND active = 1'));
    $iq->execute([$iid]);
    $instrument = $iq->fetch() ?: null;
}
if ($saving && !$instrument) {
    flash('That instrument no longer exists, so the assessment was not saved. Choose an instrument and enter the ratings again.', 'error');
    redirect('score_assessment.php?client=' . $cid);
}

if ($saving) {
    $items = db()->prepare('SELECT id, label FROM assessment_instrument_items WHERE instrument_id = ? ORDER BY sort, id');
    $items->execute([$iid]);
    $itemRows = $items->fetchAll();
    $sMin = (int)$instrument['scale_min']; $sMax = (int)$instrument['scale_max'];
    if ($sMax <= $sMin) { $sMin = 0; $sMax = 4; }
    $total = 0; $n = count($itemRows); $max = $n * $sMax; $scores = [];
    $saErrs = [];
    $onRaw = trim(is_string($_POST['assessed_on'] ?? null) ? $_POST['assessed_on'] : '');
    $on = valid_date($onRaw, 2000, (int)date('Y'));
    if ($onRaw === '') $saErrs['assessed_on'] = 'Assessment date is required.';
    elseif (!$on) $saErrs['assessed_on'] = 'Enter the assessment date as a valid date (YYYY-MM-DD) from the year 2000 onwards.';
    // Scored results cannot be edited later, and the newest is read as current.
    elseif ($on > date('Y-m-d')) $saErrs['assessed_on'] = 'The assessment date cannot be in the future. Check the year.';
    // Every item needs a rating chosen on purpose: a skipped item is never
    // saved as the lowest score.
    $posted = is_array($_POST['item'] ?? null) ? $_POST['item'] : [];
    foreach ($itemRows as $itRow) {
        $itId = (int)$itRow['id'];
        $raw = is_string($posted[$itId] ?? null) ? trim($posted[$itId]) : '';
        if ($raw === '' || !preg_match('/^-?\d{1,3}$/', $raw) || (int)$raw < $sMin || (int)$raw > $sMax) {
            $saErrs['it-' . $itId] = 'Choose a rating for "' . $itRow['label'] . '".';
            continue;
        }
        $scores[$itId] = (int)$raw; $total += (int)$raw;
    }
    $notes = trim(is_string($_POST['notes'] ?? null) ? $_POST['notes'] : '');
    if (strlen($notes) > 65535) $saErrs['notes'] = 'Notes are too long (65,535 bytes maximum; accented letters and symbols count as 2 to 4 bytes each).';
    if ($saErrs) {
        // Shown on this same page below, with everything as it was entered.
        set_form_errors($saErrs);
    } else {
        $pct = scored_pct($total, $n, $sMin, $sMax);
        db()->beginTransaction();
        try {
            db()->prepare('INSERT INTO client_scored_assessments (client_id, instrument_id, assessed_on, assessor_id, notes, total_score, max_score, pct) VALUES (?,?,?,?,?,?,?,?)')
                ->execute([$cid, $iid, $on, $user['id'], $notes !== '' ? $notes : null, $total, $max, $pct]);
            $sid = (int)db()->lastInsertId();
            $ins = db()->prepare('INSERT INTO client_scored_assessment_items (scored_id, item_id, score) VALUES (?,?,?)');
            foreach ($scores as $itId => $sc) $ins->execute([$sid, $itId, $sc]);
            db()->commit();
        } catch (Throwable $e) { db()->rollBack(); throw $e; }
        audit($user['id'], 'assessment.scored', 'client', $cid, $instrument['name'] . ': ' . $total . '/' . $max);
        flash('Scored assessment saved (' . $total . ($n * $sMin === 0 ? ' of ' . $max : ' on a range of ' . ($n * $sMin) . ' to ' . $max) . ', ' . round($pct) . '%).'
            . (empty($instrument['active']) ? ' This instrument has since been retired, so it is no longer offered for new assessments.' : ''));
        redirect('client.php?id=' . $cid . '&tab=assessments');
    }
}

page_header('Scored assessment' . ($instrument ? ': ' . $instrument['name'] : '') . ' — ' . $client['name'], $user, 'clients');
?>
<p><a href="client.php?id=<?= $cid ?>&tab=assessments"><span aria-hidden="true">&larr; </span><?= h($client['name']) ?></a></p>
<h1>Scored assessment — <?= h($client['name']) ?></h1>

<?php if (!$instrument): ?>
<section class="card">
  <h2>Choose an instrument</h2>
  <?php $insts = db()->query('SELECT i.*, (SELECT COUNT(*) FROM assessment_instrument_items WHERE instrument_id = i.id) AS n FROM assessment_instruments i WHERE i.active = 1 ORDER BY i.name')->fetchAll(); ?>
  <?php if (!$insts): ?><p class="muted">No instruments defined yet. An administrator can add them on the Templates page.</p><?php endif; ?>
  <ul>
  <?php foreach ($insts as $ins): ?>
    <?php // Not the list item's only child, so the shared 44px link rule does not reach it. ?>
    <li><a href="score_assessment.php?client=<?= $cid ?>&instrument=<?= (int)$ins['id'] ?>" style="display:inline-flex;align-items:center;min-height:2.75rem"><?= h($ins['name']) ?></a>
      <span class="muted"><?= (int)$ins['n'] ?> items</span><?= $ins['description'] ? '<br><span class="muted">' . h($ins['description']) . '</span>' : '' ?></li>
  <?php endforeach; ?>
  </ul>
</section>
<?php else: ?>
<?php
$items = db()->prepare('SELECT * FROM assessment_instrument_items WHERE instrument_id = ? ORDER BY sort, id');
$items->execute([$iid]);
$items = $items->fetchAll();
?>
<?php $scaleOpts = instrument_scale($instrument); $sMin = (int)$instrument['scale_min']; $sMax = (int)$instrument['scale_max']; if ($sMax <= $sMin) { $sMin = 0; $sMax = 4; }
// After a refused save the form shows what was entered; otherwise nothing is preselected.
$postedItems = $saving && is_array($_POST['item'] ?? null) ? $_POST['item'] : [];
$errLabels = ['assessed_on' => 'Assessment date', 'notes' => 'Notes'];
foreach ($items as $it) $errLabels['it-' . (int)$it['id']] = '';
?>
<section class="card">
  <h2><?= h($instrument['name']) ?></h2>
  <?php if ($instrument['description']): ?><p class="muted"><?= h($instrument['description']) ?></p><?php endif; ?>
  <form method="post" action="score_assessment.php?client=<?= $cid ?>&instrument=<?= $iid ?>" class="stacked" novalidate>
    <?= csrf_field() ?>
    <?= form_error_summary($errLabels) ?>
    <div class="field"><label for="assessed_on">Assessment date <span aria-hidden="true">*</span><span class="visually-hidden"> (required)</span></label>
      <input type="date" id="assessed_on" name="assessed_on" required max="<?= date('Y-m-d') ?>" value="<?= h($saving ? (is_string($_POST['assessed_on'] ?? null) ? $_POST['assessed_on'] : '') : date('Y-m-d')) ?>" <?= field_aria('assessed_on') ?>>
      <?= field_error('assessed_on') ?></div>
    <p class="hint">Choose a rating for every item.</p>
    <table>
      <caption class="visually-hidden">Ratings for <?= h($instrument['name']) ?></caption>
      <thead><tr><th scope="col">Item</th><th scope="col">Rating (<?= $sMin ?>–<?= $sMax ?>)</th></tr></thead>
      <tbody>
      <?php foreach ($items as $it): ?>
        <tr>
          <th scope="row"><label for="it-<?= (int)$it['id'] ?>"><?= h($it['label']) ?></label></th>
          <td><?php $picked = is_string($postedItems[(int)$it['id']] ?? null) ? $postedItems[(int)$it['id']] : ''; ?>
            <select id="it-<?= (int)$it['id'] ?>" name="item[<?= (int)$it['id'] ?>]" required <?= field_aria('it-' . (int)$it['id']) ?>>
            <option value="" <?= $picked === '' ? 'selected' : '' ?>>— Choose a rating —</option>
            <?php foreach ($scaleOpts as $v => $lab): ?><option value="<?= $v ?>" <?= $picked !== '' && (string)$v === $picked ? 'selected' : '' ?>><?= h($lab) ?></option><?php endforeach; ?>
          </select><?= field_error('it-' . (int)$it['id']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <div class="field"><label for="notes">Notes</label><textarea id="notes" name="notes" rows="3" <?= field_aria('notes') ?>><?= h($saving && is_string($_POST['notes'] ?? null) ? $_POST['notes'] : '') ?></textarea>
      <?= field_error('notes') ?></div>
    <button type="submit" name="save_scored" value="1" class="btn btn-primary">Save scored assessment</button>
  </form>
</section>
<?php endif; ?>
<?php page_footer();
