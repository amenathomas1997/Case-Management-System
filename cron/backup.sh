#!/bin/bash
# Nightly CMS database backup with 30-day retention. Cron: 15 2 * * *
#
# Site settings. The defaults below are the production values, so this script
# runs unchanged there. Any other install sets its own values in an optional
# backup.conf beside this script (plain shell lines such as
# CMS_PRIVATE_DIR='/home/you/cms_private') or in the cron environment, so this
# file never needs editing:
#   CMS_PRIVATE_DIR  private directory holding uploads/ and backups/
#   CMS_DB_NAME      database to dump
#   CMS_DB_CNF       option file with [client] user= / password= / host=
#                    (default: db.cnf inside the private directory, chmod 600)
set -euo pipefail
CONF="$(cd "$(dirname "$0")" && pwd)/backup.conf"
if [ -f "$CONF" ]; then . "$CONF"; fi
DEFAULT_PRIVATE_DIR=/home/cmsuser/casemanagement_private
if [ -z "${CMS_PRIVATE_DIR:-}" ] && [ ! -d "$DEFAULT_PRIVATE_DIR" ]; then
  echo "backup.sh: no settings found. Create $CONF with the lines CMS_PRIVATE_DIR='/path/to/private', CMS_DB_NAME='database' and CMS_DB_CNF='/path/to/private/db.cnf' (the installer's success page shows the exact content)." >&2
  exit 1
fi
PRIVATE_DIR="${CMS_PRIVATE_DIR:-$DEFAULT_PRIVATE_DIR}"
DB_NAME="${CMS_DB_NAME:-cms_db}"
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
# The settings table also holds stored secrets: the AI provider's API key
# (ai_api_key) and the Microsoft Entra client secret (entra_client_secret). They
# must not travel in every backup and off-site email, so that table is dumped on
# its own without them. A restored database therefore needs both entered again,
# on the AI assistant and Federation pages. If either dump fails, nothing is kept.
{ mysqldump --defaults-extra-file="$DB_CNF" \
    --single-transaction --routines --ignore-table="$DB_NAME.settings" "$DB_NAME" \
  && mysqldump --defaults-extra-file="$DB_CNF" \
    --single-transaction --where="name NOT LIKE '%secret%' AND name NOT LIKE '%api_key%'" \
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
