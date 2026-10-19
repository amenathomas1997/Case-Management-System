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

/*
 * AT services (trials, setups, funding, repairs, licenses, reuse, resources and the rest): the
 * server describes each kind's fields, so one list, one record page and one form serve them all,
 * as in the iPhone app.
 */

private val CHILD_KIND = mapOf("trial" to "trial_option", "setup" to "outcome", "resource" to "learning", "license" to "license_seat")

@Composable
fun AtServicesScreen(api: Api, nav: NavHostController) {
    val state = rememberLoaded(api.userId) { api.call("at_catalog").optJSONArray("modules").objects() }
    Show(state, "AT services") { modules ->
        Page {
            Heading("AT services")
            modules.filter { it.optString("id") !in CHILD_KIND.values }.forEach { m -> ListRow(listOf(m.optString("label"), m.optString("description")), action = "open the list", onClick = { nav.navigate("at/${m.optString("id")}") }) }
        }
    }
}

@Composable
fun AtListScreen(api: Api, nav: NavHostController, kind: String) {
    val state = rememberLoaded(kind) { api.call("at_list", mapOf("kind" to kind)) }
    Show(state, "records") { r ->
        val records = r.optJSONArray("records").objects()
        Page {
            r.str("description")?.let { Text(it) }
            if (kind != "trial_option") PrimaryButton("Add one") { nav.navigate("atEdit/$kind/0/0") }
            Heading("Records (${records.size})")
            if (records.isEmpty()) Text("None yet.")
            records.forEach { rec -> ListRow(listOf(rec.optString("title"), rec.optString("status") + ", updated " + whenText(rec.str("updated_at"))), action = "open it", onClick = { nav.navigate("atRecord/${rec.optInt("id")}") }) }
        }
    }
}

@Composable
fun AtRecordScreen(api: Api, nav: NavHostController, id: Int) {
    val state = rememberLoaded(id) { api.call("at_detail", mapOf("id" to id.toString())) }
    var notice by remember { mutableStateOf<String?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    var issuing by rememberSaveable { mutableStateOf(false) }
    var returning by rememberSaveable { mutableStateOf(false) }
    val scope = rememberCoroutineScope()
    Show(state, "the record") { r ->
        val rec = r.optJSONObject("record") ?: JSONObject()
        val kind = rec.optString("kind")
        val fields = r.optJSONArray("fields").objects()
        val values = rec.optJSONObject("values") ?: JSONObject()
        Page {
            Heading(rec.optString("title"), large = true)
            NoticeText(notice)
            ErrorText(error)
            ListRow(listOf("Status: " + rec.optString("status")))
            r.optJSONObject("summary")?.let { s -> s.keys().forEach { k -> ListRow(listOf("$k: " + s.optString(k))) } }
            fields.forEach { f ->
                val v = values.optString(f.optString("key"))
                if (v.isNotEmpty()) {
                    val shown = f.optJSONArray("options").objects().firstOrNull { it.optString("value") == v }?.optString("label") ?: v
                    ListRow(listOf(f.optString("label") + ": " + shown))
                }
            }
            if (!(kind == "reuse" && rec.optString("status") == "Redeployed")) SecondaryButton("Edit") { nav.navigate("atEdit/$kind/$id/0") }
            if ((kind == "repair" || kind == "reuse") && api.can("equipment")) {
                // 4.29.0: each only when it can work, as on the website: return for a linked loan not
                // yet returned, lend when nothing is out.
                val out = values.optString("loan_id").isNotEmpty() && r.optJSONObject("summary")?.optString("Returned") == "Not returned"
                if (!out) SecondaryButton("Lend a loaner or the item") { issuing = true }
                if (out) SecondaryButton("Record the return") { returning = true }
            }
            if (kind == "trial") SecondaryButton("Share the trial report") {
                scope.launch {
                    try {
                        val rep = api.call("at_report", body = JSONObject().put("id", id))
                        copyText(nav.context, rep.optString("title") + "\n\n" + rep.optString("text")); notice = "Trial report copied. Paste it into an email or document."
                    } catch (e: ApiException) { error = e.message }
                }
            }
            CHILD_KIND[kind]?.let { child ->
                val children = r.optJSONArray("children").objects()
                Heading(when (child) { "trial_option" -> "Devices tried"; "outcome" -> "Outcomes"; "learning" -> "Assigned to clients"; else -> "Seats" } + " (${children.size})")
                children.forEach { c -> ListRow(listOf(c.optString("title"), c.optString("status")), action = "open it", onClick = { nav.navigate("atRecord/${c.optInt("id")}") }) }
                if (!(kind == "resource" && rec.optString("status") == "Draft")) SecondaryButton("Add one") { nav.navigate("atEdit/$child/0/$id") }
                else Text("Publish this resource before assigning it to a client.")
            }
        }
        if (issuing) AtIssueDialog(api, id, onDone = { m -> issuing = false; notice = m; state.reload() }, onCancel = { issuing = false })
    }
    if (returning) Confirm("Confirm the equipment has been returned", "", "Record return", {
        scope.launch { try { api.call("at_return", body = JSONObject().put("id", id)); notice = "Return recorded."; state.reload() } catch (e: ApiException) { error = e.message } }
    }, { returning = false })
}

@Composable
private fun AtIssueDialog(api: Api, id: Int, onDone: (String) -> Unit, onCancel: () -> Unit) {
    val choices = rememberLoaded(id) { api.call("at_issue_choices", mapOf("id" to id.toString())) }
    var client by rememberSaveable { mutableStateOf("") }
    var item by rememberSaveable { mutableStateOf("") }
    var due by rememberSaveable { mutableStateOf("") }
    var idem by rememberSaveable { mutableStateOf("") }
    var error by remember { mutableStateOf<String?>(null) }
    var busy by remember { mutableStateOf(false) }
    val scope = rememberCoroutineScope()
    val c = choices.data
    FormDialog("Lend equipment", "Lend", busy, client.isNotEmpty() && item.isNotEmpty(), onSave = {
        scope.launchSave({ busy = it }, { error = it }) {
            // 4.28.0: a retry after a lost reply lends it once (see sendOnce).
            api.callOnce("at_issue", JSONObject().put("id", id).put("client_id", client.toInt()).put("equipment_id", item.toInt()).put("due_date", due.trim()), idem, { idem = it }, "the loan")
            onDone("Equipment lent.")
        }
    }, onCancel = onCancel) {
        ErrorText(choices.error)
        if (c != null) {
            Choice("Client", client, listOf("" to "Choose a client") + c.optJSONArray("clients").objects().map { it.optString("value") to it.optString("label") }) { client = it }
            Choice("Equipment", item, listOf("" to "Choose the item") + c.optJSONArray("equipment").objects().map { it.optString("value") to it.optString("label") }) { item = it }
        }
        Field("Due back", due, { due = it }, hint = "year-month-day, optional")
        ErrorText(error)
    }
}

/** A record's form, built from the fields the server describes for its kind. */
@Composable
fun AtEditScreen(api: Api, nav: NavHostController, kind: String, id: Int, parentId: Int) {
    val state = rememberLoaded(kind, id) {
        if (id > 0) api.call("at_detail", mapOf("id" to id.toString())) else api.call("at_list", mapOf("kind" to kind)).put("record", JSONObject())
    }
    Show(state, "the form") { r ->
        val rec = r.optJSONObject("record") ?: JSONObject()
        val fields = r.optJSONArray("fields").objects()
        val statuses = r.optJSONArray("statuses").let { a -> List(a?.length() ?: 0) { a!!.optString(it) } }
        val stored = rec.optJSONObject("values") ?: JSONObject()
        var title by rememberSaveable { mutableStateOf(rec.str("title") ?: "") }
        var status by rememberSaveable { mutableStateOf(rec.str("status") ?: statuses.firstOrNull() ?: "") }
        val values = rememberSaveable(kind, id) { mutableStateOf(fields.map { it.optString("key") to (stored.str(it.optString("key")) ?: (if (it.optString("key") == "parent_id" && parentId > 0) parentId.toString() else "")) }) }
        var draftFor by remember { mutableStateOf<String?>(null) }
        var draft by remember { mutableStateOf("") }
        var before by remember { mutableStateOf<Pair<String, String>?>(null) }
        var error by remember { mutableStateOf<String?>(null) }
        // 4.29.0: the server's refusal shown on the field it names (its message starts with the label).
        var fieldErrors by remember { mutableStateOf<Map<String, String>>(emptyMap()) }
        // 4.29.0: a new record is added once even when a reply was lost (see sendOnce).
        var newKey by rememberSaveable { mutableStateOf("") }
        var busy by remember { mutableStateOf(false) }
        val scope = rememberCoroutineScope()
        val map = values.value.toMap()
        // Fixed once the record exists (which client, parent or item it belongs to).
        val locked = if (id > 0) setOf("client_id", "parent_id") + (if (kind == "repair" || kind == "reuse") setOf("equipment_id") else emptySet()) else emptySet()
        Page {
            Heading(if (id > 0) "Edit" else "Add", large = true)
            Field("Title", title, { title = it }, error = fieldErrors["title"])
            if (statuses.isNotEmpty()) Choice("Status", status, statuses.map { it to it }, error = fieldErrors["status"]) { status = it }
            fields.forEach { f ->
                val key = f.optString("key"); val label = f.optString("label") + if (f.optBoolean("required")) "" else " (optional)"
                val v = map[key] ?: ""
                val set = { nv: String -> values.value = (map + (key to nv)).toList() }
                val options = f.optJSONArray("options").objects()
                when {
                    key in locked -> ListRow(listOf(f.optString("label") + ": " + (options.firstOrNull { it.optString("value") == v }?.optString("label") ?: v)))
                    f.optString("type") in setOf("select", "reference") || options.isNotEmpty() -> Choice(label, v, listOf("" to "Not chosen") + options.map { it.optString("value") to it.optString("label") }, error = fieldErrors[key]) { set(it) }
                    f.optString("type") == "score" -> Choice(label, v, listOf("" to "Not chosen") + (1..5).map { it.toString() to "$it of 5" }, error = fieldErrors[key]) { set(it) }
                    else -> {
                        Field(label, v, set, hint = when (f.optString("type")) { "date" -> "year-month-day"; "money" -> "dollars"; "url" -> "https://…"; else -> null },
                            singleLine = f.optString("type") != "textarea", error = fieldErrors[key], keyboard = when (f.optString("type")) { "money" -> KeyboardType.Decimal; "integer" -> KeyboardType.Number; "url" -> KeyboardType.Uri; else -> KeyboardType.Text })
                        if (api.aiHas("draft") && f.optString("type") in setOf("text", "textarea")) SecondaryButton("Draft ${f.optString("label").lowercase()} with AI") { draftFor = key; draft = "" }
                    }
                }
                if (draftFor == key) {
                    var instruction by remember(key) { mutableStateOf("") }
                    Field("What should the AI do", instruction, { instruction = it }, hint = "draft, simplify, translate or revise")
                    SecondaryButton(if (busy) "Drafting… up to a minute" else "Ask the AI") {
                        scope.launch {
                            busy = true; error = null
                            try {
                                val vals = JSONObject(); map.forEach { (k, x) -> vals.put(k, x) }; vals.put("title", title)
                                val res = api.call("at_ai_draft", body = JSONObject().put("kind", kind).put("field", key).put("values", vals).put("instruction", instruction).put("id", id))
                                draft = res.optString("text")
                            } catch (e: ApiException) { error = e.message } finally { busy = false }
                        }
                    }
                    if (draft.isNotEmpty()) {
                        Field("AI draft (review before using)", draft, { draft = it }, singleLine = false, minLines = 4)
                        SecondaryButton("Use this draft for ${f.optString("label").lowercase()}") { before = key to v; set(draft); draftFor = null; draft = "" }
                    }
                }
                before?.takeIf { it.first == key }?.let { (_, old) -> SecondaryButton("Restore my previous ${f.optString("label").lowercase()}") { set(old); before = null } }
            }
            ErrorText(error)
            PrimaryButton("Save", busy = busy, enabled = title.isNotBlank()) {
                if (busy) return@PrimaryButton
                scope.launchSave({ busy = it }, { error = it }) {
                    fieldErrors = emptyMap()
                    val vals = JSONObject()
                    map.forEach { (k, x) -> vals.put(k, if (fields.firstOrNull { it.optString("key") == k }?.optString("type") == "money") x.replace(',', '.').trim() else x.trim()) }
                    val b = JSONObject().put("kind", kind).put("id", id).put("version", rec.optInt("version")).put("title", title.trim()).put("status", status).put("values", vals)
                    try {
                        if (id == 0) api.callOnce("at_save", b, newKey, { newKey = it }, "the record") else api.call("at_save", body = b)
                    } catch (e: ApiException) {
                        val m = e.message ?: ""
                        // The field whose label the message starts with (the longest, so "Due date" wins over "Due").
                        val labels = fields.map { it.optString("key") to it.optString("label") } + listOf("title" to "Title", "status" to "Status")
                        labels.filter { (_, l) -> l.isNotEmpty() && m.startsWith(l) }.maxByOrNull { it.second.length }?.let { (k, _) -> fieldErrors = mapOf(k to m) }
                        throw e
                    }
                    Flash.pending = "Saved."
                    nav.popBackStack()
                }
            }
        }
    }
}
