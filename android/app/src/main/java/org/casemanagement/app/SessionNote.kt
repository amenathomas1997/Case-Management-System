package org.casemanagement.app

import org.json.JSONObject
import java.text.SimpleDateFormat
import java.util.Locale

/*
 * 4.31.0: confirming a session lands in its case note. Plain helpers (no Android or Compose), so
 * the unit tests check the routes, subjects and titles the screens use.
 */

/**
 * The note's subject: the server's note_subject ("Session on Oct 20", in the organisation's date
 * format), or, from an older server or a completion kept offline, "Session on Oct 20" made here
 * from the session's date (Y-m-d, or its start time).
 */
fun sessionNoteSubject(noteSubject: String?, sessionDate: String?): String {
    noteSubject?.trim()?.takeIf { it.isNotEmpty() }?.let { return it }
    val day = sessionDate?.trim()?.take(10).orEmpty()
    val parsed = try { SimpleDateFormat("yyyy-MM-dd", Locale.US).apply { isLenient = false }.parse(day) } catch (e: Exception) { null }
    return if (parsed != null) "Session on " + SimpleDateFormat("MMM d", Locale.US).format(parsed) else "Session note"
}

/** The editor's title, said when it opens: "Case note for Jane Doe, session on Oct 20". */
fun sessionNoteTitle(clientName: String?, subject: String?): String {
    val who = clientName?.trim().orEmpty()
    val what = subject?.trim().orEmpty().replaceFirstChar { it.lowercase(Locale.US) }
    return when {
        who.isEmpty() && what.isEmpty() -> "Add a case note"
        who.isEmpty() -> "Case note, $what"
        what.isEmpty() -> "Case note for $who"
        else -> "Case note for $who, $what"
    }
}

/**
 * A route argument percent-encoded as Navigation decodes it (Uri.decode): every byte but letters,
 * digits and - _ . ~ as %XX of its UTF-8, so a space is %20 (never +), and & = ? / # are kept apart.
 */
fun routeArg(value: String): String {
    val sb = StringBuilder()
    for (b in value.toByteArray(Charsets.UTF_8)) {
        val c = b.toInt() and 0xFF
        if ((c in 'A'.code..'Z'.code) || (c in 'a'.code..'z'.code) || (c in '0'.code..'9'.code) || c == '-'.code || c == '_'.code || c == '.'.code || c == '~'.code) sb.append(c.toChar())
        else sb.append('%').append("0123456789ABCDEF"[c shr 4]).append("0123456789ABCDEF"[c and 15])
    }
    return sb.toString()
}

/**
 * The full note editor for a session's note: trackNote/{clientId}/{trackId} when the session has a
 * track (the note is filed on it), else note/{clientId}, with the subject, session and client's name.
 */
fun sessionNoteRoute(clientId: Int, trackId: Int, sessionId: Int, subject: String, clientName: String): String {
    val base = if (trackId > 0) "trackNote/$clientId/$trackId" else "note/$clientId"
    return base + "?subject=" + routeArg(subject) + "&session=" + sessionId + "&client=" + routeArg(clientName)
}

/** The route patterns that take the session's note (the query arguments are optional). */
const val NOTE_ROUTE = "note/{clientId}?subject={subject}&session={session}&client={client}"
const val TRACK_NOTE_ROUTE = "trackNote/{clientId}/{trackId}?subject={subject}&session={session}&client={client}"

/** A completed session whose case note is not written yet (notes_to_write, or the complete_session reply). */
data class NoteToWrite(val sessionId: Int, val clientId: Int, val clientName: String, val trackId: Int, val sessionDate: String?, val subject: String) {
    val route: String get() = sessionNoteRoute(clientId, trackId, sessionId, subject, clientName)
}

/** notes_to_write: the sessions still waiting for their note, and how many in all. */
data class NotesToWrite(val items: List<NoteToWrite>, val total: Int)

fun parseNotesToWrite(r: JSONObject): NotesToWrite {
    // The list under sessions (or notes, or items: the contract names only its rows).
    val list = r.optJSONArray("sessions") ?: r.optJSONArray("notes") ?: r.optJSONArray("items")
    val items = list.objects().mapNotNull { parseNoteToWrite(it) }
    return NotesToWrite(items, r.optInt("total", items.size).coerceAtLeast(items.size))
}

/**
 * One session's note to write, from a notes_to_write row or a complete_session reply; [fallback]
 * (the schedule's row) fills in what an older server's reply leaves out. Null with no client.
 */
fun parseNoteToWrite(r: JSONObject, fallback: JSONObject? = null): NoteToWrite? {
    fun int(key: String, alt: String = key): Int = r.optInt(key).takeIf { it > 0 } ?: fallback?.optInt(alt)?.takeIf { it > 0 } ?: 0
    fun text(key: String, alt: String = key): String? = r.str(key) ?: fallback?.str(alt)
    val clientId = int("client_id")
    if (clientId <= 0) return null
    val date = text("session_date") ?: text("start_at")
    return NoteToWrite(
        int("session_id", "id"), clientId, (text("client_name", "client") ?: "").trim(), int("track_id"), date?.take(10),
        sessionNoteSubject(r.str("note_subject"), date),
    )
}

