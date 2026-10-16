<?php
// Admin editor for the wording of system emails. Blank = built-in default.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
csrf_check();
$user = require_login();
require_perm('admin');

// A key that is not a string (an array from key[]=x, say) means "no template chosen".
$key = $_REQUEST['key'] ?? '';
if (!is_string($key) || !isset(EMAIL_TEMPLATES[$key])) $key = '';

// Placeholders a customised body must keep, or the email loses the thing it
// exists to deliver (a sign-in code without {code} locks people out).
const TPL_REQUIRED = [
    'otp' => ['{code}' => 'the sign-in code'],
    'vrc_code' => ['{code}' => 'the access code'],
    'portal_link' => ['{link}' => 'the portal link'],
    'consent_request' => ['{link}' => 'the consent form link'],
    'survey_invite' => ['{link}' => 'the survey link'],
    'release_request' => ['{link}' => 'the release form link'],
    'message_notice' => ['{link}' => 'the portal link'],
    'request_closed' => ['{link}' => 'the portal link'],
    'portal_expiring' => ['{link}' => 'the portal link'],
    'report_cover' => ['{ack}' => 'the confirmation link'],
    'referral_closed' => ['{ref}' => 'the referral reference number'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_tpl']) && $key !== '') {
    $body = trim((string)($_POST['body'] ?? ''));
    if ($body !== '') {
        foreach (TPL_REQUIRED[$key] ?? [] as $ph => $what) {
            if (!str_contains($body, $ph)) {
                keep_input();
                keep_field_errors(['body' => 'The body must include ' . $ph . ' exactly as shown (lower case, no spaces): it is replaced by '
                    . $what . '. Nothing was saved.']);
                redirect('email_templates.php?key=' . urlencode($key));
            }
        }
    }
    // Strip CR/LF so a stored subject can never inject extra mail headers.
    set_setting('tpl_' . $key . '_subject', trim(str_replace(["\r", "\n"], ' ', (string)($_POST['subject'] ?? ''))));
    set_setting('tpl_' . $key . '_body', $body);
    audit($user['id'], 'email_template.save', 'settings', null, $key);
    flash('Template saved. Leave both fields blank and save to return to the built-in wording.');
    redirect('email_templates.php?key=' . urlencode($key));
}

page_header('Email templates', $user, 'admin', [['admin.php', 'Administration']]);
?>
<h1>Email templates</h1>
<p class="muted">Change the wording of the emails this system sends. Words in curly braces like <code>{name}</code> are filled in automatically.
   Leave a field blank to use the built-in wording. <a href="admin.php#forms-h">Back to My Account</a></p>

<div class="grid grid-2">
  <section class="card" aria-label="Template list">
    <h2>Templates</h2>
    <ul>
      <?php foreach (EMAIL_TEMPLATES as $k => $def): ?>
      <li><a href="email_templates.php?key=<?= h($k) ?>" <?= $k === $key ? 'aria-current="true"' : '' ?>><?= h($def[0]) ?></a>
        <?= setting('tpl_' . $k . '_subject') || setting('tpl_' . $k . '_body') ? '<span class="badge badge-active">customized</span>' : '' ?></li>
      <?php endforeach; ?>
    </ul>
  </section>

  <?php if ($key !== ''): $def = EMAIL_TEMPLATES[$key];
      $curSubject = setting('tpl_' . $key . '_subject') ?? '';
      $curBody = setting('tpl_' . $key . '_body') ?? ''; ?>
  <section class="card" aria-label="Edit template <?= h($def[0]) ?>">
    <h2>Edit: <?= h($def[0]) ?></h2>
    <p class="hint">Available placeholders: <code><?= h(implode(' ', array_unique(array_merge(preg_split('/\s+/', trim($def[3])) ?: [], ['{app}', '{org}', '{org_short}'])))) ?></code></p>
    <?= form_error_summary(['body' => 'Body'], ['body' => 'tpl_body']) ?>
    <form method="post" action="email_templates.php?key=<?= h($key) ?>" class="stacked" novalidate>
      <?= csrf_field() ?>
      <?php if ($def[1] !== ''): ?>
      <div class="field"><label for="tpl_subject">Subject <span class="hint">blank = default: “<?= h($def[1]) ?>”</span></label>
        <input type="text" id="tpl_subject" name="subject" maxlength="190" value="<?= h(old_in('subject', $curSubject)) ?>"></div>
      <?php else: ?><input type="hidden" name="subject" value=""><?php endif; ?>
      <div class="field"><label for="tpl_body">Body <span class="hint">blank = the default shown below</span></label>
        <?php if (isset(TPL_REQUIRED[$key])): ?><span class="hint" id="tpl_body-req">Must include <?= h(implode(' and ', array_keys(TPL_REQUIRED[$key]))) ?>.</span><?php endif; ?>
        <textarea id="tpl_body" name="body" rows="9" <?= field_aria('body', isset(TPL_REQUIRED[$key]) ? 'tpl_body-req' : '') ?>><?= h(old_in('body', $curBody)) ?></textarea><?= field_error('body') ?></div>
      <button type="submit" name="save_tpl" value="1" class="btn btn-primary">Save template</button>
    </form>
    <h3>Built-in default</h3>
    <p style="white-space: pre-wrap;" class="muted"><?= h($def[2]) ?></p>
  </section>
  <?php endif; ?>
</div>
<?php page_footer();
