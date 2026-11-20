package org.casemanagement.app.ui

import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import org.casemanagement.app.Api
import org.casemanagement.app.ApiException
import org.casemanagement.app.objects
import org.casemanagement.app.str
import org.casemanagement.app.strings
import org.json.JSONObject

/** The technologies an assessment can recommend, when the server sends none (an older server). */
val TECH_TYPES_FALLBACK = listOf("Screen Reader", "Magnification", "Braille Display", "Speech-to-Text", "Independent Living Skills",
    "Braille Literacy", "Low Vision Devices", "Rehabilitation Technology", "Other")

/** The assessment fields in the form's order, with the server's error keys. */
private val ASSESSMENT_KEYS = listOf("assessment_date", "primary_disability", "primary_tech", "hardware_details", "software_version", "summary")

/**
 * 4.35.0: a client's tech assessments (the website's Tech Assessments tab), newest first, and
 * "Record a tech assessment" with the website's fields and checks.
 */
@Composable
fun TechAssessmentsScreen(api: Api, clientId: Int) {
    val state = rememberLoaded(clientId) { api.call("assessments", mapOf("client_id" to clientId.toString())) }
    var adding by rememberSaveable { mutableStateOf(false) }
    var notice by remember { mutableStateOf<String?>(null) }
    var open by remember { mutableStateOf<Set<Int>>(emptySet()) }
    Page {
        NoticeText(notice)
        if (api.can("clients")) PrimaryButton("Record a tech assessment") { notice = null; adding = true }
        Show(state, "tech assessments") { r ->
            val list = r.optJSONArray("assessments").objects()
            Heading("Tech assessments (${list.size})")
            if (list.isEmpty()) Text("No assessments recorded.")
            list.forEach { a ->
                val id = a.optInt("id")
                val expanded = id in open
                val head = assessmentRowText(a.str("assessment_date"), a.str("primary_tech"))
                val more = if (expanded) listOfNotNull(
                    a.str("primary_disability"), a.str("author")?.let { "Recorded by $it" },
                    a.str("hardware_details")?.let { "Hardware: $it" }, a.str("software_version")?.let { "Software: $it" }, a.str("summary"),
                ) else emptyList()
                ListRow(listOf(head) + more, action = if (expanded) "close it" else "read it", state = if (expanded) "expanded" else "collapsed",
                    onClick = { open = if (expanded) open - id else open + id })
            }
        }
    }
    if (adding) {
        val techs = state.data?.optJSONArray("tech_types").strings().ifEmpty { api.formList("tech_types", TECH_TYPES_FALLBACK) }
        AddAssessmentDialog(api, clientId, techs, onDone = { m -> adding = false; notice = m; state.reload() }, onCancel = { adding = false })
    }
}

@Composable
private fun AddAssessmentDialog(api: Api, clientId: Int, techs: List<String>, onDone: (String) -> Unit, onCancel: () -> Unit) {
    var date by rememberSaveable { mutableStateOf(today()) }
    var disability by rememberSaveable { mutableStateOf("") }
    var tech by rememberSaveable { mutableStateOf(techs.firstOrNull() ?: "Other") }
    var hardware by rememberSaveable { mutableStateOf("") }
    var software by rememberSaveable { mutableStateOf("") }
    var summary by rememberSaveable { mutableStateOf("") }
    var idem by rememberSaveable { mutableStateOf("") }
    var fieldErrors by remember { mutableStateOf<Map<String, String>>(emptyMap()) }
    var error by remember { mutableStateOf<String?>(null) }
    var busy by remember { mutableStateOf(false) }
    var presses by remember { mutableIntStateOf(0) }
    val scope = rememberCoroutineScope()
    // Cancel or Back with something entered asks first, as the iPhone app.
    var askDiscard by remember { mutableStateOf(false) }
    val dirty = date != today() || disability.isNotBlank() || tech != (techs.firstOrNull() ?: "Other") || hardware.isNotBlank() || software.isNotBlank() || summary.isNotBlank()
    if (askDiscard) Confirm("Discard this assessment?", "What you entered here is not saved.", "Discard", onCancel, { askDiscard = false }, no = "Keep editing")
    FormDialog("Record a tech assessment", "Save", busy, onSave = {
        if (busy) return@FormDialog
        presses++
        fieldErrors = listOfNotNull(assessmentDateRule(date)?.let { "assessment_date" to it }).toMap()
        error = errorSummary(fieldErrors.values)
        if (error != null) return@FormDialog
        scope.launchSave({ busy = it }, { }) {
            try {
                val b = JSONObject().put("client_id", clientId).put("assessment_date", date.trim()).put("primary_disability", disability.trim())
                    .put("primary_tech", tech).put("hardware_details", hardware.trim()).put("software_version", software.trim()).put("summary", summary.trim())
                api.callOnce("add_assessment", b, idem, { idem = it }, "the assessment")
                onDone("Assessment saved.")
            } catch (e: ApiException) {
                presses++
                fieldErrors = serverFieldErrors(e.reply) ?: emptyMap()
                error = errorSummary(ASSESSMENT_KEYS.map { fieldErrors[it] }) ?: e.message
            }
        }
    }, onCancel = { if (dirty) askDiscard = true else onCancel() }) {
        Field("Assessment date", date, { date = it }, hint = "year-month-day", error = fieldErrors["assessment_date"], required = true)
        Field("Primary disability / needs", disability, { disability = it }, error = fieldErrors["primary_disability"])
        Choice("Primary focus / tech recommended", tech, (techs + listOfNotNull(tech.takeIf { it !in techs })).map { it to it }, error = fieldErrors["primary_tech"]) { tech = it }
        Field("Hardware details", hardware, { hardware = it }, error = fieldErrors["hardware_details"])
        Field("Software & version", software, { software = it }, error = fieldErrors["software_version"])
        Field("Assessment summary", summary, { summary = it }, singleLine = false, minLines = 4, error = fieldErrors["summary"])
        ErrorText(error, focusKey = presses)
    }
}
