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
import kotlinx.coroutines.launch
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
fun TechAssessmentsScreen(api: Api, clientId: Int, nav: androidx.navigation.NavHostController? = null) {
    val context = androidx.compose.ui.platform.LocalContext.current
    val state = rememberLoaded(clientId) { api.call("assessments", mapOf("client_id" to clientId.toString())) }
    var adding by rememberSaveable { mutableStateOf(false) }
    var notice by remember { mutableStateOf<String?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    var open by remember { mutableStateOf<Set<Int>>(emptySet()) }
    // 4.37.0 (S9): the assessment whose goal suggestions are shown (kept across rotation).
    var goalsFor by rememberSaveable { mutableIntStateOf(0) }
    var goalsDate by rememberSaveable { mutableStateOf("") }
    var downloading by remember { mutableIntStateOf(0) }
    val files = rememberScreenFiles()
    val scope = rememberCoroutineScope()
    Page {
        NoticeText(notice)
        ErrorText(error)
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
                // 4.37.0: each assessment's own buttons, outside its row (S7, S9, S11).
                val date = shortDate(a.str("assessment_date"))
                a.str("attachment_name")?.let { att ->
                    SecondaryButton(if (downloading == id) "Opening…" else "Download attachment: $att") {
                        if (downloading != 0) return@SecondaryButton
                        downloading = id; error = null
                        scope.launch { try { error = openAssessmentAttachment(api, context, id, files) } catch (e: ApiException) { error = e.message } finally { downloading = 0 } }
                    }
                }
                if (api.can("reports") && nav != null) SecondaryButton(evalButtonLabel(date)) { nav.navigate("evalReport/$id") }
                if (api.aiHas("draft")) SecondaryButton(goalsButtonLabel(date)) { goalsFor = id; goalsDate = date }
            }
        }
    }
    if (goalsFor > 0) GoalSuggestionsDialog(api, goalsFor, goalsDate) { goalsFor = 0 }
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
    // 4.37.0 (S11): a template to start from, and the report attachment (its address and name).
    val context = androidx.compose.ui.platform.LocalContext.current
    val templates = rememberLoaded(api.userId) { try { api.call("assessment_templates").optJSONArray("templates").objects() } catch (e: ApiException) { if (e.status == 401) throw e; null } }
    var template by rememberSaveable { mutableStateOf("") }
    var askTemplate by rememberSaveable { mutableStateOf("") }
    var attachUri by rememberSaveable { mutableStateOf<android.net.Uri?>(null) }
    var attachName by rememberSaveable { mutableStateOf("") }
    val pick = androidx.activity.compose.rememberLauncherForActivityResult(androidx.activity.result.contract.ActivityResultContracts.OpenDocument()) { uri ->
        if (uri != null) { attachUri = uri; attachName = pickedName(context, uri) }
    }
    fun tplValues(t: JSONObject) = TEMPLATE_KEYS.associateWith { t.optString(it) }
    fun current() = mapOf("primary_tech" to (if (tech == (techs.firstOrNull() ?: "Other")) "" else tech), "hardware_details" to hardware, "software_version" to software, "summary" to summary)
    fun apply(m: Map<String, String>) {
        m["primary_tech"]?.takeIf { it.isNotBlank() }?.let { tech = it }
        hardware = m["hardware_details"] ?: hardware; software = m["software_version"] ?: software; summary = m["summary"] ?: summary
    }
    var idem by rememberSaveable { mutableStateOf("") }
    var fieldErrors by remember { mutableStateOf<Map<String, String>>(emptyMap()) }
    var error by remember { mutableStateOf<String?>(null) }
    var busy by remember { mutableStateOf(false) }
    var presses by remember { mutableIntStateOf(0) }
    val scope = rememberCoroutineScope()
    // Cancel or Back with something entered asks first, as the iPhone app.
    var askDiscard by remember { mutableStateOf(false) }
    val dirty = attachUri != null || date != today() || disability.isNotBlank() || tech != (techs.firstOrNull() ?: "Other") || hardware.isNotBlank() || software.isNotBlank() || summary.isNotBlank()
    tplsAsk@ run {
        val t = templates.data?.firstOrNull { askTemplate.isNotEmpty() && it.optInt("id").toString() == askTemplate } ?: return@run
        // "Keep mine", Back or a tap outside: the form is left as it is.
        Confirm(templateAsk(t.optString("name")), "", "Replace", { askTemplate = ""; apply(templateReplace(current(), tplValues(t))) },
            { askTemplate = "" }, no = "Keep mine")
    }
        if (askDiscard) Confirm("Discard this assessment?", "What you entered here is not saved.", "Discard", onCancel, { askDiscard = false }, no = "Keep editing")
    FormDialog("Record a tech assessment", "Save", busy, onSave = {
        if (busy) return@FormDialog
        presses++
        fieldErrors = listOfNotNull(assessmentDateRule(date)?.let { "assessment_date" to it },
            if (attachUri != null && !attachmentAllowed(attachName)) "attachment" to ATTACHMENT_NOT_ALLOWED else null).toMap()
        error = errorSummary(fieldErrors.values)
        if (error != null) return@FormDialog
        scope.launchSave({ busy = it }, { }) {
            try {
                val b = JSONObject().put("client_id", clientId).put("assessment_date", date.trim()).put("primary_disability", disability.trim())
                    .put("primary_tech", tech).put("hardware_details", hardware.trim()).put("software_version", software.trim()).put("summary", summary.trim())
                // 4.37.0 (S11): with an attachment, the file is streamed from disk (never held whole in memory).
                val uri = attachUri
                if (uri != null) addAssessmentWithAttachment(api, context, b, uri, attachName, idem) { idem = it }
                else api.callOnce("add_assessment", b, idem, { idem = it }, "the assessment")
                onDone("Assessment saved.")
            } catch (e: ApiException) {
                presses++
                fieldErrors = serverFieldErrors(e.reply) ?: emptyMap()
                error = errorSummary((ASSESSMENT_KEYS + "attachment").map { fieldErrors[it] }) ?: e.message
            }
        }
    }, onCancel = { if (dirty) askDiscard = true else onCancel() }) {
        val tpls = templates.data
        if (!tpls.isNullOrEmpty()) Choice("Start from a template", template, listOf("" to "None") + tpls.map { it.optInt("id").toString() to it.optString("name") }) { v ->
            template = v
            tpls.firstOrNull { it.optInt("id").toString() == v }?.let { t ->
                val fill = templateFill(current(), tplValues(t))
                if (fill.asks) askTemplate = v else apply(fill.values)
            }
        }
                Field("Assessment date", date, { date = it }, hint = "year-month-day", error = fieldErrors["assessment_date"], required = true)
        Field("Primary disability / needs", disability, { disability = it }, error = fieldErrors["primary_disability"])
        Choice("Primary focus / tech recommended", tech, (techs + listOfNotNull(tech.takeIf { it !in techs })).map { it to it }, error = fieldErrors["primary_tech"]) { tech = it }
        Field("Hardware details", hardware, { hardware = it }, error = fieldErrors["hardware_details"])
        Field("Software & version", software, { software = it }, error = fieldErrors["software_version"])
        Field("Assessment summary", summary, { summary = it }, singleLine = false, minLines = 4, error = fieldErrors["summary"])
        SecondaryButton(if (attachUri == null) ATTACHMENT_LABEL else "Chosen: $attachName. Choose another attachment") { pick.launch(ATTACHMENT_MIMES) }
        if (attachUri != null) SecondaryButton("Remove attachment") { attachUri = null; attachName = ""; fieldErrors = fieldErrors - "attachment" }
        fieldErrors["attachment"]?.let { ErrorText(it, takeFocus = false) }
        ErrorText(error, focusKey = presses)
    }
}
