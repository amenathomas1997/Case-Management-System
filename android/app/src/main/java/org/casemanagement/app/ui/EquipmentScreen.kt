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
import kotlinx.coroutines.launch
import org.casemanagement.app.Api
import org.casemanagement.app.ApiException
import org.casemanagement.app.objects
import org.casemanagement.app.str
import org.json.JSONObject

/** The equipment library: what is on loan (overdue first) and what is available; loan out and return. */
@Composable
fun EquipmentScreen(api: Api) {
    var q by rememberSaveable { mutableStateOf("") }
    val state = rememberLoaded(api.userId) { api.call("equipment").optJSONArray("equipment").objects() }
    var chosen by remember { mutableStateOf<JSONObject?>(null) }
    var lending by remember { mutableStateOf<JSONObject?>(null) }
    var notice by remember { mutableStateOf<String?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    val scope = rememberCoroutineScope()
    Show(state, "equipment") { all ->
        val list = all.filter { q.isBlank() || listOf("name", "category", "serial", "borrower").any { k -> it.optString(k).contains(q.trim(), ignoreCase = true) } }
        Page {
            NoticeText(notice)
            ErrorText(error)
            Field("Find equipment", q, { q = it }, hint = "name, category, serial or borrower")
            val onLoan = list.filter { it.optString("status") == "On Loan" }.sortedByDescending { it.optBoolean("overdue") }
            val available = list.filter { it.optString("status") == "Available" }
            val other = list - onLoan.toSet() - available.toSet()
            Heading("On loan (${onLoan.size})")
            onLoan.forEach { e ->
                ListRow(listOf(e.optString("name"), listOfNotNull(e.str("borrower"), e.str("due_date")?.let { (if (e.optBoolean("overdue")) "overdue since " else "due ") + dayText(it) }).joinToString(", ")),
                    action = "see what you can do", onClick = { chosen = e })
            }
            Heading("Available (${available.size})")
            available.forEach { e ->
                ListRow(listOf(e.optString("name"), listOfNotNull(e.str("category"), e.str("item_condition"), if (e.optBoolean("held")) "reserved" else null).joinToString(", ")),
                    action = "lend it", onClick = { lending = e })
            }
            if (other.isNotEmpty()) {
                Heading("Other (${other.size})")
                other.forEach { e -> ListRow(listOf(e.optString("name"), e.optString("status"))) }
            }
        }
    }
    chosen?.let { e ->
        val canReturn = !e.isNull("loan_id") && !e.isNull("borrower_id")
        fun ret(condition: String) {
            chosen = null; error = null
            scope.launch {
                try { api.call("return_equipment", body = JSONObject().put("loan_id", e.optInt("loan_id")).put("condition_in", condition)); notice = e.optString("name") + " marked returned."; state.reload() }
                catch (x: ApiException) { error = x.message }
            }
        }
        AlertDialog(
            onDismissRequest = { chosen = null },
            title = { Text(e.optString("name"), modifier = Modifier.semantics { heading() }) },
            text = {
                Column {
                    Text(e.str("borrower")?.let { "On loan to $it" } ?: "On loan")
                    if (canReturn) {
                        TextButton(onClick = { ret("") }) { Text("Returned in the same condition") }
                        TextButton(onClick = { ret("Needs Repair") }) { Text("Returned needing repair") }
                    } else Text("This loan is not on your caseload.")
                }
            },
            confirmButton = { TextButton(onClick = { chosen = null }) { Text("Keep on loan") } },
        )
    }
    lending?.let { e -> LendDialog(api, e, onDone = { m -> lending = null; notice = m; state.reload() }, onCancel = { lending = null }) }
}

/** Why equipment is lent and to whom (4.21.0), as the website's loan form names them. */
val LOAN_PURPOSES = listOf(
    "decision" to "To help decide which device to get",
    "loaner" to "As a loaner while a device is repaired or funding is awaited",
    "accommodation" to "As a short-term accommodation",
    "training" to "For training, self-education or professional development",
)
val BORROWER_TYPES = listOf(
    "individual" to "A person with a disability",
    "family" to "A family member or guardian",
    "education" to "Someone from education",
    "employment" to "Someone from employment",
    "health" to "Someone from health or rehabilitation",
    "community" to "Someone from community living",
    "technology" to "A technology professional",
)

@Composable
private fun LendDialog(api: Api, e: JSONObject, onDone: (String) -> Unit, onCancel: () -> Unit) {
    val clients = rememberLoaded(e.optInt("id")) {
        api.call("client_choices").optJSONArray("clients").objects().filter { !it.optString("status").startsWith("Closed") }
    }
    var client by remember { mutableStateOf("") }
    var due by remember { mutableStateOf("") }
    var condition by remember { mutableStateOf(e.optString("item_condition")) }
    var signedBy by remember { mutableStateOf("") }
    var purpose by remember { mutableStateOf("") }
    var borrower by remember { mutableStateOf("") }
    var notes by remember { mutableStateOf("") }
    var error by remember { mutableStateOf<String?>(null) }
    var busy by remember { mutableStateOf(false) }
    val scope = rememberCoroutineScope()
    val conditions = api.formList("equipment_conditions", listOf("New", "Good", "Fair", "Needs Repair"))
    AlertDialog(
        onDismissRequest = onCancel,
        title = { Text("Lend " + e.optString("name"), modifier = Modifier.semantics { heading() }) },
        text = {
            Column(Modifier.verticalScroll(rememberScrollState())) {
                val list = clients.data ?: emptyList()
                if (clients.loading) Text("Loading clients…")
                ErrorText(clients.error)
                Choice("Client", client, listOf("" to "Choose a client") + list.map { it.optInt("id").toString() to it.optString("name") }) { client = it }
                Field("Due back", due, { due = it }, hint = "year-month-day; empty for the usual period")
                Choice("Condition going out", condition, conditions.map { it to it }) { condition = it }
                Field("Received by", signedBy, { signedBy = it }, hint = "the name of who took it, optional")
                Choice("Purpose", purpose, listOf("" to "Not recorded") + LOAN_PURPOSES) { purpose = it }
                Choice("Who is borrowing", borrower, listOf("" to "Not recorded") + BORROWER_TYPES) { borrower = it }
                Field("Notes", notes, { notes = it }, hint = "optional")
                ErrorText(error)
            }
        },
        confirmButton = {
            TextButton(enabled = !busy && client.isNotEmpty(), onClick = {
                scope.launchSave({ busy = it }, { error = it }) {
                    val b = JSONObject().put("equipment_id", e.optInt("id")).put("client_id", client.toInt()).put("condition_out", condition).put("accepted_name", signedBy.trim())
                    if (due.isNotBlank()) b.put("due_date", due.trim())
                    if (notes.isNotBlank()) b.put("notes", notes.trim().take(255))
                    if (purpose.isNotEmpty()) b.put("purpose", purpose)
                    if (borrower.isNotEmpty()) b.put("borrower_type", borrower)
                    api.call("loan_equipment", body = b)
                    onDone(e.optString("name") + " lent.")
                }
            }) { Text(if (busy) "Saving…" else "Lend") }
        },
        dismissButton = { TextButton(onClick = onCancel) { Text("Cancel") } },
    )
}

/**
 * Device check-ins after a loan or purchase: due, then upcoming. Recording one is kept offline
 * (the server takes it only once), as in the iPhone app.
 */
@Composable
fun FollowupsScreen(api: Api) {
    val state = rememberLoaded(api.userId) { api.callCached("followups", cacheKey = "followups") }
    var chosen by remember { mutableStateOf<JSONObject?>(null) }
    var notice by remember { mutableStateOf<String?>(null) }
    Show(state, "device follow-ups") { (r, savedAt) ->
        Page {
            OfflineCopyNotice(savedAt)
            NoticeText(notice)
            val queued = api.ownQueued().filter { it.optString("action") == "save_followup" }.map { try { JSONObject(it.optString("body")).optInt("id") } catch (e: Exception) { 0 } }.toSet()
            listOf("due" to "Due now", "upcoming" to "Coming up").forEach { (k, title) ->
                val list = r.optJSONArray(k).objects()
                Heading("$title (${list.size})")
                list.forEach { f ->
                    val waiting = f.optInt("id") in queued
                    ListRow(listOf(f.optString("client_name") + ": " + f.optString("equipment_name"),
                        (if (waiting) "Recorded, waiting to send. " else "") + "${f.optInt("interval_days")}-day check, due " + dayText(f.str("due_date"))),
                        action = "record it", onClick = if (waiting) null else ({ chosen = f }))
                }
            }
        }
    }
    chosen?.let { f -> FollowupDialog(api, f, onDone = { m -> chosen = null; notice = m; state.reload() }, onCancel = { chosen = null }) }
}

@Composable
private fun FollowupDialog(api: Api, f: JSONObject, onDone: (String) -> Unit, onCancel: () -> Unit) {
    var inUse by remember { mutableStateOf("") }
    var satisfaction by remember { mutableStateOf("") }
    var barriers by remember { mutableStateOf("") }
    var notes by remember { mutableStateOf("") }
    var error by remember { mutableStateOf<String?>(null) }
    var busy by remember { mutableStateOf(false) }
    val scope = rememberCoroutineScope()
    fun send(op: String) {
        scope.launchSave({ busy = it }, { error = it }) {
            val b = JSONObject().put("id", f.optInt("id")).put("op", op)
            if (op == "complete") {
                b.put("in_use", inUse).put("barriers", barriers.trim()).put("notes", notes.trim())
                satisfaction.toIntOrNull()?.let { b.put("satisfaction", it) }
                onDone(saveOrQueue(api, "save_followup", b, "Device follow-up: " + f.optString("client_name"), "Follow-up recorded.").let { s ->
                    s.reply?.str("warning") ?: s.message
                })
            } else {
                api.call("save_followup", body = b)
                onDone(if (op == "skip") "Follow-up skipped." else "Follow-up moved 14 days later.")
            }
        }
    }
    AlertDialog(
        onDismissRequest = onCancel,
        title = { Text(f.optString("client_name") + ": " + f.optString("equipment_name"), modifier = Modifier.semantics { heading() }) },
        text = {
            Column(Modifier.verticalScroll(rememberScrollState())) {
                Choice("Still using it", inUse, listOf("" to "Not answered", "yes" to "Yes", "no" to "No")) { inUse = it }
                Choice("How satisfied", satisfaction, listOf("" to "Not answered") + (1..5).map { it.toString() to "$it of 5" }) { satisfaction = it }
                Field("Anything in the way", barriers, { barriers = it }, hint = "optional")
                Field("Notes", notes, { notes = it }, hint = "optional", singleLine = false)
                if (inUse == "no") Text("Saying no adds a case note and a follow-up task, and a recorded follow-up cannot be changed.")
                ErrorText(error)
                TextButton(onClick = { send("snooze") }) { Text("Ask again in 14 days") }
                TextButton(onClick = { send("skip") }) { Text("Skip this check") }
            }
        },
        confirmButton = { TextButton(enabled = !busy && inUse.isNotEmpty(), onClick = { send("complete") }) { Text(if (busy) "Saving…" else "Record") } },
        dismissButton = { TextButton(onClick = onCancel) { Text("Cancel") } },
    )
}
