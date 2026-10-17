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
    var editing by remember { mutableStateOf<JSONObject?>(null) }
    var notice by remember { mutableStateOf<String?>(null) }
    Show(state, "your credentials") { r ->
        val list = r.optJSONArray("credentials").objects()
        val types = r.optJSONArray("types").let { a -> List(a?.length() ?: 0) { a!!.optString(it) } }
        Page {
            NoticeText(notice)
            Text("You are reminded 60 days before one expires.")
            PrimaryButton("Add a credential") { editing = JSONObject() }
            Heading("Credentials (${list.size})")
            if (list.isEmpty()) Text("None recorded yet.")
            list.forEach { c -> ListRow(listOf(c.optString("name"), c.optString("words")), action = "change or remove it", onClick = { editing = c }) }
        }
        editing?.let { c -> CredentialDialog(api, c, types, onDone = { m -> editing = null; notice = m; state.reload() }, onCancel = { editing = null }) }
    }
}

@Composable
private fun CredentialDialog(api: Api, c: JSONObject, types: List<String>, onDone: (String) -> Unit, onCancel: () -> Unit) {
    val existing = c.optInt("id") > 0
    var type by remember { mutableStateOf(c.str("name")?.let { if (it in types) it else "Other" } ?: "") }
    var other by remember { mutableStateOf(c.str("name")?.takeIf { it !in types } ?: "") }
    var number by remember { mutableStateOf(c.str("number") ?: "") }
    var issuer by remember { mutableStateOf(c.str("issuer") ?: "") }
    var issued by remember { mutableStateOf(c.str("issued_on") ?: "") }
    var expires by remember { mutableStateOf(c.str("expires_on") ?: "") }
    var removing by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    var busy by remember { mutableStateOf(false) }
    val scope = rememberCoroutineScope()
    FormDialog(if (existing) c.optString("name") else "Add a credential", "Save", busy, type.isNotEmpty(), onSave = {
        val name = if (type == "Other") other.trim() else type
        if (name.isEmpty()) { error = "Name the credential."; return@FormDialog }
        scope.launchSave({ busy = it }, { error = it }) {
            api.call("save_credential", body = JSONObject().put("id", c.optInt("id")).put("name", name).put("number", number.trim()).put("issuer", issuer.trim())
                .put("issued_on", issued.trim()).put("expires_on", expires.trim()))
            onDone("Saved $name.")
        }
    }, onCancel = onCancel) {
        Choice("Credential", type, listOf("" to "Choose one") + types.map { it to it }) { type = it }
        if (type == "Other") Field("Its name", other, { other = it })
        Field("Number", number, { number = it }, hint = "optional")
        Field("Issued by", issuer, { issuer = it }, hint = "optional")
        Field("Date issued", issued, { issued = it }, hint = "year-month-day, optional", keyboard = KeyboardType.Text)
        Field("Expires on", expires, { expires = it }, hint = "year-month-day; empty if it does not expire")
        ErrorText(error)
        if (existing) SecondaryButton("Remove this credential") { removing = true }
    }
    if (removing) Confirm("Remove ${c.optString("name")}?", "", "Remove", {
        scope.launchSave({ busy = it }, { error = it }) { api.call("delete_credential", body = JSONObject().put("id", c.optInt("id"))); onDone("Removed ${c.optString("name")}.") }
    }, { removing = false })
}
