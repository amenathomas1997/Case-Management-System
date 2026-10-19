package org.casemanagement.app

import org.json.JSONArray
import org.json.JSONObject
import java.util.Locale
import java.util.TimeZone

/*
 * The plain logic behind the 4.25.0 screens (custom fields, documents to sign, time zones), kept
 * apart from Compose so it can be tested on its own.
 */

/** One of the organisation's own client fields, as client.custom_fields_edit sends it ("" = not answered). */
data class CustomField(
    val id: Int, val label: String, val hint: String, val type: String,
    val options: List<String>, val required: Boolean, val value: String,
)

val CUSTOM_TYPES = setOf("text", "textarea", "number", "date", "choice", "yesno")

fun parseCustomFields(a: JSONArray?): List<CustomField> = a.objects().mapNotNull { o ->
    val id = o.optInt("id")
    if (id <= 0) return@mapNotNull null
    val type = o.optString("type").takeIf { it in CUSTOM_TYPES } ?: "text"
    CustomField(id, o.optString("label"), if (o.isNull("hint")) "" else o.optString("hint"), type,
        o.optJSONArray("options").strings().ifEmpty { if (type == "yesno") listOf("Yes", "No") else emptyList() },
        o.optBoolean("required"), if (o.isNull("value")) "" else o.optString("value"))
}

/**
 * Checks what can be checked on the phone (dates and numbers); the server checks the rest (a
 * required field, a choice still on the list). Null when nothing is wrong.
 */
fun customFieldsProblem(fields: List<CustomField>, values: Map<Int, String>): String? {
    for (f in fields) {
        val v = (values[f.id] ?: f.value).trim()
        if (v.isEmpty()) continue
        when (f.type) {
            "date" -> if (!Regex("^\\d{4}-\\d{2}-\\d{2}$").matches(v)) return "${f.label}: enter the date as year-month-day, such as 2026-03-14, or leave it empty."
            "number" -> if (v.replace(",", "").toDoubleOrNull()?.isFinite() != true) return "${f.label}: enter a number, or leave it empty."
        }
    }
    return null
}

/** The first required field left unanswered (a new client's form), then the date and number checks. */
fun customRequiredProblem(fields: List<CustomField>, values: Map<Int, String>): String? =
    fields.firstOrNull { it.required && (values[it.id] ?: it.value).isBlank() }?.let { "${it.label} is required. Answer it under More details." }
        ?: customFieldsProblem(fields, values)

/** The save_client "custom" map: only the answers changed here, so an untouched field is never re-checked. Null when none changed. */
fun customBody(fields: List<CustomField>, values: Map<Int, String>): JSONObject? {
    val out = JSONObject()
    for (f in fields) {
        val v = values[f.id]?.trim() ?: continue
        if (v != f.value.trim()) out.put(f.id.toString(), v)
    }
    return if (out.length() > 0) out else null
}

/** A closure's device count starts from what the record shows (none: left empty, as on the website). */
fun prefillCount(n: Int): String = if (n > 0) n.toString() else ""

/** A part of a document to sign: a paragraph, or a bulleted list (several lines in one paragraph). */
sealed class BodyBlock {
    data class Paragraph(val text: String) : BodyBlock()
    data class Bullets(val items: List<String>) : BodyBlock()
}

/** A document's plain text as the signer reads it: a blank line separates paragraphs; several lines in one are a list. */
fun signatureBlocks(body: String): List<BodyBlock> =
    body.replace("\r\n", "\n").replace('\r', '\n').split(Regex("\n\\s*\n")).mapNotNull { p ->
        val lines = p.split('\n').map { it.trim() }.filter { it.isNotEmpty() }
        when {
            lines.isEmpty() -> null
            lines.size == 1 -> BodyBlock.Paragraph(lines[0])
            else -> BodyBlock.Bullets(lines.map { it.removePrefix("•").removePrefix("- ").trim() })
        }
    }

/** A time zone as people say it: America/Chicago is "Central Time". Unknown ids are shown as sent. */
fun zoneWords(id: String?): String? {
    if (id.isNullOrBlank()) return null
    val tz = TimeZone.getTimeZone(id)
    if (tz.id != id) return id
    return tz.getDisplayName(false, TimeZone.LONG, Locale.US).replace(" Standard Time", " Time")
}

/** What a message's file is called in the conversation. */
fun messageFileLabel(m: JSONObject): String? =
    if (m.isNull("document_id") || m.optInt("document_id") <= 0) null else "File: " + (m.str("document_title") ?: "a document")

/* ---------- Signing with no signal (4.26.0) ---------- */

/** A typed name reduced as the server compares it: no accents, case, spaces or punctuation. */
fun typedNameKey(s: String): String {
    val plain = java.text.Normalizer.normalize(s, java.text.Normalizer.Form.NFD).replace(Regex("\\p{Mn}+"), "")
    return plain.lowercase(Locale.ROOT).replace("ß", "ss").replace(Regex("[^\\p{L}\\p{N}]+"), "")
}

/** Whether [typed] is the name on the document, by the server's rule (checked here when there is no signal). */
fun typedNameMatches(want: String, typed: String): Boolean {
    val t = typed.trim()
    if (t.isEmpty() || t.length > 120) return false
    val key = typedNameKey(want)
    return if (key.isNotEmpty()) typedNameKey(t) == key else t.lowercase(Locale.ROOT) == want.trim().lowercase(Locale.ROOT)
}

/** Now, as the server reads a time signed on the phone: ISO 8601 with the offset (2026-10-18T14:05:00-05:00). */
fun isoTime(millis: Long, zone: TimeZone = TimeZone.getDefault()): String =
    java.text.SimpleDateFormat("yyyy-MM-dd'T'HH:mm:ssXXX", Locale.US).apply { timeZone = zone }.format(java.util.Date(millis))

/** The server takes a document signed within two days of being started. */
const val SIGN_WINDOW_MS = 2L * 24 * 60 * 60 * 1000

/** Whether a document prepared at [preparedAt] can still be signed at [now] (a minute's margin for the clocks). */
fun stillSignable(preparedAt: Long, now: Long): Boolean = preparedAt > 0 && now - preparedAt < SIGN_WINDOW_MS - 60_000

/** A time the server sent ("2026-10-18 12:01:00", in the server's own time zone [zoneId]), as milliseconds; null when unreadable. */
fun serverTimeMillis(s: String?, zoneId: String): Long? {
    if (s.isNullOrBlank()) return null
    return try {
        java.text.SimpleDateFormat("yyyy-MM-dd HH:mm:ss", Locale.US).apply { timeZone = TimeZone.getTimeZone(zoneId); isLenient = false }.parse(s.trim())?.time
    } catch (e: Exception) { null }
}

/** When a document was started for signing: the server's sent_at, else [fallback] (an older server sends none). */
fun signingStart(sentAt: String?, zoneId: String, fallback: Long): Long = serverTimeMillis(sentAt, zoneId) ?: fallback

/**
 * A retry key for a request that must not be sent twice (4.27.0), kept as "<key>|<fingerprint>":
 * the same key while the request is unchanged (a retry after a lost reply), a new one as soon as
 * anything in it changes. [saved] is "" before the first send and after the server answered.
 */
fun retryKey(saved: String, request: String, newKey: () -> String = { java.util.UUID.randomUUID().toString() }): String {
    val print = Integer.toHexString(request.hashCode()) + "-" + request.length
    val key = saved.substringBefore('|')
    return if (key.isNotEmpty() && saved.substringAfter('|', "") == print) saved else newKey() + "|" + print
}

/** The key itself, to send as idem_key. */
fun retryKeyId(saved: String): String = saved.substringBefore('|')

/**
 * Said when a create or send got no reply (4.28.0): it may have been saved. Trying again with
 * nothing changed sends the same retry key, so the server never saves it twice.
 */
fun unknownOutcome(what: String): String =
    "The connection dropped after $what was sent, so it is not known whether it was saved. " +
        "Press the button again with nothing changed to finish: it will not be saved twice. If you change anything first, check whether it was saved."

/**
 * Sends a request that must never happen twice (4.28.0) with the retry key for exactly this
 * request ([fingerprint]; see [retryKey]). [saved] is the form's kept key (rememberSaveable) and
 * [keep] stores it: it stays while the reply is unknown and starts again once the server answered
 * either way. A lost reply is said as [unknownOutcome] for [what].
 */
suspend fun <T> sendOnce(saved: String, keep: (String) -> Unit, fingerprint: String, what: String, send: suspend (idem: String) -> T): T {
    val k = retryKey(saved, fingerprint)
    keep(k)
    return try {
        send(retryKeyId(k)).also { keep("") }
    } catch (e: ApiException) {
        if (!e.network) { keep(""); throw e }
        // No answer at all (status 0): sent or not, nobody knows. A redirect (3xx) was not taken, so its own words stay.
        if (e.status == 0) throw ApiException(unknownOutcome(what), 0, network = true)
        throw e
    }
}

/**
 * A drawn signature as plain numbers for keeping across rotation and process death (4.28.0):
 * the pad's width and height, then for each stroke its point count and the points' x and y.
 */
fun encodeStrokes(width: Int, height: Int, strokes: List<List<Pair<Float, Float>>>): FloatArray {
    val out = ArrayList<Float>(2 + strokes.sumOf { 1 + it.size * 2 })
    out.add(width.toFloat()); out.add(height.toFloat())
    strokes.forEach { s -> out.add(s.size.toFloat()); s.forEach { (x, y) -> out.add(x); out.add(y) } }
    return out.toFloatArray()
}

/** [encodeStrokes] read back: the width, height and strokes; an unreadable array gives none. */
fun decodeStrokes(a: FloatArray?): Triple<Int, Int, List<List<Pair<Float, Float>>>> {
    if (a == null || a.size < 2) return Triple(1, 1, emptyList())
    val strokes = mutableListOf<List<Pair<Float, Float>>>()
    var i = 2
    while (i < a.size) {
        val n = a[i].toInt(); i++
        if (n < 0 || i + n * 2 > a.size) return Triple(a[0].toInt(), a[1].toInt(), strokes)
        strokes.add(List(n) { j -> a[i + j * 2] to a[i + j * 2 + 1] })
        i += n * 2
    }
    return Triple(a[0].toInt(), a[1].toInt(), strokes)
}

/** Which kept files to start again (4.28.0): only those that failed to open; all of them only when every one failed (the key itself is lost). */
fun filesToReset(opened: List<Boolean>): Pair<Boolean, List<Int>> {
    if (opened.none { it }) return true to opened.indices.toList()
    return false to opened.indices.filter { !opened[it] }
}

/** Opened documents kept on the phone longer than this are removed at start-up (4.28.0). */
const val DOCS_KEEP_MS = 14L * 24 * 60 * 60 * 1000

/** Whether a kept document last written at [modified] is past [DOCS_KEEP_MS] at [now]. */
fun docExpired(modified: Long, now: Long): Boolean = now - modified > DOCS_KEEP_MS

/** Whether the refused session may be asked about at [base]: only at the server that refused it ([refusedAt], or the kept server for an older entry). */
fun refusedCheckAllowed(refusedAt: String, keptServerApi: String?, base: String): Boolean =
    if (refusedAt.isNotEmpty()) refusedAt == base else keptServerApi != null && keptServerApi == base

/**
 * Every problem in the organisation's own fields, by field (4.28.0), so each is shown on its field:
 * [requireAnswers] (a new client) also marks a required field left unanswered.
 */
fun customProblemsByField(fields: List<CustomField>, values: Map<Int, String>, requireAnswers: Boolean): Map<Int, String> {
    val out = linkedMapOf<Int, String>()
    for (f in fields) {
        val v = (values[f.id] ?: f.value).trim()
        if (v.isEmpty()) { if (requireAnswers && f.required) out[f.id] = "${f.label} is required. Answer it under More details."; continue }
        when (f.type) {
            "date" -> if (!Regex("^\\d{4}-\\d{2}-\\d{2}$").matches(v)) out[f.id] = "${f.label}: enter the date as year-month-day, such as 2026-03-14, or leave it empty."
            "number" -> if (v.replace(",", "").toDoubleOrNull()?.isFinite() != true) out[f.id] = "${f.label}: enter a number, or leave it empty."
        }
    }
    return out
}

/**
 * A field after the client was read again (4.28.0, someone else saved meanwhile): one changed
 * here keeps what was typed; one left as it was ([was]) takes the saved value ([now]).
 */
fun <T> mergeReloaded(current: T, was: T, now: T): T = if (current == was) now else current

/** The client fields the app's form edits, and when the record was read (save_client's loaded_at, 4.28.0). */
val CLIENT_FORM_KEYS = listOf("name", "status", "client_type", "dob", "phone", "email", "address", "state", "assigned_to", "office_id",
    "counselor_name", "counselor_agency", "counselor_email", "counselor_phone", "a11y_format", "a11y_contact", "a11y_primary_tech",
    "a11y_notes", "a11y_screen_reader", "guardian_name", "guardian_phone", "guardian_email", "school", "vr_case_number", "ipe_goal", "updated_at")

/** The part of a client record the form was filled from, small enough to keep across process death. */
fun clientSnapshot(c: JSONObject): JSONObject = JSONObject().also { o -> CLIENT_FORM_KEYS.forEach { k -> if (c.has(k)) o.put(k, c.opt(k)) } }

/** [mergeReloaded] for a whole form of text fields (4.28.0): each key keeps what was typed, or takes the saved value. */
fun mergeReloadedMap(current: Map<String, String>, was: Map<String, String>, now: Map<String, String>): Map<String, String> =
    (current.keys + now.keys).associateWith { k -> mergeReloaded(current[k] ?: "", was[k] ?: "", now[k] ?: "") }

/** The values a form opened with, for the keys it sends (save_client's and save_worksite's "orig", 4.28.0). */
fun openedValues(sent: JSONObject, opened: JSONObject, skip: Set<String> = setOf("id")): JSONObject {
    val o = JSONObject()
    for (k in sent.keys()) if (k !in skip && opened.has(k)) o.put(k, opened.opt(k))
    return o
}

/** A drawing made on a pad of [fromW]×[fromH] fitted to a pad of [toW]×[toH] (4.28.0: a turned phone keeps the whole signature). */
fun scaleStrokes(strokes: List<List<Pair<Float, Float>>>, fromW: Int, fromH: Int, toW: Int, toH: Int): List<List<Pair<Float, Float>>> {
    if (fromW <= 1 || fromH <= 1 || toW <= 0 || toH <= 0 || (fromW == toW && fromH == toH)) return strokes
    val sx = toW.toFloat() / fromW; val sy = toH.toFloat() / fromH
    return strokes.map { s -> s.map { (x, y) -> x * sx to y * sy } }
}
