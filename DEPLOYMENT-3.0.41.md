# Release 3.0.41 — October 16, 2026

Web and cron 3.0.41. There is no migration. The iPhone app is unchanged (3.0.40, build 42).

Maintainer decision (2026-10-16): counselors are emailed when a no-show occurs and when an authorization is close to used up, and for nothing else.

- **Counselor emails** (inc/client_notices.php). Both go only to a confirmed counselor (counselor_verified) who has a working portal link, and neither names the client.
  - **No-show** (counselor_noshow_notice): sent from every No Show path (single session, group attendance, app), once per session. Template counselor_noshow: the date and the portal link.
  - **Authorization nearly used** (counselor_auth_notice): sent by cron/daily_alerts.php at the existing 80% hours-or-funds point, for VR tracks. It is marked in sent_alerts as 'vrc80' only after it goes, and the mark clears when usage falls back. Template counselor_auth_low: the authorization number, how much is used, and the portal link.
- **Counselor portal (vrc.php).**
  - Each client shows attendance for the last 90 days: attended, missed and cancelled counts, and the list.
  - Reports can be opened again. This shows the report as it reads today, with a note that the emailed copy may differ, and is audited.
  - Receipt can be confirmed with a button, without the email link.
- **Client calendar emails** (client_session_notice).
  - Every booking path (single, series, group, app, voice) emails the client an invite. Each session has its own calendar ID, <prefix>-client-session-<id>.
  - Cancelling a future session (web or app) emails a calendar cancellation with the same ID.
  - Only current clients with a confirmed email who do not prefer phone or post get these. New editable templates: session_booked_client and session_cancelled_client, in English and Spanish.
- **Client portal.** Each upcoming session has Add to calendar (a calendar file with the same ID, so it is never added twice). A collapsed list shows the last 30 past sessions as attended, missed or cancelled, in Spanish too.
- **Tests.** tests/session_notices_cases.php is new. Nothing in it calls the mailer in the test process.

Verified before commit:
- full_schema 1074, run with the test process's mailer failing as on CI.
- audit_regression 61, security 67, staging_scrub 34, and run_all.
- Not checked in a browser or with a screen reader.

## Deployed 2026-10-16 11:53 CT
- Deployed from 3.0.40 with .audit_work/deploy-3.0.41 (no migration). The syntax check passed, every checksum matched, and .htaccess was unchanged. All smoke checks passed, and health.php reports 3.0.41.
- Backup: <private dir>/deploy-3.0.41-20261016-115311.
- Expect this tomorrow at 7:00: no track has a 'vrc80' mark yet, so every active VR track already at 80% or more emails its counselor once, if the counselor is confirmed and has a portal link.
