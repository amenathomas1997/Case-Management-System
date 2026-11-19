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
            // 4.34.0: as the website's client page.
            c.str("client_type")?.let { ListRow(listOf("Client type: $it")) }
            c.str("dob")?.let { ListRow(listOf("Date of birth: " + dayText(it))) }
            c.str("ipe_date")?.let { ListRow(listOf("IPE date: " + dayText(it) + (c.str("ipe_amended_on")?.let { a -> ", amended " + dayText(a) } ?: ""))) }
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

            Heading(org.casemanagement.app.notesHeading(detail.notes.size, detail.notesTotal))
            detail.notes.forEach { n -> NoteRow(n, onEdit = { nav.navigate("note/$id/edit/${n.id}") }, onAddendum = { nav.navigate("note/$id/addendum/${n.id}") }) }
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
    // 4.34.0: each refusal on its field (guardian_name, guardian_email).
    var fieldErrors by remember { mutableStateOf<Map<String, String>>(emptyMap()) }
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
            fieldErrors = emptyMap()
            scope.launchSave({ busy = it }, { error = it }) {
                // 4.28.0: the consent form is emailed once even when a reply was lost (see sendOnce).
                // 4.34.0: consent_seen names the request this form showed, so a colleague's newer one is not replaced.
                val b = org.json.JSONObject().put("client_id", clientId).put("guardian_name", name!!.trim()).put("guardian_email", email!!.trim())
                // Only from a server that sends pending_id (an older one would refuse every resend).
                if (consent?.has("pending_id") == true) b.put("consent_seen", consent.optInt("pending_id"))
                val res = try { api.callOnce("request_consent", b, idem, { idem = it }, "the consent request") } catch (e: ApiException) {
                    if (e.status == 409) loaded.reload()
                    if (e.status == 422) fieldErrors = serverFieldErrors(e.reply) ?: emptyMap()
                    throw e
                }
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
                Field("Guardian name", name ?: "", { name = it }, required = true, error = fieldErrors["guardian_name"])
                Field("Guardian email", email ?: "", { email = it }, keyboard = KeyboardType.Email, required = true, error = fieldErrors["guardian_email"])
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
private fun NoteRow(n: NoteItem, onEdit: () -> Unit = {}, onAddendum: () -> Unit = {}) {
    var open by remember { mutableStateOf(false) }
    val head = listOf(n.subject, n.noteType + ", " + whenText(n.createdAt) + (n.author?.let { ", by $it" } ?: ""))
    ListRow(head, action = if (open) "close the note" else "read the note", state = if (open) "expanded" else "collapsed", onClick = { open = !open })
    if (open) {
        Text(n.body)
        // 4.34.0: as on the website, an editable note can be edited; a locked one takes an addendum.
        if (n.canEdit) SecondaryButton("Edit note: ${n.subject}", onClick = onEdit)
        if (n.locked) SecondaryButton("Add addendum to ${n.subject}", onClick = onAddendum)
    }
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
 * 4.34.0: [editNoteId] edits a saved note (update_note); [addendumTo] adds an addendum to a locked
 * one. An edit refused because the note just locked becomes an addendum, keeping the text.
 */
@Composable
fun AddNoteScreen(api: Api, nav: NavHostController, clientId: Int, trackId: Int = 0, sessionId: Int = 0, presetSubject: String = "",
                  editNoteId: Int = 0, addendumTo: Int = 0) {
    val baseTypes = api.formList("note_types", listOf("Case Note", "Progress Report", "Monthly Summary", "Intake", "Addendum"))
    var type by rememberSaveable { mutableStateOf(if (addendumTo > 0) "Addendum" else baseTypes.first()) }
    var format by rememberSaveable { mutableStateOf("free") }
    // 4.31.0: a session's note starts with its subject ("Session on Oct 20") and is linked to the session.
    var subject by rememberSaveable { mutableStateOf(presetSubject) }
    val forSession = sessionId > 0
    var body by rememberSaveable { mutableStateOf("") }
    var original by rememberSaveable { mutableStateOf<String?>(null) }
    var sections by rememberSaveable { mutableStateOf(listOf("", "", "", "")) }
    var sectionsBefore by rememberSaveable { mutableStateOf<List<String>?>(null) }
    var rough by rememberSaveable { mutableStateOf("") }
    var questions by remember { mutableStateOf<List<String>>(emptyList()) }
    var homework by remember { mutableStateOf<List<org.json.JSONObject>>(emptyList()) }
    var include by remember { mutableStateOf<Set<Int>>(emptySet()) }
    var hwTrack by rememberSaveable { mutableStateOf("") }
    // 4.34.0: a refused homework due date, on the homework row it belongs to.
    var homeworkErrors by remember { mutableStateOf<Map<Int, String>>(emptyMap()) }
    // 4.34.0: "new", "edit" or "addendum"; the note edited or added to, and the track it is filed on.
    var mode by rememberSaveable { mutableStateOf(when { editNoteId > 0 -> "edit"; addendumTo > 0 -> "addendum"; else -> "new" }) }
    var parentId by rememberSaveable { mutableStateOf(if (addendumTo > 0) addendumTo else editNoteId) }
    var parentSubject by rememberSaveable { mutableStateOf("") }
    var noteTrack by rememberSaveable { mutableStateOf(trackId) }
    var noteVersion by rememberSaveable { mutableStateOf("") }
    var prefilled by rememberSaveable { mutableStateOf(editNoteId == 0 && addendumTo == 0) }
    // 4.34.0: a 409 conflict on an edit: Reload, and what was typed here kept below to copy.
    var conflictNote by rememberSaveable { mutableStateOf(false) }
    var reloading by remember { mutableStateOf(false) }
    var keptText by rememberSaveable { mutableStateOf<String?>(null) }
    val existing = rememberLoaded(clientId, editNoteId, addendumTo) {
        val wanted = if (editNoteId > 0) editNoteId else addendumTo
        if (wanted <= 0) null else parseClientDetail(api.call("client", mapOf("id" to clientId.toString())))?.notes?.firstOrNull { it.id == wanted }
            ?: throw ApiException("That note is not among this client's latest 50 notes. Open it on the website.")
    }
    androidx.compose.runtime.LaunchedEffect(existing.data) {
        val n = existing.data ?: return@LaunchedEffect
        if (prefilled) return@LaunchedEffect
        prefilled = true
        parentSubject = n.subject
        if (mode == "edit") {
            subject = n.subject; type = n.noteType.ifEmpty { type }; format = n.noteFormat.takeIf { it in listOf("free", "BIRP", "PAIP", "SOAP") } ?: "free"
            if (format == "free") body = n.body else sections = List(4) { n.sections.getOrNull(it) ?: "" }
            noteTrack = n.trackId ?: 0; noteVersion = n.noteVersion ?: ""
        } else {
            subject = org.casemanagement.app.addendumSubject(n.subject); type = "Addendum"
        }
    }
    val types = if (type.isNotEmpty() && type !in baseTypes) baseTypes + type else baseTypes
    val tracks = rememberLoaded(clientId) { if (api.can("clients")) api.call("tracks").optJSONArray("tracks").objects().filter { it.optInt("client_id") == clientId } else emptyList() }
    val idem = rememberSaveable { java.util.UUID.randomUUID().toString() }
    var busy by remember { mutableStateOf(false) }
    var aiBusy by remember { mutableStateOf(false) }
    var notice by remember { mutableStateOf<String?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    var bodyError by remember { mutableStateOf<String?>(null) }
    var presses by remember { androidx.compose.runtime.mutableIntStateOf(0) }
    val scope = androidx.compose.runtime.rememberCoroutineScope()
    val context = LocalContext.current
    val structured = format != "free"
    // 4.29.0: Back waits while the note is saving (it is sent or kept on the phone either way).
    HoldBackWhileSaving(busy)
    if ((editNoteId > 0 || addendumTo > 0) && !prefilled) {
        Show(existing, "the note") { }
        return
    }
    Page {
        // 4.34.0: what this form does, when it is not a new note.
        when (mode) {
            "edit" -> Heading("Edit case note: $parentSubject")
            "addendum" -> Heading("Add addendum to: $parentSubject")
        }
        val about: @Composable () -> Unit = {
            Choice("Note type", type, types.map { it to it }) { type = it }
            Choice("Format", format, listOf("free" to "Free text", "BIRP" to "BIRP", "PAIP" to "PAIP", "SOAP" to "SOAP")) { format = it; bodyError = null }
            Field("Subject", subject, { subject = it }, required = true)
        }
        // 4.31.0: opened from a completed session, the title ("Case note for Jane Doe, session on
        // Oct 20") is followed by the first thing to write; the type, format and the subject already
        // filled in come after it. Otherwise as before: type, format and subject first.
        if (!forSession) about()
        NoticeText(notice)
        // 4.34.0: the summary (an edit that became an addendum says so here) takes focus.
        ErrorText(error, focusKey = presses)
        if (mode == "edit" && conflictNote) SecondaryButton(if (reloading) "Reloading…" else "Reload the note, keeping my text below to copy") {
            if (reloading) return@SecondaryButton
            reloading = true
            val mine = org.casemanagement.app.typedNoteText(format, body, sections, NOTE_LABELS[format])
            scope.launch {
                try {
                    val n = parseClientDetail(api.call("client", mapOf("id" to clientId.toString())))?.notes?.firstOrNull { it.id == parentId }
                        ?: throw ApiException("That note could not be read again. Open it on the website.")
                    keptText = mine
                    subject = n.subject; type = n.noteType.ifEmpty { type }; format = n.noteFormat.takeIf { it in listOf("free", "BIRP", "PAIP", "SOAP") } ?: "free"
                    if (format == "free") { body = n.body; sections = listOf("", "", "", "") } else { body = ""; sections = List(4) { n.sections.getOrNull(it) ?: "" } }
                    noteTrack = n.trackId ?: 0; noteVersion = n.noteVersion ?: ""
                    conflictNote = false; error = null
                    notice = "The note is read again with the other changes. Your text is below to copy; make your changes again, then save."
                } catch (e: ApiException) { error = e.message } finally { reloading = false }
            }
        }
        keptText?.let { k ->
            Heading("Your text, not saved")
            androidx.compose.foundation.text.selection.SelectionContainer { Text(k) }
            SecondaryButton("Copy my text") { copyText(context, k); notice = "Your text is copied." }
            SecondaryButton("Hide my text") { keptText = null }
        }
        if (!structured) {
            Field("Note", body, { body = it; bodyError = null }, singleLine = false, minLines = 6, required = true, error = bodyError)
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
            if (homework.isNotEmpty() && mode != "edit") {
                Heading("Homework from the notes")
                val active = tracks.data ?: emptyList()
                if (active.isEmpty()) Text("Homework needs an active training track; none was found, so it will not be saved.")
                else if (active.size == 1 && hwTrack.isEmpty()) hwTrack = active[0].optInt("id").toString()
                if (active.isNotEmpty()) Choice("Homework goes on the track", hwTrack, listOf("" to "Choose a track") + active.map { it.optInt("id").toString() to it.optString("title") }) { hwTrack = it }
                homework.forEachIndexed { i, h -> Tick("Include: " + h.optString("title"), i in include, error = homeworkErrors[i]) { on -> include = if (on) include + i else include - i } }
            }
            Field(if (mode == "edit") "Narrative (optional; left blank keeps the narrative already saved)" else "Narrative", body, { body = it },
                hint = if (mode == "edit") null else "optional", singleLine = false, minLines = 3)
        }
        if (forSession) about()
        // 4.30.0: the AI's wait is said once when it starts.
        Announce(if (aiBusy) "Working… This can take up to a minute." else null, key = aiBusy)
        // 4.30.0: Save stays enabled; a press says what is still needed.
        val missing = stillNeeded(missingNames("Subject" to subject.isBlank(),
            (if (structured) "at least one section" else "Note") to (if (structured) sections.none { it.isNotBlank() } else body.isBlank())))
        PrimaryButton(when (mode) { "edit" -> "Save changes"; "addendum" -> "Save addendum"; else -> "Save note" }, busy = busy, missing = missing) {
            presses++
            if (busy) return@PrimaryButton
            // 4.34.0: the website refuses a free-text note whose sections still hold text (client.php:461).
            if (org.casemanagement.app.sectionsLost(format, sections)) {
                bodyError = org.casemanagement.app.FREE_TEXT_SECTIONS_LOST; error = org.casemanagement.app.FREE_TEXT_SECTIONS_LOST; return@PrimaryButton
            }
            if (mode != "edit" && structured && include.isNotEmpty() && homework.isNotEmpty() && hwTrack.isEmpty() && (tracks.data ?: emptyList()).isNotEmpty()) {
                error = "Choose a track for the homework, or untick it."; return@PrimaryButton
            }
            bodyError = null
            scope.launchSave({ busy = it }, { error = it }) {
                val payload = org.json.JSONObject().put("note_type", type).put("note_format", format).put("subject", subject.trim())
                if (structured) {
                    sections.forEachIndexed { i, v -> payload.put("sec${i + 1}", v.trim()) }
                    if (body.isNotBlank()) payload.put("body", body.trim())
                } else {
                    payload.put("body", body.trim())
                    // Sent when they hold text, so the server's own check applies too.
                    sections.forEachIndexed { i, v -> if (v.isNotBlank()) payload.put("sec${i + 1}", v.trim()) }
                }
                if (mode == "edit") {
                    payload.put("id", parentId).put("track_id", noteTrack)
                    if (noteVersion.isNotEmpty()) payload.put("note_version", noteVersion)
                    try {
                        val r = api.call("update_note", body = payload)
                        Flash.pending = if (r.optBoolean("unchanged")) "Nothing changed, so the note was not saved again." else r.optString("message").ifEmpty { "Case note saved." }
                        nav.popBackStack()
                    } catch (e: ApiException) {
                        when (org.casemanagement.app.noteEditOutcome(e.status, e.reply)) {
                            "addendum" -> {
                                // As the website: the text moves into an addendum to the note, nothing is lost.
                                mode = "addendum"; type = "Addendum"; subject = org.casemanagement.app.addendumSubject(subject)
                                error = org.casemanagement.app.lockedToAddendumMessage(e.message)
                                return@launchSave
                            }
                            // Someone saved it since: Reload is offered, keeping this text to copy.
                            "conflict" -> conflictNote = true
                        }
                        throw e
                    }
                    return@launchSave
                }
                payload.put("client_id", clientId).put("idem_key", idem)
                if (mode == "addendum") payload.put("addendum_to", parentId)
                // Written from a training track (4.26.0): the note is filed on that track.
                if (noteTrack > 0) payload.put("track_id", noteTrack)
                // 4.31.0: the session it is the note for (kept with it when saved offline); the server
                // marks the session's note written and completes its reminder task.
                if (forSession) payload.put("session_id", sessionId)
                var sentRows = emptyList<Int>()
                if (structured && mode == "new") {
                    // 4.34.0: which homework rows were sent, in order: the server numbers them among those sent.
                    sentRows = homework.indices.filter { i -> i in include && homework[i].optString("title").isNotBlank() }
                    val chosen = sentRows.map { homework[it] }
                    if (chosen.isNotEmpty() && hwTrack.isNotEmpty()) {
                        payload.put("homework_track_id", hwTrack.toInt()).put("homework", org.json.JSONArray(chosen.map { org.json.JSONObject().put("title", it.optString("title")).put("instructions", it.optString("instructions")).put("due", it.optString("due")) }))
                    }
                }
                val savedWords = if (mode == "addendum") "Addendum saved." else "Case note saved."
                val saved = try { saveOrQueue(api, "add_note", payload, (if (mode == "addendum") "Addendum: " else "Case note: ") + subject.trim().take(40), savedWords) }
                    catch (e: ApiException) { homeworkErrors = org.casemanagement.app.homeworkRowErrors(e.reply, sentRows); throw e }
                // 4.34.0: the homework added and the session warning, as the website says them.
                Flash.pending = org.casemanagement.app.noteSavedMessage(saved.reply, savedWords) ?: saved.message
                nav.popBackStack()
            }
        }
    }
}

/**
 * 4.34.0: the hours rule of the website's Log hours: more than 0 and no more than 24, two decimals
 * at most, or 0 with miles for travel only. Null when [hours] is fine.
 */
fun hoursRule(hours: Double?, miles: Double?): String? {
    val msg = "Enter the hours worked as a number more than 0 and no more than 24, such as 1.5, or 0 with miles for travel only."
    if (hours == null || hours < 0 || hours > 24) return msg
    if (Math.round(hours * 100) / 100.0 != hours) return "Enter the hours with at most two decimals, such as 1.25."
    if (hours == 0.0 && (miles ?: 0.0) <= 0) return msg
    return null
}

/** 4.34.0: log_hours' errors keys on the form's fields (the server's mileage is the form's miles). */
fun logHoursFieldErrors(reply: org.json.JSONObject?): Map<String, String> =
    (serverFieldErrors(reply) ?: emptyMap()).mapKeys { (k, _) -> if (k == "mileage") "miles" else k }

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
        Field("Hours", hours, { hours = it }, hint = "such as 1.5, or 0 with miles for travel only", keyboard = KeyboardType.Decimal, error = fieldErrors["hours"], required = true)
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
                hoursRule(h, m)?.let { "hours" to it },
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
                    val saved = try { saveOrQueue(api, "log_hours", payload, "Hours: ${hoursText(h)} on " + (t?.client ?: "track $trackId"), "Hours logged.") }
                    catch (e: ApiException) { fieldErrors = logHoursFieldErrors(e.reply); throw e }
                    // 4.34.0: the website's reminder when the track has no homework.
                    Flash.pending = listOfNotNull(saved.message, saved.reply?.str("reminder")).joinToString(" ")
                    nav.popBackStack()
                }
            }
        }
    }
}
