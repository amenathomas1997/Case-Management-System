# Zoom setup

With Zoom connected, a remote session can get its Zoom meeting link made automatically, in the instructor's own Zoom account.

- **An administrator registers the CMS with Zoom once.** That gives the CMS a Client ID and a Client secret.
- **Each staff member connects their own Zoom account** on My Account. The CMS never sees their Zoom password.

Microsoft Teams links work the same way. For Teams, see [MICROSOFT-SETUP.md](MICROSOFT-SETUP.md), "App 2: CMS Sign-in and Teams".

Below, `https://cms.example.org` stands for your CMS address. The Administrator guide in the CMS (Help) shows the exact address for your site.

## Before you start

- You need a Zoom account that can build apps in the [Zoom App Marketplace](https://marketplace.zoom.us). A Zoom account owner or admin is best, because they can also let everyone on the account use the app.
- Each staff member who will connect needs a Zoom account. Licensed (paid) accounts are best: free accounts limit meetings to 40 minutes.

## Register the CMS with Zoom

1. **Make the app.** Sign in to the Zoom App Marketplace and choose **Develop → Build App**.
   - Choose **General App**, then **Create**.
   - Give it a name your staff will recognise, for example `CMS meetings`.
2. **Basic Information:**
   - Under "How will the app be managed?", choose **User-managed**. Each person connects their own Zoom account.
   - Copy the **Client ID** and **Client Secret** shown under App Credentials.
   - Under **OAuth Information**, set the redirect URL for OAuth to exactly `https://cms.example.org/meeting_connect.php?provider=zoom`.
   - Add the same address to the **OAuth Allow List**.
3. **Scopes:** choose **Add Scopes** and add:
   - **meeting:write:meeting**: makes the meeting and its join link
   - **user:read:user**: reads who connected, so the CMS can show which Zoom account is linked
4. **Let your staff use it:**
   - Under **Local Test → Add app**, add the app to your own Zoom account to try it.
   - For others on your Zoom account to connect, the account owner or an admin allows the app in **Zoom Admin → Advanced → App Marketplace Management**. Depending on your account's settings, it may need pre-approving there.
   - You do not need to publish the app to the Marketplace.
5. **Enter the app in the CMS:** open **Administration → Settings → Online meetings (Zoom and Teams)**. Enter the **Zoom app Client ID** and the **Zoom app Client secret**, and save.

## Each staff member connects Zoom

1. Open **My Account → Online meetings** and choose to connect Zoom.
2. Zoom asks you to sign in (if you aren't already) and to allow the app. Choose **Allow**.
3. You come back to My Account, which shows that Zoom is connected.

The CMS renews each connection every day, so it doesn't end when you go a while without making a link.

If Zoom ends it anyway (for example, someone removed the app in Zoom), My Account says the connection ended and shows **Connect Zoom again**, and you get one notice about it.

To disconnect, use the same section of My Account. That also withdraws the app at Zoom.

## Making a meeting link

1. On the **Schedule**, add or edit a session and tick **Remote / telepractice session**.
2. Choose **Make the meeting link**. The link is made in the instructor's own Zoom account, and it goes into the session, the reminders and the calendar invitation.

How the meetings are set up:
- **Waiting room:** on, so the instructor lets the client in.
- **Join before host:** off.
- **Moving a session:** it keeps its link. A single Zoom meeting expires about 30 days after the time it was made for, so a session moved more than 25 days later gets a new link instead.
- **Sessions in a series:** they share one recurring Zoom link with no fixed time, which works whenever you meet.
- **Self-booked sessions** (clients booking themselves) get their link the same way, when the instructor has connected Zoom.

## Check it works

1. Connect your own Zoom account on My Account.
2. Book a remote session for yourself with **Make the meeting link**.
3. Open the link. It should start a Zoom meeting in your account, with the waiting room on.
4. In Zoom's web portal, under **Meetings**, the meeting is listed with the session's title.

## If something goes wrong

- **"Zoom is not connected for this instructor, or its connection has ended":** the instructor connects again on My Account. This happens when someone removes the app in Zoom, or the Client secret changes.
- **"Zoom is busy (too many requests)":** wait a minute and try again.
- **Any other refusal:** the message includes Zoom's own words, which name the problem.
- **Zoom says the redirect URL is invalid:** the address in Zoom must match `https://cms.example.org/meeting_connect.php?provider=zoom` exactly, including `https` and `?provider=zoom`. It must be both the redirect URL and on the allow list.
- **A staff member can't allow the app:** the Zoom account admin needs to allow or pre-approve it (step 4 above).
- **After restoring the CMS to another server:** copy the `meeting_tokens.key` file from the private folder beside `config.php` as well, or everyone has to connect again. Connections are kept encrypted with that key, never in the database or its backups.
- **The Client secret:** if it is ever regenerated in Zoom, enter the new one in Settings. Everyone then connects again.
