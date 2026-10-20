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
import org.casemanagement.app.NoteItem
import org.casemanagement.app.parseClientDetail
import org.casemanagement.app.parseClientHistory
import org.casemanagement.app.parseClients
import org.casemanagement.app.parseTracks
import kotlinx.coroutines.launch
import org.casemanagement.app.objects
import org.casemanagement.app.str
import org.casemanagement.app.zoneWords
import org.casemanagement.app.listTotal
import org.casemanagement.app.countLabel

/** The client list, searched on the server as on the website. */
@Composable
fun ClientsScreen(api: Api, nav: NavHostController) {
    var q by rememberSaveable { mutableStateOf("") }
    var search by rememberSaveable { mutableStateOf("") }
    // The whole list is kept on the phone for reading without a signal (a search is not).
    val state = rememberLoaded(search) {
        // 4.29.0: with the server's total, so a list cut at its limit says so.
        if (search.isEmpty()) api.callCached("clients", cacheKey = "clients").let { (r, at) -> parseClients(r).let { l -> Triple(l, listTotal(r, l.size), at) } }
        else api.call("clients", mapOf("q" to search)).let { r -> parseClients(r).let { l -> Triple(l, listTotal(r, l.size), null) } }
    }
    Page {
        // The server searches the name, email and phone (4.29.0), as the hint says.
        Field("Search clients", q, { q = it }, hint = "name, phone or email")
        SecondaryButton("Search") { search = q.trim() }
        SecondaryButton("Add a client") { nav.navigate("clientEdit/0") }
        Show(state, "clients") { (list, total, savedAt) ->
            OfflineCopyNotice(savedAt)
            Announce(if (search.isNotEmpty()) (if (total > list.size) "First ${list.size} of $total clients found" else "${list.size} ${if (list.size == 1) "client" else "clients"} found") else null)
            Heading(countLabel(if (search.isEmpty()) "All clients" else "Clients matching $search", list.size, total, "first"))
            if (total > list.size) Text("Showing the first ${list.size} of $total, by name. Search to find the others.")
            if (list.isEmpty()) Text("No clients found.")
            list.forEach { c ->
                ListRow(listOf(c.name, c.status, c.phone ?: ""), onClick = { nav.navigate("client/${c.id}") })
            }
        }
    }
}

/** One client: contact details, training tracks with their hours, and case notes. */
@Composable
fun ClientScreen(api: Api, nav: NavHostController, id: Int) {
    val context = LocalContext.current
    val state = rememberLoaded(id) { api.callCached("client", mapOf("id" to id.toString()), "client.$id").also { (r, at) -> if (at == null) api.noteClientDetail(r) } }
    var linkError by remember { mutableStateOf<String?>(null) }
    var notice by remember { mutableStateOf<String?>(null) }
    var portalAsk by remember { mutableStateOf(false) }
    // 4.28.0: the portal link is emailed once even when a reply was lost (see sendOnce).
    var portalKey by rememberSaveable { mutableStateOf("") }
    var consentAsk by remember { mutableStateOf(false) }
    val scope = androidx.compose.runtime.rememberCoroutineScope()
    Show(state, "the client") { (r, savedAt) ->
        val detail = parseClientDetail(r) ?: return@Show
        val c = detail.raw
        Page {
            Heading(detail.name, large = true)
            OfflineCopyNotice(savedAt)
            ListRow(listOf("Status: " + detail.status))
            detail.phone?.let { p -> ListRow(listOf("Phone: $p"), action = "call", onClick = {
                if (!openLink(context, Intent(Intent.ACTION_DIAL, Uri.parse("tel:" + p.filter { it.isDigit() || it == '+' })))) linkError = "This phone has no app for calls."
            }) }
            detail.email?.let { e -> ListRow(listOf("Email: $e"), action = "write an email", onClick = {
                if (!openLink(context, Intent(Intent.ACTION_SENDTO, Uri.parse("mailto:" + Uri.encode(e))))) linkError = "This phone has no email app."
            }) }
            NoticeText(notice)
            ErrorText(linkError)
            c.str("address")?.let { ListRow(listOf("Address: $it")) }
            c.str("state")?.let { code -> ListRow(listOf("State: " + (US_STATES.firstOrNull { it.first == code }?.second ?: code))) }
            zoneWords(c.str("time_zone"))?.let { ListRow(listOf("Time zone: $it")) }
            c.str("office")?.let { ListRow(listOf("Office: $it")) }
            c.str("school")?.let { ListRow(listOf("School: $it")) }
            c.str("vr_case_number")?.let { ListRow(listOf("VR case number: $it")) }
            c.str("ipe_goal")?.let { ListRow(listOf("IPE goal: $it")) }
            c.str("guardian_name")?.let { g -> ListRow(listOf("Guardian: $g", c.str("guardian_phone") ?: "", c.str("guardian_email") ?: "")) }
            c.str("counselor_name")?.let { n -> ListRow(listOf("Counselor: $n", c.str("counselor_agency") ?: "", c.str("counselor_email") ?: "", c.str("counselor_phone") ?: "")) }
            val a11y = listOfNotNull(c.str("a11y_format")?.let { "Prefers $it" }, c.str("a11y_primary_tech"), c.str("a11y_contact")?.let { "Contact by $it" })
            if (a11y.isNotEmpty()) ListRow(listOf("Accessibility: " + a11y.joinToString(", ")))
            if (c.optBoolean("a11y_screen_reader")) ListRow(listOf("Uses a screen reader"))
            c.str("a11y_notes")?.let { ListRow(listOf("Accessibility notes: $it")) }
            if (detail.customFields.isNotEmpty()) {
                Heading("More details")
                detail.customFields.forEach { (label, value) -> ListRow(listOf("$label: $value")) }
            }

            PrimaryButton("Add a case note") { nav.navigate("note/$id") }
            SecondaryButton("Documents and homework") { nav.navigate("docs/$id") }
            SecondaryButton("Edit client details") { nav.navigate("clientEdit/$id") }
            SecondaryButton("Message") { nav.navigate("thread/$id") }
            val waiting = detail.signaturesWaiting
            // A server before 4.25.0 has neither (its client detail has no device_counts).
            if (detail.has425) {
                SecondaryButton("Documents to sign" + if (waiting > 0) " ($waiting waiting)" else "") { nav.navigate("signatures/$id") }
                SecondaryButton("Request a release") { nav.navigate("release/$id") }
            }
            if (detail.email != null) SecondaryButton("Email a new portal link") { portalAsk = true }
            SecondaryButton("Request guardian consent") { consentAsk = true }
            SecondaryButton("Status history") { nav.navigate("history/$id") }
            if (listOf("recommend", "progress", "intake", "draft").any { api.aiHas(it) }) SecondaryButton("AI help for this client") { nav.navigate("clientAi/$id") }
            if (api.can("clients") && api.aiHas("briefing")) SecondaryButton("AI case briefing") { nav.navigate("briefing/$id") }
            if (api.aiHas("voice") && api.can("clients")) SecondaryButton("Voice assistant") { nav.navigate("voice/$id/" + android.net.Uri.encode(detail.name.ifEmpty { "-" })) }
            SecondaryButton("Scored assessments") { nav.navigate("scoredList/$id") }
            SecondaryButton("Curriculum competencies") { nav.navigate("competency/$id") }

            val tracks = detail.tracks
            Heading("Training tracks (${tracks.size})")
            if (tracks.isEmpty()) Text("No training tracks.")
            tracks.forEach { t ->
                val auth = t.authorizedHours
                val worked = t.hoursWorked
                ListRow(
                    listOf(t.title, t.status + (t.authNumber?.let { ", authorization $it" } ?: ""),
                        if (auth > 0) "${hoursText(worked)} of ${hoursText(auth)} hours used" + (t.expirationDate?.let { ", ends " + dayText(it) } ?: "") else "${hoursText(worked)} hours logged"),
                    action = "open it",
                    onClick = { nav.navigate("track/$id/${t.id}") },
                )
            }
            SecondaryButton("Add a training track") { nav.navigate("trackEdit/$id/0") }

            val releases = detail.releases
            Heading("Releases of information (${releases.size})")
            if (releases.isEmpty()) Text("No releases.")
            releases.forEach { rel ->
                ListRow(listOf(rel.recipientName + (rel.recipientType?.let { " ($it)" } ?: ""),
                    listOfNotNull(rel.status, rel.expiresOn?.let { "ends " + dayText(it) }).joinToString(", "), rel.infoScope ?: ""))
            }

            Heading("Case notes (${detail.notesTotal})")
            detail.notes.forEach { n -> NoteRow(n) }
        }
        if (portalAsk) Confirm("Email a new portal link?", "A new link to the client portal is emailed to ${detail.email ?: ""}. Any earlier link stops working.", "Email the link", {
            notice = null; linkError = null
            scope.launch {
                try { notice = api.callOnce("send_portal_link", org.json.JSONObject().put("client_id", id), portalKey, { portalKey = it }, "the portal link").optString("message").ifEmpty { "Portal link emailed." } }
                catch (e: ApiException) { linkError = e.message }
            }
        }, { portalAsk = false })
        if (consentAsk) ConsentDialog(api, id, onDone = { m -> consentAsk = false; notice = m; state.reload() }, onCancel = { consentAsk = false })
    }
}

/** Guardian consent (client.php's request form): its state now, and a request emailed to the guardian. */
@Composable
private fun ConsentDialog(api: Api, clientId: Int, onDone: (String) -> Unit, onCancel: () -> Unit) {
    val loaded = rememberLoaded(clientId) { api.call("request_consent", mapOf("client_id" to clientId.toString())) }
    var name by remember { mutableStateOf<String?>(null) }
    var email by remember { mutableStateOf<String?>(null) }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    var idem by rememberSaveable { mutableStateOf("") }
    val scope = androidx.compose.runtime.rememberCoroutineScope()
    val r = loaded.data
    val consent = r?.optJSONObject("consent")
    val canRequest = consent?.optBoolean("can_request", true) ?: false
    androidx.compose.runtime.LaunchedEffect(r) {
        if (r != null) { if (name == null) name = r.optString("guardian_name"); if (email == null) email = r.optString("guardian_email") }
    }
    FormDialog("Guardian consent", consent?.str("request_label")?.let { if (it.contains("Resend")) "Resend" else "Send" } ?: "Send", busy,
        enabled = r != null && canRequest, missing = stillNeeded(missingNames("Guardian name" to name.isNullOrBlank(), "Guardian email" to email.isNullOrBlank())), onSave = {
            scope.launchSave({ busy = it }, { error = it }) {
                // 4.28.0: the consent form is emailed once even when a reply was lost (see sendOnce).
                val res = api.callOnce("request_consent", org.json.JSONObject().put("client_id", clientId).put("guardian_name", name!!.trim()).put("guardian_email", email!!.trim()),
                    idem, { idem = it }, "the consent request")
                onDone(res.optString("message").ifEmpty { "Consent form emailed." })
            }
        }, onCancel = onCancel) {
        if (loaded.loading && r == null) Text("Loading the consent…")
        ErrorText(loaded.error, takeFocus = false)
        if (r != null) {
            val st = consent?.optString("state") ?: "None"
            Text(when (st) {
                "Signed" -> "Consent signed" + (consent?.str("signed_name")?.let { " by $it" } ?: "") + (consent?.str("expires")?.let { ", current until " + dayText(it) } ?: "") + "."
                "Requested" -> "Consent requested " + whenText(consent?.str("pending_sent_at")) + ", not signed yet."
                "Expired" -> "The signed consent has expired."
                "Withdrawn" -> "The consent was withdrawn."
                else -> "No consent on record."
            })
            if (!canRequest) Text("A new request is offered 30 days before the consent ends.")
            else {
                Field("Guardian name", name ?: "", { name = it }, required = true)
                Field("Guardian email", email ?: "", { email = it }, keyboard = KeyboardType.Email, required = true)
            }
        }
        ErrorText(error)
    }
}

/** A client's status changes, newest first (client.php's history tab). */
@Composable
fun StatusHistoryScreen(api: Api, clientId: Int) {
    val state = rememberLoaded(clientId) { parseClientHistory(api.call("client_history", mapOf("client_id" to clientId.toString()))) }
    Show(state, "the status history") { list ->
        Page {
            Heading("Status changes (${list.size})")
            if (list.isEmpty()) Text("No status changes recorded.")
            list.forEach { h ->
                ListRow(listOf(h.newStatus + (h.oldStatus?.let { ", from $it" } ?: ""),
                    whenText(h.changedAt) + ", by " + h.changedBy, h.note ?: ""))
            }
        }
    }
}

@Composable
private fun NoteRow(n: NoteItem) {
    var open by remember { mutableStateOf(false) }
    val head = listOf(n.subject, n.noteType + ", " + whenText(n.createdAt) + (n.author?.let { ", by $it" } ?: ""))
    ListRow(head, action = if (open) "close the note" else "read the note", state = if (open) "expanded" else "collapsed", onClick = { open = !open })
    if (open) Text(n.body)
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
fun AddNoteScreen(api: Api, nav: NavHostController, clientId: Int, trackId: Int = 0) {
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
    // 4.29.0: Back waits while the note is saving (it is sent or kept on the phone either way).
    HoldBackWhileSaving(busy)
    Page {
        Choice("Note type", type, types.map { it to it }) { type = it }
        Choice("Format", format, listOf("free" to "Free text", "BIRP" to "BIRP", "PAIP" to "PAIP", "SOAP" to "SOAP")) { format = it }
        Field("Subject", subject, { subject = it }, required = true)
        NoticeText(notice)
        if (!structured) {
            Field("Note", body, { body = it }, singleLine = false, minLines = 6, required = true)
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
        // 4.30.0: the AI's wait is said once when it starts.
        Announce(if (aiBusy) "Working… This can take up to a minute." else null, key = aiBusy)
        ErrorText(error)
        // 4.30.0: Save stays enabled; a press says what is still needed.
        val missing = stillNeeded(missingNames("Subject" to subject.isBlank(),
            (if (structured) "at least one section" else "Note") to (if (structured) sections.none { it.isNotBlank() } else body.isBlank())))
        PrimaryButton("Save note", busy = busy, missing = missing) {
            if (structured && include.isNotEmpty() && homework.isNotEmpty() && hwTrack.isEmpty() && (tracks.data ?: emptyList()).isNotEmpty()) {
                error = "Choose a track for the homework, or untick it."; return@PrimaryButton
            }
            scope.launchSave({ busy = it }, { error = it }) {
                val payload = org.json.JSONObject().put("client_id", clientId).put("note_type", type).put("note_format", format)
                    .put("subject", subject.trim()).put("idem_key", idem)
                // Written from a training track (4.26.0): the note is filed on that track.
                if (trackId > 0) payload.put("track_id", trackId)
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
    val tracks = rememberLoaded(trackId) { parseTracks(api.call("tracks")) }
    var date by rememberSaveable { mutableStateOf(today()) }
    var hours by rememberSaveable { mutableStateOf("") }
    var miles by rememberSaveable { mutableStateOf("") }
    var description by rememberSaveable { mutableStateOf("") }
    var category by rememberSaveable { mutableStateOf("") }
    // The drawn signature too is kept across rotation and process death (4.28.0).
    val signature = rememberSignatureState()
    var signedName by rememberSaveable { mutableStateOf("") }
    val density = androidx.compose.ui.platform.LocalDensity.current.density
    val idem = rememberSaveable { java.util.UUID.randomUUID().toString() }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    // 4.28.0: each problem on its own field as well as in the summary.
    var fieldErrors by remember { mutableStateOf<Map<String, String>>(emptyMap()) }
    var presses by remember { androidx.compose.runtime.mutableIntStateOf(0) }
    val scope = androidx.compose.runtime.rememberCoroutineScope()
    HoldBackWhileSaving(busy)
    Page {
        val t = tracks.data?.firstOrNull { it.id == trackId }
        Heading(t?.let { it.client + ": " + it.title } ?: "Training track")
        val cats = t?.visitCategories ?: emptyList()
        if (cats.isNotEmpty()) Choice("Kind of visit", category, listOf("" to "Not chosen") + cats.map { it to it }) { category = it }
        Field("Date", date, { date = it }, hint = "year-month-day, such as ${today()}", error = fieldErrors["date"], required = true)
        Field("Hours", hours, { hours = it }, hint = "such as 1.5", keyboard = KeyboardType.Decimal, error = fieldErrors["hours"], required = true)
        Field("Miles driven", miles, { miles = it }, hint = "0 if none", keyboard = KeyboardType.Decimal, error = fieldErrors["miles"])
        Field("What was done", description, { description = it }, singleLine = false, minLines = 4, error = fieldErrors["description"], required = true)
        SignaturePad(signature)
        Field("Client's typed name", signedName, { signedName = it }, hint = "optional; counts as their acknowledgement")
        ErrorText(error, focusKey = presses)
        PrimaryButton("Log hours", busy = busy) {
            presses++
            val h = number(hours)
            val m = number(miles.ifBlank { "0" })
            fieldErrors = listOfNotNull(
                if (!Regex("^\\d{4}-\\d{2}-\\d{2}$").matches(date.trim())) "date" to "Enter the date as year-month-day, such as ${today()}." else null,
                if (h == null || h <= 0 || h > 24) "hours" to "Enter the hours as a number from 0.25 to 24, such as 1.5." else null,
                if (m == null || m < 0 || m > 1000) "miles" to "Enter the miles as a number from 0 to 1000." else null,
                if (description.isBlank()) "description" to "Say what was done." else null,
            ).toMap()
            when {
                busy -> {}
                h == null || m == null || fieldErrors.isNotEmpty() -> error = errorSummary(fieldErrors.values)
                else -> scope.launchSave({ busy = it }, { error = it }) {
                    val payload = org.json.JSONObject().put("track_id", trackId).put("hours", h).put("date", date.trim())
                        .put("mileage", m).put("description", description.trim()).put("idem_key", idem)
                    if (category.isNotEmpty()) payload.put("rsa_category", category)
                    signature.png(density)?.let { payload.put("signature_b64", it) }
                    if (signedName.isNotBlank()) payload.put("signed_name", signedName.trim().take(120))
                    Flash.pending = saveOrQueue(api, "log_hours", payload, "Hours: ${hoursText(h)} on " + (t?.client ?: "track $trackId"), "Hours logged.").message
                    nav.popBackStack()
                }
            }
        }
    }
}
