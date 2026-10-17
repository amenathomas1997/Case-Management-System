# Release 4.15.0 — October 17, 2026

Web 4.15.0, Android app 4.15.0 (versionCode 4), iPhone app 4.15.0 (build 51). Apply dist/migrations/mig_4.15.0.sql, or use Database upgrade. It adds three tables (staff_agreements, staff_agreement_signatures, staff_agreement_revisions) and a starting confidentiality agreement that is off until edited. schema_version becomes 4.15.0.

Owner request (2026-10-17): staff sign their agreements themselves, not just have a date recorded.

**For administrators: Administration, Staff agreements.**
- Write one or more agreements: a line starting # is a heading, a line starting - is a list item.
- Turn each on. The starting text cannot be turned on until it is edited.
- Mark one as the confidentiality agreement.
- See who has signed the current version, who has to sign again, and each signed copy. Opening a copy is audited.

**Changing the text:**
- Any change makes a new version, which everyone signs again. A new version of the confidentiality agreement clears the date the yearly reminders follow, so Compliance and the reminders agree.
- "Small correction" keeps the version only when the lines, headings and list items are the same and at most 40 characters differ. Anything bigger is refused as a correction.
- Every change of text is kept with its before and after, who made it and when (staff_agreement_revisions).
- A signed copy says when small corrections came after it was signed. The copy itself never changes.

**For staff: My agreements** (on the website from Home or My Account, and in the phone apps).
- Home says when an agreement needs signing.
- Read it, tick that you have read and agree, and type your full name. It must match the name on the account, ignoring capitals, spaces and accents.
- The signature keeps the exact title and text, the version, the time, the name typed, the address, and where it was signed: the website, the Android app, the iPhone app, or "app" when unknown, with the browser or app details.
- Signing needs the fingerprint of the exact text shown. If the agreement changed while it was open, even by a correction, nothing is signed; the apps reload the new text and clear the tick.
- Staff can read only their own signed copies.
- Signing the confidentiality agreement records the confidentiality date. While that agreement is on, the date can't be typed on the user page, and a typed one is ignored.
- Staff are asked again a year after signing (the existing yearly renewal), or when the text changes.
- Compliance lists who still needs to sign each agreement, using one query for everyone.

**Tests:** tests/v415_cases.php checks:
- the starting text can't be turned on
- turning an agreement on, the Home notice, and reading with headings and lists
- refusals: no tick, or another name
- signing with the account name: the exact text, the date and the audit entry
- the signed copy
- a correction: kept in the history, noted on copies, refused when too big
- signing a corrected text that was already open is refused
- a new version: everyone signs again and the date is cleared
- signing an older version is refused
- the apps: list, refusals, signing (recorded as Android), and reading a copy
- the administrator's view
- a typed date is ignored
- Compliance names someone who hasn't signed

Checks before commit:
- Web: full_schema 1686, audit_regression, security, staging_scrub and run_all pass, and the migration rehearsal matched.
- Android: build, unit tests and lint pass. On the emulator against the local server:
  - signing from Home recorded "Android app" and the confidentiality date
  - with the text changed while open, signing was refused, the new text was shown and the tick was cleared
- The iPhone app builds for the simulator.

**Review:** every finding was fixed:
- **A correction saved while an agreement was open** could store text the person never read. Signing now needs the shown text's fingerprint.
- **Corrections** had no history and no limit. They now keep a before-and-after history and have a size limit.
- **The confidentiality date** could still be typed in by hand.
- **The Compliance note** depended on whether other agreements were outstanding.
- **The reminders and a new version** disagreed about who needed to sign.
- **The apps** stayed stuck after "changed while open".
- **The admin form's errors** landed far from the form.
- **The starting text** could be turned on unedited.
- **Upgraded and fresh sites** stored slightly different starting text.
- **Unknown apps** were labelled "iPhone app", and the user agent wasn't stored.
- **One query per person** on the admin and Compliance pages.
- **The iPhone signed copy** showed a raw timestamp.

## Deployed 2026-10-17 11:45 CT
From 4.14.0 with .audit_work/deploy-4.15.0: 16 files at 373499f.
- mig_4.15.0.sql ran (5 statements), and the three new tables are present.
- Production files matched 4.14.0 before install and match 4.15.0 after; .htaccess unchanged.
- Smoke checks passed, and health reports 4.15.0. CI passed all three jobs: PHP, Android and iOS.
- The starting confidentiality agreement is off on the live site until the owner edits and turns it on.
- The phone apps need their 4.15.0 builds (TestFlight, Google Play) to show My agreements. The website works now.

Backups: /home/cmsuser/casemanagement_private/deploy-4.15.0-20261017-114535.
