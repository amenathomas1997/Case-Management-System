package org.casemanagement.app.ui

import org.casemanagement.app.EquipmentItem
import org.casemanagement.app.objects
import org.casemanagement.app.str
import org.json.JSONArray
import org.json.JSONObject

/*
 * 4.37.0: the pure parts of the release's screens (request bodies, wording, error mapping and
 * grouping), kept apart from Compose so V437Test can check them against the server's replies.
 * Every sentence here is the website's, as the iPhone app says it.
 */

/* ---------------- S1: equipment status ---------------- */

/** The status moves the server offers, as the buttons that make them: (status sent, button label), in the server's order. */
fun statusButtons(actions: List<String>, name: String): List<Pair<String, String>> = actions.mapNotNull { to ->
    when (to) {
        "Repair" -> to to "Mark in repair"
        "Available" -> to to "Back in service"
        "Retired" -> to to "Retire $name"
        else -> null
    }
}

const val RETIRE_FIRST_SENTENCE = "A retired item can no longer be loaned out or reserved. It returns to service only through a Device reuse intake, which records its inspection, sanitizing and data wipe."
const val RETIRED_ROW_TEXT = "This item is retired. Start a Device reuse intake for it in AT services to put it back into service."

fun retireConfirmTitle(name: String): String = "Retire $name?"

/** The Retire confirmation's text: the website's first sentence, then the server's holds sentence. */
fun retireConfirmText(name: String, retireText: String?): String =
    listOf(RETIRE_FIRST_SENTENCE, retireText?.trim().orEmpty()).filter { it.isNotEmpty() }.joinToString(" ")
        .ifEmpty { "Retire $name." }

fun statusBody(id: Int, to: String, confirm: Boolean): JSONObject =
    JSONObject().put("equipment_id", id).put("to", to).put("confirm_retire", confirm)

/* ---------------- S2: retired items ---------------- */

/** The list's groups, as the website and the iPhone: On loan (overdue first), Available, Not available (retired last). */
data class EquipmentGroups(val onLoan: List<EquipmentItem>, val available: List<EquipmentItem>, val notAvailable: List<EquipmentItem>)

fun equipmentGroups(rows: List<EquipmentItem>): EquipmentGroups {
    val onLoan = rows.filter { it.status == "On Loan" }.sortedByDescending { it.overdue }
    val available = rows.filter { it.status == "Available" && !it.retired }
    val rest = rows.filter { it !in onLoan && it !in available }
    return EquipmentGroups(onLoan, available, rest.filter { !it.retired } + rest.filter { it.retired })
}

/** What a row in "Not available" says: its name and status; a retired item's name ends ", retired". */
fun notAvailableLines(e: EquipmentItem): List<String> = if (e.retired) listOf("${e.name}, retired") else listOf(e.name, e.status)

/** The only thing a retired row offers in the list. */
fun retiredRowActions(): List<String> = listOf("Service and details")

/* ---------------- S3: a new item's purchase date, useful life and AT type ---------------- */

const val AT_TYPE_GUESSED = "Guessed from the category"
val AT_TYPES_BUILT_IN = listOf(
    "Vision", "Hearing", "Speech communication", "Learning, cognition and developmental", "Mobility, seating and positioning",
    "Daily living", "Environmental adaptations", "Vehicle modification and transportation", "Computers and related", "Recreation, sports and leisure",
)

/** "AT Act type": "Guessed from the category" ("") first, then the server's types (the ten built in when it sends none). */
fun atTypeChoices(list: List<String>): List<Pair<String, String>> =
    listOf("" to AT_TYPE_GUESSED) + list.ifEmpty { AT_TYPES_BUILT_IN }.map { it to it }

/** add_equipment's body: a blank useful life or purchase date is left out (the server's default, 36 months, applies). */
fun equipmentBody(
    name: String, category: String, serial: String, condition: String, value: String, intervalDays: Int, notes: String,
    purchaseDate: String, usefulLife: String, atType: String,
): JSONObject {
    val b = JSONObject().put("name", name.trim()).put("category", category).put("serial", serial.trim())
        .put("condition", condition).put("value", value).put("maintenance_interval_days", intervalDays).put("notes", notes.trim())
    purchaseDate.trim().takeIf { it.isNotEmpty() }?.let { b.put("purchase_date", it) }
    usefulLife.trim().takeIf { it.isNotEmpty() }?.let { b.put("useful_life_months", it) }
    if (atType.isNotEmpty()) b.put("at_type", atType)
    return b
}

/** The form field that shows a server error key from add_equipment. */
fun equipmentFieldFor(key: String): String = when (key) {
    "maintenance_interval_days" -> "interval"
    "useful_life_months" -> "life"
    "purchase_date" -> "purchase"
    else -> key
}

/** A refusal's errors, keyed by the form's own field names. */
fun mapErrors(reply: JSONObject?, fieldFor: (String) -> String): Map<String, String> =
    replyErrors(reply).entries.associate { fieldFor(it.key) to it.value }

/** The strings of a reply list, or null when the key is absent (an older server). */
fun JSONObject.stringList(key: String): List<String>? {
    val a = optJSONArray(key) ?: return null
    return (0 until a.length()).mapNotNull { a.optString(it).takeIf { s -> s.isNotEmpty() } }
}

internal fun jsonStrings(items: List<String>): JSONArray = JSONArray().apply { items.forEach { put(it) } }

internal fun JSONObject.objectsOf(key: String): List<JSONObject> = optJSONArray(key).objects()

internal fun JSONObject.textOr(key: String, fallback: String = ""): String = str(key) ?: fallback

/** A reply flag sent as true/false or 1/0. */
fun flag(o: JSONObject?, key: String, fallback: Boolean = false): Boolean {
    if (o == null || !o.has(key) || o.isNull(key)) return fallback
    return when (val v = o.opt(key)) {
        is Boolean -> v
        is Number -> v.toInt() != 0
        is String -> v == "1" || v.equals("true", ignoreCase = true)
        else -> fallback
    }
}

/* ---------------- S4: device requests ---------------- */

const val CLOSE_REQUEST_TEXT = "It is closed until someone reopens it."
const val CLOSE_REASON_LABEL = "Reason"
const val CLOSE_REASON_HINT = "optional; up to 500 characters"
const val CLOSE_REASON_TOO_LONG = "Reason: use 500 characters or fewer."
const val INVENTORY_TICK = "Also add to the loaner inventory"
const val LETTER_EDIT_NOTE = "Change the justification on the request, then open the letter again."

fun closeTitle(device: String, to: String): String = "Close $device as ${if (to == "Denied") "denied" else "cancelled"}?"
fun closeYes(to: String): String = if (to == "Denied") "Mark denied" else "Cancel request"

/** Deny or cancel, with the reason (an empty reason clears the stored note, as the website's form). */
fun closeBody(to: String, reason: String): JSONObject = JSONObject().put("op", "status").put("to", to).put("notes", reason.trim())

/** The delivery step: add_to_inventory goes only with the equipment permission (the server reads it only then too). */
fun deliverBody(fields: JSONObject, inventory: Boolean, canEquipment: Boolean): JSONObject {
    val b = JSONObject(fields.toString()).put("op", "deliver")
    if (canEquipment) b.put("add_to_inventory", inventory)
    return b
}

/* ---------------- S5: Draft justification with AI ---------------- */

const val JUSTIFICATION_LABEL = "Justification of need"
const val JUSTIFICATION_DRAFTED = "Justification drafted. Review and edit it before creating the request."
const val JUSTIFICATION_SHORTENED = " The draft was shortened to fit 4,000 characters."
const val AI_INCOMPLETE = "Incomplete: the AI stopped before the end. Review it closely."

/** An AI draft never replaces typed text: an empty field gets the draft, otherwise it goes below, after one line break. */
fun mergeDraft(typed: String, draft: String): String = if (typed.isBlank()) draft else typed.trimEnd() + "\n" + draft

fun justificationBody(clientId: Int, device: String, category: String, text: String): JSONObject =
    JSONObject().put("type", "justification").put("client_id", clientId).put("device_name", device.trim()).put("category", category).put("text", text)

/** What is said once a justification draft arrives. */
fun justificationNotice(r: JSONObject): String =
    JUSTIFICATION_DRAFTED + (if (flag(r, "shortened")) JUSTIFICATION_SHORTENED else "") + (if (flag(r, "truncated")) " $AI_INCOMPLETE" else "")

/* ---------------- S6: recommendations ---------------- */

const val RECOMMEND_FOOTER = "Review the recommendations, then start a device request for any you want to pursue."
const val RECOMMEND_FOOTER_NO_REQUEST = "Recommendations drafted — review them before using any."

fun recommendFooter(canProcurement: Boolean): String = if (canProcurement) RECOMMEND_FOOTER else RECOMMEND_FOOTER_NO_REQUEST

/** The justification a request starts with: the recommendations, at most 4,000 characters, cut at a line end. */
fun startRequestPrefill(text: String): String {
    val t = text.trim()
    if (t.length <= 4000) return t
    val cut = t.take(4000)
    val end = cut.lastIndexOf('\n')
    return (if (end > 0) cut.take(end) else cut).trimEnd()
}

fun startRequestLabel(client: String): String = "Start a device request for $client"

/* ---------------- S7: the AT evaluation report ---------------- */

val EVAL_SECTIONS = listOf("background" to "Background", "findings" to "Evaluation findings", "recommendations" to "Recommendations", "justification" to "Justification of need")
const val EVAL_HINT = "“Draft narrative with AI” fills only the sections above that are empty or still hold the prefilled text; a section you have written in is kept as it is. To have it drafted, clear it first. Review and edit every section before sending."
const val EVAL_SIGNED_TEXT = "It is signed by you and logged on the client's file."

fun evalButtonLabel(date: String): String = "Evaluation report for the assessment of $date"
fun evalSignatureLabel(name: String): String = "Electronic signature: type your full name ($name) (required to email)"
fun evalConfirmTitle(to: String): String = "Email the signed evaluation report to ${to.trim()}?"

/** The four sections as the server reads them. */
fun evalSectionsBody(map: Map<String, String>): JSONObject =
    JSONObject().also { o -> EVAL_SECTIONS.forEach { (k, _) -> o.put(k, map[k] ?: "") } }

/** The field a send_eval_report error key belongs to (the sections, the recipient, the signature); "" for none. */
fun evalFieldFor(key: String): String = if (key in setOf("to", "signature") || EVAL_SECTIONS.any { it.first == key }) key else ""

/** eval_report's (or ai_eval_draft's) sections, by key. */
fun evalSections(r: JSONObject): Map<String, String> =
    r.objectsOf("sections").associate { it.optString("key") to it.optString("text") }

/* ---------------- S8: worksite ---------------- */

const val WORKSITE_DRAFTED = "Recommendations drafted. Read and edit them, then save the assessment."
const val WORKSITE_SAVE_FIRST = "Save the assessment first so the report includes your changes."

fun worksiteAiBody(id: Int, jobTitle: String, jobTasks: String, barriers: String, recommendations: String): JSONObject =
    JSONObject().put("id", id).put("job_title", jobTitle).put("job_tasks", jobTasks).put("barriers", barriers).put("recommendations", recommendations)

/* ---------------- S9: goals, format ---------------- */

const val GOALS_TITLE = "AI goal suggestions (draft)"
const val GOALS_INTRO = "Copy any of these into a training track's goal form and edit as needed."

fun goalsButtonLabel(date: String): String = "Suggest goals with AI for the assessment of $date"

fun formatBody(clientId: Int, format: String, body: String, sections: List<String>): JSONObject =
    JSONObject().put("type", "format").put("client_id", clientId).put("format", format).put("body", body)
        .put("sections", jsonStrings(List(4) { sections.getOrNull(it) ?: "" }))

/** The reply's sections: each one with text replaces what is there; an empty one keeps what was typed. */
fun applyFormatted(old: List<String>, new: List<String>): List<String> =
    List(4) { i -> new.getOrNull(i)?.takeIf { it.isNotBlank() } ?: old.getOrNull(i).orEmpty() }

fun formattedMessage(format: String): String =
    "Formatted as $format — review each section, then save. Text left in the narrative box is saved after the sections under \"Narrative\" — clear that box if the sections already cover it."

/* ---------------- S10: progress per goal ---------------- */

const val PROGRESS_CAPTION = "Reviews recent notes and sessions against each active goal. Record any progress below."

data class ReviewGoal(val id: Int, val text: String, val trackId: Int, val track: String, val status: String, val pct: Int)

fun parseReviewGoals(r: JSONObject): List<ReviewGoal>? = r.optJSONArray("goals")?.objects()?.map {
    ReviewGoal(it.optInt("id"), it.optString("text"), it.optInt("track_id"), it.optString("track"), it.optString("status"), it.optInt("pct"))
}

fun goalRowText(g: ReviewGoal): String = "${g.text}, ${g.track}: ${g.pct}% (${g.status})"
fun recordProgressLabel(g: ReviewGoal): String = "Record progress for " + g.text.take(60)
fun progressNotice(): String = "Incomplete: this review was cut short, so goals after the last one it mentions were not reviewed."

/* ---------------- S11: templates and attachments ---------------- */

val TEMPLATE_KEYS = listOf("primary_tech", "hardware_details", "software_version", "summary")
const val ATTACHMENT_LABEL = "Report attachment (optional; PDF, Word, text, or image, 15 MB max)"
val ATTACHMENT_TYPES = setOf("pdf", "doc", "docx", "txt", "rtf", "png", "jpg", "jpeg")
const val ATTACHMENT_NOT_ALLOWED = "Attachment type not allowed. Use PDF, Word, text, or image files."
const val ATTACHMENT_TOO_LARGE = "Attachment is too large (15 MB maximum)."

/** A template's fill: [values] with only the empty fields filled, and whether to ask (something is typed in them). */
data class TemplateFill(val values: Map<String, String>, val asks: Boolean)

fun templateFill(current: Map<String, String>, tpl: Map<String, String>): TemplateFill {
    val typed = TEMPLATE_KEYS.any { k -> current[k].orEmpty().isNotBlank() && current[k] != tpl[k] }
    return TemplateFill(TEMPLATE_KEYS.associateWith { k -> current[k].orEmpty().ifBlank { tpl[k].orEmpty() } }.let { current + it }, typed)
}

/** "Replace": every template field replaces what was typed (an empty template field keeps it). */
fun templateReplace(current: Map<String, String>, tpl: Map<String, String>): Map<String, String> =
    current + TEMPLATE_KEYS.associateWith { k -> tpl[k].orEmpty().ifBlank { current[k].orEmpty() } }

fun templateAsk(name: String): String = "Replace what you typed with the template $name?"

fun attachmentAllowed(name: String): Boolean = name.substringAfterLast('.', "").lowercase() in ATTACHMENT_TYPES

fun attachmentMime(name: String): String = when (name.substringAfterLast('.', "").lowercase()) {
    "pdf" -> "application/pdf"; "doc" -> "application/msword"
    "docx" -> "application/vnd.openxmlformats-officedocument.wordprocessingml.document"
    "txt" -> "text/plain"; "rtf" -> "application/rtf"; "png" -> "image/png"; "jpg", "jpeg" -> "image/jpeg"
    else -> "application/octet-stream"
}

val ATTACHMENT_MIMES = arrayOf("application/pdf", "application/msword", "application/vnd.openxmlformats-officedocument.wordprocessingml.document",
    "text/plain", "application/rtf", "text/rtf", "image/png", "image/jpeg")

/* ---------------- S12, S13: the amendment letter, the request and the reply ---------------- */

const val AMENDMENT_ROW = "Authorization burn-down & amendment"
const val COUNSELOR_UNCONFIRMED = "Counselor email not confirmed — confirm it on the client's profile before emailing this letter."
const val REPLACE_DRAFT_TITLE = "Replace the letter with a new AI draft? Your edits to it will be lost."
const val AI_SEPARATE_NOTE = "Your letter is unchanged. The AI draft is in a separate box below it — review it, and use it only if you want it."
const val AMENDMENT_INTRO = "After you send the letter, record it here. It is kept on the track as a case note, and a follow-up task reminds you if no reply comes."

/** mailto: for "Email counselor", with the iPhone's subject. */
fun amendmentMailto(email: String, client: String, letter: String): String =
    "mailto:" + enc(email.trim()) + "?subject=" + enc("Authorization amendment request — $client") + "&body=" + enc(letter)

private fun enc(s: String): String = java.net.URLEncoder.encode(s, "UTF-8").replace("+", "%20")

/** The track edit with the approved amendment linked and its reason filled in. */
fun trackEditRoute(clientId: Int, trackId: Int, amendRequest: Int, reason: String): String =
    "trackEdit/$clientId/$trackId?amend=$amendRequest&reason=" + enc(reason)

/**
 * Whether "Draft with AI" asks before replacing the letter: only when it was edited and the server
 * cannot keep it (an older server, with no separate AI draft).
 */
fun replaceDraftAsk(edited: Boolean, separateBox: Boolean = false): Boolean = edited && !separateBox

fun recordAmendmentBody(trackId: Int, requestedOn: String, sentTo: String, hours: String, amount: String, newEnd: String, letter: String): JSONObject =
    JSONObject().put("track_id", trackId).put("requested_on", requestedOn.trim()).put("sent_to", sentTo.trim()).put("hours", hours.trim())
        .put("amount", amount.trim()).put("new_end", newEnd.trim()).put("letter", letter)

/** The amendment form's field for a server error key (all of them are the server's own keys). */
fun amendmentFieldFor(key: String): String = if (key in setOf("requested_on", "hours", "amount", "new_end", "letter", "sent_to", "status", "replied_on")) key else ""

fun replyBody(trackId: Int, requestId: Int, status: String, repliedOn: String, note: String): JSONObject =
    JSONObject().put("track_id", trackId).put("request_id", requestId).put("status", status).put("replied_on", repliedOn.trim()).put("note", note.trim())

data class AmendmentRow(val id: Int, val requestedOn: String, val status: String, val text: String, val awaiting: Boolean, val needsChange: Boolean,
    /** The track edit's amendment reason for an approved request (the server's, per row). */
    val reasonPrefill: String = "")

fun parseAmendmentHistory(r: JSONObject): List<AmendmentRow>? = r.optJSONArray("history")?.objects()?.map {
    AmendmentRow(it.optInt("id"), it.optString("requested_on"), it.optString("status"), it.optString("text"), flag(it, "awaiting"), flag(it, "needs_change"),
        it.str("reason_prefill").orEmpty())
}

const val AMENDMENT_NONE = "No amendment requests recorded yet."
const val SENT_ON_RULE = "Enter the date the request was sent (today or earlier)."
const val REPLIED_ON_RULE = "Enter the date of the reply (today or earlier)."

/** A required date that must be a real day, today or earlier: [message] when it is not. */
fun pastDateError(text: String, todayIs: String, message: String): String? = if (realDayNotFuture(text, todayIs, "1970-01-01")) null else message

/** The amendment letter after "Draft with AI", and the AI draft box. */
data class LetterDraft(val letter: String, val serverLetter: String, val aiDraft: String?)

/**
 * What a "Draft with AI" reply does to the amendment letter. [sent] is the letter when it was
 * asked, [current] the letter now. An edited letter is never replaced and stays edited: the draft
 * goes in the separate box, or (no ai_draft_text: the AI failed or is off) nothing changes. Only
 * an older server, after the person chose "Replace with a new draft", replaces it. An unedited
 * letter takes the draft, below anything typed meanwhile.
 */
fun amendmentDraftOutcome(sent: String, current: String, serverLetter: String, separateBox: Boolean, reply: JSONObject): LetterDraft {
    val edited = sent != serverLetter
    if (edited) {
        reply.str("ai_draft_text")?.let { return LetterDraft(current, serverLetter, it) }
        if (separateBox) return LetterDraft(current, serverLetter, null)
    }
    val draft = reply.optString("letter")
    if (draft.isBlank()) return LetterDraft(current, serverLetter, null)
    return if (current == sent) LetterDraft(draft, draft, null) else LetterDraft(mergeDraft(current, draft), serverLetter, null)
}

/** A one-field draft's text: the server's merge, or (when the field changed while the AI worked) the draft below what is there now. */
fun mergeAfterDraft(sent: String, current: String, merged: String, draft: String): String =
    if (current == sent) merged else if (draft.isBlank()) current else mergeDraft(current, draft)

/** The evaluation sections after a draft: a section changed while the AI worked keeps that text, with the draft below. */
fun mergeSections(sent: Map<String, String>, current: Map<String, String>, got: Map<String, String>): Map<String, String> =
    current + got.mapValues { (k, g) ->
        val was = sent[k].orEmpty(); val now = current[k].orEmpty()
        when { now == was -> g; g == was || g.isBlank() -> now; else -> mergeDraft(now, g) }
    }

/** A send or save that worked closes its screen, saying this on the screen it returns to (so it cannot be sent twice). */
fun closeAfterSend(reply: JSONObject?, fallback: String): String = reply?.str("message") ?: fallback

/** The referral list's heading: the server's (it already ends with the count), or the app's own. */
fun referralsHeadingText(serverHeading: String?, status: String, n: Int): String = serverHeading?.takeIf { it.isNotBlank() } ?: referralsHeading(status, n)

/* ---------------- shared files ---------------- */

/** A file name the app's own folder can hold: [prefix] (kind and record) keeps each screen's file apart. */
fun safeFileName(prefix: String, name: String?, fallback: String): String {
    val clean = name.orEmpty().substringAfterLast('/').replace(Regex("[^A-Za-z0-9._ -]"), "_").trim().trimStart('.').take(120)
    return "$prefix-" + clean.ifEmpty { fallback }
}

/* ---------------- letters: the website's labels and the file to share ---------------- */

const val LETTER_TITLE_LABEL = "Title (optional; blank uses the letter's name)"
const val LETTER_DISCARD = "Discard this letter?"

fun letterLangLabel(prefersSpanish: Boolean): String =
    "Language of the letter (" + (if (prefersSpanish) LETTER_SPANISH_HINT else "the wording below is in English") + ")"

private fun esc(s: String): String = s.replace("&", "&amp;").replace("<", "&lt;").replace(">", "&gt;").replace("\"", "&quot;")

/** The letter as an accessible HTML file to print or send (the website's download): its language, title and paragraphs. */
fun letterHtml(title: String, body: String, lang: String): String {
    val paras = body.replace("\r\n", "\n").split(Regex("\n\\s*\n")).filter { it.isNotBlank() }
        .joinToString("\n") { "<p>" + esc(it.trim()).replace("\n", "<br>") + "</p>" }
    return "<!doctype html>\n<html lang=\"${if (lang == "es") "es" else "en"}\"><head><meta charset=\"utf-8\"><title>${esc(title)}</title>" +
        "<style>body{font-family:sans-serif;font-size:1.1rem;line-height:1.5;max-width:40rem;margin:2rem auto;padding:0 1rem}</style></head><body>\n$paras\n</body></html>\n"
}

/* ---------------- S14: letters ---------------- */

val LETTERS_BUILT_IN = listOf(
    "welcome" to "Welcome letter", "appointment" to "Appointment confirmation", "no_show" to "Missed appointment",
    "loan" to "Equipment loan agreement", "return" to "Equipment return receipt",
    "referral_ack" to "Referral acknowledgement to the counselor", "completion" to "Training completion",
)
const val LETTER_FILLED_IN = "Filled in from the record. Check it and change anything before saving; \"[not on file]\" marks details the record does not have."
const val LETTER_SPANISH_HINT = "this client prefers Spanish; the wording below is in English, so choose Spanish if you write it in Spanish"

fun letterBody(clientId: Int, how: String, title: String, body: String, lang: String, toPortal: Boolean): JSONObject =
    JSONObject().put("client_id", clientId).put("how", how).put("title", title.trim()).put("body", body).put("lang", lang).put("to_portal", toPortal)

fun letterFieldFor(key: String): String = if (key in setOf("body", "title", "how", "lang")) key else ""

/** Opening another letter asks first only when this one was changed. */
fun switchLetterAsk(edited: Boolean, name: String): String? = if (edited) "Open the $name instead? Your changes to this letter are lost." else null

/* ---------------- S15: releases, consent, privacy notice ---------------- */

const val RELEASE_METHOD_EMAIL = "Email"
const val RELEASE_METHOD_PAPER = "Paper"
val RELEASE_METHODS = listOf(RELEASE_METHOD_EMAIL to "Email it to sign", RELEASE_METHOD_PAPER to "Record a paper release")
const val RELEASE_WITHDRAW_TEXT = "Nothing more is shared under it."
const val CONSENT_WITHDRAW_TEXT = "The consent stops counting as current, and a new one can then be requested."
val PRIVACY_METHODS_BUILT_IN = listOf("In person", "By email", "By mail", "In the portal", "Read aloud by phone")

/** request_release's body: method always, signed_on only for a paper release. */
fun releaseBody(method: String, signedOn: String, base: JSONObject): JSONObject {
    val b = JSONObject(base.toString()).put("method", method)
    if (method == RELEASE_METHOD_PAPER) b.put("signed_on", signedOn.trim()) else b.remove("signed_on")
    return b
}

/** The release form's field for a request_release error key. */
fun releaseFieldFor(key: String): String = when (key) {
    "signer_name" -> "name"; "signer_email" -> "email"; "recipient_type" -> "type"; "recipient_name" -> "recipient"; "info", "info_other" -> "info"
    else -> key
}

fun withdrawReleaseTitle(name: String): String = "Record that the release for $name is withdrawn?"
fun consentWithdrawTitle(name: String, date: String): String = "Record that $name has withdrawn the guardian consent signed on $date?"
fun consentWithdrawText(name: String, date: String): String = consentWithdrawTitle(name, date) + " " + CONSENT_WITHDRAW_TEXT

/* ---------------- S16: documents ---------------- */

val DOCUMENT_TYPES = setOf("pdf", "doc", "docx", "xls", "xlsx", "ppt", "pptx", "txt", "rtf", "csv", "png", "jpg", "jpeg")
const val DOCUMENT_TYPE_REFUSED = "That file type is not allowed. Use PDF, Office, text, or image files."
const val DOCUMENT_TRASH_TEXT = "An administrator can restore it for 30 days."

/** The website's upload types; HEIC is converted to JPEG before upload, so it is not one of them. */
fun uploadAllowed(ext: String): Boolean = ext.trim().trimStart('.').lowercase() in DOCUMENT_TYPES
fun isHeic(ext: String): Boolean = ext.trim().trimStart('.').lowercase() in setOf("heic", "heif")
const val HEIC_TOO_BIG = "This photo is too large for the phone to convert. Save it as a JPEG first, or upload it from the website."

/** The scale a HEIC photo is decoded at so its long side is at most [maxSide] pixels (1.0: as it is). */
fun heicScale(width: Int, height: Int, maxSide: Int): Double {
    val long = maxOf(width, height)
    return if (long <= maxSide || long <= 0) 1.0 else maxSide.toDouble() / long
}

fun trashConfirmTitle(title: String): String = "Move the document \"$title\" to the trash?"
fun deleteButtonLabel(title: String): String = "Move $title to the trash"

/* ---------------- S17: portal link ---------------- */

/** The send confirmation's text: the server's (with its closed-case and contact warnings), or the app's own. */
fun portalConfirm(reply: JSONObject?, fallback: String): String = reply?.optJSONObject("portal_link")?.str("confirm_text") ?: fallback
fun portalOffTitle(name: String): String = "Turn off $name's portal link?"
const val PORTAL_OFF_TEXT = "Every link already sent stops working."

/* ---------------- S18: timeline ---------------- */

data class TimelineEvent(val date: String, val month: String, val kind: String, val text: String)

fun parseTimeline(r: JSONObject): List<TimelineEvent> = r.objectsOf("events").map {
    TimelineEvent(it.optString("date"), it.optString("month"), it.optString("kind"), it.optString("text"))
}

/** The events under their month headings, in the server's order (newest first). */
fun timelineGroups(events: List<TimelineEvent>): List<Pair<String, List<TimelineEvent>>> {
    val out = mutableListOf<Pair<String, MutableList<TimelineEvent>>>()
    events.forEach { e ->
        val m = e.month.ifEmpty { e.date.take(7) }
        if (out.isEmpty() || out.last().first != m) out.add(m to mutableListOf())
        out.last().second.add(e)
    }
    return out
}

/* ---------------- S19: referrals ---------------- */

val REFERRAL_STATUSES = listOf("open" to "Open (New or Reviewed)", "converted" to "Converted", "closed" to "Closed without a client", "all" to "All statuses")
val REFERRAL_WHO = listOf("all" to "All referrals", "me" to "Assigned to me", "unassigned" to "Unassigned")

fun referralsQuery(status: String, who: String): Map<String, String> = mapOf("status" to status, "who" to who)

fun referralsCutText(shown: Int, total: Int): String? =
    if (total > shown) "Showing the newest $shown of $total matching referrals. Narrow the filters to find older ones." else null

/** Said once when the list loads or a filter changes: "4 open referrals." */
fun referralsCountText(status: String, n: Int): String {
    val kind = when (status) { "open" -> "open "; "converted" -> "converted "; "closed" -> "closed "; else -> "" }
    return "$n $kind${if (n == 1) "referral" else "referrals"}."
}

fun referralsHeading(status: String, n: Int): String = when (status) {
    "open" -> "Open referrals"; "converted" -> "Converted referrals"; "closed" -> "Closed referrals"; else -> "All referrals"
} + " ($n)"

/* ---------------- S20: pipeline ---------------- */

data class ClosedClient(val id: Int, val name: String, val status: String)

fun parsePipelineClosed(r: JSONObject): List<ClosedClient>? = r.optJSONArray("closed")?.objects()?.map {
    ClosedClient(it.optInt("id"), it.optString("name"), it.optString("status"))
}

fun closedHeading(n: Int): String = "Closed in last 90 days ($n)"

/* ---------------- S21: Pre-ETS ---------------- */

fun cohortBody(name: String, school: String, coordinatorId: Int, start: String, end: String, notes: String): JSONObject =
    JSONObject().put("name", name.trim()).put("school", school.trim()).put("coordinator_id", coordinatorId)
        .put("start_date", start.trim()).put("end_date", end.trim()).put("notes", notes.trim())

fun cohortFieldFor(key: String): String = if (key in setOf("name", "school", "coordinator_id", "start_date", "end_date", "notes")) key else ""

fun deleteSessionTitle(activity: String, date: String): String = "Delete the session $activity, $date, and its attendance?"
fun deleteSessionLabel(activity: String, date: String): String = "Delete session: $activity, $date"
const val DELETE_SESSION_TEXT = "Its hours come out of the report."
const val SESSIONS_CUT = "Showing the newest 50 sessions. Older ones are on the website's cohort page, where they can be deleted too."

/* ---------------- S22: instructor matching ---------------- */

val MATCH_MODES = listOf("" to "Any", "In person" to "In person", "Remote" to "Remote")

/** at_matches' query, with the website's names (languages, areas, modality). */
fun matchesBody(expertise: String, language: String, area: String, date: String, mode: String): Map<String, String> =
    linkedMapOf("expertise" to expertise.trim(), "languages" to language.trim(), "areas" to area.trim(), "date" to date.trim(), "modality" to mode)

/** One match, as the website's rows: the name, what matched, and the week's hours. */
fun matchLine(m: JSONObject): List<String> {
    val matched = m.optString("matched").ifBlank { "None specified" }
    val missing = m.optString("missing").ifBlank { "None" }
    fun hrs(k: String) = m.optDouble(k, 0.0).let { if (it.isNaN()) "0" else hoursText(it) }
    return listOf(m.optString("name"), "Matched: $matched. Missing: $missing.",
        ("${hrs("available_hours")} estimated hours available of ${hrs("capacity_hours")} this week. " + m.str("availability").orEmpty()).trim())
}

fun matchesCount(n: Int): String = if (n == 0) "No available instructor profiles." else "$n matching instructor${if (n == 1) "" else "s"}"

/* ---------------- S23: knowledge base answers ---------------- */

const val KB_GENERAL_TICK = "If no article covers it, answer from general knowledge (marked as not agency procedure)"
val KB_STEPS_BUILT_IN = listOf(
    "Try fewer or more general words, for example the product name or the task.",
    "Add an article on the Knowledge page, then ask again.",
    "Or tick “answer from general knowledge” above and ask again.",
)

fun kbBody(q: String, general: Boolean): JSONObject = JSONObject().put("question", q.trim()).put("general", general)
fun kbCaption(general: Boolean): String = if (general) "Answer (general knowledge, not from your articles)" else "Answer"

/* ---------------- S24: translate into another language ---------------- */

const val TRANSLATE_OTHER = "other"
const val OTHER_LANGUAGE_HINT = "letters, spaces, hyphens, apostrophes, periods and parentheses; up to 40 characters"

/** The language name sent: the chosen one, or what was typed for Other. */
fun translateTarget(choice: String, typed: String): String = if (choice == TRANSLATE_OTHER) typed.trim() else translateLangName(choice)

fun translateFieldFor(key: String): String = when (key) { "language", "lang" -> "language"; "content" -> "content"; else -> "" }

/* ---------------- S25: remediation ---------------- */

val REMEDIATE_MODES = listOf(
    "plain" to "Plain language (6th–8th grade)", "simplify" to "Simplify (shorter sentences)", "summary" to "Summarize",
    "largeprint" to "Format for large print / low vision", "alt" to "Alt text for an image (describe it below)",
)

fun remediateBody(mode: String, content: String, clientId: Int?): JSONObject {
    val b = JSONObject().put("mode", mode).put("content", content)
    if (clientId != null && clientId > 0) b.put("client_id", clientId)
    return b
}

fun remediateTarget(format: String?): String? = format?.takeIf { it.isNotBlank() }?.let { "Targeting the client's preferred format: $it." }

/* ---------------- S26: knowledge base screens ---------------- */

const val KB_EDITOR_HINT = "plain text; blank lines separate paragraphs; start a line with ## for a heading, - for a bullet, 1. for a numbered step; up to 60,000 characters"
const val KB_PUBLIC_TICK = "Publish to client portals (visible to clients)"
const val KB_DELETE_TEXT = "It does not go to the trash, so this cannot be undone."

sealed class KbBlock {
    data class Heading(val text: String) : KbBlock()
    data class Paragraph(val text: String) : KbBlock()
    data class Item(val marker: String, val text: String) : KbBlock()
}

/** The iPhone's article rules: paragraph lines joined, "## " headings, "- " bullets, "1. " numbered steps. */
fun kbBlocks(body: String): List<KbBlock> {
    val blocks = mutableListOf<KbBlock>()
    val para = mutableListOf<String>()
    var listKind = ""
    val items = mutableListOf<MutableList<String>>()
    var start = 1
    fun flush() {
        if (para.isNotEmpty()) { blocks.add(KbBlock.Paragraph(para.joinToString("\n"))); para.clear() }
        items.forEachIndexed { i, lines -> blocks.add(KbBlock.Item(if (listKind == "ol") "${start + i}." else "•", lines.joinToString("\n"))) }
        listKind = ""; items.clear(); start = 1
    }
    val rule = Regex("^\\s*([-*_])(?:\\s*\\1){2,}\\s*$")
    val heading = Regex("^\\s{0,3}#{1,6}\\s+(.+?)[\\s#]*$")
    val bullet = Regex("^\\s*[-*•]\\s+(.+)$")
    val numbered = Regex("^\\s*(\\d{1,3})[.)]\\s+(.+)$")
    for (line in body.replace("\r\n", "\n").replace("\r", "\n").split("\n")) {
        val trimmed = line.trim()
        if (trimmed.isEmpty()) { flush(); continue }
        if (rule.matches(line)) { flush(); continue }
        val h = heading.find(line)?.groupValues?.get(1)?.trim().orEmpty()
        if (h.isNotEmpty()) { flush(); blocks.add(KbBlock.Heading(h)); continue }
        var kind = ""; var item = ""; var number = 1
        val b = bullet.find(line); val n = numbered.find(line)
        if (b != null) { kind = "ul"; item = b.groupValues[1] }
        else if (n != null) { kind = "ol"; item = n.groupValues[2]; number = maxOf(1, n.groupValues[1].toIntOrNull() ?: 1) }
        if (kind.isNotEmpty()) {
            if (para.isNotEmpty() || listKind != kind) { flush(); listKind = kind; start = if (kind == "ol") number else 1 }
            items.add(mutableListOf(item.trim()))
            continue
        }
        if (listKind.isNotEmpty() && items.isNotEmpty()) { items.last().add(trimmed); continue }
        para.add(trimmed)
    }
    flush()
    return blocks
}

fun kbMeta(category: String?, updated: String?, author: String?): String =
    listOfNotNull(category?.takeIf { it.isNotBlank() }, updated?.takeIf { it.isNotBlank() }?.let { "updated " + shortDate(it) }, author?.takeIf { it.isNotBlank() })
        .joinToString(" · ")

fun kbEmptyText(searching: Boolean): String = if (searching) "No articles match your search." else "No articles yet."
