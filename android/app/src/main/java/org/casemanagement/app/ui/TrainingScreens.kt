package org.casemanagement.app.ui

import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.selection.selectable
import androidx.compose.foundation.selection.selectableGroup
import androidx.compose.material3.RadioButton
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import androidx.navigation.NavHostController
import kotlinx.coroutines.launch
import org.casemanagement.app.Api
import org.casemanagement.app.ApiException
import org.casemanagement.app.objects
import org.casemanagement.app.str
import org.json.JSONArray
import org.json.JSONObject
import androidx.compose.runtime.key
import androidx.compose.runtime.mutableIntStateOf
import org.casemanagement.app.dialogKey

/** My training (4.16.0): what to take, and taking it (a reading, then the questions). */
@Composable
fun TrainingListScreen(api: Api, nav: NavHostController) {
    val state = rememberLoaded(api.userId) { api.call("training").optJSONArray("training").objects() }
    Show(state, "your training") { list ->
        Page {
            val needed = list.count { it.optString("state") == "needed" }
            Text(if (list.isEmpty()) "There is no training for you to take." else if (needed > 0) "$needed of ${list.size} to take." else "Every training is passed and current.")
            list.forEach { m ->
                ListRow(listOf(m.optString("title"), if (m.optString("state") == "needed") "To take: " + m.optString("why").lowercase() else m.optString("why")),
                    action = "open it", onClick = { nav.navigate("trainingTake/${m.optInt("id")}") })
            }
        }
    }
}

@Composable
fun TrainingTakeScreen(api: Api, nav: NavHostController, id: Int) {
    val state = rememberLoaded(id) { api.call("training").optJSONArray("training").objects().firstOrNull { it.optInt("id") == id } ?: throw ApiException("That training is no longer asked for.") }
    var answers by rememberSaveable { mutableStateOf<List<Int>>(emptyList()) }
    var wrong by rememberSaveable { mutableStateOf<List<Int>>(emptyList()) }
    var notice by remember { mutableStateOf<String?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    var busy by remember { mutableStateOf(false) }
    val scope = rememberCoroutineScope()
    Show(state, "the training") { m ->
        val qs = m.optJSONArray("questions").objects()
        if (answers.size != qs.size) answers = List(qs.size) { -1 }
        Page {
            Heading(m.optString("title"), large = true)
            Text(m.optString("why") + ". Read it, then answer the ${qs.size} questions; ${m.optInt("pass_pct")}% passes.")
            Heading("Read")
            MarkdownBlocks(m.optString("body"))
            Heading("Questions")
            NoticeText(notice)
            qs.forEachIndexed { i, q ->
                Text("${i + 1}. " + q.optString("q") + if ((i + 1) in wrong) " (answered wrongly last time)" else "",
                    modifier = Modifier.padding(top = 12.dp).semantics { heading() })
                val opts = q.optJSONArray("options")
                androidx.compose.foundation.layout.Column(Modifier.selectableGroup()) {
                    for (o in 0 until (opts?.length() ?: 0)) {
                        Row(Modifier.fillMaxWidth().heightIn(min = 48.dp).selectable(selected = answers.getOrNull(i) == o, role = Role.RadioButton,
                            onClick = { answers = answers.toMutableList().also { it[i] = o } }), verticalAlignment = Alignment.CenterVertically) {
                            RadioButton(selected = answers.getOrNull(i) == o, onClick = null)
                            Text(opts!!.optString(o), modifier = Modifier.padding(start = 8.dp))
                        }
                    }
                }
            }
            ErrorText(error)
            PrimaryButton("Check my answers", busy = busy) {
                val missing = answers.indexOfFirst { it < 0 }
                if (missing >= 0) { error = "Answer every question (question ${missing + 1} has no answer)."; return@PrimaryButton }
                scope.launch {
                    busy = true; error = null
                    try {
                        val r = api.call("submit_training", body = JSONObject().put("id", id).put("version", m.optInt("version")).put("text_hash", m.optString("text_hash"))
                            .put("answers", JSONArray(answers)))
                        if (r.optBoolean("passed")) { Flash.pending = r.optString("message"); api.refreshMe(); nav.popBackStack() }
                        else { wrong = r.optJSONArray("wrong").let { a -> List(a?.length() ?: 0) { a!!.optInt(it) } }; notice = r.optString("message") }
                    } catch (e: ApiException) {
                        error = e.message
                        if (e.status == 409) { answers = emptyList(); wrong = emptyList(); state.reload() }
                    } finally { busy = false }
                }
            }
        }
    }
}

/** My credentials (4.16.0): your certifications and checks, with expiry dates. */
@Composable
fun CredentialsScreen(api: Api) {
    val state = rememberLoaded(api.userId) { api.call("credentials") }
    // 4.29.0: the credential being edited (its id; 0 for a new one, -1 for none), kept across
    // rotation; the dialog's fields are keyed by it.
    var editingId by rememberSaveable { mutableIntStateOf(-1) }
    var notice by remember { mutableStateOf<String?>(null) }
    Show(state, "your credentials") { r ->
        val list = r.optJSONArray("credentials").objects()
        val types = r.optJSONArray("types").let { a -> List(a?.length() ?: 0) { a!!.optString(it) } }
        Page {
            NoticeText(notice)
            Text("You are reminded 60 days before one expires.")
            PrimaryButton("Add a credential") { editingId = 0 }
            Heading("Credentials (${list.size})")
            if (list.isEmpty()) Text("None recorded yet.")
            list.forEach { c -> ListRow(listOf(c.optString("name"), c.optString("words")), action = "change or remove it", onClick = { editingId = c.optInt("id") }) }
        }
        val editing = if (editingId == 0) JSONObject() else list.firstOrNull { editingId > 0 && it.optInt("id") == editingId }
        editing?.let { c -> key(dialogKey("credential", editingId)) { CredentialDialog(api, c, types, onDone = { m -> editingId = -1; notice = m; state.reload() }, onCancel = { editingId = -1 }) } }
    }
}

@Composable
private fun CredentialDialog(api: Api, c: JSONObject, types: List<String>, onDone: (String) -> Unit, onCancel: () -> Unit) {
    val existing = c.optInt("id") > 0
    // 4.29.0: kept across rotation and a text-size change.
    var type by rememberSaveable { mutableStateOf(c.str("name")?.let { if (it in types) it else "Other" } ?: "") }
    var other by rememberSaveable { mutableStateOf(c.str("name")?.takeIf { it !in types } ?: "") }
    var number by rememberSaveable { mutableStateOf(c.str("number") ?: "") }
    var issuer by rememberSaveable { mutableStateOf(c.str("issuer") ?: "") }
    var issued by rememberSaveable { mutableStateOf(c.str("issued_on") ?: "") }
    var expires by rememberSaveable { mutableStateOf(c.str("expires_on") ?: "") }
    var removing by rememberSaveable { mutableStateOf(false) }
    // 4.29.0: a new credential is added once even when a reply was lost (see sendOnce).
    var idem by rememberSaveable { mutableStateOf("") }
    var error by remember { mutableStateOf<String?>(null) }
    var fieldErrors by remember { mutableStateOf<Map<String, String>>(emptyMap()) }
    var busy by remember { mutableStateOf(false) }
    val scope = rememberCoroutineScope()
    val day = Regex("^\\d{4}-\\d{2}-\\d{2}$")
    FormDialog(if (existing) c.optString("name") else "Add a credential", "Save", busy, type.isNotEmpty(), onSave = {
        if (busy) return@FormDialog
        val name = if (type == "Other") other.trim() else type
        fieldErrors = listOfNotNull(
            if (name.isEmpty()) "name" to "Name the credential." else null,
            if (issued.isNotBlank() && !day.matches(issued.trim())) "issued_on" to "Enter the date issued as year-month-day, or leave it empty." else null,
            if (expires.isNotBlank() && !day.matches(expires.trim())) "expires_on" to "Enter the expiry date as year-month-day, or leave it empty." else null,
        ).toMap()
        if (fieldErrors.isNotEmpty()) { error = fieldErrors.values.first(); return@FormDialog }
        scope.launchSave({ busy = it }, { error = it }) {
            val b = JSONObject().put("id", c.optInt("id")).put("name", name).put("number", number.trim()).put("issuer", issuer.trim())
                .put("issued_on", issued.trim()).put("expires_on", expires.trim())
            try {
                if (existing) api.call("save_credential", body = b) else api.callOnce("save_credential", b, idem, { idem = it }, "the credential")
            } catch (e: ApiException) {
                e.reply?.optJSONObject("errors")?.let { errs -> fieldErrors = errs.keys().asSequence().associateWith { errs.optString(it) } }
                throw e
            }
            onDone("Saved $name.")
        }
    }, onCancel = onCancel) {
        Choice("Credential", type, listOf("" to "Choose one") + types.map { it to it }, error = if (type != "Other") fieldErrors["name"] else null) { type = it }
        if (type == "Other") Field("Its name", other, { other = it }, error = fieldErrors["name"])
        Field("Number", number, { number = it }, hint = "optional", error = fieldErrors["number"])
        Field("Issued by", issuer, { issuer = it }, hint = "optional", error = fieldErrors["issuer"])
        Field("Date issued", issued, { issued = it }, hint = "year-month-day, optional", keyboard = KeyboardType.Text, error = fieldErrors["issued_on"])
        Field("Expires on", expires, { expires = it }, hint = "year-month-day; empty if it does not expire", error = fieldErrors["expires_on"])
        ErrorText(error)
        if (existing) SecondaryButton("Remove this credential") { removing = true }
    }
    if (removing) Confirm("Remove ${c.optString("name")}?", "", "Remove", {
        scope.launchSave({ busy = it }, { error = it }) { api.call("delete_credential", body = JSONObject().put("id", c.optInt("id"))); onDone("Removed ${c.optString("name")}.") }
    }, { removing = false })
}
