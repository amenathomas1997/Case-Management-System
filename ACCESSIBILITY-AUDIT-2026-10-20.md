# Accessibility audit, October 20, 2026 (release 4.29.0)

## Scope and method

- **What was audited:** the website (staff pages, the client portal, public forms, and generated PDFs and emails), the iPhone app and the Android app.
- **Standard:** WCAG 2.2 AA, plus the owner's own rules:
  1. hints inside labels, with no extra stops
  2. quiet combo boxes
  3. one error summary, with each error tied to its field
  4. required fields marked
  5. other-language text marked
- **Auditors:** eight, all read-only.
  - **Automated scan:** axe-core 4.10 ran 2,632 scans of the 329 rendered pages, at 1280 and 320 px and in all seven colour palettes. Two layout probes ran alongside it.
  - **Keyboard and JAWS walkthroughs:** real tasks run keyboard-only in Chrome, recording every stop and where focus landed.
  - **Code and rendered-page reviews:** the shared page parts, the public and client pages (English and Spanish), the daily staff pages, and billing, reports and admin.
  - **App reviews:** the iPhone app with VoiceOver, Voice Control and Dynamic Type; the Android app with TalkBack, Voice Access and font scale.
- **Limits:** no live JAWS, NVDA, VoiceOver or TalkBack speech was recorded. Speech was inferred from the browser's accessibility tree and from the code.

## Result

- **Totals:** 2 High, about 36 Medium and about 70 Low. Some findings overlap between auditors.
- **Contrast:** passes everywhere, in every theme and palette.
- **Automated scan:** axe found one real issue across all scans.
- **Who is blocked:** nothing blocks a blind client from signing, booking, answering the survey or sending a referral. One systemic defect hides the outcome of many saves from JAWS.

## High

1. **A1. After a save that returns to a part of the page, JAWS often never hears the result or the error** (layout.php flash focus, bootstrap.php error-summary focus).
   - The message is focused on DOMContentLoaded. Chrome then scrolls to the #fragment in the redirect and moves focus off the message.
   - There are about 180 such redirects. Confirmed cases:
     - log hours (success and error)
     - complete a session
     - move refused
     - add a note with no subject
     - send to sign
     - portal booking (success, and no time chosen)
   - **Fix:** focus after the load and the fragment scroll, using load plus setTimeout(0) or a double requestAnimationFrame. Add a browser test.
2. **A2. An "Unsaved changes" dialog in English only interrupts clients** (navigation.js).
   - It appears on the Spanish portal when sending a message after picking a time, and when switching language on a public form.
   - Enter cancels the send, and "Leave without saving" actually sends.
   - **Fix:** never prompt for the language switch or for the form's own submit, translate the dialog, and correct its wording.

## Medium (grouped)

- **Website, shared parts:**
  - The Text size and voice Loudness selects announce and act on every arrow press (owner rule 2).
  - The "Type date/time instead" button adds an extra stop after every date and time field. Its name repeats the whole label, and its format notes rely on aria-describedby (owner rules 1 and 3).
  - Table buttons and selects squeeze until unreadable. Examples: "Vie/w", "Sa ve", status selects showing no value at 320 px, and "Availat" at 1280 px (1.4.10, 1.4.4).
- **Sign-in:** a wrong staff code (verify.php) is announced only through aria-describedby, and no summary takes focus.
- **Public pages:**
  - Custom referral questions on the Spanish form are not marked English.
  - The referral role radios announce on every arrow press.
  - The referral decline email has no Spanish version.
- **Staff pages:**
  - The Schedule track list shows every client's tracks as "Client — Track" (owner rule 2).
  - Session outcome options are sentences, and two of them read alike.
  - Edit note and addendum links name only the subject, never the date.
  - The referral list has no heading.
  - Move-refused errors repeat on three fields, and the summary points to the wrong field.
- **Billing, reports and admin:**
  - Generated PDFs (invoices, funder and outcome reports) are untagged, and the "accessible version" link needs a staff sign-in.
  - Custom reports results have no scroll region.
  - Missing error summaries on custom reports, the authorization email, user dates, the account mobile number and equipment reservations.
  - Letters are always marked English.
  - Custom fields are not programmatically required.
- **iPhone:**
  - Check in and Sign the agreement are unreadable in dark mode.
  - Mark submitted, Withdraw, Sign here now, Correct and Delete hours, and Share document exist only as swipe or rotor actions.
  - Training and agreement errors are spoken twice or shown off screen.
  - Errors are never tied to their fields.
  - Dashboard figures break at the largest text sizes.
- **Android:**
  - New screens are not announced.
  - Errors never take focus, and summaries name only the first problem.
  - Save buttons stay disabled with no reason, and required fields are unmarked.
  - Password managers can't fill sign-in fields.
  - Translated AI text is read in the English voice.
  - Long pick lists have no filter.
  - Text is clipped at 200% font size.

## Low

About 70 items. Details are in the appendix.
- **Shared parts:**
  - Low-vision messages mix English and Spanish.
  - English shared parts are unmarked on Spanish pages for staff.
  - The expiry notice is read twice.
  - The menu headings sit under "Low-vision controls".
- **Public pages:**
  - Spanish dates under Upcoming sessions.
  - Radio-group errors have no aria-invalid.
  - Self-booking time is not marked required.
  - File limits are outside the label.
  - Stars are read in the masked email.
  - Missing autocomplete on state.
  - "Add to calendar" does not say it downloads a file.
  - The accessibility statement is English only.
- **Staff pages:**
  - No match count on Clients.
  - Option counts in the goal Area and other selects.
  - The goal search announces too often.
  - Required marks without required.
  - Correct-entry names repeat for the same date.
  - Confirm dialogs don't name the item.
  - The scored assessments table has no caption.
  - A role=alert at page load on merge.
  - A duplicate region name on the client page.
- **Billing, reports and admin:**
  - Flash-only refusals.
  - Option labels with counts.
  - Required marks.
  - Missing novalidate.
  - Hints outside labels on admin.php, email_templates.php and users.php.
- **iPhone:**
  - Success messages are cut off when a screen closes.
  - Errors are spoken twice.
  - Menu and segmented pickers at large sizes.
  - Steppers hidden from Voice Control.
  - Redundant hints.
  - Unmarked required fields.
  - Reduce Motion ignored.
  - Small schedule links.
  - Dimmed alerts.
  - Long icon names.
  - The widget drops a line at large text.
- **Android:**
  - Field errors are read twice.
  - "Still saving" is spoken twice.
  - No AI working status.
  - Wrong refresh hint.
  - An option count.
  - A duplicated heading.
  - A white flash at launch in dark mode.

## What passed

- **Contrast** in light, dark and the four vision palettes, including focus rings.
- **Basic structure:** skip link, landmarks, timeout dialog, no keyboard traps, no single-key shortcuts, reduced motion, forced colours and 44 px targets.
- **Signing:** typed-name signing everywhere.
- **Sign-in codes:** pasteable codes with one-time-code autocomplete.
- **Analytics charts** have data tables.
- **Report tables** have captions and scoped headers.
- **Repeated row buttons** name their row in most places.
- **Apps:** touch targets, the signature pad's typed alternative, and contrast in both themes on Android.

# Appendix: findings by area

## Area: flows

### Accessibility audit: task walkthroughs (flows), release 4.29.0, 2026-10-20

Method: I served a copy of `public/` with the PHP built-in server on http://localhost:8099. The copy's `inc/config_path.php` pointed at a scratch config, and a router set HTTPS=on. The repository was not edited. The database was my own `cms_a11y_flows`, loaded from `dist/schema_full.sql` with a small synthetic seed: 2 staff, 3 clients (one Spanish), tracks, availability, self-booking on, portal tokens and one client message. It was not populated by tests/full_schema.php: the live flows needed a known password, an active caseload and portal tokens.
I drove Google Chrome (Playwright 1.48.2, channel chrome, headless) by keyboard only: Tab, Shift+Tab, Enter, Space and typing. At every step I recorded document.activeElement, Chrome's accessibility node for it (role, name, state, description) and a `focusin` log. File choosing used setInputFiles because a native file dialog cannot be driven. Arrow keys do not change a closed `<select>` on macOS Chrome, so I chose options by type-ahead letters. I did not run JAWS itself: what JAWS would say is inferred from Chrome's accessibility tree. Scripts are in /private/tmp/claude-501/a11y/node_flows, and the tree dumps are in /private/tmp/claude-501/a11y/flows_out.

---

#### [High] After a save that redirects to a #fragment, Chrome takes focus away from the result message and the error summary
- Where: inc/layout.php:218-220 (the flash script focuses `#flash-messages` on DOMContentLoaded) and inc/bootstrap.php:410-413 (`#form-error-summary` is focused on DOMContentLoaded). They meet `redirect('…#fragment')`, which has about 250 call sites (client.php 28, track.php 26, admin.php 21, schedule.php 17, account.php 16, portal.php 6, signatures.php 5, …).
- Who and what happens: The page's script focuses the message first. Chrome then scrolls to the fragment and changes focus again. If the target can take focus (a `<summary>`, or an element with tabindex=-1) Chrome focuses the target. If it cannot, Chrome drops focus to the body. Either way the result or error is never announced as the page opens. The `focusin` logs show it each time:
  - Log hours (track.php, Save session goes to `#logs`): `flash-messages` then body. "Hours logged." is not announced. With hours but no description, the order is `form-error-summary` then body: the error is not announced, and the only clue is "Error —" in the title.
  - Complete a session (schedule.php, Save status goes to `#upcoming-h`): `flash-messages` then body. "Completed: … 1 hour logged … Write the session note on the training track" is not heard.
  - Move a session refused for an overlap (goes to `#s-1`): `form-error-summary` then `th#s-1`. JAWS hears "Oct 22, 2026 10:00 AM to 11:00 AM", not "Not moved yet: the instructor already has a session…". After "Move anyway", the "moved" message is skipped the same way.
  - Add a case note with no subject (client.php goes to `#addnote-h`): `form-error-summary` then `summary#addnote-h`. JAWS hears "Add a note, expanded" and the error comes later in reading order.
  - Send a document to sign (signatures.php goes to `#list-h`): the heading "Sent to sign (1)" takes focus, and "Sent to Maria Lopez to sign." is skipped.
  - Client portal, English and Spanish: Book this time with a time chosen goes to `#sess-h`, then `flash-messages`, then body. The client never hears that the time was booked. Book with no time chosen goes to `#book-h` (tabindex=-1). The client hears "Book a session, heading" and not "Choose a time first."
  - By reading the code, the same happens for privacy_incidents.php (`#report-h`, a summary) and referrals.php close (`#close-h`, a summary), and on every other fragment redirect.
- WCAG / rule: 4.1.3 Status Messages, 3.3.1 Error Identification, 2.4.3 Focus Order; owner rule 3 (the error summary takes focus).
- Fix: Move focus after Chrome has handled the fragment. In this browser I checked two versions: `window.addEventListener('load', () => setTimeout(focusIt, 0))` and a double requestAnimationFrame both keep focus on `#flash-messages` with `#logs` in the URL. Apply this to the flash script and to both error-summary scripts (bootstrap.php:413, signatures.php:100). Alternatively, leave the fragment out of the redirect when there is a summary or flash. Then add a case to tests/accessibility_browser.js: submit a form that redirects with a fragment, and check that document.activeElement is the summary or the flash after `load`.
- Status: CONFIRMED (focus behaviour in Chrome). JAWS speech was not run.

#### [High] Portal: sending a message after browsing booking times opens an "Unsaved changes" dialog, in English even on the Spanish portal
- Where: assets/navigation.js:72-103 (the dialog is built with `lang='en'` and hard-coded English text; `changed(except)` counts any other changed form on the page); portal.php booking form.
- Who and what happens: A client picks a time in the booking list without booking it (for example, to hear what is offered), then types a message. Stops: Hora con su instructor (value changed), then Su mensaje (typed), then Enviar mensaje (Enter). A modal opens with focus on "Keep editing", and it says "Unsaved changes. You have changes that have not been saved. Save the form, keep editing, or leave without saving." On portal.php?lang=es all of it is English, and `<html lang>` is es while the dialog is lang=en. Enter on the focused default, "Keep editing", cancels sending. "Leave without saving" sends the message, so the wording does not fit what the button does. A Spanish-speaking client may be unable to send at all.
- WCAG / rule: 3.1.2 Language of Parts and 3.3.2 (instructions not in the user's language), 3.2.2 On Input (an unexpected dialog); owner rule 5.
- Fix: On the portal, do not treat a booking-time choice as unsaved work, or skip the check when the form being submitted is the only changed form with typed text. Translate the dialog with `t()`, using the page language. When the action is a form submit, name the button for what it does ("Send anyway" / "Enviar de todos modos").
- Status: CONFIRMED

#### [Medium] A wrong sign-in code is only in the field's aria-describedby (no focused error summary)
- Where: public/verify.php:216-226.
- Who and what happens: Stops: type a wrong code, then Enter. The page reloads with focus in the field. Chrome exposes "6-digit code The code expires 10 minutes after it was sent., invalid", with the description "That code is not correct… 4 tries left… We emailed a 6-digit code…". If JAWS skips descriptions (which the owner has reported), the user hears "invalid entry" with no reason and no count of tries left. On every visit the description also repeats the intro paragraph shown above the field. login.php, by contrast, focuses an error box.
- WCAG / rule: 3.3.1; owner rules 1 and 3.
- Fix: Use the same pattern as login.php: a focused summary (`form_error_summary` or a focused `#code-error`) that links to the field. Keep `aria-describedby` only for the error, not for the intro text.
- Status: CONFIRMED (behaviour in Chrome); the JAWS description behaviour is from the owner's report.

#### [Medium] Move-session refusal: the summary link goes to the wrong control, and the same error is repeated on every field
- Where: schedule.php:1040 (`form_error_summary([$mKey=>''], [$mKey=>'md-'.$sidM])`) and the move form's fields.
- Who and what happens: The fix is the "Move anyway" checkbox, but the summary link "Not moved yet: … Tick 'Move anyway'…" goes to `#md-1`, the date field. Stops from there: Date month, day, year, picker, then Type date instead, then Time hours, minutes, AM/PM, picker, then Type time instead, then Length. Every one of those is `invalid` and re-reads the same error as its description, about 10 times, even though the values are correct. Only after all of that does the user reach the "Move anyway…" checkbox and the Move button. The error text is also shown twice on screen, one right after the other: the summary, then the inline paragraph. The booking form gets this right: its summary links to the approval checkbox.
- WCAG / rule: 3.3.1 and 3.3.3; owner rule 3 (the same error is not to be announced twice).
- Fix: Point the summary anchor at the Move-anyway checkbox. Mark only that checkbox invalid and give it the error. Keep the date, time and length fields valid.
- Status: CONFIRMED

#### [Low] Overlap warning when booking: the wording says "authorization" twice and points the wrong way
- Where: schedule.php:516 and :1090 (label 'Authorization approval'). The checkbox label is "I reviewed these authorization warnings and approve this booking despite them."
- Who and what happens: The summary link reads "Authorization approval: Authorization review: Avery Admin already has a session from 10:00 to 11:00 with this client. … tick the approval box below…". The problem is a double booking, not an authorization, so the double label is confusing. The text also says the box is "below", but the message comes after the checkbox (it is that checkbox's field error).
- WCAG / rule: 3.3.2 Labels or Instructions.
- Fix: Use "Booking approval:" with no second prefix, and say "tick 'I reviewed…' (the box above this message)" or "tick the approval box".
- Status: CONFIRMED

#### [Medium] Option labels break owner rule 2 in the scheduling and outcome lists
- Where: schedule.php:990 and nearby (session outcome select); schedule.php:1105 (Training track); portal.php:899-906 (booking times).
- Who and what happens:
  - The outcome select for an ended session reads "Completed — hours already logged (1.5 hours: JAWS lesson)", "Completed — hours already logged (0.25 hours: x)", then the identical label a second time for a different log, then "Completed — log hours as well (a separate visit)". These are sentences with counts, and two of the choices sound exactly the same.
  - The Training track options are "Jose Garcia — Entrenamiento NVDA", "Maria Lopez — JAWS training", and so on: a "client — item" prefix. They are not narrowed to the client already chosen, so the user has to find their own client's track in an all-client list.
  - The portal booking list has 40 sentence options ("Wednesday, October 21 at 1:00 PM, 60 minutes, online") with no grouping.
- WCAG / rule: owner rule 2; 3.3.7 Redundant Entry (track).
- Fix: Group outcome choices with `<optgroup label="Completed">` and give each a plain, distinct name (for example the log date and time). Group tracks by client with optgroup, or filter them to the chosen client. Group booking times by day with optgroup (for example "1:00 PM, online").
- Status: CONFIRMED

#### [Low] Required selects and file inputs are exposed as "invalid" when the page opens
- Where: schedule.php:1096 (Client), messages.php (Client), portal.php (Share a document, "Choose a file (required)").
- Who and what happens: In Chrome's accessibility tree these controls are `invalid=true` on first visit, before anything has been submitted. The fields come from native `required` on a `<select>` with an empty placeholder, and on a file input. On first arrival JAWS would say "invalid entry" for the Client field in Schedule a session and in Messages, and for the portal's file chooser.
- WCAG / rule: 4.1.2 (wrong state); 3.3.1.
- Fix: These forms already use `novalidate` and server-side errors. Use `aria-required="true"` on these selects and file inputs instead of `required`, or set `required` only after the first submit.
- Status: CONFIRMED in Chrome's tree; the JAWS wording is PLAUSIBLE.

#### [Low] The in-person signature field is visibly required but not programmatically required, and the Spanish label says "(required)" in English
- Where: signatures.php:104-105 (no `required` / `aria-required` on `#signature`); inc/bootstrap.php:357 (`required_mark()` always says "(required)"); eval_report.php:310 also has no `required`, but I did not open it in the browser.
- Who and what happens: In the hand-over page, Chrome exposes "Electronic signature (required) …" as a textbox with no required state. For a Spanish signer the label is lang=es, but its accessible text is "Firma electrónica *(required) Escriba su nombre completo…". The Spanish voice reads "required" in English, and "*" is part of the visible name. On the same page the low-vision panel, the footer links and `<html lang>` stay English.
- WCAG / rule: owner rule 4, 1.3.1; owner rule 5, 3.1.2.
- Fix: Add `required` (or `aria-required`) to `#signature`. Make `required_mark()` follow the page language ("(obligatorio)"), or use `$es` here as the rest of the label already does.
- Status: CONFIRMED (signatures.php); PLAUSIBLE (eval_report.php)

#### [Low] "Type date/time instead" buttons have "*" and hint text in their names
- Where: assets/date-entry.js:15 (`aria-label` is built from `label.textContent`, which includes aria-hidden text).
- Who and what happens: Button names read "Type date instead: Date * (required)" and "Type time instead: Start time *(required)". In the Spanish hand-over and other pages, hints are read too. JAWS says "star". On track.php the name is "Type date instead: Date * (required)".
- WCAG / rule: 2.5.3 / 4.1.2 (noisy name).
- Fix: Build the name from the label with aria-hidden parts left out (clone the label and remove `[aria-hidden]` and `.hint` before reading textContent), or point aria-labelledby at the button's own text plus the label's id.
- Status: CONFIRMED

#### [Low] "Go to main menu" focuses an unnamed list, and Chrome builds a doubled name for it
- Where: assets/navigation.js:24-30; inc/layout.php:119 (`<ul id="main-nav" tabindex="-1">` has no name).
- Who and what happens: Stops on any staff page: Tab 4 "Go to main menu", then Enter. Focus goes to `ul#main-nav`. Chrome exposes the focused list with the name "Today Today Clients Clients Services Services Equipment Equipment Billing, compliance & reports Billing, compl…", built from each group heading and its labelled sub-list. JAWS would likely read that whole string before the first link. From there, Reports ("Business analytics") is 23 more Tab stops.
- WCAG / rule: 4.1.2; owner rule 1 (extra speech).
- Fix: Add `aria-label="Main menu"` to the `ul`, or move focus to the first group heading or link.
- Status: PLAUSIBLE (the name is confirmed in Chrome's tree; JAWS speech was not run)

#### [Low] Client record tabs reload the page at the top
- Where: client.php:192-197 (tab links have no fragment).
- Who and what happens: Stops: Enter on "Client Notes" reloads the page with focus at the top. To reach the notes you go through the skip link or 37 menu stops, then 20 header action stops (AI case briefing … Status History), and only then "Add a note". Overview, Tech Assessments and the other tabs behave the same.
- WCAG / rule: 2.4.3 (efficiency), owner rule 1.
- Fix: Give tab links a fragment to a focusable panel heading (`#tab-panel`, tabindex=-1). Do this only together with the fix for the first finding, so a flash is not lost.
- Status: CONFIRMED

#### [Low] Portal "Book this time" asks for confirmation before checking that a time was chosen
- Where: portal.php:908 (`onclick="return confirm('Book this time?')"`).
- Who and what happens: With "Choose a time" still selected, Book this time first opens the "Book this time?" / "¿Reservar esta hora?" confirm. After OK comes the error "Choose a time first." That is an extra dialog and keystroke before the error.
- WCAG / rule: owner rule 1; 3.3.4 is met, but the order is wrong.
- Fix: Skip the confirm when `#slot` is empty, and say the chosen time in the confirm text.
- Status: CONFIRMED

#### [Low] Progress figures are read twice
- Where: client.php overview (training tracks summary), track.php (Totals at a glance).
- Who and what happens: Reading the page line by line, the text "0 hours of 40 hours authorized used (…)" is followed by a progressbar with exactly the same name. "Authorization usage: 0 hours of 40 hours (0%)" is the same.
- WCAG / rule: owner rule 1 (repeated speech).
- Fix: Put `aria-hidden="true"` on the visible text and keep the named progressbar, or name the bar briefly ("Hours used").
- Status: CONFIRMED

---

### What worked well (checked, no defect)
- Sign-in: a blank or wrong password moves focus to the error summary or error box, and both fields are tied to it. "Send a new code" focuses the flash. A correct code lands on the dashboard with focus on "Welcome back".
- Dashboard: search, then the results summary paragraph takes focus, with one stop per result. The client opens in one more Enter.
- Saving a client note (success), booking a session (success, no fragment), sending a staff message with a file, and the error summaries for an empty message and a no-subject note without a fragment all focus the flash or summary correctly.
- The overlap approval when booking: the summary link goes to the approval checkbox, Space then Tab reaches "Schedule & send calendar invite", and the flash is focused.
- Hand-over signing: a wrong name focuses a Spanish or English error summary, and "It is signed. Thank you." is the h1 and the title. Staff must sign in again afterwards (password and code); this is by design in 4.29.0.
- No keyboard traps were found. The "Unsaved changes" dialog returns focus and closes with Escape.

### Coverage
- Done fully in Chrome by keyboard: sign-in (password, code, wrong code, resend); dashboard to client by search; adding a case note (error and success); logging hours (error and success); booking a session including the overlap approval; moving a session (refused, then Move anyway); completing a session; sending to sign by email and an in-person hand-over (English and Spanish); replying to a message with a file; main menu to Business analytics (Reports); portal booking (no time, then success) and portal message (success and empty) in English and Spanish.
- Not done: real JAWS speech (only Chrome's accessibility tree); Windows-specific select behaviour (arrow keys on a closed select); the portal "Request a new time" and "Ask to cancel" buttons; the group-session form; the session-timeout dialog within a flow; the Microsoft and passkey sign-in paths; the referral close and privacy incident pages (affected by the first finding by code reading only).

## Area: shared

### Accessibility audit: shared (layout, helpers, CSS, assets/*.js), 4.29.0, 2026-10-20

#### [Medium] Low-vision "Text size" select announces and re-lays-out the page on every arrow press
- Where: public/assets/low-vision.js:61 (`size.addEventListener('change', … update('Text size ' + size.value + '%'))`), markup public/inc/layout.php:96-98
- Who and what happens: in Chrome on Windows, arrowing through the closed select fires `change` for each option. Each press resizes the whole page and writes "Text size 150%. Display updated." to the `#vision-live` status region. A JAWS user who arrows from 100% to 200% hears four announcements, and the page reflows four times under a magnifier user. Checked in the browser: one change event put "Text size 150%. Vista actualizada." in the live region.
- WCAG / rule: owner rule 2 (quiet combo boxes)
- Fix: apply and announce only on blur, or after about 700 ms with no further change, and announce once.
- Status: CONFIRMED

#### [Medium] Voice assistant "Loudness" select plays a sound on every arrow press and updates the status live region when it passes Max; its option label has a parenthetical
- Where: public/assets/voice.js:635-647; option label public/inc/layout.php:278 (`Max (deaf-blind)`)
- Who and what happens: each arrow press plays the "notify" earcon. Arrowing past Max writes "Spoken replies off. Replies will appear here as text." to the `.vfab-status` region (role=status, aria-live=polite), and moving off it writes "Spoken replies on." The code comment already notes that arrow keys change the value at once. Checked in the browser: going Loud, Max, Loud, Normal gave two status announcements, and the earcon played on all four presses.
- WCAG / rule: owner rule 2 (no live-region updates per arrow; plain option names)
- Fix: debounce about 700 ms or apply on blur (play the sample sound and switch spoken replies then). Rename the option "Maximum" and move "for deaf-blind users" into the label as visually hidden text.
- Status: CONFIRMED

#### [Medium] Unsaved-changes dialog appears in English on Spanish public forms, falsely warns that the language switch loses data, and offers a "Save" its buttons do not have
- Where: public/assets/navigation.js:72-119 (dialog markup with `lang='en'`, and the document click handler); lang_toggle() in public/inc/bootstrap.php:142-218
- Who and what happens: navigation.js runs on every page, client pages included. Its click handler treats the Español/English toggle (same path, different query) as leaving the page. A client who has typed in the referral, consent or survey form and switches language gets a modal saying "You have changes that have not been saved… leave without saving". In fact lang_toggle() keeps every field in sessionStorage and puts it back. On a Spanish page the modal is wholly English (marked lang="en", so 3.1.2 passes, but a Spanish-only client cannot understand it). The text also says "Save the form", yet the Save button is hidden on these forms. The window.confirm fallback is English too. Checked in the browser on community_referral (both the English and the Spanish snapshot): typed a name, clicked the toggle, and the dialog opened with only "Keep editing" and "Leave without saving".
- WCAG / rule: 3.3.2 (instructions do not match the controls); owner rule 5 (spirit: client-facing copy in the page language); 3.1.2 passes technically
- Fix: skip the prompt for `.lang-toggle a`, which already keeps the data. Translate the dialog (pass strings from PHP through __()) on translated pages. Leave "Save the form" out of the description when Save is hidden.
- Status: CONFIRMED

#### [Medium] Date/time "Type … instead" control: extra tab stop whose name repeats the whole label and hint; format instructions and errors only in aria-describedby plus a separate visible line
- Where: public/assets/date-entry.js:12-18, 35-37, 50, 56
- Who and what happens: every enabled date, time and datetime field on every page (staff and client) gets an extra button after it. Its aria-label is "Type date instead: " plus the label's full textContent, so it repeats the in-label hint, and any "*" that was aria-hidden is read as text. Checked in the browser on access_bookings: "Use date and time picker instead: When date and time; leave blank if not known yet". In text mode, the format "Type YYYY-MM-DDTHH:MM…" and any validation error sit in a `<p class="hint">` / `<p class="field-error">` after the field. That paragraph is reachable only through aria-describedby (which the owner has found JAWS skips) and is also an extra line in browse mode. On Spanish client forms the button and note are English (marked lang="en"): checked on the Spanish referral form ("Type date instead" next to "Fecha de nacimiento").
- WCAG / rule: owner rule 1; owner rule 3 (the error should be in the label or announced reliably); 3.3.2 for the format instruction
- Fix: give the button a short name ("Type date instead", with the field name as visually hidden text only). In text mode, put the format inside the field's `<label>` as visually hidden text and add the error into the label as well, keeping the visible copy aria-hidden. Translate the strings on translated pages.
- Status: CONFIRMED

#### [Low] Live-region messages on Spanish pages mix English and Spanish under lang="es"
- Where: public/assets/low-vision.js:60-61 ("Text size N%", " on"/" off"); public/assets/earcons.js:92 ("Sound cues on./off."); layout.php:87 (`Sound cues` button not passed through t())
- Who and what happens: on Spanish client pages the low-vision bar's status says "Text size 150%. Vista actualizada." and "Más espacio entre el texto on. Vista actualizada." (both checked in the browser), all read in the Spanish voice. A staff member on a translated page also gets an English "Sound cues" button with no lang mark.
- WCAG / rule: 3.1.2; owner rule 5
- Fix: put the message templates in data-* attributes rendered with __() (as data-saved already is), and use t('Sound cues').
- Status: CONFIRMED

#### [Low] Staff on a translated public page: timeout dialog, session-check messages and voice assistant are English without lang="en"
- Where: public/inc/layout.php:223-311 (emitted after `PUBLIC_TRANSLATION` is restored at :171; `#timeout-warn`, `#session-check-live`, `#voice-fab` carry no lang)
- Who and what happens: 4.29.0 marks the staff header and menu lang="en" when a signed-in user opens a Spanish public page. The inactivity warning ("Are you still there?…"), the expiry notice, the session-check and partial-date messages, and the whole voice panel are still English inside `<html lang="es">`. JAWS reads them in the Spanish voice, including the 2.2.1 warning. (Path traced in code; the test snapshots have no staff-on-Spanish page.)
- WCAG / rule: 3.1.2; owner rule 5
- Fix: add `lang="en"` to `#timeout-warn`, `#session-check-live` and `#voice-fab` when page_lang() !== 'en'. The same applies to the `<dialog>` built by navigation.js (already en) and to session-forms.js's in-form message div.
- Status: CONFIRMED (by code)

#### [Low] Session-expired notice is spoken two or three times, under a dialog title that still asks "Are you still there?"
- Where: public/assets/session-timeout.js:80-94; layout.php:234-246
- Who and what happens: on expiry, expire() does three things at once. It moves `#tw-expired` into the role=alert region (announced), points aria-describedby at it, and focuses the link inside it. When the dialog was closed, it then opens with focus moved in, so JAWS reads the dialog name and description (the same text), then the link. The title stays "Are you still there?" although the session has ended.
- WCAG / rule: owner rule 3 (same message announced once); 2.4.6
- Fix: use one channel. Either focus the link with aria-describedby set to the notice, or announce through the alert without also describing. Change `#tw-title` to "You were signed out" while expired.
- Status: PLAUSIBLE (announcement count depends on JAWS/Chrome; code path confirmed)

#### [Low] Main-menu group headings are h3s under the "Low-vision controls" h2, and come before the page's h1 with no h2 when that bar is hidden
- Where: public/inc/layout.php:83, 133
- Who and what happens: in the headings list, Today, Clients, Services and the other menu groups appear as sub-sections of "Low-vision controls". A user who hid the low-vision bar from My Account (`data-account-hidden`), or has JS off (the bar starts `hidden`), gets h3 headings first and then the page h1.
- WCAG / rule: 1.3.1 (best practice; heading hierarchy)
- Fix: give the menu its own visually hidden h2 ("Main menu") inside `<nav aria-label="Main">`, and keep the group headings as h3 under it.
- Status: CONFIRMED (markup); impact Low

### Contrast results (computed, WCAG formula; script /private/tmp/claude-501/a11y/shared_work/c.py)
All passed. Light: ink/bg 15.95, ink-soft/bg 9.82, brand/bg 8.16, placeholder #555/white 7.46, input border 10.5, focus ring (ink) 15.95, header amber ring/navy 6.20 (8.65 on the current-item background), alerts 7.3–9.5, badges 7.3–9.4, error/ok borders 4.52/3.52. Dark: ink 15.83, ink-soft/card 8.62, brand/card 9.23, input and secondary-button borders 4.99–5.52, placeholder 9.54, amber focus ring 10–11, alerts and badges 8.9–11.3. Vision palettes: cream 14.37, yellow 17.2, black and white 21. Their `!important` overrides cover focus rings (vision-ink on a vision-bg halo), placeholders, links and every component listed. The menu hint (#fff on #111) is 18.9. No disabled-state colours are defined (exempt). Not defects: the dark-theme primary button (#1e40af) against the card is 1.93, and the voice button's navy against dark surfaces is 1.6–1.8; the text and icon identify both controls. In the white and cream palettes the "Menu" button's white bars disappear, but its text remains.

### Checked with no defect found
- Skip link, landmarks, title "Error —" prefix, breadcrumbs (`aria-current`, separators hidden).
- Menu hints inside links as hidden text, with the visible copy aria-hidden. Hints are dismissible with Escape, hoverable and persistent (1.4.13).
- Access keys all need modifiers, and the voice panel's Alt+L has one, so there are no single-character shortcuts (2.1.4 N/A).
- Timeout dialog: focus moves in, Tab is trapped, Escape and Return to my work give focus back, and the warning comes 30–120 s ahead with the session limited to 5–480 min (2.2.1).
- The voice panel (a non-modal dialog) closes with Escape and returns focus. The unsaved dialog is a native showModal with focus return.
- Flash focus handling folds a flash into the error summary, so it is not announced twice.
- form_error_summary / field_aria / field_error: aria-invalid, an inline error tied to its field, one focused summary, a unique id for a second summary.
- required_mark, csrf_field, i18n en()/t() lang marking, select_options lang.
- prefers-reduced-motion and the in-app Reduce motion setting, forced-colors focus and borders, text-spacing option, 44px targets.

### Coverage
Fully covered: layout.php, the bootstrap.php form, CSRF and lang helpers, i18n.php, navigation.php, styles.css (every token pair in the light and dark themes and the 4 vision palettes), and session-timeout.js, session-forms.js, navigation.js, low-vision.js, select-display.js, date-entry.js, earcons.js, voice.js and at-ai.js. qrcode.js (an SVG generator library) was skimmed only. Behaviour was checked in Chrome on synthetic snapshots (242 pages; the suite stopped early at v418_cases.php because of the uncommitted Android files, which does not matter here) for: the unsaved dialog on the language toggle (English and Spanish), the low-vision live messages, date-entry names and descriptions, and the Loudness select. Not done: real JAWS announcement counts (expiry notice, flash focus), and native browser zoom and text-spacing bookmarklet runs beyond the existing CI reflow checks.

## Area: public

### Accessibility audit: public pages (2026-10-20, 4.29.0)

Method: read every public page's code (portal.php and its includes homework_support_ui.php / at_portal.php, vrc.php, sign.php, consent.php, release.php, survey.php, ack.php, community_referral.php plus inc/custom_fields.php, help.php, privacy.php, accessibility.php, login/forgot/reset/verify.php, layout.php, i18n.php, the email templates in inc/core.php). Rendered pages from tests/full_schema.php into a throwaway DB (cms_a11y_public, now dropped): scanned every Spanish page for unmarked English and for lang="en" runs, and loaded 28 public pages (English and Spanish, error and success states) in a browser at 320 px with every details element open, measuring horizontal overflow and targets under 24 px. Reflow passed on all 28. No target failed 2.5.8 (the only sub-24 px targets were inline links in sentences, or "Add to calendar", which passes on spacing). The Spanish copy is almost fully translated and marked: apart from the items below, every lang="en" run on the Spanish portal is staff record data or a date.

Already good, not reported: typed-name signing on sign/consent/release (2.5.7 met, and accents and capitals are ignored); code fields with autocomplete="one-time-code", inputmode numeric, maxlength 20 and non-digits stripped, so pasting works (3.3.8); stale-session refill on every public form; the VRC timeout dialog (it warns, traps focus, closes with Escape and returns focus); autocomplete on the referrer's and portal's personal fields; required marking on the signature fields.

---

#### [Medium] Staff code check: the error is only in aria-describedby, and nothing takes focus
- Where: public/verify.php:216-226 (rendered verify-post-*.html)
- Who and what happens: After a wrong or expired code, the page reloads with autofocus on the code box. The error ("That code is not correct… 2 tries left", "Your code has expired. Use Send a new code") sits in a div above the field with no focus and no role. The field reaches it only through aria-describedby="code-error code-intro". The owner found that JAWS skips aria-describedby, so a JAWS user hears "Error" in the page title and then only "6-digit code, edit". They do not hear why, or how many tries are left. login.php, forgot.php and vrc.php all move focus to their error. This one sign-in step does not.
- WCAG / rule: 3.3.1, 3.3.3; owner rules 1 and 3
- Fix: Render the error as the focused summary: tabindex="-1", focused on load (instead of autofocus on the field), with a link to #code, as form_error_summary() does. Keep aria-invalid on the field. Put the intro sentence in the label's hint rather than in aria-describedby.
- Status: CONFIRMED

#### [Medium] Custom referral questions: English labels, hints and options are not marked lang="en" on the Spanish form
- Where: public/inc/custom_fields.php:27-29, 138-141, 152-154 (used by community_referral.php:663-670)
- Who and what happens: custom_field_label() falls back to the English label when label_es is empty, and prints it without a lang attribute. The field's hint has no Spanish column at all and is always printed unmarked. The options of a choice field are administrator English, also unmarked. On ?lang=es, a Spanish screen-reader voice reads these English words with Spanish pronunciation. The rest of the form avoids this with $whole and $hintInline, and wording_html() avoids it with <span lang="en">.
- WCAG / rule: 3.1.2; owner rule 5
- Fix: In public mode, wrap the label in <span lang="en"> when label_es is empty and the page is Spanish. Always wrap the hint and the choice options that way (options cannot hold a span, so put lang="en" on each <option>, as select_options() does). Better still, add a hint_es column.
- Status: CONFIRMED (code)

#### [Medium] Choosing a role on the referral form makes a second announcement on every arrow press
- Where: public/community_referral.php:508, 732-740
- Who and what happens: The "I am referring as a…" radios fire change on every arrow key. announce() then writes "Showing the questions for: <role>." into a polite status region 50 ms later. A screen-reader user arrowing through 6–7 roles hears each radio, then the same name again from the live region, queued on every press. This is the per-arrow live-region chatter the owner's combo-box rule forbids. The same handler also rewrites the hidden sections and required flags on every step.
- WCAG / rule: owner rule 2 (spirit); 4.1.3 is satisfied, but too chatty
- Fix: Debounce announce() by about 700 ms (clear the timer on each change), or announce on focusout of the role group, so only the final choice is spoken once.
- Status: CONFIRMED

#### [Medium] A referral that is turned down is emailed in English even when it was sent in Spanish
- Where: public/inc/case_outcomes.php:65; template 'referral_closed' in public/inc/core.php:319 (it has no _es version)
- Who and what happens: A Spanish speaker who refers themselves on ?lang=es has preferred_language='es' stored on the referral. When staff decline it, the email with the reason is always the English template, and the reason comes from an English list. Every other email a client receives has a Spanish version picked by email_template_for_client().
- WCAG / rule: Language access (no SC applies to email); owner rule 5 (spirit)
- Fix: Add referral_closed_es, and choose it by the referral's preferred_language. Translate the close reasons or mark them as English.
- Status: CONFIRMED (code)

#### [Low] A customised English email silently replaces the Spanish one for Spanish clients
- Where: public/inc/core.php:491-497 (email_template_for_client)
- Who and what happens: If an administrator edits the English subject or body of any client template (for example to fix the organisation's wording), every Spanish-preferring client gets that English email from then on. The Spanish template is skipped, and nothing on the Email templates page says so.
- WCAG / rule: Language access; owner rule 5 (spirit)
- Fix: Let the _es template be edited separately and keep sending it. At minimum, warn on the Email templates page that saving an English change turns off the Spanish version.
- Status: CONFIRMED (code)

#### [Low] Portal "Upcoming sessions" gives English dates on the Spanish page
- Where: public/portal.php:922, 932 (and the hidden session name in each button, $forSession)
- Who and what happens: On ?lang=es, each upcoming session reads "Friday, October 23 at 12:09 PM CDT to 1:09 PM CDT". The text is correctly marked lang="en", so the voice switches to English mid-list. The same page gives Spanish dates for booking, past sessions, offered times and "Your progress" (portal_when_es). So a Spanish-only client hears their most important dates in English. The same English date is also inside every "Join online", "Add to calendar", "Request a new time" and "Ask to cancel" name.
- WCAG / rule: 3.1.2 passes; consistency and understandability for Spanish clients
- Fix: Use portal_when_es() for $when and the end time when page_lang()==='es', as the other sections do.
- Status: CONFIRMED (rendered portal-7f72ced0.html)

#### [Low] Group errors are not on the radios themselves (survey, device check-in, referral groups)
- Where: public/survey.php:140, 157; public/portal.php:1139; public/community_referral.php:492/504, 583/588, 592/602, 675/679, 558/565
- Who and what happens: For an unanswered radio group, aria-invalid is never set on the radios. The error is tied only to the fieldset through aria-describedby, which JAWS skipped for the owner. On the referral form the error paragraph also comes after all the options. A user who follows the summary link to the first radio hears the legend and "radio button, not checked", without the error or "invalid entry". (The summary link text does carry the message once.)
- WCAG / rule: 3.3.1; owner rule 3
- Fix: Put aria-invalid="true" on each radio of a group in error. Place the error paragraph right after the legend (survey and portal already do this; the referral form does not). Do not rely on describedby on the fieldset.
- Status: CONFIRMED (code)

#### [Low] "Time with your instructor" (self-booking) is effectively required but not marked
- Where: public/portal.php:898-899
- Who and what happens: Submitting with "Choose a time" is refused with "Choose a time first." The label has no "(required)" and the select has no required or aria-required, unlike every other required field on the portal.
- WCAG / rule: 3.3.2; owner rule 4
- Fix: Add <span class="hint">(required)</span> inside the label and required (or aria-required="true") on the select.
- Status: CONFIRMED

#### [Low] Required custom referral questions are not programmatically required
- Where: public/inc/custom_fields.php:138, 143-158
- Who and what happens: A custom field the administrator marks Required shows "* (required)" in its label, but the input, select or textarea gets no required or aria-required. The state is announced only as words in the name.
- WCAG / rule: owner rule 4 (3.3.2 / 4.1.2 polish)
- Fix: Add aria-required="true" (the form is novalidate) when $f['required'] is set.
- Status: CONFIRMED

#### [Low] "Share a document": file types and size are not in the file field's label
- Where: public/portal.php:1211-1215
- Who and what happens: The allowed types and the 15 MB limit are only in the paragraph above the form. A JAWS user who tabs to the field hears "Choose a file (required)" and nothing more. The message form's file field in the same page does put "(optional; PDF, Office, text or image, up to 15 MB)" in its label.
- WCAG / rule: owner rule 1 (hint inside the label); 3.3.2
- Fix: Add the same short hint inside the "Choose a file" label.
- Status: CONFIRMED

#### [Low] "My information" is read twice, as a hidden heading and then the summary
- Where: public/portal.php:1289-1291
- Who and what happens: A visually hidden <h3>Update my details</h3> sits directly before <summary>Update my details</summary>. Reading down, a screen-reader user hears "heading level 3, Update my details" and then "Update my details, collapsed button". That is an extra stop with the same words.
- WCAG / rule: owner rule 1 (no extra keystrokes)
- Fix: Put the heading inside the summary (<summary><h3 class="legend-h">…</h3></summary>), or drop the hidden heading.
- Status: CONFIRMED

#### [Low] Masked email on the report-confirmation page is read as a string of "star"s
- Where: public/ack.php:80-81
- Who and what happens: "j********@agency.gov" is printed as text. JAWS at its default punctuation level reads each asterisk ("star star star…"), up to the length of the mailbox name, before the domain.
- WCAG / rule: 1.3.1 / understandability (polish)
- Fix: Hide the asterisks from assistive technology and add hidden text: <span aria-hidden="true">j*******</span><span class="visually-hidden">j, hidden letters,</span>@agency.gov.
- Status: PLAUSIBLE (JAWS default punctuation)

#### [Low] Self-referrer's state has no autocomplete
- Where: public/community_referral.php:607-609
- Who and what happens: Someone referring themselves is asked their own state, but the select has no autocomplete="address-level1", so autofill and personalisation tools cannot fill it. Other referrer fields do have autocomplete.
- WCAG / rule: 1.3.5
- Fix: Set autocomplete="address-level1" when the role is self (by script on role change), and autocomplete="off" otherwise.
- Status: CONFIRMED

#### [Low] "Add to calendar" does not say that it downloads a file
- Where: public/portal.php:938
- Who and what happens: Every other download link on the portal ends with "(downloads a file)". The calendar link starts an .ics download with no warning, and some screen readers announce nothing when a download starts.
- WCAG / rule: 3.2.2 / 2.4.4 consistency (polish)
- Fix: Add <span class="visually-hidden"> (downloads a file)</span> like the others.
- Status: CONFIRMED

#### [Low] Portal "Email me a new link" accepts a blank or malformed address without a word
- Where: public/portal.php:779-788, 810-811
- Who and what happens: A blank or mistyped address (for example "name@gmail" or an empty box) still gets "If that address belongs to a client… a new link is on its way". A blind client who mistyped never learns they did.
- WCAG / rule: 3.3.1 / 3.3.3
- Fix: Refuse only an empty or syntactically invalid address, on the field and with the summary. Keep the neutral answer for a well-formed address, to protect privacy.
- Status: CONFIRMED

#### [Low] The accessibility statement is English only, with no language switch
- Where: public/accessibility.php (no enable_public_translation(), no lang_toggle())
- Who and what happens: The privacy page links to it, and it is in the client-facing list. A Spanish client who opens it from the Spanish privacy notice gets an English page with no Español link.
- WCAG / rule: Language access (polish)
- Fix: Translate it as privacy.php and help.php are translated, and add lang_toggle().
- Status: CONFIRMED

---

### Coverage
Covered fully (code and rendered page): portal.php (all sections, including homework support, the AI explanation, practice, devices, requests, offered times, self-booking, documents, messages, profile, guides, resources and the learning library), sign.php, consent.php, release.php, survey.php, ack.php, vrc.php (verify, caseload, timeout dialog, messages), community_referral.php (with custom fields), self_referral.php and referral.php (redirects), login.php, forgot.php, reset.php, verify.php, help.php (client sections), accessibility.php, the client and counselor email templates, and Spanish language marking on every Spanish page the suite rendered.

Not finished or not done: privacy.php was checked only by the Spanish-marking scan and the 320 px reflow test, not read in full. eval_report.php, amendment_request.php, kb.php and report.php are staff-only pages (require_login); they were skimmed for labels only and are left to the staff-area audit. No live JAWS, NVDA or VoiceOver run was possible. The JAWS behaviour above comes from the owner's reported describedby problem and JAWS's default punctuation level. Contrast across the vision palettes and text spacing were not re-tested (tests/accessibility_browser.js already covers them). The suite run stopped early (tests/full_schema.php:48: the app kit no longer matches the four modified android/ files in the working tree). Most public pages had already rendered, but sign.php and Spanish versions of consent and survey were not rendered and were reviewed from code.

## Area: cases

### Accessibility audit: cases area (staff daily pages), 4.29.0, 2026-10-20

Rendered pages: /private/tmp/claude-501/a11y/pages_cases (main checkout, stopped at the app-kit check) and /private/tmp/claude-501/a11y/pages_cases2 (clean copy at /private/tmp/claude-501/a11y/wt; it stopped later on a v429_api_cases voice-booking clash, after rendering signatures, availability, tasks, track and the other pages in scope). The database cms_a11y_cases has been dropped.

Overall the area is in good shape. Buttons and links repeated on each row nearly always carry the row's subject. Tables have captions, column headers and usually row headers. Error summaries take focus, and hints sit inside labels. The defects below are what remains.

#### [Medium] The training-track list on Schedule a session gives every option a "Client — Track" prefix, and the list holds every client's tracks
- Where: public/schedule.php:1104-1107 (`h($t['client_name'] . ' — ' . $t['title'])`); rendered schedule-2e648c75.html line 187 (about 60 options)
- Who and what happens: A JAWS user who has already chosen the client has to arrow through every other client's tracks. Each option repeats a client name first, so the track name comes last in every announcement. Nothing narrows the list to the chosen client, so picking another client's track is only caught on submit ("belongs to a different client").
- WCAG / rule: Owner rule 2 (no "Area — item" prefixes; use optgroup); 2.4.6 / 3.3.2 (usability of the control)
- Fix: Group the options with `<optgroup label="Client name">` and make each option the track title only. Better still, list only the chosen client's tracks, as the group form already does with `$tracksByClient`. If the list is filtered with a script, filter it on blur or debounce it, and announce nothing.
- Status: CONFIRMED

#### [Medium] A note's Edit / Add addendum link and its heading give only the subject, never the date
- Where: public/client.php:1906 (`<h3><?= h($n['subject']) ?></h3>`), 1917, 1919; public/track.php:1000, 1012, 1014
- Who and what happens: Notes are often given the same subject every week ("Weekly session", "Phone call"). In the JAWS links list and the headings list, each note then reads the same: "Edit note: Weekly session" several times. The owner cannot tell which note a link opens without reading the whole note. The default subject on track session notes includes the date, but subjects that staff type do not.
- WCAG / rule: 2.4.4 / 2.4.9 link purpose; 2.4.6 headings; owner's row-subject naming rule ("Edit note from Oct 3")
- Fix: Add the date to the hidden text, for example `Edit<span class="visually-hidden"> note from <?= fmt_date(created_at) ?>: subject</span>`, and do the same for Add addendum and "Earlier versions". Consider adding the date to the h3 too (for example "Weekly session, Oct 3, 2026").
- Status: CONFIRMED

#### [Medium] Referral view: the referral list has no heading and is filed under the open referral's "Assignment" heading
- Where: public/referrals.php:398-401 (h3 Referral notes, h3 Assignment), then 470 (filter form) and 492 (table) with no heading; rendered referrals-0e79b348.html and referrals-39585dca.html
- Who and what happens: While a referral is open, the filter form and the "Open referrals" table come straight after "h3 Assignment". With heading navigation, the owner cannot jump to the list. Screen-reader structure also presents the whole list as part of the open referral's Assignment section. Without a referral open, the page has only an H1.
- WCAG / rule: 1.3.1 info and relationships; 2.4.6 headings
- Fix: Add `<h2 id="ref-list-h">Open referrals (N)</h2>` (wording that follows the status filter) before the filter form, and point the table region's aria-labelledby at it.
- Status: CONFIRMED

#### [Low] Clients page has no heading or count for its results
- Where: public/clients.php:174-181; rendered clients.html (only the H1)
- Who and what happens: After Apply filters, the owner has no heading to jump to and hears no match count. The count is only in the hidden caption, and only when results are truncated. The owner has to find the table with T or by arrowing past the whole filter form.
- WCAG / rule: 2.4.6, 1.3.1; 4.1.3 (the result count is a status the user needs)
- Fix: Add `<h2 id="clients-h" tabindex="-1">Clients (N)</h2>` before the table, with the table region labelled by it. Optionally focus it after a filter post.
- Status: CONFIRMED

#### [Low] Goal template "Area" select labels its first option with a count: "All areas (6703)"
- Where: public/track.php:1129
- Who and what happens: The count is read every time the owner arrows onto the first option, and it changes as templates are added.
- WCAG / rule: Owner rule 2 (no "(N)" counts in option labels)
- Fix: Use "All areas" and give the count in the static hint text if it is wanted.
- Status: CONFIRMED

#### [Low] Goal template search announces the match count after every 250 ms pause in typing
- Where: public/track.php:1200-1203 (`schedule()` with `setTimeout(query,250)`, and `render()` writes `#tpl-count`, which is role=status)
- Who and what happens: A screen-reader user typing in Search with key echo hears "N shown" between words, which cuts across their own echo. The Area select was already debounced to 700 ms for this reason, but the search box was not. Also, the blur handler re-runs the query whenever `timer` is set, and `timer` is never cleared after it fires. Tabbing away from Area therefore runs the query a second time and re-writes the same count.
- WCAG / rule: 4.1.3 (status messages kept quiet); owner rule 2 (spirit: no chatty announcements while operating a control)
- Fix: Debounce the search box to about 700 ms, or announce only on Enter or blur. Set `timer=null` inside the timeout callback.
- Status: CONFIRMED (code); the double count on blur is PLAUSIBLE (depends on whether JAWS re-reads identical text)

#### [Low] Fields marked "* (required)" are not programmatically required: release form, hold reason, the signing pages
- Where: public/client.php:1555 (rel_signer), 1559 (rel_rtype), 1561 (rel_rname), 1564 (Information covered fieldset), 1574 (rel_purpose); client.php:1603-1605 (hold_reason: required by client_set_hold, but not marked at all); public/signatures.php "Their full name" (signer_name) and the hand-over page's "Electronic signature" input (rendered signatures-5b842594.html: input has no `required`). public/followups.php:171 "Still in use?" has the required radio and hidden "(required)" text but no visible marker.
- Who and what happens: JAWS hears "(required)" from the label text, but the field does not report "required", and forms mode does not list it as required. Hold reason gives no warning at all until the server refuses it. In the "Information covered" group, the checkboxes are not set aria-invalid when the group is refused; only the fieldset has aria-describedby.
- WCAG / rule: Owner rule 4; 3.3.2; 4.1.2; owner rule 3 (aria-invalid on the refused field)
- Fix: Add `required` (the forms are already `novalidate`) or `aria-required="true"`. Add the visible * and hidden "(required)" to Hold reason and to "Still in use?". Put aria-invalid on the release info checkboxes when `info` is refused.
- Status: CONFIRMED

#### [Low] Log-entry "Correct" controls name only the date
- Where: public/track.php:971 (summary "Correct the log entry from <date>") and 978 ("Save correction for <date>")
- Who and what happens: Two entries on the same day (for example a morning and an afternoon session) give identical names. The Move to trash button beside them already adds hours and description.
- WCAG / rule: 2.4.6 / 2.4.9; owner's row-subject naming rule
- Fix: Reuse the trash button's suffix (date, hours, the first 50 characters of the activity) in the summary and the Save correction button.
- Status: CONFIRMED

#### [Low] Session status options are sentences
- Where: public/schedule.php:992-997 ("Completed — hours already logged (1 hour: description…)", "Completed — log hours as well (a separate visit)", "Completed (log hours)")
- Who and what happens: Long option text is read on every arrow press in the per-row status combo box. This is the owner's most-used control on the schedule.
- WCAG / rule: Owner rule 2 (plain option labels, no sentences)
- Fix: Use short labels ("Completed, hours already logged", "Completed, log new hours", "Cancelled", "No Show"). Move the explanation into the select's label hint and the existing confirm text.
- Status: CONFIRMED

#### [Low] A hint on the intake page sits on its own line
- Where: public/intake.php:185 (`<p class="hint">Drafting again replaces the assessment draft below…</p>` before the button)
- Who and what happens: It is an extra line to arrow past, separate from the control it explains.
- WCAG / rule: Owner rule 1
- Fix: Put it inside the button as visually-hidden text (for example "Draft assessment with AI<span class=visually-hidden>: replaces the draft below</span>"). Only do this when `$generated` is set; the confirm() already covers edited drafts.
- Status: CONFIRMED

#### [Low] Confirm dialogs that do not name the item
- Where: public/client.php:2112 ("Move this document to the trash?"); public/tasks.php:285 ("Delete this task permanently?"); public/trash.php ("Permanently delete this item? This cannot be undone."); public/client_merge.php:343 ("Merge and remove the duplicate record?"); public/referrals.php ("Mark this referral as reviewed?")
- Who and what happens: The dialog is all JAWS reads, so after a mis-press on the wrong row the owner cannot catch it. Other confirms in the area already name the item (log entries, expenses, releases, follow-ups, portal link).
- WCAG / rule: 3.3.4 error prevention (the confirmation should identify what is affected)
- Fix: Put the item into the text the same way the trash buttons do (document title, task title, item description, the two record names).
- Status: CONFIRMED

#### [Low] Scored assessments table has no caption and no row headers
- Where: public/client.php:1946-1968 (first column is `<td>`; the region has an aria-label but the table has no caption)
- Who and what happens: Moving down the Score or Trend column, JAWS reads values without the instrument and date. Every other table in the area uses `th scope=row`.
- WCAG / rule: 1.3.1
- Fix: Make the instrument cell `<th scope="row">` (or instrument and date) and add a visually hidden caption.
- Status: CONFIRMED

#### [Low] The client merge review uses role="alert" for a long block present at load, and a fieldset legend of about 60 words
- Where: public/client_merge.php:277 (`<div class="alert alert-error" role="alert">`) and 312 (legend with a long hint)
- Who and what happens: Chrome with JAWS can announce a role=alert that exists at load, and then the owner reads it again when arrowing. The long legend is re-read whenever focus enters the "Blank fields" group, which slows ticking through the boxes.
- WCAG / rule: 4.1.3 (alert used for static content); owner rule 1 (spirit: no long repeated hint)
- Fix: Drop role="alert", make the review a section with an h2 ("Check before merging"), and keep the legend short. Move the email explanation into the labels of the email checkboxes only.
- Status: PLAUSIBLE (announcement behaviour not tested in JAWS)

### What was covered
Fully, from rendered pages and source: dashboard (index), clients, client (overview, notes, assessments, tracks, documents, history, releases, holds, after closure), client_edit (including the closure fieldset and the error state), client_merge (select and review), client_timeline, intake, referrals (list and detail), track (log, notes, goals, homework, expenses, template picker), schedule (single, series plan, group, row status, move, warnings approval), attendance, availability, tasks, followups, messages, notifications, signatures (staff page, hand-over page, thank-you page), letters, search, trash, homework_edit, progress_review (header only).

### Not finished or not verified
- No live JAWS or browser run: findings are from code and static HTML. The site-wide scripts (select-display.js, session-forms.js, date-entry.js) were not reviewed; they are global and outside this area.
- track_edit.php, the lower part of progress_review.php and the AI-draft states (the AI was not called in the suite) were only skimmed.
- The Spanish hand-over page was not rendered: the suite had no Spanish signing sample. From the code, its `<title>` is marked with the staff page language. That is minor and not reported.
- The page shell (vision bar h2 and nav h3 groups before the h1) is deliberate per layout.php and was left to whoever audits the shell.

## Area: admin

### Accessibility audit: admin area (billing, reports, administration, printables), release 4.29.0

Read-only review. The rendered pages came from the integration suite using a separate database (cms_a11y_admin, now dropped). The main checkout's suite stopped at the app-kit check (the owner's uncommitted Android files), so pages it did not reach were reviewed from source. A second run used the clean copy at /private/tmp/claude-501/a11y/wt (database cms_a11y_admin2, dropped). It rendered 314 pages, then stopped at a voice-booking test that collided with a 13:00 session, a timing flake unrelated to accessibility. Line numbers refer to public/ at 4.29.0.

### Billing, generated documents and reports

#### [Medium] Invoices and funder/outcome reports are untagged PDFs, and the accessible copy they link to needs a staff sign-in
- Where: invoice_pdf.php:252-389, funder_report.php:74-120, outcomes.php:136-220 (FPDF through inc/app_pdf.php: no StructTreeRoot and no MarkInfo; only /Lang and DisplayDocTitle are set). The first line of each PDF reads "Accessible HTML version (sign-in required)".
- Who and what happens:
  - These PDFs leave the organisation. Invoices are uploaded to state VR agencies' portals, and funder reports go to funders. A blind VR counselor or fiscal reviewer with JAWS or NVDA gets an untagged file: no headings, and the line-item grid is not a table, so they hear a run of numbers with no Date, Hours, Miles or Amount headers.
  - The letterhead block is laid out as two side-by-side Cells per line, so the reading order interleaves the two columns: "Org name INVOICE / tagline Date: ... / remit line Invoice number ...".
  - The accessible HTML link in the PDF opens a sign-in page that no outside reader can pass. The conformance report lists generated documents as "Not covered".
- WCAG / rule: 1.3.1, 1.3.2, 4.1.2 (applied to non-web documents per WCAG2ICT / Section 508 E205.4)
- Fix: produce tagged PDFs (for example TCPDF or mPDF with tagging, or HTML to tagged PDF through Chromium headless), with a real table with TH for the line items and H1/H2 for the sections. Alternatively, let staff download the self-contained HTML copy (it already exists, using AccessibleDocument) as a file to attach next to the PDF, and change the PDF's first line to say so instead of pointing at a sign-in link. At minimum, say in the conformance report that these documents are untagged.
- Status: CONFIRMED (code)

#### [Medium] Custom reports: the result table has no scroll container, so wide reports overflow the page at 320 px and cannot be scrolled with the keyboard
- Where: custom_reports.php:135 `<div class="table-wrap"><table class="table">`. No `.table-wrap` rule exists in assets/styles.css; every other report uses `.table-scroll` with tabindex="0" and role="region".
- Who and what happens: a user can pick every column of a subject, more than 10. Each cell keeps at least 1.5rem of padding, so past about 8 columns the table is wider than a 320 px viewport (or 400% zoom), and the whole page scrolls sideways. A keyboard user has no focusable region to scroll it. tests/accessibility_browser.js does not catch this because the test renders this page with few columns.
- WCAG / rule: 1.4.10, 2.1.1
- Fix: use `<div class="table-scroll" tabindex="0" role="region" aria-labelledby="result-h">`, as the other reports do.
- Status: CONFIRMED (markup/CSS); overflow PLAUSIBLE for many columns (not measured in a browser)

#### [Medium] A refused save on custom reports and on the incoming-authorization paste form gives no error summary and moves focus nowhere
- Where: custom_reports.php:16 (report_name error) with the form at :141-149 (field_error only, no form_error_summary); authorizations.php:31-32 (email_text error) with the form at :144-150 (no form_error_summary).
- Who and what happens: the redirect lands on #save-h or #paste-h with only an inline error, tied by aria-describedby, which is the channel the owner reports JAWS skipping. There is no flash and no summary, so nothing takes focus or is announced except "Error —" in the title. A JAWS user who presses Save report, or Read the email, hears the page reload and may believe it worked.
- WCAG / rule: 3.3.1, 4.1.3; owner rule 3
- Fix: echo `form_error_summary(['report_name' => 'Name'])` inside the save form, and `form_error_summary(['email_text' => 'The authorization email'])` inside the paste form.
- Status: CONFIRMED

#### [Low] Several refusals of typed input are flash-only, not tied to the field, and the input is lost
- Where:
  - qbo.php:18 ("Enter the first date as a full date.": flash and redirect, no keep_input, no field error on #since)
  - invoices.php:131-143 (bulk "Mark the chosen invoices submitted": no box ticked, or a date before creation, gives a flash only; neither the fieldset nor #bulk_submitted_on is marked)
  - portal_requests.php:87 ("choose at least one open request": flash only)
  - authorizations.php:50-52 ("Choose the client this authorization is for.": flash, with the #client-choice fieldset not marked)
- Who and what happens: the user hears the flash, but the control is not aria-invalid and has no inline error. On qbo.php the typed date is replaced by the stored one, so it must be retyped.
- WCAG / rule: 3.3.1, 3.3.7; owner rule 3
- Fix: keep_input() and keep_field_errors() keyed to the control or fieldset, plus form_error_summary in those forms, and no flash for the same error.
- Status: CONFIRMED

#### [Low] Funder report and templates: option labels carry counts and sentences
- Where:
  - funder_report.php:142-143 (rendered "State VR — 1 training track", "... — no current tracks, billed work only")
  - templates.php:313 ("All areas (6703)")
  - audit.php:273 (client filter options "Name — #12", with "(archived)")
- Who and what happens: JAWS reads the count and sentence on every arrow press through the list, against the quiet-combo rule.
- WCAG / rule: owner rule 2
- Fix:
  - Funder report: plain agency names. Put agencies with no current tracks in an `<optgroup label="Billed work only">`, and give the track count on the page after Run.
  - Templates: "All areas".
  - Audit: keep the #id only where two names collide, or group archived clients in an optgroup.
- Status: CONFIRMED (rendered pages)

#### [Medium] Letters are always marked lang="en" and filled in English, even for a client whose preferred language is Spanish
- Where: letters.php:48 `plain_document_html($title, $body)` (inc/portal_history.php:24 defaults `$lang = 'en'`); LETTER_TEMPLATES are English only. The client's preferred_language (client_lang()) is never consulted.
- Who and what happens: a letter written in Spanish for a Spanish-speaking client (staff translate the English wording) is saved to the client's documents and portal as `<html lang="en">`. The client's screen reader reads it with an English voice. A letter left in English on a Spanish portal is correctly marked, but the client gets no Spanish wording at all.
- WCAG / rule: 3.1.1; owner rule 5
- Fix: pass client_lang($cid) to plain_document_html, or add a "Letter language" choice on the form. Add Spanish built-in wording (letter_es_*), as wording.php already does for interface text.
- Status: CONFIRMED (code); the impact depends on staff writing Spanish letters

#### [Low] Survey comments from Spanish-speaking clients are shown without lang="es"
- Where: survey_responses.php:58 (`<td><?= h((string)$r['comments']) ?></td>`); also the CSV. The survey page is bilingual (survey.php lang toggle), but the answer's language is not stored or used.
- Who and what happens: JAWS reads Spanish comments with an English voice on the English staff page.
- WCAG / rule: 3.1.2; owner rule 5
- Fix: store the page language with each answer (or use client_lang()), and wrap the comment in `<span lang="es">` when it is Spanish.
- Status: PLAUSIBLE

#### [Low] Letters: the Letter body is required but not marked
- Where: letters.php:101 (textarea #body). It is refused when empty (:40), but has no required_mark() and no `required` or aria-required.
- WCAG / rule: 3.3.2; owner rule 4
- Fix: required_mark() in the label and `required` (the form is not novalidate, so add novalidate as well, so the server summary handles it).
- Status: CONFIRMED

#### [Low] Report period notes ("The From date could not be read") are a plain paragraph, not tied to the date field
- Where: inc/report_period.php:56 and :82 notes, printed as a `<p>` by funder_report.php:155, agency_scorecard.php:99, referral_deadlines.php:32, survey_responses.php:46 and the other reports using report_period_form.
- Who and what happens: an unreadable date silently falls back to the preset or default range. The note is not announced and the field is not aria-invalid, so a JAWS user can run a report for a period they did not ask for without noticing.
- WCAG / rule: 3.3.1; owner rule 3
- Fix: when notes exist, mark the From or To field aria-invalid with an inline error and show a focused summary (or role="status" text placed first after the h1).
- Status: CONFIRMED (code)

### Administration pages (fork review, all CONFIRMED in code unless noted)

#### [Medium] Users: a wrong confidentiality or privacy-training date has no error summary and gets no focus
- Where: users.php:95-97 (validation), :502-503 (form_error_summary label list leaves out confidentiality_signed_on and privacy_training_on)
- Who and what happens: the save is refused with only inline errors. Nothing is announced or focused.
- WCAG / rule: 3.3.1, 4.1.3; owner rule 3
- Fix: add both keys to the summary labels, with anchors u_conf and u_priv.
- Status: CONFIRMED

#### [Medium] My account: a refused mobile number has no error summary
- Where: account.php:19 (keep_field_errors mobile), form :314-319 (no form_error_summary)
- Who and what happens: the page reloads at #mobile-h with only an inline aria-describedby error. The user believes the number was saved.
- WCAG / rule: 3.3.1, 4.1.3; owner rule 3
- Fix: `form_error_summary(['mobile' => 'Mobile number'])` in that form.
- Status: CONFIRMED

#### [Medium] Custom fields: required custom fields are not programmatically required, and English parts on the Spanish referral form are not marked lang="en"
- Where: inc/custom_fields.php:137-160 (custom_fields_html); custom_fields.php:66-72 offers only a Spanish label (no Spanish hint or choices)
- Who and what happens:
  - A required custom field says "(required)" or "requerido" only in its label text. There is no `required` or aria-required, on staff pages and on the public referral form.
  - On the Spanish form, the hint, the choice options and any label without a Spanish version come out in English with no lang="en", so they are read with a Spanish voice.
- WCAG / rule: 1.3.1, 3.3.2, 3.1.2; owner rules 4 and 5
- Fix:
  - Emit `required aria-required="true"` on required fields.
  - On Spanish pages, wrap English text in lang="en" (on the option itself for choices).
  - Add hint_es and options_es.
- Status: CONFIRMED

#### [Medium] Equipment reservation refused because the item was retired: flash only, and the typed values are lost
- Where: equipment.php:182-185 (and the DomainException flash on return, :282)
- Who and what happens: the client, dates and notes the user typed are discarded, and the message is not tied to the item field.
- WCAG / rule: 3.3.1, 3.3.7; owner rule 3
- Fix: keep_input() and keep_field_errors(['res_equipment_id' => ...]), then redirect to #res-form.
- Status: CONFIRMED

#### [Low] Forms with `required` controls but no `novalidate`, so Chrome's bubble replaces the site's error summary
- Where: equipment.php:572 (scan-page loan form), equipment.php:966 (#res-form), procurement.php:370, :442, :471, programs.php:326, at_services.php:222, :230
- Who and what happens: on an empty submit, Chrome shows its transient validation bubble, which JAWS reads inconsistently, and the focused summary never appears. This is inconsistent with the rest of the site.
- WCAG / rule: 3.3.1; owner rule 3
- Fix: add novalidate (the server already checks). Turn the flash refusals at procurement.php:285 into field errors.
- Status: CONFIRMED

#### [Low] Required fields not visibly or programmatically marked
- Where:
  - account.php:231-242 (password change fields: `required`, but not visibly marked)
  - account.php:443-444 (#pk_pw: marked "(required)", but not programmatically required)
  - account.php:266-267 (#td_pw) and :298-299 (#totp_code)
  - vendors.php (#nv_vendor and #nv_service: label says required, control is not)
  - programs.php:326 (#add_client: required, label not marked) and :357-358 (session_date, duration_min)
  - staff_training.php:122-127; staff_agreements.php:148-151, 171-174
  - at_services.php:223, 225; update.php:108 (have_backup checkbox); templates.php:548 (higher_is_better radios)
  - funding_agencies.php:126 (Agency name, every agency form) and vr_portal.php:91, :150 (note_t*, note_i*): label says "(required)", control has no `required`/aria-required (rendered pages)
  - admin.php:325, 330, 340: a bare "*" that JAWS reads as "star", instead of required_mark()
- WCAG / rule: 3.3.2, 1.3.1; owner rule 4
- Fix: required_mark() in each label together with `required` or aria-required on each control.
- Status: CONFIRMED

#### [Low] An asterisk is used without a "fields marked * are required" note
- Where: access_bookings.php:165, curriculum.php:333, programs.php:451, score_assessment.php:162
- WCAG / rule: 3.3.2
- Fix: add the same required-note paragraph that equipment.php and vendors.php use.
- Status: CONFIRMED

#### [Low] Option labels with status suffixes
- Where: equipment.php:745 and at_services.php:225 ("... — held by a reservation"); equipment.php:975 ("Name (Available)" / "(On Loan)")
- WCAG / rule: owner rule 2
- Fix: use optgroups by status, and keep the option text to name and serial.
- Status: CONFIRMED

#### [Low] Credentials: the "Other" name error is put on the Credential select, not on the name field
- Where: credentials.php:40 (error keyed type) and :78-79 (other_name has no field_aria or field_error)
- WCAG / rule: 3.3.1; owner rule 3
- Fix: key the error to other_name, and give that field field_aria, field_error and the summary anchor.
- Status: CONFIRMED

#### [Low] Graph secret expiry date: flash-only error, and the typed value is lost
- Where: system_health.php:45 (form at about :232-236)
- WCAG / rule: 3.3.1, 3.3.7; owner rule 3
- Fix: keep_input(), keep_field_errors(['graph_expires' => ...]), field_aria, field_error and a summary.
- Status: CONFIRMED

#### [Low] Help text outside the label (separate lines)
- Where: admin.php:563 (note-lock), admin.php:578 (retention), email_templates.php:79 (the "Available placeholders" paragraph above the Body field), users.php:599 (caseload transfer)
- WCAG / rule: owner rule 1 (3.3.2)
- Fix: move each into the control's `<label>` as `<span class="hint">`.
- Status: CONFIRMED

#### [Low] Adding a passkey: a wrong password leaves focus on the button, and the cancel message names a button that does not exist
- Where: account.php:448-505
- Who and what happens:
  - The error goes to a role="alert" paragraph and the password field gets aria-invalid plus aria-describedby, but focus stays on the button.
  - The cancel text says "Choose Add passkey", while the button reads "Add a passkey on this device".
- WCAG / rule: 3.3.1; owner rule 3
- Fix: focus #pk_pw on a password error, and match the button's wording.
- Status: CONFIRMED

#### [Low] Spanish setting values are not marked lang="es"
- Where: settings.php:126-127 (brand.privacy_law_es and any key ending in _es)
- WCAG / rule: 3.1.2; owner rule 5
- Fix: add lang="es" to inputs for _es keys, as wording.php:71 already does.
- Status: CONFIRMED

### Checked and found sound (not reported)
- Analytics charts: each bar chart is a data table, with the bar aria-hidden and a visually-hidden percentage. The NPS trend is aria-hidden, with a "Show as table" alternative.
- All report tables have captions, scoped column headers and (mostly) row headers, inside keyboard-scrollable regions. The exceptions are custom_reports (above) and the small 2- and 3-column tables on at_act_report and pricing, which do not need scrolling.
- The AccessibleDocument HTML copies (invoice, funder, outcomes, eval, procurement and accommodations reports) have an h1/h2 outline, dl properties, captioned tables with th scope, lang="en" and a focus ring.
- Equipment QR labels: the QR SVG is labelled, the item name is a link, and the print styles are black on white.
- CSV downloads: a BOM, a header row, and title and period rows above it on the agency summary and the scorecard. Readable in Excel with JAWS; not a defect.
- invoices.php: every repeated control carries the invoice number as visually-hidden text, and the pay, close and memo errors use summary anchors per row.
- vr_portal.php: a page-level summary, required marks and named buttons.
- Brand colours are refused below 4.5:1 and fall back at render time.

### Coverage
- Fully covered:
  - from code: invoices, invoice_pdf (PDF and HTML), authorizations, unused_authorizations, vr_portal, qbo, custom_reports, analytics, agency_report, funder_report, compliance, audit, capacity, survey_responses, referral_deadlines, agency_scorecard, data_quality, at_act_report, pricing, letters, equipment_labels
  - through two parallel reviewers: all the admin pages listed, plus equipment, procurement, programs, accommodations, curriculum, access_bookings, at_services, score_assessment, vendors, staff_training, staff_agreements, credentials
  - the rendered pages that the main-checkout run produced, scanned automatically for headings, table headers, required-marking mismatches, option labels, describedby, unlabelled controls and empty links (no heading skips, missing headers or unlabelled controls were found in scope)
- Not finished:
  - contracts.php, funding_agencies.php, portal_requests.php, outcomes.php, rsa_report.php, business_summary.php, staff_report.php, utilization.php, doc_audit.php and at_report.php had only a structural pass (headings, tables, flash-only errors), not a line-by-line read.
  - No live browser run: contrast in the vision palettes, 400% zoom and focus visibility were not measured.
  - The PDFs were not opened in a reader; the untagged finding comes from the FPDF code.
  - Progress-report emails are plain text, so only their portal copy was checked.

## Area: automated

### Automated accessibility scan (area: automated), release 4.29.0, 2026-10-20

Method: 329 pages rendered by tests/full_schema.php from a clean export of HEAD 1099ab9 (the same tree as /private/tmp/claude-501/a11y/wt), using my own database cms_a11y_automated, which is now dropped. Pages are in /private/tmp/claude-501/a11y/pages_auto. Pages were served the same way as tests/accessibility_browser.js: only audit.invalid is allowed, /assets/* maps to public/assets, and everything else is blocked. Tools: system Chrome, playwright 1.48.2, axe-core 4.10.2.
- Full axe run (wcag2a, wcag2aa, wcag21a, wcag21aa, wcag22aa, best-practice) on every page at 1280 px and at 320 px.
- Contrast rules (color-contrast, link-in-text-block) on every page in each vision palette: light, dark, black, white, yellow, cream. Every page has the palette switch. Each palette was set through the cms_low_vision localStorage key, and data-vision-palette was checked as applied on every scan.
- 2,632 scans, with no load errors.
- tests/accessibility_browser.js run on the same pages: PASS (329 pages, 1,316 reflow scenarios, skip link, table scroll, menu, palettes, print).
- Axe does not catch clipped controls, so I ran two extra layout probes on every page at 320 and 1280: selects too narrow to show their value, and words split across lines inside buttons. Screenshots: /private/tmp/claude-501/a11y/s1.png, s2.png, s3.png, s6.png, r1.png, r2.png, r3.png, ov1b.png.

#### [Medium] Buttons and selects inside data tables shrink until their text is unreadable (words split mid-word, select values hidden)
- Where: public/assets/styles.css:342 `button, input, select, textarea { max-width: 100%; }` and :344 `button, .btn, .brand { white-space: normal; overflow-wrap: anywhere; }`. The table rule at :384-385 resets overflow-wrap only on th/td, not on the controls inside them. With `overflow-wrap: anywhere` and a percentage max-width, a control's minimum width falls to about one character. Auto table layout then squeezes those columns to fit 100% width, so the `.table-scroll` region never gets to scroll.
  - At 1280 px: "View" shows as "Vie / w" on Referrals lists (referrals-*.html, 9 pages). "Manage" shows as "Manag / e" on users.html (3 pages). The status select (equipment.php:918, `es-N`) shows "Availat" and the service-type select (equipment.php:863, `mt-N`) is clipped on equipment.html.
  - At 320 px:
    - The equipment and access-booking status selects are 40-44 px wide and show no value at all (s1.png, s6.png).
    - The schedule outcome select (st-N) is 58 px wide and shows only "—" (s2.png).
    - The admin group select (gr-N) shows "— Leg" (s3.png).
    - Button words break mid-word on about 20 pages: Save (as "Sa ve"), Restore (one letter group per line, r3.png), Delete, Move, Cancel, Mark returned, Confirm, Request, Open, Manage, View, "correction", "recommended".
    - Affected pages: access_bookings, equipment, schedule, trash, users, vrc, compliance, procurement, invoices, track, client.
- Who and what happens: a low-vision staff member using magnification, zoom or the 320 px reflow width cannot read a row's current status or chosen outcome before saving. Button names come out as broken fragments ("Sa ve", "R es to re"). Screen-reader names are unaffected.
- WCAG / rule: 1.4.10 Reflow (content lost at 320 CSS px) and 1.4.4 Resize Text. The existing browser test misses this: it checks only that the document is not wider than the viewport.
- Fix: in styles.css, add `.table-scroll :is(button, .btn) { overflow-wrap: normal; white-space: nowrap; }` (or `break-word`, which does not reduce min-content) and `.table-scroll select { max-width: none; min-width: max-content; }`. The table then scrolls inside its focusable region instead of crushing the controls. To catch regressions, add a check to tests/accessibility_browser.js: no visible word inside a button wraps across lines, and no visible select is narrower than its selected option text.
- Status: CONFIRMED (rendered and screenshotted; computed widths of 40-62 px recorded)

#### [Low] Two regions on the client page share the name "Scored assessments"
- Where: public/client.php:1931 (`<section class="card" aria-labelledby="scored-h">`, h2 "Scored assessments") and public/client.php:1947 (`<div class="table-scroll" tabindex="0" role="region" aria-label="Scored assessments">`). Page client-17068e02.html, assessments tab. axe landmark-unique, at both 1280 and 320 px.
- Who and what happens: in the JAWS region list (Insert+Ctrl+R) or when moving by region, two nested regions are announced with the same name. The user cannot tell the section from its table.
- WCAG / rule: best practice (relates to 1.3.1 and 2.4.6). Other table-scroll regions on the site have distinct names, and this is the only axe hit across all scans.
- Fix: rename the scroll region, for example `aria-label="Scored assessments table"`, as other tables do.
- Status: CONFIRMED

### Rule hits dropped as false positives
- color-contrast "incomplete" results (no violations in any palette):
  - elmPartiallyObscured: about 1,500 nodes, mostly empty textareas or controls below the fold. They contain no text, or axe cannot sample a background behind form controls. Link and text contrast passed wherever axe could measure it.
  - nonBmp: the decorative `<span aria-hidden="true">← </span>` arrow in back links, an icon glyph in the same color as the link.
  - elmPartiallyObscuring: about 60 nodes. I measured overlaps on each sampled case. On equipment the only "overlap" is the clip box of a visually-hidden span inside the Service summary, and other cases had no overlapping visible text. The real problem behind several of these is the table squeeze reported above.
- No other axe rule fired on any page, width or palette: target-size, labels, ARIA, headings, landmarks, lists, tables, lang and duplicate ids are all clean.

### Coverage
Covered fully:
- All 329 rendered pages: axe full run at 1280 and 320 px, plus contrast in all 7 palette states (auto/default and the 6 named palettes).
- The select-clipping and split-word probes at 320 and 1280 px.
- tests/accessibility_browser.js: passes.

Not covered:
- The 125-200% vision text sizes with axe. The existing test checks those only for page overflow, and the table squeeze above is likely worse there.
- Dark scheme combined with the "auto" palette. I tested the explicit dark palette instead.
- Pages the suite does not render.
- The apps.

Harness notes, not accessibility defects:
1. In the main checkout, full_schema.php stops at v418_cases.php because of the owner's uncommitted android/ build-file edits. I left them untouched and rendered from a clean export.
2. tests/v429_api_cases.php:181 is clock-dependent. It books a voice session at "+16 days 13:00", which collided with an earlier case's session when the suite ran at 12:10. I changed it to 21:00 in my scratch copy only.

## Area: ios

### iPhone app accessibility audit (area: ios), 2026-10-20, release 4.29.0

Scope: ios/Sources, ios/Widget, ios/UITests. Read-only review of the SwiftUI. The app builds cleanly (Debug, iPhone 17 Pro simulator, OS 26.5, 0 warnings). It was not run signed in, so nothing here comes from a rendered screen. CONFIRMED means the code shows the defect. PLAUSIBLE means the code strongly suggests it but nobody has seen it on a device.

Overall the app is in very good shape. These are already handled well: one VoiceOver stop per row with the row's buttons in the Actions rotor (VoiceOverRows), Voice Control input labels on named buttons, error banners that are announced and focused, announcements made after a sheet closes, the Spanish voice used throughout in-person signing, a typed name as an alternative to drawing a signature, the lock screen and app-switcher cover, the widget and Siri never naming a client, 7:1 custom colours, and 44-point targets. The problems below sit mostly in screens that came in with 4.15 and 4.16 (Agreements, Training, Credentials) and in a few gaps that affect the whole app.

#### [Medium] White text on light-blue buttons in dark mode: "Check in" and "Sign the agreement"
- Where: ios/Sources/ScheduleView.swift:393 (Check in on each session row), ios/Sources/AgreementsView.swift:82 (Sign the agreement)
- Who and what happens: In dark mode the brand tint is light blue (#C2D0FB). These two `.borderedProminent` buttons don't use `.onBrandLabel()`, so their labels stay white. The app's own OnBrandLabel note (CaseManagementApp.swift:918) puts that at about 2:1. A low-vision instructor in dark mode can't read Check in, which they use every day. Every other prominent button in the app sets onBrandLabel.
- WCAG / rule: 1.4.3 Contrast (Minimum)
- Fix: Add `.onBrandLabel()` before `.disabled(...)` on both buttons. To stop this coming back, check in a test (or a lint step) that every `.borderedProminent` is followed by `.onBrandLabel()`.
- Status: CONFIRMED (code; the contrast figure is the project's own)

#### [Medium] Invoices: "Mark submitted" works only by swiping, with no visible button
- Where: ios/Sources/InvoicesView.swift:228-236 (DraftSubmitActions)
- Who and what happens: For a draft invoice, Mark submitted is offered as a swipe action and a VoiceOver custom action, and nowhere else. Someone who can't swipe, or doesn't know the swipe is there (Switch Control, Voice Control, a head pointer, low dexterity, a sighted user who never discovers it), has no single-tap way to do it. Other screens fixed exactly this; ProgramsView.swift:100 says "A visible single-tap way to remove, as well as the swipe action".
- WCAG / rule: 2.5.1 Pointer Gestures (a path gesture with no single-pointer alternative)
- Fix: Add a visible bordered, large "Mark submitted" button to the draft row, named after the invoice, with input labels, as the Equipment and Programs rows have.
- Status: CONFIRMED

#### [Medium] Some row actions exist only in the long-press menu and the VoiceOver Actions rotor
- Where: ios/Sources/DocumentsToSign.swift:97-99 (Remove from this phone on a prepared document), :130-132 (rowActions: Withdraw, Sign here now / resume); ios/Sources/TrackDetailView.swift:665-685 (hours entries: Correct hours or miles, Delete entry); ios/Sources/DocumentsAndHomework.swift:188-190 (document sharing choices)
- Who and what happens: Nothing on screen shows that these actions exist. VoiceOver users get them on the rotor. Sighted users of Switch Control, Voice Control or Full Keyboard Access, and anyone who doesn't think to long-press, can't find how to withdraw a document waiting for signature, correct or delete a wrong hours entry, or change who can see a document. In the Documents to sign list and the hours list the row isn't a control at all, so Voice Control's "Show names" or "Show numbers" offers nothing to act on.
- WCAG / rule: No exact success criterion. Closest are 2.1.1 Keyboard (Full Keyboard Access) and 3.3.2 / discoverability. Apple's guidelines say context menus must repeat actions that are visible elsewhere.
- Fix: Give each of these rows a visible control: either a bordered "Actions" Menu button (named after the row, at least 44 points), or the buttons themselves, as the Schedule and Equipment rows already have. Keep the Actions rotor entries.
- Status: CONFIRMED (code shows no visible control)

#### [Medium] Training and agreements: errors are said twice, or said but not shown, and aren't scrolled to
- Where: ios/Sources/TrainingView.swift:103 and :117-121 (TrainingTakeView.submit); :223 (CredentialEditView.save); ios/Sources/AgreementsView.swift:104 and :114-118 (AgreementDetailView.sign)
- Who and what happens: These screens set `error = ...` and then call `announce(...)` themselves, instead of using `showFormError`. ErrorBanner only skips its own announcement when showFormError has just made it (CaseManagementApp.swift:996-1001).
  - Agreement detail is a non-lazy ScrollView, so the banner appears and announces again. The person hears "Turn on that you have read the agreement…" twice.
  - Training is a long Form whose banner is the first row. At "Check my answers" that row isn't drawn, so the error ("question 3 has no answer") is spoken once and never shown. When the person later scrolls to the top, the banner appears and is announced again, long after it means anything.
  - In both screens nothing scrolls to the banner or moves focus to it, and the unanswered question or agreement switch isn't pointed to.
- WCAG / rule: owner rule 3 (one error summary that takes focus, never announced twice); 3.3.1 Error Identification; 4.1.3 Status Messages
- Fix: Use `showFormError(_:in:)`. Wrap TrainingTakeView and CredentialEditView in `ErrorFocusForm`. For Agreement detail, use a ScrollViewReader that scrolls to the banner and focuses it, as ErrorFocusForm does. For a missing training answer, put VoiceOver on that question's header (AccessibilityFocusState) instead of only naming its number.
- Status: CONFIRMED

#### [Medium] Errors are never tied to the field they are about
- Where: every form, e.g. ios/Sources/ClientEditSheet.swift:499-540 (the "Full name: …", "<field>: this is required", "Choose the state…" checks) and ErrorFocusForm (ClientEditSheet.swift:978-1011); grep finds no field-level error marking anywhere in the app
- Who and what happens: When a form is refused, the message goes into one banner at the top of the form and VoiceOver focus goes to the banner. The field it names isn't marked, focused, or given the error in its value or hint. In a long form such as Client edit, a VoiceOver or Switch Control user has to work back down the form to find "State" or a custom required field. A magnifier user sees nothing next to the field.
- WCAG / rule: owner rule 3 (tie each error to its field); 3.3.1 / 3.3.3
- Fix: Have the validators return the field as well as the message. Show a short inline error under that field (alertRed caption, with "Error:" in words), add it to the field's accessibilityValue, and after the banner has been read (or instead of it, when there is only one error) move AccessibilityFocusState to the field.
- Status: CONFIRMED

#### [Medium] Dashboard figures break mid-number and mid-word at the largest text sizes
- Where: ios/Sources/DashboardView.swift:46 (`LazyVGrid` with two `.flexible()` columns, always) and StatTile (:319-337)
- Who and what happens: The four tiles stay in two columns at every text size. At AX5 a column is about 150 points wide. The figure is in `.title.bold()` (around 58 points), so "1234.50" in Unbilled hours doesn't fit and wraps part-way through the number. Captions such as "Pending assessment" and the long unbilled-hours caption break mid-word. The project fixed the same thing on Utilization (UtilizationView.swift:84-88, which stacks tiles at accessibility sizes), and this is the screen everyone lands on. The largest-size UI audit doesn't visit the Dashboard.
- WCAG / rule: 1.4.4 Resize Text; 1.4.10 Reflow
- Fix: At `typeSize.isAccessibilitySize`, use a single column (`[GridItem(.flexible())]`) or a VStack. Add the Dashboard to the largest-size UI audit.
- Status: PLAUSIBLE (layout arithmetic; not rendered)

#### [Low] Success announcements are cut off when the screen closes
- Where: ios/Sources/TrainingView.swift:111-112 (training passed), :222 (credential saved), :227 (credential removed); ios/Sources/AgreementsView.swift:112-113 (agreement signed)
- Who and what happens: `announce(...)` and then `dismiss()` straight away. The pop or sheet dismissal moves VoiceOver focus, which cuts the message off. The app has `announceAfterDismiss` for exactly this case, and the other screens use it. The person may never hear "Passed" or "Signed".
- WCAG / rule: 4.1.3 Status Messages
- Fix: Use `announceAfterDismiss(...)` (or announce from the list screen once it reappears).
- Status: CONFIRMED

#### [Low] An error is spoken, then spoken again when focus lands on its banner
- Where: ios/Sources/CaseManagementApp.swift:974-979 (`showFormError` announces at once), together with the focus moves 400 ms later in ClientEditSheet.swift:999-1008 (ErrorFocusForm), ScheduleView.swift:132-139, IncidentsView.swift:111-118, EquipmentView.swift:122-128 and the other list screens with an error row
- Who and what happens: showFormError announces the message. The screen then scrolls to the banner and moves VoiceOver focus to it, and VoiceOver reads the banner's label "Error: <message>". The person hears the start of the message, cut off, then the whole message again.
- WCAG / rule: owner rule 3 (the same error must not be announced twice)
- Fix: When the screen is going to focus its banner, skip the announcement (for example, a `showFormError(_:in:announce: false)` used by ErrorFocusForm and the list screens that focus their banner). Keep the announcement only where nothing takes focus.
- Status: PLAUSIBLE (the timing makes the overlap near-certain; not heard on a device)

#### [Low] Sign-in code step: the instructions are read twice in a row
- Where: ios/Sources/LoginView.swift:193 (`.accessibilityHint(codeInstruction)` on the code field) and :299-311 / :347 (focusCodeField then queues "Password accepted. " + codeInstruction)
- Who and what happens: With VoiceOver hints on (the default), focus lands on the code field and VoiceOver reads its label and the full hint, which is the instruction. Then the queued announcement reads the same instruction again. Each time it is about 30 words about the emailed code and when it runs out, on every sign-in. The owner signs in often.
- WCAG / rule: owner rule 1 (no extra speech or stops); 4.1.3
- Fix: Drop the field's hint, so the announcement carries the instruction, or announce only "Password accepted." and let the hint carry the rest.
- Status: CONFIRMED

#### [Low] Menu pickers outside ErrorFocusForm still cut their choice off at accessibility text sizes
- Where: ios/Sources/CompetencyView.swift:42 (Curriculum) and :67-72 (Level, an explicit `.pickerStyle(.menu)`); ReferralsView.swift:403 (Assigned to) and :449 (Reason); ProgramsView.swift:123 (Student to add); TrainingView.swift:190 (Credential); InvoicesView.swift:113 (Show); FollowupsView.swift:304 (Satisfaction); AIToolsView.swift:418; ATServicesView.swift:685, :723, :812; VoiceAssistant.swift:1219-1226 (Pause before sending, "Until I tap the microphone")
- Who and what happens: The 4.26.0 audit found that menu pickers cut their chosen value in the middle at the accessibility sizes ("Activ…aining"). ReadablePickers fixes this, but only inside ErrorFocusForm. These pickers are outside it, so at the largest sizes a low-vision user sees a cut-off staff name, curriculum or reason. Competency's explicit `.menu` style would override the fix even if it were applied.
- WCAG / rule: 1.4.4 Resize Text
- Fix: Apply `.modifier(ReadablePickers(inNavigationStack: true))` to these Lists and Forms (all of them are inside a NavigationStack), and remove the explicit `.pickerStyle(.menu)` in CompetencyView.
- Status: PLAUSIBLE (same control and the same sizes as the project's own finding)

#### [Low] Segmented pickers don't grow with Dynamic Type
- Where: ios/Sources/TrackDetailView.swift:562-568 (goal Status: Not Started / In Progress / Achieved); MessagesView.swift:251-255 (Client / Counselor); FollowupsView.swift:297-299 (Still in use?)
- Who and what happens: Segment titles stay at the fixed system size at every text size, apart from the long-press Large Content Viewer. A low-vision user at a large text size gets small text on the goal status they change during sessions.
- WCAG / rule: 1.4.4 Resize Text
- Fix: At `typeSize.isAccessibilitySize`, swap to a menu or navigationLink picker (or a stacked set of buttons), as the app already does for outcome buttons with AnyLayout.
- Status: PLAUSIBLE

#### [Low] Steppers made into one VoiceOver element also hide + and − from Voice Control and Switch Control
- Where: ios/Sources/AccessibilityHelpers.swift:6-19 (voiceOverAdjustable: `accessibilityElement(children: .ignore)` always). Used by LogHoursView.swift:130 (Hours), ScheduleView.swift:852 and :856 (Duration, Repeats), BookingsView.swift:252, TrackDetailView.swift:988
- Who and what happens: Unlike VoiceOverRows, this is applied whether VoiceOver is running or not. With Voice Control, the stepper's Decrement and Increment buttons no longer exist, so "Tap Increment" does nothing and the person has to use the grid. Logging hours is a core task.
- WCAG / rule: 2.1.1 Keyboard (Full Keyboard Access); 4.1.2 Name, Role, Value
- Fix: Use `children: UIAccessibility.isVoiceOverRunning ? .ignore : .contain`, as VoiceOverRows does, or add `.accessibilityInputLabels`-named buttons.
- Status: PLAUSIBLE (Voice Control's handling of adjustable elements wasn't tested)

#### [Low] Move session: the Length stepper is three VoiceOver stops
- Where: ios/Sources/ScheduleView.swift:1204
- Who and what happens: Every other stepper is one adjustable element. This one gives VoiceOver a label, then a separate Decrement and Increment, so it takes three swipes and works differently from the New session form beside it.
- WCAG / rule: consistency (3.2.4); owner rule 1
- Fix: Add `.voiceOverAdjustable("Length", value: hoursSpoken(hours), binding: $hours, in: 0.25...12, step: 0.25)`.
- Status: CONFIRMED

#### [Low] Spoken hints repeat what VoiceOver already says
- Where: ios/Sources/AccessibilityHelpers.swift:12 ("Swipe up or down to change." on every adjustable); ScheduleView.swift:483 and EquipmentView.swift:275 ("Swipe up or down for actions.")
- Who and what happens: VoiceOver already says "adjustable" and "Actions available" from the trait and custom actions, so each row or stepper gets a second instruction.
- WCAG / rule: owner rule 1 (no extra speech)
- Fix: Remove these hints. Keep the hint on Equipment for "Double-tap for service and details" only.
- Status: CONFIRMED

#### [Low] Some required fields aren't marked, and their Save button is just dimmed
- Where: ios/Sources/ReferralsView.swift:449-455 (Close referral: Reason); TrackDetailView.swift:1457-1476 (Email report: Signature); TrainingView.swift:190-199 (Credential); AgreementsView.swift:78-85 (name to sign)
- Who and what happens: The button stays off until the field is filled, but the field doesn't say "(required)" and the dimmed button gives no reason. Elsewhere the app does this properly: ATServicesView "Client (required)" with a hint on Issue, and FollowupsView "Still in use? (required)".
- WCAG / rule: owner rule 4; 3.3.2 Labels or Instructions
- Fix: Add "(required)" to these labels and an accessibilityHint on the disabled button saying what is missing.
- Status: CONFIRMED

#### [Low] Reduce Motion ignored when scrolling to an error
- Where: ios/Sources/IncidentsView.swift:114, ios/Sources/ProgramsView.swift:160
- Who and what happens: `withAnimation { proxy.scrollTo(...) }` animates the scroll whatever the Reduce Motion setting. Every other screen uses `UIAccessibility.isReduceMotionEnabled ? nil : .default`.
- WCAG / rule: 2.3.3 Animation from Interactions (AAA); Reduce Motion
- Fix: Use the same conditional animation as the other screens.
- Status: CONFIRMED

#### [Low] Small text links on schedule rows
- Where: ios/Sources/ScheduleView.swift:410 (Join online), :418 ("<location>, directions"), :425 (Open <client>'s record)
- Who and what happens: These are callout-size text with no minimum height, about 20 points tall. Every other control on the row is a large bordered button of 44 points or more. Likely still meets 2.5.8 through spacing, but they are hard to hit for someone with a tremor.
- WCAG / rule: 2.5.8 Target Size (Minimum), likely met through spacing; app standard 44 points
- Fix: `.frame(minHeight: 44, alignment: .leading).contentShape(Rectangle())`, as the "Service & details" link in EquipmentView does.
- Status: CONFIRMED (below the app's standard)

#### [Low] Alerts with no link are shown dimmed and read as "dimmed"
- Where: ios/Sources/NotificationsView.swift:122-151 (`.buttonStyle(.plain)` and `.disabled(target == nil && webURL == nil)`)
- Who and what happens: An alert that opens nothing is drawn as a disabled button. The plain style dims its text, including the white "New" capsule, below the app's 7:1 secondary-text colour, and VoiceOver adds "dimmed", as if the alert were unavailable. Whether "Mark as read" on the rotor still works on a disabled element is uncertain.
- WCAG / rule: 1.4.3 Contrast; 4.1.2 (the state is reported wrongly)
- Fix: Draw link-less alerts as a plain row (not a disabled Button) and keep "Mark as read" as a custom action.
- Status: PLAUSIBLE

#### [Low] Hours-entry descriptions are cut to two lines with no way to see the rest
- Where: ios/Sources/TrackDetailView.swift:667
- Who and what happens: VoiceOver reads the whole description, but on screen it is cut to two lines with "…", and nothing on the screen opens the full text (the row has no detail screen, and its actions are long-press only; see above). A magnifier user can't read a long entry.
- WCAG / rule: 1.4.4 / 1.4.10
- Fix: No line limit at accessibility sizes, plus a "Show full description" button, as notes and incidents have.
- Status: CONFIRMED

#### [Low] Icon-only buttons in the message thread and voice assistant have only long spoken names
- Where: ios/Sources/MessagesView.swift:324-331 (Send: "Send message to <client>"), :437-443 (Attach a file), :312-314; VoiceAssistant.swift:1196-1207 (microphone, whose name changes with the phase)
- Who and what happens: A Voice Control user must say the whole name, for example "Tap Send message to Jordan Sample", or use numbers. The microphone's name changes between "Start listening", "Stop listening and send" and others, so a remembered command stops working.
- WCAG / rule: 2.5.3 Label in Name (no visible label; Voice Control usability)
- Fix: `.accessibilityInputLabels(["Send", "Send message"])`, `["Attach", "Paperclip"]`, and `["Microphone", "Mic", <current name>]`.
- Status: PLAUSIBLE

#### [Low] Widget text can be clipped at large text sizes
- Where: ios/Widget/CaseManagementWidget.swift:99-123
- Who and what happens: In the small widget, up to four lines at large Dynamic Type (the "Today" label, the count in headline, the next time, "Checked in" / "Not checked in") have only `minimumScaleFactor(0.8)` on the count and no priority. The check-in line is the one dropped at the bottom with no sign it is missing. VoiceOver isn't affected, because it reads the full `spoken` value.
- WCAG / rule: 1.4.4 Resize Text
- Fix: `.minimumScaleFactor` on every line, a `.layoutPriority` that keeps the next time and check-in, or the count and check-in only in `.systemSmall` at accessibility sizes.
- Status: PLAUSIBLE

### What the existing UI tests already enforce (ios/UITests/AccessibilityAuditTests.swift)
`performAccessibilityAudit(for: .all)` at the default size and at AX XXXL, but only on these screens: client detail (documents to sign, releases, documents), Client edit (Office, More details), Request a release, the Documents to sign list and its three forms, in-person signing, a message thread and its attach menu, and the Equipment new-loan sheet. It runs locally only, with a test account. The Dashboard, Schedule, Referrals, Tasks, Alerts, Agreements, Training, Credentials, Competency, Invoices, Account and the widget are never audited, and most findings above are on those screens.

### Coverage
Read in full or close to it: CaseManagementApp (root, app lock and lock screen, reauth, tabs, sync banner, ErrorBanner, showFormError, OnBrandLabel), AccessibilityHelpers, LoginView, DashboardView (including Offline entries, Action center, briefing, in-app browser), Widget, AppIntents, WidgetShared, VoiceAssistant UI, MessagesView thread, SignatureView, DocumentsToSign in-person signing, AgreementsView, TrainingView (and Credentials), DocReviewView, KnowledgeView, AIToolsView (shared card, hub, Remediate), UtilizationView, ComplianceView, NotificationsView, SearchView, IncidentsView list, ScheduleView list, session row and Move session, LocalNotifications wording, and the AccessibilityAuditTests.

Covered by searching the whole app (every file) rather than reading line by line: accessibilityLabel / input labels for label-in-name, icon-only buttons, `.borderedProminent` without onBrandLabel, Reduce Motion, fixed sizes and fonts, line limits, labelsHidden, steppers, pickers outside ReadablePickers, swipe and context-menu-only actions, direct error assignment, announce-then-dismiss, custom colours, Smart Invert (no photos are shown), required-field marking.

Not finished, with only partial or search-based review: ClientsView (beyond the list, detail top and note rows), ClientEditSheet body, TrackDetailView (2,000 lines: goals and hours sections only), EquipmentView, ProcurementView, ContractsView, BookingsView, TasksView, WaitlistView, PortalRequestsView, ReferralDetailView, LogHoursView, AccountView (only the setup key, passkeys and calendar), ATServicesView, ProgramsView, AccommodationsView, CounselorsView, OfflineSigning, UploadDocumentSheet, and the SpeechController logic (how chatty the assistant's own spoken replies are). No screen was rendered or tested with VoiceOver, Voice Control or Switch Control on a device, because signing in was out of scope.

## Area: android

### Android app accessibility findings (4.29.0, 2026-10-20)

Scope: android/app/src/main (Compose BOM 2024.06.00, so Compose UI 1.6.8 and Material3 1.2.1). Read only. No device run.

Lint (`:app:lintDebug`): no Accessibility-category issues. The only issues are GradleDependency, IconLauncherShape, OldTargetApi and UseKtx. Lint does not check Compose semantics, so a clean lint result says little here.

#### [Medium] Opening a new screen is not announced, and TalkBack focus is not placed on it
- Where: RootScreen.kt:82-120 (Scaffold, TopAppBar title, NavHost). No `paneTitle`, no focus request and no announcement on route change anywhere in ui/ (grep finds none).
- Who and what happens: A TalkBack user double-taps a row, such as a client or a tab. The row they were on disappears, and nothing says the new screen has opened or what it is called. Focus is left wherever TalkBack puts it after losing its node, which is often nothing or the bottom bar. The user has to touch-explore or swipe from the top to find out where they are. The iPhone app posts a screen change for the same step.
- WCAG / rule: 2.4.3 Focus Order, 4.1.3 Status Messages, 3.2.x predictability.
- Fix: Put `Modifier.semantics { paneTitle = title }` on the NavHost content for each destination, or on the TopAppBar title wrapper. Or, on route change, request focus on the title heading (FocusRequester plus `focusable()`), or announce the title once.
- Status: PLAUSIBLE. The missing code is confirmed; the TalkBack behaviour is not checked on a device.

#### [Medium] Errors never take focus, and messages appear far from the control that caused them
- Where: Every form. ErrorText is a polite live region only (Common.kt:81-96). There is no FocusRequester anywhere except the login code field (LoginScreen.kt:55,122). Examples where the message is at the top of a long scrolling page but the action is at the bottom or in a dialog: SignaturesScreen.kt:88-89 (Sign here now / Send by email at :184-216), TrackScreen.kt:106-107 (delete entry or expense dialogs), TasksScreen.kt:58-59, 152-153, DocumentsScreen.kt:71-72, ScheduleScreen.kt:56-58, MoreTools.kt:133-134 and 60, TrainingScreens.kt:71 (the "wrong answers" notice sits above question 1 while Check my answers is at the end). In LogHoursScreen (ClientsScreen.kt:393), NewSessionScreen.kt:114, CredentialDialog (TrainingScreens.kt:157), CorrectLogDialog (TrackScreen.kt:268) and ClientEditScreen (:345), the summary names only the first problem.
- Who and what happens: Owner rule 3 asks for one summary that takes focus. Here focus stays on the Save button. A TalkBack user hears the polite announcement at most once, then has to hunt for the field. A ZoomText-style magnifier or low-vision user at 200% text does not see a message that appeared at the top of the page or below the fold. When several fields are wrong, the summary lists only one.
- WCAG / rule: 3.3.1 Error Identification, 3.3.3 Error Suggestion, owner rule 3.
- Fix: Give ErrorText a FocusRequester and move focus to it (or to the first invalid field) when a refusal is set. Keep `focusable()` on the summary so TalkBack lands on it. List every field error in the summary. Put notices and errors next to the control that triggered them, or scroll them into view with `bringIntoViewRequester`.
- Status: CONFIRMED (code).

#### [Medium] Submit buttons stay disabled with no reason, and most required fields are not marked
- Where: Common.kt:209 PrimaryButton `enabled`. 43 call sites use `enabled =`. Examples: AddNoteScreen "Save note" (ClientsScreen.kt:326-327, needs Subject plus Note, neither marked); LogHours "Log hours" (:383, needs Hours and "What was done", unmarked); ReleaseRequestScreen "Email the release to sign" (SignaturesScreen.kt:532, five unmarked conditions); ClientEditScreen "Save changes" (ClientEditScreen.kt:337, needs Full name, unmarked, while "State (required)" is marked); LoginScreen Continue (:137); AddTaskDialog Add (TasksScreen.kt:129); in-person Sign (SignaturesScreen.kt:468).
- Who and what happens: TalkBack says "Save note, button, disabled" and nothing more. A Switch Access or Voice Access user cannot reach or activate the button, and nothing says which field is missing. Marking is inconsistent: some screens mark "(required)" or "hint = required", most mark "optional" on the others, and some, such as Subject and Full name, mark neither.
- WCAG / rule: 3.3.2 Labels or Instructions, 3.3.1, owner rule 4.
- Fix: Keep buttons enabled and validate on press, using the per-field error() path that already exists. Or, at minimum, mark every required field "(required)" in its label. Compose 1.6 has no required semantics, so the label text is the programmatic signal.
- Status: CONFIRMED (code).

#### [Medium] Sign-in fields cannot be filled by password managers or the autofill service
- Where: LoginScreen.kt:132-135 (address, Email, Password), :157 (code); MoreScreen.kt:240-291 (current/new password); Field() in Common.kt:155-170 sets no autofill or content type.
- Who and what happens: Compose UI 1.6.8 needs an explicit autofill hookup, and none exists. So 1Password, Bitwarden, Google Password Manager and the keyboard's inline suggestions cannot fill email or password. Blind and low-vision staff who rely on a password manager have to copy and paste, or type a long password with TalkBack. Paste does work, so 3.3.8 is only just met.
- WCAG / rule: 1.3.5 Identify Input Purpose (the user's own email and password), 3.3.8 Accessible Authentication (supporting).
- Fix: Move to Compose 1.8 or later and set `semantics { contentType = ContentType.EmailAddress / Password / NewPassword / SmsOtpCode }`. Or wrap these fields with `Modifier.autofill(AutofillType.EmailAddress / Password)` on the current version.
- Status: CONFIRMED (code and library version).

#### [Medium] Translated AI text is read with the English voice
- Where: AiScreens.kt:85-87 and :125 (translate tool) → AiResult (:35-45) → MarkdownBlocks (MoreTools.kt:76-85), which uses plain `Text`. `inLanguage()` is never applied.
- Who and what happens: A staff member translates a letter into Spanish, Arabic, Vietnamese and so on to check it before sending. TalkBack reads the result with the English text-to-speech voice, which makes it unintelligible. The owner and blind staff cannot proofread the translation.
- WCAG / rule: 3.1.2 Language of Parts, owner rule 5.
- Fix: Pass the chosen `lang` into AiResult and MarkdownBlocks, and build each block with `inLanguage(text, lang)`, as the signing view already does.
- Status: CONFIRMED (code).

#### [Medium] Long pick lists (clients, staff, states) open as one unsearchable dialog that starts at the top
- Where: Choice in Common.kt:177-206. It is used for "Client" in NewSessionScreen.kt:86 (every open client), "Start a conversation with" (TasksScreen.kt:196), "Assigned instructor" and "State" (ClientEditScreen.kt:303-306, 56 entries), the equipment lend dialog, and others.
- Who and what happens: The dialog shows every option as a radio row. It has no filter field, it does not scroll to the current choice, and there is no type-ahead such as a web `<select>` gives. With TalkBack, picking a client near the end of a caseload of a few hundred means swiping through every name. Switch Access users have to scan the whole list.
- WCAG / rule: 2.1.1/2.4.x efficiency (no strict failure); owner usability priority for blind staff.
- Fix: Add a "Filter" text field at the top of Choice when there are more than about 15 options. It should filter quietly: announce no counts while typing (owner rule 2), and at most one debounced count. Start the list scrolled to the selected row and put initial focus on it.
- Status: CONFIRMED (code).

#### [Medium] At large font sizes, the app bar title, bottom tab labels and floated field labels are clipped or overlap
- Where: RootScreen.kt:84-89 (TopAppBar, fixed 64dp, title Text with no maxLines or overflow handling); RootScreen.kt:92-105 (NavigationBar, fixed 80dp, 4 or 5 items with labels such as "Schedule"); Field() label (Common.kt:162), which puts the hint inside the label, as owner rule 1 requires. That makes labels long, such as "Client's typed name (optional; counts as their acknowledgement)", "Your organisation's web address (for example cms.example.org)" and "Independence outcome (optional, for a goal without a job, ...)".
- Who and what happens: At Android's 200% font scale (or a large display size), long titles like "Worksite accommodations" or "Release of information" wrap inside a bar that cannot grow, and the second line is cut off. Tab labels wrap or clip. A multi-line OutlinedTextField label floats onto the top border and overlaps the typed text. Low-vision users lose the title or label text. TalkBack is not affected.
- WCAG / rule: 1.4.4 Resize Text, 1.4.10 Reflow.
- Fix: Use `maxLines = 2` with a taller title area, such as MediumTopAppBar or a heightIn(min) app bar, or keep the title single-line with ellipsis and repeat the full name as the screen's first heading. For tabs, set `alwaysShowLabel` and single-line labels with ellipsis (the label is still read in full). For fields, put a long hint in the `placeholder` or `supportingText` visually, while keeping it in the accessible label via `semantics { contentDescription }`, or keep the label short and the hint in supportingText merged into the field (still one stop).
- Status: PLAUSIBLE (layout reading of Material3 1.2.1; not rendered at 200%).

#### [Low] A field's error can be read twice
- Where: Common.kt:160-169. `supportingText` shows the error, and the field also gets `semantics { error(error) }`. With a label, Material3 merges the decoration box (label and supporting text) into the field node.
- Who and what happens: TalkBack reads, for example, "Hours, edit box, …, Error: Enter the hours as a number…, Enter the hours as a number…". This is the same duplication that Choice and Tick avoid with `clearAndSetSemantics {}` on their visible error lines (Common.kt:185-186, ClientEditScreen.kt:116-117).
- WCAG / rule: owner rule 3 (no double announcement), 4.1.2.
- Fix: Wrap the supportingText Text in `Modifier.clearAndSetSemantics {}`, keeping `error()` as the single spoken source, as Choice does.
- Status: PLAUSIBLE.

#### [Low] "Still saving" is announced twice
- Where: Saving.kt:126-131 (HoldBackWhileSaving). It calls `announceForAccessibility(...)` and also shows a Toast with the same text.
- Who and what happens: TalkBack reads the announcement and then reads the Toast, so the same sentence is spoken twice on Back.
- WCAG / rule: owner rule 3 / 4.1.3 (chatty).
- Fix: Keep one of them. The Toast alone is read by TalkBack and is also visible.
- Status: CONFIRMED (code).

#### [Low] AI requests that take up to a minute show only "Please wait…", and the wait is never announced
- Where: AiScreens.kt:99 and :175 pass `"Working… $AI_WAIT"` as the button text while `busy = true`. PrimaryButton (Common.kt:209-212) then shows `busyText` ("Please wait…"), so the "up to a minute" text is dead code. Nothing announces the start, only "Result ready".
- Who and what happens: A TalkBack user presses Ask the AI. The button turns disabled and nothing is said, so the user cannot tell whether the press worked or how long to wait, and may leave the screen.
- WCAG / rule: 4.1.3 Status Messages.
- Fix: Pass `busyText = "Working… This can take up to a minute."` and announce it once when busy starts.
- Status: CONFIRMED (code).

#### [Low] An instruction refers to a pull-to-refresh gesture that does not exist
- Where: TrackScreen.kt:132, "The notes could not be loaded. Pull Refresh to try again." The screen has no pull-to-refresh; it has a Refresh button at the bottom (:166).
- Who and what happens: A TalkBack or Switch Access user tries a gesture that does nothing.
- WCAG / rule: 3.3.2 / 2.5.1 (instructions rely on a gesture that does not exist).
- Fix: Change the text to "Choose Refresh at the end of the page to try again."
- Status: CONFIRMED (code).

#### [Low] Instrument option labels carry an item count
- Where: AssessmentScreens.kt:92, options read "Name, 12 items".
- Who and what happens: Each option is read with a count, which goes against the plain option-name rule.
- WCAG / rule: owner rule 2.
- Fix: Use the plain name as the option label, and show the item count after the choice is made.
- Status: CONFIRMED (code).

#### [Low] The same heading is read twice on some screens
- Where: The app bar title is a heading (RootScreen.kt:85), and several screens repeat it as a large Heading. For example, NewSessionScreen.kt:85 "Schedule a session" repeats the title "Schedule a session" exactly. Near-duplicates: ReleaseRequestScreen (SignaturesScreen.kt:515) and OfflineScreen (MoreScreen.kt:133).
- Who and what happens: A heading-navigation user hears the same heading twice in a row.
- WCAG / rule: 2.4.6 (polish).
- Fix: Drop the exact duplicate on NewSessionScreen. Keep the others only where they add information.
- Status: CONFIRMED (code).

#### [Low] Bright white launch window in dark theme
- Where: res/values/themes.xml sets parent `android:Theme.Material.Light.NoActionBar` and `windowBackground` @android:color/white. Compose then draws the dark scheme (Theme.kt:35).
- Who and what happens: With dark theme on, each cold start, and any frame before Compose draws, flashes a full white screen. This is painful for light-sensitive low-vision users.
- WCAG / rule: 1.4.3/1.4.11 context, user preference (no strict SC).
- Fix: Use a DayNight parent (for example `Theme.Material3.DayNight.NoActionBar` or `android:Theme.DeviceDefault.DayNight`) and a `values-night` windowBackground matching #111318.
- Status: CONFIRMED (code).

### Checked and found sound
- Contrast: every Theme.kt text pair is above 4.5:1 in light and dark. Outline is #6B7280 on white (about 4.8:1) and #9CA3AF on #111318. The error tint backgrounds keep the error text above 4.5:1. The signature pad is black on white in both themes.
- Touch targets: ListRow, Tick and the Choice options use heightIn(min = 48dp). Buttons use the Material minimum interactive size. The only icon is Back, which is labelled. The tab icons are null-described with text labels.
- Rows are one stop (mergeDescendants plus contentDescription), with onClickLabel actions and expanded/collapsed stateDescription on notes. Tick is a single toggleable with role Checkbox. Training answers use selectableGroup with radio roles.
- Signature pad (2.5.7): drawing is optional everywhere and a typed name is the alternative. The pad is described, tells TalkBack users to pause TalkBack, and carries the document's language. The Spanish signing view marks its own words with lang, including the error prefix.
- No hard-coded sp/dp text sizes. Animations are the Compose and Material defaults, which follow the system animator scale.
- The TOTP secret is spelled out for TalkBack. Choice options are plain names, apart from the item-count case above. Choice opens a list without announcing on each move.
- Not examined in depth: Compose 1.6 does not apply Android's "high contrast text" setting to Compose Text, but the theme already meets 4.5:1.

### Coverage
Read in full: Common, Theme, Loader, Saving, RootScreen, LoginScreen, MainActivity, manifest/themes, DashboardScreen, ClientsScreen, SignatureAndUpload, SignaturesScreen (including in-person signing), VoiceScreen, AiScreens, MoreTools (knowledge base, counselors), MoreScreen, TrainingScreens, AgreementsScreen, ScheduleScreen, NewSessionScreen, TasksScreen (alerts, referrals, messages, thread), ClientEditScreen, TrackScreen (main part and dialogs to :307).
Skimmed by pattern (Choice/Field/Tick/ListRow/AlertDialog/enabled usage and option labels): EquipmentScreen, DocumentsScreen, ReferralScreen, ReportsScreens, ProgramsScreens, ProcurementScreens, AtServicesScreen, AssessmentScreens, ActionCenter, the rest of TrackScreen. They use the same shared components, so the cross-cutting findings above apply to them.
Not done: nothing was rendered on a device or emulator (by instruction), so the TalkBack focus behaviour after navigation and dialogs, and the 200% layout, are inferred from code and library behaviour.
