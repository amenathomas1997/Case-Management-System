<?php
declare(strict_types=1);
// Web bootstrap: core functions + session, CSRF, auth. CLI cron scripts use core.php directly.

// Never print a PHP warning or error into a page, whatever the host's php.ini
// (Apache mod_php ignores .user.ini, and a dotfile can be lost in an upload):
// it would show server paths to the visitor. Logged instead.
if (PHP_SAPI !== 'cli') {
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
}

require __DIR__ . '/core.php';

/* ---------- Last-resort handler for uncaught exceptions ----------
   Only exceptions no page catches reach this, so existing try/catch blocks
   behave exactly as before. The log line carries the exception class, code and
   location only — never the message (it can quote SQL or submitted values) or
   any request data. Bad input types (e.g. an array where text is expected)
   answer 400; everything else 500. */
set_exception_handler(static function (Throwable $e): void {
    error_log(sprintf('Uncaught %s (code %s) at %s:%d', get_class($e), (string)$e->getCode(), $e->getFile(), $e->getLine()));
    // Public client pages (portal, survey, consent, referral) may be in Spanish,
    // and their visitors have no staff account: send them back to the page
    // they were on (their private link), not to the staff sign-in. The text is
    // kept here rather than in lang_es.php so this page depends on nothing else.
    $es = function_exists('page_lang') && page_lang() === 'es';
    $title = $es ? 'Algo salió mal' : 'Something went wrong';
    $message = $es ? 'Algo salió mal y no se guardó nada. Regrese e inténtelo de nuevo.'
        : 'Something went wrong and nothing was saved. Go back and try again.';
    $staff = !empty($_SESSION['user_id']);
    $back = $staff || !function_exists('safe_resume_url') ? 'index.php' : safe_resume_url($_SERVER['REQUEST_URI'] ?? '');
    $backText = $staff ? ($es ? 'Volver a la página de inicio' : 'Return to the home page')
        : ($es ? 'Volver a la página' : 'Go back to the page');
    $type = 'text/html';
    foreach (headers_list() as $sent) {
        if (stripos($sent, 'Content-Type:') === 0) $type = strtolower(trim(substr($sent, 13)));
    }
    $json = str_contains($type, 'json');
    if (headers_sent()) {
        // Part of the response already left: never append markup to a download.
        if (str_starts_with($type, 'text/html')) echo '<p role="alert">' . $message . '</p>';
    } else {
        while (ob_get_level() > 0) ob_end_clean();
        http_response_code(($e instanceof TypeError || $e instanceof ValueError) ? 400 : 500);
        header_remove('Content-Disposition');
        header_remove('Content-Length');
        header('Cache-Control: no-store');
        if ($json) {
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['ok' => false, 'error' => $message]);
        } else {
            header('Content-Type: text/html; charset=UTF-8');
            echo '<!DOCTYPE html><html lang="' . ($es ? 'es' : 'en') . '"><head><meta charset="UTF-8">'
                . '<meta name="viewport" content="width=device-width, initial-scale=1">'
                . '<script src="assets/low-vision.js?v=' . h(APP_VERSION) . '"></script>'
                . '<title>' . $title . '</title><link rel="stylesheet" href="assets/styles.css?v=' . h(APP_VERSION) . '"></head>'
                . '<body><nav class="wrap page-actions" aria-label="Page navigation"><a href="index.php">Dashboard</a><a href="index.php#main-nav">Go to main menu</a></nav><main class="wrap" aria-label="Main content"><h1>' . $title . '</h1><p>' . $message . '</p>'
                . '<p><a href="' . htmlspecialchars($back, ENT_QUOTES, 'UTF-8') . '">' . $backText . '</a></p></main></body></html>';
        }
    }
    // An uncaught exception has always ended the process with a failing exit
    // status; keep that (command-line callers rely on it — browsers never see it).
    exit(255);
});

// ---- HTTPS detection. On this host Apache's .htaccess already forces HTTPS
// and sets HSTS before PHP runs; this is a fallback for installs without that
// .htaccess (e.g. white-label on other servers), and it drives the secure
// cookie flag below. cPanel can terminate TLS upstream, so trust the proxy
// scheme headers as well as $_SERVER['HTTPS'].
// X-Forwarded-* headers are client-spoofable, so only honor them when the admin
// has declared a trusted TLS-terminating proxy (config 'trust_proxy'). Otherwise
// a plain-HTTP request carrying "X-Forwarded-Proto: https" could skip the
// redirect on a fallback (no-.htaccess) install. $_SERVER['HTTPS']/SERVER_PORT
// are set by the web server and are authoritative.
$trustProxy = (bool)cfg('trust_proxy');
$isHttps = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
    || (string)($_SERVER['SERVER_PORT'] ?? '') === '443'
    || ($trustProxy && (strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https'
        || strtolower((string)($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '')) === 'on'));
// Never redirect the ACME challenge, or the certificate can't renew over http.
$isAcme = str_starts_with((string)($_SERVER['REQUEST_URI'] ?? ''), '/.well-known/acme-challenge/');
if (PHP_SAPI !== 'cli' && !$isHttps && !$isAcme) {
    // Redirect to the canonical host (from base_url, so the Host header can't be
    // spoofed into an open redirect), preserving the path, query, and method.
    // Scheme, host and port only: REQUEST_URI already carries any install folder
    // (base_url may be https://example.org/cms), so appending it to the full
    // base_url would double the folder.
    $base = parse_url(rtrim((string)cfg('base_url'), '/'));
    $host = is_array($base) && strtolower((string)($base['scheme'] ?? '')) === 'https' && !empty($base['host'])
        ? 'https://' . $base['host'] . (isset($base['port']) ? ':' . (int)$base['port'] : '')
        : 'https://' . preg_replace('/[^A-Za-z0-9.\-:]/', '', (string)($_SERVER['HTTP_HOST'] ?? ''));
    http_response_code(($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' ? 301 : 308);
    header('Location: ' . $host . ($_SERVER['REQUEST_URI'] ?? '/'));
    exit;
}

ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $isHttps,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_name('cms');
// Keep session files alive well past the app's own idle timeout, so expiry is
// always handled by our code (which preserves typed input) rather than by the
// garbage collector (which would surface as a bare CSRF failure).
ini_set('session.gc_maxlifetime', (string)(48 * 3600));
session_start();

header('Cache-Control: no-store');
// The PHP version banner (expose_php) helps only someone matching known flaws.
header_remove('X-Powered-By');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
// Inline styles/handlers are part of the templates, hence 'unsafe-inline';
// everything else is locked to this origin.
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; media-src 'self' blob:; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'");

function redirect(string $path): never { header('Location: ' . $path); exit; }

/* ---------- Public-page language (English default, Español available) ---------- */
if (isset($_GET['lang']) && in_array($_GET['lang'], ['en', 'es'], true)) {
    $_SESSION['lang'] = $_GET['lang'];
}
require_once __DIR__ . '/i18n.php';

/** The Español/English toggle links for public pages. */
function lang_toggle(): string {
    $qs = $_GET;
    $qs['lang'] = app_lang() === 'es' ? 'en' : 'es';
    $url = h(strtok((string)$_SERVER['REQUEST_URI'], '?') . '?' . http_build_query($qs));
    $label = app_lang() === 'es' ? 'English' : 'Español';
    // Switching languages reloads the page, so what is in every form on the page
    // is carried across in sessionStorage (redundant entry, WCAG 3.3.7), keyed by
    // form position and field name. Every saved field is put back as it was,
    // including edits to prefilled values and unticked boxes. File inputs can't
    // be set by script, and passwords and hidden fields are never stored.
    $keep = <<<'JS'
<script>(function(){
  var link=document.currentScript.previousElementSibling.querySelector('a'),KEY='cms_lang_keep';
  function forms(){return document.querySelectorAll('main form');}
  function skip(el){return !el.name||/^(password|hidden|file|submit|button|reset|image)$/.test(el.type);}
  function checkable(el){return el.type==='checkbox'||el.type==='radio';}
  link.addEventListener('click',function(){
    try{
      var all=[];
      forms().forEach(function(f){
        var d=Object.create(null);
        f.querySelectorAll('input,select,textarea').forEach(function(el){
          if(skip(el))return;
          var s=d[el.name]||(d[el.name]={c:[],v:[]});
          if(checkable(el)){if(el.checked)s.c.push(el.value);}
          else if(el.tagName==='SELECT'&&el.multiple){for(var i=0;i<el.options.length;i++)if(el.options[i].selected)s.c.push(el.options[i].value);}
          else s.v.push(el.value);
        });
        all.push(d);
      });
      sessionStorage.setItem(KEY,JSON.stringify({p:location.pathname,f:all}));
    }catch(e){}
  });
  function restore(){
  try{
    var saved=JSON.parse(sessionStorage.getItem(KEY)||'null');
    if(!saved||saved.p!==location.pathname)return;
    sessionStorage.removeItem(KEY);
    if(!Array.isArray(saved.f))return;
    var fs=forms(),own=Object.prototype.hasOwnProperty;
    saved.f.forEach(function(d,i){
      var f=fs[i];if(!f||!d)return;
      var seen=Object.create(null);
      f.querySelectorAll('input,select,textarea').forEach(function(el){
        if(skip(el)||!own.call(d,el.name))return;
        var s=d[el.name],ev=null;
        if(checkable(el)){
          var on=s.c.indexOf(el.value)!==-1;
          if(el.checked!==on){el.checked=on;if(el.type==='checkbox')ev='change';}
        }else if(el.tagName==='SELECT'&&el.multiple){
          for(var j=0;j<el.options.length;j++)el.options[j].selected=s.c.indexOf(el.options[j].value)!==-1;
          ev='change';
        }else{
          var n=seen[el.name]||0;seen[el.name]=n+1;
          if(n>=s.v.length)return;
          var val=s.v[n];
          if(el.tagName==='SELECT'&&!Array.prototype.some.call(el.options,function(o){return o.value===val;}))return;
          if(el.value!==val){el.value=val;ev=el.tagName==='SELECT'?'change':'input';}
        }
        if(ev)el.dispatchEvent(new Event(ev,{bubbles:true}));
      });
      f.querySelectorAll('input[type=radio]:checked').forEach(function(el){el.dispatchEvent(new Event('change',{bubbles:true}));});
    });
  }catch(e){}
  }
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',restore,{once:true});
  else restore();
})();</script>
JS;
    return '<p class="lang-toggle"><a href="' . $url . '" lang="' . (app_lang() === 'es' ? 'en' : 'es') . '">' . $label . '</a></p>' . $keep;
}

/* ---------- Resume after re-authenticating (WCAG 2.2.5) ---------- */

/**
 * A fetch() request from page script (the voice assistant, AI drafting, JSON
 * lookups) rather than a page the user navigated to. It must get a JSON answer,
 * never the sign-in page, and is never a place to come back to after sign-in.
 */
function is_script_request(): bool {
    $mode = strtolower((string)($_SERVER['HTTP_SEC_FETCH_MODE'] ?? ''));
    return isset($_SERVER['HTTP_X_CSRF_TOKEN'])
        || str_contains(strtolower((string)($_SERVER['CONTENT_TYPE'] ?? '')), 'json')
        || ($mode !== '' && $mode !== 'navigate' && $mode !== 'nested-navigate');
}

/** The session has ended during a script request: say so in JSON (401). */
function session_ended_json(): never {
    http_response_code(401);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['ok' => false, 'error' => 'Your session ended. Sign in again, then try once more.']);
    exit;
}

/** Whether a POST provably came from a page of this site (not another site). */
function same_origin_post(): bool {
    $site = strtolower((string)($_SERVER['HTTP_SEC_FETCH_SITE'] ?? ''));
    if ($site !== '') return $site === 'same-origin';
    $origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
    $host = strtolower((string)parse_url((string)cfg('base_url'), PHP_URL_HOST));
    return $origin !== '' && $host !== '' && strtolower((string)parse_url($origin, PHP_URL_HOST)) === $host;
}

/**
 * Save the current page (and, on POST, what was typed) so sign-in can bring
 * the user straight back with their input refilled instead of losing it.
 * $uid is the account the page belonged to (0 when the session had already
 * gone); only that account gets it back, so with no known account what was
 * typed is not kept at all. Script requests are never stashed.
 */
function stash_resume(int $uid = 0, bool $keepDraft = true): void {
    if (is_script_request()) return;
    $keep = $uid > 0 && $keepDraft && $_SERVER['REQUEST_METHOD'] === 'POST';
    $_SESSION['resume'] = [
        'url' => safe_resume_url($_SERVER['REQUEST_URI'] ?? ''),
        'post' => $keep ? safe_form_draft($_POST) : [],
        'lists' => $keep ? safe_form_lists($_POST) : [],
        'uid' => $uid,
        'at' => time(),
    ];
}

/**
 * After a successful sign-in: the page to land on. Stashed input becomes
 * old-input so the form fields refill on that page, but only for the account
 * that left it (within a day). When that account is unknown, the page alone is
 * kept for 15 minutes and the input is dropped: it may not be this user's, or
 * may have come from another site's form. Anyone else starts at home.
 */
function take_resume_url(): string {
    $r = $_SESSION['resume'] ?? null;
    unset($_SESSION['resume']);
    if (!is_array($r) || empty($r['url'])) return 'index.php';
    $owner = (int)($r['uid'] ?? 0);
    $age = time() - (int)($r['at'] ?? 0);
    $mine = $owner > 0
        ? $owner === (int)($_SESSION['user_id'] ?? 0) && $age <= 86400
        : $age <= 900;
    if (!$mine) return 'index.php';
    if ($owner > 0 && !empty($r['post'])) {
        $_SESSION['old_input'] = safe_form_draft($r['post']);
    }
    if ($owner > 0 && !empty($r['lists']) && is_array($r['lists'])) {
        $_SESSION['old_input_lists'] = safe_form_lists($r['lists']);
    }
    // Return the page relative to the app, so redirect() resolves it from the
    // current page under a subdirectory install too. The stashed REQUEST_URI
    // already includes any install folder ('/cms/client.php?…'), and every page
    // lives in the app's top folder, so the last path segment is the page.
    [$path, $query] = array_pad(explode('?', safe_resume_url($r['url']), 2), 2, null);
    return basename($path) . ($query !== null ? '?' . $query : '');
}

/**
 * take_resume_url() for a completed sign-in. When a draft was put back into
 * the form, the page says so (with the welcome), so nobody leaves it thinking
 * it was already saved. Not every form refills every field, so the message
 * asks for a check rather than promising the whole draft.
 */
function resume_url_after_sign_in(): string {
    $url = take_resume_url();
    if (!empty($_SESSION['old_input']) || !empty($_SESSION['old_input_lists'])) {
        flash_ui('What you had typed before signing in was put back into the form, but nothing has been saved yet. Check each field, type again anything missing or showing the saved version, then submit.', 'info');
    }
    return $url;
}

/* ---------- Preserve form input across an error redirect (WCAG 3.3.7) ---------- */
$GLOBALS['OLD_INPUT'] = $_SESSION['old_input'] ?? [];
// Multi-value fields (ticked boxes, multi-selects) are kept beside the text.
$GLOBALS['OLD_INPUT_LISTS'] = $_SESSION['old_input_lists'] ?? [];
unset($_SESSION['old_input'], $_SESSION['old_input_lists']);
// Field-level validation errors, stashed across a post/redirect/get error the
// same way old_input is (inline-rendered forms override via set_form_errors()).
$GLOBALS['FORM_ERRORS'] = $_SESSION['field_errors'] ?? [];
unset($_SESSION['field_errors']);
/** Call before redirecting on a validation error to keep what was typed. */
function keep_input(): void {
    // Never persist credential fields into the session store when refilling a form.
    $_SESSION['old_input'] = safe_form_draft($_POST);
    $_SESSION['old_input_lists'] = safe_form_lists($_POST);
}
/** Previously-typed value for refilling a field after an error redirect. */
function old_in(string $k, string $default = ''): string {
    $v = $GLOBALS['OLD_INPUT'][$k] ?? $default;
    return is_string($v) ? $v : $default;
}
/** The values previously chosen in a multi-value field (name="k[]"), for
 *  re-ticking checkboxes or multi-select options; [] when none were kept. */
function old_in_list(string $k): array {
    $v = $GLOBALS['OLD_INPUT_LISTS'][$k] ?? [];
    return is_array($v) ? array_values(array_filter($v, 'is_string')) : [];
}

function flash(string $msg, string $type = 'success'): void {
    $_SESSION['flash'][] = ['msg' => $msg, 'type' => $type];
}
/** Trusted interface copy is translated once, when the destination page renders. */
function flash_ui(string $msg, string $type = 'success'): void {
    $_SESSION['flash'][] = ['msg' => $msg, 'type' => $type, 'translate' => true];
}
function take_flashes(): array {
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

/* ---------- Accessible field-level form errors (WCAG 3.3.1 / 3.3.3) ----------
   A screen-reader user needs to know WHICH field is wrong and why. These helpers
   give each control aria-invalid + aria-describedby pointing at an inline error,
   and render an error summary of focusable links that jump to each bad field. */

/** The required-field marker for a label: a visual asterisk plus "(required)"
 *  for screen readers, so every form marks required fields the same way (3.3.2). */
function required_mark(): string {
    return '<span aria-hidden="true">*</span><span class="visually-hidden">(required)</span>';
}

/** Stash per-field errors before an error redirect (post/redirect/get forms). */
function keep_field_errors(array $errors): void { $_SESSION['field_errors'] = $errors; }
/** Set per-field errors for a form rendered inline in the same request. */
function set_form_errors(array $errors): void { $GLOBALS['FORM_ERRORS'] = $errors; }
function form_errors(): array { return $GLOBALS['FORM_ERRORS'] ?? []; }
function has_field_error(string $field): bool { return isset(form_errors()[$field]); }

/** aria-invalid + aria-describedby for one control. Extra args are the ids of
 *  any hint elements that describe the field, so they're announced too. */
function field_aria(string $field, string ...$hintIds): string {
    $errs = form_errors();
    $ids = array_values(array_filter($hintIds, fn($i) => $i !== ''));
    if (isset($errs[$field])) $ids[] = 'err-' . $field;
    $out = isset($errs[$field]) ? 'aria-invalid="true" ' : '';
    if ($ids) $out .= 'aria-describedby="' . h(implode(' ', $ids)) . '"';
    return trim($out);
}
/** The inline error paragraph for one control (empty string when valid). */
function field_error(string $field): string {
    $errs = form_errors();
    return isset($errs[$field]) ? '<p class="field-error" id="err-' . h($field) . '">' . t($errs[$field]) . '</p>' : '';
}
/** An accessible error summary: a focused heading plus a link per bad field that
 *  jumps straight to it. $labels maps field=>human label; $anchors maps
 *  field=>the control's id when it differs from the field name. '' when valid. */
function form_error_summary(array $labels = [], array $anchors = []): string {
    $errs = form_errors();
    // When labels are given, this summary only covers those fields — so a page
    // with several forms doesn't show one form's errors in another's summary.
    if ($labels) $errs = array_intersect_key($errs, $labels);
    if (!$errs) return '';
    $items = '';
    foreach ($errs as $field => $msg) {
        $anchor = $anchors[$field] ?? $field;
        $label = (isset($labels[$field]) && $labels[$field] !== '') ? t($labels[$field]) . ': ' : '';
        // Each link is a stand-alone target, so it gets the full 44px height (2.5.5).
        $items .= '<li><a href="#' . h($anchor) . '" style="display:inline-flex; align-items:center; min-height:2.75rem;">' . $label . t($msg) . '</a></li>';
    }
    // No role="alert": the summary is announced once, by the focus move below.
    // Its title is not a heading: the summary sits inside sections at every
    // level, and a fixed h2 would re-file the headings after it (1.3.1).
    return '<div class="alert alert-error" tabindex="-1" id="form-error-summary">'
        . '<p class="legend-h">' . t('Please fix the following:') . '</p>'
        . '<ul>' . $items . '</ul></div>'
        . '<script>document.addEventListener("DOMContentLoaded",function(){var s=document.getElementById("form-error-summary");if(s)s.focus();});</script>';
}

/* ---------- CSRF ---------- */
function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
function csrf_field(): string {
    // csrf_uid names the account the form was rendered for (0 for a visitor), so a
    // stale form's typed text is only ever refilled for that same account.
    return '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">'
        . '<input type="hidden" name="csrf_uid" value="' . (int)($_SESSION['user_id'] ?? 0) . '">';
}
/** Whether the posted form was rendered for the account (or the signed-out visitor) $uid. */
function csrf_form_owner_is(int $uid): bool {
    $posted = $_POST['csrf_uid'] ?? null;
    return is_string($posted) && ctype_digit($posted) && (int)$posted === $uid;
}
/** A php.ini size such as '8M' or '1G', in bytes (0 = no limit). */
function ini_size_bytes(string $v): int {
    $v = trim($v);
    $n = (int)$v;
    switch (strtolower(substr($v, -1))) {
        case 'g': $n *= 1024; // no break: each unit multiplies again
        case 'm': $n *= 1024;
        case 'k': $n *= 1024;
    }
    return $n;
}

function csrf_check(): void {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // A body over post_max_size is thrown away whole by PHP, token and file
        // included, so it would otherwise look like a forged request. Say what
        // actually happened and go back to the same page instead.
        $postMax = ini_size_bytes((string)ini_get('post_max_size'));
        if ($postMax > 0 && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > $postMax) {
            $tooBig = 'That upload is larger than this server accepts. Nothing was saved. Choose a smaller file and try again.';
            if (is_script_request()) {
                http_response_code(413);
                header('Content-Type: application/json; charset=UTF-8');
                echo json_encode(['ok' => false, 'error' => $tooBig]);
                exit;
            }
            flash_ui($tooBig, 'error');
            redirect(safe_resume_url($_SERVER['REQUEST_URI'] ?? ''));
        }
        // Form field, or the X-CSRF-Token header for JSON/fetch requests.
        $t = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        if (!is_string($t) || !hash_equals(csrf_token(), $t)) {
            // Page script keeps its plain-text 403 (voice.js and at-ai.js read it
            // as "the session changed"), or 401 JSON once signed out.
            if (is_script_request()) {
                if (empty($_SESSION['user_id'])) session_ended_json();
                http_response_code(403);
                exit('Invalid request token. Please go back, refresh the page, and try again.');
            }
            // A stale token on a page of this site (the session changed in
            // another tab, or a client's session lapsed while a portal, consent,
            // survey, receipt or counselor page sat open): back to the same page
            // with what was typed, so it only has to be sent again. Input posted
            // from another site, or from a form rendered for a different account
            // (another account has signed in on this browser since), is never
            // kept, and public visitors, who have no account, are never sent to
            // the staff sign-in page.
            $public = csrf_public_page();
            if (same_origin_post() && (!empty($_SESSION['user_id']) || $public)
                && csrf_form_owner_is((int)($_SESSION['user_id'] ?? 0))) {
                keep_input();
                flash_ui('This page had been open too long, so nothing was saved or sent. What you typed is back in the form (choose any file again): check it, then send it again.', 'error');
                redirect(csrf_return_url());
            }
            if ($public && empty($_SESSION['user_id'])) {
                flash_ui('This page had been open too long, so nothing was sent. Fill in the form and send it again.', 'error');
                redirect(safe_resume_url($_SERVER['REQUEST_URI'] ?? ''));
            }
            // A missing token with no signed-in user almost always means the
            // session died while a form sat open. Save what was typed and send
            // them to sign in; a genuine CSRF against a live session is refused.
            if (empty($_SESSION['user_id'])) {
                // Another site's form also arrives here (the cookie is SameSite=Lax),
                // so its fields are never kept to be refilled after sign-in. The
                // account is the one whose timed-out session this browser had, if
                // known; without one nothing typed is kept (see stash_resume()).
                $owner = (int)($_SESSION['resume']['uid'] ?? 0);
                $kept = $owner > 0 && same_origin_post() && csrf_form_owner_is($owner);
                stash_resume($owner, $kept);
                flash($kept ? 'You were signed out while that page was open. Sign in again — what you typed has been kept.'
                    : 'You are signed out. Sign in to continue.', 'error');
                redirect('login.php');
            }
            csrf_refused_page();
        }
    }
}

/** Public pages reached by a private link, whose visitors have no staff account. */
function csrf_public_page(): bool {
    return in_array(basename((string)($_SERVER['SCRIPT_NAME'] ?? '')),
        ['portal.php', 'consent.php', 'release.php', 'survey.php', 'ack.php', 'vrc.php', 'community_referral.php'], true);
}

/** The page the stale form was on (its same-site referrer), else the page posted to. */
function csrf_return_url(): string {
    $ref = parse_url((string)($_SERVER['HTTP_REFERER'] ?? ''));
    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    if (is_array($ref) && isset($ref['host'], $ref['path']) && $host !== ''
        && strtolower($ref['host'] . (isset($ref['port']) ? ':' . $ref['port'] : '')) === $host) {
        return safe_resume_url($ref['path'] . (isset($ref['query']) ? '?' . $ref['query'] : ''));
    }
    return safe_resume_url($_SERVER['REQUEST_URI'] ?? '');
}

/** A form token that does not match a live session, from a request not provably
 *  from this site: refused (403) with a real page — title, language, heading and
 *  a way back — rather than a bare line of text. Nothing is kept or changed. */
function csrf_refused_page(): never {
    while (ob_get_level() > 0) ob_end_clean();
    http_response_code(403);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<script src="assets/low-vision.js?v=' . h(APP_VERSION) . '"></script>'
        . '<title>Request not completed — ' . h((string)cfg('name')) . '</title>'
        . '<link rel="stylesheet" href="assets/styles.css?v=' . h(APP_VERSION) . '"></head>'
        . '<body><nav class="wrap page-actions" aria-label="Page navigation"><a href="index.php">Dashboard</a><a href="index.php#main-nav">Go to main menu</a></nav><main class="wrap" aria-label="Main content"><h1>Request not completed</h1>'
        . '<p>Invalid request token: the form was sent from an out-of-date copy of a page, or from another site, so nothing was saved.</p>'
        . '<p>Go back to the page, reload it, and send the form again. Copy any long text you typed first: reloading can clear it.</p>'
        . '<p><a href="' . h(csrf_return_url()) . '">Go back to the page</a></p></main></body></html>';
    exit;
}

/* ---------- Auth & session timeout ---------- */
/**
 * Start a sign-in from a clean session under a new id. Nothing an earlier
 * account left in this browser carries over: not its form token (a new one is
 * issued), a password-checked passkey challenge, a pending authenticator
 * secret or a draft. Only the page to return to (take_resume_url() checks
 * whose it is), the chosen language and messages waiting to be shown are kept.
 */
function fresh_auth_session(): void {
    $kept = array_intersect_key($_SESSION, array_flip(['resume', 'lang', 'flash']));
    session_unset();
    session_regenerate_id(true);
    $_SESSION = $kept;
}

/** Establish every browser sign-in against the current durable credentials. */
function establish_user_session(array $row): void {
    fresh_auth_session();
    $_SESSION['user_id'] = (int)$row['id'];
    $_SESSION['user_name'] = $row['name'];
    $_SESSION['user_email'] = $row['email'];
    $_SESSION['user_role'] = $row['role'];
    $_SESSION['credential_version'] = credential_version($row);
    $_SESSION['otp_verified'] = true;
    $_SESSION['last_activity'] = time();
}

/**
 * Password re-check for a sensitive change (see reauth_password()). The
 * failure that reaches the limit ends this session, which may be a hijacked
 * one, and is audited; the caller then shows REAUTH_SIGNED_OUT_MSG and sends
 * the user to sign in. Returns 'ok', 'wrong', 'locked' or 'signed_out'.
 */
function reauth_check(int $userId, string $password): string {
    $r = reauth_password($userId, $password);
    if ($r !== 'lockout') return $r;
    audit($userId, 'reauth.lockout', 'user', $userId, 'signed out after 5 wrong passwords in 15 minutes');
    session_unset();
    session_destroy();
    session_start();
    return 'signed_out';
}
/** User-facing text for the two refusal states of reauth_check(). */
const REAUTH_LOCKED_MSG = 'Too many incorrect passwords. Wait 15 minutes, then try again.';
const REAUTH_SIGNED_OUT_MSG = 'Too many incorrect passwords, so you were signed out to protect your account. Sign in again; you can retry this change after 15 minutes.';

function current_user(): ?array {
    if (empty($_SESSION['user_id']) || ($_SESSION['otp_verified'] ?? false) !== true) return null;
    $s = db()->prepare('SELECT * FROM users WHERE id = ?');
    $s->execute([(int)$_SESSION['user_id']]);
    $row = $s->fetch();
    if (!$row || !session_matches_user($_SESSION, $row)) {
        unset($_SESSION['user_id'], $_SESSION['otp_verified'], $_SESSION['credential_version']);
        return null;
    }
    return ['id' => (int)$row['id'], 'name' => $row['name'], 'email' => $row['email'], 'role' => $row['role']];
}

function require_login(): array {
    $timeout = ((int) cfg('session_timeout_minutes')) * 60;
    // Whose page this is, captured before the session can be cleared below: the
    // signed-in account, or once signed out, the account whose ended session
    // left a page to come back to (as csrf_check() does).
    $sessionUid = (int)($_SESSION['user_id'] ?? 0) ?: (int)($_SESSION['resume']['uid'] ?? 0);
    if (isset($_SESSION['last_activity']) && (time() - (int)$_SESSION['last_activity']) > $timeout) {
        session_unset();
        session_destroy();
        session_start();
        if (is_script_request()) session_ended_json();
        // The fresh session carries the page and any typed input forward, so
        // signing back in continues exactly where the user left off.
        stash_resume($sessionUid);
        flash('Your session expired after ' . cfg('session_timeout_minutes') . ' minutes of inactivity. Sign in again, then review the form. Some fields and file selections may need to be entered again.', 'error');
        redirect('login.php');
    }
    $_SESSION['last_activity'] = time();
    $u = current_user();
    if (!$u) {
        if (is_script_request()) session_ended_json();
        stash_resume($sessionUid);
        redirect('login.php');
    }

    // Load the live user row (deactivation takes effect immediately) and
    // resolve group permissions for this request.
    $row = db()->prepare('SELECT * FROM users WHERE id = ? AND is_active = 1');
    $row->execute([(int)$u['id']]);
    $userRow = $row->fetch();
    if (!$userRow) {
        session_unset();
        session_destroy();
        session_start();
        redirect('login.php');
    }
    $resolved = resolve_perms($userRow);
    $GLOBALS['CURRENT_PERMS'] = $resolved['perms'];
    $GLOBALS['CURRENT_SCOPED'] = $resolved['scoped'];
    $u['perms'] = $resolved['perms'];
    $u['scoped'] = $resolved['scoped'];
    $u['group'] = $resolved['group'];
    return $u;
}

/**
 * For pages open to everyone (Help): the signed-in user with permissions
 * resolved exactly as require_login() does, so the navigation matches every
 * other page, or null when nobody is signed in or the session has idled out.
 * Never redirects; an idled-out session is left for the next protected page.
 */
function optional_login(): ?array {
    $timeout = ((int) cfg('session_timeout_minutes')) * 60;
    if (isset($_SESSION['last_activity']) && (time() - (int)$_SESSION['last_activity']) > $timeout) return null;
    $u = current_user();
    if (!$u) return null;
    $row = db()->prepare('SELECT * FROM users WHERE id = ? AND is_active = 1');
    $row->execute([(int)$u['id']]);
    $userRow = $row->fetch();
    if (!$userRow) return null;
    $_SESSION['last_activity'] = time();
    $resolved = resolve_perms($userRow);
    $GLOBALS['CURRENT_PERMS'] = $resolved['perms'];
    $GLOBALS['CURRENT_SCOPED'] = $resolved['scoped'];
    $u['perms'] = $resolved['perms'];
    $u['scoped'] = $resolved['scoped'];
    $u['group'] = $resolved['group'];
    return $u;
}

/** Gate a page on a permission; redirects with an explanation when denied. */
function require_perm(string $perm): void {
    if (!can($perm)) {
        flash('Your account does not have access to that area (' . (PERMISSION_KEYS[$perm] ?? $perm) . '). Ask an administrator if you need it.', 'error');
        redirect('index.php');
    }
}

/** Deny access to a client outside a caseload-scoped user's assignments. */
function scope_check_client(?array $client, array $user): void {
    if (!caseload_scoped() || !$client) return;
    if ((int)($client['assigned_to'] ?? 0) !== (int)$user['id']) {
        flash('That client is not on your caseload.', 'error');
        redirect('clients.php');
    }
}

/** Same check when only the client id is at hand. */
function scope_check_client_id(int $clientId, array $user): void {
    if (!caseload_scoped()) return;
    $s = db()->prepare('SELECT assigned_to FROM clients WHERE id = ?');
    $s->execute([$clientId]);
    scope_check_client(['assigned_to' => $s->fetchColumn()], $user);
}
