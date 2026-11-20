package org.casemanagement.app.ui

import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
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
import androidx.compose.foundation.layout.padding
import androidx.compose.ui.unit.dp
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
                // 4.34.0: a group cut at 25 says more matched, as the website does.
                Heading(searchGroupHeading(g.optString("group"), items.size, g.optBoolean("capped")))
                items.forEach { i ->
                    val client = if (i.isNull("client_id")) 0 else i.optInt("client_id")
                    val track = if (i.isNull("track_id")) 0 else i.optInt("track_id")
                    ListRow(listOf(i.optString("title"), i.optString("subtitle")), action = "open it", onClick = when (i.optString("kind")) {
                        "referral" -> ({ nav.navigate("referral/${i.optInt("id")}") })
                        "client" -> ({ nav.navigate("client/${i.optInt("id")}") })
                        "track" -> if (client > 0) ({ nav.navigate("track/$client/${i.optInt("id")}") }) else null
                        "goal" -> if (client > 0 && track > 0) ({ nav.navigate("track/$client/$track") }) else if (client > 0) ({ nav.navigate("client/$client") }) else null
                        "invoice" -> null
                        else -> if (client > 0) ({ nav.navigate("client/$client") }) else null
                    })
                }
            }
        }
    }
}

/** 4.34.0: "Notes (25)", or "Notes (first 25 shown, more match)" when the server stopped at its limit. */
fun searchGroupHeading(group: String, shown: Int, capped: Boolean): String = if (capped) "$group (first $shown shown, more match)" else "$group ($shown)"

/** 4.34.0: one consent that needs attention (consents_due), as lines of its row. */
fun consentRowWords(o: JSONObject): List<String> {
    val date = o.str("date")?.let { dayText(it) } ?: ""
    val whenWords = when (o.optString("kind")) {
        "Expired" -> "ended $date"
        "Expiring" -> "ends $date"
        "Withdrawn" -> "withdrawn $date"
        "Unsigned" -> "sent $date"
        "Adult" -> "client turned 18 on $date"
        else -> date
    }
    val head = o.optString("client") + (o.str("guardian_name")?.let { ", guardian $it" } ?: "")
    val state = listOf(o.optString("label").ifEmpty { o.optString("kind") }, whenWords).filter { it.isNotBlank() }.joinToString(", ")
    val resent = o.str("pending_sent_at")?.let { "A new request was sent " + dayText(it) }
    return listOfNotNull(head, state, resent, o.str("action"))
}

/** 4.34.0: an incident date after today is refused, as the website refuses it. */
fun incidentDateNotFuture(date: String, todayIs: String): Boolean = date.trim() <= todayIs

/** 4.34.0: an invoice's due date is shown only once it has been submitted (a draft's is not set yet). */
fun invoiceDueShownOnlySubmitted(status: String, due: String?): String? = if (status == "Submitted" && !due.isNullOrBlank()) due else null

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
            // 4.37.0 (S20): as the website and the iPhone.
            PipelineClosedSection(r, nav)
        }
    }
}

/**
 * 4.36.0: every section of the website's compliance page, in its order, each row opening its
 * item. A 4.35.0 server sends the first three only; its consents come from consents_due.
 */
@Composable
fun ComplianceScreen(api: Api, nav: NavHostController) {
    val state = rememberLoaded(api.userId) { api.call("compliance") }
    val needsConsents = state.data?.let { !it.has("consents") } ?: false
    val consents = rememberLoaded(api.userId, needsConsents) { if (needsConsents) api.call("consents_due").optJSONArray("consents") else null }
    Show(state, "compliance") { r ->
        val merged = if (r.has("consents")) r else consents.data?.let { JSONObject(r.toString()).put("consents", it) } ?: r
        Page {
            complianceSections(merged, api.can("equipment"), api.can("billing")) { consentRowWords(it) }.forEach { sec -> ComplianceBlock(sec, nav, top = true) }
            if (needsConsents && consents.data == null) {
                Heading("Consents to renew")
                Show(consents, "consents") { }
            }
        }
    }
}

@Composable
private fun ComplianceBlock(sec: ComplianceSection, nav: NavHostController, top: Boolean) {
    if (top) Heading(sec.heading) else Text(sec.heading, fontWeight = androidx.compose.ui.text.font.FontWeight.SemiBold,
        modifier = Modifier.padding(top = 8.dp).semantics { heading() })
    sec.intro?.let { Text(it) }
    if (sec.rows.isEmpty() && sec.subs.isEmpty()) sec.empty?.let { Text(it) }
    sec.note?.let { Text(it) }
    sec.rows.forEach { row -> ListRow(row.lines, action = if (row.route != null) "open it" else null, onClick = row.route?.let { rt -> { nav.navigate(rt) } }) }
    sec.subs.forEach { ComplianceBlock(it, nav, top = false) }
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
        Choice("Show", status, listOf("" to "All invoices", "Draft" to "Drafts", "Submitted" to "Submitted", "Paid" to "Paid", "Denied" to "Denied", "Void" to "Void")) { status = it; error = null }
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
                    (invoiceDueShownOnlySubmitted(i.optString("status"), i.str("due_date"))?.let { ", due " + dayText(it) } ?: ""), i.optString("funding_agency")),
                    action = "mark it submitted", onClick = if (i.optString("status") == "Draft") ({ submit = i }) else null)
            }
        }
    }
    submit?.let { i -> key(dialogKey("submit", i.optInt("id"))) { SubmitInvoiceDialog(api, i, onDone = { m -> submit = null; error = null; notice = m; state.reload() },
        onRefused = { m -> submit = null; notice = null; error = m; state.reload() }, onCancel = { submit = null }) } }
}

/**
 * 4.34.0: marking a draft submitted asks the date it was sent to the funder (today by default),
 * as the website does; the due date follows from that day and the payment terms.
 */
@Composable
private fun SubmitInvoiceDialog(api: Api, i: JSONObject, onDone: (String) -> Unit, onRefused: (String) -> Unit, onCancel: () -> Unit) {
    val number = i.optString("invoice_number")
    var date by rememberSaveable { mutableStateOf(today()) }
    var dateError by remember { mutableStateOf<String?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    var busy by remember { mutableStateOf(false) }
    var idem by rememberSaveable { mutableStateOf("") }
    val scope = rememberCoroutineScope()
    FormDialog("Mark invoice $number as submitted to the funder?", "Mark submitted", busy, onSave = {
        val created = i.str("created_at")?.take(10) ?: "2000-01-01"
        dateError = if (!pastOrToday(date) || date.isBlank()) "Date submitted: enter a date as year-month-day, today or earlier."
            else if (date.trim() < created) "Date submitted: the invoice was created on ${dayText(created)}, so it cannot have been sent earlier." else null
        dateError?.let { error = it; return@FormDialog }
        scope.launchSave({ busy = it }, { error = it }) {
            try {
                val r = api.callOnce("submit_invoice", JSONObject().put("id", i.optInt("id")).put("submitted_on", date.trim()), idem, { idem = it }, "marking the invoice submitted")
                onDone("Invoice $number marked submitted on ${dayText(date.trim())}" + (r.str("due_date")?.let { "; it is due " + dayText(it) } ?: "") + ".")
            } catch (e: ApiException) {
                // Someone else changed it meanwhile: the list is read again.
                if (e.status == 409) onRefused(e.message ?: "The invoice changed meanwhile.") else throw e
            }
        }
    }, onCancel = onCancel) {
        Text("Its due date is set from the payment terms.")
        Field("Date submitted", date, { date = it }, hint = "year-month-day", error = dateError, required = true)
        ErrorText(error)
    }
}

/** Incidents: open ones first; report one, or change its status. */
@Composable
fun IncidentsScreen(api: Api, nav: NavHostController) {
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
        // 4.36.0: a possible privacy breach is reported on its own form, as the website's.
        if (api.can("clients")) PrimaryButton("Report a privacy incident") { nav.navigate("privacyIncident") }
        Tick("Show closed incidents", showAll) { showAll = it }
        Show(state, "incidents") { r ->
            val list = r.optJSONArray("incidents").objects()
            Heading("Incidents (${list.size})")
            list.forEach { i ->
                ListRow(listOf(i.optString("type") + ", " + i.optString("severity") + ": " + dayText(i.str("incident_date")), i.optString("status") + (i.str("client_name")?.let { ", $it" } ?: "") +
                    (i.str("reporter")?.let { ", reported by $it" } ?: ""), i.optString("description")),
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
    // 4.34.0: the agency's own list only (the server refuses any other type).
    val types = r.optJSONArray("types").let { a -> List(a?.length() ?: 0) { a!!.optString(it) } }.filter { it.isNotBlank() }
    var date by rememberSaveable { mutableStateOf(today()) }
    var type by rememberSaveable { mutableStateOf("") }
    var typeError by remember { mutableStateOf<String?>(null) }
    var severity by rememberSaveable { mutableStateOf("Low") }
    var description by rememberSaveable { mutableStateOf("") }
    var location by rememberSaveable { mutableStateOf("") }
    var action by rememberSaveable { mutableStateOf("") }
    // 4.36.0: the client it concerns, if one (closed clients too, as the iPhone's picker).
    var clientId by rememberSaveable { mutableStateOf("0") }
    var clientError by remember { mutableStateOf<String?>(null) }
    val clients = rememberLoaded(api.userId) { try { org.casemanagement.app.parseClients(api.call("client_choices")) } catch (e: ApiException) { emptyList() } }
    var idem by rememberSaveable { mutableStateOf("") }
    var error by remember { mutableStateOf<String?>(null) }
    var dateError by remember { mutableStateOf<String?>(null) }
    var descriptionError by remember { mutableStateOf<String?>(null) }
    var busy by remember { mutableStateOf(false) }
    var presses by remember { androidx.compose.runtime.mutableIntStateOf(0) }
    val scope = rememberCoroutineScope()
    AlertDialog(
        onDismissRequest = onCancel,
        title = { Text("Report an incident", modifier = Modifier.semantics { heading() }) },
        text = {
            Column(Modifier.verticalScroll(rememberScrollState())) {
                Field("Date", date, { date = it }, hint = "year-month-day", error = dateError, required = true)
                Choice("Type", type, listOf("" to "Choose a type") + types.map { it to it }, typeError, required = true) { type = it; typeError = null }
                Choice("Severity", severity, listOf("Low", "Medium", "High", "Critical").map { it to it }) { severity = it }
                Choice("Client (optional)", clientId, listOf("0" to "None") + (clients.data ?: emptyList()).map { it.id.toString() to it.name }, error = clientError) { clientId = it; clientError = null }
                Field("What happened", description, { description = it }, singleLine = false, minLines = 3, required = true, error = descriptionError)
                Field("Where", location, { location = it }, hint = "optional")
                Field("What was done", action, { action = it }, hint = "optional", singleLine = false)
                ErrorText(error, focusKey = presses)
            }
        },
        confirmButton = {
            TextButton(enabled = !busy, onClick = {
                if (busy) return@TextButton
                presses++
                dateError = if (!Regex("^\\d{4}-\\d{2}-\\d{2}$").matches(date.trim())) "Enter the date as year-month-day, such as ${today()}."
                    else if (!incidentDateNotFuture(date, today())) "The incident date cannot be in the future. Check the year." else null
                typeError = if (type.isEmpty()) "Choose a type from the list." else null
                descriptionError = if (description.isBlank()) "Say what happened." else null
                errorSummary(listOf(dateError, typeError, descriptionError))?.let { error = it; return@TextButton }
                scope.launchSave({ busy = it }, { error = it }) {
                    // 4.28.0: a retry after a lost reply reports it once (see sendOnce).
                    try {
                        api.callOnce("add_incident", incidentBody(date, type, severity, description, location, action, clientId.toIntOrNull() ?: 0), idem, { idem = it }, "the incident report")
                    } catch (e: ApiException) {
                        when (incidentFieldFor(e.message)) {
                            "client" -> clientError = e.message
                            "date" -> dateError = e.message
                            "type" -> typeError = e.message
                            "description" -> descriptionError = e.message
                        }
                        throw e
                    }
                    onDone("Incident reported.")
                }
            }) { Text(if (busy) "Saving…" else "Report") }
        },
        dismissButton = { TextButton(onClick = onCancel) { Text("Cancel") } },
    )
}
