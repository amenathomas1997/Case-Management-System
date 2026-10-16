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
