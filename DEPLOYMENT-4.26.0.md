# Release 4.26.0 — October 18, 2026

Web 4.26.0, iPhone app 4.26.0 (build 57), Android app 4.26.0 (version code 10). **No database change** (schema stays 4.25.0).

**Not deployed with deploy.sh.** It is published as the GitHub release v4.26.0, and the owner installs it with Administration, Software update. The phone apps are built from this tag.

**Why.** On 2026-10-18 the owner asked for four things:
1. finish the Android catch-up
2. signing with no signal
3. testing Android against real server replies
4. a hands-on accessibility pass on the new iPhone screens

**1. Android has everything the iPhone app has**
- **On a training track:** work logs with Correct and Delete, track notes, the progress report email, and expense deletion.
- **Goals:** listing goals, adding them from templates, recording progress, and progress history.
- **Equipment:** details with value and book value, the service interval, logging a service, adding equipment, and reservations with waitlisting.
- **The action center and the AI case briefing.**
- **Help, glossary and privacy.** Every link the server gives is opened only if it is on the same site.

**2. Signing with no signal (both apps; server: inc/signatures.php)**
- **Before the visit:** while online, "Prepare to sign with no signal" starts the document and keeps it on the phone, protected like the other kept client data. A phone with no passcode or screen lock refuses.
- **At the visit:** the client signs it with no signal. The phone first checks the typed name exactly as the server does, so a signer is never told "signed" for a name the server would refuse. It then keeps the signature and the time it was signed.
- **Afterwards:** the signature is sent when the connection returns: on app start, on return to the app, when the network comes back, and from the screen.
- **Server rules:**
  - A document can be signed within two days of when the server started it. The apps count those two days from the server's start time (sent in start_signature's reply), less a minute, and check again when Sign is pressed.
  - A phone clock a little off is held between the document's start and the time the signature arrived; a time more than a day outside that is refused.
  - A signature must arrive within a week of being signed, so a document cannot be backdated to slip inside the window.
  - A signature sent twice is recorded once.
  - The case note says it was signed on the phone with no connection, and when it was received.
- **If the server refuses one,** its words stay with the item for staff; it is never silently lost.
- **Handing the phone back:** the staff unlock works the same offline.

**3. Android tested against the server's real replies**
- **The samples:** 37 real replies (synthetic data), in android/app/src/test/resources/api, written by `CMS_WRITE_ANDROID_FIXTURES=1 php tests/full_schema.php`.
- **The Android side:** unit tests (ApiContractTest) run each sample through the same parsing functions the screens use (Parsing.kt).
- **The server side:** every server test run checks that the replies still carry every field the samples have. A renamed or dropped field fails the server's tests before it can break the app.

**4. The iPhone accessibility pass** (ios/UITests; local runs only, never in CI or release builds)
- **Setup:** XCUITest accessibility audits (performAccessibilityAudit) on every new screen, at default text and at the largest accessibility size. They run on a fresh simulator, signed in to the local test server through an HTTPS proxy.
- **Fixed in the app:**
  - a 28×19 point button, now 44×44
  - grey values at about 4:1 contrast, now 7:1
  - grey section headings
  - pickers cutting their text in half at large sizes (they now open as lists there)
  - text fields with no accessibility label
- **False positives:** each one filtered is named in the test, with the reason.

**Verified end to end**
- **Android emulator, local server:**
  - a document prepared online
  - the emulator's network turned off and the document signed
  - the network turned back on, and the signature arrived within 30 seconds with the time it was signed on the phone
- **iPhone simulator, local server, with three UI test steps run from scratch:**
  - prepared online
  - signed with the server unreachable (proxy stopped), with nothing reaching the server
  - sent once the server was back, with the case note recording the phone's signing time and the later arrival

**Tests**
- tests/v426_cases.php: offline signing rules, and the Android contract check.
- Checked before commit:
  - full_schema (2084), audit_regression, security, staging_scrub and run_all pass
  - Android: build, 45 unit tests and lint (0 errors) pass
  - iPhone: builds with no warnings, and ios_api_regression passes

**Review:** every finding was fixed.
- **Backdating:** the two-day window could be skipped by a signing time just after an old document's start. The window is now bounded by when the signature arrives (within a week), and the signing time is held to the document's start and to now.
- **"It is signed" but later refused:** the apps could say this when the server would refuse. They counted the window from the phone's time, not the server's, and did not recheck at the moment of signing. Both are fixed in both apps.
- **Android signatures lost:**
  - Android deleted unsent signatures when the password changed, on Sign out everywhere, or when another account signed in.
  - They are now kept for their account, as the offline queue is, and every such path warns first.
- **Android double sends:** a double tap could send twice; Send report and the other new send buttons are now marked busy at once.
- **The contract check:**
  - an action that stops answering now fails
  - every sample must be checked
  - a field whose kind changes fails
- **iPhone accessibility styles:**
  - list-style pickers at large sizes are only used inside a navigation stack
  - the style is chosen once, so changing the text size does not reset a form
  - the app-wide label style that reached into pickers was replaced by a colour-only setting
  - the audits were rerun at both sizes and pass

## Published (not deployed)
