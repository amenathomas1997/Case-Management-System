# Accessibility Conformance Report

**Based on:** VPAT® 2.5 format, WCAG 2.2 edition

| | |
|---|---|
| **Product** | Case Management System (web application), version 4.30.0 |
| **Report date** | October 20, 2026 |
| **Contact** | The organisation running your installation (its support email is in every page footer). For the software itself: https://github.com/amenathomas1997/Case-Management-System/issues |
| **Standard** | WCAG 2.2, Levels A and AA. Text contrast is held to the AAA level (7:1). |

## Scope

Covered: the web application's staff pages and its public pages for clients, guardians, VR counselors and referrers. The public pages are the portal, referral forms, consent and release forms, the signing page, surveys and the counselor portal.

Not covered:
- the iPhone and Android apps (reviewed separately, below)
- documents the system generates as PDF (invoices, funder and outcome reports). They are not tagged. Since 4.30.0 they read in a sensible order, carry a title and language, and print a link to an accessible HTML copy that the receiving agency can open without signing in, for 90 days. Downloaded letters are HTML marked with the letter's language.
- third-party pages it links to (Microsoft sign-in and Teams, QuickBooks, agency portals)

## How it was evaluated

**This is a self-evaluation by the developers, not a third-party audit.** The evidence:

**Daily use.** The system's owner is blind and uses it every day with JAWS on Windows and VoiceOver on iPhone. Many design rules come from that use: no extra tab stops for hints, quiet combo boxes, and errors tied to their fields.

**A full accessibility audit on October 20, 2026** (ACCESSIBILITY-AUDIT-2026-10-20.md): eight reviewers covered the shared page parts, the public and client pages in English and Spanish, the staff pages, billing, reports and admin, keyboard-only task walkthroughs in a real browser, an axe-core scan of every rendered page in every colour palette, and both phone apps. Every finding was fixed in 4.30.0.

**Automated checks on every release** (tests/run_all.sh and CI):
- Text contrast in the light and dark themes is measured against the shipped stylesheet, held to 7:1, and the dark theme's focus ring to 3:1 (tests/accessibility.php).
- The integration tests render over 100 pages with synthetic records, and check that each opens without errors and shows its expected heading.
- The keyboard and focus behaviour of the shared parts is checked: the timeout dialog, the voice assistant, form recovery and the AI draft controls (tests/*.test.cjs).

**Browser checks on every change, in CI** (tests/accessibility_browser.js, with Playwright and Chrome, since 4.25.0):
- about 240 rendered pages and states are checked for control names, unique ids and valid ARIA references
- reflow is checked at 320 and 1280 CSS pixels, at 100% and 200% text size
- the skip link, and keyboard scrolling of wide tables, are checked in the browser

**Manual audit.** An engineering accessibility audit against WCAG 2.2 was carried out on version 3.0.13 (ACCESSIBILITY-AUDIT-2026-10-10.md). Its findings, on reflow and labels, were fixed. Later versions have had code review for accessibility, but no repeat of that audit.

**Known gaps in the evaluation:**
- No independent auditor has tested it.
- The browser checks cover the pages and states the integration tests render, not every combination of records and permissions.
- The extra colour palettes (low-vision settings) are not measured for every colour pair. The light theme's focus ring and control borders have no automated 3:1 check.
- Native browser zoom, braille displays, Dragon and switch access have not been tested systematically.
- The synthetic test pages do not cover every combination of records and permissions.

## Conformance levels used

- **Supports:** meets the criterion without known defects.
- **Partially Supports:** some functionality does not meet it, or it has not been verified everywhere.
- **Does Not Support:** most functionality does not meet it.
- **Not Applicable:** the criterion is not relevant to this product.

## WCAG 2.2 Level A

| Criterion | Level | Remarks |
|---|---|---|
| 1.1.1 Non-text Content | Supports | Images and icons have text alternatives or are marked decorative. QR codes on equipment labels have a linked text equivalent. The signature pad has a typed-name alternative. |
| 1.2.1 Audio-only and Video-only (Prerecorded) | Not Applicable | The product contains no prerecorded audio or video. Files staff upload are their own content. |
| 1.2.2 Captions (Prerecorded) | Not Applicable | As above. |
| 1.2.3 Audio Description or Media Alternative | Not Applicable | As above. |
| 1.3.1 Info and Relationships | Supports | Real headings, lists, tables (with captions and header scopes), fieldsets and labels. Automated checks confirm each control has a name. |
| 1.3.2 Meaningful Sequence | Supports | Reading order follows the source order, with no CSS reordering of content. |
| 1.3.3 Sensory Characteristics | Supports | Instructions never rely on shape, position or colour alone. |
| 1.4.1 Use of Color | Supports | Status is shown in words ("Overdue", "Paid"), not by colour alone. |
| 1.4.2 Audio Control | Not Applicable | Nothing plays sound on its own. Spoken AI answers play only when asked for. |
| 2.1.1 Keyboard | Supports | Everything works from the keyboard. This is checked in daily JAWS use and by the browser tests. |
| 2.1.2 No Keyboard Trap | Supports | Dialogs return focus when closed. Scrolling tables can be left with Tab. |
| 2.1.4 Character Key Shortcuts | Supports | There are no single-character shortcuts. |
| 2.2.1 Timing Adjustable | Supports | The session-timeout warning lets users extend the session. A form left open too long keeps what was typed, including on public signing pages. |
| 2.2.2 Pause, Stop, Hide | Supports | Nothing moves, blinks or updates on its own in a way that needs pausing. |
| 2.3.1 Three Flashes or Below Threshold | Supports | Nothing flashes. |
| 2.4.1 Bypass Blocks | Supports | A skip link goes to the main content, and pages use landmarks. |
| 2.4.2 Page Titled | Supports | Every page is given a title naming the page (and the record, where there is one). A page showing a form error starts its title with "Error". |
| 2.4.3 Focus Order | Supports | Focus follows reading order. After a save, focus goes to the message or the section saved. |
| 2.4.4 Link Purpose (In Context) | Supports | Repeated links ("View", "Edit") carry visually hidden text naming their record. |
| 2.5.1 Pointer Gestures | Supports | No multipoint or path gestures are needed. Signing can be done by typing. |
| 2.5.2 Pointer Cancellation | Supports | Native buttons and links only. |
| 2.5.3 Label in Name | Supports | Visible labels start the accessible names. |
| 2.5.4 Motion Actuation | Not Applicable | Nothing responds to device motion. |
| 3.1.1 Language of Page | Supports | Every page declares its language, and public pages offer Spanish. |
| 3.2.1 On Focus | Supports | Focus never submits a form or changes context. |
| 3.2.2 On Input | Supports | Choosing in a select never submits or navigates. Filters apply only from their button. |
| 3.2.6 Consistent Help | Supports | Help and the support contact are in the same place on every page. |
| 3.3.1 Error Identification | Supports | Errors name the field and the problem in text, and are tied to the field (aria-invalid and aria-describedby). Most forms also list them in a summary that takes focus. Forms repeated in a list (one per row) say the error in a message at the top of the page instead. |
| 3.3.2 Labels or Instructions | Supports | Every field has a label. Required fields are marked in text for screen readers. |
| 3.3.7 Redundant Entry | Supports | Forms refill what was typed after an error. Known details are filled in, such as a referral's details when it becomes a client. |
| 4.1.2 Name, Role, Value | Supports | Native HTML controls. Custom widgets (disclosure menus, the voice assistant, dialogs) expose their state, and their behaviour is tested on every release. |

## WCAG 2.2 Level AA

| Criterion | Level | Remarks |
|---|---|---|
| 1.2.4 Captions (Live) | Not Applicable | No live media. Teams meetings are Microsoft's own pages. |
| 1.2.5 Audio Description (Prerecorded) | Not Applicable | No prerecorded video. |
| 1.3.4 Orientation | Supports | Works in either orientation. |
| 1.3.5 Identify Input Purpose | Supports | Personal fields use autocomplete tokens (name, email, tel, address). |
| 1.4.3 Contrast (Minimum) | Supports | Text is held to 7:1 in the light and dark themes, measured on every release. The extra low-vision palettes are designed for high contrast but not measured pair by pair. |
| 1.4.4 Resize Text | Supports | It works at 200% text size, checked by the browser tests. There is also an in-app low-vision text size setting. |
| 1.4.5 Images of Text | Supports | No images of text apart from the organisation's own logo. |
| 1.4.10 Reflow | Supports | No horizontal page scrolling at 320 CSS pixels, with 100% and 200% text, on every page the tests render, checked on every change. Wide data tables scroll within their own labelled, keyboard-reachable region, as the criterion allows for tables. |
| 1.4.11 Non-text Contrast | Partially Supports | The dark theme's focus ring is measured at 3:1 or more. The light theme's focus ring and control borders are designed for it but have no automated check. |
| 1.4.12 Text Spacing | Partially Supports | The layout uses relative sizes and no fixed-height text boxes. User text-spacing overrides have not been tested on every page. |
| 1.4.13 Content on Hover or Focus | Supports | No hover-only content. Hints are part of the label. |
| 2.4.5 Multiple Ways | Supports | Navigation menu, search, the Administration index and the Reports index. |
| 2.4.6 Headings and Labels | Supports | Headings name their sections, and labels name their fields. |
| 2.4.7 Focus Visible | Supports | A high-contrast focus ring in every theme. |
| 2.4.11 Focus Not Obscured (Minimum) | Supports | No sticky headers or footers cover focused items. |
| 2.5.7 Dragging Movements | Supports | Nothing needs dragging. |
| 2.5.8 Target Size (Minimum) | Supports | Buttons and links meet 24 by 24 CSS pixels, or have that spacing. |
| 3.1.2 Language of Parts | Partially Supports | The organisation's own English wording on Spanish pages, and documents sent to sign, are marked with their language. A document staff write themselves is shown as written, its language unmarked. |
| 3.2.3 Consistent Navigation | Supports | The same navigation on every staff page. |
| 3.2.4 Consistent Identification | Supports | The same functions have the same names throughout. |
| 3.3.3 Error Suggestion | Supports | Errors say what to enter, such as "Enter the date as a full date" or "Choose one from the list". |
| 3.3.4 Error Prevention (Legal, Financial, Data) | Supports | Signing needs the signer's typed name, and the signed words are kept and shown afterwards. Invoices are reviewed before they are submitted. Deleted records go to a trash and can be restored. |
| 3.3.8 Accessible Authentication (Minimum) | Supports | Passwords can be pasted and filled by password managers. Sign-in codes can be pasted. Passkeys and Microsoft single sign-on are offered. No cognitive tests. |
| 4.1.3 Status Messages | Supports | Saved and error messages use status or alert roles, or take focus. |

## Phone apps (summary, not evaluated against this table)

The iPhone and Android apps use native controls with VoiceOver and TalkBack labels, Dynamic Type and large text, and a typed alternative to drawn signatures. The owner uses the iPhone app with VoiceOver. They have not been evaluated item by item against WCAG or EN 301 549.

## Legal disclaimer

This report describes the product as evaluated on the date above. Each installation's own wording, uploaded documents and settings are its organisation's responsibility. Report accessibility problems to the contact above; they are treated as defects.
