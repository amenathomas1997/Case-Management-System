package org.casemanagement.app.ui

import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.key
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.input.KeyboardType
import androidx.navigation.NavHostController
import org.casemanagement.app.Api
import org.casemanagement.app.ApiException
import org.casemanagement.app.Goal
import org.casemanagement.app.GoalTemplate
import org.casemanagement.app.TrackNotes
import org.casemanagement.app.WorkLog
import org.casemanagement.app.countLabel
import org.casemanagement.app.dialogKey
import org.casemanagement.app.listTotal
import org.casemanagement.app.mergeReloadedMap
import org.casemanagement.app.openedValues
import org.casemanagement.app.strings
import org.casemanagement.app.parseGoalProgress
import org.casemanagement.app.parseGoalTemplates
import org.casemanagement.app.parseGoals
import org.casemanagement.app.parseTrackNotes
import org.casemanagement.app.parseTracks
import org.casemanagement.app.parseWorkLogs
import kotlinx.coroutines.launch
import org.casemanagement.app.objects
import org.casemanagement.app.str
import org.json.JSONObject
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale
import java.util.UUID

/** Everything the track screen reads; the lists that could not be read are null (said on the screen). */
private class TrackData(
    val goals: List<Goal>, val expenses: List<JSONObject>, val amend: JSONObject, val active: Boolean,
    val logs: List<WorkLog>?, val notes: TrackNotes?, val logsTotal: Int = logs?.size ?: 0,
)

/**
 * A training track: its authorization (hours used, when it runs out), goals with their progress
 * and history, case notes filed on it, the hours logged (correct or delete one not yet billed),
 * expenses, Log hours and the emailed progress report (4.26.0, as the iPhone app). Progress and
 * expenses are kept offline as in the iPhone app.
 */
@Composable
fun TrackScreen(api: Api, nav: NavHostController, clientId: Int, trackId: Int) {
    val state = rememberLoaded(trackId) {
        val q = mapOf("track_id" to trackId.toString())
        val goals = parseGoals(api.call("goals", q))
        val expenses = api.call("expenses", q).optJSONArray("expenses").objects()
        val amend = try { api.call("amendment", mapOf("track" to trackId.toString())) } catch (e: ApiException) { JSONObject() }
        // Only active tracks take hours; the tracks list holds exactly those.
        val active = try { parseTracks(api.call("tracks")).any { it.id == trackId } } catch (e: ApiException) { true }
        val logsReply = try { api.call("work_logs", q) } catch (e: ApiException) { if (e.status == 401) throw e; null }
        val logs = logsReply?.let { parseWorkLogs(it) }
        val notes = try { parseTrackNotes(api.call("track_notes", q)) } catch (e: ApiException) { if (e.status == 401) throw e; null }
        // 4.29.0: the server's total, so a cut list says "latest N of M".
        TrackData(goals, expenses, amend, active, logs, notes, listTotal(logsReply, logs?.size ?: 0))
    }
    // 4.29.0: each dialog's opener is the item's id, kept across rotation, so the dialog comes back
    // for the same item (with what was typed) and never hands its fields to another item.
    var goalForId by rememberSaveable { mutableIntStateOf(0) }
    var progressForId by rememberSaveable { mutableIntStateOf(0) }
    var addingGoal by rememberSaveable { mutableStateOf(false) }
    var addingExpense by rememberSaveable { mutableStateOf(false) }
    var deletingExpenseId by rememberSaveable { mutableIntStateOf(0) }
    var logForId by rememberSaveable { mutableIntStateOf(0) }
    var correctingId by rememberSaveable { mutableIntStateOf(0) }
    var deletingLogId by rememberSaveable { mutableIntStateOf(0) }
    val data = state.data
    val goalFor = data?.goals?.firstOrNull { it.id == goalForId }
    val progressFor = data?.goals?.firstOrNull { it.id == progressForId }
    val logFor = data?.logs?.firstOrNull { it.id == logForId }
    val correcting = data?.logs?.firstOrNull { it.id == correctingId }
    val deletingLog = data?.logs?.firstOrNull { it.id == deletingLogId }
    val deletingExpense = data?.expenses?.firstOrNull { it.optInt("id") == deletingExpenseId }
    var openNotes by remember { mutableStateOf(setOf<Int>()) }
    var notice by remember { mutableStateOf<String?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    val scope = rememberCoroutineScope()

    Show(state, "the track") { t ->
        val a = t.amend
        val title = a.str("title") ?: "Training track"
        Page {
            Heading((a.str("client")?.let { "$it: " } ?: "") + title, large = true)
            NoticeText(notice)
            ErrorText(error)
            if (a.has("authorized")) {
                ListRow(listOf("${hoursText(a.optDouble("worked", 0.0))} of ${hoursText(a.optDouble("authorized", 0.0))} hours used (${a.optInt("pct")}%), ${hoursText(a.optDouble("remaining", 0.0))} left"))
                a.str("expiration")?.let { ListRow(listOf((if (a.optBoolean("expired")) "Ended " else "Ends ") + dayText(it))) }
                a.str("run_out")?.let { ListRow(listOf("At the recent pace the ${if (a.optString("run_out_basis") == "funds") "funds" else "hours"} run out around " + dayText(it))) }
                // 4.37.0 (S12): the amendment letter, its request and the reply, as the iPhone app.
                ListRow(listOf(AMENDMENT_ROW), action = "open it", onClick = { nav.navigate("amendment/$clientId/$trackId") })
            }
            if (t.active) PrimaryButton("Log hours") { nav.navigate("hours/$clientId/$trackId") }
            else Text("This track is not active, so hours cannot be logged on it.")
            // Sending the report needs the reports permission, as on the website.
            if (api.can("reports")) SecondaryButton("Email the progress report to the VR counselor") { nav.navigate("report/$clientId/$trackId") }
            SecondaryButton("Edit the track") { nav.navigate("trackEdit/$clientId/$trackId") }

            Heading("SMART goals (${t.goals.size})")
            if (t.goals.isEmpty()) Text("No goals yet.")
            t.goals.forEachIndexed { i, g ->
                ListRow(listOfNotNull("Goal ${i + 1} of ${t.goals.size}: " + g.specificGoal, g.measurableMetric.ifEmpty { null }?.let { "Measured by: $it" },
                    g.targetDate?.let { "Target: " + dayText(it) }, "${g.progressPct} percent, ${g.status}"),
                    action = "record progress or see its history", onClick = { goalForId = g.id })
            }
            SecondaryButton("Add a goal") { addingGoal = true }

            val notes = t.notes
            Heading(if (notes == null) "Case notes for this track" else if (notes.total > notes.notes.size) "Case notes for this track (latest ${notes.notes.size} of ${notes.total})" else "Case notes for this track (${notes.notes.size})")
            when {
                notes == null -> Text("The notes could not be loaded. Choose Refresh at the end of the page to try again.")
                notes.notes.isEmpty() -> Text("No notes on this track yet.")
            }
            notes?.notes?.forEach { n ->
                val open = n.id in openNotes
                ListRow(listOf(n.subject, n.noteType + ", " + whenText(n.createdAt) + (n.author?.let { ", by $it" } ?: "")),
                    action = if (open) "close the note" else "read the note", state = if (open) "expanded" else "collapsed",
                    onClick = { openNotes = if (open) openNotes - n.id else openNotes + n.id })
                if (open) Text(n.body)
            }
            if (notes != null && notes.total > notes.notes.size) Text("The older notes are on the website's track page.")
            SecondaryButton("Add a note on this track") { nav.navigate("trackNote/$clientId/$trackId") }

            val logs = t.logs
            Heading(if (logs == null) "Hours logged" else countLabel("Hours logged", logs.size, t.logsTotal))
            when {
                logs == null -> Text("The hours logged could not be loaded.")
                logs.isEmpty() -> Text("No hours logged on this track.")
                t.logsTotal > logs.size -> Text("The older entries are on the website's track page.")
            }
            logs?.forEach { l ->
                ListRow(listOf(workLogSummary(l), l.description), action = if (l.isBilled) null else "correct or delete it",
                    onClick = if (l.isBilled) null else ({ logForId = l.id }))
            }

            Heading("Billable expenses (${t.expenses.size})")
            if (t.expenses.isEmpty()) Text("No expenses logged on this track.")
            t.expenses.forEach { e ->
                val billed = e.optBoolean("is_billed")
                ListRow(listOf("$" + String.format(Locale.US, "%,.2f", e.optDouble("amount", 0.0)) + ", " + e.optString("description"),
                    dayText(e.str("expense_date")) + if (billed) ", on an invoice" else ", not yet billed"),
                    action = if (billed) null else "delete it", onClick = if (billed) null else ({ deletingExpenseId = e.optInt("id") }))
            }
            SecondaryButton("Add an expense") { addingExpense = true }
            SecondaryButton("Refresh") { state.reload() }
        }
    }

    goalFor?.let { g ->
        AlertDialog(
            onDismissRequest = { goalForId = 0 },
            title = { Text(g.specificGoal, modifier = Modifier.semantics { heading() }) },
            text = {
                // Scrolls (4.29.0), so a long goal at a large text size never hides the buttons.
                Column(Modifier.verticalScroll(rememberScrollState())) {
                    Text("${g.progressPct} percent, ${g.status}.")
                    TextButton(onClick = { goalForId = 0; progressForId = g.id }) { Text("Record progress") }
                    TextButton(onClick = { goalForId = 0; nav.navigate("goal/${g.id}/" + android.net.Uri.encode(g.specificGoal.ifEmpty { "-" })) }) { Text("Progress history") }
                }
            },
            confirmButton = { TextButton(onClick = { goalForId = 0 }) { Text("Close") } },
        )
    }
    logFor?.let { l ->
        AlertDialog(
            onDismissRequest = { logForId = 0 },
            title = { Text(workLogSummary(l), modifier = Modifier.semantics { heading() }) },
            text = {
                Column(Modifier.verticalScroll(rememberScrollState())) {
                    Text(l.description)
                    TextButton(onClick = { logForId = 0; correctingId = l.id }) { Text("Correct hours or miles") }
                    TextButton(onClick = { logForId = 0; deletingLogId = l.id }) { Text("Delete the entry") }
                }
            },
            confirmButton = { TextButton(onClick = { logForId = 0 }) { Text("Close") } },
        )
    }
    deletingLog?.let { l ->
        Confirm("Delete the entry?", workLogSummary(l) + ". It moves to the trash, where an administrator can restore it for 30 days.", "Delete entry", {
            error = null; notice = null
            scope.launch {
                try { notice = api.call("delete_log", body = JSONObject().put("id", l.id)).optString("message").ifEmpty { "Entry deleted." }; state.reload() }
                catch (e: ApiException) { error = e.message }
            }
        }, { deletingLogId = 0 })
    }
    deletingExpense?.let { e ->
        val what = "$" + String.format(Locale.US, "%,.2f", e.optDouble("amount", 0.0)) + " on " + dayText(e.str("expense_date"))
        Confirm("Delete the expense?", "$what, ${e.optString("description")}. It moves to the trash, where an administrator can restore it for 30 days.", "Delete expense", {
            error = null; notice = null
            scope.launch {
                try { api.call("delete_expense", body = JSONObject().put("id", e.optInt("id"))); notice = "Expense of $what moved to the trash." }
                catch (x: ApiException) { error = x.message }
                state.reload()
            }
        }, { deletingExpenseId = 0 })
    }
    // Keyed by the item (4.29.0): fields kept across rotation belong to that item only.
    correcting?.let { l -> key(dialogKey("correct", l.id)) { CorrectLogDialog(api, l, onDone = { m -> correctingId = 0; notice = m; state.reload() }, onCancel = { correctingId = 0 }) } }
    progressFor?.let { g -> key(dialogKey("progress", g.id)) { ProgressDialog(api, g, onDone = { m -> progressForId = 0; notice = m; state.reload() }, onCancel = { progressForId = 0 }) } }
    if (addingGoal) AddGoalDialog(api, trackId, onDone = { m -> addingGoal = false; notice = m; state.reload() }, onCancel = { addingGoal = false })
    if (addingExpense) ExpenseDialog(api, trackId, onDone = { m -> addingExpense = false; notice = m; state.reload() }, onCancel = { addingExpense = false })
}

/** An hours entry as it is read out: its day, hours, miles and whether it is billed. */
fun workLogSummary(l: WorkLog): String =
    dayText(l.workDate) + ": " + hoursText(l.hours) + (if (l.hours == 1.0) " hour" else " hours") +
        (if (l.mileage > 0) ", " + hoursText(l.mileage) + " miles" else "") + if (l.isBilled) ", on an invoice" else ", not yet billed"

/** A goal's progress, newest first (goal_progress). */
@Composable
fun GoalHistoryScreen(api: Api, goalId: Int, goalText: String) {
    val state = rememberLoaded(goalId) { api.call("goal_progress", mapOf("goal" to goalId.toString())).let { r -> parseGoalProgress(r).let { it to listTotal(r, it.size) } } }
    Page {
        Heading("Goal: $goalText", large = true)
        Show(state, "the history") { (list, total) ->
            // 4.29.0: a cut list says so ("latest N of M").
            Announce(if (total > list.size) "Latest ${list.size} of $total progress entries." else "${list.size} progress ${if (list.size == 1) "entry" else "entries"}.", key = list.size)
            if (list.isEmpty()) Text("No progress recorded yet.")
            if (total > list.size) Text("Showing the latest ${list.size} of $total entries. The older ones are on the website.")
            list.forEach { h ->
                ListRow(listOfNotNull("${h.progressPct}%, ${h.status}", whenText(h.createdAt) + (h.byName?.let { ", by $it" } ?: ""), h.note))
            }
        }
    }
}

/** Correct an unbilled entry's hours and miles, with a reason (correct_log). */
@Composable
private fun CorrectLogDialog(api: Api, l: WorkLog, onDone: (String) -> Unit, onCancel: () -> Unit) {
    var hours by rememberSaveable { mutableStateOf(hoursText(l.hours)) }
    var miles by rememberSaveable { mutableStateOf(hoursText(l.mileage)) }
    var reason by rememberSaveable { mutableStateOf("") }
    var error by remember { mutableStateOf<String?>(null) }
    var hoursError by remember { mutableStateOf<String?>(null) }
    var milesError by remember { mutableStateOf<String?>(null) }
    var busy by remember { mutableStateOf(false) }
    val scope = rememberCoroutineScope()
    FormDialog("Correct the entry of " + dayText(l.workDate), "Save", busy, onSave = {
        if (busy) return@FormDialog
        val h = number(hours)
        val m = number(miles.ifBlank { "0" })
        // 4.29.0: each error on its own field, as well as in the summary.
        hoursError = if (h == null) "Enter the hours as a number, such as 1.5." else null
        milesError = if (m == null) "Enter the miles as a number, or leave it empty." else null
        if (h == null || m == null) { error = errorSummary(listOf(hoursError, milesError)); return@FormDialog }
        scope.launchSave({ busy = it }, { error = it }) {
            val r = api.call("correct_log", body = JSONObject().put("id", l.id).put("hours", h).put("mileage", m).put("reason", reason.trim()))
            onDone(r.optString("message").ifEmpty { "Entry corrected." })
        }
    }, onCancel = onCancel) {
        Field("Hours", hours, { hours = it }, keyboard = KeyboardType.Decimal, error = hoursError, required = true)
        Field("Miles", miles, { miles = it }, keyboard = KeyboardType.Decimal, error = milesError)
        Field("Reason for the correction", reason, { reason = it })
        ErrorText(error)
    }
}

/** 4.34.0: an AI draft goes below what was typed, never in its place. */
fun appendDraftBelow(current: String, draft: String): String {
    val d = draft.trim()
    if (d.isEmpty()) return current
    return if (current.isBlank()) d else current.trimEnd() + "\n\n" + d
}

/**
 * 4.34.0: the report's choices and text as send_report, report_preview and the AI drafts take
 * them. [period] counts only when there is a last report; [signature] and the rest only for sending.
 * An empty [recipient] is left out (the server sends to the counselor on file).
 */
fun reportPayload(trackId: Int, kind: String, period: Boolean, lastReport: String?, intro: String? = null, barriers: String? = null,
                  recommendations: String? = null, signature: String? = null, recipient: String? = null,
                  ccSelf: Boolean? = null, sharePortal: Boolean? = null): JSONObject {
    val b = JSONObject().put("track_id", trackId).put("report_kind", if (kind == "final") "final" else "progress")
        .put("period", if (period && lastReport != null) 1 else 0)
    intro?.let { b.put("intro", it.trim()) }
    barriers?.let { b.put("barriers", it.trim()) }
    recommendations?.let { b.put("recommendations", it.trim()) }
    signature?.let { b.put("signature", it.trim()) }
    recipient?.trim()?.takeIf { it.isNotEmpty() }?.let { b.put("recipient", it) }
    ccSelf?.let { b.put("cc_self", if (it) 1 else 0) }
    sharePortal?.let { b.put("share_portal", if (it) 1 else 0) }
    return b
}

/** 4.34.0: send_report's refusals on their fields: the signature, or who it goes to. */
fun reportFieldErrors(message: String?): Map<String, String> {
    val m = message ?: return emptyMap()
    return when {
        m.contains("to sign the report") -> mapOf("signature" to m)
        m.contains("valid recipient") || m.contains("counselor email") -> mapOf("recipient" to m)
        else -> emptyMap()
    }
}

/** 4.34.0: what send_report's reply says, in one confirmation. */
fun reportSentMessage(r: JSONObject): String {
    val to = r.str("sent_to")
    val what = r.str("report_subject")?.let { "“$it”" } ?: "The report"
    return listOfNotNull(
        if (to == null) "$what was signed and emailed." else "$what was signed and emailed to $to.",
        if (r.optBoolean("copied")) "A copy was sent to you." else null,
        when { !r.has("portal_shared") || r.isNull("portal_shared") -> null; r.optBoolean("portal_shared") -> "A copy is in the client's portal."; else -> "The copy could not be put in the client's portal." },
        r.str("release_warning"),
    ).joinToString(" ")
}

/**
 * The progress or final report emailed to the VR counselor (send_report), as the website's report
 * page (4.34.0): the recipient, a personal message and barriers and recommendations (each with an
 * AI draft, appended below what was typed), the period since the last report, a copy to the sender
 * and to the client's portal, a preview, and the typed signature.
 */
@Composable
fun SendReportScreen(api: Api, nav: NavHostController, clientId: Int, trackId: Int) {
    val form = rememberLoaded(trackId) {
        try { api.call("report_form", mapOf("track_id" to trackId.toString())) } catch (e: ApiException) {
            // An older server has no report_form: the report goes to the counselor on file, shown when it can be read.
            if (e.status == 404 || e.status == 400) JSONObject().put("_older", true).also { o ->
                try { api.call("client", mapOf("id" to clientId.toString())).optJSONObject("client")?.str("counselor_email")?.let { o.put("recipient", it) } } catch (x: ApiException) { }
            } else throw e
        }
    }
    Show(form, "the report form") { f -> SendReportForm(api, nav, trackId, f) }
}

@Composable
private fun SendReportForm(api: Api, nav: NavHostController, trackId: Int, f: JSONObject) {
    val lastReport = f.str("last_report_at")
    val myEmail = f.str("my_email")
    var recipient by rememberSaveable { mutableStateOf(f.str("recipient") ?: "") }
    var intro by rememberSaveable { mutableStateOf("") }
    var kind by rememberSaveable { mutableStateOf("progress") }
    var period by rememberSaveable { mutableStateOf(lastReport != null) }
    var barriers by rememberSaveable { mutableStateOf("") }
    var recommendations by rememberSaveable { mutableStateOf("") }
    var ccSelf by rememberSaveable { mutableStateOf(true) }
    var sharePortal by rememberSaveable { mutableStateOf(false) }
    var signature by rememberSaveable { mutableStateOf("") }
    var idem by rememberSaveable { mutableStateOf("") }
    var preview by remember { mutableStateOf<String?>(null) }
    var notice by remember { mutableStateOf<String?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    var fieldErrors by remember { mutableStateOf<Map<String, String>>(emptyMap()) }
    var busy by remember { mutableStateOf(false) }
    var aiBusy by remember { mutableStateOf(false) }
    var previewing by remember { mutableStateOf(false) }
    var presses by remember { mutableIntStateOf(0) }
    val scope = rememberCoroutineScope()
    HoldBackWhileSaving(busy)
    // Send to is required, except from an older server that could not name the counselor (it sends to the one on file).
    val recipientRequired = !(f.optBoolean("_older") && f.str("recipient") == null)
    fun choices(b: JSONObject): JSONObject = reportPayload(trackId, kind, period, lastReport).let { p -> p.keys().forEach { k -> b.put(k, p.get(k)) }; b }
    fun reportBody(i: String, br: String, rc: String) = JSONObject().put("intro", i.trim()).put("barriers", br.trim()).put("recommendations", rc.trim())
    fun draft(b: JSONObject, apply: (JSONObject) -> Unit) {
        if (aiBusy) return
        aiBusy = true; error = null; notice = null
        scope.launch {
            try { apply(api.call("ai_draft", body = choices(b))) } catch (e: ApiException) { error = e.message } finally { aiBusy = false }
        }
    }
    Page {
        Heading(if (kind == "final") "Email the final report" else "Email the progress report", large = true)
        NoticeText(notice)
        Field("Send to", recipient, { recipient = it }, keyboard = KeyboardType.Email, required = recipientRequired,
            hint = if (recipientRequired) null else "optional; left empty, it goes to the counselor on file", error = fieldErrors["recipient"])
        if (f.has("counselor_verified") && !f.optBoolean("counselor_verified")) Text("The counselor's email address has not been confirmed yet. Check it before sending.")
        Choice("Report", kind, listOf("progress" to "Progress report", "final" to "Final report (closing: adds skill levels, devices and the closure)")) { kind = it }
        if (lastReport != null) Tick("Cover only the period since the last report (${dayText(lastReport)}); totals stay for the whole authorization", period) { period = it }
        Field("Personal message", intro, { intro = it }, hint = "optional", singleLine = false, minLines = 3)
        if (api.aiHas("draft")) SecondaryButton(if (aiBusy) "Drafting… this can take up to a minute" else "Draft the personal message with AI") {
            draft(JSONObject().put("type", "report_intro").put("text", intro)) { r ->
                intro = appendDraftBelow(intro, r.optString("text"))
                notice = listOfNotNull("Message drafted below what you wrote. Review it before sending.",
                    if (r.optBoolean("truncated")) "Incomplete: the AI stopped before finishing, so check the end of the draft." else null, r.str("placeholder_warning")).joinToString(" ")
            }
        }
        Field("Barriers", barriers, { barriers = it }, hint = "optional", singleLine = false, minLines = 3)
        Field("Recommendations", recommendations, { recommendations = it }, hint = "optional", singleLine = false, minLines = 3)
        if (api.aiHas("draft")) SecondaryButton(if (aiBusy) "Drafting… this can take up to a minute" else "Draft barriers and recommendations with AI") {
            draft(JSONObject().put("type", "report_sections").put("barriers", barriers).put("recommendations", recommendations)) { r ->
                barriers = appendDraftBelow(barriers, r.optString("barriers"))
                recommendations = appendDraftBelow(recommendations, r.optString("recommendations"))
                notice = "Barriers and recommendations drafted below what you wrote. Review them before sending." +
                    if (r.optBoolean("truncated")) " Incomplete: the AI stopped before finishing, so check the end of the draft." else ""
            }
        }
        Announce(if (aiBusy) "Working… This can take up to a minute." else null, key = aiBusy)
        if (myEmail != null) Tick("Send a copy to me ($myEmail)", ccSelf) { ccSelf = it }
        if (f.optBoolean("can_share_portal")) Tick("Also put a copy in the client's portal", sharePortal) { sharePortal = it }
        SecondaryButton(if (previewing) "Making the preview…" else "Preview the report") {
            if (previewing) return@SecondaryButton
            previewing = true; error = null
            scope.launch {
                try {
                    preview = api.call("report_preview", body = choices(reportBody(intro, barriers, recommendations))).optString("text")
                } catch (e: ApiException) { error = e.message } finally { previewing = false }
            }
        }
        preview?.let { p ->
            Heading("Preview")
            Text(p)
            SecondaryButton("Close the preview") { preview = null }
        }
        Field("Signature: type your full name", signature, { signature = it }, hint = api.userName, required = true, error = fieldErrors["signature"])
        Text("The signature and the time are included on the report and recorded on the client's file.")
        ErrorText(error, focusKey = presses)
        PrimaryButton("Sign and send", busy = busy, missing = stillNeeded(missingNames("Send to" to (recipientRequired && recipient.isBlank()), "Signature" to signature.isBlank()))) {
            presses++
            if (busy) return@PrimaryButton
            fieldErrors = emptyMap()
            scope.launchSave({ busy = it }, { error = it }) {
                val b = reportPayload(trackId, kind, period, lastReport, intro, barriers, recommendations, signature, recipient,
                    ccSelf = ccSelf && myEmail != null, sharePortal = sharePortal)
                // 4.28.0: the server emails before it answers, so a retry after a lost reply carries the same key and emails once (see sendOnce).
                val r = try { api.callOnce("send_report", b, idem, { idem = it }, "the report") } catch (e: ApiException) {
                    fieldErrors = reportFieldErrors(e.message)
                    throw e
                }
                Flash.pending = reportSentMessage(r)
                nav.popBackStack()
            }
        }
    }
}

/** A new SMART goal on the track, typed or started from one of the organisation's templates. */
@Composable
private fun AddGoalDialog(api: Api, trackId: Int, onDone: (String) -> Unit, onCancel: () -> Unit) {
    var specific by rememberSaveable { mutableStateOf("") }
    var metric by rememberSaveable { mutableStateOf("") }
    var focus by rememberSaveable { mutableStateOf("") }
    var target by rememberSaveable { mutableStateOf("") }
    var picking by remember { mutableStateOf(false) }
    var notice by remember { mutableStateOf<String?>(null) }
    var idem by rememberSaveable { mutableStateOf("") }
    var error by remember { mutableStateOf<String?>(null) }
    var specificError by remember { mutableStateOf<String?>(null) }
    var metricError by remember { mutableStateOf<String?>(null) }
    var targetError by remember { mutableStateOf<String?>(null) }
    var busy by remember { mutableStateOf(false) }
    val scope = rememberCoroutineScope()
    if (picking) {
        GoalTemplatePicker(api, onPick = { t ->
            specific = t.specificGoal; metric = t.measurableMetric; focus = t.focusArea ?: ""
            picking = false; notice = "Template filled in. Review the goal before adding it."
        }, onCancel = { picking = false })
        return
    }
    FormDialog("Add a goal", "Add", busy, onSave = {
        if (busy) return@FormDialog
        // 4.29.0: each error on its own field, as well as in the summary.
        specificError = if (specific.isBlank()) "Specific objective: say what the client will be able to do." else null
        metricError = if (metric.isBlank()) "Measurable criteria: say how progress on this goal is measured." else null
        targetError = if (target.isNotBlank() && !Regex("^\\d{4}-\\d{2}-\\d{2}$").matches(target.trim())) "Enter the target date as year-month-day, such as ${today()}, or leave it empty." else null
        val first = errorSummary(listOf(specificError, metricError, targetError))
        when {
            first != null -> error = first
            else -> scope.launchSave({ busy = it }, { error = it }) {
                api.callOnce("add_goal", JSONObject().put("track_id", trackId).put("specific_goal", specific.trim())
                    .put("measurable_metric", metric.trim()).put("focus_area", focus.trim()).put("target_date", target.trim()), idem, { idem = it }, "the goal")
                onDone("Goal added.")
            }
        }
    }, onCancel = onCancel) {
        NoticeText(notice)
        SecondaryButton("Start from a template") { picking = true }
        Field("Specific objective", specific, { specific = it }, required = true, singleLine = false, minLines = 2, error = specificError)
        Field("Measurable criteria", metric, { metric = it }, required = true, singleLine = false, minLines = 2, error = metricError)
        Field("Focus area", focus, { focus = it }, hint = "optional")
        Field("Target date", target, { target = it }, hint = "optional, year-month-day", error = targetError)
        ErrorText(error)
    }
}

/** The organisation's goal templates, searched by area and words as on the website, 50 at a time. */
@Composable
private fun GoalTemplatePicker(api: Api, onPick: (GoalTemplate) -> Unit, onCancel: () -> Unit) {
    var q by remember { mutableStateOf("") }
    var search by remember { mutableStateOf("") }
    var area by remember { mutableStateOf("") }
    var areas by remember { mutableStateOf<List<String>>(emptyList()) }
    val state = rememberLoaded(search, area) {
        val p = buildMap { if (search.isNotEmpty()) put("q", search); if (area.isNotEmpty()) put("category", area) }
        parseGoalTemplates(api.call("goal_templates", p))
    }
    androidx.compose.runtime.LaunchedEffect(state.data) { state.data?.categories?.takeIf { it.isNotEmpty() }?.let { areas = it } }
    AlertDialog(
        onDismissRequest = onCancel,
        title = { Text("Goal templates", modifier = Modifier.semantics { heading() }) },
        text = {
            Column(Modifier.verticalScroll(rememberScrollState())) {
                if (areas.isNotEmpty()) Choice("Area", area, listOf("" to "All areas") + areas.map { it to it }) { area = it }
                Field("Tool, task or keyword", q, { q = it })
                SecondaryButton("Search") { search = q.trim() }
                if (state.loading) Text("Loading templates…")
                ErrorText(state.error, takeFocus = false)
                state.data?.let { r ->
                    Announce(if (r.templates.isEmpty()) "No templates match." else "${r.templates.size}${if (r.more) " or more" else ""} templates.", key = r)
                    Heading("Matching templates (${r.templates.size}${if (r.more) "+" else ""})")
                    if (r.templates.isEmpty()) Text("No templates match. Try other words or another area.")
                    r.templates.forEach { t -> ListRow(listOf(t.specificGoal, t.measurableMetric, t.category), action = "use this template", onClick = { onPick(t) }) }
                    if (r.more) Text("Showing the first 50 matches. Add words or choose an area to narrow them.")
                }
            }
        },
        confirmButton = { TextButton(onClick = onCancel) { Text("Back to the goal") } },
    )
}

@Composable
internal fun ProgressDialog(api: Api, g: Goal, onDone: (String) -> Unit, onCancel: () -> Unit) {
    val oldStatus = g.status
    val oldPct = g.progressPct
    var status by rememberSaveable { mutableStateOf(oldStatus) }
    var pct by rememberSaveable { mutableStateOf(oldPct.toString()) }
    var pctEntered by rememberSaveable { mutableStateOf(false) }
    var note by rememberSaveable { mutableStateOf("") }
    var error by remember { mutableStateOf<String?>(null) }
    var pctError by remember { mutableStateOf<String?>(null) }
    var busy by remember { mutableStateOf(false) }
    val idem = rememberSaveable { UUID.randomUUID().toString() }
    val scope = rememberCoroutineScope()
    AlertDialog(
        // 4.29.0: not closed while saving (Back or a tap outside), so the entry is never left half-sent.
        onDismissRequest = { if (!busy) onCancel() },
        title = { Text("Progress: " + g.specificGoal, modifier = Modifier.semantics { heading() }) },
        text = {
            Column(Modifier.verticalScroll(rememberScrollState())) {
                Choice("Status", status, listOf("Not Started", "In Progress", "Achieved").map { it to it }) { status = it; if (it == "Achieved") pct = "100" }
                Field("Percent done", pct, { pct = it.filter { c -> c.isDigit() }.take(3); pctEntered = true }, hint = "0 to 100", keyboard = KeyboardType.Number, error = pctError)
                Field("Note", note, { note = it }, hint = "optional", singleLine = false, minLines = 2)
                ErrorText(error)
            }
        },
        confirmButton = {
            TextButton(enabled = !busy, onClick = {
                val p = pct.toIntOrNull()
                pctError = if (p == null || p !in 0..100) "Enter the percent done as a whole number from 0 to 100." else null
                if (p == null || pctError != null) { error = pctError; return@TextButton }
                scope.launchSave({ busy = it }, { error = it }) {
                    val b = JSONObject().put("id", g.id).put("status", status).put("progress_pct", p).put("note", note.trim()).put("idem_key", idem)
                    // Reopening at 100 only when the person typed 100 themselves (the server asks otherwise).
                    if (oldStatus == "Achieved" && status != "Achieved" && p == 100 && pctEntered) b.put("reopen_confirmed", 1)
                    val recordedAt = SimpleDateFormat("yyyy-MM-dd'T'HH:mm:ssXXX", Locale.US).format(Date())
                    val s = saveOrQueue(api, "update_goal", b, "Goal progress: " + g.specificGoal.take(40), "Progress recorded.") { q ->
                        // Kept offline: what the goal said when this was written, so it never undoes newer progress.
                        q.put("expected_status", oldStatus).put("expected_pct", oldPct.toString()).put("recorded_at", recordedAt)
                    }
                    onDone(s.reply?.str("message") ?: s.message)
                }
            }) { Text(if (busy) "Saving…" else "Save") }
        },
        dismissButton = { TextButton(enabled = !busy, onClick = onCancel) { Text("Cancel") } },
    )
}

@Composable
private fun ExpenseDialog(api: Api, trackId: Int, onDone: (String) -> Unit, onCancel: () -> Unit) {
    var date by rememberSaveable { mutableStateOf(today()) }
    var description by rememberSaveable { mutableStateOf("") }
    var amount by rememberSaveable { mutableStateOf("") }
    var error by remember { mutableStateOf<String?>(null) }
    var dateError by remember { mutableStateOf<String?>(null) }
    var amountError by remember { mutableStateOf<String?>(null) }
    var descriptionError by remember { mutableStateOf<String?>(null) }
    var busy by remember { mutableStateOf(false) }
    var presses by remember { androidx.compose.runtime.mutableIntStateOf(0) }
    val idem = rememberSaveable { UUID.randomUUID().toString() }
    val scope = rememberCoroutineScope()
    AlertDialog(
        onDismissRequest = { if (!busy) onCancel() },
        title = { Text("Add an expense", modifier = Modifier.semantics { heading() }) },
        text = {
            Column(Modifier.verticalScroll(rememberScrollState())) {
                Field("Date", date, { date = it }, hint = "year-month-day", error = dateError, required = true)
                Field("What it was for", description, { description = it }, required = true, error = descriptionError)
                Field("Amount in dollars", amount, { amount = it }, hint = "such as 24.50", keyboard = KeyboardType.Decimal, error = amountError, required = true)
                ErrorText(error, focusKey = presses)
            }
        },
        confirmButton = {
            TextButton(enabled = !busy, onClick = {
                presses++
                val a = amount.trim().removePrefix("$")
                descriptionError = if (description.isBlank()) "Say what the expense was for." else null
                dateError = if (!Regex("^\\d{4}-\\d{2}-\\d{2}$").matches(date.trim())) "Enter the date as year-month-day, such as ${today()}." else null
                amountError = if (!Regex("^\\d{1,7}(\\.\\d{1,2})?$").matches(a) || (a.toDoubleOrNull() ?: 0.0) <= 0) "Enter the amount in dollars, such as 24.50." else null
                errorSummary(listOf(dateError, descriptionError, amountError))?.let { error = it; return@TextButton }
                scope.launchSave({ busy = it }, { error = it }) {
                    val b = JSONObject().put("track_id", trackId).put("date", date.trim()).put("description", description.trim()).put("amount", a).put("idem_key", idem)
                    onDone(saveOrQueue(api, "add_expense", b, "Expense: $" + a + " " + description.trim().take(30), "Expense added.").message)
                }
            }) { Text(if (busy) "Saving…" else "Add") }
        },
        dismissButton = { TextButton(enabled = !busy, onClick = onCancel) { Text("Cancel") } },
    )
}

/** The track fields the edit form sends, and the form's own defaults for each. */
private val TRACK_FORM_KEYS = listOf("title", "service_type", "status", "report_cadence", "auth_number", "funding_agency", "authorized_hours",
    "hourly_rate", "mileage_rate", "start_date", "expiration_date", "billing_mode", "authorized_amount", "bundle_id", "rsa_category")

/**
 * 4.34.0: a new track's starting values from save_track's choices, as the website's form starts
 * them (track_edit.php): who pays and the rate from `defaults`, the RSA category the service type
 * starts with (`rsa_defaults`), and a mileage rate of 0.
 */
fun trackFormDefaults(f: JSONObject): Map<String, String> {
    val service = f.optJSONArray("service_types")?.optString(0) ?: ""
    val d = f.optJSONObject("defaults")
    return mapOf(
        "service_type" to service, "status" to "Active", "report_cadence" to "monthly", "mileage_rate" to "0",
        "billing_mode" to (d?.str("billing_mode") ?: "vr"), "hourly_rate" to (d?.str("hourly_rate") ?: ""),
        "rsa_category" to (f.optJSONObject("rsa_defaults")?.str(service) ?: ""),
    )
}

/** 4.34.0: the RSA category a service type starts with, or null when the server does not say (an older one). */
fun rsaDefaultFor(f: JSONObject, serviceType: String): String? = f.optJSONObject("rsa_defaults")?.str(serviceType)

/**
 * What the form opened with (4.29.0), with when the track was read (updated_at), kept across process
 * death: sent as orig and loaded_at, so the server refuses a save over someone else's newer change.
 */
private fun trackOpened(f: JSONObject): JSONObject {
    val t = f.optJSONObject("track")
    val defaults = if (t == null) trackFormDefaults(f) else mapOf("billing_mode" to "vr")
    return JSONObject().also { o ->
        TRACK_FORM_KEYS.forEach { k -> o.put(k, t?.let { tr -> if (k == "bundle_id") tr.optInt(k).takeIf { it > 0 }?.toString() else tr.str(k) } ?: defaults[k] ?: "") }
        t?.str("updated_at")?.let { o.put("updated_at", it) }
    }
}

/** The track's details (title, status, authorization, rates, dates, report schedule), as on the website. */
@Composable
fun TrackEditScreen(api: Api, nav: NavHostController, clientId: Int, trackId: Int, amendRequest: Int = 0, reasonPrefill: String = "") {
    val state = rememberLoaded(trackId) {
        api.call("save_track", if (trackId > 0) mapOf("id" to trackId.toString()) else mapOf("client_id" to clientId.toString()))
    }
    Show(state, "the track form") { f ->
        val values = rememberSaveable(trackId) { mutableStateOf(trackOpened(f).let { o -> TRACK_FORM_KEYS.map { it to o.optString(it) } }) }
        val map = values.value.toMap()
        fun v(k: String) = map[k] ?: ""
        fun set(k: String, x: String) { values.value = (map + (k to x)).toList() }
        // 4.37.0 (S13): an approved amendment starts with the website's reason.
        var reason by rememberSaveable { mutableStateOf(reasonPrefill) }
        // 4.29.0: what the form opened with, sent as orig and loaded_at (see trackOpened).
        var baseJson by rememberSaveable(trackId) { mutableStateOf(trackOpened(f).toString()) }
        val base = remember(baseJson) { try { JSONObject(baseJson) } catch (e: Exception) { JSONObject() } }
        var conflict by rememberSaveable { mutableStateOf(false) }
        var reloading by remember { mutableStateOf(false) }
        var notice by remember { mutableStateOf<String?>(null) }
        // 4.28.0: a new track is added once even when a reply was lost (see sendOnce).
        var newKey by rememberSaveable { mutableStateOf("") }
        var error by remember { mutableStateOf<String?>(null) }
        var fieldErrors by remember { mutableStateOf<Map<String, String>>(emptyMap()) }
        var busy by remember { mutableStateOf(false) }
        val scope = rememberCoroutineScope()
        val cadences = f.optJSONArray("cadences").objects().map { it.optString("value") to it.optString("label") }
        // Read again after a conflict: what was changed here stays; everything else takes the saved values.
        LaunchedEffect(f) {
            if (!reloading) return@LaunchedEffect
            reloading = false
            val now = trackOpened(f)
            values.value = mergeReloadedMap(map, TRACK_FORM_KEYS.associateWith { base.optString(it) }, TRACK_FORM_KEYS.associateWith { now.optString(it) })
                .filterKeys { it in TRACK_FORM_KEYS }.let { m -> TRACK_FORM_KEYS.map { it to (m[it] ?: "") } }
            baseJson = now.toString()
            conflict = false; error = null
            notice = "Read again. The other changes are now shown, and what you changed here is kept. Check the form, then save again."
        }
        // A reload that failed leaves Reload usable again.
        LaunchedEffect(state.loading, state.error) { if (reloading && !state.loading && state.error != null) reloading = false }
        Page {
            Heading(if (trackId > 0) "Edit the track" else "New track for " + f.optString("client_name"), large = true)
            NoticeText(notice)
            val modes = f.optJSONArray("billing_modes").objects().map { it.optString("value") to it.optString("label") }
            val mode = v("billing_mode").ifEmpty { "vr" }
            val isVr = mode == "vr"
            Field("Title", v("title"), { set("title", it) }, error = fieldErrors["title"], required = true)
            Choice("Service type", v("service_type"), f.optJSONArray("service_types").let { a -> List(a?.length() ?: 0) { a!!.optString(it) } }.map { it to it }, error = fieldErrors["service_type"], required = true) { st ->
                // 4.34.0: the RSA category moves with the service type, as on the website.
                values.value = (map + ("service_type" to st) + ("rsa_category" to (rsaDefaultFor(f, st) ?: ""))).toList()
            }
            val rsa = f.optJSONArray("rsa_categories").strings()
            if (rsa.isNotEmpty()) Choice("RSA service category", v("rsa_category"), listOf("" to "The service type's usual category") + rsa.map { it to it }, error = fieldErrors["rsa_category"]) { set("rsa_category", it) }
            val bundles = f.optJSONArray("bundles").objects()
            if (bundles.isNotEmpty()) Choice("Service bundle", v("bundle_id"), listOf("" to "No bundle") + bundles.map { it.optInt("id").toString() to it.optString("name") }, error = fieldErrors["bundle_id"]) { set("bundle_id", it) }
            Choice("Status", v("status"), f.optJSONArray("statuses").let { a -> List(a?.length() ?: 0) { a!!.optString(it) } }.map { it to it }, error = fieldErrors["status"]) { set("status", it) }
            Choice("Automatic progress reports", v("report_cadence"), cadences, error = fieldErrors["report_cadence"]) { set("report_cadence", it) }
            // 4.34.0: who pays, as the website's form; Private pay starts at the personal rate.
            if (modes.isNotEmpty()) Choice("Who pays", mode, modes, error = fieldErrors["billing_mode"], required = true) { m ->
                var next = map + ("billing_mode" to m)
                if (m == "private" && v("hourly_rate").isBlank()) f.str("private_rate")?.let { next = next + ("hourly_rate" to it) }
                values.value = next.toList()
            }
            Heading("Authorization")
            Field("Authorization number", v("auth_number"), { set("auth_number", it) }, error = fieldErrors["auth_number"], required = isVr)
            Field("Funding agency", v("funding_agency"), { set("funding_agency", it) }, error = fieldErrors["funding_agency"], required = isVr)
            Field("Authorized hours", v("authorized_hours"), { set("authorized_hours", it) }, keyboard = KeyboardType.Decimal, error = fieldErrors["authorized_hours"], required = isVr)
            if (modes.isNotEmpty() && isVr) Field("Authorized dollar amount", v("authorized_amount"), { set("authorized_amount", it) }, hint = "optional, such as 4,500",
                keyboard = KeyboardType.Decimal, error = fieldErrors["authorized_amount"])
            if (mode != "none") Field("Hourly billing rate in dollars", v("hourly_rate"), { set("hourly_rate", it) }, keyboard = KeyboardType.Decimal, error = fieldErrors["hourly_rate"], required = true)
            Field("Mileage rate in dollars a mile", v("mileage_rate"), { set("mileage_rate", it) }, hint = "0 when travel is not billable", keyboard = KeyboardType.Decimal, error = fieldErrors["mileage_rate"])
            Field("Start date", v("start_date"), { set("start_date", it) }, hint = "year-month-day, optional", error = fieldErrors["start_date"])
            Field("End date", v("expiration_date"), { set("expiration_date", it) }, hint = "year-month-day, optional", error = fieldErrors["expiration_date"])
            if (trackId > 0) Field("Why it changed", reason, { reason = it }, hint = "kept as the amendment reason, optional", error = fieldErrors["amendment_reason"])
            ErrorText(error)
            if (conflict) SecondaryButton("Reload, keeping my changes") { if (!reloading) { reloading = true; notice = null; state.reload() } }
            PrimaryButton("Save", busy = busy, missing = stillNeeded(missingNames("Title" to v("title").isBlank()))) {
                if (busy) return@PrimaryButton
                notice = null
                scope.launchSave({ busy = it }, { error = it }) {
                    fieldErrors = emptyMap()
                    val b = JSONObject()
                    TRACK_FORM_KEYS.forEach { k -> b.put(k, v(k).trim()) }
                    b.put("bundle_id", v("bundle_id").toIntOrNull() ?: 0)
                    // An older server sends no billing modes: who pays and the dollar amount are left as they are.
                    if (f.optJSONArray("billing_modes") == null) { b.remove("billing_mode"); b.remove("authorized_amount") }
                    else if (v("billing_mode") != "vr") b.put("authorized_amount", "")
                    if (v("billing_mode") == "none") b.put("hourly_rate", "0")
                    // Blank: the server gives the service type's usual category.
                    if (v("rsa_category").isEmpty()) b.remove("rsa_category")
                    if (trackId > 0) {
                        b.put("orig", openedValues(b, base))
                        base.str("updated_at")?.let { b.put("loaded_at", it) }
                        b.put("id", trackId).put("amendment_reason", reason.trim())
                        if (amendRequest > 0) b.put("amend_request", amendRequest)
                    } else b.put("client_id", clientId)
                    try {
                        val saved = if (trackId > 0) api.call("save_track", body = b) else api.callOnce("save_track", b, newKey, { newKey = it }, "the new track")
                        Flash.pending = saved.optString("message").ifEmpty { "Track saved." }
                        nav.popBackStack()
                    } catch (e: ApiException) {
                        // Someone else saved it since it was opened (4.29.0): the message names what changed; Reload is offered.
                        if (e.status == 409 && e.reply?.optBoolean("conflict") == true) {
                            conflict = true
                            val changed = e.reply.optJSONArray("changed").strings()
                            error = (e.message ?: "Someone else changed this track since you opened it.") +
                                (if (changed.isNotEmpty() && changed.none { (e.message ?: "").contains(it) }) " Changed: " + changed.joinToString(", ") + "." else "") +
                                " Press Reload, keeping my changes, to see them."
                            return@launchSave
                        }
                        // Field errors come back by name: each on its field, and all of them in the summary.
                        val errs = e.reply?.optJSONObject("errors")
                        fieldErrors = errs?.keys()?.asSequence()?.associateWith { errs.optString(it) } ?: emptyMap()
                        error = errorSummary(fieldErrors.values) ?: e.message
                    }
                }
            }
        }
    }
}
