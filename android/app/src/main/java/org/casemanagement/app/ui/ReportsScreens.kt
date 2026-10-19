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
import androidx.navigation.NavHostController
import kotlinx.coroutines.launch
import org.casemanagement.app.Api
import org.casemanagement.app.ApiException
import org.casemanagement.app.objects
import org.casemanagement.app.str
import org.json.JSONObject
import java.util.Locale
import androidx.compose.runtime.key
import androidx.compose.runtime.mutableIntStateOf
import org.casemanagement.app.dialogKey


/** Search clients, case notes, referrals and tracks, as the website's search. */
@Composable
fun SearchScreen(api: Api, nav: NavHostController) {
    var q by rememberSaveable { mutableStateOf("") }
    var search by rememberSaveable { mutableStateOf("") }
    val state = rememberLoaded(search) { if (search.length < 2) emptyList() else api.call("search", mapOf("q" to search)).optJSONArray("results").objects() }
    Page {
        Field("Search for", q, { q = it }, hint = "at least 2 letters")
        SecondaryButton("Search") { search = q.trim() }
        if (search.length >= 2) Show(state, "results") { groups ->
            val n = groups.sumOf { it.optJSONArray("items")?.length() ?: 0 }
            Announce("$n ${if (n == 1) "result" else "results"}")
            if (n == 0) Text("Nothing found for $search.")
            groups.forEach { g ->
                val items = g.optJSONArray("items").objects()
                if (items.isEmpty()) return@forEach
                Heading(g.optString("group") + " (${items.size})")
                items.forEach { i ->
                    val client = if (i.isNull("client_id")) 0 else i.optInt("client_id")
                    ListRow(listOf(i.optString("title"), i.optString("subtitle")), action = "open it", onClick = when (i.optString("kind")) {
                        "referral" -> ({ nav.navigate("referral/${i.optInt("id")}") })
                        "client" -> ({ nav.navigate("client/${i.optInt("id")}") })
                        "track" -> if (client > 0) ({ nav.navigate("track/$client/${i.optInt("id")}") }) else null
                        else -> if (client > 0) ({ nav.navigate("client/$client") }) else null
                    })
                }
            }
        }
    }
}

/** Where every referral and client sits, and for how long. */
@Composable
fun PipelineScreen(api: Api, nav: NavHostController) {
    val state = rememberLoaded(api.userId) { api.call("pipeline") }
    Show(state, "the pipeline") { r ->
        Page {
            if (r.optBoolean("can_see_referrals")) {
                val refs = r.optJSONArray("referrals").objects()
                Heading("Referrals awaiting action (${refs.size})")
                refs.forEach { x -> ListRow(listOf(x.optString("name"), x.optString("status") + ", ${x.optInt("days_in_stage")} days" + (if (x.optBoolean("over_30")) ", over 30 days" else "") + ", " + (x.str("assigned_name") ?: "unassigned")), action = "open it", onClick = { nav.navigate("referral/${x.optInt("id")}") }) }
            }
            r.optJSONArray("stages").objects().forEach { s ->
                val clients = s.optJSONArray("clients").objects()
                Heading(s.optString("name") + " (${clients.size})")
                clients.forEach { c -> ListRow(listOf(c.optString("name"), "${c.optInt("days_in_stage")} days in stage" + (if (c.optBoolean("over_30")) ", over 30 days" else "") + (c.str("instructor")?.let { ", $it" } ?: "")), action = "open it", onClick = { nav.navigate("client/${c.optInt("id")}") }) }
            }
        }
    }
}

/** Authorizations at risk, progress reports due, equipment service due. */
@Composable
fun ComplianceScreen(api: Api, nav: NavHostController) {
    val state = rememberLoaded(api.userId) { api.call("compliance") }
    Show(state, "compliance") { r ->
        Page {
            val risk = r.optJSONArray("auth_risk").objects()
            Heading("Authorizations at risk (${risk.size})")
            risk.forEach { a -> ListRow(listOf(a.optString("client") + ": " + a.optString("title"), "${a.optInt("pct")}% of hours used" + (a.str("expiration_date")?.let { (if (a.optBoolean("expired")) ", ended " else ", ends ") + dayText(it) } ?: ""))) }
            val due = r.optJSONArray("reports_due").objects()
            Heading("Progress reports due (${due.size})")
            due.forEach { d -> ListRow(listOf(d.optString("client") + ": " + d.optString("title"), d.optString("cadence") + ", due " + dayText(d.str("due")))) }
            val eq = r.optJSONArray("equipment_due").objects()
            if (eq.isNotEmpty()) {
                Heading("Equipment service due (${eq.size})")
                eq.forEach { e -> ListRow(listOf(e.optString("name"), (if (e.optBoolean("overdue")) "overdue since " else "due ") + dayText(e.str("due")))) }
            }
        }
    }
}

/** The waitlist: each person's status, follow-up date and notes. */
@Composable
fun WaitlistScreen(api: Api) {
    val state = rememberLoaded(api.userId) { api.call("waitlist") }
    // 4.29.0: the chosen person's referral id, kept across rotation; the dialog's fields are keyed by it,
    // so one person's status and notes never show on (or save onto) another.
    var chosenId by rememberSaveable { mutableIntStateOf(0) }
    var notice by remember { mutableStateOf<String?>(null) }
    Show(state, "the waitlist") { r ->
        val statuses = r.optJSONArray("statuses").let { a -> List(a?.length() ?: 0) { a!!.optString(it) } }
        Page {
            NoticeText(notice)
            val items = r.optJSONArray("items").objects()
            Heading("Waitlist (${items.size})")
            items.forEach { w ->
                ListRow(listOf(w.optString("client_name"), w.optString("status") + (w.str("followup_date")?.let { ", follow up " + dayText(it) } ?: ""), w.str("notes") ?: ""),
                    action = "update it", onClick = { chosenId = w.optInt("referral_id") })
            }
        }
        r.optJSONArray("items").objects().firstOrNull { chosenId != 0 && it.optInt("referral_id") == chosenId }?.let { w ->
            key(dialogKey("waitlist", chosenId)) { WaitlistDialog(api, w, statuses, onDone = { m -> chosenId = 0; notice = m; state.reload() }, onCancel = { chosenId = 0 }) }
        }
    }
}

@Composable
private fun WaitlistDialog(api: Api, w: JSONObject, statuses: List<String>, onDone: (String) -> Unit, onCancel: () -> Unit) {
    var status by rememberSaveable { mutableStateOf(w.optString("status").takeIf { it in statuses } ?: statuses.firstOrNull() ?: "Waiting") }
    var followup by rememberSaveable { mutableStateOf(w.str("followup_date") ?: "") }
    var notes by rememberSaveable { mutableStateOf(w.str("notes") ?: "") }
    var error by remember { mutableStateOf<String?>(null) }
    var followupError by remember { mutableStateOf<String?>(null) }
    var busy by remember { mutableStateOf(false) }
    val scope = rememberCoroutineScope()
    AlertDialog(
        onDismissRequest = { if (!busy) onCancel() },
        title = { Text(w.optString("client_name"), modifier = Modifier.semantics { heading() }) },
        text = {
            Column(Modifier.verticalScroll(rememberScrollState())) {
                Choice("Status", status, statuses.map { it to it }) { status = it }
                Field("Follow up on", followup, { followup = it }, hint = "year-month-day, optional", error = followupError)
                Field("Notes", notes, { notes = it }, singleLine = false)
                ErrorText(error)
            }
        },
        confirmButton = {
            TextButton(enabled = !busy, onClick = {
                followupError = if (followup.isNotBlank() && !Regex("^\\d{4}-\\d{2}-\\d{2}$").matches(followup.trim())) "Enter the follow-up date as year-month-day, such as ${today()}, or leave it empty." else null
                followupError?.let { error = it; return@TextButton }
                scope.launchSave({ busy = it }, { error = it }) {
                    val r = api.call("waitlist_update", body = JSONObject().put("referral_id", w.optInt("referral_id")).put("status", status).put("followup_date", followup.trim()).put("notes", notes.trim()))
                    onDone(r.optString("message").ifEmpty { "Saved." })
                }
            }) { Text(if (busy) "Saving…" else "Save") }
        },
        dismissButton = { TextButton(enabled = !busy, onClick = onCancel) { Text("Cancel") } },
    )
}

/** Invoices by status, what each agency owes, and marking a draft submitted. */
@Composable
fun InvoicesScreen(api: Api) {
    var status by rememberSaveable { mutableStateOf("") }
    val state = rememberLoaded(status) { api.call("invoices", if (status.isEmpty()) emptyMap() else mapOf("status" to status)) }
    var submit by remember { mutableStateOf<JSONObject?>(null) }
    var notice by remember { mutableStateOf<String?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    val scope = rememberCoroutineScope()
    Page {
        Choice("Show", status, listOf("" to "All invoices", "Draft" to "Drafts", "Submitted" to "Submitted", "Paid" to "Paid", "Denied" to "Denied", "Void" to "Void")) { status = it }
        NoticeText(notice)
        ErrorText(error)
        Show(state, "invoices") { r ->
            val owed = r.optJSONArray("outstanding").objects()
            if (owed.isNotEmpty()) {
                Heading("Owed by each agency")
                owed.forEach { o -> ListRow(listOf(o.optString("funding_agency"), "${o.optInt("count")} invoices, " + money(o.optDouble("amount", 0.0)) + (o.optInt("oldest_days").takeIf { it > 0 }?.let { ", oldest $it days" } ?: ""))) }
            }
            val list = r.optJSONArray("invoices").objects()
            Heading("Invoices (${r.optInt("total", list.size)})")
            if (r.optInt("total", list.size) > list.size) Text("Showing the newest ${list.size}. Choose a status above, or see them all on the website.")
            list.forEach { i ->
                ListRow(listOf("${i.optString("invoice_number")}: ${i.optString("client_name")}", i.optString("status") + ", " + money(i.optDouble("total_amount", 0.0)) +
                    (if (i.optDouble("balance", 0.0) > 0 && i.optString("status") != "Draft") ", balance " + money(i.optDouble("balance", 0.0)) else "") +
                    (i.str("due_date")?.let { ", due " + dayText(it) } ?: ""), i.optString("funding_agency")),
                    action = "mark it submitted", onClick = if (i.optString("status") == "Draft") ({ submit = i }) else null)
            }
        }
    }
    submit?.let { i ->
        Confirm("Mark invoice ${i.optString("invoice_number")} submitted today?", "Its due date is set from today.", "Mark submitted", {
            scope.launch {
                try { val r = api.call("submit_invoice", body = JSONObject().put("id", i.optInt("id"))); notice = "Invoice ${i.optString("invoice_number")} submitted; due " + dayText(r.str("due_date")) + "."; state.reload() }
                catch (e: ApiException) { error = e.message }
            }
        }, { submit = null })
    }
}

/** Incidents: open ones first; report one, or change its status. */
@Composable
fun IncidentsScreen(api: Api) {
    var showAll by rememberSaveable { mutableStateOf(false) }
    val state = rememberLoaded(showAll) { api.call("incidents", if (showAll) mapOf("all" to "1") else emptyMap()) }
    // Kept across rotation and process death, as the report's fields are (4.28.0).
    var adding by rememberSaveable { mutableStateOf(false) }
    var chosen by remember { mutableStateOf<JSONObject?>(null) }
    var notice by remember { mutableStateOf<String?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    val scope = rememberCoroutineScope()
    Page {
        NoticeText(notice)
        ErrorText(error)
        PrimaryButton("Report an incident") { adding = true }
        Tick("Show closed incidents", showAll) { showAll = it }
        Show(state, "incidents") { r ->
            val list = r.optJSONArray("incidents").objects()
            Heading("Incidents (${list.size})")
            list.forEach { i ->
                ListRow(listOf(i.optString("type") + ", " + i.optString("severity") + ": " + dayText(i.str("incident_date")), i.optString("status") + (i.str("client_name")?.let { ", $it" } ?: ""), i.optString("description")),
                    action = "change its status", onClick = if (i.optBoolean("can_edit")) ({ chosen = i }) else null)
            }
        }
    }
    if (adding) IncidentDialog(api, state.data ?: JSONObject(), onDone = { m -> adding = false; notice = m; state.reload() }, onCancel = { adding = false })
    chosen?.let { i ->
        AlertDialog(
            onDismissRequest = { chosen = null },
            title = { Text(i.optString("type") + ": " + i.optString("status"), modifier = Modifier.semantics { heading() }) },
            text = {
                Column(Modifier.verticalScroll(rememberScrollState())) {
                    listOf("Open", "Under review", "Closed").filter { it != i.optString("status") }.forEach { to ->
                        TextButton(onClick = {
                            chosen = null
                            scope.launch {
                                try { api.call("incident_update", body = JSONObject().put("id", i.optInt("id")).put("to", to)); notice = "Incident is now $to."; state.reload() }
                                catch (e: ApiException) { error = e.message }
                            }
                        }) { Text("Mark it $to") }
                    }
                }
            },
            confirmButton = { TextButton(onClick = { chosen = null }) { Text("Cancel") } },
        )
    }
}

@Composable
private fun IncidentDialog(api: Api, r: JSONObject, onDone: (String) -> Unit, onCancel: () -> Unit) {
    val types = r.optJSONArray("types").let { a -> List(a?.length() ?: 0) { a!!.optString(it) } }.ifEmpty { listOf("Injury", "Near miss", "Equipment failure", "Behavioral", "Property damage", "Medical", "Safety concern", "Other") }
    var date by rememberSaveable { mutableStateOf(today()) }
    var type by rememberSaveable { mutableStateOf(types.firstOrNull() ?: "Other") }
    var severity by rememberSaveable { mutableStateOf("Low") }
    var description by rememberSaveable { mutableStateOf("") }
    var location by rememberSaveable { mutableStateOf("") }
    var action by rememberSaveable { mutableStateOf("") }
    var idem by rememberSaveable { mutableStateOf("") }
    var error by remember { mutableStateOf<String?>(null) }
    var dateError by remember { mutableStateOf<String?>(null) }
    var busy by remember { mutableStateOf(false) }
    val scope = rememberCoroutineScope()
    AlertDialog(
        onDismissRequest = onCancel,
        title = { Text("Report an incident", modifier = Modifier.semantics { heading() }) },
        text = {
            Column(Modifier.verticalScroll(rememberScrollState())) {
                Field("Date", date, { date = it }, hint = "year-month-day", error = dateError)
                Choice("Type", type, types.map { it to it }) { type = it }
                Choice("Severity", severity, listOf("Low", "Medium", "High", "Critical").map { it to it }) { severity = it }
                Field("What happened", description, { description = it }, singleLine = false, minLines = 3)
                Field("Where", location, { location = it }, hint = "optional")
                Field("What was done", action, { action = it }, hint = "optional", singleLine = false)
                ErrorText(error)
            }
        },
        confirmButton = {
            TextButton(enabled = !busy && description.isNotBlank(), onClick = {
                if (busy) return@TextButton
                dateError = if (!Regex("^\\d{4}-\\d{2}-\\d{2}$").matches(date.trim())) "Enter the date as year-month-day, such as ${today()}." else null
                if (dateError != null) { error = dateError; return@TextButton }
                scope.launchSave({ busy = it }, { error = it }) {
                    // 4.28.0: a retry after a lost reply reports it once (see sendOnce).
                    api.callOnce("add_incident", JSONObject().put("incident_date", date.trim()).put("type", type).put("severity", severity)
                        .put("description", description.trim()).put("location", location.trim()).put("action_taken", action.trim()), idem, { idem = it }, "the incident report")
                    onDone("Incident reported.")
                }
            }) { Text(if (busy) "Saving…" else "Report") }
        },
        dismissButton = { TextButton(onClick = onCancel) { Text("Cancel") } },
    )
}
