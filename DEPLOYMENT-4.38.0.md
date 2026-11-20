# Release 4.38.0 — November 20, 2026

Web 4.38.0, iPhone app 4.38.0 (build 69), Android app 4.38.0 (version code 22). No database change; the database stays at 4.33.0.

**Not deployed with deploy.sh.** It is published as the GitHub release v4.38.0, and an administrator installs it with Administration, Software update. The phone apps are built from this tag.

**Why.** This is the fifth and last release of the app parity work in PARITY-AUDIT-2026-11-19.md: billing and administration, plus every remaining finding in the audit that wasn't done earlier. The plan is in that document under Progress, 4.38.0.

**Maintainer decisions taken with defaults (2026-11-19)**
- **Contracts:** a Notes field, up to 2,000 characters, on the website's add-contract form and in both apps.
- **Several invoices:** they can be marked submitted from the phone, with the website's rules, "Choose all shown drafts" and a confirmation that names the count.
- **Invoices on the phone:** each opens as an accessible page; the PDF stays on the website.
- **Service profitability** is on Android now.
- **Documentation audit:** its all-clear reads "No documentation gaps found." with no emoji, on the website and in both apps. Screen readers read the emoji as "thumbs up sign".
- **Utilization:** the period is remembered in the same setting the website uses.
- **Booking costs:** "$" and commas are accepted, with the website's 8-digit limit.
- **Money:** every amount, rounding and total comes from the server and equals the website's for the same data. The apps only format it.

**What changed, in both apps unless marked**
- **Invoices:**
  - paging, unbilled work, and the track and authorization on each invoice
  - what is owed and past due, in the website's words
  - filters by agency, month, number or client, and opening one invoice from search
  - the outstanding summary by agency, with past due and part-paid
  - marking several drafts submitted at once
  - each invoice opened as an accessible page
- **Incoming authorization emails:** the list; paste one; review it and start the track with its details filled in; or dismiss it. Alerts open them.
- **Booking:**
  - several sessions at once (several days a week, a list of dates, or spread over an authorization), with the website's preview and approval
  - making the Zoom or Teams link while booking. The client's invitation now carries the link.
  - On the iPhone, approving a "Check before booking" warning now works, and a remote session keeps its location.
- **Counselor portal:** each counselor's link status, and sending or turning off the link, with the website's wording. The link itself never reaches the phone.
- **Contracts:**
  - percent spent, remaining, and the days to the end, from the server
  - change the budget, close or reopen, notes, and removing deliverables and spend entries
- **Access bookings:** upcoming, including past, and cancelled; restoring a cancelled booking; the website's service labels.
- **Reports:**
  - Utilization with the website's periods, notes and footer.
  - Service profitability grouped as on the website.
  - Data quality and the documentation audit.
- **Account:** the organisation name.
- **Android:** the website's note-type list, including Phone Call.

**Website changes**
- New contracts can have notes.
- Repeat weekly allows up to 52 weeks for "several days each week" (still 26 for weekly).
- The documentation audit's all-clear has no emoji.
- Marking several invoices submitted now names any that were skipped because they were no longer drafts.

**Safety for retried requests (server).** A request sent again with the same retry key is answered as before. A key reused with a different request is now refused:
- The message reads: "This was already sent once with different details, and that earlier send may have been saved. Reload to check what was saved, then make your change again."
- Both apps show it, never treat it as a success, and make a new key for the next send.
- Offline entries that get this answer are kept, with the reason.

Repeated replies are rebuilt only from the first request's own records, checked again for the caller's caseload. Contract and booking changes now need a POST.

**Shared code.** The website pages and the API call the same helpers:
- new files: inc/invoice_document.php, counselor_portal.php, contracts.php, bookings.php, utilization.php
- additions to billing.php, authorization_email.php, session_plan.php, meetings.php and others

The new API actions are in inc/api_438.php.

**Older apps.** 4.37.0 apps keep working.
- Bookings asked for with `past=1` still get the history alone.
- Older invoice replies keep their keys.
- A booking cost over 8 digits is now refused, with the website's sentence.

**Tests**
- **New:**
  - tests/v438_cases.php. It compares the API's money figures with the website pages' own rendered rows and the utilization CSV.
  - iPhone checks, 302 in all. They compile and run the app's decoding against the server's reply shapes.
  - android V438Test.kt.
- **A test fixed:** a 4.37.0 check of the evaluation report's "emailed but not logged" warning read a stale session left by the previous send. When the two sessions were written in the same second it picked either one, so it passed or failed by chance. It now clears old sessions first, and also checks the failure is logged for the administrator. The website's behaviour was right all along.
- **Checked before commit:**
  - full_schema (3484, run three times), audit_regression, security, staging_scrub and run_all pass
  - the browser accessibility check passes on 400 pages
  - iPhone: builds with no warnings
  - Android: build, 273 unit tests and lint (0 errors) pass

**Review.** Three independent reviews (server, iPhone, Android) checked the money figures and everything else. Everything they found was fixed before commit:
- **Money:** the shared helpers are the website's own code moved over line for line, and the figures are unchanged.
- **iPhone, High:** "Including past" listed every upcoming booking twice. Both apps now ask for the history on its own.
- **Server, Medium:** a remote session booked from an app emailed the client before the meeting link existed.
- **Server, Low:**
  - retries reusing a key with another request (now refused everywhere)
  - the weekly repeat limit in the browser
  - contract and booking changes now need a POST
  - the audit wording
  - the counselor-portal repeat message
- **iPhone, Medium:**
  - the counselor notices after starting an authorization's track were lost
  - the several-invoices result was only spoken
  - a training-track error wasn't tied to its field
- **Android, Medium:**
  - a hidden typed meeting link blocked booking
  - errors weren't tied to the training track, pattern or approval
  - the new invoice features showed against an older server
- **Both apps:** the wording, order and confirmations were made the same as each other and as the website.

**The parity work is complete.**
- **What 4.34.0 to 4.38.0 covered:** every High and Medium finding of the audit, and every Low one except those marked website-only (each with its reason in the audit and the plans).
- **What stays website-only:** administration settings, payments and write-offs, invoice PDFs and batch billing, imports, and the large period reports.
- **Still deferred:** signing in with Microsoft inside the apps. It needs its own release and security review.
