<?php
declare(strict_types=1);

function app_lang(): string { return $_SESSION['lang'] ?? 'en'; }
function page_lang(): string { return !empty($GLOBALS['PUBLIC_TRANSLATION']) ? app_lang() : 'en'; }
function enable_public_translation(): void { $GLOBALS['PUBLIC_TRANSLATION'] = true; }
function translations(): array {
    static $map = null;
    $map ??= (require __DIR__ . '/lang_es.php') + shared_ui_es();
    return ($GLOBALS['TRANSLATIONS_EXTRA'] ?? []) + $map;
}
/** Spanish for the shared page parts and their scripts (4.30.0): the unsaved-changes
 *  dialog, the date and time text entry, the low-vision messages and the required mark.
 *  lang_es.php wins where it has the same key, so these can move there unchanged.
 *  {format}, {noun}, {value}, {size} and {name} are filled in by the scripts. */
function shared_ui_es(): array {
    return [
        '(required)' => '(obligatorio)',
        'Main menu' => 'Menú principal',
        'Sound cues' => 'Avisos sonoros',
        'Sound cues on.' => 'Avisos sonoros activados.',
        'Sound cues off.' => 'Avisos sonoros desactivados.',
        'Text size {size}%' => 'Tamaño del texto {size} %',
        '{name} on' => '{name}: activado',
        '{name} off' => '{name}: desactivado',
        'Unsaved changes' => 'Cambios sin guardar',
        'You have changes on this page that have not been saved. Save them, keep editing, or leave this page without saving them.' => 'Hay cambios en esta página que no se han guardado. Guárdelos, siga editando o salga de esta página sin guardarlos.',
        'You have changes on this page that have not been saved. Keep editing, or leave this page without saving them.' => 'Hay cambios en esta página que no se han guardado. Siga editando o salga de esta página sin guardarlos.',
        'Another form on this page has changes that have not been saved. Save them first, keep editing, or send this form anyway and lose them.' => 'Otro formulario de esta página tiene cambios que no se han guardado. Guárdelos primero, siga editando o envíe este formulario de todos modos y piérdalos.',
        'Another form on this page has changes that have not been saved. Keep editing, or send this form anyway and lose them.' => 'Otro formulario de esta página tiene cambios que no se han guardado. Siga editando o envíe este formulario de todos modos y piérdalos.',
        'Save changes' => 'Guardar los cambios',
        'Keep editing' => 'Seguir editando',
        'Leave without saving' => 'Salir sin guardar',
        'Send this form anyway' => 'Enviar este formulario de todos modos',
        'Leave this page without saving your changes? Choose Cancel to keep editing.' => '¿Salir de esta página sin guardar los cambios? Elija Cancelar para seguir editando.',
        'Send this form anyway? Changes in another form on this page have not been saved and will be lost. Choose Cancel to keep editing.' => '¿Enviar este formulario de todos modos? Los cambios de otro formulario de esta página no se han guardado y se perderán. Elija Cancelar para seguir editando.',
        'Type date instead' => 'Escribir la fecha a mano',
        'Type time instead' => 'Escribir la hora a mano',
        'Type date and time instead' => 'Escribir la fecha y la hora a mano',
        'Use date picker instead' => 'Usar el selector de fecha',
        'Use time picker instead' => 'Usar el selector de hora',
        'Use date and time picker instead' => 'Usar el selector de fecha y hora',
        'YYYY-MM-DD, for example 2026-10-14' => 'AAAA-MM-DD, por ejemplo 2026-10-14',
        'HH:MM in 24-hour time, for example 14:30' => 'HH:MM en formato de 24 horas, por ejemplo 14:30',
        'YYYY-MM-DDTHH:MM, for example 2026-10-14T14:30' => 'AAAA-MM-DDTHH:MM, por ejemplo 2026-10-14T14:30',
        'Type {format}. You can paste a complete value.' => 'Escriba {format}. Puede pegar un valor completo.',
        'Enter a valid {noun} using {format}.' => 'Escriba una {noun} válida con el formato {format}.',
        'Enter the required {noun} using {format}.' => 'Escriba la {noun}, que es obligatoria, con el formato {format}.',
        'Enter a {noun} within the allowed range and interval.' => 'Escriba una {noun} dentro del intervalo permitido.',
        'Earliest: {value}.' => 'Lo más pronto: {value}.',
        'Latest: {value}.' => 'Lo más tarde: {value}.',
        'Use {format}.' => 'Use el formato {format}.',
    ];
}
/** Strings the shared scripts (navigation.js, date-entry.js) say, in the page language,
 *  as the JSON layout.php puts on <body data-cms-text>. 'lang' is the language they are in. */
function shared_script_text(): array {
    $keys = [
        'unsavedTitle' => 'Unsaved changes',
        'leaveSave' => 'You have changes on this page that have not been saved. Save them, keep editing, or leave this page without saving them.',
        'leaveNoSave' => 'You have changes on this page that have not been saved. Keep editing, or leave this page without saving them.',
        'sendSave' => 'Another form on this page has changes that have not been saved. Save them first, keep editing, or send this form anyway and lose them.',
        'sendNoSave' => 'Another form on this page has changes that have not been saved. Keep editing, or send this form anyway and lose them.',
        'save' => 'Save changes', 'stay' => 'Keep editing', 'leave' => 'Leave without saving', 'send' => 'Send this form anyway',
        'confirmLeave' => 'Leave this page without saving your changes? Choose Cancel to keep editing.',
        'confirmSend' => 'Send this form anyway? Changes in another form on this page have not been saved and will be lost. Choose Cancel to keep editing.',
        'typeDate' => 'Type date instead', 'typeTime' => 'Type time instead', 'typeDateTime' => 'Type date and time instead',
        'useDate' => 'Use date picker instead', 'useTime' => 'Use time picker instead', 'useDateTime' => 'Use date and time picker instead',
        'fmtDate' => 'YYYY-MM-DD, for example 2026-10-14', 'fmtTime' => 'HH:MM in 24-hour time, for example 14:30',
        'fmtDateTime' => 'YYYY-MM-DDTHH:MM, for example 2026-10-14T14:30',
        'nounDate' => 'date', 'nounTime' => 'time', 'nounDateTime' => 'date and time',
        'hint' => 'Type {format}. You can paste a complete value.',
        'errInvalid' => 'Enter a valid {noun} using {format}.',
        'errRequired' => 'Enter the required {noun} using {format}.',
        'errRange' => 'Enter a {noun} within the allowed range and interval.',
        'earliest' => 'Earliest: {value}.', 'latest' => 'Latest: {value}.', 'use' => 'Use {format}.',
    ];
    // The nouns are single words, so they are kept here rather than in translations(),
    // where __('date') elsewhere would start to change.
    $nouns = ['date' => 'fecha', 'time' => 'hora', 'date and time' => 'fecha y hora'];
    $out = [];
    $allTranslated = true;
    foreach ($keys as $k => $en) {
        if (page_lang() === 'en') { $out[$k] = $en; continue; }
        $es = isset($nouns[$en]) && str_starts_with($k, 'noun') ? $nouns[$en] : (translations()[$en] ?? null);
        if ($es === null) $allTranslated = false;
        $out[$k] = $es ?? $en;
    }
    // Only a set that is wholly translated is said in the page language; otherwise English, marked so.
    if (!$allTranslated) { foreach ($keys as $k => $en) $out[$k] = $en; }
    $out['lang'] = $allTranslated ? page_lang() : 'en';
    return $out;
}
/** Add Spanish labels defined at runtime (e.g. referral options an administrator
 * edits). Keys and values are plain text; t() escapes them like any other copy. */
function register_translations(array $map): void {
    foreach ($map as $en => $es) {
        if (is_string($en) && is_string($es) && $en !== '' && $es !== '') $GLOBALS['TRANSLATIONS_EXTRA'][$en] = $es;
    }
    $GLOBALS['TRANSLATIONS_VERSION'] = ($GLOBALS['TRANSLATIONS_VERSION'] ?? 0) + 1;
}
/** Plain text translation for attributes and exact UI labels only. */
function __(string $s): string {
    return page_lang() === 'es' ? (translations()[$s] ?? $s) : $s;
}
/** Text that stays English on a translated page (formatted dates, fixed record
 * values): escaped, and marked lang="en" whenever the page itself is not English
 * so a screen reader switches voice for it (WCAG 3.1.2). */
function en(string $text): string {
    return page_lang() === 'en' ? h($text) : '<span lang="en">' . h($text) . '</span>';
}
/** Render trusted UI copy only. Never pass record data or form values here.
 * Unlike a response filter, this cannot alter values, scripts, URLs or downloads.
 * Untranslated English fragments are explicitly marked for assistive technology.
 */
function t(string $copy): string {
    if (page_lang() !== 'es') return h($copy);
    $map = translations();
    static $pattern = null, $built = -1;
    if ($pattern === null || $built !== ($GLOBALS['TRANSLATIONS_VERSION'] ?? 0)) {
        $built = $GLOBALS['TRANSLATIONS_VERSION'] ?? 0;
        // Only longer phrases join the fragment pattern, and only at word
        // boundaries. A key that is a single word ('with', 'free', 'Other') is
        // translated only when it is the whole copy: as a fragment it would
        // chop unrelated English copy (an administrator's own label) into
        // mixed-language pieces. Exact lookups still use __().
        $keys = array_filter(array_keys($map), fn($k) => mb_strlen($k) >= 4 && preg_match('/[^\p{L}]/u', $k));
        usort($keys, fn($a, $b) => strlen($b) <=> strlen($a));
        $pattern = '~((?<![A-Za-z])(?:' . implode('|', array_map(fn($k) => preg_quote($k, '~'), $keys)) . ')(?![A-Za-z]))~u';
    }
    $parts = preg_split($pattern, $copy, -1, PREG_SPLIT_DELIM_CAPTURE);
    $out = '';
    foreach ($parts as $part) {
        if (isset($map[$part])) $out .= '<span lang="es">' . h($map[$part]) . '</span>';
        elseif (preg_match('/[a-z]/i', $part)) $out .= '<span lang="en">' . h($part) . '</span>';
        else $out .= h($part);
    }
    return $out;
}
