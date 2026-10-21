# Release 4.1.0 — October 16, 2026

Web 4.1.0 (no iPhone app change). Apply dist/migrations/mig_4.1.0.sql (funding_agencies; clients.state, clients.time_zone; referrals.client_state; invoices.vendor_number; schema_version 4.1.0), or use Database upgrade.

The system must handle referrals from VR agencies across the country, not only the Iowa Department for the Blind. Until now the system assumed one agency and one time zone.

- **Funding agencies** (funding_agencies.php, administrators; inc/agencies.php).
  - Each agency has a state, its clients' usual time zone, the email domains its authorizations come from, your vendor number with it, its payment terms and its billing address.
  - Agencies are matched to tracks and invoices by name, so nothing existing moved. The migration lists every agency already on a VR track, and sets Iowa Department for the Blind to IA, Central, blind.state.ia.us.
  - A name typed on a track (website, app or import) takes the listed spelling when it differs only in capitals or spaces. A new name is kept as typed and listed under "On tracks but not set up".
  - Renaming an agency moves its tracks and draft invoices; sent invoices keep the name they were sent with.
- **Invoices.** The agency's billing address comes first on the bill-to. The due date uses the agency's terms, and the PDF prints its vendor number and terms. Blank values use Billing details, and private pay is unchanged.
- **Authorization emails** are matched to an agency by its listed domains (and their subdomains), after the existing counselor-domain match.
- **Client time zones.**
  - The client edit page has State and Time zone. A state with no zone chosen takes the state's usual zone.
  - The public referral form asks for the state, in English and Spanish. Accepting the referral on the website or in the app gives the client that state and its zone. The app path now also applies the referral's language, as the website did.
  - A client in a zone other than the office's sees times in their own zone: reminders (including "today" or "tomorrow" by their calendar), booking, change and cancellation emails, the portal (English and Spanish), and letters.
  - Calendar files were already in UTC.
  - Staff see "Client's time" on schedule rows and in their reminder. The client record says how far the client's time is from the office.
- **Help:** Administrator guide, "Taking referrals from a new VR agency"; a billing note in the staff guide.
- **Tests:** tests/v41_cases.php is new. cron_regression.py has stand-ins for the time zone helpers.

Review: no security holes. Every finding was fixed:
- only a zone someone picks is stored; with none, the state's usual zone applies and follows a change of state
- "Booked for …" on the portal is written in the client's zone, with the zone named
- reminders count days on the client's calendar; a session two days away there says the weekday instead of "tomorrow"
- each invoice keeps the vendor number it was made with (invoices.vendor_number); the PDF prints the terms its due date was set with, and falls back to the track's agency for older invoices
- the migration keeps the spelling used on most tracks, collapses spaces, works under ONLY_FULL_GROUP_BY, and adds Iowa only if it (or its domain) is not already listed
- "Client's time" moved from the schedule row header into the client cell, so JAWS does not repeat it on every cell
- the checkbox is now "Still working with this agency"; an agency you no longer work with is not matched to emails
- English state names on the Spanish referral form are marked lang="en"
- Funding agencies is linked in Administration's Data section
- also fixed: the 4.0.0 sign-in renewal test used a token that was not hex, so it passed only by timing

Verified before commit:
- full_schema 1397, audit_regression, security, staging_scrub, and run_all.
- The migration rehearsal matched.
- Not checked in a browser or with a screen reader.

## Deployed 2026-10-16 18:30 CT
From 4.0.0 with .audit_work/deploy-4.1.0 (30 files). mig_4.1.0.sql ran (8 statements); all 5 new columns and tables present. Production files matched 4.0.0 before install and match 4.1.0 after; .htaccess unchanged. Smoke checks passed; health reports 4.1.0. Backups: <private dir>/deploy-4.1.0-20261016-183027.
