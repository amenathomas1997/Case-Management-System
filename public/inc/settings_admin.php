<?php
declare(strict_types=1);
// Settings administrators change on screen (3.0.54) instead of editing config.php on the
// server. Each one is stored in the settings table as cfg.<section>.<key> and laid over
// $CONFIG when the app starts; an empty value means "use config.php". The Graph client
// secret's name contains "secret", so the nightly backup leaves it out (as the other stored
// secrets): after a restore it is entered again here.

/** The editable settings: 'section.key' => [label, kind, group]. */
const CONFIG_OVERRIDES = [
    'graph.tenant_id' => ['Directory (tenant) ID', 'text', 'graph'],
    'graph.client_id' => ['Application (client) ID', 'text', 'graph'],
    'graph.client_secret' => ['Client secret', 'secret', 'graph'],
    'graph.sender' => ['Sending mailbox', 'email', 'graph'],
    'app.mail_from' => ['From address', 'email', 'addresses'],
    'app.mail_from_name' => ['From name', 'text', 'addresses'],
    'app.support_email' => ['Help address shown on every page', 'email', 'addresses'],
    'app.backup_email' => ['Backup and alert address', 'email', 'addresses'],
    'app.session_timeout_minutes' => ['Sign out after this many idle minutes (5 to 480)', 'minutes', 'signin'],
    'app.otp_expiry_minutes' => ['Sign-in codes last this many minutes (3 to 30)', 'otp', 'signin'],
    'app.timezone' => ['Time zone (times already recorded are then shown in the new zone)', 'timezone', 'signin'],
    'brand.name' => ['System name', 'text', 'brand'],
    'brand.short' => ['Short name (page header)', 'text', 'brand'],
    'brand.org' => ['Organisation name (letterhead)', 'text', 'brand'],
    'brand.tagline' => ['Tagline', 'text', 'brand'],
    'brand.color' => ['Main colour (#RRGGBB)', 'color', 'brand'],
    'brand.color_dark' => ['Dark colour for headers and PDFs (#RRGGBB)', 'color', 'brand'],
    // The privacy notice (4.3.0): who runs the service, where, the law it follows, its host.
    'brand.privacy_operator' => ['Who runs the service (a person or organisation)', 'text', 'legal'],
    'brand.privacy_address' => ['Mailing address', 'text', 'legal'],
    'brand.privacy_law' => ['The privacy laws you follow (for example, your state\'s law and HIPAA)', 'text', 'legal'],
    'brand.privacy_law_es' => ['The same laws, in Spanish (for the Spanish notice)', 'text', 'legal'],
    'brand.privacy_hosting' => ['Who hosts the server', 'text', 'legal'],
    'brand.hipaa' => ['Show the HIPAA rights section (the laws above are shown either way)', 'yesno', 'legal'],
    'app.update_url' => ['Where to check for new versions (an https address, or off)', 'url', 'updates'],
];

/** The time zones offered (the United States and its territories). */
const CONFIG_TIMEZONES = ['America/New_York', 'America/Chicago', 'America/Denver', 'America/Phoenix', 'America/Los_Angeles', 'America/Anchorage', 'Pacific/Honolulu', 'America/Puerto_Rico', 'Pacific/Guam'];

/** Lay the stored settings over $CONFIG (once, at start-up). A database that cannot be read changes nothing. */
function config_overrides_apply(): void {
    global $CONFIG;
    // A way back from a bad saved value: 'ignore_db_settings' => true in config.php's app
    // block makes config.php win again until the value is corrected on Settings.
    $GLOBALS['CONFIG_FILE'] = $CONFIG; // config.php's own values, before anything stored is laid over them
    if (!empty($CONFIG['app']['ignore_db_settings'])) return;
    try {
        $rows = db()->query("SELECT name, value FROM settings WHERE name LIKE 'cfg.%'")->fetchAll();
    } catch (Throwable $e) {
        return;
    }
    $tzBefore = date_default_timezone_get();
    foreach ($rows as $r) {
        $k = substr((string)$r['name'], 4);
        if (!isset(CONFIG_OVERRIDES[$k]) || trim((string)$r['value']) === '') continue;
        [$section, $key] = explode('.', $k, 2);
        $v = (string)$r['value'];
        $CONFIG[$section][$key] = in_array(CONFIG_OVERRIDES[$k][1], ['minutes', 'otp'], true) ? (int)$v : $v;
    }
    $tz = (string)($CONFIG['app']['timezone'] ?? '');
    if ($tz !== '' && $tz !== $tzBefore && in_array($tz, timezone_identifiers_list(), true)) {
        date_default_timezone_set($tz);
        // The SQL session was opened with the old zone: its NOW() must agree with PHP's date().
        try { db()->exec('SET time_zone = ' . db()->quote($tz)); } catch (Throwable $e) { db()->exec('SET time_zone = ' . db()->quote(date('P'))); }
    }
}

/** Check and save one setting from the form. Returns an error, or null. An empty value goes back to config.php's. */
function config_override_save(string $k, string $value, int $actor): ?string {
    if (!isset(CONFIG_OVERRIDES[$k])) return 'Unknown setting.';
    [$label, $kind] = CONFIG_OVERRIDES[$k];
    $value = trim(str_replace(["\r", "\n"], ' ', $value));
    if ($value !== '') {
        $bad = match ($kind) {
            'email' => !filter_var($value, FILTER_VALIDATE_EMAIL),
            'minutes' => !ctype_digit($value) || (int)$value < 5 || (int)$value > 480,
            'otp' => !ctype_digit($value) || (int)$value < 3 || (int)$value > 30,
            'timezone' => !in_array($value, CONFIG_TIMEZONES, true),
            'color' => !preg_match('/^#[0-9a-fA-F]{6}$/', $value),
            'yesno' => !in_array($value, ['yes', 'no'], true),
            'url' => $value !== 'off' && (!filter_var($value, FILTER_VALIDATE_URL) || !str_starts_with(strtolower($value), 'https://') || strlen($value) > 190),
            default => mb_strlen($value) > 190,
        };
        if ($bad) return $label . ': ' . match ($kind) {
            'email' => 'enter one email address.', 'minutes' => 'enter a whole number from 5 to 480.', 'otp' => 'enter a whole number from 3 to 30.',
            'timezone' => 'choose a time zone from the list.', 'color' => 'enter a colour as # and six hex digits, such as #1e3a8a.',
            'yesno' => 'choose yes or no.', 'url' => 'enter an address starting with https://, or the word off.', default => 'keep it to 190 characters.',
        };
        if ($kind === 'color' && brand_contrast_ratio($value) < 4.5) return $label . ': that colour is too light to read on white (it needs a contrast of at least 4.5 to 1).';
    }
    if ((string)(setting('cfg.' . $k) ?? '') === $value) return null;
    set_setting('cfg.' . $k, $value);
    audit($actor, 'settings.config', 'settings', null, $k . ($kind === 'secret' ? ($value === '' ? ' cleared' : ' changed') : ' = ' . ($value === '' ? '(config.php)' : mb_substr($value, 0, 100))));
    return null;
}

/** Whether Microsoft accepts these Graph credentials (a token is requested; nothing is sent). */
function graph_credentials_work(string $tenant, string $client, string $secret): bool {
    if (!preg_match('/^[A-Za-z0-9.-]+$/', $tenant)) return false;
    $ch = curl_init('https://login.microsoftonline.com/' . $tenant . '/oauth2/v2.0/token');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 20,
        CURLOPT_POSTFIELDS => http_build_query(['client_id' => $client, 'client_secret' => $secret, 'scope' => 'https://graph.microsoft.com/.default', 'grant_type' => 'client_credentials'])]);
    $resp = curl_exec($ch);
    curl_close($ch);
    $data = is_string($resp) ? json_decode($resp, true) : null;
    return is_array($data) && !empty($data['access_token']);
}

/** The contrast of a #RRGGBB colour against white (WCAG relative luminance). */
function brand_contrast_ratio(string $hex): float {
    $c = array_map(fn($x) => hexdec($x) / 255, str_split(ltrim($hex, '#'), 2));
    $l = array_map(fn($v) => $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4, $c);
    $lum = 0.2126 * $l[0] + 0.7152 * $l[1] + 0.0722 * $l[2];
    return 1.05 / ($lum + 0.05);
}

/** A brand colour as [r, g, b] for the PDF generators (the dark colour by default). */
function brand_rgb(string $key = 'color_dark'): array {
    $hex = ltrim(brand($key), '#');
    if (!preg_match('/^[0-9a-fA-F]{6}$/', $hex)) return [30, 58, 138];
    return array_map('hexdec', str_split($hex, 2));
}

/* ---------- Safe upgrades (3.0.54) ---------- */

/** The database version this code needs: the newest migration it ships. */
const DB_SCHEMA_VERSION = '4.6.0';

/** The database version recorded by the migrations, or null for a database from before 3.0.54. */
function db_schema_version(): ?string {
    $v = setting('schema_version');
    return $v !== null && preg_match('/^\d+\.\d+\.\d+$/', $v) ? $v : null;
}

/** The migration files shipped with the code (inc/migrations), oldest first: [version => path]. */
function shipped_migrations(): array {
    $out = [];
    foreach (glob(__DIR__ . '/migrations/mig_*.sql') ?: [] as $f) if (preg_match('/mig_(\d+\.\d+\.\d+)\.sql$/', $f, $m)) $out[$m[1]] = $f;
    uksort($out, 'version_compare');
    return $out;
}

/** Migrations newer than the recorded database version. With none recorded, every one (they are all safe to run again). */
function pending_migrations(): array {
    $have = db_schema_version();
    return array_filter(shipped_migrations(), fn($v) => $have === null || version_compare((string)$v, $have, '>'), ARRAY_FILTER_USE_KEY);
}

/** Run one migration file, statement by statement. Returns [statements run, error or null]. */
function run_migration_file(string $path): array {
    $stmt = ''; $n = 0;
    foreach (preg_split('/\R/', (string)file_get_contents($path)) as $line) {
        if (trim($line) === '' || str_starts_with(ltrim($line), '--')) continue;
        $stmt .= $line . "\n";
        if (preg_match('/;\s*$/', $line)) {
            try { db()->exec($stmt); } catch (Throwable $e) { return [$n, 'statement ' . ($n + 1) . ' failed: ' . $e->getMessage()]; }
            $stmt = ''; $n++;
        }
    }
    return [$n, null];
}

/** Check the vendor's update list. Returns ['ok' => bool, 'latest' => ?string]. Used by Administration and the daily alerts. */
function update_check(): array {
    // Off unless set (Settings, Updates, or config.php; the installer fills in the publisher's list).
    $url = ($GLOBALS['CONFIG']['app']['update_url'] ?? null) ?: 'off';
    if ($url === 'off') return ['ok' => false, 'latest' => null]; // a test or offline copy
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15]);
    $resp = curl_exec($ch);
    curl_close($ch);
    $m = is_string($resp) ? json_decode($resp, true) : null;
    if (!is_array($m) || !preg_match('/^\d+(\.\d+){1,3}$/', (string)($m['latest'] ?? ''))) return ['ok' => false, 'latest' => null];
    set_setting('update_latest', (string)$m['latest']);
    set_setting('update_notes', mb_substr((string)($m['notes'] ?? ''), 0, 500));
    set_setting('update_checked', date('Y-m-d H:i'));
    return ['ok' => true, 'latest' => (string)$m['latest']];
}

/* ---------- The app icon (4.3.0) ---------- */

/** Where an uploaded icon is kept, at a size (180, 192, 512 or 1024), or null with no upload folder. */
function brand_icon_path(int $size): ?string {
    $dir = (string)(cfg('upload_dir') ?? '');
    return $dir === '' ? null : rtrim($dir, '/') . '/brand/icon-' . $size . '.png';
}

/** Whether an administrator's own icon is in use. */
function brand_icon_uploaded(): bool {
    clearstatcache();   // the icon may have been added or removed by another request
    $p = brand_icon_path(512);
    return $p !== null && is_file($p);
}

/**
 * Make the app icons from an uploaded square picture (PNG or JPEG, at least 512 pixels).
 * Returns null when saved, else what is wrong, in words.
 */
function brand_icon_save(string $tmpFile): ?string {
    if (!function_exists('imagecreatefromstring')) return "This server's PHP has no image support (GD), so an icon cannot be made here.";
    if (@filesize($tmpFile) > 8 * 1024 * 1024) return 'That picture is larger than 8 MB.';
    $bytes = @file_get_contents($tmpFile);
    $info = $bytes !== false ? @getimagesizefromstring($bytes) : false;
    if (!$info || !in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG], true)) return 'Choose a PNG or JPEG picture.';
    [$w, $h] = $info;
    if ($w < 512 || $h < 512) return 'The picture must be at least 512 by 512 pixels.';
    if ($w > 4096 || $h > 4096) return 'The picture must be at most 4096 by 4096 pixels.';
    if (abs($w - $h) > max($w, $h) * 0.05) return 'The picture must be square (as wide as it is tall).';
    $src = @imagecreatefromstring($bytes);
    if (!$src) return 'That picture could not be read.';
    $dir = dirname((string)brand_icon_path(512));
    if (!is_dir($dir) && !@mkdir($dir, 0750, true)) return 'The icon folder could not be created in the upload folder.';
    foreach ([180, 192, 512, 1024] as $s) {
        $im = imagecreatetruecolor($s, $s);
        imagealphablending($im, false); imagesavealpha($im, true);
        imagecopyresampled($im, $src, 0, 0, 0, 0, $s, $s, $w, $h);
        if (!@imagepng($im, (string)brand_icon_path($s))) return 'The icon could not be saved in the upload folder.';
    }
    return null;
}

/** Go back to the built-in icons. */
function brand_icon_remove(): void {
    foreach ([180, 192, 512, 1024] as $s) { $p = brand_icon_path($s); if ($p !== null && is_file($p)) @unlink($p); }
}
