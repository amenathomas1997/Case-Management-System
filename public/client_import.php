<?php
// Import clients, or open authorizations as training tracks, from a spreadsheet (3.0.55).
// As the equipment import: a template, "Validate only" first, every row checked and reported,
// Excel's plain CSV read correctly. Clients already on file (same name, or date of birth and
// last name, or email or phone) are skipped, and imported counselor and client emails are
// marked unconfirmed. Authorizations must name exactly one client and a number not already
// on a track, with strict checks on hours, rates and dates because they feed invoices.
// Administrators only.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
csrf_check();
$user = require_login();
require_perm('admin');

const CIMPORT_KINDS = ['clients' => 'Clients', 'authorizations' => 'Open authorizations'];
const CIMPORT_COLUMNS = [
    'clients' => ['name', 'dob', 'email', 'phone', 'address', 'client_type', 'status', 'instructor_email', 'counselor_name', 'counselor_email', 'counselor_phone', 'counselor_agency', 'guardian_name', 'guardian_phone', 'guardian_email', 'vr_case_number', 'school'],
    'authorizations' => ['client_name', 'client_dob', 'auth_number', 'funding_agency', 'authorized_hours', 'hourly_rate', 'authorized_amount', 'mileage_rate', 'start_date', 'expiration_date', 'title', 'rsa_category'],
];
$kindIn = $_POST['kind'] ?? $_GET['kind'] ?? '';
$kind = is_string($kindIn) && isset(CIMPORT_KINDS[$kindIn]) ? $kindIn : 'clients';

if (isset($_GET['template'])) {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $kind . '_import_template.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    write_csv_row($out, CIMPORT_COLUMNS[$kind]);
    write_csv_row($out, $kind === 'clients'
        ? ['Jordan Example', '1985-04-12', 'jordan@example.org', '555-0100', '1 Main St, Des Moines IA', 'VR', 'Active Training', '', 'Pat Counselor', 'pat@agency.gov', '555-0199', 'State VR', '', '', '', 'VR-12345', '']
        : ['Jordan Example', '1985-04-12', 'AUTH-2026-00001', 'State VR', '20', '50.00', '1000.00', '0.655', '2026-10-01', '2027-03-31', 'Screen reader training', 'Rehabilitation Technology']);
    fclose($out);
    exit;
}

function cimport_token(): string {
    $t = bin2hex(random_bytes(16));
    $kept = is_array($_SESSION['client_import_tokens'] ?? null) ? $_SESSION['client_import_tokens'] : [];
    $kept[] = $t;
    $_SESSION['client_import_tokens'] = array_slice($kept, -10);
    return $t;
}
function cimport_spend(mixed $t): bool {
    $kept = is_array($_SESSION['client_import_tokens'] ?? null) ? $_SESSION['client_import_tokens'] : [];
    $at = is_string($t) && $t !== '' ? array_search($t, $kept, true) : false;
    if ($at === false) return false;
    unset($kept[$at]);
    $_SESSION['client_import_tokens'] = array_values($kept);
    return true;
}
function cimport_row($fh, bool &$converted): array|false {
    $row = fgetcsv($fh, 0, ',', '"', '');
    if ($row === false) return false;
    if (!mb_check_encoding(implode('', array_map('strval', $row)), 'UTF-8')) { $row = array_map(fn($c) => mb_convert_encoding((string)$c, 'UTF-8', 'Windows-1252'), $row); $converted = true; }
    return $row;
}

$results = null; $imported = 0; $skipped = 0; $dryRun = false; $converted = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do_import'])) {
    $dryRun = !empty($_POST['dry_run']);
    if (!$dryRun && !cimport_spend($_POST['import_token'] ?? null)) { flash('Nothing was imported: this form was already used or has expired. Upload the file again.', 'error'); redirect('client_import.php?kind=' . $kind); }
    $f = $_FILES['csv'] ?? null;
    if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) { keep_field_errors(['csv' => 'Choose a CSV file to upload.']); redirect('client_import.php?kind=' . $kind); }
    if (($f['size'] ?? 0) > 2 * 1024 * 1024) { keep_field_errors(['csv' => 'That file is larger than 2 MB. Split it into smaller files.']); redirect('client_import.php?kind=' . $kind); }
    $fh = fopen($f['tmp_name'], 'r');
    $header = $fh ? cimport_row($fh, $converted) : false;
    if ($header === false) { flash('The file appears to be empty.', 'error'); redirect('client_import.php?kind=' . $kind); }
    $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$header[0]);
    $idx = [];
    foreach ($header as $i => $h) { $k = strtolower((string)preg_replace('/[\s\-]+/u', '_', trim((string)$h))); if (in_array($k, CIMPORT_COLUMNS[$kind], true) && !isset($idx[$k])) $idx[$k] = $i; }
    $need = $kind === 'clients' ? ['name'] : ['client_name', 'auth_number', 'funding_agency', 'authorized_hours', 'hourly_rate'];
    if ($missing = array_diff($need, array_keys($idx))) { fclose($fh); flash('The file needs these columns: ' . implode(', ', $missing) . '. Download the template.', 'error'); redirect('client_import.php?kind=' . $kind); }
    $get = fn(array $row, string $c) => isset($idx[$c]) ? trim((string)($row[$idx[$c]] ?? '')) : '';
    $staff = [];
    // Only someone who can open client records can hold a caseload (as client_edit.php and the transfer require).
    foreach (db()->query('SELECT id, email, role, group_id FROM users WHERE is_active = 1')->fetchAll() as $s) if (in_array('clients', resolve_perms($s)['perms'], true)) $staff[strtolower((string)$s['email'])] = (int)$s['id'];
    $valid = []; $results = []; $seen = []; $line = 1;
    $skip = function (string $name, string $msg) use (&$results, &$skipped, &$line) { $results[] = ['line' => $line, 'name' => $name, 'ok' => false, 'msg' => $msg]; $skipped++; };
    while (($row = cimport_row($fh, $converted)) !== false) {
        $line++;
        if (count(array_filter($row, fn($c) => trim((string)$c) !== '')) === 0) continue;
        if ($kind === 'clients') {
            $name = $get($row, 'name');
            if ($name === '' || mb_strlen($name) > 120) { $skip($name ?: '(blank)', 'Name is missing or longer than 120 characters'); continue; }
            $dob = $get($row, 'dob');
            if ($dob !== '' && (!valid_date($dob, 1900, (int)date('Y')) || $dob > date('Y-m-d'))) { $skip($name, 'Date of birth must be a past date written 1985-04-12'); continue; }
            $emails = [];
            foreach (['email', 'counselor_email', 'guardian_email'] as $ec) { $v = $get($row, $ec); if ($v !== '' && !filter_var($v, FILTER_VALIDATE_EMAIL)) { $skip($name, $ec . ' is not an email address'); continue 2; } $emails[$ec] = $v !== '' ? strtolower($v) : null; }
            $type = $get($row, 'client_type') ?: 'VR';
            if (!in_array($type, CLIENT_TYPES, true)) { $skip($name, 'client_type must be one of: ' . implode(', ', CLIENT_TYPES)); continue; }
            $status = $get($row, 'status') ?: 'Pending Assessment';
            if (!in_array($status, ['Pending Assessment', 'Waitlist', 'Active Training'], true)) { $skip($name, 'status must be Pending Assessment, Waitlist or Active Training (On Hold needs a reason, so set it on the client page)'); continue; }
            $ie = strtolower($get($row, 'instructor_email'));
            if ($ie !== '' && !isset($staff[$ie])) { $skip($name, 'instructor_email does not match an active staff account with access to client records'); continue; }
            $key = mb_strtolower($name) . '|' . $dob;
            if (isset($seen[$key])) { $skip($name, 'Same person as row ' . $seen[$key] . ' of this file'); continue; }
            $dups = possible_duplicate_clients($name, $dob ?: null, $get($row, 'phone') ?: null, $emails['email']);
            if ($dups) { $skip($name, 'Already on file as ' . $dups[0]['name'] . ' (record #' . (int)$dups[0]['id'] . ')'); continue; }
            $seen[$key] = $line;
            $cut = fn(string $c, int $n) => ($v = mb_substr($get($row, $c), 0, $n)) !== '' ? $v : null;
            $valid[] = [$name, $dob ?: null, $emails['email'], $cut('phone', 40), $cut('address', 255), $status, $type, $cut('school', 190), $ie !== '' ? $staff[$ie] : null,
                $cut('counselor_name', 120), $emails['counselor_email'], $cut('counselor_phone', 40), $cut('counselor_agency', 190), $cut('guardian_name', 120), $cut('guardian_phone', 40), $emails['guardian_email'], $cut('vr_case_number', 60)];
            $results[] = ['line' => $line, 'name' => $name, 'ok' => true, 'msg' => $type . ' · ' . $status . ($dob ? ' · born ' . fmt_date($dob) : '')];
        } else {
            $cname = (string)preg_replace('/\s+/u', ' ', $get($row, 'client_name')); $label = $cname ?: '(blank)';
            if ($get($row, 'client_dob') !== '' && !valid_date($get($row, 'client_dob'), 1900, (int)date('Y'))) { $skip($label, 'client_dob must be written 1985-04-12'); continue; }
            $q = db()->prepare('SELECT id, name FROM clients WHERE LOWER(TRIM(name)) = ? AND archived_at IS NULL' . ($get($row, 'client_dob') !== '' ? ' AND dob = ?' : ''));
            $q->execute($get($row, 'client_dob') !== '' ? [mb_strtolower($cname), $get($row, 'client_dob')] : [mb_strtolower($cname)]);
            $cl = $q->fetchAll();
            if (count($cl) !== 1) { $skip($label, count($cl) ? 'More than one client has that name: add client_dob' : 'No client by that name (import or add the client first)'); continue; }
            $auth = $get($row, 'auth_number');
            if ($auth === '' || mb_strlen($auth) > 80) { $skip($label, 'auth_number is missing or longer than 80 characters'); continue; }
            $ex = db()->prepare('SELECT COUNT(*) FROM training_tracks WHERE auth_number = ?'); $ex->execute([$auth]);
            if ((int)$ex->fetchColumn() > 0 || isset($seen[$auth])) { $skip($label, 'Authorization ' . $auth . ' is already on a track'); continue; }
            $agency = agency_canonical($get($row, 'funding_agency'));
            if ($agency === '' || mb_strlen($agency) > 190) { $skip($label, 'funding_agency is missing'); continue; }
            $num = function (string $c, float $min, float $max, bool $required) use ($get, $row): ?float {
                $v = str_replace(['$', ',', ' '], '', $get($row, $c));
                if ($v === '') return $required ? -1.0 : null;
                return is_numeric($v) && (float)$v >= $min && (float)$v <= $max ? round((float)$v, 3) : -1.0;
            };
            // A blank rate takes the agency's own rates (Funding agencies, 4.2.0) when it has them.
            $agencyRate = agency_hourly_rate($agency);
            $hours = $num('authorized_hours', 0.25, 99999.99, true); $rate = $num('hourly_rate', 0.01, 999999.99, $agencyRate === null) ?? $agencyRate;
            $amount = $num('authorized_amount', 0.01, 99999999.99, false); $mile = $num('mileage_rate', 0, 999.999, false) ?? agency_mileage_rate($agency);
            foreach (['authorized_hours' => $hours, 'hourly_rate' => $rate, 'authorized_amount' => $amount, 'mileage_rate' => $mile] as $c => $v) if ($v === -1.0) { $skip($label, $c . ' must be a number in range (hours over 0, rate over 0)'); continue 2; }
            $start = $get($row, 'start_date'); $end = $get($row, 'expiration_date'); $maxY = (int)date('Y') + 5;
            if (($start !== '' && !valid_date($start, 2000, $maxY)) || ($end !== '' && !valid_date($end, 2000, $maxY))) { $skip($label, 'Dates must be written 2026-10-01'); continue; }
            if ($start !== '' && $end !== '' && $end < $start) { $skip($label, 'expiration_date is before start_date'); continue; }
            $rsa = $get($row, 'rsa_category');
            if ($rsa !== '' && !in_array($rsa, rsa_all_categories(), true)) { $skip($label, 'rsa_category is not one of the RSA categories'); continue; }
            $seen[$auth] = $line;
            $title = mb_substr($get($row, 'title') ?: 'Authorization ' . $auth, 0, 190);
            $valid[] = [(int)$cl[0]['id'], $title, $rsa ?: null, $auth, $agency, $hours, $amount, $rate, $mile ?? 0.0, $start ?: null, $end ?: null];
            $results[] = ['line' => $line, 'name' => $cl[0]['name'], 'ok' => true, 'msg' => $auth . ' · ' . $agency . ' · ' . $hours . ' hours at ' . fmt_money((float)$rate) . ($amount ? ' · up to ' . fmt_money((float)$amount) : '')];
        }
    }
    fclose($fh);
    if (!$dryRun && $valid) {
        db()->beginTransaction();
        try {
            foreach ($valid as $v) {
                if ($kind === 'clients') {
                    db()->prepare('INSERT INTO clients (name, dob, email, phone, address, status, client_type, school, assigned_to, counselor_name, counselor_email, counselor_phone, counselor_agency, guardian_name, guardian_phone, guardian_email, vr_case_number, email_verified, counselor_verified)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,0,0)')->execute($v);
                    record_status_change((int)db()->lastInsertId(), null, $v[5], (int)$user['id'], 'Client imported from a spreadsheet');
                } else {
                    db()->prepare("INSERT INTO training_tracks (client_id, title, rsa_category, status, auth_number, funding_agency, authorized_hours, authorized_amount, hourly_rate, mileage_rate, start_date, expiration_date, billing_mode)
                        VALUES (?,?,?,'Active',?,?,?,?,?,?,?,?,'vr')")->execute($v);
                }
                $imported++;
            }
            db()->commit();
        } catch (Throwable $e) { if (db()->inTransaction()) db()->rollBack(); throw $e; }
        audit($user['id'], $kind === 'clients' ? 'client.import' : 'track.import', $kind === 'clients' ? 'client' : 'track', null, $imported . ' imported from a spreadsheet');
        flash("Imported {$imported} " . ($kind === 'clients' ? 'client' : 'authorization') . ($imported === 1 ? '' : 's') . '.' . ($skipped ? " Skipped {$skipped}." : ''));
    } elseif ($dryRun) {
        flash('Validate only: nothing was imported. ' . count($valid) . ' row(s) would import, ' . $skipped . ' would be skipped.');
    } else {
        flash('No rows could be imported.', 'error');
    }
}

page_header('Import clients and authorizations', $user, 'admin', [['admin.php', 'Administration']]);
?>
<h1>Import clients and authorizations</h1>
<form method="get" action="client_import.php" class="inline-form">
  <label for="kind">What to import</label>
  <select id="kind" name="kind"><?php foreach (CIMPORT_KINDS as $k => $l): ?><option value="<?= $k ?>"<?= $k === $kind ? ' selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select>
  <button type="submit" class="btn btn-secondary btn-sm">Choose</button>
</form>
<section class="card" aria-labelledby="how-h">
  <h2 id="how-h"><?= h(CIMPORT_KINDS[$kind]) ?>: how it works</h2>
  <ol>
    <li>Download the <a href="client_import.php?kind=<?= $kind ?>&amp;template=1"><?= h(mb_strtolower(CIMPORT_KINDS[$kind])) ?> template</a>, fill in one row each, and save it as CSV.</li>
    <li>Columns: <?= h(implode(', ', CIMPORT_COLUMNS[$kind])) ?>. Dates are written 2026-10-01.</li>
    <?php if ($kind === 'clients'): ?>
    <li>Someone already on file (same name; date of birth and last name; email; or phone) is skipped. Imported email addresses are marked unconfirmed until staff confirm them.</li>
    <?php else: ?>
    <li>Each row must name exactly one client (add client_dob when two share a name) and an authorization number not already on a track. Hours and the hourly rate are required.</li>
    <?php endif; ?>
    <li>Upload with Validate only first, check the result, then upload again to import.</li>
  </ol>
  <form method="post" action="client_import.php" enctype="multipart/form-data" class="stacked"><?= csrf_field() ?>
    <input type="hidden" name="kind" value="<?= $kind ?>"><input type="hidden" name="import_token" value="<?= h(cimport_token()) ?>">
    <div class="field"><label for="csv">CSV file</label><input type="file" id="csv" name="csv" accept=".csv,text/csv" <?= field_aria('csv') ?>><?= field_error('csv') ?></div>
    <div class="field"><label><input type="checkbox" name="dry_run" value="1" checked> Validate only (nothing is imported)</label></div>
    <button type="submit" name="do_import" value="1" class="btn btn-primary">Upload and check</button>
  </form>
</section>
<?php if ($results !== null): ?>
<section class="card" aria-labelledby="res-h">
  <h2 id="res-h">Result<?= $dryRun ? ' (validate only)' : '' ?></h2>
  <p><?= $dryRun ? count(array_filter($results, fn($r) => $r['ok'])) . ' row(s) would import' : $imported . ' imported' ?><?= $skipped ? ', ' . $skipped . ' skipped' : '' ?>.<?= $converted ? ' The file was not saved as UTF-8; check names with accents.' : '' ?></p>
  <div class="table-scroll" tabindex="0" role="region" aria-labelledby="res-cap">
  <table><caption class="visually-hidden" id="res-cap">Import result by row</caption>
    <thead><tr><th scope="col">Row</th><th scope="col">Name</th><th scope="col">Result</th></tr></thead>
    <tbody><?php foreach ($results as $r): ?><tr><td><?= (int)$r['line'] ?></td><th scope="row"><?= h($r['name']) ?></th><td><?= $r['ok'] ? ($dryRun ? 'Would import' : 'Imported') : '<strong>Skipped</strong>' ?>: <?= h($r['msg']) ?></td></tr><?php endforeach; ?></tbody>
  </table></div>
</section>
<?php endif; ?>
<?php page_footer();
