package org.casemanagement.app.ui

import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.input.KeyboardType
import androidx.navigation.NavHostController
import kotlinx.coroutines.launch
import org.casemanagement.app.Api
import org.casemanagement.app.ApiException
import org.casemanagement.app.objects
import org.casemanagement.app.str
import org.json.JSONObject
import java.util.Locale

fun money(d: Double) = "$" + String.format(Locale.US, "%,.2f", d)

/** "24.50", "24,50" or "$24.50" as the server's money format, or null. */
fun moneyText(s: String): String? = s.trim().removePrefix("$").replace(',', '.').takeIf { Regex("^\\d{1,7}(\\.\\d{1,2})?$").matches(it) }

/** The clients a form can choose from (closed cases left out unless asked for). */
suspend fun clientChoices(api: Api, includeClosed: Boolean = false): List<JSONObject> {
    val list = try { api.call("client_choices").optJSONArray("clients").objects() } catch (e: ApiException) {
        if (e.status == 404) api.call("clients").optJSONArray("clients").objects() else throw e
    }
    return if (includeClosed) list else list.filter { !it.optString("status").startsWith("Closed") }
}

/** A dialog with a heading title, scrolling content, and Save / Cancel. */
@Composable
fun FormDialog(title: String, saveLabel: String, busy: Boolean, enabled: Boolean = true, onSave: () -> Unit, onCancel: () -> Unit, content: @Composable () -> Unit) {
    AlertDialog(
        onDismissRequest = { if (!busy) onCancel() },
        title = { Text(title, modifier = Modifier.semantics { heading() }) },
        text = { Column(Modifier.verticalScroll(rememberScrollState())) { content() } },
        confirmButton = { TextButton(enabled = enabled && !busy, onClick = onSave) { Text(if (busy) "Saving…" else saveLabel) } },
        dismissButton = { TextButton(enabled = !busy, onClick = onCancel) { Text("Cancel") } },
    )
}

/* ---------------- Pre-ETS programs ---------------- */

val PRE_ETS_ACTIVITIES = listOf("Job Exploration Counseling", "Work-Based Learning", "Postsecondary Counseling", "Workplace Readiness", "Self-Advocacy Instruction", "Other")

@Composable
fun ProgramsScreen(api: Api, nav: NavHostController) {
    val state = rememberLoaded(api.userId) { api.call("cohorts").optJSONArray("cohorts").objects() }
    var adding by remember { mutableStateOf(false) }
    var name by remember { mutableStateOf("") }
    var school by remember { mutableStateOf("") }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    val scope = rememberCoroutineScope()
    Page {
        PrimaryButton("Add a cohort") { adding = true }
        Show(state, "cohorts") { list ->
            Heading("Active cohorts (${list.size})")
            if (list.isEmpty()) Text("No active cohorts.")
            list.forEach { c -> ListRow(listOf(c.optString("name"), listOfNotNull(c.str("school"), "${c.optInt("members")} students", "${c.optInt("sessions")} sessions").joinToString(", ")), action = "open it", onClick = { nav.navigate("cohort/${c.optInt("id")}") }) }
        }
    }
    if (adding) FormDialog("Add a cohort", "Add", busy, name.isNotBlank(), onSave = {
        scope.launchSave({ busy = it }, { error = it }) {
            val r = api.call("add_cohort", body = JSONObject().put("name", name.trim()).put("school", school.trim()))
            adding = false; name = ""; school = ""; nav.navigate("cohort/${r.optInt("id")}")
        }
    }, onCancel = { adding = false }) {
        Field("Cohort name", name, { name = it })
        Field("School", school, { school = it }, hint = "optional")
        ErrorText(error)
    }
}

@Composable
fun CohortScreen(api: Api, id: Int) {
    val state = rememberLoaded(id) { api.call("cohort", mapOf("id" to id.toString())) }
    var notice by remember { mutableStateOf<String?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    var pick by remember { mutableStateOf("") }
    var removing by remember { mutableStateOf<JSONObject?>(null) }
    var logging by remember { mutableStateOf(false) }
    val scope = rememberCoroutineScope()
    fun run(block: suspend () -> String) { error = null; scope.launch { try { notice = block(); state.reload() } catch (e: ApiException) { error = e.message } } }
    Show(state, "the cohort") { r ->
        val c = r.optJSONObject("cohort") ?: JSONObject()
        val members = r.optJSONArray("members").objects()
        Page {
            Heading(c.optString("name"), large = true)
            NoticeText(notice)
            ErrorText(error)
            c.str("school")?.let { ListRow(listOf("School: $it")) }
            PrimaryButton("Log a group session", enabled = members.isNotEmpty()) { logging = true }
            Heading("Students (${members.size})")
            members.forEach { m -> ListRow(listOf(m.optString("name")), action = "remove from the cohort", onClick = { removing = m }) }
            val available = r.optJSONArray("available").objects()
            if (available.isNotEmpty()) {
                Choice("Student to add", pick, listOf("" to "Choose a Pre-ETS student") + available.map { it.optInt("id").toString() to it.optString("name") }) { pick = it }
                SecondaryButton("Add to the cohort") {
                    if (pick.isEmpty()) { error = "Choose a student to add."; return@SecondaryButton }
                    val who = available.firstOrNull { it.optInt("id").toString() == pick }?.optString("name")
                    if (who == null) { error = "That student is no longer available to add. Choose again."; pick = ""; return@SecondaryButton }
                    run { api.call("cohort_member", body = JSONObject().put("cohort_id", id).put("client_id", pick.toInt()).put("op", "add")); pick = ""; "$who added to the cohort." }
                }
            }
            val sessions = r.optJSONArray("sessions").objects()
            Heading("Sessions (${sessions.size})")
            sessions.forEach { s -> ListRow(listOf(s.optString("activity") + ", " + dayText(s.str("session_date")), "${s.optInt("duration_min")} minutes, ${s.optInt("present_n")} present" + (s.str("topic")?.let { ", $it" } ?: ""))) }
        }
        if (logging) CohortSessionDialog(api, id, members, onDone = { m -> logging = false; notice = m; state.reload() }, onCancel = { logging = false })
    }
    removing?.let { m ->
        Confirm("Remove ${m.optString("name")} from the cohort?", "", "Remove student", {
            run { api.call("cohort_member", body = JSONObject().put("cohort_id", id).put("client_id", m.optInt("id")).put("op", "remove")); "${m.optString("name")} removed from the cohort." }
        }, { removing = null })
    }
}

@Composable
private fun CohortSessionDialog(api: Api, cohortId: Int, members: List<JSONObject>, onDone: (String) -> Unit, onCancel: () -> Unit) {
    var activity by remember { mutableStateOf(PRE_ETS_ACTIVITIES.first()) }
    var date by remember { mutableStateOf(today()) }
    var minutes by remember { mutableStateOf("50") }
    var topic by remember { mutableStateOf("") }
    var notes by remember { mutableStateOf("") }
    var present by remember { mutableStateOf(members.map { it.optInt("id") }.toSet()) }
    var confirming by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    var busy by remember { mutableStateOf(false) }
    val scope = rememberCoroutineScope()
    FormDialog("Log a group session", "Log", busy, onSave = {
        val m = minutes.trim().toIntOrNull()
        if (m == null || m !in 1..1440) { error = "Duration: enter the session length in whole minutes from 1 to 1440, like 50."; return@FormDialog }
        confirming = true
    }, onCancel = onCancel) {
        Choice("Activity", activity, PRE_ETS_ACTIVITIES.map { it to it }) { activity = it }
        Field("Date", date, { date = it }, hint = "year-month-day")
        Field("Length in minutes", minutes, { minutes = it }, keyboard = KeyboardType.Number)
        Field("Topic", topic, { topic = it }, hint = "optional")
        Field("Notes", notes, { notes = it }, hint = "optional", singleLine = false)
        Heading("Who was there")
        members.forEach { s -> Tick(s.optString("name"), s.optInt("id") in present) { on -> present = if (on) present + s.optInt("id") else present - s.optInt("id") } }
        ErrorText(error)
    }
    if (confirming) {
        val absent = members.filter { it.optInt("id") !in present }.map { it.optString("name") }
        Confirm("Log $activity on ${dayText(date.trim())}, ${minutes.trim()} minutes, ${present.size} of ${members.size} present?",
            (if (absent.isNotEmpty()) "Absent: " + absent.joinToString(", ") + ". " else "") + "A logged session cannot be changed from the app.", "Log session", {
                scope.launchSave({ busy = it }, { error = it }) {
                    api.call("log_cohort_session", body = JSONObject().put("cohort_id", cohortId).put("activity", activity).put("session_date", date.trim())
                        .put("duration_min", minutes.trim().toInt()).put("topic", topic.trim()).put("notes", notes.trim()).put("present", org.json.JSONArray(present.toList())))
                    onDone("Session logged.")
                }
            }, { confirming = false })
    }
}

/* ---------------- Grants and contracts ---------------- */

@Composable
fun ContractsScreen(api: Api, nav: NavHostController) {
    val state = rememberLoaded(api.userId) { api.call("contracts").optJSONArray("contracts").objects() }
    var adding by remember { mutableStateOf(false) }
    Page {
        PrimaryButton("Add a contract") { adding = true }
        Show(state, "contracts") { list ->
            Heading("Active contracts (${list.size})")
            if (list.isEmpty()) Text("No active contracts.")
            list.forEach { c -> ListRow(listOf(c.optString("name"), money(c.optDouble("spent", 0.0)) + " of " + money(c.optDouble("amount", 0.0)) + (c.str("funder")?.let { ", $it" } ?: ""), c.str("end_date")?.let { "ends " + dayText(it) } ?: ""), action = "open it", onClick = { nav.navigate("contract/${c.optInt("id")}") }) }
        }
    }
    if (adding) AddContractDialog(api, onDone = { id -> adding = false; nav.navigate("contract/$id") }, onCancel = { adding = false })
}

@Composable
private fun AddContractDialog(api: Api, onDone: (Int) -> Unit, onCancel: () -> Unit) {
    var name by remember { mutableStateOf("") }
    var funder by remember { mutableStateOf("") }
    var number by remember { mutableStateOf("") }
    var amount by remember { mutableStateOf("") }
    var start by remember { mutableStateOf("") }
    var end by remember { mutableStateOf("") }
    var error by remember { mutableStateOf<String?>(null) }
    var busy by remember { mutableStateOf(false) }
    val scope = rememberCoroutineScope()
    FormDialog("Add a contract", "Add", busy, name.isNotBlank(), onSave = {
        val a = if (amount.isBlank()) "0" else moneyText(amount)
        if (a == null) { error = "Enter the amount in dollars, such as 12500."; return@FormDialog }
        scope.launchSave({ busy = it }, { error = it }) {
            onDone(api.call("add_contract", body = JSONObject().put("name", name.trim()).put("funder", funder.trim()).put("contract_number", number.trim())
                .put("amount", a).put("start_date", start.trim()).put("end_date", end.trim())).optInt("id"))
        }
    }, onCancel = onCancel) {
        Field("Name", name, { name = it })
        Field("Funder", funder, { funder = it }, hint = "optional")
        Field("Contract number", number, { number = it }, hint = "optional")
        Field("Budget in dollars", amount, { amount = it }, keyboard = KeyboardType.Decimal)
        Field("Start date", start, { start = it }, hint = "year-month-day, optional")
        Field("End date", end, { end = it }, hint = "year-month-day, optional")
        ErrorText(error)
    }
}

@Composable
fun ContractScreen(api: Api, id: Int) {
    val state = rememberLoaded(id) { api.call("contract", mapOf("id" to id.toString())) }
    var notice by remember { mutableStateOf<String?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    var deliverable by remember { mutableStateOf<JSONObject?>(null) }
    var form by remember { mutableStateOf("") }
    val scope = rememberCoroutineScope()
    fun update(body: JSONObject, done: String) { error = null; scope.launch { try { api.call("contract_update", body = body.put("contract_id", id)); notice = done; state.reload() } catch (e: ApiException) { error = e.message } } }
    Show(state, "the contract") { r ->
        val c = r.optJSONObject("contract") ?: JSONObject()
        val amount = c.optDouble("amount", 0.0); val spent = r.optDouble("spent", 0.0)
        Page {
            Heading(c.optString("name"), large = true)
            NoticeText(notice)
            ErrorText(error)
            ListRow(listOf("Spent " + money(spent) + " of " + money(amount) + if (amount > 0) " (${Math.floor(spent * 100 / amount).toInt()}%)" else " (no budget set)"))
            listOfNotNull(c.str("funder")?.let { "Funder: $it" }, c.str("contract_number")?.let { "Number: $it" }, c.str("end_date")?.let { "Ends " + dayText(it) }).forEach { ListRow(listOf(it)) }
            val dels = r.optJSONArray("deliverables").objects()
            Heading("Deliverables (${dels.size})")
            dels.forEach { d -> ListRow(listOf(d.optString("title"), d.optString("status") + (d.str("due_date")?.let { ", due " + dayText(it) } ?: "")), action = "change its status", onClick = { deliverable = d }) }
            SecondaryButton("Add a deliverable") { form = "deliverable" }
            val spend = r.optJSONArray("spend").objects()
            Heading("Spending")
            if (r.optInt("spend_total") > spend.size) Text("Showing the latest ${spend.size} of ${r.optInt("spend_total")} entries. The website lists them all.")
            spend.forEach { s -> ListRow(listOf(money(s.optDouble("amount", 0.0)) + ", " + dayText(s.str("spent_on")), s.str("memo") ?: "")) }
            SecondaryButton("Add spending") { form = "spend" }
        }
    }
    deliverable?.let { d ->
        AlertDialog(onDismissRequest = { deliverable = null }, title = { Text(d.optString("title"), modifier = Modifier.semantics { heading() }) },
            text = { Column { listOf("Pending", "In Progress", "Submitted", "Accepted").filter { it != d.optString("status") }.forEach { s ->
                TextButton(onClick = { deliverable = null; update(JSONObject().put("op", "set_deliverable").put("deliverable_id", d.optInt("id")).put("status", s), "${d.optString("title")} marked $s.") }) { Text("Mark it $s") } } } },
            confirmButton = { TextButton(onClick = { deliverable = null }) { Text("Cancel") } })
    }
    if (form.isNotEmpty()) {
        var a by remember { mutableStateOf("") }
        var b by remember { mutableStateOf(if (form == "spend") today() else "") }
        var t by remember { mutableStateOf("") }
        var err by remember { mutableStateOf<String?>(null) }
        FormDialog(if (form == "spend") "Add spending" else "Add a deliverable", "Add", false, onSave = {
            if (form == "spend") {
                val m = moneyText(a)
                if (m == null || m.toDouble() <= 0) { err = "Enter the amount in dollars, such as 120.50."; return@FormDialog }
                form = ""; update(JSONObject().put("op", "add_spend").put("amount", m).put("spent_on", b.trim()).put("memo", t.trim()), "Spending added.")
            } else {
                if (t.isBlank()) { err = "Give the deliverable a title."; return@FormDialog }
                form = ""; update(JSONObject().put("op", "add_deliverable").put("title", t.trim()).put("due_date", b.trim()), "Deliverable added.")
            }
        }, onCancel = { form = "" }) {
            if (form == "spend") {
                Field("Amount in dollars", a, { a = it }, keyboard = KeyboardType.Decimal)
                Field("Date", b, { b = it }, hint = "year-month-day")
                Field("What it was for", t, { t = it }, hint = "optional")
            } else {
                Field("Title", t, { t = it })
                Field("Due date", b, { b = it }, hint = "year-month-day, optional")
            }
            ErrorText(err)
        }
    }
}

/* ---------------- Interpreter and transport bookings ---------------- */

@Composable
fun BookingsScreen(api: Api) {
    val state = rememberLoaded(api.userId) {
        val up = api.call("bookings").optJSONArray("bookings").objects()
        val upIds = up.map { it.optInt("id") }.toSet()
        up to api.call("bookings", mapOf("past" to "1")).optJSONArray("bookings").objects().filter { it.optInt("id") !in upIds }
    }
    var chosen by remember { mutableStateOf<JSONObject?>(null) }
    var cancelling by remember { mutableStateOf<JSONObject?>(null) }
    var adding by remember { mutableStateOf(false) }
    var notice by remember { mutableStateOf<String?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    val scope = rememberCoroutineScope()
    fun setStatus(b: JSONObject, to: String) { error = null; scope.launch { try { api.call("booking_status", body = JSONObject().put("booking_id", b.optInt("id")).put("to", to)); notice = if (to == "Cancelled") "Booking cancelled." else "Booking marked $to."; state.reload() } catch (e: ApiException) { error = e.message } } }
    Page {
        NoticeText(notice)
        ErrorText(error)
        PrimaryButton("Add a booking") { adding = true }
        Show(state, "bookings") { (up, past) ->
            listOf("Upcoming" to up, "Past" to past).forEach { (title, list) ->
                Heading("$title (${list.size})")
                if (list.isEmpty()) Text(if (title == "Upcoming") "No upcoming bookings." else "No past bookings.")
                list.forEach { b -> ListRow(listOf(b.optString("service_type") + " for " + b.optString("client_name"), listOfNotNull(b.optString("status"), b.str("needed_at")?.let { whenText(it) }, b.str("provider"), b.str("location")).joinToString(", ")), action = "change its status", onClick = { chosen = b }) }
            }
        }
    }
    chosen?.let { b ->
        AlertDialog(onDismissRequest = { chosen = null }, title = { Text(b.optString("service_type") + " for " + b.optString("client_name"), modifier = Modifier.semantics { heading() }) },
            text = { Column { listOf("Requested", "Confirmed", "Completed", "Cancelled").filter { it != b.optString("status") }.forEach { s ->
                TextButton(onClick = { chosen = null; if (s == "Cancelled") cancelling = b else setStatus(b, s) }) { Text(if (s == "Cancelled") "Cancel the booking" else "Mark it $s") } } } },
            confirmButton = { TextButton(onClick = { chosen = null }) { Text("Close") } })
    }
    cancelling?.let { b -> Confirm("Cancel the ${b.optString("service_type")} booking for ${b.optString("client_name")}?", "Cancelled bookings are removed from this list.", "Cancel booking", { setStatus(b, "Cancelled") }, { cancelling = null }) }
    if (adding) AddBookingDialog(api, onDone = { m -> adding = false; notice = m; state.reload() }, onCancel = { adding = false })
}

@Composable
private fun AddBookingDialog(api: Api, onDone: (String) -> Unit, onCancel: () -> Unit) {
    val clients = rememberLoaded(api.userId) { clientChoices(api) }
    val types = api.formList("booking_types", listOf("Interpreter", "CART captioning", "Transportation", "Note-taker", "Other"))
    var client by remember { mutableStateOf("") }
    var type by remember { mutableStateOf(types.first()) }
    var date by remember { mutableStateOf("") }
    var time by remember { mutableStateOf("") }
    var minutes by remember { mutableStateOf("60") }
    var provider by remember { mutableStateOf("") }
    var location by remember { mutableStateOf("") }
    var cost by remember { mutableStateOf("") }
    var notes by remember { mutableStateOf("") }
    var error by remember { mutableStateOf<String?>(null) }
    var busy by remember { mutableStateOf(false) }
    val scope = rememberCoroutineScope()
    FormDialog("Add a booking", "Add", busy, client.isNotEmpty(), onSave = {
        val t = if (time.isBlank()) null else time24(time)
        val m = minutes.trim().toIntOrNull()
        val c = if (cost.isBlank()) "" else moneyText(cost)
        error = when {
            date.isNotBlank() && !Regex("^\\d{4}-\\d{2}-\\d{2}$").matches(date.trim()) -> "Enter the date as year-month-day, or leave it empty."
            time.isNotBlank() && t == null -> "Enter the time such as 2:30 PM."
            m == null || m !in 0..1440 -> "Enter the length in whole minutes, 0 to 1440."
            c == null -> "Enter the cost in dollars, such as 85.00, or leave it empty."
            else -> null
        }
        if (error != null) return@FormDialog
        scope.launchSave({ busy = it }, { error = it }) {
            try {
                api.call("add_booking", body = JSONObject().put("client_id", client.toInt()).put("service_type", type)
                    .put("needed_at", if (date.isBlank()) "" else date.trim() + " " + (t ?: "09:00")).put("duration_min", m)
                    .put("provider", provider.trim()).put("location", location.trim()).put("cost", c).put("notes", notes.trim()))
                onDone("Booking added as Requested.")
            } catch (e: ApiException) {
                if (e.network) error = "The connection dropped, so it is not known whether the booking was added. Check the list before adding it again." else throw e
            }
        }
    }, onCancel = onCancel) {
        ErrorText(clients.error)
        Choice("Client", client, listOf("" to "Choose a client") + (clients.data ?: emptyList()).map { it.optInt("id").toString() to it.optString("name") }) { client = it }
        Choice("Service", type, types.map { it to it }) { type = it }
        Field("Date needed", date, { date = it }, hint = "year-month-day, optional")
        Field("Time", time, { time = it }, hint = "such as 2:30 PM, optional")
        Field("Length in minutes", minutes, { minutes = it }, keyboard = KeyboardType.Number)
        Field("Provider", provider, { provider = it }, hint = "optional")
        Field("Location", location, { location = it }, hint = "optional")
        Field("Cost in dollars", cost, { cost = it }, hint = "optional", keyboard = KeyboardType.Decimal)
        Field("Notes", notes, { notes = it }, hint = "optional", singleLine = false)
        ErrorText(error)
    }
}
