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
#   1. copies the website files to <docroot_dst> and, from the private directory,
#      only cron/ (without logs or dumps) and .htaccess to <private_dst> — never
#      config.php, the Graph token, backups/, deploy-*/ backups or other files;
#      uploads only with --with-uploads — then points the copy at it;
#   2. dumps the production database and loads it into <staging_db_name>
#      (every table there is dropped first, so running this again refreshes it);
#   3. scrubs the copy in place: client names become "Client N", emails
#      clientN@example.invalid, phones and addresses are removed, dates of birth
#      keep the year only, and every other text column — notes, homework, goals,
#      titles, document file names, locations, links — becomes "[scrubbed]" unless
#      staging_scrub.php keeps it (codes, statuses, catalogues, staff names and
#      emails), so a column added later is scrubbed too; every credential — passwords, TOTP,
#      passkeys, API tokens, portal/VRC/consent/survey/report tokens, sign-in
#      codes, reset links — is cleared so nobody can sign in with a production
#      credential; then a staging administrator is created, whose password is
#      printed ONCE;
#   4. writes a new config.php for staging (its DSN, base_url, private paths,
#      SSO off, Graph credentials removed (and every secret stored on Settings), mail_disabled: every email the copy
#      would send is written to <private_dst>/mail_sink/ instead — the sign-in
#      code arrives there);
#   5. prints what to do next.
# The dump is written under <private_dst> (mode 0600) and deleted as soon as it
# is loaded. Safe to run again: it refuses to run when a destination is a source.
# If any step fails, every table of the staging database is dropped (never a
# partial, unscrubbed copy), and <private_dst>/config.php stays a placeholder that
# stops the staging site ("Configuration missing.") instead of letting it fall
# back to the production configuration.
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
SCRUB_PHP="$(cd "$(dirname "$0")" && pwd -P)/staging_scrub.php"
[ -f "$SCRUB_PHP" ] || die "staging_scrub.php must sit next to this script (it holds the scrub)"
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
DB_TOUCHED=0; FINISHED=0
drop_staging_tables() {
  "$MYSQL" --defaults-extra-file="$WORK/staging.cnf" -N -e "SELECT CONCAT('DROP TABLE IF EXISTS \`', REPLACE(table_name, '\`', '\`\`'), '\`;') FROM information_schema.tables WHERE table_schema = DATABASE()" "$STAGING_DB" \
    | { echo 'SET FOREIGN_KEY_CHECKS=0;'; cat; echo 'SET FOREIGN_KEY_CHECKS=1;'; } | "$MYSQL" --defaults-extra-file="$WORK/staging.cnf" "$STAGING_DB"
}
cleanup() {
  local rc=$?
  set +e
  if [ "$FINISHED" != 1 ] && [ "$DB_TOUCHED" = 1 ]; then
    # 4.29.0: a failed load or scrub must never leave production data in the staging database.
    echo "make_staging: stopped before the copy was finished; dropping every table of $STAGING_DB so no unscrubbed data stays there" >&2
    drop_staging_tables || echo "make_staging: COULD NOT drop the tables of $STAGING_DB: drop them by hand now (they may hold unscrubbed production data)" >&2
  fi
  rm -rf "$WORK"
  exit "$rc"
}
trap cleanup EXIT
trap 'exit 1' INT TERM HUP
# Until step 5 writes the real staging configuration, the copy's config.php is a placeholder
# that stops the site: with no config.php there, core.php would fall back to its built-in
# list, which for a docroot inside public_html is the PRODUCTION configuration.
write_placeholder_config() {
  ( umask 077; printf '<?php\n// Placeholder written by make_staging.sh: the staging copy is not ready (or its last refresh failed).\n// Returning false stops every page with "Configuration missing." -- never the production configuration.\nreturn false;\n' > "$PRIVATE_DST/config.php.tmp" && mv -f "$PRIVATE_DST/config.php.tmp" "$PRIVATE_DST/config.php" )
}
write_placeholder_config
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
# Allowlist: deploy-*/ folders and backups/ hold full production dumps, and logs hold
# addresses, so only what the copy needs is taken.
[ -f "$PRIVATE_SRC/.htaccess" ] && cp "$PRIVATE_SRC/.htaccess" "$PRIVATE_DST/.htaccess"
if [ -d "$PRIVATE_SRC/cron" ]; then
  rsync -a --delete --exclude '*.log' --exclude '*.log.*' --exclude '*.sql' --exclude '*.sql.gz' --exclude '*.gz' --exclude '*.tgz' --exclude '*.tar' --exclude '*.zip' --exclude '*.json' --exclude '.DS_Store' \
    "$PRIVATE_SRC/cron/" "$PRIVATE_DST/cron/"
fi
[ "$WITH_UPLOADS" = 1 ] && [ -d "$PRIVATE_SRC/uploads" ] && rsync -a --exclude 'backups/' --exclude '.DS_Store' "$PRIVATE_SRC/uploads/" "$PRIVATE_DST/uploads/"
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
DB_TOUCHED=1
drop_staging_tables
"$MYSQL" --defaults-extra-file="$WORK/staging.cnf" --default-character-set=utf8mb4 "$STAGING_DB" < "$DUMP"
rm -f "$DUMP"
echo "   loaded; the dump file was deleted"

echo "== 4. Scrubbing personal data and credentials in $STAGING_DB, creating the staging administrator"
STAGING_DSN="mysql:host=$STAGING_HOST;${STAGING_PORT:+port=$STAGING_PORT;}dbname=$STAGING_DB;charset=utf8mb4"
ADMIN_PASSWORD=$(php -r 'echo rtrim(strtr(base64_encode(random_bytes(15)), "+/", "-_"), "=");')
# Deny by default: every text column not on the keep list in staging_scrub.php is scrubbed.
php -d display_errors=stderr "$SCRUB_PHP" "$STAGING_DSN" "$STAGING_USER" "$STAGING_PASS" "$ADMIN_EMAIL" "$ADMIN_PASSWORD"

echo "== 5. Writing $PRIVATE_DST/config.php for staging"
rm -f "$PRIVATE_DST/config.php.new"
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
// 4.27.0: so do the Twilio, Zoom, Microsoft meeting and QuickBooks accounts (the scrub removes the copies stored on Settings).
if (isset($c["sms"])) { $c["sms"]["account_sid"] = ""; $c["sms"]["auth_token"] = ""; }
if (isset($c["meet"])) foreach (["zoom_client_id", "zoom_client_secret", "ms_client_id", "ms_client_secret"] as $k) $c["meet"][$k] = "";
if (isset($c["qbo"])) { $c["qbo"]["client_id"] = ""; $c["qbo"]["client_secret"] = ""; }
file_put_contents($argv[8], "<?php\n// STAGING copy written by make_staging.sh — not the production configuration.\nreturn " . var_export($c, true) . ";\n");
' "$PRIVATE_SRC/config.php" "$PRIVATE_DST" "$STAGING_DSN" "$STAGING_USER" "$STAGING_PASS" "$BASE_URL" "$ADMIN_EMAIL" "$PRIVATE_DST/config.php.new" )
# Never fall back to the production configuration: the staging config.php must have been
# written, name the staging database and turn mail off, or the run fails (and drops the copy).
php -r '$c = @include $argv[1]; exit(is_array($c) && ($c["db"]["dsn"] ?? "") === $argv[2] && !empty($c["mail_disabled"]) ? 0 : 1);' "$PRIVATE_DST/config.php.new" "$STAGING_DSN" \
  || die "the staging config.php was not written correctly; the staging copy was removed"
chmod 600 "$PRIVATE_DST/config.php.new"
mv -f "$PRIVATE_DST/config.php.new" "$PRIVATE_DST/config.php"
FINISHED=1
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
