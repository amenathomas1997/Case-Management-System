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
import androidx.compose.runtime.saveable.rememberSaveable
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
import androidx.compose.runtime.key
import org.casemanagement.app.dialogKey
import org.casemanagement.app.listTotal
import org.casemanagement.app.countLabel
import org.casemanagement.app.idsToText
import org.casemanagement.app.idsFromText

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

/**
 * A dialog with a heading title, scrolling content, and Save / Cancel. 4.30.0: Save stays enabled
 * while something is [missing]; a press says what (at the end of the dialog, taking focus).
 */
@Composable
fun FormDialog(title: String, saveLabel: String, busy: Boolean, enabled: Boolean = true, onSave: () -> Unit, onCancel: () -> Unit,
               missing: String? = null, content: @Composable () -> Unit) {
    val gate = rememberGate(missing)
    // Each Save press: the dialog's error takes focus again even when its words are the same (4.30.0).
    var presses by remember { androidx.compose.runtime.mutableIntStateOf(0) }
    AlertDialog(
        onDismissRequest = { if (!busy) onCancel() },
        title = { Text(title, modifier = Modifier.semantics { heading() }) },
        text = {
            Column(Modifier.verticalScroll(rememberScrollState())) {
                androidx.compose.runtime.CompositionLocalProvider(LocalSavePresses provides presses) { content() }
                GateError(gate)
            }
        },
        confirmButton = { TextButton(enabled = enabled && !busy, onClick = { presses++; if (gate.check(missing)) onSave() }) { Text(if (busy) "Saving…" else saveLabel) } },
        dismissButton = { TextButton(enabled = !busy, onClick = onCancel) { Text("Cancel") } },
    )
}

/* ---------------- Pre-ETS programs ---------------- */

val PRE_ETS_ACTIVITIES = listOf("Job Exploration Counseling", "Work-Based Learning", "Postsecondary Counseling", "Workplace Readiness", "Self-Advocacy Instruction", "Other")

@Composable
fun ProgramsScreen(api: Api, nav: NavHostController) {
    val state = rememberLoaded(api.userId) { api.call("cohorts").optJSONArray("cohorts").objects() }
    var adding by rememberSaveable { mutableStateOf(false) }
    var name by rememberSaveable { mutableStateOf("") }
    var school by rememberSaveable { mutableStateOf("") }
    var idem by rememberSaveable { mutableStateOf("") }
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
    if (adding) FormDialog("Add a cohort", "Add", busy, missing = stillNeeded(missingNames("Cohort name" to name.isBlank())), onSave = {
        scope.launchSave({ busy = it }, { error = it }) {
            // 4.28.0: a retry after a lost reply adds it once (see sendOnce).
            val r = api.callOnce("add_cohort", JSONObject().put("name", name.trim()).put("school", school.trim()), idem, { idem = it }, "the cohort")
            adding = false; name = ""; school = ""; nav.navigate("cohort/${r.optInt("id")}")
        }
    }, onCancel = { adding = false }) {
        Field("Cohort name", name, { name = it }, required = true)
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
    // 4.28.0: adding a student is sent with a retry key (see sendOnce).
    var addKey by rememberSaveable { mutableStateOf("") }
    var logging by rememberSaveable { mutableStateOf(false) }
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
            PrimaryButton("Log a group session", missing = if (members.isEmpty()) "Add a student to the cohort first." else null) { logging = true }
            Heading("Students (${members.size})")
            members.forEach { m -> ListRow(listOf(m.optString("name")), action = "remove from the cohort", onClick = { removing = m }) }
            val available = r.optJSONArray("available").objects()
            if (available.isNotEmpty()) {
                Choice("Student to add", pick, listOf("" to "Choose a Pre-ETS student") + available.map { it.optInt("id").toString() to it.optString("name") }) { pick = it }
                SecondaryButton("Add to the cohort") {
                    if (pick.isEmpty()) { error = "Choose a student to add."; return@SecondaryButton }
                    val who = available.firstOrNull { it.optInt("id").toString() == pick }?.optString("name")
                    if (who == null) { error = "That student is no longer available to add. Choose again."; pick = ""; return@SecondaryButton }
                    run { api.callOnce("cohort_member", JSONObject().put("cohort_id", id).put("client_id", pick.toInt()).put("op", "add"), addKey, { addKey = it }, "the student"); pick = ""; "$who added to the cohort." }
                }
            }
            val sessions = r.optJSONArray("sessions").objects()
            // 4.29.0: the server's total, so a cut list says "latest N of M".
            val sessionsTotal = listTotal(r, sessions.size, "sessions_total")
            Heading(countLabel("Sessions", sessions.size, sessionsTotal))
            if (sessionsTotal > sessions.size) Text("The older sessions are on the website's cohort page.")
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
    var activity by rememberSaveable { mutableStateOf(PRE_ETS_ACTIVITIES.first()) }
    var date by rememberSaveable { mutableStateOf(today()) }
    var minutes by rememberSaveable { mutableStateOf("50") }
    var topic by rememberSaveable { mutableStateOf("") }
    var notes by rememberSaveable { mutableStateOf("") }
    // 4.29.0: who was ticked present is kept across rotation and a text-size change (as text), so
    // an unticked absent student is never put back as present.
    var presentText by rememberSaveable { mutableStateOf(idsToText(members.map { it.optInt("id") })) }
    val present = idsFromText(presentText).filter { id -> members.any { it.optInt("id") == id } }.toSet()
    var confirming by rememberSaveable { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    var minutesError by remember { mutableStateOf<String?>(null) }
    var busy by remember { mutableStateOf(false) }
    var idem by rememberSaveable { mutableStateOf("") }
    val scope = rememberCoroutineScope()
    FormDialog("Log a group session", "Log", busy, onSave = {
        val m = minutes.trim().toIntOrNull()
        minutesError = if (m == null || m !in 1..1440) "Duration: enter the session length in whole minutes from 1 to 1440, like 50." else null
        if (minutesError != null) { error = minutesError; return@FormDialog }
        confirming = true
    }, onCancel = onCancel) {
        Choice("Activity", activity, PRE_ETS_ACTIVITIES.map { it to it }) { activity = it }
        Field("Date", date, { date = it }, hint = "year-month-day")
        Field("Length in minutes", minutes, { minutes = it }, keyboard = KeyboardType.Number, error = minutesError, required = true)
        Field("Topic", topic, { topic = it }, hint = "optional")
        Field("Notes", notes, { notes = it }, hint = "optional", singleLine = false)
        Heading("Who was there")
        members.forEach { s -> Tick(s.optString("name"), s.optInt("id") in present) { on -> presentText = idsToText(if (on) present + s.optInt("id") else present - s.optInt("id")) } }
        ErrorText(error)
    }
    if (confirming) {
        val absent = members.filter { it.optInt("id") !in present }.map { it.optString("name") }
        Confirm("Log $activity on ${dayText(date.trim())}, ${minutes.trim()} minutes, ${present.size} of ${members.size} present?",
            (if (absent.isNotEmpty()) "Absent: " + absent.joinToString(", ") + ". " else "") + "A logged session cannot be changed from the app.", "Log session", {
                scope.launchSave({ busy = it }, { error = it }) {
                    api.callOnce("log_cohort_session", JSONObject().put("cohort_id", cohortId).put("activity", activity).put("session_date", date.trim())
                        .put("duration_min", minutes.trim().toInt()).put("topic", topic.trim()).put("notes", notes.trim()).put("present", org.json.JSONArray(present.sorted())),
                        idem, { idem = it }, "the group session")
                    onDone("Session logged.")
                }
            }, { confirming = false })
    }
}

/* ---------------- Grants and contracts ---------------- */

@Composable
fun ContractsScreen(api: Api, nav: NavHostController) {
    val state = rememberLoaded(api.userId) { api.call("contracts").optJSONArray("contracts").objects() }
    var adding by rememberSaveable { mutableStateOf(false) }
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
    var name by rememberSaveable { mutableStateOf("") }
    var funder by rememberSaveable { mutableStateOf("") }
    var number by rememberSaveable { mutableStateOf("") }
    var amount by rememberSaveable { mutableStateOf("") }
    var start by rememberSaveable { mutableStateOf("") }
    var end by rememberSaveable { mutableStateOf("") }
    var idem by rememberSaveable { mutableStateOf("") }
    var error by remember { mutableStateOf<String?>(null) }
    var amountError by remember { mutableStateOf<String?>(null) }
    var busy by remember { mutableStateOf(false) }
    val scope = rememberCoroutineScope()
    FormDialog("Add a contract", "Add", busy, missing = stillNeeded(missingNames("Name" to name.isBlank())), onSave = {
        val a = if (amount.isBlank()) "0" else moneyText(amount)
        amountError = if (a == null) "Enter the budget in dollars, such as 12500." else null
        if (a == null) { error = amountError; return@FormDialog }
        scope.launchSave({ busy = it }, { error = it }) {
            onDone(api.callOnce("add_contract", JSONObject().put("name", name.trim()).put("funder", funder.trim()).put("contract_number", number.trim())
                .put("amount", a).put("start_date", start.trim()).put("end_date", end.trim()), idem, { idem = it }, "the contract").optInt("id"))
        }
    }, onCancel = onCancel) {
        Field("Name", name, { name = it }, required = true)
        Field("Funder", funder, { funder = it }, hint = "optional")
        Field("Contract number", number, { number = it }, hint = "optional")
        Field("Budget in dollars", amount, { amount = it }, keyboard = KeyboardType.Decimal, error = amountError)
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
    var form by rememberSaveable { mutableStateOf("") }
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
            text = { Column(Modifier.verticalScroll(rememberScrollState())) { listOf("Pending", "In Progress", "Submitted", "Accepted").filter { it != d.optString("status") }.forEach { s ->
                TextButton(onClick = { deliverable = null; update(JSONObject().put("op", "set_deliverable").put("deliverable_id", d.optInt("id")).put("status", s), "${d.optString("title")} marked $s.") }) { Text("Mark it $s") } } } },
            confirmButton = { TextButton(onClick = { deliverable = null }) { Text("Cancel") } })
    }
    if (form.isNotEmpty()) key(dialogKey("contract-form", form)) {
        var a by rememberSaveable { mutableStateOf("") }
        var b by rememberSaveable { mutableStateOf(if (form == "spend") today() else "") }
        var t by rememberSaveable { mutableStateOf("") }
        // 4.29.0: spending and deliverables are added with a retry key, so a retry after a lost reply
        // adds them once; the dialog stays open, with what was typed, until the server took it.
        var addKey by rememberSaveable { mutableStateOf("") }
        var err by remember { mutableStateOf<String?>(null) }
        var fieldErrors by remember { mutableStateOf<Map<String, String>>(emptyMap()) }
        var busy by remember { mutableStateOf(false) }
        val day = Regex("^\\d{4}-\\d{2}-\\d{2}$")
        FormDialog(if (form == "spend") "Add spending" else "Add a deliverable", "Add", busy, onSave = {
            if (busy) return@FormDialog
            val body: JSONObject
            val done: String
            if (form == "spend") {
                val m = moneyText(a)
                fieldErrors = listOfNotNull(
                    if (m == null || m.toDouble() <= 0) "amount" to "Enter the amount in dollars, such as 120.50." else null,
                    if (!day.matches(b.trim())) "date" to "Enter the date as year-month-day, such as ${today()}." else null,
                ).toMap()
                if (fieldErrors.isNotEmpty() || m == null) { err = errorSummary(fieldErrors.values); return@FormDialog }
                body = JSONObject().put("op", "add_spend").put("amount", m).put("spent_on", b.trim()).put("memo", t.trim()); done = "Spending added."
            } else {
                fieldErrors = listOfNotNull(
                    if (t.isBlank()) "title" to "Give the deliverable a title." else null,
                    if (b.isNotBlank() && !day.matches(b.trim())) "date" to "Enter the due date as year-month-day, or leave it empty." else null,
                ).toMap()
                if (fieldErrors.isNotEmpty()) { err = errorSummary(fieldErrors.values); return@FormDialog }
                body = JSONObject().put("op", "add_deliverable").put("title", t.trim()).put("due_date", b.trim()); done = "Deliverable added."
            }
            scope.launchSave({ busy = it }, { err = it }) {
                try {
                    api.callOnce("contract_update", body.put("contract_id", id), addKey, { addKey = it }, if (form == "spend") "the spending" else "the deliverable")
                } catch (e: ApiException) {
                    // A refusal naming a field is shown on it.
                    val errs = e.reply?.optJSONObject("errors")
                    if (errs != null) fieldErrors = errs.keys().asSequence().associate { k -> (if (k == "spent_on" || k == "due_date") "date" else k) to errs.optString(k) }
                    throw e
                }
                form = ""; notice = done; error = null; state.reload()
            }
        }, onCancel = { form = "" }) {
            if (form == "spend") {
                Field("Amount in dollars", a, { a = it }, keyboard = KeyboardType.Decimal, error = fieldErrors["amount"], required = true)
                Field("Date", b, { b = it }, hint = "year-month-day", error = fieldErrors["date"], required = true)
                Field("What it was for", t, { t = it }, hint = "optional", error = fieldErrors["memo"])
            } else {
                Field("Title", t, { t = it }, error = fieldErrors["title"], required = true)
                Field("Due date", b, { b = it }, hint = "year-month-day, optional", error = fieldErrors["date"])
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
    var adding by rememberSaveable { mutableStateOf(false) }
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
            text = { Column(Modifier.verticalScroll(rememberScrollState())) { listOf("Requested", "Confirmed", "Completed", "Cancelled").filter { it != b.optString("status") }.forEach { s ->
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
    var client by rememberSaveable { mutableStateOf("") }
    var type by rememberSaveable { mutableStateOf(types.first()) }
    var date by rememberSaveable { mutableStateOf("") }
    var time by rememberSaveable { mutableStateOf("") }
    var minutes by rememberSaveable { mutableStateOf("60") }
    var provider by rememberSaveable { mutableStateOf("") }
    var location by rememberSaveable { mutableStateOf("") }
    var cost by rememberSaveable { mutableStateOf("") }
    var notes by rememberSaveable { mutableStateOf("") }
    var idem by rememberSaveable { mutableStateOf("") }
    var error by remember { mutableStateOf<String?>(null) }
    var fieldErrors by remember { mutableStateOf<Map<String, String>>(emptyMap()) }
    var busy by remember { mutableStateOf(false) }
    val scope = rememberCoroutineScope()
    FormDialog("Add a booking", "Add", busy, missing = stillNeeded(missingNames("Client" to client.isEmpty())), onSave = {
        val t = if (time.isBlank()) null else time24(time)
        val m = minutes.trim().toIntOrNull()
        val c = if (cost.isBlank()) "" else moneyText(cost)
        // Each problem on its own field, and all of them in the summary (4.30.0).
        fieldErrors = listOfNotNull(
            if (date.isNotBlank() && !Regex("^\\d{4}-\\d{2}-\\d{2}$").matches(date.trim())) "date" to "Enter the date as year-month-day, or leave it empty." else null,
            if (time.isNotBlank() && t == null) "time" to "Enter the time such as 2:30 PM." else null,
            if (m == null || m !in 0..1440) "minutes" to "Enter the length in whole minutes, 0 to 1440." else null,
            if (c == null) "cost" to "Enter the cost in dollars, such as 85.00, or leave it empty." else null,
        ).toMap()
        error = errorSummary(fieldErrors.values)
        if (error != null) return@FormDialog
        scope.launchSave({ busy = it }, { error = it }) {
            api.callOnce("add_booking", JSONObject().put("client_id", client.toInt()).put("service_type", type)
                .put("needed_at", if (date.isBlank()) "" else date.trim() + " " + (t ?: "09:00")).put("duration_min", m)
                .put("provider", provider.trim()).put("location", location.trim()).put("cost", c).put("notes", notes.trim()), idem, { idem = it }, "the booking")
            onDone("Booking added as Requested.")
        }
    }, onCancel = onCancel) {
        ErrorText(clients.error, takeFocus = false)
        Choice("Client", client, listOf("" to "Choose a client") + (clients.data ?: emptyList()).map { it.optInt("id").toString() to it.optString("name") }) { client = it }
        Choice("Service", type, types.map { it to it }) { type = it }
        Field("Date needed", date, { date = it }, hint = "year-month-day, optional", error = fieldErrors["date"])
        Field("Time", time, { time = it }, hint = "such as 2:30 PM, optional", error = fieldErrors["time"])
        Field("Length in minutes", minutes, { minutes = it }, keyboard = KeyboardType.Number, error = fieldErrors["minutes"], required = true)
        Field("Provider", provider, { provider = it }, hint = "optional")
        Field("Location", location, { location = it }, hint = "optional")
        Field("Cost in dollars", cost, { cost = it }, hint = "optional", keyboard = KeyboardType.Decimal, error = fieldErrors["cost"])
        Field("Notes", notes, { notes = it }, hint = "optional", singleLine = false)
        ErrorText(error)
    }
}
