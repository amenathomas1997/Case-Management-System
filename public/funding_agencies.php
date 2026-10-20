<?php
// Funding agencies (administrators, 4.1.0): each VR agency you take referrals from, with its
// state, usual time zone, the email domains its authorizations come from, the vendor number
// it gave you, its payment terms and its billing address. Tracks name their agency as before;
// an agency typed on a track that is not set up here is listed so its details can be added.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
csrf_check();
$user = require_login();
require_perm('admin');

$ready = true;
try { db()->query('SELECT 1 FROM funding_agencies LIMIT 1'); } catch (PDOException $e) { $ready = false; }

if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_agency'])) {
    $str = fn(string $k): string => is_string($_POST[$k] ?? null) ? trim(str_replace("\r", '', $_POST[$k])) : '';
    $aid = (int)($_POST['agency_id'] ?? 0);
    $sfx = '_' . ($aid > 0 ? $aid : 'new');
    $back = 'funding_agencies.php' . ($aid > 0 ? '#agency-' . $aid : '#add-h');
    $errs = [];
    $name = trim((string)preg_replace('/\s+/u', ' ', $str('name')));
    if ($name === '' || mb_strlen($name) > 190) $errs['name' . $sfx] = 'Name the agency (190 characters or fewer).';
    elseif (in_array(agency_key($name), NOT_AGENCIES, true)) $errs['name' . $sfx] = 'Private pay and No charge are not agencies.';
    $state = $str('state') === '' ? null : us_state($str('state'));
    if ($str('state') !== '' && $state === null) $errs['state' . $sfx] = 'Choose a state from the list.';
    $zone = $str('time_zone') === '' ? null : us_zone($str('time_zone'));
    if ($str('time_zone') !== '' && $zone === null) $errs['time_zone' . $sfx] = 'Choose a time zone from the list.';
    $domains = array_values(array_unique(array_filter(array_map(fn($d) => strtolower(trim(ltrim(trim($d), '@'))), explode(',', $str('email_domains'))))));
    foreach ($domains as $d) if (!preg_match('/^(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}$/', $d)) { $errs['email_domains' . $sfx] = 'Write each domain like blind.state.ia.us, separated by commas.'; break; }
    if (mb_strlen(implode(', ', $domains)) > 255) $errs['email_domains' . $sfx] = 'Keep the domains to 255 characters or fewer.';
    $vendor = $str('vendor_number');
    if (mb_strlen($vendor) > 80) $errs['vendor_number' . $sfx] = 'Keep the vendor number to 80 characters or fewer.';
    $terms = null;
    if ($str('terms_days') !== '') {
        if (!preg_match('/^\d{1,3}$/', $str('terms_days')) || (int)$str('terms_days') > 365) $errs['terms_days' . $sfx] = 'Enter the days to pay as a whole number from 0 to 365, or leave it blank.';
        else $terms = (int)$str('terms_days');
    }
    // The rates you charge this agency (4.2.0); blank uses the rate on each authorization.
    $money = function (string $k, float $max, int $dec) use ($str, &$errs, $sfx): ?float {
        $raw = str_replace(['$', ','], '', $str($k));
        if ($raw === '') return null;
        if (!is_numeric($raw) || (float)$raw < 0 || (float)$raw > $max) { $errs[$k . $sfx] = 'Enter the rate in dollars, such as ' . ($dec === 2 ? '40.00' : '0.655') . ', or leave it blank.'; return null; }
        return round((float)$raw, $dec);
    };
    $hourly = $money('hourly_rate', 999999.99, 2);
    $mileage = $money('mileage_rate', 999.999, 3);
    $billTo = $str('bill_to');
    if (mb_strlen($billTo) > 500) $errs['bill_to' . $sfx] = 'Keep the billing address to 500 characters or fewer.';
    $notes = $str('notes');
    if (mb_strlen($notes) > 500) $errs['notes' . $sfx] = 'Keep the notes to 500 characters or fewer.';
    // Its referral deadlines (4.23.0), in business days; blank uses the built-in ones.
    $days = function (string $k, int $max, string $what) use ($str, &$errs, $sfx): ?int {
        if ($str($k) === '') return null;
        if (!preg_match('/^\d{1,3}$/', $str($k)) || (int)$str($k) < 1 || (int)$str($k) > $max) { $errs[$k . $sfx] = $what . ': enter business days as a whole number from 1 to ' . $max . ', or leave it blank.'; return null; }
        return (int)$str($k);
    };
    $contactDays = $days('contact_days', 30, 'Days to contact a new referral');
    $assessDays = $days('assess_days', 90, 'Days to hold the first assessment');
    // Its online vendor portal (4.23.0): where authorizations are accepted and payment requests entered.
    $portalName = $str('portal_name');
    if (mb_strlen($portalName) > 60) $errs['portal_name' . $sfx] = 'Keep the portal name to 60 characters or fewer.';
    $portalUrl = $str('portal_url');
    if ($portalUrl !== '' && (mb_strlen($portalUrl) > 255 || !preg_match('#^https://[^\s<>"]+$#i', $portalUrl) || filter_var($portalUrl, FILTER_VALIDATE_URL) === false)) $errs['portal_url' . $sfx] = 'Enter the portal\'s web address starting with https://, or leave it blank.';
    $active = !empty($_POST['is_active']) ? 1 : 0;
    $old = null;
    if ($aid > 0) {
        $q = db()->prepare('SELECT * FROM funding_agencies WHERE id = ?');
        $q->execute([$aid]);
        $old = $q->fetch();
        if (!$old) { flash('That agency is no longer listed.', 'error'); redirect('funding_agencies.php'); }
    }
    if (!$errs) {
        $clash = db()->prepare('SELECT id FROM funding_agencies WHERE name = ? AND id <> ?');
        $clash->execute([$name, $aid]);
        if ($clash->fetchColumn() !== false) $errs['name' . $sfx] = 'Another agency already has that name.';
    }
    if ($errs) {
        keep_input();
        keep_field_errors($errs);
        redirect($back);
    }
    $vals = [$name, $state, $zone, $domains ? implode(', ', $domains) : null, $vendor !== '' ? $vendor : null, $terms, $hourly, $mileage, $billTo !== '' ? $billTo : null, $notes !== '' ? $notes : null, $active, $contactDays, $assessDays, $portalName !== '' ? $portalName : null, $portalUrl !== '' ? $portalUrl : null];
    $pdo = db();
    $pdo->beginTransaction();
    try {
        if ($old) {
            $pdo->prepare('UPDATE funding_agencies SET name = ?, state = ?, time_zone = ?, email_domains = ?, vendor_number = ?, terms_days = ?, hourly_rate = ?, mileage_rate = ?, bill_to = ?, notes = ?, is_active = ?, contact_days = ?, assess_days = ?, portal_name = ?, portal_url = ? WHERE id = ?')
                ->execute([...$vals, $aid]);
            // A new name follows on its tracks and draft invoices; sent invoices keep the name they were sent with.
            if ($old['name'] !== $name) {
                $pdo->prepare('UPDATE training_tracks SET funding_agency = ? WHERE funding_agency = ?')->execute([$name, $old['name']]);
                $pdo->prepare("UPDATE invoices SET funding_agency = ? WHERE funding_agency = ? AND status = 'Draft'")->execute([$name, $old['name']]);
                // 4.28.0: the old name is remembered, so reports, the VR portal and the Invoices filter still
                // count the invoices already sent under it with this agency.
                agency_rename_record((string)$old['name'], $name);
            }
        } else {
            $pdo->prepare('INSERT INTO funding_agencies (name, state, time_zone, email_domains, vendor_number, terms_days, hourly_rate, mileage_rate, bill_to, notes, is_active, contact_days, assess_days, portal_name, portal_url) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute($vals);
            $aid = (int)$pdo->lastInsertId();
            // Tracks typed with a different capitalisation take the listed spelling.
            $pdo->prepare('UPDATE training_tracks SET funding_agency = ? WHERE funding_agency = ? AND BINARY funding_agency <> BINARY ?')->execute([$name, $name, $name]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    audit($user['id'], $old ? 'agency.save' : 'agency.add', 'settings', null, $name . ($old && $old['name'] !== $name ? ' (was ' . $old['name'] . ')' : ''));
    flash(($old ? 'Saved ' : 'Added ') . $name . '.' . ($old && $old['name'] !== $name ? ' Its tracks and draft invoices now use the new name.' : ''));
    redirect('funding_agencies.php#agency-' . $aid);
}

$agencies = $ready ? db()->query("SELECT a.*, (SELECT COUNT(*) FROM training_tracks t WHERE t.funding_agency = a.name AND t.status = 'Active') AS active_tracks
    FROM funding_agencies a ORDER BY a.is_active DESC, a.name")->fetchAll() : [];
// Names on tracks that are not set up yet (typed on a track or an import).
$listed = array_map(fn($a) => agency_key((string)$a['name']), $agencies);
$unlisted = $ready ? array_values(array_filter(db()->query("SELECT funding_agency AS name, COUNT(*) n FROM training_tracks
    WHERE TRIM(funding_agency) <> '' AND COALESCE(billing_mode, 'vr') = 'vr' GROUP BY funding_agency ORDER BY funding_agency")->fetchAll(),
    fn($r) => !in_array(agency_key((string)$r['name']), $listed, true) && !in_array(agency_key((string)$r['name']), NOT_AGENCIES, true))) : [];

/** The fields of one agency's form; $a is the row (empty for a new one), $sfx keeps ids apart. */
function agency_fields(array $a, string $sfx, bool $mine): string {
    $val = fn(string $k): string => $mine ? old_in($k) : (string)($a[$k] ?? '');
    $id = fn(string $k): string => $k . $sfx;
    ob_start(); ?>
    <div class="field"><label for="<?= $id('name') ?>">Agency name <?= required_mark() ?></label>
      <input type="text" id="<?= $id('name') ?>" name="name" maxlength="190" required aria-required="true" value="<?= h($val('name')) ?>" <?= field_aria('name' . $sfx) ?>><?= field_error('name' . $sfx) ?></div>
    <div class="field"><label for="<?= $id('state') ?>">State</label>
      <select id="<?= $id('state') ?>" name="state" <?= field_aria('state' . $sfx) ?>><?= kv_options(US_STATES, $val('state'), 'Not set') ?></select><?= field_error('state' . $sfx) ?></div>
    <div class="field"><label for="<?= $id('time_zone') ?>">Usual time zone of its clients</label>
      <select id="<?= $id('time_zone') ?>" name="time_zone" <?= field_aria('time_zone' . $sfx) ?>><?= kv_options(US_TIME_ZONES, $val('time_zone'), 'Not set') ?></select><?= field_error('time_zone' . $sfx) ?></div>
    <div class="field"><label for="<?= $id('email_domains') ?>">Email domains its authorizations come from</label>
      <input type="text" id="<?= $id('email_domains') ?>" name="email_domains" maxlength="255" value="<?= h($val('email_domains')) ?>" <?= field_aria('email_domains' . $sfx) ?>><?= field_error('email_domains' . $sfx) ?></div>
    <div class="field"><label for="<?= $id('vendor_number') ?>">Your vendor number with this agency</label>
      <input type="text" id="<?= $id('vendor_number') ?>" name="vendor_number" maxlength="80" value="<?= h($val('vendor_number')) ?>" <?= field_aria('vendor_number' . $sfx) ?>><?= field_error('vendor_number' . $sfx) ?></div>
    <div class="field"><label for="<?= $id('terms_days') ?>">Days it has to pay an invoice</label>
      <input type="number" id="<?= $id('terms_days') ?>" name="terms_days" min="0" max="365" step="1" value="<?= h($val('terms_days')) ?>" <?= field_aria('terms_days' . $sfx) ?>><?= field_error('terms_days' . $sfx) ?></div>
    <div class="field"><label for="<?= $id('hourly_rate') ?>">Your hourly rate for this agency (dollars)</label>
      <input type="text" inputmode="decimal" id="<?= $id('hourly_rate') ?>" name="hourly_rate" maxlength="10" value="<?= h($val('hourly_rate')) ?>" <?= field_aria('hourly_rate' . $sfx) ?>><?= field_error('hourly_rate' . $sfx) ?></div>
    <div class="field"><label for="<?= $id('mileage_rate') ?>">Your mileage rate for this agency (dollars a mile)</label>
      <input type="text" inputmode="decimal" id="<?= $id('mileage_rate') ?>" name="mileage_rate" maxlength="8" value="<?= h($val('mileage_rate')) ?>" <?= field_aria('mileage_rate' . $sfx) ?>><?= field_error('mileage_rate' . $sfx) ?></div>
    <div class="field"><label for="<?= $id('bill_to') ?>">Billing address for invoices</label>
      <textarea id="<?= $id('bill_to') ?>" name="bill_to" rows="3" maxlength="500" <?= field_aria('bill_to' . $sfx) ?>><?= h($val('bill_to')) ?></textarea><?= field_error('bill_to' . $sfx) ?></div>
    <div class="field"><label for="<?= $id('contact_days') ?>">Business days to contact a new referral (blank for 3)</label>
      <input type="number" id="<?= $id('contact_days') ?>" name="contact_days" min="1" max="30" step="1" value="<?= h($val('contact_days')) ?>" <?= field_aria('contact_days' . $sfx) ?>><?= field_error('contact_days' . $sfx) ?></div>
    <div class="field"><label for="<?= $id('assess_days') ?>">Business days to hold the first assessment once a referral is accepted (blank for 10)</label>
      <input type="number" id="<?= $id('assess_days') ?>" name="assess_days" min="1" max="90" step="1" value="<?= h($val('assess_days')) ?>" <?= field_aria('assess_days' . $sfx) ?>><?= field_error('assess_days' . $sfx) ?></div>
    <div class="field"><label for="<?= $id('portal_name') ?>">Name of its vendor portal, such as AWARE</label>
      <input type="text" id="<?= $id('portal_name') ?>" name="portal_name" maxlength="60" value="<?= h($val('portal_name')) ?>" <?= field_aria('portal_name' . $sfx) ?>><?= field_error('portal_name' . $sfx) ?></div>
    <div class="field"><label for="<?= $id('portal_url') ?>">Vendor portal web address</label>
      <input type="url" id="<?= $id('portal_url') ?>" name="portal_url" maxlength="255" value="<?= h($val('portal_url')) ?>" <?= field_aria('portal_url' . $sfx) ?>><?= field_error('portal_url' . $sfx) ?></div>
    <div class="field"><label for="<?= $id('notes') ?>">Notes</label>
      <input type="text" id="<?= $id('notes') ?>" name="notes" maxlength="500" value="<?= h($val('notes')) ?>" <?= field_aria('notes' . $sfx) ?>><?= field_error('notes' . $sfx) ?></div>
    <div class="field"><label><input type="checkbox" name="is_active" value="1"<?= ($mine ? old_in('is_active') !== '' : (int)($a['is_active'] ?? 1) === 1) ? ' checked' : '' ?>> Still working with this agency (its authorization emails are matched to it)</label></div>
    <?php return (string)ob_get_clean();
}

page_header('Funding agencies', $user, 'admin', [['admin.php', 'Administration']]);
?>
<h1>Funding agencies</h1>
<p>The VR agencies you take referrals from. Blank details use Billing details on the Administration page. A client's time zone is set on their record; a referral's state fills it in.</p>
<?php if (!$ready): ?>
<p class="alert alert-info">This page needs migration 4.1.0. Use Database upgrade, then reload.</p>
<?php else: ?>
<?php if ($unlisted): ?>
<section class="card" aria-labelledby="unlisted-h">
  <h2 id="unlisted-h">On tracks but not set up (<?= count($unlisted) ?>)</h2>
  <p>Add each one below with its details, using the same name. If one is a misspelling, add it with the correct name: its tracks change only when the names match apart from capitals.</p>
  <ul><?php foreach ($unlisted as $u): ?><li><?= h($u['name']) ?>: <?= (int)$u['n'] ?> track<?= (int)$u['n'] === 1 ? '' : 's' ?></li><?php endforeach; ?></ul>
</section>
<?php endif; ?>
<?php foreach ($agencies as $a): $aid = (int)$a['id']; $sfx = '_' . $aid; $mine = old_in('agency_id') === (string)$aid; ?>
<section class="card" aria-labelledby="agency-<?= $aid ?>">
  <h2 id="agency-<?= $aid ?>" tabindex="-1"><?= h($a['name']) ?><?= (int)$a['is_active'] === 1 ? '' : ' (no longer working with it)' ?></h2>
  <p><?= h(implode('. ', array_filter([
      $a['state'] ? US_STATES[$a['state']] ?? $a['state'] : 'No state set',
      $a['time_zone'] ? (US_TIME_ZONES[$a['time_zone']] ?? $a['time_zone']) . ' time' : '',
      (int)$a['active_tracks'] . ' active track' . ((int)$a['active_tracks'] === 1 ? '' : 's'),
      $a['vendor_number'] ? 'Vendor number ' . $a['vendor_number'] : 'Vendor number from Billing details',
      $a['terms_days'] !== null ? 'Pays in ' . (int)$a['terms_days'] . ' days' : '',
      $a['hourly_rate'] !== null ? fmt_money((float)$a['hourly_rate']) . ' an hour' : 'Rate from each authorization',
      'Referrals contacted within ' . agency_contact_days($a['name']) . ' business days, assessed within ' . agency_assess_days($a['name']) . ' business days',
      trim((string)($a['portal_name'] ?? '')) !== '' ? 'Portal: ' . $a['portal_name'] : '',
  ]))) ?>.</p>
  <details<?= $mine ? ' open' : '' ?>><summary>Edit <?= h($a['name']) ?></summary>
  <form method="post" action="funding_agencies.php" class="stacked" novalidate><?= csrf_field() ?>
    <input type="hidden" name="agency_id" value="<?= $aid ?>">
    <?= agency_fields($a, $sfx, $mine) ?>
    <button type="submit" name="save_agency" value="1" class="btn btn-secondary">Save<span class="visually-hidden"> <?= h($a['name']) ?></span></button>
  </form>
  </details>
</section>
<?php endforeach; ?>
<section class="card" aria-labelledby="add-h">
  <h2 id="add-h" tabindex="-1">Add an agency</h2>
  <?php $new = old_in('agency_id') === '0'; ?>
  <form method="post" action="funding_agencies.php" class="stacked" novalidate><?= csrf_field() ?>
    <input type="hidden" name="agency_id" value="0">
    <?= agency_fields(['is_active' => 1], '_new', $new) ?>
    <button type="submit" name="save_agency" value="1" class="btn btn-primary">Add agency</button>
  </form>
</section>
<?php endif; ?>
<?php page_footer();
