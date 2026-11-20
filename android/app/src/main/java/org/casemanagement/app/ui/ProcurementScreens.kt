package org.casemanagement.app.ui

import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.foundation.verticalScroll
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.semantics
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.text.input.KeyboardType
import androidx.navigation.NavHostController
import kotlinx.coroutines.launch
import org.casemanagement.app.Api
import org.casemanagement.app.ApiException
import org.casemanagement.app.mergeReloaded
import org.casemanagement.app.mergeReloadedMap
import org.casemanagement.app.openedValues
import androidx.compose.runtime.LaunchedEffect
import org.casemanagement.app.objects
import org.casemanagement.app.str
import org.json.JSONObject
import androidx.compose.runtime.key
import org.casemanagement.app.dialogKey

/* ---------------- Device procurement ---------------- */

private val CLOSED_REQUEST = setOf("Trained", "Denied", "Cancelled")

@Composable
fun ProcurementScreen(api: Api, nav: NavHostController) {
    val state = rememberLoaded(api.userId) { api.call("device_requests").optJSONArray("requests").objects() }
    var adding by rememberSaveable { mutableStateOf(false) }
    Page {
        PrimaryButton("Request a device") { adding = true }
        Show(state, "device requests") { list ->
            val open = list.filter { it.optString("status") !in CLOSED_REQUEST }
            val closed = list - open.toSet()
            listOf("Open" to open, "Closed" to closed).forEach { (title, l) ->
                Heading("$title (${l.size})")
                l.forEach { r -> ListRow(listOf(r.optString("device_name") + " for " + r.optString("client_name"), r.optString("status") + (if (r.isNull("authorized_amount")) "" else ", authorized " + money(r.optDouble("authorized_amount", 0.0)))), action = "open it", onClick = { nav.navigate("deviceRequest/${r.optInt("id")}") }) }
            }
        }
    }
    if (adding) AddDeviceRequestDialog(api, onDone = { id -> adding = false; nav.navigate("deviceRequest/$id") }, onCancel = { adding = false })
}

/**
 * A new device request. 4.37.0: "Justification of need" (the website's label) with "Draft
 * justification with AI" (S5); a request started from the AI recommendations comes with the client
 * and the recommendations as the justification (S6).
 */
@Composable
internal fun AddDeviceRequestDialog(api: Api, onDone: (Int) -> Unit, onCancel: () -> Unit, initialClientId: Int = 0, initialJustification: String = "") {
    val clients = rememberLoaded(api.userId) { clientChoices(api) }
    val cats = api.formList("device_request_categories", listOf("Other"))
    var client by rememberSaveable { mutableStateOf(if (initialClientId > 0) initialClientId.toString() else "") }
    var device by rememberSaveable { mutableStateOf("") }
    var cat by rememberSaveable { mutableStateOf(cats.first()) }
    var why by rememberSaveable { mutableStateOf(initialJustification) }
    var idem by rememberSaveable { mutableStateOf("") }
    var error by remember { mutableStateOf<String?>(null) }
    var errors by remember { mutableStateOf<Map<String, String>>(emptyMap()) }
    var notice by remember { mutableStateOf<String?>(null) }
    var busy by remember { mutableStateOf(false) }
    var drafting by remember { mutableStateOf(false) }
    val scope = rememberCoroutineScope()
    FormDialog("Request a device", "Add", busy, missing = stillNeeded(missingNames("Client" to client.isEmpty(), "Device" to device.isBlank())), onSave = {
        if (busy) return@FormDialog
        busy = true; error = null; errors = emptyMap()
        scope.launch {
            try {
                // 4.28.0: a retry after a lost reply adds it once (see sendOnce).
                onDone(api.callOnce("add_device_request", JSONObject().put("client_id", client.toInt()).put("device_name", device.trim()).put("category", cat).put("justification", why.trim()),
                    idem, { idem = it }, "the device request").optInt("id"))
            } catch (e: ApiException) {
                errors = replyErrors(e.reply)
                error = errorSummary(errors.values) ?: e.message
            } finally { busy = false }
        }
    }, onCancel = onCancel) {
        ErrorText(clients.error, takeFocus = false)
        Choice("Client", client, listOf("" to "Choose a client") + (clients.data ?: emptyList()).map { it.optInt("id").toString() to it.optString("name") }, required = true, error = errors["client_id"]) { client = it }
        Field("Device", device, { device = it }, required = true, error = errors["device_name"])
        Choice("Category", cat, cats.map { it to it }, error = errors["category"]) { cat = it }
        Field(JUSTIFICATION_LABEL, why, { why = it; errors = errors - "justification" }, hint = "optional; up to 4,000 characters", singleLine = false, minLines = 4, error = errors["justification"], enabled = !drafting)
        if (api.aiHas("draft") && client.isNotEmpty() && device.isNotBlank()) {
            SecondaryButton(if (drafting) "Drafting…" else "Draft justification with AI") {
                if (drafting) return@SecondaryButton
                drafting = true; error = null; notice = null
                val sent = why
                scope.launch {
                    try {
                        val r = api.call("ai_draft", body = justificationBody(client.toInt(), device, cat, sent))
                        // Text typed while the AI worked is kept, with the draft below it.
                        why = mergeAfterDraft(sent, why, r.str("text") ?: mergeDraft(sent, r.optString("draft")), r.optString("draft"))
                        notice = listOfNotNull(justificationNotice(r), placeholderNote(why)).joinToString(" ")
                    } catch (e: ApiException) {
                        errors = replyErrors(e.reply)
                        error = errorSummary(errors.values) ?: e.message
                    } finally { drafting = false }
                }
            }
            Announce(if (drafting) "Drafting…" else null, key = drafting)
        }
        NoticeText(notice)
        ErrorText(error)
    }
}

/** One device request through its steps: quotes, authorization, order, delivery, training. */
/** 4.34.0: "Request reopened. Status updated to Ordered." (plain "Request reopened." from an older server). */
fun reopenedWords(r: JSONObject): String = r.str("status")?.let { "Request reopened. Status updated to $it." } ?: "Request reopened."

@Composable
fun DeviceRequestScreen(api: Api, id: Int, nav: NavHostController? = null) {
    val state = rememberLoaded(id) { api.call("device_request", mapOf("id" to id.toString())) }
    var notice by remember { mutableStateOf<String?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    var step by rememberSaveable { mutableStateOf("") }
    var ask by remember { mutableStateOf<Triple<String, String, JSONObject>?>(null) }
    // 4.28.0: adding a quote is sent with a retry key, so a lost reply never adds it twice (see sendOnce).
    var quoteKey by rememberSaveable { mutableStateOf("") }
    var reopening by remember { mutableStateOf(false) }
    // 4.37.0 (S4): deny or cancel with a reason, in a dialog with a field.
    var closing by rememberSaveable { mutableStateOf("") }
    // The server's own sentence for the last change (shown as it is, without the status added).
    var said by remember { mutableStateOf<String?>(null) }
    val scope = rememberCoroutineScope()
    suspend fun send(body: JSONObject): JSONObject =
        if (body.optString("op") == "add_quote") api.callOnce("device_request_update", body.put("id", id), quoteKey, { quoteKey = it }, "the quote")
        else api.call("device_request_update", body = body.put("id", id))
    fun update(body: JSONObject, done: String) {
        error = null
        scope.launch {
            try { send(body); state.reload(); notice = done } catch (e: ApiException) { error = e.message }
        }
    }
    Show(state, "the request") { r ->
        val q = r.optJSONObject("request") ?: JSONObject()
        val status = q.optString("status")
        val closed = status in CLOSED_REQUEST
        Page {
            Heading(q.optString("device_name") + " for " + q.optString("client_name"), large = true)
            NoticeText(said ?: notice?.let { "$it Status: $status." })
            ErrorText(error)
            ListRow(listOf("Status: $status"))
            listOfNotNull(q.str("category")?.let { "Category: $it" }, q.str("justification")?.let { "Why: $it" },
                q.str("funding_source")?.let { "Funding: $it" + (q.str("auth_number")?.let { n -> ", authorization $n" } ?: "") },
                if (q.isNull("authorized_amount")) null else "Authorized " + money(q.optDouble("authorized_amount", 0.0)) + (q.str("authorized_at")?.let { " on " + dayText(it) } ?: ""),
                q.str("po_number")?.let { "Order number $it" + (q.str("ordered_at")?.let { d -> ", ordered " + dayText(d) } ?: "") },
                q.str("delivered_at")?.let { "Delivered " + dayText(it) + (q.str("serial")?.let { s -> ", serial $s" } ?: "") },
                q.str("trained_at")?.let { "Training completed " + dayText(it) }, q.str("notes")?.let { "Note: $it" }).forEach { ListRow(listOf(it)) }
            // 4.37.0 (S4): the letter built from the request, as the website downloads it.
            if (nav != null) SecondaryButton("Justification letter") { nav.navigate("deviceLetter/$id?editable=" + (if (closed) "0" else "1")) }
            val quotes = r.optJSONArray("quotes").objects()
            Heading("Quotes (${quotes.size})")
            quotes.forEach { qq ->
                val picked = !q.isNull("selected_quote_id") && q.optInt("selected_quote_id") == qq.optInt("id")
                ListRow(listOf(qq.optString("vendor") + ", " + money(qq.optDouble("amount", 0.0)) + if (picked) ", chosen" else "", qq.str("quote_ref") ?: ""),
                    action = "choose this quote", onClick = if (closed || picked) null else ({ update(JSONObject().put("op", "select_quote").put("quote_id", qq.optInt("id")), "Quote chosen.") }))
            }
            if (!closed) {
                SecondaryButton("Add a quote") { step = "add_quote" }
                Heading("Next steps")
                SecondaryButton((if (q.isNull("authorized_amount")) "Record" else "Edit") + " the authorization") { step = "authorize" }
                SecondaryButton((if (q.str("ordered_at") == null) "Record" else "Edit") + " the order") { step = "order" }
                SecondaryButton((if (q.str("delivered_at") == null) "Record" else "Edit") + " the delivery") { step = "deliver" }
                SecondaryButton("Complete training") { ask = Triple("Mark training complete and close this request?", "A trained request cannot be reopened.", JSONObject().put("op", "train")) }
                SecondaryButton("Mark denied") { said = null; closing = "Denied" }
                SecondaryButton("Cancel the request") { said = null; closing = "Cancelled" }
            } else if (status != "Trained") SecondaryButton(if (reopening) "Reopening…" else "Reopen the request") {
                // 4.34.0: it returns to its furthest step, which the reply names; one press at a time.
                if (reopening) return@SecondaryButton
                reopening = true; error = null; said = null
                scope.launch {
                    try { val r = api.call("device_request_update", body = JSONObject().put("op", "status").put("to", "Requested").put("id", id)); state.reload(); notice = reopenedWords(r) }
                    catch (e: ApiException) { error = e.message } finally { reopening = false }
                }
            }
        }
        // 4.29.0: the dialog stays open, with what was typed, until the server took it; a refusal shows inside it.
        if (step.isNotEmpty()) key(dialogKey("device-step", step)) {
            StepDialog(api, step, q, send = { b -> send(b) }, onDone = { done -> step = ""; error = null; said = null; notice = done; state.reload() },
                onServer = { m -> step = ""; error = null; notice = null; said = m; state.reload() }, onCancel = { step = "" })
        }
        if (closing.isNotEmpty()) key(dialogKey("device-close", closing)) {
            CloseRequestDialog(q.optString("device_name"), closing, q.str("notes") ?: "", send = { b -> send(b) },
                onDone = { m -> closing = ""; error = null; notice = null; said = m; state.reload() }, onCancel = { closing = "" })
        }
    }
    ask?.let { (t, m, b) -> Confirm(t, m, "Yes", { said = null; update(b, "Done.") }, { ask = null }) }
}

@Composable
private fun StepDialog(api: Api, step: String, q: JSONObject, send: suspend (JSONObject) -> JSONObject, onDone: (String) -> Unit, onServer: (String) -> Unit, onCancel: () -> Unit) {
    // 4.37.0 (S4): a delivery can add the device to the loaner inventory (off to start, as the website).
    var inventory by rememberSaveable(step) { mutableStateOf(false) }
    val f = remember(step) {
        when (step) {
            "add_quote" -> listOf("vendor" to "Vendor", "amount" to "Amount in dollars", "quote_ref" to "Quote reference, optional", "notes" to "Notes, optional")
            "authorize" -> listOf("funding_source" to "Funding source", "auth_number" to "Authorization number", "authorized_amount" to "Authorized amount in dollars", "authorized_at" to "Authorized on, year-month-day")
            "order" -> listOf("po_number" to "Order number", "ordered_at" to "Ordered on, year-month-day")
            else -> listOf("serial" to "Serial number", "delivered_at" to "Delivered on, year-month-day", "warranty_until" to "Warranty until, year-month-day, optional")
        }
    }
    // 4.29.0: kept across rotation (as pairs), for this step only.
    val values = rememberSaveable(step) { mutableStateOf(f.map { (k, _) -> k to (q.str(k) ?: "") }) }
    val map = values.value.toMap()
    var error by remember { mutableStateOf<String?>(null) }
    var fieldErrors by remember { mutableStateOf<Map<String, String>>(emptyMap()) }
    var busy by remember { mutableStateOf(false) }
    val scope = rememberCoroutineScope()
    val title = mapOf("add_quote" to "Add a quote", "authorize" to "Authorization", "order" to "Order", "deliver" to "Delivery")[step] ?: ""
    val day = Regex("^\\d{4}-\\d{2}-\\d{2}$")
    FormDialog(title, "Save", busy, onSave = {
        if (busy) return@FormDialog
        val b = JSONObject().put("op", step)
        val problems = linkedMapOf<String, String>()
        for ((k, v) in map) {
            if (k.endsWith("amount")) {
                if (v.isBlank() && k == "amount") { problems[k] = "Enter the amount in dollars."; continue }
                if (v.isNotBlank()) { val m = moneyText(v); if (m == null) problems[k] = "Enter the amount in dollars, such as 1299.00." else b.put(k, m) } else b.put(k, "")
            } else {
                if (k.endsWith("_at") || k == "warranty_until") {
                    if (v.isNotBlank() && !day.matches(v.trim())) problems[k] = "Enter the date as year-month-day, such as ${today()}."
                }
                b.put(k, v.trim())
            }
        }
        if (step == "add_quote" && map["vendor"].isNullOrBlank()) problems["vendor"] = "Enter the vendor."
        fieldErrors = problems
        if (problems.isNotEmpty()) { error = errorSummary(problems.values); return@FormDialog }
        scope.launchSave({ busy = it }, { error = it }) {
            val sent = if (step == "deliver") deliverBody(b, inventory, api.can("equipment")) else b
            val reply = try { send(sent) } catch (e: ApiException) {
                // A refusal naming a field (a delivery before the order, say) is shown on that field.
                val errs = e.reply?.optJSONObject("errors")
                fieldErrors = errs?.keys()?.asSequence()?.associateWith { errs.optString(it) }
                    ?: stepFieldOf(e.message ?: "", f.map { it.first })?.let { mapOf(it to (e.message ?: "")) } ?: emptyMap()
                throw e
            }
            val m = reply.str("message")
            if (step == "deliver" && m != null) onServer(m) else onDone(if (step == "add_quote") "Quote added." else "$title saved.")
        }
    }, onCancel = onCancel) {
        f.forEach { (k, label) -> Field(label, map[k] ?: "", { v -> values.value = (map + (k to v)).toList() }, keyboard = if (k.endsWith("amount")) KeyboardType.Decimal else KeyboardType.Text, error = fieldErrors[k]) }
        if (step == "deliver" && api.can("equipment")) Tick(INVENTORY_TICK, inventory) { inventory = it }
        ErrorText(error)
    }
}

/** 4.37.0 (S4): deny or cancel a request, with the reason (the stored note to start with). */
@Composable
private fun CloseRequestDialog(device: String, to: String, stored: String, send: suspend (JSONObject) -> JSONObject, onDone: (String) -> Unit, onCancel: () -> Unit) {
    var reason by rememberSaveable(to) { mutableStateOf(stored) }
    var error by remember { mutableStateOf<String?>(null) }
    var reasonError by remember { mutableStateOf<String?>(null) }
    var busy by remember { mutableStateOf(false) }
    val scope = rememberCoroutineScope()
    AlertDialog(
        onDismissRequest = { if (!busy) onCancel() },
        title = { Text(closeTitle(device, to), modifier = androidx.compose.ui.Modifier.semantics { heading() }) },
        text = {
            androidx.compose.foundation.layout.Column(androidx.compose.ui.Modifier.verticalScroll(androidx.compose.foundation.rememberScrollState())) {
                Text(CLOSE_REQUEST_TEXT)
                Field(CLOSE_REASON_LABEL, reason, { reason = it; reasonError = null }, hint = CLOSE_REASON_HINT, singleLine = false, minLines = 2, error = reasonError)
                ErrorText(error)
            }
        },
        confirmButton = { TextButton(enabled = !busy, onClick = {
            if (codePointCount(reason.trim()) > 500) { reasonError = CLOSE_REASON_TOO_LONG; error = CLOSE_REASON_TOO_LONG; return@TextButton }
            busy = true; error = null
            scope.launch {
                try { onDone(send(closeBody(to, reason)).str("message") ?: "Status updated to $to.") }
                catch (e: ApiException) { reasonError = replyErrors(e.reply)["notes"]; error = reasonError ?: e.message } finally { busy = false }
            }
        }) { Text(if (busy) "Saving…" else closeYes(to)) } },
        dismissButton = { TextButton(enabled = !busy, onClick = onCancel) { Text("Keep request open") } },
    )
}

/** Which field of a device-request step the server's refusal is about (it names it in words), or null. */
private fun stepFieldOf(message: String, fields: List<String>): String? {
    val m = message.lowercase()
    val words = listOf("vendor" to "vendor", "authorized amount" to "authorized_amount", "amount" to "amount", "amount" to "authorized_amount",
        "authoriz" to "authorized_at", "order number" to "po_number", "order date" to "ordered_at", "delivery date" to "delivered_at",
        "deliver" to "delivered_at", "warranty" to "warranty_until", "serial" to "serial", "funding" to "funding_source", "ordered" to "ordered_at")
    return words.firstOrNull { (w, k) -> m.startsWith(w) && k in fields }?.second ?: words.firstOrNull { (w, k) -> m.contains(w) && k in fields }?.second
}

/* ---------------- Worksite accommodations ---------------- */

private val WORKSITE_STATUSES = listOf("Scheduled", "Assessed", "Recommendations Sent", "Implemented", "Follow-up", "Closed")

@Composable
fun AccommodationsScreen(api: Api, nav: NavHostController) {
    var client by rememberSaveable { mutableStateOf("") }
    val clients = rememberLoaded(api.userId) { clientChoices(api, includeClosed = true) }
    val state = rememberLoaded(client) { api.call("worksite_assessments", if (client.isEmpty()) emptyMap() else mapOf("client_id" to client)) }
    var adding by rememberSaveable { mutableStateOf(false) }
    Page {
        PrimaryButton("Add a worksite assessment") { adding = true }
        Choice("Show assessments for", client, listOf("" to "All clients") + (clients.data ?: emptyList()).map { it.optInt("id").toString() to it.optString("name") }) { client = it }
        Show(state, "worksite assessments") { r ->
            val list = r.optJSONArray("assessments").objects()
            Heading("Worksite assessments (${list.size})")
            if (r.optBoolean("truncated")) Text("Showing the ${list.size} most recently updated of ${r.optInt("total")}. " + if (client.isEmpty()) "Choose a client above to find an older one." else "The website lists them all.")
            list.forEach { w -> ListRow(listOf(w.optString("client_name") + listOfNotNull(w.str("employer"), w.str("job_title")).joinToString(", ", prefix = ": ").takeIf { w.str("employer") != null || w.str("job_title") != null }.orEmpty(), w.optString("status") + (w.str("assessment_date")?.let { ", " + dayText(it) } ?: "")), action = "open it", onClick = { nav.navigate("worksite/${w.optInt("id")}") }) }
        }
    }
    if (adding) {
        var c by rememberSaveable { mutableStateOf("") }
        var employer by rememberSaveable { mutableStateOf("") }
        var job by rememberSaveable { mutableStateOf("") }
        var date by rememberSaveable { mutableStateOf("") }
        var idem by rememberSaveable { mutableStateOf("") }
        var error by remember { mutableStateOf<String?>(null) }
        var busy by remember { mutableStateOf(false) }
        val scope = rememberCoroutineScope()
        FormDialog("Add a worksite assessment", "Add", busy, missing = stillNeeded(missingNames("Client" to c.isEmpty())), onSave = {
            scope.launchSave({ busy = it }, { error = it }) {
                val id = api.callOnce("add_worksite", JSONObject().put("client_id", c.toInt()).put("employer", employer.trim()).put("job_title", job.trim()).put("assessment_date", date.trim()),
                    idem, { idem = it }, "the worksite assessment").optInt("id")
                adding = false; nav.navigate("worksite/$id")
            }
        }, onCancel = { adding = false }) {
            Choice("Client", c, listOf("" to "Choose a client") + (clients.data ?: emptyList()).filter { !it.optString("status").startsWith("Closed") }.map { it.optInt("id").toString() to it.optString("name") }, required = true) { c = it }
            Field("Employer", employer, { employer = it }, hint = "optional")
            Field("Job title", job, { job = it }, hint = "optional")
            Field("Assessment date", date, { date = it }, hint = "year-month-day, optional")
            ErrorText(error)
        }
    }
}

@Composable
fun WorksiteScreen(api: Api, nav: NavHostController, id: Int) {
    val state = rememberLoaded(id) { api.call("worksite_assessment", mapOf("id" to id.toString())).optJSONObject("assessment") ?: JSONObject() }
    Show(state, "the assessment") { a ->
        val keys = listOf("employer" to "Employer", "job_title" to "Job title", "worksite_location" to "Worksite location", "assessment_date" to "Assessment date (year-month-day)",
            "job_tasks" to "Job tasks", "barriers" to "Barriers", "recommendations" to "Recommendations", "follow_up_date" to "Follow-up date (year-month-day)", "outcome" to "Outcome")
        fun opened(o: JSONObject) = JSONObject().also { s -> (keys.map { it.first } + "status").forEach { k -> s.put(k, o.str(k) ?: "") }; o.str("updated_at")?.let { s.put("updated_at", it) } }
        val values = rememberSaveable(id) { mutableStateOf(keys.associate { (k, _) -> k to (a.str(k) ?: "") }.toList()) }
        var status by rememberSaveable { mutableStateOf(a.optString("status")) }
        // 4.28.0: what the form opened with (and when it was read), kept across process death; sent as loaded_at and orig.
        var baseJson by rememberSaveable(id) { mutableStateOf(opened(a).toString()) }
        val base = remember(baseJson) { try { JSONObject(baseJson) } catch (e: Exception) { JSONObject() } }
        var conflict by remember { mutableStateOf(false) }
        var reloading by remember { mutableStateOf(false) }
        var notice by remember { mutableStateOf<String?>(null) }
        var error by remember { mutableStateOf<String?>(null) }
        var busy by remember { mutableStateOf(false) }
        var aiBusy by remember { mutableStateOf(false) }
        val scope = rememberCoroutineScope()
        val map = values.value.toMap()
        // Read again after a conflict: what was changed here stays; everything else takes the saved values.
        LaunchedEffect(a) {
            if (!reloading) return@LaunchedEffect
            reloading = false
            val was = keys.associate { (k, _) -> k to (base.str(k) ?: "") }
            val now = keys.associate { (k, _) -> k to (a.str(k) ?: "") }
            values.value = mergeReloadedMap(values.value.toMap(), was, now).toList()
            status = mergeReloaded(status, base.str("status") ?: "", a.optString("status"))
            baseJson = opened(a).toString()
            conflict = false; error = null
            notice = "Read again. The other changes are now shown, and what you changed here is kept. Check the form, then save again."
        }
        // A reload that failed leaves Reload usable again.
        LaunchedEffect(state.loading, state.error) { if (reloading && !state.loading && state.error != null) reloading = false }
        Page {
            Heading(a.optString("client_name"), large = true)
            NoticeText(notice)
            Choice("Status", status, WORKSITE_STATUSES.map { it to it }) { status = it }
            keys.forEach { (k, label) ->
                Field(label, map[k] ?: "", { v -> values.value = (map + (k to v)).toList() }, singleLine = k !in setOf("job_tasks", "barriers", "recommendations", "outcome"), enabled = !(aiBusy && k == "recommendations"))
                // 4.37.0 (S8): the website's AI draft, below what was typed; nothing is saved until Save.
                if (k == "recommendations" && api.aiHas("draft")) {
                    SecondaryButton(if (aiBusy) "Drafting…" else "Draft recommendations with AI") {
                        if (aiBusy) return@SecondaryButton
                        aiBusy = true; error = null; notice = null
                        val sent = map["recommendations"].orEmpty()
                        scope.launch {
                            try {
                                val r = api.call("ai_worksite", body = worksiteAiBody(id, map["job_title"].orEmpty(), map["job_tasks"].orEmpty(), map["barriers"].orEmpty(), sent))
                                val now = values.value.toMap()["recommendations"].orEmpty()
                                val merged = mergeAfterDraft(sent, now, r.str("text") ?: mergeDraft(sent, r.optString("draft")), r.optString("draft"))
                                values.value = (values.value.toMap() + ("recommendations" to merged)).toList()
                                notice = listOfNotNull(r.str("message") ?: WORKSITE_DRAFTED, if (flag(r, "truncated")) AI_INCOMPLETE else null, placeholderNote(merged)).joinToString(" ")
                            } catch (e: ApiException) { error = e.message } finally { aiBusy = false }
                        }
                    }
                    Announce(if (aiBusy) "Drafting…" else null, key = aiBusy)
                }
            }
            ErrorText(error)
            val unsaved = status != (base.str("status") ?: "") || keys.any { (k, _) -> (map[k] ?: "") != (base.str(k) ?: "") }
            SecondaryButton("Recommendation report") { nav.navigate("worksiteReport/$id") }
            if (unsaved) Text(WORKSITE_SAVE_FIRST)
            if (conflict) SecondaryButton("Reload, keeping my changes") { if (!reloading) { reloading = true; notice = null; state.reload() } }
            PrimaryButton("Save", busy = busy) {
                if (busy) return@PrimaryButton
                notice = null
                scope.launchSave({ busy = it }, { error = it }) {
                    val b = JSONObject().put("id", id).put("status", status)
                    map.forEach { (k, v) -> b.put(k, v.trim()) }
                    val orig = openedValues(b, base)
                    base.str("updated_at")?.let { b.put("loaded_at", it) }
                    b.put("orig", orig)
                    try { api.call("save_worksite", body = b) } catch (e: ApiException) {
                        // Someone else saved it since it was opened: the message names what; Reload is offered.
                        if (e.status == 409 && e.reply?.optBoolean("conflict") == true) conflict = true
                        throw e
                    }
                    Flash.pending = "Assessment saved."
                    nav.popBackStack()
                }
            }
        }
    }
}
