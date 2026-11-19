package org.casemanagement.app.ui

import android.content.Intent
import android.net.Uri
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
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.input.KeyboardType
import kotlinx.coroutines.launch
import org.casemanagement.app.Api
import org.casemanagement.app.ApiException
import org.casemanagement.app.NoteToWrite
import org.casemanagement.app.SessionItem
import org.casemanagement.app.parseNoteToWrite
import org.casemanagement.app.parseNotesToWrite
import org.casemanagement.app.parseSchedule
import org.casemanagement.app.objects
import org.casemanagement.app.str
import org.json.JSONObject

/** 4.34.0: check-in is offered from 30 minutes before the start until the session ends (the server refuses earlier). */
fun checkInWindow(startMs: Long?, endMs: Long?, nowMs: Long): Boolean {
    if (startMs == null) return false
    val end = endMs ?: (startMs + 60 * 60_000L)
    return nowMs >= startMs - 30 * 60_000L && nowMs <= end
}

/**
 * 4.34.0: what a session offers, as the website: check in (in its window), complete and record a
 * no-show only once it has started, move and cancel while it is scheduled; "hours" (the hours and
 * the signature on completing) only for a session on a training track.
 */
fun sessionActions(status: String, startMs: Long?, endMs: Long?, nowMs: Long, hasTrack: Boolean, checkedIn: Boolean = false): List<String> {
    if (status != "Scheduled") return emptyList()
    val started = startMs != null && nowMs >= startMs
    return listOfNotNull(
        if (!checkedIn && checkInWindow(startMs, endMs, nowMs)) "check_in" else null,
        if (started) "complete" else null,
        "move",
        if (started) "no_show" else null,
        "cancel",
        if (hasTrack) "hours" else null,
    )
}

/** 4.34.0: complete_session's body: "completed_logged" sends no hours or miles (those already logged are this session's). */
fun completionBody(sessionId: Int, outcome: String, hours: Double, miles: Double): JSONObject {
    val b = JSONObject().put("session_id", sessionId).put("outcome", outcome)
    if (outcome != "completed_logged") { b.put("hours", hours); if (miles > 0) b.put("mileage", miles) }
    return b
}

/** 4.34.0: "Completed, hours already logged", with " as entry N" when several entries could be it. */
fun loggedChoiceLabel(index: Int, count: Int): String = "Completed, hours already logged" + if (count > 1) " as entry ${index + 1}" else ""

/** 4.34.0: "Showing the next 100 of 140 sessions." when the schedule stops at its limit. */
fun scheduleCutText(shownUpcoming: Int, upcomingTotal: Int): String? =
    if (upcomingTotal > shownUpcoming) "Showing the next $shownUpcoming of $upcomingTotal sessions." else null

/**
 * Sessions waiting for an outcome, then upcoming ones by day. Each session is one stop; a double
 * tap offers what can be done with it: check in, complete, record a no-show or cancel, join.
 */
@Composable
fun ScheduleScreen(api: Api, nav: androidx.navigation.NavHostController) {
    val state = rememberLoaded(api.userId) { api.callCached("schedule", cacheKey = "schedule") }
    // 4.31.0: completed sessions whose case note is not written yet; left out by an older server
    // (Unknown action) or when it cannot be read.
    val toWrite = rememberLoaded(api.userId) { try { parseNotesToWrite(api.call("notes_to_write")) } catch (e: ApiException) { null } }
    var chosen by remember { mutableStateOf<JSONObject?>(null) }
    // The session being completed, kept as text so rotation and process death keep the dialog open (4.28.0).
    var completingJson by rememberSaveable { mutableStateOf<String?>(null) }
    val completing = remember(completingJson) { completingJson?.let { try { JSONObject(it) } catch (e: Exception) { null } } }
    // 4.29.0: the session being moved, kept as text like completing, so the dialog survives rotation
    // and its typed date and time never pass to another session.
    var movingJson by rememberSaveable { mutableStateOf<String?>(null) }
    val moving = remember(movingJson) { movingJson?.let { try { JSONObject(it) } catch (e: Exception) { null } } }
    var notice by remember { mutableStateOf<String?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    // 4.34.0: Cancel and No-show are asked about first (they cannot be changed back).
    var confirmStatus by remember { mutableStateOf<Pair<JSONObject, String>?>(null) }
    val scope = rememberCoroutineScope()
    val context = LocalContext.current

    Show(state, "the schedule") { (r, savedAt) ->
        val sessions = parseSchedule(r)
        Page {
            OfflineCopyNotice(savedAt)
            NoticeText(notice)
            PrimaryButton("Schedule a session") { nav.navigate("newSession") }
            ErrorText(error)
            toWrite.data?.takeIf { it.items.isNotEmpty() && api.can("clients") }?.let { w ->
                Heading("Notes not written yet (${w.total})")
                w.items.forEach { n ->
                    ListRow(listOf(n.clientName, n.subject, "Note not written yet"), action = "write it now", onClick = { nav.navigate(n.route) })
                }
            }
            val overdue = sessions.filter { it.overdue }
            if (overdue.isNotEmpty()) {
                Heading("Ended, waiting for an outcome (${overdue.size})")
                overdue.forEach { s -> SessionRow(s) { chosen = s.raw } }
            }
            val upcoming = sessions.filter { !it.overdue }
            if (upcoming.isEmpty() && overdue.isEmpty()) Text("Nothing scheduled.")
            scheduleCutText(upcoming.size, r.optInt("upcoming_total", upcoming.size))?.let { Text(it) }
            upcoming.groupBy { (it.startAt ?: "").take(10) }.forEach { (day, list) ->
                Heading(dayText(day))
                list.forEach { s -> SessionRow(s) { chosen = s.raw } }
            }
            SecondaryButton("Refresh") { state.reload(); toWrite.reload() }
        }
    }

    chosen?.let { s ->
        val id = s.optInt("id")
        val zone = api.serverZone
        val offered = sessionActions(s.optString("status"), org.casemanagement.app.serverTimeMillis(s.str("start_at"), zone),
            org.casemanagement.app.serverTimeMillis(s.str("end_at"), zone), System.currentTimeMillis(),
            hasTrack = !s.isNull("track_id") && s.optInt("track_id") > 0, checkedIn = s.str("checked_in_at") != null)
        fun act(block: suspend () -> String) {
            chosen = null; error = null
            scope.launch {
                try { notice = block(); state.reload() } catch (e: ApiException) { error = e.message }
            }
        }
        AlertDialog(
            onDismissRequest = { chosen = null },
            title = { Text(s.optString("client") + ", " + timeText(s.str("start_at")), modifier = Modifier.semantics { heading() }) },
            text = {
                Column(Modifier.verticalScroll(rememberScrollState())) {
                    if ("check_in" in offered && !s.optBoolean("overdue")) TextButton(onClick = {
                        // The time the button was pressed (4.27.0), sent only with a copy kept to send later with no
                        // signal, so a late check-in is accepted; one sent now is timed by the server.
                        val pressedAt = org.casemanagement.app.isoTime(System.currentTimeMillis())
                        act {
                            saveOrQueue(api, "check_in_session", JSONObject().put("session_id", id), "Check-in: " + s.optString("client"),
                                "Checked in.") { it.put("checked_in_at", pressedAt) }.message
                        }
                    }) { Text("Check in now") }
                    if ("complete" in offered) TextButton(onClick = { chosen = null; completingJson = s.toString() }) { Text("Complete the session") }
                    // 4.34.0: Move is offered on an ended session too, as on the website.
                    if ("move" in offered) TextButton(onClick = { chosen = null; movingJson = s.toString() }) { Text("Move to another time") }
                    if ("no_show" in offered) TextButton(onClick = { chosen = null; confirmStatus = s to "No Show" }) { Text("Record a no-show") }
                    if ("cancel" in offered) TextButton(onClick = { chosen = null; confirmStatus = s to "Cancelled" }) { Text("Cancel the session") }
                    s.str("meeting_url")?.takeIf { it.startsWith("https://", ignoreCase = true) }?.let { url ->
                        TextButton(onClick = {
                            chosen = null
                            if (!openLink(context, Intent(Intent.ACTION_VIEW, Uri.parse(url)))) error = "No app on this phone can open the meeting link."
                        }) { Text("Join the online meeting") }
                    }
                }
            },
            confirmButton = { TextButton(onClick = { chosen = null }) { Text("Close") } },
        )
    }

    confirmStatus?.let { (s, to) ->
        val who = s.optString("client") + ", " + whenText(s.str("start_at"))
        Confirm(if (to == "Cancelled") "Record this session as Cancelled?" else "Record this session as a No Show?",
            "$who. This cannot be changed back.", "Record it", {
                error = null
                scope.launch {
                    try {
                        api.call("set_session_status", body = JSONObject().put("session_id", s.optInt("id")).put("to", to))
                        notice = if (to == "Cancelled") "Session with $who cancelled." else "Session with $who recorded as a no-show."
                        state.reload()
                    } catch (e: ApiException) { error = e.message }
                }
            }, { confirmStatus = null })
    }

    moving?.let { s -> MoveDialog(api, s, onDone = { m -> movingJson = null; notice = m; state.reload() }, onCancel = { movingJson = null }) }

    completing?.let { s ->
        // 4.31.0: completed (or kept offline to send), straight to the full case-note editor for that
        // session, which says what happened as it opens; saving the note comes back here with "Case note saved".
        CompleteDialog(api, s, onDone = { msg, note ->
            completingJson = null; state.reload(); toWrite.reload()
            // The editor only for those who may write case notes; others stay here with the notice.
            if (note != null && api.can("clients")) { Flash.pending = msg; nav.navigate(note.route) } else notice = msg
        }, onCancel = { completingJson = null })
    }
}

@Composable
private fun SessionRow(s: SessionItem, onClick: () -> Unit) {
    val lines = listOf(
        timeText(s.startAt) + " to " + timeText(s.endAt) + ", " + s.client,
        listOfNotNull(
            s.status.takeIf { it != "Scheduled" },
            if (s.isRemote) "Remote" else s.location,
            s.instructor?.let { "with $it" },
            s.checkedInAt?.let { "checked in at " + timeText(it) },
            s.groupSize.takeIf { it > 1 }?.let { "group of $it" },
        ).joinToString(", "),
    )
    ListRow(lines, action = "see what you can do", onClick = onClick)
}

/**
 * Complete a session: hours (its length to start with), miles and the client's signature, as on
 * the website. 4.31.0: no note here: [onDone] gets the session's note to write next (the full editor).
 * With no signal the completion is kept on the phone and sent later, and the note can still be written.
 */
@Composable
private fun CompleteDialog(api: Api, s: JSONObject, onDone: (String, NoteToWrite?) -> Unit, onCancel: () -> Unit) {
    val start = parseServer(s.str("start_at"))?.time ?: 0L
    val end = parseServer(s.str("end_at"))?.time ?: 0L
    val length = if (end > start) Math.round((end - start) / 900_000.0) / 4.0 else 1.0
    // Kept across rotation, a text-size change and process death, with the client's drawn signature (4.28.0).
    val id = s.optInt("id")
    val hasTrack = !s.isNull("track_id") && s.optInt("track_id") > 0
    var hours by rememberSaveable(id) { mutableStateOf(hoursText(length)) }
    var miles by rememberSaveable(id) { mutableStateOf("") }
    val idem = rememberSaveable(id) { java.util.UUID.randomUUID().toString() }
    var error by remember { mutableStateOf<String?>(null) }
    var hoursError by remember { mutableStateOf<String?>(null) }
    var milesError by remember { mutableStateOf<String?>(null) }
    var logged by remember { mutableStateOf<List<JSONObject>>(emptyList()) }
    val signature = rememberSignatureState(id)
    var signedName by rememberSaveable(id) { mutableStateOf("") }
    val density = androidx.compose.ui.platform.LocalDensity.current.density
    var busy by remember { mutableStateOf(false) }
    val scope = rememberCoroutineScope()

    fun send(outcome: String, workLogId: Int? = null) {
        val h = if (hasTrack) number(hours) else 0.0
        val m = if (hasTrack) number(miles.ifBlank { "0" }) else 0.0
        if (busy) return
        // Each error on its field as well as in the summary (4.28.0).
        hoursError = if (h == null || h < 0 || h > 24) "Enter the hours as a number from 0 to 24." else null
        milesError = if (m == null || m < 0 || m > 1000) "Enter the miles as a number from 0 to 1000." else null
        if (hoursError != null || milesError != null) { error = errorSummary(listOf(hoursError, milesError)); return }
        if (h == null || m == null) return
        // Busy at once (launchSave), so a second tap in the same moment does not send twice (4.28.0).
        scope.launchSave({ busy = it }, { }) {
            try {
                // 4.34.0: hours already logged are those hours: none are sent again (the website's rule).
                val body = completionBody(s.optInt("id"), outcome, h, m)
                if (hasTrack) signature.png(density)?.let { body.put("signature_b64", it) }
                if (hasTrack && signedName.isNotBlank()) body.put("signed_name", signedName.trim().take(120))
                workLogId?.let { body.put("work_log_id", it) }
                body.put("idem_key", idem)
                // Sent now, or with no signal kept to send later (in order, before the note written next).
                val saved = saveOrQueue(api, "complete_session", body, "Session completed: " + s.optString("client"), "Session completed.")
                val r = saved.reply
                val msg = if (r != null) ("Session completed. " + (r.str("warning") ?: "")).trim() else saved.message
                // The reply's client, track and subject; an older server's (or none, offline) from the schedule's row.
                onDone(msg, parseNoteToWrite(r ?: JSONObject(), s))
            } catch (e: ApiException) {
                // Hours are already logged for that day: say which, and ask whether they are these.
                logged = e.reply?.optJSONArray("logged_hours").objects()
                error = e.message
            }
        }
    }

    AlertDialog(
        // Not closed by a tap outside: a client's finger slipping while signing must not lose the entry.
        onDismissRequest = {},
        title = { Text("Complete: " + s.optString("client"), modifier = Modifier.semantics { heading() }) },
        text = {
            Column(Modifier.verticalScroll(rememberScrollState())) {
                // 4.34.0: a session on no training track logs no hours, so neither hours nor the signature are asked.
                if (hasTrack) {
                    Field("Hours", hours, { hours = it }, keyboard = KeyboardType.Decimal, error = hoursError, required = true)
                    Field("Miles driven", miles, { miles = it }, hint = "0 if none", keyboard = KeyboardType.Decimal, error = milesError)
                    SignaturePad(signature)
                    Field("Client's typed name", signedName, { signedName = it }, hint = "optional; counts as their acknowledgement")
                } else Text("This session is not on a training track, so no hours are logged.")
                ErrorText(error)
                // 4.34.0: the website's choices; each uses the Hours and Miles as they are now.
                logged.forEachIndexed { i, w ->
                    TextButton(onClick = { send("completed_logged", w.optInt("id")) }) {
                        Text(loggedChoiceLabel(i, logged.size) + ": ${hoursText(w.optDouble("hours", 0.0))} hours, ${w.optString("description").take(60)}")
                    }
                }
                if (logged.isNotEmpty()) TextButton(onClick = { send("completed_separate") }) { Text("Completed — log hours as well") }
            }
        },
        confirmButton = { TextButton(onClick = { send("completed") }, enabled = !busy) { Text(if (busy) "Saving…" else "Complete") } },
        dismissButton = { TextButton(onClick = onCancel) { Text("Cancel") } },
    )
}
