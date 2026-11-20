package org.casemanagement.app

import org.casemanagement.app.ui.ALERT_EMAIL_CHOICES
import org.casemanagement.app.ui.AVAILABILITY_NOT_OFFERED
import org.casemanagement.app.ui.BULK_EMPTY
import org.casemanagement.app.ui.PASSWORD_NEEDED
import org.casemanagement.app.ui.PRIVACY_DISCARD_TEXT
import org.casemanagement.app.ui.PRIVACY_DISCARD_TITLE
import org.casemanagement.app.ui.PRIVACY_NOT_OFFERED
import org.casemanagement.app.ui.alertSitePath
import org.casemanagement.app.ui.consentsIntro
import org.casemanagement.app.ui.deviceActionBody
import org.casemanagement.app.ui.deviceActionErrors
import org.casemanagement.app.ui.followupsCutText
import org.casemanagement.app.ui.tasksAppliedText
import org.casemanagement.app.ui.unknownActionText
import org.casemanagement.app.ui.BULK_NONE
import org.casemanagement.app.ui.DeviceRow
import org.casemanagement.app.ui.MICROSOFT_LINE
import org.casemanagement.app.ui.TASKS_CUT_TEXT
import org.casemanagement.app.ui.alertEmailLabel
import org.casemanagement.app.ui.alertRoute
import org.casemanagement.app.ui.alertSettingsBody
import org.casemanagement.app.ui.atAGlanceRows
import org.casemanagement.app.ui.availabilityBody
import org.casemanagement.app.ui.availabilityLocalErrors
import org.casemanagement.app.ui.bannerRows
import org.casemanagement.app.ui.bulkBody
import org.casemanagement.app.ui.bulkButton
import org.casemanagement.app.ui.bulkLabel
import org.casemanagement.app.ui.codeInput
import org.casemanagement.app.ui.codeReady
import org.casemanagement.app.ui.complianceRoute
import org.casemanagement.app.ui.complianceSections
import org.casemanagement.app.ui.connectionsRows
import org.casemanagement.app.ui.copyLinkShown
import org.casemanagement.app.ui.credentialBody
import org.casemanagement.app.ui.credentialFieldFor
import org.casemanagement.app.ui.credentialLocalErrors
import org.casemanagement.app.ui.credentialsBannerText
import org.casemanagement.app.ui.dashboardTasksShown
import org.casemanagement.app.ui.deleteConfirmTitle
import org.casemanagement.app.ui.deviceButtons
import org.casemanagement.app.ui.dueChoices
import org.casemanagement.app.ui.followupTiles
import org.casemanagement.app.ui.fundsLine
import org.casemanagement.app.ui.hhmmRule
import org.casemanagement.app.ui.incidentBody
import org.casemanagement.app.ui.incidentFieldFor
import org.casemanagement.app.ui.lostConfirmText
import org.casemanagement.app.ui.lostConfirmTitle
import org.casemanagement.app.ui.microsoftLine
import org.casemanagement.app.ui.parseDevices
import org.casemanagement.app.ui.passkeyDates
import org.casemanagement.app.ui.passkeyName
import org.casemanagement.app.ui.privacyBody
import org.casemanagement.app.ui.privacyFieldKey
import org.casemanagement.app.ui.privacyFromLostRoute
import org.casemanagement.app.ui.privacyLocalErrors
import org.casemanagement.app.ui.recentLine
import org.casemanagement.app.ui.releaseKindWords
import org.casemanagement.app.ui.replyErrors
import org.casemanagement.app.ui.sentMessage
import org.casemanagement.app.ui.signInLinksShown
import org.casemanagement.app.ui.signOutConfirmTitle
import org.casemanagement.app.ui.taskActions
import org.casemanagement.app.ui.taskBody
import org.casemanagement.app.ui.taskFieldFor
import org.casemanagement.app.ui.tasksEmptyText
import org.casemanagement.app.ui.tasksQuery
import org.casemanagement.app.ui.threadPreview
import org.casemanagement.app.ui.threadsCutText
import org.casemanagement.app.ui.todayRoute
import org.casemanagement.app.ui.todayRows
import org.casemanagement.app.ui.trainingCountText
import org.casemanagement.app.ui.undoLabel
import org.casemanagement.app.ui.undoStillOffered
import org.json.JSONArray
import org.json.JSONObject
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test
import java.io.File

/** 4.36.0: the daily workspace and safety in the app, as the website and the iPhone app. */
class V436Test {

    private fun device(thisPhone: Boolean = false, live: Boolean = true, wipeRequested: Boolean = false, wiped: Boolean = false,
                       canSignOut: Boolean = true, canReportLost: Boolean = true) =
        DeviceRow(7, "Test phone", "Test phone: signed in Oct 3, 2026. In use.", thisPhone, live, wipeRequested, wiped, canSignOut, canReportLost)

    /* R1 */

    @Test fun deviceButtonsFollowTheWebsiteRule() {
        val none = org.casemanagement.app.ui.DeviceButtons(false, false)
        assertEquals(none, deviceButtons(device(thisPhone = true)))
        assertEquals(none, deviceButtons(device(wiped = true, live = false)))
        assertEquals(none, deviceButtons(device(wipeRequested = true)))
        // Ended: only Report lost and wipe.
        assertEquals(org.casemanagement.app.ui.DeviceButtons(false, true), deviceButtons(device(live = false, canSignOut = false)))
        assertEquals(org.casemanagement.app.ui.DeviceButtons(true, true), deviceButtons(device()))
    }

    @Test fun deviceWordsAreTheWebsites() {
        assertEquals("Report Test phone lost?", lostConfirmTitle("Test phone"))
        assertEquals("The next time it connects, the app removes its drafts, its unsent offline entries and its saved lists, and signs out. Entries not yet sent are lost.", lostConfirmText("Test phone"))
        assertEquals("Sign Test phone out of the app?", signOutConfirmTitle("Test phone"))
        assertEquals("privacyIncident?kind=Lost%20or%20stolen%20device&what=Test%20phone%20was%20lost%20or%20stolen.", privacyFromLostRoute("Test phone"))
    }

    @Test fun wipingNeedsThePasswordSigningOutDoesNot() {
        val lost = deviceActionBody(7, true, "secret pass")
        assertEquals("secret pass", lost.getString("password")); assertTrue(lost.getBoolean("lost")); assertEquals(7, lost.getInt("device_id"))
        val out = deviceActionBody(7, false, "secret pass")
        assertFalse(out.has("password")); assertFalse(out.getBoolean("lost"))
        // The server's refusal (api_account_refused): the password's own words on its field and in the summary.
        val (pw, summary) = deviceActionErrors(JSONObject("""{"ok":false,"error":"Password incorrect — the phone was not reported lost.","errors":{"password":"Password incorrect — the phone was not reported lost."}}"""), "Password incorrect — the phone was not reported lost.")
        assertEquals("Password incorrect — the phone was not reported lost.", pw); assertEquals(pw, summary)
        val (none, other) = deviceActionErrors(JSONObject("""{"ok":false,"error":"That phone was already signed out or reported lost."}"""), "That phone was already signed out or reported lost.")
        assertNull(none); assertEquals("That phone was already signed out or reported lost.", other)
        assertEquals("Enter your password to confirm this change. Nothing was changed.", PASSWORD_NEEDED)
    }

    @Test fun olderServerWords() {
        assertEquals(AVAILABILITY_NOT_OFFERED, unknownActionText("Unknown action.", AVAILABILITY_NOT_OFFERED))
        assertEquals("This server does not offer privacy incident reports from the app yet.", unknownActionText("Unknown action.", PRIVACY_NOT_OFFERED))
        assertEquals("No connection.", unknownActionText("No connection.", PRIVACY_NOT_OFFERED))
        assertEquals("Discard this privacy incident report?", PRIVACY_DISCARD_TITLE); assertEquals("What you typed will be lost.", PRIVACY_DISCARD_TEXT)
    }

    @Test fun parseDevicesReadsTheContract() {
        val r = JSONObject("""{"ok":true,"intro":"x","devices":[{"id":4,"name":"Pixel","signed_in_at":"2026-10-03 10:00:00","last_used_at":null,"live":true,
            "wipe_requested":false,"wiped":false,"this_phone":true,"can_sign_out":false,"can_report_lost":false,"state":"In use","text":"Pixel: signed in Oct 3, 2026. In use."}]}""")
        val d = parseDevices(r)!!.single()
        assertTrue(d.thisPhone); assertEquals("Pixel", d.name); assertEquals(4, d.id)
        assertNull(parseDevices(JSONObject("""{"ok":true}""")))
    }

    /* R2 */

    @Test fun privacyBodyLeavesOutNoClient() {
        val b = privacyBody("Lost or stolen device", " 2026-11-18 ", "  ", 0, " A phone was lost. ", " names ")
        assertFalse(b.has("client_id"))
        assertEquals("2026-11-18", b.getString("discovered_on")); assertEquals("", b.getString("occurred_on"))
        assertEquals("A phone was lost.", b.getString("description")); assertEquals("names", b.getString("info_involved"))
        assertEquals(12, privacyBody("x", "2026-11-18", "", 12, "d", "").getInt("client_id"))
    }

    @Test fun privacyLocalErrorsUseTheServerTexts() {
        val e = privacyLocalErrors("", "2026-11-20", "2026-13-01", "", "x".repeat(501), "2026-11-19")
        assertEquals("Choose what kind of incident it is.", e["kind"])
        assertEquals("Enter the date it was discovered (today or earlier).", e["discovered_on"])
        assertEquals("Enter the date it happened (today or earlier), or leave it blank.", e["occurred_on"])
        assertEquals("Describe what happened.", e["description"])
        assertEquals("Keep the information involved to 500 characters or fewer.", e["info_involved"])
        assertEquals("What happened is too long (5,000 characters maximum).", privacyLocalErrors("k", "2026-11-19", "", "x".repeat(5001), "", "2026-11-19")["description"])
        assertTrue(privacyLocalErrors("k", "2026-11-19", "", "ok", "", "2026-11-19").isEmpty())
        assertEquals("Date discovered", privacyFieldKey("discovered_on")); assertEquals("Client affected", privacyFieldKey("client_id")); assertNull(privacyFieldKey("other"))
    }

    @Test fun replyErrorsReadsTheErrorsObject() {
        assertEquals(mapOf("kind" to "Choose what kind of incident it is."), replyErrors(JSONObject("""{"ok":false,"errors":{"kind":"Choose what kind of incident it is.","x":""}}""")))
        assertTrue(replyErrors(null).isEmpty())
    }

    /* R3 */

    @Test fun alertSettingsSendOnlyTheChangedPart() {
        val m = alertSettingsBody(true, false, " 515-555-0100 ", true, "daily")!!
        assertEquals("515-555-0100", m.getString("mobile")); assertTrue(m.getBoolean("alert_sms")); assertFalse(m.has("alert_email"))
        val e = alertSettingsBody(false, true, "", false, "off")!!
        assertEquals("off", e.getString("alert_email")); assertFalse(e.has("mobile"))
        // No number: never texted. No texting on the server: sent off.
        assertFalse(alertSettingsBody(true, false, "", true, "now")!!.getBoolean("alert_sms"))
        assertFalse(alertSettingsBody(true, false, "515-555-0100", true, "now", smsEnabled = false)!!.getBoolean("alert_sms"))
        assertNull(alertSettingsBody(false, false, "", false, "now"))
    }

    @Test fun alertEmailLabels() {
        assertEquals("Urgent ones at once, and daily", alertEmailLabel("now"))
        assertEquals("Once a day", alertEmailLabel("daily")); assertEquals("Never", alertEmailLabel("off"))
        assertEquals(3, ALERT_EMAIL_CHOICES.size)
    }

    /* R4, R5 */

    @Test fun passkeyWords() {
        assertEquals("added Oct 3, 2026, last used Oct 9, 2026", passkeyDates("2026-10-03 09:00:00", "2026-10-09 12:00:00"))
        assertEquals("added Oct 3, 2026", passkeyDates("2026-10-03 09:00:00", null))
        assertEquals("Unnamed passkey", passkeyName("")); assertEquals("Work phone", passkeyName("Work phone"))
    }

    @Test fun connectionsRowsInTheWebsitesOrder() {
        // My Account shows Online meetings, then My calendar.
        val rows = connectionsRows(JSONObject("""{"kind":"microsoft","kind_name":"Microsoft (Outlook)","sync_on":true,"text":"Microsoft (Outlook): a@b.org. Sync is on."}"""),
            JSONArray("""[{"provider":"zoom","name":"Zoom","state":"not_connected","account_email":null,"text":"Zoom: not connected."},{"provider":"microsoft","name":"Microsoft Teams","state":"connected","account_email":"a@b.org","text":"Microsoft Teams: connected as a@b.org."}]"""))
        assertEquals(listOf("Zoom: not connected.", "Microsoft Teams: connected as a@b.org.", "Microsoft (Outlook): a@b.org. Sync is on."), rows)
        assertTrue(connectionsRows(null, null).isEmpty())
    }

    /* R6 */

    @Test fun availabilityBodyAndTimes() {
        assertEquals("09:00", hhmmRule("9:00")); assertEquals("12:30", hhmmRule(" 12:30 ")); assertNull(hhmmRule("25:00")); assertNull(hhmmRule("9am"))
        val online = availabilityBody("1", "9:00", "12:00", false, "Room 2")
        assertEquals("online", online.getString("mode")); assertEquals("", online.getString("location")); assertEquals("09:00", online.getString("from"))
        val inPerson = availabilityBody("3", "13:00", "15:00", true, " Main office ")
        assertEquals("in_person", inPerson.getString("mode")); assertEquals("Main office", inPerson.getString("location"))
        // The server's own sentences (inc/self_booking.php availability_add).
        val e = availabilityLocalErrors("1", "12:00", "09:00", true, "")
        assertEquals("To: the end time must be after the start time.", e["to"])
        assertEquals("Where: say where in-person sessions in these hours happen.", e["location"])
        val f = availabilityLocalErrors("8", "9am", "", false, "")
        assertEquals("Day: choose a day of the week.", f["weekday"]); assertEquals("From: enter a time, such as 09:00.", f["from"]); assertEquals("To: enter a time, such as 12:00.", f["to"])
        assertEquals("Where: keep it to 190 characters or fewer.", availabilityLocalErrors("2", "09:00", "12:00", true, "x".repeat(191))["location"])
        assertTrue(availabilityLocalErrors("2", "09:00", "12:00", false, "").isEmpty())
    }

    /* R7, R8 */

    @Test fun signInHelp() {
        assertTrue(signInLinksShown(false, ""))
        assertFalse(signInLinksShown(true, "not a server"))
        assertTrue(signInLinksShown(true, "cms.example.org"))
        assertEquals(MICROSOFT_LINE, microsoftLine(JSONObject("""{"ok":true,"microsoft":true}""")))
        assertEquals("Signed in with Microsoft on the website? Set a password with Forgot your password first.", MICROSOFT_LINE)
        assertNull(microsoftLine(JSONObject("""{"ok":true,"microsoft":false}"""))); assertNull(microsoftLine(null))
    }

    @Test fun codeStepIsSixDigits() {
        assertEquals("123456", codeInput("1234567")); assertEquals("123456", codeInput("12 34-56"))
        assertFalse(codeReady("12345")); assertTrue(codeReady("123456"))
    }

    /* R9 to R12 */

    @Test fun todayRoutesAndRows() {
        assertEquals("taskList?due=overdue", todayRoute("tasks_overdue")); assertEquals("taskList?due=today", todayRoute("tasks_today"))
        assertEquals("schedule", todayRoute("sessions")); assertEquals("messages", todayRoute("messages"))
        assertEquals("followups", todayRoute("followups")); assertEquals("portalRequests", todayRoute("portal_requests")); assertNull(todayRoute("x"))
        val rows = todayRows(listOf(TodayItem("tasks_overdue", 1, "1 task overdue", emptyList()),
            TodayItem("sessions", 2, "2 sessions today", listOf(TodaySession(5, "9:00 AM — Synthetic Client (Keyboarding)"), TodaySession(6, "1:00 PM — Sample Person (Braille)"))),
            TodayItem("messages", 1, "1 unread message from clients and counselors", emptyList())))
        assertEquals(listOf("1 task overdue", "2 sessions today", "9:00 AM — Synthetic Client (Keyboarding)", "1:00 PM — Sample Person (Braille)", "1 unread message from clients and counselors"), rows.map { it.text })
        assertTrue(rows[2].sub); assertEquals("schedule", rows[2].route)
    }

    @Test fun parseDashboardReads436Keys() {
        val d = parseDashboard(JSONObject("""{"ok":true,"active_clients":3,"pending_clients":1,"new_referrals":0,"unbilled_hours":0,"unbilled_amount":0,
            "expiring":[{"id":9,"client":"Synthetic Client","title":"Keyboarding","expiration_date":"2026-12-01","is_expired":false,"client_id":42}],
            "week_text":"This week: 4 sessions, 6 hours logged.","today_heading":"Today (2 items)",
            "today":[{"kind":"sessions","count":1,"text":"1 session today","sessions":[{"id":5,"text":"9:00 AM — Synthetic Client (Keyboarding)"}]}],
            "tasks":{"items":[{"id":1,"title":"Call","line":"Call — Synthetic Client (due today)"}],"total":1,"overdue":0,"team":false,"heading":"My tasks (1, 0 overdue — first 6 shown)"},
            "agreements_needed":1,"training_needed":0,"credentials_due":[{"name":"CPR","words":"expires in 10 days"}]}"""))
        assertEquals("This week: 4 sessions, 6 hours logged.", d.weekText)
        assertEquals(42, d.expiring[0].clientId)
        assertEquals(1, d.today!![0].sessions.size)
        assertTrue(dashboardTasksShown(d.tasks))
        assertEquals(1, d.agreementsNeeded); assertEquals(listOf("CPR" to "expires in 10 days"), d.credentialsDue)
        val old = parseDashboard(JSONObject("""{"ok":true,"expiring":[{"id":9,"client":"A","title":"T"}]}"""))
        assertNull(old.weekText); assertNull(old.today); assertNull(old.agreementsNeeded); assertEquals(0, old.expiring[0].clientId)
        assertFalse(dashboardTasksShown(old.tasks))
    }

    @Test fun bannersInTheWebsitesOrder() {
        val rows = bannerRows(2, 1, listOf("Driver's license" to "expires in 10 days"))
        assertEquals(listOf("A credential to renew", "Training to take", "2 agreements to sign"), rows.map { it.heading })
        assertEquals("Driver's license, expires in 10 days.", rows[0].text)
        assertEquals("Please read and sign them.", rows[2].text)
        assertEquals("Please read and sign it.", bannerRows(1, 0, emptyList()).single().text)
        assertEquals("3 trainings to take", bannerRows(0, 3, emptyList()).single().heading)
        assertEquals("CPR, expired; First aid, expires in 5 days.", credentialsBannerText(listOf("CPR" to "expired", "First aid" to "expires in 5 days")))
        assertTrue(bannerRows(0, 0, emptyList()).isEmpty())
    }

    @Test fun atAGlanceHidesClientCounts() {
        val d = parseDashboard(JSONObject("""{"active_clients":3,"pending_clients":1,"new_referrals":2}"""))
        assertEquals(listOf("New referrals: 2"), atAGlanceRows(d, canClients = false, canReferrals = true, canBilling = false, unbilled = "").map { it.first })
        assertEquals(listOf("Active clients: 3", "Waiting for assessment: 1"), atAGlanceRows(d, true, false, false, "").map { it.first })
    }

    /* R13 to R15 */

    private fun task(open: Boolean = true, automatic: Boolean = false, clientId: Int? = 3, referral: Boolean = false) =
        TaskItem(10, "Call the employer", null, clientId, clientId?.let { "Synthetic Client" }, null, if (open) "Open" else "Done", 0, clientId != null,
            "2026-10-03", canOpenReferral = referral, referralId = if (referral) 8 else null, automatic = automatic)

    @Test fun tasksFilters() {
        assertEquals(mapOf("show" to "open"), tasksQuery("open", " ", "any"))
        assertEquals(mapOf("show" to "all", "q" to "employer", "due" to "week"), tasksQuery("all", " employer ", "week"))
        assertEquals(mapOf("show" to "open"), tasksQuery("open", "", "later"))
        assertEquals(listOf("Any due date", "Overdue", "Due today (open)", "Next 7 days (open)", "No due date"), dueChoices().map { it.second })
        assertEquals("No tasks match these filters.", tasksEmptyText(true)); assertEquals("No tasks.", tasksEmptyText(false))
        assertEquals("Showing the first 300 matches. Narrow your filters to find more specific tasks.", TASKS_CUT_TEXT)
        assertEquals("3 tasks overdue.", tasksAppliedText(3, "overdue", true))
        assertEquals("1 task due today.", tasksAppliedText(1, "today", true))
        assertEquals("2 tasks due in the next 7 days.", tasksAppliedText(2, "week", true))
        assertEquals("4 tasks with no due date.", tasksAppliedText(4, "none", true))
        assertEquals("No tasks match these filters.", tasksAppliedText(0, "week", true))
    }

    @Test fun taskBodyAndActions() {
        val add = taskBody(0, " Call ", "", " d ", 0, 5, canAssign = false, canLinkClient = true)
        assertFalse(add.has("id")); assertFalse(add.has("assigned_to")); assertEquals(0, add.getInt("client_id")); assertEquals("Call", add.getString("title"))
        val edit = taskBody(10, "Call", "2026-10-03", "", 3, 5, canAssign = true, canLinkClient = false)
        assertEquals(10, edit.getInt("id")); assertEquals(5, edit.getInt("assigned_to")); assertFalse(edit.has("client_id"))
        assertEquals(listOf("edit", "client"), taskActions(task()))
        assertEquals(listOf("client"), taskActions(task(automatic = true)))
        assertEquals(listOf("client", "referral", "delete"), taskActions(task(open = false, referral = true), canDelete = true))
        assertEquals("title", taskFieldFor("The task needs a title: say what needs doing."))
        assertEquals("due", taskFieldFor("Due date must be a valid date (YYYY-MM-DD)."))
        assertEquals("assign", taskFieldFor("Choose an active staff member to assign the task to."))
        assertEquals("client", taskFieldFor("That client is not on your caseload."))
        assertNull(taskFieldFor("Something else."))
    }

    @Test fun bulkAndDelete() {
        assertEquals("Call the employer, Synthetic Client, due Oct 3, 2026", bulkLabel(task()))
        assertEquals("Call the employer, due Oct 3, 2026", bulkLabel(task(clientId = null)))
        assertEquals("[3,5]", bulkBody(listOf(5, 3, 5, 0)).getJSONArray("task_ids").toString())
        assertEquals("Complete the chosen tasks (2)", bulkButton(2))
        assertEquals("Nothing was completed: choose at least one open task.", BULK_NONE)
        assertEquals("No open tasks.", BULK_EMPTY)
        assertEquals("Delete the task “Call” permanently?", deleteConfirmTitle("Call"))
    }

    /* R16, R17 */

    @Test fun alertRoutes() {
        val all: (String) -> Boolean = { true }
        assertEquals("client/12", alertRoute("client.php?id=12", all))
        assertEquals("referral/4", alertRoute("referrals.php?view=4", all))
        assertEquals("taskList?due=overdue", alertRoute("tasks.php?due=overdue", all))
        assertEquals("taskList", alertRoute("tasks.php", all))
        // A malformed escape never crashes: the link opens on the website instead.
        assertNull(alertRoute("messages.php?client=%zz", all))
        assertEquals("privacy_incidents.php?id=3", alertSitePath("/privacy_incidents.php?id=3"))
        assertEquals("portalRequests", alertRoute("portal_requests.php", all))
        assertEquals("incidents", alertRoute("incidents.php", all))
        assertEquals("thread/7?channel=counselor", alertRoute("messages.php?client=7&channel=counselor", all))
        assertEquals("thread/7", alertRoute("messages.php?client=7", all))
        assertEquals("messages", alertRoute("messages.php", all))
        assertEquals("counselors", alertRoute("counselors.php", all))
        assertEquals("followups", alertRoute("followups.php", all))
        assertEquals("schedule", alertRoute("schedule.php", all))
        assertNull(alertRoute("client.php?id=12") { it != "clients" })
        assertNull(alertRoute("schedule.php") { it != "schedule" })
        assertNull(alertRoute("counselors.php") { it != "admin" })
        assertNull(alertRoute("privacy_incidents.php?id=3", all))
        assertNull(alertRoute(null, all))
    }

    @Test fun undoIsOfferedFifteenMinutes() {
        assertEquals("Undo: mark the 2 alerts just marked read as unread again", undoLabel(2))
        assertEquals("Undo: mark the 1 alert just marked read as unread again", undoLabel(1))
        assertTrue(undoStillOffered(1_000_000L, 1_000_000L + 14 * 60_000L))
        assertFalse(undoStillOffered(1_000_000L, 1_000_000L + 15 * 60_000L))
        assertFalse(undoStillOffered(0L, 5L))
    }

    /* R18, R19 */

    @Test fun conversationsAndSending() {
        assertEquals("You: See you Tuesday", threadPreview("You: ", "See you Tuesday"))
        assertEquals("Thanks", threadPreview("", "Thanks")); assertNull(threadPreview("You: ", null))
        assertEquals("Showing 2 of 5 conversations (unread first, then the most recent). Choose a client above to open any other conversation.", threadsCutText(2, 5))
        assertNull(threadsCutText(5, 5))
        assertEquals("Message sent. They were emailed that a message is waiting.", sentMessage(true, null, true))
        assertEquals("Message sent.", sentMessage(true, null, false))
        assertEquals("Not delivered yet.", sentMessage(true, "Not delivered yet.", true))
        assertTrue(sentMessage(false, null, true).startsWith("This message had already been sent"))
        val t = parseMessageThreads(JSONObject("""{"threads":[{"client_id":1,"client_name":"A","unread":0,"last_body":"Hi","last_sender_label":"You: "}],"total":3,"unread_total":1}"""))
        assertEquals("You: ", t[0].lastSenderLabel); assertEquals("Hi", t[0].lastBody)
        assertEquals(3 to 1, parseThreadTotals(JSONObject("""{"total":3,"unread_total":1}""")))
        assertNull(parseThreadTotals(JSONObject("{}")))
        val th = parseMessageThread(JSONObject("""{"client_name":"A","messages":[],"counselor_unconfirmed":true,"counselor_unconfirmed_text":"Not yet visible to the counselor."}"""))
        assertTrue(th.counselorUnconfirmed); assertEquals("Not yet visible to the counselor.", th.counselorUnconfirmedText)
    }

    /* R20 */

    @Test fun followupTilesAndRecent() {
        val r = JSONObject("""{"retention":80,"abandoned":2,"completed":10,"due":[{}],"upcoming":[],"due_total":301,"upcoming_total":4}""")
        assertEquals(listOf("Due now: 301", "Upcoming: 4", "Still in use: 80% (of 10 checks answered yes or no)", "Flagged abandoned: 2"), followupTiles(r))
        assertEquals("Still in use: — (of 0 checks answered yes or no)", followupTiles(JSONObject("""{"retention":null,"due":[{},{}]}"""))[2])
        assertEquals("Due now: 2", followupTiles(JSONObject("""{"retention":null,"due":[{},{}]}"""))[0])
        assertEquals("Synthetic Client, Screen reader, 30-day: skipped — device returned",
            recentLine(JSONObject("""{"client_name":"Synthetic Client","equipment_name":"Screen reader","interval_days":30,"outcome":"skipped — device returned"}""")))
    }

    /* R21 */

    @Test fun incidentClient() {
        assertFalse(incidentBody("2026-11-19", "Fall", "Low", "d", "", "", 0).has("client_id"))
        assertEquals(4, incidentBody("2026-11-19", "Fall", "Low", "d", "", "", 4).getInt("client_id"))
        assertEquals("client", incidentFieldFor("That client is not on your caseload."))
        assertNull(incidentFieldFor("Something went wrong."))
    }

    /* R22, R23 */

    @Test fun complianceSectionsInOrder() {
        // The server's shape (inc/compliance_data.php compliance_api_reply): each row carries its sentence.
        val r = JSONObject("""{"ok":true,
            "auth_risk":[{"id":3,"client_id":9,"title":"Keyboarding","client":"Synthetic Client","pct":95,"funds_pct":60,"funds_remaining":800,"funds_used":1200,"authorized_funds":2000,
              "expiration_date":"2026-10-01","expired":true,"text":"Synthetic Client, Keyboarding: 19 of 20 hours used (95%). Funds: $1,200.00 of $2,000.00 (60%), $800.00 remaining. Expired Oct 1, 2026."}],
            "reports_due":[],"equipment_due":[],
            "followups_due":[{"id":1,"client_id":9,"client":"Synthetic Client","equipment":"Magnifier","interval_days":30,"due_date":"2026-11-01","overdue":true,"text":"Synthetic Client — Magnifier, 30-day check · overdue since Nov 1, 2026"}],
            "followups_due_total":301,"loans_overdue":[],"contracts_risk":[],"consents":[],
            "releases_risk":[{"client_id":9,"client":"Synthetic Client","recipient_name":"State VR","recipient_type":"Agency","kind":"Withdrawn","date":"2026-10-03","text":"Synthetic Client: State VR (Agency), withdrawn Oct 3, 2026."}],
            "no_release":[],"minors_no_consent":[{"client_id":11,"client":"Sample Student","dob":"2010-05-01","pre_ets":true,"text":"Sample Student, born May 1, 2010, Pre-ETS"}],
            "no_notice":[{"client_id":9,"client":"Synthetic Client"}],
            "counts":{"auth_risk":1,"reports_due":0,"equipment_due":0,"expired":1,"consents":0,"releases":2,"followups_due":301,"loans_overdue":0},"validity_days":365}""")
        val s = complianceSections(r, canEquipment = true, canBilling = true)
        assertEquals(listOf("auth_risk", "reports_due", "equipment_due", "followups_due", "loans_overdue", "contracts_risk", "consents", "releases", "no_notice"), s.map { it.key })
        assertEquals("Authorizations at risk (1, 1 expired)", s[0].heading)
        // The server's sentence only: no title line of its own, no second funds line.
        assertEquals(listOf("Synthetic Client, Keyboarding: 19 of 20 hours used (95%). Funds: $1,200.00 of $2,000.00 (60%), $800.00 remaining. Expired Oct 1, 2026."), s[0].rows[0].lines)
        assertEquals("track/9/3", s[0].rows[0].route)
        assertEquals("All caught up.", s[1].empty)
        val fu = s.first { it.key == "followups_due" }
        assertEquals("Device follow-ups due (301)", fu.heading)
        assertEquals("Showing the first 1 of 301 checks due, oldest first.", fu.note)
        assertEquals(listOf("Synthetic Client — Magnifier, 30-day check · overdue since Nov 1, 2026"), fu.rows[0].lines)
        assertTrue(s.first { it.key == "consents" }.intro!!.startsWith("Each open client's latest signed consent when it is within 30 days of its 365-day validity"))
        val rel = s.first { it.key == "releases" }
        assertEquals("Releases of information (2)", rel.heading)
        assertEquals(listOf("Minors and Pre-ETS students with no guardian consent requested (1)", "Expiring, expired or withdrawn (1)"), rel.subs.map { it.heading })
        assertEquals("Synthetic Client: State VR (Agency), withdrawn Oct 3, 2026.", rel.subs[1].rows[0].lines.single())
        assertEquals("Privacy notice not recorded (1)", s.last().heading)
        assertEquals(listOf("Synthetic Client"), s.last().rows[0].lines)
        // Without the permissions the gated sections are left out.
        val gated = complianceSections(r, canEquipment = false, canBilling = false).map { it.key }
        assertFalse("equipment_due" in gated); assertFalse("loans_overdue" in gated); assertFalse("contracts_risk" in gated)
        // A 4.35.0 server: its fields (no text), and the funds line built here.
        val old = complianceSections(JSONObject("""{"auth_risk":[{"id":3,"title":"Keyboarding","client":"Synthetic Client","pct":95,"funds_pct":60,"funds_remaining":800.0,"expiration_date":null,"expired":false}],"reports_due":[],"equipment_due":[]}"""), true, true)
        assertEquals(listOf("auth_risk", "reports_due", "equipment_due"), old.map { it.key })
        assertEquals("Synthetic Client: Keyboarding", old[0].rows[0].lines[0])
        assertNull(followupsCutText(5, 5))
        assertEquals("expires Oct 3, 2026", releaseKindWords("Expiring", "2026-10-03"))
        assertEquals("signed by a guardian; the client turned 18 on Oct 3, 2026", releaseKindWords("Adult", "2026-10-03"))
        assertTrue(consentsIntro(365).endsWith("Send the renewal from the client's page."))
    }

    @Test fun fundsAndRoutes() {
        assertEquals("Funds: $2,000.00 of $2,000.00 (100%, used up), $0.00 remaining", fundsLine(2000.0, 2000.0, 100, 0.0))
        assertEquals("equipmentItem/4", complianceRoute("loans_overdue", JSONObject("""{"id":4}""")))
        assertEquals("contract/2", complianceRoute("contracts_risk", JSONObject("""{"id":2}""")))
        assertEquals("followups", complianceRoute("followups_due", JSONObject("""{"id":2}""")))
        assertEquals("client/9", complianceRoute("no_notice", JSONObject("""{"client_id":9}""")))
        assertNull(complianceRoute("auth_risk", JSONObject("""{"id":3}""")))
    }

    /* R24 to R26 */

    @Test fun agreementsAndTraining() {
        assertTrue(copyLinkShown("due", "12")); assertTrue(copyLinkShown("signed", "12")); assertFalse(copyLinkShown("needed", null))
        assertEquals("1 of 2 to take.", trainingCountText(listOf("done", "due")))
        assertEquals("Every training is passed and current.", trainingCountText(listOf("done")))
        assertEquals("There is no training for you to take.", trainingCountText(emptyList()))
    }

    @Test fun credentialsAsTheWebsite() {
        val b = credentialBody(0, "Other", " Orientation and mobility ", "", "", "2026-01-02", "", " renewed online ")
        assertEquals("Other", b.getString("type")); assertEquals("Orientation and mobility", b.getString("other_name"))
        assertEquals("Orientation and mobility", b.getString("name")); assertEquals("renewed online", b.getString("notes"))
        val cpr = credentialBody(4, "CPR", "ignored", "1", "", "", "", "")
        assertEquals("CPR", cpr.getString("name")); assertEquals("", cpr.getString("other_name"))
        assertEquals("other_name", credentialFieldFor("other_name")); assertEquals("type", credentialFieldFor("name")); assertNull(credentialFieldFor("x"))
        // The day is passed in, so the result never depends on the machine's date or zone. The server's
        // rule (valid_date) takes a year from 1950 to this one: next year is refused, later this year is not.
        val e = credentialLocalErrors("Other", "", "x".repeat(81), "", "2027-01-02", "", "", "2026-11-19")
        assertEquals("Name the credential, since Other is chosen.", e["other_name"])
        assertEquals("Keep this to 80 characters or fewer.", e["number"])
        assertEquals("Enter the date issued as a real date, not in the future.", e["issued_on"])
        assertNull(credentialLocalErrors("CPR", "", "", "", "2026-12-01", "", "", "2026-11-19")["issued_on"])
        assertEquals("Enter the date issued as a real date, not in the future.", credentialLocalErrors("CPR", "", "", "", "1949-12-31", "", "", "2026-11-19")["issued_on"])
        assertEquals("Enter the expiry date as a real date, or leave it empty if it does not expire.", credentialLocalErrors("CPR", "", "", "", "", "2047-01-01", "", "2026-11-19")["expires_on"])
        assertEquals("The expiry date is before the date issued.", credentialLocalErrors("CPR", "", "", "", "2026-02-01", "2026-01-01", "", "2026-11-19")["expires_on"])
        assertEquals("Choose the credential, or choose Other and name it.", credentialLocalErrors("", "", "", "", "", "", "", "2026-11-19")["type"])
        assertTrue(credentialLocalErrors("CPR", "", "", "", "", "", "", "2026-11-19").isEmpty())
    }

    /* Every dialog is the lock-aware one (LockAware.kt), never Material's directly. */

    @Test fun noDialogShowsAboveTheAppLock() {
        val dir = File("src/main/java/org/casemanagement/app/ui")
        assertTrue("run from the app module", dir.isDirectory)
        // RootScreen's own warning about a phone with no screen lock is the one allowed exception, as LockAware.kt itself.
        val offenders = dir.listFiles()!!.filter { it.name.endsWith(".kt") && it.name !in setOf("LockAware.kt", "RootScreen.kt") }
            .filter { f ->
                val t = f.readText()
                // Named outright, or reached through a wildcard import of Material.
                t.contains("androidx.compose.material3.AlertDialog") || (t.contains("import androidx.compose.material3.*") && t.contains("AlertDialog("))
            }.map { it.name }
        assertEquals(emptyList<String>(), offenders)
        listOf("V436Helpers.kt", "V436Screens.kt").forEach { assertFalse(File(dir, it).readText().contains("material3.AlertDialog")) }
    }
}
