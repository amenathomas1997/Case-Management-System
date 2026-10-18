# Release 4.23.0 — October 17, 2026

Web 4.23.0. The apps are unchanged. Apply dist/migrations/mig_4.23.0.sql, or use Database upgrade; Software update applies it on its own. It adds:
- funding_agencies contact_days, assess_days, portal_name and portal_url
- training_tracks portal_status, portal_at, portal_note and portal_auth (the authorization number accepted)
- invoices portal_status, portal_ref, portal_at and portal_note
- the table client_signatures (with each document's language)
- the setting vr_portal_since (the day the migration first runs)
- schema_version 4.23.0

**Not deployed with deploy.sh.** As with 4.21.0 and 4.22.0, it is published only as the GitHub release v4.23.0, and the owner installs it with Administration, Software update.

**Why.** On 2026-10-17 the owner asked to compare the system with other AT and VR case management software and build what was missing. Of the gaps found, the owner chose to build all four below.

**1. VR portal tracker** (Invoices, VR portal; billing permission; inc/vr_portal.php)
- **The gap:** VR agencies run their own vendor portals, such as AWARE, used by about 39 agencies. Providers accept authorizations and enter payment requests there. The system did not track that work.
- **It lists:**
  - authorizations to accept, which can be marked Accepted, or Returned with what the agency asked for
  - invoices to enter as payment requests, with the portal's request number
  - rejected requests, with the agency's reason, which can be entered again
  - requests waiting to be paid, with days waiting and a flag when past the agency's payment terms
- The invoices to enter download as a spreadsheet in the fields portals ask for.
- Each agency can name its portal and link to it (Funding agencies).
- It counts authorizations added and invoices submitted from the day the migration runs. Earlier ones were dealt with before the tracker existed.
- Payment is recorded on Invoices as before.

**2. Documents to sign** (client page, Documents to sign; inc/signatures.php, sign.php)
- **What can be sent:** consent to services, an equipment loan agreement for a device on loan, or a document staff write. The client or their guardian gets it by email.
- **Signing:** on a private page, by typing their name, as with the release of information. The page is in English and Spanish.
- **What is kept:** the words as sent, so what was signed can be shown again. A case note is added when it is signed.
- **Loan agreements:** a signed one records the borrower's acceptance on the loan.
- **Withdrawing:** possible, and the link then says so.
- **Wording:** consent to services and loan agreement wording are on Public wording.
- **Personal data:** merging duplicates, the client export, the timeline and the staging scrub all cover the new table.

**3. Referral deadlines per agency** (Funding agencies)
- Each agency can set the business days it gives you to contact a new referral (3 if blank) and to hold the first assessment once accepted (10 if blank).
- A referral's agency is found by the counselor's agency name, or by their email domain.
- The contact and assessment tasks are due by those deadlines. The referral list shows "Contact by" or "Contact overdue since".

**4. Accessibility conformance report**
- ACCESSIBILITY-CONFORMANCE-REPORT.md: in the VPAT 2.5 format, against WCAG 2.2 A and AA.
- It is a self-evaluation and says so. It states what the evidence is and what is not yet verified.
- A public Accessibility statement (accessibility.php), linked from the privacy notice.

**Tests:** tests/v423_cases.php, and the 3.0.7 upgrade-chain test now also drops and re-adds the invoice portal columns. Checked before commit: full_schema (2001), audit_regression, security, staging_scrub and run_all pass, and the migration rehearsal matched.

**Review:** every finding was fixed.
- **The VR portal ignored caseload limits.** A caseload-limited billing account could see, download and change other caseloads' authorizations and invoices. It is now limited as on Invoices, for reading and writing.
- **The download could carry spreadsheet formulas** typed into names on public forms. Every cell now goes through csv_cell().
- **A renewed or amended authorization number was never accepted again.** The number accepted is now kept, and a new number is listed again.
- **Spanish-speaking signers got English terms.** Consent and loan wording, titles and loan lines now have Spanish built-in text, and the document's language is kept and marked on the page.
- **Smaller fixes:**
  - VR portal errors are now said in a message; failures are no longer styled as successes
  - the summary link for a missing loan
  - deadline wording only names an agency that set its own deadline
  - "business days" added to the agency summary
  - staging keeps the portal statuses
  - a returned loan is not marked accepted
- **The conformance report claimed more than the evidence showed:**
  - browser checks are run at audits, not on every release
  - contrast is measured in the light and dark themes, not every palette
  - the manual audit was on 3.0.13
  - 1.4.11 and 3.1.2 are now Partially Supports
- **The public statement** spoke in the organisation's name. It now describes the software, which suits every installation.

## Published (not deployed)
