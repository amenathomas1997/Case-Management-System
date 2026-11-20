package org.casemanagement.app.ui

import android.content.Context
import android.content.Intent
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.text.selection.SelectionContainer
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.saveable.Saver
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.input.KeyboardType
import androidx.core.content.FileProvider
import androidx.core.net.toUri
import androidx.navigation.NavHostController
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import org.casemanagement.app.Api
import org.casemanagement.app.ApiException
import org.casemanagement.app.objects
import org.casemanagement.app.str
import org.json.JSONObject

/*
 * 4.37.0 screens: the device justification letter, the worksite recommendation report, the AT
 * evaluation report, the amendment letter with its request and reply, letters, the client timeline,
 * instructor matching, and the client record's releases, privacy notice and portal link. Every
 * dialog is the app's AlertDialog (LockAware.kt); typed text survives rotation.
 */

/** A map kept across rotation, as JSON text. */
val StringMapSaver = Saver<Map<String, String>, String>(
    save = { JSONObject(it).toString() },
    restore = { t -> try { JSONObject(t).let { o -> o.keys().asSequence().associateWith { o.optString(it) } } } catch (e: Exception) { emptyMap() } },
)

const val NO_LOCK_FILES = "This phone has no screen lock, so documents are not saved on it to open. Open it on the website, or set a screen lock in Settings."
const val ACCOUNT_CHANGED = "Your account changed. Please reload this screen."

/** The files a screen wrote to share or open: removed from the phone when the screen closes. */
@Composable
fun rememberScreenFiles(): MutableList<java.io.File> {
    val files = remember { mutableListOf<java.io.File>() }
    DisposableEffect(Unit) { onDispose { files.forEach { try { it.delete() } catch (e: Exception) { } }; files.clear() } }
    return files
}

/**
 * Writes [bytes] to the app's own temporary folder (emptied at sign-out) under a name that keeps
 * each screen's file apart, for this account only. Returns the file, or why it could not.
 */
suspend fun writeScreenFile(api: Api, name: String, bytes: ByteArray, files: MutableList<java.io.File>): Pair<java.io.File?, String?> {
    if (!api.store.phoneCanKeepClientData) return null to NO_LOCK_FILES
    val owner = api.userId
    return try {
        val f = withContext(Dispatchers.IO) {
            api.docsFolder.mkdirs()
            java.io.File(api.docsFolder, name).also { it.writeBytes(bytes) }
        }
        files.add(f)
        // Signed out or another account meanwhile: the file goes, as Api.download does.
        if (api.userId != owner) { f.delete(); null to ACCOUNT_CHANGED } else f to null
    } catch (e: java.io.IOException) { null to "The file could not be saved on this phone. Try again." }
}

/** Offers a file the screen wrote to another app ([send]: to share it, otherwise to open it). Why it could not, or null. */
fun offerFile(context: Context, f: java.io.File, mime: String, send: Boolean, chooser: String): String? = try {
    val uri = FileProvider.getUriForFile(context, context.packageName + ".files", f)
    val i = if (send) Intent(Intent.ACTION_SEND).setType(mime).putExtra(Intent.EXTRA_STREAM, uri) else Intent(Intent.ACTION_VIEW).setDataAndType(uri, mime)
    if (!openLink(context, Intent.createChooser(i.addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION), chooser))) "No app on this phone can take the file." else null
} catch (e: IllegalArgumentException) { "The file could not be offered to another app." }

/** Writes [html] and offers it to another app. Returns why it could not, or null. */
suspend fun shareHtml(api: Api, context: Context, html: String, prefix: String, file: String?, chooser: String, files: MutableList<java.io.File>): String? {
    val name = safeFileName(prefix, file, "document.html").let { if (it.lowercase().endsWith(".html")) it else "$it.html" }
    val (f, err) = writeScreenFile(api, name, html.toByteArray(), files)
    return err ?: offerFile(context, f!!, "text/html", true, chooser)
}

/** A placeholder an AI draft left in [text] (a bracketed tag other than "[not on file]"), named once. */
fun placeholderNote(text: String): String? =
    Regex("\\[[A-Za-z][A-Za-z0-9 _-]{0,40}]").findAll(text).map { it.value }.firstOrNull { it != "[not on file]" }
        ?.let { "It still contains the placeholder $it: replace it with the actual detail before sending." }

/**
 * A document from the server: its text (selectable), shared as the HTML file the website
 * downloads, and copied. [note] says what to change first; the release warning comes first.
 */
@Composable
fun ServerDocument(api: Api, title: String, r: JSONObject, prefix: String, shareLabel: String, copyLabel: String, note: String? = null) {
    val context = LocalContext.current
    val files = rememberScreenFiles()
    var notice by remember { mutableStateOf<String?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    var sharing by remember { mutableStateOf(false) }
    var presses by remember { mutableIntStateOf(0) }
    val scope = rememberCoroutineScope()
    Heading(r.str("title") ?: title, large = true)
    r.str("release_warning")?.let { Text(it) }
    note?.let { Text(it) }
    NoticeText(notice)
    ErrorText(error, focusKey = presses)
    r.str("html")?.let { html ->
        SecondaryButton(if (sharing) "Preparing…" else shareLabel) {
            if (sharing) return@SecondaryButton
            presses++; sharing = true; error = null; notice = null
            scope.launch { try { error = shareHtml(api, context, html, prefix, r.str("file"), shareLabel, files) } finally { sharing = false } }
        }
    }
    SecondaryButton(copyLabel, enabled = r.optString("text").isNotEmpty()) { copyText(context, r.optString("text")); error = null; notice = COPIED_NOTE }
    SelectionContainer { Text(r.optString("text")) }
}

/* ---------------- S4: the justification letter ---------------- */

@Composable
fun DeviceLetterScreen(api: Api, id: Int, editable: Boolean) {
    val state = rememberLoaded(id) { api.call("device_letter", mapOf("id" to id.toString())) }
    Show(state, "the letter") { r ->
        Page { ServerDocument(api, "Justification letter", r, "letter-$id", "Share the letter (HTML file)", "Copy letter", if (editable) LETTER_EDIT_NOTE else null) }
    }
}

/* ---------------- S8: the worksite recommendation report ---------------- */

@Composable
fun WorksiteReportScreen(api: Api, id: Int) {
    val state = rememberLoaded(id) { api.call("worksite_report", mapOf("id" to id.toString())) }
    Show(state, "the report") { r ->
        Page { ServerDocument(api, "Recommendation report", r, "worksite-$id", "Share the report (HTML file)", "Copy report") }
    }
}

/* ---------------- S7: the AT evaluation report ---------------- */

@Composable
fun EvalReportScreen(api: Api, nav: NavHostController, assessmentId: Int) {
    val state = rememberLoaded(assessmentId) { api.call("eval_report", mapOf("assessment_id" to assessmentId.toString())) }
    Show(state, "the evaluation report") { r -> EvalReportForm(api, nav, assessmentId, r) }
}

@Composable
private fun EvalReportForm(api: Api, nav: NavHostController, assessmentId: Int, r: JSONObject) {
    val context = LocalContext.current
    val files = rememberScreenFiles()
    var sections by rememberSaveable(assessmentId, stateSaver = StringMapSaver) { mutableStateOf(evalSections(r)) }
    var to by rememberSaveable { mutableStateOf(r.optString("to")) }
    var signature by rememberSaveable { mutableStateOf("") }
    var idem by rememberSaveable { mutableStateOf("") }
    var asking by rememberSaveable { mutableStateOf(false) }
    var notice by remember { mutableStateOf<String?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    var errors by remember { mutableStateOf<Map<String, String>>(emptyMap()) }
    var drafting by remember { mutableStateOf(false) }
    var sending by remember { mutableStateOf(false) }
    var sharing by remember { mutableStateOf(false) }
    var presses by remember { mutableIntStateOf(0) }
    val scope = rememberCoroutineScope()
    val signer = r.optString("signer")
    val a = r.optJSONObject("assessment") ?: JSONObject()
    HoldBackWhileSaving(sending)
    Page {
        Heading(evalButtonLabel(shortDate(a.str("date"))), large = true)
        a.str("client")?.let { Text("Client: $it") }
        r.str("release_warning")?.let { Text(it) }
        NoticeText(notice)
        ErrorText(error, focusKey = presses)
        EVAL_SECTIONS.forEach { (k, label) ->
            Field(label, sections[k] ?: "", { v -> sections = sections + (k to v); errors = errors - k }, singleLine = false, minLines = 4, error = errors[k], enabled = !drafting)
        }
        if (flag(r, "ai", true) && api.aiHas("draft")) {
            Heading("Draft with AI")
            Text(EVAL_HINT)
            PrimaryButton("Draft narrative with AI", busy = drafting, busyText = "Drafting…", announceBusy = true) {
                if (drafting) return@PrimaryButton
                drafting = true; error = null; notice = null
                val sent = sections
                scope.launch {
                    try {
                        val d = api.call("ai_eval_draft", body = JSONObject().put("assessment_id", assessmentId).put("sections", evalSectionsBody(sent)))
                        val got = evalSections(d)
                        if (got.isNotEmpty()) sections = mergeSections(sent, sections, got)
                        notice = listOfNotNull(d.str("message"), if (flag(d, "truncated")) AI_INCOMPLETE else null,
                            placeholderNote(got.values.joinToString("\n"))).joinToString(" ")
                    } catch (e: ApiException) { presses++; error = e.message } finally { drafting = false }
                }
            }
        }
        Heading("Email the signed report")
        Field("Email to (required to email)", to, { to = it; errors = errors - "to" }, keyboard = KeyboardType.Email, error = errors["to"])
        Field(evalSignatureLabel(signer), signature, { signature = it; errors = errors - "signature" }, error = errors["signature"])
        PrimaryButton("Email signed report to counselor", busy = sending, busyText = "Sending…", announceBusy = true,
            missing = stillNeeded(missingNames("Email to" to to.isBlank(), "Electronic signature" to signature.isBlank()))) { if (!sending) asking = true }
        SecondaryButton(if (sharing) "Preparing…" else "Share accessible HTML") {
            if (sharing) return@SecondaryButton
            sharing = true; error = null
            scope.launch {
                try {
                    val h = api.call("eval_report", body = JSONObject().put("assessment_id", assessmentId).put("sections", evalSectionsBody(sections)).put("html", true))
                    error = shareHtml(api, context, h.optString("html"), "evaluation-$assessmentId", h.str("file") ?: "evaluation-report.html", "Share accessible HTML", files)
                } catch (e: ApiException) { error = e.message } finally { presses++; sharing = false }
            }
        }
    }
    if (asking) Confirm(evalConfirmTitle(to), EVAL_SIGNED_TEXT, "Email signed report", {
        if (sending) return@Confirm
        presses++; sending = true; error = null; notice = null; errors = emptyMap()
        scope.launch {
            try {
                val res = api.callOnce("send_eval_report", JSONObject().put("assessment_id", assessmentId).put("sections", evalSectionsBody(sections))
                    .put("to", to.trim()).put("signature", signature.trim()), idem, { idem = it }, "the evaluation report")
                // Sent: the screen closes, so it cannot be emailed twice.
                Flash.pending = closeAfterSend(res, "Evaluation report emailed to ${to.trim()} and logged on the client's file.")
                nav.popBackStack()
            } catch (e: ApiException) {
                errors = mapErrors(e.reply, ::evalFieldFor).filterKeys { it.isNotEmpty() }
                error = errorSummary(errors.values) ?: e.message
            } finally { sending = false }
        }
    }, { asking = false })
}

/* ---------------- S9: goal suggestions from an assessment ---------------- */

@Composable
fun GoalSuggestionsDialog(api: Api, assessmentId: Int, date: String, onDismiss: () -> Unit) {
    val context = LocalContext.current
    // The reply is kept across rotation, so turning the phone never asks the AI again.
    var replyText by rememberSaveable(assessmentId) { mutableStateOf<String?>(null) }
    var error by rememberSaveable(assessmentId) { mutableStateOf<String?>(null) }
    var copied by remember { mutableStateOf(false) }
    LaunchedEffect(assessmentId) {
        if (replyText != null || error != null) return@LaunchedEffect
        try { replyText = api.call("ai_draft", body = JSONObject().put("type", "goals").put("assessment_id", assessmentId)).toString() }
        catch (e: ApiException) { error = e.message ?: "The suggestions could not be drafted." }
    }
    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text(GOALS_TITLE, modifier = Modifier.semantics { heading() }) },
        text = {
            Column(Modifier.verticalScroll(rememberScrollState())) {
                Text("For the assessment of $date. $GOALS_INTRO")
                val d = replyText?.let { try { JSONObject(it) } catch (e: Exception) { null } }
                when {
                    d != null -> {
                        Announce("Goal suggestions ready.", key = replyText)
                        if (flag(d, "truncated")) Text(AI_INCOMPLETE)
                        SelectionContainer { Text(d.optString("text")) }
                        SecondaryButton("Copy suggestions") { copyText(context, d.optString("text")); copied = true }
                        if (copied) NoticeText(COPIED_NOTE)
                    }
                    error != null -> ErrorText(error)
                    else -> { Text("Drafting… This can take up to a minute."); Announce("Drafting…", key = "drafting") }
                }
            }
        },
        confirmButton = { TextButton(onClick = onDismiss) { Text("Close") } },
    )
}

/* ---------------- S12, S13: the amendment letter, the request and the reply ---------------- */

@Composable
fun AmendmentScreen(api: Api, nav: NavHostController, clientId: Int, trackId: Int) {
    val state = rememberLoaded(trackId) { api.call("amendment", mapOf("track" to trackId.toString())) }
    Show(state, "the burn-down") { d -> AmendmentBody(api, nav, clientId, trackId, d, reload = { state.reload() }) }
}

@Composable
private fun AmendmentBody(api: Api, nav: NavHostController, clientId: Int, trackId: Int, first: JSONObject, reload: () -> Unit) {
    val context = LocalContext.current
    // The server's latest reply (read again after a request or reply is recorded); the typed letter is kept apart.
    var dataText by remember(first) { mutableStateOf(first.toString()) }
    val d = remember(dataText) { try { JSONObject(dataText) } catch (e: Exception) { first } }
    var letter by rememberSaveable(trackId) { mutableStateOf(first.optString("letter")) }
    var serverLetter by rememberSaveable(trackId) { mutableStateOf(first.optString("letter")) }
    var aiDraft by rememberSaveable { mutableStateOf<String?>(null) }
    var myLetter by rememberSaveable { mutableStateOf<String?>(null) }
    var askReplace by rememberSaveable { mutableStateOf(false) }
    var aiBusy by remember { mutableStateOf(false) }
    var notice by remember { mutableStateOf<String?>(null) }
    var aiSaid by remember { mutableStateOf<String?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    var presses by remember { mutableIntStateOf(0) }
    // The reason each approved request starts the track edit with, from its reply (when the row has none), for this screen only.
    val prefills = remember { mutableMapOf<Int, String>() }
    val scope = rememberCoroutineScope()
    val separate = d.has("history")
    val history = parseAmendmentHistory(d)
    fun draft() {
        if (aiBusy) return
        aiBusy = true; error = null; notice = null
        val sent = letter
        scope.launch {
            try {
                val b = JSONObject().put("track", trackId).put("ai_draft", 1)
                if (sent != serverLetter) b.put("letter", sent)
                val res = api.call("amendment", body = b)
                val out = amendmentDraftOutcome(sent, letter, serverLetter, separate, res)
                letter = out.letter; serverLetter = out.serverLetter
                if (out.aiDraft != null) { aiDraft = out.aiDraft; myLetter = null }
                dataText = JSONObject(dataText).put("ai_note", res.optString("ai_note")).toString()
                // The ai_note shows as the letter's caption, said once here; otherwise the result is said.
                aiSaid = res.str("ai_note")
                notice = placeholderNote(out.aiDraft ?: out.letter) ?: if (res.str("ai_note") == null) "Letter updated." else null
            } catch (e: ApiException) { presses++; error = e.message } finally { aiBusy = false }
        }
    }
    Page {
        Heading(listOfNotNull(d.str("client"), d.str("title")).joinToString(": "), large = true)
        NoticeText(notice)
        Announce(aiSaid)
        ErrorText(error, focusKey = presses)
        Heading("Burn-down")
        amendmentBurnDown(d).forEach { ListRow(listOf(it)) }
        Heading("Amendment request letter")
        d.str("ai_note")?.let { Text(it) }
        Field("Letter", letter, { letter = it }, singleLine = false, minLines = 8, enabled = !aiBusy)
        if (api.aiHas("draft")) SecondaryButton(if (aiBusy) "Drafting…" else "Draft with AI") {
            if (replaceDraftAsk(letter != serverLetter, separate)) askReplace = true else draft()
        }
        Announce(if (aiBusy) "Drafting…" else null, key = aiBusy)
        aiDraft?.let { draftText ->
            Heading("AI draft")
            Text(AI_SEPARATE_NOTE)
            SelectionContainer { Text(draftText) }
            SecondaryButton("Use the AI draft as the letter") { myLetter = letter; letter = draftText; aiDraft = null; notice = "The AI draft is now the letter. Review and edit it before sending." }
        }
        myLetter?.let { mine -> SecondaryButton("Restore my letter") { letter = mine; myLetter = null; notice = "Your letter is back." } }
        val email = if (flag(d, "counselor_email_unconfirmed")) null else d.str("counselor_email")
        if (flag(d, "counselor_email_unconfirmed")) Text(COUNSELOR_UNCONFIRMED)
        else if (email != null) SecondaryButton("Email counselor") {
            if (!openLink(context, Intent(Intent.ACTION_SENDTO, amendmentMailto(email, d.optString("client"), letter).toUri()))) error = "This phone has no email app."
        }
        SecondaryButton("Copy letter", enabled = letter.isNotEmpty()) { copyText(context, letter); notice = COPIED_NOTE }
        if (history != null) {
            RecordAmendmentSection(api, trackId, d, letter) { message -> notice = message; reload() }
            Heading("Requests and replies (${history.size})")
            d.str("waiting_text")?.let { Text(it) }
            if (history.isEmpty()) Text(AMENDMENT_NONE)
            history.forEach { h ->
                ListRow(listOf(h.text))
                if (h.awaiting) AmendmentReplyForm(api, trackId, h, d.stringList("reply_statuses") ?: listOf("Approved", "Partly approved", "Denied", "Withdrawn"), d.optString("today", today())) { res ->
                    notice = res.optString("message")
                    res.str("reason_prefill")?.let { prefills[h.id] = it }
                    reload()
                }
                if (h.needsChange) SecondaryButton("Enter the approved change on the track") {
                    nav.navigate(trackEditRoute(clientId, trackId, h.id, h.reasonPrefill.ifEmpty { prefills[h.id].orEmpty() }))
                }
            }
        }
    }
    if (askReplace) Confirm(REPLACE_DRAFT_TITLE, "", "Replace with a new draft", { draft() }, { askReplace = false }, no = "Keep my letter")
}

/** The burn-down lines, with the iPhone's labels. */
fun amendmentBurnDown(d: JSONObject): List<String> {
    fun m(k: String) = money(d.optDouble(k, 0.0))
    val byFunds = flag(d, "funds_limit") && !d.isNull("suggest_funds") && d.has("suggest_funds")
    val out = mutableListOf(
        "Hours used: %.2f of %.2f hours (%d%%)".format(java.util.Locale.US, d.optDouble("worked", 0.0), d.optDouble("authorized", 0.0), d.optInt("pct")),
        "Hours remaining: %.2f hours".format(java.util.Locale.US, d.optDouble("remaining", 0.0)),
    )
    if (d.optDouble("funds_authorized", 0.0) > 0) {
        out += "Funds used: ${m("funds_used")} of ${m("funds_authorized")}" + (if (d.has("funds_pct")) " (${d.optInt("funds_pct")}%)" else "")
        out += "Funds remaining: ${m("funds_remaining")}"
    }
    if (byFunds) {
        out += "The authorized funds, not the hours, are the limit: mileage and expenses are using them up faster, so they run out before the hours do."
        if (d.optDouble("weekly_spend", 0.0) > 0) out += "Recent spending: ${m("weekly_spend")} per week"
    } else if (d.optDouble("weekly_rate", 0.0) > 0) out += "Recent pace: %.2f hours per week".format(java.util.Locale.US, d.optDouble("weekly_rate", 0.0))
    d.str("run_out")?.let { out += (if (byFunds) "Projected run-out of funds: " else "Projected run-out of hours: ") + it }
    d.str("expiration")?.let { out += (if (flag(d, "expired")) "Expired on: " else "Expires: ") + it }
    if (flag(d, "expired")) out += "The authorization has already expired, so no run-out date is projected."
    out += "Suggested top-up: " + if (byFunds) "${m("suggest_funds")} in additional authorized funds" else "${d.optInt("suggest_add")} hours"
    return out
}

@Composable
private fun RecordAmendmentSection(api: Api, trackId: Int, d: JSONObject, letter: String, onDone: (String) -> Unit) {
    val todayIs = d.optString("today", today())
    var sentOn by rememberSaveable { mutableStateOf(todayIs) }
    var sentTo by rememberSaveable { mutableStateOf(d.optString("sent_to_default")) }
    var hours by rememberSaveable { mutableStateOf("") }
    var amount by rememberSaveable { mutableStateOf("") }
    var askEnd by rememberSaveable { mutableStateOf(false) }
    var newEnd by rememberSaveable { mutableStateOf("") }
    var idem by rememberSaveable { mutableStateOf("") }
    var errors by remember { mutableStateOf<Map<String, String>>(emptyMap()) }
    var error by remember { mutableStateOf<String?>(null) }
    var busy by remember { mutableStateOf(false) }
    var presses by remember { mutableIntStateOf(0) }
    val scope = rememberCoroutineScope()
    Heading("Record the request as sent")
    Text(AMENDMENT_INTRO)
    Field("Date sent", sentOn, { sentOn = it }, hint = "year-month-day; today or earlier", required = true, error = errors["requested_on"])
    Field("Sent to", sentTo, { sentTo = it }, error = errors["sent_to"])
    Field("Additional hours requested", hours, { hours = it }, keyboard = KeyboardType.Decimal, error = errors["hours"])
    Field("Additional dollars requested", amount, { amount = it }, keyboard = KeyboardType.Decimal, error = errors["amount"])
    Tick("New end date requested", askEnd) { askEnd = it; if (!it) newEnd = "" }
    if (askEnd) Field("New end date", newEnd, { newEnd = it }, hint = "year-month-day", required = true, error = errors["new_end"])
    errors["letter"]?.let { ErrorText(it, takeFocus = false) }
    ErrorText(error, focusKey = presses)
    PrimaryButton("Record request as sent", busy = busy) {
        if (busy) return@PrimaryButton
        presses++; error = null
        errors = listOfNotNull(pastDateError(sentOn, todayIs, SENT_ON_RULE)?.let { "requested_on" to it }).toMap()
        if (errors.isNotEmpty()) { error = errorSummary(errors.values); return@PrimaryButton }
        busy = true
        scope.launch {
            try {
                val res = api.callOnce("record_amendment", recordAmendmentBody(trackId, sentOn, sentTo, hours, amount, if (askEnd) newEnd else "", letter), idem, { idem = it }, "the amendment request")
                hours = ""; amount = ""; newEnd = ""; askEnd = false
                onDone(res.optString("message").ifEmpty { "Recorded: amendment requested." })
            } catch (e: ApiException) {
                errors = mapErrors(e.reply, ::amendmentFieldFor).filterKeys { it.isNotEmpty() }
                error = errorSummary(errors.values) ?: e.message
            } finally { busy = false }
        }
    }
}

@Composable
private fun AmendmentReplyForm(api: Api, trackId: Int, h: AmendmentRow, statuses: List<String>, todayIs: String, onDone: (JSONObject) -> Unit) {
    var status by rememberSaveable(h.id) { mutableStateOf("") }
    var on by rememberSaveable(h.id) { mutableStateOf(todayIs) }
    var note by rememberSaveable(h.id) { mutableStateOf("") }
    var errors by remember { mutableStateOf<Map<String, String>>(emptyMap()) }
    var error by remember { mutableStateOf<String?>(null) }
    var busy by remember { mutableStateOf(false) }
    var presses by remember { mutableIntStateOf(0) }
    val scope = rememberCoroutineScope()
    Choice("Reply", status, listOf("" to "Choose the reply") + statuses.map { it to it }, error = errors["status"], required = true) { status = it }
    Field("Date of the reply", on, { on = it }, hint = "year-month-day; today or earlier", required = true, error = errors["replied_on"])
    Field("Note", note, { note = it }, hint = "optional", singleLine = false)
    ErrorText(error, focusKey = presses)
    PrimaryButton("Record reply", busy = busy) {
        if (busy) return@PrimaryButton
        presses++; error = null
        errors = listOfNotNull(if (status.isEmpty()) "status" to "Choose the reply." else null,
            pastDateError(on, todayIs, REPLIED_ON_RULE)?.let { "replied_on" to it }).toMap()
        if (errors.isNotEmpty()) { error = errorSummary(errors.values); return@PrimaryButton }
        busy = true
        scope.launch {
            try { onDone(api.call("amendment_reply", body = replyBody(trackId, h.id, status, on, note))) }
            catch (e: ApiException) {
                errors = mapErrors(e.reply, ::amendmentFieldFor).filterKeys { it.isNotEmpty() }
                error = errorSummary(errors.values) ?: e.message
            } finally { busy = false }
        }
    }
}

/* ---------------- S14: letters ---------------- */

@Composable
fun LettersScreen(api: Api, nav: NavHostController, clientId: Int) {
    val context = LocalContext.current
    val files = rememberScreenFiles()
    var key by rememberSaveable { mutableStateOf("") }
    val state = rememberLoaded(clientId, key) { api.call("letters", if (key.isEmpty()) mapOf("client_id" to clientId.toString()) else mapOf("client_id" to clientId.toString(), "key" to key)) }
    var title by rememberSaveable { mutableStateOf("") }
    var body by rememberSaveable { mutableStateOf("") }
    var lang by rememberSaveable { mutableStateOf("en") }
    var toPortal by rememberSaveable { mutableStateOf(false) }
    var openedKey by rememberSaveable { mutableStateOf<String?>(null) }
    var openedBody by rememberSaveable { mutableStateOf("") }
    var openedTitle by rememberSaveable { mutableStateOf("") }
    var askSwitch by rememberSaveable { mutableStateOf<String?>(null) }
    var askDiscard by rememberSaveable { mutableStateOf(false) }
    var aiDraft by rememberSaveable { mutableStateOf<String?>(null) }
    var myLetter by rememberSaveable { mutableStateOf<String?>(null) }
    var idem by rememberSaveable { mutableStateOf("") }
    var opened by remember { mutableStateOf<String?>(null) }
    var notice by remember { mutableStateOf<String?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    var errors by remember { mutableStateOf<Map<String, String>>(emptyMap()) }
    var busy by remember { mutableStateOf(false) }
    var aiBusy by remember { mutableStateOf(false) }
    var presses by remember { mutableIntStateOf(0) }
    val scope = rememberCoroutineScope()
    // A letter opened (or opened again): its filled-in wording, unless this one is already open (a rotation).
    LaunchedEffect(state.data) {
        val r = state.data ?: return@LaunchedEffect
        val k = r.optString("key")
        if (openedKey != k) {
            if (openedKey != null) opened = (r.objectsOf("letters").firstOrNull { it.optString("key") == k }?.optString("name") ?: "The letter") + " opened."
            openedKey = k
            title = r.optString("title"); body = r.optString("body"); lang = r.optString("lang", "en").ifEmpty { "en" }
            openedBody = body; openedTitle = title; aiDraft = null; myLetter = null
        }
    }
    val edited = body != openedBody || title != openedTitle
    HoldBackWhileSaving(busy)
    androidx.activity.compose.BackHandler(enabled = edited && !busy && !askDiscard) { askDiscard = true }
    Announce(opened)
    Show(state, "the letters") { r ->
        val letters = r.objectsOf("letters").map { it.optString("key") to it.optString("name") }.ifEmpty { LETTERS_BUILT_IN }
        // The letter shown: the one chosen, or the one the server opened first.
        val shownKey = key.ifEmpty { r.optString("key") }
        val name = letters.firstOrNull { it.first == shownKey }?.second ?: "Letter"
        fun save(how: String) {
            if (busy) return
            presses++; busy = true; error = null; notice = null; errors = emptyMap()
            scope.launch {
                try {
                    val res = api.callOnce("save_letter", letterBody(clientId, how, title, body, lang, toPortal), idem, { idem = it }, "the letter")
                    // Saved: the screen closes, so it cannot be saved twice.
                    Flash.pending = closeAfterSend(res, if (how == "note") "The letter was kept as a case note." else "The letter was saved to the client's documents.")
                    openedBody = body; openedTitle = title
                    nav.popBackStack()
                } catch (e: ApiException) {
                    errors = mapErrors(e.reply, ::letterFieldFor).filterKeys { it.isNotEmpty() }
                    error = errorSummary(errors.values) ?: e.message
                } finally { busy = false }
            }
        }
        Page {
            NoticeText(notice)
            ErrorText(error, focusKey = presses)
            Choice("Letter", shownKey, letters) { k ->
                if (k == shownKey) return@Choice
                if (switchLetterAsk(edited, letters.firstOrNull { it.first == k }?.second ?: "letter") != null) askSwitch = k else { notice = null; key = k }
            }
            Text(LETTER_FILLED_IN)
            Field(LETTER_TITLE_LABEL, title, { title = it }, error = errors["title"], enabled = !aiBusy)
            Choice(letterLangLabel(flag(r, "prefers_spanish")), lang, listOf("en" to "English", "es" to "Spanish"), error = errors["lang"]) { lang = it }
            Field("Letter", body, { body = it }, singleLine = false, minLines = 10, required = true, error = errors["body"], lang = if (lang == "es") "es" else null, enabled = !aiBusy)
            aiDraft?.let { draftText ->
                Heading("AI draft")
                Text(AI_SEPARATE_NOTE)
                SelectionContainer { Text(draftText) }
                SecondaryButton("Use the AI draft as the letter") { myLetter = body; body = draftText; aiDraft = null; notice = "The AI draft is now the letter. Review and edit it before saving." }
            }
            myLetter?.let { mine -> SecondaryButton("Restore my letter") { body = mine; myLetter = null; notice = "Your letter is back." } }
            Tick("Let the client read it in their portal", toPortal) { toPortal = it }
            PrimaryButton("Save to the client's documents", busy = busy, busyText = "Saving…") { save("doc") }
            SecondaryButton("Keep as a case note", enabled = !busy) { save("note") }
            SecondaryButton("Share the letter", enabled = !busy) {
                error = null
                val shownTitle = title.trim().ifEmpty { name }
                scope.launch { presses++; error = shareHtml(api, context, letterHtml(shownTitle, body, lang), "letter-$clientId", "$shownTitle.html", "Share the letter", files) }
            }
            if (flag(r, "ai", true) && api.aiHas("draft")) SecondaryButton(if (aiBusy) "Drafting…" else "Draft the letter with AI") {
                if (aiBusy) return@SecondaryButton
                aiBusy = true; error = null; notice = null
                val sent = body
                scope.launch {
                    try {
                        val res = api.call("ai_letter", body = JSONObject().put("client_id", clientId).put("key", shownKey).put("body", sent).put("edited", sent != openedBody))
                        val text = res.optString("text")
                        if (flag(res, "separate")) { aiDraft = text; myLetter = null }
                        else if (body == sent) { body = text; openedBody = text; aiDraft = null }
                        else body = mergeDraft(body, text)
                        notice = listOfNotNull(res.str("message"), if (flag(res, "truncated")) AI_INCOMPLETE else null, placeholderNote(text)).joinToString(" ")
                    } catch (e: ApiException) { presses++; error = e.message } finally { aiBusy = false }
                }
            }
            Announce(if (aiBusy) "Drafting…" else null, key = aiBusy)
        }
        askSwitch?.let { k ->
            val other = letters.firstOrNull { it.first == k }?.second ?: "letter"
            Confirm(switchLetterAsk(true, other) ?: "", "", "Open it", { askSwitch = null; notice = null; key = k }, { askSwitch = null }, no = "Keep my letter")
        }
    }
    if (askDiscard) Confirm(LETTER_DISCARD, "What you changed in this letter is not saved.", "Discard", { askDiscard = false; openedBody = body; openedTitle = title; nav.popBackStack() },
        { askDiscard = false }, no = "Keep editing")
}

/* ---------------- S18: the client timeline ---------------- */

@Composable
fun ClientTimelineScreen(api: Api, clientId: Int) {
    val state = rememberLoaded(clientId) { api.call("client_timeline", mapOf("client_id" to clientId.toString())) }
    Show(state, "the timeline") { r ->
        Page {
            val days = r.objectsOf("status_days")
            Heading("Time in each status")
            r.str("status_summary")?.let { Text(it) }
            if (days.isEmpty()) Text("None.")
            days.forEach { ListRow(listOf(it.str("text") ?: "${it.optString("status")}: ${it.optInt("days")} days")) }
            val events = parseTimeline(r)
            Heading("Events (${r.optInt("total", events.size)}), newest first")
            if (events.isEmpty()) Text("None.")
            timelineGroups(events).forEach { (month, list) ->
                Heading(month)
                list.forEach { ListRow(listOf(it.text)) }
            }
        }
    }
}

/* ---------------- S22: instructor matching ---------------- */

@Composable
fun AtMatchesScreen(api: Api) {
    var expertise by rememberSaveable { mutableStateOf("") }
    var language by rememberSaveable { mutableStateOf("") }
    var area by rememberSaveable { mutableStateOf("") }
    var week by rememberSaveable { mutableStateOf(today()) }
    var mode by rememberSaveable { mutableStateOf("") }
    var resultText by rememberSaveable { mutableStateOf<String?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    var dateError by remember { mutableStateOf<String?>(null) }
    var busy by remember { mutableStateOf(false) }
    var presses by remember { mutableIntStateOf(0) }
    val scope = rememberCoroutineScope()
    Page {
        Field("Expertise needed", expertise, { expertise = it }, hint = "comma-separated; optional")
        Field("Language / communication", language, { language = it }, hint = "optional")
        Field("Service area", area, { area = it }, hint = "optional")
        Field("Week starting", week, { week = it; dateError = null }, hint = "year-month-day", error = dateError)
        Choice("Delivery mode", mode, MATCH_MODES) { mode = it }
        ErrorText(error, focusKey = presses)
        PrimaryButton("Find matches", busy = busy) {
            if (busy) return@PrimaryButton
            presses++; busy = true; error = null; dateError = null
            scope.launch {
                try {
                    resultText = api.call("at_matches", matchesBody(expertise, language, area, week, mode)).toString()
                } catch (e: ApiException) {
                    if (e.message == "Choose a valid matching date.") dateError = e.message
                    error = e.message; resultText = null
                } finally { busy = false }
            }
        }
        resultText?.let { t ->
            val list = JSONObject(t).objectsOf("matches")
            NoticeText(matchesCount(list.size))
            list.forEach { ListRow(matchLine(it)) }
        }
    }
}

/* ---------------- S15, S17: the client record's releases, privacy notice and portal link ---------------- */

/** The release rows with their state and Record withdrawn (from a 4.37.0 server). */
@Composable
fun ReleasesSection(api: Api, clientId: Int, r: JSONObject, onChanged: (String) -> Unit) {
    val rows = r.objectsOf("releases")
    var asking by rememberSaveable { mutableIntStateOf(0) }
    var error by remember { mutableStateOf<String?>(null) }
    var presses by remember { mutableIntStateOf(0) }
    val scope = rememberCoroutineScope()
    Heading("Releases of information (${rows.size})")
    if (rows.isEmpty()) Text("No releases.")
    rows.forEach { rel ->
        val name = rel.optString("recipient_name")
        ListRow(listOf(name + (rel.str("recipient_type")?.let { " ($it)" } ?: ""),
            rel.str("text") ?: listOfNotNull(rel.str("status"), rel.str("expires_on")?.let { "ends " + dayText(it) }).joinToString(", "), rel.str("info_scope") ?: ""))
        if (flag(rel, "can_withdraw") && api.can("clients")) SecondaryButton("Record withdrawn: $name") { error = null; asking = rel.optInt("id") }
    }
    ErrorText(error, focusKey = presses)
    rows.firstOrNull { asking > 0 && it.optInt("id") == asking }?.let { rel ->
        Confirm(withdrawReleaseTitle(rel.optString("recipient_name")), RELEASE_WITHDRAW_TEXT, "Record withdrawn", {
            scope.launch {
                try { onChanged(api.call("withdraw_release", body = JSONObject().put("client_id", clientId).put("release_id", rel.optInt("id"))).optString("message").ifEmpty { "Release recorded as withdrawn. Nothing more is shared under it." }) }
                catch (e: ApiException) { presses++; error = e.message }
            }
        }, { asking = 0 })
    }
}

/** The privacy notice: when it was given, and recording it. Hidden on an older server. */
@Composable
fun PrivacyNoticeSection(api: Api, clientId: Int, r: JSONObject, onChanged: (String) -> Unit) {
    val c = r.optJSONObject("client") ?: JSONObject()
    val p = r.optJSONObject("privacy_notice") ?: c.optJSONObject("privacy_notice") ?: return
    val methods = (r.stringList("privacy_notice_methods") ?: c.stringList("privacy_notice_methods")).orEmpty().ifEmpty { PRIVACY_METHODS_BUILT_IN }
    var how by rememberSaveable { mutableStateOf("") }
    var error by remember { mutableStateOf<String?>(null) }
    var howError by remember { mutableStateOf<String?>(null) }
    var busy by remember { mutableStateOf(false) }
    var presses by remember { mutableIntStateOf(0) }
    val scope = rememberCoroutineScope()
    Heading("Privacy notice")
    Text(p.optString("text"))
    if (!api.can("clients")) return
    Choice("Privacy notice given", how, listOf("" to "Choose how") + methods.map { it to it }, error = howError, required = true) { how = it; howError = null }
    ErrorText(error, focusKey = presses)
    PrimaryButton("Record notice given", busy = busy) {
        if (busy) return@PrimaryButton
        presses++; busy = true; error = null; howError = null
        scope.launch {
            try { onChanged(api.call("record_privacy_notice", body = JSONObject().put("client_id", clientId).put("how", how)).optString("message")); how = "" }
            catch (e: ApiException) { howError = replyErrors(e.reply)["how"]; error = howError ?: e.message } finally { busy = false }
        }
    }
}

/** The portal link's state, and Turn off portal link when one is active. Hidden on an older server. */
@Composable
fun PortalLinkSection(api: Api, clientId: Int, name: String, r: JSONObject, onChanged: (String) -> Unit) {
    val c = r.optJSONObject("client") ?: JSONObject()
    val p = r.optJSONObject("portal_link") ?: c.optJSONObject("portal_link") ?: return
    var asking by rememberSaveable { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    var presses by remember { mutableIntStateOf(0) }
    val scope = rememberCoroutineScope()
    Heading("Client portal")
    p.str("text")?.let { Text(it) }
    if (p.optString("state") == "active" && api.can("clients")) SecondaryButton("Turn off portal link") { error = null; asking = true }
    ErrorText(error, focusKey = presses)
    if (asking) Confirm(portalOffTitle(name), PORTAL_OFF_TEXT, "Turn off portal link", {
        scope.launch {
            try { onChanged(api.call("revoke_portal_link", body = JSONObject().put("client_id", clientId)).optString("message")) }
            catch (e: ApiException) { presses++; error = e.message }
        }
    }, { asking = false })
}

/* ---------------- S10: record progress from the AI review ---------------- */

@Composable
fun ReviewGoalRows(goals: List<ReviewGoal>, onRecord: (ReviewGoal) -> Unit) {
    Heading("Record progress")
    if (goals.isEmpty()) Text("No active goals.")
    goals.forEach { g ->
        ListRow(listOf(goalRowText(g)))
        SecondaryButton(recordProgressLabel(g)) { onRecord(g) }
    }
}

/* ---------------- S20: closed in the last 90 days ---------------- */

@Composable
fun PipelineClosedSection(r: JSONObject, nav: NavHostController) {
    val closed = parsePipelineClosed(r) ?: return
    Heading(closedHeading(closed.size))
    if (closed.isEmpty()) Text("None.")
    closed.forEach { c -> ListRow(listOf(c.name, c.status), action = "open it", onClick = { nav.navigate("client/${c.id}") }) }
}

/* ---------------- S11: the assessment's report attachment ---------------- */

const val ATTACHMENT_UNREADABLE = "The attachment could not be read. Choose it again."

/** The size the picker reports for a chosen file, or -1 when it does not say. */
fun pickedSize(context: Context, uri: android.net.Uri): Long =
    try { context.contentResolver.query(uri, arrayOf(android.provider.OpenableColumns.SIZE), null, null, null)?.use { c -> if (c.moveToFirst() && !c.isNull(0)) c.getLong(0) else -1L } ?: -1L }
    catch (e: Exception) { -1L }

private fun attachmentRefusal(message: String) = ApiException(message, 422, reply = JSONObject().put("errors", JSONObject().put("attachment", message)))

/**
 * Sends add_assessment with the attachment streamed from disk as base64 (as uploads are), never
 * held whole in memory: refused over 15 MB before it is read, copied to the app's temporary
 * folder, and removed after.
 */
suspend fun addAssessmentWithAttachment(api: Api, context: Context, fields: JSONObject, uri: android.net.Uri, name: String, key: String, setKey: (String) -> Unit): JSONObject {
    val limit = 15L * 1024 * 1024
    if (pickedSize(context, uri) > limit) throw attachmentRefusal(ATTACHMENT_TOO_LARGE)
    val tmp = java.io.File(api.docsFolder, "attachment.tmp")
    try {
        val copied = copyPicked(context, uri, tmp)
        if (copied == -2L || copied == 0L) throw attachmentRefusal(ATTACHMENT_UNREADABLE)
        if (copied < 0 || tmp.length() > limit) throw attachmentRefusal(ATTACHMENT_TOO_LARGE)
        val short = shortName(name)
        val print = "add_assessment|" + fields.toString() + "|" + short + "|" + tmp.length() + "|" + uri
        return org.casemanagement.app.sendOnce(key, setKey, print, "the assessment") { k ->
            api.postWithNestedFile("add_assessment", JSONObject(fields.toString()).put("idem_key", k), "attachment", short, tmp, "The assessment was not saved.")
        }
    } finally { tmp.delete() }
}

/** Downloads an assessment's attachment and opens it in another app; why it could not, or null. */
suspend fun openAssessmentAttachment(api: Api, context: Context, id: Int, files: MutableList<java.io.File>): String? {
    if (!api.store.phoneCanKeepClientData) return NO_LOCK_FILES
    val r = api.call("download_assessment_attachment", mapOf("id" to id.toString()))
    val bytes = try { java.util.Base64.getDecoder().decode(r.optString("data")) } catch (e: IllegalArgumentException) { return "The attachment could not be read from the server. Try again." }
    val name = safeFileName("assessment-$id", r.str("name"), "attachment")
    val (f, err) = writeScreenFile(api, name, bytes, files)
    return err ?: offerFile(context, f!!, r.str("mime") ?: attachmentMime(name), false, "Open with")
}
