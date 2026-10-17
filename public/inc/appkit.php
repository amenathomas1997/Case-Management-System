<?php
declare(strict_types=1);
// The app kit (4.18.0, owner request 2026-10-17): an organisation running its own installation
// downloads the Android and iPhone apps' source already set to its own name, server, app ID and
// icon (inc/appkit/app-source.zip, made by dist/make_appkit.sh), with the steps to build them and
// hand them to its staff. Apple and Google require each organisation to publish under its own
// developer account, so the kit is the source, not a finished app.

const APPKIT_SOURCE = __DIR__ . '/appkit/app-source.zip';
/** Android launcher icon sizes, by folder. */
const APPKIT_ANDROID_ICONS = ['mdpi' => 48, 'hdpi' => 72, 'xhdpi' => 96, 'xxhdpi' => 144, 'xxxhdpi' => 192];

/** The server address the apps sign in to: base_url without https://, such as cms.example.org or example.org/cms. */
function appkit_server(): string {
    $base = rtrim((string)cfg('base_url'), '/');
    return (string)preg_replace('~^https://~i', '', $base);
}

/** An app ID made from the server's name: cms.example.org becomes org.example.cms. */
function appkit_default_id(): string {
    $host = strtolower((string)(parse_url('https://' . appkit_server(), PHP_URL_HOST) ?: 'example.org'));
    $parts = array_reverse(array_values(array_filter(explode('.', $host), fn($p) => $p !== '' && $p !== 'www')));
    // Letters and numbers only: Android refuses hyphens and Apple refuses underscores.
    $parts = array_map(fn($p) => preg_match('/^[a-z]/', $p = preg_replace('/[^a-z0-9]/', '', $p) ?? '') ? $p : 'x' . $p, $parts);
    if (count($parts) < 2) $parts[] = 'cms';
    return implode('.', $parts);
}

/** The values a kit is made with: saved ones, else ones made from the branding and server. */
function appkit_values(): array {
    $name = trim((string)(brand('name') ?: cfg('name')));
    $short = trim((string)(brand('short') ?: $name));
    return [
        'app_id' => (string)(setting('appkit_app_id') ?: appkit_default_id()),
        'display_name' => (string)(setting('appkit_display_name') ?: mb_substr($short, 0, 30)),
        'spoken_name' => (string)(setting('appkit_spoken_name') ?: $name),
        'signin_title' => (string)(setting('appkit_signin_title') ?: $name),
        'apple_team' => (string)(setting('appkit_apple_team') ?: ''),
    ];
}

/** Checks posted values. Returns [values, errors by field]. */
function appkit_check(array $in): array {
    $v = [];
    foreach (['app_id', 'display_name', 'spoken_name', 'signin_title', 'apple_team'] as $k) $v[$k] = trim(is_string($in[$k] ?? null) ? $in[$k] : '');
    $e = [];
    // Valid for both stores: Android refuses hyphens, Apple refuses underscores, and every part starts with a letter.
    if (!preg_match('/^[a-z][a-z0-9]*(\.[a-z][a-z0-9]*)+$/', $v['app_id']) || strlen($v['app_id']) > 100) {
        $e['app_id'] = 'Use lower-case letters and numbers in parts joined by dots, each starting with a letter, such as org.example.cms.';
    }
    // Names go into build files: letters, numbers, spaces and plain punctuation only.
    $plain = '/^[\p{L}\p{N} .,&\'()\-]+$/u';
    foreach (['display_name' => [30, 'The name under the icon'], 'spoken_name' => [80, 'How screen readers say it'], 'signin_title' => [80, 'The sign-in title']] as $k => [$max, $label]) {
        if ($v[$k] === '' || mb_strlen($v[$k]) > $max || !preg_match($plain, $v[$k])) $e[$k] = $label . ': up to ' . $max . ' letters, numbers, spaces and . , & \' ( ) -';
    }
    $v['apple_team'] = strtoupper($v['apple_team']);
    if ($v['apple_team'] !== '' && !preg_match('/^[A-Z0-9]{10}$/', $v['apple_team'])) $e['apple_team'] = 'The Apple team ID is 10 letters and numbers (Apple Developer, Membership), or leave it empty.';
    return [$v, $e];
}

/** A value for gradle.properties: backslashes, and anything not plain ASCII as \uXXXX. */
function appkit_prop(string $s): string {
    $out = '';
    foreach (mb_str_split($s) as $ch) {
        $cp = mb_ord($ch);
        $out .= $ch === '\\' ? '\\\\' : ($cp < 0x20 || $cp > 0x7E ? sprintf('\\u%04X', $cp) : $ch);
    }
    return $out;
}

/** A double-quoted YAML string. */
function appkit_yaml(string $s): string {
    return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $s) . '"';
}

/** Replace "key=..." (or "key: ...") lines; every key must be found once. */
function appkit_set_lines(string $text, array $pairs, string $sep): string {
    foreach ($pairs as $key => $value) {
        $re = '/^(\s*' . preg_quote($key, '/') . ')' . ($sep === '=' ? '=' : ':\s') . '.*$/m';
        if (preg_match_all($re, $text) !== 1) throw new RuntimeException("The app source has no single $key setting.");
        $text = (string)preg_replace_callback($re, fn($m) => $m[1] . ($sep === '=' ? '=' : ': ') . $value, $text);
    }
    return $text;
}

/** The brand icon at $size, as PNG bytes, on white (the App Store refuses a see-through icon). */
function appkit_icon(int $size, bool $opaque): ?string {
    if (!brand_icon_uploaded() || !function_exists('imagecreatefrompng')) return null;
    $src = @imagecreatefrompng((string)brand_icon_path($size >= 512 ? 1024 : 512));
    if (!$src) return null;
    $im = imagecreatetruecolor($size, $size);
    if ($opaque) imagefill($im, 0, 0, imagecolorallocate($im, 255, 255, 255));
    else { imagealphablending($im, false); imagesavealpha($im, true); imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127)); imagealphablending($im, true); }
    imagecopyresampled($im, $src, 0, 0, 0, 0, $size, $size, imagesx($src), imagesy($src));
    if (!$opaque) imagesavealpha($im, true);
    ob_start();
    imagepng($im);
    return (string)ob_get_clean();
}

/** The steps that come with the kit, in the organisation's own words. */
function appkit_readme(array $v): string {
    $server = appkit_server();
    $org = trim((string)(brand('name') ?: cfg('name')));
    $team = $v['apple_team'] !== '' ? $v['apple_team'] : 'your 10-character team ID (Apple Developer, Membership)';
    return <<<MD
# {$v['display_name']}: your own phone apps

This kit is the source of the Android and iPhone apps for {$org}. It is already set to:
- your name ("{$v['display_name']}" under the icon; "{$v['signin_title']}" on the sign-in screen)
- your server (https://{$server})
- your app ID ({$v['app_id']})
- your icon, if one was uploaded on Settings, Branding

Your staff sign in with their usual accounts. The apps are free software under the GPL (see LICENSE).

Apple and Google require each organisation to publish its apps under its own developer account. So you build and sign the apps once, then hand them to your staff as below. Your settings are in android/gradle.properties and ios/project.yml if you need to change them later.

## Android

You need a Mac, Windows or Linux computer with Android Studio installed (it brings the Java and the Android SDK the build uses). Open the android folder in Android Studio once, so it records where the SDK is (or set ANDROID_HOME).

1. Make an upload key once, and keep it and its password safe:
   `keytool -genkeypair -keystore upload.jks -alias upload -keyalg RSA -keysize 2048 -validity 10000`
   (keytool is in Android Studio's jbr/bin folder.)
2. In the android folder, make a file named keystore.properties:
   ```
   storeFile=/full/path/to/upload.jks
   storePassword=your password
   keyAlias=upload
   keyPassword=your password
   ```
3. Build, from the android folder, with JAVA_HOME set to Android Studio's jbr folder:
   - `./gradlew :app:bundleRelease` makes the Google Play file: app/build/outputs/bundle/release/app-release.aab
   - `./gradlew :app:assembleRelease` makes an installable file: app/build/outputs/apk/release/app-release.apk
4. Hand it to your staff in one of these ways:
   - **Google Play, internal testing:** the simplest way to keep everyone up to date. Make a Play Console account (a one-time US\$25 fee). Create the app, open Testing, Internal testing, upload the .aab, and add your staff's Google accounts (up to 100). They install it from Play and get updates on their own.
   - **Managed Google Play:** if your phones are managed by your organisation, publish it as a private app that only your organisation sees.
   - **Directly:** send the .apk file (for example as a download link). On each phone, allow installing apps from that source when asked. Updates are not automatic: send each new .apk the same way, signed with the same key.

## iPhone

You need a Mac with Xcode, and xcodegen (`brew install xcodegen`).

1. Join the Apple Developer Program as an organisation (US\$99 a year). In ios/project.yml, CMS_TEAM must be {$team}.
2. In Xcode, open Settings, Accounts, and sign in with your Apple ID.
3. At appstoreconnect.apple.com, add a new iOS app with the bundle ID {$v['app_id']}.
4. From the ios folder, run `xcodegen generate`. Then open the .xcodeproj, choose Product, Archive, and in the Organizer choose Distribute App, App Store Connect, Upload.
5. Hand it to your staff in one of these ways:
   - **TestFlight:** in App Store Connect, open TestFlight, Internal Testing, and add your staff as App Store Connect users (up to 100). They install the TestFlight app and accept the invitation. No Apple review is needed. Builds expire after 90 days, so upload a fresh one at least that often.
   - **Custom app (Apple Business Manager):** publish it privately to your organisation for good.

Raise the build number before each new upload: cms.versionCode in android/gradle.properties, CURRENT_PROJECT_VERSION in ios/project.yml. The stores refuse a number they have seen.

## Privacy answers both stores ask for

- No tracking and no ads.
- Data is used only to run the app, and sent only to your own server:
  - names, email addresses, phone numbers and addresses
  - health-related case notes
  - messages
  - documents and photos staff upload
- Your privacy policy is at https://{$server}/privacy.php
- The stores need a sign-in for review. Make a staff account that sees only made-up clients.
MD;
}

/**
 * Build the kit for $v in a temporary file and return its path (the caller sends it and removes it).
 * Throws RuntimeException with words for staff when it cannot.
 */
function appkit_build(array $v): string {
    if (!class_exists('ZipArchive')) throw new RuntimeException("This server's PHP has no zip support, so the kit cannot be made here.");
    if (!is_file(APPKIT_SOURCE)) throw new RuntimeException('The app source is missing from this installation (inc/appkit/app-source.zip).');
    $tmp = tempnam(sys_get_temp_dir(), 'appkit');
    if ($tmp === false || !copy(APPKIT_SOURCE, $tmp)) throw new RuntimeException('The kit could not be prepared on this server.');
    $zip = new ZipArchive();
    if ($zip->open($tmp) !== true) { @unlink($tmp); throw new RuntimeException('The app source could not be read.'); }
    try {
        $gp = $zip->getFromName('android/gradle.properties');
        $py = $zip->getFromName('ios/project.yml');
        if ($gp === false || $py === false) throw new RuntimeException('The app source is incomplete.');
        $zip->addFromString('android/gradle.properties', appkit_set_lines($gp, [
            'cms.appId' => $v['app_id'], 'cms.displayName' => appkit_prop($v['display_name']), 'cms.spokenName' => appkit_prop($v['spoken_name']),
            'cms.signinTitle' => appkit_prop($v['signin_title']), 'cms.defaultServer' => appkit_prop(appkit_server()),
        ], '='));
        $zip->addFromString('ios/project.yml', appkit_set_lines($py, [
            'CMS_BUNDLE_ID' => $v['app_id'], 'CMS_TEAM' => appkit_yaml($v['apple_team']), 'CMS_DISPLAY_NAME' => appkit_yaml($v['display_name']),
            'CMS_SPOKEN_NAME' => appkit_yaml($v['spoken_name']), 'CMS_SIGNIN_TITLE' => appkit_yaml($v['signin_title']), 'CMS_DEFAULT_SERVER' => appkit_yaml(appkit_server()),
        ], ':'));
        // The organisation's own icon, when it uploaded one (Settings, Branding).
        foreach (APPKIT_ANDROID_ICONS as $dir => $px) {
            if (($png = appkit_icon($px, false)) !== null) $zip->addFromString("android/app/src/main/res/mipmap-$dir/ic_launcher.png", $png);
        }
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $n = (string)$zip->getNameIndex($i);
            if (preg_match('~^ios/Sources/[^/]+\.xcassets/AppIcon\.appiconset/[^/]+\.png$~', $n) && ($png = appkit_icon(1024, true)) !== null) $zip->addFromString($n, $png);
        }
        $zip->addFromString('BUILD-YOUR-APPS.md', appkit_readme($v));
    } catch (Throwable $e) {
        $zip->close(); @unlink($tmp);
        throw $e instanceof RuntimeException ? $e : new RuntimeException('The kit could not be made.');
    }
    if (!$zip->close()) { @unlink($tmp); throw new RuntimeException('The kit could not be saved on this server.'); }
    return $tmp;
}
