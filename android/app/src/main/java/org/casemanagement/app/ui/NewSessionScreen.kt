package org.casemanagement.app.ui

import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
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
import org.casemanagement.app.idsFromText
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
 * 4.34.0: the clients a session can be booked for: the server's bookable flag (open and not on the
 * waitlist, as the website); an older server without it keeps the old rule (not Closed).
 */
fun bookableFilter(rows: List<JSONObject>): List<JSONObject> =
    rows.filter { if (it.has("bookable")) it.optBoolean("bookable") else !it.optString("status").startsWith("Closed") }

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
        }.let { bookableFilter(it) }
        val tracks = if (api.can("clients")) api.call("tracks").optJSONArray("tracks").objects() else emptyList()
        val staff = if (api.scoped) emptyList() else try { api.call("staff").optJSONArray("staff").objects() } catch (e: ApiException) { emptyList() }
        Triple(clients, tracks, staff)
    }
    // 4.38.0 (T7, T8): the plan patterns, weekdays and meeting providers; an older server has none, so
    // the form stays weekly with a typed link.
    val options = rememberLoaded(api.userId) { try { api.call("booking_options") } catch (e: ApiException) { if (e.status == 401) throw e; null } }
    Show(choices, "the session form") { (clients, tracks, staff) ->
        val opts = options.data
        val patterns = opts?.optJSONArray("plan_patterns").objects().mapNotNull { p -> p.str("key")?.let { it to (p.str("label") ?: it) } }
        val weekdays = opts?.optJSONArray("weekdays").objects().map { it.optInt("n") to it.optString("label") }.filter { it.first in 1..7 }
        val providers = opts?.optJSONArray("meeting_providers").objects()
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
        // 4.38.0 (T7): more than one session (the website's plan), and (T8) the meeting made in the instructor's account.
        var planOpen by rememberSaveable { mutableStateOf(false) }
        var pattern by rememberSaveable { mutableStateOf("weekly") }
        var planDays by rememberSaveable { mutableStateOf("") }
        var planUntil by rememberSaveable { mutableStateOf("") }
        var planDates by rememberSaveable { mutableStateOf("") }
        var meetingMake by rememberSaveable { mutableStateOf("") }
        // The preview the server asked to check (its hash, hours and lines), kept across rotation.
        var previewJson by rememberSaveable { mutableStateOf("") }
        val preview = remember(previewJson) { if (previewJson.isEmpty()) null else try { planPreview(JSONObject().put("plan_preview", JSONObject(previewJson))) } catch (e: Exception) { null } }
        var planOk by rememberSaveable { mutableStateOf(false) }
        var warningsText by rememberSaveable { mutableStateOf("") }
        val warnings = remember(warningsText) { if (warningsText.isEmpty()) emptyList() else warningsText.split('\n') }
        var approved by rememberSaveable { mutableStateOf(false) }
        // 4.27.0: the server's hash of the warnings shown; approving sends it back, so it covers exactly those.
        var warningsHash by rememberSaveable { mutableStateOf<String?>(null) }
        var error by remember { mutableStateOf<String?>(null) }
        var info by remember { mutableStateOf<String?>(null) }
        // 4.28.0: each problem on its own field too (the summary above Save stays).
        var fieldErrors by remember { mutableStateOf<Map<String, String>>(emptyMap()) }
        var busy by remember { mutableStateOf(false) }
        // 4.28.0: a retry after a lost reply books the series once (see sendOnce).
        var idem by rememberSaveable { mutableStateOf("") }
        var presses by remember { androidx.compose.runtime.mutableIntStateOf(0) }
        val scope = rememberCoroutineScope()
        // A change to what is booked clears an approval given for the old one.
        fun changed() { approved = false; warningsText = ""; previewJson = ""; planOk = false }
        val usePattern = if (patterns.isEmpty()) "weekly" else pattern
        val days = idsFromText(planDays)
        Page {
            // 4.30.0: no heading repeating the title bar's "Schedule a session".
            Choice("Client", client, listOf("" to "Choose a client") + clients.map { it.optInt("id").toString() to it.optString("name") }, error = fieldErrors["client"], required = true) { client = it; track = "0"; changed() }
            val own = tracks.filter { it.optInt("client_id").toString() == client }
            if (own.isNotEmpty()) Choice("Training track", track, listOf("0" to "None") + own.map { it.optInt("id").toString() to it.optString("title") }, error = fieldErrors["track"]) { track = it; changed() }
            if (staff.isNotEmpty()) Choice("Instructor", who, staff.map { it.optInt("id").toString() to it.optString("name") }) { who = it; changed() }
            Field("Date", date, { date = it; changed() }, hint = "year-month-day", error = fieldErrors["date"], required = true)
            Field("Start time", time, { time = it; changed() }, hint = "such as 2:30 PM", error = fieldErrors["time"], required = true)
            Field("Length in hours", duration, { duration = it; changed() }, hint = "such as 1.5", keyboard = KeyboardType.Decimal, error = fieldErrors["duration"], required = true)
            Field("Repeat weekly for this many weeks", weeks, { weeks = it; changed() }, hint = "1 for a single session", keyboard = KeyboardType.Number, error = fieldErrors["weeks"], required = usePattern == "weekly")
            if (patterns.isNotEmpty()) {
                // Collapsing it books once or weekly again, so no hidden field can hold an error.
                SecondaryButton((if (planOpen) "Hide: " else "") + PLAN_SECTION) { planOpen = !planOpen; if (!planOpen && pattern != "weekly") { pattern = "weekly"; changed() } }
                if (planOpen) {
                    Choice("How often", pattern, patterns, error = fieldErrors["plan_pattern"]) { pattern = it; changed() }
                    if ((pattern == "days" || pattern == "fill") && weekdays.isNotEmpty()) {
                        Text("Days of the week (several days, or the whole authorization)", modifier = androidx.compose.ui.Modifier.semantics { heading() })
                        weekdays.forEachIndexed { n, (d, label) ->
                            Tick(label, d in days, error = if (n == 0) fieldErrors["plan_days"] else null) { on -> planDays = org.casemanagement.app.idsToText(if (on) days + d else days - d); changed() }
                        }
                    }
                    if (pattern == "days") Field("Last date", planUntil, { planUntil = it; changed() }, hint = "year-month-day; or a number of weeks in Repeat weekly", error = fieldErrors["plan_until"])
                    if (pattern == "dates") Field("Dates, one per line", planDates, { planDates = it; changed() }, hint = "2026-11-03, or 2026-11-03 2:30 PM", singleLine = false, minLines = 4, error = fieldErrors["plan_dates"])
                }
            }
            Tick("Remote or telepractice session", remote) { remote = it; changed() }
            // 4.38.0 (T8): the link made in the instructor's own connected account; a typed link is then not offered.
            if (remote && !providers.isNullOrEmpty()) Choice(MEETING_LABEL, meetingMake, meetingChoices(providers), error = fieldErrors["meeting_make"]) { meetingMake = it; changed() }
            if (remote && (providers.isNullOrEmpty() || meetingMake.isEmpty())) Field("Meeting link", link, { link = it }, hint = "https://…, optional", keyboard = KeyboardType.Uri, error = fieldErrors["link"])
            // 4.34.0: a remote session keeps its location too (where the client joins from), as on the website.
            Field("Location", location, { location = it }, hint = "optional")
            Field("Notes", notes, { notes = it }, hint = "optional")
            preview?.let { p ->
                Heading(previewHeading(p.sessions.size))
                p.sessions.forEach { ListRow(listOf(it)) }
                Tick(bookTheseLabel(p.sessions.size), planOk, error = fieldErrors["plan_ok"]) { planOk = it }
            }
            if (warnings.isNotEmpty()) {
                Heading("Check before booking")
                warnings.forEach { ListRow(listOf(it)) }
                Tick(PLAN_APPROVAL, approved, error = fieldErrors["authorization_ack"]) { approved = it }
            }
            NoticeText(info)
            ErrorText(error, focusKey = presses)
            PrimaryButton("Schedule and send the invite", busy = busy) {
                if (busy) return@PrimaryButton
                presses++
                info = null
                val t = time24(time); val d = number(duration); val w = weeks.trim().toIntOrNull()
                fieldErrors = listOfNotNull(
                    if (client.isEmpty()) "client" to "Choose a client." else null,
                    if (!DAY.matches(date.trim())) "date" to "Enter the date as year-month-day, such as ${today()}." else null,
                    if (t == null) "time" to "Enter the start time, such as 2:30 PM." else null,
                    if (d == null || d < 0.25 || d > 12) "duration" to "Enter a length from 0.25 to 12 hours." else null,
                    // The website's own sentences (session_plan.php); a last date stands in for the weeks.
                    if ((usePattern == "weekly" || (usePattern == "days" && planUntil.isBlank())) && (w == null || w !in weeksRange(usePattern))) "weeks" to weeksError(usePattern) else null,
                    if ((usePattern == "days" || usePattern == "fill") && days.isEmpty()) "plan_days" to PLAN_NO_DAYS else null,
                    if (usePattern == "dates" && planDates.isBlank()) "plan_dates" to PLAN_NO_DATES else null,
                    if (remote && (providers.isNullOrEmpty() || meetingMake.isEmpty()) && link.isNotBlank() && !link.trim().startsWith("https://")) "link" to "The meeting link must start with https://." else null,
                ).toMap()
                error = errorSummary(fieldErrors.values)
                if (error != null) return@PrimaryButton
                // Busy at once (launchSave), so a double tap never books the series twice (4.28.0).
                scope.launchSave({ busy = it }, { }) {
                    try {
                        val b = JSONObject().put("client_id", client.toInt()).put("track_id", track.toInt()).put("staff_id", who.toInt())
                            .put("date", date.trim()).put("time", t).put("duration", d).put("repeat_weeks", w ?: 1)
                            .put("location", location.trim()).put("notes", notes.trim())
                            .put("is_remote", if (remote) 1 else 0).put("authorization_ack", approved)
                        val meeting = meetingBody(remote, if (providers.isNullOrEmpty()) "" else meetingMake, link)
                        meeting.keys().forEach { k -> b.put(k, meeting.get(k)) }
                        if (approved) warningsHash?.let { b.put("authorization_ack_hash", it) }
                        // Only with a server that offers plans (booking_options); the preview's hash once ticked.
                        if (patterns.isNotEmpty()) {
                            val plan = planBody(usePattern, days, planUntil, planDates, if (planOk) preview?.hash else null)
                            plan.keys().forEach { k -> b.put(k, plan.get(k)) }
                        }
                        val r = api.callOnce("schedule_session", b, idem, { idem = it }, "the booking")
                        val n = r.optInt("sessions_created", 1)
                        Flash.pending = r.str("message")?.let { m -> m + (r.str("meeting_why")?.takeIf { !r.optBoolean("meeting_made") && !m.contains(it) }?.let { " $it" } ?: "") }
                            ?: ((if (n > 1) "$n sessions scheduled." else "Session scheduled.") + if (r.optBoolean("invite_sent")) " A calendar invite was emailed." else "")
                        nav.popBackStack()
                    } catch (e: ApiException) {
                        val w2 = e.reply?.optJSONArray("authorization_warnings")
                        val p2 = planPreview(e.reply)
                        if (p2 != null || (w2 != null && w2.length() > 0)) {
                            // 4.38.0 (T7): the preview and the warnings together, so both are approved in one send.
                            if (p2 != null) {
                                if (p2.hash != preview?.hash) planOk = false
                                previewJson = e.reply?.optJSONObject("plan_preview")?.toString() ?: ""
                            } else { previewJson = ""; planOk = false }
                            if (w2 != null && w2.length() > 0) {
                                val shown = List(w2.length()) { w2.optString(it) }
                                // Different warnings from those approved: approved again.
                                if (shown != warnings) approved = false
                                warningsText = shown.joinToString("\n")
                                warningsHash = e.reply?.str("authorization_hash")
                            } else { warningsText = ""; approved = false }
                            fieldErrors = serverFieldErrors(e.reply)?.mapKeys { (k, _) -> planFieldFor(k) } ?: emptyMap()
                            error = e.message ?: "Check the sessions and warnings below, then approve and schedule again."
                        } else if (e.status == 409 && e.reply?.optBoolean("duplicate") == true) {
                            // 4.34.0: it is already booked, so this is information, not a failure.
                            error = null; info = e.message
                        } else {
                            serverFieldErrors(e.reply)?.let { m -> fieldErrors = m.mapKeys { (k, _) -> planFieldFor(k) } }
                            error = e.message
                        }
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
    // Kept by the session's id (4.29.0): a later Move for another session never takes these.
    val sid = s.optInt("id")
    var date by rememberSaveable(sid, key = org.casemanagement.app.dialogKey("move-date", sid)) { mutableStateOf(start.take(10)) }
    var time by rememberSaveable(sid, key = org.casemanagement.app.dialogKey("move-time", sid)) { mutableStateOf(timeText(start)) }
    var duration by rememberSaveable(sid, key = org.casemanagement.app.dialogKey("move-length", sid)) { mutableStateOf(hoursText(if (endTs > startTs) Math.round((endTs - startTs) / 900_000.0) / 4.0 else 1.0)) }
    var dateError by remember { mutableStateOf<String?>(null) }
    var timeError by remember { mutableStateOf<String?>(null) }
    var lengthError by remember { mutableStateOf<String?>(null) }
    var warnings by remember { mutableStateOf<List<String>>(emptyList()) }
    // 4.28.0: the server's hash of the warnings shown; Move anyway sends it back, so it covers exactly those.
    var warningsHash by remember { mutableStateOf<String?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    var presses by remember { androidx.compose.runtime.mutableIntStateOf(0) }
    var busy by remember { mutableStateOf(false) }
    val scope = rememberCoroutineScope()
    fun clearWarnings() { warnings = emptyList(); warningsHash = null }
    fun showWarnings(r: JSONObject) {
        warnings = r.optJSONArray("warnings").let { a -> List(a?.length() ?: 0) { a!!.optString(it) } }
        warningsHash = r.str("warnings_hash")
    }
    fun send(confirm: Boolean) {
        if (busy) return
        presses++
        val t = time24(time); val d = number(duration)
        dateError = if (!DAY.matches(date.trim())) "Enter the date as year-month-day, such as ${today()}." else null
        timeError = if (t == null) "Enter a time such as 2:30 PM." else null
        lengthError = if (d == null || d < 0.25 || d > 12) "Enter a length from 0.25 to 12 hours." else null
        errorSummary(listOf(dateError, timeError, lengthError))?.let { error = it; return }
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
        onDismissRequest = { if (!busy) onCancel() },
        title = { Text("Move: " + s.optString("client"), modifier = Modifier.semantics { heading() }) },
        text = {
            Column(Modifier.verticalScroll(rememberScrollState())) {
                Field("Date", date, { date = it; clearWarnings() }, hint = "year-month-day", error = dateError)
                Field("Start time", time, { time = it; clearWarnings() }, hint = "such as 2:30 PM", error = timeError)
                Field("Length in hours", duration, { duration = it; clearWarnings() }, keyboard = KeyboardType.Decimal, error = lengthError)
                ErrorText(error, focusKey = presses)
                warnings.forEach { Text(it) }
            }
        },
        confirmButton = { TextButton(enabled = !busy, onClick = { send(warnings.isNotEmpty()) }) { Text(if (busy) "Saving…" else if (warnings.isNotEmpty()) "Move anyway" else "Move") } },
        dismissButton = { TextButton(enabled = !busy, onClick = onCancel) { Text("Cancel") } },
    )
}
