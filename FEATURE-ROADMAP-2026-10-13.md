# Feature roadmap — what is still missing (October 13, 2026)

Baseline: release 3.0.20, the version running on the live site. This list came from nine independent reviews of the code, each looking from a different angle: VR case practice, billing and funding, privacy and records, a blind owner-operator, field work on the iPhone, reporting, operations, the client and counselor experience, and unfinished work in the code. They proposed 128 gaps, which merged into 92 distinct ones. A separate reviewer then tried to prove each gap already existed. None of the 92 did: 69 are partly built and 23 are missing entirely.

Bugs are not in this document. The code audit that ran alongside it confirmed 413 defects (none critical or high), and those are being fixed for release 3.0.21.

## Summary

The system already covers the whole working day of an assistive-technology training provider: referrals arrive through an accessible public form, clients and their VR authorizations are tracked, sessions are booked and logged on the website, the iPhone app and by voice, signed progress reports go to counselors, invoices are produced, and clients and counselors each have their own portal. For day-to-day case work it is close to complete, and the separate code audit found 413 smaller defects (handled separately) but nothing critical or high. What is missing sits in a few places. The most important is money after an invoice goes out. A short-paid invoice can never take its second payment, a denied invoice cannot be voided and rebilled, and nothing stops hours or invoices that fall outside an authorization's dates, hours or dollars. Invoices also lack your vendor number and remit-to address, and free or self-pay training cannot be recorded even though the referral form offers it. Next is the referral queue: a referral can only end by becoming a brand-new client, so declined and repeat referrals stay open forever and the referring counselor never hears the outcome. Then two protections are missing. Adult clients have no consent or release on file before their reports go to counselors and employers, and off-site backups happen only once a week, with a restore that has never been written down or tested. After those essentials come a proper case closure (reason, outcome, final report and follow-up), fuller progress reports, notices to clients and counselors when sessions change, and making voice and the iPhone app cover more of your day. That includes shipping the app update that has been waiting since version 3.0.13. The remaining items are reports, data checks and administration screens. They save time, but the system does not need them to count as complete.

Totals: 10 needed to call the system complete, 73 with clear value, 9 nice to have.

## Suggested order for the first ten

1. Put your organisation's billing details on every invoice: a small job, and VR payment systems match payments on vendor number and remit-to address, so this helps get invoices paid
2. Daily off-site backups and a tested restore: today losing the server could lose up to a week of records and signed documents, and the restore has never been tried
3. Check every hour and invoice against the authorization: stops work VR will not pay for before it is invoiced and denied
4. Record payments properly: partial payments, write-offs and real dates: fixes the receivables figures and is the base for aging, iPhone invoices and voice billing answers
5. Handle denied or returned invoices: void, release and rebill: builds on the payments table; today the only fix is editing the database
6. Close a referral with a reason and tell the referring counselor: clears the open queue and keeps your main funders informed
7. Attach a referral to an existing client: uses the same referral status change, and stops repeat referrals creating duplicate client records
8. Consent and release forms for adult clients: since intake became AT-only nearly every new client is an adult with no release on file before reports go out
9. Ship the iPhone app update: a small job that puts features built since 3.0.13 on staff phones; it includes publishing the short privacy notice page the App Store requires
10. Free and private-pay training: needed if you serve free or self-pay clients as the referral form promises; build it after the payments item because private-pay invoices bill the client

## Questions only you can answer

Several items cannot be built until you answer the question below them. The answers decide how the feature works.

1. **Close a referral with a reason and tell the referring counselor:** Which decline reasons do you want on the list, and should the outcome email to the counselor go automatically or only when staff choose to send it?
2. **Attach a referral to an existing client:** When a former client is referred again, should their old record be reopened (recommended, because it keeps their history together) or should a separate new case be started?
3. **Referral receipt number, contact log and timeliness report:** How many months should a referral that never became a client be kept before it is deleted?
4. **Ask contact preference, language, area and authorization details on the referral form:** Do you want a public phone number shown on the referral form and site footer, or email only?
5. **Check every hour and invoice against the authorization:** When work falls outside an authorization, should staff be able to continue after giving a written reason, or should it be blocked until an amendment is recorded? (Release 3.0.21 already makes staff confirm before logging hours dated outside the authorization's start and end dates. It does not yet check the remaining hours or dollars. Say so if you would rather block it entirely.)
6. **Record payments properly: partial payments, write-offs and real dates:** Who may write off an unpaid balance: only you as administrator, or anyone with billing permission?
7. **Put your organisation's billing details on every invoice:** Please confirm the vendor number, remit-to address and payment terms your VR agency expects to see on an invoice.
8. **Free and private-pay training:** Do you currently train free or self-paying clients? If so, should private-pay clients be invoiced from this system, or should it only record their hours?
9. **Funder list, fee schedules and dated rate changes:** How many funding agencies do you bill, and does each one have its own fee schedule?
10. **Receivables aging, overdue alerts and reconciliation:** After how many days should an unpaid invoice, or work not yet billed, raise an alert?
11. **Cleaner accounting export and a month-end close:** Which accounting package do you use (QuickBooks Online or Desktop), and when do you close each month?
12. **Grants and contracts tied to real services and costs:** Which grants or contracts do you hold besides VR authorizations, and what numbers do they ask you to report?
13. **Keep financial records when a client record is purged:** How many years must financial records be kept under your VR contract and your grants?
14. **Bill Pre-ETS group sessions from cohort attendance:** Since the October 12 change made new referrals AT-only, is Pre-ETS group work still part of your business? If not, skip this item.
15. **Non-billable staff time and timesheets:** Do you need staff hours for payroll from this system, or is payroll already handled somewhere else?
16. **File emailed authorizations into the client record:** How do your counselors usually send authorizations: as email attachments, by fax-to-email, or through their agency's own portal?
17. **Client sign-off for sessions on the web, by voice and remotely:** Does your VR agency require a client signature or confirmation for each session before it pays?
18. **A 'waiting for authorization' stage and On Hold follow-up:** Do you want a separate 'Awaiting authorization' status, or is On Hold with a reason enough for how you work?
19. **Structured case closure: reason, outcome and checklist:** Which closure reasons and outcome details do your VR agency and funders ask you for?
20. **Final report to the VR counselor at closure:** Does your VR agency have its own required closure or final report format to match?
21. **Better progress reports: reporting period, attendance and goal progress:** Should automatic monthly reports go out unreviewed, or wait for staff to add a short narrative first?
22. **Vision status and demographics for blind-services grants:** Do you hold, or plan to apply for, an Older Individuals who are Blind or state blind-services grant that asks for these counts?
23. **Consent and release forms for adult clients:** What release wording does your VR agency require, and how long should a signed release stay valid?
24. **Privacy notice page and a record of who received it:** Do you have approved privacy notice wording to use, or should a draft be prepared for you to review?
25. **Guardian details on the client record and a check for minors without consent:** How many of your current clients are minors or have a legal guardian?
26. **Keep earlier versions when case notes are edited:** Should notes lock for editing after a set number of days, and if so, how many?
27. **AI and email vendor safeguards:** Have business associate agreements been signed with Microsoft, your web host and your AI provider?
28. **Less client detail in emails, and reports by secure link:** Do your VR counselors accept progress reports as plain email, or do they require encrypted email or a portal?
29. **Notify the counselor about no-shows, holds, closures and expiring authorizations:** Which of these events would your counselors want to be told about by email?
30. **Preferred language for each client and guardian:** How many of your clients or guardians prefer Spanish, and do you need any other languages?
31. **Letter templates the organisation can edit:** Which letters do staff write most often today?
32. **Ship the iPhone app update:** Will the app go through the public App Store, a private custom app through Apple Business Manager, or TestFlight only?
33. **Documents in each client's preferred format:** Should the system make braille-ready and audio files itself, or should staff keep making them with their own tools?
34. **Equipment loan hand-off record:** Do you want clients to sign, or type their name, to accept a loan agreement, and do you have agreement wording?
35. **Easier iPhone sign-in and hands-free shortcuts:** Do you want staff to sign in to the app with Microsoft, or is Face ID plus a monthly emailed code acceptable?
36. **One Reports page and period-based business summary:** When does your fiscal year start?
37. **Monthly summary emailed to you:** Should it be monthly, quarterly, or both, and who besides you should receive it?
38. **Daily off-site backups and a tested restore:** Does your web host already keep daily off-site backups, and where should the daily copy go: the backup mailbox, or a OneDrive or SharePoint folder?
39. **Staff accounts: invitations, least access, training records and departures:** Should staff who leave be switched off automatically when their Microsoft 365 account is disabled?
40. **Built-in how-to guides for staff and administrators:** Would you like to review draft procedures written from how the system works, and add your own office practices?
41. **Import clients and open authorizations from a spreadsheet:** Do you plan to offer this system to other organisations? If so, this becomes a should; for your own caseload, which is already entered, it can wait.

## All roadmap items by theme

Each item lists its priority and a rough size: S is under a day, M a few days, L one to two weeks, XL more than two weeks.

### Referrals and repeat referrals

Referrals are how clients come in, and VR counselors are your main funders. Right now a referral can only end by becoming a brand-new client. Declined, duplicate and repeat referrals stay in the open queue forever, and the referring counselor never hears what happened.

#### Close a referral with a reason and tell the referring counselor

Priority: Needed to call the system complete. Size: a few days.

Add closed outcomes (Declined, Not eligible, Withdrawn, Unable to reach, Duplicate) with a required reason and closed date to the referrals table through a new migration, and offer them on referrals.php, api.php and iOS ReferralsView.swift. Closed referrals must drop out of every open list (referrals.php, pipeline.php, index.php, the API open filter), the waitlist 'Closed' status in inc/workflows.php should use the same outcome, and closing should complete the 3-day ref-contact task. Send the confirmed referring VR counselor a short outcome email (reference number, accepted or not accepted, general reason, no client details) from a new editable template in EMAIL_TEMPLATES (inc/core.php).

Builds on: referrals table, referral_waitlist, the ref-contact task, email_template() and email_templates.php. Gaps: G6, G103.

**Decision needed:** Which decline reasons do you want on the list, and should the outcome email to the counselor go automatically or only when staff choose to send it?

#### Attach a referral to an existing client

Priority: Needed to call the system complete. Size: a few days.

Add an 'Attach to existing client' action next to the possible-duplicate list on referrals.php and in api.php and iOS. It sets referrals.client_id to the chosen client, marks the referral Linked, copies the intake note to that client, closes the contact task and opens a follow-up task. If the client is closed or archived, it offers to reopen them to Pending Assessment with a status-history note that cites the referral number. For a 'Plan amendment' referral, the follow-up task points to the active track's amendment_request.php and track_edit.php.

Builds on: referrals.php duplicate check, client_status_history and record_status_change(), auth_amendments, add_task(). Gap: G3.

**Decision needed:** When a former client is referred again, should their old record be reopened (recommended, because it keeps their history together) or should a separate new case be started?

#### Referral receipt number, contact log and timeliness report

Priority: Clear value. Size: a few days.

Show a reference number on the community_referral.php confirmation page and email an acknowledgement with no health details to the referrer's address. Add a contact-attempt log and a first-contact date to each referral, plus a small report of days from referral to first contact, assessment and first session, to check the 3-business-day promise made in help.php. Add a setting and a cron/daily_alerts.php job that deletes referrals that were closed or never converted after a set number of months, together with their tasks and notifications, and writes an audit entry.

Builds on: community_referral.php, ref-contact task done_at, pipeline.php days-waiting, cron/daily_alerts.php. Gaps: G6, G103, G35. Build after the referral outcome item.

**Decision needed:** How many months should a referral that never became a client be kept before it is deleted?

#### Ask contact preference, language, area and authorization details on the referral form

Priority: Clear value. Size: a few days.

Add questions to community_referral.php for:
- preferred contact method and document format, using the A11Y_CONTACTS and A11Y_FORMATS lists in inc/core.php
- preferred language and best time to call
- county or area
- in-person or remote, and availability
Store which language the form was filled in. For VR counselors, add optional fields for VR case ID, authorization number, hours and dates. Staff see these to check them; they never become a billable track automatically. On conversion (referrals.php and api.php convert_referral), copy these answers into the client's access fields and the referral_waitlist row, along with referral source, income bracket and quoted fee.

Builds on: clients.a11y_contact and a11y_format, referral_waitlist languages/areas/modality, inc/referral_request.php, lang_es.php. Gaps: G115, G8 (referral source).

**Decision needed:** Do you want a public phone number shown on the referral form and site footer, or email only?

### Billing and getting paid

Making and sending invoices works well. The gaps are just before billing (checking work against the authorization) and just after it (short payments, denials, rebills, aging). That is where a VR-funded provider loses money, and today several of these problems can only be fixed by editing the database directly.

#### Check every hour and invoice against the authorization

Priority: Needed to call the system complete. Size: a few days.

Write one shared check in inc/core.php, next to track_rollup(), that tests a work date against the track's start and expiry dates, its status, and its authorized hours and dollars. Call it from:
- hour logging in track.php, api.php log_hours, auto_log_session and voice.php
- generate_invoice
- the invoices.php batch and Submit step
Require a confirm-with-reason override that is recorded in the audit log, reusing the booking override in schedule.php and workflow_authorization() in inc/workflows.php. Leave Cancelled, Completed and expired tracks out of the invoice batch unless someone confirms them. Show overruns as signed figures ('12 hours over') instead of '0 remaining / 100%' on track.php, on invoices, on compliance.php and in the iOS warning.

Builds on: track_rollup(), workflow_authorization(), the schedule.php override and audit entry. Gap: G17.

**Decision needed:** When work falls outside an authorization, should staff be able to continue after giving a written reason, or should it be blocked until an amendment is recorded?

#### Record payments properly: partial payments, write-offs and real dates

Priority: Needed to call the system complete. Size: a few days.

Add an invoice_payments table so an invoice can take more than one payment, and add a write-off or adjustment with a reason to clear a short-paid balance. Let staff enter the actual submission and deposit dates instead of today's date (invoices.php lines 104 and 142). Add one remittance form that applies a single VR deposit across several invoices and checks that the parts add up to the deposit. Then update everything that reads paid_amount: the Outstanding summary, the QuickBooks CSV, analytics.php and inc/at_services.php.

Builds on: invoices.paid_amount, payment_ref and paid_at; the mark-paid form in invoices.php; search by payment reference. Gaps: G15, G51.

**Decision needed:** Who may write off an unpaid balance: only you as administrator, or anyone with billing permission?

#### Handle denied or returned invoices: void, release and rebill

Priority: Needed to call the system complete. Size: a few days.

Add Denied/Returned and Void statuses to invoices.status, with a reason and a link to the replacement invoice. A 'release for correction' action unbills the invoice's work_logs and track_expenses so they can be fixed and invoiced again, while the original invoice number stays on record for the audit trail. Denied and voided invoices drop out of Outstanding and aging. They can optionally update the linked AT funding record ('funding' in inc/at_catalog.php), and they show their status read-only on iOS.

Builds on: Draft void in invoices.php, generate_invoice(), the AT funding record's Denied and Appeal states. Gap: G16. Best built together with the payments item.

#### Put your organisation's billing details on every invoice

Priority: Needed to call the system complete. Size: under a day.

Add billing settings to the settings table, edited on account.php: VR vendor or provider number, remit-to address, phone, billing email, tax ID and payment terms in days. Print them in both the PDF and the accessible HTML versions of invoice_pdf.php. Work out the due date from the terms on the invoice and in the QuickBooks CSV (invoices.php line 180, now fixed at 30 days). Add a memo or PO field on Draft invoices that saves to the unused invoices.notes column.

Builds on: brand() letterhead, settings table, invoices.notes, AccessibleDocument. Gaps: G22, G89.

**Decision needed:** Please confirm the vendor number, remit-to address and payment terms your VR agency expects to see on an invoice.

#### Free and private-pay training

Priority: Needed to call the system complete. Size: one to two weeks.

Give each training track a billing mode: VR-authorized, private pay or no charge.
- Only VR tracks need an authorization number and funding agency (track_edit.php).
- No-charge tracks may log hours at $0. That means changing the six rate checks in track.php, auto_log_session, api.php and voice.php.
- The invoice batch skips no-charge work.
- Private-pay invoices are billed to the client, not the counselor (generate_invoice in inc/core.php).
Agency, RSA and analytics money totals keep non-VR work separate, while outcomes and utilization still count the hours. The fee rule now hard-coded in community_referral.php and lang_es.php ($2,000 a month threshold, $17.50 an hour) becomes a setting, the quoted fee carries over when a referral is converted, and the iPhone app follows the same rules.

Builds on: training_tracks, generate_invoice(), referrals.quoted_fee and income_bracket. Gap: G18.

**Decision needed:** Do you currently train free or self-paying clients? If so, should private-pay clients be invoiced from this system, or should it only record their hours?

#### Funder list, fee schedules and dated rate changes

Priority: Clear value. Size: a few days.

Add a funders table (name, address, vendor number, payment terms) and pick the agency from a list on track_edit.php instead of typing it. Migrate the existing funding_agency spellings so reports stop splitting one agency into several. Store default hourly and mileage rates by funder and service type to fill in new authorizations. Give rate changes an effective date, so track_edit.php and the Draft void in invoices.php only re-price work done on or after that date.

Builds on: training_tracks.funding_agency, auth_amendments, the effective-dated staff labor rates in AT services. Gap: G21.

**Decision needed:** How many funding agencies do you bill, and does each one have its own fee schedule?

#### Receivables aging, overdue alerts and reconciliation

Priority: Clear value. Size: a few days.

Show 0-30, 31-60, 61-90 and 90+ day buckets by agency on invoices.php and in its CSV, with agency and date filters. Add a cron/daily_alerts.php check for submitted invoices past their payment terms, and for logged work still unbilled after a set number of days. Add a reconciliation view for an agency and date range, listing invoice number, authorization number, billed, paid, payment reference and balance, to match against VR remittance advice.

Builds on: Outstanding summary on invoices.php, cron/daily_alerts.php, the payments item. Gap: G24.

**Decision needed:** After how many days should an unpaid invoice, or work not yet billed, raise an alert?

#### Cleaner accounting export and a month-end close

Priority: Clear value. Size: a few days.

Give the QuickBooks export on invoices.php a date range and status filter, leave out Drafts by default, and mark rows as exported so they are not imported twice. Put hours, mileage and expenses on separate lines using the stored hours_amount, mileage_amount and expenses_amount, and add a payments-received CSV by deposit date. Add an administrator 'books closed through' date that refuses new or changed work, expenses and invoices dated on or before it on the web, in api.php and in the iOS offline replay. Add an as-of-date summary of invoiced, received and outstanding for the auditor.

Builds on: invoices.php export, agency_report.php, the payments item. Gap: G23.

**Decision needed:** Which accounting package do you use (QuickBooks Online or Desktop), and when do you close each month?

#### Grants and contracts tied to real services and costs

Priority: Clear value. Size: one to two weeks.

Let a contract's name, funder, number, dates and notes be edited on contracts.php and through api.php contract_update. Today a no-cost extension means starting a new contract. Add contract_id to training_tracks. The contract page then shows, for the grant period and against the budget, the hours delivered, clients served, amount invoiced and received, and direct cost, with numeric targets on deliverables. Add an action that posts a device purchase's authorized amount from procurement.php, or an interpreter booking's cost from access_bookings, as a track expense or contract spend line instead of retyping it, and warn when a quote is above the authorized amount.

Builds on: contracts, contract_spend, contract_deliverables, at_profitability(), device_requests, access_bookings. Gaps: G19, G28.

**Decision needed:** Which grants or contracts do you hold besides VR authorizations, and what numbers do they ask you to report?

#### Receipts and trip details behind billed expenses and mileage

Priority: Clear value. Size: a few days.

Add a document link and a category (materials, travel, interpreter and so on) to track_expenses, and a 'Receipt' document category. Add an upload-or-link option to the expense forms in track.php, api.php add_expense and the iOS ExpenseSheet. Add optional from and to locations for billed mileage on work_logs, so every billed trip has a record behind it for auditors.

Builds on: track_expenses, client_documents, iOS document upload. Gap: G26.

#### Keep financial records when a client record is purged

Priority: Clear value. Size: a few days.

Make the purge on client.php refuse while any invoice is still unpaid. Instead of deleting invoice, payment, expense and amendment rows with the client, keep a de-identified copy: number, dates, amounts, agency, authorization number and payment reference. Record invoice counts and totals in the client.purge audit entry, and confirm the export ZIP was made first. Prior-year revenue and agency reports then stay the same after a purge.

Builds on: client.php purge and its incident-redaction pattern, client_export.php, audit_log. Gap: G25.

**Decision needed:** How many years must financial records be kept under your VR contract and your grants?

#### Bill Pre-ETS group sessions from cohort attendance

Priority: Nice to have. Size: a few days.

When a cohort session is logged in programs.php or api.php log_cohort_session, offer to create a work log on each present student's active Pre-ETS track. Use the matching Pre-ETS service category and the authorization check, and reverse those logs if the session is deleted before it is invoiced. Count a group session's time once in utilization.php, and add cohort_sessions minutes to staff utilization.

Builds on: cohort_sessions, cohort_attendance, auto_log_session(), Pre-ETS RSA categories. Gaps: G20, G27.

**Decision needed:** Since the October 12 change made new referrals AT-only, is Pre-ETS group work still part of your business? If not, skip this item.

#### Non-billable staff time and timesheets

Priority: Nice to have. Size: a few days.

Add a way to log documentation, admin, travel and unfunded-client time that is not tied to an authorized track. Add a per-staff pay-period timesheet that totals work logs, cohort sessions, non-billable time and mileage for each staff member. Add an employee or contractor type on users, so you can see real workload and not only billable hours.

Builds on: work_logs.created_by, utilization.php, users.weekly_hours. Gap: G27.

**Decision needed:** Do you need staff hours for payroll from this system, or is payroll already handled somewhere else?

### Authorizations, plans and service delivery

Logging sessions and tracking authorizations already works on the web, the iPhone and by voice. The gaps are following an amendment request until it is answered, getting emailed authorizations into the client file, recording what really happened at a session, and moving a session without cancelling it and booking it again.

#### Track amendment requests from sent to answered

Priority: Clear value. Size: a few days.

Add a 'Record request as sent' step to amendment_request.php, the api.php 'amendment' action and iOS AmendmentView. It stores the date, the recipient, the hours, dollars or end date requested, and the final letter as a track case note. It also opens a follow-up task for the client's worker. Staff later record the reply (approved, partly approved, denied) with its date, which carries into track_edit.php so the auth_amendments row links back to the request. The track page, compliance.php, the dashboard and the daily alerts show 'amendment requested on <date>, awaiting reply'.

Builds on: amendment_request.php letter draft, auth_amendments, add_task(), cron/daily_alerts.php. Gap: G10.

#### File emailed authorizations into the client record

Priority: Clear value. Size: a few days.

Make cron/ingest_email.php fetch each email's attachments from Microsoft Graph and save allowed file types as client_documents: category Authorization when the email comes from the verified counselor, otherwise Other. Link each file to the case note and list the files in the note; just naming the attachments in the note is a quick first step. Add a short in-app queue where staff confirm the category and the client, and an optional text column on client_documents so a scanned authorization can be searched and read as text. Optionally, let counselors upload an authorization or IPE on vrc.php, reusing the upload code in portal.php.

Builds on: cron/ingest_email.php, client_documents categories, upload_document checks, portal.php upload. Gaps: G91, G44, G106.

**Decision needed:** How do your counselors usually send authorizations: as email attachments, by fax-to-email, or through their agency's own portal?

#### Record what actually happened at a session, and fix hours afterwards

Priority: Clear value. Size: a few days.

Add optional fields to session completion in schedule.php (single and group) and api.php complete_session: actual hours or times, miles driven, service category, the goal worked on, and a short note. Write them to work_logs, goal_progress and client_notes. Allow an unbilled work log's hours and miles to be corrected, with an audit entry, and allow an entry that is mileage only. Add an api.php list of a track's work logs, a delete action for unbilled entries, an hours section on iOS TrackDetailView, and a 'my hours this week against my weekly target' view for every staff member.

Builds on: auto_log_session(), schedule_hours(), the work_logs mileage, rsa_category and goal_id columns, trash_row(). Gaps: G61, G66.

#### Client sign-off for sessions on the web, by voice and remotely

Priority: Clear value. Size: a few days.

Add an optional typed 'client name as signed' attestation, and a drawn signature where possible, to the hour-logging form on track.php and to session completion on schedule.php. Let staff add one to an existing unbilled log. Send signed_name from iOS LogHoursView (the API already accepts it), ask for it in voice logging, and add an 'I attended this session' confirmation for past and remote sessions in the client portal. Record signed_at and the source on work_logs, as consent_forms already does, so each attestation can be audited.

Builds on: work_logs.signature_path and signed_name, api.php complete_session, SignatureView.swift, the '[Client-signed]' marking in invoice_pdf.php. Gap: G11.

**Decision needed:** Does your VR agency require a client signature or confirmation for each session before it pays?

#### Reschedule sessions and cancel a series or group in one step

Priority: Clear value. Size: a few days.

Add a reschedule action that changes a session's time on schedule.php, api.php, iOS ScheduleView and voice. It re-runs workflow_authorization() and keeps the session's history. Add a series_id column so the rest of a weekly series can be cancelled at once, and a whole-group cancel for group sessions. Give build_ics() stable per-session IDs with SEQUENCE and METHOD:CANCEL so the instructor's emailed invite updates or disappears. Warn when the instructor is already booked at an overlapping time.

Builds on: scheduled_sessions, build_ics() in inc/core.php, calfeed.php, group_uid. Gaps: G50, G90.

#### A 'waiting for authorization' stage and On Hold follow-up

Priority: Clear value. Size: a few days.

Add an 'Awaiting Authorization' client status everywhere statuses are listed: CLIENT_STATUSES in inc/core.php, the clients.status column, iOS, the voice prompt and pipeline.php. Offer it when the evaluation report is emailed. Require a reason for On Hold and allow a return date, and have cron/daily_alerts.php create check-in tasks for On Hold clients as it already does for Waitlist. Warn, without blocking, when a client is set to Active Training with no active authorized track, and flag the same case in doc_audit.php.

Builds on: CLIENT_STATUSES, client_status_history, pipeline.php stages, the Waitlist check-ins in daily_alerts. Gap: G13.

**Decision needed:** Do you want a separate 'Awaiting authorization' status, or is On Hold with a reason enough for how you work?

#### Plan goal, VR case number and counselor handover

Priority: Clear value. Size: a few days.

Add client fields for the IPE employment goal, the IPE and amendment dates, and the VR agency's case or participant ID. Make them editable on client_edit.php, api.php and iOS ClientEditSheet. Print them on invoice_pdf.php, report.php and the monthly reports, rsa_report.php, and the eval_report.php and procurement.php justifications, replacing today's placeholder wording. Add a 'replace counselor' action on counselors.php that moves every client with one counselor email to a new counselor in one audited step, resets counselor_verified and revokes the old portal link.

Builds on: the counselor fields on clients, counselors.php, eval_report.php, procurement.php. Gap: G7.

### Progress reports, case closure and outcomes

Signed progress reports to counselors already work. Closing a case, though, only records a status label. There is no closure reason, outcome, final report, checklist or follow-up, and the outcome data staff already enter is never added up across clients. Closures and outcomes are what counselors and funders pay for and audit.

#### Structured case closure: reason, outcome and checklist

Priority: Clear value. Size: a few days.

When a client is closed, ask for a reason from a set list (required for 'Closed - Other') and an optional short outcome: employed yes or no with employer, job title, start date, hours and wage, or an independence outcome for clients without a job goal. Show a closure checklist on client_edit.php, the API, iOS and voice that lists open tracks, unbilled work, equipment loans, assigned licence seats, future sessions and any final report not yet sent, with a way to resolve each. Add the reason and outcome to outcomes.php, funder_report.php and rsa_report.php, so the employment column holds more than Y or blank.

Builds on: the closed values in CLIENT_STATUSES, record_status_change(), client_status_history, the closure counts in outcomes.php. Gap: G1.

**Decision needed:** Which closure reasons and outcome details do your VR agency and funders ask you for?

#### Final report to the VR counselor at closure

Priority: Clear value. Size: a few days.

Add a 'final' mode to build_track_report() in inc/report_builder.php, titled Final Report and with its own note type. It adds starting and ending skill levels (client_competency and scored assessments), devices and setups delivered, recommendations for further services, and the closure reason and outcome. Offer to send it when a track is set to Completed in track_edit.php or a case is closed, using the existing signed and acknowledged sending on report.php and iOS send_report.

Builds on: report.php, report_acks, client_competency, client_scored_assessments, equipment and AT setup records. Gaps: G2, G97, G107. Build after the closure item.

**Decision needed:** Does your VR agency have its own required closure or final report format to match?

#### Better progress reports: reporting period, attendance and goal progress

Priority: Clear value. Size: a few days.

Pass a reporting period (from the last report to today) into build_track_report(), so the session log and hours cover that period while the totals stay all-time. Add:
- each goal's percentage and the progress notes from goal_progress for the period
- an attendance section from scheduled_sessions (completed, cancelled, no-show), plus a new column for who cancelled and why
- barriers and recommendations boxes that the AI draft on report.php can prefill
Save a copy of each sent report's text so it can be read back later. Let the automatic report in cron/monthly_reports.php either wait for staff to add a narrative or reuse the last one.

Builds on: inc/report_builder.php, goal_progress, scheduled_sessions statuses, the AI cover note on report.php, report_acks. Gaps: G5, G80, G36.

**Decision needed:** Should automatic monthly reports go out unreviewed, or wait for staff to add a short narrative first?

#### Follow up with clients after closure

Priority: Clear value. Size: a few days.

Add a 'closure follow-up' record kind in inc/at_catalog.php, which gives web and iOS forms automatically. Schedule 30, 60 and 90-day checks on all three closing paths (client_edit.php, api.php, voice.php) for both closed statuses. Each check asks whether the person is still employed or independent in the trained skills, still using their technology, and needs more service. A 'needs service' answer creates a re-referral task. Report retention rates in outcomes.php and funder_report.php.

Builds on: AT outcome records and cron/at_reminders.php, equipment_followups, the send_outcome_survey() trigger points. Gaps: G12, G84.

#### Outcome survey for every closure, by email or by phone

Priority: Clear value. Size: a few days.

Send the survey for both closed statuses, and optionally when a track is completed. Add a 'Send survey' button on client.php and a staff form for answers taken by phone, with a channel column on the surveys table. Add a reminder for unanswered surveys and a survey responses page with filters and CSV. The satisfaction figures shown to the board will then cover more than successful closures.

Builds on: send_outcome_survey(), survey.php, surveys table, the survey section of analytics.php. Gap: G84.

#### Outcome measures across all clients

Priority: Clear value. Size: a few days.

Add a section to outcomes.php, and correct funder_report.php, so they add up the outcome data staff already enter:
- device still in use, and change in independence and confidence, from the 30/60/90-day AT outcome records
- skill gains from client_competency
- goals achieved within the period, worked out from goal_progress dates rather than the date the goal was created
Allow breakdowns by instructor, service type and program.

Builds on: the at_records outcome kind, client_competency, goal_progress, funder_metrics() in funder_report.php. Gap: G75.

#### Vision status and demographics for blind-services grants

Priority: Nice to have. Size: a few days.

Add a structured vision status (totally blind, legally blind, low vision) and age of onset to the client record. Add an age-band and impairment breakdown in outcomes.php, using the date of birth already stored. A full Older Individuals who are Blind style report, with gender, race and living situation fields, would follow only if such a grant is held.

Builds on: clients.dob, tech_assessments.primary_disability, AT outcome independence scores. Gap: G8.

**Decision needed:** Do you hold, or plan to apply for, an Older Individuals who are Blind or state blind-services grant that asks for these counts?

### Consent, privacy and record keeping

The system holds health information and sends it to counselors, employers and AI services. Guardian consent is well built, but since referrals became AT-only almost every new client is an adult with no consent or release on file. Edits, views and deletions of records are also not fully traceable.

#### Consent and release forms for adult clients

Priority: Needed to call the system complete. Size: a few days.

Extend consent_forms, or add a releases table, so clients can sign for themselves. Each release records:
- a named recipient (VR counselor and agency, employer, doctor, other)
- what information it covers and why
- start and expiry dates
- withdrawal
Reuse the accessible typed-name signing page in consent.php, in English and Spanish. Make the Compliance consents card cover all open clients. Warn before a report is sent when no current release covers the recipient: report.php, api.php send_report, eval_report.php and the employer report in accommodations.php. Add telepractice and recording consent, an admin setting for consent_validity_days on account.php (today it is stuck at 365), and consent status in the iOS app.

Builds on: consent_forms, the consent.php e-signature, withdrawn_at, the compliance.php renewal list. Gaps: G4, G32, G89.

**Decision needed:** What release wording does your VR agency require, and how long should a signed release stay valid?

#### Privacy notice page and a record of who received it

Priority: Clear value. Size: under a day.

Add a plain-language privacy notice page in English and Spanish, built like consent.php. It says what is collected, who it is shared with (school, VR agency, AI service), how long it is kept (from the retention_years setting) and whom to contact. Link it from the footer in inc/layout.php, the consent section of community_referral.php, help.php and the guardian consent page. Record a 'notice given' date for adult clients, shown on client.php and compliance.php. The same page is the privacy policy address the App Store requires for the iPhone app.

Builds on: the bilingual wording in consent.php, register_translations, retention_years. Gaps: G39, G68.

**Decision needed:** Do you have approved privacy notice wording to use, or should a draft be prepared for you to review?

#### Guardian details on the client record and a check for minors without consent

Priority: Clear value. Size: a few days.

Add guardian name, phone and email to client_edit.php, the api.php client actions and iOS ClientEditSheet, and show them with a call link on client.php and iOS ClientsView. Today a guardian's phone number can never be corrected after intake. List open clients who are under 18 or Pre-ETS and have no consent requested on compliance.php, and have cron/daily_alerts.php warn when a client with a guardian turns 18.

Builds on: the guardian columns on clients, consent_forms, compliance.php. Gaps: G32, G60.

**Decision needed:** How many of your current clients are minors or have a legal guardian?

#### Keep earlier versions when case notes are edited

Priority: Clear value. Size: a few days.

Add a client_note_revisions table that saves a note's old text just before it is changed in client.php or api.php update_note, and an 'earlier versions' view on client.php and track.php. Add an Addendum or Late Entry note type, so a correction can sit beside the original, and an optional setting that locks notes after a number of days. Include revisions in purge, merge (inc/client_merge.php) and export (inc/client_export.php).

Builds on: client_notes updated_at and updated_by, NOTE_TYPES, the documentation review fingerprint. Gaps: G36, G37.

**Decision needed:** Should notes lock for editing after a set number of days, and if so, how many?

#### Record who viewed a client's file and every bulk export

Priority: Clear value. Size: a few days.

Write an audit entry, limited to one per person per client within a short time window, when a staff member opens client.php or track.php or calls an API read action for a client, and when the client or counselor portal is opened for a client. Add an audit entry to each bulk export (export.php, rsa_report.php, agency_report.php, funder_report.php, utilization.php, outcomes.php, invoice_pdf.php). The export auditing alone is a small, high-value first step. Add action and entity filters to audit.php.

Builds on: audit() and audit_log, the client filter on audit.php. Gap: G30.

#### Holds and safer deletion for key records

Priority: Clear value. Size: a few days.

Add a hold flag on a client record that records who placed or lifted it, when and why. Make auto-archive in cron/daily_alerts.php, client purge and trash purging respect it. Let only administrators delete Authorization, IPE and Release documents, and keep those out of the 30-day trash purge. Write an audit entry for every automatic trash purge, and refuse a purge while an AT funding appeal, an unpaid invoice or an open incident exists.

Builds on: client.php purge, trash_row() and purge_trash() in inc/core.php, retention_years, DOC_CATEGORIES. Gap: G34.

#### Privacy incident log with notice deadlines

Priority: Clear value. Size: a few days.

Add privacy incident types (misdirected report, lost device, unauthorized access, wrong portal link) to incidents.php, api.php and iOS IncidentsView. Each incident gets:
- a discovery date, the 60-day notice deadline and cron reminders
- a list of all affected clients
- risk assessment fields
- a log of notices sent
- dated follow-up entries
Link each incident to the client's access history in audit.php, and keep affected-person details for breach incidents when a client is purged.

Builds on: incidents table, admin alerts for High and Critical incidents, audit.php. Gap: G33.

#### AI and email vendor safeguards

Priority: Clear value. Size: a few days.

Make the 'business associate agreement confirmed' box in ai.php a real requirement: until it is ticked, features that send unredacted text (web read-aloud through ai_speak in inc/ai.php, translate.php, remediate.php) cannot run. Record who confirmed it, when, and when it expires. Add a setting that turns off the fallback from Microsoft Graph to PHP mail() in send_app_mail (inc/core.php). Add a small vendor register (Microsoft 365, web host, AI provider, backup mailbox) with agreement status and renewal reminders.

Builds on: ai.php settings, ai_redact(), send_app_mail(), cron/daily_alerts.php. Gap: G38.

**Decision needed:** Have business associate agreements been signed with Microsoft, your web host and your AI provider?

#### Less client detail in emails, and reports by secure link

Priority: Clear value. Size: a few days.

Add a setting that makes the staff alert digests in cron/daily_alerts.php show counts and a sign-in link instead of client names, authorization numbers and serial numbers. Remove client names from fixed email subjects in inc/report_builder.php, cron/session_reminders.php and referrals.php. Offer to deliver progress reports as a link into the code-protected counselor portal, using a stored copy of the report, and make the 'copy to me' option a link rather than the full text.

Builds on: the daily_alerts digests, the code sign-in on vrc.php, the stored report copy from the progress report item. Gap: G41.

**Decision needed:** Do your VR counselors accept progress reports as plain email, or do they require encrypted email or a portal?

#### Lost phone: wipe and per-device control

Priority: Clear value. Size: a few days.

Record a device name on each app session in api_tokens, so an administrator can see and revoke one phone on users.php. Add a 'report lost device' action that tells the app to wipe its drafts, offline queue and cached client names; this must be separate from an ordinary sign-out or password change. Refuse to store drafts and the offline queue on a phone without a passcode, and offer a 'remove all data from this phone' option at sign-out.

Builds on: api_tokens, the 'sign out of mobile app' action on users.php, iOS AppLock and DraftStore. Gap: G42.

#### Track requests for record copies and corrections

Priority: Clear value. Size: a few days.

Add 'copy of my records' and 'correction' request types to the portal (PREQ_TYPES and portal.php). Each has a 30-day due date and records who asked, how the copy was delivered, the outcome and any reason for refusal. Build a client-safe version of client_export.php that leaves out internal review and billing items, and let staff publish it to the client's portal.

Builds on: portal_requests, client_export.php, Resource documents in the portal. Gap: G37.

### Keeping clients and counselors informed

Both portals work, but they mostly wait for someone to visit. Clients are not told when sessions are booked, moved or cancelled. Staff replies go unnoticed, and portal links expire without warning. The contact method and language recorded for each client are not used. Blind clients and busy counselors need this information to come to them.

#### Tell clients when sessions are booked, moved or cancelled

Priority: Clear value. Size: a few days.

When a session is booked on any path (schedule.php single, series and group, api.php schedule_session, voice.php), email the client a confirmation with a calendar invite, and the guardian too where chosen. When a session is cancelled or moved, send a cancellation that removes it from their calendar. Add an 'Add to calendar' link to sessions in the portal, confirm back to the client when their reschedule or cancel request is closed, and use new editable templates in EMAIL_TEMPLATES.

Builds on: build_ics(), cron/session_reminders.php, portal_requests.php, email_templates.php. Gaps: G102, G90. Needs the reschedule item first for stable calendar IDs.

#### Notices for staff replies and request decisions

Priority: Clear value. Size: a few days.

When staff post a message from messages.php or api.php post_message, email the client or counselor a 'you have a new message' notice with the portal link and no health details, at most about once per conversation per day. Add a staff response or reason to portal_requests, shown to the client in portal.php beside the status (especially 'Not possible'). Email the client when a request is closed.

Builds on: post_message(), portal_requests, email_template(). Gap: G101.

#### Portal links that do not silently expire

Priority: Clear value. Size: under a day.

Extend a client or counselor portal link's expiry each time it is used; portal.php and vrc.php now only record the last use. Have cron/daily_alerts.php warn staff and the link holder about 14 days before a link expires, and state the expiry date in the email and on the dead-link page along with contact details. Optionally add a rate-limited 'email me a new link' form that sends only to the address on file, and an API action so the iPhone app can send a client portal link.

Builds on: portal_tokens, vrc_tokens, resend on client.php and counselors.php. Gap: G105.

#### Counselor portal: reopen reports, see attendance, confirm receipt

Priority: Clear value. Size: a few days.

On vrc.php, let a counselor:
- open any listed report again, regenerated by build_track_report() or taken from the stored copy
- see each client's sessions marked completed, cancelled or no-show
- confirm receipt of a report without the email link
- see pending referrals and approved authorization amendments
Add a counselor portal section to help.php and to the help map in inc/layout.php.

Builds on: vrc.php, report_acks, ack.php, auth_amendments. Gap: G106.

#### Notify the counselor about no-shows, holds, closures and expiring authorizations

Priority: Clear value. Size: a few days.

Send optional, minimal notices with a portal link, to confirmed counselors only (counselor_verified), when:
- a client has repeated no-shows
- a client is put On Hold or closed (client_edit.php, api.php, voice.php)
- an authorization reaches the alert points cron/daily_alerts.php already uses (30, 14 and 7 days left, 80% of hours or funds used)
The details stay behind the portal sign-in.

Builds on: the thresholds in cron/daily_alerts.php, vrc.php, record_status_change(). Gap: G107.

**Decision needed:** Which of these events would your counselors want to be told about by email?

#### Client portal history: past sessions, hours left and finished work

Priority: Clear value. Size: a few days.

In portal.php, show the client their past sessions and attendance, hours used and remaining on each authorization, completed homework, closed tracks with their goals, and their own earlier practice logs. Let staff share a copy of a progress or evaluation report to the portal, add a staff feedback field on homework practice, and offer a completion summary when training ends.

Builds on: portal.php, track_rollup(), homework and homework_practice, the Resource category in client_documents. Gap: G108.

#### A 'My devices' section in the client portal

Priority: Clear value. Size: a few days.

Add a read-only card on portal.php listing:
- the client's open equipment loans, with due dates and return instructions
- the setup and training instructions from AT setup records that staff mark as shareable
- the status of any repair and loaner
Send due-soon and overdue loan reminders to the client, and let clients answer the 30/60/90-day device check-ins themselves, saved as client-reported.

Builds on: equipment_loans, the at_records setup, repair and outcome kinds, inc/at_portal.php. Gap: G112.

#### Use each client's preferred contact method

Priority: Clear value. Size: a few days.

Make cron/session_reminders.php read the client's recorded contact preference. It skips the email for Phone and Postal mail clients and gives the instructor a 'call or mail these clients' list, which also includes clients with no valid email. Before staff send a survey (send_outcome_survey) or a portal link (client.php), show the preferred contact method and format, with a warning when it is not email, on the web, the API, iOS and voice. Link remediate.php?client=N from the client page, and start the portal's text size larger for clients who prefer large print.

Builds on: clients.a11y_contact and a11y_format, the portal's low-vision settings, remediate.php. Gap: G121.

#### Preferred language for each client and guardian

Priority: Clear value. Size: a few days.

Add preferred_language to clients and guardians and capture it on the referral form. Use it to:
- open portal, consent and survey links in that language
- send Spanish versions of the portal link, consent request, survey invitation and session reminder emails (email_template() gets a language argument)
Translate the homework help labels in lang_es.php. Let staff mark homework instructions and knowledge base articles as Spanish, so screen readers stop reading Spanish text with an English voice (portal.php lines 645 and 863).

Builds on: inc/i18n.php, lang_es.php, email_template(), the language toggle on the referral form. Gap: G109.

**Decision needed:** How many of your clients or guardians prefer Spanish, and do you need any other languages?

#### Clients choose from offered times in the portal

Priority: Nice to have. Size: a few days.

On a portal request, let staff offer two or three times that have passed the authorization and double-booking checks. The client sees them in portal.php as an accessible list with one Confirm button. Confirming books the session, cancels the original one if it was a reschedule, sends the calendar invite and closes the request task.

Builds on: portal_requests, workflow_authorization(), the reschedule and client-notice items. Gap: G111.

#### Letter templates the organisation can edit

Priority: Nice to have. Size: a few days.

Add letter templates the organisation can edit, with merge fields for client, counselor, track and authorization, by extending email_template() in inc/core.php. Examples: welcome, appointment, no-show, equipment loan and return, and referral acknowledgement letters. Each letter produces accessible HTML and a PDF through AccessibleDocument and is saved to the client's documents or notes.

Builds on: EMAIL_TEMPLATES, email_templates.php, AccessibleDocument, client_documents. Gap: G97.

**Decision needed:** Which letters do staff write most often today?

### Voice, accessibility and the iPhone app

You run the business without sight, and voice and the iPhone app are your fastest tools. Today voice stops working whenever the AI service is off, several daily jobs still mean switching to web pages in Safari, and staff alerts can only be seen on the website. An improved iPhone app has been built but not shipped since version 3.0.13.

#### Ship the iPhone app update

Priority: Clear value. Size: under a day.

Raise the app version in ios/project.yml from 3.0.13 build 28, so the Action center and case briefing changes from 3.0.16 to 3.0.20 reach staff phones. Add the missing file-timestamp reason (C617.1) to ios/Sources/PrivacyInfo.xcprivacy. Publish the privacy notice page and a support page for the App Store listing, and write a short release checklist covering archiving, TestFlight or private distribution, and how App Review signs in. Have the app read server_version from the 'me' action, so it can show a 'please update' notice when it is too old.

Builds on: ios/project.yml, PrivacyInfo.xcprivacy, server_version in the api.php 'me' action, the privacy notice item. Gap: G68.

**Decision needed:** Will the app go through the public App Store, a private custom app through Apple Business Manager, or TestFlight only?

#### Voice keeps working without the AI service

Priority: Clear value. Size: a few days.

Add a built-in matcher in voice_interpret() (inc/voice.php) that answers the 12 fixed questions, the 28 section names, 'search X' and 'open <client>' directly, and sends only free-form requests to the AI provider. Separate the voice switch from the AI switch in layout.php, ai_command.php and api.php, so the assistant stays available when AI is off, down or over its rate limit. This also stops client names in simple questions from being sent to the provider.

Builds on: voice_answer(), voice_sections() and voice_find_client() in inc/voice.php. Gap: G47.

#### More voice commands for daily work

Priority: Clear value. Size: a few days.

Add voice commands to:
- cancel a session or mark it No Show, with confirmation, as api.php set_session_status does; reschedule once that exists
- hear and reply to unread messages, and hear unread alerts, new referrals and open client requests
- hear a client's and their counselor's phone and email
- get billing answers, for users with billing permission: unbilled hours and amount, unpaid and short-paid invoices, and aging, using unbilled_totals() and the invoices table
- hear the caseload's authorizations expiring soon, using the dashboard query in index.php

Builds on: the ask and create intents in inc/voice.php, messages.read_at, notifications, unbilled_totals(). Gap: G46.

#### Alerts that reach you outside the website

Priority: Clear value. Size: a few days.

Let notify_user() (inc/core.php) also send an email with a sign-in link and no client details for time-sensitive events: client cancel or reschedule requests, portal messages and 'need help' practice reports. Each user chooses immediately, daily digest or off. Add unread alerts to the existing daily email, and add api.php actions plus an Alerts list with an unread count to the iPhone app.

Builds on: notifications table, notifications.php, the personal emails from cron/daily_alerts.php. Gap: G45.

#### Client requests, invoices and tasks in the iPhone app

Priority: Clear value. Size: one to two weeks.

Add api.php actions and native iOS screens for:
- the client-request inbox: type and details, set In progress, Done or Declined, and close the linked task, as portal_requests.php does
- invoices: list Draft, Submitted, Paid and short-paid, and mark them submitted or paid once the payments item exists
- a task list with complete and reopen
Also fix voice task completion so it updates the linked client request.

Builds on: portal_requests.php, invoices.php, tasks.php, iOS WorkflowActionsView. Gap: G48.

#### Do several at once: bulk actions for month-end and inboxes

Priority: Clear value. Size: a few days.

Add a checkbox list with one Submit button for:
- marking several Draft invoices Submitted on invoices.php
- recording outcomes on schedule.php's ended-sessions list, returning focus to where you were
- completing tasks on tasks.php
- closing client requests on portal_requests.php
Each one saves a screen-reader user dozens of rounds of finding a row, filling a form and confirming.

Builds on: set_status in invoices.php and schedule.php, the focus_next pattern in tasks.php, mark-all in notifications.php. Gap: G51.

#### Documents in each client's preferred format

Priority: Clear value. Size: one to two weeks.

Read clients.a11y_format to choose which version of a client-facing document is produced, downloaded or offered in the portal, and add a large-print print stylesheet for AccessibleDocument (inc/document.php). Either add braille-ready (BRF) output through liblouis and audio files through ai_speak(), or stop offering those two formats until they can be delivered. Tagged accessible PDF is the expensive part and can wait.

Builds on: A11Y_FORMATS, the HTML versions from AccessibleDocument, ai_speak(). Gap: G43.

**Decision needed:** Should the system make braille-ready and audio files itself, or should staff keep making them with their own tools?

#### Work offline at a visit

Priority: Clear value. Size: one to two weeks.

Keep a small, protected copy on the phone of the next 7 days of sessions and those clients' cards (name, phone, address, access needs, active goals), built on the DraftStore pattern in ios/Sources/API.swift and labelled with when it was last updated. ScheduleView and ClientDetailView fall back to it, so a case note can be started with no signal. Let signed session completions, No Show, Cancelled and cohort attendance wait offline and send later, with server-side protection against duplicates (idem_key) in api.php complete_session, set_session_status and log_cohort_session. Keep drafts of incident, assessment and expense forms.

Builds on: the iOS offline queue and Offline entries screen, DraftStore, TrackCache, work_logs.idem_key. Gaps: G56, G57.

#### Everything for a visit in one place: address, directions, guardian and call links

Priority: Clear value. Size: a few days.

On the iPhone client card, show the client's address with a Directions link and the counselor's and guardian's phones as call links, adding school and guardian fields to the api.php 'client' action. Add call and directions links on client.php, clients.php, referrals.php and schedule.php on the web. In the app, make each session link to the client card and to Add note, give directions to the session location or the client's address, and add a 'My sessions' filter.

Builds on: iOS telURL(), ClientsView, the api.php schedule action, session locations in calfeed.php. Gaps: G60, G100.

#### Equipment loan hand-off record

Priority: Clear value. Size: a few days.

Record the device's condition when it goes out and when it comes back, and the client's acceptance of the loan agreement (typed name or the existing SignaturePad), on equipment_loans in equipment.php, api.php and iOS EquipmentView. Let item_condition be updated when damage is found. Add an equipment agreement document category so a signed paper agreement can be linked to the loan. Scanning QR labels inside the app can come later.

Builds on: equipment_loans, loan_equipment() and return_equipment() in inc/access.php, SignatureView.swift. Gap: G64.

**Decision needed:** Do you want clients to sign, or type their name, to accept a loan agreement, and do you have agreement wording?

#### Create and amend authorizations from the iPhone

Priority: Nice to have. Size: a few days.

Move track_edit.php's checks into a shared include, and add api.php actions to create a track and change its authorization terms. They write auth_amendments in the same locked transaction, and a read action returns amendment history. Add 'Add training track' and 'Edit authorization' sheets and an amendment history list to the iOS app.

Builds on: track_edit.php, auth_amendments, TrackDetailView. Gap: G14.

#### Calendar feed additions and a subscribe link in the app

Priority: Nice to have. Size: under a day.

Add interpreter, CART and transport bookings and Pre-ETS cohort sessions to calfeed.php, with titles that do not reveal disability. Add a reminder alarm (VALARM) to feed events. Offer a one-tap webcal:// subscribe link in the iPhone app and on account.php, and explain the subscription in help.php.

Builds on: calfeed.php, users.cal_token, the alarm already in build_ics(). Gap: G67.

#### Easier iPhone sign-in and hands-free shortcuts

Priority: Nice to have. Size: a few days.

Add 'Sign in with Microsoft' and passkey sign-in to the app through new api.php actions that reuse sso.php and inc/webauthn.php. Renew the 30-day app token while it is in use (api.php line 336), so staff are not asked for a code every month. Later, add Siri and Shortcuts actions that go through the existing voice_command with its yes/no confirmation, and an iPad layout.

Builds on: sso.php, passkey.php, inc/webauthn.php, VoiceAssistant.swift. Gaps: G69, G70.

**Decision needed:** Do you want staff to sign in to the app with Microsoft, or is Face ID plus a monthly emailed code acceptable?

### Management reports and data quality

Many reports exist, but they are spread over eight pages, mostly cover all time, and none arrives by itself. You need one page, organised by headings, that shows how the business is doing this period. You also need spreadsheet exports you can sort with a screen reader, and a page that shows which data needs cleaning before a report is due.

#### One Reports page and period-based business summary

Priority: Clear value. Size: a few days.

Add a Reports page to the main navigation in inc/layout.php that links analytics, outcomes, the funder pack, RSA-911, the agency summary, utilization, capacity, risk and the documentation audit, and add those names to voice navigation. Give analytics.php:
- a date range or fiscal-year selector, with comparison to the previous period and the same period last year
- monthly tables of referrals received, intakes, active caseload, closures and rehabilitation rate
- open caseload by instructor and status
- a CSV and accessible HTML export
Add a fiscal-year start setting that becomes the default period for the other reports.

Builds on: analytics.php, outcomes.php, utilization.php, voice_sections(). Gap: G76.

**Decision needed:** When does your fiscal year start?

#### Monthly summary emailed to you

Priority: Clear value. Size: a few days.

Move the figures now worked out inside outcomes.php, utilization.php, doc_audit.php, invoices.php, analytics.php and funder_report.php into a shared include that cron can call. Each month or quarter, email administrators an accessible HTML summary with totals only and no client names: outcomes and rehabilitation rate, utilization, referrals and conversions, invoices outstanding, documentation gaps, and authorizations or contracts nearing their end. Add a setting for who receives it and how often.

Builds on: send_app_mail(), effective_admin_user_ids(), the Reports page item. Gap: G77.

**Decision needed:** Should it be monthly, quarterly, or both, and who besides you should receive it?

#### Spreadsheet exports of everyday lists and better client filters

Priority: Clear value. Size: a few days.

Add CSV downloads for the client list, referrals, tasks, sessions, equipment inventory and loans, follow-ups, incidents, contracts, compliance and portal requests. Use the existing csv_cell and write_csv_row helpers in inc/security.php, keep caseload limits, and write an audit entry for each export. Add funding agency, client type, named instructor and school filters to clients.php, and let the export go past the 500-row screen limit.

Builds on: the CSV helpers in inc/security.php, report_table() caseload scoping, the filters on clients.php. Gaps: G78, G85.

#### Data-quality check page and duplicate warnings

Priority: Clear value. Size: a few days.

Add a page like doc_audit.php that lists:
- likely duplicate clients, matched on name, date of birth, phone or email, with a link to merge them on client_merge.php
- closed clients with tracks still Active
- Active Training clients with no active track
- open clients missing date of birth, counselor or disability
- older hours with no service category
- minors with no consent requested
Warn about a possible duplicate when a client is created on client_edit.php, through api.php or by voice.

Builds on: doc_audit.php, the duplicate check on referrals.php, client_merge.php. Gap: G79.

#### Attendance and no-show rates

Priority: Clear value. Size: under a day.

Add a report of completed, cancelled and no-show sessions from scheduled_sessions, by client, instructor, service type, remote or in person, and period, with CSV. Show each client's session history on client.php and list clients with repeated recent no-shows. The 'who cancelled' column comes from the progress report item.

Builds on: scheduled_sessions.status, report_table(), schedule.php. Gap: G80.

#### Unused authorization report

Priority: Clear value. Size: a few days.

Add a report of authorizations that ended in a period. It shows hours and dollars authorized, delivered, invoiced and left unused, with totals and breakdowns by funding agency, service type and staff member, plus CSV and an API view. Add a completed_at date on training_tracks, set when a track is marked Completed or Cancelled, so the report can tell accurately which authorizations ended in the period.

Builds on: track_rollup(), the agency_report.php and funder_report.php patterns. Gap: G81.

#### Client timeline and time in each status

Priority: Clear value. Size: a few days.

Add a Timeline tab on client.php for staff who can see the client. It lists case events by date under date headings, each linked to its record: referral and intake, status changes, sessions held or missed, goal progress, assessment scores, loans and returns, setups and follow-ups, reports sent and acknowledged, consents, incidents, requests, the survey and closure. Show days spent in each status and the total length of stay, from client_status_history.

Builds on: the child-record mapping in audit.php, the stage_since logic in pipeline.php, client_status_history. Gap: G83.

#### Fix assessment scoring direction and old percentages

Priority: Clear value. Size: under a day.

Add a 'higher is better / lower is better' choice to the add-instrument form in templates.php, and make the trend badge on client.php respect it. Write a one-off migration that recalculates client_scored_assessments.pct from the stored item scores for rows saved by the old mobile endpoint, so funder 'average gain' figures are consistent. Add a delete-session swipe in iOS ProgramsView and remove the unused curriculum_skills API action.

Builds on: assessment_instruments.higher_is_better, scored_pct(), api.php delete_cohort_session. Gap: G128.

#### Custom report builder

Priority: Nice to have. Size: one to two weeks.

Let staff pick a subject (clients, tracks, hours, goals, referrals, sessions, loans, AT records), filters and a grouping, save the definition, and download it as CSV or accessible HTML. This first needs report_table() in inc/access.php extended to limit those extra tables to each user's caseload. Do the list exports item first, since it answers most one-off funder questions more cheaply.

Builds on: report_table(), the CSV helpers, the list exports item. Gap: G85.

### Running the system safely: backups, updates, settings and staff accounts

So far every deployment has been done by hand with technical help, and several settings can only be changed by editing config.php on the server. A small organisation with no IT staff needs a system that protects its own data and says when something has stopped working. You, or whoever comes after you, should be able to run it without the developer.

#### Daily off-site backups and a tested restore

Priority: Needed to call the system complete. Size: a few days.

Send the database copy and new or changed uploaded documents off the server every day, not only weekly: today cron/backup.sh archives uploads only on Sundays and cron/weekly_offsite.php sends only on Mondays. Write a restore guide that works well with a screen reader, plus a script that decrypts the backup, installs a fresh copy, imports the data, puts documents back, and recreates config.php and the other private files, including the Microsoft Graph secret. Run a scheduled test restore into a scratch database and record the result. Have cron/watchdog.php alert when the uploads archive or the last off-site send is too old, and add guidance on how long to keep backup emails.

Builds on: cron/backup.sh, the AES-256 ZIP from cron/weekly_offsite.php, cron/watchdog.php, dist/install.php. Gaps: G86, G35.

**Decision needed:** Does your web host already keep daily off-site backups, and where should the daily copy go: the backup mailbox, or a OneDrive or SharePoint folder?

#### System status page and stopped-job alerts

Priority: Clear value. Size: a few days.

Have each cron job (daily_alerts, session_reminders, monthly_reports, at_reminders, ingest_email, weekly_offsite, backup.sh) record when it last ran and whether it succeeded, and have cron/watchdog.php alert when one is overdue. Add an administrator 'System status' section showing:
- the last backup and off-site send
- each job's last run
- a live Microsoft Graph check
- the Graph secret's expiry date, with warnings 30, 14 and 7 days ahead
- free disk space and the version
Add an in-app notice when email falls back from Graph to PHP mail(), and a simple log of outbound emails (type, recipient type, result, time, no content).

Builds on: cron/watchdog.php, the connection test in federation.php, the version section in account.php. Gap: G87.

#### Safe upgrades from inside the app

Priority: Clear value. Size: a few days.

Store a database schema version, written by dist/install.php and by every migration, and warn administrators when the code needs a newer database than the one installed. Add an administrator-only upgrade runner that works with a screen reader: it confirms a fresh backup, applies the newer dist/migrations files in order and reports each step. Check for updates automatically every day, instead of only when the button is pressed.

Builds on: dist/migrations (tests/full_schema.php shows they are safe to re-run), the update check on account.php, APP_VERSION. Gap: G88.

#### Settings screens instead of editing config.php

Priority: Clear value. Size: a few days.

Add administrator forms, with a test button where useful, for the Microsoft Graph mail credentials and sender, session and sign-in code timeouts, time zone, the from, support and backup email addresses, and branding. Nobody then has to edit config.php through the hosting file manager. Make the PDF generators use the brand colour instead of fixed navy. Making the iPhone app usable by other organisations is a later, separate step.

Builds on: the settings forms in federation.php and ai.php, settings table, brand(), dist/install.php. Gap: G89.

#### Staff accounts: invitations, least access, training records and departures

Priority: Clear value. Size: a few days.

Replace the typed temporary password on account.php with an emailed invitation link. Choose the permission group when the account is created; today a new account sees every client until a group is set (resolve_perms in inc/core.php). Flag accounts not used for a set number of days, and record each staff member's confidentiality agreement and privacy training with renewal dates on users.php, compliance.php and the daily alerts. Add a nightly Microsoft 365 check that deactivates CMS accounts disabled there, and an offboarding checklist that also reassigns AT records, equipment reservations, cohorts and access bookings.

Builds on: add_user on account.php, the caseload transfer and reset link on users.php, the credentials in graph_mail.php, groups. Gaps: G40, G96.

**Decision needed:** Should staff who leave be switched off automatically when their Microsoft 365 account is disabled?

#### Built-in how-to guides for staff and administrators

Priority: Clear value. Size: a few days.

Add staff procedures to help.php, or as starter knowledge base articles shipped in a migration: the billing cycle, entering an authorization, sending reports, and handling client requests and messages. Include one screen-reader and keyboard quick reference and the voice phrase list from inc/voice.php. Add an administrator guide that covers:
- the cron table
- renewing the Graph secret
- restoring from backup
- staff joining and leaving
- fixing a misfiled email
- designing permission groups
- turning on client homework AI safely
- what to do after a suspected privacy incident

Builds on: help.php, kb.php and kb_articles, the voice help text, the notes in WORKFLOWS-3.0.16.md. Gaps: G53, G99.

**Decision needed:** Would you like to review draft procedures written from how the system works, and add your own office practices?

#### Import clients and open authorizations from a spreadsheet

Priority: Nice to have. Size: a few days.

Reuse the equipment_import.php pattern (template download, dry run, encoding detection, checks on every row) to import clients with their counselor and guardian contacts. The import matches existing clients to avoid duplicates and marks imported counselor emails as unconfirmed. Import open authorizations as training tracks, with strict checks on hours, rates and dates because they feed invoices.

Builds on: equipment_import.php, the checks in client_edit.php and track_edit.php. Gap: G95.

**Decision needed:** Do you plan to offer this system to other organisations? If so, this becomes a should; for your own caseload, which is already entered, it can wait.

## Appendix: every verified gap

This is the raw list behind the roadmap. Each entry was checked against the code by a reviewer who tried to show the feature already existed. "Partial" means something exists and the reviewer named the missing part.

- **Billing does not check the authorization: hours or invoices outside the authorized dates, hours or funds go through with no warning or recorded override** (partial; must, size M; area: Authorization burn-down / billing controls). Missing: 1. Logging on web, the iOS API and auto_log_session has no check that work_date falls within start_date..expiration_date, that hours stay within authorized_hours, or that funds stay within the authorized amount, and no track-status check. Voice already refuses non-Active tracks but not the other three. Nothing warns, and nothing asks for a confirmation with a reason.
2. generate_invoice and the Submit transition do not flag lines dated outside the authorization or beyond its hours or funds. Nothing requires an override with a recorded reason, as schedule.php already does for bookings (there without the reason text).
3. The invoices.php batch includes Cancelled, Completed and expired tracks.
4. track_rollup clamps remaining hours and funds at 0 and the percentage at 100%. The track page, the invoice PDF and HTML statement, reports, the export and the VRC view show "0 remaining / 100%" instead of a signed overrun ("12.00 hrs / $780 over"). A funds overrun is not visible anywhere.
5. Not part of the claim but related: the optional AT Services funding record has a self-attested "Ready to bill" checklist, but it is not linked to invoice generation, and no pre-invoice check exists for log signatures or case notes. Treat this as a smaller follow-on rather than part of the must-have.
- **Consent tracking is guardian-only: no adult consent-to-services, no recipient-specific release of information, and no disclosure checks** (partial; must, size M; area: Eligibility and consent). Missing: 1. A consent and release record signed by the client themself. It should either generalise consent_forms (signer role self or guardian, nullable guardian fields) or add a releases table with: named recipient (VR counselor and agency, employer, physician, school, other), information scope, purpose, effective date, expiry date, and revocation (reuse the withdrawn_at pattern). Signing should use an adult version of the consent.php typed-name e-sign, in the client's own accessible format and in Spanish.
2. The Compliance 'Consents & releases' card (compliance.php:106-143) should cover all open clients. Today it lists only clients who have a guardian consent row.
3. Before sending, check for (or at least warn about) a current release covering the recipient in: report.php send (lines 67-83), api.php send_report, the eval and AT reports, and the employer recommendation report (accommodations.php:116-164).
4. A telepractice consent and a session-recording consent, linked to remote sessions in schedule.php.
5. An admin control for consent_validity_days, for example beside retention_years in account.php. Today it is only read, with a default of 365.
6. Showing consent status in the iOS app.
Already present and not part of the gap: guardian consent with e-sign, withdrawal and renewal tracking, and uploading a scanned document under the 'Release' category.
- **Invoices have no provider address/contact, VR vendor number, tax ID, payment terms or due date, and Drafts cannot carry a memo/PO** (missing; must, size S; area: Invoice document (invoice_pdf.php)). Missing: The invoice letterhead (invoice_pdf.php:96, 209-215) shows only brand('org') and brand('tagline'). It has no block for the provider's own details: no remit-to or mailing address, phone, billing email (support_email is never printed), VR vendor/provider number, EIN/tax ID, payment terms or due date. No config, settings or schema field exists for any of these, and brand() and install.php have only display keys. invoices.notes (schema_full.sql:714) is never written or printed, so a Draft cannot hold a memo or PO number. Draft lines are locked (track.php:244, 263; api.php:1278), so the only way to correct one is to void the Draft and regenerate it (invoices.php:57-97). The QuickBooks CSV hard-codes DueDate as submitted + 30 days (invoices.php:180) instead of using a terms setting. Fix: add billing settings (vendor number, remit-to, EIN, terms days, billing contact), print them in both the PDF and the HTML version, add a memo/PO field on Drafts that saves to invoices.notes, and calculate the due date from terms in both the invoice and the CSV.
- **A submitted or short-paid invoice can't be denied, voided, written off or rebilled** (partial; must, size M; area: Invoice lifecycle). Missing: invoices.status allows only Draft, Submitted and Paid, and the only void is for a Draft (invoices.php:57-98). A Submitted invoice that VR rejects cannot be marked Denied or Returned or be voided, and its billed work logs and expenses cannot be released for a corrected invoice: they stay locked (track.php:244/263), with no link from the original to the replacement. A short-paid balance cannot be written off or adjusted. A Denied or Appeal funding record never changes the invoice. As a result, denied invoices and short-paid remainders stay in Outstanding/aging (invoices.php:224-229) for good, and the only fix is editing the database directly.
- **Short-paid balances can never be closed, and payment and submission dates are always set to today** (partial; must, size M; area: Invoices / receivables (invoices.php)). Missing: Once an invoice is marked Paid, it is final:
- No later payment can be recorded against it.
- It cannot be written off or adjusted.
- It cannot be reopened.

So any short-paid balance stays in Outstanding permanently (invoices.php:142, :221-230). submitted_at and paid_at are always set to today (invoices.php:104, :142), and those wrong dates go out in the QuickBooks CSV as PaidDate and DueDate (:180, :185). No report shows cash received by period, because analytics.php:56 groups by status only.

Must-level fix: a payments ledger, or at least a dated follow-on payment or write-off on Paid/short-paid invoices, plus entry fields for the actual submission and deposit dates.

Should-level addition: one deposit applied across several invoices, with a check that the parts add up to the EFT total. Today staff can already enter the same EFT reference on each invoice one at a time and find them all via search.php:65.
- **Off-site copies of the database and documents are weekly only, and restore is unscripted, undocumented and untested** (partial; must, size M; area: Operations / backup and disaster recovery). Missing: 1) Daily off-site copy of the DB dump and of new or changed uploads. Today both leave the server only on Monday. The nightly dumps and the Sunday uploads tar stay on the same server, so losing the server loses up to about 7 days of records and signed documents.
2) A screen-reader-friendly restore runbook plus a script that:
   - decrypts the AES ZIP;
   - runs a fresh install, or creates an empty DB;
   - imports the dump and puts uploads back in <private>/uploads;
   - recreates config.php, config_path.php, cron_config.php, backup.conf and db.cnf;
   - re-enters the AI key and Entra secret, and regenerates the Graph client_secret.
   Today the only guidance is three lines in the off-site email and the README.
3) A scheduled test restore into a scratch DB, with the date and result recorded and failures alerted.
4) Watchdog freshness checks for the uploads archive and for the last successful off-site send. That means writing a success marker, because a cron job that never runs is silent today; the existing FAILED email only covers sends that start and then fail.
5) Recovery guidance for the Graph client_secret and the other private config values. The backup_password already has a "store in your password manager" step.
- **Referrals cannot be closed with a disposition and reason, and referral timeliness is not measured** (partial; must, size M; area: Referral intake). Missing: Referrals have no end state other than Converted, so a declined, withdrawn, unreachable, referred-elsewhere, duplicate or spam referral can never leave the open queue. It stays in referrals.php, pipeline.php, the dashboard and the API/iOS lists, and only a client purge deletes referrals. Needed:
- a disposition with a reason and a closed date, on web, API and iOS, that also closes the ref-contact task. The waitlist 'Closed' status currently does neither.
- a contact-attempt log and a first-contact date on the referral. Today the only trace is done_at on the 3-day contact task.
- an optional decline or acknowledgement notice to a confirmed referring counselor.
- a simple timeliness report (received to first contact, to assessment, to first session) to check the 3-business-day promise.
Already present, so not part of the gap: overdue first-contact tasks are flagged in the daily digest and action center, and the pipeline shows days since a referral arrived.
- **Referrals for existing clients ('Additional service', 'Plan amendment', re-referral) cannot be attached to the existing client record** (partial; must, size M; area: Referral intake / re-referral). Missing: Referral intake has no way to resolve a referral against a client who already exists. The missing piece is an 'Attach to existing client' action, on the web (referrals.php) and in the API/iOS (convert_referral or a new case). It would:
- set referrals.client_id to the chosen existing client and close the referral out of the queue (a Linked/Converted status);
- add the intake note to that client;
- optionally reopen a closed or archived client (Pending Assessment) and record a status-history entry noting the referral number;
- complete the ref-contact task and add a follow-up task. For a Plan amendment referral, the task should point at the client's active track (track_edit.php or amendment_request.php) so the change is recorded in auth_amendments.
Also needed: a 'Closed/Declined' disposition on referrals.php (the waitlist 'Closed' status is ignored by referrals.php, pipeline.php and index.php). A per-episode referral date (an episode entity, or a referral_id on training_tracks) is a separate, lower-priority 'could' item. Reopening clients, adding tracks and recording authorization amendments already exist.
- **Referrals have no decline outcome, and referrers get no reference number, acknowledgement or outcome notice** (missing; must, size M; area: Referral intake feedback loop). Missing: Must (the core):
(1) referrals.status needs a terminal Declined / Not eligible / Withdrawn state with a required reason, available on referrals.php, api.php and the iOS app. Today the only close is the waitlist Closed status, which has no reason, hides the referral only from action items, and leaves it Open on referrals.php for good.
(2) The confirmation page should show a reference number, and an acknowledgement email should go to the referrer's address. Neither exists, so the referrer has no number to quote.
(3) A minimal outcome email (accepted / scheduled / declined with a generic reason) should go to the referring VR counselor. Nothing in assign, review, convert, waitlist or cron emails the referrer.

Should or could:
- Pending and waitlisted referrals in vrc.php, which today lists only verified, converted clients.
- Status notices to medical, school and family referrers, gated by consent.

PHI constraint: automated messages to the typed-in referrer address must carry the reference number and generic status only, with no client name or clinical detail.
- **No billing mode for free (no-charge) or private-pay training: every track and every logged hour is treated as VR-authorized and invoiceable** (missing; must, size L; area: Unbillable / pro-bono / self-pay work). Missing: Tracks need a billing mode: VR-authorized, private pay, or no charge.

- **Validation.** Authorization number and funding agency should be required only for VR-authorized tracks. A no-charge track should allow a $0 rate, and hours should log on it. The rate rule is enforced in six places, and all need the change: track.php:132, core.php:1221, api.php:814, 937 and 950, and voice.php.
- **Invoicing.** The batch generator should skip no-charge work. Private-pay invoices or statements should be billed to the client, not to the counselor (core.php:979-984).
- **Reports.** Agency, RSA and analytics reports should separate or exclude non-VR money. Outcomes and utilization should still count the hours.
- **Referral conversion.** Converting a referral should carry quoted_fee and income_bracket into the new client or track.
- **Fee settings.** The fee schedule ($2,000 a month threshold, $17.50 an hour) is fixed in community_referral.php and lang_es.php and should become a setting.
- **iOS.** The app needs the same changes.

What already exists:

- Quoted-fee display on referrals.
- Completing a session without a track, though it logs no hours.
- Pre-ETS cohort sessions, which record group time without billing.
- **No admin UI for Graph mail, core app config or consent validity; PDFs and the iOS app are not white-label ready** (partial; should, size L; area: Administration / configuration and white-label). Missing: Branding and mail addresses are entered once at install. After that, these have no admin UI and need config.php edited by hand:
(a) Graph tenant, client, secret and sender, which email ingest and large off-site backups depend on. These need a save form with test checks, like the one on federation.php.
(b) Session and OTP timeouts, timezone, mail_from, support_email and backup_email.
(c) Branding changes.

Separately:
(d) consent_validity_days is read in compliance.php and client.php, but nothing writes it, so it is always 365. A form for it would take under a day (S) and is the quick win.
(e) The five PDF generators hard-code navy (30, 58, 138) instead of brand('color_dark').
(f) There are no provider address, phone, logo, VR vendor number or tax ID fields for the invoice letterhead. That could matter for getting VR invoices accepted.
(g) The iOS app hard-codes the production server URL and its display name, so another install needs a rebuild to use it. The Keychain service name is not a real blocker.

For Example Agency's own single install, (a), (b) and (d) matter most because the owner is blind. (e) and (g) matter only for white-label resale (could).
- **New staff get an admin-typed password and broad legacy access, and a Microsoft 365 exit does not end CMS access** (partial; should, size M; area: Administration / staff lifecycle). Missing: 1) Creating an account should send an invitation link so the person sets their own password, instead of the admin typing a visible temporary password (account.php:521). Today the workaround is the separate 30-minute "Email password reset link" in users.php. There is also no forced password change at first sign-in. 2) The permission group should be chosen when the account is created, defaulting to least privilege. Today new accounts get unscoped legacy access to all clients (core.php:122) until someone assigns a group. 3) Entra lifecycle sync: a cron Graph check of accountEnabled that deactivates the CMS account and clears passkeys, TOTP, api_tokens and cal_token, plus an optional "Microsoft sign-in only" policy for linked staff. Today passkey sign-in, authenticator-app sign-in, the iOS token (30 days) and the calendar feed (no expiry) keep working after an M365-only exit. 4) Short onboarding and offboarding checklists. The offboarding one should list the person's open AT records (staff_id), equipment reservations, cohorts they coordinate and access bookings, and offer to reassign them alongside the caseload transfer. Not missing: instructor matching already skips inactive staff, and AT reminders already fall back to admins.
- **Receivables lack aging buckets, automatic overdue/unbilled-age alerts and an invoice-level reconciliation report** (partial; should, size M; area: Aging / receivables / reconciliation). Missing: What exists: days outstanding on each invoice row, the oldest days per agency, short-paid balances, a Submitted filter, and a per-invoice QuickBooks CSV with due date (+30), status, paid date, paid amount and payment ref. Funding records can also be dated by hand and get in-app reminders.

What is missing:
(a) 0-30/31-60/61-90/90+ buckets by agency on invoices.php, and a days-outstanding/bucket column in the CSV, with agency and date filters.
(b) An automatic daily_alerts.php digest or task for Submitted invoices past a configurable payment-terms threshold (today the terms are hard-coded at +30 days, and nothing in cron looks at invoices).
(c) An alert for logged work or expenses that are still unbilled N days after their work date.
(d) A reconciliation view for a date range and agency, listing invoice #, auth #, period, billed, paid, payment ref and balance by authorization. agency_report.php gives service value only, so matching against VR remittance advice now means cross-referencing it by hand with the QuickBooks CSV.
- **Instrument scoring direction cannot be set and is ignored on client trends; historical mobile pct values never recalculated; two API actions with no caller** (partial; should, size S; area: Assessments / API surface). Missing: 1) Instrument direction. The add-instrument form and its INSERT (public/templates.php:175, form at 444-465) never set higher_is_better, and no edit form exists, so an instrument scored as "higher = worse" (for example a difficulty or burden rating scale; timed or error-count measures don't fit the integer -10..10 item model) can only be configured in SQL. If it is created through the form, funder_report.php reports its gains with the wrong sign. The client trend badge (client.php:1164-1173) ignores direction even when the flag is set. 2) assessment_instruments.category is seeded but never read anywhere. Either show it (for example to group instruments in the picker or on funder packs) or drop it, rather than only adding it to the form. 3) No migration or script recalculates client_scored_assessments.pct for rows saved by the pre-fix mobile endpoint (REVIEW-2026-10-02.md:41). mig_2.46.0 backfilled only 0-based scales with pct=0, so funder "average gain" can mix old and new percentages. A one-off migration can recompute pct from client_scored_assessment_items and the instrument's scale_min/scale_max using the same formula as scored_pct(). 4) api.php delete_cohort_session has no iOS control: ProgramsView "Recent sessions" is read-only, so a group session logged by mistake on the phone must be deleted on the web. Add a swipe action with a confirmation that calls it. 5) api.php curriculum_skills has no caller and duplicates client_competency, so remove it.
- **Who-viewed logging is missing: record reads, API/iOS reads, portal visits and bulk report exports are not audited** (partial; should, size M; area: Audit and access logging (audit.php, api.php, vrc.php, portal.php, report exports)). Missing: Missing from the audit trail:
- Opening a client or track record on the web (GET on client.php and track.php), and searches (search.php, API search).
- API/iOS read actions: client, tracks, track_notes, documents, assessments and the other list/detail cases.
- Client or guardian portal visits. Only portal_tokens.last_used_at is overwritten, and nothing displays it.
- Counselor portal sessions per client. Only vrc_tokens.last_used_at per counselor is kept.
- Cron-emailed monthly progress reports. These are recorded in report_acks, not audit_log.
- Bulk PHI exports: export.php, rsa_report.php, agency_report.php, funder_report.php, utilization.php, outcomes.php and invoice_pdf.php.

Not missing: a per-client disclosure history already exists through audit.php's client filter. It covers document and signature views, evaluation report download and send, progress report send, portal link sent, consent request, client export, AT comparison report and worksite report.
- **Purging a client hard-deletes its invoices, including unpaid ones, with no de-identified financial record kept** (partial; should, size M; area: Audit trail / record retention for money). Missing: What is missing:
- A purge check that refuses, or warns, while any invoice is still Submitted (unpaid).
- A way for invoice, payment, expense and amendment rows to survive a purge without client details, the way incidents do (invoice number, dates, amounts, agency, authorisation number, payment reference). Today the cascade-on-delete links make that impossible, so prior-period revenue, aging, agency/RSA figures and the QuickBooks export change after a purge.
- Invoice totals and counts in the client.purge audit entry.
- Any check that the export ZIP (which carries invoices only when the admin has billing permission) was actually made before purge.
- An action or entity filter on the audit log, and a financial-changes view that billing staff can open, since today the page is admin-only.
- A way to find invoice audit entries by client once the invoice row is gone, whether it was purged or a voided draft.

Note: the retention period is not the only path to purge. The voice archive_client command can archive a closed case immediately, so it can be purged the same day.
- **Amendment requests can be drafted but not logged as pending with a follow-up and an outcome** (partial; should, size M; area: Authorization tracking). Missing: amendment_request.php, API case 'amendment' and iOS AmendmentView need a "Record request as sent" step. It would store against the track:
- the date sent and who it was sent to
- the requested hours, funds and/or new end date
- the final letter text, saved to the case file as a track case note or document
It would also open a follow-up task (for example in 7-10 business days), assigned to the client's worker. Staff could then record the outcome (approved, partially approved, denied) with the response date. An approval would carry into track_edit.php, so the auth_amendments row links back to the request. The track page, Compliance page and dashboard should show "amendment requested on <date>, awaiting reply", and the daily alert text should mention it. This is a single flow anyone with the clients permission can use. Today staff can only piece it together by hand from a billing-only AT 'Authorization to payment' record (money only, no partial approval), a manual task and a pasted case note. Out of scope, because it already exists: telling staff that services must pause at expiry. The track banner, the dashboard expired list, Compliance and the booking acknowledgement already cover it.
- **No payment ledger or remittance posting for invoices, and no multi-select bulk actions for month-end and inbox queues** (missing; should, size L; area: Billing, schedule, tasks, client requests). Missing: There are two separate gaps.

(1) Billing correctness, which is the weightier gap. Each invoice can hold only one payment: invoices.php:141 records paid_amount and moves the invoice to Paid in the same step. So a short-paid VR invoice can never take its catch-up payment, and its balance stays in Outstanding permanently. There is also no way to post one VR remittance or EFT across many invoices.
Fixing this needs:
- an invoice_payments table and migration;
- a per-invoice "record another payment" path;
- a remittance form that applies one reference and amounts across selected invoices;
- updates to every reader of paid_amount: the aging block, the QuickBooks CSV, analytics.php:56 and inc/at_services.php:375 and :395.

(2) Accessibility and throughput. There is no checkbox-plus-one-submit for any of these:
- marking several Draft invoices Submitted;
- recording outcomes on schedule.php's ended-sessions list (and a single save there also returns focus to the top of the page);
- completing or reassigning tasks (tasks cannot be reassigned even one at a time; only an admin caseload transfer moves them);
- setting client requests to Done or Declined.

Tasks already has a focus-next mitigation; the schedule and requests pages do not.

The existing bulk paths are batch_generate, mark-all-read, complete_group, caseload transfer and the equipment CSV import.
- **Portal links expire 180 days after they are sent, even when in use, with no warning to anyone and no self-service renewal** (partial; should, size S; area: Client and counselor portal access). Missing: (1) Expiry is fixed from the date sent: portal.php:88 and vrc.php:170 update only last_used_at, so active users are still cut off on day 180. (2) cron/daily_alerts.php has no portal_tokens or vrc_tokens check, so neither staff nor the client/counselor gets a warning before expiry, and there is no dashboard list of expiring links. (3) The portal_link email (inc/core.php:289-291) and the portal never state the expiry date. (4) The client dead-link page (portal.php:505) does not mention expiry and gives no contact details (vrc.php:36-40 does give a mailto). (5) There is no 'email me a new link' form sending only to the address on file, and no code-to-email sign-in for clients. (6) The API/iOS app cannot send a client portal link (api.php:3227), only counselor links.
- **Automated client contact ignores the recorded contact preference, and nothing flags clients who must be phoned or mailed** (partial; should, size M; area: Client communications / accessibility). Missing: 1. cron/session_reminders.php should read a11y_contact and phone. It should skip the email for Phone and Postal clients and give the instructor or case worker a "call/mail these clients" list. That list should cover Phone and Postal clients, clients with no email or an invalid one, and clients who prefer the portal. Today the staff email mentions a missed reminder only for closed clients.
2. send_outcome_survey (inc/core.php:921) and the portal-link send (client.php:476) should check a11y_contact. They should show staff the client's preferred contact method and format on the button and in the confirmation, and warn when the preference is not Email. The same is needed in the API, iOS and voice paths.
3. Link remediate.php?client=N from the client page, so the existing format-targeting tool can be reached.
4. Optional: seed the portal's low-vision text size or palette from a11y_format (for example, start Large print clients at 150%).
5. Lower priority (could, L): a large-print print stylesheet for AccessibleDocument output, and a BRF export via liblouis.
- **Staff replies and request decisions send no notice, and declined requests carry no reason** (missing; should, size M; area: Client portal / counselor portal / messaging). Missing: Four things are missing:
(1) A PHI-free "You have a new message from {org_short}" email with the portal link, sent when staff post on either channel from messages.php or api.php post_message. It should be throttled to one email per thread per day or so, and skipped when the recipient has no email address or no live portal link.
(2) A staff response or reason column on portal_requests. Staff set it on portal_requests.php, and the client sees it on portal.php next to the status, especially for Declined ('Not possible').
(3) An email to the client when a request moves to Done or Declined.
(4) New EMAIL_TEMPLATES entries for these emails, which appear on email_templates.php automatically, plus EMAIL_TEMPLATE_REQUIRED entries for {link}.

The sending, template and editing infrastructure already exists and only needs reusing. The api.php post_message action needs the same message notice, and the API has no portal_requests action at all.
- **Client portal shows only current items: no session history, hours used, finished work, past tracks, shared reports or completion summary** (partial; should, size M; area: Client portal content). Missing: The portal has no past sessions or attendance and shows no hours used or remaining (authorized_hours is never shown). Homework drops off once staff mark it Completed. Completed or cancelled tracks and their goals drop off, though achieved goals on active tracks still show. The client never sees their own earlier practice logs or practice updates again. Progress and evaluation reports go only to the counselor, and the client sees them only if staff manually upload a copy as 'Resource'. There is no completion summary or certificate when training ends. Homework practice reports have no staff feedback field at all, so the only way to reply is the message thread. AT learning items are already fine: completed items and their feedback stay visible.
- **Portal has no device view: loans, due dates, setup instructions, repair progress and outcome self-report are staff-only** (partial; should, size M; area: Client portal — devices). Missing: A read-only 'My devices' card on the portal, listing:
- the client's open equipment_loans: name, loaned_at, due_date and return instructions;
- a shared view of each Active setup record's setup, recovery and training instructions. This needs a per-record 'share with client' flag, because staff-entered settings text may hold PHI.
- the client-facing state of open repair records and any linked loaner.

Due-soon and overdue loan reminders to the client, by email or portal notice. Today only staff are reminded.

A token-protected self-report form for the 30/60/90-day post-delivery outcome checks (setup outcome records and equipment_followups). The client's answers would be stored as client-reported, kept separate from staff completion, as the learning workflow already does.

Not missing:
- Device help requests already show their status to the client.
- Staff can already share written instructions by uploading a Resource document or assigning a Learning library item. They just have to copy the text across by hand.
- **Guardian details can't be edited or seen on the client record, and minors with no consent are never flagged** (partial; should, size M; area: Client record (client_edit.php, client.php, portal, iOS ClientsView)). Missing: Core slice, M effort, which I would treat as close to must for Pre-ETS work:
(1) Add guardian name, phone and email to client_edit.php, to the api.php client create/update and to the iOS ClientEditSheet. Today they can be changed only by sending a consent email (client.php:551), and guardian_phone can never be changed after referral conversion.
(2) Show the guardian name, phone and email, plus consent status, on client.php and iOS ClientsView.
(3) Work out minor status from DOB. List open Pre-ETS or under-18 clients who have no signed consent and no pending one on the Compliance page; today compliance.php:124 drops anyone with no consent row.
(4) Add a daily_alerts warning when a client with a guardian on file turns 18, so consent authority is moved to the student.

Later, could priority:
- A relationship or authority type (parent of a minor, court-appointed guardian, POA, authorized representative).
- Optional guardian copies of reminders, portal links and surveys.
- A FERPA or school-source re-disclosure flag on referrals from schools.
- An adult self-consent version of consent.php.
- **No case-event timeline or length-of-stay summary on the client record (only an admin-only per-client audit log)** (partial; should, size M; area: Client record / longitudinal view). Missing: Missing: a timeline view on client.php, open to staff who can see the client. It would list case events by the date each happened, under headings by date: the referral and intake, status changes, sessions held, cancelled or missed, goal progress entries, scored-assessment results, equipment loans and returns, setups and 30/60/90-day follow-ups, reports sent and acknowledged, consents signed or withdrawn, incidents, portal requests, the survey, and closure. Each entry would link to its record. Also missing is a per-client summary of days spent in each status and total length of stay, from client_status_history. What exists already: audit.php?client=N, but it is admin-only, not linked from the client record, and lists system actions rather than case events. pipeline.php shows days in the current stage only, across all clients. The new view could build on the child-table mapping in audit.php:39-49 and on the stage_since logic in pipeline.php.
- **Records-copy and amendment requests are not tracked, there is no client-safe way to deliver an export, and note corrections overwrite the original** (partial; should, size M; area: Client rights (portal.php, portal_requests.php, client_export.php)). Missing: 1. Add 'records_copy' and 'amendment' request types, in both PREQ_TYPES and the portal whitelist at portal.php:201. Requests can be filed by clients now only as 'other'.
2. Give these requests a due date that defaults to 30 days, plus fields for the requester (client, guardian or representative), how the copy was delivered, the outcome (granted, partly granted or denied), a reason for any denial, and the response letter.
3. Build a client-copy version of the export that leaves out internal items such as documentation_reviews and billing-only records.
4. Let staff publish that export to the client's portal (today only a PDF can be shared as a Resource; a ZIP is refused).
5. Record amendments as linked addenda that keep the original note. Today, editing a note overwrites it and the audit entry keeps only the subject.

Already present, so not part of the gap: the staff export (PDF, accessible HTML, JSON and documents) with an audit entry; the generic request queue with received date, handler and Done/Declined status; and clients correcting their own contact details, with the old values kept in a case note.
- **No offer-and-accept rescheduling: staff cannot send times that a client confirms in the portal** (missing; should, size M; area: Client self-service scheduling). Missing: Recommended scope (should/M): on a portal request, staff pick two or three candidate times. The authorization check (workflow_authorization) runs on each one, plus a new check that the instructor is not already booked then. The client sees those times in the portal as an accessible radio list with one Confirm button. Confirming books the session automatically: it creates the scheduled_sessions row, cancels the original session for a reschedule, sends the calendar invite and closes the preq task. Requests that expire or are declined come back to staff. This also needs two things that do not exist yet: a way to move or reschedule a session in place (nothing updates start_at today) and a check that an instructor is not double-booked. Full self-scheduling is a separate could/L item: structured weekly availability for each instructor, with clients browsing open slots on the bearer-link portal. It is lower value for a small practice and puts staff calendars and booking on a link that has no password.
- **Case note edits overwrite the earlier text, with no revision history, addendum type or edit window** (missing; should, size M; area: Clinical documentation integrity (client.php, api.php update_note)). Missing: Missing:
- Earlier text: when the author or an admin edits a client_note (client.php:296, api.php:744), the earlier subject, body and BIRP sections are lost. The audit log keeps only the new subject.
- Addendum type: there is no Addendum or Late Entry note type, so a correction cannot be added beside the original.
- Edit window: no optional setting locks notes after N days.
- Viewing and export: no screen shows earlier versions, and client_export has none to include.

Not part of the gap:
- Billed hours and VR progress reports are built from work_logs, whose descriptions cannot be edited and which cannot be deleted once billed.
- Note edits are already shown visibly with the editor's name and time.
- Notes cannot be deleted by staff.
- The review queue's fingerprint already detects a reviewed note that changed.

A related but separate gap: the text of each emailed progress report is not archived. Only an "emailed to X" note is saved.
- **Case closure has no structured reason or outcome detail, and no closure checklist for open tracks, loans, seats and sessions** (partial; should, size M; area: Closure and outcomes). Missing: Still missing:
- A required, coded closure reason, at least for 'Closed - Other'. Example codes: declined services, unable to contact, health, moved, transferred, services completed with no employment goal.
- An optional structured outcome at closure: employed yes/no, employer, job title, start date, hours, wage, AT used on the job. For clients with no employment goal, an independence-style outcome.
- These fields in the outcomes, funder and RSA exports, so the RSA Employment Outcome column carries more than Y or blank.
- A closure checklist, shown on the web form, the mobile API/iOS and voice. On closing, it should list or offer to resolve the client's Active tracks, unbilled work or expenses, open equipment loans, assigned license seats, future Scheduled sessions and any final progress report not yet sent.

Not missing:
- Closure date. It is recorded in client_status_history.changed_at and exported as "Closed Date".
- Unbilled work on closed clients. Batch invoicing still picks it up.
- **The counselor portal can be checked, but nothing is sent to counselors: no attendance, status-change or authorization notices, and no closure report** (partial; should, size M; area: Counselor notifications). Missing: A counselor can already see case status (including On Hold and Closed), authorization expiry and hours remaining in the counselor portal (vrc.php:291, 303-304). Staff can also send a report by hand (report.php) or draft an amendment letter (amendment_request.php). Still missing:
(1) Attendance in any form. No-shows and cancellations never reach the counselor: no email, no portal entry, nothing in progress reports.
(2) A notice to the confirmed counselor when status changes to On Hold or Closed, from web, mobile or voice.
(3) Authorization alerts for the counselor at the thresholds daily_alerts.php already uses (30/14/7 days and 80% of hours or funds), pointing to the portal so they can request or approve an amendment.
(4) A closure or final report sent when a case closes. monthly_reports.php stops at closure.
Any notices should be optional and only go to confirmed counselor emails (counselor_verified). Because this is PHI, they should carry the minimum necessary, ideally a portal link rather than case details. The portal's message thread could carry the details, with an email nudge.
- **Counselor portal cannot re-open reports, show attendance, confirm receipt or accept authorization uploads** (partial; should, size M; area: Counselor portal (vrc.php)). Missing: On vrc.php a counselor cannot open or re-download a listed report. report_acks stores no content, but build_track_report() could render it again for the counselor's verified clients. There is no list of sessions with Completed, Cancelled or No Show status, and those statuses are not in emailed reports either. Goal text and the session log are emailed monthly (report_builder.php:162-178, monthly_reports.php) but cannot be viewed in the portal. Receipt cannot be confirmed in the portal, only through the ack.php email link. Counselors cannot upload an authorization, amendment or IPE, even though client_documents has Authorization and IPE categories and portal.php already has upload code that could be reused. cron/ingest_email.php ignores attachments, so emailed authorizations have to be filed by hand. The portal does not show the counselor's pending referrals or applied authorization amendments (auth_amendments). There is no help section for vrc.php, either in the layout.php:269 helpSection map or in help.php. Remove "view of drafted amendment requests" from the claim: amendment_request.php only builds a mailto: draft and never stores it, so there is nothing to show. Most valuable for this organisation: authorization upload (portal upload and/or ingesting email attachments) and opening reports in the portal. The attendance list, in-portal receipt confirmation and help section are small additions.
- **Bulk import exists only for equipment: no CSV import of clients (with counselor/guardian contacts) or open authorizations/tracks** (missing; should, size M; area: Data onboarding / import). Missing: CSV import with template, dry run, encoding detection and per-row validation (the equipment_import.php pattern) for:
(a) clients, including the counselor_name/email/phone/agency and guardian_* columns. Imported counselor emails should arrive as counselor_verified=0, as public referrals do, and rows should be matched against existing clients by name and DOB or email to avoid duplicates.
(b) open authorizations as training tracks: client match, auth_number, agency, authorized hours, rate and start/end dates, strictly validated because they feed invoices.
Optional, lower value: upload of .md, .html or .docx files into KB articles or learning-library resources, and batch import of several articles at once. Single-article paste with "## ", "- " and "1. " formatting already works in kb.php. A separate counselor-list import is not needed, because counselors are client fields, not a table.
- **No consolidated data-quality page; no duplicate detection across the client list or when a client is created** (partial; should, size M; area: Data quality). Missing: Still missing after checking the code:
(1) Possible duplicate clients across the whole list. Match on name, date of birth, phone or email, and suggest likely pairs on client_merge.php. A duplicate warning is also needed when a client is created in client_edit.php, through the API (api.php line 1099) and by the voice add_client command (inc/voice.php line 548); today only the referral view checks.
(2) A flag for Closed or archived clients whose tracks are still Active. Today they are skipped silently, and closing a client never offers to close its tracks.
(3) A flag for Active Training clients with no active track.
(4) A flag for open clients missing date of birth, VR counselor or a recorded disability. Disability currently only shows up indirectly for Active Training clients, through doc_audit's "No tech assessment" finding.
(5) A one-off list of older work logs whose RSA category is blank. New entries always get one.
(6) Minor or Pre-ETS students with no guardian consent ever requested. This applies to students only, not to every open client.
Already covered, so drop from the claim: clients stuck in Pending Assessment, Waitlist or On Hold (pipeline.php marks them over 30 days). Unassigned clients and active-track counts can already be checked by hand with the clients.php filters.
- **Received VR authorizations never reach the client record as searchable text: email attachments are not filed, counselors cannot upload, no text is extracted, and there is no draft check of the track** (missing; should, size L; area: Document intake / authorizations). Missing: 1. cron/ingest_email.php does not request or save attachments. An authorization PDF emailed in stays only in the "Filed to CMS" mailbox folder, and the case note does not even say there was an attachment. Fix: file allowed file types into client_documents with category Authorization when the sender is the verified counselor, otherwise Other, and add an "N attachments filed" line to the note.
2. vrc.php has no upload, so a counselor cannot send an authorization or amendment through the portal.
3. Scans have no text layer. On iOS, VNDocumentCameraViewController plus VNRecognizeTextRequest could store a searchable PDF or a text sidecar. On the web, add an optional text or description column on client_documents, shown as a "Read text" view.
4. Optional: a draft prefill of auth number, hours, dates and rate from the extracted text, which staff confirm against track_edit.php. It must never auto-save, and PHI handling decides whether this uses AI.
Not missing: basic readability. QuickLook with VoiceOver text recognition on iOS and JAWS or NVDA OCR on desktop already let a blind user read a downloaded scan.
- **No consolidated administrator/operations guide shipped with the product** (partial; should, size M; area: Documentation / administrators). Missing: Individual admin screens already have task-level hints: Entra setup steps, the caseload-only checkbox, retention, user deactivation and caseload transfer, the AI disclosure and BAA reminder. The installer page, the bundle README and the weekly backup email also cover install, cron, backup and a basic restore. What's missing is one accessible guide for administrators, as a help.php admin section, seeded KB articles or an admin guide in the bundle. It should keep the cron table available after install and add the procedures found nowhere today:
- renewing the Graph mail client secret in config.php, plus Entra secret expiry monitoring
- a full restore and restore-test procedure
- an onboarding/offboarding checklist (deactivate, transfer caseload, remove Entra link and passkeys, review portal and counselor links)
- how to find and correct a misfiled ingested email (a note on the wrong client) and what to do with the Needs review (CMS) folder
- guidance on designing permission groups
- enabling client homework AI safely (currently only in the unshipped WORKFLOWS-3.0.16.md)
- what to do after a suspected PHI incident (audit log review, revoking access, rotating secrets, notification duties)
- **The client's preferred document format is recorded but never honored, and BRF, audio, large-print and tagged-PDF output do not exist** (partial; should, size L; area: Documents / client communication). Missing: Accessible HTML already exists for every generated document. What is missing:
(a) Nothing reads clients.a11y_format to choose or default the version produced, downloaded, emailed or offered in the portal. Its only reader is an AI prompt hint in remediate.php.
(b) Nothing produces Braille-ready (BRF) output, an audio (MP3) export, or a large-print layout (e.g. 18pt+ HTML print CSS or PDF) for the client-facing documents: client file export, evaluation or accommodation report copies, portal resources.
(c) AppPdf output is untagged (no StructTreeRoot or MarkInfo), so "Accessible PDF" is never produced.
(d) Portal resources and learning-library items offer no per-client format choice.

Suggested scope: honor the preference by defaulting to HTML or plain text and adding a large-print HTML stylesheet (S-M); BRF through liblouis if the host allows it (M); audio export through ai_speak with chunking (M); tagged PDF is the costly part (L+). Two of the six formats a client can pick (BRF, Audio) cannot be delivered at all, so the system either needs to produce them or stop offering them.
- **No VR closure (final) report, and no organization-editable letter templates; existing letters are fixed in code and generated documents are not filed to the record** (partial; should, size M; area: Documents / templates and mail-merge). Missing: Two gaps.

(1) Closure report for VR. When a client moves to Closed - Rehabilitated or Closed - Other, or a track is completed, nothing prompts for or produces a final report. That report would include the closure reason, the employment or skills outcome, goals achieved against target, total hours against the authorization, equipment issued and returned, and recommendations. It should go out through the existing signed email and acknowledgement flow in report.php and be logged to the record. Today the only options are the generic "Training Progress Report" with a personal message, or an AI case summary saved as a note.

(2) Letter templates the organization can edit, using {var} merge fields for client, counselor, track and authorization, extending the email_template() pattern. Examples: welcome or intake letter, appointment and no-show letters, equipment loan and return letter, referral acknowledgement to the referrer. Each would render through AccessibleDocument and AppPdf and be saved to client_documents or the notes. The amendment request and justification letters already exist but are fixed in code. client_documents only receives uploads.
- **Equipment loans record no hand-off agreement or condition, and labels cannot be scanned into the app** (partial; should, size M; area: Equipment loans). Missing: 1. Hand-off record (should):
- equipment_loans and the web and iOS loan and return flows capture no condition at check-out or check-in.
- They capture no client acknowledgment of a loan agreement (no drawn signature and no typed name) and no photo.
- The existing SignaturePad and typed-acknowledgment pattern (SignatureView.swift) is used only for work-log sign-off.
- item_condition is set only when an item is added and can never be edited afterwards, so damage found on return cannot be recorded against the item.
- There is no equipment or agreement document category, so a scanned paper agreement cannot be linked to the loan.

2. Field scanning (could):
- The iOS app has no QR or barcode scanner (no DataScannerViewController or AVCaptureMetadataOutput).
- It has no universal links: no associated-domains entitlement, no apple-app-site-association file and no onOpenURL handler.
- A label scanned with the Camera therefore opens equipment.php?scan=ID in Safari, which needs a separate web sign-in if no session exists, instead of opening the item in the app.
- **Operational lists (clients, equipment inventory, referrals, tasks, sessions and others) cannot be exported to CSV; client list filters are thin** (missing; should, size M; area: Exports). Missing: There is no CSV export for the full client list or for referrals, tasks, schedule and sessions across clients, equipment inventory and loans, device follow-ups, incidents, contracts and spend, compliance and documentation audit, pipeline, capacity, accommodations, procurement, AT services or portal requests. The closest substitute is rsa_report.php's per-client CSV, which only covers clients who had service in a chosen period, and the schedule is only available as an .ics calendar feed. The clients list is capped at 500 rows on screen. Its free-text search matches counselor name, but there is no funding agency, client type or instructor filter, and the caseload filter only offers "me" or "unassigned". Every new export must keep the existing caseload scoping and should write an audit entry, because the data is PHI.
- **No period report of under-used authorizations (authorized vs delivered/invoiced at close)** (missing; should, size M; area: Financial / authorization reporting). Missing: There is no report covering tracks that ended in a period (expired, or Completed or Cancelled). It would show authorized hours and funds against what was delivered and invoiced, and the hours and dollars left unused. It would total them and break them down by funding agency, service type or RSA category, and staff member (from work_logs.created_by). It needs a CSV and the app's API and iOS parity, as the other reports have. Tracks have no completion date (no completed_at, and status changes are only logged as track.update), so "ended in period" has to use expiration_date unless a completion timestamp is added when a track is set to Completed or Cancelled. That is part of the work. The web page alone is about S, because track_rollup() and the agency and funder report patterns can be reused. With the completion date, the API endpoint and the iOS view it is M.
- **No client-level follow-up scheduled at case closure (employment or independence retention, AT use, re-referral prompt)** (missing; should, size M; area: Follow-up). Missing: Nothing is scheduled when a case closes. Missing pieces:
- Automatic 30/60/90-day client follow-ups created when a client moves to either closed status. This must cover all three closure paths: client_edit.php:180, api.php:1132 and inc/voice.php:361.
- Structured fields on each follow-up: still employed or still independent in the trained skills, still using the AT, needs further service.
- Reporting of retention rates in outcomes.php, funder_report.php and rsa_report.php. rsa_report.php currently labels every 'Closed - Rehabilitated' an employment outcome with no verification.
- An automatic re-referral or reopen task when a follow-up shows a need.

'Closed - Other' clients get nothing at all. The existing survey goes only to 'Closed - Rehabilitated' clients and asks only satisfaction, goals met and comments.

Device-level checks already exist and should be reused, not duplicated: equipment_followups, AT 'outcome' records after a device setup, and the manual worksite-assessment follow_up_date.
- **Grant/contract ledger can't be edited and isn't scoped to services or the grant period** (partial; should, size M; area: Grant budgets and deliverables (contracts.php)). Missing: 1. A contract's name, funder, number, start and end dates and notes cannot be edited after creation, on the web or in the API, so a no-cost extension or a corrected number cannot be recorded. The API also cannot change the budget or set notes.
2. Contracts have no direct link to tracks, invoices or expenses. The only link is a JSON contract_id on an AT funding record, and only an admin-only, all-time profitability rollup uses it.
3. The contract page shows only hand-typed spend. It never shows computed actuals for the grant period (hours delivered, clients served, amount invoiced or received, direct labor and expense cost) against the budget.
4. Deliverables have no numeric targets (for example clients or hours), so no actuals can be computed against them.
5. There is no contract-scoped report of outputs for the grant period. funder_report.php works only by funding_agency text.
6. Lower priority, and probably better left to the accounting package: budget line items with budget-versus-actual by category, and drawdown or reimbursement requests with received-versus-requested tracking.
- **No staff procedures or screen-reader/voice quick reference in the app, and no starter knowledge-base content** (partial; should, size M; area: Help / onboarding). Missing: The help and KB tools work, but there is no staff content. Add the following to help.php or as starter KB articles shipped in a migration:

(1) Step-by-step staff procedures for:
- the billing cycle: add the authorization on the track, log hours, generate the invoice, mark it submitted, record the payment or short pay, and void a Draft;
- entering or renewing an authorization and reading the remaining-hours warnings;
- sending a funder or VR report;
- working through portal requests and messages.

(2) A single screen-reader and keyboard quick reference covering:
- the layout every staff page shares: skip link, navigation landmark, main region, one h1, h2 sections;
- scrollable table regions;
- Alt+L and Escape in the voice panel.

(3) The voice phrase list on help.php. The text already exists at public/inc/voice.php:772-777 and can be reused.

These procedures have to be written from scratch, because the repository Markdown is only release notes. A per-page landmark outline is not needed.
- **Incident log has no structured privacy/breach workflow (only a free-text "Other" incident tied to one client)** (partial; should, size M; area: Incident management (incidents.php, api.php incidents, IncidentsView.swift)). Missing: A breach can only be recorded as a generic "Other" incident: free text, one client, and nothing can be changed afterwards except the status. Missing are:
- privacy breach types (misdirected email or report, lost or stolen device, unauthorized access, portal link sent to the wrong person)
- a discovery date, with the 60-day individual notice deadline worked out from it, plus deadlines for the VR agency contract notice and the annual HHS log, and cron reminders for them
- a list of affected clients (several clients per incident)
- risk assessment fields: what data was involved, who received it, and whether it was retrieved
- a log of notices sent (to whom, when, how)
- dated follow-up and investigation entries, since description and action taken cannot be added to after creation
- a link from the incident to the client access history that already exists in audit.php
- for breach incidents, keeping affected-person details through a client purge instead of redacting them (client.php:179)
All of this is needed with matching api.php and IncidentsView.swift support.
- **No structured IPE employment goal, IPE dates or VR agency case ID, and no way to move a caseload to a new VR counselor** (partial; should, size M; area: Individualized plan and goals). Missing: Client-level fields for the IPE: employment goal (SOC optional), IPE date and latest amendment date, and the VR agency's case or participant ID. These should be editable on client_edit.php, intake, api.php and iOS ClientEditSheet, and appear on invoice_pdf.php, report.php and the monthly reports, rsa_report.php (alongside the internal Client ID), the eval_report.php and procurement.php justifications, and the AI draft context, which today uses fixed placeholder wording. Optionally, show the IPE goal on each track so its SMART goals read against it. The authorization number already appears on invoices and is not part of the gap. counselors.php also needs a "replace counselor" action: change the name, email, phone and agency on every client that has a given counselor_email in one step, record it in the audit log, reset counselor_verified as needed, and revoke the old portal link. Today each client has to be edited one by one.
- **Email-to-file ingest ignores attachments without saying so, so emailed or faxed authorizations and IPEs stay only in the mailbox** (missing; should, size M; area: Integrations / email ingest and document intake). Missing: 1. Save file attachments as client_documents rows. This covers PDF authorizations, IPEs, releases, referral packets and fax-to-email PDFs. Each row needs a category (default Other, or pending review), a link to the note it came from (client_documents has no note_id or source column), the same type and size limits as manual upload, and per-attachment idempotency.

2. Flag attachments in the note. Today the note does not record that the email had any: hasAttachments is not in the Graph $select. The message is also moved to "Filed to CMS" at once, so nobody is prompted to upload what came with it.

3. Add an in-app queue where staff confirm the category and client for attachments that were filed automatically or could not be matched. Today the only review queue is the "Needs review (CMS)" mailbox folder.

Manual upload already exists on web (client.php), the API and iOS, and the client portal. Staff can file attachments by hand, but it is slow and easy to miss.
- **Sessions cannot be rescheduled, clients get no calendar invites or cancellation notices, and booking does not check for double-booking** (partial; should, size M; area: Integrations / scheduling and calendar). Missing: 1) There is no edit or reschedule action for a scheduled session on the website, api.php or iOS. A client's portal reschedule request can only be handled by cancelling and rebooking, which re-runs the authorization check and loses the session's history.
2) No calendar update or cancellation goes out when a session is cancelled, marked No Show, or moved to another instructor (client_edit.php, users.php, client_merge.php). build_ics() has no METHOD:CANCEL, SEQUENCE or STATUS:CANCELLED, so the emailed invite stays in the calendar. Staff who subscribe to the calfeed are mostly covered; clients are not.
3) Clients never get a calendar invite. They get no .ics at booking, reschedule or cancellation, and the portal's Upcoming sessions list has no "Add to calendar" (.ics) link. They get only a plain-text reminder the day before, with nothing sent at booking and no notice when a session is cancelled or moved.
4) Booking does not warn when the instructor already has a CMS session at an overlapping time (schedule_input, api schedule_session, voice schedule_session).
Optional, could priority: Outlook free/busy lookup through Graph getSchedule, and creating Teams meetings through Graph onlineMeetings instead of pasting meeting_url by hand. Both need extra Graph app permissions.
- **No per-client preferred language: outbound email and token links always start in English, and staff-written Spanish is tagged as English** (partial; should, size M; area: Language access). Missing: Core (M): add preferred_language to clients, plus the guardian's language and a language captured on community referrals. Use it to set the session language when a portal, consent or survey token is opened, and add lang=es to the links in emails. Give email_template() a language argument, with Spanish defaults and per-language override keys (tpl_<key>_<lang>_subject/body) for portal_link, consent_request, survey_invite and session_reminder_client. Put the homework practice and help labels and the AI explanation heading through t()/__(), with entries in lang_es.php. Let staff mark the language of homework instructions and KB articles, or fall back to the client's language, so portal.php:645 and portal.php:863 stop forcing lang="en". Optional extra (could, roughly M more): store parallel Spanish versions of homework, goals and KB articles, add a save-to-record action on translate.php, and support languages other than Spanish in the interface.
- **No 'awaiting authorization' client stage, On Hold has no return date or check-in, and Active Training needs no active authorization** (partial; should, size M; area: Lifecycle status machine). Missing: Still missing:
(a) A client status between assessment and training, such as 'Awaiting Authorization'. It needs adding everywhere the statuses are listed: CLIENT_STATUSES, the clients.status enum, iOS clientStatuses, the voice prompt, the layout badge map, the pipeline stages and PIPELINE_WAITING_STAGES. The funder and RSA reports should also be checked. Emailing the eval report could offer to move the client to this stage and open a follow-up task with the counselor.
(b) On Hold needs an expected return or review date, stored on the client or in status history, and the hold reason should be required (today it is optional free text).
(c) A daily_alerts check-in for On Hold clients like the Waitlist one: due on the return date, or every ~3 weeks if no date is set.
(d) A warning, not a block, when a client is set to Active Training with no Active track that has an auth number. It should apply on the web, the API and voice, and doc_audit should flag the same condition.
An optional counselor notification on hold or resume is lower priority: the portal already shows status, and an outbound email would carry PHI.

Not missing: a reason field for status changes, days in stage and the over-30-day flag for On Hold on the pipeline, and authorization checks when sessions are booked.
- **Analytics page is all-time only, with no period comparisons, trends of referrals or closures, fiscal-year setting or reports hub** (partial; should, size M; area: Management dashboards / navigation). Missing: 1. A from/to (or fiscal-year/quarter/month) selector on analytics.php. Goal %, rehabilitation %, agency and invoice figures are all-time.
2. A comparison with the previous period and the same period last year for the headline figures, plus month-by-month tables for referrals received, intakes/conversions, active caseload, closures and rehabilitation rate. Today only billable value and survey NPS trend over 12 months.
3. Current open caseload by named instructor and status. Program, client-type, RSA-category and per-staff hours breakdowns already exist for a period on outcomes.php and utilization.php, so this item is narrower than claimed.
4. A CSV/accessible-HTML export of analytics.php itself. The other report pages already export.
5. An organisation fiscal-year-start setting used as the default period by outcomes, funder_report, rsa_report and agency_report. Their defaults currently differ.
6. One 'Reports' page in the main nav that links analytics, outcomes, funder pack, RSA-911, agency billing summary, utilization, capacity, risk and documentation audit. funder_report and rsa_report are reachable only from outcomes and agency_report. Voice navigation also lacks them.
7. Optional: an API action and iOS view for outcome/period summaries.
- **Billed expenses and mileage are not linked to receipts, categories or trip detail** (partial; should, size M; area: Mileage and expense policy). Missing: Receipts cannot be linked to an expense. Staff can upload a receipt to the client's documents (web and iOS camera), but only under 'Other': there is no 'Receipt' category, and track_expenses has no document_id, so nothing ties the receipt to the expense or the invoice line. Track expenses have no category or type (materials, travel, interpreter, etc.). Billed mileage has no from/to locations or trip detail beyond the work log's activity description. Nothing separates staff-reimbursable costs from funder-billable ones: every track expense is billed. Pre-approval exists only for device purchases (procurement.php: funder authorization number and amount, quotes, PO), not for general track expenses. General expenses are only counted against the authorization's remaining funds after they are logged.
- **No immediate out-of-app alert for time-sensitive portal events, no notifications API or iOS alerts list, and no per-user delivery preference** (partial; should, size M; area: Notifications). Missing: What needs building:
1. An optional email from notify_user for time-sensitive events. These are portal cancel and reschedule requests, portal messages, and 'Need help' practice reports. The email should carry no PHI: a generic subject and a sign-in link. A per-user preference (users column or settings key) should choose immediate, daily digest or off.
2. Unread in-app alerts added to the existing daily_alerts per-user email, or a separate digest.
3. API actions to list notifications and mark them read, plus an Alerts list with an unread badge in the iOS app.

Already present (do not rebuild): emails for referral assignment, tomorrow's sessions, booking invites, and each case worker's authorization and overdue-task alerts; iOS unread portal messages; the iOS Action center tasks.

APNs push is a separate, larger item (L) and should be rated could.
- **Outbound emails carry more client detail than needed (often in hard-coded subjects), and reports go only as plain-text email bodies** (partial; should, size M; area: Notifications and outbound email (cron/daily_alerts.php, report.php, core.php templates)). Missing: 1. A setting for minimum-necessary staff emails. The daily alert, task and loan digests would carry counts and a sign-in link instead of client names, track titles, authorization numbers and serial numbers (cron/daily_alerts.php:51, 166, 204). The same setting should cover staff session reminders and referral-assignment emails.
2. PHI-free defaults for the subjects that are hard-coded today, or a way to edit them:
   - the report subject, which carries the client name, track and authorization number (report_builder.php:198);
   - 'Tomorrow: {client}' (session_reminders.php:76);
   - 'Referral assigned to you: {client}', whose body also carries the referral reason (referrals.php:63, api.php:1862).
   Also change the consent_request default subject, which names the client today, although admins can already edit that one.
3. A choice to deliver progress reports as a link instead of body text:
   - store a report snapshot, since report_acks holds no content today;
   - show it behind the existing code-verified VRC portal session, which today lists only sent dates and receipt status;
   - optionally add a configurable subject tag or sensitivity flag that sets off Microsoft 365 message encryption on the Graph send path.
   This covers report.php, cron/monthly_reports.php and api.php send_report.
4. The copy-to-self (report.php:110) should be a note with a link, not the full report text.
- **A lost or revoked phone keeps client notes, track names and the offline queue; there is no per-device revoke, wipe signal or passcode requirement** (partial; should, size M; area: Offline device controls (ios/Sources/API.swift, CaseManagementApp.swift, users.php)). Missing: (a) The server cannot tell the app that a session was revoked for loss and its data must be wiped. api.php returns the same 401 for expired and revoked sessions, and the app keeps open screens, drafts, TrackCache and the offline queue waiting for the same account. A revocation reason is needed, because password change and reset also delete tokens and must not wipe unsynced notes. (b) api_tokens has no device label, so an admin cannot list app sessions per device (last used, created) or revoke just one phone. (c) There is no "report lost device" action or audit record beyond 'mobile app signed out'. (d) A phone with no passcode gets a one-time warning, then opens PHI. The app should refuse to show records, or at least refuse to store drafts and the offline queue, until a passcode is set, because complete file protection gives nothing without one. (e) A deliberate sign-out leaves the offline queue of case notes on the phone. There is no way to wipe all local data from the device.
- **No cron heartbeats, status page, deep health check, Graph secret-expiry warning or outbound email ledger** (partial; should, size M; area: Operations / monitoring and alerting). Missing: 1) A last-run (and last-result) record written by each cron job: daily_alerts, session_reminders, monthly_reports, at_reminders, ingest_email, weekly_offsite and backup.sh. The watchdog should then alert when a job is overdue. Several jobs already report their own failures, so the real gap is detecting a job that has stopped running altogether. 2) An admin-only, screen-reader-friendly "System status" section showing the last backup and off-site send, last-run time for each cron job, the result of a live Graph token check, the Entra/Graph secret expiry date, free disk space in the private directory, and the version. 3) An optional health URL with a shared token for an off-host uptime monitor that checks the database and cron freshness, not just login.php. Documenting that login.php can already be monitored is a partial stopgap. 4) A stored "secret expires on" date with 30-, 14- and 7-day warnings in daily_alerts and the status page. Also, when Graph fails and send_app_mail falls back to mail(), admins should get an in-app notification. 5) A minimal outbound email ledger (template key, recipient type, result and timestamp, without PHI bodies), so failed consent, portal, survey, reminder and VRC-report emails can be seen in one place. Dropped from the original claim: pruning audit_log (it must be kept), rotating error_log (a hosting job), and pruning trash and login_attempts (already done).
- **No schema-version tracking or in-app upgrade path: code newer than the database goes undetected** (partial; should, size M; area: Operations / update and upgrade mechanism). Missing: Already present: a manual update check (account.php:119-138, 652-666), feature-level table probes for AT (2.73.0) and workflows (3.0.16), and migrations proven safe to re-run (tests/full_schema.php:716-717).

Missing:
(1) A stored schema version, as a settings key or table. install.php and each migration would write it.
(2) A check at boot, or on the admin and account page, that warns admins when APP_VERSION needs a newer schema than the stored one. Without it, uploading the files first breaks sign-in and invoicing with nothing to explain why.
(3) An admin-only, screen-reader-friendly runner. It would take or confirm a fresh DB backup, apply the mig_*.sql files newer than the stored version, record the new version, and report each statement's result. The bundle keeps migrations outside the docroot, so the runner needs a defined place to find them.
(4) A daily cron update check, e.g. in daily_alerts.php, with an admin notice instead of the button only.
(5) A file manifest written by make_dist.sh, plus an admin integrity check against it.
Optional: a packaged update download. The README and REVIEW notes also need to agree on whether re-running migrations is safe.
- **Outcome survey only goes out by email at a Rehabilitated closure; staff cannot send it manually or record phone answers** (partial; should, size M; area: Outcome surveys). Missing: 1. Survey trigger is too narrow. The client satisfaction survey goes out only on a status change to Closed - Rehabilitated. Clients closed 'Other' and clients who finish a track while their case stays open are never surveyed, so the satisfaction and NPS shown to the board count only successful closures. There is no 'send survey' button on the client record and no reminder for an unanswered survey.
2. No staff entry. Staff cannot record answers taken by phone or in an accessible format. This matters for clients with vision loss who have no email or prefer phone. The surveys table also has no column for channel or for who entered the answers.
3. No response list. There is no page listing survey responses and comments with filters or export. The only route today is a global note search for 'Outcome survey completed'.
4. No employment follow-up. There is no post-closure follow-up on employment or job retention (for example at 90 days).

Device-use follow-ups at 30/60/90 days already exist (followups.php, Post-delivery outcomes) and are out of scope.
- **Outcome measures collected in AT outcomes, competency and goal history are not reported across clients** (partial; should, size M; area: Outcomes measurement). Missing: 1. No report reads at_records outcome checks. That means no device-in-use rate and no independence or confidence change across the 30/60/90-day checks. funder_report and followups use only the legacy equipment_followups.
2. No report shows skill-mastery gains from client_competency across clients.
3. Goal attainment within a period is not computed. funder_report counts goals created in the period, and analytics is all-time. smart_goals has no achieved_at, and no report uses the goal_progress dates or target_date.
4. outcomes.php (the annual report) has no goal, assessment-gain or device-outcome section. It has only survey "goals met /10".
5. None of these measures is broken down by instructor, program, service type or cohort.
- **No funder directory, fee-schedule defaults, or effective-dated rate changes (rate edits and draft voids re-price all unbilled work regardless of service date)** (missing; should, size M; area: Per-funder rates and rate changes). Missing: 1. No funders table. The agency is free text on tracks, invoices and contracts, with no suggestion list. Reports group by the raw string, so case differences merge (the collation ignores case) but misspellings and variant names split the agency report, funder report, analytics and the invoice aging summary.
2. No funder billing details:
   - Bill-to is the counselor's name, agency and email.
   - The invoice letterhead has no organisation address, vendor number or tax ID.
   - The QuickBooks export hard-codes net-30 for every funder.
   - There is no preferred invoice format per funder.
3. No fee schedule by funder and service type to fill in a new authorization's rates, and no organisation mileage-rate default. The hourly rate starts blank and mileage starts at 0.
4. A rate change cannot be given an effective date. track_edit.php:118-134 re-prices every unbilled or Draft-invoice log whatever its work_date, and voiding a Draft (invoices.php:73-79) also re-prices its logs to the current rate. September work not yet billed is therefore always re-priced at a rate that starts on 1 October. The workaround is to invoice and Submit first, or open a new track.
- **Pre-ETS cohort sessions are not linked to students' billable Pre-ETS tracks (double entry with schedule.php group sessions)** (partial; should, size M; area: Pre-ETS programs (programs.php, cohorts)). Missing: A cohort session (programs.php or the API's log_cohort_session) creates no work_logs. Its activity hours and attendance therefore never reach invoices, utilization, funder_report, rsa_report, agency_report or analytics. Cohorts have no link to each student's Pre-ETS training track (which holds the funder, authorisation and rate) or to a contract. Today the only billable route for group Pre-ETS instruction is a separate schedule.php group session completed through complete_group. That route needs the group entered a second time, files the track's default RSA category instead of the session's Pre-ETS activity, and has no attendance (absent students must be cancelled one by one). The fix needed: when a cohort session is logged, offer a work_log on each present student's active Pre-ETS track. Map the activity to the matching '(Pre-ETS)' RSA category, run the authorisation and rate checks, and reverse those work_logs if the session is deleted before it is invoiced. Also count a group session's time only once in staff utilization.
- **No dedicated final/closure version of the VR progress report and no prompt to send one when a track completes or a case closes** (partial; should, size M; area: Progress reporting / closure). Missing: The existing signed Training Progress Report (report.php, api.php send_report) can already be emailed for a Completed track. It covers authorized against used hours and funds, the final status of each goal, the full session log, a free-text cover note, a signature and an acknowledgement. What is missing:
(a) A "Final / Closure Report" mode, with that title and its own note type, so the case file holds a document clearly marked as the final report.
(b) Structured sections the current builder leaves out: baseline and ending skill (client_competency and scored-assessment gains), devices and setup delivered (equipment and AT setup records), recommendations for further services or maintenance, the closure reason, and the employment or independence outcome.
(c) A prompt or task to send that report when a track is set to Completed (track_edit.php has no side effects) or a case is closed (client_edit.php only sends the outcome survey). The monthly cron skips non-Active tracks, so no automatic final report goes out.
- **Counselor progress reports are not limited to a reporting period, leave out attendance and per-goal progress, and the automatic report has no written narrative** (partial; should, size M; area: Progress reporting to counselor). Missing: 1. A reporting period (from last_report_at, or the track start, to today) passed into build_track_report() and used to filter the session log and hours (report_builder.php:19,24). All-time totals can stay in the Usage Summary. 2. Per-goal progress for the period: the current progress_pct, plus the change and notes from goal_progress entries in the period. 3. An attendance section built from scheduled_sessions in the period (scheduled, completed, cancelled, no-show), and an attendance or no-show signal in risk.php and/or daily_alerts. Recording who cancelled needs a new column, or a link to the portal_requests cancellation. 4. Barriers and Recommendations/plan sections that staff fill in on report.php (with the existing AI draft able to prefill them from progress_review or the case briefing). 5. A narrative for the automatic cron report, beyond the one fixed sentence it adds now. Options: hold the report for staff to review, or reuse the last saved narrative. Already present, so not gaps: the manual intro and AI cover note, recording goal_progress, and the internal AI progress review and briefing.
- **No privacy notice or footer link on public pages and portals; only the guardian consent form says how data is shared and records sign-off** (partial; should, size M; area: Public intake and client portals). Missing: The system needs a plain-language privacy notice page, built in the same accessible way as the existing public pages, in English and Spanish (register_translations, as consent.php does). It should say what the referral form, portal, survey and VRC pages collect, who it is shared with (the school, the VR agency, any AI service), how long it is kept (tied to the retention_years setting) and whom to contact.

Link the notice from the footer in public/inc/layout.php, from the community_referral.php "Contact and consent" section and from the help.php referral and portal sections.

Record who received the notice. For guardians, add a bullet and link to consent.php so the existing consent_forms signature becomes the acknowledgment. Adult clients, who never sign a consent form, need a new place to record when they were given the notice, for example a date column on clients shown on client.php and listed in compliance.php.

Leave out the iOS part of the claim, because the app is staff-only. The organisation must still write the notice text itself.
- **The QuickBooks invoice export has no filters and lumps all lines; there is no received-payments export and no billing-period close** (partial; should, size M; area: QuickBooks / CSV export, year-end). Missing: (a) The invoices.php export needs a date range (created or submitted date) and a status filter. Drafts should be excluded by default, and an exported-on marker or batch should stop repeat imports. (b) The export should give hours, mileage and expenses separate lines or Items, using the stored hours_amount, mileage_amount and expenses_amount, with an optional Class for RSA category or service type. (c) A payments-received CSV, filtered by paid_at range, is needed for cash and deposit reconciliation. Invoice rows already carry the paid date, amount and reference, but they cannot be pulled by the date the money arrived. (d) A billing-period close date is needed: an admin setting that refuses new or edited work logs and expenses, and invoice generation, dated on or before it. This must cover the web forms, api.php and the iOS offline-sync replay in inc/core.php:362/392, so reports already sent to funders cannot change. (e) A period summary for the auditor should show invoiced, received and outstanding as of a chosen period-end date. The agency summary and analytics already cover service value by period and current receivables. Out of scope: 1099 totals (no contractor pay records exist, and this belongs in payroll or QuickBooks) and deferred revenue (fee-for-service billing has no prepayments).
- **No legal hold or retention-aware deletion: Authorization, IPE and Release documents can be trashed by any caseload staff and destroyed after 30 days, unaudited** (missing; should, size M; area: Records retention (client.php purge, trash.php, cron/daily_alerts.php)). Missing: There is no hold flag on a client record that records who placed or lifted it, when and why (with audit entries). The nightly auto-archive, the admin client purge, and the trash purges (cron and trash.php "purge now") ignore holds because none exist. The trash purge is not tied to retention_years at all, so a document deleted early in a 7-year retention period is unrecoverable after 30 days, and the cron purge writes no audit entry naming what was destroyed. Funding-critical categories (Authorization, IPE, Release) have no protection, such as limiting deletion to admins or keeping them out of auto-purge while the retention period runs. Client purge also does not refuse when an AT funding appeal, unpaid invoice or open incident exists. Contract deliverables and spend lines are hard-deleted without going through the trash. Contracts and incidents themselves have no delete path, so they need no hold of their own beyond blocking the incident redaction during client purge.
- **Unconverted referrals are never disposed of, and off-site backup emails and exports fall outside retention** (partial; should, size M; area: Records retention and disposition). Missing: 1) Unconverted referrals: add a Declined or Closed referral status plus admin delete, and a setting-driven cron that purges New or Reviewed referrals older than N months. That purge must also remove referral_waitlist rows (already cascaded), tasks linked by referral_id and referrals.php?view= notifications, and write an audit line. This holds DOB, guardian, school and specific_needs from the public form. 2) Off-site copies: weekly_offsite.php and help.php should say how long to keep backup emails (for example, delete those older than N weeks) and that the sender's Sent Items needs an M365 retention policy. The purge screen should warn that the client survives in earlier off-site backups until they age out, and should ask for or record where the exported ZIP was stored. 3) Lower value: retention clock options for minors (age of majority plus N years) and for contract or final-payment close-out, a disposition report listing purges, and pruning of old ai_feedback comments and failed sign-in rows. Drop "unlinked messages" (they cannot exist: client_id NOT NULL with cascade) and ai_usage (no PHI) from the claim.
- **The referral form does not ask how and in what language to contact the person, what they need to match an instructor, or the VR authorization details** (partial; should, size M; area: Referral intake). Missing: Questions to add to the form, wired to fields that already exist:
- **Preferred contact method and format.** Ask these on the form, using the same lists as the client record (A11Y_CONTACTS, A11Y_FORMATS, the screen-reader flag), and copy them onto the client at conversion.
- **Preferred language and best time to contact.** Also record which language (English or Spanish) the form was sent in, which is currently lost.
- **Area or county, in-person or remote, and availability.** These should seed the referral waitlist row, so staff do not collect them again by phone. Self-referrers are not asked for an address or area at all.

For the VR counselor role, add optional fields: VR case ID, authorization number, authorized hours, and start and end dates. These should be shown to staff for checking, not used to create a billable track automatically.

Optionally, add a public phone number setting shown in the footer and the form's help line.

Leave out a file upload on the anonymous public form. It would collect PHI from unverified senders and needs its own security design. Authorization documents should keep arriving through staff upload, or through the token-protected counselor portal if one is added later.
- **No scheduled management summary email (monthly/quarterly pack) to the owner and administrators** (missing; should, size M; area: Scheduled reporting / cron). Missing: Nothing regularly emails the owner or administrators a summary of totals for a period. Such a pack would cover outcomes and rehab rate, utilization, referral intake and conversion, invoices outstanding/AR, documentation-audit counts, and authorizations or contracts nearing end_date before funder renewals. There is also no setting for who receives it or how often. daily_alerts.php already sends administrators a daily operational digest, so this gap is only about summaries for a period. To build it, the metric queries now written directly in outcomes.php, utilization.php, doc_audit.php, invoices.php and analytics.php, plus funder_metrics() from funder_report.php, must be moved into a shared include that cron can call. The email should be accessible HTML with totals only and no client names, because of PHI, and go only to effective administrators.
- **Sessions cannot be rescheduled, a series or group cannot be cancelled in one action, and staff changes send no calendar update or client notice** (missing; should, size M; area: Scheduling). Missing: 1) Reschedule: nothing updates start_at or end_at on the web, API, iOS or voice. Today the only option is to cancel and book again.
2) Bulk cancel: weekly series have no series identifier in scheduled_sessions, so 'cancel the rest of this series' has nothing to key on. Group sessions (group_uid) also have to be cancelled one client at a time.
3) Calendar update: build_ics only sends METHOD:REQUEST and STATUS:CONFIRMED with no SEQUENCE. Its per-occurrence UID (cms-session-{firstId}-{i}) can't be rebuilt from a session row, so neither a CANCEL nor an updated invite can be sent for an email already delivered. The calfeed.php subscription feed already drops Cancelled sessions, so the stale-invite problem only affects staff who rely on emailed invites.
4) Client notice: no email, portal message or SMS goes to the client when staff cancel or move a session. The portal just stops listing it and the next-day reminder is skipped. Setting a portal reschedule or cancel request to Done doesn't notify the client either, and it doesn't touch the linked session.
- **Session completion bills the scheduled time and cannot capture actual hours, mileage, RSA category, goal or a note, and logged hours cannot be edited** (partial; should, size M; area: Scheduling / billing (web and iOS)). Missing: Completion on the web (set_status, complete_group) and through the API (complete_session) has no optional fields for: actual hours (or actual start and end), miles driven, the visit's RSA category or service type, the goal advanced, or a session or case note. The work log always gets the scheduled length rounded to a quarter hour, the track's first RSA category, no goal and 0 miles.

No work log can be edited afterwards. A wrong duration is fixed only by deleting the log to trash and re-logging on the web, and iOS cannot do this at all.

Miles for a session that is already completed cannot be added either. Web and API log entries both require hours above 0, so the only workaround is a dollar-amount expense on the web.

Lower-value parts:
- A staff mileage reimbursement or trip log with origin and destination does not exist. This is a could, not part of the should.
- Completing a whole group in one action, and scheduling a group, are web-only. Each client's group session can still be completed one at a time on iOS.
- **No attendance, no-show or cancellation rates, and no attendance history on the client record** (partial; should, size S; area: Scheduling metrics). Missing: Nothing computes Completed / No Show / Cancelled counts or rates from scheduled_sessions by client, instructor, service type (via track), remote vs in-person, or period. That means no report page, no CSV and no API action.

There is also no list of clients with repeated or recent no-shows. risk.php's AI signals and the client record leave attendance out, and client.php does not show a client's scheduled sessions at all. No workflow rule fires after N no-shows to tell the assigned staff member or remind them to inform the VR counselor.

Secondary issue: a Cancelled session does not record who cancelled (client or provider), why, or when, so a meaningful client cancellation rate cannot be computed. The core rate report is about a day of work (S), reusing report_table() and caseload scoping. Adding cancelled_by/reason across web, API and iOS would make it M.
- **Clients and guardians get no booking confirmation or cancellation notice for sessions, and no calendar invite** (partial; should, size M; area: Scheduling notifications). Missing: Missing items:
- Booking: no email or .ics invite goes to the client, or the guardian where appropriate, when a single session, series or group session is booked. This applies on all four paths: schedule.php single/series, schedule.php group, api.php schedule_session and voice.php.
- Cancelled or No Show: no notice to the client from either schedule.php set_status or api.php set_session_status. No METHOD:CANCEL invite goes to anyone, so the instructor's emailed invite also stays on their calendar.
- Portal requests: setting a reschedule or cancel request to Done or Declined (portal_requests.php) sends the client no confirmation.
- Portal calendar: no add-to-calendar link or per-client .ics feed.
- Guardians: session reminders never go to guardian_email.
- Contact preference: no notification uses clients.a11y_contact.
- Templates: no editable templates for session booked or cancelled.

Note: there is no move or reschedule function at all; staff cancel and rebook. Notifying a change of time therefore means notifying on cancel and on rebook, unless an in-place reschedule is added.
- **Client service verification can only be captured when completing a session in the iOS app (not on the web, by voice, in iOS ad-hoc logging, or in the client portal)** (partial; should, size M; area: Service delivery / service verification). Missing: Four separate gaps:

1. Staff-side web capture. Add an optional "client name as signed" typed attestation, and ideally a drawn-signature option, to the track.php log-hours form (insert at line 201) and to schedule.php completion, single and whole-group, through auto_log_session at core.php:1205. Also allow adding an attestation to an existing unbilled log.
2. Voice. Voice logging and completion (voice.php:472) take no attestation.
3. iOS ad-hoc logging. LogHoursView does not use the signature_b64 and signed_name that API log_hours already accepts.
4. Remote verification. The client portal has no tokenised "I attended this session" confirmation for completed or past sessions, so telepractice sessions cannot carry client verification. When adding this, also record signed_at and the source or IP on work_logs, as consent_forms does, so an attestation is auditable.

The invoice marking and the display already exist and would pick these up unchanged.
- **The AI 'BAA confirmed' box changes nothing, unredacted AI paths run without it, mail() fallback cannot be turned off, and there is no business-associate register** (partial; should, size M; area: Vendor / business-associate governance (ai.php, inc/ai.php, ai_speak.php, core.php mail)). Missing: (a) ai_baa_confirmed gates nothing. These features send text without redaction whatever the setting:
- web read-aloud (inc/ai.php ai_speak)
- translation (translate.php, api.php ai_translate)
- remediate (remediate.php, api.php ai_remediate)
- client names in live voice transcripts, which are redacted with no client row

(b) The ai.config audit entry leaves out the BAA state. Nothing records who confirmed it, when, or when the agreement expires.

(c) There is no business-associate register or table with agreement status and renewal reminders. It would cover Microsoft 365/Graph, the hosting provider, the AI provider and the backup mailbox.

(d) send_app_mail falls back to PHP mail() whenever Graph is not configured or a send fails, and no setting turns this off. Attachments, including the encrypted off-site backup, are already Graph-only.

Not missing: portal homework explanations are redacted through ai_run with the client row. iOS read-aloud runs on the device.
- **The voice assistant cannot cancel, mark No Show or reschedule a session, read or reply to messages and alerts, answer billing, intake-queue or caseload-wide authorization-expiry questions, or read a client's or counselor's contact details** (partial; should, size M; area: Voice assistant (public/inc/voice.php, iOS VoiceAssistant.swift)). Missing: Missing from voice:
- Cancel or No Show (a confirmed write mirroring api.php set_session_status) and reschedule.
- Unread client and counselor message counts and read-aloud (messages.read_at), plus replying by voice.
- Unread notifications/alerts.
- Billing answers, using unbilled_totals() and the invoices table: unbilled hours and amount, invoices submitted but unpaid or short-paid, and aging.
- New referrals and open portal client requests.
- Caseload-wide authorizations expiring soon, using the same query as the index.php dashboard.
- Reading a client's phone and email, and the VR counselor's name and phone.

What already covers part of this:
- On the web, voice can only navigate to the messages, invoices, client requests and referrals pages. The iOS assistant cannot open those sections at all.
- On iOS, opening a client by voice gives tap-to-call, so a call is reachable but the number is never spoken.

Notes for the build:
- Billing answers need a billing-permission check.
- Message reading needs the same keep-it-out-of-AI-history treatment as read_notes, because it is PHI.
- Reschedule is the largest piece, because it has to reuse schedule_session's authorization checks and calendar invite.
- **Voice assistant (web and iOS, including typed requests) disappears without an external AI provider; no built-in matcher for its fixed questions and navigation** (missing; should, size M; area: Voice assistant / availability). Missing: Missing: a deterministic server-side matcher that runs before ai_run('voice') in voice_interpret (public/inc/voice.php:744). It would cover the 12 voice_answer ask types, the 28 voice_sections names, "search X" and "open <client>", and send only free-form create requests to the provider. The voice gate also needs to be separated from ai_enabled() at layout.php:205, ai_command.php:38, api.php:3465 and api.php:1512 (iOS aiHas), so the assistant stays available when AI is unconfigured, down, or over the 40/10-min voice limit or the shared 60/10-min AI limit. A self-hosted model on the local network is not a workaround, because ai_endpoint_ips rejects private and loopback addresses. Read-only questions answered locally would also stop client names in transcripts reaching the provider: transcripts are sent with redactClient=null, so names go unredacted. The BAA checkbox is only a declaration and does not turn voice off.
- **No staff confidentiality/training acknowledgment tracking; temporary password not forced to change; dormant accounts not flagged** (partial; should, size M; area: Workforce security (users.php, account.php)). Missing: Missing: per-staff records of a signed confidentiality agreement and of privacy/security training, each with a renewal date. These should show on users.php, in a 'Staff due' section on compliance.php, and in a daily_alerts reminder. Missing: a must_change_password flag set when account.php creates a user with an admin-typed password (or creation should email the existing set-password link instead). Missing: a flag and highlight, with optional auto-deactivation, for active accounts not signed in for N days; last-sign-in data already exists in users.php:252. Optional: a 'permissions last reviewed' date per group. Not missing: emailed OTP on every password sign-in, accurate last sign-in for all sign-in methods, deactivation and app sign-out, admin reset-link email, and audit of access changes.
- **iOS app has no native client-request inbox or invoice handling, and task handling there is voice-only** (partial; should, size L; area: iOS companion). Missing: Three things are missing from the API and from iOS:

1. A client-request inbox. There is no API action and no iOS screen to list portal requests with their type and details, or to set them In Progress, Done or Declined (closing or reopening the linked preq task, as portal_requests.php does). Today a request reaches the phone only as a bare "Client <type>: <name>" task link. Completing that task by voice leaves the request "New" on the website.

2. Invoice handling. There is no API action and no iOS screen to list invoices (Draft, Submitted, Paid, outstanding or short-paid) or to mark one Submitted or Paid with a paid amount and reference. iOS shows only unbilled totals and per-authorization submitted, received and outstanding figures.

3. A native task list. There is no screen to list, complete or reopen tasks by touch. The Action center rows open tasks.php in Safari, which may need a second sign-in. Completing a task is possible only by voice, matched on its title.

Reassigning individual tasks should be dropped from the claim, because the website does not offer it either.
- **The iOS companion app has not shipped since 3.0.13, and the next upload is blocked by a missing manifest entry and a missing privacy policy page** (partial; should, size M; area: iOS distribution / App Store readiness). Missing: Needed to ship:
(a) Bump MARKETING_VERSION and the build number from 3.0.13/28, so the Action center and briefing Swift changes (3.0.16 to 3.0.20) reach staff phones. WORKFLOWS-3.0.16 Rollout step 4 is still open.
(b) Add NSPrivacyAccessedAPICategoryFileTimestamp (reason C617.1) to PrivacyInfo.xcprivacy, because API.swift:569 and 599-601 read file modification dates. Consider declaring collected data types to match the App Store privacy label.
(c) Publish a privacy policy page and a support page under public/. App Store Connect, including a custom app distributed through Apple Business Manager, requires both URLs.
(d) Write a short release runbook in the repo: archive, TestFlight or custom-app route, and how App Review signs in with email OTP.

Should have: decode server_version from 'me' and add a minimum supported app version on the server (for example in the 'me' response or updates.json), so the app shows a 'please update' notice. Optionally record the client app version with each API token, so the owner can see which build each phone runs.

Nice to have, not needed for this organisation: a shared scheme, CI, fastlane, an XCTest target and a formal MDM policy.
- **iOS cannot list or remove hours once they reach the server; no personal weekly hours view (only unsynced offline entries can be discarded)** (missing; should, size M; area: iOS hours / corrections). Missing: The API has no action that lists a track's work logs or the current user's recent entries, and no delete_log or correct action. Correction is delete-to-trash and re-log, which only exists on the website (track.php:236). The iOS track screen has no hours section. delete_cohort_session has no iOS caller. Users without the analytics permission have no 'my hours this week vs weekly_hours' view. What already exists: hours still in the offline queue can be discarded in Offline entries (DashboardView.swift:249).
- **Signed session completion, No Show/Cancelled and cohort attendance cannot be saved offline; non-note forms keep no local draft** (partial; should, size M; area: iOS offline / field work). Missing: The iOS offline queue and Offline entries recovery screen exist but cover only add_note and log_hours. Missing: (1) queueing for complete_session (with signature_b64/signed_name), set_session_status and log_cohort_session, with server-side idempotency (idem_key or treating a matching prior completion as success) because these endpoints currently dedupe only via the status='Scheduled' claim and would return 409 on replay; extend PendingCall.kind/readableText for them. (2) DraftStore persistence for incident, scored-assessment and expense forms so typed text survives the app closing (the signature sheet already keeps the drawing in memory on failure, but not across app termination). (3) Lower value: a recovery/export path for pre-upgrade owner-0 queue entries, which are counted in the notice but never listed. Lower-priority writes (update_note, update_goal, save_followup, loan/return_equipment, upload_document with file payloads) can remain online-only with a clear error.
- **iOS app keeps no client cards or in-app schedule for offline reading, so a case note cannot be started from a cold offline launch** (partial; should, size M; area: iOS offline / field work). Missing: Offline today: hours can be logged and queued from a cold start (Schedule toolbar > Log hours > TrackCache). Session time and location are readable offline in the phone's calendar if the instructor subscribed to calfeed.php. Missing:
(a) an in-app schedule that works offline;
(b) the client card offline: full name, phone, address, access or communication needs, active tracks and goals;
(c) a way to open AddNoteSheet with no signal. It is reachable only from ClientDetailView and TrackDetailView, which need live lists, so the add_note queue cannot be used from a cold start.
Needed: a small protected cache built on DraftStore (complete file protection, per account, age expiry, purged on sign-out) holding the next 7 days of the user's sessions and their clients' cards. Refresh it in the foreground, show a "last updated" label, and fall back to it in ScheduleView and ClientDetailView, with Add note enabled from the cached card. Optionally, point the iOS app to the existing calendar subscription.
- **No address, directions, guardian contact or route from session to client card at the point of the visit (iOS and website)** (partial; should, size M; area: iOS schedule / client card). Missing: 1. iOS client card: show the address read-only with a Directions link (maps.apple.com), and show the counselor phone as a tel: link. Today both appear only as editable fields in the Edit client sheet.
2. Add school, guardian_name and guardian_phone to the API 'client' action, and show them on the iOS card for Pre-ETS clients, with guardian phone as a tel: link.
3. Guardian phone is not shown or editable on the website client record either (client.php, client_edit.php). It is only copied in once when a referral is converted, so it cannot be corrected later.
4. Schedule API and iOS session rows: return client_id, and link each row to the client card and to Add note. Give a Directions link for the session location, falling back to the client address when the location is blank.
5. Add a 'My sessions' filter (user_id) to the schedule API and the iOS Schedule tab.

The personal calendar feed (calfeed.php) already gives each instructor their own sessions, with Maps directions for the typed session location, in Apple Calendar. That covers part of item 5 and part of the directions need. The client's stored address and the guardian contact are covered nowhere.
- **No ad-hoc report or filtered list export beyond the fixed report pages** (missing; could, size L; area: Ad-hoc reporting). Missing: Staff cannot choose a subject, filters and a grouping, save the definition, and download the result as CSV or accessible HTML. The subjects needed are clients, tracks, work logs, goals, referrals, sessions, equipment loans and AT records. The filters needed are date range, status, agency, instructor, client type, RSA category, and school or district. Today, one-off questions are answered by exporting the RSA-911 per-client CSV (billing permission only, with no school or instructor column), the Pre-ETS per-cohort CSV, or by counting by hand. A builder would also need report_table() extended to scope work_logs, referrals, equipment loans and AT records. A cheaper first step, about M effort, would cover most funder questions: add client-type, agency, instructor and school filters plus a CSV export to the client list, and add a filtered work-log export.
- **Calendar feed covers only training sessions, has no reminders, and the iOS app has no subscribe link** (partial; could, size M; area: Calendar integration). Missing: 1. The calfeed.php subscription includes only scheduled_sessions. Interpreter, CART and transport bookings (access_bookings.needed_at, routed to the client's assigned_to) and Pre-ETS cohort sessions (date only, so all-day events by staff_id) are left out. Equipment loan and task due dates could be optional all-day entries. All of these need titles that do not reveal disability or other PHI to the calendar provider.
2. Feed events have no VALARM, unlike the emailed invites, which already have a 30-minute alarm (core.php:867).
3. The iOS app cannot get the feed address. There is no API action for cal_token and no 'Subscribe in Calendar' webcal:// link. The web page also shows only an https:// address.
4. help.php does not document the calendar subscription.
EventKit is not needed. A webcal:// link is enough.
- **Phone numbers and addresses are not actionable links (web fully, iOS partly); no mileage help** (partial; could, size S; area: Day-to-day operations / contact and travel). Missing: 1) Web: tel: links for client, counselor and guardian phones on client.php, clients.php and referrals.php. The PHP port of iOS telURL() should keep plain text for anything it cannot dial. client.php should also show guardian_phone, which is stored but never displayed. 2) iOS: apply the existing telURL() to the counselor and guardian phones in ReferralsView and to the counselor phone on client detail. Show the client address, with a directions link. 3) A "directions" link for client addresses and in-person session locations on client.php and schedule.php. It should hand off to the user's maps app or a configurable maps URL and make no server-side call. Session locations already reach calendar apps through calfeed.php. 4) Optional and separate (M effort, PHI review needed): saved office or staff start locations and a distance suggestion that staff confirm. Any third-party geocoding should be off unless an admin turns it on. Items 1-3 are under a day of work. Item 4 is not needed to call the system complete.
- **Device purchase and interpreter booking costs must be re-keyed by hand to reach invoices, grant spend-down or profitability** (partial; could, size M; area: Procurement / ancillary costs). Missing: The AT 'Authorization to payment' record can already link a device request to a grant/contract and an invoice and follow it to Paid. Track expenses and 'Equipment' cost records can carry a device's cost. What is missing:
(1) Vendor-side close-out fields on device_requests: vendor invoice number and amount, paid-to-vendor date, and billed/reimbursed status.
(2) An action that posts the selected quote or authorized amount as a track expense or contract_spend entry, so nobody has to re-type it.
(3) A warning when the selected quote, or the vendor invoice, exceeds authorized_amount.
(4) access_bookings.cost never reaches track expenses, contract spend or profitability. It is display-only.
- **No structured demographic or vision-status fields on the client, and no age-band, impairment or OIB-style served-and-outcomes report** (missing; could, size L; area: Reporting / intake data). Missing: Missing:
- Client fields for gender, race and ethnicity, veteran status, degree of visual impairment (totally blind, legally blind, low vision), cause and age of onset, other impairments (such as hearing), and living situation. Today primary_disability is only free text on tech_assessments.
- Referral source and income bracket stay on the referral (referrals.referral_source and income_bracket) and are never copied to the client when a referral is converted. Clients created directly have no referral source at all.
- A report that counts clients served by age band (for example 55-64, 65-74, 75 and over), impairment, demographics and services, with independence outcomes.

Not missing:
- Age can already be derived from DOB, as rsa_report.php does.
- Hours by RSA category exist in outcomes.php and funder_report.php.
- Independence and confidence 1-5 scores are captured on post-delivery outcome records (at_catalog.php:19). Functional Vision Skills score gains are reported per funder.

So the outcome half of an OIB report needs aggregation, not new data capture. Raise this to "should" only if the organisation actually holds an OIB, Title VII Chapter 2 or state blind-services grant, since the repo shows only VR and Pre-ETS funding.
- **No way to log non-billable staff time, and no per-staff timesheet or contractor summary (group Pre-ETS minutes are recorded but left out of utilization)** (partial; could, size M; area: Staff time / utilization (utilization.php)). Missing: Every work log needs an authorized, rated track (track_edit.php:38-44), so staff cannot record documentation, admin, travel time or time with free or unfunded clients. Group Pre-ETS minutes are already in cohort_sessions (staff_id, duration_min), but utilization.php and api.php 'utilization' leave them out, so team utilization understates workload. No page totals per staff member for a pay period: hours from work logs, cohort sessions and non-billable entries, plus work_logs.mileage by created_by. Staff mileage for trips that are not client work cannot be recorded. users has no employee or contractor type or pay basis, so there is no annual contractor payment summary.
- **iOS app has no Microsoft or passkey sign-in, and its 30-day token is never extended** (missing; could, size M; area: iOS authentication parity). Missing: The app has no 'Sign in with Microsoft' (ASWebAuthenticationSession plus an api.php action that exchanges the Entra code for an app token) and no passkey sign-in (ASAuthorization, webcredentials entitlement, apple-app-site-association file, and api.php passkey challenge/assert actions that issue a token). The app token expires a fixed 30 days after sign-in and is not extended by use (api.php:336, 349), so the app asks for the password and code again every 30 days. Auto-provisioned Entra users (sso.php:180) are not locked out, but they must first set a password through forgot.php, which the app links to, before they can use the app.
- **iOS app cannot create a training track or record an approved authorization amendment (service tracks only)** (missing; could, size M; area: iOS parity / authorization tracking). Missing: Missing:
- API actions (for example save_track and track_amendments) to create a track and to update its authorization terms (auth number, funding agency, hours, hourly and mileage rate, start and expiry dates, service bundle or RSA category, report cadence, amendment reason). The update must write auth_amendments in the same locked transaction as track_edit.php.
- A read action for amendment history.
- On iOS, an "Add training track" entry on the client record (or at least a link to track_edit.php?client_id=N), an "Edit authorization" sheet on TrackDetailView, and an amendment-history list.

Not part of this gap:
- SMART goals and expenses on an existing track already work on iOS.
- Procurement device authorizations already work on iOS.
- Invoices, the AT evaluation report and guardian consent are separate web-only items and should be tracked as their own gaps.
- **No Siri/Shortcuts actions (App Intents) or native iPad build for the iOS app** (partial; could, size M; area: iOS platform reach). Missing: The iOS app exposes no App Intents or App Shortcuts. Siri and the Shortcuts app can open it by name, but cannot run an action or answer a question without it. Examples are "log 2 hours for X" and "what's my next session" answered from CMS data. Intents that write should require unlocking the device and should go through the existing voice_command interpreter, keeping its yes/no confirmation. TARGETED_DEVICE_FAMILY is 1, so on an iPad the app runs as a scaled iPhone app and the drawn signature pad is small. The web app is the only full-size iPad option. Home-screen quick actions are also missing, for example one that opens straight into the voice assistant, and so is an app-native Watch app. The next-session glance and voice query are mostly covered already by the calendar subscription feed (calfeed.php) through Calendar, Siri and the Up Next widget. One small, worthwhile fix is to add an optional VALARM to that feed, so calendar alerts reliably reach the phone and Watch as a wrist tap. A native widget or Watch companion is optional on top of that.
