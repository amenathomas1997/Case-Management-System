package org.casemanagement.app

import org.json.JSONObject

/*
 * The server's replies read into plain values (4.26.0): each screen shows what these return, and
 * the unit tests run the same functions over real replies captured from the server
 * (src/test/resources/api), so a renamed or dropped field fails a test before it blanks a screen.
 * Nothing here touches Android or Compose.
 */

/** A number the server may send as a number, a numeric string or null. */
private fun JSONObject.num(key: String): Double = if (isNull(key)) 0.0 else optDouble(key, 0.0).takeIf { !it.isNaN() } ?: 0.0
private fun JSONObject.intOrNull(key: String): Int? = if (isNull(key) || !has(key)) null else optInt(key, Int.MIN_VALUE).takeIf { it != Int.MIN_VALUE }
private fun JSONObject.numOrNull(key: String): Double? = if (isNull(key) || !has(key)) null else optDouble(key).takeIf { !it.isNaN() }
private fun JSONObject.text(key: String): String = str(key) ?: ""

/* ---------- me ---------- */

data class Me(
    val id: Int, val name: String, val perms: List<String>, val scoped: Boolean, val aiEnabled: Boolean,
    val aiFeatures: List<String>, val accessHold: List<String>, val serverVersion: String?, val timezone: String?,
    /** 4.34.0: whether client identifiers are stripped before AI (null: an older server does not say). */
    val aiRedaction: Boolean? = null,
)

fun parseMe(r: JSONObject) = Me(
    r.optInt("id"), r.text("name"), r.optJSONArray("perms").strings(), r.optBoolean("scoped"), r.optBoolean("ai_enabled"),
    r.optJSONArray("ai_features").strings(), r.optJSONArray("access_hold").strings(), r.str("server_version"), r.str("timezone"),
    if (r.has("ai_redaction") && !r.isNull("ai_redaction")) r.optBoolean("ai_redaction") else null,
)

/* ---------- form_options ---------- */

/** The organisation's lists (note types, equipment categories and the rest), by name. */
fun parseFormLists(r: JSONObject): Map<String, List<String>> {
    val lists = r.optJSONObject("lists") ?: return emptyMap()
    return lists.keys().asSequence().associateWith { lists.optJSONArray(it).strings() }
}

data class Office(val id: Int, val name: String)

fun parseOffices(r: JSONObject): List<Office> = r.optJSONArray("offices").objects().mapNotNull { o ->
    o.optInt("id").takeIf { it > 0 }?.let { Office(it, o.text("name")) }
}

/* ---------- clients ---------- */

data class ClientItem(val id: Int, val name: String, val status: String, val phone: String?, val email: String?) {
    val closed: Boolean get() = status.startsWith("Closed")
}

/** clients and client_choices: the same rows (client_choices has no phone or email). */
fun parseClients(r: JSONObject): List<ClientItem> = r.optJSONArray("clients").objects().mapNotNull { c ->
    c.optInt("id").takeIf { it > 0 }?.let { ClientItem(it, c.text("name"), c.text("status"), c.str("phone"), c.str("email")) }
}

/** The clients offered when choosing one for something new: closed clients are left out. */
fun openClientChoices(r: JSONObject): List<ClientItem> = parseClients(r).filter { !it.closed }

data class TrackSummary(
    val id: Int, val title: String, val status: String, val authNumber: String?, val authorizedHours: Double,
    val hoursWorked: Double, val expirationDate: String?,
)

data class NoteItem(
    val id: Int, val subject: String, val noteType: String, val createdAt: String?, val author: String?, val body: String,
    /** 4.34.0: past the edit window (an addendum is offered instead), and whether this account may edit it. */
    val locked: Boolean = false, val canEdit: Boolean = false, val addendumTo: Int? = null, val noteVersion: String? = null,
    val trackId: Int? = null, val noteFormat: String = "free", val sections: List<String> = emptyList(),
    val editor: String? = null, val trackTitle: String? = null,
    /** 4.35.0: when it was last edited (shown with the editor's name). */
    val updatedAt: String? = null,
)

data class ReleaseItem(val id: Int, val recipientName: String, val recipientType: String?, val status: String, val expiresOn: String?, val infoScope: String?)

/** One client's record as the client screen shows it (the many single fields are read from [raw]). */
data class ClientDetail(
    val id: Int, val name: String, val status: String, val phone: String?, val email: String?,
    val tracks: List<TrackSummary>, val notes: List<NoteItem>, val notesTotal: Int, val releases: List<ReleaseItem>,
    val customFields: List<Pair<String, String>>, val signaturesWaiting: Int, val has425: Boolean, val raw: JSONObject,
)

fun parseClientDetail(r: JSONObject): ClientDetail? {
    val c = r.optJSONObject("client") ?: return null
    val tracks = r.optJSONArray("tracks").objects().map { t ->
        TrackSummary(t.optInt("id"), t.text("title"), t.text("status"), t.str("auth_number"), t.num("authorized_hours"), t.num("hours_worked"), t.str("expiration_date"))
    }
    val notes = r.optJSONArray("notes").objects().map { parseNote(it) }
    val releases = r.optJSONArray("releases").objects().map { rel ->
        ReleaseItem(rel.optInt("id"), rel.text("recipient_name"), rel.str("recipient_type"), rel.text("status"), rel.str("expires_on"), rel.str("info_scope"))
    }
    val custom = c.optJSONArray("custom_fields").objects().map { it.text("label") to it.text("value") }
    return ClientDetail(c.optInt("id"), c.text("name"), c.text("status"), c.str("phone"), c.str("email"), tracks, notes,
        r.optInt("notes_total", notes.size), releases, custom, c.optInt("signatures_waiting"), c.has("device_counts"), c)
}

fun parseNote(n: JSONObject) = NoteItem(
    n.optInt("id"), n.text("subject"), n.text("note_type"), n.str("created_at"), n.str("author"), n.text("body"),
    n.optBoolean("locked"), n.optBoolean("can_edit"), n.intOrNull("addendum_to")?.takeIf { it > 0 }, n.str("note_version"),
    n.intOrNull("track_id")?.takeIf { it > 0 }, n.str("note_format") ?: "free",
    n.optJSONArray("sections").let { a -> if (a == null) emptyList() else List(a.length()) { a.optString(it) } },
    n.str("editor"), n.str("track_title"), n.str("updated_at"),
)

/** 4.34.0: an addendum's subject, "Addendum: Phone call", never "Addendum: Addendum: Phone call". */
fun addendumSubject(subject: String): String {
    val s = subject.trim()
    return if (s.startsWith("Addendum:", ignoreCase = true)) s else "Addendum: $s"
}

/** 4.34.0: what an edit refusal means: "addendum" (the note just locked), "conflict" (saved by someone since), or "error". */
fun noteEditOutcome(status: Int, reply: JSONObject?): String = when {
    status == 409 && reply?.optBoolean("locked") == true -> "addendum"
    status == 409 && reply?.optBoolean("conflict") == true -> "conflict"
    else -> "error"
}

/** 4.34.0: the website's words when an edit becomes an addendum (client.php:518). */
fun lockedToAddendumMessage(serverMessage: String?): String =
    (serverMessage?.takeIf { it.isNotBlank() } ?: "This note is locked.") + " Your changes are in the addendum form below."

/** 4.34.0: what was typed in a note, to keep for copying after a Reload: the narrative and each labelled section. */
fun typedNoteText(format: String, body: String, sections: List<String>, labels: List<String>?): String =
    if (format == "free" || labels == null) body.trim()
    else (sections.mapIndexedNotNull { i, v -> v.trim().takeIf { it.isNotEmpty() }?.let { "${labels.getOrElse(i) { "Section ${i + 1}" }}: $it" } } +
        listOfNotNull(body.trim().takeIf { it.isNotEmpty() }?.let { "Narrative: $it" })).joinToString("\n\n")

/**
 * 4.34.0: add_note's errors.hw_due_N (N counts the homework items sent, from 1) on the rows of the
 * form they came from: [sentRows] holds each sent item's row, in the order sent.
 */
fun homeworkRowErrors(reply: JSONObject?, sentRows: List<Int>): Map<Int, String> {
    val errs = reply?.optJSONObject("errors") ?: return emptyMap()
    return errs.keys().asSequence().mapNotNull { k ->
        val n = Regex("^hw_due_(\\d+)$").find(k)?.groupValues?.get(1)?.toIntOrNull() ?: return@mapNotNull null
        sentRows.getOrNull(n - 1)?.let { it to errs.optString(k) }
    }.toMap()
}

/** 4.34.0: the case notes heading; the client reply holds the newest 50. */
fun notesHeading(shown: Int, total: Int): String = if (total > shown) "Case notes (latest $shown of $total)" else "Case notes ($total)"

/** The website's words (client.php:461) when Free text is chosen but sections still hold text. */
const val FREE_TEXT_SECTIONS_LOST = "Free text is chosen as the note format, but the structured sections still hold text that a free-text note does not keep. " +
    "Move anything you need from the sections into the narrative box and clear them, or choose a structured format again."

/** 4.34.0: true when [format] is Free text and a section still holds text that would be lost. */
fun sectionsLost(format: String, sections: List<String>): Boolean = format == "free" && sections.any { it.isNotBlank() }

/**
 * 4.34.0: what add_note's reply says, as the website (client.php:623): homework added to the
 * portal and the session warning. Null [reply] (kept offline) gives null: the queued words stay.
 */
fun noteSavedMessage(reply: JSONObject?, saved: String = "Case note saved."): String? {
    if (reply == null) return null
    if (reply.optBoolean("deduplicated")) return "This was already saved from an earlier try, so it was not added twice."
    val n = reply.optInt("homework_created")
    val hw = if (n > 0) " $n homework ${if (n == 1) "item" else "items"} added to the client portal." else ""
    val warn = reply.str("warning")?.let { " $it" } ?: ""
    return saved + hw + warn
}

data class StatusChange(val id: Int, val newStatus: String, val oldStatus: String?, val changedAt: String?, val changedBy: String, val note: String?)

fun parseClientHistory(r: JSONObject): List<StatusChange> = r.optJSONArray("history").objects().map { h ->
    StatusChange(h.optInt("id"), h.text("new_status"), h.str("old_status"), h.str("changed_at"), h.text("changed_by"), h.str("note"))
}

/* ---------- schedule and tracks ---------- */

data class SessionItem(
    val id: Int, val startAt: String?, val endAt: String?, val client: String, val clientId: Int, val status: String,
    val location: String?, val isRemote: Boolean, val instructor: String?, val checkedInAt: String?, val groupSize: Int,
    val overdue: Boolean, val meetingUrl: String?, val raw: JSONObject,
) {
    /** 4.31.0: completed, and its case note not written yet (note_pending). */
    val notePending: Boolean get() = status == "Completed" && raw.optBoolean("note_pending")
}

fun parseSchedule(r: JSONObject): List<SessionItem> = r.optJSONArray("sessions").objects().map { s ->
    SessionItem(s.optInt("id"), s.str("start_at"), s.str("end_at"), s.text("client"), s.optInt("client_id"), s.text("status"),
        s.str("location"), s.optBoolean("is_remote"), s.str("instructor"), s.str("checked_in_at"), s.optInt("group_size"),
        s.optBoolean("overdue"), s.str("meeting_url"), s)
}

/** An active training track, as the tracks list (every one the account may log hours on) has it. */
data class TrackChoice(val id: Int, val title: String, val client: String, val clientId: Int, val serviceType: String?, val visitCategories: List<String>)

fun parseTracks(r: JSONObject): List<TrackChoice> = r.optJSONArray("tracks").objects().map { t ->
    TrackChoice(t.optInt("id"), t.text("title"), t.text("client"), t.optInt("client_id"), t.str("service_type"), t.optJSONArray("visit_categories").strings())
}

data class Goal(
    val id: Int, val specificGoal: String, val measurableMetric: String, val focusArea: String?, val status: String,
    val targetDate: String?, val progressPct: Int,
)

fun parseGoals(r: JSONObject): List<Goal> = r.optJSONArray("goals").objects().mapNotNull { g ->
    g.optInt("id").takeIf { it > 0 }?.let {
        Goal(it, g.text("specific_goal"), g.text("measurable_metric"), g.str("focus_area"), g.str("status") ?: "Not Started",
            g.str("target_date"), g.optString("progress_pct").toDoubleOrNull()?.toInt() ?: 0)
    }
}

data class GoalTemplate(val id: Int, val category: String, val specificGoal: String, val measurableMetric: String, val focusArea: String?)
data class GoalTemplates(val templates: List<GoalTemplate>, val more: Boolean, val categories: List<String>)

fun parseGoalTemplates(r: JSONObject) = GoalTemplates(
    r.optJSONArray("templates").objects().map { GoalTemplate(it.optInt("id"), it.text("category"), it.text("specific_goal"), it.text("measurable_metric"), it.str("focus_area")) },
    r.optBoolean("more"), r.optJSONArray("categories").strings(),
)

data class GoalProgressEntry(val progressPct: Int, val status: String, val note: String?, val createdAt: String?, val byName: String?)

fun parseGoalProgress(r: JSONObject): List<GoalProgressEntry> = r.optJSONArray("history").objects().map { h ->
    GoalProgressEntry(h.optString("progress_pct").toDoubleOrNull()?.toInt() ?: 0, h.text("status"), h.str("note"), h.str("created_at"), h.str("by_name"))
}

data class WorkLog(val id: Int, val workDate: String?, val hours: Double, val mileage: Double, val description: String, val isBilled: Boolean, val author: String?)

fun parseWorkLogs(r: JSONObject): List<WorkLog> = r.optJSONArray("logs").objects().mapNotNull { l ->
    l.optInt("id").takeIf { it > 0 }?.let { WorkLog(it, l.str("work_date"), l.num("hours"), l.num("mileage"), l.text("description"), l.optBoolean("is_billed"), l.str("author")) }
}

/** A track's case note (track_notes), with whether this account may still edit it. */
data class TrackNotes(val notes: List<NoteItem>, val total: Int, val clientId: Int)

fun parseTrackNotes(r: JSONObject): TrackNotes {
    val notes = r.optJSONArray("notes").objects().map { parseNote(it) }
    return TrackNotes(notes, r.optInt("notes_total", notes.size), r.optInt("client_id"))
}

/* ---------- messages, documents, homework ---------- */

data class ThreadSummary(val clientId: Int, val clientName: String, val unread: Int, val lastAt: String?, val unreadClient: Int = 0, val unreadCounselor: Int = 0,
    /** 4.36.0: the last message (cut at 60 characters) and who wrote it ("You: ", "<name>: " or ""). */
    val lastBody: String? = null, val lastSenderLabel: String? = null)

fun parseMessageThreads(r: JSONObject): List<ThreadSummary> = r.optJSONArray("threads").objects().map { t ->
    ThreadSummary(t.optInt("client_id"), t.text("client_name"), t.optInt("unread"), t.str("last_at"),
        // 4.34.0: by channel; an older server's single count is the client's.
        if (t.has("unread_client")) t.optInt("unread_client") else t.optInt("unread"), t.optInt("unread_counselor"),
        t.str("last_body"), if (t.isNull("last_sender_label")) null else t.optString("last_sender_label"))
}

/** 4.36.0: how many conversations there are in all, and how many unread messages; null from an older server. */
fun parseThreadTotals(r: JSONObject): Pair<Int, Int>? = if (r.has("total")) r.optInt("total") to r.optInt("unread_total") else null

/** 4.34.0: a conversation opens on the channel with the unread messages, as on the iPhone. */
fun startChannel(unreadClient: Int, unreadCounselor: Int): String = if (unreadClient == 0 && unreadCounselor > 0) "counselor" else "client"

/** 4.34.0: a conversation row's unread words, by channel. */
fun threadUnreadWords(t: ThreadSummary): String = listOfNotNull(
    t.unreadClient.takeIf { it > 0 }?.let { "$it unread from the client" },
    t.unreadCounselor.takeIf { it > 0 }?.let { "$it unread from the counselor" },
).joinToString(", ")

/** 4.34.0: both channels are always offered; the counselor is named when known. */
fun threadChannelOptions(counselorName: String?): List<Pair<String, String>> =
    listOf("client" to "The client", "counselor" to (counselorName?.takeIf { it.isNotBlank() }?.let { "The counselor, $it" } ?: "The counselor"))

data class Message(val id: Int, val who: String, val body: String, val createdAt: String?, val byText: Boolean, val documentId: Int?, val fileLabel: String?)
data class MessageThread(val clientName: String, val counselorName: String?, val textEnabled: Boolean, val textBlocked: String?, val messages: List<Message>,
    /** 4.36.0: the counselor's email is not confirmed yet, said before typing on the counselor channel. */
    val counselorUnconfirmed: Boolean = false, val counselorUnconfirmedText: String? = null)

fun parseMessageThread(r: JSONObject): MessageThread {
    val client = r.text("client_name")
    val counselor = r.str("counselor_name")
    val messages = r.optJSONArray("messages").objects().map { m ->
        val who = m.str("staff_name") ?: when (m.optString("sender")) { "client" -> client; "counselor" -> counselor ?: "The counselor"; else -> "Staff" }
        Message(m.optInt("id"), who, m.text("body"), m.str("created_at"), m.str("via") == "text", m.intOrNull("document_id")?.takeIf { it > 0 }, messageFileLabel(m))
    }
    return MessageThread(client, counselor, r.optBoolean("text_enabled"), r.str("text_blocked"), messages,
        r.optBoolean("counselor_unconfirmed"), r.str("counselor_unconfirmed_text"))
}

data class DocumentItem(
    val id: Int, val title: String, val category: String, val createdAt: String?, val portalShared: Boolean,
    val counselorShared: Boolean, val uploadedVia: String?,
    /** 4.37.0 (S16): this user may move it to the trash (key categories are administrators' only). */
    val canDelete: Boolean = false,
) {
    /** A Resource, or a file the client sent, is always in their portal. */
    val portalFixed: Boolean get() = category in setOf("Resource", "Shared by client") || uploadedVia == "portal"
}

fun parseDocuments(r: JSONObject): List<DocumentItem> = r.optJSONArray("documents").objects().map { d ->
    DocumentItem(d.optInt("id"), d.str("title") ?: d.text("orig_name"), d.text("category"), d.str("created_at"),
        d.optBoolean("portal_shared"), d.optBoolean("counselor_shared"), d.str("uploaded_via"), org.casemanagement.app.ui.flag(d, "can_delete"))
}

/** Who can see a document besides staff, in words. */
fun sharedWords(d: DocumentItem): String {
    val with = listOfNotNull(if (d.portalShared) "the client" else null, if (d.counselorShared) "the counselor" else null)
    return if (with.isEmpty()) "Not shared" else "Shared with " + with.joinToString(" and ")
}

data class HomeworkItem(
    val id: Int, val title: String, val status: String, val dueDate: String?, val trackTitle: String?, val instructions: String?,
    /** 4.35.0: sent back with an edit (at_homework_update), and when the client reported it done. */
    val editToken: String? = null, val clientReportedAt: String? = null,
)

fun parseHomework(r: JSONObject): List<HomeworkItem> = r.optJSONArray("homework").objects().map { h ->
    HomeworkItem(h.optInt("id"), h.text("title"), h.text("status"), h.str("due_date"), h.str("track_title"), h.str("instructions"),
        h.str("edit_token"), h.str("client_reported_at"))
}

/* ---------- documents to sign and releases ---------- */

data class SignatureItem(
    val id: Int, val title: String, val status: String, val method: String, val signerName: String?,
    val sentAt: String?, val signedAt: String?, val signedName: String?, val hasDrawnSignature: Boolean,
) {
    /** Still open: it can be signed (by email, or here on this phone) or withdrawn. */
    val open: Boolean get() = status == "Awaiting signature" || status == "Waiting to be signed here"
    val signedHere: Boolean get() = status == "Waiting to be signed here"
}

data class Loan(val id: Int, val name: String, val serial: String?)
data class Signatures(val items: List<SignatureItem>, val kinds: List<Pair<String, String>>, val openLoans: List<Loan>)

fun parseSignatures(r: JSONObject): Signatures {
    val items = r.optJSONArray("signatures").objects().map { s ->
        SignatureItem(s.optInt("id"), s.text("title"), s.text("status"), s.text("method"), s.str("signer_name"), s.str("sent_at"),
            s.str("signed_at"), s.str("signed_name"), s.optBoolean("has_drawn_signature"))
    }
    // In the website's order (a JSON object's keys may come in any order): the known kinds first.
    val order = listOf("services", "loan", "document")
    val kinds = r.optJSONObject("kinds")?.let { k -> k.keys().asSequence().map { it to k.optString(it) }.sortedBy { (key, _) -> order.indexOf(key).let { i -> if (i < 0) order.size else i } }.toList() }
        ?: listOf("services" to "Consent to services", "loan" to "Equipment loan agreement", "document" to "Another document")
    val loans = r.optJSONArray("open_loans").objects().map { Loan(it.optInt("id"), it.text("name"), it.str("serial")) }
    return Signatures(items, kinds, loans)
}

/**
 * A document to sign in person, as start_signature answers and the signature action sends it
 * (the "signature" object). Everything the signing view shows, so it can be kept on the phone.
 */
data class SignatureDoc(val id: Int, val title: String, val body: String, val lang: String?, val signerName: String, val org: String, val sentAt: String? = null)

fun parseSignatureDoc(o: JSONObject?): SignatureDoc? {
    if (o == null) return null
    val id = o.optInt("id").takeIf { it > 0 } ?: return null
    return SignatureDoc(id, o.text("title"), o.text("body"), o.str("lang"), o.text("signer_name"), o.text("org"), o.str("sent_at"))
}

/** The signature action's reply. */
fun parseSignatureReply(r: JSONObject): SignatureDoc? = parseSignatureDoc(r.optJSONObject("signature"))

fun SignatureDoc.toJson(): JSONObject = JSONObject().put("id", id).put("title", title).put("body", body).put("lang", lang ?: JSONObject.NULL)
    .put("signer_name", signerName).put("org", org).put("sent_at", sentAt ?: JSONObject.NULL)

data class ReleaseChoices(val recipientTypes: List<String>, val infoItems: List<String>, val validityDays: Int)

fun parseReleaseChoices(r: JSONObject) = ReleaseChoices(r.optJSONArray("recipient_types").strings(), r.optJSONArray("info_items").strings(), r.optInt("validity_days"))

/* ---------- referrals, tasks, alerts, dashboard ---------- */

data class ReferralDeadlines(
    val referrals: Int, val contactedOnTime: Int, val contactedLate: Int, val waiting: Int, val overdueNow: Int,
    val assessedOnTime: Int, val assessedLate: Int,
) {
    val worthShowing: Boolean get() = referrals > 0 || overdueNow > 0
}

fun parseReferralDeadlines(r: JSONObject) = ReferralDeadlines(
    r.optInt("referrals"), r.optInt("contacted_on_time"), r.optInt("contacted_late"), r.optInt("waiting"), r.optInt("overdue_now"),
    r.optInt("assessed_on_time"), r.optInt("assessed_late"),
)

data class ReferralItem(val id: Int, val clientName: String, val reason: String?, val status: String, val createdAt: String?, val counselorAgency: String?, val assignedName: String?,
    /** 4.37.0 (S19): "Contact overdue since <date>" / "Contact by <date>", from the open contact task. */
    val contactText: String? = null)

fun parseReferrals(r: JSONObject): List<ReferralItem> = r.optJSONArray("referrals").objects().map { x ->
    ReferralItem(x.optInt("id"), x.text("client_name"), x.str("reason"), x.text("status"), x.str("created_at"), x.str("counselor_agency"), x.str("assigned_name"), x.str("contact_text"))
}

data class TaskItem(
    val id: Int, val title: String, val details: String?, val clientId: Int?, val clientName: String?, val assignedName: String?,
    val status: String, val daysLeft: Int?, val canOpenClient: Boolean, val dueDate: String? = null,
    // 4.36.0: the referral it is about, whether the system made it, and who it is for.
    val canOpenReferral: Boolean = false, val referralId: Int? = null, val referralName: String? = null,
    val automatic: Boolean = false, val assignedTo: Int? = null,
) {
    val open: Boolean get() = status == "Open"
}

fun parseTasks(r: JSONObject): List<TaskItem> = r.optJSONArray("tasks").objects().map { t ->
    TaskItem(t.optInt("id"), t.text("title"), t.str("details"), t.intOrNull("client_id"), t.str("client_name"), t.str("assigned_name"),
        t.text("status"), t.intOrNull("days_left"), t.optBoolean("can_open_client"), t.str("due_date"),
        t.optBoolean("can_open_referral"), t.intOrNull("referral_id"), t.str("referral_name"), t.optBoolean("automatic"), t.intOrNull("assigned_to"))
}

/** 4.34.0: a task that is done is never "overdue": its due date is said plainly. [dayWords] formats the date. */
fun doneTaskDueText(t: TaskItem, dayWords: (String) -> String = { it }): String =
    if (t.open) dueWords(t.daysLeft) else t.dueDate?.let { "due " + dayWords(it) } ?: "no due date"

/** 4.34.0: the unread alerts listed, as mark_notifications_read's ids (comma-separated). */
fun markReadIds(items: List<AlertItem>): String = items.filter { it.unread }.joinToString(",") { it.id.toString() }

/** 4.34.0: "Mark these 3 as read" when more are unread than listed, else "Mark all as read". */
fun markReadLabel(listedUnread: Int, totalUnread: Int): String = if (totalUnread > listedUnread) "Mark these $listedUnread as read" else "Mark all as read"

/** When a task is due, in words. */
fun dueWords(daysLeft: Int?): String = when {
    daysLeft == null -> "no due date"
    daysLeft < 0 -> "overdue by ${-daysLeft} ${if (daysLeft == -1) "day" else "days"}"
    daysLeft == 0 -> "due today"
    daysLeft == 1 -> "due tomorrow"
    else -> "due in $daysLeft days"
}

data class AlertItem(val id: Int, val message: String, val createdAt: String?, val unread: Boolean, val link: String? = null)
data class Alerts(val items: List<AlertItem>, val unread: Int)

fun parseNotifications(r: JSONObject) = Alerts(
    r.optJSONArray("notifications").objects().map { AlertItem(it.optInt("id"), it.text("message"), it.str("created_at"), it.optBoolean("unread"), it.str("link")) },
    r.optInt("unread"),
)

data class ExpiringTrack(val id: Int, val client: String, val title: String, val expirationDate: String?, val expired: Boolean, val clientId: Int = 0)
data class RecentReferral(val id: Int, val clientName: String, val reason: String, val status: String, val createdAt: String?)
data class RecentNote(val id: Int, val subject: String, val noteType: String, val clientId: Int, val clientName: String, val createdAt: String?)
data class Dashboard(
    val activeClients: Int, val pendingClients: Int, val newReferrals: Int, val unbilledHours: Double, val unbilledAmount: Double,
    val expiring: List<ExpiringTrack>, val expiringTotal: Int, val recentReferrals: List<RecentReferral>, val recentNotes: List<RecentNote>,
    // 4.36.0 (null or empty from an older server): Today, My tasks, Your week and the banners' counts.
    val today: List<TodayItem>? = null, val todayHeading: String? = null, val tasks: DashTasks? = null, val weekText: String? = null,
    val agreementsNeeded: Int? = null, val trainingNeeded: Int? = null, val credentialsDue: List<Pair<String, String>>? = null,
)

data class TodaySession(val id: Int, val text: String)
data class TodayItem(val kind: String, val count: Int, val text: String, val sessions: List<TodaySession>)
data class DashTask(val id: Int, val title: String, val line: String)
data class DashTasks(val items: List<DashTask>, val total: Int, val overdue: Int, val team: Boolean, val heading: String)

fun parseDashboard(r: JSONObject): Dashboard {
    val expiring = r.optJSONArray("expiring").objects().map { ExpiringTrack(it.optInt("id"), it.text("client"), it.text("title"), it.str("expiration_date"), it.optBoolean("is_expired"), it.optInt("client_id")) }
    val today = r.optJSONArray("today")?.objects()?.map { i ->
        // The contract's "sessions"; "sub" as the plan's helper names it.
        val subs = (i.optJSONArray("sessions") ?: i.optJSONArray("sub")).objects().map { s -> TodaySession(s.optInt("id", s.optInt("session_id")), s.text("text")) }
        TodayItem(i.text("kind"), i.optInt("count"), i.text("text"), subs)
    }
    val tasks = r.optJSONObject("tasks")?.let { t ->
        DashTasks((t.optJSONArray("items") ?: t.optJSONArray("rows")).objects().map { x -> DashTask(x.optInt("id"), x.text("title"), x.str("line") ?: x.text("title")) },
            t.optInt("total"), t.optInt("overdue"), t.optBoolean("team"), t.text("heading"))
    }
    return Dashboard(
        r.optInt("active_clients"), r.optInt("pending_clients"), r.optInt("new_referrals"), r.num("unbilled_hours"), r.num("unbilled_amount"),
        expiring, r.optInt("expiring_total", expiring.size),
        r.optJSONArray("recent_referrals").objects().map { RecentReferral(it.optInt("id"), it.text("client_name"), it.text("reason"), it.text("status"), it.str("created_at")) },
        r.optJSONArray("recent_notes").objects().map { RecentNote(it.optInt("id"), it.text("subject"), it.text("note_type"), it.optInt("client_id"), it.text("client_name"), it.str("created_at")) },
        today, r.str("today_heading"), tasks, r.str("week_text"),
        r.intOrNull("agreements_needed"), r.intOrNull("training_needed"),
        r.optJSONArray("credentials_due")?.objects()?.map { it.text("name") to it.text("words") },
    )
}

/* ---------- equipment ---------- */

data class EquipmentItem(
    val id: Int, val name: String, val category: String?, val serial: String?, val condition: String?, val status: String,
    val loanId: Int?, val borrower: String?, val borrowerId: Int?, val dueDate: String?, val overdue: Boolean, val held: Boolean,
    /** 4.37.0: a retired item (listed with include_retired, under Not available). */
    val retired: Boolean = false,
) {
    val canReturn: Boolean get() = loanId != null && borrowerId != null
}

fun parseEquipment(r: JSONObject): List<EquipmentItem> = r.optJSONArray("equipment").objects().map { e ->
    EquipmentItem(e.optInt("id"), e.text("name"), e.str("category"), e.str("serial"), e.str("item_condition"), e.text("status"),
        e.intOrNull("loan_id"), e.str("borrower"), e.intOrNull("borrower_id"), e.str("due_date"), e.optBoolean("overdue"), e.optBoolean("held"), e.optBoolean("retired", e.optString("status") == "Retired"))
}

/** The equipment action lists an item once per open loan: each item once, for choosing one. */
fun distinctItems(list: List<EquipmentItem>): List<EquipmentItem> = list.distinctBy { it.id }

data class ServiceEntry(val serviceDate: String?, val serviceType: String, val notes: String?, val cost: Double?, val byName: String?)
data class EquipmentDetail(
    val id: Int, val name: String, val category: String?, val serial: String?, val condition: String, val status: String,
    val lastServiceDate: String?, val intervalDays: Int?, val value: Double?, val purchaseDate: String?, val usefulLifeMonths: Int?,
    val bookValue: Double?, val notes: String?, val maintenance: List<ServiceEntry>,
    /** How many service entries there are in all (4.29.0; the list holds the latest ones). */
    val maintenanceTotal: Int = maintenance.size,
    /** 4.37.0 (S1): what the status buttons need; statusActions is null from an older server (no buttons). */
    val onLoan: Boolean = false, val inWorkflow: Boolean = false, val holds: Int = 0,
    val statusActions: List<String>? = null, val retireText: String = "", val manageable: Boolean = true,
)

fun parseEquipmentDetail(r: JSONObject): EquipmentDetail? {
    val i = r.optJSONObject("item") ?: return null
    return EquipmentDetail(i.optInt("id"), i.text("name"), i.str("category"), i.str("serial"), i.text("item_condition"), i.text("status"),
        i.str("last_service_date"), i.intOrNull("maintenance_interval_days"), i.numOrNull("value"), i.str("purchase_date"),
        i.intOrNull("useful_life_months"), i.numOrNull("book_value"), i.str("notes"),
        r.optJSONArray("maintenance").objects().map { m -> ServiceEntry(m.str("service_date"), m.text("service_type"), m.str("notes"), m.numOrNull("cost"), m.str("by_name")) })
        .let { d -> d.copy(maintenanceTotal = listTotal(r, d.maintenance.size, "maintenance_total"),
            onLoan = r.optBoolean("on_loan"), inWorkflow = r.optBoolean("in_workflow"), holds = r.optInt("holds"),
            statusActions = r.optJSONArray("status_actions")?.let { a -> (0 until a.length()).map { a.optString(it) }.filter { it.isNotEmpty() } },
            retireText = r.optString("retire_text"), manageable = r.optBoolean("manageable", true)) }
}

/** A service interval typed as whole days from 0 to 3650 (0: no schedule); null when it is not one. */
fun intervalDays(raw: String): Int? {
    val t = raw.trim()
    if (t.isEmpty() || t.length > 4 || !t.all { it in '0'..'9' }) return null
    return t.toInt().takeIf { it <= 3650 }
}

data class Reservation(
    val id: Int, val status: String, val neededFrom: String?, val neededUntil: String?, val notes: String?,
    val itemName: String, val itemStatus: String, val clientName: String, val clientId: Int,
)

fun parseReservations(r: JSONObject): List<Reservation> = r.optJSONArray("reservations").objects().map { x ->
    Reservation(x.optInt("id"), x.text("status"), x.str("needed_from"), x.str("needed_until"), x.str("notes"), x.text("item_name"),
        x.text("item_status"), x.text("client_name"), x.optInt("client_id"))
}

/** What add_reservation did, in words (Reserved now, or on the waitlist). */
fun reservationWords(r: JSONObject): String = when (r.str("status")) {
    "Waitlist" -> "Added to the waitlist. The item is not available now."
    "Reserved" -> "Item reserved."
    else -> "Reservation saved."
}

/* ---------- the action center and the AI case briefing ---------- */

data class ActionItem(val kind: String, val title: String, val due: String?, val url: String)

fun parseWorkflowActions(r: JSONObject): List<ActionItem> = r.optJSONArray("items").objects().map {
    ActionItem(it.text("kind"), it.text("title"), it.str("due"), it.text("url"))
}

data class BriefSource(val id: String, val label: String, val url: String)
data class BriefItem(val text: String, val isFact: Boolean, val sources: List<BriefSource>)

/** workflow_briefing's items: facts from the record and proposed next steps, each with its sources. */
fun parseBriefing(r: JSONObject): List<BriefItem> = r.optJSONArray("items").objects().map { item ->
    val src = item.optJSONObject("sources")
    val sources = src?.keys()?.asSequence()?.sorted()?.mapNotNull { k ->
        src.optJSONObject(k)?.let { s -> BriefSource(k, s.text("label").ifEmpty { k }, s.text("url")) }
    }?.toList() ?: emptyList()
    BriefItem(item.text("text"), item.optString("type") == "fact", sources)
}

/**
 * A page on the organisation's website, from a path the server gave (track.php?id=3): only a
 * relative path on the same site, never another site or another scheme. Null when it is not one.
 */
fun sitePage(apiUrl: String, path: String): String? {
    val p = path.trim()
    if (p.isEmpty() || p.contains("://") || p.startsWith("//") || p.startsWith("/") || p.contains('\\') || p.any { it.isWhitespace() }) return null
    if (Regex("^[A-Za-z][A-Za-z0-9+.-]*:").containsMatchIn(p)) return null
    val base = apiUrl.substringBefore('?').substringBeforeLast('/')
    if (!base.startsWith("https://") && !base.startsWith("http://")) return null
    return "$base/$p"
}
