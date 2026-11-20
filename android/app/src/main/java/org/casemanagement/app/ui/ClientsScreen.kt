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

/** The client list, searched on the server as on the website. 4.35.0: with the website's filters. */
@Composable
fun ClientsScreen(api: Api, nav: NavHostController) {
    var q by rememberSaveable { mutableStateOf("") }
    var search by rememberSaveable { mutableStateOf("") }
    // 4.35.0: the filters (the caseload is null until chosen here: the server then uses the remembered one).
    var fStatus by rememberSaveable { mutableStateOf("") }
    var fWho by rememberSaveable { mutableStateOf<String?>(null) }
    var fType by rememberSaveable { mutableStateOf("") }
    var fAgency by rememberSaveable { mutableStateOf("") }
    var fSchool by rememberSaveable { mutableStateOf("") }
    var fOffice by rememberSaveable { mutableStateOf("") }
    var filtersOpen by rememberSaveable { mutableStateOf(false) }
    var applied by remember { androidx.compose.runtime.mutableIntStateOf(0) }
    var filterError by remember { mutableStateOf<String?>(null) }
    // The filter lists and the caseload in use, from the last reply that had them.
    var filterInfo by remember { mutableStateOf<org.json.JSONObject?>(null) }
    val scope = androidx.compose.runtime.rememberCoroutineScope()
    val filters = mapOf("status" to fStatus, "who" to fWho, "type" to fType, "agency" to fAgency, "school" to fSchool, "office" to fOffice)
    val whoDefault = if (api.scoped) "me" else ""
    val shownWho = fWho ?: filterInfo?.str("who") ?: whoDefault
    // The caseload counts as a filter whenever it is not Everyone's clients (as the iPhone app).
    val on = filtersOnCount(filters + ("who" to shownWho), "")
    val query = clientsQuery(filters, search)
    // The whole list is kept on the phone for reading without a signal (a search or a filter is not).
    val state = rememberLoaded(query.toString()) {
        // 4.29.0: with the server's total, so a list cut at its limit says so.
        val (r, at) = if (search.isEmpty() && filtersOnCount(filters, whoDefault) == 0) api.callCached("clients", query, cacheKey = "clients") else api.call("clients", query) to null
        r.optJSONObject("filters")?.let { filterInfo = it }
        parseClients(r).let { l -> Triple(l, listTotal(r, l.size), at) }
    }
    Page {
        // The server searches the name, email and phone (4.29.0), as the hint says.
        Field("Search clients", q, { q = it }, hint = "name, phone or email")
        SecondaryButton("Search") { search = q.trim() }
        // 4.35.0: only from a server that sends the filters (an older one has none).
        if (filterInfo != null) SecondaryButton(if (on > 0) "Filters, $on on" else "Filters") { filterError = null; filtersOpen = true }
        SecondaryButton("Add a client") { nav.navigate("clientEdit/0") }
        ErrorText(filterError)
        Show(state, "clients") { (list, total, savedAt) ->
            OfflineCopyNotice(savedAt)
            Announce(if (search.isNotEmpty()) (if (total > list.size) "First ${list.size} of $total clients found" else "${list.size} ${if (list.size == 1) "client" else "clients"} found") else null)
            // 4.35.0: the count is said once after the filters are applied.
            if (applied > 0 && search.isEmpty()) Announce("$total ${if (total == 1) "client matches" else "clients match"} the filters.", key = "$applied:$total")
            Heading(clientsHeading(list.size, total, on > 0, search))
            clientsCutText(list.size, total, on > 0)?.let { Text(it) }
            if (list.isEmpty()) Text("No clients found.")
            list.forEach { c ->
                ListRow(listOf(c.name, c.status, c.phone ?: ""), onClick = { nav.navigate("client/${c.id}") })
            }
        }
    }
    val info = filterInfo
    if (filtersOpen && info != null) ClientFiltersDialog(info, filters + ("who" to shownWho), offices = info.optJSONArray("offices").objects(),
        whoDefault = whoDefault, onApply = { f ->
            filtersOpen = false; filterError = null
            val who = f["who"] ?: whoDefault
            fun apply() {
                fStatus = f["status"] ?: ""; fType = f["type"] ?: ""; fAgency = f["agency"] ?: ""; fSchool = f["school"] ?: ""; fOffice = f["office"] ?: ""
                if (who != shownWho || fWho != null) fWho = who
                applied++
            }
            // The caseload is remembered for the website too (the same setting), as the website's filter form does.
            if (who != shownWho) scope.launch {
                try { api.call("clients_filter", body = org.json.JSONObject().put("who", who)) } catch (e: ApiException) { filterError = e.message }
                apply()
            } else apply()
        }, onCancel = { filtersOpen = false })
}

/** 4.35.0: the website's client list filters, in its order. */
@Composable
private fun ClientFiltersDialog(info: org.json.JSONObject, current: Map<String, String?>, offices: List<org.json.JSONObject>, whoDefault: String,
                                onApply: (Map<String, String>) -> Unit, onCancel: () -> Unit) {
    var status by rememberSaveable { mutableStateOf(current["status"] ?: "") }
    var who by rememberSaveable { mutableStateOf(current["who"] ?: whoDefault) }
    var type by rememberSaveable { mutableStateOf(current["type"] ?: "") }
    var agency by rememberSaveable { mutableStateOf(current["agency"] ?: "") }
    var school by rememberSaveable { mutableStateOf(current["school"] ?: "") }
    var office by rememberSaveable { mutableStateOf(current["office"] ?: "") }
    fun list(key: String) = info.optJSONArray(key)?.let { a -> List(a.length()) { a.optString(it) } }?.filter { it.isNotEmpty() } ?: emptyList()
    val whoChoices = info.optJSONArray("who_choices").objects().map { it.optString("value") to it.optString("label") }
    FormDialog("Filters", "Apply filters", busy = false, onSave = {
        onApply(mapOf("status" to status, "who" to who, "type" to type, "agency" to agency, "school" to school.trim(), "office" to office))
    }, onCancel = onCancel) {
        Choice("Case status", status, listOf("" to "All statuses") + list("statuses").map { it to it }) { status = it }
        if (whoChoices.isNotEmpty()) Choice("Caseload", who, whoChoices + listOfNotNull((who to who).takeIf { p -> whoChoices.none { it.first == p.first } })) { who = it }
        Choice("Client type", type, listOf("" to "All types") + list("types").map { it to it }) { type = it }
        Choice("Funding agency", agency, listOf("" to "All agencies") + list("agencies").map { it to it }) { agency = it }
        Field("School", school, { school = it }, hint = "contains")
        if (offices.isNotEmpty()) Choice("Office", office, listOf("" to "All offices") + offices.map { it.optInt("id").toString() to it.optString("name") }) { office = it }
        // Clears every filter and shows the list at once (the caseload goes back to the account's default).
        SecondaryButton("Clear filters") {
            onApply(mapOf("status" to "", "who" to whoDefault, "type" to "", "agency" to "", "school" to "", "office" to ""))
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
    // 4.35.0: older notes loaded under the newest 50 (kept while the screen is open; a reload of the
    // record drops them, and says so once). Not kept across process death: a page of notes is too
    // large for the saved state.
    var olderNotes by remember(id) { mutableStateOf<List<NoteItem>>(emptyList()) }
    var olderBusy by remember { mutableStateOf(false) }
    var olderError by remember { mutableStateOf<String?>(null) }
    var olderSaid by remember { mutableStateOf<String?>(null) }
    var lastTotal by remember { androidx.compose.runtime.mutableIntStateOf(-1) }
    androidx.compose.runtime.LaunchedEffect(state.data) {
        val r = state.data?.first ?: return@LaunchedEffect
        val total = r.optInt("notes_total", -1)
        // A reload keeps the older notes already loaded (the newest page is merged over them). Only when
        // notes were removed meanwhile are they dropped, and that is said once unless a save's message is showing.
        if (olderNotes.isNotEmpty() && lastTotal >= 0 && total in 0 until lastTotal) {
            olderNotes = emptyList()
            if (notice == null && Flash.message == null) olderSaid = "The notes were refreshed. Load older notes again if you need them."
        }
        lastTotal = total
    }
    Announce(olderSaid)
    Show(state, "the client") { (r, savedAt) ->
        val detail = parseClientDetail(r) ?: return@Show
        val c = detail.raw
        Page {
            Heading(detail.name, large = true)
            OfflineCopyNotice(savedAt)
            // 4.35.0: as the website, the On Hold reason and return date come first.
            if (detail.status == "On Hold") onHoldLine(c.str("on_hold_reason"), c.str("on_hold_until"))?.let { ListRow(listOf(it)) }
            ListRow(listOf("Status: " + detail.status))
            // 4.34.0: as the website's client page.
            c.str("client_type")?.let { ListRow(listOf("Client type: $it")) }
            c.str("dob")?.let { ListRow(listOf("Date of birth: " + dayText(it))) }
            // 4.35.0: attendance and vision, as the website's Demographics.
            attendanceText(c.optJSONObject("attendance") ?: r.optJSONObject("attendance"))?.let { ListRow(listOf(it)) }
            visionText(c.str("vision_status"), if (c.has("vision_onset_age") && !c.isNull("vision_onset_age")) c.optInt("vision_onset_age") else null)?.let { ListRow(listOf(it)) }
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
            c.str("preferred_language")?.let { l -> if (l == "es") ListRow(listOf("Preferred language: Spanish")) }
            c.str("created_at")?.let { ListRow(listOf("Client since " + dayText(it))) }
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
            // 4.37.0 (S14, S18): letters from the record, and its timeline (from a server that has them).
            if (api.can("clients") && (c.has("portal_link") || r.has("portal_link"))) SecondaryButton("Write a letter") { nav.navigate("letters/$id") }
            SecondaryButton("Request guardian consent") { consentAsk = true }
            SecondaryButton("Status history") { nav.navigate("history/$id") }
            if (c.has("portal_link") || r.has("portal_link")) ListRow(listOf("Timeline"), action = "open it", onClick = { nav.navigate("timeline/$id") })
            if (listOf("recommend", "progress", "intake", "draft", "remediate").any { api.aiHas(it) }) SecondaryButton("AI help for this client") { nav.navigate("clientAi/$id") }
            if (api.can("clients") && api.aiHas("briefing")) SecondaryButton("AI case briefing") { nav.navigate("briefing/$id") }
            if (api.aiHas("voice") && api.can("clients")) SecondaryButton("Voice assistant") { nav.navigate("voice/$id/" + android.net.Uri.encode(detail.name.ifEmpty { "-" })) }
            SecondaryButton("Tech assessments") { nav.navigate("assessments/$id") }
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

            // 4.37.0 (S15, S17): each release's state and Record withdrawn, the privacy notice, and the portal link.
            ReleasesSection(api, id, r) { m -> notice = m; state.reload() }
            PrivacyNoticeSection(api, id, r) { m -> notice = m; state.reload() }
            PortalLinkSection(api, id, detail.name, r) { m -> notice = m; state.reload() }

            // 4.35.0: after closure, the outcome survey and the follow-up checks (from a server that sends them).
            (r.optJSONObject("after_closure") ?: c.optJSONObject("after_closure"))?.let { a ->
                if (afterClosureShown(a)) AfterClosureSection(api, id, detail.name, a, onChanged = { m -> notice = m; state.reload() })
            }

            val notes = mergeNotePages(detail.notes, olderNotes)
            Heading(org.casemanagement.app.notesHeading(notes.size, detail.notesTotal))
            notes.forEach { n -> NoteRow(n, onEdit = { nav.navigate("note/$id/edit/${n.id}") }, onAddendum = { nav.navigate("note/$id/addendum/${n.id}") }) }
            // 4.35.0: the older notes, a page at a time, as the iPhone app.
            if (detail.notesTotal > notes.size && savedAt == null) {
                SecondaryButton(if (olderBusy) "Loading older notes…" else olderNotesLabel(detail.notesTotal, notes.size)) {
                    if (olderBusy) return@SecondaryButton
                    olderBusy = true; olderError = null; olderSaid = null
                    scope.launch {
                        try {
                            val page = parseClientDetail(api.call("client", mapOf("id" to id.toString(), "notes_offset" to notes.size.toString())))?.notes ?: emptyList()
                            val merged = mergeNotePages(notes, page)
                            olderNotes = merged.drop(detail.notes.size)
                            val added = merged.size - notes.size
                            olderSaid = if (added > 0) "$added older ${if (added == 1) "note" else "notes"} loaded." else "No older notes were found."
                        } catch (e: ApiException) { olderError = e.message } finally { olderBusy = false }
                    }
                }
                Announce(if (olderBusy) "Loading older notes…" else null, key = olderBusy)
                ErrorText(olderError)
            }
        }
        if (portalAsk) Confirm("Email a new portal link?", portalConfirm(r, "A new link to the client portal is emailed to ${detail.email ?: ""}. Any earlier link stops working."), "Email the link", {
            notice = null; linkError = null
            scope.launch {
                try { notice = api.callOnce("send_portal_link", org.json.JSONObject().put("client_id", id), portalKey, { portalKey = it }, "the portal link").optString("message").ifEmpty { "Portal link emailed." }; state.reload() }
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
    var withdrawing by rememberSaveable { mutableStateOf(false) }
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
            // 4.37.0 (S15): the signed consent shown can be recorded as withdrawn (only that one).
            val signedId = if (r.has("signed_id") && !r.isNull("signed_id")) r.optInt("signed_id") else consent?.let { if (it.has("signed_id") && !it.isNull("signed_id")) it.optInt("signed_id") else null }
            if (st == "Signed" && signedId != null && signedId > 0) SecondaryButton("Record consent withdrawn") { withdrawing = true }
            if (withdrawing && signedId != null) {
                val who = consent?.str("signed_name") ?: "the guardian"
                val on = shortDate(consent?.str("signed_at"))
                Confirm(consentWithdrawTitle(who, on), CONSENT_WITHDRAW_TEXT, "Record consent withdrawn", {
                    scope.launch {
                        try { onDone(api.call("withdraw_consent", body = org.json.JSONObject().put("client_id", clientId).put("consent_id", signedId)).optString("message")
                            .ifEmpty { "Guardian consent recorded as withdrawn. Request a new consent below when the guardian agrees again." }) }
                        catch (e: ApiException) { error = e.message; loaded.reload() }
                    }
                }, { withdrawing = false })
            }
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
    // 4.35.0: the format, the track and who edited it, as the website's note rows.
    val head = noteRowLines(n)
    ListRow(head, action = if (open) "close the note" else "read the note", state = if (open) "expanded" else "collapsed", onClick = { open = !open })
    if (open) {
        Text(n.body)
        // 4.34.0: as on the website, an editable note can be edited; a locked one takes an addendum.
        if (n.canEdit) SecondaryButton("Edit note: ${n.subject}", onClick = onEdit)
        if (n.locked) SecondaryButton("Add addendum to ${n.subject}", onClick = onAddendum)
    }
}

/** 4.35.0: Generate structured note with nothing to work from (the iPhone app's words). */
const val GENERATE_NOTHING = "Rough notes: type or dictate your rough notes here, or in the note's own boxes above, then choose Generate structured note."

/**
 * 4.35.0: one of a client's notes, paging back through the record 50 at a time until it is found
 * (the editor can open a note older than the newest 50). Null when it is not on the record.
 */
suspend fun findClientNote(api: Api, clientId: Int, noteId: Int): NoteItem? {
    var offset = 0
    while (true) {
        val r = api.call("client", if (offset == 0) mapOf("id" to clientId.toString()) else mapOf("id" to clientId.toString(), "notes_offset" to offset.toString()))
        val d = parseClientDetail(r) ?: return null
        d.notes.firstOrNull { it.id == noteId }?.let { return it }
        offset += d.notes.size
        if (d.notes.isEmpty() || offset >= d.notesTotal || offset > 5000) return null
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
    // 4.38.0 (T16): the website's built-in list (with Phone Call) when the server sent none.
    val baseTypes = api.formList("note_types", noteTypeFallback())
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
    var roughError by remember { mutableStateOf<String?>(null) }
    // 4.35.0: the generated questions, homework items (with their edits) and ticks are kept across
    // rotation and process death, as the rest of the note.
    var questions by rememberSaveable(stateSaver = androidx.compose.runtime.saveable.Saver<List<String>, String>(
        save = { stringsToText(it) }, restore = { stringsFromText(it) })) { mutableStateOf(emptyList()) }
    var homework by rememberSaveable(stateSaver = androidx.compose.runtime.saveable.Saver<List<org.json.JSONObject>, String>(
        save = { homeworkToText(it) }, restore = { homeworkFromText(it) })) { mutableStateOf(emptyList()) }
    var include by rememberSaveable(stateSaver = androidx.compose.runtime.saveable.Saver<Set<Int>, String>(
        save = { org.casemanagement.app.idsToText(it) }, restore = { org.casemanagement.app.idsFromText(it) })) { mutableStateOf(emptySet()) }
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
        if (wanted <= 0) null else findClientNote(api, clientId, wanted) ?: throw ApiException("That note could not be found on this client's record. It may have been removed.")
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
            // 4.35.0: an addendum is filed on its note's track unless it was opened from another.
            if (noteTrack == 0) noteTrack = n.trackId ?: 0
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
            // 4.35.0: any note can be filed on a track, as on the website (an addendum keeps its note's track).
            val active = tracks.data ?: emptyList()
            val trackOptions = listOf("0" to "General (not tied to a track)") + active.map { it.optInt("id").toString() to it.optString("title") } +
                listOfNotNull((noteTrack.toString() to (existing.data?.trackTitle ?: "Track $noteTrack")).takeIf { noteTrack > 0 && active.none { a -> a.optInt("id") == noteTrack } })
            Choice("Track", noteTrack.toString(), trackOptions) { noteTrack = it.toIntOrNull() ?: 0 }
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
                    val n = findClientNote(api, clientId, parentId)
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
            sectionsBefore?.let { old -> SecondaryButton("Restore my sections") { sections = old; sectionsBefore = null; notice = "Your sections are back." } }
            labels.forEachIndexed { i, l -> Field(l, sections[i], { v -> sections = sections.toMutableList().also { it[i] = v } }, singleLine = false, minLines = 3) }
            if (questions.isNotEmpty()) { Heading("Before you save, double-check"); questions.forEach { Text("• $it") } }
            if (homework.isNotEmpty() && mode != "edit") {
                Heading("Homework from the notes")
                val active = tracks.data ?: emptyList()
                if (active.isEmpty()) Text("Homework needs an active training track; none was found, so it will not be saved.")
                else if (active.size == 1 && hwTrack.isEmpty()) hwTrack = active[0].optInt("id").toString()
                if (active.isNotEmpty()) Choice("Homework goes on the track", hwTrack, listOf("" to "Choose a track") + active.map { it.optInt("id").toString() to it.optString("title") }) { hwTrack = it }
                // 4.35.0: each item's title, instructions and due date can be changed before saving, as on the website.
                homework.forEachIndexed { i, h ->
                    val n = i + 1
                    fun edit(key: String, v: String) { homework = homework.toMutableList().also { it[i] = org.json.JSONObject(it[i].toString()).put(key, v) } }
                    Tick("Include: " + h.optString("title").ifBlank { "homework $n" }, i in include) { on -> include = if (on) include + i else include - i }
                    if (i in include) {
                        Field("Homework $n title", h.optString("title"), { edit("title", it) })
                        Field("Homework $n instructions", h.optString("instructions"), { edit("instructions", it) }, singleLine = false, minLines = 2)
                        if (active.isNotEmpty()) Field("Homework $n due date", h.optString("due"), { edit("due", it); homeworkErrors = homeworkErrors - i },
                            hint = "optional, year-month-day", error = homeworkErrors[i])
                    }
                }
            }
            Field(if (mode == "edit") "Narrative (optional; left blank keeps the narrative already saved)" else "Narrative", body, { body = it },
                hint = if (mode == "edit") null else "optional", singleLine = false, minLines = 3)
        }
        // 4.35.0: Generate structured note, in every format, from the rough box or else from what was typed
        // (a free-text note becomes BIRP, its text kept as the narrative), as the website and the iPhone app.
        if (api.aiHas("draft") && mode != "edit") {
            Field("Rough notes", rough, { rough = it; roughError = null }, hint = "optional; leave empty to use what you typed in the note", singleLine = false, minLines = 3, error = roughError)
            SecondaryButton(if (aiBusy) "Generating… this can take up to a minute" else "Generate structured note") {
                if (aiBusy) return@SecondaryButton
                presses++
                val source = org.casemanagement.app.ui.generatorSource(rough, body, sections)
                if (source.isEmpty()) { roughError = GENERATE_NOTHING; error = GENERATE_NOTHING; return@SecondaryButton }
                roughError = null
                val before = sections
                val wasFree = !structured
                val fmt = generateFormat(format)
                scope.launch {
                    aiBusy = true; error = null
                    try {
                        val r = api.call("ai_generate_note", body = org.json.JSONObject().put("client_id", clientId).put("note_format", fmt).put("text", source))
                        if (sections != before) { error = "The $fmt sections changed while the draft was being generated, so it was not applied. Choose Generate structured note again to replace them."; return@launch }
                        val a = r.optJSONArray("sections")
                        if (before.any { it.isNotBlank() }) sectionsBefore = before
                        val got = r.str("format")?.takeIf { it in NOTE_LABELS } ?: fmt
                        format = got
                        // A section the AI left empty keeps what was typed in it.
                        sections = List(4) { i -> a?.optString(i)?.takeIf { it.isNotBlank() } ?: before[i] }
                        questions = r.optJSONArray("questions").let { q -> List(q?.length() ?: 0) { q!!.optString(it) } }
                        homework = r.optJSONArray("homework").objects()
                        include = homework.indices.toSet()
                        homeworkErrors = emptyMap()
                        var summary = "$got sections drafted."
                        if (wasFree && body.isNotBlank()) summary += " The text you typed in Note is kept as the narrative, saved after the sections."
                        if (questions.isNotEmpty()) summary += " ${questions.size} ${if (questions.size == 1) "thing" else "things"} to double-check."
                        if (homework.isNotEmpty()) summary += " ${homework.size} homework ${if (homework.size == 1) "item" else "items"} found."
                        notice = "$summary Review before saving."
                    } catch (e: ApiException) { error = e.message } finally { aiBusy = false }
                }
            }
        }
        // 4.37.0 (S9): "Format with AI" splits the narrative into the chosen format's sections, as the website.
        if (api.aiHas("draft") && structured) {
            SecondaryButton(if (aiBusy) "Formatting… this can take up to a minute" else "Format with AI") {
                if (aiBusy) return@SecondaryButton
                presses++
                if (body.isBlank()) { error = "Type the session notes first, then format them."; return@SecondaryButton }
                val before = sections
                val fmt = format
                scope.launch {
                    aiBusy = true; error = null
                    try {
                        val r = api.call("ai_draft", body = formatBody(clientId, fmt, body, before))
                        if (sections != before) { error = "The $fmt sections changed while the note was being formatted, so it was not applied. Choose Format with AI again."; return@launch }
                        val got = r.optJSONArray("sections").let { a -> List(a?.length() ?: 0) { a!!.optString(it) } }
                        if (before.any { it.isNotBlank() }) sectionsBefore = before
                        sections = applyFormatted(before, got)
                        notice = r.str("message") ?: formattedMessage(fmt)
                    } catch (e: ApiException) { error = replyErrors(e.reply)["body"] ?: e.message } finally { aiBusy = false }
                }
            }
        }
        if (forSession) about()
        // 4.30.0: the AI's wait is said once when it starts.
        Announce(if (aiBusy) "Working… This can take up to a minute." else null, key = aiBusy)
        // 4.30.0: Save stays enabled; a press says what is still needed.
        val missing = stillNeeded(missingNames("Subject" to subject.isBlank(),
            (if (structured) "at least one section" else "Note") to (if (structured) sections.none { it.isNotBlank() } else body.isBlank())))
        // 4.35.0: a group's note can be left for later; it stays on Notes to write.
        val inGroup = forSession && mode == "new" && GroupNoteQueue.items.size > 1 && GroupNoteQueue.items.any { it.sessionId == sessionId }
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
            // 4.35.0: a homework due date is checked here with the server's words (numbered among the items sent).
            if (mode == "new" && structured && hwTrack.isNotEmpty()) {
                val rows = homework.indices.filter { i -> i in include && homework[i].optString("title").isNotBlank() }
                val bad = rows.mapIndexedNotNull { k, i -> homeworkDueRule(k + 1, homework[i].optString("due"))?.let { i to it } }.toMap()
                if (bad.isNotEmpty()) { homeworkErrors = bad; error = errorSummary(bad.values); return@PrimaryButton }
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
                val said = org.casemanagement.app.noteSavedMessage(saved.reply, savedWords) ?: saved.message
                // 4.35.0: a group's notes are written in turn: saving one opens the next (as the website).
                val next = if (forSession) nextInQueue(GroupNoteQueue.items, sessionId) else null
                if (next != null) {
                    Flash.pending = said + nextNoteWords(next.first.clientName, next.second, GroupNoteQueue.items.size)
                    nav.popBackStack(); nav.navigate(next.first.route)
                } else {
                    if (forSession && GroupNoteQueue.items.any { it.sessionId == sessionId }) GroupNoteQueue.items = emptyList()
                    Flash.pending = said
                    nav.popBackStack()
                }
            }
        }
        if (inGroup) {
            val next = nextInQueue(GroupNoteQueue.items, sessionId)
            SecondaryButton("Skip for now") {
                if (busy) return@SecondaryButton
                if (next != null) {
                    Flash.pending = "Skipped for now: that note stays under Notes to write." + nextNoteWords(next.first.clientName, next.second, GroupNoteQueue.items.size)
                    nav.popBackStack(); nav.navigate(next.first.route)
                } else {
                    GroupNoteQueue.items = emptyList()
                    Flash.pending = "Skipped for now: that note stays under Notes to write."
                    nav.popBackStack()
                }
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

/**
 * 4.34.0: log_hours' errors keys on the form's fields (the server's mileage is the form's miles).
 * 4.35.0: a refusal without a map is tied by its start ("Goal this session advanced: …").
 */
fun logHoursFieldErrors(reply: org.json.JSONObject?, message: String? = null): Map<String, String> =
    (serverFieldErrors(reply, LOG_HOURS_ERROR_FIELDS, message) ?: emptyMap()).mapKeys { (k, _) -> if (k == "mileage") "miles" else k }

/** 4.35.0: log_hours' one-message refusals, by the field their words start with. */
val LOG_HOURS_ERROR_FIELDS = listOf("Goal this session advanced" to "goal_id", "RSA category this visit" to "rsa_category",
    "Service this visit" to "service_type", "Work date" to "date")

/** The organisation's services, when the server sent none (as the iPhone app). */
val SERVICE_TYPES_FALLBACK = listOf("Assistive Technology", "Rehab Teaching", "Counseling", "Pre-ETS", "Other")

/**
 * Hours on a training track, with mileage; with no signal they are kept and sent later.
 * 4.35.0: as the website's Record a session and the iPhone app: the service this visit, the RSA
 * category, the goal advanced, and a session note saved with the hours (or on its own, hours blank).
 */
@Composable
fun LogHoursScreen(api: Api, nav: NavHostController, clientId: Int, trackId: Int) {
    val tracks = rememberLoaded(trackId) { parseTracks(api.call("tracks")) }
    val goals = rememberLoaded(trackId) {
        try { org.casemanagement.app.parseGoals(api.call("goals", mapOf("track_id" to trackId.toString()))).filter { it.status != "Achieved" } } catch (e: ApiException) { emptyList() }
    }
    var date by rememberSaveable { mutableStateOf(today()) }
    var hours by rememberSaveable { mutableStateOf("") }
    var miles by rememberSaveable { mutableStateOf("") }
    var description by rememberSaveable { mutableStateOf("") }
    var category by rememberSaveable { mutableStateOf("") }
    // Null until chosen here: the server then uses the track's own service.
    var serviceType by rememberSaveable { mutableStateOf<String?>(null) }
    var goal by rememberSaveable { mutableStateOf("0") }
    var sessionNote by rememberSaveable { mutableStateOf("") }
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
        val services = api.formList("service_types", SERVICE_TYPES_FALLBACK)
        val svc = serviceType ?: t?.serviceType ?: services.first()
        Choice("Service this visit", svc, (services + listOfNotNull(svc.takeIf { it !in services })).map { it to it }, error = fieldErrors["service_type"]) { serviceType = it }
        val cats = t?.visitCategories ?: emptyList()
        if (cats.size > 1) Choice("Rehabilitation Services Administration (RSA) category this visit", category.ifEmpty { cats.first() }, cats.map { it to it }, error = fieldErrors["rsa_category"]) { category = it }
        else if (cats.size == 1) ListRow(listOf("Rehabilitation Services Administration (RSA) category: " + cats.first()))
        val open = goals.data ?: emptyList()
        if (open.isNotEmpty()) Choice("Goal this session advanced", goal, listOf("0" to "None") + open.map { it.id.toString() to it.specificGoal }, error = fieldErrors["goal_id"]) { goal = it }
        Field("Date", date, { date = it }, hint = "year-month-day, such as ${today()}", error = fieldErrors["date"], required = true)
        Field("Hours", hours, { hours = it }, hint = "0 with miles for travel only; blank to save only the session note", keyboard = KeyboardType.Decimal, error = fieldErrors["hours"])
        Field("Miles driven", miles, { miles = it }, hint = "0 if none", keyboard = KeyboardType.Decimal, error = fieldErrors["miles"])
        Field("What did you work on?", description, { description = it }, hint = "required with hours", singleLine = false, minLines = 4, error = fieldErrors["description"])
        Field("Session note", sessionNote, { sessionNote = it }, hint = "optional", singleLine = false, minLines = 4, error = fieldErrors["session_note"])
        SignaturePad(signature)
        Field("Client's typed name", signedName, { signedName = it }, hint = "optional; counts as their acknowledgement")
        ErrorText(error, focusKey = presses)
        PrimaryButton("Log hours", busy = busy) {
            presses++
            val noteOnly = hours.isBlank()
            val h = if (noteOnly) 0.0 else number(hours)
            val m = number(miles.ifBlank { "0" })
            fieldErrors = listOfNotNull(
                // 4.35.0: a real date, not in the future (the server's words).
                if (!realDate(date)) "date" to "Work date must be a valid date (YYYY-MM-DD)."
                else if (date.trim() > today()) "date" to "Work date: ${dayText(date.trim())} is in the future. Hours are logged once a session has taken place: enter the session date, today or earlier."
                else null,
                hoursRule(hours, m, sessionNote)?.let { "hours" to it },
                if (m == null || m < 0 || m > 1000) "miles" to "Enter the miles as a number from 0 to 1000." else null,
                if (!noteOnly && description.isBlank()) "description" to "Describe the activity, for the hours on the invoice." else null,
            ).toMap()
            when {
                busy -> {}
                h == null || m == null || fieldErrors.isNotEmpty() -> error = errorSummary(fieldErrors.values)
                else -> scope.launchSave({ busy = it }, { error = it }) {
                    val payload = logHoursPayload(trackId, hours, date, m, description, serviceType ?: "",
                        if (cats.size > 1) category.ifEmpty { cats.first() } else "", goal.toIntOrNull() ?: 0, sessionNote, idem)
                    signature.png(density)?.let { payload.put("signature_b64", it) }
                    if (signedName.isNotBlank()) payload.put("signed_name", signedName.trim().take(120))
                    val who = t?.client ?: "track $trackId"
                    val saved = try { saveOrQueue(api, "log_hours", payload, if (noteOnly) "Session note: $who" else "Hours: ${hoursText(h)} on $who", "Hours logged.") }
                    catch (e: ApiException) { fieldErrors = logHoursFieldErrors(e.reply, e.message); throw e }
                    val r = saved.reply
                    val said = if (r == null || r.optBoolean("deduplicated")) saved.message
                        else logHoursMessage(h, sessionNote.isNotBlank(), noteOnly || (r.optBoolean("note_saved") && r.has("hours_logged") && !r.optBoolean("hours_logged")))
                    // 4.34.0: the website's reminder when the track has no homework.
                    Flash.pending = listOfNotNull(said, r?.str("reminder")).joinToString(" ")
                    nav.popBackStack()
                }
            }
        }
    }
}
