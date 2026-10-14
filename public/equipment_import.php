<?php
// Bulk-import equipment from a CSV. Upload a spreadsheet of devices (name,
// category, serial, condition, value, service interval, notes); rows are
// validated and added to the inventory. A dry-run option validates without
// importing. Gated by the 'equipment' permission.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
csrf_check();
$user = require_login();
require_perm('equipment');

// Must match equipment.php's allowed values.
const IMPORT_CATEGORIES = ['Braille Display', 'Screen Reader License', 'Magnifier', 'Tablet/Phone', 'Computer', 'Keyboard/Input', 'Audio', 'Other'];
const IMPORT_CONDITIONS = ['New', 'Good', 'Fair', 'Needs Repair'];
const IMPORT_COLUMNS = ['name', 'category', 'serial', 'condition', 'value', 'maintenance_interval_days', 'notes'];
const IMPORT_ENCODING_NOTE = 'The file was not saved as UTF-8, so its text was read as Windows-1252 (Excel’s plain “CSV” format). Check names with accents or symbols below; saving as “CSV UTF-8” avoids this.';

/* ---- CSV template download ---- */
if (isset($_GET['template'])) {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="equipment_import_template.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    write_csv_row($out, IMPORT_COLUMNS);
    write_csv_row($out, ['Focus 40 Braille Display', 'Braille Display', 'SN-0001', 'Good', '2995.00', '365', 'Freedom Scientific']);
    write_csv_row($out, ['ZoomText Magnifier License', 'Screen Reader License', '', 'New', '599.00', '', 'Annual license']);
    fclose($out);
    exit;
}

$results = null; $imported = 0; $skipped = 0; $dryRun = false; $converted = false; $ignoredCols = [];

/** A one-use token for each import form shown. A real import spends it, so pressing the
 *  button twice, or reloading the result page (which posts the file again), cannot add
 *  the same devices a second time. A few are kept so two open tabs both work. */
function import_form_token(): string {
    $t = bin2hex(random_bytes(16));
    $kept = is_array($_SESSION['equipment_import_tokens'] ?? null) ? $_SESSION['equipment_import_tokens'] : [];
    $kept[] = $t;
    $_SESSION['equipment_import_tokens'] = array_slice($kept, -10);
    return $t;
}
/** Spends the posted token; false when it was never issued or is already spent. */
function import_spend_token(mixed $t): bool {
    $kept = is_array($_SESSION['equipment_import_tokens'] ?? null) ? $_SESSION['equipment_import_tokens'] : [];
    $at = is_string($t) && $t !== '' ? array_search($t, $kept, true) : false;
    if ($at === false) return false;
    unset($kept[$at]);
    $_SESSION['equipment_import_tokens'] = array_values($kept);
    return true;
}

/** Read one CSV record: RFC 4180 quoting with no backslash escape (as write_csv_row
 *  writes), and text decoded to UTF-8. Excel's plain "CSV" is Windows-1252, so a row
 *  that is not valid UTF-8 is read as Windows-1252; the dry run and the real import
 *  read the file the same way. */
function import_csv_row($fh, bool &$converted): array|false {
    $row = fgetcsv($fh, 0, ',', '"', '');
    if ($row === false) return false;
    if (!mb_check_encoding(implode('', array_map('strval', $row)), 'UTF-8')) {
        $row = array_map(fn($c) => mb_convert_encoding((string)$c, 'UTF-8', 'Windows-1252'), $row);
        $converted = true;
    }
    return $row;
}

/* ---- Handle upload ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do_import'])) {
    $dryRun = !empty($_POST['dry_run']);
    // A dry run changes nothing, so only a real import spends the form's token.
    if (!$dryRun && !import_spend_token($_POST['import_token'] ?? null)) {
        flash('Nothing was imported: this form was already used (the button was pressed twice, or the result page was reloaded) or has expired. Check the inventory on the Equipment page, then upload the file again if it is still needed.', 'error');
        redirect('equipment_import.php');
    }
    $f = $_FILES['csv'] ?? null;
    if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        flash('Please choose a CSV file to upload.', 'error');
        redirect('equipment_import.php');
    }
    if (($f['size'] ?? 0) > 2 * 1024 * 1024) {
        flash('That file is larger than 2 MB. Split it into smaller files.', 'error');
        redirect('equipment_import.php');
    }
    $fh = fopen($f['tmp_name'], 'r');
    if (!$fh) { flash('Could not read the uploaded file.', 'error'); redirect('equipment_import.php'); }

    // Header row → column index map (case/space-insensitive): spaces around a heading are
    // ignored ("Serial " is serial), and inner spaces or hyphens read as underscores.
    // A heading that matches no column (or repeats one) is named in the result, so a
    // column that will not be imported is seen in the dry run, not discovered later.
    $header = import_csv_row($fh, $converted);
    if ($header === false) { fclose($fh); flash('The file appears to be empty.', 'error'); redirect('equipment_import.php'); }
    $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$header[0]); // strip BOM
    $idx = [];
    foreach ($header as $i => $h) {
        $label = trim((string)$h);
        $key = strtolower((string)preg_replace('/[\s\-]+/u', '_', $label));
        if (in_array($key, IMPORT_COLUMNS, true) && !isset($idx[$key])) $idx[$key] = $i;
        elseif ($label !== '') $ignoredCols[] = $label . (in_array($key, IMPORT_COLUMNS, true) ? ' (a second ' . $key . ' column)' : '');
    }
    $ignoredNote = $ignoredCols ? ' Ignored columns, not imported: ' . implode(', ', $ignoredCols) . '. The columns read are: ' . implode(', ', IMPORT_COLUMNS) . '.' : '';
    if (!isset($idx['name'])) {
        fclose($fh);
        flash('The CSV needs at least a "name" column. Download the template for the expected format.' . $ignoredNote, 'error');
        redirect('equipment_import.php');
    }

    $get = fn(array $row, string $col) => isset($idx[$col]) ? trim((string)($row[$idx[$col]] ?? '')) : '';
    $valid = [];
    $results = [];
    $seenSerials = [];
    $serialHave = db()->prepare('SELECT id FROM equipment WHERE serial = ? LIMIT 1');
    $line = 1;
    while (($row = import_csv_row($fh, $converted)) !== false) {
        $line++;
        if (count(array_filter($row, fn($c) => trim((string)$c) !== '')) === 0) continue; // blank line
        $name = $get($row, 'name');
        if ($name === '') { $results[] = ['line' => $line, 'name' => '(blank)', 'ok' => false, 'msg' => 'Missing name — skipped']; $skipped++; continue; }
        $cat = $get($row, 'category');
        $cat = in_array($cat, IMPORT_CATEGORIES, true) ? $cat : 'Other';
        $cond = $get($row, 'condition');
        $cond = in_array($cond, IMPORT_CONDITIONS, true) ? $cond : 'Good';
        // One plain amount: a dollar sign, spaces and thousands commas are allowed.
        // Anything else ('N/A', '$599 (2023)', '1.299,50') skips the row rather than
        // being read as a different number.
        $valueRaw = $get($row, 'value');
        $valueClean = str_replace(['$', ' ', "\u{00A0}"], '', $valueRaw);
        if ($valueRaw !== '' && !preg_match('/^-?(\d{1,3}(,\d{3})+|\d+)(\.\d{1,2})?$/D', $valueClean)) {
            $results[] = ['line' => $line, 'name' => $name, 'ok' => false, 'msg' => 'Value must be one amount with up to two decimal places, such as 2995.00 — skipped'];
            $skipped++;
            continue;
        }
        $value = $valueRaw !== '' ? (float)str_replace(',', '', $valueClean) : null;
        // The value column is decimal(9,2): a negative or oversized amount would
        // fail the real import, so the dry run has to reject the row too.
        if ($value !== null && ($value < 0 || $value > 9999999.99)) {
            $results[] = ['line' => $line, 'name' => $name, 'ok' => false, 'msg' => 'Value must be between 0 and 9,999,999.99 — skipped'];
            $skipped++;
            continue;
        }
        $ivRaw = $get($row, 'maintenance_interval_days');
        // A whole number of days (0 or blank = no schedule). Text such as '1 year' or
        // 'N/A' skips the row rather than being read as 1 day or as no schedule.
        if ($ivRaw !== '' && (!preg_match('/^\d{1,4}$/D', $ivRaw) || (int)$ivRaw > 3650)) {
            $results[] = ['line' => $line, 'name' => $name, 'ok' => false, 'msg' => 'Maintenance interval must be a whole number of days (0–3650) — skipped'];
            $skipped++;
            continue;
        }
        $iv = $ivRaw !== '' ? (int)$ivRaw : null;
        // Text is cut to the real column sizes (name 190, serial 120, notes 255)
        // in both modes, and the result row says so when something was shortened.
        $serial = $get($row, 'serial');
        $notes = $get($row, 'notes');
        // A serial number already in the inventory, or earlier in this file, is the same
        // device: the row is skipped (and said so in the dry run too), never added twice.
        $serialKey = mb_strtolower(mb_substr($serial, 0, 120));
        if ($serial !== '') {
            if (isset($seenSerials[$serialKey])) {
                $results[] = ['line' => $line, 'name' => $name, 'ok' => false, 'msg' => 'Serial number ' . $serial . ' is also on row ' . $seenSerials[$serialKey] . ' of this file — skipped'];
                $skipped++;
                continue;
            }
            $serialHave->execute([mb_substr($serial, 0, 120)]);
            if ($serialHave->fetchColumn()) {
                $results[] = ['line' => $line, 'name' => $name, 'ok' => false, 'msg' => 'Serial number ' . $serial . ' is already in the inventory — skipped'];
                $skipped++;
                continue;
            }
            $seenSerials[$serialKey] = $line;
        }
        $shortened = array_keys(array_filter(['name' => mb_strlen($name) > 190, 'serial' => mb_strlen($serial) > 120, 'notes' => mb_strlen($notes) > 255]));
        $rec = [mb_substr($name, 0, 190), $cat, mb_substr($serial, 0, 120) ?: null, $cond,
            $value, mb_substr($notes, 0, 255) ?: null, $iv ?: null];
        $valid[] = $rec;
        $results[] = ['line' => $line, 'name' => $name, 'ok' => true, 'msg' => $cat . ' · ' . $cond . ($value !== null ? ' · ' . fmt_money($value) : '')
            . ($iv ? ' · service every ' . $iv . ' day' . ($iv === 1 ? '' : 's') : '')
            . ($shortened ? ' · ' . implode(', ', $shortened) . ' shortened to fit' : '')];
    }
    fclose($fh);

    if (!$dryRun && $valid) {
        db()->beginTransaction();
        try {
            $ins = db()->prepare('INSERT INTO equipment (name, category, serial, item_condition, value, notes, maintenance_interval_days) VALUES (?,?,?,?,?,?,?)');
            foreach ($valid as $rec) { $ins->execute($rec); $imported++; }
            db()->commit();
        } catch (Throwable $e) { db()->rollBack(); throw $e; }
        audit($user['id'], 'equipment.import', 'equipment', null, $imported . ' items');
        flash("Imported {$imported} item(s)." . ($skipped ? " Skipped {$skipped}." : '') . $ignoredNote . ($converted ? ' ' . IMPORT_ENCODING_NOTE : ''));
    } elseif ($dryRun) {
        flash('Dry run only — nothing was imported. ' . count($valid) . ' row(s) would import, ' . $skipped . ' skipped.' . $ignoredNote . ($converted ? ' ' . IMPORT_ENCODING_NOTE : ''));
    } else {
        flash('No valid rows to import.' . $ignoredNote, 'error');
    }
}

page_header('Import equipment', $user, 'equipment');
?>
<p class="page-actions"><a href="equipment.php"><span aria-hidden="true">&larr; </span>Back to equipment</a></p>
<h1>Import equipment from CSV</h1>
<p class="muted">Bulk-add devices from a spreadsheet. Each row becomes an inventory item.</p>

<section class="card" aria-labelledby="how-h">
  <h2 id="how-h">How it works</h2>
  <ol>
    <li>Download the <a href="equipment_import.php?template=1">CSV template</a> and fill in your devices (one per row).</li>
    <li>Columns: <code>name</code> (required), <code>category</code>, <code>serial</code>, <code>condition</code>, <code>value</code>, <code>maintenance_interval_days</code>, <code>notes</code>.</li>
    <li>Category must be one of: <?= h(implode(', ', IMPORT_CATEGORIES)) ?> (anything else becomes “Other”).</li>
    <li>Condition must be one of: <?= h(implode(', ', IMPORT_CONDITIONS)) ?> (anything else becomes “Good”).</li>
    <li><code>maintenance_interval_days</code> must be a whole number of days from 0 to 3650 (blank or 0 = no service schedule); a row with anything else is skipped.</li>
    <li>A row whose serial number is already in the inventory, or repeats an earlier row's, is skipped, so the same device is never added twice.</li>
    <li>A column with any other heading is not imported; the result names it.</li>
    <li>Upload below. Use <strong>Validate only</strong> first to preview without importing.</li>
  </ol>
  <form method="post" action="equipment_import.php" enctype="multipart/form-data" class="stacked"><?= csrf_field() ?>
    <input type="hidden" name="import_token" value="<?= h(import_form_token()) ?>">
    <div class="field"><label for="csv">CSV file</label><input type="file" id="csv" name="csv" accept=".csv,text/csv" required></div>
    <div class="field"><label><input type="checkbox" name="dry_run" value="1" checked> Validate only (dry run — don't import yet)</label></div>
    <button type="submit" name="do_import" value="1" class="btn btn-primary">Upload &amp; process</button>
  </form>
</section>

<?php if ($results !== null): ?>
<section class="card" aria-labelledby="res-h">
  <h2 id="res-h">Result <?= $dryRun ? '(dry run)' : '' ?></h2>
  <p><?= $dryRun ? count(array_filter($results, fn($r) => $r['ok'])) . ' row(s) would import' : $imported . ' item(s) imported' ?><?= $skipped ? ', ' . $skipped . ' skipped' : '' ?>.</p>
  <?php if ($ignoredCols): ?><p><strong>Ignored columns, not imported:</strong> <?= h(implode(', ', $ignoredCols)) ?>. The columns read are: <?= h(implode(', ', IMPORT_COLUMNS)) ?>.</p><?php endif; ?>
  <div class="table-scroll" tabindex="0" role="region" aria-label="Import results">
  <table>
    <caption class="visually-hidden">Import results by row</caption>
    <thead><tr><th scope="col">Row</th><th scope="col">Name</th><th scope="col">Result</th></tr></thead>
    <tbody>
    <?php foreach ($results as $r): ?>
      <tr>
        <td><?= (int)$r['line'] ?></td>
        <th scope="row"><?= h($r['name']) ?></th>
        <td><?= $r['ok'] ? '<span class="badge badge-closed">' . ($dryRun ? 'Would import' : 'Imported') . '</span> <span class="muted">' . h($r['msg']) . '</span>' : '<span class="badge badge-hold">Skipped</span> <span class="muted">' . h($r['msg']) . '</span>' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php if ($dryRun && array_filter($results, fn($r) => $r['ok'])): ?>
  <p class="muted" style="margin-top:1rem">Looks right? Re-upload the same file with “Validate only” unchecked to import.</p>
  <?php endif; ?>
</section>
<?php endif; ?>
<?php page_footer();
