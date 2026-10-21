# Release 3.0.34 — October 16, 2026

Web + iPhone 3.0.34 (36); migration **mig_3.0.34.sql** (adds training_tracks.authorized_amount and billing_mode; invoices.closed_reason, due_date, written_off and bill_mode; Denied and Void statuses; the invoice_payments table; backfills earlier payments and due dates). Production baseline: 3.0.33. "Getting paid", the first of the roadmap releases, with the maintainer's decisions of 2026-10-15: block work over an authorization; anyone with billing may write off; include private pay.

- **Billing details** (Administration, Billing details): vendor number, tax ID, remit-to address, billing phone and email, payment terms in days, and the private-pay fee and free-below income. They print on every invoice (PDF and accessible HTML), with the due date, payments, write-offs and balance due. The public referral form quotes the fee and threshold from these settings, in English and Spanish.
- **Payments** (inc/billing.php invoice_apply / invoice_payment_remove / invoice_retotal):
  - Any number of payments per invoice, each with the date received and its reference.
  - A write-off with a reason; any billing user may record one, as the maintainer decided.
  - An entry recorded by mistake can be removed, with the reason audited.
  - The invoice becomes Paid when its balance reaches zero.
  - Mark submitted takes the date it was sent; the due date follows from the terms.
  - A Draft takes a memo or PO number.
  - Outstanding on Invoices, the client page, analytics, the AT funding records and the app's list all use one rule (INVOICE_OPEN_SQL), net of write-offs. The Outstanding table adds a Past due column.
  - The QuickBooks CSV uses the real due date and adds the written-off amount, balance and memo.
- **Denied and Void** (invoice_close): a submitted invoice with no payments recorded is closed with a reason. It stays on record, and its work logs and expenses are released to be invoiced again. Denied and void invoices are left out of invoiced totals and receivables.
- **Authorization limits are enforced** (work_authorization_block): hours, mileage and expenses outside a VR track's dates, or over its authorized hours or dollars, are refused.
  - The check runs on the track page, the app (log_hours, add_expense), session completion (auto_log_session, so the schedule, groups and the app) and voice.
  - It runs with the track row locked, and the message says how far over the work would go.
  - "Log it anyway" and authorization_ack are removed.
  - A new optional authorized dollar amount sits on the track. Overruns show as "N hours over" or "$X over".
  - Booking-time authorization review is unchanged.
- **Billing mode per track**:
  - VR (as before): needs an authorization.
  - Private pay: no authorization; invoiced to the client's name, address and confirmed email, with funding agency "Private pay".
  - No charge: hours are logged at $0, never invoiced, and Generate invoice is hidden.
  - A new track starts from the fee quoted on the client's referral.
  - The app's save_track takes billing_mode and authorized_amount.
- **iPhone:** the Invoices list knows Denied and Void and shows write-offs and due dates. iOS 3.0.34 (36).
- **Tests:**
  - tests/billing_cases.php adds 21 checks.
  - release_3021_cases.php and the voice tests are rewritten for the block rule.
  - mig_3.0.34 is in the upgrade-chain test.

Files: every public/ file in `git diff --name-only v3.0.33 HEAD -- public`. Run mig_3.0.34.sql (deploy-3.0.34/migrate.php checks for invoice_payments and training_tracks.billing_mode).

After deploy:
- An administrator enters the Billing details under Administration. Until then, invoices print without them.
- Existing tracks are all VR. Set any private-pay or free clients on Edit track.

Verified before commit:
- full_schema 944, audit_regression, security, staging_scrub, and run_all.
- php -l on all PHP files.
- The iPhone build succeeded.

Review fixes before release (all eight are tested in billing_cases.php):
- Who pays cannot change while a track has unbilled work or a Draft invoice, so that work is never re-priced to $0 or from $0.
- A replayed entry that waited on the track lock is acknowledged, not refused as "over". This covers the app's hours and expenses, the website and voice.
- Trash restore checks the authorization block.
- The dollar check applies only when an authorized amount is entered; otherwise the hours limit is the authorization.
- No-charge work is left out of the unbilled totals and the batch.
- The migration's paid_amount backfill is safe to re-run.
- Private-pay statements and invoices are billed to the client, with no agency line.
- A refused Remove of a payment shows in the error summary, with its own field name.
