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
import org.casemanagement.app.objects
import org.casemanagement.app.str
import org.json.JSONObject
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale
import java.util.UUID

/**
 * A training track: its authorization (hours used, when it runs out), goals with their progress,
 * expenses, and Log hours. Progress and expenses are kept offline as in the iPhone app.
 */
@Composable
fun TrackScreen(api: Api, nav: NavHostController, clientId: Int, trackId: Int) {
    val state = rememberLoaded(trackId) {
        val goals = api.call("goals", mapOf("track_id" to trackId.toString())).optJSONArray("goals").objects()
        val expenses = api.call("expenses", mapOf("track_id" to trackId.toString())).optJSONArray("expenses").objects()
        val amend = try { api.call("amendment", mapOf("track" to trackId.toString())) } catch (e: ApiException) { JSONObject() }
        // Only active tracks take hours; the tracks list holds exactly those.
        val active = try { api.call("tracks").optJSONArray("tracks").objects().any { it.optInt("id") == trackId } } catch (e: ApiException) { true }
        amend.put("_active", active)
        Triple(goals, expenses, amend)
    }
    var progressFor by remember { mutableStateOf<JSONObject?>(null) }
    var addingExpense by remember { mutableStateOf(false) }
    var notice by remember { mutableStateOf<String?>(null) }

    Show(state, "the track") { (goals, expenses, a) ->
        Page {
            Heading((a.str("client")?.let { "$it: " } ?: "") + (a.str("title") ?: "Training track"), large = true)
            NoticeText(notice)
            if (a.has("authorized")) {
                ListRow(listOf("${hoursText(a.optDouble("worked", 0.0))} of ${hoursText(a.optDouble("authorized", 0.0))} hours used (${a.optInt("pct")}%), ${hoursText(a.optDouble("remaining", 0.0))} left"))
                a.str("expiration")?.let { ListRow(listOf((if (a.optBoolean("expired")) "Ended " else "Ends ") + dayText(it))) }
                a.str("run_out")?.let { ListRow(listOf("At the recent pace the ${if (a.optString("run_out_basis") == "funds") "funds" else "hours"} run out around " + dayText(it))) }
                if (a.optInt("suggest_add") > 0) ListRow(listOf("Suggested amendment: add ${a.optInt("suggest_add")} hours. The amendment letter is on the website."))
            }
            if (a.optBoolean("_active", true)) PrimaryButton("Log hours") { nav.navigate("hours/$clientId/$trackId") }
            else Text("This track is not active, so hours cannot be logged on it.")
            SecondaryButton("Edit the track") { nav.navigate("trackEdit/$clientId/$trackId") }

            Heading("Goals (${goals.size})")
            if (goals.isEmpty()) Text("No goals on this track.")
            goals.forEach { g ->
                ListRow(listOf(g.optString("specific_goal"), g.optString("status") + ", ${g.optString("progress_pct").toDoubleOrNull()?.toInt() ?: 0}% done" +
                    (g.str("target_date")?.let { ", target " + dayText(it) } ?: ""), g.optString("measurable_metric")),
                    action = "record progress", onClick = { progressFor = g })
            }

            Heading("Expenses (${expenses.size})")
            expenses.forEach { e ->
                ListRow(listOf(e.optString("description"), "$" + String.format(Locale.US, "%,.2f", e.optDouble("amount", 0.0)) + ", " + dayText(e.str("expense_date")) + if (e.optBoolean("is_billed")) ", billed" else ""))
            }
            SecondaryButton("Add an expense") { addingExpense = true }
        }
    }

    progressFor?.let { g -> ProgressDialog(api, g, onDone = { m -> progressFor = null; notice = m; state.reload() }, onCancel = { progressFor = null }) }
    if (addingExpense) ExpenseDialog(api, trackId, onDone = { m -> addingExpense = false; notice = m; state.reload() }, onCancel = { addingExpense = false })
}

@Composable
private fun ProgressDialog(api: Api, g: JSONObject, onDone: (String) -> Unit, onCancel: () -> Unit) {
    val oldStatus = g.optString("status")
    val oldPct = g.optString("progress_pct").toDoubleOrNull()?.toInt() ?: 0
    var status by remember { mutableStateOf(oldStatus) }
    var pct by remember { mutableStateOf(oldPct.toString()) }
    var pctEntered by remember { mutableStateOf(false) }
    var note by remember { mutableStateOf("") }
    var error by remember { mutableStateOf<String?>(null) }
    var busy by remember { mutableStateOf(false) }
    val idem = remember { UUID.randomUUID().toString() }
    val scope = rememberCoroutineScope()
    AlertDialog(
        onDismissRequest = onCancel,
        title = { Text("Progress: " + g.optString("specific_goal"), modifier = Modifier.semantics { heading() }) },
        text = {
            Column(Modifier.verticalScroll(rememberScrollState())) {
                Choice("Status", status, listOf("Not Started", "In Progress", "Achieved").map { it to it }) { status = it; if (it == "Achieved") pct = "100" }
                Field("Percent done", pct, { pct = it.filter { c -> c.isDigit() }.take(3); pctEntered = true }, hint = "0 to 100", keyboard = KeyboardType.Number)
                Field("Note", note, { note = it }, hint = "optional", singleLine = false, minLines = 2)
                ErrorText(error)
            }
        },
        confirmButton = {
            TextButton(enabled = !busy, onClick = {
                val p = pct.toIntOrNull()
                if (p == null || p !in 0..100) { error = "Enter the percent done as a whole number from 0 to 100."; return@TextButton }
                scope.launchSave({ busy = it }, { error = it }) {
                    val b = JSONObject().put("id", g.optInt("id")).put("status", status).put("progress_pct", p).put("note", note.trim()).put("idem_key", idem)
                    // Reopening at 100 only when the person typed 100 themselves (the server asks otherwise).
                    if (oldStatus == "Achieved" && status != "Achieved" && p == 100 && pctEntered) b.put("reopen_confirmed", 1)
                    val recordedAt = SimpleDateFormat("yyyy-MM-dd'T'HH:mm:ssXXX", Locale.US).format(Date())
                    val s = saveOrQueue(api, "update_goal", b, "Goal progress: " + g.optString("specific_goal").take(40), "Progress recorded.") { q ->
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
    var busy by remember { mutableStateOf(false) }
    val idem = rememberSaveable { UUID.randomUUID().toString() }
    val scope = rememberCoroutineScope()
    AlertDialog(
        onDismissRequest = onCancel,
        title = { Text("Add an expense", modifier = Modifier.semantics { heading() }) },
        text = {
            Column(Modifier.verticalScroll(rememberScrollState())) {
                Field("Date", date, { date = it }, hint = "year-month-day")
                Field("What it was for", description, { description = it })
                Field("Amount in dollars", amount, { amount = it }, hint = "such as 24.50", keyboard = KeyboardType.Decimal)
                ErrorText(error)
            }
        },
        confirmButton = {
            TextButton(enabled = !busy && description.isNotBlank(), onClick = {
                val a = amount.trim().removePrefix("$")
                if (!Regex("^\\d{1,7}(\\.\\d{1,2})?$").matches(a) || (a.toDoubleOrNull() ?: 0.0) <= 0) { error = "Enter the amount in dollars, such as 24.50."; return@TextButton }
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
                        Flash.pending = api.call("save_track", body = b).optString("message").ifEmpty { "Track saved." }
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
