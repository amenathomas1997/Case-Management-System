<?php
// Printable QR labels for equipment. Each label links to equipment.php?scan=ID,
// which opens a quick loan/return/service view when a staff member scans it.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
$user = require_login();
require_perm('equipment');

$items = db()->query("SELECT id, name, serial, category FROM equipment WHERE status <> 'Retired' ORDER BY name")->fetchAll();
$base = rtrim((string)cfg('base_url'), '/');

page_header('Equipment QR labels', $user, 'equipment');
?>
<div class="no-print">
  <p class="page-actions"><a href="equipment.php"><span aria-hidden="true">&larr; </span>Back to equipment</a></p>
  <h1>QR labels</h1>
  <p class="muted">Print this page and stick a label on each item. Scanning a label with any phone camera opens that item's
     quick loan / return / service page (staff sign-in required). <strong>Tip:</strong> scan one label with your phone to
     confirm it reads before printing a full sheet.</p>
  <p><button type="button" class="btn btn-primary" onclick="window.print()">Print</button></p>
</div>

<?php if (!$items): ?>
<p class="muted">No equipment to label yet.</p>
<?php else: ?>
<div class="label-sheet">
  <?php foreach ($items as $it): $url = $base . '/equipment.php?scan=' . (int)$it['id']; ?>
  <div class="label">
    <div class="label-qr" data-url="<?= h($url) ?>" data-label="QR code for <?= h($it['name']) ?>"></div>
    <div class="label-text">
      <strong><a href="equipment.php?scan=<?= (int)$it['id'] ?>"><?= h($it['name']) ?></a></strong>
      <?php if ($it['serial']): ?><br><span class="muted">Serial number <?= h($it['serial']) ?></span><?php endif; ?>
      <br><span class="muted"><?= h($it['category']) ?></span>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<style>
.label-sheet { display: grid; grid-template-columns: repeat(auto-fill, minmax(min(15rem, 100%), 1fr)); gap: .75rem; }
.label { border: 1px solid #999; border-radius: 6px; padding: .5rem; display: flex; flex-wrap: wrap; gap: .6rem; align-items: center; background: #fff; color: #000; break-inside: avoid; }
.label-qr { max-width: 100%; }
.label-qr svg { width: 7rem; max-width: 100%; height: auto; display: block; }
.label-text { font-size: .95rem; }
/* Labels keep a white surface in ordinary themes; custom contrast palettes
   override these colors through styles.css. Printed labels remain black on white. */
.label .muted { color: #3d3d56; }
:root .label a { color: #1e3a8a; }
@media print { .label-sheet { --vision-bg: #fff; --vision-ink: #000; --vision-link: #000; } .no-print { display: none; } .site-header, .site-footer { display: none; } :root .label-sheet .label { border-color: #000; background: #fff !important; }
  :root .label-sheet .label .label-text, :root .label-sheet .label .label-text * { color: #000 !important; background-color: transparent !important; } }
</style>
<script src="assets/qrcode.js"></script>
<script>
document.querySelectorAll('.label-qr').forEach(function (el) {
  try { el.innerHTML = window.QRCode.svg(el.getAttribute('data-url'), { scale: 4, quiet: 2, label: el.getAttribute('data-label') }); }
  catch (e) { el.textContent = el.getAttribute('data-url'); }
});
</script>
<?php page_footer();
