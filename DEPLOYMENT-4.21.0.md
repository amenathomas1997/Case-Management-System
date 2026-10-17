# Release 4.21.0 — October 17, 2026

Web 4.21.0. The apps are unchanged. Apply dist/migrations/mig_4.21.0.sql, or use Database upgrade; Software update applies it on its own. It adds:
- equipment.at_type
- equipment_loans purpose, borrower_type and decision
- invoices qbo_id, qbo_synced_at, qbo_error and qbo_status
- invoice_payments.qbo_id
- the tables qbo_connection and qbo_customers
- schema_version 4.21.0

**Not deployed with deploy.sh.** At the owner's request (2026-10-17) it is published only as the GitHub release v4.21.0. The owner installs it on the live site (4.20.0) with Administration, Software update: the first real one-button update. The package lists files new in this release first, and inc/bootstrap.php and inc/core.php last, so no page loads a file before it is there.

**1. The AT Act report** (Reports, AT Act report; inc/at_act.php)
- **Period:** a federal fiscal year (October to September), in the Annual Progress Report's categories. On screen, or as a spreadsheet.
- **Device demonstrations:** the new AT services kind Device demonstrations, counted by AT type. Participants are people with disabilities, family members, and professionals from education, employment, health, community living and technology. Each demonstration records what was decided.
- **Short-term device loans:**
  - Counted by AT type, by purpose (deciding, loaner during repair or while waiting for funding, short-term accommodation, training), by who borrowed, and by the decision for loans made to help decide.
  - Redeployments and repair loaners made by AT services are left out.
  - Lending (website and apps) now asks the purpose and the borrower; neither is filled in for you.
- **Device reuse:** redeployments dated by their loan, with savings at the device's new value.
- Each device can have an AT Act type; otherwise it is guessed from its category.
- Loans missing details are listed for staff with equipment access to complete. A caseload-limited account counts only its own clients' loans.

**2. QuickBooks Online** (Administration, QuickBooks; Settings, QuickBooks Online; inc/qbo.php)
- An administrator's own Intuit app (sandbox optional), connected through OAuth. Tokens are encrypted and renewed under a lock.
- **Which invoices go:** those submitted on or after the date QuickBooks was connected (adjustable), so old and already-paid invoices never enter the books.
- **Each invoice:** goes to its customer. That is the funding agency; for private pay it is "Private pay client N" unless the organisation chooses names, since Intuit signs no health-privacy agreement. VR invoices carry the authorization number, never the client's name. There are lines for hours, mileage and expenses; quantity and rate are sent only when they make the amount exactly.
- **No duplicates:** one run at a time (a database lock); a requestid on every send; an invoice already there under its number is linked, not sent again.
- **Payments go both ways:** each QuickBooks payment is recorded here once, on its own date, and each payment recorded here goes there once.
- **Voided or denied here** after sending: voided there.
- **Another company** (or switching away from the sandbox) starts afresh. The daily job runs it, and the page has Send invoices now and a list of what could not be sent, with reasons.

**Also:** the test hooks for meetings and texting now work only from the command line, as the updater's do.

**Tests:** tests/v421_cases.php (with fake Intuit replies), and the 3.0.7 upgrade-chain test, which now also drops and re-adds the QuickBooks invoice columns. Checked before commit: full_schema 1939, audit_regression, security, staging_scrub and run_all pass, and the migration rehearsal matched.

**Review:** every finding was fixed, except those accepted below:
- **The first connect would have sent every old invoice**, paid ones as open.
- **Duplicates on retries** or two runs at once.
- **Sandbox ids reused** in the real company.
- **Line amounts QuickBooks would refuse.**
- **Voided invoices left open** there.
- **Payment matching:** write-offs counted as payments, sync dates used instead of payment dates, and payments recorded here never sent.
- **Customer names:** colons, and inactive clashes.
- **The income account chosen.**
- **Private-pay names**, now a choice.
- **Minor version 75.**
- **AT Act counts:**
  - double-counted redeployments
  - reuse dated by the last edit
  - the loan purposes and professional categories
  - savings
  - caseload scope
- **Forms:**
  - the fill-in forms shown to people who could not save them
  - a defaulted borrower
  - a long label
  - array input

**Accepted:**
- **Decisions:** demonstration decisions are one per demonstration, not per person.
- **Credit memos:** a credit memo in QuickBooks is not brought back.
- **Update timing:** during the one-button update, a page opened in the milliseconds between swapping equipment.php and core.php can fail once. On a small site that is harmless.
