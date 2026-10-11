<?php
declare(strict_types=1);

function color_luminance(string $hex): float {
    $channels = array_map(static function ($value): float {
        $s = hexdec($value) / 255;
        return $s <= 0.04045 ? $s / 12.92 : (($s + 0.055) / 1.055) ** 2.4;
    }, str_split(substr($hex, 1), 2));
    return .2126 * $channels[0] + .7152 * $channels[1] + .0722 * $channels[2];
}

function color_contrast(string $a, string $b): float {
    $a = color_luminance($a); $b = color_luminance($b);
    return (max($a, $b) + .05) / (min($a, $b) + .05);
}

/** Keep a configured brand/text color when it is legible, otherwise fall back.
 * Hex validation also prevents arbitrary CSS in the inline theme. A configured
 * colour colours every link, primary button and the header behind white text, so
 * it must meet the AAA 7:1 enhanced-contrast ratio the default theme meets
 * (1.4.6); a colour that does not is replaced by the accessible default and the
 * override is logged for the administrator (at most once a day per message, not
 * on every page render). 3- and 6-digit hex are accepted.
 * $min lowers the ratio only for a token styles.css never uses as text.
 */
function brand_color_log(string $message): void {
    static $seen = [];
    if (isset($seen[$message])) return;
    $seen[$message] = true;
    // A marker file per message keeps one override from logging on every render.
    $marker = rtrim(sys_get_temp_dir(), '/') . '/cms_brand_color_' . md5(__DIR__ . $message);
    $last = @filemtime($marker);
    if ($last !== false && $last > time() - 86400) return;
    @touch($marker);
    error_log($message);
}

function accessible_brand_color(string $candidate, string $fallback, bool $header = false, float $min = 7.0): string {
    $raw = trim($candidate);
    // Normalise #abc -> #aabbcc.
    if (preg_match('/^#[0-9a-f]{3}$/iD', $raw)) {
        $raw = '#' . $raw[1] . $raw[1] . $raw[2] . $raw[2] . $raw[3] . $raw[3];
    }
    if (!preg_match('/^#[0-9a-f]{6}$/iD', $raw)) {
        if ($candidate !== '') brand_color_log('accessible_brand_color: rejected non-hex brand color ' . $candidate);
        return $fallback;
    }
    $candidate = strtolower($raw);
    // Needs 7:1 (AAA) against the light surfaces it is used as text/fill on.
    foreach (['#ffffff', '#eef1f6', '#ecfdf5', '#fef2f2', '#f4f6fb'] as $surface) {
        if (color_contrast($candidate, $surface) < $min) { brand_color_log('accessible_brand_color: brand color ' . $candidate . ' fails ' . $min . ':1 on ' . $surface . ', using fallback'); return $fallback; }
    }
    if (!$header) return $candidate;
    // Header display buttons use a 12% white overlay over the brand color.
    $overlay = '#';
    foreach (str_split(substr($candidate, 1), 2) as $channel) {
        $overlay .= sprintf('%02x', (int)round(hexdec($channel) * .88 + 255 * .12));
    }
    if (color_contrast('#ffffff', $overlay) >= 7) return $candidate;
    brand_color_log('accessible_brand_color: header overlay for ' . $candidate . ' fails 7:1, using fallback');
    return $fallback;
}
