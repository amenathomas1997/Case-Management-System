<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
csrf_check();
$user = require_login();
require_perm('templates');

/** A refused add form: keep what was typed and name each bad field (the page's
 *  error summary takes focus and links to it), then return to that form. */
function tpl_refuse(array $errors, string $anchor, string $lib): never {
    keep_input();
    keep_field_errors($errors);
    redirect('templates.php?lib=' . $lib . '#' . $anchor);
}
/** A posted text field, trimmed; a value that is not a string (name[]=) reads as blank. */
function tpl_text(string $key): string {
    return trim(is_string($_POST[$key] ?? null) ? $_POST[$key] : '');
}
/** The same add form sent twice (Enter pressed again while it loads) saves once:
 *  the second answer goes back to the library the first was saved in. */
function tpl_once(?string $key, string $what, string $back): void {
    if (web_once_get($key) === null) return;
    flash('This ' . $what . ' was already added (the form was sent twice), so it was saved once.', 'info');
    redirect($back);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_goal_tpl'])) {
    $cat = tpl_text('category');
    $goal = tpl_text('specific_goal');
    $metric = tpl_text('measurable_metric');
    $focus = tpl_text('focus_area');
    // Lengths match the columns: too long is explained, never cut short or a failed save.
    $errs = [];
    if ($cat === '') $errs['category'] = 'A category is required.';
    elseif (mb_strlen($cat) > 80) $errs['category'] = 'Keep the category to 80 characters or fewer.';
    if ($goal === '') $errs['specific_goal'] = 'A specific objective is required.';
    if ($metric === '') $errs['measurable_metric'] = 'Measurable criteria are required.';
    if (mb_strlen($focus) > 190) $errs['focus_area'] = 'Keep the focus area to 190 characters or fewer.';
    if ($errs) tpl_refuse($errs, 'add-goal-tpl-h', 'goals');
    $tplKey = web_idem_key($_POST);
    tpl_once($tplKey, 'goal template', 'templates.php?lib=goals');
    db()->prepare('INSERT INTO goal_templates (category, specific_goal, measurable_metric, focus_area) VALUES (?,?,?,?)')
        ->execute([$cat, $goal, $metric, $focus ?: null]);
    // Capture the id before audit() inserts its own row and replaces it.
    $newId = (int)db()->lastInsertId();
    web_once_set($tplKey, $newId);
    audit($user['id'], 'goal_template.add', 'goal_template', $newId, $cat . ': ' . $goal);
    flash('Goal template added.');
    redirect('templates.php?lib=goals');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['del_goal_tpl'])) {
    $gtId = (int)($_POST['id'] ?? 0);
    $gtRow = db()->prepare('SELECT specific_goal FROM goal_templates WHERE id = ?');
    $gtRow->execute([$gtId]);
    $gtLabel = $gtRow->fetchColumn();
    $gtDel = db()->prepare('DELETE FROM goal_templates WHERE id = ?');
    $gtDel->execute([$gtId]);
    // Only a row really removed is audited and reported as deleted; a stale page or
    // a double submit is told it is already gone.
    if ($gtDel->rowCount() === 1) {
        audit($user['id'], 'goal_template.delete', 'goal_template', $gtId, is_string($gtLabel) ? $gtLabel : null);
        flash('Goal template deleted.');
    } else {
        flash('That goal template no longer exists. It may already have been deleted.', 'error');
    }
    // Back to the same search, so the next match is where the user left off.
    $gtBack = ['lib' => 'goals'];
    foreach (['gt_q', 'gt_cat'] as $gtKey) {
        $gtVal = trim(is_string($_POST[$gtKey] ?? null) ? $_POST[$gtKey] : '');
        if ($gtVal !== '') $gtBack[$gtKey] = mb_substr($gtVal, 0, 190);
    }
    redirect('templates.php?' . http_build_query($gtBack) . '#goals-h');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_assess_tpl'])) {
    $name = tpl_text('name');
    $hardware = tpl_text('hardware_details');
    $software = tpl_text('software_version');
    $errs = [];
    if ($name === '') $errs['name'] = 'A template name is required.';
    elseif (mb_strlen($name) > 190) $errs['name'] = 'Keep the template name to 190 characters or fewer.';
    if (mb_strlen($hardware) > 255) $errs['hardware_details'] = 'Keep the hardware details to 255 characters or fewer.';
    if (mb_strlen($software) > 190) $errs['software_version'] = 'Keep the software and version to 190 characters or fewer.';
    if ($errs) tpl_refuse($errs, 'add-assess-tpl-h', 'assessments');
    $tplKey = web_idem_key($_POST);
    tpl_once($tplKey, 'assessment template', 'templates.php?lib=assessments');
    $tech = in_array($_POST['primary_tech'] ?? '', cms_list_items('tech_types'), true) ? $_POST['primary_tech'] : 'Screen Reader';
    db()->prepare('INSERT INTO assessment_templates (name, primary_tech, hardware_details, software_version, summary) VALUES (?,?,?,?,?)')
        ->execute([$name, $tech, $hardware ?: null, $software ?: null, tpl_text('summary') ?: null]);
    $newId = (int)db()->lastInsertId();
    web_once_set($tplKey, $newId);
    audit($user['id'], 'assessment_template.add', 'assessment_template', $newId, $name);
    flash('Assessment template added.');
    redirect('templates.php?lib=assessments');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['del_assess_tpl'])) {
    $atId = (int)($_POST['id'] ?? 0);
    $atRow = db()->prepare('SELECT name FROM assessment_templates WHERE id = ?');
    $atRow->execute([$atId]);
    $atLabel = $atRow->fetchColumn();
    $atDel = db()->prepare('DELETE FROM assessment_templates WHERE id = ?');
    $atDel->execute([$atId]);
    if ($atDel->rowCount() === 1) {
        audit($user['id'], 'assessment_template.delete', 'assessment_template', $atId, is_string($atLabel) ? $atLabel : null);
        flash('Assessment template deleted.');
    } else {
        flash('That assessment template no longer exists. It may already have been deleted.', 'error');
    }
    redirect('templates.php?lib=assessments');
}

// Each library is its own view (?lib=), so a screen reader user lands on one
// table and one add form rather than all four. A bare templates.php is the goal library.
$libs = ['goals' => 'SMART goal templates', 'assessments' => 'Assessment templates', 'bundles' => 'Service bundles', 'instruments' => 'Scored assessment instruments'];
$lib = is_string($_GET['lib'] ?? null) ? $_GET['lib'] : 'goals';   // an array is ignored (4.27.0)
if (!isset($libs[$lib])) $lib = 'goals';
/** True when the refused add form names one of these fields, so its <details> opens. */
$tplFormFailed = fn(array $fields): bool => array_intersect_key(form_errors(), array_flip($fields)) !== [];

// The goal library can be very large, so it is searched on demand rather than
// rendered in full: no rows until a search or an area is chosen. gt_q / gt_cat
// drive a server-side filter; results are capped.
$gtQ = trim(is_string($_GET['gt_q'] ?? null) ? $_GET['gt_q'] : '');
$gtCat = trim(is_string($_GET['gt_cat'] ?? null) ? $_GET['gt_cat'] : '');
$gtFiltered = $gtQ !== '' || $gtCat !== '';
$goalTpls = []; $gtTruncated = false; $goalTplTotal = 0; $goalCats = [];
if ($lib === 'goals') {
    if ($gtFiltered) {
        $gtWhere = []; $gtArgs = [];
        if ($gtCat !== '') { $gtWhere[] = 'category = ?'; $gtArgs[] = $gtCat; }
        // Each word must match somewhere, so multi-word searches narrow the list.
        foreach (array_slice(preg_split('/\s+/', $gtQ, -1, PREG_SPLIT_NO_EMPTY) ?: [], 0, 6) as $gtWord) {
            $gtWhere[] = '(specific_goal LIKE ? OR category LIKE ? OR measurable_metric LIKE ? OR focus_area LIKE ?)';
            $like = '%' . $gtWord . '%'; array_push($gtArgs, $like, $like, $like, $like);
        }
        $gtSql = 'SELECT * FROM goal_templates' . ($gtWhere ? ' WHERE ' . implode(' AND ', $gtWhere) : '')
            . ' ORDER BY category, specific_goal LIMIT 201';
        $gtStmt = db()->prepare($gtSql); $gtStmt->execute($gtArgs);
        $goalTpls = $gtStmt->fetchAll();
        $gtTruncated = count($goalTpls) > 200; if ($gtTruncated) array_pop($goalTpls);
    }
    $goalTplTotal = (int)db()->query('SELECT COUNT(*) FROM goal_templates')->fetchColumn();
    $goalCats = db()->query('SELECT category, COUNT(*) n FROM goal_templates GROUP BY category ORDER BY category')->fetchAll();
}

$assessTpls = $lib === 'assessments' ? db()->query('SELECT * FROM assessment_templates ORDER BY name')->fetchAll() : [];

/* ---- Service bundles ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_bundle'])) {
    $name = tpl_text('bundle_name');
    $bDesc = tpl_text('bundle_description');
    $ctype = in_array($_POST['bundle_client_type'] ?? '', array_merge(cms_list_items('client_types'), ['Any']), true) ? $_POST['bundle_client_type'] : 'Any';
    $cats = array_values(array_intersect(array_filter((array)($_POST['bundle_categories'] ?? []), 'is_string'), rsa_all_categories()));
    $errs = [];
    if ($name === '') $errs['bundle_name'] = 'A bundle name is required.';
    elseif (mb_strlen($name) > 120) $errs['bundle_name'] = 'Keep the bundle name to 120 characters or fewer.';
    if (mb_strlen($bDesc) > 255) $errs['bundle_description'] = 'Keep the description to 255 characters or fewer.';
    if (!$cats) $errs['bundle_categories'] = 'Tick at least one RSA category for the bundle.';
    if ($errs) {
        // The ticked categories are a list, which keep_input() leaves out.
        $_SESSION['tpl_bundle_cats'] = $cats;
        tpl_refuse($errs, 'add-bundle-h', 'bundles');
    }
    $tplKey = web_idem_key($_POST);
    tpl_once($tplKey, 'service bundle', 'templates.php?lib=bundles#bundles-h');
    db()->prepare('INSERT INTO service_bundles (name, client_type, categories, description) VALUES (?,?,?,?)')
        ->execute([$name, $ctype, implode(';', $cats), $bDesc ?: null]);
    $newId = (int)db()->lastInsertId();
    web_once_set($tplKey, $newId);
    audit($user['id'], 'bundle.add', 'bundle', $newId, $name);
    flash('Service bundle added.');
    redirect('templates.php?lib=bundles#bundles-h');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_bundle'])) {
    // The button posts the state it asks for, so a stale page or a double submit
    // can never flip the bundle back; only a real change is audited.
    $bid = (int)($_POST['id'] ?? 0);
    $to = $_POST['to'] ?? '';
    if (!in_array($to, ['active', 'retired'], true)) { flash('That request was incomplete — reload the page and try again.', 'error'); redirect('templates.php?lib=bundles#bundles-h'); }
    $active = $to === 'active' ? 1 : 0;
    $bn = db()->prepare('SELECT name, active FROM service_bundles WHERE id = ?');
    $bn->execute([$bid]);
    $b = $bn->fetch();
    if (!$b) { flash('That service bundle no longer exists.', 'error'); redirect('templates.php?lib=bundles#bundles-h'); }
    $up = db()->prepare('UPDATE service_bundles SET active = ? WHERE id = ? AND active <> ?');
    $up->execute([$active, $bid, $active]);
    if ($up->rowCount() === 1) {
        audit($user['id'], 'bundle.toggle', 'bundle', $bid, ($active ? 'reactivated: ' : 'retired: ') . $b['name']);
        flash($active
            ? 'Bundle “' . $b['name'] . '” reactivated — it is offered for new tracks again.'
            : 'Bundle “' . $b['name'] . '” retired — it is no longer offered for new tracks. Tracks that already use it keep it until someone chooses a different bundle.');
    } else {
        flash('Bundle “' . $b['name'] . '” was already ' . ($active ? 'active' : 'retired') . ' — nothing changed.');
    }
    redirect('templates.php?lib=bundles#bundles-h');
}

/* ---- Scored assessment instruments ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_instrument'])) {
    $name = tpl_text('instrument_name');
    $desc = tpl_text('instrument_desc') ?: null;
    // The scale is used exactly as entered: a bad one is refused, never replaced.
    $range = ['options' => ['min_range' => -10, 'max_range' => 10]];
    $sMin = filter_var(trim((string)($_POST['scale_min'] ?? '')), FILTER_VALIDATE_INT, $range);
    $sMax = filter_var(trim((string)($_POST['scale_max'] ?? '')), FILTER_VALIDATE_INT, $range);
    $sLabels = tpl_text('scale_labels') ?: null;
    $lines = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', (string)($_POST['instrument_items'] ?? ''))), fn($l) => $l !== ''));
    // Which way is an improvement: the funder report counts a gain in this direction.
    $direction = $_POST['higher_is_better'] ?? '';
    $errs = [];
    if ($name === '') $errs['instrument_name'] = 'An instrument name is required.';
    elseif (mb_strlen($name) > 190) $errs['instrument_name'] = 'Keep the name to 190 characters or fewer.';
    if ($desc !== null && mb_strlen($desc) > 500) $errs['instrument_desc'] = 'Keep the description to 500 characters or fewer.';
    if ($sMin === false) $errs['scale_min'] = 'Enter the lowest rating as a whole number from -10 to 10.';
    if ($sMax === false) $errs['scale_max'] = 'Enter the highest rating as a whole number from -10 to 10.';
    elseif ($sMin !== false && $sMax <= $sMin) $errs['scale_max'] = 'The highest rating must be greater than the lowest rating.';
    if ($sLabels !== null && mb_strlen($sLabels) > 600) $errs['scale_labels'] = 'Keep the rating labels to 600 characters or fewer.';
    if ($direction !== '1' && $direction !== '0') $errs['higher_is_better'] = 'Choose whether a higher rating means better or worse.';
    if (!$lines) $errs['instrument_items'] = 'Add at least one item, one per line.';
    foreach ($lines as $n => $lab) {
        if (mb_strlen($lab) > 255) { $errs['instrument_items'] = 'Item ' . ($n + 1) . ' is longer than 255 characters — shorten it or split it into two items.'; break; }
    }
    if ($errs) tpl_refuse($errs, 'add-instrument-h', 'instruments');
    $tplKey = web_idem_key($_POST);
    tpl_once($tplKey, 'instrument', 'templates.php?lib=instruments#instr-h');
    db()->beginTransaction();
    try {
        db()->prepare('INSERT INTO assessment_instruments (name, description, scale_min, scale_max, scale_labels, higher_is_better) VALUES (?,?,?,?,?,?)')->execute([$name, $desc, $sMin, $sMax, $sLabels, (int)$direction]);
        $iid = (int)db()->lastInsertId();
        $ins = db()->prepare('INSERT INTO assessment_instrument_items (instrument_id, label, sort) VALUES (?,?,?)');
        foreach ($lines as $n => $lab) $ins->execute([$iid, $lab, $n + 1]);
        db()->commit();
    } catch (Throwable $e) { db()->rollBack(); throw $e; }
    web_once_set($tplKey, $iid);
    audit($user['id'], 'instrument.add', 'instrument', $iid, $name . ' (' . count($lines) . ' items, ' . ($direction === '1' ? 'higher' : 'lower') . ' is better)');
    flash('Assessment instrument added.');
    redirect('templates.php?lib=instruments#instr-h');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_instrument'])) {
    // Explicit target state, as for bundles: a stale page can't flip it back.
    $iid = (int)($_POST['id'] ?? 0);
    $to = $_POST['to'] ?? '';
    if (!in_array($to, ['active', 'inactive'], true)) { flash('That request was incomplete — reload the page and try again.', 'error'); redirect('templates.php?lib=instruments#instr-h'); }
    $active = $to === 'active' ? 1 : 0;
    $in = db()->prepare('SELECT name FROM assessment_instruments WHERE id = ?');
    $in->execute([$iid]);
    $iname = $in->fetchColumn();
    if ($iname === false) { flash('That instrument no longer exists.', 'error'); redirect('templates.php?lib=instruments#instr-h'); }
    $up = db()->prepare('UPDATE assessment_instruments SET active = ? WHERE id = ? AND active <> ?');
    $up->execute([$active, $iid, $active]);
    if ($up->rowCount() === 1) {
        audit($user['id'], 'instrument.toggle', 'instrument', $iid, ($active ? 'activated: ' : 'deactivated: ') . $iname);
        flash('Instrument “' . $iname . '” ' . ($active ? 'activated — staff can administer it again.' : 'deactivated — it is no longer offered for new assessments; completed assessments keep it.'));
    } else {
        flash('Instrument “' . $iname . '” was already ' . ($active ? 'active' : 'inactive') . ' — nothing changed.');
    }
    redirect('templates.php?lib=instruments#instr-h');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['del_instrument'])) {
    $iid = (int)($_POST['id'] ?? 0);
    $in = db()->prepare('SELECT name FROM assessment_instruments WHERE id = ?');
    $in->execute([$iid]);
    $iname = $in->fetchColumn();
    $used = db()->prepare('SELECT COUNT(*) FROM client_scored_assessments WHERE instrument_id = ?');
    $used->execute([$iid]);
    if ($iname === false) {
        flash('That instrument no longer exists.', 'error');
    } elseif ((int)$used->fetchColumn() > 0) {
        flash('This instrument has completed assessments on client records, so it can only be deactivated, not deleted.', 'error');
    } else {
        // Only a row that was really removed is audited and reported (a double
        // submit or a stale page deletes nothing).
        $del = db()->prepare('DELETE FROM assessment_instruments WHERE id = ?');
        $del->execute([$iid]);
        if ($del->rowCount() === 1) {
            audit($user['id'], 'instrument.delete', 'instrument', $iid, $iname);
            flash('Instrument “' . $iname . '” deleted.');
        } else {
            flash('That instrument no longer exists.', 'error');
        }
    }
    redirect('templates.php?lib=instruments#instr-h');
}
$instruments = $lib === 'instruments' ? db()->query('SELECT i.*, (SELECT COUNT(*) FROM assessment_instrument_items WHERE instrument_id = i.id) AS n,
    (SELECT COUNT(*) FROM client_scored_assessments WHERE instrument_id = i.id) AS used FROM assessment_instruments i ORDER BY i.name')->fetchAll() : [];

$bundles = $lib === 'bundles' ? db()->query('SELECT * FROM service_bundles ORDER BY active DESC, FIELD(client_type, \'VR\', \'Pre-ETS\', \'Any\') = 0, FIELD(client_type, \'VR\', \'Pre-ETS\', \'Any\'), client_type, name')->fetchAll() : [];
// Categories ticked on an "Add bundle" form that was refused (see add_bundle).
$keptBundleCats = is_array($_SESSION['tpl_bundle_cats'] ?? null) ? $_SESSION['tpl_bundle_cats'] : [];
unset($_SESSION['tpl_bundle_cats']);

page_header($libs[$lib] . ' — Goal & assessment templates', $user, 'templates');
?>
<h1>Goal &amp; assessment templates</h1>
<p class="muted">Reusable SMART goals and assessment structures. Apply them from a training track's goal form or a client's assessment form.</p>
<div class="page-actions"><a class="btn btn-secondary btn-sm" href="curriculum.php">Training curricula</a></div>
<nav aria-label="Template libraries">
  <ul class="tab-nav">
    <?php foreach ($libs as $libKey => $libLabel): ?>
    <li><a href="templates.php?lib=<?= h($libKey) ?>" <?= $lib === $libKey ? 'aria-current="page"' : '' ?>><?= h($libLabel) ?></a></li>
    <?php endforeach; ?>
  </ul>
</nav>

<?php if ($lib === 'goals'): ?>
<section id="goals" class="card" aria-labelledby="goals-h">
  <h2 id="goals-h">SMART goal templates (<?= $goalTplTotal ?>)</h2>
  <?php // The fragment brings a search back to this section; the count below then takes focus. ?>
  <form method="get" action="templates.php#goals-h" class="grid grid-2" role="search" aria-label="Search goal templates">
    <input type="hidden" name="lib" value="goals">
    <div class="field">
      <label for="gt-cat">Area</label>
      <select id="gt-cat" name="gt_cat">
        <option value="">All areas (<?= $goalTplTotal ?>)</option>
        <?php foreach ($goalCats as $gc): ?><option value="<?= h($gc['category']) ?>" <?= $gtCat === $gc['category'] ? 'selected' : '' ?>><?= h($gc['category']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label for="gt-search">Search</label>
      <div class="inline-form">
        <input type="search" id="gt-search" name="gt_q" value="<?= h($gtQ) ?>" placeholder="e.g. JAWS email, cane travel, Excel" autocomplete="off">
        <button type="submit" class="btn btn-secondary btn-sm">Search</button>
        <?php if ($gtFiltered): ?><a class="btn btn-secondary btn-sm" href="templates.php?lib=goals#goals-h">Clear goal template filters</a><?php endif; ?>
      </div>
    </div>
  </form>
  <?php // A search reloads the page, so a live region (already filled at load) would not
        // be announced: after a search the count is focused once instead. ?>
  <p class="hint" id="gt-count" tabindex="-1">
    <?php if ($gtFiltered): ?>
      <?= $gtTruncated ? 'Showing the first 200 matches — refine your search to narrow.' : (count($goalTpls) . ' ' . (count($goalTpls) === 1 ? 'match' : 'matches')) ?>
    <?php else: ?>
      There <?= $goalTplTotal === 1 ? 'is 1 goal template' : 'are ' . $goalTplTotal . ' goal templates' ?>. Search or choose an area to list them.
    <?php endif; ?>
  </p>
  <?php if ($gtFiltered): ?>
  <script>document.addEventListener('DOMContentLoaded',function(){if(document.getElementById('flash-messages')||document.getElementById('form-error-summary'))return;var c=document.getElementById('gt-count');if(c)c.focus();});</script>
  <div class="table-scroll" tabindex="0" role="region" aria-labelledby="goals-cap">
  <table id="goal-tpl-table">
    <caption class="visually-hidden" id="goals-cap">SMART goal templates by category</caption>
    <thead><tr><th scope="col">Category</th><th scope="col">Objective</th><th scope="col">Measurable criteria</th><th scope="col">Focus</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead>
    <tbody>
    <?php if (!$goalTpls): ?><tr><td colspan="5" class="muted">No templates match — try different words.</td></tr><?php endif; ?>
    <?php foreach ($goalTpls as $g):
      // Many objectives share their opening words, so the button and the confirmation
      // name the whole objective and its category.
      $gtName = $g['category'] . ': ' . $g['specific_goal']; ?>
      <tr>
        <td><?= h($g['category']) ?></td>
        <th scope="row"><?= h($g['specific_goal']) ?></th>
        <td><?= h($g['measurable_metric']) ?></td>
        <td><?= h($g['focus_area'] ?? '—') ?></td>
        <td>
          <form method="post" action="templates.php" class="inline-form"><?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int)$g['id'] ?>">
            <input type="hidden" name="gt_q" value="<?= h($gtQ) ?>">
            <input type="hidden" name="gt_cat" value="<?= h($gtCat) ?>">
            <button type="submit" name="del_goal_tpl" value="1" class="btn btn-danger btn-sm"
              onclick="return confirm(<?= h((string)json_encode('Delete the goal template "' . $gtName . '" permanently? This cannot be undone.', JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)) ?>);">Delete permanently<span class="visually-hidden"> — goal template <?= h($gtName) ?></span></button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
  <h3 class="visually-hidden">Add a goal template</h3>
  <details class="mt-1"<?= $tplFormFailed(['category', 'specific_goal', 'measurable_metric', 'focus_area']) ? ' open' : '' ?>>
  <summary accesskey="n" id="add-goal-tpl-h">Add a goal template</summary>
  <form method="post" action="templates.php?lib=goals" class="stacked" novalidate>
    <?= csrf_field() ?><?= web_idem_field() ?>
    <?= form_error_summary(['category' => 'Category', 'specific_goal' => 'Specific objective', 'measurable_metric' => 'Measurable criteria', 'focus_area' => 'Focus area']) ?>
    <p class="hint">Fields marked * are required.</p>
    <div class="field"><label for="category">Category <?= required_mark() ?></label>
      <span class="hint" id="category-hint">e.g., JAWS / Screen Reader, iOS / Mobile</span>
      <input type="text" id="category" name="category" required maxlength="80" value="<?= h(old_in('category')) ?>" <?= field_aria('category', 'category-hint') ?>><?= field_error('category') ?></div>
    <div class="field"><label for="specific_goal">Specific objective <?= required_mark() ?></label>
      <textarea id="specific_goal" name="specific_goal" rows="2" required <?= field_aria('specific_goal') ?>><?= h(old_in('specific_goal')) ?></textarea><?= field_error('specific_goal') ?></div>
    <div class="field"><label for="measurable_metric">Measurable criteria <?= required_mark() ?></label>
      <textarea id="measurable_metric" name="measurable_metric" rows="2" required <?= field_aria('measurable_metric') ?>><?= h(old_in('measurable_metric')) ?></textarea><?= field_error('measurable_metric') ?></div>
    <div class="field"><label for="focus_area">Focus area</label>
      <input type="text" id="focus_area" name="focus_area" maxlength="190" value="<?= h(old_in('focus_area')) ?>" <?= field_aria('focus_area') ?>><?= field_error('focus_area') ?></div>
    <button type="submit" name="add_goal_tpl" value="1" class="btn btn-primary">Add goal template</button>
  </form>
  </details>
</section>
<?php endif; ?>

<?php if ($lib === 'assessments'): ?>
<section id="assessments" class="card" aria-labelledby="assess-h">
  <h2 id="assess-h">Assessment templates (<?= count($assessTpls) ?>)</h2>
  <div class="table-scroll" tabindex="0" role="region" aria-labelledby="assess-cap">
  <table>
    <caption class="visually-hidden" id="assess-cap">Assessment templates</caption>
    <thead><tr><th scope="col">Name</th><th scope="col">Primary tech</th><th scope="col">Hardware</th><th scope="col">Software</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead>
    <tbody>
    <?php foreach ($assessTpls as $a): ?>
      <tr>
        <th scope="row"><?= h($a['name']) ?></th>
        <td><?= h($a['primary_tech']) ?></td>
        <td><?= h($a['hardware_details'] ?? '—') ?></td>
        <td><?= h($a['software_version'] ?? '—') ?></td>
        <td>
          <form method="post" action="templates.php" class="inline-form"><?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
            <button type="submit" name="del_assess_tpl" value="1" class="btn btn-danger btn-sm"
              onclick="return confirm(<?= h((string)json_encode('Delete the assessment template "' . $a['name'] . '" permanently? This cannot be undone.', JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)) ?>);">Delete permanently<span class="visually-hidden"> — assessment template: <?= h($a['name']) ?></span></button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <h3 class="visually-hidden">Add an assessment template</h3>
  <details class="mt-1"<?= $tplFormFailed(['name', 'hardware_details', 'software_version']) ? ' open' : '' ?>>
  <summary accesskey="n" id="add-assess-tpl-h">Add an assessment template</summary>
  <form method="post" action="templates.php?lib=assessments" class="stacked" novalidate>
    <?= csrf_field() ?><?= web_idem_field() ?>
    <?= form_error_summary(['name' => 'Template name', 'hardware_details' => 'Hardware details', 'software_version' => 'Software & version']) ?>
    <p class="hint">Fields marked * are required.</p>
    <div class="field"><label for="name">Template name <?= required_mark() ?></label>
      <input type="text" id="name" name="name" required maxlength="190" value="<?= h(old_in('name')) ?>" <?= field_aria('name') ?>><?= field_error('name') ?></div>
    <div class="field"><label for="primary_tech">Primary tech</label>
      <select id="primary_tech" name="primary_tech"><?= select_options(cms_list_items('tech_types'), old_in('primary_tech') ?: null) ?></select></div>
    <div class="field"><label for="hardware_details">Hardware details</label>
      <input type="text" id="hardware_details" name="hardware_details" maxlength="255" value="<?= h(old_in('hardware_details')) ?>" <?= field_aria('hardware_details') ?>><?= field_error('hardware_details') ?></div>
    <div class="field"><label for="software_version">Software &amp; version</label>
      <input type="text" id="software_version" name="software_version" maxlength="190" value="<?= h(old_in('software_version')) ?>" <?= field_aria('software_version') ?>><?= field_error('software_version') ?></div>
    <div class="field"><label for="summary">Summary skeleton</label>
      <textarea id="summary" name="summary" rows="6"><?= h(old_in('summary')) ?></textarea></div>
    <button type="submit" name="add_assess_tpl" value="1" class="btn btn-primary">Add assessment template</button>
  </form>
  </details>
</section>
<?php endif; ?>
<?php if ($lib === 'bundles'): ?>
<section id="bundles" class="card" aria-labelledby="bundles-h">
  <h2 id="bundles-h">Service bundles (<?= count($bundles) ?>)</h2>
  <p class="muted">RSA program packages billed under one label. A track picks a bundle; each visit is then logged against one of its categories. Pre-ETS bundles appear only for Pre-ETS clients, VR bundles for VR clients. Retiring a bundle stops offering it for new tracks; tracks that already use it keep it until someone chooses a different bundle.</p>
  <div class="table-scroll" tabindex="0" role="region" aria-labelledby="bundles-cap">
  <table>
    <caption class="visually-hidden" id="bundles-cap">Service bundles with client type, categories and status</caption>
    <thead><tr><th scope="col">Bundle</th><th scope="col">Client type</th><th scope="col">Categories</th><th scope="col">Status</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead>
    <tbody>
    <?php foreach ($bundles as $b): ?>
      <tr>
        <th scope="row"><?= h($b['name']) ?><?= $b['description'] ? '<br><span class="muted">' . h($b['description']) . '</span>' : '' ?></th>
        <td><?= h($b['client_type']) ?></td>
        <td><?= h(implode(' · ', bundle_categories($b))) ?></td>
        <td><?= $b['active'] ? '<span class="badge badge-closed">Active</span>' : '<span class="badge badge-hold">Retired</span>' ?></td>
        <td>
          <form method="post" action="templates.php" class="inline-form"><?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
            <input type="hidden" name="to" value="<?= $b['active'] ? 'retired' : 'active' ?>">
            <button type="submit" name="toggle_bundle" value="1" class="btn btn-secondary btn-sm"><?= $b['active'] ? 'Retire' : 'Reactivate' ?><span class="visually-hidden"> bundle <?= h($b['name']) ?></span></button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>

  <h3 class="visually-hidden">Add a service bundle</h3>
  <details class="mt-1"<?= $tplFormFailed(['bundle_name', 'bundle_description', 'bundle_categories']) ? ' open' : '' ?>>
  <summary accesskey="n" id="add-bundle-h">Add a service bundle</summary>
  <form method="post" action="templates.php?lib=bundles" class="stacked" novalidate>
    <?= csrf_field() ?><?= web_idem_field() ?>
    <?= form_error_summary(['bundle_name' => 'Bundle name', 'bundle_description' => 'Description', 'bundle_categories' => 'RSA categories'], ['bundle_categories' => 'bcat-0']) ?>
    <p class="hint">Fields marked * are required.</p>
    <div class="field"><label for="bundle_name">Bundle name (billing label) <?= required_mark() ?></label>
      <span class="hint" id="bundle_name-hint">e.g., “Pre-ETS Rehab Tech”, “Tech Referral”</span>
      <input type="text" id="bundle_name" name="bundle_name" required maxlength="120" value="<?= h(old_in('bundle_name')) ?>" <?= field_aria('bundle_name', 'bundle_name-hint') ?>><?= field_error('bundle_name') ?></div>
    <div class="field"><label for="bundle_client_type">Client type</label>
      <select id="bundle_client_type" name="bundle_client_type"><?= select_options(array_merge(cms_list_items('client_types'), ['Any']), old_in('bundle_client_type') ?: 'VR') ?></select></div>
    <div class="field"><label for="bundle_description">Description</label>
      <input type="text" id="bundle_description" name="bundle_description" maxlength="255" value="<?= h(old_in('bundle_description')) ?>" <?= field_aria('bundle_description') ?>><?= field_error('bundle_description') ?></div>
    <fieldset <?= has_field_error('bundle_categories') ? 'aria-describedby="err-bundle_categories"' : '' ?>>
      <legend>RSA categories in this bundle <?= required_mark() ?> <span class="hint">Full RSA-911 taxonomy</span></legend>
      <?= field_error('bundle_categories') ?>
      <?php $bci = 0; foreach (RSA_CATEGORY_GROUPS as $group => $cats): ?>
      <h4><?= h($group) ?></h4>
      <?php foreach ($cats as $c): ?>
      <div class="field"><label><input type="checkbox" id="bcat-<?= $bci++ ?>" name="bundle_categories[]" value="<?= h($c) ?>" <?= in_array($c, $keptBundleCats, true) ? 'checked' : '' ?>> <?= h($c) ?></label></div>
      <?php endforeach; endforeach; ?>
    </fieldset>
    <button type="submit" name="add_bundle" value="1" class="btn btn-primary">Add bundle</button>
  </form>
  </details>
</section>
<?php endif; ?>

<?php if ($lib === 'instruments'): ?>
<section id="instruments" class="card" aria-labelledby="instr-h">
  <h2 id="instr-h">Scored assessment instruments (<?= count($instruments) ?>)</h2>
  <p class="muted">Structured rating instruments that staff administer on a client's Assessments tab and re-administer over time to show progress. Each item is rated on the instrument's own scale; scores are normalized to a percent so trends compare fairly, and each instrument says whether a higher rating means better or worse, so the funder report counts an improvement as a gain. Includes seeded AT-outcome tools (device satisfaction, psychosocial impact, person–technology match).</p>
  <div class="table-scroll" tabindex="0" role="region" aria-labelledby="instr-cap">
  <table>
    <caption class="visually-hidden" id="instr-cap">Scored assessment instruments with item count, scale, use and status</caption>
    <thead><tr><th scope="col">Name</th><th scope="col" class="num">Items</th><th scope="col">Scale</th><th scope="col" class="num">In use</th><th scope="col">Status</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead>
    <tbody>
    <?php if (!$instruments): ?><tr><td colspan="6" class="muted">None yet — use “Add an instrument” below.</td></tr><?php endif; ?>
    <?php foreach ($instruments as $ins): ?>
      <tr>
        <th scope="row"><?= h($ins['name']) ?><?= $ins['description'] ? '<br><span class="muted">' . h($ins['description']) . '</span>' : '' ?></th>
        <td class="num"><?= (int)$ins['n'] ?></td>
        <td><?= (int)$ins['scale_min'] ?>–<?= (int)$ins['scale_max'] ?>, <?= (int)($ins['higher_is_better'] ?? 1) === 0 ? 'lower is better' : 'higher is better' ?></td>
        <td class="num"><?= (int)$ins['used'] ?></td>
        <td><?= $ins['active'] ? status_badge('Active') : '<span class="badge badge-hold">Inactive</span>' ?></td>
        <td>
          <form method="post" action="templates.php" class="inline-form"><?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int)$ins['id'] ?>">
            <input type="hidden" name="to" value="<?= $ins['active'] ? 'inactive' : 'active' ?>">
            <button type="submit" name="toggle_instrument" value="1" class="btn btn-secondary btn-sm"><?= $ins['active'] ? 'Deactivate' : 'Activate' ?><span class="visually-hidden"> — <?= h($ins['name']) ?></span></button>
          </form>
          <?php if ((int)$ins['used'] === 0): ?>
          <form method="post" action="templates.php" class="inline-form"><?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int)$ins['id'] ?>">
            <button type="submit" name="del_instrument" value="1" class="btn btn-danger btn-sm" onclick="return confirm('Delete this instrument and its items?');">Delete<span class="visually-hidden"> — <?= h($ins['name']) ?></span></button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <h3 class="visually-hidden">Add an instrument</h3>
  <details class="mt-1"<?= $tplFormFailed(['instrument_name', 'instrument_desc', 'scale_min', 'scale_max', 'scale_labels', 'higher_is_better', 'instrument_items']) ? ' open' : '' ?>>
  <summary accesskey="n" id="add-instrument-h">Add an instrument</summary>
  <form method="post" action="templates.php?lib=instruments" class="stacked" novalidate>
    <?= csrf_field() ?><?= web_idem_field() ?>
    <?= form_error_summary(['instrument_name' => 'Name', 'instrument_desc' => 'Description', 'scale_min' => 'Lowest rating', 'scale_max' => 'Highest rating', 'scale_labels' => 'Rating labels', 'higher_is_better' => 'Higher ratings mean', 'instrument_items' => 'Items'], ['higher_is_better' => 'hib-1']) ?>
    <p class="hint">Fields marked * are required.</p>
    <div class="field"><label for="instrument_name">Name <?= required_mark() ?></label>
      <input type="text" id="instrument_name" name="instrument_name" required maxlength="190" value="<?= h(old_in('instrument_name')) ?>" <?= field_aria('instrument_name') ?>><?= field_error('instrument_name') ?></div>
    <div class="field"><label for="instrument_desc">Description</label>
      <input type="text" id="instrument_desc" name="instrument_desc" maxlength="500" value="<?= h(old_in('instrument_desc')) ?>" <?= field_aria('instrument_desc') ?>><?= field_error('instrument_desc') ?></div>
    <div class="grid-2">
      <div class="field"><label for="scale_min">Lowest rating <?= required_mark() ?></label>
        <span class="hint" id="scale-hint">whole numbers from -10 to 10; the highest must be greater than the lowest</span>
        <input type="number" id="scale_min" name="scale_min" value="<?= h(old_in('scale_min', '0')) ?>" min="-10" max="10" step="1" required <?= field_aria('scale_min', 'scale-hint') ?>><?= field_error('scale_min') ?></div>
      <div class="field"><label for="scale_max">Highest rating <?= required_mark() ?></label>
        <input type="number" id="scale_max" name="scale_max" value="<?= h(old_in('scale_max', '4')) ?>" min="-10" max="10" step="1" required <?= field_aria('scale_max', 'scale-hint') ?>><?= field_error('scale_max') ?></div>
    </div>
    <div class="field"><label for="scale_labels">Rating labels</label>
      <span class="hint" id="scale_labels-hint">optional; one per level as <code>value=label</code> separated by semicolons, e.g. <code>0=0 Not at all;4=4 Fully</code>. Levels without a label show the number.</span>
      <input type="text" id="scale_labels" name="scale_labels" maxlength="600" value="<?= h(old_in('scale_labels')) ?>" <?= field_aria('scale_labels', 'scale_labels-hint') ?>><?= field_error('scale_labels') ?></div>
    <?php // Required, with no default: the funder report counts a gain in this direction. ?>
    <fieldset <?= has_field_error('higher_is_better') ? 'aria-describedby="err-higher_is_better"' : '' ?>>
      <legend>Higher ratings mean <?= required_mark() ?></legend>
      <?= field_error('higher_is_better') ?>
      <?php foreach (['1' => 'Better (for example, more independent or more satisfied)', '0' => 'Worse (for example, more difficulty or more help needed)'] as $hv => $hl): ?>
      <div class="field"><label><input type="radio" id="hib-<?= $hv ?>" name="higher_is_better" value="<?= $hv ?>"<?= old_in('higher_is_better') === (string)$hv ? ' checked' : '' ?><?= has_field_error('higher_is_better') ? ' aria-invalid="true"' : '' ?>> <?= h($hl) ?></label></div>
      <?php endforeach; ?>
    </fieldset>
    <div class="field"><label for="instrument_items">Items <?= required_mark() ?></label>
      <span class="hint" id="instrument_items-hint">one item per line, up to 255 characters each; each is rated on the scale above when administered</span>
      <textarea id="instrument_items" name="instrument_items" rows="6" required <?= field_aria('instrument_items', 'instrument_items-hint') ?>><?= h(old_in('instrument_items')) ?></textarea><?= field_error('instrument_items') ?></div>
    <button type="submit" name="add_instrument" value="1" class="btn btn-primary">Add instrument</button>
  </form>
  </details>
</section>
<?php endif; ?>
<?php page_footer();
