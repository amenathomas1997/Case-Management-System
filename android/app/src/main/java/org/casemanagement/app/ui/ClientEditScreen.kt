package org.casemanagement.app.ui

import androidx.compose.material3.Checkbox
import androidx.compose.material3.Text
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.selection.toggleable
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import androidx.navigation.NavHostController
import org.casemanagement.app.Api
import org.casemanagement.app.objects
import org.casemanagement.app.str
import org.json.JSONObject

val A11Y_FORMATS = listOf("Standard print", "Large print", "Plain text", "Braille-ready (BRF)", "Audio", "Accessible PDF")
val A11Y_CONTACTS = listOf("No preference", "Email", "Phone", "Client portal", "Postal mail")
val BUILT_IN_STATUSES = listOf("Pending Assessment", "Waitlist", "Active Training", "On Hold", "Closed - Rehabilitated", "Closed - Other")

/** A tick box that is one stop, read with its label and state. */
@Composable
fun Tick(label: String, checked: Boolean, onChange: (Boolean) -> Unit) {
    Row(Modifier.fillMaxWidth().heightIn(min = 48.dp).toggleable(checked, role = Role.Checkbox, onValueChange = onChange),
        verticalAlignment = Alignment.CenterVertically) {
        Checkbox(checked = checked, onCheckedChange = null)
        Text(label, modifier = Modifier.padding(start = 8.dp))
    }
}


/** A new client (id 0) or a client's details, as the website's client form and the iPhone app's. */
@Composable
fun ClientEditScreen(api: Api, nav: NavHostController, id: Int) {
    val loaded = rememberLoaded(id) {
        val c = if (id > 0) api.call("client", mapOf("id" to id.toString())).optJSONObject("client") else null
        val staff = if (api.scoped) emptyList() else try { api.call("staff").optJSONArray("staff").objects().filter { it.optBoolean("can_clients", true) } } catch (e: Exception) { emptyList() }
        (c ?: JSONObject()) to staff
    }
    Show(loaded, "the client") { (c, staff) -> ClientForm(api, nav, id, c, staff) }
}

@Composable
private fun ClientForm(api: Api, nav: NavHostController, id: Int, c: JSONObject, staff: List<JSONObject>) {
    val statuses = api.formList("client_statuses", BUILT_IN_STATUSES).let { l -> c.str("status")?.let { if (it in l) l else l + it } ?: l }
    val types = api.formList("client_types", listOf("VR", "Pre-ETS"))
    val reasons = api.formList("closure_reasons", listOf("Goals met", "Declined services", "Moved", "Unable to contact", "Other"))
    var name by rememberSaveable { mutableStateOf(c.str("name") ?: "") }
    var status by rememberSaveable { mutableStateOf(c.str("status") ?: "Pending Assessment") }
    var statusNote by rememberSaveable { mutableStateOf("") }
    var type by rememberSaveable { mutableStateOf(c.str("client_type") ?: types.first()) }
    var dob by rememberSaveable { mutableStateOf(c.str("dob") ?: "") }
    var phone by rememberSaveable { mutableStateOf(c.str("phone") ?: "") }
    var email by rememberSaveable { mutableStateOf(c.str("email") ?: "") }
    var emailConfirmed by rememberSaveable { mutableStateOf(false) }
    var address by rememberSaveable { mutableStateOf(c.str("address") ?: "") }
    var assigned by rememberSaveable { mutableStateOf(if (c.isNull("assigned_to") || !c.has("assigned_to")) (if (id == 0) api.userId else 0) else c.optInt("assigned_to")) }
    var counselorName by rememberSaveable { mutableStateOf(c.str("counselor_name") ?: "") }
    var counselorAgency by rememberSaveable { mutableStateOf(c.str("counselor_agency") ?: "") }
    var counselorEmail by rememberSaveable { mutableStateOf(c.str("counselor_email") ?: "") }
    var counselorPhone by rememberSaveable { mutableStateOf(c.str("counselor_phone") ?: "") }
    var counselorConfirmed by rememberSaveable { mutableStateOf(false) }
    var format by rememberSaveable { mutableStateOf(c.str("a11y_format") ?: "") }
    var contact by rememberSaveable { mutableStateOf(c.str("a11y_contact") ?: "") }
    var tech by rememberSaveable { mutableStateOf(c.str("a11y_primary_tech") ?: "") }
    var reason by rememberSaveable { mutableStateOf("") }
    var reasonNote by rememberSaveable { mutableStateOf("") }
    var employed by rememberSaveable { mutableStateOf("") }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    val scope = rememberCoroutineScope()
    val oldStatus = c.str("status")
    val closing = status.startsWith("Closed") && status != oldStatus
    val statusChanged = id > 0 && status != oldStatus

    Page {
        Heading(if (id == 0) "Add a client" else "Edit " + (c.str("name") ?: "client"), large = true)
        Field("Full name", name, { name = it })
        Choice("Status", status, statuses.map { it to it }) { status = it }
        if (statusChanged) Field("Reason for the status change", statusNote, { statusNote = it }, hint = if (status == "On Hold") "required for On Hold" else "kept in the history")
        if (closing) {
            Choice("Why the case is closed", reason, listOf("" to "Choose a reason") + reasons.map { it to it }) { reason = it }
            if (reason == "Other") Field("Say why", reasonNote, { reasonNote = it })
            Choice("Employed at closure", employed, listOf("" to "Not recorded", "yes" to "Yes", "no" to "No")) { employed = it }
            if (status == "Closed - Rehabilitated" && email.isNotBlank()) NoticeText("Saving this emails the client the outcome survey, if their email address is confirmed.")
        }
        Choice("Client type", type, types.map { it to it }) { type = it }
        Field("Date of birth", dob, { dob = it }, hint = "optional, year-month-day")
        Field("Phone", phone, { phone = it }, keyboard = KeyboardType.Phone)
        Field("Email", email, { email = it }, keyboard = KeyboardType.Email)
        if (email.isNotBlank() && (c.optBoolean("email_verified").not() || email.trim() != (c.str("email") ?: ""))) Tick("I confirmed this email address with the client", emailConfirmed) { emailConfirmed = it }
        Field("Address", address, { address = it }, singleLine = false)
        if (api.scoped) Text("Assigned instructor: you. Your account works only its own caseload, so clients you add or edit stay assigned to you.")
        else Choice("Assigned instructor", assigned.toString(), listOf("0" to "Unassigned") + staff.map { it.optInt("id").toString() to it.optString("name") }) { assigned = it.toInt() }
        Heading("VR counselor")
        Field("Counselor name", counselorName, { counselorName = it })
        Field("Agency", counselorAgency, { counselorAgency = it })
        Field("Counselor email", counselorEmail, { counselorEmail = it }, keyboard = KeyboardType.Email)
        if (counselorEmail.isNotBlank() && (c.optBoolean("counselor_verified").not() || counselorEmail.trim() != (c.str("counselor_email") ?: ""))) Tick("I confirmed this email address with the counselor", counselorConfirmed) { counselorConfirmed = it }
        Field("Counselor phone", counselorPhone, { counselorPhone = it }, keyboard = KeyboardType.Phone)
        Heading("Accessibility")
        Choice("Preferred format", format, listOf("" to "Not set") + A11Y_FORMATS.map { it to it }) { format = it }
        Choice("Preferred contact", contact, listOf("" to "Not set") + A11Y_CONTACTS.map { it to it }) { contact = it }
        Field("Primary technology", tech, { tech = it })
        ErrorText(error)
        PrimaryButton(if (id == 0) "Add client" else "Save changes", busy = busy, enabled = name.isNotBlank()) {
            if (closing && reason.isEmpty()) { error = "Choose why the case is closed."; return@PrimaryButton }
            scope.launchSave({ busy = it }, { error = it }) {
                val b = JSONObject().put("id", id).put("name", name.trim()).put("status", status).put("client_type", type)
                    .put("dob", dob.trim()).put("phone", phone.trim()).put("email", email.trim()).put("address", address.trim())
                    .put("counselor_name", counselorName.trim()).put("counselor_agency", counselorAgency.trim())
                    .put("counselor_email", counselorEmail.trim()).put("counselor_phone", counselorPhone.trim())
                    .put("a11y_format", format).put("a11y_contact", contact).put("a11y_primary_tech", tech.trim())
                if (!api.scoped) b.put("assigned_to", assigned)
                if (statusChanged && statusNote.isNotBlank()) b.put("status_note", statusNote.trim())
                if (emailConfirmed) b.put("email_verified", true)
                if (counselorConfirmed) b.put("counselor_verified", true)
                if (closing) { b.put("closure_reason", reason).put("closure_note", reasonNote.trim()).put("closure_employed", employed) }
                val r = api.call("save_client", body = b)
                Flash.pending = listOfNotNull(
                    if (id == 0) "Client added." else "Client saved.",
                    if (r.optBoolean("unarchived")) "The archived record was reopened." else null,
                    when { r.isNull("survey_sent") || !r.has("survey_sent") -> null; r.optBoolean("survey_sent") -> "An outcome survey was emailed to the client."; else -> "No outcome survey was emailed." },
                    r.str("duplicate_warning"),
                ).joinToString(" ")
                if (id == 0) { nav.popBackStack(); nav.navigate("client/${r.optInt("client_id")}") } else nav.popBackStack()
            }
        }
    }
}
