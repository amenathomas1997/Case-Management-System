#!/bin/bash
# Nightly CMS database backup with 30-day retention. Cron: 15 2 * * *
#
# Site settings. With this script in the private directory's cron/ folder (as the
# installer sets it up), it needs none: the private directory is the folder above
# it and the database is the one named in its config.php. Anything else is set in
# an optional backup.conf beside this script (plain shell lines such as
# CMS_PRIVATE_DIR='/home/you/cms_private') or in the cron environment, so this
# file never needs editing:
#   CMS_PRIVATE_DIR  private directory holding uploads/ and backups/
#   CMS_DB_NAME      database to dump (default: the dbname in config.php)
#   CMS_DB_CNF       option file with [client] user= / password= / host=
#                    (default: db.cnf inside the private directory, chmod 600)
set -euo pipefail
CONF="$(cd "$(dirname "$0")" && pwd)/backup.conf"
if [ -f "$CONF" ]; then . "$CONF"; fi
DEFAULT_PRIVATE_DIR="$(cd "$(dirname "$0")/.." && pwd)"
if [ -z "${CMS_PRIVATE_DIR:-}" ] && [ ! -f "$DEFAULT_PRIVATE_DIR/config.php" ]; then
  echo "backup.sh: no settings found. Create $CONF with the lines CMS_PRIVATE_DIR='/path/to/private', CMS_DB_NAME='database' and CMS_DB_CNF='/path/to/private/db.cnf' (the installer's success page shows the exact content)." >&2
  exit 1
fi
PRIVATE_DIR="${CMS_PRIVATE_DIR:-$DEFAULT_PRIVATE_DIR}"
DB_NAME="${CMS_DB_NAME:-}"
if [ -z "$DB_NAME" ] && [ -f "$PRIVATE_DIR/config.php" ]; then
  # Read from the DSN text itself, so cron needs no php on its PATH.
  DB_NAME="$(grep -o 'dbname=[A-Za-z0-9_]*' "$PRIVATE_DIR/config.php" | head -n 1 | cut -d= -f2 || true)"
fi
if [ -z "$DB_NAME" ]; then
  echo "backup.sh: no database name found. Add CMS_DB_NAME='database' to $CONF." >&2
  exit 1
fi
DB_CNF="${CMS_DB_CNF:-$PRIVATE_DIR/db.cnf}"
# watchdog.php and weekly_offsite.php look for dumps in <private>/backups.
BACKUP_DIR="$PRIVATE_DIR/backups"
mkdir -p "$BACKUP_DIR"
chmod 700 "$BACKUP_DIR"
STAMP=$(date +%Y%m%d_%H%M%S)
umask 077
DUMP_TMP=$(mktemp "$BACKUP_DIR/.cms_dump.XXXXXX")
UPLOAD_TMP=""
trap 'rm -f "$DUMP_TMP"; if [ -n "$UPLOAD_TMP" ]; then rm -f "$UPLOAD_TMP"; fi' EXIT
# The settings table also holds stored secrets. They must not travel in every
# backup and off-site email, so that table is dumped on its own without them
# (4.27.0: named one by one, as well as anything whose name holds secret, token
# or api_key; tests/v427_billing_cases.php checks every secret on Settings is left out):
#   ai_api_key                  the AI provider's key (AI assistant page)
#   entra_client_secret         Microsoft Entra single sign-on (Federation page)
#   cfg.graph.client_secret     Microsoft Graph email (Settings, Email)
#   cfg.sms.auth_token          Twilio text messages (Settings, Text messages)
#   cfg.meet.zoom_client_secret Zoom meetings (Settings, Online meetings)
#   cfg.meet.ms_client_secret   Microsoft meetings (Settings, Online meetings)
#   cfg.qbo.client_secret       QuickBooks Online (Settings, QuickBooks)
#   totp_pending_<user id>      an authenticator being set up, not yet confirmed (4.28.0)
# A restored database therefore needs each one entered again on those pages; a person
# who was part way through setting up an authenticator starts that again.
# Authenticator seeds (users.totp_secret, 4.28.0) and the QuickBooks and meeting tokens are
# in the dump only encrypted, with <private>/meeting_tokens.key, which is never dumped or
# emailed. Restoring onto a new server: copy the old meeting_tokens.key back beside config.php
# (chmod 600) and nothing is lost. If it is gone for good, an administrator (let in first with
# cron/reset_2fa.php if needed) uses System health, "Forget stored connections and keys", then
# connects QuickBooks again and staff connect Zoom or Teams again on My Account.
# Live link tokens (4.29.0): a client's portal token, an instructor's calendar-feed token,
# a counselor's VRC portal token and the pending consent, signature, release, report
# acknowledgement and survey links each open records by themselves, and a portal link is
# extended each time it is used. The dump never holds them: those tables are dumped with
# each token replaced by a one-way hash nobody can use (TOKEN_COLUMNS below). After a
# restore, portal and calendar-feed links, and any pending signature, consent, release,
# acknowledgement or survey link, must be sent again (restore.php says so).
# If any dump fails, nothing is kept.
TOKEN_COLUMNS="portal_tokens.token vrc_tokens.token consent_forms.token client_signatures.token client_releases.token report_acks.token surveys.token users.cal_token"
SECRET_SETTINGS="'ai_api_key','entra_client_secret','cfg.graph.client_secret','cfg.sms.auth_token','cfg.meet.zoom_client_secret','cfg.meet.ms_client_secret','cfg.qbo.client_secret'"
SETTINGS_WHERE="name NOT IN ($SECRET_SETTINGS) AND name NOT LIKE '%secret%' AND name NOT LIKE '%api_key%' AND name NOT LIKE '%token%' AND name NOT LIKE 'totp_pending%'"
MYSQL=$(command -v mariadb || command -v mysql || true)
if [ -z "$MYSQL" ]; then echo "backup.sh: the mysql (or mariadb) client is not installed; it is needed to leave live link tokens out of the backup." >&2; exit 1; fi
# Only the token tables this database has (an older schema may lack some).
TOKEN_TABLES=$("$MYSQL" --defaults-extra-file="$DB_CNF" -N -B -e "SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ($(for tc in $TOKEN_COLUMNS; do printf "'%s'," "${tc%%.*}"; done | sed 's/,$//'))" "$DB_NAME")
IGNORE_TOKENS=(); for t in $TOKEN_TABLES; do IGNORE_TOKENS+=("--ignore-table=$DB_NAME.$t"); done
# Writes INSERT statements for table $1 with column $2 replaced by an unusable hash (NULL stays
# NULL), one row per line, newlines escaped, binary values as hex, generated columns left out.
token_table_rows() {
  "$MYSQL" --defaults-extra-file="$DB_CNF" -N -B -r --default-character-set=utf8mb4 "$DB_NAME" << SQL
SET SESSION group_concat_max_len = 16777216;
SET @t = '$1', @tok = '$2';
$(cat << 'GEN'
SELECT GROUP_CONCAT(CONCAT('`', column_name, '`') ORDER BY ordinal_position SEPARATOR ','),
       GROUP_CONCAT(CASE
         WHEN column_name = @tok THEN CONCAT('IF(`', column_name, '` IS NULL, ''NULL'', QUOTE(LEFT(SHA2(CONCAT(''cms-backup:'', `', column_name, '`, RAND()), 256), ', COALESCE(character_maximum_length, 48), ')))')
         WHEN data_type IN ('binary', 'varbinary', 'tinyblob', 'blob', 'mediumblob', 'longblob') THEN CONCAT('IF(`', column_name, '` IS NULL, ''NULL'', CONCAT(''X, HEX(`', column_name, '`), ''))')
         ELSE CONCAT('REPLACE(REPLACE(QUOTE(`', column_name, '`), CHAR(10), ''\\\\n''), CHAR(13), ''\\\\r'')')
       END ORDER BY ordinal_position SEPARATOR ', '','', ')
  INTO @cols, @vals
  FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = @t AND extra NOT LIKE '%GENERATED%';
SET @q = CONCAT('SELECT CONCAT(''INSERT INTO `', @t, '` (', @cols, ') VALUES ('', ', @vals, ', '');'') FROM `', @t, '`');
PREPARE token_rows FROM @q; EXECUTE token_rows; DEALLOCATE PREPARE token_rows;
GEN
)
SQL
}
token_tables_dump() {
  [ -n "$TOKEN_TABLES" ] || return 0
  # shellcheck disable=SC2086
  mysqldump --defaults-extra-file="$DB_CNF" --single-transaction --no-data --skip-triggers "$DB_NAME" $TOKEN_TABLES || return 1
  echo 'SET NAMES utf8mb4; SET FOREIGN_KEY_CHECKS=0;'
  for tc in $TOKEN_COLUMNS; do
    case " $(echo $TOKEN_TABLES) " in *" ${tc%%.*} "*) token_table_rows "${tc%%.*}" "${tc#*.}" || return 1 ;; esac
  done
  echo 'SET FOREIGN_KEY_CHECKS=1;'
}
{ mysqldump --defaults-extra-file="$DB_CNF" \
    --single-transaction --routines --ignore-table="$DB_NAME.settings" ${IGNORE_TOKENS[@]+"${IGNORE_TOKENS[@]}"} "$DB_NAME" \
  && mysqldump --defaults-extra-file="$DB_CNF" \
    --single-transaction --where="$SETTINGS_WHERE" \
    "$DB_NAME" settings \
  && token_tables_dump; } | gzip > "$DUMP_TMP"
mv "$DUMP_TMP" "$BACKUP_DIR/cms_${STAMP}.sql.gz"
# Weekly (Sunday) copy of uploaded assessment attachments
if [ "$(date +%u)" = "7" ]; then
  UPLOAD_TMP=$(mktemp "$BACKUP_DIR/.cms_uploads.XXXXXX")
  tar czf "$UPLOAD_TMP" -C "$PRIVATE_DIR" uploads
  mv "$UPLOAD_TMP" "$BACKUP_DIR/uploads_${STAMP}.tar.gz"
fi
# Retention: 30 days
find "$BACKUP_DIR" -name '*.gz' -mtime +30 -delete
echo "Backup complete: cms_${STAMP}.sql.gz"
