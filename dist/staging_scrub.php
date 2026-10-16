<?php
// Scrubs a staging copy of the database in place and creates the staging administrator.
// Run by make_staging.sh (keep the two files together):
//
//   php staging_scrub.php <staging_dsn> <user> <password> <admin_email> <admin_password>
//
// Deny by default: every text column of every table is scrubbed unless it is named in
// STAGING_SCRUB_KEEP below (structural values, catalogues, staff names and emails). A
// column added by a later release is therefore scrubbed without anyone remembering to
// list it here. $plan gives nicer replacements ("Client 12", clientN@example.invalid)
// for the columns that tests and screens read; everything else becomes "[scrubbed]"
// (empty and NULL values stay as they are; JSON values become {}). Dates are kept,
// except dates of birth, which keep the year only. tests/staging_scrub.php seeds a
// marker into every column of every table and checks that only kept columns hold it.

// Text columns kept as they are: codes, statuses, catalogue content and keys.
const STAGING_SCRUB_KEEP = [
    'access_bookings' => ['service_type', 'status'],
    'ai_feedback' => ['rating'],
    'ai_usage' => ['feature', 'provider', 'model'],
    'assessment_instrument_items' => ['label'],
    'assessment_instruments' => ['name', 'description', 'scale_labels', 'category'],
    'assessment_templates' => ['name', 'hardware_details', 'software_version', 'summary'],
    'at_records' => ['kind', 'status', 'source_key'],
    'audit_log' => ['action', 'entity'],
    'client_documents' => ['path'],
    'client_notes' => ['note_format', 'idem_key'],
    'client_note_revisions' => ['note_type', 'note_format'],
    'client_releases' => ['signer_role', 'recipient_type', 'method'],
    'amendment_requests' => ['status'],
    'closure_followups' => ['status'],
    'surveys' => ['channel'],
    'client_status_history' => ['old_status', 'new_status'],
    'client_closures' => ['status', 'reason'],
    'clients' => ['privacy_notice_how', 'a11y_format', 'a11y_primary_tech', 'a11y_contact', 'preferred_language', 'counselor_agency'],
    'cohort_sessions' => ['activity'],
    'cohorts' => ['name'],
    'contract_deliverables' => ['title', 'status'],
    'contracts' => ['name', 'funder', 'contract_number', 'status'],
    'curricula' => ['name', 'tech', 'description'],
    'curriculum_skills' => ['skill'],
    'device_request_quotes' => ['vendor', 'quote_ref'],
    'device_requests' => ['device_name', 'category', 'status', 'funding_source'],
    'documentation_reviews' => ['fingerprint'],
    'equipment' => ['name', 'serial'],
    'equipment_reservations' => ['status'],
    'goal_templates' => ['category', 'specific_goal', 'measurable_metric', 'focus_area'],
    'groups' => ['name', 'description', 'perms'],
    'homework_practice' => ['difficulty', 'request_key'],
    'incidents' => ['type'],
    'invoices' => ['invoice_number', 'funding_agency'],
    'kb_articles' => ['title', 'category', 'body'],
    'notifications' => ['link'],
    'portal_requests' => ['type', 'status'],
    'privacy_incidents' => ['kind', 'risk_level', 'status'],
    'privacy_incident_events' => ['event_type', 'recipient'],
    'referral_waitlist' => ['status', 'languages', 'areas', 'modality'],
    'referrals' => ['closed_reason', 'counselor_agency', 'income_bracket', 'referral_purpose', 'requested_service', 'grade_level',
        'relationship', 'contact_first', 'preets_services', 'preferred_language'],
    'scheduled_sessions' => ['group_uid'],
    'service_bundles' => ['name', 'categories', 'description'],
    'settings' => ['name', 'value'],
    'tasks' => ['auto_key'],
    'tech_assessments' => ['hardware_details', 'software_version', 'attachment_path'],
    'track_expenses' => ['idem_key'],
    'training_tracks' => ['rsa_category', 'funding_agency'],
    'trash' => ['entity'],
    'users' => ['name', 'email'],
    // The organisation's own vendor list: no client information.
    'vendor_agreements' => ['vendor', 'service', 'client_info', 'agreement_status', 'notes'],
    'work_logs' => ['service_type', 'rsa_category', 'signature_path', 'idem_key'],
    'worksite_assessments' => ['status'],
];

// Emptied outright: nothing issued in production may sign in or open a link here.
const STAGING_SCRUB_EMPTY = ['api_tokens', 'passkeys', 'portal_tokens', 'vrc_tokens', 'password_resets', 'login_otps', 'login_attempts', 'sent_alerts'];

const STAGING_SCRUB_TEXT_TYPES = ['char', 'varchar', 'tinytext', 'text', 'mediumtext', 'longtext', 'json',
    'binary', 'varbinary', 'tinyblob', 'blob', 'mediumblob', 'longblob'];

/** Scrubs the database $db is connected to. Returns one line per table changed. */
function staging_scrub(PDO $db): array
{
    $cols = [];
    foreach ($db->query("SELECT table_name, column_name, data_type, character_maximum_length FROM information_schema.columns WHERE table_schema = DATABASE() ORDER BY table_name, ordinal_position") as $r) {
        $cols[$r[0]][$r[1]] = ['type' => strtolower((string)$r[2]), 'len' => $r[3] === null ? null : (int)$r[3]];
    }
    $has = fn(string $t, string $c): bool => isset($cols[$t][$c]);
    // A random 48-character token per row, unique in practice: no production link keeps working.
    $token = "SUBSTRING(SHA2(CONCAT(id, RAND(), \"staging\"), 256), 1, 48)";
    // Column → replacement, applied only where the table and column exist in this schema.
    $plan = [
        "clients" => ["vision_onset_age" => "NULL", "name" => "CONCAT(\"Client \", id)", "email" => "CONCAT(\"client\", id, \"@example.invalid\")", "phone" => "NULL", "address" => "NULL",
            "dob" => "IF(dob IS NULL, NULL, MAKEDATE(YEAR(dob), 1))", "school" => "NULL",
            "counselor_name" => "IF(counselor_name IS NULL OR counselor_name = \"\", counselor_name, CONCAT(\"Counselor \", id))",
            "counselor_email" => "IF(counselor_email IS NULL OR counselor_email = \"\", counselor_email, CONCAT(\"counselor\", id, \"@example.invalid\"))",
            "counselor_phone" => "NULL", "guardian_name" => "IF(guardian_name IS NULL, NULL, CONCAT(\"Guardian \", id))",
            "guardian_email" => "IF(guardian_email IS NULL, NULL, CONCAT(\"guardian\", id, \"@example.invalid\"))", "guardian_phone" => "NULL"],
        "referrals" => ["client_name" => "CONCAT(\"Referral \", id)", "client_dob" => "IF(client_dob IS NULL, NULL, MAKEDATE(YEAR(client_dob), 1))",
            "client_phone" => "NULL", "client_email" => "IF(client_email IS NULL, NULL, CONCAT(\"referral\", id, \"@example.invalid\"))", "client_address" => "NULL",
            "counselor_name" => "CONCAT(\"Counselor \", id)", "counselor_email" => "CONCAT(\"counselor-ref\", id, \"@example.invalid\")", "counselor_phone" => "\"\"",
            "school" => "NULL", "guardian_name" => "IF(guardian_name IS NULL, NULL, CONCAT(\"Guardian \", id))", "guardian_phone" => "NULL"],
        "surveys" => ["token" => $token],
        "work_logs" => ["signed_name" => "NULL"],
        "worksite_assessments" => ["employer" => "NULL", "worksite_location" => "NULL"],
        "tech_assessments" => ["primary_disability" => "NULL"],
        "access_bookings" => ["location" => "NULL"],
        "equipment_loans" => ["notes" => "NULL"],
        "consent_forms" => ["guardian_name" => "CONCAT(\"Guardian \", client_id)", "guardian_email" => "CONCAT(\"guardian\", client_id, \"@example.invalid\")",
            "signed_name" => "IF(signed_name IS NULL, NULL, CONCAT(\"Guardian \", client_id))", "sign_ip" => "NULL", "token" => $token],
        "client_releases" => ["token" => "IF(token IS NULL, NULL, $token)", "sign_ip" => "NULL"],
        "report_acks" => ["sent_to" => "CONCAT(\"counselor\", client_id, \"@example.invalid\")", "ack_ip" => "NULL", "token" => $token],
        "audit_log" => ["ip" => "NULL"],
        "trash" => ["payload" => "\"{}\""],
        "at_records" => ["details" => "\"{}\""],
        // Staff: names and emails kept; every credential cleared (an invalid hash can never verify).
        "users" => ["password_hash" => "\"!staging\"", "totp_secret" => "NULL", "totp_enabled" => "0", "totp_last_step" => "NULL", "cal_token" => "NULL", "entra_oid" => "NULL"],
    ];
    $report = [];
    $db->exec("SET FOREIGN_KEY_CHECKS=0");
    foreach ($cols as $table => $columns) {
        if (in_array($table, STAGING_SCRUB_EMPTY, true)) {
            $db->exec("DELETE FROM `$table`");
            $report[] = "$table: emptied";
            continue;
        }
        $set = [];
        foreach ($plan[$table] ?? [] as $col => $expr) if ($has($table, $col)) $set[$col] = "`$col` = $expr";
        foreach ($columns as $col => $info) {
            if (isset($set[$col]) || in_array($col, STAGING_SCRUB_KEEP[$table] ?? [], true)) continue;
            if (!in_array($info['type'], STAGING_SCRUB_TEXT_TYPES, true)) continue;
            $c = '`' . str_replace('`', '``', $col) . '`';
            $fit = fn(string $v): string => $info['len'] !== null && $info['len'] < strlen($v) ? substr($v, 0, $info['len']) : $v;
            $set[$col] = "$c = CASE WHEN $c IS NULL OR $c = '' THEN $c WHEN JSON_VALID($c) THEN '" . $fit('{}') . "' ELSE '" . $fit('[scrubbed]') . "' END";
        }
        if ($set) {
            $n = $db->exec("UPDATE `$table` SET " . implode(", ", $set));
            $report[] = "$table: $n rows scrubbed";
        }
    }
    if (isset($cols['settings'])) {
        // Provider secrets and SSO stay in production; AI features are off until an
        // administrator turns them on here with staging keys.
        $db->exec("DELETE FROM settings WHERE name IN (\"ai_api_key\", \"entra_enabled\", \"entra_tenant_id\", \"entra_client_id\", \"entra_client_secret\", \"entra_auto_provision_group\") OR name LIKE \"totp_pending_%\"");
        $db->exec("INSERT INTO settings (name, value) VALUES (\"ai_enabled\", \"0\") ON DUPLICATE KEY UPDATE value = \"0\"");
    }
    $db->exec("SET FOREIGN_KEY_CHECKS=1");
    return $report;
}

/** Creates (or, on a re-run, resets) the staging administrator, in the Administrators group when there is one. */
function staging_admin(PDO $db, string $adminEmail, string $adminPassword): void
{
    $adminHash = password_hash($adminPassword, PASSWORD_DEFAULT);
    $group = $db->query("SELECT id FROM `groups` WHERE name = \"Administrators\" LIMIT 1")->fetchColumn();
    $exists = $db->prepare("SELECT id FROM users WHERE email = ?"); $exists->execute([$adminEmail]);
    if ($id = $exists->fetchColumn()) {
        $db->prepare("UPDATE users SET password_hash = ?, role = \"admin\", is_active = 1, totp_secret = NULL, totp_enabled = 0, group_id = ? WHERE id = ?")->execute([$adminHash, $group ?: null, (int)$id]);
    } else {
        $db->prepare("INSERT INTO users (name, email, password_hash, role, is_active, group_id) VALUES (\"Staging Administrator\", ?, ?, \"admin\", 1, ?)")->execute([$adminEmail, $adminHash, $group ?: null]);
    }
}

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    if ($argc !== 6) { fwrite(STDERR, "usage: php staging_scrub.php <dsn> <user> <password> <admin_email> <admin_password>\n"); exit(2); }
    $db = new PDO($argv[1], $argv[2], $argv[3], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    foreach (staging_scrub($db) as $line) echo "   $line\n";
    staging_admin($db, $argv[4], $argv[5]);
    echo "   staging administrator: {$argv[4]}\n";
}
