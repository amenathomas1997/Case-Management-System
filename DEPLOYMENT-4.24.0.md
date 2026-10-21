# Release 4.24.0 — October 17, 2026

Web 4.24.0. The apps are unchanged; the API adds a client's answered custom fields to their details. Apply dist/migrations/mig_4.24.0.sql, or use Database upgrade; Software update applies it on its own. It adds:
- client_documents portal_shared, counselor_shared and uploaded_via
- messages.document_id
- client_closures at_recommended, at_acquired, at_in_use and at_note
- the tables custom_fields and custom_values
- schema_version 4.24.0

**Not deployed with deploy.sh.** It is published only as the GitHub release v4.24.0, and an administrator installs it with Administration, Software update.

**Why.** On 2026-10-17 the maintainer asked to build the rest of the competitor list ("Build it all"): portal file exchange and messaging, closure outcome data, and an intake form builder. Messaging, portal uploads, outcome surveys and editable lists already existed, so each item is built on what was there.

**1. File exchange** (inc/file_exchange.php)
- **Client portal:** a file can be attached to a message, and the message may then be empty. It is kept as the client's upload, under the portal's limits (10 an hour, 100 MB a day, 500 MB in all).
- **Counselor portal:**
  - It lists the files shared with the counselor for each client and gives them only those.
  - A counselor can attach a file to a message about one of their clients. It is filed under "From VR counselor".
- **Staff:**
  - A reply in Messages can carry a new file or one already on the record. The file is then shared with whoever the conversation is with.
  - On the Documents tab, Share with client and Share with counselor turn sharing on and off per document. Uploads can be shared when they are made.
  - Resource documents are shared with the client as before.
- **Content checks:** every upload is checked to be the kind of file its name says (PDF, PNG, JPEG, HEIC, RTF, Office and text): from clients, counselors and staff, including assessment attachments and the apps' uploads. A renamed web page or program is refused. Staff may also upload what agency systems produce: a .doc that is RTF, a .xls that is a web-page or text export, and Unicode text. Staff files are only ever sent as downloads.

**2. Technology outcomes at closure** (inc/case_outcomes.php)
- **Recorded at closure:** devices recommended, devices the client got, whether they use them, and a note. The counts are started from the device requests.
- **Reports:**
  - The Outcome report counts the devices, the share in use, and employment and average wage at closure.
  - The RSA-911 download gains the closure reason, employment, employer, job title, hours, wage and the device counts.
  - The final report states the devices.

**3. Custom fields: an intake form builder** (Administration, Custom fields; inc/custom_fields.php)
- **Field types:** short or longer text, a number, a date, a choice from a list, or yes or no.
- **Options per field:** required or not, and asked on the public referral form or not, with an optional Spanish label.
- **Where they appear:**
  - under More details on the client's edit page and on their record
  - on a referral's page
  - in the apps' client details
- **Referrals:** answers on a referral are carried to the client when it is accepted. Merging keeps the kept record's answers and fills in the rest.
- **Personal data:** the client export and the staging scrub cover them.

**Tests:** tests/v424_cases.php. The 3.0.7 upgrade-chain test now removes documents filed under later categories before narrowing the category column to the 3.0.7 list. Checked before commit: full_schema (2037), audit_regression, security, staging_scrub and run_all pass, and the migration rehearsal matched.

**Review:** no security defects were found. Every finding was fixed:
- **A renamed or removed list option no longer erases saved answers.** The answer is kept, shown as "(no longer on the list)", and saves again.
- **A file-only reply sent also as a text** now says a file is waiting on the portal, instead of an empty text.
- **The content check now covers the two upload paths it missed:** assessment attachments and the apps. It also accepts staff's legitimate agency exports.
- **The portal message box** is no longer marked required when a file can be sent alone.
- **File-only messages** read "Archivo enviado." for Spanish-speaking clients.
- **The RSA-911 closure columns** describe the closure in the period, not a later one.
- **Smaller fixes:**
  - sharing a document already shared is no longer an error
  - the counselor portal labels counselor uploads "sent through the counselor portal"
  - the double-send check includes the file
  - the "choose a client" error sits on the client choice
  - attaching a referral to an existing client carries its answers too
  - a malformed custom-field value leaves the stored answer as it is

**Accepted:** files shared with "the VR counselor" are shared with whoever is the client's confirmed counselor. A new counselor sees what was shared before.

## Published (not deployed)

2026-10-17, after CI passed all three jobs on 6b7cfba. The GitHub release v4.24.0 (latest, not a pre-release) carries:
- the install bundle, built from a clean checkout of the tag
- the signed update package: 321 files, verified with the key built into the code

The live site stays as it is until an administrator installs it with Administration, Software update. Installing from 4.21.0 applies the 4.22.0, 4.23.0 and 4.24.0 migrations in order.

**Rehearsed before publishing.** The package was installed with the 4.23.0 updater onto a copy of a 4.23.0 site, with its own private and cron folders and a database in the 4.23.0 shape:
- the real signature verified, and the plan showed no problems
- the backup was made first
- the database changes ran (11 statements; database version 4.24.0, custom_fields and custom_values present)
- 25 files were replaced, and the new files were added
- afterwards the sign-in, referral and accessibility pages served at 4.24.0 with no PHP errors
