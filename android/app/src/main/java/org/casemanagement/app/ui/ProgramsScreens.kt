package org.casemanagement.app.ui

import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
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

/**
 * "24.50", "$1,250" or "$ 2,995.00" as the server's money format ("1250.00"), or null. 4.34.0: as
 * the website, a comma only groups thousands (never a decimal point, so "12,50" is refused), and up
 * to [maxDigits] digits before the point, as the server's api_money: 7, or 10 for a contract's amounts.
 */
fun moneyText(s: String, maxDigits: Int = 7): String? {
    val t = s.trim().removePrefix("$").trim().replace(" ", "")
    if (!Regex("^(\\d{1,3}(,\\d{3})+|\\d+)(\\.\\d{1,2})?$").matches(t)) return null
    val plain = t.replace(",", "")
    if (plain.substringBefore('.').length > maxDigits) return null
    return java.math.BigDecimal(plain).setScale(2).toPlainString()
}

/** A contract's amount and its spending may run to 10 digits before the point (the server's api_money for contracts). */
const val CONTRACT_DIGITS = 10

/** 4.34.0: whole percent of a budget spent, rounded down and worked in cents (contracts.php), so an exact 29% stays 29. */
fun percentInCents(spent: Double, amount: Double): Int {
    val amountCents = Math.round(amount * 100)
    if (amountCents <= 0) return 0
    return ((Math.round(spent * 100) * 100) / amountCents).toInt()
}

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
    // 4.37.0 (S21): active or archived cohorts, and the website's New cohort fields.
    var show by rememberSaveable { mutableStateOf("active") }
    val state = rememberLoaded(api.userId, show) { api.call("cohorts", if (show == "active") emptyMap() else mapOf("show" to show)).optJSONArray("cohorts").objects() }
    val choices = rememberLoaded(api.userId) { try { api.call("cohort_choices") } catch (e: ApiException) { if (e.status == 401) throw e; null } }
    var adding by rememberSaveable { mutableStateOf(false) }
    var name by rememberSaveable { mutableStateOf("") }
    var school by rememberSaveable { mutableStateOf("") }
    var coordinator by rememberSaveable { mutableStateOf(api.userId.toString()) }
    var start by rememberSaveable { mutableStateOf("") }
    var end by rememberSaveable { mutableStateOf("") }
    var notes by rememberSaveable { mutableStateOf("") }
    var idem by rememberSaveable { mutableStateOf("") }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    var errors by remember { mutableStateOf<Map<String, String>>(emptyMap()) }
    val scope = rememberCoroutineScope()
    val coordinators = choices.data?.optJSONArray("coordinators")?.objects()
    Page {
        PrimaryButton("Add a cohort") { adding = true }
        // An older server lists active cohorts only.
        if (coordinators != null) Choice("Show", show, listOf("active" to "Active cohorts", "archived" to "Archived cohorts")) { show = it }
        Show(state, "cohorts") { list ->
            Heading((if (show == "archived") "Archived cohorts" else "Active cohorts") + " (${list.size})")
            if (list.isEmpty()) Text(if (show == "archived") "No archived cohorts." else "No active cohorts.")
            list.forEach { c -> ListRow(listOf(c.optString("name"), listOfNotNull(c.str("school"), c.str("coordinator")?.let { "coordinator $it" }, "${c.optInt("members")} students", "${c.optInt("sessions")} sessions").joinToString(", ")), action = "open it", onClick = { nav.navigate("cohort/${c.optInt("id")}") }) }
        }
    }
    if (adding) FormDialog("Add a cohort", "Create cohort", busy, missing = stillNeeded(missingNames("Cohort name" to name.isBlank())), onSave = {
        if (busy) return@FormDialog
        if (notes.trim().length > 2000) { errors = mapOf("notes" to "Notes: use 2,000 characters or fewer."); error = errors["notes"]; return@FormDialog }
        busy = true; error = null; errors = emptyMap()
        scope.launch {
            try {
                // 4.28.0: a retry after a lost reply adds it once (see sendOnce). An older server takes name and school only.
                val b = if (coordinators != null) cohortBody(name, school, coordinator.toIntOrNull() ?: 0, start, end, notes)
                    else JSONObject().put("name", name.trim()).put("school", school.trim())
                val r = api.callOnce("add_cohort", b, idem, { idem = it }, "the cohort")
                adding = false; name = ""; school = ""; start = ""; end = ""; notes = ""
                Flash.pending = r.optString("message").ifEmpty { "Cohort created." }
                nav.navigate("cohort/${r.optInt("id")}")
            } catch (e: ApiException) {
                errors = mapErrors(e.reply, ::cohortFieldFor).filterKeys { it.isNotEmpty() }
                error = errorSummary(errors.values) ?: e.message
            } finally { busy = false }
        }
    }, onCancel = { adding = false }) {
        Field("Cohort name", name, { name = it }, required = true, error = errors["name"])
        Field("School / district", school, { school = it }, hint = "optional", error = errors["school"])
        if (coordinators != null) {
            Choice("Coordinator", coordinator, listOf("0" to "None") + coordinators.map { it.optInt("id").toString() to it.optString("name") }, error = errors["coordinator_id"]) { coordinator = it }
            Field("Start date", start, { start = it }, hint = "year-month-day; optional", error = errors["start_date"])
            Field("End date", end, { end = it }, hint = "year-month-day; optional", error = errors["end_date"])
            Field("Notes", notes, { notes = it }, hint = "optional; up to 2,000 characters", singleLine = false, error = errors["notes"])
        }
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
    var deletingSession by rememberSaveable { androidx.compose.runtime.mutableIntStateOf(0) }
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
            // 4.37.0 (S21): the coordinator, dates and notes, and archive or reactivate (no confirmation, as the website).
            (c.str("coordinator_name") ?: r.str("coordinator_name"))?.let { ListRow(listOf("Coordinator: $it")) }
            listOfNotNull(c.str("start_date")?.let { "Starts " + shortDate(it) }, c.str("end_date")?.let { "ends " + shortDate(it) }).joinToString(", ").ifEmpty { null }?.let { ListRow(listOf(it)) }
            c.str("notes")?.let { ListRow(listOf("Notes: $it")) }
            val activeFlag = if (c.has("active")) flag(c, "active", true) else null
            if (activeFlag != null) SecondaryButton(if (activeFlag) "Archive cohort" else "Reactivate cohort") {
                run { api.call("set_cohort_active", body = JSONObject().put("cohort_id", id).put("active", !activeFlag)).optString("message").ifEmpty { if (activeFlag) "Cohort archived." else "Cohort reactivated." } }
            }
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
            if (sessionsTotal > sessions.size) Text(if (sessions.any { it.has("can_delete") }) SESSIONS_CUT else "The older sessions are on the website's cohort page.")
            sessions.forEach { s ->
                ListRow(s.str("text")?.let { listOf(it) } ?: listOf(s.optString("activity") + ", " + dayText(s.str("session_date")), "${s.optInt("duration_min")} minutes, ${s.optInt("present_n")} present" + (s.str("topic")?.let { ", $it" } ?: "")))
                // 4.37.0 (S21): its own visible button, outside the row.
                if (flag(s, "can_delete")) SecondaryButton(deleteSessionLabel(s.optString("activity"), shortDate(s.str("session_date")))) { deletingSession = s.optInt("id") }
            }
        }
        if (logging) CohortSessionDialog(api, id, members, onDone = { m -> logging = false; notice = m; state.reload() }, onCancel = { logging = false })
    }
    state.data?.optJSONArray("sessions")?.objects()?.firstOrNull { deletingSession > 0 && it.optInt("id") == deletingSession }?.let { sx ->
        val act = sx.optString("activity"); val on = shortDate(sx.str("session_date"))
        Confirm(deleteSessionTitle(act, on), DELETE_SESSION_TEXT, "Delete session", {
            run { api.call("delete_cohort_session", body = JSONObject().put("cohort_id", id).put("session_id", sx.optInt("id"))).optString("message").ifEmpty { "Session deleted: $act, $on." } }
        }, { deletingSession = 0 }, no = "Keep it")
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
                    val r = api.callOnce("log_cohort_session", JSONObject().put("cohort_id", cohortId).put("activity", activity).put("session_date", date.trim())
                        .put("duration_min", minutes.trim().toInt()).put("topic", topic.trim()).put("notes", notes.trim()).put("present", org.json.JSONArray(present.sorted())),
                        idem, { idem = it }, "the group session")
                    // 4.37.0: the website's sentence ("If anything is wrong, delete it under Recent sessions…").
                    onDone(r.optString("message").ifEmpty { "Session logged." })
                }
            }, { confirming = false })
    }
}

/* ---------------- Grants and contracts ---------------- */

const val CONTRACT_BUDGET_ERROR = "Budget: Enter a dollar amount of 0 or more, for example 50000 or 50,000.00, with a period before the cents."
const val CONTRACT_SPEND_ERROR = "Amount: enter a dollar amount above 0, for example 1250 or 1,250.00, with a period before the cents."
const val CONTRACT_NOTES_LONG = "Notes must be 2,000 characters or fewer."
const val BOOKING_COST_ERROR = "Enter the estimated cost in dollars and cents, for example 125.50 (no more than 99,999,999.99), or leave it blank."

@Composable
fun ContractsScreen(api: Api, nav: NavHostController) {
    // 4.38.0 (T11): active or closed contracts, as the website's Show closed / Show active.
    var show by rememberSaveable { mutableStateOf("active") }
    val state = rememberLoaded(api.userId, show) { api.call("contracts", if (show == "closed") mapOf("show" to "closed") else emptyMap()).optJSONArray("contracts").objects() }
    var adding by rememberSaveable { mutableStateOf(false) }
    Page {
        PrimaryButton("Add a contract") { adding = true }
        Choice("Show", show, contractShowChoices()) { show = it }
        Show(state, "contracts") { all ->
            // An older server lists active ones only, whatever is asked.
            val list = if (show == "closed") all.filter { it.optString("status", "Closed") != "Active" } else all
            Heading((if (show == "closed") "Closed contracts" else "Active contracts") + " (${list.size})")
            if (list.isEmpty()) Text(if (show == "closed") "No closed contracts." else "No active contracts.")
            list.forEach { c -> ListRow(contractRowLines(c), action = "open it", onClick = { nav.navigate("contract/${c.optInt("id")}") }) }
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
    var notes by rememberSaveable { mutableStateOf("") }
    var idem by rememberSaveable { mutableStateOf("") }
    var error by remember { mutableStateOf<String?>(null) }
    var fieldErrors by remember { mutableStateOf<Map<String, String>>(emptyMap()) }
    var busy by remember { mutableStateOf(false) }
    val scope = rememberCoroutineScope()
    FormDialog("Add a contract", "Add", busy, missing = stillNeeded(missingNames("Name" to name.isBlank())), onSave = {
        if (busy) return@FormDialog
        val a = if (amount.isBlank()) "0" else moneyText(amount, CONTRACT_DIGITS)
        fieldErrors = listOfNotNull(
            if (a == null) "amount" to CONTRACT_BUDGET_ERROR else null,
            if (notes.trim().length > 2000) "notes" to CONTRACT_NOTES_LONG else null,
        ).toMap()
        error = errorSummary(fieldErrors.values)
        if (error != null || a == null) return@FormDialog
        scope.launchSave({ busy = it }, { error = it }) {
            try {
                onDone(api.callOnce("add_contract", JSONObject().put("name", name.trim()).put("funder", funder.trim()).put("contract_number", number.trim())
                    .put("amount", a).put("start_date", start.trim()).put("end_date", end.trim()).put("notes", notes.trim()), idem, { idem = it }, "the contract").optInt("id"))
            } catch (e: ApiException) {
                fieldErrors = replyErrors(e.reply).mapKeys { (k, _) -> contractFieldFor(k) }
                throw e
            }
        }
    }, onCancel = onCancel) {
        Field("Name", name, { name = it }, required = true, error = fieldErrors["name"])
        Field("Funder", funder, { funder = it }, hint = "optional", error = fieldErrors["funder"])
        Field("Contract number", number, { number = it }, hint = "optional", error = fieldErrors["contract_number"])
        Field("Budget in dollars", amount, { amount = it }, hint = "for example 50000 or 50,000.00", keyboard = KeyboardType.Decimal, error = fieldErrors["amount"])
        Field("Start date", start, { start = it }, hint = "year-month-day, optional", error = fieldErrors["start"])
        Field("End date", end, { end = it }, hint = "year-month-day, optional", error = fieldErrors["end"])
        Field("Notes", notes, { notes = it }, hint = "optional; up to 2,000 characters", singleLine = false, error = fieldErrors["notes"])
        ErrorText(error)
    }
}

@Composable
fun ContractScreen(api: Api, id: Int) {
    val state = rememberLoaded(id) { api.call("contract", mapOf("id" to id.toString())) }
    var notice by remember { mutableStateOf<String?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    // 4.38.0: what is open (a deliverable's choices, a removal asked about), by id, kept across rotation.
    var deliverableId by rememberSaveable { mutableIntStateOf(0) }
    var removeDeliverable by rememberSaveable { mutableIntStateOf(0) }
    var removeSpend by rememberSaveable { mutableIntStateOf(0) }
    var form by rememberSaveable { mutableStateOf("") }
    // Each change goes once, even when a reply was lost (contract_update keeps its first reply).
    var opKey by rememberSaveable { mutableStateOf("") }
    var busy by remember { mutableStateOf(false) }
    var presses by remember { androidx.compose.runtime.mutableIntStateOf(0) }
    val scope = rememberCoroutineScope()
    fun update(body: JSONObject, done: String, what: String) {
        if (busy) return
        presses++
        scope.launchSave({ busy = it }, { error = it; if (it != null) notice = null }) {
            val r = api.callOnce("contract_update", body.put("contract_id", id), opKey, { opKey = it }, what)
            error = null; notice = r.optString("message").ifEmpty { done }; state.reload()
        }
    }
    Show(state, "the contract") { r ->
        val c = r.optJSONObject("contract") ?: JSONObject()
        val active = c.optString("status", "Active") == "Active"
        Page {
            Heading(c.optString("name"), large = true)
            NoticeText(notice)
            ErrorText(error, focusKey = presses)
            contractDetailLines(r).forEach { ListRow(listOf(it)) }
            SecondaryButton("Change budget") { form = "budget" }
            // No confirmation, as the website's.
            if (c.has("status")) SecondaryButton(if (active) "Close contract" else "Reopen contract", enabled = !busy) {
                update(contractOpBody("set_active", id, !active), if (active) "Contract closed." else "Contract reopened.", if (active) "closing the contract" else "reopening the contract")
            }
            val dels = r.optJSONArray("deliverables").objects()
            Heading("Deliverables (${dels.size})")
            dels.forEach { d -> ListRow(deliverableLines(d), action = "change its status or remove it", onClick = { deliverableId = d.optInt("id") }) }
            SecondaryButton("Add a deliverable") { form = "deliverable" }
            val spend = r.optJSONArray("spend").objects()
            Heading("Spending")
            if (r.optInt("spend_total") > spend.size) Text("Showing the latest ${spend.size} of ${r.optInt("spend_total")} entries. The website lists them all.")
            spend.forEach { s -> ListRow(listOf(spendLine(s)), action = "remove it", onClick = { removeSpend = s.optInt("id") }) }
            SecondaryButton("Add spending") { form = "spend" }
        }
    }
    val r = state.data
    val dels = r?.optJSONArray("deliverables").objects()
    val spends = r?.optJSONArray("spend").objects()
    dels.firstOrNull { deliverableId != 0 && it.optInt("id") == deliverableId }?.let { d ->
        AlertDialog(onDismissRequest = { deliverableId = 0 }, title = { Text(d.optString("title"), modifier = Modifier.semantics { heading() }) },
            text = { Column(Modifier.verticalScroll(rememberScrollState())) {
                deliverableStatusChoices(d.optString("status")).forEach { s ->
                    TextButton(onClick = { deliverableId = 0; update(JSONObject().put("op", "set_deliverable").put("deliverable_id", d.optInt("id")).put("status", s), "Deliverable updated.", "the deliverable's status") }) { Text("Mark it $s") }
                }
                TextButton(onClick = { deliverableId = 0; removeDeliverable = d.optInt("id") }) { Text("Remove") }
            } },
            confirmButton = { TextButton(onClick = { deliverableId = 0 }) { Text("Cancel") } })
    }
    dels.firstOrNull { removeDeliverable != 0 && it.optInt("id") == removeDeliverable }?.let { d ->
        Confirm(removeConfirmTitle("deliverable", d.optString("title"), null, null), "", "Remove",
            { update(contractOpBody("delete_deliverable", id, d.optInt("id")), "Deliverable removed.", "removing the deliverable") }, { removeDeliverable = 0 }, no = "Keep it")
    }
    spends.firstOrNull { removeSpend != 0 && it.optInt("id") == removeSpend }?.let { s ->
        Confirm(removeConfirmTitle("spend", null, s.optDouble("amount", 0.0), s.str("spent_on")), "", "Remove",
            { update(contractOpBody("delete_spend", id, s.optInt("id")), "Spend entry removed.", "removing the spend entry") }, { removeSpend = 0 }, no = "Keep it")
    }
    if (form.isNotEmpty()) key(dialogKey("contract-form", form)) {
        var a by rememberSaveable { mutableStateOf("") }
        var b by rememberSaveable { mutableStateOf(if (form == "spend") today() else "") }
        var t by rememberSaveable { mutableStateOf("") }
        var n by rememberSaveable { mutableStateOf("") }
        // 4.29.0: spending and deliverables are added with a retry key, so a retry after a lost reply
        // adds them once; the dialog stays open, with what was typed, until the server took it.
        var addKey by rememberSaveable { mutableStateOf("") }
        var err by remember { mutableStateOf<String?>(null) }
        var fieldErrors by remember { mutableStateOf<Map<String, String>>(emptyMap()) }
        var formBusy by remember { mutableStateOf(false) }
        val day = Regex("^\\d{4}-\\d{2}-\\d{2}$")
        val title = when (form) { "spend" -> "Add spending"; "budget" -> "Change budget"; else -> "Add a deliverable" }
        FormDialog(title, if (form == "budget") "Save budget" else "Add", formBusy, onSave = {
            if (formBusy) return@FormDialog
            val body: JSONObject
            val done: String
            when (form) {
                "spend" -> {
                    val m = moneyText(a, CONTRACT_DIGITS)
                    fieldErrors = listOfNotNull(
                        if (m == null || m.toDouble() <= 0) "amount" to CONTRACT_SPEND_ERROR else null,
                        if (!day.matches(b.trim())) "date" to "Enter the date as year-month-day, such as ${today()}." else null,
                    ).toMap()
                    if (fieldErrors.isNotEmpty() || m == null) { err = errorSummary(fieldErrors.values); return@FormDialog }
                    body = JSONObject().put("op", "add_spend").put("amount", m).put("spent_on", b.trim()).put("memo", t.trim()); done = "Spending added."
                }
                "budget" -> {
                    val m = moneyText(a, CONTRACT_DIGITS)
                    fieldErrors = if (m == null) mapOf("amount" to CONTRACT_BUDGET_ERROR) else emptyMap()
                    if (m == null) { err = errorSummary(fieldErrors.values); return@FormDialog }
                    body = contractOpBody("set_budget", id, m); done = "Budget changed to ${money(m.toDouble())}."
                }
                else -> {
                    fieldErrors = listOfNotNull(
                        if (t.isBlank()) "title" to "Give the deliverable a title." else null,
                        if (b.isNotBlank() && !day.matches(b.trim())) "date" to "Enter the due date as year-month-day, or leave it empty." else null,
                        if (n.trim().length > 500) "notes" to "Notes must be 500 characters or fewer." else null,
                    ).toMap()
                    if (fieldErrors.isNotEmpty()) { err = errorSummary(fieldErrors.values); return@FormDialog }
                    body = JSONObject().put("op", "add_deliverable").put("title", t.trim()).put("due_date", b.trim()).put("notes", n.trim()); done = "Deliverable added."
                }
            }
            scope.launchSave({ formBusy = it }, { err = it }) {
                val reply = try {
                    api.callOnce("contract_update", body.put("contract_id", id), addKey, { addKey = it }, when (form) { "spend" -> "the spending"; "budget" -> "the budget"; else -> "the deliverable" })
                } catch (e: ApiException) {
                    // A refusal naming a field is shown on it.
                    replyErrors(e.reply).takeIf { it.isNotEmpty() }?.let { m -> fieldErrors = m.mapKeys { (k, _) -> contractFieldFor(k) } }
                    throw e
                }
                form = ""; notice = reply.optString("message").ifEmpty { done }; error = null; state.reload()
            }
        }, onCancel = { form = "" }) {
            when (form) {
                "spend" -> {
                    Field("Amount in dollars", a, { a = it }, hint = "for example 1250 or 1,250.00", keyboard = KeyboardType.Decimal, error = fieldErrors["amount"], required = true)
                    Field("Date", b, { b = it }, hint = "year-month-day", error = fieldErrors["date"], required = true)
                    Field("What it was for", t, { t = it }, hint = "optional", error = fieldErrors["memo"])
                }
                "budget" -> {
                    Text("The budget is now " + money(state.data?.optJSONObject("contract")?.optDouble("amount", 0.0) ?: 0.0) + ".")
                    Field("Budget in dollars", a, { a = it }, hint = "for example 50000 or 50,000.00", keyboard = KeyboardType.Decimal, error = fieldErrors["amount"], required = true)
                }
                else -> {
                    Field("Title", t, { t = it }, error = fieldErrors["title"], required = true)
                    Field("Due date", b, { b = it }, hint = "year-month-day, optional", error = fieldErrors["date"])
                    Field("Notes", n, { n = it }, hint = "optional; up to 500 characters", singleLine = false, error = fieldErrors["notes"])
                }
            }
            ErrorText(err)
        }
    }
}

/* ---------------- Interpreter and transport bookings ---------------- */

@Composable
fun BookingsScreen(api: Api) {
    // 4.38.0 (T12): upcoming, including past, or the cancelled list (where a booking can be restored).
    var view by rememberSaveable { mutableStateOf("upcoming") }
    val state = rememberLoaded(api.userId, view) {
        when (view) {
            "cancelled" -> listOf("Cancelled bookings" to api.call("bookings", bookingsQuery("cancelled")).optJSONArray("bookings").objects().filter { it.optString("status") == "Cancelled" })
            "past" -> {
                val up = api.call("bookings", bookingsQuery("upcoming")).optJSONArray("bookings").objects()
                val upIds = up.map { it.optInt("id") }.toSet()
                listOf("Upcoming" to up, "Past" to api.call("bookings", bookingsQuery("past")).optJSONArray("bookings").objects().filter { it.optInt("id") !in upIds })
                    // An empty Past section is not shown, as the iPhone's.
                    .filter { it.first != "Past" || it.second.isNotEmpty() }
            }
            else -> listOf("Upcoming" to api.call("bookings", bookingsQuery("upcoming")).optJSONArray("bookings").objects())
        }
    }
    var chosenId by rememberSaveable { mutableIntStateOf(0) }
    var cancellingId by rememberSaveable { mutableIntStateOf(0) }
    var adding by rememberSaveable { mutableStateOf(false) }
    var busy by remember { mutableStateOf(false) }
    var notice by remember { mutableStateOf<String?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    var presses by remember { androidx.compose.runtime.mutableIntStateOf(0) }
    val scope = rememberCoroutineScope()
    fun setStatus(b: JSONObject, to: String) {
        if (busy) return
        presses++
        scope.launchSave({ busy = it }, { error = it; if (it != null) notice = null }) {
            val r = api.call("booking_status", body = JSONObject().put("booking_id", b.optInt("id")).put("to", to))
            error = null
            notice = r.optString("message").ifEmpty { if (to == "Cancelled") "Booking cancelled." else "Booking marked $to." }
            state.reload()
        }
    }
    Page {
        NoticeText(notice)
        ErrorText(error, focusKey = presses)
        PrimaryButton("Add a booking") { adding = true }
        Choice("Show", view, bookingShowChoices()) { view = it; error = null }
        Show(state, "bookings") { sections ->
            sections.forEach { (title, list) ->
                Heading("$title (${list.size})")
                if (list.isEmpty()) Text(when (title) { "Upcoming" -> "No upcoming bookings."; "Past" -> "No past bookings."; else -> "No cancelled bookings." })
                list.forEach { b -> ListRow(bookingLines(b), action = if (b.optString("status") == "Cancelled") "restore it" else "change its status", onClick = { chosenId = b.optInt("id") }) }
            }
        }
    }
    val all = state.data?.flatMap { it.second } ?: emptyList()
    all.firstOrNull { chosenId != 0 && it.optInt("id") == chosenId }?.let { b ->
        AlertDialog(onDismissRequest = { chosenId = 0 }, title = { Text(bookingLabel(b) + " for " + b.optString("client_name"), modifier = Modifier.semantics { heading() }) },
            text = { Column(Modifier.verticalScroll(rememberScrollState())) { restoreChoices(b.optString("status")).forEach { s ->
                TextButton(onClick = { chosenId = 0; if (s == "Cancelled") cancellingId = b.optInt("id") else setStatus(b, s) }) { Text(if (s == "Cancelled") "Cancel the booking" else "Mark it $s") } } } },
            confirmButton = { TextButton(onClick = { chosenId = 0 }) { Text("Close") } })
    }
    all.firstOrNull { cancellingId != 0 && it.optInt("id") == cancellingId }?.let { b ->
        val (title, text) = bookingCancelConfirm(bookingLabel(b), b.optString("client_name"))
        Confirm(title, text, "Cancel booking", { setStatus(b, "Cancelled") }, { cancellingId = 0 }, no = "Keep booking")
    }
    if (adding) AddBookingDialog(api, onDone = { m -> adding = false; error = null; notice = m; state.reload() }, onCancel = { adding = false })
}

@Composable
private fun AddBookingDialog(api: Api, onDone: (String) -> Unit, onCancel: () -> Unit) {
    val clients = rememberLoaded(api.userId) { clientChoices(api) }
    // 4.38.0 (T12): the website's labels ("CART (real-time captioning)"); the name is what is sent.
    val labels = rememberLoaded(api.userId) { try { api.call("form_options").optJSONObject("booking_type_labels") } catch (e: ApiException) { if (e.status == 401) throw e; null } }
    val types = api.formList("booking_types", listOf("Interpreter", "CART captioning", "Transportation", "Note-taker", "Other"))
    var client by rememberSaveable { mutableStateOf("") }
    var type by rememberSaveable { mutableStateOf(types.first()) }
    // 4.38.0 (T12): as the iPhone's: off sends no date, so the booking is listed as "no date set".
    var dated by rememberSaveable { mutableStateOf(true) }
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
        if (busy) return@FormDialog
        val t = if (time.isBlank()) null else time24(time)
        val m = minutes.trim().toIntOrNull()
        // Up to 8 digits, with "$" and commas, as the website's (booking_cost_in).
        val c = if (cost.isBlank()) "" else moneyText(cost, 8)
        // Each problem on its own field, and all of them in the summary (4.30.0).
        fieldErrors = listOfNotNull(
            if (dated && date.isNotBlank() && !Regex("^\\d{4}-\\d{2}-\\d{2}$").matches(date.trim())) "date" to "Enter the date as year-month-day, or leave it empty." else null,
            if (dated && time.isNotBlank() && t == null) "time" to "Enter the time such as 2:30 PM." else null,
            if (m == null || m !in 0..1440) "minutes" to "Enter the length in whole minutes, 0 to 1440." else null,
            if (c == null) "cost" to BOOKING_COST_ERROR else null,
        ).toMap()
        error = errorSummary(fieldErrors.values)
        if (error != null) return@FormDialog
        scope.launchSave({ busy = it }, { error = it }) {
            val r = try {
                val body = JSONObject().put("client_id", client.toInt()).put("service_type", type).put("duration_min", m)
                    .put("provider", provider.trim()).put("location", location.trim()).put("cost", c).put("notes", notes.trim())
                if (dated && date.isNotBlank()) body.put("needed_at", date.trim() + " " + (t ?: "09:00"))
                api.callOnce("add_booking", body, idem, { idem = it }, "the booking")
            } catch (e: ApiException) {
                replyErrors(e.reply).takeIf { it.isNotEmpty() }?.let { f -> fieldErrors = f.mapKeys { (k, _) -> when (k) { "needed_at" -> "date"; "duration_min" -> "minutes"; "client_id" -> "client"; else -> k } } }
                throw e
            }
            onDone(r.optString("message").ifEmpty { "Booking added as Requested." })
        }
    }, onCancel = onCancel) {
        ErrorText(clients.error, takeFocus = false)
        Choice("Client", client, listOf("" to "Choose a client") + (clients.data ?: emptyList()).map { it.optInt("id").toString() to it.optString("name") }, error = fieldErrors["client"], required = true) { client = it }
        Choice("Service", type, bookingTypeChoices(types, labels.data)) { type = it }
        Tick("Date and time known", dated) { dated = it }
        if (dated) {
            Field("Date needed", date, { date = it }, hint = "year-month-day, optional", error = fieldErrors["date"])
            Field("Time", time, { time = it }, hint = "such as 2:30 PM, optional", error = fieldErrors["time"])
        }
        Field("Length in minutes", minutes, { minutes = it }, keyboard = KeyboardType.Number, error = fieldErrors["minutes"], required = true)
        Field("Provider", provider, { provider = it }, hint = "optional")
        Field("Location", location, { location = it }, hint = "optional")
        Field("Estimated cost in dollars", cost, { cost = it }, hint = "optional; up to 99,999,999.99", keyboard = KeyboardType.Decimal, error = fieldErrors["cost"])
        Field("Notes", notes, { notes = it }, hint = "optional", singleLine = false)
        ErrorText(error)
    }
}
