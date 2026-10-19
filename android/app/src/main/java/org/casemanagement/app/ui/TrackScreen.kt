package org.casemanagement.app.ui

import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
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
    val logs: List<WorkLog>?, val notes: TrackNotes?,
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
        val logs = try { parseWorkLogs(api.call("work_logs", q)) } catch (e: ApiException) { if (e.status == 401) throw e; null }
        val notes = try { parseTrackNotes(api.call("track_notes", q)) } catch (e: ApiException) { if (e.status == 401) throw e; null }
        TrackData(goals, expenses, amend, active, logs, notes)
    }
    var goalFor by remember { mutableStateOf<Goal?>(null) }
    var progressFor by remember { mutableStateOf<Goal?>(null) }
    var addingGoal by rememberSaveable { mutableStateOf(false) }
    var addingExpense by rememberSaveable { mutableStateOf(false) }
    var deletingExpense by remember { mutableStateOf<JSONObject?>(null) }
    var logFor by remember { mutableStateOf<WorkLog?>(null) }
    var correcting by remember { mutableStateOf<WorkLog?>(null) }
    var deletingLog by remember { mutableStateOf<WorkLog?>(null) }
    var reporting by rememberSaveable { mutableStateOf(false) }
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
                if (a.optInt("suggest_add") > 0) ListRow(listOf("Suggested amendment: add ${a.optInt("suggest_add")} hours. The amendment letter is on the website."))
            }
            if (t.active) PrimaryButton("Log hours") { nav.navigate("hours/$clientId/$trackId") }
            else Text("This track is not active, so hours cannot be logged on it.")
            // Sending the report needs the reports permission, as on the website.
            if (api.can("reports")) SecondaryButton("Email the progress report to the VR counselor") { reporting = true }
            SecondaryButton("Edit the track") { nav.navigate("trackEdit/$clientId/$trackId") }

            Heading("SMART goals (${t.goals.size})")
            if (t.goals.isEmpty()) Text("No goals yet.")
            t.goals.forEachIndexed { i, g ->
                ListRow(listOfNotNull("Goal ${i + 1} of ${t.goals.size}: " + g.specificGoal, g.measurableMetric.ifEmpty { null }?.let { "Measured by: $it" },
                    g.targetDate?.let { "Target: " + dayText(it) }, "${g.progressPct} percent, ${g.status}"),
                    action = "record progress or see its history", onClick = { goalFor = g })
            }
            SecondaryButton("Add a goal") { addingGoal = true }

            val notes = t.notes
            Heading(if (notes == null) "Case notes for this track" else if (notes.total > notes.notes.size) "Case notes for this track (latest ${notes.notes.size} of ${notes.total})" else "Case notes for this track (${notes.notes.size})")
            when {
                notes == null -> Text("The notes could not be loaded. Pull Refresh to try again.")
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
            Heading(if (logs == null) "Hours logged" else "Hours logged (${logs.size})")
            when {
                logs == null -> Text("The hours logged could not be loaded.")
                logs.isEmpty() -> Text("No hours logged on this track.")
            }
            logs?.forEach { l ->
                ListRow(listOf(workLogSummary(l), l.description), action = if (l.isBilled) null else "correct or delete it",
                    onClick = if (l.isBilled) null else ({ logFor = l }))
            }

            Heading("Billable expenses (${t.expenses.size})")
            if (t.expenses.isEmpty()) Text("No expenses logged on this track.")
            t.expenses.forEach { e ->
                val billed = e.optBoolean("is_billed")
                ListRow(listOf("$" + String.format(Locale.US, "%,.2f", e.optDouble("amount", 0.0)) + ", " + e.optString("description"),
                    dayText(e.str("expense_date")) + if (billed) ", on an invoice" else ", not yet billed"),
                    action = if (billed) null else "delete it", onClick = if (billed) null else ({ deletingExpense = e }))
            }
            SecondaryButton("Add an expense") { addingExpense = true }
            SecondaryButton("Refresh") { state.reload() }
        }
    }

    goalFor?.let { g ->
        AlertDialog(
            onDismissRequest = { goalFor = null },
            title = { Text(g.specificGoal, modifier = Modifier.semantics { heading() }) },
            text = {
                Column {
                    Text("${g.progressPct} percent, ${g.status}.")
                    TextButton(onClick = { goalFor = null; progressFor = g }) { Text("Record progress") }
                    TextButton(onClick = { goalFor = null; nav.navigate("goal/${g.id}/" + android.net.Uri.encode(g.specificGoal.ifEmpty { "-" })) }) { Text("Progress history") }
                }
            },
            confirmButton = { TextButton(onClick = { goalFor = null }) { Text("Close") } },
        )
    }
    logFor?.let { l ->
        AlertDialog(
            onDismissRequest = { logFor = null },
            title = { Text(workLogSummary(l), modifier = Modifier.semantics { heading() }) },
            text = {
                Column {
                    Text(l.description)
                    TextButton(onClick = { logFor = null; correcting = l }) { Text("Correct hours or miles") }
                    TextButton(onClick = { logFor = null; deletingLog = l }) { Text("Delete the entry") }
                }
            },
            confirmButton = { TextButton(onClick = { logFor = null }) { Text("Close") } },
        )
    }
    deletingLog?.let { l ->
        Confirm("Delete the entry?", workLogSummary(l) + ". It moves to the trash, where an administrator can restore it for 30 days.", "Delete entry", {
            error = null; notice = null
            scope.launch {
                try { notice = api.call("delete_log", body = JSONObject().put("id", l.id)).optString("message").ifEmpty { "Entry deleted." }; state.reload() }
                catch (e: ApiException) { error = e.message }
            }
        }, { deletingLog = null })
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
        }, { deletingExpense = null })
    }
    correcting?.let { l -> CorrectLogDialog(api, l, onDone = { m -> correcting = null; notice = m; state.reload() }, onCancel = { correcting = null }) }
    progressFor?.let { g -> ProgressDialog(api, g, onDone = { m -> progressFor = null; notice = m; state.reload() }, onCancel = { progressFor = null }) }
    if (addingGoal) AddGoalDialog(api, trackId, onDone = { m -> addingGoal = false; notice = m; state.reload() }, onCancel = { addingGoal = false })
    if (addingExpense) ExpenseDialog(api, trackId, onDone = { m -> addingExpense = false; notice = m; state.reload() }, onCancel = { addingExpense = false })
    if (reporting) SendReportDialog(api, trackId, state.data?.amend?.str("client") ?: "the client", onDone = { m -> reporting = false; notice = m }, onCancel = { reporting = false })
}

/** An hours entry as it is read out: its day, hours, miles and whether it is billed. */
fun workLogSummary(l: WorkLog): String =
    dayText(l.workDate) + ": " + hoursText(l.hours) + (if (l.hours == 1.0) " hour" else " hours") +
        (if (l.mileage > 0) ", " + hoursText(l.mileage) + " miles" else "") + if (l.isBilled) ", on an invoice" else ", not yet billed"

/** A goal's progress, newest first (goal_progress). */
@Composable
fun GoalHistoryScreen(api: Api, goalId: Int, goalText: String) {
    val state = rememberLoaded(goalId) { parseGoalProgress(api.call("goal_progress", mapOf("goal" to goalId.toString()))) }
    Page {
        Heading("Goal: $goalText", large = true)
        Show(state, "the history") { list ->
            Announce("${list.size} progress ${if (list.size == 1) "entry" else "entries"}.", key = list.size)
            if (list.isEmpty()) Text("No progress recorded yet.")
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
    var busy by remember { mutableStateOf(false) }
    val scope = rememberCoroutineScope()
    FormDialog("Correct the entry of " + dayText(l.workDate), "Save", busy, onSave = {
        if (busy) return@FormDialog
        val h = number(hours)
        val m = number(miles.ifBlank { "0" })
        if (h == null || m == null) { error = "Enter the hours and miles as numbers."; return@FormDialog }
        scope.launchSave({ busy = it }, { error = it }) {
            val r = api.call("correct_log", body = JSONObject().put("id", l.id).put("hours", h).put("mileage", m).put("reason", reason.trim()))
            onDone(r.optString("message").ifEmpty { "Entry corrected." })
        }
    }, onCancel = onCancel) {
        Field("Hours", hours, { hours = it }, keyboard = KeyboardType.Decimal)
        Field("Miles", miles, { miles = it }, keyboard = KeyboardType.Decimal)
        Field("Reason for the correction", reason, { reason = it })
        ErrorText(error)
    }
}

/**
 * The full progress report (authorization, goals and session log) emailed to the VR counselor on
 * file, signed by typing the staff member's own name (send_report).
 */
@Composable
private fun SendReportDialog(api: Api, trackId: Int, clientName: String, onDone: (String) -> Unit, onCancel: () -> Unit) {
    var intro by rememberSaveable { mutableStateOf("") }
    var signature by rememberSaveable { mutableStateOf("") }
    var idem by rememberSaveable { mutableStateOf("") }
    var error by remember { mutableStateOf<String?>(null) }
    var busy by remember { mutableStateOf(false) }
    val scope = rememberCoroutineScope()
    FormDialog("Email the progress report", "Sign and send", busy, enabled = signature.isNotBlank(), onSave = {
        if (busy) return@FormDialog
        scope.launchSave({ busy = it }, { error = it }) {
            // 4.28.0: the server emails before it answers, so a retry after a lost reply carries the same key and emails once (see sendOnce).
            val r = api.callOnce("send_report", JSONObject().put("track_id", trackId).put("intro", intro.trim()).put("signature", signature.trim()),
                idem, { idem = it }, "the report")
            val to = r.str("sent_to")
            onDone(listOfNotNull(if (to == null) "Report signed and emailed." else "Report signed and emailed to $to.", r.str("release_warning")).joinToString(" "))
        }
    }, onCancel = onCancel) {
        Text("Sends the full progress report for $clientName (authorization, goals and session log) to the VR counselor on file, with a link to confirm they received it.")
        Field("Personal message", intro, { intro = it }, hint = "optional", singleLine = false, minLines = 3)
        Field("Signature: type your full name", signature, { signature = it }, hint = api.userName)
        Text("The signature and the time are included on the report and recorded on the client's file.")
        ErrorText(error)
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
        when {
            specific.isBlank() -> error = "Specific objective: say what the client will be able to do."
            metric.isBlank() -> error = "Measurable criteria: say how progress on this goal is measured."
            target.isNotBlank() && !Regex("^\\d{4}-\\d{2}-\\d{2}$").matches(target.trim()) -> error = "Enter the target date as year-month-day, such as ${today()}, or leave it empty."
            else -> scope.launchSave({ busy = it }, { error = it }) {
                api.callOnce("add_goal", JSONObject().put("track_id", trackId).put("specific_goal", specific.trim())
                    .put("measurable_metric", metric.trim()).put("focus_area", focus.trim()).put("target_date", target.trim()), idem, { idem = it }, "the goal")
                onDone("Goal added.")
            }
        }
    }, onCancel = onCancel) {
        NoticeText(notice)
        SecondaryButton("Start from a template") { picking = true }
        Field("Specific objective", specific, { specific = it }, hint = "required", singleLine = false, minLines = 2)
        Field("Measurable criteria", metric, { metric = it }, hint = "required", singleLine = false, minLines = 2)
        Field("Focus area", focus, { focus = it }, hint = "optional")
        Field("Target date", target, { target = it }, hint = "optional, year-month-day")
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
                ErrorText(state.error)
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
private fun ProgressDialog(api: Api, g: Goal, onDone: (String) -> Unit, onCancel: () -> Unit) {
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
        onDismissRequest = onCancel,
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
        dismissButton = { TextButton(onClick = onCancel) { Text("Cancel") } },
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
    var busy by remember { mutableStateOf(false) }
    val idem = rememberSaveable { UUID.randomUUID().toString() }
    val scope = rememberCoroutineScope()
    AlertDialog(
        onDismissRequest = onCancel,
        title = { Text("Add an expense", modifier = Modifier.semantics { heading() }) },
        text = {
            Column(Modifier.verticalScroll(rememberScrollState())) {
                Field("Date", date, { date = it }, hint = "year-month-day", error = dateError)
                Field("What it was for", description, { description = it })
                Field("Amount in dollars", amount, { amount = it }, hint = "such as 24.50", keyboard = KeyboardType.Decimal, error = amountError)
                ErrorText(error)
            }
        },
        confirmButton = {
            TextButton(enabled = !busy && description.isNotBlank(), onClick = {
                val a = amount.trim().removePrefix("$")
                dateError = if (!Regex("^\\d{4}-\\d{2}-\\d{2}$").matches(date.trim())) "Enter the date as year-month-day, such as ${today()}." else null
                amountError = if (!Regex("^\\d{1,7}(\\.\\d{1,2})?$").matches(a) || (a.toDoubleOrNull() ?: 0.0) <= 0) "Enter the amount in dollars, such as 24.50." else null
                if (dateError != null || amountError != null) { error = dateError ?: amountError; return@TextButton }
                scope.launchSave({ busy = it }, { error = it }) {
                    val b = JSONObject().put("track_id", trackId).put("date", date.trim()).put("description", description.trim()).put("amount", a).put("idem_key", idem)
                    onDone(saveOrQueue(api, "add_expense", b, "Expense: $" + a + " " + description.trim().take(30), "Expense added.").message)
                }
            }) { Text(if (busy) "Saving…" else "Add") }
        },
        dismissButton = { TextButton(onClick = onCancel) { Text("Cancel") } },
    )
}

/** The track's details (title, status, authorization, rates, dates, report schedule), as on the website. */
@Composable
fun TrackEditScreen(api: Api, nav: NavHostController, clientId: Int, trackId: Int) {
    val state = rememberLoaded(trackId) {
        api.call("save_track", if (trackId > 0) mapOf("id" to trackId.toString()) else mapOf("client_id" to clientId.toString()))
    }
    Show(state, "the track form") { f ->
        val t = f.optJSONObject("track") ?: JSONObject()
        var title by rememberSaveable { mutableStateOf(t.str("title") ?: "") }
        var serviceType by rememberSaveable { mutableStateOf(t.str("service_type") ?: f.optJSONArray("service_types")?.optString(0) ?: "") }
        var status by rememberSaveable { mutableStateOf(t.str("status") ?: "Active") }
        var cadence by rememberSaveable { mutableStateOf(t.str("report_cadence") ?: "monthly") }
        var authNumber by rememberSaveable { mutableStateOf(t.str("auth_number") ?: "") }
        var agency by rememberSaveable { mutableStateOf(t.str("funding_agency") ?: "") }
        var hours by rememberSaveable { mutableStateOf(t.str("authorized_hours") ?: "") }
        var rate by rememberSaveable { mutableStateOf(t.str("hourly_rate") ?: "") }
        // A new track leaves it empty, so the agency's own (or the default) mileage rate applies.
        var mileage by rememberSaveable { mutableStateOf(t.str("mileage_rate") ?: "") }
        var start by rememberSaveable { mutableStateOf(t.str("start_date") ?: "") }
        var end by rememberSaveable { mutableStateOf(t.str("expiration_date") ?: "") }
        var reason by rememberSaveable { mutableStateOf("") }
        // 4.28.0: a new track is added once even when a reply was lost (see sendOnce).
        var newKey by rememberSaveable { mutableStateOf("") }
        var error by remember { mutableStateOf<String?>(null) }
        var busy by remember { mutableStateOf(false) }
        val scope = rememberCoroutineScope()
        val cadences = f.optJSONArray("cadences").objects().map { it.optString("value") to it.optString("label") }
        Page {
            Heading(if (trackId > 0) "Edit the track" else "New track for " + f.optString("client_name"), large = true)
            Field("Title", title, { title = it })
            Choice("Service type", serviceType, f.optJSONArray("service_types").let { a -> List(a?.length() ?: 0) { a!!.optString(it) } }.map { it to it }) { serviceType = it }
            Choice("Status", status, f.optJSONArray("statuses").let { a -> List(a?.length() ?: 0) { a!!.optString(it) } }.map { it to it }) { status = it }
            Choice("Automatic progress reports", cadence, cadences) { cadence = it }
            Heading("Authorization")
            Field("Authorization number", authNumber, { authNumber = it })
            Field("Funding agency", agency, { agency = it })
            Field("Authorized hours", hours, { hours = it }, keyboard = KeyboardType.Decimal)
            Field("Hourly rate in dollars", rate, { rate = it }, keyboard = KeyboardType.Decimal)
            Field("Mileage rate in dollars a mile", mileage, { mileage = it }, keyboard = KeyboardType.Decimal)
            Field("Start date", start, { start = it }, hint = "year-month-day, optional")
            Field("End date", end, { end = it }, hint = "year-month-day, optional")
            if (trackId > 0) Field("Why it changed", reason, { reason = it }, hint = "kept as the amendment reason, optional")
            ErrorText(error)
            PrimaryButton("Save", busy = busy, enabled = title.isNotBlank()) {
                scope.launchSave({ busy = it }, { error = it }) {
                    val b = JSONObject().put("title", title.trim()).put("service_type", serviceType).put("status", status).put("report_cadence", cadence)
                        .put("auth_number", authNumber.trim()).put("funding_agency", agency.trim()).put("authorized_hours", hours.trim())
                        .put("hourly_rate", rate.trim()).put("mileage_rate", mileage.trim()).put("start_date", start.trim()).put("expiration_date", end.trim())
                        .put("bundle_id", t.optInt("bundle_id"))
                    // The RSA category belongs to the service type: kept only while that is unchanged.
                    if (serviceType == t.str("service_type")) b.put("rsa_category", t.str("rsa_category") ?: "")
                    if (trackId > 0) b.put("id", trackId).put("amendment_reason", reason.trim()) else b.put("client_id", clientId)
                    try {
                        val saved = if (trackId > 0) api.call("save_track", body = b) else api.callOnce("save_track", b, newKey, { newKey = it }, "the new track")
                        Flash.pending = saved.optString("message").ifEmpty { "Track saved." }
                        nav.popBackStack()
                    } catch (e: ApiException) {
                        // Field errors come back by name: say the first one, with its field.
                        val errs = e.reply?.optJSONObject("errors")
                        error = errs?.keys()?.asSequence()?.firstOrNull()?.let { errs.optString(it) } ?: e.message
                    }
                }
            }
        }
    }
}
