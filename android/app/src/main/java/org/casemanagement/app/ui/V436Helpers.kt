package org.casemanagement.app.ui

import org.casemanagement.app.Dashboard
import org.casemanagement.app.TaskItem
import org.casemanagement.app.TodayItem
import org.casemanagement.app.objects
import org.casemanagement.app.str
import org.json.JSONArray
import org.json.JSONObject

/*
 * 4.36.0: the daily workspace and safety in the app (phones signed in, privacy incidents, alerts by
 * text and email, availability, the dashboard's Today, tasks, alerts, messages, follow-ups,
 * compliance, agreements, training and credentials), as the website and the iPhone app. The plain
 * rules, wording and request bodies, kept apart from Compose so the unit tests (V436Test) run them.
 * Nothing here touches Android.
 */

/** "Oct 3, 2026" from "2026-10-03" (or "2026-10-03 14:30:00"); the text as it is when it is not a date. */
fun shortDate(ymd: String?): String {
    val d = ymd?.trim()?.take(10) ?: return ""
    return try {
        java.time.LocalDate.parse(d).format(java.time.format.DateTimeFormatter.ofPattern("MMM d, yyyy", java.util.Locale.US))
    } catch (e: Exception) { d }
}

/** A refusal's field errors (`errors` in the reply), by the server's keys; empty when none. */
fun replyErrors(reply: JSONObject?): Map<String, String> {
    val o = reply?.optJSONObject("errors") ?: return emptyMap()
    return o.keys().asSequence().mapNotNull { k -> o.optString(k).takeIf { it.isNotBlank() }?.let { k to it } }.toMap()
}

/** A real year-month-day from [from] to [todayIs], or false. */
fun realDayNotFuture(text: String, todayIs: String, from: String = "2000-01-01"): Boolean {
    val t = text.trim()
    if (!Regex("^\\d{4}-\\d{2}-\\d{2}$").matches(t)) return false
    val real = try { java.time.LocalDate.parse(t); true } catch (e: Exception) { false }
    return real && t <= todayIs && t >= from
}

/* ---------------- R1: phones signed in to the app ---------------- */

const val PHONES_INTRO = "If a phone is lost or stolen, choose Report lost and wipe: the next time it connects, the app removes its drafts, unsent offline entries and saved lists, and signs out."
const val PHONES_NONE = "Not signed in to the mobile app on any phone."

data class DeviceRow(
    val id: Int, val name: String, val text: String, val thisPhone: Boolean, val live: Boolean,
    val wipeRequested: Boolean, val wiped: Boolean, val canSignOut: Boolean, val canReportLost: Boolean,
)

/** app_devices' (or device_action's) devices; null when the reply has none (an older server). */
fun parseDevices(r: JSONObject?): List<DeviceRow>? {
    val a = r?.optJSONArray("devices") ?: return null
    return a.objects().map { d ->
        DeviceRow(d.optInt("id"), d.optString("name").ifEmpty { "A phone" }, d.optString("text"), d.optBoolean("this_phone"), d.optBoolean("live"),
            d.optBoolean("wipe_requested"), d.optBoolean("wiped"), d.optBoolean("can_sign_out"), d.optBoolean("can_report_lost"))
    }
}

data class DeviceButtons(val signOut: Boolean, val reportLost: Boolean)

/**
 * The buttons a phone's row offers: none for the phone in use (Sign out covers it) or one already
 * reported lost or wiped; Sign out only while it is signed in; Report lost and wipe as the server allows.
 */
fun deviceButtons(d: DeviceRow): DeviceButtons =
    if (d.thisPhone || d.wiped || d.wipeRequested) DeviceButtons(false, false)
    else DeviceButtons(d.canSignOut && d.live, d.canReportLost)

/** The row's lines: the server's sentence, then "This phone". */
fun deviceLines(d: DeviceRow): List<String> = listOfNotNull(d.text.ifEmpty { d.name }, if (d.thisPhone) "This phone" else null)

fun signOutConfirmTitle(name: String) = "Sign $name out of the app?"
const val SIGN_OUT_CONFIRM_TEXT = "Whoever uses it has to sign in again. Entries not yet sent from it stay on it."
fun lostConfirmTitle(name: String) = "Report $name lost?"
/** The website's own confirmation (inc/records_privacy.php app_devices_html). */
fun lostConfirmText(@Suppress("UNUSED_PARAMETER") name: String = ""): String =
    "The next time it connects, the app removes its drafts, its unsent offline entries and its saved lists, and signs out. Entries not yet sent are lost."

/** The privacy incident form, opened from a phone reported lost: the kind and what happened filled in. */
fun privacyFromLostRoute(name: String): String =
    "privacyIncident?kind=" + routeArg(LOST_DEVICE_KIND) + "&what=" + routeArg("$name was lost or stolen.")

const val LOST_DEVICE_KIND = "Lost or stolen device"

/**
 * device_action's body. Reporting a phone lost (which wipes it) needs the person's current
 * password; signing a phone out does not, as the website.
 */
fun deviceActionBody(deviceId: Int, lost: Boolean, password: String): JSONObject {
    val b = JSONObject().put("device_id", deviceId).put("lost", lost)
    if (lost) b.put("password", password)
    return b
}

/** Where a device_action refusal goes: the password field, the phone, or the form's summary. */
fun deviceActionErrors(reply: JSONObject?, message: String?): Pair<String?, String?> {
    val e = replyErrors(reply)
    return e["password"] to (e["device_id"] ?: e["password"] ?: message)
}

/** Older servers answer "Unknown action." for a new action: said as the iPhone says it. */
fun unknownActionText(message: String?, instead: String): String? = if (message?.trim()?.startsWith("Unknown action") == true) instead else message

/** The server's own words for a missing password (api_account_reauth). */
const val PASSWORD_NEEDED = "Enter your password to confirm this change. Nothing was changed."

const val AVAILABILITY_NOT_OFFERED = "This server does not offer My availability in the app yet. Use My availability on the website."
const val PRIVACY_NOT_OFFERED = "This server does not offer privacy incident reports from the app yet."
const val PRIVACY_DISCARD_TITLE = "Discard this privacy incident report?"
const val PRIVACY_DISCARD_TEXT = "What you typed will be lost."

/** A value inside a route's query (spaces as %20, so it reads back as typed). */
fun routeArg(s: String): String = java.net.URLEncoder.encode(s, "UTF-8").replace("+", "%20")

/* ---------------- R2: report a privacy incident ---------------- */

const val PRIVACY_INTRO = "Report a possible privacy breach here: a report or email sent to the wrong person, a lost phone or laptop, someone seeing a record they should not, or a wrong portal link. An administrator assesses it."

/** report_privacy_incident's body: dates trimmed, an unknown date sent as "", no client left out. */
fun privacyBody(kind: String, discovered: String, occurred: String, clientId: Int, description: String, info: String): JSONObject {
    val b = JSONObject().put("kind", kind).put("discovered_on", discovered.trim()).put("occurred_on", occurred.trim())
        .put("description", description.trim()).put("info_involved", info.trim())
    if (clientId > 0) b.put("client_id", clientId)
    return b
}

/** The checks made before sending, with the website's words, by the server's keys. */
fun privacyLocalErrors(kind: String, discovered: String, occurred: String, description: String, info: String, todayIs: String): Map<String, String> = listOfNotNull(
    if (kind.isBlank()) "kind" to "Choose what kind of incident it is." else null,
    if (!realDayNotFuture(discovered, todayIs)) "discovered_on" to "Enter the date it was discovered (today or earlier)." else null,
    if (occurred.isNotBlank() && !realDayNotFuture(occurred, todayIs)) "occurred_on" to "Enter the date it happened (today or earlier), or leave it blank." else null,
    if (description.isBlank()) "description" to "Describe what happened."
        else if (description.trim().length > 5000) "description" to "What happened is too long (5,000 characters maximum)." else null,
    if (info.trim().length > 500) "info_involved" to "Keep the information involved to 500 characters or fewer." else null,
).toMap()

/** The field a server error key belongs to, by its label; null for a key the form does not have. */
fun privacyFieldKey(key: String): String? = mapOf(
    "kind" to "Kind", "discovered_on" to "Date discovered", "occurred_on" to "Date it happened",
    "client_id" to "Client affected", "description" to "What happened", "info_involved" to "Information involved",
)[key]

/* ---------------- R3: mobile number and alert emails ---------------- */

val ALERT_EMAIL_CHOICES = listOf("now" to "Urgent ones at once, and daily", "daily" to "Once a day", "off" to "Never")

/** save_alert_settings' body: only the part being saved; null when nothing is. */
fun alertSettingsBody(mobileChanged: Boolean, emailChanged: Boolean, mobile: String, sms: Boolean, alertEmail: String, smsEnabled: Boolean = true): JSONObject? {
    if (!mobileChanged && !emailChanged) return null
    val b = JSONObject()
    // Without texting on the server the tick is not shown, so it is sent off (as the website and the iPhone).
    if (mobileChanged) b.put("mobile", mobile.trim()).put("alert_sms", smsEnabled && sms && mobile.isNotBlank())
    if (emailChanged) b.put("alert_email", alertEmail)
    return b
}

/** The label of an alert-email value, from the server's list when it sent one. */
fun alertEmailLabel(value: String, choices: List<Pair<String, String>> = ALERT_EMAIL_CHOICES): String =
    choices.firstOrNull { it.first == value }?.second ?: ALERT_EMAIL_CHOICES.firstOrNull { it.first == value }?.second ?: value

/** The server's alert_email_choices, or the website's three. */
fun alertEmailChoices(a: JSONObject): List<Pair<String, String>> =
    a.optJSONArray("alert_email_choices").objects().mapNotNull { o -> o.str("value")?.let { it to o.optString("label").ifEmpty { it } } }.ifEmpty { ALERT_EMAIL_CHOICES }

/* ---------------- R4: passkeys ---------------- */

/** "added Oct 3, 2026, last used Oct 9, 2026", as the iPhone's passkeyDates. */
fun passkeyDates(created: String?, lastUsed: String?): String =
    "added " + shortDate(created) + (lastUsed?.takeIf { it.isNotBlank() && it != "null" }?.let { ", last used " + shortDate(it) } ?: "")

fun passkeyName(name: String?): String = name?.takeIf { it.isNotBlank() && it != "null" } ?: "Unnamed passkey"

/* ---------------- R5: calendar and online meetings ---------------- */

/**
 * The status sentences in the website's order on My Account (Online meetings, then My calendar);
 * empty (hidden) when the server sent none.
 */
fun connectionsRows(calendar: JSONObject?, meetings: JSONArray?): List<String> =
    meetings.objects().mapNotNull { it.str("text") } + listOfNotNull(calendar?.str("text"))

/* ---------------- R6: my availability ---------------- */

/** "9:00" or "09:00" as "09:00"; null when it is not a time of day. */
fun hhmmRule(text: String): String? {
    val m = Regex("^(\\d{1,2}):(\\d{2})$").matchEntire(text.trim()) ?: return null
    val h = m.groupValues[1].toInt(); val mi = m.groupValues[2].toInt()
    if (h > 23 || mi > 59) return null
    return "%02d:%02d".format(h, mi)
}

/** add_availability's body: the times as hour:minute, the place only in person. */
fun availabilityBody(day: String, from: String, to: String, inPerson: Boolean, where: String): JSONObject =
    JSONObject().put("weekday", day).put("from", hhmmRule(from) ?: from.trim()).put("to", hhmmRule(to) ?: to.trim())
        .put("mode", if (inPerson) "in_person" else "online").put("location", if (inPerson) where.trim() else "")

/** The local checks, in the server's own words (inc/self_booking.php availability_add). */
fun availabilityLocalErrors(day: String, from: String, to: String, inPerson: Boolean, where: String): Map<String, String> {
    val f = hhmmRule(from); val t = hhmmRule(to)
    return listOfNotNull(
        if (day !in AVAILABILITY_DAYS.map { it.first }) "weekday" to "Day: choose a day of the week." else null,
        if (f == null) "from" to "From: enter a time, such as 09:00." else null,
        if (t == null) "to" to "To: enter a time, such as 12:00."
            else if (f != null && t <= f) "to" to "To: the end time must be after the start time." else null,
        if (inPerson && where.isBlank()) "location" to "Where: say where in-person sessions in these hours happen."
            else if (inPerson && where.trim().length > 190) "location" to "Where: keep it to 190 characters or fewer." else null,
    ).toMap()
}

val AVAILABILITY_DAYS = listOf("1" to "Monday", "2" to "Tuesday", "3" to "Wednesday", "4" to "Thursday", "5" to "Friday", "6" to "Saturday", "7" to "Sunday")

/* ---------------- R7, R8: signing in ---------------- */

const val MICROSOFT_LINE = "Signed in with Microsoft on the website? Set a password with Forgot your password first."

/** The website links on Sign in: always with a built-in server; otherwise once a valid address is typed. */
fun signInLinksShown(asksForServer: Boolean, server: String, allowDevHttp: Boolean = false): Boolean =
    !asksForServer || org.casemanagement.app.ServerAddress.apiUrl(server, allowDevHttp) != null

/** The Microsoft line, when sign_in_options says the website signs in with Microsoft. */
fun microsoftLine(reply: JSONObject?): String? = if (reply?.optBoolean("microsoft") == true) MICROSOFT_LINE else null

/** The code as typed: digits only, at most 6. */
fun codeInput(typed: String): String = typed.filter { it.isDigit() }.take(6)

fun codeReady(code: String): Boolean = code.length == 6 && code.all { it.isDigit() }

/* ---------------- R9 to R12: the dashboard ---------------- */

/** Where a Today item opens. */
fun todayRoute(kind: String): String? = when (kind) {
    // The task list opened from another screen (not the Tasks tab), so it has Back and its tab stays lit.
    "tasks_overdue" -> "taskList?due=overdue"
    "tasks_today" -> "taskList?due=today"
    "sessions" -> "schedule"
    "messages" -> "messages"
    "followups" -> "followups"
    "portal_requests" -> "portalRequests"
    else -> null
}

data class TodayRow(val text: String, val route: String?, val sub: Boolean)

/** Today's rows: each item, then its sessions as their own rows under it. */
fun todayRows(items: List<TodayItem>): List<TodayRow> = items.flatMap { i ->
    listOf(TodayRow(i.text, todayRoute(i.kind), false)) + i.sessions.map { TodayRow(it.text, "schedule", true) }
}

/** My tasks is shown when the server sent it (a 4.36.0 server), even with none open. */
fun dashboardTasksShown(tasks: org.casemanagement.app.DashTasks?): Boolean = tasks != null && tasks.heading.isNotBlank()

data class BannerRow(val heading: String, val text: String, val route: String)

/** "Driver's license, expires in 10 days; CPR, expired." */
fun credentialsBannerText(list: List<Pair<String, String>>): String =
    list.joinToString("; ") { (n, w) -> if (w.isBlank()) n else "$n, $w" } + "."

/** The banners in the website's order: credentials, training, agreements. */
fun bannerRows(agreements: Int, training: Int, credentials: List<Pair<String, String>>): List<BannerRow> = listOfNotNull(
    if (credentials.isNotEmpty()) BannerRow(if (credentials.size == 1) "A credential to renew" else "${credentials.size} credentials to renew",
        credentialsBannerText(credentials), "credentials") else null,
    if (training > 0) BannerRow(if (training == 1) "Training to take" else "$training trainings to take", "Please read it and answer the questions.", "training") else null,
    if (agreements > 0) BannerRow(if (agreements == 1) "An agreement to sign" else "$agreements agreements to sign",
        if (agreements == 1) "Please read and sign it." else "Please read and sign them.", "agreements") else null,
)

/** At a glance: the client counts only with the clients permission (as the website), each with where it opens. */
fun atAGlanceRows(d: Dashboard, canClients: Boolean, canReferrals: Boolean, canBilling: Boolean, unbilled: String): List<Pair<String, String?>> = listOfNotNull(
    if (canClients) "Active clients: ${d.activeClients}" to null else null,
    if (canClients) "Waiting for assessment: ${d.pendingClients}" to null else null,
    if (canReferrals) "New referrals: ${d.newReferrals}" to "referrals" else null,
    if (canBilling) "Unbilled: $unbilled" to null else null,
)

/* ---------------- R13 to R15: tasks ---------------- */

val DUE_CHOICES = listOf("any" to "Any due date", "overdue" to "Overdue", "today" to "Due today (open)", "week" to "Next 7 days (open)", "none" to "No due date")

fun dueChoices(): List<Pair<String, String>> = DUE_CHOICES

/** The tasks query: only what is set (the server's defaults are "open" and any due date). */
fun tasksQuery(show: String, q: String, due: String): Map<String, String> = buildMap {
    put("show", if (show == "all") "all" else "open")
    q.trim().takeIf { it.isNotEmpty() }?.let { put("q", it) }
    if (due in DUE_CHOICES.map { it.first } && due != "any") put("due", due)
}

fun tasksEmptyText(filtered: Boolean): String = if (filtered) "No tasks match these filters." else "No tasks."

const val TASKS_CUT_TEXT = "Showing the first 300 matches. Narrow your filters to find more specific tasks."

/** "3 tasks overdue." said once after the filters apply. */
fun tasksAppliedText(n: Int, due: String, filtered: Boolean): String {
    if (n == 0) return tasksEmptyText(filtered)
    val tasks = "$n ${if (n == 1) "task" else "tasks"}"
    return when (due) {
        "overdue" -> "$tasks overdue."
        "today" -> "$tasks due today."
        "week" -> "$tasks due in the next 7 days."
        "none" -> "$tasks with no due date."
        else -> "$tasks."
    }
}

/** save_task's body: the id on an edit, client 0 for none, Assign to only for those who may choose it. */
fun taskBody(existingId: Int, title: String, due: String, details: String, clientId: Int, assignedTo: Int, canAssign: Boolean, canLinkClient: Boolean): JSONObject {
    val b = JSONObject()
    if (existingId > 0) b.put("id", existingId)
    b.put("title", title.trim()).put("due_date", due.trim()).put("details", details.trim())
    if (canLinkClient) b.put("client_id", clientId.coerceAtLeast(0))
    if (canAssign) b.put("assigned_to", assignedTo.coerceAtLeast(0))
    return b
}

/** What the task's dialog offers besides Done or Reopen, in order. */
fun taskActions(t: TaskItem, canDelete: Boolean = false): List<String> = listOfNotNull(
    if (t.open && !t.automatic) "edit" else null,
    if (t.canOpenClient && t.clientId != null) "client" else null,
    if (t.canOpenReferral && t.referralId != null) "referral" else null,
    if (canDelete) "delete" else null,
)

/** The field a save_task refusal belongs to. */
fun taskFieldFor(message: String?): String? {
    val m = message ?: return null
    return when {
        m.startsWith("The task needs a title") || m.startsWith("The task title") -> "title"
        m.startsWith("Due date") || m.contains("due date", ignoreCase = true) -> "due"
        m.startsWith("Choose an active staff member") -> "assign"
        m.contains("client", ignoreCase = true) || m.contains("caseload", ignoreCase = true) -> "client"
        else -> null
    }
}

/** "Call the employer, Synthetic Client, due Oct 3, 2026", as the website's list. */
fun bulkLabel(t: TaskItem): String = listOfNotNull(t.title, t.clientName?.takeIf { it.isNotBlank() }, t.dueDate?.let { "due " + shortDate(it) }).joinToString(", ")

fun bulkBody(ids: Collection<Int>): JSONObject = JSONObject().put("task_ids", JSONArray(ids.filter { it > 0 }.distinct().sorted()))

fun bulkButton(n: Int): String = "Complete the chosen tasks ($n)"

const val BULK_EMPTY = "No open tasks."

const val BULK_NONE = "Nothing was completed: choose at least one open task."

fun deleteConfirmTitle(title: String): String = "Delete the task “$title” permanently?"

/* ---------------- R16, R17: alerts ---------------- */

/**
 * Where an alert's link opens in the app, when it can (and the person may open it there); null
 * opens it on the website instead.
 */
fun alertRoute(link: String?, can: (String) -> Boolean): String? {
    val l = link?.trim()?.removePrefix("/") ?: return null
    if (l.isEmpty()) return null
    val page = l.substringBefore('?').substringBefore('#')
    val query = l.substringAfter('?', "").substringBefore('#').split('&').mapNotNull { p ->
        // A malformed escape is left as it is (never a crash): such a link opens on the website.
        p.split('=', limit = 2).takeIf { it.size == 2 }?.let { it[0] to (try { java.net.URLDecoder.decode(it[1], "UTF-8") } catch (e: IllegalArgumentException) { return null }) }
    }.toMap()
    fun id(k: String) = query[k]?.toIntOrNull()?.takeIf { it > 0 }
    return when (page) {
        "client.php" -> id("id")?.takeIf { can("clients") }?.let { "client/$it" }
        "referrals.php" -> if (!can("referrals")) null else id("view")?.let { "referral/$it" } ?: "referrals"
        "tasks.php" -> query["due"]?.takeIf { d -> DUE_CHOICES.any { it.first == d } }?.let { "taskList?due=$it" } ?: "taskList"
        "portal_requests.php" -> if (can("clients")) "portalRequests" else null
        "incidents.php" -> if (can("clients")) "incidents" else null
        "messages.php" -> if (!can("clients")) null else id("client")?.let { c ->
            "thread/$c" + (query["channel"]?.takeIf { it == "client" || it == "counselor" }?.let { "?channel=$it" } ?: "")
        } ?: "messages"
        "counselors.php" -> if (can("admin")) "counselors" else null
        "followups.php" -> if (can("clients")) "followups" else null
        "schedule.php" -> if (can("schedule")) "schedule" else null
        // 4.38.0 (T6): "A new authorization email (N) is waiting on Incoming authorizations."
        "authorizations.php" -> if (!can("clients")) null else id("id")?.let { "authorization/$it" } ?: "authorizations"
        else -> null
    }
}

/** An alert's link as a path on the website (a leading "/" is the site's own root, as the website's links are relative). */
fun alertSitePath(link: String): String = link.trim().trimStart('/')

fun undoLabel(n: Int): String = "Undo: mark the $n ${if (n == 1) "alert" else "alerts"} just marked read as unread again"

/** The undo is offered for 15 minutes after marking read, as the website's. */
fun undoStillOffered(madeAtMillis: Long, nowMillis: Long): Boolean = madeAtMillis > 0 && nowMillis - madeAtMillis in 0 until 15 * 60 * 1000L

/* ---------------- R18, R19: messages ---------------- */

/** "You: See you Tuesday", or null when the server sent no last message. */
fun threadPreview(label: String?, body: String?): String? = body?.takeIf { it.isNotBlank() }?.let { (label ?: "") + it }

fun threadsCutText(shown: Int, total: Int): String? =
    if (total > shown) "Showing $shown of $total conversations (unread first, then the most recent). Choose a client above to open any other conversation." else null

fun unreadTotalText(n: Int): String = "$n unread."

/** What a send says: the server's own warning, a repeat, or sent (and emailed). */
fun sentMessage(delivered: Boolean, warning: String?, noticed: Boolean): String = when {
    warning != null -> warning
    !delivered -> "This message had already been sent from an earlier try, so it was not sent twice."
    else -> "Message sent." + if (noticed) " They were emailed that a message is waiting." else ""
}

/* ---------------- R20: device follow-ups ---------------- */

/** The website's four tiles. */
fun followupTiles(r: JSONObject): List<String> {
    val due = if (r.has("due_total")) r.optInt("due_total") else r.optJSONArray("due")?.length() ?: 0
    val up = if (r.has("upcoming_total")) r.optInt("upcoming_total") else r.optJSONArray("upcoming")?.length() ?: 0
    val ret = if (r.isNull("retention") || !r.has("retention")) "—" else "${r.optInt("retention")}%"
    return listOf("Due now: $due", "Upcoming: $up", "Still in use: $ret (of ${r.optInt("completed")} checks answered yes or no)", "Flagged abandoned: ${r.optInt("abandoned")}")
}

/** "Synthetic Client, Screen reader, 30-day: In use · 4/5". */
fun recentLine(f: JSONObject): String {
    val outcome = f.str("outcome") ?: when (f.optString("status")) { "Skipped" -> "skipped"; else -> f.optString("status").lowercase() }
    return f.optString("client_name") + ", " + f.optString("equipment_name") + ", " + f.optInt("interval_days") + "-day: " + outcome
}

/* ---------------- R21: incidents ---------------- */

fun incidentBody(date: String, type: String, severity: String, description: String, location: String, action: String, clientId: Int): JSONObject {
    val b = JSONObject().put("incident_date", date.trim()).put("type", type).put("severity", severity)
        .put("description", description.trim()).put("location", location.trim()).put("action_taken", action.trim())
    if (clientId > 0) b.put("client_id", clientId)
    return b
}

fun incidentFieldFor(message: String?): String? {
    val m = message ?: return null
    return when {
        m.contains("caseload", ignoreCase = true) || m.contains("client", ignoreCase = true) -> "client"
        m.contains("date", ignoreCase = true) -> "date"
        m.contains("type", ignoreCase = true) -> "type"
        m.contains("happened", ignoreCase = true) -> "description"
        else -> null
    }
}

/* ---------------- R22, R23: compliance ---------------- */

data class ComplianceRow(val lines: List<String>, val route: String?)
data class ComplianceSection(val key: String, val heading: String, val empty: String?, val rows: List<ComplianceRow>, val subs: List<ComplianceSection> = emptyList(),
    /** The website's explanation under the heading, and its cut-off line. */
    val intro: String? = null, val note: String? = null)

/** The consents explanation, as compliance.php says it. */
fun consentsIntro(validDays: Int): String =
    "Each open client's latest signed consent when it is within 30 days of its $validDays-day validity, already expired, or withdrawn by the guardian — listed until a new consent is signed, with the date of any renewal request already sent — and, for clients with no signed consent, requests sent over two weeks ago that are still unsigned. Send the renewal from the client's page."

/** "Showing the first 300 of 412 checks due, oldest first." when the list is cut. */
fun followupsCutText(shown: Int, total: Int): String? = if (total > shown) "Showing the first $shown of $total checks due, oldest first." else null

/** "withdrawn Oct 3, 2026", as the website's release list. */
fun releaseKindWords(kind: String, whenDate: String?): String =
    (mapOf("Withdrawn" to "withdrawn ", "Expired" to "expired ", "Expiring" to "expires ", "Adult" to "signed by a guardian; the client turned 18 on ")[kind] ?: "") + shortDate(whenDate)

private fun moneyWords(d: Double) = "$" + String.format(java.util.Locale.US, "%,.2f", d)

/** "Funds: $1,200.00 of $2,000.00 (60%), $800.00 remaining", with ", used up" at 100%. */
fun fundsLine(used: Double, of: Double, pct: Int, remaining: Double): String =
    "Funds: ${moneyWords(used)} of ${moneyWords(of)} ($pct%" + (if (pct >= 100) ", used up" else "") + "), ${moneyWords(remaining)} remaining"

/** Where a compliance row opens. */
fun complianceRoute(section: String, row: JSONObject): String? {
    fun n(k: String) = if (row.isNull(k)) 0 else row.optInt(k)
    return when (section) {
        "auth_risk", "reports_due" -> if (n("client_id") > 0 && n("id") > 0) "track/${n("client_id")}/${n("id")}" else null
        "equipment_due", "loans_overdue" -> if (n("id") > 0) "equipmentItem/${n("id")}" else null
        "contracts_risk" -> if (n("id") > 0) "contract/${n("id")}" else null
        "followups_due" -> "followups"
        "consents", "releases_risk", "no_release", "minors_no_consent", "no_notice" -> if (n("client_id") > 0) "client/${n("client_id")}" else null
        else -> null
    }
}

private fun authLines(a: JSONObject): List<String> {
    // The server's sentence says it all (hours, funds, expiry), as the website's row.
    a.str("text")?.let { return listOf(it) }
    val hours = a.str("text") ?: ("${a.optInt("pct")}% of hours used" + (a.str("expiration_date")?.let { (if (a.optBoolean("expired")) ", ended " else ", ends ") + shortDate(it) } ?: ""))
    val funds = if (a.isNull("funds_pct") || !a.has("funds_pct") || hours.contains("Funds:")) null else {
        val of = if (a.isNull("authorized_funds")) null else a.optDouble("authorized_funds").takeIf { !it.isNaN() }
        val rem = if (a.isNull("funds_remaining")) null else a.optDouble("funds_remaining").takeIf { !it.isNaN() }
        val used = if (a.isNull("funds_used")) null else a.optDouble("funds_used").takeIf { !it.isNaN() }
        if (of != null && rem != null) fundsLine(used ?: (of - rem), of, a.optInt("funds_pct"), rem) else null
    }
    return listOfNotNull(a.optString("client") + ": " + a.optString("title"), hours, funds)
}

/**
 * The compliance page's sections, in the website's order, each with its count and its rows. A
 * section the server leaves out (equipment, loans and contracts need their permission) is left out.
 * [consentWords] gives a consent row's lines (consentRowWords on the screen).
 */
fun complianceSections(r: JSONObject, canEquipment: Boolean, canBilling: Boolean, consentWords: (JSONObject) -> List<String> = { listOf(it.optString("client")) }): List<ComplianceSection> {
    // A row is the server's own sentence when it sends one (4.36.0); an older server's fields otherwise.
    fun rows(key: String, lines: (JSONObject) -> List<String>) = r.optJSONArray(key).objects().map { o -> ComplianceRow(o.str("text")?.let { listOf(it) } ?: lines(o), complianceRoute(key, o)) }
    val out = mutableListOf<ComplianceSection>()
    val auth = r.optJSONArray("auth_risk").objects()
    val expired = r.optJSONObject("counts")?.optInt("expired") ?: auth.count { it.optBoolean("expired") }
    out += ComplianceSection("auth_risk", "Authorizations at risk (${auth.size}" + (if (expired > 0) ", $expired expired" else "") + ")",
        "Nothing needs attention right now.", rows("auth_risk", ::authLines))
    val reports = rows("reports_due") { d -> listOf(d.optString("client") + ": " + d.optString("title"), d.str("text") ?: (d.optString("cadence") + ", due " + shortDate(d.str("due")))) }
    out += ComplianceSection("reports_due", "Progress reports due (${reports.size})", "All caught up.", reports)
    if (canEquipment && r.has("equipment_due")) {
        val eq = rows("equipment_due") { e -> listOf(e.optString("name"), e.str("text") ?: ((if (e.optBoolean("overdue")) "overdue since " else "due ") + shortDate(e.str("due")))) }
        out += ComplianceSection("equipment_due", "Equipment service due (${eq.size})", "No service due.", eq)
    }
    if (r.has("followups_due")) {
        val fu = rows("followups_due") { f -> listOf(f.optString("client") + ": " + f.optString("equipment"), f.str("text") ?: "${f.optInt("interval_days")}-day check, due " + shortDate(f.str("due_date"))) }
        val total = if (r.has("followups_due_total")) r.optInt("followups_due_total") else fu.size
        out += ComplianceSection("followups_due", "Device follow-ups due ($total)", "No follow-ups are due.", fu, note = if (fu.isEmpty()) null else followupsCutText(fu.size, total))
    }
    if (canEquipment && r.has("loans_overdue")) {
        val lo = rows("loans_overdue") { l -> listOf(l.optString("name"), l.str("text") ?: ("due " + shortDate(l.str("due_date")) + (l.str("borrower")?.let { ", lent to $it" } ?: ""))) }
        out += ComplianceSection("loans_overdue", "Overdue equipment loans (${lo.size})", "No loans are overdue.", lo)
    }
    if (canBilling && r.has("contracts_risk")) {
        val ct = rows("contracts_risk") { c -> listOf(c.optString("name"), c.str("text") ?: "${c.optInt("pct")}% spent") }
        out += ComplianceSection("contracts_risk", "Contracts at risk (${ct.size})", "Nothing needs attention right now.", ct)
    }
    if (r.has("consents")) {
        val cs = rows("consents", consentWords)
        out += ComplianceSection("consents", "Consents to renew (${cs.size})", "No consents need attention.", cs,
            intro = r.optInt("validity_days").takeIf { it > 0 }?.let { consentsIntro(it) })
    }
    if (r.has("releases_risk") || r.has("no_release") || r.has("minors_no_consent")) {
        val minors = rows("minors_no_consent") { m -> listOf(m.str("text") ?: (m.optString("client") + (m.str("dob")?.let { ", born " + shortDate(it) } ?: "") + (if (m.optBoolean("pre_ets")) ", Pre-ETS" else ""))) }
        val risk = rows("releases_risk") { x -> listOf(x.str("text") ?: (x.optString("client") + ": " + x.optString("recipient_name") + " (" + x.optString("recipient_type") + "), " + releaseKindWords(x.optString("kind"), x.str("date")) + ".")) }
        val none = rows("no_release") { x -> listOf(x.optString("client")) }
        val subs = listOfNotNull(
            if (minors.isNotEmpty()) ComplianceSection("minors_no_consent", "Minors and Pre-ETS students with no guardian consent requested (${minors.size})", null, minors) else null,
            if (risk.isNotEmpty()) ComplianceSection("releases_risk", "Expiring, expired or withdrawn (${risk.size})", null, risk) else null,
            if (none.isNotEmpty()) ComplianceSection("no_release", "No release or consent on file (${none.size})", null, none) else null,
        )
        out += ComplianceSection("releases", "Releases of information (${minors.size + risk.size + none.size})", "No releases need attention.", emptyList(), subs)
    }
    val notice = rows("no_notice") { x -> listOf(x.optString("client")) }
    if (notice.isNotEmpty()) out += ComplianceSection("no_notice", "Privacy notice not recorded (${notice.size})", null, notice)
    return out
}

/* ---------------- R24 to R26: agreements, training, credentials ---------------- */

/** The signed copy is offered whenever there is a signature, whatever the state ("due" included). */
fun copyLinkShown(@Suppress("UNUSED_PARAMETER") state: String?, signatureId: String?): Boolean = !signatureId.isNullOrBlank() && signatureId != "null"

/** "1 of 2 to take." counting every module not done, as the website's My training. */
fun trainingCountText(states: List<String>): String {
    if (states.isEmpty()) return "There is no training for you to take."
    val left = states.count { it != "done" }
    return if (left > 0) "$left of ${states.size} to take." else "Every training is passed and current."
}

/** save_credential's body: the website's keys, and the name an older server reads. */
fun credentialBody(id: Int, type: String, other: String, number: String, issuer: String, issued: String, expires: String, notes: String): JSONObject {
    val name = if (type == "Other" || type.isEmpty()) other.trim() else type
    return JSONObject().put("id", id).put("type", type).put("other_name", if (type == "Other") other.trim() else "").put("name", name)
        .put("number", number.trim()).put("issuer", issuer.trim()).put("issued_on", issued.trim()).put("expires_on", expires.trim()).put("notes", notes.trim())
}

/** The form's field for a server error key (the server's keys are the field names). */
fun credentialFieldFor(key: String): String? = when (key) {
    "type", "other_name", "issued_on", "expires_on", "number", "issuer", "notes" -> key
    "name" -> "type"
    else -> null
}

/** The website's checks before sending, by the server's keys. */
fun credentialLocalErrors(type: String, other: String, number: String, issuer: String, issued: String, expires: String, notes: String, todayIs: String): Map<String, String> {
    val day = Regex("^\\d{4}-\\d{2}-\\d{2}$")
    fun real(s: String, max: String) = day.matches(s) && (try { java.time.LocalDate.parse(s); true } catch (e: Exception) { false }) && s >= "1950-01-01" && s <= max
    val iss = issued.trim(); val exp = expires.trim()
    val endOfYearPlus20 = "${todayIs.take(4).toInt() + 20}-12-31"
    val name = if (type == "Other" || type.isEmpty()) other.trim() else type
    val e = linkedMapOf<String, String>()
    if (type == "Other" && name.isEmpty()) e["other_name"] = "Name the credential, since Other is chosen."
    else if (name.isEmpty()) e["type"] = "Choose the credential, or choose Other and name it."
    else if (name.length > 120) e["other_name"] = "Keep the name to 120 characters or fewer."
    // The website's and the server's rule (valid_date): issued in a year from 1950 to this one.
    val issuedOk = iss.isEmpty() || real(iss, "${todayIs.take(4)}-12-31")
    if (!issuedOk) e["issued_on"] = "Enter the date issued as a real date, not in the future."
    val expOk = exp.isEmpty() || real(exp, endOfYearPlus20)
    if (!expOk) e["expires_on"] = "Enter the expiry date as a real date, or leave it empty if it does not expire."
    if (issuedOk && expOk && iss.isNotEmpty() && exp.isNotEmpty() && exp < iss) e["expires_on"] = "The expiry date is before the date issued."
    if (number.trim().length > 80) e["number"] = "Keep this to 80 characters or fewer."
    if (issuer.trim().length > 120) e["issuer"] = "Keep this to 120 characters or fewer."
    if (notes.trim().length > 255) e["notes"] = "Keep this to 255 characters or fewer."
    return e
}
