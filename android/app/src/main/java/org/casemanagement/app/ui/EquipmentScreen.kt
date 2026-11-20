package org.casemanagement.app.ui

import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
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
import kotlinx.coroutines.launch
import org.casemanagement.app.Api
import org.casemanagement.app.ApiException
import org.casemanagement.app.EquipmentItem
import org.casemanagement.app.distinctItems
import org.casemanagement.app.intervalDays
import org.casemanagement.app.openClientChoices
import org.casemanagement.app.parseEquipment
import org.casemanagement.app.parseEquipmentDetail
import org.casemanagement.app.parseReservations
import org.casemanagement.app.reservationWords
import androidx.compose.ui.text.input.KeyboardType
import androidx.navigation.NavHostController
import org.casemanagement.app.objects
import org.casemanagement.app.str
import org.json.JSONObject
import androidx.compose.runtime.key
import org.casemanagement.app.dialogKey
import org.casemanagement.app.countLabel

/**
 * The equipment library: what is on loan (overdue first) and what is available; lend, return,
 * open an item's service details (4.26.0), add an item, and the reservations and waitlist.
 */
@Composable
fun EquipmentScreen(api: Api, nav: NavHostController) {
    var q by rememberSaveable { mutableStateOf("") }
    val state = rememberLoaded(api.userId) { parseEquipment(api.call("equipment")) }
    var chosen by remember { mutableStateOf<EquipmentItem?>(null) }
    // The item being lent, by its number, so rotation and process death keep the form open (4.28.0).
    var lendingId by rememberSaveable { mutableStateOf<Int?>(null) }
    val lending = lendingId?.let { id -> state.data?.firstOrNull { it.id == id } }
    var returning by remember { mutableStateOf<EquipmentItem?>(null) }
    var adding by rememberSaveable { mutableStateOf(false) }
    var notice by remember { mutableStateOf<String?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    val scope = rememberCoroutineScope()
    Show(state, "equipment") { all ->
        val list = all.filter { e -> q.isBlank() || listOfNotNull(e.name, e.category, e.serial, e.borrower).any { it.contains(q.trim(), ignoreCase = true) } }
        Page {
            NoticeText(notice)
            ErrorText(error)
            SecondaryButton("Add equipment") { adding = true }
            SecondaryButton("Reservations and waitlist") { nav.navigate("reservations") }
            Field("Find equipment", q, { q = it }, hint = "name, category, serial or borrower")
            if (all.isEmpty()) Text("No equipment tracked yet. Use Add equipment to add an item.")
            else if (list.isEmpty()) Text("No equipment matches “${q.trim()}”.")
            val onLoan = list.filter { it.status == "On Loan" }.sortedByDescending { it.overdue }
            val available = list.filter { it.status == "Available" }
            val other = list - onLoan.toSet() - available.toSet()
            Heading("On loan (${onLoan.size})")
            onLoan.forEach { e ->
                ListRow(listOf(e.name, listOfNotNull(e.borrower, e.dueDate?.let { (if (e.overdue) "overdue since " else "due ") + dayText(it) }).joinToString(", ")),
                    action = "see what you can do", onClick = { chosen = e })
            }
            Heading("Available (${available.size})")
            available.forEach { e ->
                ListRow(listOf(e.name, listOfNotNull(e.category, e.condition, if (e.held) "reserved" else null).joinToString(", ")),
                    action = "see what you can do", onClick = { chosen = e })
            }
            if (other.isNotEmpty()) {
                Heading("Other (${other.size})")
                other.forEach { e -> ListRow(listOf(e.name, e.status), action = "see what you can do", onClick = { chosen = e }) }
            }
        }
    }
    chosen?.let { e ->
        AlertDialog(
            onDismissRequest = { chosen = null },
            title = { Text(e.name, modifier = Modifier.semantics { heading() }) },
            text = {
                // Scrolls (4.29.0), so the last button is reachable at a large text size.
                Column(Modifier.verticalScroll(rememberScrollState())) {
                    Text(when (e.status) { "On Loan" -> e.borrower?.let { "On loan to $it." } ?: "On loan."; else -> e.status + "." })
                    if (e.status == "Available") TextButton(onClick = { chosen = null; lendingId = e.id }) { Text("Lend it") }
                    if (e.status == "On Loan") {
                        if (e.canReturn) TextButton(onClick = { chosen = null; returning = e }) { Text("Mark it returned") }
                        else Text("This loan is not on your caseload.")
                    }
                    TextButton(onClick = { chosen = null; nav.navigate("equipmentItem/${e.id}") }) { Text("Service and details") }
                }
            },
            confirmButton = { TextButton(onClick = { chosen = null }) { Text("Close") } },
        )
    }
    returning?.let { e ->
        // 4.34.0: the condition it came back in, from the organisation's list (Unchanged first), as on the website.
        var condition by remember(e.id) { mutableStateOf("") }
        val conditions = api.formList("equipment_conditions", listOf("New", "Good", "Fair", "Needs Repair"))
        AlertDialog(
            onDismissRequest = { returning = null },
            title = { Text("Return " + e.name, modifier = Modifier.semantics { heading() }) },
            text = {
                Column(Modifier.verticalScroll(rememberScrollState())) {
                    Text(e.borrower?.let { "On loan to $it." } ?: "On loan.")
                    Choice("Condition when returned", condition, returnConditionOptions(conditions)) { condition = it }
                }
            },
            confirmButton = { TextButton(onClick = {
                returning = null; error = null; notice = null
                scope.launch {
                    try { api.call("return_equipment", body = JSONObject().put("loan_id", e.loanId ?: 0).put("condition_in", condition)); notice = e.name + " marked returned."; state.reload() }
                    catch (x: ApiException) { error = x.message }
                }
            }) { Text("Mark ${e.name} returned") } },
            dismissButton = { TextButton(onClick = { returning = null }) { Text("Keep on loan") } },
        )
    }
    lending?.let { e -> key(dialogKey("lend", e.id)) { LendDialog(api, e, onDone = { m -> lendingId = null; notice = m; state.reload() }, onCancel = { lendingId = null }) } }
    if (adding) AddEquipmentDialog(api, onDone = { m -> adding = false; notice = m; state.reload() }, onCancel = { adding = false })
}

/** 4.34.0: the conditions an item can come back in, "Unchanged" ("") first. */
fun returnConditionOptions(conditions: List<String>): List<Pair<String, String>> = listOf("" to "Unchanged") + conditions.map { it to it }

/** 4.34.0: the conditions a loan can go out in, starting at "Not recorded" (""), as on the website. */
fun loanConditionOptions(conditions: List<String>): List<Pair<String, String>> = listOf("" to "Not recorded") + conditions.map { it to it }

/** 4.34.0: the return date a loan starts with: today plus the administrator's loan period. */
fun defaultReturnDate(todayIs: String, days: Int): String =
    try { java.time.LocalDate.parse(todayIs).plusDays(days.toLong()).toString() } catch (e: Exception) { "" }

/** 4.34.0: what cancelling a reservation did, saying when the item went to the next client waiting. */
fun reservationCancelledWords(r: JSONObject?): String =
    if (r?.optBoolean("promoted") == true) "Reservation cancelled. The item is now held for the client who has waited longest on its waitlist." else "Reservation cancelled."

/** 4.34.0: the follow-up's answers (Unknown too); nothing is chosen to start with. */
val FOLLOWUP_ANSWERS = listOf("yes" to "Yes", "no" to "No", "unknown" to "Unknown")

/** 4.34.0: what recording a follow-up did: the server's warning, or what a "No" created. */
fun followupRecordedWords(reply: JSONObject?, fallback: String): String {
    if (reply == null) return fallback
    reply.str("warning")?.let { return it }
    if (reply.optBoolean("abandoned")) return "Recorded. Because the device is no longer in use, a case note and a follow-up task were created."
    return fallback
}

private const val INTERVAL_MESSAGE = "Service interval: enter a whole number of days from 0 to 3650, like 7, 14 or 365 (0 means no service schedule)."

/** One item's details (4.26.0): value, its service schedule, the service log; log a service or reserve it. */
@Composable
fun EquipmentDetailScreen(api: Api, id: Int) {
    val state = rememberLoaded(id) { parseEquipmentDetail(api.call("equipment_detail", mapOf("id" to id.toString()))) ?: throw ApiException("The item could not be read.") }
    var interval by rememberSaveable { mutableStateOf<String?>(null) }
    var intervalNote by remember { mutableStateOf<String?>(null) }
    var intervalError by remember { mutableStateOf<String?>(null) }
    var savingInterval by remember { mutableStateOf(false) }
    var logging by rememberSaveable { mutableStateOf(false) }
    var reserving by rememberSaveable { mutableStateOf(false) }
    var notice by remember { mutableStateOf<String?>(null) }
    val scope = rememberCoroutineScope()
    Show(state, "the item") { d ->
        val shown = interval ?: (d.intervalDays ?: 0).toString()
        Page {
            Heading(d.name, large = true)
            NoticeText(notice)
            ListRow(listOf("Status: " + d.status))
            ListRow(listOf("Condition: " + d.condition))
            d.category?.let { ListRow(listOf("Category: $it")) }
            d.serial?.let { ListRow(listOf("Serial: $it")) }
            ListRow(listOf("Last service: " + (d.lastServiceDate?.let { dayText(it) } ?: "none logged")))
            d.notes?.let { ListRow(listOf("Notes: $it")) }
            if (d.value != null || d.bookValue != null) {
                Heading("Asset value")
                d.value?.let { ListRow(listOf("Purchase value: " + money(it))) }
                d.purchaseDate?.let { ListRow(listOf("Purchased " + dayText(it))) }
                d.usefulLifeMonths?.let { ListRow(listOf("Useful life: " + if (it <= 0) "non-depreciating" else "$it months")) }
                d.bookValue?.let { ListRow(listOf("Current book value: " + money(it))) }
            }
            Heading("Maintenance schedule")
            Field("Service every so many days", shown, { interval = it; intervalNote = null; intervalError = null }, hint = "0 for no schedule", keyboard = KeyboardType.Number, error = intervalError)
            PrimaryButton("Save the interval", busy = savingInterval) {
                val days = intervalDays(shown)
                if (days == null) { intervalNote = null; intervalError = INTERVAL_MESSAGE; return@PrimaryButton }
                scope.launchSave({ savingInterval = it }, { intervalError = it?.let { m -> "Service interval not saved. $m" } }) {
                    api.call("set_equipment_interval", body = JSONObject().put("equipment_id", id).put("interval", days))
                    interval = days.toString()
                    intervalNote = if (days == 0) "Service schedule turned off." else "Service interval saved: every $days day${if (days == 1) "" else "s"}."
                    state.reload()
                }
            }
            NoticeText(intervalNote)
            SecondaryButton("Log a service") { logging = true }
            if (d.status != "Retired") SecondaryButton("Reserve for a client") { reserving = true }
            Heading(countLabel("Service history", d.maintenance.size, d.maintenanceTotal))
            if (d.maintenance.isEmpty()) Text("No service logged.")
            if (d.maintenanceTotal > d.maintenance.size) Text("The older entries are on the website's equipment page.")
            d.maintenance.forEach { m ->
                ListRow(listOfNotNull(m.serviceType + ", " + dayText(m.serviceDate), m.cost?.let { "Cost " + money(it) }, m.byName?.let { "By $it" }, m.notes))
            }
        }
    }
    if (logging) LogServiceDialog(api, id, onDone = { m -> logging = false; notice = m; state.reload() }, onCancel = { logging = false })
    if (reserving) ReservationDialog(api, fixedItem = id, onDone = { m -> reserving = false; notice = m; state.reload() }, onCancel = { reserving = false })
}

@Composable
private fun LogServiceDialog(api: Api, id: Int, onDone: (String) -> Unit, onCancel: () -> Unit) {
    val types = api.formList("maintenance_types", listOf("Maintenance", "Calibration", "Repair", "Inspection", "Cleaning", "Other"))
    var type by rememberSaveable { mutableStateOf(types.first()) }
    var date by rememberSaveable { mutableStateOf(today()) }
    var notes by rememberSaveable { mutableStateOf("") }
    var cost by rememberSaveable { mutableStateOf("") }
    var error by remember { mutableStateOf<String?>(null) }
    var fieldErrors by remember { mutableStateOf<Map<String, String>>(emptyMap()) }
    var busy by remember { mutableStateOf(false) }
    var idem by rememberSaveable { mutableStateOf("") }
    val scope = rememberCoroutineScope()
    FormDialog("Log a service", "Save", busy, onSave = {
        if (busy) return@FormDialog
        val c = if (cost.isBlank()) "" else moneyText(cost)
        // 4.29.0: each problem on its own field; 4.30.0: all of them in the summary.
        fieldErrors = listOfNotNull(
            if (!Regex("^\\d{4}-\\d{2}-\\d{2}$").matches(date.trim())) "date" to "Enter the date as year-month-day, such as ${today()}."
            else if (date.trim() > today()) "date" to "The service date cannot be in the future." else null,
            if (c == null) "cost" to "Cost: enter digits, like 12.50, or leave it empty." else null,
        ).toMap()
        when {
            fieldErrors.isNotEmpty() || c == null -> error = errorSummary(fieldErrors.values)
            else -> scope.launchSave({ busy = it }, { error = it }) {
                // 4.28.0: a retry after a lost reply logs it once (see sendOnce).
                api.callOnce("equipment_service", JSONObject().put("equipment_id", id).put("service_type", type).put("service_date", date.trim()).put("notes", notes.trim()).put("cost", c),
                    idem, { idem = it }, "this service")
                onDone("Service logged.")
            }
        }
    }, onCancel = onCancel) {
        Choice("Type", type, types.map { it to it }) { type = it }
        Field("Date", date, { date = it }, hint = "year-month-day", error = fieldErrors["date"], required = true)
        Field("Notes", notes, { notes = it }, hint = "optional")
        Field("Cost in dollars", cost, { cost = it }, hint = "optional, like 12.50", keyboard = KeyboardType.Decimal, error = fieldErrors["cost"])
        ErrorText(error)
    }
}

@Composable
private fun AddEquipmentDialog(api: Api, onDone: (String) -> Unit, onCancel: () -> Unit) {
    val categories = api.formList("equipment_categories", listOf("Braille Display", "Screen Reader License", "Magnifier", "Tablet/Phone", "Computer", "Keyboard/Input", "Audio", "Other"))
    val conditions = api.formList("equipment_conditions", listOf("New", "Good", "Fair", "Needs Repair"))
    var name by rememberSaveable { mutableStateOf("") }
    var category by rememberSaveable { mutableStateOf(if ("Other" in categories) "Other" else categories.first()) }
    var serial by rememberSaveable { mutableStateOf("") }
    var condition by rememberSaveable { mutableStateOf(if ("Good" in conditions) "Good" else conditions.first()) }
    var value by rememberSaveable { mutableStateOf("") }
    var interval by rememberSaveable { mutableStateOf("") }
    var notes by rememberSaveable { mutableStateOf("") }
    var error by remember { mutableStateOf<String?>(null) }
    var fieldErrors by remember { mutableStateOf<Map<String, String>>(emptyMap()) }
    var busy by remember { mutableStateOf(false) }
    var idem by rememberSaveable { mutableStateOf("") }
    val scope = rememberCoroutineScope()
    FormDialog("Add equipment", "Add", busy, onSave = {
        if (busy) return@FormDialog
        val v = if (value.isBlank()) "" else moneyText(value)
        val days = if (interval.isBlank()) 0 else intervalDays(interval)
        // 4.29.0: each problem on its own field; 4.30.0: all of them in the summary.
        fieldErrors = listOfNotNull(
            if (name.isBlank()) "name" to "Item name: enter the item's name." else null,
            if (v == null) "value" to "Value: enter digits, like 12.50, or leave it empty." else null,
            if (days == null) "interval" to INTERVAL_MESSAGE else null,
        ).toMap()
        when {
            fieldErrors.isNotEmpty() || v == null || days == null -> error = errorSummary(fieldErrors.values)
            else -> scope.launchSave({ busy = it }, { error = it }) {
                api.callOnce("add_equipment", JSONObject().put("name", name.trim()).put("category", category).put("serial", serial.trim())
                    .put("condition", condition).put("value", v).put("maintenance_interval_days", days).put("notes", notes.trim()),
                    idem, { idem = it }, "“${name.trim()}”")
                onDone(name.trim() + " added.")
            }
        }
    }, onCancel = onCancel) {
        Field("Item name", name, { name = it }, required = true, error = fieldErrors["name"])
        Choice("Category", category, categories.map { it to it }) { category = it }
        Field("Serial number", serial, { serial = it }, hint = "optional")
        Choice("Condition", condition, conditions.map { it to it }) { condition = it }
        Field("Value in dollars", value, { value = it }, hint = "optional, like 12.50", keyboard = KeyboardType.Decimal, error = fieldErrors["value"])
        Field("Service interval in days", interval, { interval = it }, hint = "optional, 0 to 3650", keyboard = KeyboardType.Number, error = fieldErrors["interval"])
        Field("Notes", notes, { notes = it }, hint = "optional")
        ErrorText(error)
    }
}

/** Reservations and the waitlist, held and waiting (4.26.0): add one, or cancel one. */
@Composable
fun ReservationsScreen(api: Api) {
    val state = rememberLoaded(api.userId) { parseReservations(api.call("reservations")) }
    var adding by rememberSaveable { mutableStateOf(false) }
    var cancelling by remember { mutableStateOf<org.casemanagement.app.Reservation?>(null) }
    var notice by remember { mutableStateOf<String?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    val scope = rememberCoroutineScope()
    Page {
        NoticeText(notice)
        ErrorText(error)
        PrimaryButton("Add a reservation") { adding = true }
        Show(state, "reservations") { list ->
            Heading("Active reservations (${list.size})")
            if (list.isEmpty()) Text("No active reservations.")
            list.forEach { r ->
                ListRow(listOfNotNull(r.itemName, r.clientName + ", " + r.status, r.neededFrom?.let { "Needed from " + dayText(it) }, r.neededUntil?.let { "until " + dayText(it) }, r.notes),
                    action = "cancel it", onClick = { cancelling = r })
            }
        }
    }
    cancelling?.let { r ->
        Confirm("Cancel the reservation?", "Cancel the reservation of ${r.itemName} for ${r.clientName}? A waitlist place cannot be given back.", "Cancel reservation", {
            error = null; notice = null
            scope.launch {
                try { notice = reservationCancelledWords(api.call("cancel_reservation", body = JSONObject().put("reservation_id", r.id))) }
                catch (e: ApiException) { error = e.message }
                state.reload()
            }
        }, { cancelling = null })
    }
    if (adding) ReservationDialog(api, fixedItem = 0, onDone = { m -> adding = false; notice = m; state.reload() }, onCancel = { adding = false })
}

/** Reserve an item for a client: held now when it is free, otherwise on the waitlist. */
@Composable
private fun ReservationDialog(api: Api, fixedItem: Int, onDone: (String) -> Unit, onCancel: () -> Unit) {
    val choices = rememberLoaded(fixedItem) {
        val items = if (fixedItem > 0) emptyList() else distinctItems(parseEquipment(api.call("equipment"))).filter { it.status != "Retired" }
        items to openClientChoices(api.call("client_choices"))
    }
    var item by rememberSaveable { mutableStateOf(if (fixedItem > 0) fixedItem.toString() else "") }
    var client by rememberSaveable { mutableStateOf("") }
    var from by rememberSaveable { mutableStateOf("") }
    var until by rememberSaveable { mutableStateOf("") }
    var notes by rememberSaveable { mutableStateOf("") }
    var error by remember { mutableStateOf<String?>(null) }
    var fromError by remember { mutableStateOf<String?>(null) }
    var untilError by remember { mutableStateOf<String?>(null) }
    var busy by remember { mutableStateOf(false) }
    var idem by rememberSaveable { mutableStateOf("") }
    val scope = rememberCoroutineScope()
    val day = Regex("^\\d{4}-\\d{2}-\\d{2}$")
    FormDialog("Reserve an item", "Reserve", busy, missing = stillNeeded(missingNames("Item" to item.isEmpty(), "Client" to client.isEmpty())), onSave = {
        if (busy) return@FormDialog
        // 4.29.0: each problem on its own field; 4.30.0: all of them in the summary.
        fromError = if (from.isNotBlank() && !day.matches(from.trim())) "Enter Needed from as year-month-day, or leave it empty." else null
        untilError = if (until.isNotBlank() && !day.matches(until.trim())) "Enter Needed until as year-month-day, or leave it empty."
            else if (until.isNotBlank() && until.trim() < today()) "Needed until cannot be in the past. Enter today or a later date, or leave it blank." else null
        when {
            fromError != null || untilError != null -> error = fromError ?: untilError
            else -> scope.launchSave({ busy = it }, { error = it }) {
                val b = JSONObject().put("equipment_id", item.toInt()).put("client_id", client.toInt()).put("notes", notes.trim())
                if (from.isNotBlank()) b.put("needed_from", from.trim())
                if (until.isNotBlank()) b.put("needed_until", until.trim())
                onDone(reservationWords(api.callOnce("add_reservation", b, idem, { idem = it }, "the reservation")))
            }
        }
    }, onCancel = onCancel) {
        if (choices.loading) Text("Loading…")
        ErrorText(choices.error, takeFocus = false)
        choices.data?.let { (items, clients) ->
            if (fixedItem == 0) Choice("Item", item, listOf("" to "Choose an item") + items.map { it.id.toString() to "${it.name} (${it.status})" }, required = true) { item = it }
            Choice("Client", client, listOf("" to "Choose a client") + clients.map { it.id.toString() to it.name }, required = true) { client = it }
        }
        Field("Needed from", from, { from = it }, hint = "optional, year-month-day", error = fromError)
        Field("Needed until", until, { until = it }, hint = "optional, year-month-day", error = untilError)
        Field("Notes", notes, { notes = it }, hint = "optional")
        ErrorText(error)
    }
}

/** Why equipment is lent and to whom (4.21.0), as the website's loan form names them. */
val LOAN_PURPOSES = listOf(
    "decision" to "To help decide which device to get",
    "loaner" to "As a loaner while a device is repaired or funding is awaited",
    "accommodation" to "As a short-term accommodation",
    "training" to "For training, self-education or professional development",
)
val BORROWER_TYPES = listOf(
    "individual" to "A person with a disability",
    "family" to "A family member or guardian",
    "education" to "Someone from education",
    "employment" to "Someone from employment",
    "health" to "Someone from health or rehabilitation",
    "community" to "Someone from community living",
    "technology" to "A technology professional",
)

@Composable
private fun LendDialog(api: Api, e: EquipmentItem, onDone: (String) -> Unit, onCancel: () -> Unit) {
    val clients = rememberLoaded(e.id) { openClientChoices(api.call("client_choices")) }
    var client by rememberSaveable { mutableStateOf("") }
    // 4.34.0: the administrator's loan period gives the return date; clearing it means no return date.
    var due by rememberSaveable { mutableStateOf(defaultReturnDate(today(), api.formNumber("loan_due_days", 60))) }
    var dueError by remember { mutableStateOf<String?>(null) }
    var condition by rememberSaveable { mutableStateOf("") }
    var signedBy by rememberSaveable { mutableStateOf("") }
    var purpose by rememberSaveable { mutableStateOf("") }
    var borrower by rememberSaveable { mutableStateOf("") }
    var notes by rememberSaveable { mutableStateOf("") }
    var error by remember { mutableStateOf<String?>(null) }
    var busy by remember { mutableStateOf(false) }
    var idem by rememberSaveable { mutableStateOf("") }
    val scope = rememberCoroutineScope()
    val conditions = api.formList("equipment_conditions", listOf("New", "Good", "Fair", "Needs Repair"))
    val gate = rememberGate(stillNeeded(missingNames("Client" to client.isEmpty())))
    AlertDialog(
        onDismissRequest = { if (!busy) onCancel() },
        title = { Text("Lend " + e.name, modifier = Modifier.semantics { heading() }) },
        text = {
            Column(Modifier.verticalScroll(rememberScrollState())) {
                val list = clients.data ?: emptyList()
                if (clients.loading) Text("Loading clients…")
                ErrorText(clients.error, takeFocus = false)
                Choice("Client", client, listOf("" to "Choose a client") + list.map { it.id.toString() to it.name }, required = true) { client = it }
                Field("Return due", due, { due = it; dueError = null }, hint = "year-month-day; clear it for no return date", error = dueError)
                Choice("Condition going out", condition, loanConditionOptions(conditions)) { condition = it }
                Field("Received by", signedBy, { signedBy = it }, hint = "the name of who took it, optional")
                Choice("Purpose", purpose, listOf("" to "Not recorded") + LOAN_PURPOSES) { purpose = it }
                Choice("Who is borrowing", borrower, listOf("" to "Not recorded") + BORROWER_TYPES) { borrower = it }
                Field("Notes", notes, { notes = it }, hint = "optional")
                ErrorText(error)
                GateError(gate)
            }
        },
        confirmButton = {
            TextButton(enabled = !busy, onClick = {
                if (!gate.check(stillNeeded(missingNames("Client" to client.isEmpty())))) return@TextButton
                dueError = if (due.isNotBlank() && !Regex("^\\d{4}-\\d{2}-\\d{2}$").matches(due.trim())) "Return due: enter the date as year-month-day, or clear it for no return date."
                    else if (due.isNotBlank() && due.trim() < today()) "Return due cannot be before today." else null
                dueError?.let { error = it; return@TextButton }
                scope.launchSave({ busy = it }, { error = it }) {
                    val b = JSONObject().put("equipment_id", e.id).put("client_id", client.toInt()).put("condition_out", condition).put("accepted_name", signedBy.trim())
                    // Always sent: a cleared date ("") is a loan with no return date.
                    b.put("due_date", due.trim())
                    if (notes.isNotBlank()) b.put("notes", notes.trim().take(255))
                    if (purpose.isNotEmpty()) b.put("purpose", purpose)
                    if (borrower.isNotEmpty()) b.put("borrower_type", borrower)
                    api.callOnce("loan_equipment", b, idem, { idem = it }, "the loan")
                    onDone(e.name + " lent.")
                }
            }) { Text(if (busy) "Saving…" else "Lend") }
        },
        dismissButton = { TextButton(enabled = !busy, onClick = onCancel) { Text("Cancel") } },
    )
}

/**
 * Device check-ins after a loan or purchase: due, then upcoming. Recording one is kept offline
 * (the server takes it only once), as in the iPhone app.
 */
@Composable
fun FollowupsScreen(api: Api) {
    val state = rememberLoaded(api.userId) { api.callCached("followups", cacheKey = "followups") }
    // Kept as text, so rotation and process death keep the follow-up open (4.28.0).
    var chosenJson by rememberSaveable { mutableStateOf<String?>(null) }
    val chosen = remember(chosenJson) { chosenJson?.let { try { JSONObject(it) } catch (e: Exception) { null } } }
    var notice by remember { mutableStateOf<String?>(null) }
    Show(state, "device follow-ups") { (r, savedAt) ->
        Page {
            OfflineCopyNotice(savedAt)
            NoticeText(notice)
            val queued = api.ownQueued().filter { it.optString("action") == "save_followup" }.map { try { JSONObject(it.optString("body")).optInt("id") } catch (e: Exception) { 0 } }.toSet()
            // 4.36.0: the website's four tiles, one stop together.
            ListRow(followupTiles(r))
            listOf("due" to "Due now", "upcoming" to "Upcoming").forEach { (k, title) ->
                val list = r.optJSONArray(k).objects()
                Heading("$title (${list.size})")
                list.forEach { f ->
                    val waiting = f.optInt("id") in queued
                    ListRow(listOf(f.optString("client_name") + ": " + f.optString("equipment_name"),
                        (if (waiting) "Recorded, waiting to send. " else "") + "${f.optInt("interval_days")}-day check, due " + dayText(f.str("due_date"))),
                        action = "record it", onClick = if (waiting) null else ({ chosenJson = f.toString() }))
                }
            }
            // 4.36.0: Recently recorded, in the website's words.
            val recent = r.optJSONArray("recent").objects()
            if (recent.isNotEmpty()) {
                Heading("Recently recorded (${recent.size})")
                recent.forEach { f -> ListRow(listOf(recentLine(f))) }
            }
        }
    }
    chosen?.let { f -> key(dialogKey("followup", f.optInt("id"))) { FollowupDialog(api, f, onDone = { m -> chosenJson = null; notice = m; state.reload() }, onCancel = { chosenJson = null }) } }
}

@Composable
private fun FollowupDialog(api: Api, f: JSONObject, onDone: (String) -> Unit, onCancel: () -> Unit) {
    var inUse by rememberSaveable { mutableStateOf("") }
    var satisfaction by rememberSaveable { mutableStateOf("") }
    var barriers by rememberSaveable { mutableStateOf("") }
    var notes by rememberSaveable { mutableStateOf("") }
    var error by remember { mutableStateOf<String?>(null) }
    var busy by remember { mutableStateOf(false) }
    var asking by remember { mutableStateOf<String?>(null) }
    val scope = rememberCoroutineScope()
    val client = f.optString("client_name"); val equipment = f.optString("equipment_name")
    fun send(op: String) {
        // 4.29.0: one press at a time, so a repeated double tap never moves it twice.
        if (busy) return
        scope.launchSave({ busy = it }, { error = it }) {
            val b = JSONObject().put("id", f.optInt("id")).put("op", op)
            // The due date shown, so the server moves it only from there (a second snooze changes nothing).
            if (op == "snooze") f.str("due_date")?.let { b.put("due_date", it) }
            if (op == "complete") {
                b.put("in_use", inUse).put("barriers", barriers.trim()).put("notes", notes.trim())
                satisfaction.toIntOrNull()?.let { b.put("satisfaction", it) }
                onDone(saveOrQueue(api, "save_followup", b, "Device follow-up: " + f.optString("client_name"), "Follow-up recorded.").let { s ->
                    followupRecordedWords(s.reply, s.message)
                })
            } else {
                val r = api.call("save_followup", body = b)
                // already: an earlier press (or try) moved it; nothing moved twice.
                onDone(if (op == "skip") "Follow-up skipped." else if (r.optBoolean("already")) r.str("message") ?: "This follow-up was already moved 14 days later." else "Follow-up moved 14 days later.")
            }
        }
    }
    val gate = rememberGate(stillNeeded(missingNames("Still in use?" to inUse.isEmpty())))
    AlertDialog(
        // 4.29.0: not closed while saving (Back or a tap outside), so the entry is never left half-sent.
        onDismissRequest = { if (!busy) onCancel() },
        title = { Text(f.optString("client_name") + ": " + f.optString("equipment_name"), modifier = Modifier.semantics { heading() }) },
        text = {
            Column(Modifier.verticalScroll(rememberScrollState())) {
                Choice("Still in use?", inUse, listOf("" to "Not answered") + FOLLOWUP_ANSWERS, required = true) { inUse = it }
                Choice("How satisfied", satisfaction, listOf("" to "Not answered") + (1..5).map { it.toString() to "$it of 5" }) { satisfaction = it }
                Field("Anything in the way", barriers, { barriers = it }, hint = "optional")
                Field("Notes", notes, { notes = it }, hint = "optional", singleLine = false)
                if (inUse == "no") Text("Saying no adds a case note and a follow-up task, and a recorded follow-up cannot be changed.")
                ErrorText(error)
                GateError(gate)
                TextButton(enabled = !busy, onClick = { send("snooze") }) { Text("Ask again in 14 days") }
                TextButton(enabled = !busy, onClick = { asking = "skip" }) { Text("Skip this check") }
            }
        },
        confirmButton = { TextButton(enabled = !busy, onClick = {
            if (gate.check(stillNeeded(missingNames("Still in use?" to inUse.isEmpty())))) { if (inUse == "no") asking = "no" else send("complete") }
        }) { Text(if (busy) "Saving…" else "Record") } },
        dismissButton = { TextButton(enabled = !busy, onClick = onCancel) { Text("Cancel") } },
    )
    // 4.34.0: a "No" and a skip are asked about first, naming the device and the client.
    when (asking) {
        "no" -> Confirm("Record that $equipment is no longer in use?", "Record that $equipment is no longer in use by $client? This adds a case note and a follow-up task, and a recorded follow-up cannot be changed.",
            "Record it", { send("complete") }, { asking = null })
        "skip" -> Confirm("Skip this follow-up?", "Skip the ${f.optInt("interval_days")}-day follow-up for $client, $equipment?", "Skip it", { send("skip") }, { asking = null })
    }
}
