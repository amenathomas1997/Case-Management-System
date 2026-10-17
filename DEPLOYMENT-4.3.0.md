# Release 4.3.0 — October 16, 2026

Web 4.3.0 (no iPhone app change). Apply dist/migrations/mig_4.3.0.sql (keeps the "cms" calendar ID prefix on upgraded sites; schema_version 4.3.0), or use Database upgrade.

This is the first of the open-source releases (the plan is in .audit_work/configurability-audit-2026-10-16.md). Nothing names one business any more; everything that does is set on screen.
- **Neutral built-in branding.**
  - The defaults are Case Management System / Case Management / Assistive Technology Services.
  - **Production:** the live config.php has no brand block, so .audit_work/deploy-extra/brand_4.3.0.sql saves Example Agency's branding and privacy details as Settings values. It runs with the migration and is never shipped. It uses INSERT IGNORE, so anything set on Settings wins.
- **Settings, Privacy notice** (new group): who runs the service, mailing address, privacy laws followed, host, and whether the HIPAA rights section shows (off unless set to yes). privacy.php no longer has Example Agency's details in code.
- **Settings, Updates:** where to check for new versions (https only, or off).
- **Settings, App icon:** upload a square PNG or JPEG of at least 512 pixels. It is resized with GD into the upload folder (brand/icon-180/192/512/1024.png) and served by icon.php, with the assets/ icons as the fallback. "Go back to the built-in icons" removes it.
- **Public wording** (wording.php, inc/wording.php), in English and Spanish, empty for the built-in text:
  - the consent form's description of the organisation's services
  - what the guardian agrees to
  - an optional extra privacy notice section with its own contents link
- **manifest.php** builds the web-app manifest from the branding (layout links to it and to icon.php). manifest.webmanifest is now neutral and unused.
- **No built-in server paths.**
  - core.php finds config.php in a private directory beside public_html.
  - The cron scripts find inc/ in public_html/casemanagement beside the private directory. On the live server both are the same paths as before.
  - backup.sh takes the private directory as the folder above its cron/ folder, and the database name from config.php's DSN. Both were checked on the live server (cron runs from casemanagement_private/cron; dbname=cms_db).
- **Calendar IDs** come from one helper, ics_uid(). The prefix is setting ics_uid_prefix: "cms" on a new site, "cms" kept by the migration so invitations already sent can still be updated or cancelled. PRODID is neutral.
- **Removed:** the built-in Iowa authorization-email domain (Funding agencies does this since 4.1.0); the Iowa sample import row.
- **Kept, for compatibility:** the session cookie name and the low-vision preference keys in the browser (renaming them would sign everyone out and reset saved display settings).
- **Help:** Administrator guide, "Making the system your own".
- **Tests:**
  - tests/v43_cases.php is new.
  - Updated: devices_cases (switches on HIPAA), authorization_email_cases (lists Iowa as an agency), the calendar tests (neutral prefix), and cron_regression (database name from config.php).

Review: no security holes. Every finding was fixed:
- brand_4.3.0.sql uses ON DUPLICATE KEY UPDATE and fills a row left empty by an earlier Settings save (the live site had no cfg rows, checked before deploy). It runs with the migration, before the files are installed.
- backup.sh reads the database name from config.php with grep (no php needed on cron's PATH)
- icon.php and manifest.php load core.php only (no session or cookie on a cacheable reply)
- icon pictures are capped at 4096 pixels
- a change to public wording keeps the words it replaced, dated (wording_<key>_history, newest 50), because the audit log keeps 255 characters
- the privacy law line shows on its own; brand.privacy_law_es gives it in Spanish (the old hard-coded Spanish string is gone)
- the HIPAA label says the laws line shows either way
- icon.php falls back to the 512 icon
- the installer asks whether HIPAA applies, sets the publisher's update list, and no longer writes manifest.webmanifest
- the update check is off unless set
- wording labels name their section; no placeholders
- Not changed: with 'ignore_db_settings' on, the live site would show the neutral branding, since its config.php has no brand block. Add one there if that switch is ever needed.

Verified before commit:
- full_schema 1444, audit_regression, security, staging_scrub, and run_all.
- The migration rehearsal matched.
- Not checked in a browser or with a screen reader.

## Deployed 2026-10-16 19:26 CT
From 4.2.0 with .audit_work/deploy-4.3.0 (34 files), at 55bb9ed. The first CI run failed on a test-only problem (PHP's file status cache in the test process; cleanup could not remove the icon folder); 55bb9ed fixed it and CI passed.

- **Before:** the checksum check stopped the first attempt. The server's manifest.webmanifest was an older copy (October 8, with absolute paths) that no deploy had changed. Since 4.3.0 no longer uses that file, it was left out of the package and is untouched on the server.
- **Database:**
  - mig_4.3.0.sql and the production-only brand_4.3.0.sql ran before the files were installed.
  - Checked beforehand: the live database had no cfg.* rows.
  - The branding SQL was dry-run locally: empty rows are filled and set values are kept.
- **Checked after:**
  - The sign-in page title is still "Case Management System".
  - The privacy notice names the maintainer, the Springfield address, the governing law and HIPAA, and shows the HIPAA section.
  - manifest.php serves the Example Agency name.
  - icon.php returns a PNG with no Set-Cookie.
  - Health and smoke checks passed.

Backups: /home/cmsuser/casemanagement_private/deploy-4.3.0-20261016-192633.
