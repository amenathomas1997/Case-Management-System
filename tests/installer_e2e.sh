#!/bin/bash
# End-to-end check of the white-label installer (dist/install.php) against the
# local MariaDB: a fresh docroot is built from public/ + dist/, served by PHP's
# built-in server, and the installer's form is sent with curl as an administrator
# would. Then: the config is outside the docroot with mode 0600, the installer
# locked and removed itself, the sign-in page loads, ping.php and updates.json are
# reachable, every table of schema_full.sql exists and the admin account can sign in.
#
# Needs, like the other database suites: CMS_TEST_DSN (its dbname MUST be
# cms_installer_e2e — the database is created and dropped by this script),
# CMS_TEST_USER and CMS_TEST_PASSWORD (a user allowed to CREATE/DROP that database).
#   CMS_TEST_DSN="mysql:host=localhost;dbname=cms_installer_e2e" CMS_TEST_USER=root CMS_TEST_PASSWORD= bash tests/installer_e2e.sh
# Prints PASS/FAIL lines and exits non-zero on any failure. The server, the
# database and the temporary directories are removed on exit, however it ends.
set -u
cd "$(dirname "$0")/.." || exit 1
ROOT=$(pwd)
PORT=${CMS_E2E_PORT:-8799}
DSN=${CMS_TEST_DSN:-}
DB_USER=${CMS_TEST_USER:-root}
DB_PASS=${CMS_TEST_PASSWORD:-}
DB_NAME=cms_installer_e2e
case "$DSN" in
  *";dbname=${DB_NAME}" | *";dbname=${DB_NAME};"*) ;;
  *) echo "FAIL: CMS_TEST_DSN must name the dedicated database ${DB_NAME} (got '${DSN}')" >&2; exit 2 ;;
esac
DB_HOST=$(printf '%s' "$DSN" | sed -n 's/.*host=\([^;]*\).*/\1/p')
DB_HOST=${DB_HOST:-localhost}

failed=0
pass() { echo "PASS: $*"; }
fail() { echo "FAIL: $*"; failed=1; }

# Database administration through PDO, so the mysql client is not needed.
dbadmin() {  # dbadmin <sql>  (no database selected)
  php -r '$p=new PDO("mysql:host=".$argv[1].";charset=utf8mb4",$argv[2],$argv[3],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]); $p->exec($argv[4]);' "$DB_HOST" "$DB_USER" "$DB_PASS" "$1"
}
dbquery() {  # dbquery <sql> -> first column of the first row, in the test database
  php -r '$p=new PDO($argv[1],$argv[2],$argv[3],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]); echo (string)$p->query($argv[4])->fetchColumn();' "$DSN" "$DB_USER" "$DB_PASS" "$1"
}

TMP=$(mktemp -d "${TMPDIR:-/tmp}/cms-installer-e2e.XXXXXX")
DOCROOT="$TMP/docroot"
PRIVATE="$TMP/private"
SERVER_PID=""
cleanup() {
  [ -n "$SERVER_PID" ] && kill "$SERVER_PID" 2>/dev/null && wait "$SERVER_PID" 2>/dev/null
  dbadmin "DROP DATABASE IF EXISTS \`$DB_NAME\`" 2>/dev/null
  rm -rf "$TMP"
}
trap cleanup EXIT

# ---- A fresh docroot as the bundle lays it out: public/ plus the installer and schema ----
mkdir -p "$DOCROOT"
cp -R "$ROOT/public/." "$DOCROOT/"
cp "$ROOT/dist/install.php" "$ROOT/dist/schema_full.sql" "$DOCROOT/"
rm -f "$DOCROOT/inc/config_path.php" "$DOCROOT/install.lock"
touch "$DOCROOT/install.php.enabled"
# The built-in server speaks plain HTTP; the app redirects to https unless the
# request is marked secure, so a router (outside the docroot) marks each PHP
# request as HTTPS and serves it, as a TLS-terminating web server would.
cat > "$TMP/router.php" << 'EOF'
<?php
$docroot = realpath($_SERVER['DOCUMENT_ROOT']);
$path = (string)parse_url((string)$_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/' || $path === '') $path = '/index.php';
$file = realpath($docroot . $path);
if ($file === false || !str_starts_with($file, $docroot . '/') || !is_file($file)) { http_response_code(404); exit('Not found'); }
if (!str_ends_with($file, '.php')) return false;
$_SERVER['HTTPS'] = 'on';
$_SERVER['SCRIPT_FILENAME'] = $file;
$_SERVER['SCRIPT_NAME'] = $_SERVER['PHP_SELF'] = $path;
chdir(dirname($file));
require $file;
EOF

if ! dbadmin "DROP DATABASE IF EXISTS \`$DB_NAME\`; CREATE DATABASE \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci"; then
  echo "FAIL: could not create database $DB_NAME on $DB_HOST as $DB_USER" >&2; exit 1
fi
pass "Empty database $DB_NAME created"

php -S "127.0.0.1:$PORT" -t "$DOCROOT" "$TMP/router.php" > "$TMP/server.log" 2>&1 &
SERVER_PID=$!
BASE="http://127.0.0.1:$PORT"
for _ in $(seq 1 50); do
  curl -s -o /dev/null "$BASE/install.php" && break
  sleep 0.1
done
if ! kill -0 "$SERVER_PID" 2>/dev/null; then echo "FAIL: php -S did not start:"; cat "$TMP/server.log"; exit 1; fi

# ---- The installer, as an administrator sees and sends it ----
form=$(curl -s "$BASE/install.php")
if printf '%s' "$form" | grep -q '<form method="post">'; then pass "Enabled installer shows its form"; else fail "Installer form did not load: $(printf '%s' "$form" | head -c 300)"; fi

ADMIN_EMAIL='e2e-admin@example.invalid'
ADMIN_PASS="E2e-$(php -r 'echo bin2hex(random_bytes(6));')"
result=$(curl -s "$BASE/install.php" \
  --data-urlencode 'brand_name=E2E Vision Services CMS' --data-urlencode 'brand_short=E2E Vision' \
  --data-urlencode 'brand_org=E2E Vision Services, Inc.' --data-urlencode 'brand_tagline=Installer check' \
  --data-urlencode 'brand_color=#1e40af' --data-urlencode 'brand_color_dark=#1e3a8a' --data-urlencode 'brand_accent=#0f766e' \
  --data-urlencode "base_url=https://127.0.0.1:$PORT" --data-urlencode "private_dir=$PRIVATE" \
  --data-urlencode 'mail_from=noreply@example.invalid' --data-urlencode 'support_email=help@example.invalid' \
  --data-urlencode 'support_phone=+1 555 010 2000' \
  --data-urlencode 'privacy_operator=E2E Vision Services, Inc.' --data-urlencode 'privacy_address=12 Main St, Springfield, Illinois 62701' \
  --data-urlencode 'privacy_law=Illinois' --data-urlencode 'privacy_hosting=E2E test host' \
  --data-urlencode 'timezone=America/Chicago' \
  --data-urlencode "db_host=$DB_HOST" --data-urlencode "db_name=$DB_NAME" --data-urlencode "db_user=$DB_USER" --data-urlencode "db_pass=$DB_PASS" \
  --data-urlencode 'admin_name=E2E Administrator' --data-urlencode "admin_email=$ADMIN_EMAIL" --data-urlencode "admin_pass=$ADMIN_PASS")
if printf '%s' "$result" | grep -q 'Installed successfully'; then
  pass "Installer reports success"
else
  fail "Installer did not succeed: $(printf '%s' "$result" | sed -n 's/.*<li>\(.*\)<\/li>.*/\1/p' | head -5 | tr '\n' ' ')"
  echo "$result" > "$TMP/install-result.html"
  exit 1
fi

# ---- Config outside the docroot, mode 0600 ----
CONFIG="$PRIVATE/config.php"
if [ -f "$CONFIG" ]; then pass "config.php written to the private directory"; else fail "config.php missing at $CONFIG"; fi
mode=$(stat -f '%Lp' "$CONFIG" 2>/dev/null || stat -c '%a' "$CONFIG" 2>/dev/null)
if [ "$mode" = "600" ]; then pass "config.php has mode 0600"; else fail "config.php mode is '$mode', not 600"; fi
case "$(cd "$PRIVATE" && pwd -P)" in
  "$(cd "$DOCROOT" && pwd -P)"/*) fail "private directory is inside the docroot" ;;
  *) pass "Private directory is outside the docroot" ;;
esac
if php -r '$c=require $argv[1]; exit($c["brand"]["privacy_operator"]==="E2E Vision Services, Inc." && $c["brand"]["privacy_law"]==="Illinois" && $c["app"]["base_url"]==="https://127.0.0.1:".$argv[2] && !is_file($argv[3]) ? 0 : 1);' "$CONFIG" "$PORT" "$DOCROOT/private/config.php"; then
  pass "config.php carries the privacy-notice fields and base URL"
else fail "config.php does not hold the expected brand privacy fields and base URL"; fi

# ---- The installer locked and removed itself ----
if [ ! -f "$DOCROOT/install.php" ] && [ ! -f "$DOCROOT/schema_full.sql" ] && [ ! -f "$DOCROOT/install.php.enabled" ]; then
  pass "install.php, schema_full.sql and the enable marker were removed"
elif [ -f "$DOCROOT/install.lock" ]; then pass "install.lock present (installer files left behind but locked)"
else fail "installer files remain and no install.lock was written"; fi
code=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/install.php")
if [ "$code" = "404" ] || [ "$code" = "403" ]; then pass "install.php no longer served (HTTP $code)"; else fail "install.php still answers HTTP $code"; fi

# ---- The site answers ----
login=$(curl -s -w '\n%{http_code}' "$BASE/login.php")
if [ "$(printf '%s' "$login" | tail -n1)" = "200" ] && printf '%s' "$login" | grep -q '<form'; then pass "Sign-in page loads"; else fail "login.php: HTTP $(printf '%s' "$login" | tail -n1) — $(cat "$TMP/server.log" | tail -3)"; fi
ping=$(curl -s -w '\n%{http_code}' "$BASE/ping.php")
if [ "$(printf '%s' "$ping" | tail -n1)" = "401" ] && printf '%s' "$ping" | head -n1 | grep -q expired; then pass "ping.php (health) reachable: 401 expired without a session"; else fail "ping.php answered: $ping"; fi
if [ -f "$DOCROOT/health.php" ]; then
  health=$(curl -s -w '\n%{http_code}' "$BASE/health.php")
  if printf '%s' "$health" | sed '$d' | php -r '$j=json_decode(stream_get_contents(STDIN), true); exit(is_array($j) && is_bool($j["ok"] ?? null) && isset($j["checks"][0]["name"]) ? 0 : 1);'; then
    pass "health.php (external monitor) answers JSON with its checks (HTTP $(printf '%s' "$health" | tail -n1); a fresh install has no cron runs or backups yet)"
  else fail "health.php did not answer JSON: $(printf '%s' "$health" | head -c 200)"; fi
fi
updates=$(curl -s -w '\n%{http_code}' "$BASE/updates.json")
if [ "$(printf '%s' "$updates" | tail -n1)" = "200" ] && printf '%s' "$updates" | sed '$d' | php -r 'exit(json_decode(stream_get_contents(STDIN), true) === null ? 1 : 0);'; then pass "updates.json reachable and valid JSON"; else fail "updates.json: $(printf '%s' "$updates" | tail -n1)"; fi

# ---- The database ----
expected=$(grep -c '^CREATE TABLE ' "$ROOT/dist/schema_full.sql")
actual=$(dbquery "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()")
if [ "$expected" = "$actual" ]; then pass "Schema has all $expected tables"; else fail "Schema has $actual tables, schema_full.sql defines $expected"; fi
if [ "$(dbquery "SELECT COUNT(*) FROM users WHERE email='$ADMIN_EMAIL' AND role='admin' AND is_active=1")" = "1" ]; then pass "Admin user exists"; else fail "Admin user missing"; fi
hash=$(dbquery "SELECT password_hash FROM users WHERE email='$ADMIN_EMAIL'")
if php -r 'exit(password_verify($argv[1], $argv[2]) ? 0 : 1);' "$ADMIN_PASS" "$hash"; then pass "Admin password verifies"; else fail "Admin password hash does not verify"; fi
if [ "$(dbquery "SELECT value FROM settings WHERE name='support_phone'")" = "+1 555 010 2000" ]; then pass "Support phone saved as a setting"; else fail "support_phone setting missing"; fi
if [ "$(dbquery "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name='scheduled_sessions' AND column_name='checked_in_at'")" = "1" ]; then pass "Fresh schema includes scheduled_sessions.checked_in_at (3.0.28)"; else fail "checked_in_at column missing from the fresh schema"; fi

if [ "$failed" -ne 0 ]; then echo "installer_e2e: FAILED"; exit 1; fi
echo "installer_e2e: all checks passed"
