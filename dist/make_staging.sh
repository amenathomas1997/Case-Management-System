#!/bin/bash
# Make (or refresh) a staging copy of a production installation on the same server.
#
# Usage, as the account that owns the site, after creating the staging subdomain
# (its docroot) and an EMPTY staging database with a user that can write to it:
#
#   dist/make_staging.sh [options] <docroot_src> <private_src> <docroot_dst> <private_dst> <staging_db_name>
#
#   --base-url URL      the staging site's address (https://staging.example.org);
#                       or env STAGING_BASE_URL. Required.
#   --db-host HOST      staging database host (default: the production host)
#   --db-user USER      staging database user (default: the production user)
#   --db-pass PASS      staging database password, or env STAGING_DB_PASSWORD
#                       (default: the production password — same user, same server)
#   --admin-email MAIL  the staging administrator's sign-in email
#                       (default: staging-admin@<base-url host>)
#   --with-uploads      copy the uploaded client documents too (default: not copied;
#                       the document records stay, pointing at files that are absent)
#
# What it does, in order (nothing in production is changed; it is only read):
#   1. copies the website files to <docroot_dst> and the private directory to
#      <private_dst> (never backups/, config.php or the Graph token; uploads only
#      with --with-uploads), then points the copy at its own private directory;
#   2. dumps the production database and loads it into <staging_db_name>
#      (every table there is dropped first, so running this again refreshes it);
#   3. scrubs the copy in place: client names become "Client N", emails
#      clientN@example.invalid, phones and addresses are removed, dates of birth
#      keep the year only, note bodies and other free text become "[scrubbed]",
#      guardian and counselor contacts are scrubbed, document file names are kept,
#      staff names and emails are kept, and every credential — passwords, TOTP,
#      passkeys, API tokens, portal/VRC/consent/survey/report tokens, sign-in
#      codes, reset links — is cleared so nobody can sign in with a production
#      credential; then a staging administrator is created, whose password is
#      printed ONCE;
#   4. writes a new config.php for staging (its DSN, base_url, private paths,
#      SSO off, Graph credentials removed, mail_disabled: every email the copy
#      would send is written to <private_dst>/mail_sink/ instead — the sign-in
#      code arrives there);
#   5. prints what to do next.
# The dump is written under <private_dst> (mode 0600) and deleted as soon as it
# is loaded. Safe to run again: it refuses to run when a destination is a source.
set -euo pipefail

usage() { sed -n '2,36p' "$0" | sed 's/^# \{0,1\}//'; exit "${1:-2}"; }
die() { echo "make_staging: $*" >&2; exit 1; }

BASE_URL=${STAGING_BASE_URL:-}
DB_HOST_OPT=""; DB_USER_OPT=""; DB_PASS_OPT=${STAGING_DB_PASSWORD:-}; DB_PASS_SET=${STAGING_DB_PASSWORD+1}
ADMIN_EMAIL=""; WITH_UPLOADS=0
while [ $# -gt 0 ]; do
  case "$1" in
    --base-url) BASE_URL=$2; shift 2 ;;
    --db-host) DB_HOST_OPT=$2; shift 2 ;;
    --db-user) DB_USER_OPT=$2; shift 2 ;;
    --db-pass) DB_PASS_OPT=$2; DB_PASS_SET=1; shift 2 ;;
    --admin-email) ADMIN_EMAIL=$2; shift 2 ;;
    --with-uploads) WITH_UPLOADS=1; shift ;;
    -h|--help) usage 0 ;;
    --) shift; break ;;
    -*) die "unknown option $1 (see --help)" ;;
    *) break ;;
  esac
done
[ $# -eq 5 ] || usage 2
DOCROOT_SRC=$1; PRIVATE_SRC=$2; DOCROOT_DST=$3; PRIVATE_DST=$4; STAGING_DB=$5

# ---- Checks before anything is written ----
for tool in php rsync; do command -v "$tool" >/dev/null 2>&1 || die "$tool is not installed"; done
MYSQL=$(command -v mariadb || command -v mysql || true); [ -n "$MYSQL" ] || die "the mysql (or mariadb) client is not installed"
MYSQLDUMP=$(command -v mariadb-dump || command -v mysqldump || true); [ -n "$MYSQLDUMP" ] || die "mysqldump (or mariadb-dump) is not installed"
[ -d "$DOCROOT_SRC" ] || die "docroot_src $DOCROOT_SRC is not a directory"
[ -f "$DOCROOT_SRC/inc/core.php" ] || die "$DOCROOT_SRC does not hold the application (no inc/core.php)"
[ -f "$PRIVATE_SRC/config.php" ] || die "$PRIVATE_SRC/config.php not found: private_src must be the production private directory"
[ -d "$DOCROOT_DST" ] || die "docroot_dst $DOCROOT_DST does not exist: create the staging subdomain's docroot first"
[[ "$BASE_URL" =~ ^https://[A-Za-z0-9.-]+ ]] || die "--base-url (or STAGING_BASE_URL) must be the staging site's https:// address"
BASE_URL=${BASE_URL%/}
[[ "$STAGING_DB" =~ ^[A-Za-z0-9_]+$ ]] || die "staging database name may hold letters, digits and underscores only"
abs() { (cd "$1" 2>/dev/null && pwd -P); }
DOCROOT_SRC=$(abs "$DOCROOT_SRC"); PRIVATE_SRC=$(abs "$PRIVATE_SRC"); DOCROOT_DST=$(abs "$DOCROOT_DST")
mkdir -p "$PRIVATE_DST"; chmod 700 "$PRIVATE_DST"; PRIVATE_DST=$(abs "$PRIVATE_DST")
inside() { [ "$1" = "$2" ] || [[ "$1/" == "$2/"* ]]; }   # inside <path> <root>
for dst in "$DOCROOT_DST" "$PRIVATE_DST"; do
  for src in "$DOCROOT_SRC" "$PRIVATE_SRC"; do
    if inside "$dst" "$src" || inside "$src" "$dst"; then die "refusing to run: destination $dst is, or contains, or is inside, source $src"; fi
  done
done
[ "$DOCROOT_DST" != "$PRIVATE_DST" ] || die "docroot_dst and private_dst must be different directories"
inside "$PRIVATE_DST" "$DOCROOT_DST" && die "private_dst must be outside the staging docroot (it holds config.php and the dump)"

# Production database settings, read from config.php by PHP (never parsed by shell).
PROD_JSON=$(php -r '$c=require $argv[1]; $d=$c["db"]["dsn"]; preg_match("/host=([^;]+)/",$d,$h); preg_match("/dbname=([^;]+)/",$d,$n); preg_match("/port=([^;]+)/",$d,$p);
  echo json_encode(["host"=>$h[1]??"localhost","port"=>$p[1]??"","name"=>$n[1]??"","user"=>$c["db"]["user"],"pass"=>$c["db"]["pass"]]);' "$PRIVATE_SRC/config.php")
jget() { printf '%s' "$PROD_JSON" | php -r '$j=json_decode(stream_get_contents(STDIN),true); echo (string)$j[$argv[1]];' "$1"; }
PROD_HOST=$(jget host); PROD_PORT=$(jget port); PROD_DB=$(jget name); PROD_USER=$(jget user); PROD_PASS=$(jget pass)
[ -n "$PROD_DB" ] || die "could not read the production database name from $PRIVATE_SRC/config.php"
STAGING_HOST=${DB_HOST_OPT:-$PROD_HOST}; STAGING_USER=${DB_USER_OPT:-$PROD_USER}
if [ -n "${DB_PASS_SET:-}" ]; then STAGING_PASS=$DB_PASS_OPT; else STAGING_PASS=$PROD_PASS; fi
[ "$STAGING_DB" != "$PROD_DB" ] || [ "$STAGING_HOST" != "$PROD_HOST" ] || die "refusing to run: the staging database is the production database ($PROD_DB on $PROD_HOST)"
[ -n "$ADMIN_EMAIL" ] || ADMIN_EMAIL="staging-admin@$(printf '%s' "$BASE_URL" | sed -E 's#^https://([^/:]+).*#\1#')"

# Credentials go to the clients through temporary defaults files (0600), never the command line.
WORK=$(mktemp -d "$PRIVATE_DST/staging-work.XXXXXX"); chmod 700 "$WORK"
cleanup() { rm -rf "$WORK"; }
trap cleanup EXIT
cnf() {  # cnf <file> <host> <port> <user> <pass>
  ( umask 077; { echo '[client]'; echo "host=$2"; [ -n "$3" ] && echo "port=$3"; echo "user=$4"; printf 'password="%s"\n' "$(printf '%s' "$5" | sed 's/\\/\\\\/g; s/"/\\"/g')"; } > "$1" )
}
STAGING_PORT=""; [ -n "$DB_HOST_OPT" ] || STAGING_PORT=$PROD_PORT
cnf "$WORK/prod.cnf" "$PROD_HOST" "$PROD_PORT" "$PROD_USER" "$PROD_PASS"
cnf "$WORK/staging.cnf" "$STAGING_HOST" "$STAGING_PORT" "$STAGING_USER" "$STAGING_PASS"
"$MYSQL" --defaults-extra-file="$WORK/staging.cnf" -e 'SELECT 1' "$STAGING_DB" >/dev/null 2>&1 \
  || die "cannot connect to the staging database $STAGING_DB on $STAGING_HOST as $STAGING_USER (create it, and pass --db-user/--db-pass if its user differs)"

echo "== 1. Copying the website files to $DOCROOT_DST"
rsync -a --delete \
  --exclude 'install.php' --exclude 'install.php.enabled' --exclude 'install.lock' --exclude 'schema_full.sql' \
  --exclude 'inc/config_path.php' --exclude 'error_log' --exclude '.DS_Store' \
  "$DOCROOT_SRC/" "$DOCROOT_DST/"
printf '<?php\n// Written by make_staging.sh: the private directory of this STAGING copy.\nreturn %s;\n' "$(php -r 'echo var_export($argv[1], true);' "$PRIVATE_DST")" > "$DOCROOT_DST/inc/config_path.php"
echo "   inc/config_path.php points the copy at $PRIVATE_DST"

echo "== 2. Copying the private directory to $PRIVATE_DST$( [ "$WITH_UPLOADS" = 1 ] && echo ' (with uploads)' || echo ' (without uploads)')"
RSYNC_PRIVATE=(--exclude 'config.php' --exclude 'graph_token.json' --exclude 'db.cnf' --exclude 'backups/' --exclude 'backups/**' --exclude 'mail_sink/' --exclude 'staging-work.*' --exclude '.DS_Store')
[ "$WITH_UPLOADS" = 1 ] || RSYNC_PRIVATE+=(--exclude 'uploads/' --exclude 'uploads/**')
rsync -a "${RSYNC_PRIVATE[@]}" "$PRIVATE_SRC/" "$PRIVATE_DST/"
mkdir -p "$PRIVATE_DST/uploads" "$PRIVATE_DST/backups" "$PRIVATE_DST/mail_sink"
chmod 750 "$PRIVATE_DST/uploads"; chmod 700 "$PRIVATE_DST/backups" "$PRIVATE_DST/mail_sink"
if [ -d "$PRIVATE_DST/cron" ]; then
  # The cron settings name the production paths: rewritten for the copy (the jobs
  # themselves are not to be scheduled for staging — see the notes at the end).
  printf '<?php\n// Written by make_staging.sh: the STAGING copy'"'"'s inc/ directory, for the cron scripts.\nreturn %s;\n' "$(php -r 'echo var_export($argv[1], true);' "$DOCROOT_DST/inc")" > "$PRIVATE_DST/cron/cron_config.php"
  sq() { printf "'%s'" "$(printf '%s' "$1" | sed "s/'/'\\\\''/g")"; }
  { echo '# Written by make_staging.sh: settings for backup.sh on the STAGING copy (read as shell).'
    echo "CMS_PRIVATE_DIR=$(sq "$PRIVATE_DST")"; echo "CMS_DB_NAME=$(sq "$STAGING_DB")"; echo "CMS_DB_CNF=$(sq "$PRIVATE_DST/db.cnf")"; } > "$PRIVATE_DST/cron/backup.conf"
fi

echo "== 3. Dumping the production database $PROD_DB and loading it into $STAGING_DB"
DUMP="$WORK/production.sql"
( umask 077; "$MYSQLDUMP" --defaults-extra-file="$WORK/prod.cnf" --single-transaction --skip-lock-tables --quick --default-character-set=utf8mb4 "$PROD_DB" > "$DUMP" )
# Every table in the staging database goes first, so a second run refreshes it.
"$MYSQL" --defaults-extra-file="$WORK/staging.cnf" -N -e "SELECT CONCAT('DROP TABLE IF EXISTS \`', table_name, '\`;') FROM information_schema.tables WHERE table_schema = DATABASE()" "$STAGING_DB" \
  | { echo 'SET FOREIGN_KEY_CHECKS=0;'; cat; echo 'SET FOREIGN_KEY_CHECKS=1;'; } | "$MYSQL" --defaults-extra-file="$WORK/staging.cnf" "$STAGING_DB"
"$MYSQL" --defaults-extra-file="$WORK/staging.cnf" --default-character-set=utf8mb4 "$STAGING_DB" < "$DUMP"
rm -f "$DUMP"
echo "   loaded; the dump file was deleted"

echo "== 4. Scrubbing personal data and credentials in $STAGING_DB, creating the staging administrator"
STAGING_DSN="mysql:host=$STAGING_HOST;${STAGING_PORT:+port=$STAGING_PORT;}dbname=$STAGING_DB;charset=utf8mb4"
ADMIN_PASSWORD=$(php -r 'echo rtrim(strtr(base64_encode(random_bytes(15)), "+/", "-_"), "=");')
php -d display_errors=stderr -r '
$db = new PDO($argv[1], $argv[2], $argv[3], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$adminEmail = $argv[4]; $adminHash = password_hash($argv[5], PASSWORD_DEFAULT);
$cols = [];
foreach ($db->query("SELECT table_name, column_name FROM information_schema.columns WHERE table_schema = DATABASE()") as $r) $cols[$r[0]][$r[1]] = true;
$has = fn(string $t, string $c): bool => isset($cols[$t][$c]);
$tables = array_keys($cols);
// A random 48-character token per row, unique in practice: no production link keeps working.
$token = "SUBSTRING(SHA2(CONCAT(id, RAND(), \"staging\"), 256), 1, 48)";
// Column → replacement, applied only where the table and column exist in this schema.
$plan = [
  "clients" => ["name" => "CONCAT(\"Client \", id)", "email" => "CONCAT(\"client\", id, \"@example.invalid\")", "phone" => "NULL", "address" => "NULL",
    "dob" => "IF(dob IS NULL, NULL, MAKEDATE(YEAR(dob), 1))", "a11y_notes" => "IF(a11y_notes IS NULL, NULL, \"[scrubbed]\")", "school" => "NULL",
    "counselor_name" => "IF(counselor_name IS NULL OR counselor_name = \"\", counselor_name, CONCAT(\"Counselor \", id))",
    "counselor_email" => "IF(counselor_email IS NULL OR counselor_email = \"\", counselor_email, CONCAT(\"counselor\", id, \"@example.invalid\"))",
    "counselor_phone" => "NULL", "guardian_name" => "IF(guardian_name IS NULL, NULL, CONCAT(\"Guardian \", id))",
    "guardian_email" => "IF(guardian_email IS NULL, NULL, CONCAT(\"guardian\", id, \"@example.invalid\"))", "guardian_phone" => "NULL"],
  "referrals" => ["client_name" => "CONCAT(\"Referral \", id)", "client_dob" => "IF(client_dob IS NULL, NULL, MAKEDATE(YEAR(client_dob), 1))",
    "client_phone" => "NULL", "client_email" => "IF(client_email IS NULL, NULL, CONCAT(\"referral\", id, \"@example.invalid\"))", "client_address" => "NULL",
    "counselor_name" => "CONCAT(\"Counselor \", id)", "counselor_email" => "CONCAT(\"counselor-ref\", id, \"@example.invalid\")", "counselor_phone" => "\"\"",
    "body" => "\"[scrubbed]\"", "specific_needs" => "IF(specific_needs IS NULL, NULL, \"[scrubbed]\")", "school" => "NULL",
    "guardian_name" => "IF(guardian_name IS NULL, NULL, CONCAT(\"Guardian \", id))", "guardian_phone" => "NULL"],
  "client_notes" => ["body" => "\"[scrubbed]\"", "birp_behavior" => "IF(birp_behavior IS NULL, NULL, \"[scrubbed]\")", "birp_intervention" => "IF(birp_intervention IS NULL, NULL, \"[scrubbed]\")",
    "birp_response" => "IF(birp_response IS NULL, NULL, \"[scrubbed]\")", "birp_plan" => "IF(birp_plan IS NULL, NULL, \"[scrubbed]\")"],
  "messages" => ["body" => "\"[scrubbed]\""],
  "surveys" => ["comments" => "IF(comments IS NULL, NULL, \"[scrubbed]\")", "token" => $token],
  "incidents" => ["description" => "\"[scrubbed]\"", "action_taken" => "IF(action_taken IS NULL, NULL, \"[scrubbed]\")"],
  "tasks" => ["details" => "IF(details IS NULL, NULL, \"[scrubbed]\")"],
  "work_logs" => ["description" => "\"[scrubbed]\"", "signed_name" => "NULL"],
  "worksite_assessments" => ["employer" => "NULL", "worksite_location" => "NULL", "job_tasks" => "IF(job_tasks IS NULL, NULL, \"[scrubbed]\")", "barriers" => "IF(barriers IS NULL, NULL, \"[scrubbed]\")",
    "recommendations" => "IF(recommendations IS NULL, NULL, \"[scrubbed]\")", "outcome" => "IF(outcome IS NULL, NULL, \"[scrubbed]\")"],
  "tech_assessments" => ["primary_disability" => "NULL", "summary" => "IF(summary IS NULL, NULL, \"[scrubbed]\")"],
  "device_requests" => ["justification" => "IF(justification IS NULL, NULL, \"[scrubbed]\")", "notes" => "IF(notes IS NULL, NULL, \"[scrubbed]\")"],
  "access_bookings" => ["notes" => "IF(notes IS NULL, NULL, \"[scrubbed]\")", "location" => "NULL"],
  "equipment_loans" => ["notes" => "NULL"],
  "consent_forms" => ["guardian_name" => "CONCAT(\"Guardian \", client_id)", "guardian_email" => "CONCAT(\"guardian\", client_id, \"@example.invalid\")",
    "signed_name" => "IF(signed_name IS NULL, NULL, CONCAT(\"Guardian \", client_id))", "sign_ip" => "NULL", "token" => $token],
  "report_acks" => ["sent_to" => "CONCAT(\"counselor\", client_id, \"@example.invalid\")", "ack_ip" => "NULL", "token" => $token],
  "audit_log" => ["detail" => "IF(detail IS NULL, NULL, \"[scrubbed]\")", "ip" => "NULL"],
  "notifications" => ["message" => "\"[scrubbed]\""],
  "trash" => ["label" => "IF(label IS NULL, NULL, \"[scrubbed]\")", "payload" => "\"{}\""],
  "at_records" => ["details" => "\"{}\""],
  "referral_waitlist" => ["availability" => "\"\"", "expertise" => "\"\""],
  "homework_practice" => ["notes" => "\"\""],
  "ai_feedback" => ["comment" => "\"\""],
  "documentation_reviews" => ["rationale" => "\"[scrubbed]\""],
  // Staff: names and emails kept; every credential cleared (an invalid hash can never verify).
  "users" => ["password_hash" => "\"!staging\"", "totp_secret" => "NULL", "totp_enabled" => "0", "totp_last_step" => "NULL", "cal_token" => "NULL", "entra_oid" => "NULL"],
];
$db->exec("SET FOREIGN_KEY_CHECKS=0");
foreach ($plan as $table => $columns) {
  if (!in_array($table, $tables, true)) continue;
  $set = [];
  foreach ($columns as $col => $expr) if ($has($table, $col)) $set[] = "`$col` = $expr";
  if ($set) { $n = $db->exec("UPDATE `$table` SET " . implode(", ", $set)); echo "   $table: $n rows scrubbed\n"; }
}
// Nothing issued in production may sign in or open a link here.
foreach (["api_tokens", "passkeys", "portal_tokens", "vrc_tokens", "password_resets", "login_otps", "login_attempts", "sent_alerts"] as $t) {
  if (in_array($t, $tables, true)) { $db->exec("DELETE FROM `$t`"); echo "   $t: emptied\n"; }
}
if (in_array("settings", $tables, true)) {
  // Provider secrets and SSO stay in production; AI features are off until an
  // administrator turns them on here with staging keys.
  $db->exec("DELETE FROM settings WHERE name IN (\"ai_api_key\", \"entra_enabled\", \"entra_tenant_id\", \"entra_client_id\", \"entra_client_secret\", \"entra_auto_provision_group\") OR name LIKE \"totp_pending_%\"");
  $db->exec("INSERT INTO settings (name, value) VALUES (\"ai_enabled\", \"0\") ON DUPLICATE KEY UPDATE value = \"0\"");
}
$db->exec("SET FOREIGN_KEY_CHECKS=1");
// The staging administrator (updated on a re-run), in the Administrators group when there is one.
$group = $db->query("SELECT id FROM `groups` WHERE name = \"Administrators\" LIMIT 1")->fetchColumn();
$exists = $db->prepare("SELECT id FROM users WHERE email = ?"); $exists->execute([$adminEmail]);
if ($id = $exists->fetchColumn()) {
  $db->prepare("UPDATE users SET password_hash = ?, role = \"admin\", is_active = 1, totp_secret = NULL, totp_enabled = 0, group_id = ? WHERE id = ?")->execute([$adminHash, $group ?: null, (int)$id]);
} else {
  $db->prepare("INSERT INTO users (name, email, password_hash, role, is_active, group_id) VALUES (\"Staging Administrator\", ?, ?, \"admin\", 1, ?)")->execute([$adminEmail, $adminHash, $group ?: null]);
}
echo "   staging administrator: $adminEmail\n";
' "$STAGING_DSN" "$STAGING_USER" "$STAGING_PASS" "$ADMIN_EMAIL" "$ADMIN_PASSWORD"

echo "== 5. Writing $PRIVATE_DST/config.php for staging"
( umask 077; php -r '
$c = require $argv[1];
$private = $argv[2];
$c["db"] = ["dsn" => $argv[3], "user" => $argv[4], "pass" => $argv[5]];
$c["app"]["base_url"] = $argv[6];
$c["app"]["name"] = "STAGING " . (string)($c["app"]["name"] ?? "");
$c["app"]["upload_dir"] = $private . "/uploads";
$c["app"]["backup_email"] = $argv[7];
$c["app"]["backup_password"] = bin2hex(random_bytes(12));
$c["brand"]["short"] = "STAGING " . (string)($c["brand"]["short"] ?? "");
// No email leaves the staging copy: send_app_mail() keeps each message in mail_sink/.
$c["mail_disabled"] = true;
$c["mail_sink"] = $private . "/mail_sink";
// Graph credentials and single sign-on stay in production.
$c["graph"] = ["tenant_id" => "", "client_id" => "", "client_secret" => "", "sender" => (string)($c["graph"]["sender"] ?? ""), "token_cache" => $private . "/graph_token.json"];
if (isset($c["entra"])) $c["entra"] = ["enabled" => false, "tenant_id" => "", "client_id" => "", "client_secret" => "", "auto_provision_group" => ""];
file_put_contents($argv[8], "<?php\n// STAGING copy written by make_staging.sh — not the production configuration.\nreturn " . var_export($c, true) . ";\n");
' "$PRIVATE_SRC/config.php" "$PRIVATE_DST" "$STAGING_DSN" "$STAGING_USER" "$STAGING_PASS" "$BASE_URL" "$ADMIN_EMAIL" "$PRIVATE_DST/config.php" )
chmod 600 "$PRIVATE_DST/config.php"
[ -f "$PRIVATE_DST/.htaccess" ] || cp "$PRIVATE_SRC/.htaccess" "$PRIVATE_DST/.htaccess" 2>/dev/null || true

cat << EOF

Staging copy ready.
  Website files: $DOCROOT_DST
  Private dir:   $PRIVATE_DST  (config.php, mail_sink/, uploads/$( [ "$WITH_UPLOADS" = 1 ] || echo ' — empty: document files were not copied'))
  Database:      $STAGING_DB on $STAGING_HOST (scrubbed copy of $PROD_DB)

Staging administrator — the password is shown ONCE; store it in the password manager now:
  Sign in at: $BASE_URL/login.php
  Email:      $ADMIN_EMAIL
  Password:   $ADMIN_PASSWORD

Next:
  1. The sign-in code is NOT emailed on staging: after entering the password, read it
     from the newest file in $PRIVATE_DST/mail_sink/ (every email the copy would
     send lands there). Delete that folder's files now and then.
  2. Do not add the cron jobs for the staging copy (reminders and reports would be
     written to mail_sink/ anyway, but the off-site backup and the watchdog are for
     production only).
  3. Other staff accounts exist with their production names and emails but no
     usable password: set one for each tester from Administration, or let them use
     "Forgot password" and read the link from mail_sink/.
  4. Run this script again whenever staging should be refreshed from production:
     it replaces the files and the database and prints a new administrator password.
EOF
