# Release 4.32.0 — October 20, 2026

Web 4.32.0, iPhone app 4.32.0 (build 64), Android app 4.32.0 (version code 17). Apply dist/migrations/mig_4.32.0.sql, or use Database upgrade; Software update applies it on its own. It adds:
- meeting_accounts.refused_at (when Zoom or Microsoft last refused to renew a connection)
- scheduled_sessions.meeting_made_for (the start time a single meeting link was made for)
- schema_version 4.32.0

**Not deployed with deploy.sh.** It is published as the GitHub release v4.32.0, and an administrator installs it with Administration, Software update. The phone apps are built from this tag; they have no changes beyond the version.

**Why.** On 2026-10-20 the maintainer asked for step-by-step Zoom setup instructions, and to make sure the Zoom and Teams integrations work.
- **How it was checked:** an independent review compared every request the system sends with Zoom's and Microsoft's current documentation.
- **Result:** nothing would stop a correctly set-up app from connecting, making links or syncing the calendar. But connections could end without anyone knowing, and a few error and disconnect paths were weak. All of these are fixed here.

**What changed**
- **Connections are renewed every day** (cron/daily_alerts.php, meeting_renew_all).
  - **Before:** Zoom ends a connection whose refresh token goes unused for 90 days, and Microsoft one unused for 90 days by default. An instructor who rarely made links lost theirs, and My Account still said "connected".
  - **Now:** a connection is marked as ended (refused_at) only when Zoom or Microsoft refuses the person's own sign-in: Microsoft `invalid_grant`, or Zoom `invalid_grant` or "Invalid Token!". My Account then says it ended, with Connect again, and the person gets one notice. No more requests are sent until they connect again.
  - **A fault of the app itself** (an expired or changed client secret, wrong permissions or tenant) ends nobody's connection. Administrators are told once a day to check Settings.
  - **Kept for the next try:** a token the server can't read (a missing key file after a restore), and no answer or a server error.
  - **Limits:** the run covers active staff only, has a 4-minute limit, and starts with the oldest renewals.
- **Making a link:**
  - A token withdrawn before it expired (401) is renewed once and the meeting is tried again.
  - Reasons are in words: no answer, busy (429), a Microsoft account without Teams (403), and Zoom's or Microsoft's own message.
  - Tokens are renewed when under five minutes are left, so a long calendar run doesn't fail halfway.
  - A 10-second connection timeout.
- **Moving a session:** a single Zoom meeting expires about 30 days after its time, and a Teams meeting 60 days.
  - **Now:** the system keeps the time each single link was made for (meeting_made_for). A session that ends up more than 25 days past that time, after however many moves, gets a new link from the instructor's account before the emails go out.
  - **Kept as they are:** typed links (such as a personal meeting room) and a series' recurring meeting.
  - The old meeting is left in Zoom or Teams.
- **Disconnecting:**
  - **Microsoft:** Outlook calendar sync is turned off first, while the connection can still remove the session events it put in Outlook.
    - Only events Outlook confirms as removed are counted. If some stay, the person is told to delete them.
    - A failure there never stops the disconnect.
  - **Zoom:** a working access token is withdrawn (renewed first if it expired), as Zoom's revoke requires. If Zoom doesn't answer, the stored token is sent.
  - **Notices:** a renewal refused while disconnecting leaves no "connect again" notice.
- **Self-booking:** a failure while making the link no longer skips the client's email and the instructor's notice.
- **Guides:**
  - New ZOOM-SETUP.md.
  - MICROSOFT-SETUP.md updated, and both are linked from the README.
  - The in-app Administrator guide now lists Calendars.ReadWrite for Teams.

**Tests**
- tests/v432_meetings_cases.php. The test stand-in for Zoom and Microsoft now records every request (in tests only), so the tests check what is sent:
  - **Zoom connect and renewal:** Basic auth, the exact return address, no secret or scope in the body, and the rotated refresh token kept.
  - **Microsoft connect and renewal:** the app in the body, the granted scopes, the refresh token kept when none comes back, and the tenant used.
  - **Making meetings:** the Zoom body (type 2 or 3, UTC start, minutes, waiting room, no joining before the host) and the Teams body (subject, UTC start and end).
  - **A refused token:** the 401 retry, refusal marking with one notice and My Account's Connect again; connecting again restores the connection.
  - **Daily renewal:** connections renewed, refused ones marked, and unanswered ones kept.
  - **Reasons in words** for the common refusals.
  - **Disconnecting:** the Zoom revoke and the Microsoft calendar clean-up.
  - **Moving a session:** a new link after a far move.
- Checked before commit:
  - full_schema (2834), audit_regression, security, staging_scrub and run_all pass
  - the migration rehearsal matched
  - the browser accessibility check passes on 360 pages
  - iPhone: builds with no warnings, ios_api_regression passes
  - Android: build, unit tests and lint (0 errors) pass

**Review:** an independent review of these changes found the following, all fixed before commit:
- **High:**
  - **Problem:** any 400 or 401 from the token endpoint counted as the person's refusal. An expired app secret would have marked every connection as ended and told everyone.
  - **Fix:** only the grant refusals above count now. App faults tell administrators instead.
- **Medium:**
  - **An unreadable token:** it counted as a refusal. Now it is only logged.
  - **The tenant:** a change sending blank-tenant connections to the Microsoft sign-in tenant could have broken connections from other tenants. It was undone; MICROSOFT-SETUP.md says to enter the tenant ID.
  - **Moving a session:**
    - The 25-day rule measured only the last move. It now uses the time the link was made for.
    - Typed personal meeting room links could have been replaced, and group members moved far kept a link that expires. Both are fixed by meeting_made_for.
  - **Disconnect:** a long or failing Outlook clean-up could stop it.
  - **The daily renewal:** it had no time limit and included inactive staff.
- **Low:**
  - **Disconnect:** it could leave a "connect again" notice, and skipped the Zoom revoke when Zoom didn't answer.
  - **Event count:** removed events were miscounted.
  - **A waiting request:** after the lock it didn't check again whether the connection had ended.
  - **The tests:** they didn't cover app faults, and left other files' connections changed.

**Only a live test with real accounts can confirm:**
- that Zoom accepts the return address with `?provider=zoom`
- whether staff on other Zoom accounts can connect to an unpublished app (expect admin pre-approval)
- the Teams lobby and anonymous-join policy for clients
- Conditional Access policies that end Microsoft connections early
