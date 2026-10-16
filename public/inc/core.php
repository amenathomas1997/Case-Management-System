<?php
declare(strict_types=1);
// As bootstrap.php: api.php and calfeed.php load this file directly, and a PHP
// warning printed into their response would show server paths. Logged instead.
if (PHP_SAPI !== 'cli') {
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
}
require_once __DIR__ . '/access.php';
require_once __DIR__ . '/referral_request.php';
require_once __DIR__ . '/program_access.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/tasks.php';
require_once __DIR__ . '/ai.php';
require_once __DIR__ . '/voice.php';
// Core app functions shared by web pages (via bootstrap.php) and CLI cron scripts.

$configPaths = [
    '/home/cmsuser/casemanagement_private/config.php', // production
    dirname(__DIR__, 2) . '/private/config.php',            // local dev
];
// inc/config_path.php (written by the installer, never shipped in a bundle) names the private directory that holds config.php; it wins over the built-in list.
if (is_file(__DIR__ . '/config_path.php')) array_unshift($configPaths, rtrim((string)(require __DIR__ . '/config_path.php'), '/\\') . '/config.php');
$CONFIG = null;
foreach ($configPaths as $p) {
    if (is_file($p)) { $CONFIG = require $p; break; }
}
if (!$CONFIG) { http_response_code(500); exit('Configuration missing.'); }

const APP_VERSION = '3.0.40';

date_default_timezone_set(($CONFIG['app']['timezone'] ?? null) ?: 'America/Chicago'); // db() sets each SQL session to this offset

function db(): PDO {
    static $pdo = null;
    global $CONFIG;
    if ($pdo === null) {
        try {
            $pdo = new PDO($CONFIG['db']['dsn'], $CONFIG['db']['user'], $CONFIG['db']['pass'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $e) {
            // The PDOException's stack trace carries the constructor arguments
            // (DSN, user, password), and calfeed.php and the cron scripts have no
            // handler of their own: rethrow without it (no previous exception).
            throw new RuntimeException('Database connection failed (' . $e->getCode() . ')');
        }
        // SQL NOW()/CURDATE() must agree with PHP's date(). Prefer the named app
        // zone, which converts each TIMESTAMP value with its own DST offset; when
        // the server has no time-zone tables loaded, fall back to PHP's current offset.
        try {
            $pdo->exec('SET time_zone = ' . $pdo->quote(date_default_timezone_get()));
        } catch (PDOException $e) {
            $pdo->exec('SET time_zone = ' . $pdo->quote(date('P')));
        }
        // Pin strict mode for this session whatever the server default is: a value
        // too long or out of range for its column is then refused (and reported as
        // the caller's to fix) instead of being stored cut short with a warning.
        $pdo->exec("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
    }
    return $pdo;
}

function cfg(string $key) { global $CONFIG; return $CONFIG['app'][$key] ?? null; }

/** White-label branding: config-driven with Example Agency defaults. */
function brand(string $key): string {
    static $defaults = [
        'name' => 'Case Management System',       // app name (titles, emails)
        'short' => 'Example Agency',                       // header brand
        'org' => 'Example Agency',       // legal/report letterhead
        'tagline' => 'Assistive Technology Training & Consulting',
        'color' => '#1e40af',                              // --brand (8.7:1 on white)
        'color_dark' => '#1e3a8a',                         // --brand-dark (header, PDFs)
        'accent' => '#134e4a',                             // --accent / dark teal (9.5:1 on white)
    ];
    global $CONFIG;
    return (string)($CONFIG['brand'][$key] ?? $defaults[$key] ?? '');
}

/** HTML-escape (invalid UTF-8 shows as U+FFFD rather than blanking the whole value) */
function h(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

/* ---------- Groups & permissions ---------- */

// Permission keys and what they gate.
const PERMISSION_KEYS = [
    'clients' => 'Clients & case records (notes, tracks, assessments, documents)',
    'reports' => 'Send progress & evaluation reports',
    'referrals' => 'Referral queue',
    'schedule' => 'Scheduling & capacity',
    'programs' => 'Pre-ETS & group programs',
    'equipment' => 'Equipment & loaners',
    'procurement' => 'Device funding & procurement',
    'billing' => 'Invoices, exports & agency reports',
    'analytics' => 'Analytics dashboards',
    'templates' => 'Goal/assessment templates & service bundles',
    'admin' => 'User management, audit log, counselor portal',
];

$GLOBALS['CURRENT_PERMS'] = null;   // set by resolve_perms() at sign-in check
$GLOBALS['CURRENT_SCOPED'] = false;

/**
 * Resolve a user's permission set. Group membership wins; users without a
 * group keep legacy behavior (admin role = everything; instructor role =
 * everything except admin, unscoped) so existing accounts are unaffected
 * until a group is assigned.
 */
function resolve_perms(array $userRow): array {
    if (!empty($userRow['group_id'])) {
        $g = db()->prepare('SELECT * FROM `groups` WHERE id = ?');
        $g->execute([(int)$userRow['group_id']]);
        if ($group = $g->fetch()) {
            return [
                'perms' => array_values(array_filter(array_map('trim', explode(';', $group['perms'])))),
                'scoped' => (bool)$group['own_caseload_only'],
                'group' => $group['name'],
            ];
        }
    }
    $all = array_keys(PERMISSION_KEYS);
    if (($userRow['role'] ?? '') === 'admin') {
        return ['perms' => $all, 'scoped' => false, 'group' => null];
    }
    return ['perms' => array_values(array_diff($all, ['admin'])), 'scoped' => false, 'group' => null];
}

function can(string $perm): bool {
    return is_array($GLOBALS['CURRENT_PERMS']) && in_array($perm, $GLOBALS['CURRENT_PERMS'], true);
}

/** Whether the current user is limited to their own assigned caseload. */
function caseload_scoped(): bool {
    return (bool)$GLOBALS['CURRENT_SCOPED'];
}

/* ---------- App settings stored in the database ---------- */

/** Read one setting; null when unset (or the settings table doesn't exist yet). */
function setting(string $name): ?string {
    try {
        $s = db()->prepare('SELECT value FROM settings WHERE name = ?');
        $s->execute([$name]);
        $v = $s->fetchColumn();
        return $v === false ? null : (string)$v;
    } catch (Throwable $e) {
        return null;
    }
}

function set_setting(string $name, string $value): void {
    db()->prepare('INSERT INTO settings (name, value) VALUES (?,?) ON DUPLICATE KEY UPDATE value = VALUES(value)')
        ->execute([$name, $value]);
}

/**
 * Entra ID (Azure AD) federation config, or null when SSO is not enabled.
 * Settings saved on the Federation admin page (database) take precedence;
 * an 'entra' block in config.php remains as a fallback for existing installs.
 */
function entra_cfg(): ?array {
    global $CONFIG;
    static $resolved = false, $cached = null;
    if ($resolved) return $cached;
    $resolved = true;

    $e = is_array($CONFIG['entra'] ?? null) ? $CONFIG['entra'] : [];
    try {
        $rows = db()->query("SELECT name, value FROM settings WHERE name LIKE 'entra\\_%'")
            ->fetchAll(PDO::FETCH_KEY_PAIR);
    } catch (Throwable $x) {
        $rows = [];
    }
    foreach (['enabled', 'tenant_id', 'client_id', 'client_secret', 'auto_provision_group'] as $k) {
        if (isset($rows['entra_' . $k]) && ($k === 'auto_provision_group' || $rows['entra_' . $k] !== '')) {
            $e[$k] = $rows['entra_' . $k];
        }
    }
    if (isset($rows['entra_enabled'])) $e['enabled'] = $rows['entra_enabled'] === '1';

    if (empty($e['enabled']) || empty($e['tenant_id']) || empty($e['client_id']) || empty($e['client_secret'])) {
        return $cached = null;
    }
    return $cached = $e;
}

/** Effective Entra values for the admin form, ignoring the enabled flag. */
function entra_form_values(): array {
    global $CONFIG;
    $e = is_array($CONFIG['entra'] ?? null) ? $CONFIG['entra'] : [];
    try {
        $rows = db()->query("SELECT name, value FROM settings WHERE name LIKE 'entra\\_%'")
            ->fetchAll(PDO::FETCH_KEY_PAIR);
    } catch (Throwable $x) {
        $rows = [];
    }
    $out = [
        'enabled' => !empty($e['enabled']),
        'tenant_id' => (string)($e['tenant_id'] ?? ''),
        'client_id' => (string)($e['client_id'] ?? ''),
        'client_secret' => (string)($e['client_secret'] ?? ''),
        'auto_provision_group' => (string)($e['auto_provision_group'] ?? ''),
    ];
    foreach (['tenant_id', 'client_id', 'client_secret'] as $k) {
        if (($rows['entra_' . $k] ?? '') !== '') $out[$k] = $rows['entra_' . $k];
    }
    if (isset($rows['entra_enabled'])) $out['enabled'] = $rows['entra_enabled'] === '1';
    if (isset($rows['entra_auto_provision_group'])) $out['auto_provision_group'] = $rows['entra_auto_provision_group'];
    return $out;
}

/* ---------- Tasks & compliance timers ---------- */

/** The date N business days (Mon–Fri) from today. */
function business_days_from(int $days): string {
    $ts = time();
    $added = 0;
    while ($added < $days) {
        $ts += 86400;
        if ((int)date('N', $ts) < 6) $added++;
    }
    return date('Y-m-d', $ts);
}

/** Mark a system task done by its auto key (e.g. when the event it tracked happens). */
function complete_task_by_key(string $autoKey, ?int $userId): void {
    try {
        db()->prepare("UPDATE tasks SET status = 'Done', done_at = NOW(), done_by = ? WHERE auto_key = ? AND status = 'Open'")
            ->execute([$userId, $autoKey]);
    } catch (Throwable $e) {
        error_log('complete_task failed: ' . get_class($e) . ' ' . $e->getCode() . ' at ' . basename($e->getFile()) . ':' . $e->getLine());
    }
}

/**
 * Store a client signature image (base64 PNG from the app) in the private
 * upload dir. Returns the stored filename, or null when absent/invalid.
 */
function save_signature_png(?string $b64): ?string {
    if ($b64 === null || $b64 === '') return null;
    if (strlen($b64) > 700000) return null;   // cap BEFORE decoding (≈500KB binary)
    $bin = base64_decode($b64, true);
    if ($bin === false || strlen($bin) < 100 || strlen($bin) > 500 * 1024) return null;
    if (!str_starts_with($bin, "\x89PNG\r\n\x1a\n")) return null;
    $dir = cfg('upload_dir');
    if (!is_dir($dir)) mkdir($dir, 0750, true);
    $name = 'sig_' . bin2hex(random_bytes(16)) . '.png';
    return file_put_contents($dir . '/' . $name, $bin) !== false ? $name : null;
}

/* ---------- In-app notifications ---------- */

function notify_user(int $userId, string $message, ?string $link = null): void {
    try {
        db()->prepare('INSERT INTO notifications (user_id, message, link) VALUES (?,?,?)')
            ->execute([$userId, mb_substr($message, 0, 255), $link]);
    } catch (Throwable $e) {
        error_log('notify_user failed: ' . get_class($e) . ' ' . $e->getCode() . ' at ' . basename($e->getFile()) . ':' . $e->getLine());
    }
}

/**
 * Ids of active users whose EFFECTIVE permissions include 'admin', by the same
 * rule as resolve_perms(): the permission group wins when the user has one
 * (exact 'admin' token in its perms), otherwise the legacy role decides. A
 * group limited to its own caseload never counts, even with 'admin' listed or
 * a leftover role of admin, so org-wide notices never reach scoped staff.
 */
function effective_admin_user_ids(): array {
    $ids = db()->query("SELECT u.id FROM users u LEFT JOIN `groups` g ON g.id = u.group_id
        WHERE u.is_active = 1 AND IF(g.id IS NULL, u.role = 'admin',
            g.own_caseload_only = 0 AND CONCAT(';', REPLACE(g.perms, ' ', ''), ';') LIKE '%;admin;%')
        ORDER BY u.id")->fetchAll(PDO::FETCH_COLUMN);
    return array_map('intval', $ids);
}

/** One in-app notification per active admin. */
function notify_admin_users(string $message, ?string $link = null): void {
    foreach (effective_admin_user_ids() as $adminId) {
        notify_user($adminId, $message, $link);
    }
}

/* ---------- Editable email templates ---------- */

// Default subject/body per template key; {placeholders} are swapped at send
// time. Admins can override any of these on the Email templates page.
const EMAIL_TEMPLATES = [
    'referral_closed' => ['Referral outcome to the referrer', 'Your referral {ref} to {org_short}',
        "Hello,\n\nThank you for your referral {ref}, received on {date}.\n\nWe were not able to accept it: {reason}.\n\nNo client details are included in this email. If you have questions, reply to this email or contact {org}.\n",
        '{ref} {date} {reason} {org_short} {org}'],
    'otp' => ['Sign-in codes', 'Your sign-in code: {code}',
        "Hello {name},\n\nYour one-time sign-in code for {app} is:\n\n{code}\n\nThis code expires in {minutes} minutes. If you did not try to sign in, you can ignore this email.\n",
        '{name} {code} {app} {minutes}'],
    'portal_link' => ['Client portal invitation', 'Your {org_short} training portal',
        "Hello {name},\n\nHere is your personal link to view your upcoming training sessions, goals, and resources:\n\n{link}\n\nKeep this link private — anyone with it can view your training page. It keeps working as long as you open it at least once every six months. If you lose it, your instructor can send a new one (which replaces this link).\n",
        '{name} {link} {org_short} {org}'],
    'consent_request' => ['Guardian consent request', 'A consent form to sign — {org_short}',
        "Hello {name},\n\n{client} has been referred to {org} for services. Please review and sign the consent form at this private link:\n\n{link}\n\nIt takes about a minute. Thank you!\n",
        '{name} {client} {link} {org_short} {org}'],
    'release_request' => ['Release of information request', 'A release form to sign — {org_short}',
        "Hello {name},\n\n{org} would like your permission to share some of your training information. Please read the release form at this private link. It names who receives the information, what is shared and why, and you can sign it there:\n\n{link}\n\nYou do not have to sign it, and you can withdraw it later. If you have questions, reply to this email.\n",
        '{name} {link} {org_short} {org}'],
    'message_notice' => ['New message notice (client or counselor)', 'You have a new message from {org_short}',
        "Hello {name},\n\nYou have a new message from {org}. To read it, open your portal:\n\n{link}\n\nFor your privacy, the message itself is not in this email.\n",
        '{name} {link} {org_short} {org}'],
    'request_closed' => ['Portal request answered (client)', 'Your request to {org_short} was answered',
        "Hello {name},\n\nWe answered a request you sent from your portal. To see the answer, open your portal:\n\n{link}\n",
        '{name} {link} {org_short} {org}'],
    'portal_expiring' => ['Portal link about to expire', 'Your {org_short} portal link expires on {date}',
        "Hello {name},\n\nYour portal link has not been used for a while and stops working on {date}. To keep it working, open it before then:\n\n{link}\n\nIf you no longer need it, you can ignore this email.\n",
        '{name} {link} {date} {org_short} {org}'],
    'survey_invite' => ['Outcome survey invitation', 'How was your training with {org_short}?',
        "Hello {name},\n\nCongratulations on completing your assistive technology training!\n\nWe'd love your feedback — three quick questions, under a minute:\n\n{link}\n\nThank you for training with {org}.\n",
        '{name} {link} {org_short} {org}'],
    'vrc_code' => ['Counselor portal access code', 'Your portal access code: {code}',
        "Hello {name},\n\nYour access code for the {org_short} counselor portal is:\n\n{code}\n\nIt expires in 10 minutes.\n",
        '{name} {code} {org_short}'],
    'session_reminder_client' => ['Session reminder (client)', 'Reminder: your assistive technology training session {day}',
        "Hello {name},\n\nThis is a friendly reminder of your training session with {org}.\n\nWhen: {when}{where}\nInstructor: {instructor}\n\nIf you need to reschedule, please reply to this email or call your instructor.\n\nSee you {day}!\n",
        '{name} {when} {where} {instructor} {org} {day} {date} {time} {location} {join_link} {portal_link} {contact}'],
    'report_cover' => ['Report email closing note', '',
        "Please confirm you received this report (one click):\n{ack}\n",
        '{ack}'],
    // Spanish versions of the emails a client or guardian receives, with the same
    // placeholders. email_template_for_client() sends one when the client's
    // preferred language is Spanish and the English wording was not customised.
    'portal_link_es' => ['Client portal invitation (Spanish)', 'Su portal de capacitación de {org_short}',
        "Hola, {name}:\n\nEste es su enlace personal para ver sus próximas sesiones de capacitación, sus metas y sus recursos:\n\n{link}\n\nMantenga este enlace en privado: cualquier persona que lo tenga puede ver su página de capacitación. Sigue funcionando mientras lo abra al menos una vez cada seis meses. Si lo pierde, su instructor puede enviarle uno nuevo (que reemplaza a este).\n",
        '{name} {link} {org_short} {org}'],
    'consent_request_es' => ['Guardian consent request (Spanish)', 'Un formulario de consentimiento para firmar — {org_short}',
        "Hola, {name}:\n\n{client} fue referido/a a {org} para recibir servicios. Revise y firme el formulario de consentimiento en este enlace privado:\n\n{link}\n\nToma alrededor de un minuto. ¡Gracias!\n",
        '{name} {client} {link} {org_short} {org}'],
    'release_request_es' => ['Release of information request (Spanish)', 'Un formulario de autorización para firmar — {org_short}',
        "Hola, {name}:\n\n{org} quisiera su permiso para compartir parte de la información de su capacitación. Lea el formulario de autorización en este enlace privado. Dice quién recibe la información, qué se comparte y por qué, y puede firmarlo allí:\n\n{link}\n\nNo tiene que firmarlo, y puede retirarlo más adelante. Si tiene preguntas, responda a este correo.\n",
        '{name} {link} {org_short} {org}'],
    'message_notice_es' => ['New message notice (client, Spanish)', 'Tiene un mensaje nuevo de {org_short}',
        "Hola, {name}:\n\nTiene un mensaje nuevo de {org}. Para leerlo, abra su portal:\n\n{link}\n\nPor su privacidad, el mensaje no viene en este correo.\n",
        '{name} {link} {org_short} {org}'],
    'request_closed_es' => ['Portal request answered (client, Spanish)', 'Respondimos su solicitud a {org_short}',
        "Hola, {name}:\n\nRespondimos una solicitud que envió desde su portal. Para ver la respuesta, abra su portal:\n\n{link}\n",
        '{name} {link} {org_short} {org}'],
    'portal_expiring_es' => ['Portal link about to expire (Spanish)', 'Su enlace al portal de {org_short} vence el {date}',
        "Hola, {name}:\n\nHace tiempo que no usa su enlace al portal, y dejará de funcionar el {date}. Para que siga funcionando, ábralo antes de esa fecha:\n\n{link}\n\nSi ya no lo necesita, puede ignorar este correo.\n",
        '{name} {link} {date} {org_short} {org}'],
    'survey_invite_es' => ['Outcome survey invitation (Spanish)', '¿Cómo fue su capacitación con {org_short}?',
        "Hola, {name}:\n\n¡Felicidades por completar su capacitación en tecnología de asistencia!\n\nNos gustaría conocer su opinión: tres preguntas rápidas, en menos de un minuto:\n\n{link}\n\nGracias por capacitarse con {org}.\n",
        '{name} {link} {org_short} {org}'],
    'session_reminder_client_es' => ['Session reminder (client, Spanish)', 'Recordatorio: su sesión de capacitación en tecnología de asistencia {day}',
        "Hola, {name}:\n\nLe recordamos su sesión de capacitación con {org}.\n\nCuándo: {when}{where}\nInstructor: {instructor}\n\nSi necesita cambiar la fecha u hora, responda a este correo o llame a su instructor.\n\n¡Nos vemos {day}!\n",
        '{name} {when} {where} {instructor} {org} {day} {date} {time} {location} {join_link} {portal_link} {contact}'],
];

// The placeholder each body must keep to deliver what the email exists for
// (a sign-in code without {code} locks people out). The Email templates page
// refuses to save a body without it; a body stored before that check was added
// falls back to the built-in wording instead.
const EMAIL_TEMPLATE_REQUIRED = [
    'otp' => '{code}', 'vrc_code' => '{code}',
    'portal_link' => '{link}', 'consent_request' => '{link}', 'survey_invite' => '{link}',
    'portal_link_es' => '{link}', 'consent_request_es' => '{link}', 'survey_invite_es' => '{link}',
    'release_request' => '{link}', 'release_request_es' => '{link}',
    'message_notice' => '{link}', 'message_notice_es' => '{link}', 'request_closed' => '{link}', 'request_closed_es' => '{link}',
    'portal_expiring' => '{link}', 'portal_expiring_es' => '{link}',
    'report_cover' => '{ack}',
    'referral_closed' => '{ref}',
];

/** Render a template: admin override from settings, else the built-in default. */
function email_template(string $key, array $vars): array {
    $def = EMAIL_TEMPLATES[$key] ?? null;
    if (!$def) return ['', ''];
    $subject = setting('tpl_' . $key . '_subject');
    $body = setting('tpl_' . $key . '_body');
    $subject = ($subject !== null && trim($subject) !== '') ? $subject : $def[1];
    $body = ($body !== null && trim($body) !== '') ? $body : $def[2];
    if (isset(EMAIL_TEMPLATE_REQUIRED[$key]) && !str_contains($body, EMAIL_TEMPLATE_REQUIRED[$key])) $body = $def[2];
    $vars += ['app' => (string)cfg('name'), 'org' => brand('org'), 'org_short' => brand('short')];
    foreach ($vars as $k => $v) {
        $subject = str_replace('{' . $k . '}', (string)$v, $subject);
        $body = str_replace('{' . $k . '}', (string)$v, $body);
    }
    return [$subject, $body];
}

/** The language a client prefers: 'es' (Spanish) or 'en'. $client is the client's
 *  row (with preferred_language) or id. 'en' for a database without the column
 *  (mig_3.0.27 not run yet) or an unknown client. */
function client_lang(array|int $client): string {
    try {
        if (is_int($client)) {
            $q = db()->prepare('SELECT preferred_language FROM clients WHERE id = ?');
            $q->execute([$client]);
            $lang = $q->fetchColumn();
        } else {
            $lang = $client['preferred_language'] ?? null;
            if ($lang === null && isset($client['id'])) return client_lang((int)$client['id']);
        }
        return $lang === 'es' ? 'es' : 'en';
    } catch (Throwable $e) {
        return 'en';
    }
}

/** Render the email a client (or their guardian) receives, in the client's language:
 *  the Spanish template ({key}_es) when the client prefers Spanish and the
 *  administrator has not customised the English one (a customised English wording
 *  is theirs for every client, so it is never swapped away); otherwise the English
 *  one. Same placeholders either way. */
function email_template_for_client(string $key, array|int $client, array $vars): array {
    if (client_lang($client) === 'es' && isset(EMAIL_TEMPLATES[$key . '_es'])) {
        $customSubject = trim((string)setting('tpl_' . $key . '_subject')) !== '';
        $customBody = trim((string)setting('tpl_' . $key . '_body')) !== '';
        if (!$customSubject && !$customBody) return email_template($key . '_es', $vars);
    }
    return email_template($key, $vars);
}

/** The language of a converted referral, carried to its client: a referral sent on
 *  the Spanish public form makes a Spanish-speaking client. For referrals.php to
 *  call right after the client row is inserted: apply_referral_language($clientId, $ref);
 *  Nothing happens for a database without the columns (mig_3.0.27 not run yet). */
function apply_referral_language(int $clientId, array $ref): void {
    if (($ref['preferred_language'] ?? 'en') !== 'es') return;
    try {
        db()->prepare("UPDATE clients SET preferred_language = 'es' WHERE id = ?")->execute([$clientId]);
    } catch (Throwable $e) {
        error_log('apply_referral_language: ' . get_class($e));
    }
}

/** A date and time in Spanish, for Spanish emails and dialogs (an English date read
 *  by a Spanish voice is mangled): "martes, 3 de marzo a las 2:00 p. m. CDT".
 *  $withTime false gives the date alone: "martes, 3 de marzo". */
function when_es(int $ts, bool $withTime = true): string {
    $days = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
    $months = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
    $date = $days[(int)date('w', $ts)] . ', ' . (int)date('j', $ts) . ' de ' . $months[(int)date('n', $ts) - 1];
    return $withTime ? $date . ' a las ' . time_es($ts) : $date;
}
/** A time of day in Spanish with its zone: "2:00 p. m. CDT". */
function time_es(int $ts): string {
    return date('g:i', $ts) . (date('A', $ts) === 'AM' ? ' a. m. ' : ' p. m. ') . date('T', $ts);
}

/** The support phone an administrator set (setting support_phone), or ''. */
function support_phone(): string {
    return trim((string)setting('support_phone'));
}
/** "Call {phone} or email {email}" (HTML, the words in the page language) once an
 *  administrator has set a support phone; '' while there is none, so each page
 *  keeps its email-only wording. $capital false gives "call …" mid-sentence. */
function support_contact_html(bool $capital = true): string {
    $phone = support_phone();
    if ($phone === '') return '';
    $email = trim((string)(cfg('support_email') ?: cfg('mail_from')));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $email = '';
    $word = fn(string $s): string => function_exists('__') ? h(__($s)) : h($s);
    $phoneHtml = '<a href="tel:' . h(preg_replace('/[^0-9+]/', '', $phone) ?: $phone) . '">' . h($phone) . '</a>';
    return $word($capital ? 'Call' : 'call') . ' ' . $phoneHtml
        . ($email !== '' ? ' ' . $word('or email') . ' <a href="mailto:' . h($email) . '">' . h($email) . '</a>' : '');
}

/* ---------- Trash (30-day undo for deletions) ---------- */

/**
 * Soft delete: snapshot the row into the trash, then delete it. Restorable
 * for 30 days from the Trash page; the nightly cron purges older entries.
 */
function trash_row(string $table, int $id, ?int $userId, string $label): bool {
    if (!in_array($table, ['work_logs', 'track_expenses', 'client_documents', 'client_notes'], true)) return false;
    $billable = in_array($table, ['work_logs', 'track_expenses'], true);
    $pdo = db();
    $pdo->beginTransaction();
    try {
        // Match invoice generation's lock order before examining billable work.
        if ($billable) {
            $q = $pdo->prepare("SELECT track_id FROM {$table} WHERE id=?");
            $q->execute([$id]);
            $trackId = $q->fetchColumn();
            if ($trackId) {
                $q = $pdo->prepare('SELECT id FROM training_tracks WHERE id=? FOR UPDATE');
                $q->execute([$trackId]);
            }
        }
        $s = $pdo->prepare("SELECT * FROM {$table} WHERE id = ? FOR UPDATE");
        $s->execute([$id]);
        $row = $s->fetch();
        // Work or an expense on an invoice stays put: the invoice totals would no longer match.
        if (!$row || ($billable && $row['is_billed'])) {
            $pdo->rollBack();
            return false;
        }
        $pdo->prepare('INSERT INTO trash (entity, entity_id, label, payload, deleted_by) VALUES (?,?,?,?,?)')
            ->execute([$table, $id, mb_substr($label, 0, 190), json_encode($row, JSON_THROW_ON_ERROR), $userId]);
        $pdo->prepare("DELETE FROM {$table} WHERE id = ?")->execute([$id]);
        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/** Restore and cleanup share the trash-row lock; neither can consume it twice. */
function restore_trash(int $id): ?array {
    $pdo = db(); $pdo->beginTransaction();
    try {
        $q = $pdo->prepare('SELECT * FROM trash WHERE id=? FOR UPDATE'); $q->execute([$id]);
        $row = $q->fetch();
        if (!$row) { $pdo->rollBack(); return null; }
        if (!in_array($row['entity'], ['work_logs','track_expenses','client_documents','client_notes'], true)) throw new DomainException('Unsupported trash record.');
        $payload = json_decode($row['payload'], true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($payload) || !$payload) throw new DomainException('Invalid trash record.');
        // An unbilled work log comes back at its track's CURRENT rates, as every
        // other unbilled log on the track was re-priced if the rate was corrected
        // while this one sat in the trash. The track is locked first, the order
        // generate_invoice() and track_edit.php use.
        $repriced = false;
        if ($row['entity'] === 'work_logs' && empty($payload['is_billed']) && isset($payload['track_id'])) {
            $tr = $pdo->prepare('SELECT hourly_rate, mileage_rate FROM training_tracks WHERE id = ? FOR UPDATE');
            $tr->execute([(int)$payload['track_id']]);
            if ($rates = $tr->fetch()) {
                foreach (['hourly_rate', 'mileage_rate'] as $rc) {
                    if (!array_key_exists($rc, $payload)) continue;
                    if ($payload[$rc] !== null && round((float)$payload[$rc], 3) !== round((float)$rates[$rc], 3)) $repriced = true;
                    $payload[$rc] = $rates[$rc];
                }
            }
        }
        // An unbilled log or expense comes back only inside its authorization (3.0.34):
        // restoring must not reach work the block refuses (hours or dollars used meanwhile,
        // an expired or closed track). The track is locked, as above.
        if (in_array($row['entity'], ['work_logs', 'track_expenses'], true) && empty($payload['is_billed']) && isset($payload['track_id'])) {
            $tl = $pdo->prepare('SELECT * FROM training_tracks WHERE id = ? FOR UPDATE');
            $tl->execute([(int)$payload['track_id']]);
            if ($trow = $tl->fetch()) {
                $isLog = $row['entity'] === 'work_logs';
                $h = $isLog ? (float)($payload['hours'] ?? 0) : 0.0;
                $amt = $isLog ? round($h * (float)$trow['hourly_rate'], 2) + round((float)($payload['mileage'] ?? 0) * (float)$trow['mileage_rate'], 2) : (float)($payload['amount'] ?? 0);
                $block = work_authorization_block($trow, (string)($isLog ? ($payload['work_date'] ?? '') : ($payload['expense_date'] ?? '')), $h, $amt);
                if ($block !== null) throw new DomainException('Not restored: ' . $block);
            }
        }
        $cols = array_keys($payload);
        foreach ($cols as $col) if (!is_string($col) || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $col)) throw new DomainException('Invalid trash column.');
        $sql = 'INSERT INTO '.$row['entity'].' ('.implode(',',array_map(fn($c)=>"`$c`",$cols)).') VALUES ('.implode(',',array_fill(0,count($cols),'?')).')';
        $pdo->prepare($sql)->execute(array_values($payload));
        $pdo->prepare('DELETE FROM trash WHERE id=?')->execute([$id]);
        $pdo->commit(); return $row + ['repriced' => $repriced];
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}

/** Delete the file only after consuming the trash entry, never a restored file. */
function purge_trash(int $id, bool $expiredOnly=false): ?array {
    $pdo = db(); $pdo->beginTransaction(); $path = null;
    try {
        $q = $pdo->prepare('SELECT * FROM trash WHERE id=?'.($expiredOnly?' AND deleted_at < DATE_SUB(NOW(), INTERVAL 30 DAY)':'').' FOR UPDATE');
        $q->execute([$id]); $row = $q->fetch();
        if (!$row) { $pdo->rollBack(); return null; }
        if ($row['entity']==='client_documents') {
            $payload = json_decode($row['payload'],true,512,JSON_THROW_ON_ERROR);
            $candidate = basename((string)($payload['path']??''));
            if ($candidate!=='') {
                $q = $pdo->prepare('SELECT id FROM client_documents WHERE path=? LIMIT 1'); $q->execute([$candidate]);
                $live = $q->fetchColumn();
                // Older releases could create duplicate trash snapshots.
                $q = $pdo->prepare("SELECT id FROM trash WHERE id<>? AND entity='client_documents' AND JSON_UNQUOTE(JSON_EXTRACT(payload,'$.path'))=? LIMIT 1");
                $q->execute([$id,$candidate]);
                if (!$live && !$q->fetchColumn()) $path = cfg('upload_dir').'/'.$candidate;
            }
        } elseif ($row['entity']==='work_logs') {
            // A purged log takes its client signature image with it, unless a live
            // log or another trash snapshot still points at the same file.
            $payload = json_decode($row['payload'],true,512,JSON_THROW_ON_ERROR);
            $candidate = basename((string)($payload['signature_path']??''));
            if ($candidate!=='' && str_starts_with($candidate, 'sig_')) {
                $q = $pdo->prepare('SELECT id FROM work_logs WHERE signature_path=? LIMIT 1'); $q->execute([$candidate]);
                $live = $q->fetchColumn();
                $q = $pdo->prepare("SELECT id FROM trash WHERE id<>? AND entity='work_logs' AND JSON_UNQUOTE(JSON_EXTRACT(payload,'$.signature_path'))=? LIMIT 1");
                $q->execute([$id,$candidate]);
                if (!$live && !$q->fetchColumn()) $path = cfg('upload_dir').'/'.$candidate;
            }
        }
        $pdo->prepare('DELETE FROM trash WHERE id=?')->execute([$id]);
        $pdo->commit();
        if ($path && is_file($path) && !unlink($path)) error_log('Could not remove a purged file.');
        return $row;
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}

/* ---------- Mail ---------- */
require __DIR__ . '/graph_mail.php';

/**
 * One plain-text message through Microsoft Graph, sent as graph_send_mail() sends
 * it (no Sent Items copy), with what is known of the outcome: 'sent' (HTTP 202);
 * 'refused' when Graph cannot have accepted it (no token, a connection that never
 * carried the request, a 4xx answer), so another route may be tried; 'unknown'
 * when the request went out and no answer came back, or Graph answered 5xx: it
 * may already be queued, and sending it another way could deliver it twice.
 */
function graph_send_text(string $to, string $subject, string $body): string {
    $g = graph_cfg();
    $token = $g ? graph_token() : null;
    if (!$g || !$token) return 'refused';
    $payload = json_encode(['message' => [
        'subject' => $subject,
        'body' => ['contentType' => 'Text', 'content' => $body],
        'toRecipients' => [['emailAddress' => ['address' => $to]]],
    ], 'saveToSentItems' => false], JSON_INVALID_UTF8_SUBSTITUTE);
    for ($try = 0; ; $try++) {
        $ch = curl_init('https://graph.microsoft.com/v1.0/users/' . rawurlencode($g['sender']) . '/sendMail');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => (string)$payload,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
            CURLOPT_TIMEOUT => 30,
        ]);
        curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        // Bytes of the request body that went out: none means the request never left.
        $uploaded = (float)curl_getinfo($ch, CURLINFO_SIZE_UPLOAD);
        // No curl_close(): the handle is freed with $ch (the call has done nothing
        // since PHP 8.0 and is deprecated from 8.5).
        // A refused token means the request was not carried out, so it is safe to resend.
        if ($try === 0 && ($fresh = graph_retry_token($code)) !== null) { $token = $fresh; continue; }
        break;
    }
    if ($code === 202) return 'sent';
    error_log("Graph sendMail failed with HTTP $code");
    return ($code >= 500 || ($code === 0 && $uploaded > 0)) ? 'unknown' : 'refused';
}

/** A cron job's heartbeat: the time of its last good run (heartbeat_<job>), read by
 *  health.php and System health. Never stops the job that records it. */
function record_heartbeat(string $job): void {
    try { set_setting('heartbeat_' . $job, date('c')); }
    catch (Throwable $e) { error_log($job . ': could not record the heartbeat (' . get_class($e) . ' ' . $e->getCode() . ')'); }
}

/** The last email sent and the last one that failed (System health), recorded once per
 *  request each, so a team notification to every user writes two settings at most.
 *  No address or subject is kept. */
function mail_outcome_note(bool $sent): void {
    static $noted = [];
    if (isset($noted[$sent])) return;
    $noted[$sent] = true;
    try { set_setting($sent ? 'mail_last_sent' : 'mail_last_failed', date('c')); } catch (Throwable $e) { /* never stops a send */ }
}

function send_app_mail(string $to, string $subject, string $body): bool {
    $sent = send_app_mail_now($to, $subject, $body);
    mail_outcome_note($sent);
    return $sent;
}

/** send_app_mail() without the outcome note. */
function send_app_mail_now(string $to, string $subject, string $body): bool {
    // Subjects and addresses can carry record data (names); newlines in them
    // would become extra SMTP headers through the mail() fallback.
    $to = trim(preg_replace('/[\r\n]+/', '', $to));
    $subject = preg_replace('/[\r\n]+/', ' ', $subject);
    // Exactly one valid address: a stored comma list must never fan out to
    // several recipients through mail(). No address in the log (retention).
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        error_log('send_app_mail refused a recipient that is not one valid email address.');
        return false;
    }
    // A staging copy (dist/make_staging.sh) sets $CONFIG['mail_disabled']: nothing
    // leaves the server. Each message is kept as a file in $CONFIG['mail_sink'] (a
    // private directory) so a tester can read a sign-in code there, and the caller is
    // told it was sent so the page behaves as in production.
    global $CONFIG;
    if (!empty($CONFIG['mail_disabled'])) {
        $sink = (string)($CONFIG['mail_sink'] ?? '');
        if ($sink !== '' && is_dir($sink) && is_writable($sink)) {
            @file_put_contents($sink . '/' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.txt',
                "To: {$to}\nSubject: {$subject}\nDate: " . date('c') . "\n\n{$body}\n");
        } else {
            error_log('send_app_mail: mail is disabled on this copy (mail_disabled); message to a recipient not sent.');
        }
        return true;
    }
    // Preferred path: Microsoft Graph (cmswebnotifications mailbox)
    if (graph_cfg() !== null) {
        $outcome = graph_send_text($to, $subject, $body);
        if ($outcome === 'sent') return true;
        // No recipient address: the server log is outside the app's retention controls.
        // A timeout or 5xx after the request went out may still be delivered by Graph,
        // so it is not sent a second time through mail(); the caller reports it unsent.
        if ($outcome === 'unknown') {
            error_log('Graph send outcome unknown (timeout or server error); not resent through PHP mail().');
            return false;
        }
        error_log('Graph send failed; falling back to PHP mail().');
    }
    $from = cfg('mail_from');
    $fromName = preg_replace('/[\r\n]+/', ' ', (string)cfg('mail_from_name'));
    // mail() sends header text as given, so non-ASCII (an em dash, an accented
    // client name) must be RFC 2047-encoded or some mail apps show it garbled.
    $nonAscii = fn(string $s): bool => (bool)preg_match('/[^\x20-\x7E]/', $s);
    if ($nonAscii($subject)) $subject = mb_encode_mimeheader($subject, 'UTF-8', 'B', "\r\n");
    if ($nonAscii($fromName)) $fromName = mb_encode_mimeheader($fromName, 'UTF-8', 'B', "\r\n");
    // An ASCII name with RFC 5322 specials (a comma in "Acme, Inc.") must be a
    // quoted-string, or the From header becomes a broken mailbox list.
    elseif (preg_match('/[()<>\[\]:;@\\\\,."]/', $fromName)) $fromName = '"' . addcslashes($fromName, '"\\') . '"';
    $headers = "From: {$fromName} <{$from}>\r\n"
             . "Reply-To: {$from}\r\n"
             . "MIME-Version: 1.0\r\n"
             . "Content-Type: text/plain; charset=UTF-8\r\n"
             . "Content-Transfer-Encoding: 8bit\r\n";
    return mail($to, $subject, $body, $headers, '-f' . $from);
}

/** Email every active user (team notifications). */
function notify_team(string $subject, string $body): void {
    foreach (db()->query('SELECT email FROM users WHERE is_active = 1') as $u) {
        send_app_mail($u['email'], $subject, $body);
    }
}

/** Email active admins only (e.g., new unassigned referrals). True if any send succeeded. */
function notify_admins(string $subject, string $body): bool {
    $ok = false;
    $ids = effective_admin_user_ids();
    if (!$ids) return false;
    $q = db()->prepare('SELECT email FROM users WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
    $q->execute($ids);
    foreach ($q->fetchAll() as $u) {
        if (send_app_mail($u['email'], $subject, $body)) $ok = true;
    }
    return $ok;
}

/* ---------- Formatting ---------- */
/** The mark for a missing date. Plain text (CSV, PDF, email, audit) keeps the bare
 *  dash; $html = true gives markup that is seen as a dash and spoken as "not set"
 *  (screen readers skip or mispronounce a lone dash). */
function no_date_mark(bool $html = false): string {
    return $html ? '<span aria-hidden="true">—</span><span class="visually-hidden">not set</span>' : '—';
}
function fmt_date(?string $d, bool $html = false): string {
    if (!$d) return no_date_mark($html);
    $ts = strtotime($d);
    return $ts ? date('M j, Y', $ts) : no_date_mark($html);
}
function fmt_datetime(?string $d, bool $html = false): string {
    if (!$d) return no_date_mark($html);
    $ts = strtotime($d);
    return $ts ? date('M j, Y g:i A', $ts) : no_date_mark($html);
}
function fmt_money(float $n): string { return '$' . number_format($n, 2); }
/** A per-mile rate, which carries three decimals (0.655): shown to the tenth of a
 *  cent so rate x miles matches the line amounts; a third decimal of 0 is dropped. */
function fmt_mileage_rate(float $n): string {
    $s = number_format($n, 3);
    return '$' . (str_ends_with($s, '0') ? substr($s, 0, -1) : $s);
}
/** Hours with the unit spelled out ("1 hour", "1.5 hours"): "hrs" is an
 *  unexpanded abbreviation that some speech synthesizers spell letter by letter. */
function fmt_hours(float $n): string {
    $v = rtrim(rtrim(number_format($n, 2), '0'), '.');
    return $v . (abs((float)str_replace(',', '', $v)) === 1.0 ? ' hour' : ' hours');
}
/** A typed name (a signature) compared the way a person reads it: Unicode-normalized
 *  when the intl extension is present, spaces collapsed, case-folded for every
 *  alphabet. Callers reject invalid UTF-8 (mb_check_encoding) before comparing. */
function name_key(string $s): string {
    if (class_exists('Normalizer')) $s = Normalizer::normalize($s, Normalizer::FORM_C) ?: $s;
    return mb_convert_case(preg_replace('/\s+/u', ' ', trim($s)) ?? trim($s), MB_CASE_FOLD, 'UTF-8');
}

/**
 * Assemble a case note from submitted fields. Structured formats (BIRP/PAIP/SOAP)
 * use four positional section slots, and text left in the narrative box follows
 * them as a final 'Narrative' part; 'free' is a plain narrative. Returns
 * ['type','format','body','cols'=>[4]]. Shared by the client and track note forms.
 */
function assemble_note(array $post): array {
    // A field posted as an array (name[]=) counts as blank, never a TypeError.
    $str = fn(string $k): string => is_string($post[$k] ?? null) ? trim($post[$k]) : '';
    $type = in_array($post['note_type'] ?? '', NOTE_TYPES, true) ? $post['note_type'] : 'Case Note';
    $fmt = isset(NOTE_FORMATS[$str('note_format')]) ? $str('note_format') : 'free';
    $sections = [$str('sec1'), $str('sec2'), $str('sec3'), $str('sec4')];
    $hasSections = $fmt !== 'free' && implode('', $sections) !== '';
    $body = $str('body');
    if ($hasSections) {
        $labels = NOTE_FORMATS[$fmt];
        $parts = [];
        foreach ($sections as $i => $v) if ($v !== '') $parts[] = $labels[$i] . ":\n" . $v;
        // Text still in the narrative box is kept after the sections, in the
        // format client.php's edit form reads back into the box.
        if ($body !== '') $parts[] = "Narrative:\n" . $body;
        $body = implode("\n\n", $parts);
    } else {
        $fmt = 'free';
    }
    // Compared with '' (not truthiness), so a section that is just "0" is kept.
    $cols = $hasSections ? array_map(fn($v) => $v !== '' ? $v : null, $sections) : [null, null, null, null];
    return ['type' => $type, 'format' => $fmt, 'body' => $body, 'cols' => $cols];
}

/**
 * The 'Narrative' part assemble_note() saved after a structured note's sections,
 * read back from a client_notes row ('' when there is none, or for a free-text
 * note). Same prefix rule as client.php's edit form. A save path that receives
 * only the sections (the app's update_note) passes this back as 'body' so the
 * narrative saved from the website is never dropped.
 */
function note_narrative(array $note): string {
    $labels = NOTE_FORMATS[$note['note_format'] ?? ''] ?? [];
    if (count($labels) !== 4) return '';
    $parts = [];
    foreach (['birp_behavior', 'birp_intervention', 'birp_response', 'birp_plan'] as $i => $k) {
        $v = (string)($note[$k] ?? '');
        if ($v !== '') $parts[] = $labels[$i] . ":\n" . $v;
    }
    if (!$parts) return '';
    $prefix = implode("\n\n", $parts) . "\n\nNarrative:\n";
    $body = (string)($note['body'] ?? '');
    return str_starts_with($body, $prefix) ? substr($body, strlen($prefix)) : '';
}

/* The note generator's homework rows as the form posts them back (title,
   instructions, due, and whether "Add this homework" is ticked), so they can be
   shown again after a failed save or regenerate. Non-text values are dropped.
   Shared by track.php and client.php. */
function posted_note_homework(array $post): array {
    if (!is_array($post['hw_title'] ?? null)) return [];
    $text = fn($a, $k) => (is_array($a) && is_string($a[$k] ?? null)) ? $a[$k] : '';
    $rows = [];
    foreach ($post['hw_title'] as $k => $title) {
        if (!is_string($title)) continue;
        $rows[] = ['title' => $title, 'instructions' => $text($post['hw_instructions'] ?? null, $k),
            'due' => $text($post['hw_due'] ?? null, $k), 'include' => is_array($post['hw_include'] ?? null) && !empty($post['hw_include'][$k])];
    }
    return array_slice($rows, 0, 10);
}

/** Create homework items captured by the note generator on a client's training
 * track, so they appear in the client's portal. Only items whose hw_include[k]
 * checkbox is set are created; the track must belong to the client. Returns the
 * number created. */
function save_note_homework(int $clientId, int $trackId, array $post, int $userId): int {
    if ($trackId <= 0 || empty($post['hw_title']) || !is_array($post['hw_title'])) return 0;
    $vt = db()->prepare('SELECT id FROM training_tracks WHERE id = ? AND client_id = ?');
    $vt->execute([$trackId, $clientId]);
    if (!$vt->fetchColumn()) return 0;
    $ins = db()->prepare('INSERT INTO homework (track_id, title, instructions, assigned_at, due_date, created_by) VALUES (?,?,?,CURDATE(),?,?)');
    $include = (array)($post['hw_include'] ?? []);
    $added = 0;
    // Runs after the note is written, so a malformed (non-text) field is skipped
    // rather than thrown: the user must never be told "nothing was saved" here.
    $instrs = is_array($post['hw_instructions'] ?? null) ? $post['hw_instructions'] : [];
    $dues = is_array($post['hw_due'] ?? null) ? $post['hw_due'] : [];
    foreach ($post['hw_title'] as $k => $t) {
        if (empty($include[$k]) || !is_string($t)) continue;
        $t = trim($t);
        if ($t === '') continue;
        $instr = is_string($instrs[$k] ?? null) ? trim($instrs[$k]) : '';
        // The years the standalone homework form allows; callers report a bad ticked
        // date before saving the note (so this only drops a value posted around them).
        $due = is_string($dues[$k] ?? null) ? valid_date($dues[$k], 2000, (int)date('Y') + 5) : null;
        $ins->execute([$trackId, mb_substr($t, 0, 190), $instr !== '' ? mb_substr($instr, 0, 1000) : null, $due, $userId]);
        $added++;
    }
    if ($added) audit($userId, 'homework.add', 'client', $clientId, $added . ' from case note');
    return $added;
}

/**
 * A one-time key for a web form that creates a record, so the same form sent
 * twice (Enter pressed again while the first POST is still loading) is saved
 * once. Each render gets a fresh key. A table with an idem_key column stores it,
 * as the app API does (a UNIQUE clash means "already saved"); for a table without
 * one the page remembers, in the session, the record a key created
 * (web_once_get() / web_once_set()).
 */
function web_idem_field(): string {
    $hex = strtoupper(bin2hex(random_bytes(16)));
    return '<input type="hidden" name="idem_key" value="' . substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-'
        . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20) . '">';
}
/** The key a web form posted, normalized as the app API stores it; null when it is
 *  missing (a page opened before this release) or malformed, which is simply saved. */
function web_idem_key(array $post): ?string {
    $k = $post['idem_key'] ?? null;
    return is_string($k) && preg_match('/^[0-9a-fA-F-]{36}$/D', $k) ? strtoupper($k) : null;
}
/** The record an earlier POST of the same form created in this browser session, else
 *  null. PHP's session file lock runs one browser's requests one at a time, so the
 *  check and the save cannot interleave. */
function web_once_get(?string $key): ?int {
    return $key !== null && isset($_SESSION['web_once'][$key]) ? (int)$_SESSION['web_once'][$key] : null;
}
function web_once_set(?string $key, int $id): void {
    if ($key === null) return;
    $_SESSION['web_once'][$key] = $id;
    // A double submit follows within seconds, so only the latest few are kept.
    $_SESSION['web_once'] = array_slice($_SESSION['web_once'], -20, null, true);
}

/** Validate a YYYY-MM-DD date from user input; null when blank or invalid.
 * $minYear/$maxYear refuse a typo'd year such as 0026 or 2062 on fields with a
 * known range (work and billing dates use 2000 to five years ahead, as the app's
 * api_date() does); leave them null for dates such as a date of birth.
 * Anything that is not a string (an array from ?from[]=x, say) is invalid. */
function valid_date(mixed $d, ?int $minYear = null, ?int $maxYear = null): ?string {
    // A query string such as ?from[]=x arrives as an array: invalid, not a TypeError.
    if (!is_string($d) && $d !== null) return null;
    $d = trim((string)$d);
    if ($d === '') return null;
    $x = DateTime::createFromFormat('Y-m-d', $d);
    if (!$x || $x->format('Y-m-d') !== $d) return null;
    $y = (int)$x->format('Y');
    if (($minYear !== null && $y < $minYear) || ($maxYear !== null && $y > $maxYear)) return null;
    return $d;
}

/**
 * One work log's billable amounts, each part rounded to the cent (half up)
 * exactly as track_rollup(), unbilled_totals() and generate_invoice() round
 * each line in SQL, so the lines printed on an invoice, statement or export
 * always add up to its totals. A log without a rate snapshot is priced at the
 * fallback rates given (an invoice's captured rates, else the track's current
 * ones). Returns ['hours' => float, 'mileage' => float, 'total' => float].
 */
function log_line_amounts(array $log, float $fallbackRate, float $fallbackMileageRate): array {
    $rate = $log['hourly_rate'] ?? null;
    $mileageRate = $log['mileage_rate'] ?? null;
    // Whole units of 1/10,000 dollar (hours and hourly rates have 2 decimals,
    // miles 1 and mileage rates 3), so no floating-point drift decides a cent.
    $h = (int)round((float)$log['hours'] * 100) * (int)round((float)($rate ?? $fallbackRate) * 100);
    $m = (int)round((float)($log['mileage'] ?? 0) * 10) * (int)round((float)($mileageRate ?? $fallbackMileageRate) * 1000);
    $hc = intdiv($h + 50, 100);
    $mc = intdiv($m + 50, 100);
    return ['hours' => $hc / 100.0, 'mileage' => $mc / 100.0, 'total' => ($hc + $mc) / 100.0];
}

/**
 * Billing rollups for one training track.
 * Each work log carries the rate in force when it was entered
 * (work_logs.hourly_rate/mileage_rate); older logs without a snapshot
 * fall back to the track's current rate. Every log line is rounded to the
 * cent before summing (see log_line_amounts()).
 * - total_billed / billed_amount / mileage_amount: the value of ALL logged
 *   work (hours, mileage), whether or not it is on an invoice yet;
 * - invoiced_amount: what invoices for this track total (work and expenses),
 *   Drafts included, since a Draft already takes its work off "not yet invoiced";
 * - submitted_amount: the part of that on invoices Submitted to the funder or
 *   Paid (what the AT funding record calls invoiced);
 * - unbilled_*: logged work and expenses not on any invoice yet;
 * - billable_*: the part of that dated today or earlier, which is exactly what
 *   generate_invoice() bills now (work dated ahead waits for its date);
 * - funds_remaining: authorized funds less all logged work AND expenses,
 *   since expenses are billed against the same authorization.
 */
function track_rollup(int $trackId): array {
    $t = db()->prepare('SELECT * FROM training_tracks WHERE id = ?');
    $t->execute([$trackId]);
    $track = $t->fetch() ?: [];
    $authHours = (float)($track['authorized_hours'] ?? 0);
    $rate = (float)($track['hourly_rate'] ?? 0);
    $mileageRate = (float)($track['mileage_rate'] ?? 0);
    $hAmt = 'ROUND(w.hours * COALESCE(w.hourly_rate, t.hourly_rate), 2)';
    $mAmt = 'ROUND(w.mileage * COALESCE(w.mileage_rate, t.mileage_rate), 2)';
    $today = date('Y-m-d');
    $w = db()->prepare("SELECT COALESCE(SUM(w.hours),0) AS worked,
        COALESCE(SUM(CASE WHEN w.is_billed=0 THEN w.hours ELSE 0 END),0) AS unbilled,
        COALESCE(SUM(w.mileage),0) AS mileage,
        COALESCE(SUM(CASE WHEN w.is_billed=0 THEN w.mileage ELSE 0 END),0) AS unbilled_mileage,
        COALESCE(SUM($hAmt),0) AS hours_amount,
        COALESCE(SUM($mAmt),0) AS mileage_amount,
        COALESCE(SUM(CASE WHEN w.is_billed=0 THEN $hAmt + $mAmt ELSE 0 END),0) AS unbilled_amount,
        COALESCE(SUM(CASE WHEN w.is_billed=0 AND w.work_date <= ? THEN w.hours ELSE 0 END),0) AS billable_hours,
        COALESCE(SUM(CASE WHEN w.is_billed=0 AND w.work_date <= ? THEN w.mileage ELSE 0 END),0) AS billable_mileage,
        COALESCE(SUM(CASE WHEN w.is_billed=0 AND w.work_date <= ? THEN $hAmt + $mAmt ELSE 0 END),0) AS billable_amount
        FROM work_logs w JOIN training_tracks t ON t.id = w.track_id WHERE w.track_id = ?");
    $w->execute([$today, $today, $today, $trackId]);
    $sums = $w->fetch();
    $e = db()->prepare('SELECT COALESCE(SUM(amount),0) AS total, COALESCE(SUM(CASE WHEN is_billed=0 THEN amount ELSE 0 END),0) AS unbilled,
        COALESCE(SUM(CASE WHEN is_billed=0 AND expense_date <= ? THEN amount ELSE 0 END),0) AS billable
        FROM track_expenses WHERE track_id = ?');
    $e->execute([$today, $trackId]);
    $exp = $e->fetch();
    // Denied and void invoices stay on record, but their work was released to be billed again.
    $inv = db()->prepare("SELECT COALESCE(SUM(CASE WHEN status NOT IN ('Denied','Void') THEN total_amount ELSE 0 END),0) AS total,
        COALESCE(SUM(CASE WHEN status IN ('Submitted','Paid') THEN total_amount ELSE 0 END),0) AS submitted
        FROM invoices WHERE track_id = ?");
    $inv->execute([$trackId]);
    $invoiced = $inv->fetch();
    $worked = (float)$sums['worked'];
    $mileage = (float)$sums['mileage'];
    $hoursAmount = (float)$sums['hours_amount'];
    $mileageAmount = (float)$sums['mileage_amount'];
    $expensesAmount = (float)$exp['total'];
    // The dollar limit: the authorization's own amount when one was entered (3.0.34),
    // else the authorized hours at the hourly rate. Overruns are reported, signed, by
    // hours_over and funds_over (positive only when over).
    $authFunds = $track && $track['authorized_amount'] !== null ? (float)$track['authorized_amount'] : $authHours * $rate;
    $used = round($hoursAmount + $mileageAmount + $expensesAmount, 2);
    return [
        'billing_mode' => (string)($track['billing_mode'] ?? 'vr'),
        'hours_over' => max(0.0, round($worked - $authHours, 2)),
        'funds_over' => max(0.0, round($used - $authFunds, 2)),
        'authorized_hours' => $authHours,
        'hourly_rate' => $rate,
        'mileage_rate' => $mileageRate,
        'hours_worked' => $worked,
        'hours_remaining' => max(0.0, $authHours - $worked),
        'billed_amount' => $hoursAmount,
        'mileage_amount' => $mileageAmount,
        'total_billed' => round($hoursAmount + $mileageAmount, 2),
        'expenses_amount' => $expensesAmount,
        'invoiced_amount' => (float)$invoiced['total'],
        'submitted_amount' => (float)$invoiced['submitted'],
        'authorized_funds' => $authFunds,
        'funds_remaining' => max(0.0, round($authFunds - $used, 2)),
        'unbilled_hours' => (float)$sums['unbilled'],
        'unbilled_mileage' => (float)$sums['unbilled_mileage'],
        'unbilled_amount' => (float)$sums['unbilled_amount'],
        'unbilled_expenses' => (float)$exp['unbilled'],
        'billable_hours' => (float)$sums['billable_hours'],
        'billable_mileage' => (float)$sums['billable_mileage'],
        'billable_amount' => (float)$sums['billable_amount'],
        'billable_expenses' => (float)$exp['billable'],
        'mileage' => $mileage,
        'pct_used' => $authHours > 0 ? min(100.0, round($worked / $authHours * 100, 1)) : 0.0,
    ];
}

/**
 * Logged work and expenses not yet on any invoice, across every track, or only
 * the caseload of $scopeUserId (clients assigned to that user) when given.
 * service_amount is hours plus mileage at each log's snapshot rate, rounded per
 * line exactly as generate_invoice() will bill it. Like generate_invoice(), only
 * entries dated today or earlier count (work dated ahead waits for its date).
 * Shared by the dashboard, the Invoices page and the app dashboard so all three agree.
 * Returns ['hours', 'service_amount', 'expense_amount', 'total'] as floats.
 */
function unbilled_totals(?int $scopeUserId): array {
    $scope = $scopeUserId !== null ? ' AND c.assigned_to = ?' : '';
    $args = array_merge([date('Y-m-d')], $scopeUserId !== null ? [$scopeUserId] : []);
    $w = db()->prepare('SELECT COALESCE(SUM(w.hours),0) AS h,
        COALESCE(SUM(ROUND(w.hours * COALESCE(w.hourly_rate, t.hourly_rate), 2)
            + ROUND(w.mileage * COALESCE(w.mileage_rate, t.mileage_rate), 2)),0) AS a
        FROM work_logs w JOIN training_tracks t ON t.id = w.track_id JOIN clients c ON c.id = t.client_id
        WHERE w.is_billed = 0 AND w.work_date <= ? AND t.billing_mode <> \'none\'' . $scope);
    $w->execute($args);
    $work = $w->fetch();
    $e = db()->prepare('SELECT COALESCE(SUM(e.amount),0) FROM track_expenses e
        JOIN training_tracks t ON t.id = e.track_id JOIN clients c ON c.id = t.client_id
        WHERE e.is_billed = 0 AND e.expense_date <= ? AND t.billing_mode <> \'none\'' . $scope);
    $e->execute($args);
    $service = (float)$work['a'];
    $expense = (float)$e->fetchColumn();
    return ['hours' => (float)$work['h'], 'service_amount' => $service, 'expense_amount' => $expense,
        'total' => round($service + $expense, 2)];
}

/**
 * The date a track's progress report is due, shared by the compliance page, the
 * app's compliance view and cron/monthly_reports.php so all three agree. $since
 * is the last report date or, before the first report, the later of the track's
 * start date and the day it was entered. Due one cadence period (weekly 7,
 * biweekly 14, monthly 28 days) after $since; a monthly report keeps the cron's
 * 1st-of-month rhythm: the first 1st of a month on or after that, or 45 days
 * after $since if that comes sooner. Returns YYYY-MM-DD.
 */
function report_due_date(string $cadence, string $since): string {
    $gap = ['weekly' => 7, 'biweekly' => 14, 'monthly' => 28][$cadence] ?? 28;
    $since = substr($since, 0, 10);
    $due = date('Y-m-d', strtotime($since . ' +' . $gap . ' days'));
    if ($cadence === 'monthly' && substr($due, 8, 2) !== '01') {
        $due = min(date('Y-m-d', strtotime('first day of next month', strtotime($due))),
            date('Y-m-d', strtotime($since . ' +45 days')));
    }
    return $due;
}

function record_status_change(int $clientId, ?string $old, string $new, ?int $userId, ?string $note = null): void {
    if ($old === $new) return;
    // A leading '[merged]' marks history carried over from a merged duplicate,
    // which the reports leave out. The forms refuse such a note with a field
    // error; as a backstop the marker is never stored on a new row.
    if ($note !== null) {
        $note = preg_replace('/^(\s*\[merged\])+\s*/i', '', $note);
        if (trim($note) === '') $note = null;
    }
    $s = db()->prepare('INSERT INTO client_status_history (client_id, old_status, new_status, changed_by, note) VALUES (?,?,?,?,?)');
    $s->execute([$clientId, $old, $new, $userId, $note]);
    // A closed case needs no more assessment, homework-support or waitlist
    // follow-ups: its open system tasks are completed with the status change
    // (callers run this inside their transaction). Staff-made tasks stay open.
    if (str_starts_with($new, 'Closed')) {
        db()->prepare("UPDATE tasks SET status = 'Done', done_at = NOW(), done_by = ? WHERE client_id = ? AND status = 'Open'
            AND (auto_key LIKE 'assess-%' OR auto_key LIKE 'practice:%' OR auto_key LIKE 'wait-%')")
            ->execute([$userId, $clientId]);
    }
}

/** Email with attachments — Graph only (no fallback). */
function send_app_mail_attach(string $to, string $subject, string $body, array $attachments): bool {
    $sent = graph_cfg() !== null && graph_send_mail($to, $subject, $body, false, null, $attachments);
    mail_outcome_note($sent);
    return $sent;
}

/** Record an action in the admin audit trail. */
function audit(?int $userId, string $action, string $entity = '', ?int $entityId = null, ?string $detail = null): void {
    try {
        db()->prepare('INSERT INTO audit_log (user_id, action, entity, entity_id, detail, ip) VALUES (?,?,?,?,?,?)')
            ->execute([$userId, $action, $entity ?: null, $entityId,
                $detail !== null ? mb_substr($detail, 0, 255) : null,
                $_SERVER['REMOTE_ADDR'] ?? null]);
    } catch (Throwable $e) {
        error_log('audit failed: ' . get_class($e) . ' ' . $e->getCode() . ' at ' . basename($e->getFile()) . ':' . $e->getLine());
    }
}

/**
 * Build an iCalendar meeting request (renders as an invite in Outlook/Google).
 * $occurrences: list of [startTs, endTs] pairs for a weekly series — each is
 * emitted as its own VEVENT with the correct UTC time, which stays accurate
 * across daylight-saving changes (a UTC RRULE would drift an hour).
 */
function build_ics(string $uid, string $title, string $description, string $location, int $startTs, int $endTs, string $organizerEmail, string $attendeeEmail, array $occurrences = [], string $url = ''): string {
    $fmt = fn(int $ts) => gmdate('Ymd\THis\Z', $ts);
    // Collapse any CR/LF before escaping so no value can inject an iCalendar
    // property line (defence-in-depth; inputs are already validated upstream).
    $esc = fn(string $s) => addcslashes(preg_replace('/[\r\n]+/', ' ', $s), ",;\\");
    // DESCRIPTION keeps its line breaks: escape the text FIRST, then turn each
    // real newline into the two-character \n escape (the other order would
    // double the backslash and calendar apps would show a literal "\n").
    $escMultiline = fn(string $s) => str_replace("\n", '\\n', addcslashes(preg_replace('/\r\n?/', "\n", $s), ",;\\"));
    // URL is a URI value, not TEXT: it must not be backslash-escaped (only line
    // breaks are removed; callers pass a validated http(s) link).
    $url = preg_replace('/[\r\n]+/', '', $url);
    // A property PARAMETER (the organizer's CN) takes no backslash escapes: it is
    // DQUOTE-quoted so , ; and : stay inside it, with any DQUOTE removed.
    $param = fn(string $s) => '"' . str_replace('"', '', preg_replace('/[\r\n]+/', ' ', $s)) . '"';
    if (!$occurrences) $occurrences = [[$startTs, $endTs]];
    // For a remote session, surface the join link as the LOCATION (what calendar
    // apps show and make clickable) and also as a URL property + in the body.
    if ($url !== '') {
        if ($location === '') $location = $url;
        $description = trim($description . "\nJoin online: " . $url);
    }
    $ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//CaseManagement//CMS//EN\r\nMETHOD:REQUEST\r\n";
    foreach ($occurrences as $i => [$s, $e]) {
        $ics .= "BEGIN:VEVENT\r\nUID:" . ($i === 0 ? $uid : preg_replace('/@/', "-{$i}@", $uid, 1)) . "\r\nDTSTAMP:" . $fmt(time()) . "\r\n"
            . "DTSTART:" . $fmt($s) . "\r\nDTEND:" . $fmt($e) . "\r\n"
            . "SUMMARY:" . $esc($title) . "\r\n"
            . "DESCRIPTION:" . $escMultiline($description) . "\r\n"
            . ($url !== '' ? "URL:" . $url . "\r\n" : '')
            . ($location !== '' ? "LOCATION:" . $esc($location) . "\r\n" : '')
            . "ORGANIZER;CN=" . $param(brand('short')) . ":mailto:" . $esc($organizerEmail) . "\r\n"
            . "ATTENDEE;ROLE=REQ-PARTICIPANT;PARTSTAT=NEEDS-ACTION;RSVP=TRUE:mailto:" . $esc($attendeeEmail) . "\r\n"
            . "STATUS:CONFIRMED\r\nBEGIN:VALARM\r\nTRIGGER:-PT30M\r\nACTION:DISPLAY\r\nDESCRIPTION:Reminder\r\nEND:VALARM\r\n"
            . "END:VEVENT\r\n";
    }
    return $ics . "END:VCALENDAR\r\n";
}

/** Straight-line book value of an asset given its acquisition value, purchase
 * date and useful life (months). Returns null when value/date are unknown;
 * floors at 0 once fully depreciated. */
function equip_book_value(?float $value, ?string $purchaseDate, int $lifeMonths): ?float {
    if ($value === null || $value <= 0 || !$purchaseDate) return $value;
    if ($lifeMonths <= 0) return round($value, 2); // 0 = non-depreciating asset
    $start = strtotime($purchaseDate);
    if ($start === false) return $value;
    $months = (int)floor((time() - $start) / (30.4375 * 86400));
    if ($months <= 0) return round($value, 2);
    if ($months >= $lifeMonths) return 0.0;
    return round($value * (1 - $months / $lifeMonths), 2);
}

/** Post one secure message into a client's thread (channel 'client' or
 * 'counselor'; sender 'staff'|'client'|'counselor'). Returns the new id. */
function post_message(int $clientId, string $channel, string $sender, ?int $staffId, string $body): int {
    db()->prepare('INSERT INTO messages (client_id, channel, sender, staff_id, body) VALUES (?,?,?,?,?)')
        ->execute([$clientId, $channel, $sender, $staffId, mb_substr(trim($body), 0, 5000)]);
    return (int)db()->lastInsertId();
}

/** Parse the "remote session" checkbox + meeting URL from a submitted form.
 * Returns [isRemote(bool), meetingUrl(?string), error(?string)] — URL kept only
 * when it's a valid http(s) link under 500 chars and the session is marked
 * remote. When a remote session's link was typed but fails that check, the
 * third value is a message naming the field: callers must show it and keep the
 * input instead of saving the session without its link. */
function session_remote_input(array $post): array {
    $isRemote = !empty($post['is_remote']);
    $url = trim((string)($post['meeting_url'] ?? ''));
    $error = null;
    if ($url !== '' && (mb_strlen($url) > 500 || !filter_var($url, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $url))) {
        if ($isRemote) {
            $error = mb_strlen($url) > 500
                ? 'Meeting link: use a link of 500 characters or fewer.'
                : 'Meeting link: enter the full web address, starting with https:// (for example https://zoom.us/j/123456789).';
        }
        $url = '';
    }
    if (!$isRemote) $url = '';
    return [$isRemote, $url !== '' ? $url : null, $error];
}

/** Send the post-closure outcome survey to a client (if they have an email).
 * An address not yet confirmed by staff (email_verified = 0: typed on the public
 * referral form) is skipped like a missing one, so nothing goes to a stranger.
 * When the email does not go out the survey row is removed again, so it is not
 * counted as sent and the 30-day resend guard does not block a retry; callers
 * tell the user on false. */
function send_outcome_survey(int $clientId): bool {
    $c = db()->prepare('SELECT name, email, email_verified FROM clients WHERE id = ?');
    $c->execute([$clientId]);
    $client = $c->fetch();
    if (!$client || !filter_var($client['email'] ?? '', FILTER_VALIDATE_EMAIL) || (int)$client['email_verified'] === 0) return false;
    $token = bin2hex(random_bytes(24));
    db()->prepare('INSERT INTO surveys (client_id, token) VALUES (?,?)')->execute([$clientId, $token]);
    $surveyId = (int)db()->lastInsertId();
    // In the client's language; the survey page itself opens in it too (lang=).
    $lang = client_lang($clientId);
    [$subj, $body] = email_template_for_client('survey_invite', $clientId, ['name' => $client['name'], 'link' => cfg('base_url') . '/survey.php?t=' . $token . ($lang === 'es' ? '&lang=es' : '')]);
    if (send_app_mail($client['email'], $subj, $body)) return true;
    db()->prepare('DELETE FROM surveys WHERE id = ? AND completed_at IS NULL')->execute([$surveyId]);
    return false;
}

/** Create an acknowledgment token for an emailed report; returns the public confirm URL. */
function create_report_ack(int $clientId, int $trackId, string $sentTo): string {
    $token = bin2hex(random_bytes(24));
    db()->prepare('INSERT INTO report_acks (client_id, track_id, token, sent_to) VALUES (?,?,?,?)')
        ->execute([$clientId, $trackId, $token, $sentTo]);
    return cfg('base_url') . '/ack.php?t=' . $token;
}

/**
 * Create an invoice from a track's unbilled work logs and expenses. Returns the
 * invoice id, or null if there is nothing to bill. The billing header (auth #,
 * agency, bill-to, rates, billing period) is captured on the invoice so a
 * reprint shows what was billed, not the track as later edited.
 */
/** $until (Y-m-d, never later than today) limits the batch to work and expenses dated
 *  on or before it; null = everything dated up to today, as before. */
function generate_invoice(int $trackId, ?int $userId, ?string $until = null): ?int {
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $t = $pdo->prepare('SELECT client_id, hourly_rate, mileage_rate, auth_number, funding_agency, billing_mode FROM training_tracks WHERE id = ? FOR UPDATE');
        $t->execute([$trackId]);
        $track = $t->fetch();
        // A no-charge track (3.0.34) is never invoiced: its hours are logged at $0.
        if (!$track || track_mode($track) === 'none') { $pdo->rollBack(); return null; }
        $private = track_mode($track) === 'private';
        // Bill each log at the rate in force when it was entered (snapshot column),
        // falling back to the track's current rate for older logs; each line is
        // rounded to the cent first, as log_line_amounts() prints it. Locking reads,
        // so work added meanwhile can't be marked billed without being counted.
        // Work or an expense dated after today (a year slip, a session logged ahead)
        // is never billed: it stays unbilled until its date has come. The cutoff is
        // fixed once and bound to every statement, so the totals and the rows marked
        // billed agree even if the transaction runs across midnight.
        $today = $until !== null ? min($until, date('Y-m-d')) : date('Y-m-d');
        $s = $pdo->prepare('SELECT COALESCE(SUM(w.hours),0) h, COALESCE(SUM(w.mileage),0) m,
            COALESCE(SUM(ROUND(w.hours * COALESCE(w.hourly_rate, t.hourly_rate), 2)),0) ha,
            COALESCE(SUM(ROUND(w.mileage * COALESCE(w.mileage_rate, t.mileage_rate), 2)),0) ma,
            MIN(w.work_date) d1, MAX(w.work_date) d2
            FROM work_logs w JOIN training_tracks t ON t.id = w.track_id
            WHERE w.track_id = ? AND w.is_billed = 0 AND w.work_date <= ? FOR UPDATE');
        $s->execute([$trackId, $today]);
        $sums = $s->fetch();
        $ex = $pdo->prepare('SELECT COALESCE(SUM(amount),0) a, MIN(expense_date) d1, MAX(expense_date) d2
            FROM track_expenses WHERE track_id = ? AND is_billed = 0 AND expense_date <= ? FOR UPDATE');
        $ex->execute([$trackId, $today]);
        $exp = $ex->fetch();
        $expAmt = round((float)$exp['a'], 2);
        if ((float)$sums['h'] <= 0 && (float)$sums['m'] <= 0 && $expAmt <= 0) { $pdo->rollBack(); return null; }
        $hoursAmt = round((float)$sums['ha'], 2);
        $milesAmt = round((float)$sums['ma'], 2);
        $dates = array_filter([$sums['d1'], $sums['d2'], $exp['d1'], $exp['d2']]);
        // A counselor email not yet confirmed (counselor_verified = 0, e.g. typed on
        // the public referral form) is left off the bill-to, as client_edit.php does.
        // A private-pay invoice is billed to the client (name, address and a confirmed email).
        $c = $pdo->prepare('SELECT name, address, email, email_verified, counselor_name, counselor_agency, counselor_email, counselor_verified FROM clients WHERE id = ?');
        $c->execute([(int)$track['client_id']]);
        $counselor = $c->fetch() ?: [];
        $billTo = implode("\n", array_filter(array_map(fn($v) => trim((string)$v), $private
            ? [$counselor['name'] ?? null, $counselor['address'] ?? null, (int)($counselor['email_verified'] ?? 0) === 1 ? ($counselor['email'] ?? null) : null]
            : [$counselor['counselor_name'] ?? null, $counselor['counselor_agency'] ?? null,
                (int)($counselor['counselor_verified'] ?? 0) === 1 ? ($counselor['counselor_email'] ?? null) : null]), fn($v) => $v !== ''));
        // Number the invoice from its own id after the insert — collision-free
        // even for two invoices created at the same instant.
        $pdo->prepare('INSERT INTO invoices (track_id, invoice_number, hours, hours_amount, mileage, mileage_amount, expenses_amount, total_amount, created_by,
                auth_number, funding_agency, bill_mode, bill_to, hourly_rate, mileage_rate, period_start, period_end) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$trackId, 'PENDING', $sums['h'], $hoursAmt, $sums['m'], $milesAmt, $expAmt, round($hoursAmt + $milesAmt + $expAmt, 2), $userId,
                $private ? '' : $track['auth_number'], $private ? 'Private pay' : $track['funding_agency'], $private ? 'private' : 'vr', $billTo, $track['hourly_rate'], $track['mileage_rate'],
                $dates ? min($dates) : null, $dates ? max($dates) : null]);
        $invId = (int)$pdo->lastInsertId();
        $pdo->prepare('UPDATE invoices SET invoice_number = ? WHERE id = ?')
            ->execute(['INV-' . date('Ym') . '-' . str_pad((string)$invId, 4, '0', STR_PAD_LEFT), $invId]);
        $pdo->prepare('UPDATE work_logs SET is_billed = 1, invoice_id = ? WHERE track_id = ? AND is_billed = 0 AND work_date <= ?')
            ->execute([$invId, $trackId, $today]);
        $pdo->prepare('UPDATE track_expenses SET is_billed = 1, invoice_id = ? WHERE track_id = ? AND is_billed = 0 AND expense_date <= ?')
            ->execute([$invId, $trackId, $today]);
        $pdo->commit();
        return $invId;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

const DOC_CATEGORIES = ['Authorization','IPE','Release','Report','Resource','Other'];
const SESSION_STATUSES = ['Scheduled','Completed','Cancelled','No Show'];
const CLIENT_STATUSES = ['Pending Assessment','Waitlist','Active Training','On Hold','Closed - Rehabilitated','Closed - Other'];
const NOTE_TYPES = ['Case Note','Phone Call','Progress Report','Monthly Summary','Intake','Addendum'];
// A client's accessible-format and contact preferences (client form and the app).
const A11Y_FORMATS = ['Standard print', 'Large print', 'Plain text', 'Braille-ready (BRF)', 'Audio', 'Accessible PDF'];
const A11Y_CONTACTS = ['No preference', 'Email', 'Phone', 'Client portal', 'Postal mail'];

/** Normalize a scored-assessment total to 0–100% across any scale (incl. negative
 * minimums like a -3..+3 impact scale), so trends compare fairly between
 * instruments. Returns 0 when the scale has no range. */
function scored_pct(int $total, int $itemCount, int $scaleMin, int $scaleMax): float {
    $lo = $itemCount * $scaleMin;
    $hi = $itemCount * $scaleMax;
    if ($hi <= $lo) return 0.0;
    return round(($total - $lo) / ($hi - $lo) * 100, 1);
}

/** The option map (value => label) for an instrument's rating scale. Falls back
 * to the legacy 0–4 labels when the instrument uses that scale with no custom
 * labels; otherwise builds numeric labels, overlaid with any scale_labels. */
function instrument_scale(array $inst): array {
    $min = (int)($inst['scale_min'] ?? 0);
    $max = (int)($inst['scale_max'] ?? 4);
    if ($max <= $min) { $min = 0; $max = 4; }
    $labels = [];
    if (!empty($inst['scale_labels'])) {
        foreach (explode(';', (string)$inst['scale_labels']) as $pair) {
            $p = explode('=', $pair, 2);
            if (count($p) === 2) $labels[(int)trim($p[0])] = trim($p[1]);
        }
    }
    if ($min === 0 && $max === 4 && !$labels && defined('SCORE_LABELS_DEFAULT')) return SCORE_LABELS_DEFAULT;
    $opts = [];
    for ($v = $min; $v <= $max; $v++) $opts[$v] = $labels[$v] ?? (string)$v;
    // Gloss the endpoints when the instrument gave no labels, so screen-reader
    // users hear what the lowest/highest rating means, not just a bare number.
    if (!isset($labels[$min])) $opts[$min] .= ' (lowest)';
    if (!isset($labels[$max])) $opts[$max] .= ' (highest)';
    return $opts;
}

// Legacy default rating labels (0–4), used when an instrument carries no scale.
const SCORE_LABELS_DEFAULT = [0 => '0 — Unable', 1 => '1 — Maximal assistance', 2 => '2 — Moderate assistance', 3 => '3 — Minimal assistance / prompts', 4 => '4 — Independent'];

// Structured case-note formats. Each has exactly four sections, stored in the
// four note_section columns in order. 'free' = plain narrative (no sections).
const NOTE_FORMATS = [
    'free' => [],
    'BIRP' => ['Behavior', 'Intervention', 'Response', 'Plan'],
    'PAIP' => ['Purpose', 'Assessment', 'Intervention', 'Plan'],
    'SOAP' => ['Subjective', 'Objective', 'Assessment', 'Plan'],
];
const REFERRAL_NOTE_TYPES = ['Initial Intake','Tech Assessment Request','Rehab Technology','Rehab Teaching Request','Counseling Request','Pre-ETS Request','Assistive Technology Training Request','Low Vision Device Request','Screen Reader Training Request','Magnification Training Request','Braille Training Request','Independent Living Skills Request','Workplace Assessment Request','Device Trial / Loan Request','Device Setup Request','Device Repair / Support Request','Device Funding Assistance Request','Plan Amendment','Other'];
const TECH_TYPES = ['Screen Reader','Magnification','Braille Display','Speech-to-Text','Independent Living Skills','Braille Literacy','Low Vision Devices','Rehabilitation Technology','Other'];
const SERVICE_TYPES = ['Assistive Technology','Rehab Teaching','Counseling','Pre-ETS','Other'];
const CLIENT_TYPES = ['VR','Pre-ETS'];
// Full RSA-911 case service taxonomy (PD-19-03), grouped as RSA groups them,
// plus the five required pre-employment transition services under
// Section 113 of the Rehabilitation Act / 34 CFR 361.48(a).
const RSA_CATEGORY_GROUPS = [
    'Career Services' => [
        'Assessment',
        'Diagnosis and Treatment of Impairments',
        'VR Counseling and Guidance',
        'Job Search Assistance',
        'Job Placement Assistance',
        'Short-Term Job Supports',
        'Supported Employment Services',
        'Customized Employment Services',
        'Information and Referral Services',
        'Benefits Counseling',
        'Extended Services',
    ],
    'Training Services' => [
        'Disability-Related Skills Training',
        'Job Readiness Training',
        'Occupational or Vocational Training',
        'On-the-Job Training',
        'Registered Apprenticeship Training',
        'Basic Academic Remedial or Literacy Training',
        'Graduate College or University Training',
        'Four-Year College or University Training',
        'Junior or Community College Training',
        'Customized Training',
        'Miscellaneous Training',
    ],
    'Pre-Employment Transition Services (§113)' => [
        'Job Exploration Counseling (Pre-ETS)',
        'Work-Based Learning Experiences (Pre-ETS)',
        'Counseling on Postsecondary Opportunities (Pre-ETS)',
        'Workplace Readiness Training (Pre-ETS)',
        'Instruction in Self-Advocacy (Pre-ETS)',
    ],
    'Other Services' => [
        'Rehabilitation Technology',
        'Personal Assistance Services',
        'Transportation',
        'Maintenance',
        'Reader Services',
        'Interpreter Services',
        'Technical Assistance Services',
        'Other Services',
    ],
];

/** Flat list of every RSA category (validation). */
function rsa_all_categories(): array {
    static $all = null;
    if ($all === null) $all = array_merge(...array_values(RSA_CATEGORY_GROUPS));
    return $all;
}

/** Legacy flat constant kept for existing call sites. */
const RSA_CATEGORIES = [
    'Assessment',
    'VR Counseling and Guidance',
    'Rehabilitation Technology',
    'Disability-Related Skills Training',
    'Job Readiness Training',
    'Benefits Counseling',
    'Information and Referral Services',
    'Job Exploration Counseling (Pre-ETS)',
    'Work-Based Learning Experiences (Pre-ETS)',
    'Counseling on Postsecondary Opportunities (Pre-ETS)',
    'Workplace Readiness Training (Pre-ETS)',
    'Instruction in Self-Advocacy (Pre-ETS)',
    'Other Services',
];

/** Default RSA category for a service type. */
function rsa_default_for(string $serviceType): string {
    return match ($serviceType) {
        'Counseling' => 'VR Counseling and Guidance',
        'Rehab Teaching' => 'Disability-Related Skills Training',
        'Assistive Technology' => 'Rehabilitation Technology',
        'Pre-ETS' => 'Job Exploration Counseling (Pre-ETS)',
        default => 'Other Services',
    };
}

/** <optgroup>-structured options for the full RSA taxonomy. */
function rsa_grouped_options(?string $selected): string {
    $out = '';
    foreach (RSA_CATEGORY_GROUPS as $group => $cats) {
        $out .= '<optgroup label="' . h($group) . '">';
        foreach ($cats as $c) {
            $sel = $c === $selected ? ' selected' : '';
            $out .= '<option value="' . h($c) . '"' . $sel . '>' . h($c) . '</option>';
        }
        $out .= '</optgroup>';
    }
    return $out;
}

/* ---------- Service bundles (billing labels grouping RSA categories) ---------- */

/** Active bundles applicable to a client type ('VR' or 'Pre-ETS'). */
function bundles_for(string $clientType): array {
    $s = db()->prepare("SELECT * FROM service_bundles WHERE active = 1 AND (client_type = ? OR client_type = 'Any') ORDER BY name");
    $s->execute([$clientType]);
    return $s->fetchAll();
}

function bundle(?int $id): ?array {
    if (!$id) return null;
    $s = db()->prepare('SELECT * FROM service_bundles WHERE id = ?');
    $s->execute([$id]);
    return $s->fetch() ?: null;
}

/** Categories a bundle covers, as a list. */
function bundle_categories(array $bundle): array {
    return array_values(array_filter(array_map('trim', explode(';', $bundle['categories']))));
}

/**
 * The billing/program label for a track: its bundle name when bundled,
 * else its single RSA category.
 */
function track_program_label(array $track): string {
    if (!empty($track['bundle_id']) && ($b = bundle((int)$track['bundle_id']))) return $b['name'];
    return $track['rsa_category'] ?? rsa_default_for($track['service_type'] ?? 'Assistive Technology');
}

/**
 * Why work dated $workDate (YYYY-MM-DD) should not be billed under this track's
 * authorization without a person confirming it, as one plain sentence; null when
 * nothing is wrong. $track is a training_tracks row (status, start_date,
 * expiration_date). It never refuses by itself: track.php asks for a "Log it
 * anyway" tick, the app API answers 422 until authorization_ack is sent, the
 * voice assistant reads it back before "Say yes to save", and session completion
 * (auto_log_session()) logs the hours and adds the sentence to its message.
 */
function work_authorization_issue(array $track, string $workDate): ?string {
    $exp = (string)($track['expiration_date'] ?? '');
    if ($exp !== '' && $workDate > substr($exp, 0, 10)) return 'This authorization expired on ' . fmt_date($exp) . '.';
    $start = (string)($track['start_date'] ?? '');
    if ($start !== '' && $workDate < substr($start, 0, 10)) return 'This authorization does not start until ' . fmt_date($start) . '.';
    $status = (string)($track['status'] ?? 'Active');
    if ($status !== 'Active') return 'This training track is ' . $status . '.';
    return null;
}

/**
 * Insert an auto-generated work log (session completion): stamps the track's
 * service type and its default RSA category. Returns the new log id.
 * A track with no hourly rate, or a session dated after today, is refused
 * (DomainException) rather than snapshotting a $0.00 rate onto billable work or
 * logging hours for a session that has not happened; callers run this inside
 * their claim transaction, so the refusal leaves the session open.
 * The rate is read under the track's row lock (track_edit.php re-prices unbilled
 * work under that lock), so a rate corrected at the same moment either applies
 * here or re-prices this log, never neither.
 * Since 3.0.34 a session outside the track's authorization (its dates, hours or
 * dollars: work_authorization_block()) is refused with that sentence, like a track
 * with no rate; $authIssue is always null (kept for the callers' signatures). A
 * no-charge track logs its hours at $0.
 */
function auto_log_session(int $trackId, string $date, float $hours, string $description, int $userId, ?string &$authIssue = null, float $mileage = 0.0): int {
    $authIssue = null;
    $year = (int)substr($date, 0, 4);
    if ($year < 2000 || $year > (int)date('Y') + 5) {
        throw new DomainException('The session date must have a year from 2000 to ' . ((int)date('Y') + 5) . '.');
    }
    if ($date > date('Y-m-d')) {
        throw new DomainException('This session is dated ' . fmt_date($date) . ', which is in the future. Hours are logged only once a session has taken place.');
    }
    $pdo = db();
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        $t = $pdo->prepare('SELECT * FROM training_tracks WHERE id = ? FOR UPDATE');
        $t->execute([$trackId]);
        $track = $t->fetch() ?: [];
        if ($track && track_needs_rate($track) && (float)$track['hourly_rate'] <= 0) {
            throw new DomainException('This track has no hourly rate. Ask an administrator to set the rate on the track before logging hours.');
        }
        // Outside the authorization's dates, hours or dollars: refused until an amendment
        // is recorded (owner decision, 3.0.34). The session stays Scheduled.
        if ($track && ($block = work_authorization_block($track, $date, $hours,
                round($hours * (float)$track['hourly_rate'], 2) + round(max(0.0, min(1000.0, $mileage)) * (float)$track['mileage_rate'], 2))) !== null) {
            throw new DomainException($block);
        }
        $cats = $track ? track_visit_categories($track) : [];
        $pdo->prepare('INSERT INTO work_logs (track_id, work_date, service_type, rsa_category, hours, mileage, description, created_by, hourly_rate, mileage_rate) VALUES (?,?,?,?,?,?,?,?,?,?)')
            ->execute([$trackId, $date, $track['service_type'] ?? null, $cats[0] ?? null, $hours, max(0.0, min(1000.0, $mileage)), $description, $userId,
                $track['hourly_rate'] ?? null, $track['mileage_rate'] ?? null]);
        $logId = (int)$pdo->lastInsertId();
        if ($own) $pdo->commit();
        $authIssue = null;
        return $logId;
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/**
 * RSA categories offered when logging a visit on a track: the bundle's
 * categories when bundled, otherwise the track's single category.
 */
function track_visit_categories(array $track): array {
    if (!empty($track['bundle_id']) && ($b = bundle((int)$track['bundle_id']))) return bundle_categories($b);
    $single = $track['rsa_category'] ?? rsa_default_for($track['service_type'] ?? 'Assistive Technology');
    return [$single];
}
const GOAL_STATUSES = ['Not Started','In Progress','Achieved'];
const TRACK_STATUSES = ['Active','Completed','Cancelled'];

// Billing rules shared by every page, the API and voice (3.0.34).
require_once __DIR__ . '/billing.php';
// Referral outcomes and case closure (3.0.35).
require_once __DIR__ . '/case_outcomes.php';
// Note versions and the 30-day lock, adult releases of information, vendor agreements (3.0.36).
require_once __DIR__ . '/records_privacy.php';
// Portal links that renew, message and request notices (3.0.40).
require_once __DIR__ . '/client_notices.php';
