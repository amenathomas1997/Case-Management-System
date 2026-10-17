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
import org.casemanagement.app.objects
import org.casemanagement.app.str

/** The client list, searched on the server as on the website. */
@Composable
fun ClientsScreen(api: Api, nav: NavHostController) {
    var q by rememberSaveable { mutableStateOf("") }
    var search by rememberSaveable { mutableStateOf("") }
    // The whole list is kept on the phone for reading without a signal (a search is not).
    val state = rememberLoaded(search) {
        if (search.isEmpty()) api.callCached("clients", cacheKey = "clients").let { (r, at) -> r.optJSONArray("clients").objects() to at }
        else api.call("clients", mapOf("q" to search)).optJSONArray("clients").objects() to null
    }
    Page {
        Field("Search clients", q, { q = it }, hint = "name, phone or email")
        SecondaryButton("Search") { search = q.trim() }
        SecondaryButton("Add a client") { nav.navigate("clientEdit/0") }
        Show(state, "clients") { (list, savedAt) ->
            OfflineCopyNotice(savedAt)
            Announce(if (search.isNotEmpty()) "${list.size} ${if (list.size == 1) "client" else "clients"} found" else null)
            Heading(if (search.isEmpty()) "All clients (${list.size})" else "Clients matching $search (${list.size})")
            if (list.isEmpty()) Text("No clients found.")
            list.forEach { c ->
                ListRow(listOf(c.optString("name"), c.optString("status"), c.str("phone") ?: ""), onClick = { nav.navigate("client/${c.optInt("id")}") })
            }
        }
    }
}

/** One client: contact details, training tracks with their hours, and case notes. */
@Composable
fun ClientScreen(api: Api, nav: NavHostController, id: Int) {
    val context = LocalContext.current
    val state = rememberLoaded(id) { api.callCached("client", mapOf("id" to id.toString()), "client.$id") }
    var linkError by remember { mutableStateOf<String?>(null) }
    Show(state, "the client") { (r, savedAt) ->
        val c = r.optJSONObject("client") ?: return@Show
        Page {
            Heading(c.optString("name"), large = true)
            OfflineCopyNotice(savedAt)
            ListRow(listOf("Status: " + c.optString("status")))
            c.str("phone")?.let { p -> ListRow(listOf("Phone: $p"), action = "call", onClick = {
                if (!openLink(context, Intent(Intent.ACTION_DIAL, Uri.parse("tel:" + p.filter { it.isDigit() || it == '+' })))) linkError = "This phone has no app for calls."
            }) }
            c.str("email")?.let { e -> ListRow(listOf("Email: $e"), action = "write an email", onClick = {
                if (!openLink(context, Intent(Intent.ACTION_SENDTO, Uri.parse("mailto:" + Uri.encode(e))))) linkError = "This phone has no email app."
            }) }
            ErrorText(linkError)
            c.str("address")?.let { ListRow(listOf("Address: $it")) }
            c.str("counselor_name")?.let { n -> ListRow(listOf("Counselor: $n", c.str("counselor_agency") ?: "", c.str("counselor_email") ?: "", c.str("counselor_phone") ?: "")) }
            val a11y = listOfNotNull(c.str("a11y_format")?.let { "Prefers $it" }, c.str("a11y_primary_tech"), c.str("a11y_contact")?.let { "Contact by $it" })
            if (a11y.isNotEmpty()) ListRow(listOf("Accessibility: " + a11y.joinToString(", ")))

            PrimaryButton("Add a case note") { nav.navigate("note/$id") }
            SecondaryButton("Documents and homework") { nav.navigate("docs/$id") }
            SecondaryButton("Edit client details") { nav.navigate("clientEdit/$id") }

            val tracks = r.optJSONArray("tracks").objects()
            Heading("Training tracks (${tracks.size})")
            if (tracks.isEmpty()) Text("No training tracks.")
            tracks.forEach { t ->
                val auth = t.optDouble("authorized_hours", 0.0)
                val worked = t.optDouble("hours_worked", 0.0)
                ListRow(
                    listOf(t.optString("title"), t.optString("status") + (t.str("auth_number")?.let { ", authorization $it" } ?: ""),
                        if (auth > 0) "${hoursText(worked)} of ${hoursText(auth)} hours used" + (t.str("expiration_date")?.let { ", ends " + dayText(it) } ?: "") else "${hoursText(worked)} hours logged"),
                    action = "open it",
                    onClick = { nav.navigate("track/$id/${t.optInt("id")}") },
                )
            }
            SecondaryButton("Add a training track") { nav.navigate("trackEdit/$id/0") }

            val notes = r.optJSONArray("notes").objects()
            Heading("Case notes (${r.optInt("notes_total", notes.size)})")
            notes.forEach { n -> NoteRow(n) }
        }
    }
}

@Composable
private fun NoteRow(n: org.json.JSONObject) {
    var open by remember { mutableStateOf(false) }
    val head = listOf(n.optString("subject"), n.optString("note_type") + ", " + whenText(n.str("created_at")) + (n.str("author")?.let { ", by $it" } ?: ""))
    ListRow(head, action = if (open) "close the note" else "read the note", state = if (open) "expanded" else "collapsed", onClick = { open = !open })
    if (open) Text(n.optString("body"))
}

/** A case note, saved as on the website; with no signal it is kept on the phone and sent later. */
@Composable
fun AddNoteScreen(api: Api, nav: NavHostController, clientId: Int) {
    val types = api.formList("note_types", listOf("Case Note", "Progress Report", "Monthly Summary", "Intake", "Addendum"))
    var type by rememberSaveable { mutableStateOf(types.first()) }
    var subject by rememberSaveable { mutableStateOf("") }
    var body by rememberSaveable { mutableStateOf("") }
    val idem = rememberSaveable { java.util.UUID.randomUUID().toString() }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    val scope = androidx.compose.runtime.rememberCoroutineScope()
    Page {
        Choice("Note type", type, types.map { it to it }) { type = it }
        Field("Subject", subject, { subject = it })
        Field("Note", body, { body = it }, singleLine = false, minLines = 6)
        ErrorText(error)
        PrimaryButton("Save note", busy = busy, enabled = subject.isNotBlank() && body.isNotBlank()) {
            scope.launchSave({ busy = it }, { error = it }) {
                val payload = org.json.JSONObject().put("client_id", clientId).put("note_type", type).put("note_format", "free")
                    .put("subject", subject.trim()).put("body", body.trim()).put("idem_key", idem)
                Flash.pending = saveOrQueue(api, "add_note", payload, "Case note: " + subject.trim().take(40), "Case note saved.").message
                nav.popBackStack()
            }
        }
    }
}

/** Hours on a training track, with mileage; with no signal they are kept and sent later. */
@Composable
fun LogHoursScreen(api: Api, nav: NavHostController, clientId: Int, trackId: Int) {
    val tracks = rememberLoaded(trackId) { api.call("tracks").optJSONArray("tracks").objects() }
    var date by rememberSaveable { mutableStateOf(today()) }
    var hours by rememberSaveable { mutableStateOf("") }
    var miles by rememberSaveable { mutableStateOf("") }
    var description by rememberSaveable { mutableStateOf("") }
    var category by rememberSaveable { mutableStateOf("") }
    val idem = rememberSaveable { java.util.UUID.randomUUID().toString() }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    val scope = androidx.compose.runtime.rememberCoroutineScope()
    Page {
        val t = tracks.data?.firstOrNull { it.optInt("id") == trackId }
        Heading(t?.let { it.optString("client") + ": " + it.optString("title") } ?: "Training track")
        val cats = t?.optJSONArray("visit_categories")?.let { a -> List(a.length()) { a.optString(it) } } ?: emptyList()
        if (cats.isNotEmpty()) Choice("Kind of visit", category, listOf("" to "Not chosen") + cats.map { it to it }) { category = it }
        Field("Date", date, { date = it }, hint = "year-month-day, such as ${today()}")
        Field("Hours", hours, { hours = it }, hint = "such as 1.5", keyboard = KeyboardType.Decimal)
        Field("Miles driven", miles, { miles = it }, hint = "0 if none", keyboard = KeyboardType.Decimal)
        Field("What was done", description, { description = it }, singleLine = false, minLines = 4)
        ErrorText(error)
        PrimaryButton("Log hours", busy = busy, enabled = hours.isNotBlank() && description.isNotBlank()) {
            val h = number(hours)
            val m = number(miles.ifBlank { "0" })
            when {
                h == null || h <= 0 || h > 24 -> error = "Enter the hours as a number from 0.25 to 24, such as 1.5."
                m == null || m < 0 || m > 1000 -> error = "Enter the miles as a number from 0 to 1000."
                !Regex("^\\d{4}-\\d{2}-\\d{2}$").matches(date.trim()) -> error = "Enter the date as year-month-day, such as ${today()}."
                else -> scope.launchSave({ busy = it }, { error = it }) {
                    val payload = org.json.JSONObject().put("track_id", trackId).put("hours", h).put("date", date.trim())
                        .put("mileage", m).put("description", description.trim()).put("idem_key", idem)
                    if (category.isNotEmpty()) payload.put("rsa_category", category)
                    Flash.pending = saveOrQueue(api, "log_hours", payload, "Hours: ${hoursText(h)} on " + (t?.optString("client") ?: "track $trackId"), "Hours logged.").message
                    nav.popBackStack()
                }
            }
        }
    }
}
