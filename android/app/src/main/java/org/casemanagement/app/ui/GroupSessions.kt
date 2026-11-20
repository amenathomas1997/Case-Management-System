package org.casemanagement.app.ui

import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
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
import org.casemanagement.app.Api
import org.casemanagement.app.ApiException
import org.casemanagement.app.NoteToWrite
import org.casemanagement.app.dialogKey
import org.casemanagement.app.idsFromText
import org.casemanagement.app.idsToText
import org.casemanagement.app.objects
import org.casemanagement.app.parseNoteToWrite
import org.casemanagement.app.ratingsFromText
import org.casemanagement.app.ratingsToText
import org.casemanagement.app.str
import org.json.JSONObject

/**
 * 4.35.0: the case notes still to write after a group is completed, in order (kept while the app is
 * open; any left are on Notes to write in any case). The note editor moves from one to the next.
 */
object GroupNoteQueue {
    var items: List<NoteToWrite> = emptyList()
}

/**
 * 4.35.0: book a group session, as the website's schedule page: two or more clients (each client's
 * hours go to the track picked for them, else their newest active track), the instructor, the date,
 * time and length, the place or online meeting. Authorization warnings are shown and approved.
 */
@Composable
fun GroupSessionScreen(api: Api, nav: NavHostController) {
    val choices = rememberLoaded(api.userId) { api.call("group_choices") }
    Show(choices, "the group session form") { r ->
        val clients = r.optJSONArray("clients").objects()
        val staff = r.optJSONArray("staff").objects()
        var tickedText by rememberSaveable { mutableStateOf("") }
        val ticked = idsFromText(tickedText)
        // The track picked for a client with more than one, by client id.
        var picksText by rememberSaveable { mutableStateOf("") }
        val picks = ratingsFromText(picksText)
        var who by rememberSaveable { mutableStateOf((r.optInt("default_staff_id").takeIf { it > 0 } ?: api.userId).toString()) }
        var date by rememberSaveable { mutableStateOf(today()) }
        // The website's group form starts at 10:00.
        var time by rememberSaveable { mutableStateOf("10:00") }
        var duration by rememberSaveable { mutableStateOf("1.5") }
        var location by rememberSaveable { mutableStateOf("") }
        var notes by rememberSaveable { mutableStateOf("") }
        var remote by rememberSaveable { mutableStateOf(false) }
        var link by rememberSaveable { mutableStateOf("") }
        // The warnings, their approval and the server's hash are kept across rotation and process death.
        var warnings by rememberSaveable(stateSaver = androidx.compose.runtime.saveable.Saver<List<String>, String>(
            save = { stringsToText(it) }, restore = { stringsFromText(it) })) { mutableStateOf(emptyList()) }
        var approved by rememberSaveable { mutableStateOf(false) }
        var warningsHash by rememberSaveable { mutableStateOf<String?>(null) }
        var askDiscard by remember { mutableStateOf(false) }
        var error by remember { mutableStateOf<String?>(null) }
        var info by remember { mutableStateOf<String?>(null) }
        var fieldErrors by remember { mutableStateOf<Map<String, String>>(emptyMap()) }
        var busy by remember { mutableStateOf(false) }
        var idem by rememberSaveable { mutableStateOf("") }
        var presses by remember { mutableIntStateOf(0) }
        val scope = rememberCoroutineScope()
        fun changed() { approved = false; warnings = emptyList(); warningsHash = null }
        HoldBackWhileSaving(busy)
        // Back with something entered asks first, as the iPhone app's Cancel.
        val dirty = ticked.isNotEmpty() || time != "10:00" || date != today() || duration != "1.5" || location.isNotBlank() || notes.isNotBlank() || remote || link.isNotBlank()
        androidx.activity.compose.BackHandler(enabled = dirty && !busy) { askDiscard = true }
        if (askDiscard) Confirm("Discard this group session?", "What you entered here is not saved.", "Discard", { nav.popBackStack() }, { askDiscard = false }, no = "Keep editing")
        Page {
            Heading("Clients (required, choose two or more)")
            if (clients.isEmpty()) Text("No clients can be booked: a group session needs open clients who are not on the waitlist.")
            clients.forEachIndexed { i, c ->
                val cid = c.optInt("id")
                val tracks = c.optJSONArray("tracks").objects()
                Tick(groupClientLabel(c.optString("name"), tracks.map { it.optString("title") }), cid in ticked,
                    error = if (i == 0) fieldErrors["clients"] else null) { on ->
                    tickedText = idsToText(if (on) ticked + cid else ticked - cid); changed()
                }
                if (cid in ticked && tracks.size > 1) {
                    val current = (picks[cid] ?: tracks.first().optInt("id")).toString()
                    Choice("Training track for " + c.optString("name"), current, tracks.map { it.optInt("id").toString() to it.optString("title") },
                        error = fieldErrors["track_$cid"]) { v -> picksText = ratingsToText(picks + (cid to (v.toIntOrNull() ?: 0))); changed() }
                }
            }
            if (staff.isNotEmpty()) Choice("Group session instructor", who, staff.map { it.optInt("id").toString() to it.optString("name") }, error = fieldErrors["staff_id"]) { who = it; changed() }
            Field("Group session date", date, { date = it; changed() }, hint = "year-month-day", error = fieldErrors["date"], required = true)
            Field("Group session start time", time, { time = it; changed() }, hint = "such as 2:30 PM", error = fieldErrors["time"], required = true)
            Field("Group session duration (hours)", duration, { duration = it; changed() }, hint = "0.25 to 12", keyboard = KeyboardType.Decimal, error = fieldErrors["duration"], required = true)
            Field("Group session location", location, { location = it }, hint = "optional", error = fieldErrors["location"])
            Field("Group session notes", notes, { notes = it }, hint = "optional", error = fieldErrors["notes"])
            Tick("Group session is remote / telepractice", remote) { remote = it }
            if (remote) Field("Meeting link", link, { link = it }, hint = "optional, starts with https://; for a remote session", keyboard = KeyboardType.Uri, error = fieldErrors["meeting_url"])
            if (warnings.isNotEmpty()) {
                Heading("Authorization review")
                warnings.forEach { ListRow(listOf(it)) }
                Tick("I reviewed these warnings and approve this group booking despite them", approved, error = fieldErrors["authorization_ack"]) { approved = it }
            }
            NoticeText(info)
            ErrorText(error, focusKey = presses)
            PrimaryButton("Schedule group session", busy = busy, announceBusy = true, busyText = "Scheduling the group session…") {
                if (busy) return@PrimaryButton
                presses++
                info = null
                val t = time24(time); val d = number(duration)
                val chosenIds = clients.map { it.optInt("id") }.filter { it in ticked }
                fieldErrors = listOfNotNull(
                    groupClientsRule(chosenIds.size)?.let { "clients" to it },
                    if (!realDate(date)) "date" to "Enter a valid date with a four-digit year from 2000 to ${currentYear() + 5}." else null,
                    if (t == null) "time" to "Enter the start time, such as 2:30 PM." else null,
                    if (d == null || d < 0.25 || d > 12) "duration" to "Enter a length from 0.25 to 12 hours." else null,
                    if (remote && link.isNotBlank() && !link.trim().startsWith("https://")) "meeting_url" to "The meeting link must start with https://." else null,
                ).toMap()
                error = errorSummary(listOf("clients", "date", "time", "duration", "meeting_url").map { fieldErrors[it] })
                if (error != null || t == null || d == null) return@PrimaryButton
                scope.launchSave({ busy = it }, { }) {
                    try {
                        val b = groupBody(chosenIds, picks, who.toIntOrNull() ?: api.userId, date, t, d, location, notes, remote, link,
                            if (approved) warningsHash else null)
                        val res = api.callOnce("schedule_group", b, idem, { idem = it }, "the group booking")
                        val n = res.optInt("sessions_created", chosenIds.size)
                        Flash.pending = res.optString("message").ifEmpty { "Group session scheduled for $n clients." }
                        askDiscard = false
                        nav.popBackStack()
                    } catch (e: ApiException) {
                        val w = e.reply?.optJSONArray("authorization_warnings")
                        if (w != null && w.length() > 0) {
                            val shown = List(w.length()) { w.optString(it) }
                            if (shown != warnings) approved = false
                            warnings = shown
                            warningsHash = e.reply?.str("authorization_hash")
                            fieldErrors = emptyMap()
                            error = "This group booking needs your approval. Check the warnings above, then turn on the approval and tap Schedule group session again."
                        } else if (e.status == 409 && e.reply?.optBoolean("duplicate") == true) {
                            // Already on the schedule: information, not a failure.
                            error = null; fieldErrors = emptyMap(); info = e.message
                        } else {
                            fieldErrors = groupFieldErrors(e.reply)
                            error = e.message
                        }
                    }
                }
            }
        }
    }
}

/**
 * 4.35.0: record a group's attendance and complete it, as the website's group card: everyone is
 * ticked to start with; anyone unticked is recorded as No Show with no hours. [onDone] gets the
 * message and the first case note to write.
 */
@Composable
fun GroupAttendanceDialog(api: Api, uid: String, onDone: (String, NoteToWrite?) -> Unit, onCancel: () -> Unit) {
    val loaded = rememberLoaded(uid) { api.call("group_session", mapOf("group_uid" to uid)) }
    // The members not ticked (everyone attended to start with), and the hours, kept with this group.
    var absentText by rememberSaveable(uid, key = dialogKey("group-absent", uid)) { mutableStateOf("") }
    val absent = idsFromText(absentText)
    var hours by rememberSaveable(uid, key = dialogKey("group-hours", uid)) { mutableStateOf<String?>(null) }
    var idem by rememberSaveable(uid, key = dialogKey("group-idem", uid)) { mutableStateOf("") }
    var hoursError by remember { mutableStateOf<String?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    var asking by remember { mutableStateOf(false) }
    var busy by remember { mutableStateOf(false) }
    var presses by remember { mutableIntStateOf(0) }
    val scope = rememberCoroutineScope()
    val r = loaded.data
    val members = r?.optJSONArray("members").objects()
    LaunchedEffect(r) { if (r != null && hours == null) hours = r.optString("default_hours") }
    val listed = members.map { it.optInt("session_id") }
    val attended = listed.filter { it !in absent }
    val billable = members.count { it.optInt("session_id") in attended && it.optInt("track_id") > 0 }
    AlertDialog(
        onDismissRequest = { if (!busy) onCancel() },
        title = { Text("Group session" + (r?.str("start_at")?.let { ", " + whenText(it) } ?: ""), modifier = Modifier.semantics { heading() }) },
        text = {
            Column(Modifier.verticalScroll(rememberScrollState())) {
                if (loaded.loading && r == null) Text("Loading the group…")
                if (r == null) ErrorText(loaded.error, takeFocus = false)
                if (r != null) {
                    r.str("instructor")?.let { Text("With $it.") }
                    if (!r.optBoolean("can_record", true)) Text(groupCannotRecordText(r.optBoolean("started")))
                    else {
                        Text("Who attended? Untick anyone who did not come: they are recorded as No Show, and no hours are logged for them.")
                        members.forEach { m ->
                            val sid = m.optInt("session_id")
                            Tick(groupAttendLabel(m.optString("client_name"), m.str("track_title"), m.optInt("track_id") > 0), sid !in absent) { on ->
                                absentText = idsToText(if (on) absent - sid else absent + sid)
                            }
                        }
                        Field("Hours for each client who attended", hours ?: "", { hours = it; hoursError = null }, hint = "blank: each client's own scheduled length",
                            keyboard = KeyboardType.Decimal, error = hoursError)
                    }
                }
                ErrorText(error, focusKey = presses)
            }
        },
        confirmButton = {
            if (r != null && r.optBoolean("can_record", true)) TextButton(enabled = !busy, onClick = {
                presses++
                hoursError = groupHoursRule(hours ?: "")
                if (hoursError != null) { error = hoursError; return@TextButton }
                error = null; asking = true
            }) { Text(if (busy) "Saving…" else "Record attendance and complete") }
        },
        dismissButton = { TextButton(enabled = !busy, onClick = onCancel) { Text("Cancel") } },
    )
    if (asking) Confirm("Record attendance?", groupConfirmText(attended.size, listed.size, billable, hours ?: ""), "Record attendance", {
        if (busy) return@Confirm
        scope.launchSave({ busy = it }, { }) {
            try {
                val res = api.callOnce("complete_group", groupCompleteBody(uid, listed, attended, hours ?: ""), idem, { idem = it }, "the group's attendance")
                // Every note to write, in turn (as the website): the first opens now, each one saved moves on to the next.
                val notes = res.optJSONArray("notes_to_write").objects().mapNotNull { n ->
                    val member = members.firstOrNull { it.optInt("session_id") == n.optInt("session_id") }
                    parseNoteToWrite(n, JSONObject().put("track_id", member?.optInt("track_id") ?: 0).put("start_at", r?.str("start_at") ?: JSONObject.NULL))
                }
                GroupNoteQueue.items = notes
                val msg = res.optString("message").ifEmpty { "Group attendance recorded." }
                onDone(if (notes.size > 1) msg + nextNoteWords(notes[0].clientName, 1, notes.size) else msg, notes.firstOrNull())
            } catch (e: ApiException) {
                presses++
                hoursError = serverFieldErrors(e.reply)?.get("hours")
                error = e.message
            }
        }
    }, { asking = false })
}
