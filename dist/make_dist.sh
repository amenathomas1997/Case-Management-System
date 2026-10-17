#!/bin/bash
# Assemble the white-label install bundle: cms_dist.tar.gz
# Contents: app files (docroot/), cron jobs (cron/), installer, schema, README.
set -euo pipefail
cd "$(dirname "$0")/.."
# The bundle holds only files git tracks: a stray file on this machine (a PHP
# error_log that can name a client, an editor backup, a scratch .env) must never
# reach another organisation. An untracked file in a shipped folder stops the
# build instead of being dropped silently: commit it or remove it.
UNTRACKED=$(git ls-files --others --exclude-standard -- public cron dist/migrations dist/install.php dist/schema_full.sql | grep -Ev '(^|/)\.DS_Store$' || true)
if [ -n "$UNTRACKED" ]; then
  echo "Refusing to build: these files are not tracked by git. Commit or remove them first:" >&2
  echo "$UNTRACKED" >&2
  exit 1
fi
# The file list comes from git but each file's content from this working tree, so a
# tracked file edited since the last commit (a debug line, a hard-coded value, a
# change nobody reviewed) would ship as it is on this machine. The bundle is built
# only from committed content: commit (or undo) such changes first.
CHANGED=$(git status --porcelain --untracked-files=no -- public cron dist/migrations dist/install.php dist/restore.php dist/schema_full.sql LICENSE README.md RUNBOOK.md SECURITY.md RISK-ASSESSMENT.md)
if [ -n "$CHANGED" ]; then
  echo "Refusing to build: these tracked files have changes that are not committed. Commit or undo them first:" >&2
  echo "$CHANGED" >&2
  exit 1
fi
STAGE=$(mktemp -d)
mkdir -p "$STAGE/docroot" "$STAGE/cron" "$STAGE/migrations" "$STAGE/src"
git ls-files -z -- public cron dist/migrations dist/install.php dist/schema_full.sql | tar --null -T - -cf - | tar -xf - -C "$STAGE/src"
# The licence the software is conveyed under travels with it (GPL-3, section 4).
cp LICENSE "$STAGE/LICENSE"
# What to do when something goes wrong, the security policy, and a start on a risk assessment (4.19.0).
cp README.md RUNBOOK.md SECURITY.md RISK-ASSESSMENT.md "$STAGE/"
# The restore script RUNBOOK.md and Help rely on, beside them (run with php restore.php from this folder).
cp dist/restore.php "$STAGE/restore.php"
cp -R "$STAGE/src/public/." "$STAGE/docroot/"
cp -R "$STAGE/src/cron/." "$STAGE/cron/"
cp -R "$STAGE/src/dist/migrations/." "$STAGE/migrations/"
cp "$STAGE/src/dist/install.php" "$STAGE/src/dist/schema_full.sql" "$STAGE/docroot/"
rm -rf "$STAGE/src"
rm -f "$STAGE/docroot/install.lock" "$STAGE/docroot/install.php.enabled"
# updates.json is this vendor's own release feed (name, notes, version); a
# white-label site must not serve it from its own domain.
rm -f "$STAGE/docroot/updates.json"
# Never ship this machine's own settings or desktop clutter. inc/config_path.php
# is written by the installer on each site (where its config is); a bundle that
# carried one would point an updated site at the wrong private directory.
rm -f "$STAGE/cron/cron_config.php" "$STAGE/cron/backup.conf" "$STAGE/docroot/inc/config_path.php"
find "$STAGE" -name '.DS_Store' -delete
cat > "$STAGE/README.txt" << 'EOF'
WHITE-LABEL CASE MANAGEMENT SYSTEM — INSTALL
Licence: this software is free software, licensed under the GNU General Public
License, version 3; the full terms are in the LICENSE file beside this one. It
comes with NO WARRANTY, to the extent permitted by law. It includes FPDF 1.86 by
Olivier Plathey (docroot/inc/fpdf/), which is free to use, change and pass on
under FPDF's own permissive licence (www.fpdf.org).

Requirements: PHP 8.1+ (extensions: pdo_mysql, mbstring, iconv, curl, zip,
openssl; gd is optional, for icons in your colors — the installer refuses to run
without the others) and MariaDB 10.4 or newer. MySQL is not supported: the
schema and the upgrade scripts use MariaDB syntax, and the installer refuses any
other server.

Updating: from 4.18.0 on, set Settings, Updates to github and use Administration,
Software update: it installs each new signed release with one button. Installations
older than 4.18.0 update once by hand, as below.

Existing deployments, in this order:
  1. Back up the database and the docroot.
  2. Apply the migrations/ SQL files newer than your installed version, in version
     order, with your database administration tool. mig_3.0.8.sql also adds the
     few objects that v2.35.0 and later packages left out, so run it on every
     existing installation, then the newer files. Every file is safe to run again.
     The migrations only add to the database, so the application files you are
     still running keep working on the migrated database.
  3. Only then upload the new application files and cron scripts (see below).
     The new code uses the columns the migrations add: uploaded first, sign-in and
     invoicing fail until the SQL has been applied.
These files bring an installation made from a v2.35.0 or later package up to
date. An installation made from v2.34.0 or an older package lacks tables that no
migration creates (programs, curricula, contracts, procurement and others):
export its data, install this release into a new empty database, and move the
data across, rather than running the migrations on it.
Keep migrations outside the web document root. Version 2.73.0 adds AT service
workflows. Schedule cron/at_reminders.php daily for in-app due-date reminders.

Updating the application files on an existing deployment (after the migrations
above have been applied): back up the docroot, then upload the new docroot/ over
it EXCEPT these files, which belong to your site and must be kept as they are:
  inc/config_path.php — written by the installer; tells the app where its private
      directory (config.php) is. Without it every page, the mobile app and every
      cron job stop with "Configuration missing."
  assets/apple-touch-icon.png, assets/icon-192.png, assets/icon-512.png and
      assets/icon-1024.png — the icons in your colors (the bundle's copies carry
      the default branding). An icon uploaded on Settings is kept elsewhere.
Do not upload install.php or schema_full.sql to an installed site, and keep its
install.lock. A site installed before inc/config_path.php existed (its installer
edited the $configPaths list at the top of inc/core.php instead) must create that
file BEFORE uploading, or the new inc/core.php will not find its config. It holds
one line naming the folder that contains config.php:
      <?php return '/full/path/to/your/private/directory';

Updating the cron scripts on an existing deployment: the 3.0.8 scripts are no
longer edited by hand. They read two small settings files that must sit in the
same folder as the scripts. Installations made with the 3.0.8 installer already
have them. Older installations must create both BEFORE copying the new scripts
over the old ones, or every job stops with "inc/core.php was not found":
  cron_config.php — one line naming the app's inc/ folder in your web docroot:
      <?php return '/full/path/to/your/web/docroot/inc';
  backup.conf — read by backup.sh as shell lines (keep the single quotes):
      CMS_PRIVATE_DIR='/full/path/to/your/private/directory'
      CMS_DB_NAME='your_database_name'
      CMS_DB_CNF='/full/path/to/your/private/directory/db.cnf'
Use the paths your old scripts had hand-edited at their tops. Without backup.conf,
backup.sh refuses to run and says so.

1. Create an empty MariaDB database + user (any cPanel/hosting panel).
2. Upload everything in docroot/ to your web docroot.
3. For this fresh installation only, create an empty install.php.enabled file
   beside install.php using your hosting file manager. Do not include that marker
   in deployment bundles. Browse to https://your-domain/install.php and complete the form
   (branding, colors, database, admin account). It checks the database server,
   imports the schema, seeds the goal/assessment template libraries, writes the
   private config outside the docroot, generates icons in your colors, and locks
   itself. The private directory must not be inside the docroot; prefer one that
   is also outside public_html / www / htdocs.
4. The success page shows the off-site backup password ONCE. Store it in your
   organisation's password manager, not only on the server: every weekly off-site
   backup email is an encrypted ZIP that cannot be opened without it, so if the
   password is lost along with the server, those backups are unreadable.
   Then confirm install.php, install.php.enabled and schema_full.sql are gone (the
   installer removes them and writes install.lock; the success page lists any it
   could not remove — delete those by hand at once).
5. Copy the files in cron/ into the cron folder inside your private directory.
   The installer creates that folder and writes cron_config.php and backup.conf
   into it — keep those two files; the scripts read them and need no editing.
   Create db.cnf and add the cron jobs exactly as the installer's success page shows.
6. Optional but recommended: Microsoft Graph mail (Azure app registration with
   Mail.Send application permission) — put tenant/client/secret in config.php's
   'graph' block for reliable email delivery. Without it, mail() is used.
   Email filing (cron/ingest_email.php) and weekly off-site backups larger than
   2 MB also need the Mail.ReadWrite application permission.

Backups leave out the stored AI provider API key and the Microsoft Entra client
secret, so after restoring a backup, enter them again on the AI assistant and
Federation pages.
EOF
tar czf dist/cms_dist.tar.gz -C "$STAGE" .
rm -rf "$STAGE"
echo "Built dist/cms_dist.tar.gz ($(du -h dist/cms_dist.tar.gz | cut -f1))"
