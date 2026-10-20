<?php
declare(strict_types=1);
require __DIR__ . '/../public/inc/accessibility.php';
function check(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
    echo "PASS: $message\n";
}
// Contrast is measured on the colours styles.css actually ships — its theme
// tokens and the component rules that override them — so a stylesheet change
// that drops a pair below the bar fails here. Text needs 7:1 (WCAG AAA enhanced
// contrast); the focus ring, a non-text indicator, needs 3:1.
$css = preg_replace('~/\*.*?\*/~s', '', (string)file_get_contents(__DIR__ . '/../public/assets/styles.css'));
$rules = [];
preg_match_all('/(?:^|(?<=\}))\s*([^{}@]+?)\s*\{([^{}]*)\}/', $css, $found, PREG_SET_ORDER);
foreach ($found as [, $selectors, $body]) {
    foreach (array_map('trim', explode(',', $selectors)) as $selector) $rules[$selector] = ($rules[$selector] ?? '') . ';' . $body;
}
$hex = static function (string $value): string {
    $value = strtolower($value);
    return strlen($value) === 4 ? '#' . $value[1] . $value[1] . $value[2] . $value[2] . $value[3] . $value[3] : $value;
};
$tokens = static function (string $selector) use ($rules, $hex): array {
    if (!isset($rules[$selector])) throw new RuntimeException("styles.css has no $selector rule");
    preg_match_all('/--([a-z0-9-]+)\s*:\s*(#[0-9a-fA-F]{6}\b|#[0-9a-fA-F]{3}\b)/', $rules[$selector], $vars, PREG_SET_ORDER);
    $out = [];
    foreach ($vars as $v) $out[$v[1]] ??= $hex($v[2]);
    return $out;
};
$color = static function (string $selector, string $property, array $theme) use ($rules, $hex): string {
    if (!isset($rules[$selector])) throw new RuntimeException("styles.css has no $selector rule");
    if (!preg_match('/(?<![\w-])' . preg_quote($property, '/') . '\s*:\s*(#[0-9a-fA-F]{6}\b|#[0-9a-fA-F]{3}\b|var\(--([a-z0-9-]+)\))/', $rules[$selector], $m)) {
        throw new RuntimeException("styles.css sets no $property colour for $selector");
    }
    return !empty($m[2]) ? $theme[$m[2]] : $hex($m[1]);
};
$light = $tokens(':root');
$dark = array_replace($light, $tokens(':root[data-theme="dark"]'));
$d = ':root[data-theme="dark"] ';
$pairs = [];
foreach (['light' => $light, 'dark' => $dark] as $name => $theme) {
    // The dark theme keeps --brand-dark navy for the header background; it is not a text colour there.
    foreach ($name === 'light' ? ['ink', 'ink-soft', 'brand', 'brand-dark', 'accent', 'danger'] : ['ink', 'ink-soft', 'brand', 'accent', 'danger'] as $fg) {
        foreach (['bg', 'card', 'surface-2'] as $bg) $pairs[] = ["$name --$fg on --$bg", $theme[$fg], $theme[$bg], 7];
    }
    $pairs[] = ["$name success message", $theme['ok-ink'], $theme['ok-bg'], 7];
    $pairs[] = ["$name error message", $theme['err-ink'], $theme['err-bg'], 7];
}
foreach (['.badge-pending', '.badge-active', '.badge-hold', '.badge-closed', '.btn-primary', '.btn-secondary', '.btn-danger', 'th', 'textarea'] as $selector) {
    $pairs[] = ["light $selector", $color($selector, 'color', $light), $color($selector, 'background', $light), 7];
    if ($selector !== '.btn-danger' && $selector !== 'th' && $selector !== 'textarea') {
        $pairs[] = ["dark $selector", $color($d . $selector, 'color', $dark), $color($d . $selector, 'background', $dark), 7];
    }
}
$pairs[] = ['light placeholder', $color('textarea::placeholder', 'color', $light), $color('textarea', 'background', $light), 7];
$pairs[] = ['light field error', $color('.field-error', 'color', $light), $light['card'], 7];
$pairs[] = ['dark table header', $dark['ink'], $color($d . 'th', 'background', $dark), 7];
$pairs[] = ['dark form field', $color($d . 'textarea', 'color', $dark), $color($d . 'textarea', 'background', $dark), 7];
$pairs[] = ['dark placeholder', $color($d . 'textarea::placeholder', 'color', $dark), $color($d . 'textarea', 'background', $dark), 7];
$pairs[] = ['dark field error', $color($d . '.field-error', 'color', $dark), $dark['card'], 7];
$pairs[] = ['dark link hover', $color($d . 'a:hover', 'color', $dark), $dark['card'], 7];
$pairs[] = ['dark focus ring', $color($d . ':focus-visible', 'outline-color', $dark), $dark['bg'], 3];
foreach ($pairs as [$label, $ink, $bg, $min]) {
    $ratio = color_contrast($ink, $bg);
    check($ratio >= $min, "Contrast $label: $ink on $bg (" . round($ratio, 2) . ":1, needs $min:1)");
}
check(accessible_brand_color('#ffffff', '#1e40af') === '#1e40af', 'Low-contrast branding falls back');
check(accessible_brand_color('red;display:none', '#1e40af') === '#1e40af', 'Invalid CSS branding falls back');
check(accessible_brand_color('#1e3a8a', '#000000', true) === '#1e3a8a', 'Default header meets contrast including display-button overlay');
check(accessible_brand_color('#1e40af', '#000000') === '#1e40af', 'Accessible brand colors remain unchanged');
ob_start(); require __DIR__ . '/accessibility_fixture.php'; $html = ob_get_clean();
$dom = new DOMDocument(); @$dom->loadHTML($html); $xp = new DOMXPath($dom);
check($xp->query('//nav[contains(@class,"cms-page-navigation")]//a[@data-cms-back]')->length === 1, 'Shared pages provide an explicit Go back link');
check($xp->query('//nav[contains(@class,"cms-page-navigation")]//a[@href="index.php"]')->length >= 2, 'Shared pages provide a Dashboard destination and safe back fallback');
check($xp->query('//footer//a[@data-cms-menu]')->length === 1, 'Main navigation is reachable at the bottom of shared pages');
check($xp->query('//ul[@id="main-nav" and @tabindex="-1"]')->length === 1, 'Main navigation accepts focus from the navigation shortcut');
check($xp->query('//details[contains(@class,"vision-panel") and not(@open)]/summary')->length === 1, 'Low-vision controls start collapsed with a native keyboard-operable disclosure');
// Native HTML landmarks retain their specific roles; named sections supplement them.
check($xp->query('//header[@aria-label="Site header"]')->length === 1, 'One named site-header banner landmark');
check($xp->query('//nav[@aria-label="Main"]')->length === 1, 'One named Main navigation landmark');
check($xp->query('//main[@aria-label="Main content"]')->length === 1, 'One named main-content landmark');
check($xp->query('//footer[@aria-label="Site information"]')->length === 1, 'One named site-information contentinfo landmark');
check($xp->query('//form[@role="search" and @aria-label="Site search"]')->length === 1, 'Site search has a named search landmark');
check($xp->query('//section[@id="low-vision-bar" and @aria-labelledby="vision-title"]')->length === 1, 'Display controls have a heading-named region');
// Before the page's h1 only two h2s appear (Low-vision controls, then Main menu); the main-menu groups are
// h3 headings under the menu's h2, each still a heading with its id, so H moves between groups.
check($xp->query('//h2[@id="vision-title"]')->length === 1, 'Low-vision controls is the one h2 before the main content');
$h1 = $xp->query('//h1')->item(0);
// (The session-timeout alertdialog's h2 is hidden until it opens, so it is not in the headings list.)
$beforeH1 = $xp->query('//h2[following::h1 and not(ancestor::*[@role="alertdialog"])]');
// 4.30.0: the main menu has its own visually hidden h2, so its h3 groups are not filed under Low-vision controls.
$ids = []; foreach ($beforeH1 as $hh) $ids[] = $hh->getAttribute('id');
check($h1 !== null && $ids === ['vision-title', 'main-menu-h'], 'Only the Low-vision controls and Main menu h2s precede the page title');
$groupHeadings = $xp->query('//ul[@id="main-nav"]//li[contains(@class,"nav-group")]/h3[contains(@class,"nav-group-title") and starts-with(@id,"nav-group-")]');
check($groupHeadings->length >= 1 && $groupHeadings->length === $xp->query('//ul[@id="main-nav"]//li[contains(@class,"nav-group")]')->length, 'Each main-menu group is headed by an h3 with its id');
foreach ($groupHeadings as $gh) {
    check($xp->query('//ul[@id="main-nav"]//ul[@aria-labelledby="' . $gh->getAttribute('id') . '"]')->length === 1, 'Menu group list is named by its h3: ' . $gh->getAttribute('id'));
}
$navNames = [];
foreach ($xp->query('//nav') as $nav) {
    $name = trim($nav->getAttribute('aria-label'));
    check($name !== '' && !in_array($name, $navNames, true), 'Navigation landmark has a distinct name: ' . $name);
    $navNames[] = $name;
}
check($xp->query('//main[@id="main" and @tabindex="-1"]')->length === 1, 'Skip-link destination accepts keyboard focus');
$seen = [];
foreach ($xp->query('//*[@accesskey]') as $el) { $k = strtolower($el->getAttribute('accesskey')); check(!isset($seen[$k]), 'Access key used once on the page: ' . $k); $seen[$k] = true; }
check(isset($seen['1'], $seen['0'], $seen['m'], $seen['s'], $seen['9']), 'Quick keys reach Dashboard, main menu, main content, search and notifications');
check(!isset($seen['n'], $seen['t']) && !array_intersect_key(cms_access_keys(), ['n' => 1, 't' => 1]), 'N and T are left free for each page\'s own add and log forms');
check($xp->query('//button[contains(@class,"vfab-stop")]')->length === 1, 'Speech output has an explicit stop control');
check($xp->query('//button[contains(@class,"vfab-listen")]')->length === 1, 'Microphone has an explicit activation control');
check($xp->query('//footer//a[@href="help.php" and @lang="en"]')->length === 1, 'Shared help is consistently available and language-labelled');
foreach ($xp->query('//input[not(@type="hidden")] | //select | //textarea') as $field) {
    $id = $field->getAttribute('id');
    $label = $id !== '' && $xp->query('//label[@for="' . $id . '"]')->length > 0;
    $label = $label || $field->parentNode->nodeName === 'label' || $field->hasAttribute('aria-label');
    check($label, 'Fixture input has a programmatic label: ' . ($id ?: $field->getAttribute('type')));
}

check($xp->query('//footer//a[@href="mailto:help@example.test"]')->length === 1, 'Footer uses the public contact email');
check(!str_contains($html, 'private-backups@example.test') && !str_contains($html, 'mailto:notifications@example.test'), 'Configured contact replaces the sender without exposing the backup address');
