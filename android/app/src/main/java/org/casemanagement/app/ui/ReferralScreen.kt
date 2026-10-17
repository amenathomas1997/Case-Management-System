package org.casemanagement.app.ui

import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.navigation.NavHostController
import kotlinx.coroutines.launch
import org.casemanagement.app.Api
import org.casemanagement.app.ApiException
import org.casemanagement.app.objects
import org.casemanagement.app.str
import org.json.JSONObject

/** One referral in full, with what can be done with it, as on the website and the iPhone app. */
@Composable
fun ReferralScreen(api: Api, nav: NavHostController, id: Int) {
    val state = rememberLoaded(id) {
        val r = api.call("referral", mapOf("id" to id.toString()))
        val staff = try { api.call("staff").optJSONArray("staff").objects().filter { it.optBoolean("can_referrals", true) } } catch (e: ApiException) { emptyList() }
        r to staff
    }
    var notice by remember { mutableStateOf<String?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    var ask by remember { mutableStateOf<Triple<String, String, suspend () -> String>?>(null) }
    var closeReason by remember { mutableStateOf("") }
    var closeNote by remember { mutableStateOf("") }
    var confirmCounselor by remember { mutableStateOf(false) }
    val scope = rememberCoroutineScope()

    fun run(block: suspend () -> String) {
        error = null
        scope.launch { try { notice = block(); state.reload() } catch (e: ApiException) { error = e.message } }
    }

    Show(state, "the referral") { (r, staff) ->
        val ref = r.optJSONObject("referral") ?: return@Show
        val status = ref.optString("status")
        val open = status == "New" || status == "Reviewed"
        Page {
            Heading(ref.optString("client_name"), large = true)
            NoticeText(notice)
            ErrorText(error)
            ListRow(listOf("Status: $status"))
            ListRow(listOf("Received " + whenText(ref.str("created_at"))))
            ref.str("reference")?.let { ListRow(listOf("Reference: $it")) }
            ref.str("referral_source")?.takeIf { it != "counselor" }?.let { ListRow(listOf("Referred by: " + if (it == "self") "the person themself" else it)) }
            ref.str("counselor_name")?.let { ListRow(listOf("From: $it", ref.str("counselor_agency") ?: "", ref.str("counselor_email") ?: "", ref.str("counselor_phone") ?: "")) }
            listOfNotNull(ref.str("client_phone")?.let { "Phone: $it" }, ref.str("client_email")?.let { "Email: $it" }).forEach { ListRow(listOf(it)) }
            ListRow(listOf(ref.str("assigned_name")?.let { "Assigned to $it" } ?: "Unassigned"))
            r.optJSONArray("possible_duplicates").let { a -> List(a?.length() ?: 0) { a!!.optString(it) } }.forEach { ListRow(listOf("Possible duplicate: $it")) }
            Heading("The referral")
            Text(ref.optString("body"))

            Heading("What you can do")
            if (status == "New") SecondaryButton("Mark as reviewed") { run { api.call("review_referral", body = JSONObject().put("id", id)); "Marked as reviewed." } }
            if (open && staff.isNotEmpty()) {
                Choice("Assigned to", (if (ref.isNull("assigned_to")) 0 else ref.optInt("assigned_to")).toString(),
                    listOf("0" to "Unassigned") + staff.map { it.optInt("id").toString() to it.optString("name") }) { v ->
                    val who = staff.firstOrNull { it.optInt("id").toString() == v }?.optString("name")
                    ask = Triple(if (who != null) "Assign this referral to $who?" else "Unassign this referral?",
                        if (api.scoped && v.toInt() != api.userId) "It leaves your list, and you won't be able to open it again." else "",
                    ) { api.call("assign_referral", body = JSONObject().put("id", id).put("user_id", v.toInt())); if (who != null) "Assigned to $who." else "Unassigned." }
                }
            }
            if (open && api.can("clients")) {
                if (ref.optString("referral_source") == "counselor" && !ref.str("counselor_email").isNullOrEmpty()) {
                    Tick("I confirmed the counselor's email address", confirmCounselor) { confirmCounselor = it }
                }
                SecondaryButton("Make this person a client") {
                    ask = Triple("Make ${ref.optString("client_name")} a client?", "A new client record is made from this referral.") {
                        val b = JSONObject().put("id", id)
                        if (confirmCounselor) b.put("confirm_counselor_email", 1)
                        val res = api.call("convert_referral", body = b)
                        nav.navigate("client/${res.optInt("client_id")}")
                        "Client record made."
                    }
                }
                r.optJSONArray("duplicate_clients").objects().forEach { c ->
                    SecondaryButton("Add to the existing client ${c.optString("name")} (${c.optString("status")})") {
                        ask = Triple("Add this referral to ${c.optString("name")}?", if (c.optString("status").startsWith("Closed")) "A closed record is reopened to Pending Assessment." else "") {
                            val res = api.call("attach_referral", body = JSONObject().put("id", id).put("client_id", c.optInt("id")))
                            "Added to ${c.optString("name")}" + if (res.optBoolean("reopened")) ", whose record was reopened." else "."
                        }
                    }
                }
            }
            if (open) {
                val reasons = r.optJSONArray("close_reasons").let { a -> List(a?.length() ?: 0) { a!!.optString(it) } }
                Choice("Why it is closed", closeReason, listOf("" to "Choose a reason") + reasons.map { it to it }) { closeReason = it }
                Field("Note", closeNote, { closeNote = it }, hint = if (closeReason == "Other") "required for Other" else "optional")
                SecondaryButton("Close the referral") {
                    if (closeReason.isEmpty()) { error = "Choose why the referral is closed."; return@SecondaryButton }
                    run {
                        val res = api.call("close_referral", body = JSONObject().put("id", id).put("reason", closeReason).put("note", closeNote.trim()))
                        "Referral closed." + if (res.optBoolean("emailed")) " The referrer was emailed." else ""
                    }
                }
            }
            if (status == "Closed") SecondaryButton("Reopen the referral") { run { api.call("reopen_referral", body = JSONObject().put("id", id)); "Referral reopened." } }
        }
    }
    ask?.let { (title, text, block) ->
        Confirm(title, text, "Yes", { run(block) }, { ask = null })
    }
}
