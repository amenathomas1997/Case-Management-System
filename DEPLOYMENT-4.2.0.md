# Release 4.2.0 — October 16, 2026

Web 4.2.0 (no iPhone app change). Apply dist/migrations/mig_4.2.0.sql (referrals.vr_case_open; funding_agencies.hourly_rate and mileage_rate; schema_version 4.2.0), or use Database upgrade.

The owner's pricing rules (2026-10-16), with every rule set on screen so another AT business can set its own (the owner plans to open-source the system):
- **Pricing rules** (pricing.php, administrators; inc/pricing.php).
  - The personal hourly rate (the old private-pay fee) and the free-below income moved here from Billing details. Billing details still accepts them from an old form.
  - Also set here:
    - whether training is free below that income
    - the age under which a parent's income is used (default 18; 0 for never)
    - whether an open VR case is always billed at the VR rate (default on)
    - what happens when staff set up free or personal-rate training for someone with an open VR case: warn (default), refuse, or say nothing
    - who the referral form asks: people referring themselves (default), or everyone except VR counselors and agencies
  - The page lists each VR agency's rates.
- **Per-agency rates.** Funding agencies has the hourly and mileage rate you charge each VR agency (for example Iowa $40, Missouri $60, Minnesota $80). A new VR track with no rate typed takes its agency's rates, on the website, in the app and in the spreadsheet import. A rate typed from the authorization is kept.
- **The referral form.**
  - Those asked answer "Do you have an open case with a vocational rehabilitation (VR) agency?" (Yes, No, Not sure).
  - The income question says a person under the youth age gives their parents' income. With the sliding fee off, income is not asked.
  - Quote: an open VR case is the VR rate (no fee quoted; the confirmation says to ask the counselor for an authorization). Otherwise free below the income limit, else the personal rate.
  - The answer is stored in referrals.vr_case_open. Staff see it and the quote on the referral.
  - Spanish wording is included.
- **Tracks.** A client has an open VR case when they have an active VR track, or their referral said yes. Their new track starts as VR or agency authorization. Saving free or personal-rate training for them warns, or is refused, by the rule. The app's save_track does the same.
- **Help:** the Administrator guide has "Setting your pricing rules"; the staff guide's who-pays paragraph is updated.
- **Tests:** tests/v42_cases.php is new. referral_request_cases.php answers the new question.

The configurability audit for the open-source release is in .audit_work/configurability-audit-2026-10-16.md (planned 4.3 to 4.5 and iOS).

Review: no security holes. Every finding was fixed:
- only a new track, or one changed to free or personal, is checked, and only while it is active, so an existing personal track can always be edited or closed
- an expired authorization is not an open VR case, and a referral's "yes" stops counting once the client has had a VR track
- the agency-rate default applies only to new tracks; in the app a blank rate takes it too (the app always sends the field)
- the confirmation says the flat rate plainly when income is not asked, and speaks of "the person" when someone else referred them
- with everyone asked, the VR question is worded for whoever is filling in the form
- a null quote shows "Not quoted" unless the answer was yes
- Spanish "Not sure" is "No estoy seguro"

Also: the licence is GPL version 3 (the owner's choice, 2026-10-16). LICENSE has the full text since the first commit; README.md is new, with the notice and the third-party code (FPDF is freeware; qrcode.js is our own).

Verified before commit:
- full_schema 1424, audit_regression, security, staging_scrub, and run_all.
- The migration rehearsal matched.
- Not checked in a browser or with a screen reader.

## Deployed 2026-10-16 18:58 CT
From 4.1.0 with .audit_work/deploy-4.2.0 (18 files). mig_4.2.0.sql ran (3 statements); all 3 new columns present. Production files matched 4.1.0 before install and match 4.2.0 after; .htaccess unchanged. Smoke checks passed; health reports 4.2.0. Backups: /home/cmsuser/casemanagement_private/deploy-4.2.0-20261016-185842.
