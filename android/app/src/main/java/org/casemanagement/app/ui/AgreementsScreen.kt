package org.casemanagement.app.ui

import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.navigation.NavHostController
import org.casemanagement.app.Api
import org.casemanagement.app.ApiException
import org.casemanagement.app.objects
import org.casemanagement.app.str
import org.json.JSONObject

/** My agreements (4.15.0): the staff agreements to read and sign, and copies of those signed. */
@Composable
fun AgreementsScreen(api: Api, nav: NavHostController) {
    val state = rememberLoaded(api.userId) { api.call("agreements").optJSONArray("agreements").objects() }
    Show(state, "your agreements") { list ->
        Page {
            val needed = list.count { it.optString("state") == "needed" }
            Text(if (list.isEmpty()) "There are no agreements for you to sign." else if (needed > 0) "$needed of ${list.size} need your signature." else "Every agreement is signed and current.")
            list.forEach { a ->
                ListRow(listOf(a.optString("title"), if (a.optString("state") == "needed") "Needs your signature: " + a.optString("why").lowercase() else a.optString("why")),
                    action = if (a.optString("state") == "signed") "read it" else "read and sign it", onClick = { nav.navigate("agreement/${a.optInt("id")}") })
            }
        }
    }
}

@Composable
fun AgreementScreen(api: Api, nav: NavHostController, id: Int) {
    val state = rememberLoaded(id) { api.call("agreements").optJSONArray("agreements").objects().firstOrNull { it.optInt("id") == id } ?: throw ApiException("That agreement is no longer asked for.") }
    var agree by rememberSaveable { mutableStateOf(false) }
    var name by rememberSaveable { mutableStateOf("") }
    var error by remember { mutableStateOf<String?>(null) }
    var agreeError by remember { mutableStateOf<String?>(null) }
    var busy by remember { mutableStateOf(false) }
    val scope = rememberCoroutineScope()
    Show(state, "the agreement") { a ->
        Page {
            Heading(a.optString("title"), large = true)
            Text(a.optString("why") + ".")
            MarkdownBlocks(a.optString("body"))
            if (a.optString("state") == "signed") {
                a.str("signature_id")?.let { sid -> SecondaryButton("Read your signed copy") { nav.navigate("agreementCopy/$sid") } }
            } else {
                Heading("Sign")
                Tick("I have read this agreement and agree to it", agree, error = agreeError) { agree = it; if (it) agreeError = null }
                Field("Type your full name to sign", name, { name = it }, hint = "as it is on your account: ${api.userName}")
                ErrorText(error)
                PrimaryButton("Sign the agreement", busy = busy, enabled = name.isNotBlank()) {
                    agreeError = if (!agree) "Tick that you have read the agreement and agree to it." else null
                    if (!agree) { error = agreeError; return@PrimaryButton }
                    scope.launchSave({ busy = it }, { error = it }) {
                        try {
                            // The fingerprint of the text shown here: a change since it loaded is refused.
                            val r = api.call("sign_agreement", body = JSONObject().put("id", id).put("version", a.optInt("version")).put("agree", true)
                                .put("signed_name", name.trim()).put("text_hash", a.optString("text_hash")))
                            Flash.pending = r.optString("message").ifEmpty { "Signed." }
                            nav.popBackStack()
                        } catch (e: ApiException) {
                            // Changed while open: show the new text, so it is read before signing.
                            if (e.status == 409) { agree = false; state.reload() }
                            throw e
                        }
                    }
                }
            }
        }
    }
}

@Composable
fun AgreementCopyScreen(api: Api, id: Int) {
    val state = rememberLoaded(id) { api.call("agreement_copy", mapOf("id" to id.toString())).optJSONObject("copy") ?: JSONObject() }
    Show(state, "your signed copy") { c ->
        Page {
            Heading(c.optString("title") + ": your signed copy", large = true)
            Text("Signed by ${c.optString("signed_name")} on ${whenText(c.str("signed_at"))}, version ${c.optInt("version")}. This is the text exactly as it read when you signed.")
            MarkdownBlocks(c.optString("body"))
        }
    }
}
