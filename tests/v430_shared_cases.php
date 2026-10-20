<?php
// Included by full_schema.php after the v429 cases, against its synthetic database only:
// 4.30.0 (accessibility), the shared page parts: messages focused after a #fragment
// redirect (A1), the unsaved-changes dialog in the page language (A2), English parts
// marked on Spanish pages, the required mark in Spanish, the menu's own heading and
// name, quiet Text size and Loudness lists, and table controls that never squeeze.

$v430sLayout = (string)file_get_contents($root . '/public/inc/layout.php');
$v430sBoot = (string)file_get_contents($root . '/public/inc/bootstrap.php');
$v430sCss = (string)file_get_contents($root . '/public/assets/styles.css');
$v430sNav = (string)file_get_contents($root . '/public/assets/navigation.js');
$v430sDate = (string)file_get_contents($root . '/public/assets/date-entry.js');
$v430sVision = (string)file_get_contents($root . '/public/assets/low-vision.js');
$v430sVoice = (string)file_get_contents($root . '/public/assets/voice.js');
$v430sTimeout = (string)file_get_contents($root . '/public/assets/session-timeout.js');

// ---- A1: result and error messages are focused after the load and the fragment scroll ----
check(str_contains($v430sBoot, "focus_after_load_script('document.getElementById(\"form-error-summary\")')")
    && !str_contains($v430sBoot, 'document.addEventListener("DOMContentLoaded",function(){var s=document.getElementById("form-error-summary");if(s)s.focus();});'),
    'V430-SHARED: the error summary takes focus after the page has loaded, not on DOMContentLoaded');
check(str_contains($v430sBoot, 'if(window.cmsFocusAfterLoad)window.cmsFocusAfterLoad(f);else document.addEventListener("DOMContentLoaded",f);'),
    'V430-SHARED: the focus script uses the after-load helper, with DOMContentLoaded only where the page has none');
check(str_contains($v430sLayout, "window.addEventListener('load',run,{once:true})") && str_contains($v430sLayout, 'setTimeout(function(){if(!acted)fn();},0)')
    && str_contains($v430sLayout, '(window.cmsFocusAfterLoad||function(f){f();})(function(){a.focus();})')
    && str_contains($v430sLayout, "focus_after_load_script('document.getElementById(\"form-errors\")')"),
    'V430-SHARED: the result messages and error notices are focused after load plus one task');

[$code, $v430sIndex, $err] = $renderPage('index', []);
check($code === 0 && $err === '' && str_contains($v430sIndex, 'window.cmsFocusAfterLoad=function(fn){window.cmsFocusClaimed=true;afterLoad(fn);};')
    && strpos($v430sIndex, 'window.cmsFocusAfterLoad=function(fn)') < strpos($v430sIndex, '<body')
    && str_contains($v430sIndex, 'if(t&&(f===t||t.contains(f)))return;') && str_contains($v430sIndex, 'if(!window.cmsFocusClaimed&&'),
    'V430-SHARED: every page defines the after-load focus helper in its head' . ($err ? ": $err" : ''));

// ---- Menu: its own hidden h2, and a named list for "Go to main menu" ----
check(preg_match('~<nav aria-label="Main">\s*(?:<\?php[^?]*\?>\s*)?<h2 class="visually-hidden" id="main-menu-h">Main menu</h2>~', $v430sIndex) === 1
    && str_contains($v430sIndex, '<ul class="main-nav" id="main-nav" tabindex="-1" aria-labelledby="main-menu-h">')
    && strpos($v430sIndex, 'id="main-menu-h"') < strpos($v430sIndex, 'class="nav-group-title"'),
    'V430-SHARED: the menu groups sit under a "Main menu" h2, which also names the list "Go to main menu" focuses');

// ---- English page: shared script text is English ----
preg_match('~<body data-cms-text="([^"]*)"~', $v430sIndex, $v430sM);
$v430sText = json_decode(html_entity_decode($v430sM[1] ?? '', ENT_QUOTES), true);
check(is_array($v430sText) && $v430sText['lang'] === 'en' && $v430sText['unsavedTitle'] === 'Unsaved changes' && $v430sText['typeDate'] === 'Type date instead',
    'V430-SHARED: on an English page the dialog and date entry words are English');

// ---- Staff on a Spanish public page: English shared parts are marked, the rest is Spanish ----
putenv('CMS_TEST_SESSION_ADD=' . json_encode(['lang' => 'es']));
[$code, $v430sEs, $err] = $renderPage('community_referral', ['lang' => 'es']);
putenv('CMS_TEST_SESSION_ADD');
check($code === 0 && $err === '' && preg_match('~<html[^>]*lang="es"~', $v430sEs) === 1
    && preg_match('~<div id="timeout-warn"[^>]*lang="en"~', $v430sEs) === 1
    && preg_match('~<div id="session-check-live"[^>]*lang="en"~', $v430sEs) === 1
    && (!str_contains($v430sEs, '<div id="voice-fab"') || str_contains($v430sEs, '<div id="voice-fab" lang="en">')),
    'V430-SHARED: the timeout warning, session check and voice assistant are marked English on a Spanish page' . ($err ? ": $err" : ''));
preg_match('~<body data-cms-text="([^"]*)"~', $v430sEs, $v430sM);
$v430sText = json_decode(html_entity_decode($v430sM[1] ?? '', ENT_QUOTES), true);
check(is_array($v430sText) && $v430sText['lang'] === 'es' && $v430sText['unsavedTitle'] === 'Cambios sin guardar'
    && $v430sText['leave'] === 'Salir sin guardar' && $v430sText['send'] === 'Enviar este formulario de todos modos'
    && $v430sText['typeDate'] === 'Escribir la fecha a mano' && $v430sText['nounDate'] === 'fecha'
    && $v430sText['confirmLeave'] !== 'Leave this page without saving your changes? Choose Cancel to keep editing.',
    'V430-SHARED: on a Spanish page the unsaved-changes dialog, its confirm fallback and the date entry are Spanish');
check(str_contains($v430sEs, 'data-size-msg="Tamaño del texto {size} %"') && str_contains($v430sEs, 'data-on-msg="{name}: activado"')
    && str_contains($v430sEs, 'data-on-msg="Avisos sonoros activados."') && str_contains($v430sEs, '<span lang="es">Avisos sonoros</span>'),
    'V430-SHARED: the low-vision and sound cue messages are Spanish on a Spanish page');
check(!str_contains($v430sEs, '<span class="visually-hidden">(required)</span>'),
    'V430-SHARED: a Spanish page never has an English "(required)" mark');

// ---- required_mark() follows the page language ----
check(str_contains($v430sBoot, "'<span aria-hidden=\"true\">*</span><span class=\"visually-hidden\">' . h(__('(required)')) . '</span>'"),
    'V430-SHARED: the required mark is said in the page language');
check(str_contains($v430sIndex, '<span class="visually-hidden">(required)</span>') || !str_contains($v430sIndex, '<span aria-hidden="true">*</span><span class="visually-hidden">'),
    'V430-SHARED: the required mark is "(required)" on English pages');
putenv('CMS_TEST_ANON=1'); putenv('CMS_TEST_SESSION=' . json_encode(['lang' => 'es']));
[$code, $v430sPortalEs, $err] = $renderPage('portal', ['t' => str_repeat('0', 48), 'lang' => 'es']);
putenv('CMS_TEST_ANON'); putenv('CMS_TEST_SESSION');
check($code === 0 && preg_match('~lang="es"~', $v430sPortalEs) === 1 && !str_contains($v430sPortalEs, '<span class="visually-hidden">(required)</span>')
    && (!str_contains($v430sPortalEs, '<span aria-hidden="true">*</span><span class="visually-hidden">') || str_contains($v430sPortalEs, '<span aria-hidden="true">*</span><span class="visually-hidden">(obligatorio)</span>')),
    'V430-SHARED: the required mark is "(obligatorio)" on a Spanish page');
check(!preg_match('/\b(the|this|your|Enter|Type)\b/', implode(' ', array_diff_key($v430sText, ['lang' => 1]))),
    'V430-SHARED: every shared script string has Spanish');

// ---- A2: the unsaved-changes dialog ----
check(str_contains($v430sNav, "if (a.closest && a.closest('.lang-toggle') && langKeepsAll()) { leaving = true; return; }") && str_contains($v430sBoot, '<p class="lang-toggle">')
    && str_contains($v430sNav, "(e.type === 'file' && e.files && e.files.length) || (e.type === 'password' && e.value)"),
    'V430-SHARED: the language switch asks about unsaved changes only when it cannot carry them (chosen files, passwords, no sessionStorage)');
check(str_contains($v430sNav, 'var other = typedElsewhere(e.target);') && str_contains($v430sNav, '}, other, true);'),
    'V430-SHARED: sending a form asks only about typed work in another form, never about its own fields or a choice in a list');
check(str_contains($v430sNav, "description.textContent = sending ? (save.hidden ? T.sendNoSave : T.sendSave) : (save.hidden ? T.leaveNoSave : T.leaveSave);")
    && str_contains($v430sNav, 'leave.textContent = sending ? T.send : T.leave;') && !str_contains($v430sNav, 'Save the form,') && str_contains($v430sNav, 'dialog.lang = T.lang;'),
    'V430-SHARED: the dialog says Save only when it has a Save button, its buttons say what they do, and it takes the page language');

// ---- Quiet lists (owner rule 2) ----
check(str_contains($v430sVision, 'sizeTimer = setTimeout(commitSize, 700);') && str_contains($v430sVision, "size.addEventListener('blur', commitSize);")
    && !str_contains($v430sVision, "update('Text size ' + size.value + '%')"),
    'V430-SHARED: Text size is applied and announced once, when the choice rests or the list is left');
check(str_contains($v430sVoice, 'volTimer = setTimeout(applyVolume, 700);') && str_contains($v430sVoice, "volSel.addEventListener('blur', applyVolume);"),
    'V430-SHARED: Loudness plays its sound and switches replies once, not per arrow press');
check(str_contains($v430sLayout, '<option value="max">Max</option>') && !str_contains($v430sLayout, 'Max (deaf-blind)')
    && str_contains($v430sLayout, 'Loudness<span class="visually-hidden"> (Max is for deaf-blind users: replies are shown as text)</span>'),
    'V430-SHARED: the Loudness options are plain names, with what Max does said in the label');

// ---- Date entry: a short button and the format inside the label ----
check(str_contains($v430sDate, "copy.querySelectorAll('[aria-hidden=\"true\"], .hint, .field-error, .visually-hidden, [data-date-entry]')")
    && !str_contains($v430sDate, "setAttribute('aria-label'") && str_contains($v430sDate, "note.setAttribute('aria-hidden', 'true');")
    && str_contains($v430sDate, 'if (spoken) label.appendChild(spoken);'),
    'V430-SHARED: "Type date instead" names only the field, and the format and error are said in the field\'s label');

// ---- Session-expired notice said once, under its own title ----
check(str_contains($v430sTimeout, "if (opening) {") && str_contains($v430sTimeout, "title.textContent = dialog.dataset.expiredTitle || 'You were signed out';")
    && str_contains($v430sLayout, 'data-expired-title="You were signed out"') && str_contains($v430sLayout, '<span id="tw-expired-lead">You were signed out. </span>'),
    'V430-SHARED: the signed-out dialog is titled "You were signed out" and says its notice by one channel');

// ---- Table controls never squeeze ----
check(str_contains($v430sCss, '.table-scroll :is(button, .btn) { overflow-wrap: normal; white-space: nowrap; }')
    && str_contains($v430sCss, '.table-scroll select { max-width: none; min-width: max-content; }'),
    'V430-SHARED: buttons and selects in data tables keep their words and values whole; the table scrolls');

// ---- A1 on every page: no page focuses its own message on DOMContentLoaded ----
$v430sEarly = [];
foreach (glob($root . '/public/*.php') as $v430sFile) {
    foreach (explode("\n", (string)file_get_contents($v430sFile)) as $v430sLine) {
        if (preg_match('~document\.addEventListener\(["\']DOMContentLoaded["\'],\s*function\s*\(\)\s*\{.*\.focus\(~', $v430sLine)) $v430sEarly[] = basename($v430sFile);
    }
}
check($v430sEarly === [], 'V430-SHARED: every page focuses its result or error after the load and fragment scroll' . ($v430sEarly ? ': ' . implode(', ', array_unique($v430sEarly)) : ''));
