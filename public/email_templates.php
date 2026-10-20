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
// 4.29.0: the one list is EMAIL_TEMPLATE_REQUIRED (inc/core.php), which email_template()
// enforces when sending, so every body the page accepts is one that is actually used
// (the Spanish templates and "Document to sign" were missing here). This adds only what
// each placeholder is replaced by, for the error message.
const TPL_WHAT = [
    'otp' => 'the sign-in code', 'vrc_code' => 'the access code',
    'counselor_noshow' => 'the counselor portal link', 'counselor_auth_low' => 'the counselor portal link',
    'report_cover' => 'the confirmation link', 'referral_closed' => 'the referral reference number',
    'consent_request' => 'the consent form link', 'survey_invite' => 'the survey link', 'release_request' => 'the release form link',
    'sign_request' => 'the link to the document to sign', 'session_moved_client' => 'the new session time',
];
const TPL_WHAT_BY_PLACEHOLDER = ['{code}' => 'the code', '{link}' => 'the portal link', '{item}' => 'the item loaned',
    '{sessions}' => 'the session dates', '{ack}' => 'the confirmation link', '{ref}' => 'the referral reference number'];

/** The placeholders a body for $key must keep: placeholder => what it is replaced by. */
function tpl_required(string $key): array {
    $ph = EMAIL_TEMPLATE_REQUIRED[$key] ?? null;
    if ($ph === null) return [];
    $base = preg_replace('/_es$/', '', $key);
    return [$ph => TPL_WHAT[$key] ?? TPL_WHAT[$base] ?? TPL_WHAT_BY_PLACEHOLDER[$ph] ?? 'what the email delivers'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_tpl']) && $key !== '') {
    $body = trim((string)($_POST['body'] ?? ''));
    if ($body !== '') {
        foreach (tpl_required($key) as $ph => $what) {
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
   Leave a field blank to use the built-in wording. <a href="admin.php#forms-h">Back to Administration</a></p>

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
    <?php $tplPlaceholders = implode(' ', array_unique(array_merge(preg_split('/\s+/', trim($def[3])) ?: [], ['{app}', '{org}', '{org_short}']))); ?>
    <?= form_error_summary(['body' => 'Body'], ['body' => 'tpl_body']) ?>
    <form method="post" action="email_templates.php?key=<?= h($key) ?>" class="stacked" novalidate>
      <?= csrf_field() ?>
      <?php if ($def[1] !== ''): ?>
      <div class="field"><label for="tpl_subject">Subject <span class="hint">blank = default: “<?= h($def[1]) ?>”</span></label>
        <input type="text" id="tpl_subject" name="subject" maxlength="190" value="<?= h(old_in('subject', $curSubject)) ?>"></div>
      <?php else: ?><input type="hidden" name="subject" value=""><?php endif; ?>
      <div class="field"><label for="tpl_body">Body <span class="hint">blank = the default shown below<?php if (tpl_required($key)): ?>; required: must include <?= h(implode(' and ', array_keys(tpl_required($key)))) ?><?php endif; ?>. Available placeholders: <code><?= h($tplPlaceholders) ?></code></span></label>
        <textarea id="tpl_body" name="body" rows="9" <?= field_aria('body') ?>><?= h(old_in('body', $curBody)) ?></textarea><?= field_error('body') ?></div>
      <button type="submit" name="save_tpl" value="1" class="btn btn-primary">Save template</button>
    </form>
    <h3>Built-in default</h3>
    <p style="white-space: pre-wrap;" class="muted"><?= h($def[2]) ?></p>
  </section>
  <?php endif; ?>
</div>
<?php page_footer();
