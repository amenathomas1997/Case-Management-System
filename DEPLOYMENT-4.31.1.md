# Release 4.31.1 — October 20, 2026

Web 4.31.1, iPhone app 4.31.1 (build 63), Android app 4.31.1 (version code 16). No database change: the database stays at 4.31.0.

**Not deployed with deploy.sh.** It is published as the GitHub release v4.31.1, and an administrator installs it with Administration, Software update. The phone apps are built from this tag.

**Why.** On 2026-10-20 the maintainer asked:
- that the newer Android Studio build tools be tested and included in the next release
- that the GitHub repository be a plain product anyone can download and install, with no organisation's name in it

**Maintainer's decisions (2026-10-20)**
1. **History:** rewritten in the same repository, so installed sites keep receiving updates from it. Earlier releases are replaced by this one.
2. **Author:** every commit is credited to the maintainer's GitHub private (noreply) address.
3. **Internal documents** (audits, release notes, deployment notes) stay public, with names and addresses removed.

**What changed**
- **Website:**
  - No organisation's name, logo, domain or address in the code. The organisation name, logo and app icon come only from Settings.
  - The installer offers a plain clipboard icon until an administrator uploads their own.
  - The sign-in cookie is now `cmssession` and the hand-over cookie `cms_handover`, so **everyone is signed out once** after the update.
  - The low-vision setting moves to a neutral browser key on its own, so nobody loses it.
- **iPhone:**
  - The project is now CaseManagement (CaseManagement.xcodeproj; schemes CaseManagement, CaseManagementWidget, CaseManagementUITests).
  - Neutral defaults: bundle ID org.example.cms, name "CMS", no default server (the app asks for it), plain icon.
  - An organisation's own bundle ID, team, name, server and icon go in a git-ignored ios/Private.xcconfig, which ios/Overrides.xcconfig includes when it exists.
  - A home-screen widget may need adding again.
- **Android:**
  - The same neutral defaults. An organisation's own values go in a git-ignored android/private.properties (cms.* keys) and icons in android/private-res/.
  - Build tools: Gradle 9.4.1, Android plugin 9.2.1, Kotlin 2.2.10, the foojay toolchain plugin and gradle-daemon-jvm.properties. CI builds with JetBrains Java 21.
- **Store materials:** the Play Store graphics and App Store notes moved out of the repository. PLAY_STORE.md and TESTFLIGHT.md now explain how any organisation publishes its own build.
- **App kit:** the downloadable app source no longer carries any private file; tests/v418 checks this.
- **Repository:** local working notes (.audit_work/) and local Claude Code settings (.claude/) are no longer tracked.

**Tests**
- Checked before commit:
  - full_schema (2803, with the new tests/v4311_cases.php), audit_regression, security, staging_scrub and run_all pass
  - the browser accessibility check passes on 360 pages
  - iPhone: builds with no warnings, ios_api_regression passes (97)
  - Android: build, unit tests and lint (0 errors) pass, with the new build tools
- A search of every tracked file, file name and packaged binary finds no trace of the former name.

**Review:** an independent review found no High problems. It found these Low ones, all fixed before commit:
- **Hand-over marker:** a browser handed over for signing just before the update lost its marker with the cookie rename. A marker under the earlier name now still holds until it expires, and a full sign-in removes it.
- **iPhone:** document previews kept in the folder under the former app name are now removed too.
- **Android:** a version number in private.properties would have frozen the app's version; the version now always comes from gradle.properties.
