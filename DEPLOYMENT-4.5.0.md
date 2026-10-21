# Release 4.5.0 — October 16, 2026

Web 4.5.0, iPhone app 4.5.0 (build 47). Apply dist/migrations/mig_4.5.0.sql, or use Database upgrade.

The migration turns these enum columns into varchar(60), keeping every value and default:
- clients.client_type
- service_bundles.client_type
- training_tracks.service_type
- client_notes.note_type
- client_documents.category
- assessment_templates.primary_tech and tech_assessments.primary_tech
- equipment.category and equipment.item_condition
- equipment_maintenance.service_type

It also widens equipment_loans.condition_out and condition_in to 60, and sets schema_version 4.5.0.

The third open-source release finishes the configurability plan (.audit_work/configurability-audit-2026-10-16.md).
- **Lists and time limits** gains eight lists: client types, case note types, document categories, service types, primary technology, equipment categories, equipment conditions (items and loans) and maintenance types.
  - Each keeps the values the code relies on. The label says "always keeps …", and removing one is refused:

    | List | Always kept |
    |---|---|
    | Client types | VR, Pre-ETS |
    | Note types | Case Note, Progress Report, Monthly Summary, Intake, Addendum |
    | Document categories | all six built-in |
    | Service types | Assistive Technology, Pre-ETS, Other |
    | Primary technology | Screen Reader, Other |
    | Equipment categories | Other |
    | Equipment conditions | Good, Needs Repair |
    | Maintenance types | Maintenance, Other |

  - List items are at most 40 characters, the size of the narrowest record column they are saved in.
- **Every former constant use** (about 45 places) reads the editable list. The equipment lists moved from equipment.php and inc/access.php into inc/lists.php, and equipment_import uses them too. Editing a client's type or a track's service type keeps a value since removed from its list (cms_choices), on the website and in the app. Service bundles offer every client type plus Any.
- **The iPhone app:**
  - The new form_options API action sends the lists (names only; any signed-in user).
  - The app saves them on the phone at each account refresh, and every copied list reads from them, falling back to its built-in list for an older server.
  - Lists that follow the server: client types, technology, document categories, service types, booking types, competency levels, equipment conditions, categories and maintenance types, device request categories, score labels, closure reasons, note types.
- **Not made editable** (the code depends on their meaning): client statuses, report cadences, referral sources and the older referral note types. The referral form's purposes, services and needs are on Referral form settings.
- **Tests:**
  - tests/v45_cases.php is new.
  - v44_cases posts the new lists in their groups.
  - The 3.0.8 schema test also applies mig_4.5.0.

Review: no security holes. Every finding was fixed:
- the app's add-equipment and maintenance actions use the editable lists (they still had fixed ones)
- editing a note keeps a note type since removed from the list, on the website and in the app
- reserved words are refused: "Any" as a client type (it means every type for a service bundle), "Client Upload" as a document category (it marks files a client shared)
- the primary technology is matched as whole words, longest first, so a short new item ("AI") cannot capture "Braille"
- the app clears the lists when signing out, or when a server does not send them; it keeps them in memory; it uses its built-in 0 to 4 scales unless the server sends exactly five levels
- new bookings and device requests start on the first choice when "Interpreter" or "Other" was removed
- service bundles keep sorting VR, Pre-ETS, Any, then other types
- the app's track form is sent a track's removed service type so it stays chosen
- the lock test checks the message names Pre-ETS

**Caution:** a migration from before 4.5.0 turns these columns back into fixed lists. Once a site has its own values, never run an older migration again. Database upgrade runs only newer ones. updates.json says so.

Deploy the files and run the migration together (deploy.sh does both, migration first). Each ALTER copies its table briefly, so run it when the site is quiet.

Verified before commit:
- full_schema 1473, audit_regression, security, staging_scrub, and run_all (including the iOS API and model tests).
- The migration rehearsal matched.
- The iPhone app builds for the simulator.
- Not checked in a browser, with a screen reader, or on a phone.

## Deployed 2026-10-16 20:06 CT
From 4.4.0 with .audit_work/deploy-4.5.0 (22 files), at ed9f5ca. mig_4.5.0.sql ran (11 statements) before the files were installed; every converted column kept its values. Production files matched 4.4.0 before install and match 4.5.0 after; .htaccess unchanged. Smoke checks passed; health reports 4.5.0. The iPhone app 4.5.0 (build 47) still needs archiving to TestFlight by the maintainer. Backups: <private dir>/deploy-4.5.0-20261016-200616.
