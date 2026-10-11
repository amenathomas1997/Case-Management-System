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
check($xp->query('//main[@id="main" and @tabindex="-1"]')->length === 1, 'Skip-link destination accepts keyboard focus');
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
