# Release 3.0.39 — October 16, 2026

Web and iPhone 3.0.39 (41); migration **mig_3.0.39.sql** (api_tokens: device_name, wipe_requested_at, wiped_at). Builds on 3.0.34 to 3.0.38, which are held undeployed. Apply mig_3.0.34 to mig_3.0.39 in order.

- **One phone at a time.**
  - The app sends its device name at sign-in (verify, device_name), and the session row keeps it.
  - My Account (Phones signed in to the app) and Users (for the person being edited) list each phone, with Sign out and Report lost and wipe.
  - A phone reported lost gets a 401 answer with wipe true at its next request. The server then ends the session, records wiped_at and audits it as user.device_wiped.
  - The app (API.wipeThisPhone) then removes every draft, the offline queue file and the saved lists, clears scheduled reminders and signs out, with a notice on Sign in. Unsent entries are lost; the confirmation says so.
  - Wiped sessions stay listed for 30 days. New audit labels: phone reported lost, phone signed out, lost phone wiped.
- **No passcode, nothing kept.** On an iPhone without a passcode (API.phoneCanKeepClientData): drafts and saved lists are not written, and an offline save is refused with a message saying to save with a signal or set a passcode.
- **Privacy notice in Spanish.**
  - privacy.php has the Español switch. The Spanish text is inc/privacy_es.php, built from the same settings.
  - The English text now covers releases of information, record copies from the portal, locked notes, view logging, key documents and holds kept from trash purging, and the lost-phone wipe. Updated date 2026-10-16.
  - **The Spanish text is Claude's translation: have a fluent reader check it before deploying.**
- **Tests.** tests/devices_cases.php is new, and mig_3.0.39 is added to the chain test.

Verified before commit:
- full_schema 1048, audit_regression 61, security 67, staging_scrub 34, and run_all (including the iOS API regression).
- The iPhone simulator build succeeded.
- Not checked in a browser or with a screen reader. The wipe has not been tried on a real phone.

## Deployed 2026-10-16 11:22 CT
- 3.0.34 to 3.0.39 went out together from 3.0.33, using .audit_work/deploy-3.0.39 (deploy.sh, migrate.php and mig_batch.sql, which joins mig_3.0.34 to mig_3.0.39).
- Before deploying, the batch was rehearsed locally: a 3.0.33 schema upgraded with the deploy's own statement splitter matches a fresh 3.0.39 schema, and a re-run changes nothing.
- On the server: 20 migration statements ran, all 16 new columns and tables are present, and 4 vendors are listed. Every PHP file passed its syntax check, every installed file matched its checksum, and .htaccess was unchanged.
- Smoke checks passed: login, community_referral, help, privacy (English and Spanish) and release.php return 200; admin, vendors and privacy_incidents return 302; api.php me returns 401; health.php reports ok, version 3.0.39; updates.json and the sign-in page both show 3.0.39.
- Backup: /home/cmsuser/casemanagement_private/deploy-3.0.39-20261016-112219 (website, cron and database-before.sql.gz).
- iOS 3.0.39 (41) is not archived.
