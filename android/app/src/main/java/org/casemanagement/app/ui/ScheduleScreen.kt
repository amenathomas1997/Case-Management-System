package org.casemanagement.app.ui

import android.content.Intent
import android.net.Uri
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
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.input.KeyboardType
import kotlinx.coroutines.launch
import org.casemanagement.app.Api
import org.casemanagement.app.ApiException
import org.casemanagement.app.SessionItem
import org.casemanagement.app.parseSchedule
import org.casemanagement.app.objects
import org.casemanagement.app.str
import org.json.JSONObject

/**
 * Sessions waiting for an outcome, then upcoming ones by day. Each session is one stop; a double
 * tap offers what can be done with it: check in, complete, record a no-show or cancel, join.
 */
@Composable
fun ScheduleScreen(api: Api, nav: androidx.navigation.NavHostController) {
    val state = rememberLoaded(api.userId) { api.callCached("schedule", cacheKey = "schedule") }
    var chosen by remember { mutableStateOf<JSONObject?>(null) }
    var completing by remember { mutableStateOf<JSONObject?>(null) }
    var moving by remember { mutableStateOf<JSONObject?>(null) }
    var notice by remember { mutableStateOf<String?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    val scope = rememberCoroutineScope()
    val context = LocalContext.current

    Show(state, "the schedule") { (r, savedAt) ->
        val sessions = parseSchedule(r)
        Page {
            OfflineCopyNotice(savedAt)
            NoticeText(notice)
            PrimaryButton("Schedule a session") { nav.navigate("newSession") }
            ErrorText(error)
            val overdue = sessions.filter { it.overdue }
            if (overdue.isNotEmpty()) {
                Heading("Ended, waiting for an outcome (${overdue.size})")
                overdue.forEach { s -> SessionRow(s) { chosen = s.raw } }
            }
            val upcoming = sessions.filter { !it.overdue }
            if (upcoming.isEmpty() && overdue.isEmpty()) Text("Nothing scheduled.")
            upcoming.groupBy { (it.startAt ?: "").take(10) }.forEach { (day, list) ->
                Heading(dayText(day))
                list.forEach { s -> SessionRow(s) { chosen = s.raw } }
            }
            SecondaryButton("Refresh") { state.reload() }
        }
    }

    chosen?.let { s ->
        val id = s.optInt("id")
        val scheduled = s.optString("status") == "Scheduled"
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
                Column {
                    if (scheduled && s.str("checked_in_at") == null && !s.optBoolean("overdue")) TextButton(onClick = {
                        // The time the button was pressed (4.27.0), sent only with a copy kept to send later with no
                        // signal, so a late check-in is accepted; one sent now is timed by the server.
                        val pressedAt = org.casemanagement.app.isoTime(System.currentTimeMillis())
                        act {
                            saveOrQueue(api, "check_in_session", JSONObject().put("session_id", id), "Check-in: " + s.optString("client"),
                                "Checked in.") { it.put("checked_in_at", pressedAt) }.message
                        }
                    }) { Text("Check in now") }
                    if (scheduled) TextButton(onClick = { chosen = null; completing = s }) { Text("Complete the session") }
                    if (scheduled && !s.optBoolean("overdue")) TextButton(onClick = { chosen = null; moving = s }) { Text("Move to another time") }
                    if (scheduled) TextButton(onClick = {
                        act { api.call("set_session_status", body = JSONObject().put("session_id", id).put("to", "No Show")); "Recorded as a no-show." }
                    }) { Text("Record a no-show") }
                    if (scheduled) TextButton(onClick = {
                        act { api.call("set_session_status", body = JSONObject().put("session_id", id).put("to", "Cancelled")); "Session cancelled." }
                    }) { Text("Cancel the session") }
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

    moving?.let { s -> MoveDialog(api, s, onDone = { m -> moving = null; notice = m; state.reload() }, onCancel = { moving = null }) }

    completing?.let { s ->
        CompleteDialog(api, s, onDone = { msg -> completing = null; notice = msg; state.reload() }, onCancel = { completing = null })
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

/** Complete a session: hours (its length to start with), miles and a note, as on the website. */
@Composable
private fun CompleteDialog(api: Api, s: JSONObject, onDone: (String) -> Unit, onCancel: () -> Unit) {
    val start = parseServer(s.str("start_at"))?.time ?: 0L
    val end = parseServer(s.str("end_at"))?.time ?: 0L
    val length = if (end > start) Math.round((end - start) / 900_000.0) / 4.0 else 1.0
    var hours by remember { mutableStateOf(hoursText(length)) }
    var miles by remember { mutableStateOf("") }
    var note by remember { mutableStateOf("") }
    var error by remember { mutableStateOf<String?>(null) }
    var logged by remember { mutableStateOf<List<JSONObject>>(emptyList()) }
    val signature = remember { SignatureState() }
    var signedName by remember { mutableStateOf("") }
    val density = androidx.compose.ui.platform.LocalDensity.current.density
    var busy by remember { mutableStateOf(false) }
    val scope = rememberCoroutineScope()

    fun send(outcome: String, workLogId: Int? = null) {
        val h = number(hours)
        val m = number(miles.ifBlank { "0" })
        if (h == null || h < 0 || h > 24) { error = "Enter the hours as a number from 0 to 24."; return }
        if (m == null || m < 0 || m > 1000) { error = "Enter the miles as a number from 0 to 1000."; return }
        scope.launch {
            busy = true; error = null
            try {
                val body = JSONObject().put("session_id", s.optInt("id")).put("outcome", outcome).put("hours", h)
                if (m > 0) body.put("mileage", m)
                if (note.isNotBlank()) body.put("session_note", note.trim())
                signature.png(density)?.let { body.put("signature_b64", it) }
                if (signedName.isNotBlank()) body.put("signed_name", signedName.trim().take(120))
                workLogId?.let { body.put("work_log_id", it) }
                val r = api.call("complete_session", body = body)
                onDone("Session completed. " + (r.str("warning") ?: ""))
            } catch (e: ApiException) {
                // Hours are already logged for that day: say which, and ask whether they are these.
                logged = e.reply?.optJSONArray("logged_hours").objects()
                error = e.message
            } finally { busy = false }
        }
    }

    AlertDialog(
        // Not closed by a tap outside: a client's finger slipping while signing must not lose the entry.
        onDismissRequest = {},
        title = { Text("Complete: " + s.optString("client"), modifier = Modifier.semantics { heading() }) },
        text = {
            Column(Modifier.verticalScroll(rememberScrollState())) {
                Field("Hours", hours, { hours = it }, keyboard = KeyboardType.Decimal)
                Field("Miles driven", miles, { miles = it }, hint = "0 if none", keyboard = KeyboardType.Decimal)
                Field("Session note", note, { note = it }, hint = "optional", singleLine = false, minLines = 3)
                SignaturePad(signature)
                Field("Client's typed name", signedName, { signedName = it }, hint = "optional; counts as their acknowledgement")
                ErrorText(error)
                logged.forEach { w ->
                    TextButton(onClick = { send("completed_logged", w.optInt("id")) }) {
                        Text("These are this session's hours: ${hoursText(w.optDouble("hours", 0.0))} hours, ${w.optString("description").take(60)}")
                    }
                }
                if (logged.isNotEmpty()) TextButton(onClick = { send("completed_separate") }) { Text("This was a separate visit: log these hours as well") }
            }
        },
        confirmButton = { TextButton(onClick = { send("completed") }, enabled = !busy) { Text(if (busy) "Saving…" else "Complete") } },
        dismissButton = { TextButton(onClick = onCancel) { Text("Cancel") } },
    )
}
