package org.casemanagement.app.ui

import androidx.compose.material3.Text
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
import org.casemanagement.app.objects
import org.casemanagement.app.str
import org.json.JSONObject

/* ---------------- Device procurement ---------------- */

private val CLOSED_REQUEST = setOf("Trained", "Denied", "Cancelled")

@Composable
fun ProcurementScreen(api: Api, nav: NavHostController) {
    val state = rememberLoaded(api.userId) { api.call("device_requests").optJSONArray("requests").objects() }
    var adding by remember { mutableStateOf(false) }
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

@Composable
private fun AddDeviceRequestDialog(api: Api, onDone: (Int) -> Unit, onCancel: () -> Unit) {
    val clients = rememberLoaded(api.userId) { clientChoices(api) }
    val cats = api.formList("device_request_categories", listOf("Other"))
    var client by remember { mutableStateOf("") }
    var device by remember { mutableStateOf("") }
    var cat by remember { mutableStateOf(cats.first()) }
    var why by remember { mutableStateOf("") }
    var error by remember { mutableStateOf<String?>(null) }
    var busy by remember { mutableStateOf(false) }
    val scope = rememberCoroutineScope()
    FormDialog("Request a device", "Add", busy, client.isNotEmpty() && device.isNotBlank(), onSave = {
        scope.launchSave({ busy = it }, { error = it }) {
            onDone(api.call("add_device_request", body = JSONObject().put("client_id", client.toInt()).put("device_name", device.trim()).put("category", cat).put("justification", why.trim())).optInt("id"))
        }
    }, onCancel = onCancel) {
        ErrorText(clients.error)
        Choice("Client", client, listOf("" to "Choose a client") + (clients.data ?: emptyList()).map { it.optInt("id").toString() to it.optString("name") }) { client = it }
        Field("Device", device, { device = it })
        Choice("Category", cat, cats.map { it to it }) { cat = it }
        Field("Why it is needed", why, { why = it }, hint = "optional", singleLine = false)
        ErrorText(error)
    }
}

/** One device request through its steps: quotes, authorization, order, delivery, training. */
@Composable
fun DeviceRequestScreen(api: Api, id: Int) {
    val state = rememberLoaded(id) { api.call("device_request", mapOf("id" to id.toString())) }
    var notice by remember { mutableStateOf<String?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    var step by remember { mutableStateOf("") }
    var ask by remember { mutableStateOf<Triple<String, String, JSONObject>?>(null) }
    val scope = rememberCoroutineScope()
    fun update(body: JSONObject, done: String) {
        error = null
        scope.launch {
            try { api.call("device_request_update", body = body.put("id", id)); state.reload(); notice = done } catch (e: ApiException) { error = e.message }
        }
    }
    Show(state, "the request") { r ->
        val q = r.optJSONObject("request") ?: JSONObject()
        val status = q.optString("status")
        val closed = status in CLOSED_REQUEST
        Page {
            Heading(q.optString("device_name") + " for " + q.optString("client_name"), large = true)
            NoticeText(notice?.let { "$it Status: $status." })
            ErrorText(error)
            ListRow(listOf("Status: $status"))
            listOfNotNull(q.str("category")?.let { "Category: $it" }, q.str("justification")?.let { "Why: $it" },
                q.str("funding_source")?.let { "Funding: $it" + (q.str("auth_number")?.let { n -> ", authorization $n" } ?: "") },
                if (q.isNull("authorized_amount")) null else "Authorized " + money(q.optDouble("authorized_amount", 0.0)) + (q.str("authorized_at")?.let { " on " + dayText(it) } ?: ""),
                q.str("po_number")?.let { "Order number $it" + (q.str("ordered_at")?.let { d -> ", ordered " + dayText(d) } ?: "") },
                q.str("delivered_at")?.let { "Delivered " + dayText(it) + (q.str("serial")?.let { s -> ", serial $s" } ?: "") },
                q.str("trained_at")?.let { "Training completed " + dayText(it) }).forEach { ListRow(listOf(it)) }
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
                SecondaryButton("Mark denied") { ask = Triple("Mark this request denied?", "It is closed until someone reopens it.", JSONObject().put("op", "status").put("to", "Denied")) }
                SecondaryButton("Cancel the request") { ask = Triple("Cancel this request?", "It is closed until someone reopens it.", JSONObject().put("op", "status").put("to", "Cancelled")) }
            } else if (status != "Trained") SecondaryButton("Reopen the request") { update(JSONObject().put("op", "status").put("to", "Requested"), "Request reopened.") }
        }
        if (step.isNotEmpty()) StepDialog(step, q, onSave = { b, done -> step = ""; update(b, done) }, onCancel = { step = "" })
    }
    ask?.let { (t, m, b) -> Confirm(t, m, "Yes", { update(b, "Done.") }, { ask = null }) }
}

@Composable
private fun StepDialog(step: String, q: JSONObject, onSave: (JSONObject, String) -> Unit, onCancel: () -> Unit) {
    val f = remember(step) {
        when (step) {
            "add_quote" -> listOf("vendor" to "Vendor", "amount" to "Amount in dollars", "quote_ref" to "Quote reference, optional", "notes" to "Notes, optional")
            "authorize" -> listOf("funding_source" to "Funding source", "auth_number" to "Authorization number", "authorized_amount" to "Authorized amount in dollars", "authorized_at" to "Authorized on, year-month-day")
            "order" -> listOf("po_number" to "Order number", "ordered_at" to "Ordered on, year-month-day")
            else -> listOf("serial" to "Serial number", "delivered_at" to "Delivered on, year-month-day", "warranty_until" to "Warranty until, year-month-day, optional")
        }
    }
    val values = remember(step) { mutableStateOf(f.associate { (k, _) -> k to (q.str(k) ?: "") }) }
    var error by remember { mutableStateOf<String?>(null) }
    val title = mapOf("add_quote" to "Add a quote", "authorize" to "Authorization", "order" to "Order", "deliver" to "Delivery")[step] ?: ""
    FormDialog(title, "Save", false, onSave = {
        val b = JSONObject().put("op", step)
        for ((k, v) in values.value) {
            if (k.endsWith("amount")) {
                if (v.isBlank() && k == "amount") { error = "Enter the amount in dollars."; return@FormDialog }
                if (v.isNotBlank()) { val m = moneyText(v) ?: run { error = "Enter amounts in dollars, such as 1299.00."; return@FormDialog }; b.put(k, m) } else b.put(k, "")
            } else b.put(k, v.trim())
        }
        if (step == "add_quote" && values.value["vendor"].isNullOrBlank()) { error = "Enter the vendor."; return@FormDialog }
        onSave(b, if (step == "add_quote") "Quote added." else "$title saved.")
    }, onCancel = onCancel) {
        f.forEach { (k, label) -> Field(label, values.value[k] ?: "", { v -> values.value = values.value + (k to v) }, keyboard = if (k.endsWith("amount")) KeyboardType.Decimal else KeyboardType.Text) }
        ErrorText(error)
    }
}

/* ---------------- Worksite accommodations ---------------- */

private val WORKSITE_STATUSES = listOf("Scheduled", "Assessed", "Recommendations Sent", "Implemented", "Follow-up", "Closed")

@Composable
fun AccommodationsScreen(api: Api, nav: NavHostController) {
    var client by rememberSaveable { mutableStateOf("") }
    val clients = rememberLoaded(api.userId) { clientChoices(api, includeClosed = true) }
    val state = rememberLoaded(client) { api.call("worksite_assessments", if (client.isEmpty()) emptyMap() else mapOf("client_id" to client)) }
    var adding by remember { mutableStateOf(false) }
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
        var c by remember { mutableStateOf("") }
        var employer by remember { mutableStateOf("") }
        var job by remember { mutableStateOf("") }
        var date by remember { mutableStateOf("") }
        var error by remember { mutableStateOf<String?>(null) }
        var busy by remember { mutableStateOf(false) }
        val scope = rememberCoroutineScope()
        FormDialog("Add a worksite assessment", "Add", busy, c.isNotEmpty(), onSave = {
            scope.launchSave({ busy = it }, { error = it }) {
                val id = api.call("add_worksite", body = JSONObject().put("client_id", c.toInt()).put("employer", employer.trim()).put("job_title", job.trim()).put("assessment_date", date.trim())).optInt("id")
                adding = false; nav.navigate("worksite/$id")
            }
        }, onCancel = { adding = false }) {
            Choice("Client", c, listOf("" to "Choose a client") + (clients.data ?: emptyList()).filter { !it.optString("status").startsWith("Closed") }.map { it.optInt("id").toString() to it.optString("name") }) { c = it }
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
        val values = rememberSaveable(id) { mutableStateOf(keys.associate { (k, _) -> k to (a.str(k) ?: "") }.toList()) }
        var status by rememberSaveable { mutableStateOf(a.optString("status")) }
        var error by remember { mutableStateOf<String?>(null) }
        var busy by remember { mutableStateOf(false) }
        val scope = rememberCoroutineScope()
        val map = values.value.toMap()
        Page {
            Heading(a.optString("client_name"), large = true)
            Choice("Status", status, WORKSITE_STATUSES.map { it to it }) { status = it }
            keys.forEach { (k, label) ->
                Field(label, map[k] ?: "", { v -> values.value = (map + (k to v)).toList() }, singleLine = k !in setOf("job_tasks", "barriers", "recommendations", "outcome"))
            }
            ErrorText(error)
            PrimaryButton("Save", busy = busy) {
                scope.launchSave({ busy = it }, { error = it }) {
                    val b = JSONObject().put("id", id).put("status", status)
                    map.forEach { (k, v) -> b.put(k, v.trim()) }
                    api.call("save_worksite", body = b)
                    Flash.pending = "Assessment saved."
                    nav.popBackStack()
                }
            }
        }
    }
}
