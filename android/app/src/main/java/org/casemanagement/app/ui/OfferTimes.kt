package org.casemanagement.app.ui

import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.navigation.NavHostController
import org.casemanagement.app.Api
import org.casemanagement.app.ApiException
import org.casemanagement.app.objects
import org.casemanagement.app.str

/** 4.35.0: counts the times offered here, so the client requests list reads itself again. */
object RequestOffers {
    var count by androidx.compose.runtime.mutableIntStateOf(0)
}

/**
 * 4.35.0: offer up to three times for a client's appointment or reschedule request, as the
 * website's client requests page: the client chooses one in their portal.
 */
@Composable
fun OfferTimesScreen(api: Api, nav: NavHostController, id: Int) {
    val loaded = rememberLoaded(id) { api.call("offer_times", mapOf("id" to id.toString())) }
    Show(loaded, "the request") { r ->
        val req = r.optJSONObject("request") ?: org.json.JSONObject()
        val client = req.optString("client_name")
        val d = r.optJSONObject("defaults") ?: org.json.JSONObject()
        val staff = r.optJSONArray("staff").objects()
        val tracks = r.optJSONArray("tracks").objects()
        val lengths = r.optJSONArray("lengths")?.let { a -> List(a.length()) { a.optInt(it) } }?.filter { it > 0 }?.ifEmpty { null } ?: listOf(30, 45, 60, 90, 120, 180)
        val keepsLength = r.optBoolean("keeps_length")
        var t1 by rememberSaveable { mutableStateOf("") }
        var t2 by rememberSaveable { mutableStateOf("") }
        var t3 by rememberSaveable { mutableStateOf("") }
        var minutes by rememberSaveable { mutableStateOf((d.optInt("minutes").takeIf { it > 0 } ?: 60).toString()) }
        var who by rememberSaveable { mutableStateOf(d.optInt("staff_id").takeIf { it > 0 }?.toString() ?: staff.firstOrNull()?.optInt("id")?.toString() ?: "") }
        // No "None": as the website and the iPhone app, the track defaults to the client's (or the first).
        var track by rememberSaveable { mutableStateOf(if (d.isNull("track_id") || d.optInt("track_id") <= 0) (tracks.firstOrNull()?.optInt("id")?.toString() ?: "0") else d.optInt("track_id").toString()) }
        var second by rememberSaveable { mutableStateOf(false) }
        var third by rememberSaveable { mutableStateOf(false) }
        var remote by rememberSaveable { mutableStateOf(d.optBoolean("remote")) }
        var location by rememberSaveable { mutableStateOf(d.str("location") ?: "") }
        var idem by rememberSaveable { mutableStateOf("") }
        var fieldErrors by remember { mutableStateOf<Map<String, String>>(emptyMap()) }
        var error by remember { mutableStateOf<String?>(null) }
        var asking by remember { mutableStateOf(false) }
        var busy by remember { mutableStateOf(false) }
        var presses by remember { mutableIntStateOf(0) }
        val scope = rememberCoroutineScope()
        // A time not offered (its toggle off) is not sent.
        val times = listOf(t1, if (second) t2 else "", if (third) t3 else "")
        HoldBackWhileSaving(busy)
        Page {
            Heading(client + ": " + req.optString("type_label"))
            val offered = r.optJSONArray("offered").objects()
            if (offered.isNotEmpty()) Text("Offered already: " + offered.joinToString("; ") { o -> whenText(o.str("start_at")) + (o.str("staff_name")?.let { " with $it" } ?: "") } + ".")
            if (!r.optBoolean("can_offer", true)) {
                Text("Times can be offered only for an open appointment or reschedule request.")
                return@Page
            }
            val hint = "year-month-day hour:minute, such as ${today()} 14:30"
            Field("First time", t1, { t1 = it }, hint = hint, error = fieldErrors["time1"], required = true)
            Tick("Offer a second time", second) { second = it }
            if (second) Field("Second time", t2, { t2 = it }, hint = hint, error = fieldErrors["time2"])
            Tick("Offer a third time", third) { third = it }
            if (third) Field("Third time", t3, { t3 = it }, hint = hint, error = fieldErrors["time3"])
            if (keepsLength) Text("The session keeps its length.")
            else Choice("Length", minutes, lengths.map { it.toString() to "$it minutes" }, error = fieldErrors["minutes"]) { minutes = it }
            if (staff.isNotEmpty()) Choice("With", who, staff.map { it.optInt("id").toString() to it.optString("name") }, error = fieldErrors["staff_id"]) { who = it }
            if (!keepsLength && tracks.isNotEmpty()) Choice("Training track", track, tracks.map { it.optInt("id").toString() to it.optString("title") }, error = fieldErrors["track_id"]) { track = it }
            Tick("Remote", remote) { remote = it }
            Field("Location", location, { location = it }, hint = "optional")
            ErrorText(error, focusKey = presses)
            PrimaryButton("Offer these times", busy = busy, busyText = "Offering the times…", announceBusy = true) {
                if (busy) return@PrimaryButton
                presses++
                fieldErrors = listOfNotNull(
                    if (t1.isBlank()) "time1" to "First time: enter a date and a time." else null,
                ).toMap() + times.indices.mapNotNull { i ->
                    val t = times[i]
                    when {
                        t.isBlank() -> if (i > 0 && (if (i == 1) second else third)) "time${i + 1}" to "${if (i == 1) "Second" else "Third"} time: enter a date and a time, or turn off Offer a ${if (i == 1) "second" else "third"} time." else null
                        offerTimeText(t) == null -> "time${i + 1}" to "This time could not be read. Enter a date and a time."
                        // A time already past is refused here (the client could never choose it).
                        offerTimePast(offerTimeText(t)!!) -> "time${i + 1}" to "This time has already passed. Enter a later date and time."
                        else -> null
                    }
                }.toMap()
                error = errorSummary(listOf("time1", "time2", "time3").map { fieldErrors[it] })
                if (error == null) asking = true
            }
            if (asking) Confirm(offerConfirmText(times.filter { it.isNotBlank() }.map { whenText(offerTimeText(it)?.let { t -> "$t:00" }) }, client), "", "Offer these times", {
                val sent = offerSentFields(times)
                scope.launchSave({ busy = it }, { }) {
                    try {
                        val b = offerTimesBody(id, times, if (keepsLength) null else minutes.toIntOrNull(), who.toIntOrNull() ?: 0, track.toIntOrNull() ?: 0, remote, location)
                        val res = api.callOnce("offer_times", b, idem, { idem = it }, "the offered times")
                        RequestOffers.count++
                        Flash.pending = res.optString("message").ifEmpty { "Times offered to $client. They choose one in their portal." }
                        nav.popBackStack()
                    } catch (e: ApiException) {
                        presses++
                        fieldErrors = offerFieldErrors(serverFieldErrors(e.reply) ?: emptyMap(), sent)
                        error = e.message
                    }
                }
            }, { asking = false }, no = "Not yet")
        }
    }
}
