<?php
// Accommodation bookings: schedule sign-language interpreters, CART captioning,
// and transportation for clients' sessions and appointments, and track each
// from Requested → Confirmed → Completed (or Cancelled). Caseload-scoped;
// gated by the 'schedule' permission.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
csrf_check();
$user = require_login();
require_perm('schedule');

const BOOKING_TYPES = ['Interpreter', 'CART captioning', 'Transportation', 'Note-taker', 'Other'];
const BOOKING_STATUSES = ['Requested', 'Confirmed', 'Completed', 'Cancelled'];
// What each stored service type is called on screen (CART is spelled out).
const BOOKING_TYPE_LABELS = ['CART captioning' => 'CART (real-time captioning)'];

/** When a booking is needed, as spoken in labels and messages. */
function booking_when(?string $neededAt): string {
    return $neededAt ? date('D M j, Y g:i A', strtotime($neededAt)) : 'no date set';
}

function booking_badge(string $s): string {
    $map = ['Requested' => 'badge-pending', 'Confirmed' => 'badge-active', 'Completed' => 'badge-closed', 'Cancelled' => 'badge-hold'];
    return '<span class="badge ' . ($map[$s] ?? 'badge-pending') . '">' . h($s) . '</span>';
}

/* ---- Create ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_booking'])) {
    $cid = (int)($_POST['client_id'] ?? 0);
    // Caseload scope first: an out-of-scope client is refused outright.
    if ($cid > 0) scope_check_client_id($cid, $user);
    $in = fn(string $k): string => is_string($_POST[$k] ?? null) ? trim($_POST[$k]) : '';
    $cn = db()->prepare('SELECT name FROM clients WHERE id = ?');
    $cn->execute([$cid]);
    $clientName = $cid > 0 ? $cn->fetchColumn() : false;
    // Every field is checked and refused on the field, never cut, clamped or
    // cleared. A partly typed date and time arrives blank from the browser with a
    // needed_at_bad marker from the page script, so it is not saved as "no date".
    $errors = [];
    if ($clientName === false) $errors['client_id'] = 'Choose a client.';
    $needed = $in('needed_at');
    $neededAt = null;
    if (!empty($_POST['needed_at_bad'])) {
        $errors['needed_at'] = 'Enter the full date and time (month, day, year, hour, minutes and AM or PM), for example 10/14/2026 2:30 PM, or leave it all blank.';
    } elseif ($needed !== '') {
        $dt = DateTime::createFromFormat('!Y-m-d\TH:i', substr($needed, 0, 16));
        if ($dt === false || $dt->format('Y-m-d\TH:i') !== substr($needed, 0, 16) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d+)?)?$/', $needed)) {
            $errors['needed_at'] = 'Enter the date and time as a real date and time, for example 10/14/2026 2:30 PM.';
        } elseif ((int)$dt->format('Y') < 2000 || (int)$dt->format('Y') > (int)date('Y') + 5) {
            // The same year range as the app (api_datetime): a year typed as 26
            // (stored as 0026) or 2062 is refused, never booked out of sight.
            $errors['needed_at'] = 'Enter a year from 2000 to ' . ((int)date('Y') + 5) . ' (four digits), for example 10/14/2026 2:30 PM.';
        } else {
            $neededAt = $dt->format('Y-m-d H:i:s');
        }
    }
    $durIn = $in('duration_min');
    if (!empty($_POST['duration_min_bad']) || ($durIn !== '' && (!ctype_digit($durIn) || (int)$durIn > 1440))) {
        $errors['duration_min'] = 'Enter the duration as whole minutes, from 0 to 1440, or leave it blank.';
    }
    $costIn = $in('cost');
    if (!empty($_POST['cost_bad']) || ($costIn !== '' && (!preg_match('/^\d{1,8}(\.\d{1,2})?$/', $costIn)))) {
        $errors['cost'] = 'Enter the estimated cost in dollars and cents, for example 125.50 (no more than 99,999,999.99), or leave it blank.';
    }
    foreach (['provider' => [190, 'Provider / agency'], 'location' => [255, 'Location'], 'notes' => [500, 'Notes']] as $k => [$max, $label]) {
        if (mb_strlen($in($k)) > $max) $errors[$k] = $label . ' is too long (' . $max . ' characters maximum).';
    }
    if ($errors) { keep_input(); keep_field_errors($errors); redirect('access_bookings.php#new-h'); }
    $serviceType = in_array($_POST['service_type'] ?? '', BOOKING_TYPES, true) ? $_POST['service_type'] : 'Other';
    $provider = $in('provider') !== '' ? $in('provider') : null;
    $location = $in('location') !== '' ? $in('location') : null;
    $notes = $in('notes') !== '' ? $in('notes') : null;
    $duration = $durIn !== '' ? (int)$durIn : 0;
    $cost = $costIn !== '' ? $costIn : null;
    // A repeated submit (Create booking pressed again while the first was still
    // saving) finds the booking it already made and adds nothing: the same booking,
    // field for field, made by this user in the last 10 minutes and not cancelled.
    $dup = db()->prepare("SELECT id FROM access_bookings WHERE client_id = ? AND service_type = ? AND needed_at <=> ? AND duration_min = ?
        AND provider <=> ? AND location <=> ? AND cost <=> ? AND notes <=> ? AND created_by = ? AND status <> 'Cancelled'
        AND created_at > DATE_SUB(NOW(), INTERVAL 10 MINUTE) LIMIT 1");
    $dup->execute([$cid, $serviceType, $neededAt, $duration, $provider, $location, $cost, $notes, $user['id']]);
    if ($dup->fetchColumn()) {
        flash('That ' . (BOOKING_TYPE_LABELS[$serviceType] ?? $serviceType) . ' booking for ' . $clientName . ' (' . booking_when($neededAt)
            . ') was already created a moment ago, so it was not added again. To book a second one for the same time, add a note that tells them apart.', 'info');
        redirect('access_bookings.php');
    }
    db()->prepare('INSERT INTO access_bookings (client_id, service_type, provider, needed_at, duration_min, location, status, cost, notes, created_by) VALUES (?,?,?,?,?,?,?,?,?,?)')
        ->execute([$cid, $serviceType, $provider, $neededAt, $duration, $location, 'Requested', $cost, $notes, $user['id']]);
    // The stored (validated) type: a malformed post can never make the audit call
    // throw after the booking was written.
    audit($user['id'], 'booking.add', 'access_booking', (int)db()->lastInsertId(), $serviceType);
    // Name what was booked, and when, so a missing date is noticed at once.
    flash((BOOKING_TYPE_LABELS[$serviceType] ?? $serviceType) . ' booking created for ' . $clientName . ': ' . booking_when($neededAt) . '.');
    redirect('access_bookings.php');
}

/* ---- Status change ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_status'])) {
    $bid = (int)($_POST['booking_id'] ?? 0);
    $back = 'access_bookings.php' . (in_array($_POST['view'] ?? '', ['past', 'cancelled'], true) ? '?view=' . $_POST['view'] : '');
    $b = db()->prepare('SELECT ab.id, ab.service_type, ab.needed_at, ab.status, c.name AS client_name, c.assigned_to FROM access_bookings ab JOIN clients c ON c.id = ab.client_id WHERE ab.id = ?');
    $b->execute([$bid]);
    if ($row = $b->fetch()) {
        if (caseload_scoped() && (int)$row['assigned_to'] !== (int)$user['id']) { flash('Not your caseload.', 'error'); redirect('access_bookings.php'); }
        $to = in_array($_POST['to'] ?? '', BOOKING_STATUSES, true) ? $_POST['to'] : null;
        if ($to) {
            db()->prepare('UPDATE access_bookings SET status = ? WHERE id = ?')->execute([$to, $bid]);
            audit($user['id'], 'booking.status', 'access_booking', $bid, $row['status'] . ' → ' . $to);
            // Name the booking and the change, and say where a cancelled one went.
            flash($row['service_type'] . ' booking for ' . $row['client_name'] . ' (' . booking_when($row['needed_at']) . ') set to ' . $to . '.'
                . ($to === 'Cancelled' ? ' It is now listed under "Show cancelled", where it can be restored.' : ''));
        } else {
            flash('Choose a status for the booking.', 'error');
        }
    }
    redirect($back . '#bookings-h');
}

$scoped = caseload_scoped();
$scopeCond = $scoped ? ' AND c.assigned_to = ?' : '';
$scopeArg = $scoped ? [(int)$user['id']] : [];
// Views: upcoming (default), every non-cancelled booking ("past"), or the
// cancelled ones, which can be set back to an earlier status from there.
$view = in_array($_GET['view'] ?? '', ['past', 'cancelled'], true) ? $_GET['view'] : (!empty($_GET['past']) ? 'past' : 'upcoming');
$where = [
    'upcoming' => "ab.status <> 'Cancelled' AND ((ab.needed_at IS NULL AND ab.status <> 'Completed') OR ab.needed_at >= DATE_SUB(NOW(), INTERVAL 1 DAY))",
    'past' => "ab.status <> 'Cancelled'",
    'cancelled' => "ab.status = 'Cancelled'",
][$view];
// Upcoming lists the nearest first; the longer lists put the newest first, so
// recent bookings are never the ones cut off by the limit.
$order = $view === 'upcoming' ? 'ab.needed_at IS NULL, ab.needed_at' : 'ab.needed_at IS NULL, ab.needed_at DESC';
$sql = "SELECT ab.*, c.name AS client_name, c.id AS cid, u.name AS by_name
    FROM access_bookings ab JOIN clients c ON c.id = ab.client_id LEFT JOIN users u ON u.id = ab.created_by
    WHERE {$where}{$scopeCond}
    ORDER BY {$order}, ab.id DESC LIMIT 301";
$st = db()->prepare($sql);
$st->execute($scopeArg);
$rows = $st->fetchAll();
$truncated = count($rows) > 300;
$rows = array_slice($rows, 0, 300);
$unconfirmed = count(array_filter($rows, fn($r) => $r['status'] === 'Requested'));

$cq = db()->prepare("SELECT id, name FROM clients WHERE status NOT LIKE 'Closed%'" . ($scoped ? ' AND assigned_to = ?' : '') . ' ORDER BY name');
$cq->execute($scoped ? [(int)$user['id']] : []);
$clients = $cq->fetchAll();

page_header('Accommodation bookings', $user, 'schedule');
?>
<p><a href="schedule.php"><span aria-hidden="true">&larr; </span>Schedule</a></p>
<h1>Interpreter, CART (real-time captioning) &amp; transport bookings</h1>
<p class="muted">Schedule communication-access and transportation accommodations for sessions and appointments<?= $scoped ? ' (your caseload)' : '' ?>.
  CART (Communication Access Realtime Translation) is live captioning typed by a trained captioner.</p>

<div class="grid grid-4">
  <div class="card stat"><span class="stat-num"><?= count($rows) ?></span><span class="stat-label"><?= ['upcoming' => 'Upcoming', 'past' => 'Shown', 'cancelled' => 'Cancelled'][$view] ?></span></div>
  <?php if ($view !== 'cancelled'): ?><div class="card stat"><span class="stat-num"><?= $unconfirmed ?></span><span class="stat-label">Not yet confirmed<?= $view === 'past' ? ' (shown)' : '' ?></span></div><?php endif; ?>
</div>

<section class="card" aria-labelledby="new-h">
  <h2 id="new-h">New booking</h2>
  <form method="post" action="access_bookings.php" class="stacked" novalidate><?= csrf_field() ?>
    <?= form_error_summary(['client_id' => 'Client', 'needed_at' => 'When', 'duration_min' => 'Duration (minutes)', 'provider' => 'Provider / agency',
        'location' => 'Location', 'cost' => 'Estimated cost', 'notes' => 'Notes']) ?>
    <div class="grid grid-4">
      <div class="field"><label for="client_id">Client <span aria-hidden="true">*</span><span class="visually-hidden">(required)</span></label>
        <select id="client_id" name="client_id" required <?= field_aria('client_id') ?>>
          <option value="">— Choose —</option>
          <?php foreach ($clients as $c): ?><option value="<?= (int)$c['id'] ?>" <?= old_in('client_id') === (string)$c['id'] ? 'selected' : '' ?>><?= h($c['name']) ?></option><?php endforeach; ?>
        </select><?= field_error('client_id') ?></div>
      <?php $pickType = old_in('service_type', 'Interpreter'); ?>
      <div class="field"><label for="service_type">Service</label><select id="service_type" name="service_type">
        <?php foreach (BOOKING_TYPES as $bt): ?><option value="<?= h($bt) ?>"<?= $bt === $pickType ? ' selected' : '' ?>><?= h(BOOKING_TYPE_LABELS[$bt] ?? $bt) ?></option><?php endforeach; ?>
      </select></div>
      <div class="field"><label for="needed_at">When <span class="hint">date and time; leave blank if not known yet</span></label><input type="datetime-local" id="needed_at" name="needed_at" min="2000-01-01T00:00" max="<?= (int)date('Y') + 5 ?>-12-31T23:59" value="<?= h(old_in('needed_at')) ?>" <?= field_aria('needed_at') ?>><?= field_error('needed_at') ?></div>
      <div class="field"><label for="duration_min">Duration (minutes)</label><input type="number" id="duration_min" name="duration_min" min="0" max="1440" step="1" value="<?= h(old_in('duration_min', '60')) ?>" <?= field_aria('duration_min') ?>><?= field_error('duration_min') ?></div>
    </div>
    <div class="grid grid-4">
      <div class="field"><label for="provider">Provider / agency</label><input type="text" id="provider" name="provider" maxlength="190" value="<?= h(old_in('provider')) ?>" <?= field_aria('provider') ?>><?= field_error('provider') ?></div>
      <div class="field"><label for="location">Location</label><input type="text" id="location" name="location" maxlength="255" value="<?= h(old_in('location')) ?>" <?= field_aria('location') ?>><?= field_error('location') ?></div>
      <div class="field"><label for="cost">Estimated cost (dollars)</label><input type="number" id="cost" name="cost" step="0.01" min="0" value="<?= h(old_in('cost')) ?>" <?= field_aria('cost') ?>><?= field_error('cost') ?></div>
      <div class="field"><label for="notes">Notes</label><input type="text" id="notes" name="notes" maxlength="500" value="<?= h(old_in('notes')) ?>" <?= field_aria('notes') ?>><?= field_error('notes') ?></div>
    </div>
    <script>
    // A date, time or number the browser cannot read (such as a When with the AM/PM
    // part missing) is sent blank; mark it so the server says so on the field
    // instead of saving the booking with no date.
    document.currentScript.closest('form').addEventListener('submit', function (e) {
      var f = e.currentTarget;
      f.querySelectorAll('input[data-bad-marker]').forEach(function (m) { m.remove(); });
      f.querySelectorAll('input[type=datetime-local],input[type=number]').forEach(function (i) {
        if (i.name && i.validity && i.validity.badInput) {
          var m = document.createElement('input');
          m.type = 'hidden'; m.name = i.name + '_bad'; m.value = '1'; m.setAttribute('data-bad-marker', '');
          f.appendChild(m);
        }
      });
    });
    </script>
    <button type="submit" name="add_booking" value="1" class="btn btn-primary">Create booking</button>
  </form>
</section>

<div class="page-actions">
  <?php if ($view !== 'upcoming'): ?><a class="btn btn-secondary btn-sm" href="access_bookings.php">Upcoming only</a><?php endif; ?>
  <?php if ($view !== 'past'): ?><a class="btn btn-secondary btn-sm" href="access_bookings.php?view=past">Include past</a><?php endif; ?>
  <?php if ($view !== 'cancelled'): ?><a class="btn btn-secondary btn-sm" href="access_bookings.php?view=cancelled">Show cancelled</a><?php endif; ?>
</div>
<?php if ($truncated): ?><p class="muted">Only the <?= $view === 'upcoming' ? 'next' : 'most recent' ?> 300 bookings are listed here.</p><?php endif; ?>

<h2 id="bookings-h"><?= ['upcoming' => 'Upcoming bookings', 'past' => 'Bookings, including past ones', 'cancelled' => 'Cancelled bookings'][$view] ?></h2>
<div class="table-scroll" tabindex="0" role="region" aria-label="Accommodation bookings">
<table>
  <caption class="visually-hidden"><?= ['upcoming' => 'Upcoming accommodation bookings', 'past' => 'Accommodation bookings, including past ones', 'cancelled' => 'Cancelled accommodation bookings'][$view] ?></caption>
  <thead><tr><th scope="col">When</th><th scope="col">Client</th><th scope="col">Service, location and notes</th><th scope="col">Provider</th><th scope="col">Status</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead>
  <tbody>
  <?php if (!$rows): ?><tr><td colspan="6" class="muted">No <?= ['upcoming' => 'upcoming ', 'past' => '', 'cancelled' => 'cancelled '][$view] ?>bookings.</td></tr><?php endif; ?>
  <?php foreach ($rows as $r):
    // Hidden text for the row's controls: a client can have several bookings for
    // the same service, so the date tells them apart.
    $which = h($r['client_name']) . ', ' . h($r['service_type']) . ', ' . h(booking_when($r['needed_at'])); ?>
    <tr>
      <td><?= $r['needed_at'] ? h(booking_when($r['needed_at'])) : '<span class="muted">—</span>' ?><?= $r['duration_min'] ? '<br><span class="muted">' . (int)$r['duration_min'] . ' minutes</span>' : '' ?></td>
      <?php // The client links to their record only for staff who can open it (this page needs only scheduling). ?>
      <td><?php if (can('clients')): ?><a href="client.php?id=<?= (int)$r['cid'] ?>"><?= h($r['client_name']) ?></a><?php else: ?><?= h($r['client_name']) ?><?php endif; ?></td>
      <?php // The booking's notes (access needs, where to meet) are shown with it, line breaks kept. ?>
      <td><?= h(BOOKING_TYPE_LABELS[$r['service_type']] ?? $r['service_type']) ?><?= $r['location'] ? '<br><span class="muted">' . h($r['location']) . '</span>' : '' ?><?= ($r['notes'] ?? '') !== '' ? '<br>Notes: ' . nl2br(h($r['notes'])) : '' ?></td>
      <td><?= h($r['provider'] ?? '—') ?><?= $r['cost'] !== null ? '<br><span class="muted">' . fmt_money((float)$r['cost']) . '</span>' : '' ?></td>
      <td><?= booking_badge($r['status']) ?></td>
      <td><form method="post" action="access_bookings.php" class="inline-form"><?= csrf_field() ?>
        <input type="hidden" name="booking_id" value="<?= (int)$r['id'] ?>">
        <input type="hidden" name="view" value="<?= h($view) ?>">
        <label class="visually-hidden" for="bs-<?= (int)$r['id'] ?>">Set status for <?= $which ?></label>
        <select id="bs-<?= (int)$r['id'] ?>" name="to"><?= select_options(BOOKING_STATUSES, $r['status']) ?></select>
        <button type="submit" name="set_status" value="1" class="btn btn-secondary btn-sm"
          onclick="var s=document.getElementById('bs-<?= (int)$r['id'] ?>').value;return s!=='Cancelled'||s===<?= h(json_encode((string)$r['status'], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP)) ?>||confirm('Set this booking to Cancelled? It moves to the cancelled list, where it can be restored. Choose OK to cancel the booking, or Cancel to keep it as it is.');">Save<span class="visually-hidden"> status for <?= $which ?></span></button>
      </form></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php page_footer();
