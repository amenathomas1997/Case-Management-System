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
