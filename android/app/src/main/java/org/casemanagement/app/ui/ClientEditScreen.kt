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
import org.casemanagement.app.parseOffices
import org.casemanagement.app.CustomField
import org.casemanagement.app.customBody
import org.casemanagement.app.customFieldsProblem
import org.casemanagement.app.customRequiredProblem
import org.casemanagement.app.parseCustomFields
import org.casemanagement.app.prefillCount
import org.casemanagement.app.objects
import org.casemanagement.app.str
import org.json.JSONObject

val A11Y_FORMATS = listOf("Standard print", "Large print", "Plain text", "Braille-ready (BRF)", "Audio", "Accessible PDF")
val A11Y_CONTACTS = listOf("No preference", "Email", "Phone", "Client portal", "Postal mail")
val BUILT_IN_STATUSES = listOf("Pending Assessment", "Waitlist", "Active Training", "On Hold", "Closed - Rehabilitated", "Closed - Other")

/** The states and territories a client can live in (the server's US_STATES, 4.19.0): code to name. */
val US_STATES = listOf(
    "AL" to "Alabama",
    "AK" to "Alaska",
    "AZ" to "Arizona",
    "AR" to "Arkansas",
    "CA" to "California",
    "CO" to "Colorado",
    "CT" to "Connecticut",
    "DE" to "Delaware",
    "DC" to "District of Columbia",
    "FL" to "Florida",
    "GA" to "Georgia",
    "HI" to "Hawaii",
    "ID" to "Idaho",
    "IL" to "Illinois",
    "IN" to "Indiana",
    "IA" to "Iowa",
    "KS" to "Kansas",
    "KY" to "Kentucky",
    "LA" to "Louisiana",
    "ME" to "Maine",
    "MD" to "Maryland",
    "MA" to "Massachusetts",
    "MI" to "Michigan",
    "MN" to "Minnesota",
    "MS" to "Mississippi",
    "MO" to "Missouri",
    "MT" to "Montana",
    "NE" to "Nebraska",
    "NV" to "Nevada",
    "NH" to "New Hampshire",
    "NJ" to "New Jersey",
    "NM" to "New Mexico",
    "NY" to "New York",
    "NC" to "North Carolina",
    "ND" to "North Dakota",
    "OH" to "Ohio",
    "OK" to "Oklahoma",
    "OR" to "Oregon",
    "PA" to "Pennsylvania",
    "RI" to "Rhode Island",
    "SC" to "South Carolina",
    "SD" to "South Dakota",
    "TN" to "Tennessee",
    "TX" to "Texas",
    "UT" to "Utah",
    "VT" to "Vermont",
    "VA" to "Virginia",
    "WA" to "Washington",
    "WV" to "West Virginia",
    "WI" to "Wisconsin",
    "WY" to "Wyoming",
    "AS" to "American Samoa",
    "GU" to "Guam",
    "MP" to "Northern Mariana Islands",
    "PR" to "Puerto Rico",
    "VI" to "U.S. Virgin Islands",
)

/** A tick box that is one stop, read with its label and state. */
@Composable
fun Tick(label: String, checked: Boolean, onChange: (Boolean) -> Unit) {
    Row(Modifier.fillMaxWidth().heightIn(min = 48.dp).toggleable(checked, role = Role.Checkbox, onValueChange = onChange),
        verticalAlignment = Alignment.CenterVertically) {
        Checkbox(checked = checked, onCheckedChange = null)
        Text(label, modifier = Modifier.padding(start = 8.dp))
    }
}


/** One of the organisation's own fields, edited as its type asks ("" is Not answered). */
@Composable
private fun CustomFieldInput(f: CustomField, value: String, onChange: (String) -> Unit) {
    val label = f.label + if (f.required) " (required)" else ""
    val hint = f.hint.ifBlank { null }
    when (f.type) {
        "choice", "yesno" -> Choice(label, value, listOf("" to "Not answered") + (f.options + listOfNotNull(value.takeIf { it.isNotEmpty() && it !in f.options })).map { it to it }, onChange)
        "textarea" -> Field(label, value, onChange, hint = hint, singleLine = false, minLines = 3)
        "number" -> Field(label, value, onChange, hint = hint, keyboard = KeyboardType.Decimal)
        "date" -> Field(label, value, onChange, hint = listOfNotNull(hint, "year-month-day, empty for Not answered").joinToString(", "))
        else -> Field(label, value, onChange, hint = hint)
    }
}

/** A new client (id 0) or a client's details, as the website's client form and the iPhone app's. */
@Composable
fun ClientEditScreen(api: Api, nav: NavHostController, id: Int) {
    val loaded = rememberLoaded(id) {
        val r = if (id > 0) api.call("client", mapOf("id" to id.toString())).also { api.noteClientDetail(it) } else null
        // The texting agreement (4.17.0) rides along on the client, as the form reads it.
        val c = r?.optJSONObject("client")?.put("_texting", r.optJSONObject("texting"))
        val staff = if (api.scoped) emptyList() else try { api.call("staff").optJSONArray("staff").objects().filter { it.optBoolean("can_clients", true) } } catch (e: Exception) { emptyList() }
        // A new client (4.25.0): the organisation's own fields and offices come with the form's lists.
        val form = c ?: JSONObject().also { n ->
            val o = try { api.call("form_options") } catch (e: Exception) { null }
            n.put("custom_fields_edit", o?.optJSONArray("custom_fields") ?: org.json.JSONArray()).put("offices", o?.optJSONArray("offices") ?: org.json.JSONArray())
        }
        form to staff
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
    var usState by rememberSaveable { mutableStateOf(c.str("state") ?: "") }
    // Required for a new client and for one whose state is known (as the website); an older record with none can still be saved.
    val stateRequired = id == 0 || !c.str("state").isNullOrEmpty()
    var assigned by rememberSaveable { mutableStateOf(if (c.isNull("assigned_to") || !c.has("assigned_to")) (if (id == 0) api.userId else 0) else c.optInt("assigned_to")) }
    var counselorName by rememberSaveable { mutableStateOf(c.str("counselor_name") ?: "") }
    var counselorAgency by rememberSaveable { mutableStateOf(c.str("counselor_agency") ?: "") }
    var counselorEmail by rememberSaveable { mutableStateOf(c.str("counselor_email") ?: "") }
    var counselorPhone by rememberSaveable { mutableStateOf(c.str("counselor_phone") ?: "") }
    var counselorConfirmed by rememberSaveable { mutableStateOf(false) }
    var format by rememberSaveable { mutableStateOf(c.str("a11y_format") ?: "") }
    var contact by rememberSaveable { mutableStateOf(c.str("a11y_contact") ?: "") }
    var tech by rememberSaveable { mutableStateOf(c.str("a11y_primary_tech") ?: "") }
    var a11yNotes by rememberSaveable { mutableStateOf(c.str("a11y_notes") ?: "") }
    var screenReader by rememberSaveable { mutableStateOf(c.optBoolean("a11y_screen_reader")) }
    var guardianName by rememberSaveable { mutableStateOf(c.str("guardian_name") ?: "") }
    var guardianPhone by rememberSaveable { mutableStateOf(c.str("guardian_phone") ?: "") }
    var guardianEmail by rememberSaveable { mutableStateOf(c.str("guardian_email") ?: "") }
    var school by rememberSaveable { mutableStateOf(c.str("school") ?: "") }
    var vrCase by rememberSaveable { mutableStateOf(c.str("vr_case_number") ?: "") }
    var ipeGoal by rememberSaveable { mutableStateOf(c.str("ipe_goal") ?: "") }
    // Offices (4.22.0): only when the organisation uses them (the server sends none otherwise).
    val offices = parseOffices(c)
    var office by rememberSaveable { mutableStateOf(if (c.isNull("office_id") || !c.has("office_id")) "" else c.optInt("office_id").toString()) }
    // The organisation's own fields (4.25.0), edited by type; only changed answers are sent.
    val customFields = remember(c) { parseCustomFields(c.optJSONArray("custom_fields_edit")) }
    var custom by rememberSaveable { mutableStateOf(mapOf<Int, String>()) }
    fun customValue(f: CustomField) = custom[f.id] ?: f.value
    fun setCustom(f: CustomField, v: String) { custom = custom + (f.id to v) }
    val counts = c.optJSONObject("device_counts")
    var employer by rememberSaveable { mutableStateOf("") }
    var jobTitle by rememberSaveable { mutableStateOf("") }
    var jobStart by rememberSaveable { mutableStateOf("") }
    var jobHours by rememberSaveable { mutableStateOf("") }
    var wage by rememberSaveable { mutableStateOf("") }
    var independence by rememberSaveable { mutableStateOf("") }
    var atRecommended by rememberSaveable { mutableStateOf(prefillCount(counts?.optInt("recommended") ?: 0)) }
    var atAcquired by rememberSaveable { mutableStateOf(prefillCount(counts?.optInt("acquired") ?: 0)) }
    var atInUse by rememberSaveable { mutableStateOf("") }
    var atNote by rememberSaveable { mutableStateOf("") }
    var reason by rememberSaveable { mutableStateOf("") }
    var reasonNote by rememberSaveable { mutableStateOf("") }
    var employed by rememberSaveable { mutableStateOf("") }
    val texting = c.optJSONObject("_texting")
    var smsConsent by rememberSaveable { mutableStateOf(texting?.optBoolean("agreed") ?: false) }
    var smsSource by rememberSaveable { mutableStateOf(texting?.str("source") ?: "In person") }
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
            if (employed == "yes") {
                Field("Employer", employer, { employer = it })
                Field("Job title", jobTitle, { jobTitle = it })
                Field("Job start date", jobStart, { jobStart = it }, hint = "optional, year-month-day")
                Field("Hours a week", jobHours, { jobHours = it }, hint = "optional", keyboard = KeyboardType.Decimal)
                Field("Hourly wage in dollars", wage, { wage = it }, hint = "optional", keyboard = KeyboardType.Decimal)
            }
            Field("Independence outcome", independence, { independence = it }, hint = "optional, for a goal without a job, such as reading mail on their own", singleLine = false)
            Field("Devices recommended", atRecommended, { atRecommended = it }, hint = "optional, 0 to 99", keyboard = KeyboardType.Number)
            Field("Devices the client got", atAcquired, { atAcquired = it }, hint = "optional, 0 to 99", keyboard = KeyboardType.Number)
            Choice("Using their devices at closure?", atInUse, listOf("" to "Not recorded", "yes" to "Yes", "no" to "No")) { atInUse = it }
            Field("Technology note", atNote, { atNote = it }, hint = "optional, such as a device still on order")
            if (status == "Closed - Rehabilitated" && email.isNotBlank()) NoticeText("Saving this emails the client the outcome survey, if their email address is confirmed.")
        }
        Choice("Client type", type, types.map { it to it }) { type = it }
        // As the website: only a Pre-ETS student has a school.
        if (type == "Pre-ETS") Field("School", school, { school = it })
        Field("Date of birth", dob, { dob = it }, hint = "optional, year-month-day")
        // A new number needs the client's agreement again (the server clears it too).
        Field("Phone", phone, { phone = it; if (it.trim() != (c.str("phone") ?: "").trim()) smsConsent = false }, keyboard = KeyboardType.Phone)
        // Texts (4.17.0), as the website's client form: after STOP, only the client's START turns them back on.
        if (texting?.optBoolean("opted_out") == true) Text(texting.optString("words"))
        else if (phone.isNotBlank()) {
            Tick("The client agreed to text messages at this number", smsConsent) { smsConsent = it }
            if (smsConsent) Choice("How they agreed", smsSource, listOf("In person", "By phone", "In writing", "Referral form", "Client portal").map { it to it }) { smsSource = it }
        }
        Field("Email", email, { email = it }, keyboard = KeyboardType.Email)
        if (email.isNotBlank() && (c.optBoolean("email_verified").not() || email.trim() != (c.str("email") ?: ""))) Tick("I confirmed this email address with the client", emailConfirmed) { emailConfirmed = it }
        Field("Address", address, { address = it }, singleLine = false)
        // Required (4.19.0): it sets the client's time zone for reminders and texts.
        Choice(if (stateRequired) "State (required)" else "State, not recorded yet", usState, listOf("" to "Choose a state") + US_STATES) { usState = it }
        if (offices.isNotEmpty()) Choice("Office", office, listOf("" to if (id == 0) "Your own office" else "No office") + offices.map { it.id.toString() to it.name }) { office = it }
        if (api.scoped) Text("Assigned instructor: you. Your account works only its own caseload, so clients you add or edit stay assigned to you.")
        else Choice("Assigned instructor", assigned.toString(), listOf("0" to "Unassigned") + staff.map { it.optInt("id").toString() to it.optString("name") }) { assigned = it.toInt() }
        Heading("Guardian")
        Field("Guardian name", guardianName, { guardianName = it }, hint = "optional")
        Field("Guardian phone", guardianPhone, { guardianPhone = it }, hint = "optional", keyboard = KeyboardType.Phone)
        Field("Guardian email", guardianEmail, { guardianEmail = it }, hint = "optional", keyboard = KeyboardType.Email)
        Heading("VR counselor")
        Field("Counselor name", counselorName, { counselorName = it })
        Field("Agency", counselorAgency, { counselorAgency = it })
        Field("Counselor email", counselorEmail, { counselorEmail = it }, keyboard = KeyboardType.Email)
        if (counselorEmail.isNotBlank() && (c.optBoolean("counselor_verified").not() || counselorEmail.trim() != (c.str("counselor_email") ?: ""))) Tick("I confirmed this email address with the counselor", counselorConfirmed) { counselorConfirmed = it }
        Field("Counselor phone", counselorPhone, { counselorPhone = it }, keyboard = KeyboardType.Phone)
        Field("VR case number", vrCase, { vrCase = it }, hint = "optional")
        Field("IPE employment goal", ipeGoal, { ipeGoal = it }, hint = "optional")
        Heading("Accessibility")
        Choice("Preferred format", format, listOf("" to "Not set") + A11Y_FORMATS.map { it to it }) { format = it }
        Choice("Preferred contact", contact, listOf("" to "Not set") + A11Y_CONTACTS.map { it to it }) { contact = it }
        Field("Primary technology", tech, { tech = it })
        Tick("Uses a screen reader", screenReader) { screenReader = it }
        Field("Accessibility notes", a11yNotes, { a11yNotes = it }, hint = "optional", singleLine = false)
        if (customFields.isNotEmpty()) {
            Heading("More details")
            customFields.forEach { f -> CustomFieldInput(f, customValue(f)) { setCustom(f, it) } }
        }
        ErrorText(error)
        PrimaryButton(if (id == 0) "Add client" else "Save changes", busy = busy, enabled = name.isNotBlank()) {
            if (closing && reason.isEmpty()) { error = "Choose why the case is closed."; return@PrimaryButton }
            (if (id == 0) customRequiredProblem(customFields, custom) else customFieldsProblem(customFields, custom))?.let { error = it; return@PrimaryButton }
            if (usState.isEmpty() && stateRequired) { error = "Choose the state the client lives in. It sets their time zone for reminders and texts."; return@PrimaryButton }
            scope.launchSave({ busy = it }, { error = it }) {
                val b = JSONObject().put("id", id).put("name", name.trim()).put("status", status).put("client_type", type)
                    .put("dob", dob.trim()).put("phone", phone.trim()).put("email", email.trim()).put("address", address.trim())
                    .put("counselor_name", counselorName.trim()).put("counselor_agency", counselorAgency.trim())
                    .put("counselor_email", counselorEmail.trim()).put("counselor_phone", counselorPhone.trim())
                    .put("a11y_format", format).put("a11y_contact", contact).put("a11y_primary_tech", tech.trim())
                    .put("a11y_screen_reader", screenReader).put("a11y_notes", a11yNotes.trim())
                    .put("guardian_name", guardianName.trim()).put("guardian_phone", guardianPhone.trim()).put("guardian_email", guardianEmail.trim())
                    .put("vr_case_number", vrCase.trim()).put("ipe_goal", ipeGoal.trim())
                if (type == "Pre-ETS") b.put("school", school.trim())
                // A new client left on "Your own office" sends nothing, so the server gives them the creator's office.
                if (offices.isNotEmpty() && (id > 0 || office.isNotEmpty())) b.put("office_id", office.toIntOrNull() ?: JSONObject.NULL)
                customBody(customFields, custom)?.let { b.put("custom", it) }
                if (!api.scoped) b.put("assigned_to", assigned)
                // Sent only when chosen: a server before 4.19.0 ignores it, and an empty one is refused.
                if (usState.isNotEmpty()) b.put("state", usState)
                if (texting?.optBoolean("opted_out") != true) b.put("sms_consent", smsConsent && phone.isNotBlank()).put("sms_consent_source", smsSource)
                if (statusChanged && statusNote.isNotBlank()) b.put("status_note", statusNote.trim())
                if (emailConfirmed) b.put("email_verified", true)
                if (counselorConfirmed) b.put("counselor_verified", true)
                if (closing) {
                    b.put("closure_reason", reason).put("closure_note", reasonNote.trim()).put("closure_employed", employed)
                        .put("closure_independence", independence.trim())
                        .put("closure_at_recommended", atRecommended.trim()).put("closure_at_acquired", atAcquired.trim())
                        .put("closure_at_in_use", atInUse).put("closure_at_note", atNote.trim())
                    if (employed == "yes") b.put("closure_employer", employer.trim()).put("closure_job_title", jobTitle.trim())
                        .put("closure_job_start", jobStart.trim()).put("closure_hours", jobHours.trim()).put("closure_wage", wage.trim())
                }
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
