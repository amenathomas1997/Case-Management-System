# Release 3.0.27 — October 15, 2026

"Build all suggestions": the open suggestions from the usability audit, the parity review and the tester's feedback, built in the safe form of each. Production baseline: 3.0.26 (32d2b2a).

Database migration: dist/migrations/mig_3.0.27.sql adds clients.preferred_language (additive, safe to re-run). Apply it before publishing files.

Decisions taken (owner may change later):
- Support phone number: a setting on the Administration page; client pages show "Call … or email …" only once it is set.
- Survey: keeps the 1–10 scale and stored values; each scale now reads vertically with labelled ends.
- iPhone notifications: local reminders only (session in 30 minutes; follow-ups due today), with no client names; push would need an Apple server setup.
- Offline client list / schedule / follow-ups on the phone: read-only, encrypted at rest, excluded from backups, cleared at sign-out.
- Combined session record: hours are priced exactly as before; the note is saved in the same transaction.
- Batch billing cut-off date and agency restriction are optional; blank = today's behaviour.
- Voice assistant starts listening when opened (setting, default on).
- Combined iPhone rows apply only while VoiceOver is running, so Voice Control keeps the inner buttons.

Streams (details appended by each as it finished):
- A — Today section on the Dashboard (overdue/due-today tasks, today's sessions, unread messages, follow-ups due, new portal requests); Client records remembers the caseload filter and defaults to My clients for scoped staff; Compliance adds follow-ups due and overdue loans; new admin.php (People & permissions, Sign-in & security, Forms & email incl. the support phone setting, Data, AI) with the admin sections moved out of My Account; Administration in the menu (access key Z); other admin pages breadcrumb to it.
- B — Combined session record: track_note_create() in inc/workflows.php; Log a session saves an optional session note in the same transaction; Mark completed on the schedule takes an optional note; action-centre wording.
- C — clients.preferred_language (+ referrals.preferred_language) via mig_3.0.27.sql; Spanish client email templates (portal link, consent request, survey invite, session reminder) chosen by email_template_for_client(); referral form sets the language and requires phone or email; portal opens in Spanish for Spanish clients, one "Ask us something" form, practice logging in the homework section; survey scales vertical; support phone shown via support_contact_html() when set; VR counselor portal shows last/next session.
- D — Server-side duplicate-submit keys on the remaining create forms; consent request in one locked transaction; array-safe inputs on contracts/equipment/email templates; menu group headings are h3 so only Low-vision controls precedes the h1.
- E — iPhone: optional local reminders (no client names), offline read-only cache for clients/schedule/follow-ups (encrypted, cleared at sign-out), follow-up recording queues offline, voice assistant context + auto-listen + screen routing, Waitlist and Documentation review screens, combined rows only while VoiceOver runs.
- F — Invoices: agency/month/text filters, Mark all shown drafts submitted, optional batch cut-off date and agency (generate_invoice() gained an $until parameter; no copied logic), payment fields behind Record payment; API waitlist/waitlist_update/doc_review; shared remembered report period picker (inc/report_period.php) on outcomes, funder, agency, RSA-911, utilization, capacity, risk; AT modules cross-link.
- Also: Ask the knowledge base explains no-match results and offers a marked general-knowledge answer; sign-in code expiry fallback; KB and admin links repointed.
- G — Lesson plans: the curriculum generator now produces, per lesson, Objectives, numbered learner-facing "Steps to complete the task" and "How you know it's done", with a plan text download; homework drafted from a session note: homework_draft_from_note() in inc/ai.php (feature key homework_draft, client redaction, audited), "Draft homework from this note" on every track note, after a saved session note, and on Homework support; the draft prefills the Assign homework form and nothing is assigned until reviewed.
