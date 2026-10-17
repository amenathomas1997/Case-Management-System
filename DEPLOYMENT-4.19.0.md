# Release 4.19.0 — October 17, 2026

Web 4.19.0, Android app 4.19.0 (versionCode 8), iPhone app 4.19.0 (build 55). No database change: the database stays 4.17.0. Settings rows are written as needed: errors_cursor and errors_alerted_at.

The owner asked for all the production-readiness work (2026-10-17), and for a default time zone so that everything, update checks included, shows in the company's main time zone.

**1. The client's state at intake**
- **Website client form:**
  - Required for a new client, and for one whose state is already known.
  - An older record with none can still be saved and closed, and Data quality lists it.
- **Public referral form:**
  - "Your state" or "Their state" is asked of every referrer, self-referrers included, outside the role sections.
  - It is marked required for screen readers and has Spanish text.
- **API:** accepts state and time zone, and refuses an empty state only when one is sent. Older apps that send none still save. It is saved in the same transaction as the rest of the client.
- **Apps:** a state picker, required on the same rules as the website, and sent only when chosen. An app talking to an older server is never blocked.
- **Spreadsheet import:** a State column (IA or Iowa). A merge carries the duplicate's state.

**2. Page error alerts**
- **What is recorded:** pages and jobs that fail, kept in the private folder as the kind of failure, file:line and page name only, never the message.
- **What is left out:** bad input from callers, such as an array where text belongs.
- **Floods:** the same failure in the same place is recorded once a minute, under a lock that also covers rotation.
- **Alerts:** the watchdog emails new errors at most once an hour. Its cursor moves only once an email went, so none is missed when mail fails.
- **System health** lists the last 7 days.

**3. The time zone**
- Settings now has its own Time zone section, at the top, with the zones by name (Central, Eastern…).
- System health names the zone in use.
- "Last checked" for updates is shown in the zone, with its abbreviation.
- Help and the staff guide give the texting hours for clients with no state in the organisation's own time.

**4. Smaller changes**
- Check for updates says when checking is off and how to turn it on. The live site's check is off: set Settings, Updates to github.
- The installer's off-site cron line runs nightly (45 2 * * *), not on Mondays only. A test now checks every installer cron line against the job's own schedule.
- **Install bundle:** it now carries restore.php, README.md, RUNBOOK.md, SECURITY.md and RISK-ASSESSMENT.md. Help says to run restore.php from the bundle's folder. restore.php refuses an option given twice.

**5. Recovery and assurance**
- **Restore drill** (tests/restore_drill.php, in run_all): on synthetic data, the whole way back from an off-site backup. It uses its own databases, named per run, and honours port= in the DSN.
- **Installer test** (tests/installer_e2e.sh): after a fresh install from the bundle, it now applies a signed update package of a made-up next version through the updater. Over HTTP, the new pages are served and the site still works. The site's config_path.php is kept, the jobs in the installer's cron folder are updated, a backup is kept, and no install is left unfinished.
- **RUNBOOK.md:** what to do when the site does not open, an update goes wrong, the server is lost, a device or key is lost, or email or texts stop.
- **RISK-ASSESSMENT.md:** a starting HIPAA-style security risk assessment, listing the safeguards and what each organisation must do itself.

**Tests:** tests/v419_cases.php, 23 checks:
- **The state:** web new client refused or saved; older records saved; a known state cannot be emptied; referral refused without it; a self-referral taken with it; the field outside the role sections; the API from new and old apps; data quality.
- **Errors:** recording, the once-a-minute limit, the alert cursor and hourly limit, System health.
- **The rest:** the update-check message, the installer cron lines, restore.php in the bundle, the Settings time zone section, "last checked" with the zone, and System health naming it.
- Also the restore drill, and the updated installer test.

**Review:** every finding was fixed:
- **Self-referrals were impossible:** the state sat in a section hidden from self-referrers.
- **Older stateless clients could not be saved or closed.** Neither import nor merge carried the state.
- **Bundle and docs:**
  - restore.php was missing from the bundle.
  - Installer sites sent off-site copies weekly.
  - The docs named a "Sign out everywhere" control that does not exist. They now say to switch the account off, which ends every session.
  - The docs said the PHP error log is free of client data. It isn't, and now they say so.
- **Watchdog throttling** sent too often after a quiet run, and could miss errors when mail failed.
- **The error log:** a bot could flood it, and the rotation race is fixed.
- **Apps on older servers** were blocked by the picker.
- **The restore drill** made a stray folder, and used fixed database names.
- **Smaller:**
  - the required attribute on both state fields
  - the API saving state outside the transaction
  - missing-key safety in the error summary
  - three doc claims corrected

## Deployed

2026-10-17 14:52 CT with `.audit_work/make_deploy.sh 4.18.0 4.19.0` and its deploy.sh, after CI passed all three jobs on eb524a2. Every replaced file matched 4.18.0 first. The website, cron folder and database were backed up to /home/cmsuser/casemanagement_private/deploy-4.19.0-20261017-145212. There was no database change. Every installed file matches the release. health.php, updates.json and the sign-in page report 4.19.0.

Published as the GitHub release v4.19.0 (latest). It carries:
- the install bundle, which now includes restore.php and the four documents
- the signed update package, verified with the built-in key

A site on 4.18.0 with Settings, Updates set to github can now install 4.19.0 with the button. This is the first real one-button update path.

Also checked on the Android emulator: an older client with no state shows "State, not recorded yet". Choosing Iowa and saving stored IA.
