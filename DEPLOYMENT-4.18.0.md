# Release 4.18.0 — October 17, 2026

Web 4.18.0, Android app 4.18.0 (versionCode 7), iPhone app 4.18.0 (build 54). No database change: the database stays 4.17.0. Settings rows are written as needed: appkit_* and update_downloaded.

The owner's requests on 2026-10-17:
- texts arriving outside business hours for clients with no state
- Play Store and TestFlight preparation
- downloadable custom apps for other installations
- updates from the live GitHub repository with the update button
- what other companies need to pull, install, customise and update

**1. Texts for clients with no state or time zone** (inc/sms.php)
- Such a client could be anywhere in the US. Texts go only while it is 8 a.m. to 8 p.m. in every zone from Puerto Rico to Hawaii: 1 to 7 p.m. Central in summer, 12 to 6 p.m. in winter.
- The client's record and the held-text message say so in office time, with the day when it is not today.
- Adding the state lifts the limit.

**2. Store preparation**
- **Android:**
  - Now targets Android 16 (API 36), which Google Play has required since August 31, 2026.
  - Build tools moved to AGP 8.11.1 and Gradle 8.13. Release signing comes from a git-ignored keystore.properties. R8 needed rules for the encryption library's compile-time annotations.
  - CI now also builds the shrunk release bundle.
  - android/PLAY_STORE.md has the steps and answers, and android/play-store/ has the icon, banner and five screenshots of made-up data.
  - App names with an apostrophe ("St. Mary's") are escaped for Android.
- **iPhone:**
  - The widget has its own privacy manifest, and the app's manifest lists the data it collects.
  - ios/testflight.sh and ExportOptions.plist archive and upload, and ios/TESTFLIGHT.md has the steps and App Privacy answers.
  - The Release archive builds unsigned here; your Apple account was not touched.

**3. Phone apps for other installations** (Administration, Phone apps)
- Downloads the apps' source (inc/appkit/app-source.zip, made by dist/make_appkit.sh) set to the installation's:
  - name, spoken name, sign-in title and app ID
  - Apple team and server
  - uploaded icon, made opaque for Apple
- BUILD-YOUR-APPS.md comes with it: Play internal testing, Managed Play, direct APK, TestFlight and Apple Business Manager.
- App IDs are lower-case letters and numbers, since Android refuses hyphens and Apple refuses underscores.
- Kits for two made-up organisations, one named "St. Mary's AT", built on both platforms with their own names and IDs.

**4. One-button updates from GitHub** (Administration, Software update; inc/updater.php)
- Set Settings, Updates to `github`. New installations start that way.
- A release carries cms-update-X.zip and its .sig, made by dist/make_update.php with the private key in private/ (never committed).
- The package is used only if it verifies against the RSA-3072 key built into the code. Its manifest lists every file's SHA-256 and the signed release notes. It also lists the earlier stock copies of .htaccess and .user.ini, the files removed since earlier releases, and which scripts must stay executable.
- **Installing, in order:**
  1. Every new file is written beside the old one and checked.
  2. Everything it replaces or removes is copied to a backup.
  3. The database changes run.
  4. Renames swap the files in. Files already current are skipped.
- **When things go wrong:**
  - A failure puts the old files back.
  - An install the server cuts off leaves a marker, and the page then offers to put the previous files back.
  - One install runs at a time, and it keeps going if the browser closes.
- **What it never replaces:** each site's configuration, icons, cron settings, and its own changed .htaccess or .user.ini.
- **Limits:** packages are capped at 50 MB, and the three newest backups and packages are kept.
- The test hooks work only from the command line, never on a web server.
- README.md is rewritten as a "run your own" guide, SECURITY.md says how to report a problem privately, and the install bundle's README.txt mentions the button.

**5. Restore drill** (tests/restore_drill.php, in run_all)
- On synthetic data: a database dumped as the nightly job does and packed by the off-site job's own function, then restored with dist/restore.php into an empty database.
- All 93 tables, the records with accents and quotes, and the document come back, and config.php is private.
- A wrong password restores nothing.

**Tests:** tests/v418_cases.php, 36 checks:
- **The kit:** in step with the sources, values refused or saved, icons, and the audit.
- **The updater:** finding and downloading; refusing tampered, other-key and unsafe packages; the plan; installing; site files kept; removal; an executable script; a failure changing nothing; the marker and putting files back; stock and own .htaccess.
- **Quiet hours:** in tests/v417_cases.php.
- Also the restore drill.

**Checks before commit:**
- full_schema 1825, audit_regression, security, staging_scrub and run_all (with the restore drill) pass.
- Android: debug and signed release (test key) build. The release APK starts on the emulator, and the debug build targeting API 36 signs in to the local server.
- iPhone: builds and archives.

**Review:** every finding was fixed, except those accepted below.
- **An install cut off part way** left a half-updated site. It now writes every file first, then renames, and leaves a marker with an offer to undo; it locks, and ignores the browser closing.
- **A stock .htaccess was never updated.** Earlier stock copies are now in the signed manifest. .user.ini is treated the same way.
- **Files removed upstream stayed.** They are now removed, with a backup.
- **The test hooks worked on web servers.** One of them swapped the signing key. They are now command line only.
- **The write check** tested the file, not its folder.
- **A failed read could write an empty file.** Every file and migration is now checked against its SHA-256.
- **Other fixes:**
  - opcache (files invalidated; a restart is suggested when needed)
  - a 50 MB cap
  - signed notes
  - pruning old backups and packages
  - refusing "." paths and symlinks
  - keeping the executable bit
  - the held-text date
  - Android apostrophes
  - the Play health declaration wording
  - the widget's privacy entry (it shares a file, not settings)

**Accepted:**
- Whoever controls the GitHub repository can hide newer releases or offer an older signed one that is still newer than a site. They cannot install anything unsigned.
- A site with no recorded database version runs every shipped migration, as Database upgrade always has.
- Clients without a state get the narrow texting window until their state is added. 4.19.0 asks for it at intake.

**Owner actions:**
- Back up private/update_signing_key.pem to your password manager. Without it, no update can be published.
- Turn on private vulnerability reporting (repository Settings, Security).
- Store accounts and uploads: see PLAY_STORE.md and TESTFLIGHT.md.
