<?php
declare(strict_types=1);

function app_lang(): string { return $_SESSION['lang'] ?? 'en'; }
function page_lang(): string { return !empty($GLOBALS['PUBLIC_TRANSLATION']) ? app_lang() : 'en'; }
function enable_public_translation(): void { $GLOBALS['PUBLIC_TRANSLATION'] = true; }
function translations(): array {
    static $map = null;
    $map ??= require __DIR__ . '/lang_es.php';
    return ($GLOBALS['TRANSLATIONS_EXTRA'] ?? []) + $map;
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
