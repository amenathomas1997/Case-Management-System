# Release 3.0.54 — October 16, 2026

Web 3.0.54. Apply dist/migrations/mig_3.0.54.sql, which records schema_version 3.0.54.

From the roadmap section "Running the system safely":
- **Settings on screen** (settings.php; inc/settings_admin.php).
  - The editable values:
    - Microsoft Graph: tenant, client, secret and sender
    - the from, from-name, help and backup addresses
    - the sign-out and sign-in code timeouts
    - the time zone (US zones)
    - branding, with contrast-checked colours
  - Each is stored as a cfg.* setting and laid over config.php at start-up. config.php's own values are kept in $CONFIG_FILE.
  - New Graph details are tried with Microsoft before they are saved. The secret is write-only and never audited, and the nightly backup excludes it.
  - 'ignore_db_settings' => true in config.php's app block makes config.php win again. This is the recovery path, and it is documented on the page and in the restore guide.
- **PDFs** use the brand dark colour (brand_rgb).
- **Safe upgrades.**
  - Migrations record schema_version; the installer writes the files' DB_SCHEMA_VERSION.
  - public/inc/migrations is a tested copy of dist/migrations.
  - upgrade.php applies pending migrations in order once an administrator confirms there is a backup from today, and reports each step.
  - Administration, System health and the daily alerts warn when the database is older than the files, including when no version is recorded.
- **Daily update check** in the daily alerts. Administrators are told once about each new version.
- **Backup check** ignores tables created after the dump. That was the false alarm on 3.0.53's first production check.
- **Tests:**
  - tests/settings_upgrade_cases.php is new. It also checks that every migration splits cleanly.
  - The installer end-to-end test checks the recorded version.

Review: one reviewer found nothing critical. Every finding was fixed:
- the installer's version on a bundle
- warnings when no version is recorded
- a recovery path from a bad Graph value, with the credentials tried before saving
- the useless cache delete was removed
- the time-zone wording
- the splitter test

Verified before commit:
- full_schema 1330, audit_regression, security, staging_scrub, run_all and installer_e2e.
- The migration rehearsal matched.
- Not checked in a browser or with a screen reader.
