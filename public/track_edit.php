<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
csrf_check();
$user = require_login();
require_perm('clients');

$id = (int)($_GET['id'] ?? 0);
$track = null;
if ($id) {
    $stmt = db()->prepare('SELECT * FROM training_tracks WHERE id = ?');
    $stmt->execute([$id]);
    $track = $stmt->fetch();
    if (!$track) { flash('Training track not found.', 'error'); redirect('clients.php'); }
    scope_check_client_id((int)$track['client_id'], $user);
    $clientId = (int)$track['client_id'];
} else {
    $clientId = (int)($_GET['client_id'] ?? 0);
}
$cstmt = db()->prepare('SELECT id, name, client_type, assigned_to FROM clients WHERE id = ?');
$cstmt->execute([$clientId]);
$client = $cstmt->fetch();
if (!$client) { flash('Choose a client first.', 'error'); redirect('clients.php'); }
scope_check_client($client, $user);

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $authNumber = trim($_POST['auth_number'] ?? '');
    $agency = agency_canonical((string)($_POST['funding_agency'] ?? ''));
    $authHours = (float)($_POST['authorized_hours'] ?? 0);
    $rate = (float)($_POST['hourly_rate'] ?? 0);
    $reason = trim((string)($_POST['amendment_reason'] ?? ''));
    // Lengths and ranges match the columns, so an oversize value is explained
    // here instead of failing the save (or being cut short) in the database.
    if ($title === '') $errors['title'] = 'Track title is required.';
    elseif (mb_strlen($title) > 190) $errors['title'] = 'Keep the track title to 190 characters or fewer.';
    // How the work is paid for (3.0.34): a VR authorization needs its number, agency,
    // hours and rate; private pay needs a rate; no charge logs at $0 and is never invoiced.
    $billingMode = isset(BILLING_MODES[$_POST['billing_mode'] ?? '']) ? $_POST['billing_mode'] : 'vr';
    $isVr = $billingMode === 'vr';
    if (!$isVr) {
        if ($agency === '') $agency = $billingMode === 'private' ? 'Private pay' : 'No charge';
        if ($billingMode === 'none') $rate = 0.0;
    }
    // A VR track with no rate typed charges the agency's own rate (Funding agencies, 4.2.0).
    if ($isVr && !$track && $rate <= 0 && trim((string)($_POST['hourly_rate'] ?? '')) === '' && ($agencyRate = agency_hourly_rate($agency)) !== null) $rate = $agencyRate;
    $amtRaw = is_string($_POST['authorized_amount'] ?? null) ? str_replace([',', '$'], '', trim($_POST['authorized_amount'])) : '';
    $authAmount = $amtRaw === '' || !$isVr ? null : (is_numeric($amtRaw) ? round((float)$amtRaw, 2) : -1.0);
    if ($authAmount !== null && ($authAmount < 0 || $authAmount > 99999999.99)) $errors['authorized_amount'] = 'Enter the authorized dollar amount in dollars and cents, such as 4500.00, or leave it blank to use the authorized hours at the hourly rate.';
    if ($isVr && $authNumber === '') $errors['auth_number'] = 'Service authorization number is required for a VR or agency track. For a client who pays, or free training, choose Private pay or No charge.';
    elseif (mb_strlen($authNumber) > 80) $errors['auth_number'] = 'Keep the authorization number to 80 characters or fewer.';
    if ($agency === '') $errors['funding_agency'] = 'Funding agency is required.';
    elseif (mb_strlen($agency) > 190) $errors['funding_agency'] = 'Keep the funding agency to 190 characters or fewer.';
    if ($isVr && $authHours <= 0) $errors['authorized_hours'] = 'Authorized hours must be greater than zero.';
    elseif ($authHours < 0) $errors['authorized_hours'] = 'Authorized hours cannot be negative.';
    elseif ($authHours > 99999.99) $errors['authorized_hours'] = 'Authorized hours must be 99,999.99 or fewer.';
    // Changing who pays would re-price work already logged (to $0, or from $0): refused
    // while the track has unbilled work or a Draft invoice. Invoice or void them first.
    if ($track && track_mode($track) !== $billingMode) {
        $pend = db()->prepare("SELECT (SELECT COUNT(*) FROM work_logs WHERE track_id = ? AND is_billed = 0) + (SELECT COUNT(*) FROM track_expenses WHERE track_id = ? AND is_billed = 0)
            + (SELECT COUNT(*) FROM invoices WHERE track_id = ? AND status = 'Draft')");
        $pend->execute([$id, $id, $id]);
        if ((int)$pend->fetchColumn() > 0) $errors['billing_mode'] = 'Who pays cannot change while this track has work not yet invoiced or a Draft invoice: that work would be re-priced. Invoice it (or void the Draft) first, or start a new track for the new arrangement.';
    }
    if ($billingMode !== 'none' && $rate <= 0) $errors['hourly_rate'] = 'Hourly rate must be greater than zero.' . ($billingMode === 'private' ? ' The personal rate is ' . fmt_money(billing_settings()['private_rate']) . ' an hour (Administration > Pricing rules).' : '');
    elseif ($rate > 999999.99) $errors['hourly_rate'] = 'Hourly rate must be 999,999.99 or less.';
    if (mb_strlen($reason) > 255) $errors['amendment_reason'] = 'Keep the amendment reason to 255 characters or fewer.';
    $status = in_array($_POST['status'] ?? '', TRACK_STATUSES, true) ? $_POST['status'] : 'Active';
    $serviceType = in_array($_POST['service_type'] ?? '', cms_choices('service_types', $track['service_type'] ?? null), true) ? $_POST['service_type'] : 'Assistive Technology';
    // Free or personal-rate training set up for someone with an open VR case (Pricing rules,
    // 4.2.0): refused, or saved with a warning, as the administrator chose.
    $pricingConflict = pricing_mode_conflict($clientId, $billingMode, $track ?: null, $status);
    if ($pricingConflict && $pricingConflict['block']) $errors['billing_mode'] ??= $pricingConflict['message'];
    // Program: either a service bundle (validated against the client's type) or a single RSA category.
    // A track keeps its current bundle even after the bundle is retired or stops
    // matching the client type: it shows as a disabled, selected option, which the
    // browser does not submit, so a missing bundle_id on an edit means "unchanged".
    $currentBundleId = $track ? ((int)($track['bundle_id'] ?? 0) ?: null) : null;
    $bundleId = ($track && !array_key_exists('bundle_id', $_POST)) ? $currentBundleId : ((int)($_POST['bundle_id'] ?? 0) ?: null);
    if ($bundleId !== null) {
        $chosen = bundle($bundleId);
        $keeping = $bundleId === $currentBundleId;
        if (!$chosen || (!$keeping && (!$chosen['active']
            || ($chosen['client_type'] !== 'Any' && $chosen['client_type'] !== ($client['client_type'] ?? 'VR'))))) {
            $errors['bundle_id'] = 'That service bundle is not available for this client type.';
            $bundleId = null;
        }
    }
    $rsaCategory = in_array($_POST['rsa_category'] ?? '', rsa_all_categories(), true) ? $_POST['rsa_category'] : rsa_default_for($serviceType);
    $start = trim($_POST['start_date'] ?? '') ?: null;
    $exp = trim($_POST['expiration_date'] ?? '') ?: null;
    // A year from 2000 to five years ahead, as the app's api_date() allows, so a
    // two-digit year typo (0026, 2062) is refused instead of saved.
    $maxYear = (int)date('Y') + 5;
    if ($start && !valid_date($start, 2000, $maxYear)) $errors['start_date'] = 'Use a valid start date (YYYY-MM-DD) with a year from 2000 to ' . $maxYear . '.';
    if ($exp && !valid_date($exp, 2000, $maxYear)) $errors['expiration_date'] = 'Use a valid expiration date (YYYY-MM-DD) with a year from 2000 to ' . $maxYear . '.';
    if ($start && $exp && $exp < $start) $errors['expiration_date'] = 'Expiration date must be after the start date.';

    $mileageRaw = trim((string)($_POST['mileage_rate'] ?? ''));
    $mileageRate = $mileageRaw === '' ? (!$track ? default_mileage_for($billingMode, $agency) : 0.0) : (float)$mileageRaw;
    if ($mileageRaw !== '' && (!is_numeric($mileageRaw) || $mileageRate < 0 || $mileageRate > 999.999)) {
        $errors['mileage_rate'] = 'Enter the mileage rate in dollars per mile, from 0 to 999.999 (0 when travel is not billable).';
    }

    if (!$errors) {
        $cadence = is_string($_POST['report_cadence'] ?? null) && isset(report_cadence_choices($track['report_cadence'] ?? null)[$_POST['report_cadence']]) ? $_POST['report_cadence'] : 'monthly';
        $vals = [$title, $serviceType, $rsaCategory, $bundleId, $status, $authNumber, $agency, $authHours, $rate, $mileageRate, $start, $exp, $cadence];
        if ($track) {
            db()->beginTransaction();
            try {
                $locked = db()->prepare('SELECT * FROM training_tracks WHERE id=? FOR UPDATE');
                $locked->execute([$id]);
                $track = $locked->fetch();
                if (!$track) throw new DomainException('Training track no longer exists.');
                // Record an amendment when authorization terms change (compared at
                // the columns' precision, so re-saving unchanged values is no change).
                $hoursChanged = round((float)$track['authorized_hours'], 2) !== round($authHours, 2);
                $rateChanged = round((float)$track['hourly_rate'], 2) !== round($rate, 2);
                $mileageChanged = round((float)$track['mileage_rate'], 3) !== round($mileageRate, 3);
                $expChanged = ($track['expiration_date'] ?? null) !== $exp;
                $authNoChanged = (string)$track['auth_number'] !== $authNumber;
                $agencyChanged = (string)$track['funding_agency'] !== $agency;
                // Old -> new for every changed field, most billing-relevant first
                // (audit() keeps 255 characters).
                $changes = [];
                foreach (['auth_number' => $authNumber, 'funding_agency' => $agency, 'hourly_rate' => $rate, 'mileage_rate' => $mileageRate,
                          'authorized_hours' => $authHours, 'expiration_date' => $exp, 'start_date' => $start, 'bundle_id' => $bundleId,
                          'rsa_category' => $rsaCategory, 'service_type' => $serviceType, 'status' => $status,
                          'report_cadence' => $cadence, 'title' => $title] as $col => $new) {
                    $changed = match ($col) {
                        'hourly_rate' => $rateChanged, 'mileage_rate' => $mileageChanged, 'authorized_hours' => $hoursChanged,
                        default => (string)($track[$col] ?? '') !== (string)($new ?? ''),
                    };
                    if ($changed) $changes[] = $col . ' ' . ($track[$col] ?? '—') . '->' . ($new ?? '—');
                }
                // A rate correction reaches the work already logged but not yet
                // invoiced, and the work on this track's Draft invoices (not yet sent
                // to the funder): those entries carry the rate as a snapshot, which
                // would otherwise bill them at the old rate. A new authorization number
                // or funding agency likewise reaches the Drafts' captured header.
                // Submitted and paid invoices never change. The drafts are locked
                // (after the track, as in invoices.php) so none is submitted meanwhile.
                $repriced = 0;
                $draftIds = [];
                if ($rateChanged || $mileageChanged || $authNoChanged || $agencyChanged) {
                    $dq = db()->prepare("SELECT id FROM invoices WHERE track_id = ? AND status = 'Draft' FOR UPDATE");
                    $dq->execute([(int)$track['id']]);
                    $draftIds = array_map('intval', $dq->fetchAll(PDO::FETCH_COLUMN));
                }
                $onDraft = $draftIds ? ' OR invoice_id IN (' . implode(',', $draftIds) . ')' : '';
                if ($rateChanged) {
                    $rp = db()->prepare('UPDATE work_logs SET hourly_rate = ? WHERE track_id = ? AND (is_billed = 0' . $onDraft . ')');
                    $rp->execute([$rate, (int)$track['id']]);
                    $repriced = $rp->rowCount();
                }
                if ($mileageChanged) {
                    $rp = db()->prepare('UPDATE work_logs SET mileage_rate = ? WHERE track_id = ? AND (is_billed = 0' . $onDraft . ')');
                    $rp->execute([$mileageRate, (int)$track['id']]);
                    $repriced = max($repriced, $rp->rowCount());
                }
                // Drafts that captured a header print it (invoice_pdf.php, the list and the
                // QuickBooks export); older drafts without one already show the track's.
                $headerUpdated = 0;
                if ($draftIds && ($authNoChanged || $agencyChanged)) {
                    $hu = db()->prepare('UPDATE invoices SET auth_number = ?, funding_agency = ? WHERE id IN (' . implode(',', $draftIds) . ")
                        AND status = 'Draft' AND auth_number IS NOT NULL");
                    $hu->execute([$authNumber, $agency]);
                    $headerUpdated = $hu->rowCount();
                }
                $amended = $hoursChanged || $rateChanged || $expChanged || $authNoChanged || $mileageChanged;
                if ($amended) {
                    db()->prepare('INSERT INTO auth_amendments (track_id, old_hours, new_hours, old_rate, new_rate, old_expiration, new_expiration, old_auth_number, new_auth_number, old_mileage_rate, new_mileage_rate, reason, changed_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')
                        ->execute([(int)$track['id'],
                            $hoursChanged ? $track['authorized_hours'] : null, $hoursChanged ? $authHours : null,
                            $rateChanged ? $track['hourly_rate'] : null, $rateChanged ? $rate : null,
                            $expChanged ? $track['expiration_date'] : null, $expChanged ? $exp : null,
                            $authNoChanged ? $track['auth_number'] : null, $authNoChanged ? $authNumber : null,
                            $mileageChanged ? $track['mileage_rate'] : null, $mileageChanged ? $mileageRate : null,
                            $reason !== '' ? $reason : null, $user['id']]);
                    // Entered from an approved amendment request: the two are linked (3.0.44).
                    $amendReq = (int)($_POST['amend_request'] ?? 0);
                    if ($amendReq > 0) {
                        db()->prepare("UPDATE amendment_requests SET amendment_id = ? WHERE id = ? AND track_id = ? AND status IN ('Approved','Partly approved') AND amendment_id IS NULL")
                            ->execute([(int)db()->lastInsertId(), $amendReq, (int)$track['id']]);
                    }
                }
                // The authorization changed, so the alerts tied to the changed term
                // start over for the new terms (they'd otherwise stay silent forever):
                // expiry alerts when the expiration moved, the 80% hours/funds alerts
                // when hours or a rate changed. Other marks (such as the monthly
                // report job's no-counselor-email notices) are left alone.
                if ($expChanged) {
                    db()->prepare("DELETE FROM sent_alerts WHERE track_id = ? AND alert_key LIKE 'exp%'")->execute([(int)$track['id']]);
                }
                if ($hoursChanged || $rateChanged || $mileageChanged) {
                    db()->prepare("DELETE FROM sent_alerts WHERE track_id = ? AND alert_key IN ('pct80','funds80')")->execute([(int)$track['id']]);
                }
                if ($hoursChanged || $rateChanged || $expChanged || $mileageChanged || $authNoChanged || $agencyChanged) {
                    audit($user['id'], 'auth.amend', 'track', (int)$track['id'],
                        ($hoursChanged ? "hours {$track['authorized_hours']}->{$authHours} " : '')
                        . ($rateChanged ? "rate {$track['hourly_rate']}->{$rate} " : '')
                        . ($mileageChanged ? "mileage {$track['mileage_rate']}->{$mileageRate} " : '')
                        . ($expChanged ? "exp {$track['expiration_date']}->{$exp} " : '')
                        . ($authNoChanged ? "auth# {$track['auth_number']}->{$authNumber} " : '')
                        . ($agencyChanged ? "agency {$track['funding_agency']}->{$agency} " : '')
                        . ($repriced ? "; {$repriced} unbilled or draft log(s) re-priced" : '')
                        . ($draftIds && $repriced ? '; draft invoice(s) #' . implode(', #', $draftIds) . ' re-priced' : '')
                        . ($headerUpdated ? "; {$headerUpdated} draft invoice header(s) updated" : ''));
                }
                $vals[] = $track['id'];
                db()->prepare('UPDATE training_tracks SET title=?, service_type=?, rsa_category=?, bundle_id=?, status=?, auth_number=?, funding_agency=?, authorized_hours=?, hourly_rate=?, mileage_rate=?, start_date=?, expiration_date=?, report_cadence=? WHERE id=?')->execute($vals);
                db()->prepare('UPDATE training_tracks SET billing_mode = ?, authorized_amount = ? WHERE id = ?')->execute([$billingMode, $authAmount, $id]);
                track_mark_ended($id, $status);
                if (($track['billing_mode'] ?? 'vr') !== $billingMode || ($track['authorized_amount'] === null ? null : round((float)$track['authorized_amount'], 2)) !== $authAmount) {
                    audit($user['id'], 'track.billing', 'track', $id, 'billing ' . ($track['billing_mode'] ?? 'vr') . ' -> ' . $billingMode
                        . '; authorized amount ' . ($track['authorized_amount'] ?? 'none') . ' -> ' . ($authAmount ?? 'none'));
                }
                // Each re-priced Draft is re-totalled exactly as generate_invoice()
                // sums it (each line rounded to the cent), and its header names the
                // new rates. Its expenses are unchanged.
                $retotalled = 0;
                if ($draftIds && ($rateChanged || $mileageChanged)) {
                    $headerSet = ($rateChanged ? ', i.hourly_rate = ?' : '') . ($mileageChanged ? ', i.mileage_rate = ?' : '');
                    $headerArgs = array_merge($rateChanged ? [$rate] : [], $mileageChanged ? [$mileageRate] : []);
                    $rt = db()->prepare('UPDATE invoices i
                        JOIN (SELECT w.invoice_id,
                                COALESCE(SUM(ROUND(w.hours * COALESCE(w.hourly_rate, t.hourly_rate), 2)),0) ha,
                                COALESCE(SUM(ROUND(w.mileage * COALESCE(w.mileage_rate, t.mileage_rate), 2)),0) ma
                            FROM work_logs w JOIN training_tracks t ON t.id = w.track_id
                            WHERE w.invoice_id IN (' . implode(',', $draftIds) . ') GROUP BY w.invoice_id) s ON s.invoice_id = i.id
                        SET i.hours_amount = s.ha, i.mileage_amount = s.ma, i.total_amount = s.ha + s.ma + i.expenses_amount' . $headerSet
                        . " WHERE i.status = 'Draft'");
                    $rt->execute($headerArgs);
                    $retotalled = $rt->rowCount();
                }
                db()->commit();
            } catch (Throwable $e) {
                if (db()->inTransaction()) db()->rollBack();
                throw $e;
            }
            audit($user['id'], 'track.update', 'track', (int)$track['id'], $changes ? implode('; ', $changes) : 'no changes (' . $title . ')');
            flash('Training track updated.' . ($amended ? ' The authorization change was recorded in the amendment history.' : '')
                . ($repriced ? ' ' . $repriced . ' work log entr' . ($repriced === 1 ? 'y' : 'ies') . ' not yet invoiced or on a draft invoice now use' . ($repriced === 1 ? 's' : '') . ' the new rate.' : '')
                . ($retotalled ? ' ' . ($retotalled === 1 ? '1 draft invoice was' : $retotalled . ' draft invoices were') . ' re-totalled at the new rate.' : '')
                . ($headerUpdated ? ' ' . ($headerUpdated === 1 ? '1 draft invoice now shows' : $headerUpdated . ' draft invoices now show') . ' the new authorization number and funding agency.' : ''));
            if ($pricingConflict) flash($pricingConflict['message'], 'info');
            redirect('track.php?id=' . (int)$track['id']);
        } else {
            // The same form sent twice (Enter pressed again while it loads) creates
            // the track once; the second answer goes to the track the first created.
            $trackKey = web_idem_key($_POST);
            if (($firstId = web_once_get($trackKey)) !== null) {
                flash('This track was already created (the form was sent twice), so it was saved once.', 'info');
                redirect('track.php?id=' . $firstId);
            }
            db()->prepare('INSERT INTO training_tracks (client_id, title, service_type, rsa_category, bundle_id, status, auth_number, funding_agency, authorized_hours, hourly_rate, mileage_rate, start_date, expiration_date, report_cadence, billing_mode, authorized_amount) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute(array_merge([$clientId], $vals, [$billingMode, $authAmount]));
            // Capture the id before audit() inserts its own row and replaces it.
            $newTrackId = (int)db()->lastInsertId();
            web_once_set($trackKey, $newTrackId);
            track_mark_ended($newTrackId, $status);
            audit($user['id'], 'track.create', 'track', $newTrackId, $title . ' (auth ' . $authNumber . ')');
            // Started from an authorization email (3.0.48): it is done once its track exists.
            $incAuth = (int)($_POST['incoming_auth'] ?? 0);
            if ($incAuth > 0) incoming_authorization_handled($incAuth, 'Track created', $newTrackId, (int)$user['id'], $clientId);
            flash('Training track created.');
            if ($pricingConflict) flash($pricingConflict['message'], 'info');
            redirect('track.php?id=' . $newTrackId);
        }
    }
}

// A new track started from an authorization email (3.0.48, authorizations.php) opens with
// what the email said, for staff to check before saving.
$fromAuth = !$track && $_SERVER['REQUEST_METHOD'] !== 'POST' ? ($_SESSION['auth_prefill'][$clientId] ?? null) : null;
$fromAuthRow = is_array($fromAuth) ? incoming_authorization((int)($fromAuth['incoming_auth'] ?? 0)) : null;
if ($fromAuthRow && $fromAuthRow['status'] === 'New' && (int)$fromAuthRow['client_id'] === $clientId
    && (string)$fromAuth['incoming_auth'] === (string)(int)($_GET['from_auth'] ?? 0)) { $_POST += $fromAuth; unset($_SESSION['auth_prefill'][$clientId]); }
else $fromAuth = null;
$v = fn(string $k, $default = '') => h($_POST[$k] ?? $track[$k] ?? $default);
// A new track starts from the fee quoted when the client's referral came in (3.0.34):
// $0 (free) is No charge, an hourly fee is Private pay at that fee; else VR.
$newMode = 'vr'; $newRate = '';
if (!$track) {
    $qf = db()->prepare('SELECT quoted_fee FROM referrals WHERE client_id = ? AND quoted_fee IS NOT NULL ORDER BY id DESC LIMIT 1');
    $qf->execute([$clientId]);
    $fee = $qf->fetchColumn();
    if ($fee !== false) { $newMode = (float)$fee > 0 ? 'private' : 'none'; $newRate = (float)$fee > 0 ? number_format((float)$fee, 2, '.', '') : ''; }
    // An open VR case is billed at the VR rate (Pricing rules, 4.2.0), whatever was quoted.
    if (pricing_settings()['vr_first'] && client_open_vr_case($clientId)) { $newMode = 'vr'; $newRate = ''; }
}
$curMode = (string)($_POST['billing_mode'] ?? $track['billing_mode'] ?? $newMode);
// The shared helpers render the error summary, each inline error, and the
// aria-invalid / aria-describedby that ties an error to its control.
set_form_errors($errors);
// The stored title (not a refused edit) and the client name, so tabs and headings tell tracks apart.
page_header(($errors ? 'Error — ' : '') . ($track ? 'Edit ' . $track['title'] . ' — ' . $client['name'] : 'Add training track — ' . $client['name']), $user, 'clients', array_merge([['client.php?id=' . (int)$client['id'], $client['name']], ['client.php?id=' . (int)$client['id'] . '&tab=tracks', 'Training Tracks']], $track ? [['track.php?id=' . (int)$track['id'], $track['title']]] : []));
?>
<p class="page-actions"><a href="client.php?id=<?= $clientId ?>&amp;tab=tracks"><span aria-hidden="true">&larr; </span>Back to <?= h($client['name']) ?></a></p>
<h1><?= $track ? 'Edit training track: ' . h($track['title']) . ' for ' . h($client['name']) : 'New training track for ' . h($client['name']) . ($fromAuth ? ', filled in from authorization ' . h((string)$fromAuth['auth_number']) . ': check each field before saving' : '') ?></h1>
<?php // Focus moves to this summary once on load; each entry links to its field.
// (The messages already name their field, so no label is put in front of them.) ?>
<?= form_error_summary(array_fill_keys(array_keys($errors), '')) ?>

<p class="hint">Fields marked * are required.</p>
<form method="post" class="stacked" novalidate>
  <?= csrf_field() ?><?php if (!$track) echo web_idem_field(); ?>
  <?php if (!$track && (int)($_POST['incoming_auth'] ?? 0) > 0): ?><input type="hidden" name="incoming_auth" value="<?= (int)$_POST['incoming_auth'] ?>"><?php endif; ?>
  <fieldset>
    <legend>Track</legend>
    <div class="field">
      <label for="title">Track title <?= required_mark() ?></label>
      <span class="hint" id="title-hint">e.g., “Screen Reader Training — JAWS”, “Independent Living Skills — Kitchen &amp; Home”, or “Braille Literacy”</span>
      <input type="text" id="title" name="title" required maxlength="190" value="<?= $v('title') ?>" <?= field_aria('title', 'title-hint') ?>>
      <?= field_error('title') ?>
    </div>
    <div class="field">
      <label for="service_type">Service type</label>
      <select id="service_type" name="service_type"><?= select_options(cms_choices('service_types', $track['service_type'] ?? null), $_POST['service_type'] ?? $track['service_type'] ?? 'Assistive Technology') ?></select>
    </div>
    <?php
    $clientType = $client['client_type'] ?? 'VR';
    $bundles = bundles_for($clientType);
    $currentBundle = (int)($_POST['bundle_id'] ?? $track['bundle_id'] ?? 0);
    // The track's own bundle when it is no longer offered (retired, or not for this
    // client type): listed disabled so it can't be newly chosen, and selected
    // while it is still the track's bundle, so saving never silently drops it.
    $keptBundle = null;
    if ($track && !empty($track['bundle_id']) && !in_array((int)$track['bundle_id'], array_map(fn($b) => (int)$b['id'], $bundles), true)) {
        $keptBundle = bundle((int)$track['bundle_id']);
    }
    $descBundles = $keptBundle ? array_merge([$keptBundle], $bundles) : $bundles;
    ?>
    <div class="field">
      <label for="bundle_id">Service bundle</label>
      <span class="hint" id="bundle-hint">Bills the RSA program under one label. The chosen bundle's categories are listed after this field.</span>
      <select id="bundle_id" name="bundle_id" <?= field_aria('bundle_id', 'bundle-hint') ?>>
        <option value="0">No bundle (choose a single RSA category below)</option>
        <?php if ($keptBundle): ?>
        <option value="<?= (int)$keptBundle['id'] ?>" disabled <?= $currentBundle === (int)$keptBundle['id'] ? 'selected' : '' ?>>
          <?= h($keptBundle['name']) ?> (<?= $keptBundle['active'] ? 'not offered for ' . h($clientType) . ' clients' : 'retired' ?> — kept on this track until you choose another)
        </option>
        <?php endif; ?>
        <?php foreach ($bundles as $b): ?>
        <option value="<?= (int)$b['id'] ?>" <?= $currentBundle === (int)$b['id'] ? 'selected' : '' ?>>
          <?= h($b['name']) ?>
        </option>
        <?php endforeach; ?>
      </select>
      <?= field_error('bundle_id') ?>
      <?php /* The chosen bundle's categories, as visible text after the field. Not a live
               region and not part of the select's description: arrowing through a native
               select fires a change per option, and announcing the list each time made the
               field far too chatty for a screen reader. The list is read on request, by
               moving to it. */ ?>
      <div id="bundle-desc">
        <?php if ($descBundles): ?><p class="hint"><?= count($bundles) ?> bundle<?= count($bundles) === 1 ? '' : 's' ?> offered for <?= h($clientType) ?> clients; manage them on the Templates page.</p><?php endif; ?>
        <?php foreach ($descBundles as $b): ?>
        <p class="hint" data-bundle-desc="<?= (int)$b['id'] ?>" <?= $currentBundle === (int)$b['id'] ? '' : 'hidden' ?>><?= h($b['name']) ?>: <?= h(implode(' + ', bundle_categories($b))) ?></p>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="field">
      <label for="rsa_category">Single RSA service category</label>
      <span class="hint" id="rsa-hint">Used when no bundle is chosen — the full RSA-911 taxonomy, grouped as RSA groups it</span>
      <select id="rsa_category" name="rsa_category" aria-describedby="rsa-hint">
        <?= rsa_grouped_options($_POST['rsa_category'] ?? $track['rsa_category'] ?? rsa_default_for($_POST['service_type'] ?? $track['service_type'] ?? 'Assistive Technology')) ?>
      </select>
    </div>
    <script>
    // Show the chosen bundle's category list. The list for the bundle selected
    // when the page loads is already rendered, so nothing is announced on load.
    (function () {
        var sel = document.getElementById('bundle_id');
        sel.addEventListener('change', function () {
            document.querySelectorAll('[data-bundle-desc]').forEach(function (el) {
                el.hidden = el.getAttribute('data-bundle-desc') !== sel.value;
            });
        });
    })();
    </script>
    <div class="field">
      <label for="status">Status</label>
      <select id="status" name="status"><?= select_options(TRACK_STATUSES, $_POST['status'] ?? $track['status'] ?? 'Active') ?></select>
    </div>
    <div class="field">
      <label for="report_cadence">Automatic progress reports</label>
      <span class="hint" id="cadence-hint">How often the VR counselor gets an emailed summary</span>
      <select id="report_cadence" name="report_cadence" aria-describedby="cadence-hint">
        <?php $cadCur = $_POST['report_cadence'] ?? $track['report_cadence'] ?? 'monthly';
        foreach (report_cadence_choices($track['report_cadence'] ?? null) as $cv => $cl): ?>
        <option value="<?= h($cv) ?>" <?= $cadCur === $cv ? 'selected' : '' ?>><?= h($cl) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </fieldset>

  <fieldset>
    <legend>Service authorization</legend>
    <div class="field">
      <label for="billing_mode">Who pays for this training</label>
      <select id="billing_mode" name="billing_mode" <?= field_aria('billing_mode', 'billing_mode-hint') ?>>
        <?php foreach (BILLING_MODES as $mk => $ml): ?><option value="<?= h($mk) ?>"<?= $curMode === $mk ? ' selected' : '' ?>><?= h($ml) ?></option><?php endforeach; ?>
      </select>
      <span class="hint" id="billing_mode-hint">VR or agency: needs the authorization below, and work over its dates, hours or dollars is refused until it is amended. Private pay: invoiced to the client. No charge: hours are logged at $0 and never invoiced.</span>
      <?= field_error('billing_mode') ?>
    </div>
    <div class="field">
      <label for="auth_number">Authorization number (required for VR or agency)</label>
      <input type="text" id="auth_number" name="auth_number" maxlength="80" value="<?= $v('auth_number') ?>" <?= field_aria('auth_number') ?>>
      <?= field_error('auth_number') ?>
    </div>
    <div class="field">
      <label for="funding_agency">Funding agency <?= required_mark() ?></label>
      <input type="text" id="funding_agency" name="funding_agency" required maxlength="190" value="<?= $v('funding_agency', 'State VR') ?>" <?= field_aria('funding_agency') ?>>
      <?= field_error('funding_agency') ?>
    </div>
    <div class="field">
      <label for="authorized_hours">Authorized hours, total granted (required for VR or agency)</label>
      <input type="number" id="authorized_hours" name="authorized_hours" step="0.25" min="0" value="<?= $v('authorized_hours') ?>" <?= field_aria('authorized_hours') ?>>
      <?= field_error('authorized_hours') ?>
    </div>
    <div class="field">
      <label for="authorized_amount">Authorized dollar amount (optional)</label>
      <span class="hint" id="authorized_amount-hint">When the authorization states a dollar limit. Left blank, the limit is the authorized hours at the hourly rate. Hours, mileage and expenses all count toward it.</span>
      <input type="text" inputmode="decimal" id="authorized_amount" name="authorized_amount" maxlength="14" value="<?= $v('authorized_amount') ?>" <?= field_aria('authorized_amount', 'authorized_amount-hint') ?>>
      <?= field_error('authorized_amount') ?>
    </div>
    <div class="field">
      <label for="hourly_rate">Hourly billing rate (USD)</label>
      <span class="hint" id="rate-hint">The rate on the authorization (left blank, the agency's own rate), or the personal rate (<?= fmt_money(billing_settings()['private_rate']) ?> an hour). Not used for No charge.</span>
      <input type="number" id="hourly_rate" name="hourly_rate" step="0.01" min="0" value="<?= $v('hourly_rate', $newRate) ?>" <?= field_aria('hourly_rate', 'rate-hint') ?>>
      <?= field_error('hourly_rate') ?>
    </div>
    <div class="field">
      <label for="mileage_rate">Mileage reimbursement rate (USD per mile)</label>
      <span class="hint" id="mileage-hint">Leave 0 if travel is not billable on this authorization</span>
      <input type="number" id="mileage_rate" name="mileage_rate" step="0.001" min="0" value="<?= $v('mileage_rate', '0') ?>" <?= field_aria('mileage_rate', 'mileage-hint') ?>>
      <?= field_error('mileage_rate') ?>
    </div>
    <?php if ($track): ?>
    <div class="field">
      <label for="amendment_reason">Amendment reason</label>
      <span class="hint" id="amend-hint">If you are changing the authorization number, hours, hourly or mileage rate, or expiration, note why (e.g., “VR approved 10 additional hours 7/21”) — kept in the audit history. A new hourly or mileage rate also applies to work logged on this track that is not yet on an invoice, and re-prices and re-totals this track's Draft invoices; a new authorization number or funding agency is shown on those drafts too. Submitted and paid invoices keep what they billed.</span>
      <?php // Opened from an approved amendment request (3.0.44): linked when saved, with the reason filled in.
      $amendReqId = (int)($_GET['amend_request'] ?? old_in('amend_request', '0'));
      $amendReqRow = null;
      if ($amendReqId > 0) { $aq = db()->prepare("SELECT * FROM amendment_requests WHERE id = ? AND track_id = ? AND status IN ('Approved','Partly approved')"); $aq->execute([$amendReqId, (int)$track['id']]); $amendReqRow = $aq->fetch() ?: null; }
      if ($amendReqRow): ?><input type="hidden" name="amend_request" value="<?= (int)$amendReqRow['id'] ?>"><?php endif; ?>
      <input type="text" id="amendment_reason" name="amendment_reason" maxlength="255" value="<?= $amendReqRow && $v('amendment_reason') === '' ? h('VR ' . strtolower($amendReqRow['status']) . ' the amendment requested ' . fmt_date($amendReqRow['requested_on']) . ($amendReqRow['replied_on'] ? ' (reply ' . fmt_date($amendReqRow['replied_on']) . ')' : '')) : $v('amendment_reason') ?>" <?= field_aria('amendment_reason', 'amend-hint') ?>>
      <?= field_error('amendment_reason') ?>
    </div>
    <?php endif; ?>
    <div class="field"><label for="start_date">Start date</label>
      <input type="date" id="start_date" name="start_date" min="2000-01-01" max="<?= ((int)date('Y') + 5) ?>-12-31" value="<?= $v('start_date') ?>" <?= field_aria('start_date') ?>>
      <?= field_error('start_date') ?></div>
    <div class="field">
      <label for="expiration_date">Expiration date</label>
      <input type="date" id="expiration_date" name="expiration_date" min="2000-01-01" max="<?= ((int)date('Y') + 5) ?>-12-31" value="<?= $v('expiration_date') ?>" <?= field_aria('expiration_date') ?>>
      <?= field_error('expiration_date') ?>
    </div>
  </fieldset>

  <div class="page-actions">
    <button type="submit" class="btn btn-primary"><?= $track ? 'Save changes' : 'Create track' ?></button>
    <a class="btn btn-secondary" href="<?= $track ? 'track.php?id=' . $id : 'client.php?id=' . $clientId . '&tab=tracks' ?>">Cancel</a>
  </div>
</form>
<?php page_footer();
