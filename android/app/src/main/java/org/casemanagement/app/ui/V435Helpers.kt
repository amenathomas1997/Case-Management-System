package org.casemanagement.app.ui

import org.casemanagement.app.NoteItem
import org.casemanagement.app.str
import org.json.JSONArray
import org.json.JSONObject

/*
 * 4.35.0: field casework in the app (notes, clients, sessions), as the website and the iPhone app.
 * The plain rules and request bodies of the new screens, kept apart from Compose so the unit tests
 * (V435Test) run them. Nothing here touches Android.
 */

/** True when the server is [wanted] ("4.35") or newer; an unknown version counts as new enough. */
fun serverAtLeast(version: String?, wanted: String): Boolean {
    val have = version?.split('.')?.mapNotNull { it.trim().toIntOrNull() } ?: return true
    if (have.isEmpty()) return true
    val want = wanted.split('.').mapNotNull { it.toIntOrNull() }
    for (i in want.indices) {
        val h = have.getOrElse(i) { 0 }
        if (h != want[i]) return h > want[i]
    }
    return true
}

/* ---------------- Q1: book a group session ---------------- */

/**
 * schedule_group's body: the clients as "3,5" (the contract takes a comma-separated string), the
 * track picked for a client with more than one (only those, by client id), and the session itself.
 */
fun groupBody(clientIds: List<Int>, tracks: Map<Int, Int>, staffId: Int, date: String, time: String, duration: Double,
              location: String, notes: String, remote: Boolean, link: String, ackHash: String?): JSONObject {
    val b = JSONObject().put("client_ids", clientIds.joinToString(","))
    val picks = tracks.filter { (c, t) -> c in clientIds && t > 0 }.toSortedMap()
    if (picks.isNotEmpty()) b.put("tracks", JSONObject().also { o -> picks.forEach { (c, t) -> o.put(c.toString(), t) } })
    b.put("staff_id", staffId).put("date", date.trim()).put("time", time).put("duration", duration)
        .put("location", location.trim()).put("notes", notes.trim()).put("is_remote", if (remote) 1 else 0)
    if (remote && link.isNotBlank()) b.put("meeting_url", link.trim())
    if (ackHash != null) b.put("authorization_ack", true).put("authorization_ack_hash", ackHash)
    return b
}

/** A client's tick on the group form; the hint (where the hours go) is part of the label. [trackTitles]: newest first. */
fun groupClientLabel(name: String, trackTitles: List<String>): String = when (trackTitles.size) {
    0 -> "$name (no active training track, so no hours will be logged)"
    1 -> "$name — ${trackTitles.first()}"
    // Several: the picker under the tick names the one chosen, as the website.
    else -> name
}

/** Why a group's attendance cannot be recorded (the iPhone app's and the website's words). */
fun groupCannotRecordText(started: Boolean): String =
    if (started) "This group has fewer than two clients still scheduled. Record each one from the schedule."
    else "It can be completed once it has started, with who attended."

/** The year now, in the server's time zone. */
fun currentYear(): Int = today().take(4).toIntOrNull() ?: java.time.LocalDate.now().year

/** A typed date that is a real calendar date (year-month-day) from [minYear] to [maxYear]. */
fun realDate(text: String, maxYear: Int = currentYear() + 5, minYear: Int = 2000): Boolean {
    val t = text.trim()
    if (!Regex("^\\d{4}-\\d{2}-\\d{2}$").matches(t)) return false
    val d = try { java.time.LocalDate.parse(t) } catch (e: Exception) { return false }
    return d.year in minYear..maxYear
}

/** The next note after [sessionId] in a group's notes, with its place (from 1), or null at the end. */
fun nextInQueue(queue: List<org.casemanagement.app.NoteToWrite>, sessionId: Int): Pair<org.casemanagement.app.NoteToWrite, Int>? {
    val i = queue.indexOfFirst { it.sessionId == sessionId }
    if (i < 0 || i + 1 >= queue.size) return null
    return queue[i + 1] to (i + 2)
}

/** " Next: Synthetic Client, 2 of 3." (the website's words). */
fun nextNoteWords(name: String, pos: Int, total: Int): String = " Next: $name, $pos of $total."

/** schedule_group's refusal on the form's fields: track_<client id> on that client's picker, client_ids on the list. */
fun groupFieldErrors(reply: JSONObject?): Map<String, String> =
    (serverFieldErrors(reply) ?: emptyMap()).mapKeys { (k, _) -> if (k == "client_ids") "clients" else k }

/** "Two or more" is needed for a group; null when [ticked] is enough. */
fun groupClientsRule(ticked: Int): String? = if (ticked < 2) "Clients: choose two or more clients for a group session." else null

/* ---------------- Q2: a group's attendance ---------------- */

/** A member's tick on the attendance form: "<name> attended (hours logged to <track>)". */
fun groupAttendLabel(name: String, trackTitle: String?, onTrack: Boolean): String =
    "$name attended (" + (if (onTrack) "hours logged to " + (trackTitle?.takeIf { it.isNotBlank() } ?: "their training track") else "no training track, so no hours are logged") + ")"

/** The website's question before a group's attendance is recorded (schedule.php's Record attendance and complete). */
fun groupConfirmText(attended: Int, total: Int, billable: Int, hours: String): String {
    val hv = hours.trim()
    val none = hv.isNotEmpty() && !((hv.replace(',', '.').toDoubleOrNull() ?: 0.0) > 0)
    val middle = if (none) ", with no hours logged. "
        else ", and $billable of them will have " + (if (hv.isNotEmpty()) "$hv hours" else "hours") + " logged to their training track and billed. "
    return "Record attendance for this group session? $attended of $total clients attended and will be marked Completed" + middle +
        "${total - attended} will be recorded as No Show, with no hours logged."
}

/** The group hours field: blank (each client's own length) or 0 to 24; null when fine. */
fun groupHoursRule(hours: String): String? {
    val t = hours.trim()
    if (t.isEmpty()) return null
    val h = t.replace(',', '.').toDoubleOrNull()
    return if (h == null || !h.isFinite() || h < 0 || h > 24) "Hours for each client who attended: enter a number from 0 to 24, or leave it blank to log each client's own scheduled length." else null
}

/** complete_group's body: every member listed on the form, the ones ticked as attended, and the hours ("" or a number). */
fun groupCompleteBody(groupUid: String, listed: List<Int>, attended: Collection<Int>, hours: String): JSONObject =
    JSONObject().put("group_uid", groupUid).put("listed", JSONArray(listed)).put("attended", JSONArray(listed.filter { it in attended }))
        .put("hours", hours.trim().replace(',', '.'))

/* ---------------- Q3: goal and service when completing ---------------- */

/** Which completion choices show: the goal picker with an open goal, the service picker with more than one category. */
fun visitChoicesShown(openGoals: Int, categories: Int): Pair<Boolean, Boolean> = (openGoals >= 1) to (categories > 1)

/* ---------------- Q4: log hours ---------------- */

/** Log hours' hours rule with blank hours: blank with a session note saves only the note (the server's texts). */
fun hoursRule(hoursText: String, miles: Double?, note: String): String? {
    if (hoursText.isBlank()) return if (note.isBlank()) "Enter the hours worked, or write a session note to save the note on its own." else null
    return hoursRule(number(hoursText), miles)
}

/** What Log hours says when it is saved, as the iPhone app. */
fun logHoursMessage(hours: Double, withNote: Boolean, noteOnly: Boolean): String = when {
    noteOnly -> "Session note saved. No hours were logged."
    hours == 0.0 -> if (withNote) "Travel logged with no hours, and the session note saved." else "Travel logged with no hours."
    else -> "${hoursText(hours)} hours logged" + if (withNote) " and the session note saved." else "."
}

/** log_hours' body: hours "" when only the session note is saved; no goal_id for None. */
fun logHoursPayload(trackId: Int, hoursText: String, date: String, miles: Double, description: String, serviceType: String,
                    category: String, goalId: Int, note: String, idem: String): JSONObject {
    val noteOnly = hoursText.isBlank()
    val b = JSONObject().put("track_id", trackId).put("date", date.trim())
    if (noteOnly) b.put("hours", "") else b.put("hours", number(hoursText) ?: hoursText.trim())
    b.put("mileage", miles).put("description", description.trim()).put("idem_key", idem)
    if (serviceType.isNotEmpty()) b.put("service_type", serviceType)
    if (category.isNotEmpty()) b.put("rsa_category", category)
    if (goalId > 0) b.put("goal_id", goalId)
    if (note.isNotBlank()) b.put("session_note", note.trim())
    return b
}

/* ---------------- Q5: cancel more ---------------- */

/** The question before cancelling a series ("series") or a whole group ("group"), as the website. */
fun cancelMoreConfirm(kind: String, client: String, date: String): String =
    if (kind == "group") "Cancel this group session on $date for every client in it? Each client is sent a calendar cancellation."
    else "Cancel this session with $client on $date and every later session in its weekly series? The client is sent a calendar cancellation."

/* ---------------- Q6: client requests and offered times ---------------- */

/** A client request's row: who and what, its status, the linked session, the due date, the times offered, the details. */
fun requestRowLines(q: JSONObject): List<String> {
    val offered = q.optJSONArray("offered")?.let { a -> List(a.length()) { a.optJSONObject(it)?.str("start_at") }.filterNotNull() } ?: emptyList()
    return listOfNotNull(
        q.optString("client_name") + ": " + q.optString("type_label"),
        listOfNotNull(q.str("status"), q.str("created_at")?.let { whenText(it) }).joinToString(", ").ifEmpty { null },
        q.str("session_start")?.let { "session " + whenText(it) },
        q.str("due_on")?.let { "due " + dayText(it) },
        if (offered.isNotEmpty()) "Offered: " + offered.joinToString("; ") { whenText(it) } else null,
        q.str("details"),
    )
}

/** A time typed as "2026-11-20 14:30" (or "2026-11-20 2:30 PM") as the server's "Y-m-d H:i", or null. */
fun offerTimeText(raw: String): String? {
    val t = raw.trim().replace('T', ' ')
    val day = t.substringBefore(' ')
    if (!Regex("^\\d{4}-\\d{2}-\\d{2}$").matches(day)) return null
    try { java.time.LocalDate.parse(day) } catch (e: Exception) { return null }
    val time = time24(t.substringAfter(' ', "")) ?: return null
    return "$day $time"
}

/** The time fields that hold text (0, 1, 2), in order: the server numbers the times it is sent from 1. */
fun offerSentFields(times: List<String>): List<Int> = times.indices.filter { times[it].isNotBlank() }

/** offer_times' POST body: blank times dropped, each as "Y-m-d H:i". */
fun offerTimesBody(id: Int, times: List<String>, minutes: Int?, staffId: Int, trackId: Int, remote: Boolean, location: String): JSONObject {
    val sent = times.filter { it.isNotBlank() }.map { offerTimeText(it) ?: it.trim() }
    val b = JSONObject().put("id", id).put("times", JSONArray(sent)).put("staff_id", staffId).put("remote", if (remote) 1 else 0).put("location", location.trim())
    if (minutes != null) b.put("minutes", minutes)
    if (trackId > 0) b.put("track_id", trackId)
    return b
}

/** offer_times' errors on the form's fields: timeN (N among those sent) back on the field it came from. */
fun offerFieldErrors(errors: Map<String, String>, sentFields: List<Int>): Map<String, String> = errors.mapKeys { (k, _) ->
    Regex("^time(\\d)$").find(k)?.groupValues?.get(1)?.toIntOrNull()?.let { n -> sentFields.getOrNull(n - 1)?.let { "time${it + 1}" } } ?: k
}

/** "Offer these times to Synthetic Client: Fri, Nov 20, 2:30 PM; Sat, Nov 21, 9:00 AM? They choose one in their portal." */
fun offerConfirmText(times: List<String>, client: String): String =
    "Offer these times to $client: ${times.joinToString("; ")}? They choose one in their portal."

/** True when a "Y-m-d H:i" time is now or earlier, by the server's clock ([now] in the same form). */
fun offerTimePast(time: String, now: String = java.text.SimpleDateFormat("yyyy-MM-dd HH:mm", java.util.Locale.US)
    .apply { timeZone = java.util.TimeZone.getTimeZone(serverZoneId) }.format(java.util.Date())): Boolean = time <= now

/* ---------------- Q7: tech assessments ---------------- */

/** The website's assessment date rule (client.php): required, a real date from 2000, not in the future. Null when fine. */
fun assessmentDateRule(text: String, todayIs: String = today()): String? {
    val t = text.trim()
    if (t.isEmpty()) return "Assessment date is required."
    val ok = Regex("^\\d{4}-\\d{2}-\\d{2}$").matches(t) && try { java.time.LocalDate.parse(t); true } catch (e: Exception) { false } && t >= "2000-01-01"
    if (!ok) return "Enter the assessment date as a valid date (YYYY-MM-DD) from the year 2000 onwards."
    if (t > todayIs) return "The assessment date cannot be in the future. Check the year."
    return null
}

/** An assessment row's first line: "Monday, November 16, 2026: Screen Reader". */
fun assessmentRowText(date: String?, tech: String?): String = dayText(date) + ": " + (tech?.takeIf { it.isNotBlank() } ?: "No technology recorded")

/* ---------------- Q8: older notes ---------------- */

/** The notes shown, then an older page's notes not already shown, in order. */
fun mergeNotePages(shown: List<NoteItem>, page: List<NoteItem>): List<NoteItem> {
    val ids = shown.map { it.id }.toMutableSet()
    return shown + page.filter { ids.add(it.id) }
}

fun olderNotesLabel(total: Int, shown: Int): String = "Load older notes (${(total - shown).coerceAtLeast(0)} more)"

/* ---------------- Q9: after closure ---------------- */

/** A follow-up question's answer as sent: "yes", "no", or "" (not asked). */
fun followupAnswer(choice: String): String = if (choice == "yes" || choice == "no") choice else ""

/** The question before a follow-up check is saved. */
fun followupConfirm(interval: Int, client: String, needs: String): String =
    "Save the $interval-day check for $client?" + if (needs == "yes") " A re-referral task will be added." else ""

/** The After closure section shows when the server sends its state. */
fun afterClosureShown(after: Any?): Boolean = after is JSONObject

/** A survey rating typed on the phone form: null when it is 1 to 10. */
fun surveyRatingRule(label: String, text: String): String? =
    if (text.trim().toIntOrNull()?.takeIf { it in 1..10 } == null) "$label: enter a rating from 1 to 10." else null

val FOLLOWUP_QUESTIONS = listOf(
    "still_employed" to "Still employed?", "still_using_tech" to "Still using the technology?",
    "independent" to "Living or working independently?", "needs_service" to "Needs more service?",
)

/* ---------------- Q10 and Q11: client details ---------------- */

/** The website's age-of-onset rule: blank, or a whole number from 0 to 120. Null when fine. */
fun onsetAgeRule(text: String): String? {
    val t = text.trim()
    if (t.isEmpty()) return null
    return if (t.all { it in '0'..'9' } && t.length <= 3 && t.toInt() <= 120) null
        else "Age when vision loss began: enter a whole number of years from 0 to 120, or leave it blank."
}

fun languageChoices(): List<Pair<String, String>> = listOf("en" to "English", "es" to "Spanish")

fun holdDateShown(status: String): Boolean = status == "On Hold"

/** The return date rule (on_hold_input): blank, or a real date today or later. Null when fine. */
fun holdDateRule(text: String, todayIs: String = today()): String? {
    val t = text.trim()
    if (t.isEmpty()) return null
    val ok = Regex("^\\d{4}-\\d{2}-\\d{2}$").matches(t) && try { java.time.LocalDate.parse(t); true } catch (e: Exception) { false }
    return if (!ok || t < todayIs) "Enter the expected return date as today or later, or leave it blank." else null
}

/** "Attendance: 3 completed, 1 no-show, 0 cancelled", or null when there is none (as the website). */
fun attendanceText(att: JSONObject?): String? {
    if (att == null) return null
    val done = att.optInt("completed"); val noShow = att.optInt("no_show"); val cancelled = att.optInt("cancelled")
    if (done + noShow + cancelled == 0) return null
    return "Attendance: $done completed, $noShow no-show${if (noShow == 1) "" else "s"}, $cancelled cancelled"
}

/** "On Hold: waiting for the authorization. Expected back Monday, December 7, 2026." or null when neither is known. */
fun onHoldLine(reason: String?, until: String?): String? {
    val r = reason?.trim()?.takeIf { it.isNotEmpty() }
    val u = until?.trim()?.takeIf { it.isNotEmpty() }
    if (r == null && u == null) return null
    return "On Hold" + (r?.let { ": $it" } ?: "") + (u?.let { ". Expected back " + dayText(it) } ?: "") + "."
}

/** "Vision: Low vision, since age 12", or null when neither is recorded (as the website). */
fun visionText(status: String?, onsetAge: Int?): String? {
    val s = status?.takeIf { it.isNotBlank() }
    if (s == null && onsetAge == null) return null
    return "Vision: " + (s ?: "Not recorded") + (onsetAge?.let { ", since age $it" } ?: "")
}

/* ---------------- Q12: the note editor ---------------- */

/** What Generate structured note works from: the rough box, or else the note as typed (body, then each section). */
fun generatorSource(rough: String, body: String, sections: List<String>): String {
    val r = rough.trim()
    if (r.isNotEmpty()) return r
    return (listOf(body) + sections).map { it.trim() }.filter { it.isNotEmpty() }.joinToString("\n")
}

/** The format the generator drafts: a free-text note becomes BIRP. */
fun generateFormat(format: String): String = if (format == "free") "BIRP" else format

/** A generated homework row's due date (the server's rule): blank, or a real date from 2000 to five years ahead. */
fun homeworkDueRule(n: Int, due: String, year: Int = java.time.LocalDate.now().year): String? {
    val t = due.trim()
    if (t.isEmpty()) return null
    val d = if (Regex("^\\d{4}-\\d{2}-\\d{2}$").matches(t)) try { java.time.LocalDate.parse(t) } catch (e: Exception) { null } else null
    return if (d == null || d.year < 2000 || d.year > year + 5)
        "Homework item $n: enter a valid due date with a four-digit year from 2000 to ${year + 5}, or clear it." else null
}

/** A case note's row: subject; type, format, date and author; its track; who edited it. */
fun noteRowLines(n: NoteItem): List<String> = listOfNotNull(
    n.subject,
    listOfNotNull(n.noteType.ifBlank { null }, n.noteFormat.takeIf { it != "free" && it.isNotBlank() }, whenText(n.createdAt).ifBlank { null }).joinToString(", ") +
        (n.author?.let { ", by $it" } ?: ""),
    n.trackTitle?.let { "Track: $it" },
    n.editor?.let { e -> "Edited by $e" + (n.updatedAt?.let { " on " + whenText(it) } ?: "") },
)

/* ---------------- Q13: client list filters ---------------- */

/** The filter keys the clients action takes, in the form's order. */
val CLIENT_FILTER_KEYS = listOf("status", "who", "type", "agency", "school", "office")

/** clients' query: only the filters that are set (who only once it is known), the search, and with_filters. */
fun clientsQuery(filters: Map<String, String?>, search: String): Map<String, String> {
    val q = linkedMapOf("with_filters" to "1")
    if (search.isNotBlank()) q["q"] = search.trim()
    CLIENT_FILTER_KEYS.forEach { k ->
        val v = filters[k]?.trim()
        if (k == "who") { if (v != null) q[k] = v } else if (!v.isNullOrEmpty()) q[k] = v
    }
    return q
}

/** How many filters are on: each one set, and the caseload when it is not the account's default. */
fun filtersOnCount(filters: Map<String, String?>, whoDefault: String): Int =
    CLIENT_FILTER_KEYS.count { k -> val v = filters[k]?.trim(); if (k == "who") v != null && v != whoDefault else !v.isNullOrEmpty() }

/** The client list's heading: what it holds, with its count. */
fun clientsHeading(shown: Int, total: Int, filtered: Boolean, search: String = ""): String {
    val label = when {
        filtered && search.isNotBlank() -> "Clients matching $search and the filters"
        filtered -> "Clients matching the filters"
        search.isNotBlank() -> "Clients matching $search"
        else -> "All clients"
    }
    // With filters, the count of every match (the line under a cut list says how many are shown), as the iPhone app.
    if (filtered) return "$label ($total)"
    return if (total > shown) "$label (first $shown of $total)" else "$label ($shown)"
}

/** The line under a cut list, in the website's words (clients.php). */
fun clientsCutText(shown: Int, total: Int, @Suppress("UNUSED_PARAMETER") filtered: Boolean = false): String? =
    if (total <= shown) null
    else "Showing the first $shown of ${"%,d".format(java.util.Locale.US, total)} matching clients, in name order. Search by name or narrow the filters to find the rest."

/* ---------------- Q14: homework edit ---------------- */

/** at_homework_update's refusal on its field: "title", "due", "reload" (changed since), or null. */
fun homeworkEditErrorField(message: String?): String? = when {
    message == null -> null
    message.startsWith("This assignment changed") -> "reload"
    message.startsWith("Use a title") -> "title"
    message.startsWith("Use a valid due date") -> "due"
    else -> null
}

/** at_homework_update's body: the edit token it was opened with, and a blank due date as "". */
fun homeworkEditBody(id: Int, title: String, instructions: String, due: String, editToken: String?): JSONObject =
    JSONObject().put("id", id).put("title", title.trim()).put("instructions", instructions.trim()).put("due_date", due.trim()).put("edit_token", editToken ?: "")

/** The Save question for a homework edit: it names the item, and says when the client's report is cleared. */
fun homeworkEditConfirm(title: String, reported: Boolean, textChanged: Boolean): String =
    "Save the changes to $title?" + if (reported && textChanged) " Changing the title or instructions clears the client's report that it is done." else ""

/* ---------------- saved screen state (rotation and process death) ---------------- */

/** Generated homework items (title, instructions, due) as text, and back. */
fun homeworkToText(items: List<JSONObject>): String = JSONArray(items.map { h ->
    JSONObject().put("title", h.optString("title")).put("instructions", h.optString("instructions")).put("due", h.optString("due"))
}).toString()

fun homeworkFromText(s: String): List<JSONObject> = try {
    JSONArray(s).let { a -> (0 until a.length()).mapNotNull { a.optJSONObject(it) } }
} catch (e: Exception) { emptyList() }

/** A list of lines as text, and back. */
fun stringsToText(items: List<String>): String = JSONArray(items).toString()

fun stringsFromText(s: String): List<String> = try { JSONArray(s).let { a -> List(a.length()) { a.optString(it) } } } catch (e: Exception) { emptyList() }

/** "1,000" when [text] (trimmed, counted in characters as the server counts them) is longer than [max], else null. */
fun textTooLong(text: String, max: Int): String? {
    val t = text.trim()
    return if (t.codePointCount(0, t.length) > max) "%,d".format(java.util.Locale.US, max) else null
}
