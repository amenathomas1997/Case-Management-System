package org.casemanagement.app.ui

import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.input.KeyboardType
import androidx.navigation.NavHostController
import kotlinx.coroutines.launch
import org.casemanagement.app.Api
import org.casemanagement.app.ApiException
import org.casemanagement.app.objects
import org.casemanagement.app.str
import org.json.JSONObject

private val TIME = Regex("^([01]?\\d|2[0-3]):[0-5]\\d$")
private val DAY = Regex("^\\d{4}-\\d{2}-\\d{2}$")

/** "2:30 PM", "14:30" or "2pm" as 24-hour "14:30", or null. */
fun time24(s: String): String? {
    val t = s.trim().lowercase().replace(".", "")
    Regex("^(\\d{1,2})(?::(\\d{2}))?\\s*(am|pm)$").find(t)?.let { m ->
        var h = m.groupValues[1].toInt(); val min = m.groupValues[2].ifEmpty { "00" }
        if (h !in 1..12 || min.toInt() > 59) return null
        if (m.groupValues[3] == "pm" && h != 12) h += 12
        if (m.groupValues[3] == "am" && h == 12) h = 0
        return "%02d:%s".format(h, min)
    }
    return if (TIME.matches(t)) t.padStart(5, '0') else null
}

/**
 * Schedule a session (once, or weekly), as on the website: client, track, instructor, date, time,
 * length, place or online meeting. Authorization warnings are shown and must be approved. Times
 * are the organisation's time zone, as on the website.
 */
@Composable
fun NewSessionScreen(api: Api, nav: NavHostController) {
    val choices = rememberLoaded(api.userId) {
        val clients = try { api.call("client_choices").optJSONArray("clients").objects() } catch (e: ApiException) {
            if (e.status == 404) api.call("clients").optJSONArray("clients").objects() else throw e
        }.filter { !it.optString("status").startsWith("Closed") }
        val tracks = if (api.can("clients")) api.call("tracks").optJSONArray("tracks").objects() else emptyList()
        val staff = if (api.scoped) emptyList() else try { api.call("staff").optJSONArray("staff").objects() } catch (e: ApiException) { emptyList() }
        Triple(clients, tracks, staff)
    }
    Show(choices, "the session form") { (clients, tracks, staff) ->
        var client by rememberSaveable { mutableStateOf("") }
        var track by rememberSaveable { mutableStateOf("0") }
        var who by rememberSaveable { mutableStateOf(api.userId.toString()) }
        var date by rememberSaveable { mutableStateOf(today()) }
        var time by rememberSaveable { mutableStateOf("") }
        var duration by rememberSaveable { mutableStateOf("1") }
        var weeks by rememberSaveable { mutableStateOf("1") }
        var location by rememberSaveable { mutableStateOf("") }
        var remote by rememberSaveable { mutableStateOf(false) }
        var link by rememberSaveable { mutableStateOf("") }
        var notes by rememberSaveable { mutableStateOf("") }
        var warnings by remember { mutableStateOf<List<String>>(emptyList()) }
        var approved by remember { mutableStateOf(false) }
        // 4.27.0: the server's hash of the warnings shown; approving sends it back, so it covers exactly those.
        var warningsHash by remember { mutableStateOf<String?>(null) }
        var error by remember { mutableStateOf<String?>(null) }
        // 4.28.0: each problem on its own field too (the summary above Save stays).
        var fieldErrors by remember { mutableStateOf<Map<String, String>>(emptyMap()) }
        var busy by remember { mutableStateOf(false) }
        // 4.28.0: a retry after a lost reply books the series once (see sendOnce).
        var idem by rememberSaveable { mutableStateOf("") }
        val scope = rememberCoroutineScope()
        // A change to what is booked clears an approval given for the old one.
        fun changed() { approved = false; warnings = emptyList() }
        Page {
            Heading("Schedule a session", large = true)
            Choice("Client", client, listOf("" to "Choose a client") + clients.map { it.optInt("id").toString() to it.optString("name") }) { client = it; track = "0"; changed() }
            val own = tracks.filter { it.optInt("client_id").toString() == client }
            if (own.isNotEmpty()) Choice("Training track", track, listOf("0" to "None") + own.map { it.optInt("id").toString() to it.optString("title") }) { track = it; changed() }
            if (staff.isNotEmpty()) Choice("Instructor", who, staff.map { it.optInt("id").toString() to it.optString("name") }) { who = it }
            Field("Date", date, { date = it; changed() }, hint = "year-month-day", error = fieldErrors["date"])
            Field("Start time", time, { time = it }, hint = "such as 2:30 PM", error = fieldErrors["time"])
            Field("Length in hours", duration, { duration = it; changed() }, hint = "such as 1.5", keyboard = KeyboardType.Decimal, error = fieldErrors["duration"])
            Field("Repeat weekly for this many weeks", weeks, { weeks = it; changed() }, hint = "1 for a single session", keyboard = KeyboardType.Number, error = fieldErrors["weeks"])
            Tick("Remote or telepractice session", remote) { remote = it }
            if (remote) Field("Meeting link", link, { link = it }, hint = "https://…, optional", keyboard = KeyboardType.Uri, error = fieldErrors["link"])
            else Field("Location", location, { location = it }, hint = "optional")
            Field("Notes", notes, { notes = it }, hint = "optional")
            if (warnings.isNotEmpty()) {
                Heading("Authorization review")
                warnings.forEach { ListRow(listOf(it)) }
                Tick("I reviewed these authorization warnings and approve booking despite them", approved) { approved = it }
            }
            ErrorText(error)
            PrimaryButton("Schedule and send the invite", busy = busy, enabled = client.isNotEmpty()) {
                if (busy) return@PrimaryButton
                val t = time24(time); val d = number(duration); val w = weeks.trim().toIntOrNull()
                fieldErrors = listOfNotNull(
                    if (!DAY.matches(date.trim())) "date" to "Enter the date as year-month-day, such as ${today()}." else null,
                    if (t == null) "time" to "Enter the start time, such as 2:30 PM." else null,
                    if (d == null || d < 0.25 || d > 12) "duration" to "Enter a length from 0.25 to 12 hours." else null,
                    if (w == null || w !in 1..26) "weeks" to "Repeat for 1 to 26 weeks." else null,
                    if (remote && link.isNotBlank() && !link.trim().startsWith("https://")) "link" to "The meeting link must start with https://." else null,
                ).toMap()
                error = fieldErrors.values.firstOrNull()
                if (error != null) return@PrimaryButton
                // Busy at once (launchSave), so a double tap never books the series twice (4.28.0).
                scope.launchSave({ busy = it }, { }) {
                    try {
                        val b = JSONObject().put("client_id", client.toInt()).put("track_id", track.toInt()).put("staff_id", who.toInt())
                            .put("date", date.trim()).put("time", t).put("duration", d).put("repeat_weeks", w)
                            .put("location", if (remote) "" else location.trim()).put("notes", notes.trim())
                            .put("is_remote", if (remote) 1 else 0).put("authorization_ack", approved)
                        if (remote && link.isNotBlank()) b.put("meeting_url", link.trim())
                        if (approved) warningsHash?.let { b.put("authorization_ack_hash", it) }
                        val r = api.callOnce("schedule_session", b, idem, { idem = it }, "the booking")
                        val n = r.optInt("sessions_created", 1)
                        Flash.pending = (if (n > 1) "$n sessions scheduled." else "Session scheduled.") + if (r.optBoolean("invite_sent")) " A calendar invite was emailed." else ""
                        nav.popBackStack()
                    } catch (e: ApiException) {
                        val w2 = e.reply?.optJSONArray("authorization_warnings")
                        if (w2 != null && w2.length() > 0) {
                            val shown = List(w2.length()) { w2.optString(it) }
                            // Different warnings from those approved: approved again.
                            if (shown != warnings) approved = false
                            warnings = shown
                            warningsHash = e.reply?.str("authorization_hash")
                            error = "Check the authorization warnings below, then approve and schedule again."
                        } else error = e.message
                    }
                }
            }
        }
    }
}

/** Move a session to another time; warnings (a clash, outside the authorization) are asked about first. */
@Composable
fun MoveDialog(api: Api, s: JSONObject, onDone: (String) -> Unit, onCancel: () -> Unit) {
    val start = s.str("start_at") ?: ""
    val startTs = parseServer(start)?.time ?: 0L
    val endTs = parseServer(s.str("end_at"))?.time ?: 0L
    var date by rememberSaveable { mutableStateOf(start.take(10)) }
    var time by rememberSaveable { mutableStateOf(timeText(start)) }
    var duration by rememberSaveable { mutableStateOf(hoursText(if (endTs > startTs) Math.round((endTs - startTs) / 900_000.0) / 4.0 else 1.0)) }
    var warnings by remember { mutableStateOf<List<String>>(emptyList()) }
    // 4.28.0: the server's hash of the warnings shown; Move anyway sends it back, so it covers exactly those.
    var warningsHash by remember { mutableStateOf<String?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    var busy by remember { mutableStateOf(false) }
    val scope = rememberCoroutineScope()
    fun clearWarnings() { warnings = emptyList(); warningsHash = null }
    fun showWarnings(r: JSONObject) {
        warnings = r.optJSONArray("warnings").let { a -> List(a?.length() ?: 0) { a!!.optString(it) } }
        warningsHash = r.str("warnings_hash")
    }
    fun send(confirm: Boolean) {
        val t = time24(time); val d = number(duration)
        if (!DAY.matches(date.trim()) || t == null) { error = "Enter the date as year-month-day and a time such as 2:30 PM."; return }
        if (d == null || d < 0.25 || d > 12) { error = "Enter a length from 0.25 to 12 hours."; return }
        scope.launchSave({ busy = it }, { error = it }) {
            val b = JSONObject().put("session_id", s.optInt("id")).put("start_at", "${date.trim()} $t").put("duration", d).put("confirm", confirm)
            if (confirm) warningsHash?.let { b.put("confirm_hash", it) }
            val r = try { api.call("move_session", body = b) } catch (e: ApiException) {
                // A refusal with warnings (4.28.0): shown with their hash, to approve or not.
                val w = e.reply?.optJSONArray("warnings")
                if (w != null && w.length() > 0) { showWarnings(e.reply); error = e.message ?: "Check before moving."; return@launchSave }
                throw e
            }
            if (r.optBoolean("moved")) onDone(r.optString("message").ifEmpty { "Session moved." })
            else { showWarnings(r); error = "Check before moving." }
        }
    }
    AlertDialog(
        onDismissRequest = onCancel,
        title = { Text("Move: " + s.optString("client"), modifier = Modifier.semantics { heading() }) },
        text = {
            Column(Modifier.verticalScroll(rememberScrollState())) {
                Field("Date", date, { date = it; clearWarnings() }, hint = "year-month-day")
                Field("Start time", time, { time = it; clearWarnings() }, hint = "such as 2:30 PM")
                Field("Length in hours", duration, { duration = it; clearWarnings() }, keyboard = KeyboardType.Decimal)
                ErrorText(error)
                warnings.forEach { Text(it) }
            }
        },
        confirmButton = { TextButton(enabled = !busy, onClick = { send(warnings.isNotEmpty()) }) { Text(if (warnings.isNotEmpty()) "Move anyway" else "Move") } },
        dismissButton = { TextButton(onClick = onCancel) { Text("Cancel") } },
    )
}
