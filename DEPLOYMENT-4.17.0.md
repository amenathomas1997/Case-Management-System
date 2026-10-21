# Release 4.17.0 — October 17, 2026

Web 4.17.0, Android app 4.17.0 (versionCode 6), iPhone app 4.17.0 (build 53). Apply dist/migrations/mig_4.17.0.sql, or use Database upgrade. It adds:
- the table sms_log (every text in and out, with its delivery status)
- clients: sms_consent_at, sms_consent_source, sms_consent_by, sms_opted_out_at
- users: mobile, alert_sms
- scheduled_sessions: sms_reminder_sent, client_reply, client_reply_at
- homework.sms_notified (existing homework is marked as already notified, so nothing old is texted)
- messages.via (portal or text), messages.idem_key (an app's retried send is posted once), and referrals.sms_ok
- schema_version 4.17.0

The maintainer asked for all texting features (2026-10-17). Texts go through Twilio; nothing is sent until an administrator enters the account on Settings, Text messages.

**1. Setup** (Administration, Text messages)
- Steps on the page: Twilio account, a number that can text, A2P 10DLC registration, the incoming-message address (sms_inbound.php), then the Account SID, Auth Token and number (or a Messaging Service SID) on Settings.
- A test text to your own mobile (My Account), and a log of every text with its delivery status.
- Choose whether texts name an in-person session's place (off by default: time only).

**2. Who is texted**
- Only a client who agreed at that number: recorded on the client's edit page or in either app, with how they agreed; a self-referral that ticked "OK to text" is recorded when converted.
- A new phone number clears the agreement. STOP stops everything at once; only the client's START restores it (staff cannot).
- Closed and archived clients are never texted.
- Only from 8 a.m. to 8 p.m. in the client's time zone; otherwise held until 8 a.m. and rechecked before sending (a session text is dropped if the session moved or was cancelled meanwhile).
- A number several current clients share (siblings, a guardian's phone): STOP and START apply to all of them; other replies go to the administrators to file.
- A first name, a time, an online session's link, and optionally the place: never a full name, a diagnosis or case details.

**3. What is texted**
- Session reminders (the reminder job), answerable: C confirms; R and X become client requests for staff to act on (the session is never moved or cancelled by a text alone). "CANCEL" is a carrier stop word, so X is used; a client who replies Cancel anyway is opted out (the carrier rule) and staff still get the cancel request.
- Held texts go out within ten minutes of 8 a.m. (the watchdog, every ten minutes, sends them).
- Booked, moved and cancelled sessions (one text for a series), in Spanish for Spanish-speaking clients.
- New homework: one text per client per run, without titles.
- Messages: "Also send it as a text message" on the website and in both apps; texted replies land in the client's conversation, marked "by text", and alert their staff member.
- Urgent alerts to staff who add a mobile on My Account: no alert words, at most one every 15 minutes.
- HELP is answered; a text from an unknown number is logged and the administrators told.

**4. Security**
- Both webhooks (sms_inbound.php, sms_status.php) read only requests signed with the Auth Token (X-Twilio-Signature).
- The same incoming text delivered twice is read once; a late delivery report never undoes a final one.
- The product rule in tests/security.php was narrowed: payments are still banned; texting is allowed through Twilio from inc/sms.php only.

**Tests:** tests/v417_cases.php, 58 checks plus one per US time zone for quiet hours (the fake provider CMS_TEST_SMS answers as Twilio would; whether a send goes now or is held depends on the hour the suite runs, and the checks follow it).

**Checks before commit:**
- Web: full_schema 1781, audit_regression, security, staging_scrub and run_all pass, and the migration rehearsal matched.
- Android on the emulator, against the local server with the fake provider: the consent was saved ("In person"); a message also sent as a text to a client in Hawaii at 7:50 a.m. their time was held until 8 a.m., the conversation showed "by text" and the app said why it waited.
- The iPhone app builds.

**Review:** every finding was fixed, except those accepted below:
- **Consent followed a new number:** the forms pre-tick the box, so changing the phone re-recorded consent for the new number. A changed number now always clears a recorded agreement and says so; the apps untick the box when the phone is edited. An agreement is refused for a number that cannot take texts.
- **Held texts went at 5:15 p.m.:** only the 7:00, 7:15 and 17:15 jobs sent them, so Central reminders from the 7:15 run waited all day. The watchdog now sends held texts every ten minutes.
- **"Cancel" lost the cancellation:** it opted the client out and told no one. It now also makes the cancel request; the reminder says "X to ask to cancel".
- **Stale held texts:** a held reminder or notice was sent after the session moved or was cancelled.
- **Merge, delete and export:** sms_log is now merged, exported, and deleted with the client (foreign key); a merge keeps either record's STOP.
- **Shared numbers:** STOP stopped only one client, and replies could be filed under the wrong one.
- **Retried app messages** were posted and texted twice (the iPhone app already sent a key the server ignored; Android now sends one too).
- **Moved sessions** kept the old text reminder flag and confirmation.
- **The web form** re-ticked an unticked consent box after a validation error.
- **Spanish:** the referral form's texting agreement had no Spanish, and Spanish texts had English dates.
- **Smaller:** sends are claimed first (no double send when two jobs overlap); homework older than two days is never texted (no backlog when texting is set up later); error 21610 (unsubscribed at Twilio) opts the client out; a staff member's STOP turns off their alert texts; the Twilio call times out after 10 seconds inside a webhook.

**Accepted:**
- A client with no state or time zone is texted by the office's time zone.
- A staff message longer than 1,600 characters is cut to 1,600 in the text (the full message stays in the portal; the box says the limit).
- With Twilio Advanced Opt-Out on, START and HELP may be answered twice (by Twilio and by the system).

## Deployed

2026-10-17 13:07 CT with `.audit_work/make_deploy.sh 4.16.0 4.17.0` and its deploy.sh, after CI passed all three jobs on 6c520ce. Every replaced file matched 4.16.0 first. The website, cron folder and database were backed up to <private dir>/deploy-4.17.0-20261017-130705. All 17 migration statements ran and the new columns and tables are present; every installed file matches the release. health.php reports 4.17.0, and the sign-in page and updates.json show 4.17.0. Both webhooks (sms_inbound.php, sms_status.php) refuse an unsigned request (403). Texting stays off until the Twilio account is entered on Settings, Text messages.
