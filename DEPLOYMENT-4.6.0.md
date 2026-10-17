# Release 4.6.0 — October 16, 2026

Web 4.6.0, iPhone app 4.6.0 (build 48). Apply dist/migrations/mig_4.6.0.sql (schema_version only), or use Database upgrade.

This finishes the open-source configurability work (the remaining items after 4.5.0).
- **Lists and time limits** gains four numbers (inc/lists.php CMS_NUMBERS):

  | Number | Built-in | Where it is used |
  |---|---|---|
  | Session reminder days ahead | 1, today and tomorrow, as before | cron/session_reminders.php's window (days + 1) |
  | AT service reminder days ahead | 7 | cron/at_reminders.php |
  | Documentation review: case notes from the last N days | 60 | doc_audit.php queries and wording |
  | Documentation review: sessions and goal progress from the last N days | 30 | doc_audit.php queries and wording |

- **Pricing rules** has a default mileage rate (setting pricing_default_mileage). A new track with no mileage typed takes, in order: the VR agency's own rate, the default, then $0. "No charge" stays $0. This applies on the website, in the app and in the spreadsheet import (default_mileage_for in inc/pricing.php).
- **iPhone app:**
  - One block of build settings at the top of ios/project.yml (CMS_BUNDLE_ID, CMS_TEAM, CMS_DISPLAY_NAME, CMS_SPOKEN_NAME, CMS_SIGNIN_TITLE, CMS_DEFAULT_SERVER) names the business. Targets, entitlements and Info.plist read them.
  - The code no longer names Example Agency:
    - API.base is the address chosen at sign-in, else the build's CMSDefaultServer, as https://…/api.php.
    - The keychain service is the bundle ID.
    - The widget's shared container is group.<bundle ID>.
    - The sign-in line is CMSSignInTitle.
  - Example Agency's build has the same values as before (checked in the built app: org.example.cms, group.org.example.cms, the same names and server). Staff stay signed in and see nothing new.
  - A build with an empty server asks "Your organisation's web address" at sign-in. It must be https; a bare host, a folder, or a page address is accepted. The address is kept for next time.
- **README.md** explains the build settings; Help mentions them.
- **Tests:**
  - tests/v46_cases.php is new.
  - cron_regression.py has a stand-in for cms_number.

Review: nothing affecting Example Agency's own build. Every finding was fixed:
- **Server change.**
  - The address field is hidden when signing in again to the same account, so the old session's token is never sent to another server.
  - Choosing a different address first signs out at the old server (the logout address is fixed before the switch). It also removes drafts, offline copies and the writes waiting to upload.
  - A build that names its server always uses it.
- **Addresses.** Refused: anything with a user or password part, a backslash, a space, or a scheme other than https (http, ftp, "https:/x", "http//x"). The scheme and host are lower-cased. "API.PHP" is understood. tests/ios_api_regression.py covers these.
- **Sign-in** announces the server it is sending to, and a failure says to check a new address.
- **Settings hints** use the app's own name instead of "CMS".
- **README** says to replace the icon and colours.
- **Reminders set more than a day ahead** name the day and date, for staff and administrators as well as clients. The setting says one reminder is sent per session.
- **The review's "no progress note" check** follows the activity window.

Verified before commit:
- full_schema 1480, audit_regression, security, staging_scrub, and run_all (including the iOS API and model tests).
- The migration rehearsal matched.
- The iPhone app builds for the simulator, and its Info.plist and entitlements show the expected values.
- Not checked in a browser, with a screen reader, or on a phone.

## Deployed 2026-10-16 20:47 CT
From 4.5.0 with .audit_work/deploy-4.6.0 (15 files), at 42eba28. mig_4.6.0.sql ran (1 statement). Production files matched 4.5.0 before install and match 4.6.0 after; .htaccess unchanged. Smoke checks passed; health reports 4.6.0. The new time limits and mileage default start at their built-in values, so nothing changes on the live site until an administrator sets them. The iPhone app 4.6.0 (build 48) still needs archiving to TestFlight by the owner. Backups: /home/cmsuser/casemanagement_private/deploy-4.6.0-20261016-204700.
