<?php
declare(strict_types=1);
/** The short brand name, with a trailing "AT" (assistive technology) spoken as letters:
 *  screen readers would otherwise say "at" (3.1.6). */
function brand_short_html(): string {
    $short = brand('short');
    if (preg_match('/^(.*\S) AT$/', $short, $m)) return '<span aria-hidden="true">' . h($short) . '</span><span class="visually-hidden">' . h($m[1]) . ' A T</span>';
    return h($short);
}
require_once __DIR__ . '/accessibility.php';
require_once __DIR__ . '/navigation.php';

/** Pages people outside the agency use (clients, families, counselors, referrers). */
function cms_client_facing_page(): bool {
    return empty($GLOBALS['CMS_PAGE_SIGNED_IN']) && in_array(basename($_SERVER['SCRIPT_NAME'] ?? ''),
        ['portal.php', 'consent.php', 'release.php', 'sign.php', 'survey.php', 'community_referral.php', 'vrc.php', 'ack.php', 'access_bookings.php', 'amendment_request.php', 'help.php', 'privacy.php', 'accessibility.php'], true);
}

function page_header(string $title, ?array $user = null, string $active = '', array $parents = []): void {
    // Public/help/error screens may omit the actor, but signed-in staff still
    // need their permission-filtered navigation on those screens.
    if ($user === null && function_exists('current_user')) $user = current_user();
    // A page handed to someone signing in person (4.25.0) shows no staff menu, links or shortcuts.
    // While a device is handed over (4.27.0), no page shows the staff menu or counts, public ones included.
    if (!empty($GLOBALS['CMS_KIOSK']) || !empty($_SESSION['kiosk_sign'])) $user = null;
    $GLOBALS['CMS_PAGE_SIGNED_IN'] = $user !== null; // page_footer shows staff shortcuts to the same audience
    $hideVision = $user && function_exists('setting') && setting('user_low_vision_hidden_' . (int)$user['id']) === '1';
    $app = cfg('name');
    // Flashes are taken before the <title> so a failed submit says so there too
    // (2.4.2/3.3.1); a page that already starts its title with 'Error — ' keeps it.
    $flashes = take_flashes();
    $failed = (function_exists('form_errors') && form_errors() !== []) || in_array('error', array_column($flashes, 'type'), true);
    $titleText = ($failed && !str_starts_with($title, 'Error — ') ? 'Error — ' : '') . __($title);
    ?><!DOCTYPE html>
<html lang="<?= h(page_lang()) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<script src="assets/low-vision.js?v=<?= h(APP_VERSION) ?>"></script>
<script src="assets/date-entry.js?v=<?= h(APP_VERSION) ?>" defer></script>
<script src="assets/select-display.js?v=<?= h(APP_VERSION) ?>" defer></script>
<script src="assets/navigation.js?v=<?= h(APP_VERSION) ?>" defer></script>
<script>window.cmsRestoredFields=<?= json_encode(array_keys(($GLOBALS['OLD_INPUT'] ?? []) + (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' ? $_POST : [])), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
<title lang="<?= page_lang() === 'es' && !isset(translations()[$title]) ? 'en' : h(page_lang()) ?>"><?= h($titleText) ?> — <?= h($app) ?></title>
<link rel="stylesheet" href="assets/styles.css?v=<?= h(APP_VERSION) ?>">
<?php /* Relative, as the stylesheet is: a site installed in a folder (https://example.org/cms)
         uses its own manifest and icon, not the domain root's. */ ?>
<link rel="manifest" href="manifest.php">
<?php $brandDark = accessible_brand_color(brand('color_dark'), '#1e3a8a', true); ?>
<style>:root{--brand:<?= h(accessible_brand_color(brand('color'), '#1e40af')) ?>;--brand-dark:<?= h($brandDark) ?>;--accent:<?= h(accessible_brand_color(brand('accent'), '#134e4a', false, 4.5)) ?>;}</style>
<?php /* --brand and --brand-dark colour links, buttons and the header, so they need 7:1;
         --accent is not used as text by styles.css, so the default teal (5.5:1) is kept. */ ?>
<link rel="apple-touch-icon" href="icon.php?s=180">
<?php /* The browser toolbar matches the header (the installed brand), not the vendor navy. */ ?>
<meta name="theme-color" content="<?= h($brandDark) ?>">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="<?= h(brand('short')) ?>">
</head>
<body>
<a class="skip-link" href="#main" accesskey="m"><?= t('Skip to main content') ?></a>
<?php /* Staff shortcuts only: clients on the portal, referral or sign-in pages have no Dashboard or staff menu. */ ?>
<?php
// Staff on a translated public page (4.29.0): the staff shortcuts, header and menu are English
// only, so they are written wholly in English and marked lang="en", never read in the page's
// Spanish voice nor half translated. The page's own content keeps its language.
$staffEn = $user && page_lang() !== 'en';
$pageTranslation = $GLOBALS['PUBLIC_TRANSLATION'] ?? null;
$staffLangAttr = $staffEn ? ' lang="en"' : '';
if ($staffEn) $GLOBALS['PUBLIC_TRANSLATION'] = false;
?>
<?php if ($user): ?>
<nav class="wrap page-actions cms-page-navigation" aria-label="<?= h(__('Page navigation')) ?>"<?= $staffLangAttr ?>>
  <span class="nav-item"><a class="btn btn-secondary" href="index.php" data-cms-back><?= t('Go back') ?><span class="nav-hint-sr visually-hidden">: <?= t('Returns to the page you came from. It does not undo a save.') ?></span></a><span class="nav-hint" aria-hidden="true"><?= t('Returns to the page you came from. It does not undo a save.') ?></span></span>
  <span class="nav-item"><a class="btn btn-secondary" href="index.php"><?= t('Dashboard') ?><span class="nav-hint-sr visually-hidden">: <?= t('Your starting page.') ?></span></a><span class="nav-hint" aria-hidden="true"><?= t('Your starting page.') ?></span></span>
  <span class="nav-item"><a class="btn btn-secondary" href="#main-nav" data-cms-menu accesskey="0"><?= t('Go to main menu') ?><span class="nav-hint-sr visually-hidden">: <?= t('Moves to the main menu, grouped by Today, Clients, Services and more.') ?></span></a><span class="nav-hint" aria-hidden="true"><?= t('Moves to the main menu, grouped by Today, Clients, Services and more.') ?></span></span>
</nav>
<?php endif; ?>
<?php if ($staffEn) $GLOBALS['PUBLIC_TRANSLATION'] = $pageTranslation; ?>
<section id="low-vision-bar" class="vision-bar" aria-labelledby="vision-title" data-saved="<?= h(__('Display updated.')) ?>" data-account-hidden="<?= $hideVision ? 'true' : 'false' ?>" hidden>
  <div class="wrap">
    <?php /* The heading stays outside the summary: inside it, it becomes part of a button and drops out of the headings list. */ ?>
    <h2 class="vision-title" id="vision-title"><?= t('Low-vision controls') ?></h2>
    <details class="vision-panel"><summary><?= t('Show display options') ?></summary>
    <div class="vision-heading">
      <a href="#main"><?= t('Skip to main content') ?></a>
      <?php if ($user): ?><button type="button" id="earcon-toggle" aria-pressed="true" hidden>Sound cues</button><?php endif; ?>
      <button type="button" id="vision-reset"><?= t('Reset display') ?></button></div>
    <div class="vision-colors" role="group" aria-label="<?= h(__('Colors and contrast')) ?>">
      <?php foreach (['auto'=>'System colors', 'light'=>'Light', 'dark'=>'Dark', 'black'=>'White on black', 'white'=>'Black on white', 'yellow'=>'Yellow on black', 'cream'=>'Dark on cream'] as $value => $label): ?>
      <button type="button" data-palette="<?= $value ?>" aria-pressed="false"><?= t($label) ?></button>
      <?php endforeach; ?>
    </div>
    <details class="vision-options"><summary><?= t('Text size and reading options') ?></summary>
      <div class="vision-settings">
        <div><label for="vision-size"><?= t('Text size') ?></label><select id="vision-size">
          <?php foreach ([100,125,150,175,200] as $size): ?><option value="<?= $size ?>"><?= $size ?>%</option><?php endforeach; ?>
        </select></div>
        <?php foreach (['spacing'=>'More text spacing', 'width'=>'Narrow reading width', 'links'=>'Highlight links', 'motion'=>'Reduce motion', 'lists'=>'Show dropdown choices as lists'] as $value => $label): ?>
        <button type="button" data-vision-toggle="<?= $value ?>" aria-pressed="false"><?= t($label) ?></button>
        <?php endforeach; ?>
      </div>
    </details>
    </details>
    <span id="vision-live" class="visually-hidden" role="status"></span>
  </div>
</section>
<?php if ($staffEn) $GLOBALS['PUBLIC_TRANSLATION'] = false; ?>
<header class="site-header" aria-label="<?= h(__('Site header')) ?>"<?= $staffLangAttr ?>>
  <div class="wrap header-inner">
    <?php if ($user): ?>
    <a class="brand" href="index.php"><?= brand_short_html() ?> <span class="brand-sub"><?= t('Case Management') ?></span></a>
    <?php /* The Menu button sits inside the landmark, so a collapsed "Main" navigation
             still holds the control that opens it. */ ?>
    <nav aria-label="<?= h(__('Main')) ?>">
      <button type="button" class="nav-toggle" id="nav-toggle" aria-expanded="false" aria-controls="main-nav" hidden>
        <span class="nav-toggle-bars" aria-hidden="true"></span> <?= t('Menu') ?>
      </button>
      <ul class="main-nav" id="main-nav" tabindex="-1">
        <?php
        // Grouped under h3 headings (H or the heading list jumps between groups) below the
        // page's one h2 before <main>, "Low-vision controls", so the page's h1 is preceded by
        // a single h2, not seven; each link carries a one-sentence hint inside it as hidden text
        // (4.8.0: JAWS skipped it as a description; as part of the link it is read on Tab and
        // when arrowing, still never an extra line or stop), and a separate on-screen
        // copy, hidden from screen readers, appears on hover or keyboard focus. Groups with
        // no permitted link are left out.
        $script = basename($_SERVER['SCRIPT_NAME'] ?? '');
        $byGroup = [];
        foreach (cms_navigation_items() as $item) if ($item[3] === null || can($item[3])) $byGroup[$item[4]][] = $item;
        foreach (cms_navigation_groups() as $group => $groupLabel): if (empty($byGroup[$group])) continue; ?>
        <li class="nav-group">
          <h3 class="nav-group-title" id="nav-group-<?= $group ?>"><?= h($groupLabel) ?></h3>
          <ul aria-labelledby="nav-group-<?= $group ?>">
          <?php foreach ($byGroup[$group] as [$href, $label, $key, , , $hint, $accessKey]):
            $current = $active === $key ? ($script === explode('#', $href, 2)[0] ? 'aria-current="page"' : 'aria-current="true"') : ''; ?>
            <li class="nav-item"><a href="<?= $href ?>" <?= $current ?><?= $accessKey !== '' ? ' accesskey="' . $accessKey . '"' : '' ?>><?= h($label) ?><span class="nav-hint-sr visually-hidden">: <?= h($hint) ?></span></a><span class="nav-hint" aria-hidden="true"><?= h($hint) ?></span></li>
          <?php endforeach; ?>
          </ul>
        </li>
        <?php endforeach; ?>
        <?php
        try {
            $nq = db()->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL');
            $nq->execute([(int)$user['id']]);
            $unreadAlerts = (int)$nq->fetchColumn();
        } catch (Throwable $e) { $unreadAlerts = 0; }
        ?>
        <li><a href="notifications.php" accesskey="9" <?= $active === 'alerts' ? 'aria-current="page"' : '' ?>>Notifications<?= $unreadAlerts ? '<span aria-hidden="true"> (' . $unreadAlerts . ')</span><span class="visually-hidden">, ' . $unreadAlerts . ' unread</span>' : '' ?></a></li>
        <?php if (can('clients')): // search.php requires the clients permission ?>
        <li>
          <form method="get" action="search.php" role="search" aria-label="Site search" class="inline-form nav-search">
            <label for="nav-q" class="visually-hidden">Search client records and other accessible information</label>
            <input type="search" id="nav-q" name="q" placeholder="Search…" minlength="2" accesskey="s">
          </form>
        </li>
        <?php endif; ?>
        <li>
          <form method="post" action="logout.php" class="inline-form"><?= csrf_field() ?>
            <button type="submit" class="nav-logout">Sign out</button>
          </form>
        </li>
      </ul>
    </nav>
    <?php else: ?>
    <?php /* Clients and counselors are not "cases": their pages show the organization's name only. */ ?>
    <span class="brand"><?= brand_short_html() ?><?php if (!cms_client_facing_page()): ?> <span class="brand-sub"><?= t('Case Management') ?></span><?php endif; ?></span>
    <?php endif; ?>
  </div>
</header>
<?php if ($staffEn) $GLOBALS['PUBLIC_TRANSLATION'] = $pageTranslation; ?>
<?php if ($user): ?>
<script>
/* Enable the collapsible mobile nav (progressive enhancement: with JS off the
   full nav stays visible). */
(function(){
  var h=document.querySelector('.site-header'),t=document.getElementById('nav-toggle'),n=document.getElementById('main-nav');
  if(!h||!t||!n)return;
  h.setAttribute('data-nav','collapsible');
  t.hidden=false;
  t.addEventListener('click',function(){
    var open=n.classList.toggle('open');
    t.setAttribute('aria-expanded',open?'true':'false');
  });
})();
</script>
<?php endif; ?>
<main id="main" class="wrap" tabindex="-1" aria-label="<?= h(__('Main content')) ?>">
<?= cms_breadcrumb_html($title, $active, $parents, $user !== null) ?>
<?php
    if ($flashes) {
        // Live regions present at page load are often not announced after a
        // redirect, so the messages are announced by moving focus to them — once,
        // to one group holding every message. They carry no alert/status role,
        // because a focused live region is read twice by some screen readers;
        // errors start with a visible, spoken "Error:" instead (and a heavier left
        // border), so colour is never the only cue (1.4.1). A short earcon
        // (success/error) confirms non-visually. After a redirect to a section
        // (#logs), the group is shown just before that section, so focusing it
        // does not cancel the jump back to where the user was working.
        $flashErr = false;
        echo '<div id="flash-messages" class="flash-messages" tabindex="-1">';
        foreach ($flashes as $f) {
            $isErr = $f['type'] === 'error';
            if ($isErr) $flashErr = true;
            // Label each flash with the language it is actually in (3.1.2): translated
            // interface copy follows the page language (t() marks any untranslated
            // fragment itself); every other message is written in English.
            $translated = !empty($f['translate']);
            // 'info' (a reminder, not a result) gets a neutral style; like success it
            // has no "Error:" label and plays the success earcon.
            $kind = $isErr ? 'alert-error' : ($f['type'] === 'info' ? 'alert-info' : 'alert-success');
            echo '<div lang="' . ($translated ? h(page_lang()) : 'en') . '" class="alert ' . $kind . '">'
                . ($isErr ? '<strong class="alert-label">' . ($translated ? t('Error:') : 'Error:') . '</strong> ' : '')
                . ($translated ? t($f['msg']) : h($f['msg'])) . '</div>';
        }
        echo '</div>';
        echo '<script>document.addEventListener("DOMContentLoaded",function(){var a=document.getElementById("flash-messages"),m=document.getElementById("main"),t=null;if(!a)return;'
            . 'try{t=location.hash.length>1?document.getElementById(decodeURIComponent(location.hash.slice(1))):null;}catch(e){}'
            . 'if(t&&m&&t!==m&&m.contains(t)&&!a.contains(t)&&!t.closest("[hidden],details:not([open])")){var w=t.closest("table");if(w){w=w.closest(".table-scroll")||w;w.parentNode.insertBefore(a,w);}else if(t.tagName==="LI"){t.insertBefore(a,t.firstChild);}else{t.parentNode.insertBefore(a,t);}}'
            . 'var s=document.getElementById("form-error-summary")||document.getElementById("form-errors");if(s&&!s.contains(a)){s.insertBefore(a,s.firstChild);a.removeAttribute("tabindex");}else{a.focus();}if(window.Earcon)Earcon.play(' . ($flashErr ? "'error'" : "'success'") . ');});</script>';
    }
    if ($user) {
        $totalSecs = max(120, (int)cfg('session_timeout_minutes') * 60);
        // Warn 2 minutes ahead, but never sooner than halfway through short timeouts.
        $leadSecs = min(120, max(30, intdiv($totalSecs, 2)));
        ?>
<?php /* session-forms.js says "Checking your session…" here: a polite region already in
         the page, so it is announced; its failure messages are announced by focus instead. */ ?>
<div id="session-check-live" class="visually-hidden" role="status"></div>
<?php /* data-uid: session-forms.js refuses to resubmit under a different signed-in account.
         data-timeout-ms: the server's own limit (as ping.php applies it), after which
         session-timeout.js switches the dialog to "You were signed out". */ ?>
<div id="timeout-warn" role="alertdialog" aria-modal="true" aria-labelledby="tw-title" aria-describedby="tw-desc" hidden class="timeout-dialog" data-warn-ms="<?= ($totalSecs - $leadSecs) * 1000 ?>" data-timeout-ms="<?= max(0, (int)cfg('session_timeout_minutes')) * 60000 ?>" data-uid="<?= (int)$user['id'] ?>">
  <h2 id="tw-title">Are you still there?</h2>
  <p id="tw-desc">You've been inactive and will be signed out in about <?= (int)ceil($leadSecs / 60) ?> minute<?= $leadSecs > 90 ? 's' : '' ?>. Keep this page open to retain your work. File uploads may need to be selected again.</p>
  <button type="button" class="btn btn-primary" id="tw-stay">Stay signed in</button>
  <button type="button" class="btn btn-secondary" id="tw-dismiss">Return to my work</button>
  <?php /* Assertive live region, in place before any message exists: session-timeout.js
           moves the message below that applies into it, so it is announced. */ ?>
  <div id="tw-live" role="alert"></div>
  <p id="tw-error" class="field-error" hidden>We could not extend your session. Check your connection and try again, or return to your work to copy any unsaved text.</p>
  <p id="tw-expired" hidden>You were signed out. <strong>What you typed is still on this page</strong> — copy it if needed, then
     <a href="login.php" target="_blank" rel="noopener">sign in again in a new tab <span class="visually-hidden">(opens in a new tab)</span></a>
     and come back to this page before submitting.</p>
</div>
<script src="assets/session-timeout.js?v=<?= h(APP_VERSION) ?>" defer></script>
<script src="assets/session-forms.js?v=<?= h(APP_VERSION) ?>" defer></script>
<script src="assets/earcons.js?v=<?= h(APP_VERSION) ?>" defer></script>
<?php
        // Voice assistant (natural-language commands + human-voice replies). Its
        // endpoint, ai_command.php, requires the clients permission.
        if (function_exists('voice_available') && voice_available() && can('clients')) {
            $voiceTts = function_exists('ai_speak_capable') && ai_feature_enabled('readaloud') && ai_speak_capable();
            ?>
<div id="voice-fab">
  <div class="vfab-panel" id="vfab-panel" role="dialog" aria-label="Voice assistant" hidden>
    <div class="vfab-head"><strong>Voice assistant</strong>
      <button type="button" class="vfab-convo btn btn-secondary btn-sm">Start conversation</button>
      <button type="button" class="vfab-close" aria-label="Close voice assistant">&times;</button></div>
    <button type="button" class="vfab-listen btn btn-secondary">Use microphone</button>
    <button type="button" class="vfab-stop btn btn-secondary">Stop speaking</button>
    <p class="vfab-status" role="status" aria-live="polite">Choose Use microphone and speak, or type a request.</p>
    <div class="vfab-log" role="region" tabindex="0" aria-label="Conversation history" hidden></div>
    <div class="vfab-confirm" role="group" aria-labelledby="vfab-ctext" hidden><p class="vfab-ctext" id="vfab-ctext"></p>
      <button type="button" class="vfab-yes btn btn-primary btn-sm">Accept and save</button>
      <button type="button" class="vfab-no btn btn-secondary btn-sm">Decline</button></div>
    <label for="vfab-input" class="visually-hidden">Type a request for the voice assistant</label>
    <div class="vfab-type"><input type="text" id="vfab-input" class="vfab-input" placeholder="Type a request…">
      <button type="button" class="vfab-send btn btn-secondary btn-sm">Send</button></div>
    <div class="vfab-cues">
      <button type="button" class="vfab-mute">Sound cues: on</button>
      <button type="button" class="vfab-voice">Spoken replies: on</button>
      <label class="vfab-vol">Loudness
        <select class="vfab-vol-sel">
          <option value="normal">Normal</option>
          <option value="loud">Loud</option>
          <option value="max">Max (deaf-blind)</option>
        </select>
      </label>
    </div>
  </div>
  <button type="button" class="vfab-btn" aria-label="Voice assistant" aria-haspopup="dialog" aria-controls="vfab-panel" aria-expanded="false"><span aria-hidden="true">&#127908;</span></button>
</div>
<style>
#voice-fab{position:relative;margin-bottom:1rem}
.vfab-btn{width:3.5rem;height:3.5rem;border-radius:50%;border:2px solid #fff;background:var(--brand-dark);color:#fff;font-size:1.5rem;cursor:pointer;box-shadow:0 4px 14px rgba(0,0,0,.35)}
.vfab-btn.listening{animation:vpulse 1.1s infinite}
@keyframes vpulse{0%,100%{box-shadow:0 0 0 0 rgba(30,64,175,.5)}50%{box-shadow:0 0 0 14px rgba(30,64,175,0)}}
.vfab-panel{position:relative;width:min(32rem,100%);margin-bottom:.5rem;background:var(--card);color:var(--ink);border:1px solid var(--line);border-radius:var(--radius);padding:.75rem;box-shadow:0 8px 30px rgba(0,0,0,.4)}
.vfab-head{display:flex;flex-wrap:wrap;gap:.5rem;justify-content:space-between;align-items:center;margin-bottom:.4rem}
.vfab-close{background:none;border:none;font-size:1.4rem;line-height:1;cursor:pointer;color:var(--ink)}
.vfab-status{margin:.25rem 0;min-height:2.5em}
.vfab-type{display:flex;gap:.4rem;align-items:center;margin-top:.5rem}
.vfab-type .vfab-input{flex:1;min-width:0}
.vfab-confirm{margin:.4rem 0;padding:.4rem;border:1px solid var(--line);border-radius:var(--radius)}
.vfab-convo.on{background:var(--brand-dark);color:#fff}
.vfab-log{max-height:11rem;overflow-y:auto;margin:.3rem 0;font-size:.85rem;display:flex;flex-direction:column;gap:.25rem}
.vfab-log .vmsg{padding:.3rem .45rem;border-radius:var(--radius);max-width:90%}
.vfab-log .vmsg.u{align-self:flex-end;background:var(--brand-dark);color:#fff}
.vfab-log .vmsg.a{align-self:flex-start;background:var(--bg);border:1px solid var(--line)}
.vfab-cues{display:flex;justify-content:space-between;align-items:center;gap:.5rem;margin:.4rem 0 0;flex-wrap:wrap}
.vfab-mute,.vfab-voice{background:none;border:none;color:var(--muted,inherit);font-size:.875rem;text-decoration:underline;cursor:pointer;padding:0}
.vfab-vol{font-size:.875rem;display:flex;align-items:center;gap:.3rem}
.vfab-vol-sel{font-size:.875rem;padding:.1rem .2rem}
</style>
<script>window.__csrf=<?= json_encode(csrf_token(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;window.__voiceSpeak=<?= $voiceTts ? 'true' : 'false' ?>;window.__voiceUid=<?= (int)$user['id'] ?>;</script>
<script src="assets/voice.js?v=<?= h(APP_VERSION) ?>" defer></script>
<?php
        }
    }
}

function page_footer(): void {
    // A public page's help link goes to its own section of help.php, and its label
    // follows the page language (English pages keep lang="en" on it).
    $helpSection = ['portal.php' => 'portal', 'consent.php' => 'consent', 'community_referral.php' => 'referral', 'survey.php' => 'survey'][basename($_SERVER['SCRIPT_NAME'] ?? '')] ?? '';
    $helpLink = '<a href="help.php' . ($helpSection !== '' ? '#' . $helpSection : '') . '"'
        . (page_lang() === 'es' ? '>' . t('Help and glossary') : ' lang="en">Help and glossary') . '</a>';
    // The footer is shown before sign-in (login, portal, referral form), so it
    // names the public contact address, never backup_email (where off-site
    // backups and alerts go; the installer sets it to the administrator's
    // sign-in email). Always use APP_VERSION so the footer follows each release.
    $contact = (string)(cfg('support_email') ?: cfg('mail_from'));
    // Staff shortcuts are not shown to clients on public pages (see page_header).
    $signedIn = !empty($GLOBALS['CMS_PAGE_SIGNED_IN']);
    ?>
</main>
<script>
/* A scrollable table region is a tab stop only while its table really overflows;
   one that fits is not an extra stop. (With JS off every region stays focusable,
   which is always safe.) Re-checked when the window, region or table changes size. */
(function(){
  var regions=document.querySelectorAll('.table-scroll[tabindex="0"]');
  if(!regions.length)return;
  function sync(){
    regions.forEach(function(r){
      if(r.scrollWidth>r.clientWidth+1)r.setAttribute('tabindex','0');
      else if(r!==document.activeElement)r.removeAttribute('tabindex');
    });
  }
  sync();
  window.addEventListener('resize',sync);
  if(window.ResizeObserver){
    var ro=new ResizeObserver(sync);
    regions.forEach(function(r){ro.observe(r);if(r.firstElementChild)ro.observe(r.firstElementChild);});
  }
})();
</script>
<footer class="site-footer" aria-label="<?= h(__('Site information')) ?>">
  <div class="wrap">
    <?php if ($signedIn): ?>
    <nav class="page-actions" aria-label="<?= h(__('Footer navigation')) ?>">
      <a class="btn btn-secondary" href="index.php" data-cms-back><?= t('Go back') ?></a>
      <a class="btn btn-secondary" href="index.php"><?= t('Dashboard') ?></a>
      <a class="btn btn-secondary" href="#main-nav" data-cms-menu><?= t('Go to main menu') ?></a>
    </nav>
    <?php endif; ?>
    <p><?= h(cms_client_facing_page() ? brand('org') : cfg('name')) ?> <span aria-hidden="true">&middot;</span> <a href="community_referral.php"><?= t('Referral Form') ?></a>
       <span aria-hidden="true">&middot;</span> <?= $helpLink ?>
       <span aria-hidden="true">&middot;</span> <a href="privacy.php"<?= page_lang() === 'es' ? '>' . t('Privacy notice') : ' lang="en">Privacy notice' ?></a>
       <?php // With a support phone set: "Questions? Call {phone} or email {email}". ?>
       <?php if (($contactLine = support_contact_html()) !== ''): ?><span aria-hidden="true">&middot;</span> <?= t('Questions?') ?> <?= $contactLine ?>
       <?php elseif ($contact !== ''): ?><span aria-hidden="true">&middot;</span> <?= t('Questions? Email') ?> <a href="mailto:<?= h($contact) ?>"><?= h($contact) ?></a><?php endif; ?>
    </p>
    <?php if (!cms_client_facing_page()): ?><p class="site-version"><?= page_lang() === 'es' ? 'Versión' : 'Version' ?> <?= h(APP_VERSION) ?></p><?php endif; ?>
  </div>
</footer>
</body>
</html><?php
}

/** A page-level error message (staff pages; plain text in, escaped here). It is
 *  announced by moving focus to it once after the page loads, so it carries no
 *  alert role — a focused live region is read twice by some screen readers. */
function error_notice(string $message): string {
    return '<div class="alert alert-error" id="form-errors" tabindex="-1"><strong class="alert-label">Error:</strong> ' . h($message) . '</div>'
        . '<script>document.addEventListener("DOMContentLoaded",function(){var e=document.getElementById("form-errors");if(e)e.focus();});</script>';
}

/** The "Back to …" link at the top or foot of a page, worded and marked up the
 *  same everywhere: the arrow is hidden from screen readers, so the link is read
 *  as "Back to {label}". $href and $label are plain text; both are escaped here. */
function back_link(string $href, string $label): string {
    return '<p class="page-actions back-link"><a href="' . h($href) . '"><span aria-hidden="true">&larr; </span>Back to ' . h($label) . '</a></p>';
}

function status_badge(string $status): string {
    $map = [
        'Pending Assessment' => 'badge-pending', 'Active Training' => 'badge-active',
        'On Hold' => 'badge-hold', 'Closed - Rehabilitated' => 'badge-closed',
        'Closed - Other' => 'badge-closed',
        'Active' => 'badge-active', 'Completed' => 'badge-closed', 'Cancelled' => 'badge-hold',
        'New' => 'badge-pending', 'Reviewed' => 'badge-active', 'Converted' => 'badge-closed', 'Closed' => 'badge-closed',
        'Not Started' => 'badge-pending', 'In Progress' => 'badge-active', 'Achieved' => 'badge-closed',
        'Scheduled' => 'badge-active', 'No Show' => 'badge-hold',
        'Waitlist' => 'badge-hold', 'Assigned' => 'badge-active',
    ];
    $cls = $map[$status] ?? (str_starts_with($status, 'Closed') ? 'badge-closed' : 'badge-pending');
    return '<span class="badge ' . $cls . '">' . t($status) . '</span>';
}

function select_options(array $options, ?string $selected): string {
    $out = '';
    foreach ($options as $o) {
        $sel = $o === $selected ? ' selected' : '';
        // English only when the dictionary has no entry: 'Audio' and 'Portal' are
        // translated even though the Spanish is spelled the same.
        $lang = page_lang() === 'es' && !isset(translations()[$o]) ? ' lang="en"' : '';
        $out .= '<option value="' . h($o) . '"' . $sel . $lang . '>' . h(__($o)) . '</option>';
    }
    return $out;
}

/** A phone number as a call link (4.0.0), or the text as it is when it has no digits. */
function tel_link(?string $phone, string $whose = ''): string {
    $p = trim((string)$phone);
    if ($p === '') return '—';
    $dial = preg_replace('/[^0-9+]/', '', $p);
    return strlen((string)$dial) >= 7 ? '<a href="tel:' . h($dial) . '">' . h($p) . ($whose !== '' ? '<span class="visually-hidden"> (call ' . h($whose) . ')</span>' : '') . '</a>' : h($p);
}

/** An address with a Directions link that opens the phone's or computer's maps (4.0.0). */
function directions_link(?string $address): string {
    $a = trim((string)$address);
    if ($a === '') return '—';
    return h($a) . ' <a href="https://maps.apple.com/?daddr=' . rawurlencode($a) . '" target="_blank" rel="noopener">Directions<span class="visually-hidden"> to ' . h($a) . ' (opens maps in a new tab)</span></a>';
}

