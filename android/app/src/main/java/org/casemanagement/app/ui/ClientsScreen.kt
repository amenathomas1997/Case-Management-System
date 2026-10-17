package org.casemanagement.app.ui

import android.content.Intent
import android.net.Uri
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.input.KeyboardType
import androidx.navigation.NavHostController
import org.casemanagement.app.Api
import org.casemanagement.app.ApiException
import kotlinx.coroutines.launch
import org.casemanagement.app.objects
import org.casemanagement.app.str

/** The client list, searched on the server as on the website. */
@Composable
fun ClientsScreen(api: Api, nav: NavHostController) {
    var q by rememberSaveable { mutableStateOf("") }
    var search by rememberSaveable { mutableStateOf("") }
    // The whole list is kept on the phone for reading without a signal (a search is not).
    val state = rememberLoaded(search) {
        if (search.isEmpty()) api.callCached("clients", cacheKey = "clients").let { (r, at) -> r.optJSONArray("clients").objects() to at }
        else api.call("clients", mapOf("q" to search)).optJSONArray("clients").objects() to null
    }
    Page {
        Field("Search clients", q, { q = it }, hint = "name, phone or email")
        SecondaryButton("Search") { search = q.trim() }
        SecondaryButton("Add a client") { nav.navigate("clientEdit/0") }
        Show(state, "clients") { (list, savedAt) ->
            OfflineCopyNotice(savedAt)
            Announce(if (search.isNotEmpty()) "${list.size} ${if (list.size == 1) "client" else "clients"} found" else null)
            Heading(if (search.isEmpty()) "All clients (${list.size})" else "Clients matching $search (${list.size})")
            if (list.isEmpty()) Text("No clients found.")
            list.forEach { c ->
                ListRow(listOf(c.optString("name"), c.optString("status"), c.str("phone") ?: ""), onClick = { nav.navigate("client/${c.optInt("id")}") })
            }
        }
    }
}

/** One client: contact details, training tracks with their hours, and case notes. */
@Composable
fun ClientScreen(api: Api, nav: NavHostController, id: Int) {
    val context = LocalContext.current
    val state = rememberLoaded(id) { api.callCached("client", mapOf("id" to id.toString()), "client.$id") }
    var linkError by remember { mutableStateOf<String?>(null) }
    Show(state, "the client") { (r, savedAt) ->
        val c = r.optJSONObject("client") ?: return@Show
        Page {
            Heading(c.optString("name"), large = true)
            OfflineCopyNotice(savedAt)
            ListRow(listOf("Status: " + c.optString("status")))
            c.str("phone")?.let { p -> ListRow(listOf("Phone: $p"), action = "call", onClick = {
                if (!openLink(context, Intent(Intent.ACTION_DIAL, Uri.parse("tel:" + p.filter { it.isDigit() || it == '+' })))) linkError = "This phone has no app for calls."
            }) }
            c.str("email")?.let { e -> ListRow(listOf("Email: $e"), action = "write an email", onClick = {
                if (!openLink(context, Intent(Intent.ACTION_SENDTO, Uri.parse("mailto:" + Uri.encode(e))))) linkError = "This phone has no email app."
            }) }
            ErrorText(linkError)
            c.str("address")?.let { ListRow(listOf("Address: $it")) }
            c.str("counselor_name")?.let { n -> ListRow(listOf("Counselor: $n", c.str("counselor_agency") ?: "", c.str("counselor_email") ?: "", c.str("counselor_phone") ?: "")) }
            val a11y = listOfNotNull(c.str("a11y_format")?.let { "Prefers $it" }, c.str("a11y_primary_tech"), c.str("a11y_contact")?.let { "Contact by $it" })
            if (a11y.isNotEmpty()) ListRow(listOf("Accessibility: " + a11y.joinToString(", ")))

            PrimaryButton("Add a case note") { nav.navigate("note/$id") }
            SecondaryButton("Documents and homework") { nav.navigate("docs/$id") }
            SecondaryButton("Edit client details") { nav.navigate("clientEdit/$id") }
            if (listOf("recommend", "progress", "intake", "draft").any { api.aiHas(it) }) SecondaryButton("AI help for this client") { nav.navigate("clientAi/$id") }
            if (api.aiHas("voice") && api.can("clients")) SecondaryButton("Voice assistant") { nav.navigate("voice/$id/" + android.net.Uri.encode(c.optString("name").ifEmpty { "-" })) }
            SecondaryButton("Scored assessments") { nav.navigate("scoredList/$id") }
            SecondaryButton("Curriculum competencies") { nav.navigate("competency/$id") }

            val tracks = r.optJSONArray("tracks").objects()
            Heading("Training tracks (${tracks.size})")
            if (tracks.isEmpty()) Text("No training tracks.")
            tracks.forEach { t ->
                val auth = t.optDouble("authorized_hours", 0.0)
                val worked = t.optDouble("hours_worked", 0.0)
                ListRow(
                    listOf(t.optString("title"), t.optString("status") + (t.str("auth_number")?.let { ", authorization $it" } ?: ""),
                        if (auth > 0) "${hoursText(worked)} of ${hoursText(auth)} hours used" + (t.str("expiration_date")?.let { ", ends " + dayText(it) } ?: "") else "${hoursText(worked)} hours logged"),
                    action = "open it",
                    onClick = { nav.navigate("track/$id/${t.optInt("id")}") },
                )
            }
            SecondaryButton("Add a training track") { nav.navigate("trackEdit/$id/0") }

            val notes = r.optJSONArray("notes").objects()
            Heading("Case notes (${r.optInt("notes_total", notes.size)})")
            notes.forEach { n -> NoteRow(n) }
        }
    }
}

@Composable
private fun NoteRow(n: org.json.JSONObject) {
    var open by remember { mutableStateOf(false) }
    val head = listOf(n.optString("subject"), n.optString("note_type") + ", " + whenText(n.str("created_at")) + (n.str("author")?.let { ", by $it" } ?: ""))
    ListRow(head, action = if (open) "close the note" else "read the note", state = if (open) "expanded" else "collapsed", onClick = { open = !open })
    if (open) Text(n.optString("body"))
}

val NOTE_LABELS = mapOf(
    "BIRP" to listOf("Behavior", "Intervention", "Response", "Plan"),
    "PAIP" to listOf("Purpose", "Assessment", "Intervention", "Plan"),
    "SOAP" to listOf("Subjective", "Objective", "Assessment", "Plan"),
)

/**
 * A case note, as on the website: free text, or BIRP, PAIP or SOAP sections. With AI drafting on,
 * a written note can be cleaned up, and sections drafted from rough notes, with homework the notes
 * mention; staff review everything before saving. With no signal it is kept and sent later.
 */
@Composable
fun AddNoteScreen(api: Api, nav: NavHostController, clientId: Int) {
    val types = api.formList("note_types", listOf("Case Note", "Progress Report", "Monthly Summary", "Intake", "Addendum"))
    var type by rememberSaveable { mutableStateOf(types.first()) }
    var format by rememberSaveable { mutableStateOf("free") }
    var subject by rememberSaveable { mutableStateOf("") }
    var body by rememberSaveable { mutableStateOf("") }
    var original by rememberSaveable { mutableStateOf<String?>(null) }
    var sections by rememberSaveable { mutableStateOf(listOf("", "", "", "")) }
    var sectionsBefore by rememberSaveable { mutableStateOf<List<String>?>(null) }
    var rough by rememberSaveable { mutableStateOf("") }
    var questions by remember { mutableStateOf<List<String>>(emptyList()) }
    var homework by remember { mutableStateOf<List<org.json.JSONObject>>(emptyList()) }
    var include by remember { mutableStateOf<Set<Int>>(emptySet()) }
    var hwTrack by rememberSaveable { mutableStateOf("") }
    val tracks = rememberLoaded(clientId) { if (api.can("clients")) api.call("tracks").optJSONArray("tracks").objects().filter { it.optInt("client_id") == clientId } else emptyList() }
    val idem = rememberSaveable { java.util.UUID.randomUUID().toString() }
    var busy by remember { mutableStateOf(false) }
    var aiBusy by remember { mutableStateOf(false) }
    var notice by remember { mutableStateOf<String?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    val scope = androidx.compose.runtime.rememberCoroutineScope()
    val structured = format != "free"
    Page {
        Choice("Note type", type, types.map { it to it }) { type = it }
        Choice("Format", format, listOf("free" to "Free text", "BIRP" to "BIRP", "PAIP" to "PAIP", "SOAP" to "SOAP")) { format = it }
        Field("Subject", subject, { subject = it })
        NoticeText(notice)
        if (!structured) {
            Field("Note", body, { body = it }, singleLine = false, minLines = 6)
            if (api.aiHas("draft") && body.isNotBlank()) SecondaryButton(if (aiBusy) "Cleaning up… this can take up to a minute" else "Clean up with AI") {
                if (aiBusy) return@SecondaryButton
                val sent = body
                scope.launch {
                    aiBusy = true; error = null
                    try {
                        val r = api.call("ai_draft", body = org.json.JSONObject().put("type", "note").put("text", sent).put("client_id", clientId))
                        when {
                            r.optBoolean("truncated") -> notice = "This note is too long to clean up in one go. Your text was kept. Split it into parts and try again."
                            body != sent -> notice = "The note changed while the AI was working, so the clean-up was not applied. Choose Clean up with AI again to use it."
                            else -> { if (original == null) original = sent; body = r.optString("text"); notice = "Note cleaned up. Review it before saving. Restore my original text puts back what you wrote." }
                        }
                    } catch (e: ApiException) { error = e.message } finally { aiBusy = false }
                }
            }
            original?.let { o -> SecondaryButton("Restore my original text") { body = o; original = null; notice = "Your original text is back." } }
        } else {
            val labels = NOTE_LABELS[format] ?: NOTE_LABELS.getValue("BIRP")
            if (api.aiHas("draft")) {
                Field("Rough notes for the AI to sort into sections", rough, { rough = it }, hint = "optional", singleLine = false, minLines = 3)
                if (rough.isNotBlank()) SecondaryButton(if (aiBusy) "Drafting… this can take up to a minute" else "Draft the $format sections") {
                    if (aiBusy) return@SecondaryButton
                    val before = sections
                    scope.launch {
                        aiBusy = true; error = null
                        try {
                            val r = api.call("ai_generate_note", body = org.json.JSONObject().put("client_id", clientId).put("note_format", format).put("text", rough))
                            if (sections != before) { notice = "The sections changed while the AI was working, so the draft was not applied."; return@launch }
                            val a = r.optJSONArray("sections")
                            if (before.any { it.isNotBlank() }) sectionsBefore = before
                            sections = List(4) { a?.optString(it) ?: "" }
                            questions = r.optJSONArray("questions").let { q -> List(q?.length() ?: 0) { q!!.optString(it) } }
                            homework = r.optJSONArray("homework").objects()
                            include = homework.indices.toSet()
                            notice = "$format sections drafted." + (if (questions.isNotEmpty()) " ${questions.size} things to double-check." else "") +
                                (if (homework.isNotEmpty()) " ${homework.size} homework items found." else "") + " Review before saving."
                        } catch (e: ApiException) { error = e.message } finally { aiBusy = false }
                    }
                }
            }
            sectionsBefore?.let { old -> SecondaryButton("Restore my sections") { sections = old; sectionsBefore = null; notice = "Your sections are back." } }
            labels.forEachIndexed { i, l -> Field(l, sections[i], { v -> sections = sections.toMutableList().also { it[i] = v } }, singleLine = false, minLines = 3) }
            if (questions.isNotEmpty()) { Heading("Before you save, double-check"); questions.forEach { Text("• $it") } }
            if (homework.isNotEmpty()) {
                Heading("Homework from the notes")
                val active = tracks.data ?: emptyList()
                if (active.isEmpty()) Text("Homework needs an active training track; none was found, so it will not be saved.")
                else if (active.size == 1 && hwTrack.isEmpty()) hwTrack = active[0].optInt("id").toString()
                if (active.isNotEmpty()) Choice("Homework goes on the track", hwTrack, listOf("" to "Choose a track") + active.map { it.optInt("id").toString() to it.optString("title") }) { hwTrack = it }
                homework.forEachIndexed { i, h -> Tick("Include: " + h.optString("title"), i in include) { on -> include = if (on) include + i else include - i } }
            }
            Field("Narrative", body, { body = it }, hint = "optional", singleLine = false, minLines = 3)
        }
        ErrorText(error)
        val ready = subject.isNotBlank() && (if (structured) sections.any { it.isNotBlank() } else body.isNotBlank())
        PrimaryButton("Save note", busy = busy, enabled = ready) {
            if (structured && include.isNotEmpty() && homework.isNotEmpty() && hwTrack.isEmpty() && (tracks.data ?: emptyList()).isNotEmpty()) {
                error = "Choose a track for the homework, or untick it."; return@PrimaryButton
            }
            scope.launchSave({ busy = it }, { error = it }) {
                val payload = org.json.JSONObject().put("client_id", clientId).put("note_type", type).put("note_format", format)
                    .put("subject", subject.trim()).put("idem_key", idem)
                if (structured) {
                    sections.forEachIndexed { i, v -> payload.put("sec${i + 1}", v.trim()) }
                    if (body.isNotBlank()) payload.put("body", body.trim())
                    val chosen = homework.filterIndexed { i, h -> i in include && h.optString("title").isNotBlank() }
                    if (chosen.isNotEmpty() && hwTrack.isNotEmpty()) {
                        payload.put("homework_track_id", hwTrack.toInt()).put("homework", org.json.JSONArray(chosen.map { org.json.JSONObject().put("title", it.optString("title")).put("instructions", it.optString("instructions")).put("due", it.optString("due")) }))
                    }
                } else payload.put("body", body.trim())
                Flash.pending = saveOrQueue(api, "add_note", payload, "Case note: " + subject.trim().take(40), "Case note saved.").message
                nav.popBackStack()
            }
        }
    }
}

/** Hours on a training track, with mileage; with no signal they are kept and sent later. */
@Composable
fun LogHoursScreen(api: Api, nav: NavHostController, clientId: Int, trackId: Int) {
    val tracks = rememberLoaded(trackId) { api.call("tracks").optJSONArray("tracks").objects() }
    var date by rememberSaveable { mutableStateOf(today()) }
    var hours by rememberSaveable { mutableStateOf("") }
    var miles by rememberSaveable { mutableStateOf("") }
    var description by rememberSaveable { mutableStateOf("") }
    var category by rememberSaveable { mutableStateOf("") }
    val signature = remember { SignatureState() }
    var signedName by rememberSaveable { mutableStateOf("") }
    val density = androidx.compose.ui.platform.LocalDensity.current.density
    val idem = rememberSaveable { java.util.UUID.randomUUID().toString() }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    val scope = androidx.compose.runtime.rememberCoroutineScope()
    Page {
        val t = tracks.data?.firstOrNull { it.optInt("id") == trackId }
        Heading(t?.let { it.optString("client") + ": " + it.optString("title") } ?: "Training track")
        val cats = t?.optJSONArray("visit_categories")?.let { a -> List(a.length()) { a.optString(it) } } ?: emptyList()
        if (cats.isNotEmpty()) Choice("Kind of visit", category, listOf("" to "Not chosen") + cats.map { it to it }) { category = it }
        Field("Date", date, { date = it }, hint = "year-month-day, such as ${today()}")
        Field("Hours", hours, { hours = it }, hint = "such as 1.5", keyboard = KeyboardType.Decimal)
        Field("Miles driven", miles, { miles = it }, hint = "0 if none", keyboard = KeyboardType.Decimal)
        Field("What was done", description, { description = it }, singleLine = false, minLines = 4)
        SignaturePad(signature)
        Field("Client's typed name", signedName, { signedName = it }, hint = "optional; counts as their acknowledgement")
        ErrorText(error)
        PrimaryButton("Log hours", busy = busy, enabled = hours.isNotBlank() && description.isNotBlank()) {
            val h = number(hours)
            val m = number(miles.ifBlank { "0" })
            when {
                h == null || h <= 0 || h > 24 -> error = "Enter the hours as a number from 0.25 to 24, such as 1.5."
                m == null || m < 0 || m > 1000 -> error = "Enter the miles as a number from 0 to 1000."
                !Regex("^\\d{4}-\\d{2}-\\d{2}$").matches(date.trim()) -> error = "Enter the date as year-month-day, such as ${today()}."
                else -> scope.launchSave({ busy = it }, { error = it }) {
                    val payload = org.json.JSONObject().put("track_id", trackId).put("hours", h).put("date", date.trim())
                        .put("mileage", m).put("description", description.trim()).put("idem_key", idem)
                    if (category.isNotEmpty()) payload.put("rsa_category", category)
                    signature.png(density)?.let { payload.put("signature_b64", it) }
                    if (signedName.isNotBlank()) payload.put("signed_name", signedName.trim().take(120))
                    Flash.pending = saveOrQueue(api, "log_hours", payload, "Hours: ${hoursText(h)} on " + (t?.optString("client") ?: "track $trackId"), "Hours logged.").message
                    nav.popBackStack()
                }
            }
        }
    }
}
