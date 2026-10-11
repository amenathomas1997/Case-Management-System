# WCAG 2.2 AAA audit — October 1, 2026

## Outcome and scope

**AAA conformance is not established.** This review found and repaired the defects below, with source inspection, numerical contrast checks, executable regression tests, and browser testing of the actual shared layout rendered with synthetic content. It is not a complete assessment of every authenticated page, role, data state, uploaded document, or native iOS screen.

Baseline: [WCAG 2.2 Recommendation](https://www.w3.org/TR/WCAG22/). AAA includes applicable A and AA criteria as well as AAA criteria, across complete pages and processes. A passing component test does not establish whole-site conformance. The earlier September audit and the October functional fixes remain in the workspace.

No production data was loaded, no external messages were sent, and no deployment was performed.

## Verified defects and repairs

| Finding | Criteria affected | Repair and evidence |
|---|---|---|
| Selection changes immediately submit five forms, causing navigation or writes before a user explicitly requests them. | 3.2.2 A; 3.2.5 AAA | Removed automatic submission in contracts, curriculum, accommodations, procurement, and access bookings. Exposed explicit submit buttons. The contract Save status button now sends the action name its server handler requires; the prior automatic submit omitted it. |
| The voice assistant could overlay focused page content; its panel was poorly suited to narrow/zoomed screens. | 1.4.10 AA; 2.4.12 AAA | Moved the assistant into document flow and allowed its header to wrap. Verified panel layout at 320 CSS pixels, including 200% text with expanded spacing. |
| Opening the assistant also starts the microphone; saved conversations automatically restart on navigation; close does not always stop conversation mode or server-generated speech. | 1.4.2 A; 2.2.4 AAA; 3.2.5 AAA | Added explicit Use microphone and Stop speaking controls. Opening focuses the typed input. Closing/Escape ends conversation, stops audio, updates expanded state, and restores focus. Saved history remains available without restarting capture. Unit checks cover opening, explicit microphone use, stopping, and Escape. |
| Small buttons, standalone row links, and some clickable labels do not guarantee 44×44 CSS-pixel targets. | 2.5.5 AAA | Added minimum dimensions for buttons, summaries, row actions, and checkbox/radio labels. Browser measured all visible fixture buttons at least 44×44; checkbox's clickable label was 704×44.99 at desktop width. Inline prose links retain the criterion's inline exception. This is not an inventory of every target in every page. |
| A client card's light fallback background remains light in dark mode, making inherited light text unreadable. | 1.4.3 AA; 1.4.6 AAA | Defined theme-specific secondary surfaces. Browser measured dark card colors as #ececf4 on #262636, 12.65:1. |
| Configured branding can defeat text contrast; placeholder colors were partly browser-dependent. | 1.4.6 AAA | Validate six-digit brand colors and fall back when they fail 7:1 against shared light surfaces. Header colors also account for translucent display buttons. Explicit placeholder colors. Numerical tests include supporting text at 7.62:1 and placeholders at 7.46:1. Not a proof for every combination of inline styles, user content, and branding outside the shared web layout. |
| Search/time/URL controls have inconsistent shared styling; grid children, navigation labels, and email addresses can overflow under zoom and spacing changes. | 1.4.4 AA; 1.4.10 AA; 1.4.12 AA | Added control styling, shrinkable grid tracks, and wrapping. A browser reproduction initially measured 425-pixel document width at a 320-pixel viewport with 200% text and spacing overrides. After repair it measured 320 pixels. Data tables retain their own horizontal scrolling. |
| Paragraph spacing was below the enhanced presentation target. | 1.4.8 AAA | Increased default paragraph separation relative to line height; retained bounded paragraph measure and user-agent zoom support. Added forced-colors focus/border rules. User-agent color customization and real content still require broader validation. |
| Skip-link destination was not explicitly focusable; an assessment View link and knowledge Edit link depended on surrounding content for meaning. | 2.4.1 A; 2.4.9 AAA | Main has tabindex=-1. Keyboard activation in the browser placed focus on main. Added assessment/date and article-title context to link names. |
| Activity keepalive waits four minutes even when the configured session expires sooner. | 2.2.1 A | Calculate a shorter keepalive interval from the warning deadline. Regression verifies activity triggers a request before the old threshold. Security timeout remains in effect. |
| Redirect-based draft recovery only saves string values, omits files/arrays, and is not honored by every form, despite broad claims that all input is retained. | 2.2.5 AAA; 3.3.7 A | Added a pre-submit session check to normal CSRF-protected staff POST forms. On expiration/offline failure it leaves the live form and files intact and focuses recovery guidance. Re-authentication in another tab is followed by a fresh CSRF token and the original submit action. Tests cover arrays/files, refreshed token, action preservation, offline failure, and duplicate-click suppression. Narrowed recovery wording. Public token flows, custom fetch handlers, JavaScript-disabled operation, and page reload/close are not covered by this mechanism. |
| Help and common acronym explanations are not consistently available. | 3.1.3/3.1.4/3.3.5 AAA; 3.2.6 A | Added a shared Help and glossary link and plain-language guidance. Includes forms, navigation, session recovery, and common terms. The English link is language-marked on Spanish pages. This is a starting glossary, not a complete review of domain-specific or user-authored language. |

## AAA criteria coverage

“Partial” means positive evidence or repairs in the reviewed components, with more coverage required. “Unverified” is not a pass. “Not observed” describes the inspected source, not future uploads or embedded content.

| AAA criterion | Assessment |
|---|---|
| 1.2.6 Sign Language (Prerecorded) | No prerecorded video was observed in the inspected web templates; attachments not assessed. |
| 1.2.7 Extended Audio Description | Same media coverage limitation. |
| 1.2.8 Media Alternative (Prerecorded) | Same media coverage limitation; voice responses retain text. |
| 1.2.9 Audio-only (Live) | Microphone requests use text recognition and typed alternatives; external live sessions not assessed. |
| 1.3.6 Identify Purpose | Partial: shared landmarks, labels, and controls inspected; full role/page semantics unverified. |
| 1.4.6 Contrast (Enhanced) | Partial: tested shared palette and repaired branding/secondary surface; full rendered-state inventory required. |
| 1.4.7 Low or No Background Audio | No background soundtrack observed; voice output has stop controls. Uploaded audio unverified. |
| 1.4.8 Visual Presentation | Partial: spacing, bounded prose, zoom, wrapping, themes, forced-colors support; real long-form content and user-agent color settings unverified. |
| 1.4.9 Images of Text (No Exception) | No new images of text introduced; uploaded scans, QR labels, signature images, and resources need contextual review. |
| 2.1.3 Keyboard (No Exception) | Shared skip link/voice controls checked. Complete authenticated flows and external editors unverified. |
| 2.2.3 No Timing | **Open:** sign-in codes and sessions have time limits. Do not assume a security rationale alone satisfies this criterion. Evaluate a complete untimed equivalent authentication/workflow path; security settings were not disabled. |
| 2.2.4 Interruptions | Partial: removed automatic voice restart; timeout warnings and other live updates still need user-controlled interruption review. |
| 2.2.5 Re-authenticating | Partial: improved normal staff POST forms; actual end-to-end re-authentication, custom fetch/public flows and no-JavaScript recovery remain unverified. |
| 2.2.6 Timeouts | Staff inactivity duration is disclosed at sign-in and in help; public token lifetimes/data-loss warnings need separate review. |
| 2.3.2 Three Flashes | No deliberate flashing found in inspected templates; media/uploads require review. |
| 2.3.3 Animation from Interactions | Existing reduced-motion rule suppresses animations and transitions; OS preference not exercised in this browser run. |
| 2.4.8 Location | Shared current-page navigation exists; nested record paths/breadcrumb equivalents require full process review. |
| 2.4.9 Link Purpose (Link Only) | Two concrete ambiguous link names repaired; full dynamic link inventory unverified. |
| 2.4.10 Section Headings | Shared fixture and help use headings; long dynamic documents and all pages need review. |
| 2.4.12 Focus Not Obscured (Enhanced) | Removed voice overlay and padded table scrolling; full-page keyboard sweeps still required. |
| 2.4.13 Focus Appearance | Existing 3-pixel outlines retained, table clipping reduced, forced-colors outline added. Pixel-level focus-state comparison across every component not performed. |
| 2.5.5 Target Size (Enhanced) | Partial: shared targets fixed and measured; full rendered-state target inventory required. |
| 2.5.6 Concurrent Input Mechanisms | Keyboard, pointer, and typed voice fallback remain available. Touch, switch, and speech combinations unverified. |
| 3.1.3 Unusual Words | Shared glossary added; specialist vocabulary and user-authored content need editorial review. |
| 3.1.4 Abbreviations | Shared glossary added; complete first-use expansions and all abbreviations unverified. |
| 3.1.5 Reading Level | **Unverified:** domain reports, notices, and public consent copy need reading-level review and plain-language supplements where needed. |
| 3.1.6 Pronunciation | No specific meaning-dependent pronunciation defect established; names and specialist terms require content review. |
| 3.2.5 Change on Request | Five selection-triggered submissions and automatic voice restart removed; full process coverage remains partial. |
| 3.3.5 Help | Shared guidance added; field-specific assistance and complicated workflows require review. |
| 3.3.6 Error Prevention (All) | **Unverified:** every submission must have suitable reversal, checking, or review. Existing validation/confirmations are not proof for all writes. Inspect task creation, financial entries, outgoing reports, and public submissions with real role permissions. |
| 3.3.9 Accessible Authentication (Enhanced) | Password fields support autocomplete, code field supports paste/autofill, and passkeys are offered. Actual password manager/passkey/OTP/SSO/recovery experiences are not end-to-end tested. |

## A/AA coverage needed for an AAA claim

This pass specifically exercised shared labels/landmarks, contrast, keyboard skip navigation, responsive reflow, text spacing, control target size, predictable form submission, and status/recovery feedback. Source inspection found existing table headers/captions, document language, input-purpose tokens in sign-in/referral fields, visible focus, reduced-motion support, and accessible HTML alternatives for some PDF exports.

Still required: complete A/AA assessment across authenticated roles and public token flows, screen-reader reading order and announcements, name/role/value for dynamic widgets, error association and focus, input-purpose mapping, language-of-parts review of Spanish content, contrast in every state, and accessible equivalents for every export/upload. FPDF output remains visually structured rather than tagged; the existence of some HTML exports does not establish equivalence and discoverability for every document.

## Validation and reproducibility

- `php tests/accessibility.php`: 24 assertions passed, including numerical contrast and actual shared-layout markup.
- `node tests/accessibility.test.cjs`: four voice-interaction groups passed.
- `node tests/session-forms.test.cjs`: four submission/re-authentication groups passed.
- `node tests/session-timeout.test.cjs`: seven timeout/activity groups passed.
- PHP syntax validation: 126 files passed across public, cron, tests, and installer.
- `git diff --check`: passed.
- Browser: Codex in-app browser, synthetic CLI-generated layout using production layout/CSS/voice code, no production configuration or records. Tested light and dark themes, 1280-pixel desktop and 320-pixel narrow widths, explicit skip-link focus, voice opening/Escape focus restoration, control bounds, and 200% root text combined with line/letter/word/paragraph spacing overrides. No document-level horizontal overflow in the repaired fixture. This is text enlargement, not a claim that browser 400% zoom was tested.
- Browser viewport override was temporary. No microphone permission was requested during browser checks.

Render the fixture with `php tests/accessibility_fixture.php > /tmp/index.html`, copy the referenced assets into a neighboring `assets/` directory, and serve only that synthetic directory to reproduce. The fixture is CLI-only and does not load application configuration.

## Remaining work before claiming conformance

1. Run all principal processes as an administrator, scoped staff member, and public/client user in a seeded test environment. Test success, validation failure, network failure, expiration, and re-authentication with files and multi-value fields.
2. Perform VoiceOver/Safari and NVDA/Firefox or Chrome testing, including table navigation, dynamic feedback, input errors, and focus order. Include touch and switch input where supported.
3. Review time limits and all-write error prevention as complete workflows. Implement any missing equivalent path, review step, or reversal based on those results.
4. Review consent/report language, all abbreviations, pronunciation-dependent terms, all uploaded media, and exported document equivalence. Validate PDF accessibility where PDF is the required deliverable.
5. Repeat layout/contrast/focus measurements across all pages at 200% text and 400% zoom, custom text spacing, forced colors, and light/dark branding. Test iOS separately; it was outside this web audit.

The code fixes are reviewable locally and the install archive is rebuilt. No AAA badge or conformance claim should be published on this evidence alone.
