<?php
// Admin: configure the optional AI drafting assistant (choose the provider).
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
csrf_check();
$user = require_login();
require_perm('admin');

$providers = ai_providers();
$post = fn(string $k): string => trim(is_string($_POST[$k] ?? null) ? $_POST[$k] : '');
$host = fn(string $url): string => strtolower((string)(parse_url($url, PHP_URL_HOST) ?? ''));
$refused = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_ai'])) {
    $enabled = !empty($_POST['ai_enabled']) ? '1' : '0';
    $provider = isset($providers[$post('ai_provider')]) ? $post('ai_provider') : 'anthropic';
    $model = $post('ai_model');
    $baseUrl = $post('ai_base_url');
    // A blank key field leaves the stored key unchanged (so it isn't wiped on edits).
    $key = $post('ai_api_key');
    $errs = [];
    // The base URL is fetched server-side with the API key attached, so it must
    // be an https:// endpoint — reject http/file/other schemes (SSRF/local-file).
    if ($baseUrl !== '' && !preg_match('#^https://#i', $baseUrl)) $errs['ai_base_url'] = 'The API base URL must start with https://';
    // Nor may it reach a service inside the hosting network (ai_http() refuses it too).
    elseif ($baseUrl !== '' && ai_endpoint_ips($baseUrl) === null) $errs['ai_base_url'] = 'The API base URL must be a public internet address: this one could not be found, or it points to a local, private or reserved network address.';
    // Custom has no standard address: enabled without one, the assistant would stay off for everyone.
    elseif ($baseUrl === '' && $provider === 'custom' && $enabled === '1') $errs['ai_base_url'] = 'Enter the API base URL: the Custom provider has no standard address.';
    // The saved key is sent to whatever endpoint is configured, so it never
    // follows a change of provider or host: moving it needs the key typed again.
    $oldProvider = (string)(setting('ai_provider') ?? 'anthropic');
    if (!isset($providers[$oldProvider])) $oldProvider = 'anthropic';
    $oldEndpoint = trim((string)(setting('ai_base_url') ?? '')) ?: $providers[$oldProvider]['endpoint'];
    $newEndpoint = $baseUrl ?: $providers[$provider]['endpoint'];
    $moved = $provider !== $oldProvider || $host($newEndpoint) !== $host($oldEndpoint);
    $hadKey = trim((string)(setting('ai_api_key') ?? '')) !== '';
    if ($moved && $hadKey && $key === '' && !isset($_POST['clear_key'])) {
        $errs['ai_api_key'] = 'Enter the API key again: the provider or API base URL changed, and the saved key is never sent to a different provider or address.';
    }
    if ($errs) {
        // Nothing saved; the form below shows what was typed, with each problem marked.
        set_form_errors($errs);
        $refused = true;
    } else {
        // What changed, for the audit trail (an endpoint move or redaction switch must not look like a routine save).
        $featChanges = [];
        foreach (array_keys(ai_features()) as $fk) {
            $was = (setting('ai_feat_' . $fk) ?? ($fk === 'portal_homework' ? '0' : '1')) !== '0'; $now = !empty($_POST['feat'][$fk]);
            if ($was !== $now) $featChanges[] = $fk . ($now ? ' on' : ' off');
        }
        $redactNow = !empty($_POST['ai_redact']);
        $redactWas = ai_redaction_on();
        // All settings are saved together or not at all, the key first: a failure
        // part-way must never leave the old key paired with a new provider or address.
        db()->beginTransaction();
        try {
            if ($key !== '') set_setting('ai_api_key', $key);
            if (isset($_POST['clear_key'])) set_setting('ai_api_key', '');
            set_setting('ai_enabled', $enabled);
            set_setting('ai_provider', $provider);
            set_setting('ai_model', $model);
            set_setting('ai_base_url', $baseUrl);
            // Per-feature toggles + governance.
            foreach (array_keys(ai_features()) as $fk) set_setting('ai_feat_' . $fk, !empty($_POST['feat'][$fk]) ? '1' : '0');
            set_setting('ai_redact', $redactNow ? '1' : '0');
            set_setting('ai_baa_confirmed', !empty($_POST['ai_baa_confirmed']) ? '1' : '0');
            db()->commit();
        } catch (Throwable $e) {
            if (db()->inTransaction()) db()->rollBack();
            throw $e;
        }
        audit($user['id'], 'ai.config', 'setting', null,
            'enabled=' . $enabled . ' provider=' . $provider . ' host=' . ($host($newEndpoint) ?: '(none)')
            . ($host($newEndpoint) !== $host($oldEndpoint) ? ' (host changed)' : '')
            . ' redact=' . ($redactNow ? 'on' : 'OFF') . ($redactNow !== $redactWas ? ' (changed)' : '')
            . ($key !== '' ? ' (key updated)' : '') . (isset($_POST['clear_key']) ? ' (key cleared)' : '')
            . ' model=' . ($model ?: '(default)')
            . ($featChanges ? ' features: ' . implode(', ', $featChanges) : ''));
        flash('AI assistant settings saved.');
        redirect('ai.php');
    }
}

$hasKey = trim((string)(setting('ai_api_key') ?? '')) !== '';
if ($refused) {
    // Re-show what the admin typed (except the key, which is never echoed).
    $enabled = !empty($_POST['ai_enabled']);
    $featOn = fn(string $fk): bool => !empty($_POST['feat'][$fk]);
    $redactOn = !empty($_POST['ai_redact']);
    $baaOn = !empty($_POST['ai_baa_confirmed']);
} else {
    $enabled = (setting('ai_enabled') ?? '') === '1';
    $provider = (string)(setting('ai_provider') ?? 'anthropic');
    if (!isset($providers[$provider])) $provider = 'anthropic';
    $model = trim((string)(setting('ai_model') ?? ''));
    $baseUrl = trim((string)(setting('ai_base_url') ?? ''));
    $featOn = fn(string $fk): bool => (setting('ai_feat_' . $fk) ?? ($fk === 'portal_homework' ? '0' : '1')) !== '0';
    $redactOn = ai_redaction_on();
    $baaOn = (setting('ai_baa_confirmed') ?? '') === '1';
}
$active = ai_enabled();
// The status line describes what is saved, even while a refused form is re-shown.
$savedProvider = (string)(setting('ai_provider') ?? 'anthropic');
if (!isset($providers[$savedProvider])) $savedProvider = 'anthropic';
$savedEnabled = (setting('ai_enabled') ?? '') === '1';

page_header('AI assistant', $user, 'admin', [['admin.php', 'Administration']]);
?>
<h1>AI drafting assistant</h1>
<p class="muted">Optional. When enabled, staff see “Draft with AI” buttons that generate editable drafts of reports, evaluations, notes, goals, and case summaries.</p>

<div class="alert alert-info" role="note" tabindex="-1">
  <p><strong>What this sends, and where.</strong> When a staff member clicks “Draft with AI,” the relevant client
     information for that record (for example a training track's goals and logged sessions, or an assessment's findings)
     is sent to the AI provider you select below, over an encrypted connection, to generate the draft. Nothing is sent
     automatically — only when someone clicks the button. Every use is recorded in the AI usage log (feature, staff member and
     time), and drafts for a client are also written to the audit log. Choose a provider
     whose data-handling terms you are comfortable with; check whether your plan trains on API data. Drafts are always
     reviewed and edited by staff before anything is saved or sent; the AI never emails a counselor or saves a record on its own.</p>
</div>

<section class="card" aria-labelledby="ai-h" style="max-width: 42rem;">
  <h2 id="ai-h">Settings</h2>
  <form method="post" action="ai.php" class="stacked" novalidate>
    <?= csrf_field() ?>
    <?= form_error_summary(['ai_base_url' => 'API base URL', 'ai_api_key' => 'API key']) ?>
    <div class="field">
      <label><input type="checkbox" name="ai_enabled" value="1" <?= $enabled ? 'checked' : '' ?>>
        Enable the AI drafting assistant <span class="hint">Turning this off hides all “Draft with AI” buttons immediately.</span></label>
    </div>
    <div class="field">
      <label for="ai_provider">AI provider <span class="hint">Pick the company whose API you have a key for. “Custom” lets you point at any OpenAI-compatible endpoint.</span></label>
      <select id="ai_provider" name="ai_provider">
        <?php foreach ($providers as $pid => $p): ?>
        <option value="<?= h($pid) ?>" data-default-model="<?= h($p['default_model']) ?>" <?= $provider === $pid ? 'selected' : '' ?>><?= h($p['label']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label for="ai_model">Model <span class="hint">leave blank to use the provider's default. The model id for the chosen provider (e.g. <code>claude-sonnet-5</code>, <code>gpt-4o</code>, <code>gemini-1.5-pro</code>, <code>grok-2-latest</code>, <code>mistral-large-latest</code>).</span></label>
      <input type="text" id="ai_model" name="ai_model" value="<?= h($model) ?>" autocomplete="off" placeholder="<?= h($providers[$provider]['default_model'] ?: 'model id') ?>">
    </div>
    <div class="field" id="baseurl-field">
      <label for="ai_base_url">API base URL <span class="hint">required for “Custom”, optional override for others. For “Custom”, give the full chat-completions URL (OpenAI-compatible). Leave blank to use the provider's standard endpoint. Changing the provider or this address requires entering the API key again.</span></label>
      <input type="url" id="ai_base_url" name="ai_base_url" value="<?= h($baseUrl) ?>" autocomplete="off" <?= field_aria('ai_base_url') ?> placeholder="https://your-endpoint/v1/chat/completions">
      <?= field_error('ai_base_url') ?>
    </div>
    <div class="field">
      <label for="ai_api_key">API key <span class="hint"><?= $hasKey ? 'A key is saved — leave blank to keep it' : 'From your provider\'s console' ?>. Stored in the private settings table and never shown again or exported by the app. Nightly database backups leave it out, so after restoring a backup enter it again here.</span></label>
      <input type="password" id="ai_api_key" name="ai_api_key" autocomplete="off" <?= field_aria('ai_api_key') ?> <?= $hasKey ? '' : 'placeholder="paste your API key"' ?>>
      <?= field_error('ai_api_key') ?>
    </div>
    <?php if ($hasKey): ?>
    <div class="field"><label><input type="checkbox" name="clear_key" value="1" <?= $refused && isset($_POST['clear_key']) ? 'checked' : '' ?>> Remove the saved key</label></div>
    <?php endif; ?>

    <fieldset>
      <legend>AI features <span class="hint">Turn individual AI features on or off. All are on by default when the assistant is enabled.</span></legend>
      <?php foreach (ai_features() as $fk => $label): $on = $featOn($fk); ?>
      <?php if ($fk === 'readaloud'): $speech = ai_speech_endpoint(); ?>
      <div class="field"><label><input type="checkbox" name="feat[<?= h($fk) ?>]" value="1" <?= $on ? 'checked' : '' ?>> <?= h($label) ?> <span class="hint"><?= $speech !== null
            ? 'Spoken replies are sent to ' . h($host($speech)) . ' (the /audio/speech address beside the configured chat address).'
            : 'Not available with the saved settings: it needs the OpenAI provider with a chat-completions address. Staff hear the browser\'s own voice instead.' ?></span></label></div>
      <?php else: ?>
      <div class="field"><label><input type="checkbox" name="feat[<?= h($fk) ?>]" value="1" <?= $on ? 'checked' : '' ?>> <?= h($label) ?></label></div>
      <?php endif; ?>
      <?php endforeach; ?>
    </fieldset>

    <fieldset>
      <legend>Privacy &amp; governance</legend>
      <div class="field">
        <label><input type="checkbox" name="ai_redact" value="1" <?= $redactOn ? 'checked' : '' ?>> Redact direct identifiers before sending to the provider <span class="hint">Removes names, emails, phone numbers, addresses, dates of birth and SSNs from the context, keeping the functional description. Strongly recommended. Voice assistant requests are sent as spoken, except that client names said in full are replaced and emails, phone numbers and SSNs are removed; a first name said alone and dictated note text are sent as said.</span></label>
      </div>
      <div class="field">
        <label><input type="checkbox" name="ai_baa_confirmed" value="1" <?= $baaOn ? 'checked' : '' ?>> I have a data-handling agreement with this provider (for example a business associate agreement, BAA), or I send it no protected health information (PHI) <span class="hint">A reminder for your own records — this box does not change what is sent.</span></label>
      </div>
    </fieldset>

    <button type="submit" name="save_ai" value="1" class="btn btn-primary">Save settings</button>
    <a class="btn btn-secondary" href="admin.php#forms-h">Back to My Account</a>
  </form>
  <p class="muted" style="margin-top:1rem;">Status: <strong><?= $active ? 'Active (' . h($providers[$savedProvider]['label']) . ')' : ($savedEnabled ? 'Enabled, but not fully configured (needs an API key' . ($savedProvider === 'custom' ? ' and base URL' : '') . ')' : 'Off') ?></strong>
    <?= ai_redaction_on() ? ' · <span class="badge badge-closed">Redaction on</span>' : ' · <span class="badge badge-pending">Redaction off</span>' ?></p>
</section>

<?php
// Usage (metadata only — the system never stores prompts or AI outputs).
$usage = [];
try {
    $usage = db()->query("SELECT feature, COUNT(*) n, MAX(created_at) last_used, SUM(redacted) redacted_n
        FROM ai_usage WHERE created_at > DATE_SUB(NOW(), INTERVAL 30 DAY) GROUP BY feature ORDER BY n DESC")->fetchAll();
} catch (Throwable $e) { $usage = []; }
$total = array_sum(array_map(fn($r) => (int)$r['n'], $usage));
?>
<section class="card" aria-labelledby="usage-h" style="max-width: 42rem;">
  <h2 id="usage-h">AI usage (last 30 days)</h2>
  <p class="muted">Metadata only — the system logs which feature ran, when, and how much text, but never the prompt or the AI's output. <?= $total ?> call<?= $total === 1 ? '' : 's' ?> total.</p>
  <?php if ($usage): ?>
  <div class="table-scroll" tabindex="0" role="region" aria-labelledby="usage-cap">
  <table>
    <caption class="visually-hidden" id="usage-cap">AI usage by feature, last 30 days</caption>
    <thead><tr><th scope="col">Feature</th><th scope="col" class="num">Calls</th><th scope="col" class="num">Redacted</th><th scope="col">Last used</th></tr></thead>
    <tbody>
    <?php $labels = ai_features(); foreach ($usage as $u): ?>
      <tr><td><?= h($labels[$u['feature']] ?? $u['feature']) ?></td>
        <td class="num"><?= (int)$u['n'] ?></td>
        <td class="num"><?= (int)$u['redacted_n'] ?>/<?= (int)$u['n'] ?><?= $u['feature'] === 'voice' && (int)$u['redacted_n'] > 0 ? ' (partial)' : '' ?></td>
        <td><?= fmt_datetime($u['last_used']) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php else: ?><p class="muted">No AI calls in the last 30 days.</p><?php endif; ?>
</section>
<script>
(function(){
  // Show the model's default placeholder for the chosen provider; base URL is
  // required only for Custom.
  var prov=document.getElementById('ai_provider'), model=document.getElementById('ai_model'),
      baseField=document.getElementById('baseurl-field'), baseInput=document.getElementById('ai_base_url');
  function sync(){
    var opt=prov.options[prov.selectedIndex], def=opt.getAttribute('data-default-model')||'model id';
    model.placeholder=def;
    baseField.style.display = prov.value==='custom' ? '' : '';
    baseInput.required = prov.value==='custom';
  }
  prov.addEventListener('change',sync); sync();
})();
</script>
<?php page_footer();
