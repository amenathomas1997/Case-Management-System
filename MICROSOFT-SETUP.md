# Microsoft 365 setup

The CMS uses Microsoft 365 for three things. You set them up as two app registrations in Microsoft Entra:

| App registration | Works as | What it does | Needed? |
|---|---|---|---|
| **CMS Mail** | the system itself, with no one signed in | sends every email (sign-in codes, invitations, reminders, backups) and files forwarded emails to client records | Recommended. Without it the server's own `mail()` is used, which is often marked as spam |
| **CMS Sign-in and Teams** | each staff member | "Sign in with Microsoft", Teams meeting links and the Outlook calendar sync | Optional |

You can use one app for everything (Microsoft Entra ID federation has a "Copy from email settings" button). Two apps is safer: the mail app's permissions reach a whole mailbox, so its secret should be kept in one place only.

Below, `https://cms.example.org` stands for your CMS address. The CMS also shows the exact addresses on its own setup pages.

## Before you start

1. You need an account with the **Global Administrator** or **Application Administrator** role in the [Microsoft Entra admin center](https://entra.microsoft.com).
2. In the Microsoft 365 admin center, create a mailbox for the system, for example `cms@example.org`. A **shared mailbox** works and needs no licence.
   - All system email comes from it.
   - Anything forwarded to it with a client's full name in the subject becomes a note on that client's file.

## App 1: CMS Mail

1. In Entra, open **Identity → Applications → App registrations → New registration**.
   - Name: `CMS Mail`.
   - Supported account types: **Accounts in this organizational directory only**.
   - Redirect URI: leave empty.
   - Choose **Register**.
2. On the app's **Overview** page, copy the **Application (client) ID** and the **Directory (tenant) ID**.
3. Open **API permissions → Add a permission → Microsoft Graph → Application permissions** and add:
   - **Mail.Send**: sends all system email.
   - **Mail.ReadWrite**: needed for two things:
     - Email-to-client-file: reads the mailbox, makes its "Filed to CMS" and "Needs review (CMS)" folders and moves messages into them.
     - The weekly off-site backup: a backup larger than 2 MB is sent as a draft with an uploaded attachment.
   - **User.Read.All**: only if you turn on the nightly check that switches off staff whose Microsoft 365 account is disabled.
4. Choose **Grant admin consent for (your organisation)**. Every permission should then show "Granted".
5. Open **Certificates & secrets → New client secret**.
   - Choose the longest expiry (24 months).
   - Copy the **Value** at once; it is shown only once.
   - Write down the expiry date.
6. In the CMS, open **Administration → Settings → Microsoft Graph email** and enter:
   - the directory (tenant) ID
   - the application (client) ID
   - the client secret
   - the sending mailbox (`cms@example.org`)

   Saving checks the values with Microsoft and refuses them if Microsoft does not accept them.
7. In **Settings → Email addresses**, set the From address to the same mailbox.
8. In **Administration → System health**, enter the secret's expiry date. The CMS warns you before it runs out. If mail stops, an expired secret is the first thing to check.

The same four values can instead go in the `graph` block of the private `config.php`. Values entered in Settings win.

### Optional: limit the app to that one mailbox

The Mail.Send and Mail.ReadWrite permissions on their own reach every mailbox in your organisation. Exchange Online "RBAC for Applications" limits them to the system's mailbox. In Exchange Online PowerShell, run:

```powershell
New-ServicePrincipal -AppId <client ID> -ObjectId <object ID> -DisplayName "CMS Mail"
New-ManagementScope -Name "CMS mailbox" -RecipientRestrictionFilter "PrimarySmtpAddress -eq 'cms@example.org'"
New-ManagementRoleAssignment -App <client ID> -Role "Application Mail.Send" -CustomResourceScope "CMS mailbox"
New-ManagementRoleAssignment -App <client ID> -Role "Application Mail.ReadWrite" -CustomResourceScope "CMS mailbox"
```

- The object ID is under **Enterprise applications → CMS Mail**, not App registrations.
- Then **remove** Mail.Send and Mail.ReadWrite from the app's API permissions in Entra. Permissions granted there cover every mailbox and would override the limit.
- Afterwards, sign in to the CMS again to check that the sign-in code email still arrives.

## App 2: CMS Sign-in and Teams

1. Make a **New registration**:
   - Name: `CMS Sign-in and Teams`.
   - Supported account types: accounts in this organizational directory only.
   - Redirect URI: platform **Web**, address `https://cms.example.org/sso.php`.
2. Open **Authentication → Web → Add URI** and add two more addresses:
   - `https://cms.example.org/login.php`: signs a device that was handed over for signing in person out of Microsoft.
   - `https://cms.example.org/meeting_connect.php?provider=microsoft`: used by Teams meetings and the Outlook calendar.
3. Open **Token configuration → Add optional claim → ID**.
   - Tick **acct** and **auth_time** and add them. If Microsoft asks to turn on the Microsoft Graph profile permission, accept.
   - Both claims are required:
     - **acct**: without it, a person's first Microsoft sign-in cannot be matched to their CMS account by email.
     - **auth_time**: without it, Microsoft sign-in is refused on a device that was handed over for signing.
4. Open **API permissions → Microsoft Graph → Delegated permissions** and add:
   - **openid, profile, email, offline_access and User.Read**: sign-in
   - **OnlineMeetings.ReadWrite**: Teams meeting links
   - **Calendars.ReadWrite**: sessions in each person's Outlook calendar

   Then choose **Grant admin consent**, so staff are not asked one by one.
5. Under **Certificates & secrets**, make a client secret. Copy the Value and write down the expiry.
6. **Microsoft sign-in:** in the CMS, open **Administration → Set up Microsoft Entra ID federation**.
   - Enter the tenant ID, client ID and secret.
   - Leave "Automatically create accounts for new Microsoft users" **Off**. Then only people who already have a CMS account with the same email can sign in.
   - Save, choose **Test connection to Microsoft**, then turn Microsoft sign-in on.
   - Password sign-in keeps working alongside it.
7. **Teams and Outlook calendar:**
   - In **Settings → Online meetings (Zoom and Teams)**, enter the Microsoft tenant ID, the app client ID and the secret. Enter the tenant ID: left blank, any work account's tenant is used, which an app registered for your organization only refuses.
   - Teams links need a work or school account with a Teams licence. A personal Microsoft account can't make them.
   - Each staff member then opens **My Account**, connects Microsoft, and can turn on "Put my sessions in my Outlook calendar".
   - The same Settings section decides whether calendar events show the client's first name or only "Client session".

### Optional: choose who can use Microsoft sign-in

In **Enterprise applications → CMS Sign-in and Teams → Properties**, set **Assignment required** to Yes. Then add your staff, or a group of them, under **Users and groups**.

## Check it works

1. **Email:** sign out, sign in again, and check that the code email arrives from the system's mailbox.
2. **Email to client file:** forward an email to the mailbox with a test client's full name in the subject. Within 15 minutes it is a note on that client's file and sits in "Filed to CMS".
3. **Microsoft sign-in:** in a private browser window, choose "Sign in with Microsoft".
4. **Teams:** schedule a session as a Teams meeting. It should have a join link and appear in the staff member's Outlook calendar.
5. **Connections:** the CMS renews each staff member's Microsoft connection every day. If Microsoft ends one (for example, after a password reset or a sign-in policy), My Account shows **Connect Microsoft Teams again**, and the person gets one notice. Disconnecting Microsoft turns Outlook calendar sync off first and removes the session events it added.
6. **Offboarding (optional):** turn on the nightly Microsoft 365 check in **Administration → People & permissions**. It needs User.Read.All on the mail app.

## Keeping it working

- **Secrets expire** after at most 24 months. Put each expiry date in a calendar, make a new secret a few weeks before, and enter it in the same CMS page.
- **Backups never hold the secrets.** After restoring a backup, enter them again: Settings for the email and Teams secrets, and Microsoft Entra ID federation for the sign-in secret.
- **Mail stopped?** Check System health first. It shows the secret's expiry date and whether mail is going out.
