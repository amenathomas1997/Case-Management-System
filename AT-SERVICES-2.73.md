# AT services — version 2.73.0

Implemented in the web application and native iOS app. Open **AT services** in the web navigation or under **More tools** on the iOS dashboard. Client records link directly to their AT services; procurement records link to device setup and follow-up creation.

## Workflows

| Area | Available behavior |
|---|---|
| Device trial comparisons | Record common goals and context, add device-specific results, scores, costs and client preferences, select a tested recommendation with rationale, and export an accessible HTML comparison. iOS can share a text comparison. |
| Authorization to payment | Link a client, track, referral, procurement request, contract, authorization document and invoice. Track evidence readiness, denial/appeal reasons, next actions and due dates. Submitted/paid states require the actual corresponding invoice state. Current authorization hours, delivered hours, invoiced amounts and receipts are read from existing records. |
| Device setup | Record versions, accessibility settings, accessories and reproducible setup/training instructions. An active setup with a delivery date schedules 30-, 60- and 90-day outcome records without duplicate scheduling. |
| Post-delivery outcomes | Record actual device use, independence, confidence, barriers, additional training and follow-up plans. Completed checks require completion evidence. Existing equipment-loan follow-ups continue to operate separately. |
| Repair and loaners | Track issues, warranty dates, claims, vendors and resolution. Issue and return actual inventory loaners from the support record. Loaners must return before closure. Inventory repair/reuse restrictions also apply to legacy loan entry points. |
| Instructor matching | Maintain expertise, communication/language, service areas, delivery mode and availability. Match requested needs and show missing requirements plus aggregate scheduled hours and capacity. Results are suggestions, not automatic client reassignment or bookings. |
| Learning library | Publish accessible instructions, transcripts, links and practice exercises. Assign client-specific instructions and goals to a resource. Assigned learning appears in the existing client portal; client-reported practice stays distinct from staff-verified completion. |
| Service profitability | Record additional direct costs and effective-dated labor cost rates. Combine these with existing track expenses, work-log hours, submitted/paid invoices and receipts. Review totals by track, service or linked contract. Missing labor rates are visible rather than treated as known zero costs. |
| Software licenses | Record products, vendors, purchased seats, renewal dates/costs, compatibility, payment responsibility and renewal staff. Allocate/release client seats, enforce capacity under concurrent requests, and show renewal reminders. No secret license keys are stored. |
| Device reuse | Intake and quarantine inventory, record inspection/sanitization/data-wipe/repair evidence, approve readiness and redeploy through the existing loan system. |

## AI and homework

The **Work on this with AI** control is available in narrative AT service fields, learning materials and assignments, the existing web homework assignment form, and homework editing. The native iOS homework form also supports drafting and revisions to existing assignments.

Staff choose the field and describe the change: draft, simplify, translate, adapt an exercise, or revise existing instructions. Context is limited to entered narrative content, the selected track's goals, and relevant authorized parent records. The existing AI feature switch, configured provider, redaction, rate limiting and metadata logging apply.

The result is an editable draft. Staff explicitly apply it to the form, can restore the prior text, and must save separately. Generation does not save or assign homework, change a status, enter a score or amount, issue a device, or send a message. Prompts prohibit inventing observed results, completed work, qualifications, approvals, settings or payment facts.

Existing homework can be edited without silently overwriting a newer edit or a client practice report. Editing preserves its Assigned/Completed state; substantive revisions clear the old client practice acknowledgement.

## Access

Billing has access to **all new AT service areas**, including internal costs, profitability, labor rates, shared library maintenance and instructor profiles, as requested. Administrators retain access through their existing permissions.

For other staff, modules use their corresponding existing clients, equipment, scheduling, billing, template or admin permissions. Non-billing staff need template access to maintain the shared learning library; ordinary instructors can maintain their own matching profile.

Existing own-caseload restrictions still apply to every client-bound record and reference, including API, AI context, portal actions and exports. Shared license totals include aggregate seat usage but do not expose other caseloads' assignment details. This change does not grant billing user-management or authentication-settings privileges.

## Installation / upgrade

No production deployment or database migration was performed during development.

1. Back up the existing database and apply `dist/migrations/mig_2.73.0.sql` using the deployment's database administration process. It creates two new tables and can be rerun without clearing their records. Apply any earlier migrations that the deployment still lacks separately.
2. Deploy the updated public and cron files together. The new workspace displays a migration-needed message when its tables are absent. Client merge/export integration expects the migration to be installed.
3. Schedule `cron/at_reminders.php` daily (the installer suggests 08:00). It creates in-app reminders, with no external email. Reminder creation and deduplication are transactional, so a failed notification remains retryable.
4. Build/distribute the updated iOS app through the existing signing/release process. The build verified here used the simulator SDK with signing disabled.
5. AI controls use the existing enabled **Drafting assistant** feature and configured provider. No production AI configuration or credentials were changed.

The rebuilt `dist/cms_dist.tar.gz` includes public files, cron jobs, the fresh-install schema and upgrade migrations. The archive is ignored by Git. The old unpacked `dist/cms_dist` directory is not the release artifact.

## Data and reporting details

- Workflow records carry relational client/track/equipment/staff/parent references, validated field data, audit entries and an optimistic version number. Server validation is shared by web and API; iOS consumes the same form contract.
- Client merges preserve the new workflow records. Case exports include client-bound AT records and exclude internal cost/rate records.
- Profitability is an operational estimate, not net profit or a complete accounting ledger. It excludes draft invoices, unbilled services and unrecorded overhead. Labor cost uses each work log's author and the rate effective on its service date. Additional cost entries must not duplicate existing track expenses or labor.
- Contract grouping uses current contract links in funding records. Tracks linked to multiple contracts are shown as unallocated, not counted multiple times. No automatic cost allocation is inferred.
- Instructor capacity is a seven-day scheduling estimate; staff still verify actual availability and fit.
- Device configuration and learning content need staff review. AI drafts are not evidence that a task, assessment, inspection or repair occurred.

## Verification

- Full fresh-schema integration suite, including actual API calls for all workflows, scoped/billing permissions, state validation, stale-edit rejection, concurrent seat allocation, actual equipment loans, portal isolation, financial calculations, migration replay, merges and export exclusions.
- AI provider transport replaced with a local test double: exercised the real redaction/governance path, feature disabling and no implicit homework persistence. No live provider was contacted.
- Reminder tests forced a notification insert failure, verified rollback, and checked repeated-run deduplication.
- Actual Swift models decoded populated AT API and homework responses.
- Existing database access/security concurrency regressions and offline queue regression harness passed.
- JavaScript AI controls tested for explicit apply, undo, target-field consistency, CSRF and error handling. Existing JavaScript, form-message and accessibility checks passed.
- PHP syntax checks and Xcode arm64 simulator build passed.
- Synthetic web lists/forms/detail pages rendered without PHP warnings. Narrow-screen visual inspection covered service cards, learning form labels and AI controls.
- Bundle contents were compared with current source files.

Live provider behavior, production deployment, signed device installation and complete assistive-technology usability testing remain environment-specific verification steps.
