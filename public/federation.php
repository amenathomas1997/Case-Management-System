<?php
// Admin GUI for Microsoft Entra ID federation (single sign-on).
// Settings are stored in the `settings` table and take precedence over any
// 'entra' block in config.php (kept as a fallback for existing installs).
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
csrf_check();
$user = require_login();
require_perm('admin');

$guid = '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/';
$redirectUri = cfg('base_url') . '/sso.php';
$groups = db()->query('SELECT id, name FROM `groups` ORDER BY name')->fetchAll();
$testResults = null;

/* ---- Copy the tenant/app credentials already used for email (Graph) ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['use_graph'])) {
    global $CONFIG;
    $g = $CONFIG['graph'] ?? [];
    if (empty($g['tenant_id']) || empty($g['client_id']) || empty($g['client_secret'])) {
        flash('No email (Graph) app credentials are configured to copy from.', 'error');
    } elseif (entra_cfg() !== null) {
        // Never swap the credentials of a live sign-in in one click: the stored
        // secret cannot be read back, so it could not be restored.
        flash('Microsoft sign-in is ON, so its saved credentials were not replaced. To switch to the email app, turn Microsoft sign-in off and save first, or paste the new values into the form.', 'error');
    } else {
        $had = entra_form_values();
        // One transaction, secret first: a failure part-way never pairs an old
        // secret with a new tenant or app.
        db()->beginTransaction();
        try {
            set_setting('entra_client_secret', $g['client_secret']);
            set_setting('entra_tenant_id', strtolower(trim((string)$g['tenant_id'])));
            set_setting('entra_client_id', strtolower(trim((string)$g['client_id'])));
            audit($user['id'], 'federation.update', 'settings', null, 'copied app credentials from email (Graph) settings');
            db()->commit();
        } catch (Throwable $e) { if (db()->inTransaction()) db()->rollBack(); throw $e; }
        flash(($had['tenant_id'] !== '' || $had['client_id'] !== '' || $had['client_secret'] !== ''
                ? 'The saved tenant and app credentials were replaced with the ones from your email settings.'
                : 'Tenant and app credentials copied from your email settings.')
            . ' Microsoft sign-in is still OFF: review, then enable and test below.');
    }
    redirect('federation.php');
}

/* ---- Save settings ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save'])) {
    // Stored in lower case, as Microsoft issues them in the ID token (sso.php
    // compares the token's tenant and audience with these).
    $tenant = strtolower(trim(is_string($_POST['tenant_id'] ?? null) ? $_POST['tenant_id'] : ''));
    $client = strtolower(trim(is_string($_POST['client_id'] ?? null) ? $_POST['client_id'] : ''));
    $secret = trim(is_string($_POST['client_secret'] ?? null) ? $_POST['client_secret'] : '');
    $enabled = !empty($_POST['enabled']);
    $apg = trim(is_string($_POST['auto_provision_group'] ?? null) ? $_POST['auto_provision_group'] : '');
    $current = entra_form_values();

    $errors = [];
    if ($tenant !== '' && !preg_match($guid, $tenant)) {
        $errors['tenant_id'] = 'Tenant ID must be the Directory (tenant) ID — a GUID like 01c0760b-89cb-4501-83f4-9d36a7299f8a — not the domain name. Sign-in verification requires the GUID form.';
    }
    if ($client !== '' && !preg_match($guid, $client)) {
        $errors['client_id'] = 'Application (client) ID must be a 36-character ID like 01c0760b-89cb-4501-83f4-9d36a7299f8a, copied from the app registration\'s Overview page.';
    }
    if ($secret === '' && $current['client_secret'] !== '') $secret = $current['client_secret']; // blank keeps the stored secret
    if ($enabled) {
        $need = 'is required to enable Microsoft sign-in.';
        if ($tenant === '') $errors['tenant_id'] = 'The Directory (tenant) ID ' . $need;
        if ($client === '') $errors['client_id'] = 'The Application (client) ID ' . $need;
        if ($secret === '') $errors['client_secret'] = 'The client secret ' . $need;
    }
    if ($apg !== '' && !in_array($apg, array_column($groups, 'name'), true)) $apg = '';

    if ($errors) {
        // Keep what was typed (the secret is never kept) and tie each message to its field.
        keep_input();
        keep_field_errors($errors);
    } else {
        // One transaction, secret first: a failure part-way never pairs an old
        // secret with a new tenant or app, or turns sign-in on half-configured.
        db()->beginTransaction();
        try {
            set_setting('entra_client_secret', $secret);
            set_setting('entra_tenant_id', $tenant);
            set_setting('entra_client_id', $client);
            set_setting('entra_enabled', $enabled ? '1' : '0');
            set_setting('entra_auto_provision_group', $apg);
            audit($user['id'], 'federation.update', 'settings', null,
                ($enabled ? 'enabled' : 'disabled') . ($apg !== '' ? ', auto-provision into ' . $apg : ''));
            db()->commit();
        } catch (Throwable $e) { if (db()->inTransaction()) db()->rollBack(); throw $e; }
        flash($enabled
            ? 'Federation settings saved. Microsoft sign-in is ON — the button now appears on the sign-in page.'
            : 'Federation settings saved. Microsoft sign-in is currently OFF.');
    }
    redirect('federation.php');
}

/* ---- Test the connection to Microsoft ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['test'])) {
    $c = entra_form_values();
    $testResults = [];

    if ($c['tenant_id'] === '' || $c['client_id'] === '' || $c['client_secret'] === '') {
        $testResults[] = ['fail', 'Fill in and save the tenant ID, client ID, and client secret first.'];
    } else {
        // 1. Does the tenant exist?
        // A transport failure (DNS, timeout, blocked outbound HTTPS) is reported
        // as such, never as a wrong tenant ID.
        $ch = curl_init("https://login.microsoftonline.com/{$c['tenant_id']}/v2.0/.well-known/openid-configuration");
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15]);
        $raw = curl_exec($ch);
        $curlErr = curl_errno($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        $disco = is_string($raw) ? json_decode($raw, true) : null;
        if ($curlErr !== 0 || $status === 0) {
            $testResults[] = ['fail', 'Could not reach Microsoft (' . curl_strerror($curlErr) . '). This server may be blocking outbound connections, so nothing was checked — your tenant ID may well be right. Try again later, or ask your host.'];
        } elseif (!empty($disco['authorization_endpoint'])) {
            $testResults[] = ['ok', 'Tenant found — Microsoft recognizes this Directory (tenant) ID.'];
        } elseif ($status >= 500) {
            $testResults[] = ['fail', 'Microsoft answered with a server error (HTTP ' . $status . '), so the tenant ID could not be checked. Try again in a few minutes.'];
        } else {
            $testResults[] = ['fail', 'Microsoft does not recognize this tenant ID. Check the Directory (tenant) ID on the app registration\'s Overview page.'];
        }

        // 2. Do the app credentials work?
        if (!empty($disco['token_endpoint'])) {
            $ch = curl_init($disco['token_endpoint']);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query([
                    'client_id' => $c['client_id'],
                    'client_secret' => $c['client_secret'],
                    'grant_type' => 'client_credentials',
                    'scope' => 'https://graph.microsoft.com/.default',
                ]),
                CURLOPT_TIMEOUT => 15,
            ]);
            $raw = curl_exec($ch);
            $curlErr = curl_errno($ch);
            curl_close($ch);
            $tok = is_string($raw) ? json_decode($raw, true) : null;
            $desc = (string)($tok['error_description'] ?? '');
            if ($curlErr !== 0) {
                $testResults[] = ['fail', 'Could not reach Microsoft to check the app credentials (' . curl_strerror($curlErr) . '). Try again later.'];
            } elseif (!empty($tok['access_token'])) {
                $testResults[] = ['ok', 'App credentials accepted — the client ID and secret are valid for this tenant.'];
            } elseif (str_contains($desc, 'AADSTS7000215')) {
                $testResults[] = ['fail', 'The client secret is wrong or expired. Create a new secret under the app registration\'s "Certificates & secrets" and paste its Value (not the Secret ID).'];
            } elseif (str_contains($desc, 'AADSTS700016')) {
                $testResults[] = ['fail', 'No app with this Application (client) ID exists in that tenant.'];
            } else {
                // Only Microsoft's error code is shown, never its response text.
                $testResults[] = ['fail', 'Microsoft did not accept the app credentials'
                    . (preg_match('/AADSTS\d+/', $desc, $m) ? ' (error ' . $m[0] . ')' : '') . '. Check the client ID and secret, then try again.'];
            }
        }

        // 3. Redirect URI can only be confirmed in Azure.
        $testResults[] = ['note', 'One thing this test cannot check: the redirect URI. Make sure ' . $redirectUri . ' is listed as a Web redirect URI on the app registration (step 2 below), or Microsoft will show an error page at sign-in.'];
        audit($user['id'], 'federation.test', 'settings', null,
            in_array('fail', array_column($testResults, 0), true) ? 'connection test failed' : 'connection test passed');
    }
}

$cfg = entra_form_values();
$live = entra_cfg() !== null;
// After a rejected save, show what was typed rather than the stored values.
// The secret is never kept, so it has to be pasted again.
$refill = old_in('save') !== '';
$formTenant = $refill ? old_in('tenant_id') : $cfg['tenant_id'];
$formClient = $refill ? old_in('client_id') : $cfg['client_id'];
$formEnabled = $refill ? old_in('enabled') === '1' : $cfg['enabled'];
$formApg = $refill ? old_in('auto_provision_group') : $cfg['auto_provision_group'];

page_header('Federation', $user, 'admin', [['admin.php', 'Administration']]);
?>
<h1>Microsoft Entra ID federation</h1>
<p class="muted">Let your team sign in with their Microsoft work accounts ("Sign in with Microsoft" on the sign-in page). Microsoft handles the password and multi-factor step; this system then matches the verified email address to a user here.</p>

<div class="alert <?= $live ? 'alert-success' : 'alert-error' ?>" role="status">
  Microsoft sign-in is currently <strong><?= $live ? 'ON' : 'OFF' ?></strong>.
</div>

<?php if ($testResults): ?>
<section class="card" aria-labelledby="test-heading">
  <h2 id="test-heading" tabindex="-1">Connection test results</h2>
  <ul>
    <?php foreach ($testResults as [$kind, $msg]):
        [$emoji, $word] = $kind === 'ok' ? ['✅', 'Passed:'] : ($kind === 'fail' ? ['❌', 'Failed:'] : ['ℹ️', 'Note:']); ?>
    <li><span aria-hidden="true"><?= $emoji ?></span><span class="visually-hidden"><?= $word ?></span> <?= h($msg) ?></li>
    <?php endforeach; ?>
  </ul>
</section>
<script>document.addEventListener('DOMContentLoaded',function(){var t=document.getElementById('test-heading');if(t)t.focus();});</script>
<?php endif; ?>

<div class="grid" style="grid-template-columns: repeat(auto-fit, minmax(min(22rem, 100%), 1fr));">
  <section class="card" aria-labelledby="fed-heading">
    <h2 id="fed-heading">Connection settings</h2>
    <?= form_error_summary(['tenant_id' => 'Directory (tenant) ID', 'client_id' => 'Application (client) ID', 'client_secret' => 'Client secret']) ?>
    <?php // novalidate: the server's messages (with a GUID example) are shown in the
          // summary and on the field, instead of the browser's generic "match the format". ?>
    <form method="post" action="federation.php" class="stacked" novalidate>
      <?= csrf_field() ?>
      <div class="field">
        <label for="tenant_id">Directory (tenant) ID</label>
        <input type="text" id="tenant_id" name="tenant_id" value="<?= h($formTenant) ?>"
               autocomplete="off" spellcheck="false"
               <?= field_aria('tenant_id', 'tenant-help') ?>>
        <p class="muted" id="tenant-help">A 36-character ID such as 01c0760b-89cb-4501-83f4-9d36a7299f8a (not the domain name), from the app registration's Overview page in the Microsoft Entra admin center.</p>
        <?= field_error('tenant_id') ?>
      </div>
      <div class="field">
        <label for="client_id">Application (client) ID</label>
        <input type="text" id="client_id" name="client_id" value="<?= h($formClient) ?>"
               autocomplete="off" spellcheck="false" <?= field_aria('client_id', 'client-help') ?>>
        <p class="muted" id="client-help">Also a 36-character ID in the same form, shown just above the tenant ID on the app registration's Overview page.</p>
        <?= field_error('client_id') ?>
      </div>
      <div class="field">
        <label for="client_secret">Client secret</label>
        <input type="password" id="client_secret" name="client_secret" value="" autocomplete="new-password"
               placeholder="<?= $cfg['client_secret'] !== '' ? 'saved — leave blank to keep' : '' ?>"
               <?= field_aria('client_secret', 'secret-help') ?>>
        <p class="muted" id="secret-help"><?= $cfg['client_secret'] !== ''
            ? 'A secret is saved. Leave blank to keep it, or paste a new one to replace it.'
            : 'The secret Value from “Certificates &amp; secrets” on the app registration (shown only once when created).' ?>
          <?= $refill ? ' Nothing was saved: if you had typed a new secret, paste it again (a typed secret is never kept after an error).' : '' ?></p>
        <?= field_error('client_secret') ?>
      </div>
      <div class="field">
        <label><input type="checkbox" name="enabled" value="1" <?= $formEnabled ? 'checked' : '' ?>>
          Enable “Sign in with Microsoft” on the sign-in page</label>
      </div>
      <div class="field">
        <label for="auto_provision_group">Automatically create accounts for new Microsoft users, in this group</label>
        <select id="auto_provision_group" name="auto_provision_group" aria-describedby="apg-help">
          <option value="">Off (recommended)</option>
          <?php foreach ($groups as $g): ?>
          <option value="<?= h($g['name']) ?>" <?= $formApg === $g['name'] ? 'selected' : '' ?>>
            <?= h($g['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <p class="muted" id="apg-help">Off: only existing users can sign in. A group name: anyone in your Microsoft tenant who signs in gets an account in that group. Leave off unless you want that.</p>
      </div>
      <button type="submit" name="save" value="1" class="btn btn-primary">Save settings</button>
    </form>

    <form method="post" action="federation.php" class="inline-form mt-1">
      <?= csrf_field() ?>
      <button type="submit" name="test" value="1" class="btn btn-secondary">Test connection to Microsoft</button>
      <button type="submit" name="use_graph" value="1" class="btn btn-secondary"
        <?= !$live && ($cfg['tenant_id'] !== '' || $cfg['client_id'] !== '' || $cfg['client_secret'] !== '')
            ? 'onclick="return confirm(\'Replace the saved tenant ID, client ID and client secret with the ones from your email settings? The saved secret cannot be recovered afterwards.\');"' : '' ?>>Copy from email settings</button>
    </form>
    <p class="hint">"Copy from email settings" reuses the same Microsoft app this system already uses to send email — no retyping.</p>
  </section>

  <section class="card" aria-labelledby="setup-heading">
    <h2 id="setup-heading">One-time setup in Microsoft Entra</h2>
    <ol>
      <li>Sign in to the <a href="https://entra.microsoft.com" rel="noopener" target="_blank">Microsoft Entra admin center (opens in a new tab)</a> and open <strong>App registrations</strong>. Use an existing app (the “Copy from email settings” button reuses the one that sends this system's email) or create a new one.</li>
      <li>Under <strong>Authentication</strong> → <em>Add a platform</em> → <strong>Web</strong>, add this redirect URI exactly:
        <p><code style="user-select: all; word-break: break-all;"><?= h($redirectUri) ?></code></p></li>
      <li>Copy the <strong>Directory (tenant) ID</strong> and <strong>Application (client) ID</strong> from the app's Overview page into the form here.</li>
      <li>Under <strong>Token configuration</strong> → <em>Add optional claim</em> → <strong>ID</strong>, tick <strong>acct</strong> and add it. It tells this system that the person is a member of your own organization, not a guest; without it a person's first Microsoft sign-in cannot be matched to their account here by email.</li>
      <li>Under <strong>Certificates &amp; secrets</strong>, create a client secret and paste its <em>Value</em> here (it is shown only once).</li>
      <li>Save, then use <strong>Test connection</strong> to confirm everything checks out before telling your team.</li>
    </ol>
    <h3>How sign-in matching works</h3>
    <p>The first time someone signs in with Microsoft, they are matched to an <strong>active user here with the same email address</strong>, but only when Microsoft says they are a member of your own organization (the acct claim in step 4). A guest or a personal Microsoft account is never matched by email: that person signs in with their password once, then connects Microsoft from My Account. Check each person's email under <a href="account.php">Administration → People & permissions</a>. Password sign-in keeps working alongside Microsoft sign-in.</p>
  </section>
</div>
<?php page_footer();
