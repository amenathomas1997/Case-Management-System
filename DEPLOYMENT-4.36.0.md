# Release 4.36.0 — November 19, 2026

Web 4.36.0, iPhone app 4.36.0 (build 67), Android app 4.36.0 (version code 20). No database change; the database stays at 4.33.0.

**Not deployed with deploy.sh.** It is published as the GitHub release v4.36.0, and an administrator installs it with Administration, Software update. The phone apps are built from this tag.

**Why.** This is the third release of the app parity work in PARITY-AUDIT-2026-11-19.md, covering the daily workspace and safety. The plan is in that document under Progress, 4.36.0.

**Maintainer decisions taken with defaults (2026-11-19)**
- **Signing in person:** administrators are now told when a client signs in person, as they already were for signing from an emailed link. One shared helper does this for the website and the apps.
- **Phones signed in:**
  - Signing out another phone needs no password, as on the website.
  - **Report lost and wipe** from the app now needs the person's current password. A lost phone that is still unlocked is the device most likely to be misused, and a wipe deletes entries not yet sent. The website is unchanged.
  - The phone in use can never be wiped from itself.
- **Signing in with Microsoft inside the apps** is deferred to its own release, with its own security review. Until then, the sign-in screen tells Microsoft-only staff how to get a password.

**What changed, in both apps**
- **Dashboard:**
  - Today, My tasks and Your week.
  - The website's banners for agreements, training and credentials, in the website's order and wording.
  - Expiring items open the client.
- **Tasks:**
  - Search and the due filter, with the count said once.
  - Add and edit with the website's fields; delete; complete several at once.
  - Visible buttons for opening the client or the referral.
- **Alerts:**
  - Each alert opens what it is about.
  - Undo after marking alerts read is offered for 15 minutes, and works for any number of alerts.
- **Messages:** last-message previews, unread counts, the counselor-unconfirmed notice, and whether the client was emailed.
- **Follow-ups:** the website's four totals and the recently recorded list.
- **Compliance:** every website section, in order, with its counts and its explanations.
- **Safety:**
  - Phones signed in, with Sign out and Report lost and wipe.
  - Report a privacy incident, which can also start from a lost phone.
- **My account:**
  - your mobile number, and how alerts reach you
  - removing a passkey
  - My availability
  - your calendar and meeting connections (read-only, with a link to the website)
  - in the website's order
- **Incidents:** the client is optional, and closed clients can be chosen.
- **Agreements and training:** your signed copy, its corrections, and the training count.
- **Credentials:** notes, and the website's checks.
- **Sign-in:**
  - the Microsoft option
  - a link to reset your password
  - the code step's website wording

**Website changes**
- Administrators are told when a client signs in person.
- The Schedule has a Check in button.
- The Follow-ups page fits a 320-pixel screen at 200% text. Its buttons and boxes used to run off the edge; this was found by the browser check once the tests put a due follow-up on that page.
- An invalid alert-email choice is refused with a message, instead of being saved as off.

**Shared code.** The website pages and the API call the same helpers:
- new files: inc/dashboard.php, alerts.php, messages_list.php, device_followups.php, compliance_data.php
- additions to records_privacy.php, account_actions.php, self_booking.php, tasks.php, staff_agreements.php, staff_credentials.php, signatures.php and session_changes.php

The new API actions are in inc/api_436.php. The API's own copies for compliance, consents, marking alerts read and check-in are gone.

**Older apps.** 4.35.0 apps keep working.
- A 4.35.0 app that saves a credential still gets the old behaviour: long values are cut to length, not refused.
- The compliance reply keeps every key older apps read.

**Tests**
- New: tests/v436_cases.php (122 checks), iPhone static checks (221 in all, which also read the server files), android V436Test.kt.
- Checked before commit:
  - full_schema (3186), audit_regression, security, staging_scrub and run_all pass
  - the browser accessibility check passes on 370 pages
  - iPhone: builds with no warnings
  - Android: build, 198 unit tests and lint (0 errors) pass

**Review.** Three independent reviews (server, iPhone, Android) found no High problems. Everything they found was fixed before commit:
- **Server:**
  - **Wiping a phone:** Report lost and wipe now needs a password.
  - **Undo after "mark all":** it restored only 100 alerts; it now restores all of them.
  - **Permissions:** they are checked before a retry is answered.
  - **Unmigrated servers:** privacy reports get a clear refusal.
  - **Retries:** deleting a task is now safe to retry.
  - **Older apps:** their credentials are cut to length again, as before.
  - **Consents:** the consents list is lighter.
  - **Yes/no values:** they are read consistently, and a missing mobile number never removes the stored one.
- **iPhone:**
  - a typed mobile number was lost when another setting was saved
  - the undo button outlived its 15 minutes
  - the count on Complete several tasks could be wrong
  - the privacy form now asks before discarding
  - the Microsoft line follows a newly typed server
  - a required mark was missing
  - results are shown as well as spoken
  - one bad row no longer blanks Compliance
- **Android:**
  - compliance rows said their name twice
  - the privacy-report offer now follows the permission, and is cleared after filing
  - the privacy form now asks before discarding
  - texting follows the server switch
  - a bad alert link could crash the app
  - tasks opened from Today now have Back
- **Both apps:** the order, wording and confirmations were made the same as each other and as the website.

## Published (not deployed)

2026-11-19, after CI passed all three jobs on 3c1c342. The Android job's first attempt stopped before building because GitHub limited the CI machine's downloads; the rerun passed. The GitHub release v4.36.0 (latest, not a pre-release) carries:
- the install bundle, built from a clean checkout of the tag, including the six new shared files
- the signed update package: 353 files, verified

**Rehearsed:** installed with the 4.35.0 updater onto a copy of a 4.35.0 site.
- The signature verified, the plan showed no problems, and the backup was made first.
- 36 files were replaced or added (the new shared files are present), with no database change.
- The sign-in page then served 4.36.0.

The live site stays as it is until an administrator installs it with Software update. The 4.36.0 phone apps are built from this tag.
