<?php
// Pricing rules (administrators, 4.2.0): who pays, and how much, so any AT business can set
// its own. The rate a VR agency pays is on each authorization (Edit track / authorization).
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
csrf_check();
$user = require_login();
require_perm('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_pricing'])) {
    $in = fn(string $k): string => trim(is_string($_POST[$k] ?? null) ? $_POST[$k] : '');
    $errs = [];
    $rate = str_replace(['$', ','], '', $in('personal_rate'));
    if (!is_numeric($rate) || (float)$rate <= 0 || (float)$rate > 999.99) $errs['personal_rate'] = 'Enter the personal hourly rate as an amount more than $0, such as 17.50.';
    $limit = str_replace(['$', ','], '', $in('free_below'));
    if (!is_numeric($limit) || (float)$limit <= 0 || (float)$limit > 1000000) $errs['free_below'] = 'Enter the monthly income below which training is free, such as 2000.';
    $age = $in('youth_age');
    if (!ctype_digit($age) || (int)$age > 30) $errs['youth_age'] = 'Enter an age from 0 to 30, such as 18. 0 means a parent\'s income is never used.';
    $conflict = $in('vr_conflict');
    if (!isset(PRICING_CONFLICT_CHOICES[$conflict])) $errs['vr_conflict'] = 'Choose what happens from the list.';
    $ask = $in('ask');
    if (!isset(PRICING_ASK_CHOICES[$ask])) $errs['ask'] = 'Choose who is asked from the list.';
    if ($errs) { keep_input(); keep_field_errors($errs); redirect('pricing.php'); }
    $vals = [
        'billing_private_rate' => number_format((float)$rate, 2, '.', ''),
        'billing_private_threshold' => number_format((float)$limit, 2, '.', ''),
        'pricing_sliding_on' => !empty($_POST['sliding_on']) ? '1' : '0',
        'pricing_vr_first' => !empty($_POST['vr_first']) ? '1' : '0',
        'pricing_youth_age' => (string)(int)$age,
        'pricing_vr_conflict' => $conflict,
        'pricing_ask' => $ask,
    ];
    foreach ($vals as $k => $v) set_setting($k, $v);
    audit($user['id'], 'settings.pricing', 'settings', null, implode(', ', array_map(fn($k, $v) => $k . '=' . $v, array_keys($vals), $vals)));
    flash('Pricing rules saved. New referrals and tracks use them from now on.');
    redirect('pricing.php');
}

$p = pricing_settings();
$kept = old_in('save_pricing') !== '';
$val = fn(string $k, string $cur): string => $kept ? old_in($k) : $cur;
$on = fn(string $k, bool $cur): bool => $kept ? old_in($k) !== '' : $cur;

page_header('Pricing rules', $user, 'admin', [['admin.php', 'Administration']]);
?>
<h1>Pricing rules</h1>
<p>Who pays for training, and how much. Each VR agency's own rate is listed below; a rate on an authorization is used for that track. Changes apply to new referrals and tracks, not to invoices already made.</p>
<form method="post" action="pricing.php" class="stacked" novalidate><?= csrf_field() ?>
  <input type="hidden" name="save_pricing" value="1">
  <?= form_error_summary(['personal_rate' => 'Personal hourly rate', 'free_below' => 'Free below this monthly income', 'youth_age' => 'Youth age',
      'vr_conflict' => 'Open VR case and other pricing', 'ask' => 'Who is asked'], ['personal_rate' => 'personal_rate', 'free_below' => 'free_below',
      'youth_age' => 'youth_age', 'vr_conflict' => 'vr_conflict', 'ask' => 'ask']) ?>
  <section class="card" aria-labelledby="personal-h">
    <h2 id="personal-h">Personal cases</h2>
    <div class="field"><label for="personal_rate">Personal hourly rate (dollars)</label>
      <input type="text" inputmode="decimal" id="personal_rate" name="personal_rate" maxlength="7" value="<?= h($val('personal_rate', number_format($p['personal_rate'], 2, '.', ''))) ?>" <?= field_aria('personal_rate') ?>><?= field_error('personal_rate') ?></div>
  </section>
  <section class="card" aria-labelledby="sliding-h">
    <h2 id="sliding-h">Sliding fee</h2>
    <div class="field"><label><input type="checkbox" name="sliding_on" value="1"<?= $on('sliding_on', $p['sliding_on']) ? ' checked' : '' ?>> Free training below a monthly income</label></div>
    <div class="field"><label for="free_below">Free below this monthly income (dollars)</label>
      <input type="text" inputmode="decimal" id="free_below" name="free_below" maxlength="10" value="<?= h($val('free_below', number_format($p['free_below'], 0, '.', ''))) ?>" <?= field_aria('free_below') ?>><?= field_error('free_below') ?></div>
    <div class="field"><label for="youth_age">Use a parent's income for anyone younger than (years; 0 for never)</label>
      <input type="number" id="youth_age" name="youth_age" min="0" max="30" step="1" value="<?= h($val('youth_age', (string)$p['youth_age'])) ?>" <?= field_aria('youth_age') ?>><?= field_error('youth_age') ?></div>
  </section>
  <section class="card" aria-labelledby="vr-h">
    <h2 id="vr-h">Open VR cases</h2>
    <div class="field"><label><input type="checkbox" name="vr_first" value="1"<?= $on('vr_first', $p['vr_first']) ? ' checked' : '' ?>> Someone with an open VR case is billed at the VR rate, not free or personal</label></div>
    <div class="field"><label for="vr_conflict">When free or personal-rate training is set up for someone with an open VR case</label>
      <select id="vr_conflict" name="vr_conflict" <?= field_aria('vr_conflict') ?>><?php foreach (PRICING_CONFLICT_CHOICES as $k => $label): ?><option value="<?= h($k) ?>"<?= $val('vr_conflict', $p['vr_conflict']) === $k ? ' selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?></select><?= field_error('vr_conflict') ?></div>
  </section>
  <section class="card" aria-labelledby="ask-h">
    <h2 id="ask-h">The referral form</h2>
    <div class="field"><label for="ask">Who is asked about income and an open VR case</label>
      <select id="ask" name="ask" <?= field_aria('ask') ?>><?php foreach (PRICING_ASK_CHOICES as $k => $label): ?><option value="<?= h($k) ?>"<?= $val('ask', $p['ask']) === $k ? ' selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?></select><?= field_error('ask') ?></div>
  </section>
  <button type="submit" class="btn btn-primary">Save pricing rules</button>
</form>
<section class="card" aria-labelledby="agency-rates-h">
  <h2 id="agency-rates-h">VR agency rates</h2>
  <?php $agencyRates = array_values(array_filter(agencies_all(), fn($a) => (int)$a['is_active'] === 1)); ?>
  <?php if (!$agencyRates): ?><p>No agencies are listed yet. Add them on <a href="funding_agencies.php">Funding agencies</a>.</p>
  <?php else: ?>
  <table class="table">
    <caption>The rates you charge each VR agency; change them on <a href="funding_agencies.php">Funding agencies</a></caption>
    <thead><tr><th scope="col">Agency</th><th scope="col">Hourly rate</th><th scope="col">Mileage rate</th></tr></thead>
    <tbody>
    <?php foreach ($agencyRates as $a): ?>
      <tr><th scope="row"><?= h($a['name']) ?></th><td><?= $a['hourly_rate'] !== null ? h(fmt_money((float)$a['hourly_rate'])) : 'From each authorization' ?></td><td><?= $a['mileage_rate'] !== null ? h(fmt_mileage_rate((float)$a['mileage_rate'])) : 'From each authorization' ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</section>
<?php page_footer();
