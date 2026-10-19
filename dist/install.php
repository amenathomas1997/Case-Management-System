<?php
/**
 * White-label CMS installer.
 *
 * Requirements: PHP 8.1+ and MariaDB 10.4 or newer (MySQL is not supported —
 * the schema and the upgrade scripts use MariaDB syntax).
 *
 * Usage: upload the app files + this installer + schema_full.sql to the web
 * docroot, create an empty install.php.enabled file beside it, then browse
 * to install.php and fill in the form. It checks the database server, imports
 * the schema, creates the admin account, writes the private config OUTSIDE the
 * docroot, generates brand icons, then locks itself.
 *
 * The app finds its config through inc/config_path.php, which the installer
 * writes and which no bundle ships, so uploading a newer bundle's docroot/ over
 * the site later keeps it. (An inc/core.php without that lookup, from an older
 * bundle, is patched instead, as before.)
 *
 * Nothing outside the database is written until the schema import and the
 * admin account have succeeded, so a failed run leaves the app untouched.
 *
 * Delete install.php and schema_full.sql after a successful install.
 */
declare(strict_types=1);
error_reporting(E_ALL);

$enableFile = __FILE__ . '.enabled';
if (!is_file($enableFile)) {
    http_response_code(403);
    exit('Installer disabled. For a fresh installation, create install.php.enabled beside this file using your hosting file manager.');
}
$lockFile = __DIR__ . '/install.lock';
if (is_file($lockFile)) {
    exit('Installer is locked (already ran). Delete install.lock to re-run — only do this on a fresh install.');
}

function field(string $k, string $d = ''): string {
    return trim($_POST[$k] ?? $d);
}

/** Folder names that web servers conventionally publish. */
const WEB_DIR_NAMES = ['public_html', 'www', 'htdocs'];

/** Dropped into the private directory so a misplaced one is still not served. */
const PRIVATE_HTACCESS = "# Written by the CMS installer: nothing in this directory is ever served over HTTP.\n"
    . "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n"
    . "<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n";

/** Absolute path with the part that already exists resolved through symlinks. */
/** 4.28.0: an error about one field, named by the label the person hears; listed in the summary as a link to the field. */
function field_fail(string $field, string $msg): void {
    $GLOBALS['errors'][] = $msg;
    $GLOBALS['errorFor'][$field] ??= $msg;
}
/** aria-invalid and the inline error's id for a field with an error ('' when it has none). */
function field_err_attr(string $field): string {
    return isset($GLOBALS['errorFor'][$field]) ? ' aria-invalid="true" aria-describedby="err-' . $field . '"' : '';
}
/** The inline error under a field ('' when it has none). */
function field_err_msg(string $field): string {
    $m = $GLOBALS['errorFor'][$field] ?? null;
    return $m === null ? '' : '<p class="field-error" id="err-' . $field . '">' . htmlspecialchars($m) . '</p>';
}

function resolve_path(string $path): string {
    $cur = rtrim(str_replace('\\', '/', $path), '/');
    $tail = '';
    while ($cur !== '' && $cur !== '.' && !@file_exists($cur)) {
        $tail = '/' . basename($cur) . $tail;
        $cur = rtrim(str_replace('\\', '/', dirname($cur)), '/');
    }
    $real = $cur === '' ? '' : @realpath($cur);
    return rtrim(str_replace('\\', '/', is_string($real) ? $real : $cur), '/') . $tail;
}

/** True when $path is $root itself or lies inside it (both already resolved). */
function path_inside(string $path, string $root): bool {
    return $root !== '' && ($path === $root || str_starts_with($path . '/', $root . '/'));
}

/** The first part of a path named like a web-published folder, or null. */
function web_dir_component(string $path): ?string {
    foreach (explode('/', $path) as $part) {
        if (in_array(strtolower($part), WEB_DIR_NAMES, true)) return $part;
    }
    return null;
}

/** A php.ini size ("20M", "1G", "512K", "0") in bytes; 0 means no limit. */
function ini_bytes(string $v): int {
    $v = trim($v);
    $n = (int)$v;
    return match (strtolower(substr($v, -1))) { 'g' => $n * 1073741824, 'm' => $n * 1048576, 'k' => $n * 1024, default => $n };
}

/** Every directory this installer knows the web server publishes. */
function docroots(): array {
    $roots = [resolve_path(__DIR__)];
    $serverRoot = (string)($_SERVER['DOCUMENT_ROOT'] ?? '');
    if ($serverRoot !== '') $roots[] = resolve_path($serverRoot);
    return array_values(array_unique(array_filter($roots, fn(string $r): bool => $r !== '')));
}

/**
 * Suggested private directory: beside the outermost docroot and, whenever that
 * location is writable, above any public_html / www / htdocs folder (cPanel
 * puts subdomain docroots inside public_html, so "one level up" is not enough).
 */
function default_private_dir(): string {
    $doc = resolve_path(__DIR__);
    foreach (docroots() as $root) {
        if (path_inside($doc, $root) && strlen($root) < strlen($doc)) $doc = $root;
    }
    $parts = explode('/', $doc);
    foreach ($parts as $i => $name) {
        if (in_array(strtolower($name), WEB_DIR_NAMES, true)) {
            $above = implode('/', array_slice($parts, 0, $i));
            if ($above !== '' && @is_dir($above) && @is_writable($above)) return $above . '/cms_private';
            break;
        }
    }
    return rtrim(str_replace('\\', '/', dirname($doc)), '/') . '/cms_private';
}

$errors = [];
$errorFor = []; // field id => its first error (4.28.0)
$done = false;
$report = [];
$notices = [];        // things the administrator must act on, shown prominently on the success page
$leftovers = [];      // installer files that could not be removed
$lockOk = false;
$backupPassword = '';
$cronDir = '';
$cronConfig = '';
$backupConf = '';
$cronShift = '';      // how far the server's clock is from the app's time zone, for the cron hours

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // ---- Gather ----
    $privateDir = rtrim(field('private_dir', default_private_dir()), '/\\');
    $baseUrl = rtrim(field('base_url'), '/');
    $dbHost = field('db_host', 'localhost');
    $dbName = field('db_name');
    $dbUser = field('db_user');
    $dbPass = $_POST['db_pass'] ?? '';
    $brandName = field('brand_name');
    $brandShort = field('brand_short');
    $brandOrg = field('brand_org');
    $brandTagline = field('brand_tagline');
    $brandColor = field('brand_color', '#1e40af');
    $brandDark = field('brand_color_dark', '#1e3a8a');
    $brandAccent = field('brand_accent', '#0f766e');
    $mailFrom = field('mail_from');
    $supportEmail = strtolower(field('support_email'));
    $supportPhone = field('support_phone');
    // Privacy notice (privacy.php reads these from $CONFIG['brand']): all optional.
    $privacyOperator = field('privacy_operator');
    $privacyAddress = field('privacy_address');
    $privacyLaw = field('privacy_law');
    $privacyHosting = field('privacy_hosting');
    $hipaa = field('hipaa') === 'yes' ? 'yes' : 'no';
    $adminName = field('admin_name');
    $adminEmail = strtolower(field('admin_email'));
    $adminPass = $_POST['admin_pass'] ?? '';
    $tz = field('timezone', 'America/Chicago');

    // ---- Validate ----
    // PHP extensions the app calls without a fallback (PDF pages use iconv, the
    // exports zip, mail and sign-in use curl and openssl), checked before
    // anything is changed so a missing one is not found only once the site runs.
    $missingExt = array_values(array_filter(['pdo_mysql', 'mbstring', 'iconv', 'curl', 'zip', 'openssl'], fn(string $x): bool => !extension_loaded($x)));
    if ($missingExt) $errors[] = 'This server\'s PHP is missing the ' . implode(', ', $missingExt) . ' extension' . (count($missingExt) > 1 ? 's' : '') . ', which the app needs. Turn ' . (count($missingExt) > 1 ? 'them' : 'it') . ' on in your hosting panel\'s PHP settings (on cPanel: Select PHP Version, or EasyApache) and run the installer again. Nothing was changed.';
    if (!preg_match('#^https://#', $baseUrl)) field_fail('base_url', 'Base URL must start with https://');
    // Named by the labels on the form (what a screen reader reads), never the internal field keys.
    foreach (['brand_name' => [$brandName, 'Business / app name'], 'brand_short' => [$brandShort, 'Short brand'], 'brand_org' => [$brandOrg, 'Full organization name'],
              'db_name' => [$dbName, 'Database name'], 'db_user' => [$dbUser, 'Database user'], 'admin_name' => [$adminName, "Administrator's name"]] as $k => [$v, $label]) {
        if ($v === '') field_fail($k, "{$label} is required.");
    }
    // The users table holds a name of up to 120 characters and an email of up to
    // 190, but sign-in refuses an address over 183 characters (login.php), so the
    // installer stops at 183; checked here, before the database is loaded.
    $adminNameLength = function_exists('mb_strlen') ? mb_strlen($adminName, 'UTF-8') : strlen($adminName);
    if ($adminNameLength > 120) field_fail('admin_name', "Administrator's name is {$adminNameLength} characters long; the most it can be is 120.");
    if (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) field_fail('admin_email', "Administrator's email is not a valid email address.");
    elseif (strlen($adminEmail) > 183) field_fail('admin_email', "Administrator's email is " . strlen($adminEmail) . ' characters long; the most it can be is 183.');
    if (!filter_var($mailFrom, FILTER_VALIDATE_EMAIL)) field_fail('mail_from', 'Mail-from address is not a valid email address.');
    if ($supportEmail !== '' && !filter_var($supportEmail, FILTER_VALIDATE_EMAIL)) field_fail('support_email', 'Public contact email is not a valid email address.');
    if (strlen($adminPass) < 10) field_fail('admin_pass', "Administrator's password must be at least 10 characters.");
    // The optional contact and privacy-notice fields: free text, only kept short and free of line breaks.
    foreach (['privacy_operator' => ['Operator', $privacyOperator, 190], 'privacy_address' => ["Operator's postal address", $privacyAddress, 255],
              'privacy_law' => ['Governing law', $privacyLaw, 120], 'privacy_hosting' => ['Hosting provider', $privacyHosting, 190]] as $k => [$label, $v, $max]) {
        $len = function_exists('mb_strlen') ? mb_strlen($v, 'UTF-8') : strlen($v);
        if ($len > $max) field_fail($k, "{$label} is {$len} characters long; the most it can be is {$max}.");
        if (preg_match('/[\r\n]/', $v)) field_fail($k, "{$label} must be on one line.");
    }
    // The same rule as admin.php's Support phone number field, which can change it later.
    if ($supportPhone !== '' && (mb_strlen($supportPhone) > 30 || !preg_match('/^[0-9+() \-]*$/', $supportPhone))) {
        field_fail('support_phone', 'Support phone: use only digits, spaces, plus, hyphens and parentheses (30 characters at most), like +1 555 010 2000, or leave it blank.');
    }
    foreach (['brand_color' => ['Primary color', $brandColor], 'brand_color_dark' => ['Dark color', $brandDark], 'brand_accent' => ['Accent color', $brandAccent]] as $k => [$label, $c]) {
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $c)) field_fail($k, "{$label} '{$c}' must be a 6-digit hex value like #1e40af.");
    }
    if (!in_array($tz, timezone_identifiers_list(), true)) field_fail('timezone', 'Time zone: ' . ($tz === '' ? 'enter one' : "'{$tz}' is not a time zone name") . ', like America/Chicago.');
    // The private directory holds the config, uploaded client documents and the
    // nightly database dumps, so it must sit outside everything the web server
    // publishes. Checked before the database is touched.
    if ($privateDir === '') {
        field_fail('private_dir', 'Private directory is required.');
    } elseif (!preg_match('#^(/|[A-Za-z]:[\\\\/])#', $privateDir) || preg_match('#(^|[\\\\/])\.\.?([\\\\/]|$)#', $privateDir)) {
        field_fail('private_dir', 'Private directory must be an absolute path with no "." or ".." parts.');
    } else {
        $privateReal = resolve_path($privateDir);
        $blocked = false;
        foreach (docroots() as $root) {
            if (path_inside($privateReal, $root)) {
                field_fail('private_dir', "Private directory {$privateDir} is inside the web docroot ({$root}). The config, uploads and database backups kept there could be downloaded — choose a directory outside the docroot.");
                $blocked = true;
                break;
            }
            if (path_inside($root, $privateReal)) {
                field_fail('private_dir', "Private directory {$privateDir} contains the web docroot ({$root}). The installer denies all web access to the private directory, which would take the site offline — choose a separate directory.");
                $blocked = true;
                break;
            }
        }
        $webDir = web_dir_component($privateReal);
        if (!$blocked && $webDir !== null && empty($_POST['private_dir_confirm'])) {
            field_fail('private_dir', "Private directory {$privateDir} is under a folder named \"{$webDir}\", which web servers normally publish. Choose a directory outside it, or tick the confirmation box under the Private directory field to continue anyway.");
        }
    }
    // Accessibility guard, the same rule as public/inc/accessibility.php
    // accessible_brand_color(): the brand colors are used for links, buttons and
    // headings on every page surface (white, the grey page background, the green
    // and red notice tints, the card tint), so Primary and Dark must reach WCAG AAA
    // text contrast (7:1) against each of them and Accent must reach 4.5:1. Dark is
    // also lightened a little for the header, which must stay 7:1 against white.
    $luminance = function (string $hex): float {
        [$r, $g, $b] = sscanf($hex, '#%02x%02x%02x');
        $lin = fn(int $c) => ($c /= 255) <= 0.04045 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        return 0.2126 * $lin($r) + 0.7152 * $lin($g) + 0.0722 * $lin($b);
    };
    $contrast = fn(string $a, string $b): float => (max($luminance($a), $luminance($b)) + .05) / (min($luminance($a), $luminance($b)) + .05);
    // The exact ratio is compared, as the app does: 6.998:1 shown rounded is "7:1",
    // but the app would refuse that colour on every page. The message rounds down.
    $shown = fn(float $ratio): string => number_format(floor($ratio * 100) / 100, 2);
    if (!$errors) {
        $surfaces = ['#ffffff', '#eef1f6', '#ecfdf5', '#fef2f2', '#f4f6fb'];
        foreach (['brand_color' => ['Primary', $brandColor, 7.0], 'brand_color_dark' => ['Dark', $brandDark, 7.0], 'brand_accent' => ['Accent', $brandAccent, 4.5]] as $k => [$label, $c, $min]) {
            foreach ($surfaces as $surface) {
                $ratio = $contrast($c, $surface);
                if ($ratio < $min) { field_fail($k, "{$label} color {$c} is too light ({$shown($ratio)}:1 against {$surface}; {$min}:1 is needed). Pick a darker shade."); break; }
            }
        }
        if (!$errors) {
            [$r, $g, $b] = sscanf($brandDark, '#%02x%02x%02x');
            $header = sprintf('#%02x%02x%02x', (int)round($r * .88 + 255 * .12), (int)round($g * .88 + 255 * .12), (int)round($b * .88 + 255 * .12));
            $ratio = $contrast($header, '#ffffff');
            if ($ratio < 7.0) field_fail('brand_color_dark', "Dark color {$brandDark} is too light for the page header ({$shown($ratio)}:1 against white once lightened; 7:1 is needed). Pick a darker shade.");
        }
    }

    // ---- Database: reachable, MariaDB 10.4+, and empty ----
    $pdo = null;
    if (!$errors) {
        try {
            $pdo = new PDO("mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4", $dbUser, $dbPass,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $serverVersion = (string)$pdo->query('SELECT VERSION()')->fetchColumn();
            // Some servers prefix the MariaDB version with a MySQL-compatible "5.5.5-".
            $versionNumber = preg_replace('/^5\.5\.5-/', '', $serverVersion);
            if (stripos($serverVersion, 'mariadb') === false
                || !preg_match('/^(\d+)\.(\d+)/', (string)$versionNumber, $vm)
                || version_compare($vm[1] . '.' . $vm[2], '10.4', '<')) {
                $errors[] = "This application requires MariaDB 10.4 or newer, but the database server reports \"{$serverVersion}\". MySQL and older MariaDB releases are not supported. Nothing was changed — point the installer at a MariaDB 10.4+ database and run it again.";
                $pdo = null;
            } elseif ((int)$pdo->query("SELECT GET_LOCK(CONCAT('cms_install_', MD5(DATABASE())), 0)")->fetchColumn() !== 1) {
                // One installation into this database at a time: a second press of
                // Install while the first is still importing the schema would
                // otherwise run beside it. The lock ends with the first request.
                $errors[] = 'An installation into this database is already running: the Install button was probably pressed twice. Nothing was changed by this request. '
                    . 'Wait a minute for the first one to finish, then go to your site\'s sign-in page. The off-site backup password that installation shows once is also the '
                    . "'backup_password' in config.php in the private directory you entered: keep it in your organisation's password manager.";
                $pdo = null;
            } else {
                // The database must be empty BEFORE anything is written anywhere —
                // a failed run must never leave the app pointed at a foreign config.
                $existing = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()")->fetchColumn();
                if ($existing > 0) {
                    $errors[] = "Database {$dbName} is not empty ({$existing} tables) — use a fresh database.";
                    $pdo = null;
                } else {
                    // The app pins strict mode on each of its own connections (core.php db()),
                    // so bad values are refused rather than silently stored. Say so when the
                    // server's default is not strict: anything else writing here is not.
                    $globalMode = strtoupper((string)$pdo->query('SELECT @@GLOBAL.sql_mode')->fetchColumn());
                    if (strpos($globalMode, 'STRICT_TRANS_TABLES') === false && strpos($globalMode, 'STRICT_ALL_TABLES') === false) {
                        $notices[] = "SQL mode: this database server's default sql_mode is not strict (neither STRICT_TRANS_TABLES nor STRICT_ALL_TABLES is set). "
                            . "The app turns strict mode on for each of its own database connections, so it refuses invalid values instead of storing them silently. "
                            . "Ask your host to make the server default strict as well, so that any other tool writing to this database is held to the same rules.";
                    }
                    // The app sets every database session to this timezone's offset, so its
                    // SQL dates agree with PHP's. Say so when the server's own clock zone
                    // differs: anything else reading this database sees the server's time.
                    $dbOffset = (int)$pdo->query('SELECT TIMESTAMPDIFF(MINUTE, UTC_TIMESTAMP(), NOW())')->fetchColumn();
                    $tzOffset = intdiv((new DateTime('now', new DateTimeZone($tz)))->getOffset(), 60);
                    if (abs($dbOffset - $tzOffset) > 1) {
                        $utc = fn(int $m): string => sprintf('UTC%s%02d:%02d', $m < 0 ? '-' : '+', intdiv(abs($m), 60), abs($m) % 60);
                        $notices[] = "Time zone: the database server's clock runs at {$utc($dbOffset)}, but {$tz} is at {$utc($tzOffset)} right now. "
                            . "The app sets each of its database connections to {$tz}, so dates and times inside the app are right. "
                            . "Anything else that reads this database directly (a report or export run by hand) sees the server's clock instead. "
                            . "If {$tz} is not your organisation's local time zone, correct 'timezone' in config.php now, before any records are entered.";
                        // Cron runs on the server's clock too: say how to shift the hours shown on the success page.
                        $shift = $dbOffset - $tzOffset;
                        $amount = intdiv(abs($shift), 60) . ' hour' . (intdiv(abs($shift), 60) === 1 ? '' : 's') . (abs($shift) % 60 ? ' ' . (abs($shift) % 60) . ' minutes' : '');
                        $cronShift = "This server's clock appears to run at {$utc($dbOffset)} (measured on the database server, which usually shares the web server's clock), while {$tz} is at {$utc($tzOffset)} right now: "
                            . ($shift > 0 ? "add {$amount} to" : "subtract {$amount} from") . " each hour above so the jobs run at those local times, and check them again after a daylight-saving change.";
                    }
                }
            }
        } catch (PDOException $e) {
            $errors[] = 'Database connection failed: ' . $e->getMessage();
            $pdo = null;
        }
    }

    // ---- Pre-flight: everything the later steps need, checked before any change ----
    $core = __DIR__ . '/inc/core.php';
    $pointerFile = __DIR__ . '/inc/config_path.php';
    $schemaFile = __DIR__ . '/schema_full.sql';
    $configFile = $privateDir . '/config.php';
    $configPathsPattern = '#\$configPaths = \[[^\]]*\];#s';
    $coreSrc = false;
    $usesPointer = false;
    $schemaSql = false;
    if (!$errors && $pdo) {
        $schemaSql = is_file($schemaFile) ? @file_get_contents($schemaFile) : false;
        if ($schemaSql === false || trim($schemaSql) === '') {
            $errors[] = 'schema_full.sql was not found (or could not be read) next to install.php. Nothing was changed.';
        }
        $coreSrc = is_file($core) ? @file_get_contents($core) : false;
        // This release's inc/core.php reads inc/config_path.php first; an older one
        // only has its built-in list, which is patched as before.
        $usesPointer = is_string($coreSrc) && str_contains($coreSrc, 'config_path.php');
        if ($coreSrc === false) {
            $errors[] = 'inc/core.php could not be read — upload the complete docroot/ folder first. Nothing was changed.';
        } elseif ($usesPointer) {
            if (is_file($pointerFile) ? !is_writable($pointerFile) : !is_writable(dirname($pointerFile))) {
                $errors[] = 'The inc folder is not writable by the web server, so the installer cannot write inc/config_path.php, which tells the app where its config is. Make the inc folder writable (or create an empty, writable inc/config_path.php) and run the installer again. Nothing was changed.';
            }
        } elseif (!preg_match($configPathsPattern, $coreSrc)) {
            $errors[] = 'inc/core.php does not contain the expected $configPaths list, so the installer cannot point the app at its config. Upload an unmodified inc/core.php and run the installer again. Nothing was changed.';
        } elseif (!is_writable($core)) {
            $errors[] = 'inc/core.php is not writable by the web server, so the installer cannot point the app at its config. Make that one file writable and run the installer again. Nothing was changed.';
        }
        if (is_file($configFile)) {
            $errors[] = "A config.php already exists in {$privateDir}. If it is left over from an earlier failed attempt, delete it and run the installer again; otherwise choose a different private directory so another installation is not overwritten. Nothing was changed.";
        }
    }

    // ---- Private directory (created and locked down before the database is loaded) ----
    if (!$errors && $pdo) {
        if (!is_dir($privateDir) && !@mkdir($privateDir, 0700, true)) {
            $errors[] = "Could not create private directory {$privateDir} — create it manually (chmod 700) and re-run. Nothing else was changed.";
        } elseif (!is_writable($privateDir)) {
            $errors[] = "Private directory {$privateDir} is not writable by the web server — fix its permissions (chmod 700, owned by the web user) and re-run. Nothing else was changed.";
        } else {
            $denyFile = $privateDir . '/.htaccess';
            if (is_file($denyFile)) {
                $report[] = "Existing {$denyFile} left as it is — make sure it denies all web access.";
            } elseif (@file_put_contents($denyFile, PRIVATE_HTACCESS) === false) {
                $errors[] = "Could not write {$denyFile}. Fix the permissions on {$privateDir} and re-run. Nothing else was changed.";
            } else {
                $report[] = "Web access to {$privateDir} denied with a .htaccess file (protects it on Apache-compatible servers even if the directory is ever published).";
            }
            @mkdir($privateDir . '/uploads', 0750);
            @mkdir($privateDir . '/backups', 0700);
        }
    }

    // ---- Schema + admin account. Until both succeed, no config is written and
    // the app's files are untouched, so a failure here leaves the app as it was. ----
    $dropHint = "Drop every table in database {$dbName} (or drop and re-create it as an empty database), then run the installer again.";
    if (!$errors && $pdo) {
        try {
            $pdo->exec($schemaSql);
            // exec() runs the whole dump as one batch; confirm every table really arrived.
            $expected = (int)preg_match_all('/^CREATE TABLE /m', $schemaSql);
            $tables = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()")->fetchColumn();
            if ($tables < $expected) {
                throw new RuntimeException("only {$tables} of {$expected} tables were created");
            }
            $report[] = "Schema imported: {$tables} tables (goal & assessment template libraries included).";

            $adminGroup = (int)($pdo->query("SELECT id FROM `groups` WHERE name = 'Administrators'")->fetchColumn() ?: 0);
            $pdo->prepare('INSERT INTO users (name, email, password_hash, role, group_id) VALUES (?,?,?,?,?)')
                ->execute([$adminName, $adminEmail, password_hash($adminPass, PASSWORD_DEFAULT), 'admin', $adminGroup ?: null]);
            $report[] = "Admin account created for {$adminEmail}.";
            // A fresh database is the newest version: Database upgrade then has nothing to do.
            // The version the application files need (inc/settings_admin.php), wherever the bundle put the migrations.
            $schemaSrc = (string)@file_get_contents(__DIR__ . '/inc/settings_admin.php') ?: (string)@file_get_contents(dirname(__DIR__) . '/public/inc/settings_admin.php');
            if (preg_match("/const DB_SCHEMA_VERSION = '(\d+\.\d+\.\d+)'/", $schemaSrc, $sv)) {
                $pdo->prepare("INSERT INTO settings (name, value) VALUES ('schema_version', ?) ON DUPLICATE KEY UPDATE value = VALUES(value)")->execute([$sv[1]]);
            }
            if ($supportPhone !== '') {
                // Shown beside the contact email in the page footer and the privacy notice
                // (core.php support_phone()); an administrator can change it later.
                $pdo->prepare('INSERT INTO settings (name, value) VALUES (?, ?)')->execute(['support_phone', $supportPhone]);
                $report[] = "Support phone {$supportPhone} saved (setting support_phone).";
            }
        } catch (Throwable $e) {
            $errors[] = 'Installation stopped while loading the database: ' . $e->getMessage()
                . ". The database now holds a partial installation. {$dropHint}"
                . " No config.php was written and the app's files were not modified; the private directory {$privateDir} can stay as it is.";
        }
    }

    // ---- Config, then point the app at it (inc/config_path.php, or for an older
    // inc/core.php its search path) ----
    if (!$errors && $pdo) {
        // Shown once on the success page: the emailed off-site backups cannot be
        // opened without it, and config.php is lost with the server.
        $backupPassword = bin2hex(random_bytes(12));
        $config = "<?php\nreturn " . var_export([
            'db' => ['dsn' => "mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4", 'user' => $dbUser, 'pass' => $dbPass],
            'brand' => [
                'name' => $brandName, 'short' => $brandShort, 'org' => $brandOrg,
                'tagline' => $brandTagline, 'color' => $brandColor,
                'color_dark' => $brandDark, 'accent' => $brandAccent,
                // The privacy notice (privacy.php): who runs the service, where, under
                // which law and on whose servers. Empty: that line is left out of the notice.
                'privacy_operator' => $privacyOperator, 'privacy_address' => $privacyAddress,
                'privacy_law' => $privacyLaw, 'privacy_hosting' => $privacyHosting,
                // Whether the privacy notice includes the HIPAA rights section (Settings can change it).
                'hipaa' => $hipaa,
            ],
            // token_cache: where the Graph access token is cached, beside this config.
            'graph' => ['tenant_id' => '', 'client_id' => '', 'client_secret' => '', 'sender' => $mailFrom,
                'token_cache' => $privateDir . '/graph_token.json'],
            // Optional Microsoft Entra ID single sign-on. Fill in and set enabled=true;
            // the Entra app registration needs a Web redirect URI of {base_url}/sso.php.
            'entra' => ['enabled' => false, 'tenant_id' => '', 'client_id' => '', 'client_secret' => '', 'auto_provision_group' => ''],
            'app' => [
                'name' => $brandName, 'base_url' => $baseUrl,
                // New versions come from the publisher's signed GitHub releases, installed with one
                // button on Administration, Software update (Settings, Updates; 'off' to stop checking).
                'update_url' => 'github',
                'mail_from' => $mailFrom, 'mail_from_name' => $brandName,
                // Public contact address shown in the page footer for help requests.
                // When empty, mail_from is shown instead: set this whenever mail_from is
                // an unmonitored notifications mailbox. backup_email is for cron only.
                'support_email' => $supportEmail,
                'backup_email' => $adminEmail,
                'session_timeout_minutes' => 30, 'otp_expiry_minutes' => 10,
                'upload_dir' => $privateDir . '/uploads',
                'timezone' => $tz,
                // Password for the AES-encrypted weekly off-site backup ZIP.
                'backup_password' => $backupPassword,
            ],
        ], true) . ";\n";
        // Created, never overwritten: a config.php that appeared since the check above
        // belongs to another installation.
        $configHandle = @fopen($configFile, 'x');
        $configWritten = $configHandle !== false && @fwrite($configHandle, $config) === strlen($config);
        if ($configHandle !== false) @fclose($configHandle);
        if (!$configWritten) {
            $errors[] = "Could not write {$configFile}, so the installation is incomplete. The database was already loaded: {$dropHint} Also check that {$privateDir} is writable and holds no config.php.";
        } else {
            @chmod($configFile, 0600);
            $report[] = "Config written to {$configFile}";
            if ($usesPointer) {
                $pointer = "<?php\n// Written by the installer: the private directory that holds config.php.\n"
                    . "// No bundle ships this file; keep it when uploading newer application files.\nreturn " . var_export($privateDir, true) . ";\n";
                if (@file_put_contents($pointerFile, $pointer) === false) {
                    $errors[] = "Could not write inc/config_path.php, so the app cannot find its config yet. The database and {$configFile} are in place. "
                        . "Either finish by hand — create inc/config_path.php containing <?php return " . var_export($privateDir, true) . "; then delete install.php, install.php.enabled and schema_full.sql — "
                        . "or start over: delete {$configFile}, make the inc folder writable, and then: {$dropHint}";
                } else {
                    $report[] = 'inc/config_path.php now points the app at its config. Keep that file when you upload newer application files.';
                }
            } else {
                // A callback keeps "$" and "\" in the path from being read as back-references.
                $patched = preg_replace_callback($configPathsPattern,
                    fn(array $m): string => "\$configPaths = [\n    " . var_export($configFile, true) . ",\n    dirname(__DIR__, 2) . '/private/config.php',\n];",
                    $coreSrc, 1);
                if (!is_string($patched) || @file_put_contents($core, $patched) === false) {
                    $errors[] = "Could not update inc/core.php, so the app cannot find its config yet. The database and {$configFile} are in place. "
                        . "Either finish by hand — edit inc/core.php so the first entry of \$configPaths is '{$configFile}', then delete install.php, install.php.enabled and schema_full.sql — "
                        . "or start over: delete {$configFile}, make inc/core.php writable, and then: {$dropHint}";
                } else {
                    $report[] = 'inc/core.php now loads the new config path.';
                }
            }
        }
    }

    if (!$errors && $pdo) {
        // ---- Cron settings: where the scheduled jobs find the app and what backup.sh dumps ----
        $shellQuote = fn(string $v): string => "'" . str_replace("'", "'\\''", $v) . "'";
        $cronDir = $privateDir . '/cron';
        $cronConfig = "<?php\n// Written by the installer: the application's inc/ directory, for the cron scripts.\nreturn " . var_export(__DIR__ . '/inc', true) . ";\n";
        $backupConf = "# Written by the installer: settings for backup.sh (read as shell).\n"
            . 'CMS_PRIVATE_DIR=' . $shellQuote($privateDir) . "\n"
            . 'CMS_DB_NAME=' . $shellQuote($dbName) . "\n"
            . 'CMS_DB_CNF=' . $shellQuote($privateDir . '/db.cnf') . "\n";
        $cronWritten = (is_dir($cronDir) || @mkdir($cronDir, 0700))
            && @file_put_contents($cronDir . '/cron_config.php', $cronConfig) !== false
            && @file_put_contents($cronDir . '/backup.conf', $backupConf) !== false;
        $report[] = $cronWritten
            ? "Cron settings written to {$cronDir}/cron_config.php and {$cronDir}/backup.conf."
            : "Could not write the cron settings into {$cronDir} — create the two files shown under the manual steps by hand.";

        // ---- Brand icons (requires GD) ----
        $defaultIcons = 'the default icons are still in place: replace assets/apple-touch-icon.png, icon-192.png, icon-512.png and icon-1024.png with your own.';
        if (function_exists('imagecreatetruecolor')) {
            [$r, $g, $b] = sscanf($brandDark, '#%02x%02x%02x');
            [$ar, $ag, $ab] = sscanf($brandAccent, '#%02x%02x%02x');
            $iconsOk = true;
            foreach ([180 => 'apple-touch-icon.png', 192 => 'icon-192.png', 512 => 'icon-512.png', 1024 => 'icon-1024.png'] as $s => $file) {
                $im = imagecreatetruecolor($s, $s);
                imagefill($im, 0, 0, imagecolorallocate($im, $r, $g, $b));
                $white = imagecolorallocate($im, 255, 255, 255);
                $c = (int)($s / 2); $outer = (int)($s * 0.62); $thick = (int)($s * 0.09);
                for ($i = 0; $i < $thick; $i++) {
                    imageellipse($im, $c, $c, $outer - $i, $outer - $i, $white);
                    imageellipse($im, $c, $c, $outer - $i - 1, $outer - $i, $white);
                }
                imagefilledellipse($im, $c, (int)($c - $outer / 2 + $thick / 2), (int)($s * 0.09), (int)($s * 0.09),
                    imagecolorallocate($im, $ar, $ag, $ab));
                $iconsOk = @imagepng($im, __DIR__ . '/assets/' . $file) && $iconsOk;
            }
            if ($iconsOk) $report[] = 'Brand icons generated in your colors.';
            else $notices[] = 'Icons: the icons in your colors could not be written to the assets folder, so ' . $defaultIcons;
        } else {
            $notices[] = "Icons: PHP's GD extension is not available, so " . $defaultIcons;
        }

        // The web-app manifest is manifest.php, built from the branding (4.3.0): nothing to write.

        // ---- Upload limits ----
        // A client document can be up to 25 MB. PHP turns away a bigger file, or a
        // bigger request, before the app sees it, so the app cannot say why. The
        // mobile app sends a document base64-encoded inside its JSON request, which
        // makes a 25 MB file about 35 MB: post_max_size needs 36M (as .user.ini sets).
        $uploadMax = ini_bytes((string)ini_get('upload_max_filesize'));
        $postMax = ini_bytes((string)ini_get('post_max_size'));
        if (($uploadMax > 0 && $uploadMax < 25 * 1048576) || ($postMax > 0 && $postMax < 36 * 1048576)) {
            $notices[] = 'Upload size: this server accepts files up to ' . ini_get('upload_max_filesize') . ' (upload_max_filesize) in a request of up to ' . ini_get('post_max_size') . ' (post_max_size). '
                . 'Client documents can be up to 25 MB, and the mobile app sends a document base64-encoded, which makes a 25 MB file about 35 MB. Set upload_max_filesize to at least 25M and post_max_size to at least 36M, in the .user.ini file in this folder or in your hosting panel\'s PHP settings (on cPanel, the MultiPHP INI Editor). '
                . 'Until then, a larger file is refused by the server before the app can say why.';
        }

        // ---- Lock ----
        $lockOk = @file_put_contents($lockFile, date('c')) !== false;
        @unlink($enableFile);
        // Remove the installer and the schema dump so they can't be served afterward
        // (don't rely on the admin remembering the manual step). .htaccess also denies them.
        @unlink(__DIR__ . '/schema_full.sql');
        @unlink(__FILE__);
        // Whatever could not be removed is listed prominently on the success page.
        clearstatcache();
        $leftovers = array_values(array_filter([__FILE__, $enableFile, __DIR__ . '/schema_full.sql'], 'file_exists'));
        if ($lockOk && !$leftovers) $report[] = 'The installer locked itself (install.lock) and removed install.php, install.php.enabled and schema_full.sql.';
        $done = true;
    }
}

// The Private directory field: its current value, and whether that sits under a
// folder web servers normally publish (then a warning and a confirmation box show).
$privateDirValue = field('private_dir', default_private_dir());
$privateDirWebDir = $privateDirValue !== '' ? web_dir_component(resolve_path($privateDirValue)) : null;
header('Cache-Control: no-store');   // the success page shows the off-site backup password once
?><!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>CMS Installer</title>
<style>
*{box-sizing:border-box}input:not([type=checkbox]),button{min-height:44px}input[type=checkbox]{width:24px;height:24px}label:has(input[type=checkbox]){min-height:44px}
body{font-family:system-ui,sans-serif;max-width:44rem;margin:2rem auto;padding:0 1rem;line-height:1.5;color:#1a1a2e}
h1{color:#1e3a8a} fieldset{border:1px solid #ccd;border-radius:8px;margin:0 0 1rem;padding:1rem}
label{display:block;font-weight:600;margin:.6rem 0 .2rem} input{width:100%;padding:.5rem;border:2px solid #667;border-radius:6px;font-size:1rem}
input[type=checkbox]{width:24px;height:24px;margin-right:.5rem}
button{background:#1e40af;color:#fff;border:0;border-radius:8px;padding:.7rem 1.4rem;font-size:1rem;font-weight:600;cursor:pointer}
.err{background:#fef2f2;border:2px solid #dc2626;color:#991b1b;padding:.7rem 1rem;border-radius:8px;margin-bottom:1rem}
.ok{background:#ecfdf5;border:2px solid #059669;color:#065f46;padding:.7rem 1rem;border-radius:8px;margin-bottom:1rem}
.warn{background:#fffbeb;border:2px solid #b45309;color:#78350f;padding:.7rem 1rem;border-radius:8px;margin:.6rem 0}
.field-error{color:#991b1b;font-weight:600;margin:.2rem 0 .4rem} .err a{color:#991b1b}
.hint{font-weight:400;color:#556;font-size:.9rem} code{background:#eef;padding:.1rem .3rem;border-radius:4px}
pre{background:#eef;padding:.6rem .8rem;border-radius:6px;overflow-x:auto;white-space:pre-wrap;word-break:break-all}
</style></head><body>
<h1>Case Management System — Installer</h1>

<?php if ($done): ?>
  <div class="ok"><strong>Installed successfully.</strong></div>
  <?php if ($leftovers): ?>
  <div class="err"><h2>Secure the installer now</h2>
    <p>The web server was not allowed to delete <?= count($leftovers) === 1 ? 'this installer file' : 'these installer files' ?><?= !$lockOk ? ' or to create <code>install.lock</code>' : '' ?>. Until <?= count($leftovers) === 1 ? 'it is' : 'they are' ?> gone, the installer could be run again — taking over this site — or the database layout downloaded. Delete <?= count($leftovers) === 1 ? 'it' : 'them' ?> from the docroot now with your hosting file manager:</p>
    <ul><?php foreach ($leftovers as $f) echo '<li><code>' . htmlspecialchars(basename($f)) . '</code></li>'; ?></ul>
  </div>
  <?php endif; ?>
  <?php foreach ($notices as $n) echo '<div class="warn">' . htmlspecialchars($n) . '</div>'; ?>
  <ul><?php foreach ($report as $r) echo '<li>' . htmlspecialchars($r) . '</li>'; ?></ul>
  <h2>Off-site backup password — shown only once</h2>
  <p>Every Monday the weekly off-site backup emails an encrypted ZIP of the database and uploaded documents. This is its password. <strong>Store it now in your organisation's password manager</strong>, somewhere other than this server: if the server is ever lost, the emailed backups cannot be opened without it, and this page will not show it again.</p>
  <label for="backup_password">Off-site backup password</label>
  <input id="backup_password" type="text" readonly autocomplete="off" spellcheck="false" value="<?= htmlspecialchars($backupPassword) ?>">
  <h2>Finish up (manual steps)</h2>
  <ol>
    <li><strong>Confirm <code>install.php</code>, <code>install.php.enabled</code> and <code>schema_full.sql</code> are gone, and <code>install.lock</code> is present.</strong> The installer does this itself on success (and <code>.htaccess</code> denies them); if your host blocked it, fix it in the docroot now.</li>
    <li>Sign in at <code><?= htmlspecialchars($baseUrl) ?>/login.php</code> — 2FA codes email via PHP mail() until Graph is configured; add Azure Graph credentials (tenant/client/secret; Mail.Send, plus Mail.ReadWrite for email filing and off-site backups over 2 MB) in <code>config.php</code> for reliable delivery.</li>
    <li>Copy the files from the install bundle's <code>cron/</code> folder into <code><?= htmlspecialchars($cronDir) ?></code>. The scripts need no editing: they read the two small settings files the installer wrote there. Keep those files; if either is missing, create it with exactly this content.<br>
      <code><?= htmlspecialchars($cronDir) ?>/cron_config.php</code> (tells the scripts where the app's <code>inc/</code> folder is):
      <pre><?= htmlspecialchars($cronConfig) ?></pre>
      <code><?= htmlspecialchars($cronDir) ?>/backup.conf</code> (tells <code>backup.sh</code> what to back up):
      <pre><?= htmlspecialchars($backupConf) ?></pre></li>
    <li>Create <code><?= htmlspecialchars($privateDir) ?>/db.cnf</code> (permissions 600) so the nightly backup can sign in to the database:
      <pre>[client]
user=<?= htmlspecialchars($dbUser) ?>

password="YOUR DATABASE PASSWORD"
host=<?= htmlspecialchars($dbHost) ?>
</pre></li>
    <li>Add cron jobs (adjust the PHP path as needed). The hours are local times in <?= htmlspecialchars($tz) ?>, but cron runs them by the server's own clock<?= $cronShift === '' ? ', which appears to match it' : '' ?>:<br>
      <code>15 2 * * * <?= htmlspecialchars($cronDir) ?>/backup.sh</code> (make it executable: chmod 700)<br>
      <code>0 7 * * * php <?= htmlspecialchars($cronDir) ?>/daily_alerts.php</code><br>
      <code>15 7,17 * * * php <?= htmlspecialchars($cronDir) ?>/session_reminders.php</code> (morning and evening; each run reminds every session from then to the end of tomorrow not yet reminded, so a session booked after the evening run is reminded the next morning)<br>
      <code>30 7 * * * php <?= htmlspecialchars($cronDir) ?>/monthly_reports.php</code><br>
      <code>0 8 * * * php <?= htmlspecialchars($cronDir) ?>/at_reminders.php</code><br>
      <code>*/15 * * * * php <?= htmlspecialchars($cronDir) ?>/ingest_email.php</code> (needs Graph)<br>
      <code>*/10 * * * * php <?= htmlspecialchars($cronDir) ?>/watchdog.php</code><br>
      <code>45 2 * * * php <?= htmlspecialchars($cronDir) ?>/weekly_offsite.php</code>
      <?php if ($cronShift !== ''): ?><br><strong>Server clock:</strong> <?= htmlspecialchars($cronShift) ?><?php endif; ?></li>
    <li>After the first night, confirm a <code>cms_*.sql.gz</code> file appeared in <code><?= htmlspecialchars($privateDir) ?>/backups</code>. The watchdog emails <code><?= htmlspecialchars($adminEmail) ?></code> if no backup is newer than 48 hours.</li>
  </ol>
<?php else: ?>
  <?php if ($errors): ?>
  <div class="err" role="alert"><h2>The installation could not continue</h2>
    <ul><?php foreach ($errors as $e) { $f = array_search($e, $errorFor, true); echo '<li>' . ($f !== false ? '<a href="#' . $f . '">' . htmlspecialchars($e) . '</a>' : htmlspecialchars($e)) . '</li>'; } ?></ul></div>
  <?php endif; ?>
  <p>Requires PHP 8.1+ and <strong>MariaDB 10.4 or newer</strong> (MySQL is not supported).</p>
  <form method="post">
    <fieldset><legend>Branding</legend>
      <label for="brand_name">Business / app name <span class="hint">(titles, emails — e.g., “Riverbend Vision Services CMS”)</span></label>
      <input id="brand_name" name="brand_name" required autocomplete="organization" value="<?= htmlspecialchars(field('brand_name')) ?>"<?= field_err_attr('brand_name') ?>><?= field_err_msg('brand_name') ?>
      <label for="brand_short">Short brand <span class="hint">(header — e.g., “Riverbend Vision”)</span></label>
      <input id="brand_short" name="brand_short" required autocomplete="off" value="<?= htmlspecialchars(field('brand_short')) ?>"<?= field_err_attr('brand_short') ?>><?= field_err_msg('brand_short') ?>
      <label for="brand_org">Full organization name <span class="hint">(report letterheads)</span></label>
      <input id="brand_org" name="brand_org" required autocomplete="organization" value="<?= htmlspecialchars(field('brand_org')) ?>"<?= field_err_attr('brand_org') ?>><?= field_err_msg('brand_org') ?>
      <label for="brand_tagline">Tagline <span class="hint">(invoice letterhead subtitle)</span></label>
      <input id="brand_tagline" name="brand_tagline" autocomplete="off" value="<?= htmlspecialchars(field('brand_tagline')) ?>">
      <label for="brand_color">Primary color <span class="hint">(6-digit hex, e.g. #1e40af)</span></label><input id="brand_color" name="brand_color" autocomplete="off" value="<?= htmlspecialchars(field('brand_color', '#1e40af')) ?>"<?= field_err_attr('brand_color') ?>><?= field_err_msg('brand_color') ?>
      <label for="brand_color_dark">Dark color <span class="hint">(header and PDFs, 6-digit hex)</span></label><input id="brand_color_dark" name="brand_color_dark" autocomplete="off" value="<?= htmlspecialchars(field('brand_color_dark', '#1e3a8a')) ?>"<?= field_err_attr('brand_color_dark') ?>><?= field_err_msg('brand_color_dark') ?>
      <label for="brand_accent">Accent color <span class="hint">(6-digit hex)</span></label><input id="brand_accent" name="brand_accent" autocomplete="off" value="<?= htmlspecialchars(field('brand_accent', '#0f766e')) ?>"<?= field_err_attr('brand_accent') ?>><?= field_err_msg('brand_accent') ?>
    </fieldset>
    <fieldset><legend>Site</legend>
      <label for="base_url">Base URL <span class="hint">(https://cms.example.com — no trailing slash)</span></label>
      <input id="base_url" name="base_url" required autocomplete="url" value="<?= htmlspecialchars(field('base_url')) ?>"<?= field_err_attr('base_url') ?>><?= field_err_msg('base_url') ?>
      <label for="private_dir">Private directory <span class="hint">(config, uploads and backups — must be outside the docroot; created if missing)</span></label>
      <input id="private_dir" name="private_dir" autocomplete="off" value="<?= htmlspecialchars($privateDirValue) ?>"<?= field_err_attr('private_dir') ?>><?= field_err_msg('private_dir') ?>
      <?php if ($privateDirWebDir !== null): ?>
      <div class="warn" role="alert"><strong>Warning:</strong> this path is under a folder named “<?= htmlspecialchars($privateDirWebDir) ?>”, which web servers normally publish. The config, uploaded client documents and nightly database backups kept there could become downloadable. Use a directory outside “<?= htmlspecialchars($privateDirWebDir) ?>” if your hosting account has one. If it does not, tick the box below to continue: the installer writes a deny-all <code>.htaccess</code> into the directory, which protects it on Apache-compatible servers only.</div>
      <label for="private_dir_confirm"><input type="checkbox" id="private_dir_confirm" name="private_dir_confirm" value="1"<?= !empty($_POST['private_dir_confirm']) ? ' checked' : '' ?>>I understand the risk and want to use this directory anyway</label>
      <?php endif; ?>
      <label for="mail_from">Mail-from address <span class="hint">(the mailbox the app sends email from)</span></label>
      <input id="mail_from" name="mail_from" required autocomplete="off" value="<?= htmlspecialchars(field('mail_from')) ?>"<?= field_err_attr('mail_from') ?>><?= field_err_msg('mail_from') ?>
      <label for="support_email">Public contact email <span class="hint">(optional — shown in the page footer for help requests; leave blank to show the mail-from address, but fill it in if that mailbox is not monitored)</span></label>
      <input id="support_email" name="support_email" type="email" maxlength="190" autocomplete="off" value="<?= htmlspecialchars(field('support_email')) ?>"<?= field_err_attr('support_email') ?>><?= field_err_msg('support_email') ?>
      <label for="support_phone">Support phone <span class="hint">(optional — shown with the contact email in the page footer and the privacy notice, e.g. +1 555 010 2000; can be changed later in Administration)</span></label>
      <input id="support_phone" name="support_phone" type="tel" maxlength="30" autocomplete="off" value="<?= htmlspecialchars(field('support_phone')) ?>"<?= field_err_attr('support_phone') ?>><?= field_err_msg('support_phone') ?>
      <label for="timezone">Time zone <span class="hint">(your organisation's local time zone, e.g. America/Chicago — every date and time in the app uses it)</span></label>
      <input id="timezone" name="timezone" autocomplete="off" value="<?= htmlspecialchars(field('timezone', 'America/Chicago')) ?>"<?= field_err_attr('timezone') ?>><?= field_err_msg('timezone') ?>
    </fieldset>
    <fieldset><legend>Privacy notice <span class="hint">(all optional — fills in the privacy page's "who runs this service" lines; leave blank to omit them, or edit them later in config.php)</span></legend>
      <label for="privacy_operator">Operator <span class="hint">(the person or organisation legally responsible for the data, e.g. “Riverbend Vision Services, Inc.”)</span></label>
      <input id="privacy_operator" name="privacy_operator" maxlength="190" autocomplete="organization" value="<?= htmlspecialchars(field('privacy_operator')) ?>"<?= field_err_attr('privacy_operator') ?>><?= field_err_msg('privacy_operator') ?>
      <label for="privacy_address">Operator's postal address <span class="hint">(one line, e.g. “12 Main St, Suite 4, Springfield, Illinois 62701”)</span></label>
      <input id="privacy_address" name="privacy_address" maxlength="255" autocomplete="street-address" value="<?= htmlspecialchars(field('privacy_address')) ?>"<?= field_err_attr('privacy_address') ?>><?= field_err_msg('privacy_address') ?>
      <label for="privacy_law">Governing law <span class="hint">(the state or country whose law applies, e.g. “Illinois”)</span></label>
      <input id="privacy_law" name="privacy_law" maxlength="120" autocomplete="off" value="<?= htmlspecialchars(field('privacy_law')) ?>"<?= field_err_attr('privacy_law') ?>><?= field_err_msg('privacy_law') ?>
      <label for="privacy_hosting">Hosting provider <span class="hint">(where the server runs, e.g. “Linode” or “our own data centre in Chicago”)</span></label>
      <input id="privacy_hosting" name="privacy_hosting" maxlength="190" autocomplete="off" value="<?= htmlspecialchars(field('privacy_hosting')) ?>"<?= field_err_attr('privacy_hosting') ?>><?= field_err_msg('privacy_hosting') ?>
      <label for="hipaa">Does HIPAA apply to you?</label>
      <select id="hipaa" name="hipaa"><option value="no"<?= field('hipaa') !== 'yes' ? ' selected' : '' ?>>No</option><option value="yes"<?= field('hipaa') === 'yes' ? ' selected' : '' ?>>Yes</option></select>
    </fieldset>
    <fieldset><legend>Database (empty MariaDB 10.4+ database)</legend>
      <label for="db_host">Database host</label><input id="db_host" name="db_host" autocomplete="off" value="<?= htmlspecialchars(field('db_host', 'localhost')) ?>">
      <label for="db_name">Database name</label><input id="db_name" name="db_name" required autocomplete="off" value="<?= htmlspecialchars(field('db_name')) ?>"<?= field_err_attr('db_name') ?>><?= field_err_msg('db_name') ?>
      <label for="db_user">Database user</label><input id="db_user" name="db_user" required autocomplete="off" value="<?= htmlspecialchars(field('db_user')) ?>"<?= field_err_attr('db_user') ?>><?= field_err_msg('db_user') ?>
      <label for="db_pass">Database password</label><input id="db_pass" name="db_pass" type="password" autocomplete="off">
    </fieldset>
    <fieldset><legend>Admin account</legend>
      <label for="admin_name">Administrator's name</label><input id="admin_name" name="admin_name" required maxlength="120" autocomplete="name" value="<?= htmlspecialchars(field('admin_name')) ?>"<?= field_err_attr('admin_name') ?>><?= field_err_msg('admin_name') ?>
      <label for="admin_email">Administrator's email <span class="hint">(used to sign in)</span></label><input id="admin_email" name="admin_email" type="email" required maxlength="183" autocomplete="email" value="<?= htmlspecialchars(field('admin_email')) ?>"<?= field_err_attr('admin_email') ?>><?= field_err_msg('admin_email') ?>
      <label for="admin_pass">Administrator's password <span class="hint">(10+ characters)</span></label><input id="admin_pass" name="admin_pass" type="password" required minlength="10" autocomplete="new-password"<?= field_err_attr('admin_pass') ?>><?= field_err_msg('admin_pass') ?>
    </fieldset>
    <p id="install-progress" role="status"></p>
    <button type="submit">Install</button>
  </form>
  <script>
  // One press installs: a second press (or Enter) while the first is still running
  // is not sent, and the status line says what is happening.
  (function () {
    var form = document.querySelector('form'), status = document.getElementById('install-progress'), sent = false;
    if (!form || !status) return;
    form.addEventListener('submit', function (event) {
      if (sent) { event.preventDefault(); return; }
      sent = true;
      var button = form.querySelector('button[type=submit]');
      if (button) button.setAttribute('aria-disabled', 'true');
      status.textContent = 'Installing. This can take a minute: keep this page open and do not press Install again.';
    });
  })();
  </script>
<?php endif; ?>
</body></html>
