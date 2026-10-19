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
# emailed. Restoring onto a new server: copy that key file across by hand, or each person
# with an authenticator app is let back in with cron/reset_2fa.php and sets it up again.
# If either dump fails, nothing is kept.
SECRET_SETTINGS="'ai_api_key','entra_client_secret','cfg.graph.client_secret','cfg.sms.auth_token','cfg.meet.zoom_client_secret','cfg.meet.ms_client_secret','cfg.qbo.client_secret'"
SETTINGS_WHERE="name NOT IN ($SECRET_SETTINGS) AND name NOT LIKE '%secret%' AND name NOT LIKE '%api_key%' AND name NOT LIKE '%token%' AND name NOT LIKE 'totp_pending%'"
{ mysqldump --defaults-extra-file="$DB_CNF" \
    --single-transaction --routines --ignore-table="$DB_NAME.settings" "$DB_NAME" \
  && mysqldump --defaults-extra-file="$DB_CNF" \
    --single-transaction --where="$SETTINGS_WHERE" \
    "$DB_NAME" settings; } | gzip > "$DUMP_TMP"
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
