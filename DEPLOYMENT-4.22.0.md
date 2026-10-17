# Release 4.22.0 — October 17, 2026

Web 4.22.0. The apps are unchanged; the API adds the office to a client's details and accepts it on save, and older app builds that never send it keep what is stored. Apply dist/migrations/mig_4.22.0.sql, or use Database upgrade; Software update applies it on its own. It adds:
- the table offices (name, address, phone, time zone, open or closed)
- users.office_id and clients.office_id, each indexed
- schema_version 4.22.0

**Not deployed with deploy.sh.** As with 4.21.0, at the owner's request it is published only as the GitHub release v4.22.0, and the owner installs it on the live site with Administration, Software update.

**Offices in one company** (owner's choice, 2026-10-17: one organisation with several locations, not separate agencies; inc/offices.php, Administration, Offices)
- **Offices:** an administrator adds each location with its address, phone and time zone. An office is closed, not deleted, so records keep it. With no offices added, nothing about offices appears anywhere.
- **Staff:** placed in an office on their user page.
- **Clients:**
  - placed in an office on their edit page; the client page names the office and its phone
  - a new client starts in the office of the person adding them, if it is open: on the website, in the apps, from an accepted referral, an import and by voice
  - merging duplicates can bring the office over to the kept record
  - choosing an office that was closed since the form was opened is refused with a field error, and the stored office is kept
  - a closed office stays on a record already in it, but cannot be newly chosen
- **Time zones:** a client's own zone first, then their state's, then their office's. Texts, email reminders, booking notices, the portal, letters and the Schedule's "Client's time" all follow the same order.
- **Lists:** the client list and the Schedule's upcoming sessions have an Office filter. The client download has an Office column and keeps the filter.
- **Apps (API):** `client` returns office_id and office; `save_client` takes office_id (an open office's id, or null for none). An unknown or newly chosen closed office is refused with 422.

**Tests:** tests/v422_cases.php. Checked before commit: full_schema (1966), audit_regression, security, staging_scrub and run_all pass, and the migration rehearsal matched.

**Review:** every finding was fixed except the one accepted below.
- **Time zones:**
  - email reminders, booking notices, offered times, letters and the Schedule did not use the office's time zone; only texts and the portal did
- **New clients:**
  - referrals, imports and voice did not start new clients in the creator's office
  - merging did not carry the office over
  - a closed creator's office was treated differently on the website and in the apps
- **Lost choices:**
  - a chosen office that had just been closed was quietly cleared
  - a "No office" choice was lost when the staff form came back with errors
- **Smaller:**
  - the apps' "0" was refused while 0 was accepted
  - the client download always had an Office column
  - the office name was not marked required
  - the empty upcoming list did not mention the office filter

**Accepted:** during an update, pages might read office_id before the migration has run. The updater runs migrations before swapping files in, so that never happens.

## Published (not deployed)
