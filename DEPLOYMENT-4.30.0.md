# Release 4.30.0 — October 20, 2026

Web 4.30.0, iPhone app 4.30.0 (build 61), Android app 4.30.0 (version code 14). Apply dist/migrations/mig_4.30.0.sql, or use Database upgrade; Software update applies it on its own. It adds:
- surveys.answer_lang (the language a survey was answered in)
- schema_version 4.30.0

**Not deployed with deploy.sh.** It is published as the GitHub release v4.30.0, and the owner installs it with Administration, Software update. The phone apps are built from this tag.

**Why.** On 2026-10-20 the owner asked for a check of the whole system for accessibility issues, then to fix them all. The audit is ACCESSIBILITY-AUDIT-2026-10-20.md: 2 High, about 36 Medium, about 70 Low.
- **How it was done:** eight auditors, including an axe-core scan of every rendered page in every colour palette and keyboard-only task walkthroughs in a real browser.
- **What it found that passes:** contrast in every theme. Nothing blocked a blind client from signing, booking, answering a survey or sending a referral.

**High**
- **A1. The result of a save is read out every time.**
  - **Problem:** after about 180 kinds of save the page returns to a section (a #fragment), and Chrome's jump there moved focus off the success message or error summary. So JAWS often never said "Hours logged" or why a save was refused.
  - **Fix:** the message now takes focus after the page loads and the jump is done, exactly once. That includes a jump to a heading or table row the browser focuses itself, such as the Schedule rows.
  - **Pages updated:** every page that moved focus to its own message.
  - **Test:** the browser check now follows 17 such redirects.
- **A2. The "Unsaved changes" dialog stops interrupting clients.**
  - The language switch no longer asks, unless it would lose a chosen file or password.
  - Sending a form after only picking from a list no longer asks.
  - The dialog is in Spanish on Spanish pages, and its buttons say what they do.

**Medium (selected)**
- **Website, shared parts:**
  - The Text size and spoken-loudness lists act once, not on every arrow press.
  - The "Type date instead" button no longer adds a long extra stop; the date format and errors are inside the field's label, in Spanish too.
  - Buttons and lists in tables no longer squeeze until unreadable.
  - The main menu has its own heading.
  - The signed-out notice is read once.
  - Required marks say "(obligatorio)" on Spanish pages.
- **Sign-in:** a wrong code is announced through a focused error summary.
- **Public pages:**
  - Custom referral questions are marked English on the Spanish form.
  - The referral role choice is announced once.
  - Spanish decline emails and session reminders.
  - An edited English email template no longer replaces the Spanish one.
  - The portal reads dates in Spanish, and each booking time names its day.
  - The accessibility statement is in Spanish.
- **Staff pages:**
  - The Schedule track list shows only the chosen client's tracks, by name.
  - Session outcome choices are short, with the hours already logged inside the label.
  - Note links and headings include the date.
  - Lists and referrals have headings with counts.
  - Required fields are marked for screen readers.
  - Confirmations name the item.
  - A refused move points to the "Move anyway" box.
- **Billing, reports and admin:**
  - **Generated PDFs** (invoices, funder and outcome reports) read in order and carry a title and language. Each prints a link to an accessible HTML copy the agency can open without signing in, for 90 days.
    - The link is signed and expires (accessible_copy.php, HMAC with the private key file).
    - Copies are removed when their client is purged.
  - Error summaries replace flash-only refusals on many admin forms.
  - Option labels are plain names.
  - Survey comments answered in Spanish are marked Spanish.
- **iPhone:**
  - Dark-mode contrast on Check in and Sign the agreement.
  - Visible buttons for actions that were swipe-only (kept on the Actions rotor for VoiceOver).
  - Errors tied to their fields, read once, and announced if VoiceOver can't land on them.
  - Steppers keep + and − for Voice Control and Switch Control.
  - Layouts for the largest text sizes.
- **Android:**
  - Each screen is announced.
  - Errors take focus and list every problem.
  - Save buttons explain what is missing instead of staying dimmed.
  - Password managers can fill sign-in.
  - Translated AI text is read in its language.
  - Long pick lists have a filter.
  - Layouts work at 200% font.
  - No white flash in dark mode.

**Tests**
- New PHP cases: tests/v430_shared_cases.php, v430_public_cases.php, v430_cases_cases.php and v430_admin_cases.php.
- tests/accessibility_browser.js now checks:
  - focus after #fragment redirects, including targets the browser focuses
  - the language switch and the Spanish dialog
  - no split button words or clipped lists at 320 and 1280 px
- New iPhone regression checks and Android V430Test.
- The iPhone local accessibility audits now visit the everyday screens.
- Checked before commit:
  - full_schema (2759), audit_regression, security, staging_scrub and run_all pass, and the migration rehearsal matched
  - the browser accessibility check passes on 360 pages
  - iPhone: builds with no warnings, ios_api_regression passes (78)
  - Android: build, unit tests and lint (0 errors) pass

**Review:** two independent reviews found these, all fixed before commit:
- **Focus after a save (most important):** focus was given back to a heading or row the browser had focused, after the message. Now nothing takes focus back once the message has it.
- **Equipment scan page:** a loan refused there was silent.
- **Schedule:** the hours already logged sat outside the outcome list's label.
- **Portal:** times on different days sounded the same.
- **Language switch:** it could drop a chosen file.
- **Accessible copies:** they outlived a purged client.
- **iPhone:** an error could be silent under a sheet or on a scrolled Alerts list.
- **Android:**
  - the "Saved" message was cut off by the screen title
  - a fast load error was lost
  - pressing Save again was silent
  - the "still needed" text went stale
  - moving between two clients didn't announce
  - long labels were cut at large font

**Owner's own Android Studio change:** the build-tool upgrade found uncommitted in android/ (Gradle 9.4.1, Android plugin 9.2.1, Kotlin 2.2.10) was set aside in a git stash during this release, so 4.30.0 builds with the same tools as CI. It was put back afterwards, uncommitted, for the owner to decide on.

## Published (not deployed)

2026-10-20, after CI passed all three jobs on 445ae79. The GitHub release v4.30.0 (latest, not a pre-release) carries:
- the install bundle, built from a clean checkout of the tag
- the signed update package: 333 files, verified

**Rehearsed:** installed with the 4.29.0 updater onto a copy of a 4.29.0 site.
- The signature verified, the plan showed no problems, and the backup was made first.
- The database change ran (2 statements; database version 4.30.0, surveys.answer_lang present).
- 86 files were replaced, including the scheduled jobs and the new accessible_copy.php.
- The sign-in, signing and survey pages then served at 4.30.0.

The live site stays as it is until the owner installs it with Software update. The 4.30.0 phone apps are built from this tag.
