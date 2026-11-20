package org.casemanagement.app.ui

import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.key
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.text.input.KeyboardType
import kotlinx.coroutines.launch
import org.casemanagement.app.Api
import org.casemanagement.app.ApiException
import org.casemanagement.app.dialogKey
import org.casemanagement.app.objects
import org.casemanagement.app.str
import org.json.JSONObject

/** The website's order: Yes, No, Not asked (Not asked chosen to start with). */
private val YES_NO_NOT_ASKED = listOf("yes" to "Yes", "no" to "No", "" to "Not asked")

/**
 * 4.35.0: After closure, as the website's client page: the outcome survey (email it again, or
 * enter the answers taken by phone) and the 30, 60 and 90-day follow-up checks. [onChanged] reads
 * the record again after a change, with the message to show.
 */
@Composable
fun AfterClosureSection(api: Api, clientId: Int, clientName: String, after: JSONObject, onChanged: (String) -> Unit) {
    val surveys = after.optJSONArray("surveys").objects()
    val followups = after.optJSONArray("followups").objects()
    val byPhone = after.optBoolean("by_phone")
    var askEmail by remember { mutableStateOf(false) }
    var emailKey by rememberSaveable { mutableStateOf("") }
    var phoneOpen by rememberSaveable { mutableStateOf(false) }
    var followupId by rememberSaveable { mutableIntStateOf(0) }
    var error by remember { mutableStateOf<String?>(null) }
    var errorPresses by remember { mutableIntStateOf(0) }
    var emailing by remember { mutableStateOf(false) }
    val scope = rememberCoroutineScope()
    val canEdit = api.can("clients")

    Heading("After closure")
    Heading("Outcome survey")
    if (surveys.isEmpty()) Text("No survey sent yet.")
    surveys.forEach { sv -> ListRow(listOf(sv.str("text") ?: surveyLine(sv))) }
    ErrorText(error, focusKey = errorPresses)
    if (canEdit) {
        SecondaryButton(if (emailing) "Emailing the survey…" else if (byPhone) "Email the survey (client prefers phone or post)" else "Email the survey") {
            if (emailing) return@SecondaryButton
            error = null; askEmail = true
        }
        Announce(if (emailing) "Emailing the survey…" else null, key = emailing)
        SecondaryButton("Enter survey answers taken by phone") { error = null; phoneOpen = true }
    }
    Heading("Follow-up checks")
    if (followups.isEmpty()) Text("None scheduled. Checks are scheduled at 30, 60 and 90 days when the closure is recorded.")
    followups.forEach { fu ->
        ListRow(listOf(fu.str("text") ?: "${fu.optInt("interval_days")}-day check, ${fu.optString("status")}"))
        if (canEdit && fu.optString("status") == "Pending" && fu.optBoolean("due_now")) {
            SecondaryButton("Record the ${fu.optInt("interval_days")}-day check") { error = null; followupId = fu.optInt("id") }
        }
    }

    if (askEmail) Confirm("Email the outcome survey to $clientName?",
        if (byPhone) "The client prefers phone or post, so it may not be emailed; enter their answers by phone instead." else "The client answers it from the email.",
        "Email the survey", {
            if (emailing) return@Confirm
            emailing = true
            scope.launch {
                try {
                    val r = api.callOnce("send_survey", JSONObject().put("client_id", clientId), emailKey, { emailKey = it }, "the survey email")
                    onChanged(r.optString("message").ifEmpty { "Outcome survey emailed." })
                } catch (e: ApiException) { errorPresses++; error = e.message } finally { emailing = false }
            }
        }, { askEmail = false })

    if (phoneOpen) PhoneSurveyDialog(api, clientId, onDone = { m -> phoneOpen = false; onChanged(m) }, onCancel = { phoneOpen = false })

    followups.firstOrNull { followupId != 0 && it.optInt("id") == followupId }?.let { fu ->
        key(dialogKey("closure-followup", followupId)) {
            FollowupCheckDialog(api, clientId, clientName, fu, onDone = { m -> followupId = 0; onChanged(m) }, onCancel = { followupId = 0 })
        }
    }
}

/** A survey row's words when the server sends none (the website's line). */
private fun surveyLine(sv: JSONObject): String =
    if (sv.str("completed_at") != null) "Answered " + dayText(sv.str("completed_at")) + (if (sv.optString("channel") == "Phone") " by phone" else "") +
        ": satisfaction ${sv.optInt("satisfaction")} of 10, goals met ${sv.optInt("goals_met")} of 10"
    else "Emailed " + dayText(sv.str("sent_at")) + ", not answered yet"

@Composable
private fun PhoneSurveyDialog(api: Api, clientId: Int, onDone: (String) -> Unit, onCancel: () -> Unit) {
    var satisfaction by rememberSaveable { mutableStateOf("") }
    var goals by rememberSaveable { mutableStateOf("") }
    var comments by rememberSaveable { mutableStateOf("") }
    var idem by rememberSaveable { mutableStateOf("") }
    var fieldErrors by remember { mutableStateOf<Map<String, String>>(emptyMap()) }
    var error by remember { mutableStateOf<String?>(null) }
    var busy by remember { mutableStateOf(false) }
    var presses by remember { mutableIntStateOf(0) }
    val scope = rememberCoroutineScope()
    FormDialog("Enter survey answers taken by phone", "Save survey answers", busy, onSave = {
        if (busy) return@FormDialog
        presses++
        fieldErrors = listOfNotNull(
            surveyRatingRule("Satisfaction", satisfaction)?.let { "satisfaction" to it },
            surveyRatingRule("Goals met", goals)?.let { "goals_met" to it },
            textTooLong(comments, 2000)?.let { "comments" to "Comments are too long ($it characters maximum)." },
        ).toMap()
        error = errorSummary(listOf(fieldErrors["satisfaction"], fieldErrors["goals_met"], fieldErrors["comments"]))
        if (error != null) return@FormDialog
        scope.launchSave({ busy = it }, { }) {
            try {
                val b = JSONObject().put("client_id", clientId).put("satisfaction", satisfaction.trim().toInt()).put("goals_met", goals.trim().toInt()).put("comments", comments.trim())
                val r = api.callOnce("phone_survey", b, idem, { idem = it }, "the survey answers")
                onDone(r.optString("message").ifEmpty { "Survey answers recorded." })
            } catch (e: ApiException) {
                presses++
                fieldErrors = serverFieldErrors(e.reply) ?: emptyMap()
                error = e.message
            }
        }
    }, onCancel = onCancel) {
        Field("Satisfaction, 1 to 10", satisfaction, { satisfaction = it }, keyboard = KeyboardType.Number, error = fieldErrors["satisfaction"], required = true)
        Field("Goals met, 1 to 10", goals, { goals = it }, keyboard = KeyboardType.Number, error = fieldErrors["goals_met"], required = true)
        Field("Comments", comments, { comments = it }, hint = "optional, up to 2000 characters", singleLine = false, minLines = 3, error = fieldErrors["comments"])
        ErrorText(error, focusKey = presses)
    }
}

@Composable
private fun FollowupCheckDialog(api: Api, clientId: Int, clientName: String, fu: JSONObject, onDone: (String) -> Unit, onCancel: () -> Unit) {
    val interval = fu.optInt("interval_days")
    var answers by rememberSaveable { mutableStateOf(List(FOLLOWUP_QUESTIONS.size) { "" }) }
    var notes by rememberSaveable { mutableStateOf("") }
    var idem by rememberSaveable { mutableStateOf("") }
    var notesError by remember { mutableStateOf<String?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    var asking by remember { mutableStateOf(false) }
    var busy by remember { mutableStateOf(false) }
    var presses by remember { mutableIntStateOf(0) }
    val scope = rememberCoroutineScope()
    FormDialog("$interval-day check, due " + dayText(fu.str("due_on")), "Save the $interval-day check", busy, onSave = {
        if (busy) return@FormDialog
        presses++
        notesError = textTooLong(notes, 1000)?.let { "Notes are too long ($it characters maximum)." }
        error = notesError
        if (error == null) asking = true
    }, onCancel = onCancel) {
        FOLLOWUP_QUESTIONS.forEachIndexed { i, (_, q) ->
            Choice(q, answers[i], YES_NO_NOT_ASKED) { v -> answers = answers.toMutableList().also { it[i] = v } }
        }
        Field("Notes", notes, { notes = it }, hint = "optional, up to 1000 characters", error = notesError)
        ErrorText(error, focusKey = presses)
    }
    val needs = followupAnswer(answers[FOLLOWUP_QUESTIONS.indexOfFirst { it.first == "needs_service" }])
    if (asking) Confirm(followupConfirm(interval, clientName, needs), "It cannot be recorded again.", "Save the check", {
        scope.launchSave({ busy = it }, { }) {
            try {
                val b = JSONObject().put("client_id", clientId).put("followup_id", fu.optInt("id")).put("notes", notes.trim())
                FOLLOWUP_QUESTIONS.forEachIndexed { i, (k, _) -> b.put(k, followupAnswer(answers[i])) }
                val r = api.callOnce("closure_followup", b, idem, { idem = it }, "the follow-up check")
                onDone(r.optString("message").ifEmpty { if (needs == "yes") "Follow-up recorded. Because the client needs more service, a re-referral task was added." else "Follow-up recorded." })
            } catch (e: ApiException) { presses++; notesError = serverFieldErrors(e.reply)?.get("notes"); error = e.message }
        }
    }, { asking = false })
}
