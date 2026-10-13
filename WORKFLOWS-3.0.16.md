# Workflow additions — 3.0.16

Website deployed October 12, 2026; see DEPLOYMENT-3.0.16.md for deployment verification. The iOS build has not been distributed.

## Staff workflows

Open **Action center** in the main navigation. Its tools include:

- Daily action items: visible open tasks, documentation flags, expiring authorizations, overdue session outcomes, open referrals, homework support, and AT deadlines. Explicitly delegated tasks keep their existing cross-caseload visibility; other client records retain caseload restrictions.
- AI case briefings: summaries and suggested next steps linked to server-selected source records. Unknown or missing citations invalidate the response. The source window is limited to 20 tracks, 10 goals per track, 15 recent notes, 10 homework assignments, and 10 practice reports, with a 40,000-character evidence cap. A valid citation does not prove the generated claim is correct.
- Documentation review: checks the newest 1,000 accessible notes for empty narratives, incomplete structured sections, and placeholders. Staff can correct a note or acknowledge its flags with a reason. Changed content invalidates the acknowledgment. This is a rules-based completeness check, not a clinical contradiction detector.
- Homework support: read client practice updates and draft adaptations for staff review. Staff apply approved changes through the existing homework editor.
- Referral waitlist: record skills, language, area, delivery mode, availability, status, and follow-up date; compare saved requirements with available instructor profiles and their next-seven-day booked capacity. Matching is advisory; assignment remains an explicit staff action. Version checks prevent stale overwrites.
- AI quality: request outcomes, latency, truncation, and staff feedback. Staff see their own usage; administrators see organization-wide usage and feature settings. Historical outcome metadata remains unknown. Character counts are not token counts or costs. Briefing validation failures count as failed requests; other features retain provider-level success reporting.

The iOS dashboard includes a native action list and briefing/adaptation screen. Detailed workflow management opens the website and may require web sign-in.

## Scheduling and funding

Web, mobile, and voice scheduling check authorization status, number, effective dates, delivered hours, and scheduled reservations. Recurring bookings check the whole series. Web/mobile staff can explicitly approve an exception; voice directs them to the scheduling form. These are advisory checks, not transactional spending limits. Bookings without a linked training track remain available under existing rules.

Authorization-to-payment records now include the requested amount, submission/decision dates, appeal deadline, missing documents, supporting evidence, and a staff-reviewed appeal draft. Existing AT AI drafting works with these fields. The action center and existing AT reminder cron use an appeal deadline for Denied/Appeal records when supplied. Billing permissions and caseload restrictions still apply.

## Client homework

The existing token-scoped client portal accepts minutes practiced, difficulty, and comments on assigned homework. “Need help” creates one follow-up task. Retries are idempotent; a reused submission key with changed data is refused. Reports never mark homework complete. Practice reports and documentation reviews are included in case exports; reusable submission keys are omitted.

Client AI explanations are **off by default**. An administrator can enable **Client homework explanations** in AI assistant settings after configuring the provider. The portal discloses that instructions and questions are sent to the AI service. Existing redaction settings apply. There is a per-client limit of 10 explanations and 20 practice submissions per rolling day. Explanations do not update assignments or records. The practice/help controls are currently English, explicitly marked as English on the Spanish portal; explanation output follows the portal language.

## Rollout

1. Back up files and the database using the existing deployment process.
2. Apply `dist/migrations/mig_3.0.16.sql` before making the new PHP files live. The upgrade is additive and repeatable; fresh installations include it in `dist/schema_full.sql`.
3. Install the updated web files and `cron/at_reminders.php`; keep the existing cron schedule.
4. Publish the updated iOS build through the existing release process when ready.
5. Confirm action-center access with administrator, billing, and scoped staff accounts. Try a permitted booking, an authorization exception, a portal practice report, and a reviewed briefing using synthetic records before client-facing AI is enabled.

No production data or paid AI-provider calls are needed for the automated regression suite. Live provider behavior and assistive-technology user acceptance require a separate rollout check.

## Validation

The isolated full-schema integration suite passed, including workflow permissions, forged portal requests, retry handling, daily limits, review fingerprints, invalid AI citations, web/mobile/voice authorization warnings, appeal reminders, exports, and migration repeatability and upgrade parity. PHP syntax checks passed for 160 files. JavaScript, accessibility-helper, and form-message regression tests passed. The iOS simulator Debug build succeeded. No live AI provider call was performed during testing. Subsequent website deployment verification is recorded in DEPLOYMENT-3.0.16.md.
