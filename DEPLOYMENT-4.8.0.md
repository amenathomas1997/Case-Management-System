# Release 4.8.0 — October 16, 2026

Web 4.8.0, iPhone app 4.8.0 (build 49). Apply dist/migrations/mig_4.8.0.sql (goal_progress.idem_key and an index; schema_version 4.8.0), or use Database upgrade.

More of the iPhone app works without a signal (the maintainer approved the list on 2026-10-16). With no connection, these are now kept in the phone's offline queue (protected, owner-bound, sent in order when back online), as notes, hours, check-ins and device follow-ups already were:

| Action | How a resend is recognised |
|---|---|
| Expense | Its existing key. The server already recognised a resend (track_expenses.idem_key), so the "isn't known whether it was logged" message is replaced by queueing. |
| Goal progress | update_goal now takes the key the phone gives each entry (goal_progress.idem_key), and a resend answers "deduplicated" without a second entry. |
| Completing or reopening a task | complete_task with a queue key answers done when the task, which the person can see (task_visibility_sql), is already in that state. Without a key it is refused as before. |
| Completing homework | complete_homework sets Completed, so a resend changes nothing. |

The staff guide's offline answer lists them.

**Main menu hints for JAWS** (user report, 2026-10-16): each menu link's one-sentence hint was an aria-describedby description, which JAWS did not read when moving through the menu. It is now visually-hidden text inside the link ("Client records: Find a person…"), read on Tab and when arrowing, still no extra line or stop. The on-screen copy is unchanged (aria-hidden, shown on hover or focus).

**Tests:** tests/v48_cases.php is new. It checks the goal progress resend, the task completion resend, and that refusal without a key is unchanged.

Review: nothing is applied twice. Every finding was fixed:
- **Goals.** A goal entry queued offline carries the goal's status and percent when it was written, and the phone's time. If someone recorded newer progress since, the entry goes into the history at its own time and the newer progress is kept; the reply says so.
- **Once-only.** The goal key is UNIQUE (recorded_by, idem_key), and two sends at once count once. The repeat lookup also checks the goal.
- **Homework** changes and is audited only when it is still assigned.
- **The app** shows a queued task as "Waiting to send" and queued homework as "Completed, waiting to send", without their buttons, so nothing is queued twice.
- **Left as is:** an entry the server refuses (a deleted task, an expense outside the authorization) stays in Offline entries with its message, as before, until discarded there.

Verified before commit:
- full_schema 1603, audit_regression, security, staging_scrub, and run_all (including the iOS API and model tests).
- The migration rehearsal matched.
- The iPhone app builds for the simulator.
- Not checked on a phone.

## Deployed 2026-10-16 21:37 CT
From 4.7.1 with .audit_work/deploy-4.8.0 (10 files), at 67d32df. mig_4.8.0.sql ran; goal_progress.idem_key present. Production files matched 4.7.1 before install and match 4.8.0 after; .htaccess unchanged. Smoke checks passed; health reports 4.8.0. The JAWS menu hint change is live for everyone. The offline additions reach staff once the iPhone app 4.8.0 (build 49) is on TestFlight. Backups: <private dir>/deploy-4.8.0-20261016-213723.
