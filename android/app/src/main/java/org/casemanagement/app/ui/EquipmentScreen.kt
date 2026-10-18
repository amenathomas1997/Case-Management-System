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

/**
 * The equipment library: what is on loan (overdue first) and what is available; lend, return,
 * open an item's service details (4.26.0), add an item, and the reservations and waitlist.
 */
@Composable
fun EquipmentScreen(api: Api, nav: NavHostController) {
    var q by rememberSaveable { mutableStateOf("") }
    val state = rememberLoaded(api.userId) { parseEquipment(api.call("equipment")) }
    var chosen by remember { mutableStateOf<EquipmentItem?>(null) }
    var lending by remember { mutableStateOf<EquipmentItem?>(null) }
    var returning by remember { mutableStateOf<EquipmentItem?>(null) }
    var adding by remember { mutableStateOf(false) }
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
                Column {
                    Text(when (e.status) { "On Loan" -> e.borrower?.let { "On loan to $it." } ?: "On loan."; else -> e.status + "." })
                    if (e.status == "Available") TextButton(onClick = { chosen = null; lending = e }) { Text("Lend it") }
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
        fun ret(condition: String) {
            returning = null; error = null; notice = null
            scope.launch {
                try { api.call("return_equipment", body = JSONObject().put("loan_id", e.loanId ?: 0).put("condition_in", condition)); notice = e.name + " marked returned."; state.reload() }
                catch (x: ApiException) { error = x.message }
            }
        }
        AlertDialog(
            onDismissRequest = { returning = null },
            title = { Text("Return " + e.name, modifier = Modifier.semantics { heading() }) },
            text = {
                Column {
                    Text(e.borrower?.let { "On loan to $it." } ?: "On loan.")
                    TextButton(onClick = { ret("") }) { Text("Returned in the same condition") }
                    TextButton(onClick = { ret("Needs Repair") }) { Text("Returned needing repair") }
                }
            },
            confirmButton = { TextButton(onClick = { returning = null }) { Text("Keep on loan") } },
        )
    }
    lending?.let { e -> LendDialog(api, e, onDone = { m -> lending = null; notice = m; state.reload() }, onCancel = { lending = null }) }
    if (adding) AddEquipmentDialog(api, onDone = { m -> adding = false; notice = m; state.reload() }, onCancel = { adding = false })
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
    var logging by remember { mutableStateOf(false) }
    var reserving by remember { mutableStateOf(false) }
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
            Heading("Service history (${d.maintenance.size})")
            if (d.maintenance.isEmpty()) Text("No service logged.")
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
    var type by remember { mutableStateOf(types.first()) }
    var date by remember { mutableStateOf(today()) }
    var notes by remember { mutableStateOf("") }
    var cost by remember { mutableStateOf("") }
    var error by remember { mutableStateOf<String?>(null) }
    var busy by remember { mutableStateOf(false) }
    val scope = rememberCoroutineScope()
    FormDialog("Log a service", "Save", busy, onSave = {
        if (busy) return@FormDialog
        val c = if (cost.isBlank()) "" else moneyText(cost)
        when {
            !Regex("^\\d{4}-\\d{2}-\\d{2}$").matches(date.trim()) -> error = "Enter the date as year-month-day, such as ${today()}."
            date.trim() > today() -> error = "The service date cannot be in the future."
            c == null -> error = "Cost: enter digits, like 12.50, or leave it empty."
            else -> scope.launchSave({ busy = it }, { error = it }) {
                try {
                    api.call("equipment_service", body = JSONObject().put("equipment_id", id).put("service_type", type).put("service_date", date.trim()).put("notes", notes.trim()).put("cost", c))
                } catch (e: ApiException) {
                    if (e.network) throw ApiException("The connection dropped before the server answered, so it isn't known whether this service was logged. Check the item's service history for $type on ${dayText(date.trim())} before saving it again.")
                    throw e
                }
                onDone("Service logged.")
            }
        }
    }, onCancel = onCancel) {
        Choice("Type", type, types.map { it to it }) { type = it }
        Field("Date", date, { date = it }, hint = "year-month-day")
        Field("Notes", notes, { notes = it }, hint = "optional")
        Field("Cost in dollars", cost, { cost = it }, hint = "optional, like 12.50", keyboard = KeyboardType.Decimal)
        ErrorText(error)
    }
}

@Composable
private fun AddEquipmentDialog(api: Api, onDone: (String) -> Unit, onCancel: () -> Unit) {
    val categories = api.formList("equipment_categories", listOf("Braille Display", "Screen Reader License", "Magnifier", "Tablet/Phone", "Computer", "Keyboard/Input", "Audio", "Other"))
    val conditions = api.formList("equipment_conditions", listOf("New", "Good", "Fair", "Needs Repair"))
    var name by remember { mutableStateOf("") }
    var category by remember { mutableStateOf(if ("Other" in categories) "Other" else categories.first()) }
    var serial by remember { mutableStateOf("") }
    var condition by remember { mutableStateOf(if ("Good" in conditions) "Good" else conditions.first()) }
    var value by remember { mutableStateOf("") }
    var interval by remember { mutableStateOf("") }
    var notes by remember { mutableStateOf("") }
    var error by remember { mutableStateOf<String?>(null) }
    var busy by remember { mutableStateOf(false) }
    val scope = rememberCoroutineScope()
    FormDialog("Add equipment", "Add", busy, onSave = {
        if (busy) return@FormDialog
        val v = if (value.isBlank()) "" else moneyText(value)
        val days = if (interval.isBlank()) 0 else intervalDays(interval)
        when {
            name.isBlank() -> error = "Item name: enter the item's name."
            v == null -> error = "Value: enter digits, like 12.50, or leave it empty."
            days == null -> error = INTERVAL_MESSAGE
            else -> scope.launchSave({ busy = it }, { error = it }) {
                try {
                    api.call("add_equipment", body = JSONObject().put("name", name.trim()).put("category", category).put("serial", serial.trim())
                        .put("condition", condition).put("value", v).put("maintenance_interval_days", days).put("notes", notes.trim()))
                } catch (e: ApiException) {
                    if (e.network) throw ApiException("The connection dropped before the server answered, so it isn't known whether this item was added. Check the equipment list for “${name.trim()}” before adding it again.")
                    throw e
                }
                onDone(name.trim() + " added.")
            }
        }
    }, onCancel = onCancel) {
        Field("Item name", name, { name = it }, hint = "required")
        Choice("Category", category, categories.map { it to it }) { category = it }
        Field("Serial number", serial, { serial = it }, hint = "optional")
        Choice("Condition", condition, conditions.map { it to it }) { condition = it }
        Field("Value in dollars", value, { value = it }, hint = "optional, like 12.50", keyboard = KeyboardType.Decimal)
        Field("Service interval in days", interval, { interval = it }, hint = "optional, 0 to 3650", keyboard = KeyboardType.Number)
        Field("Notes", notes, { notes = it }, hint = "optional")
        ErrorText(error)
    }
}

/** Reservations and the waitlist, held and waiting (4.26.0): add one, or cancel one. */
@Composable
fun ReservationsScreen(api: Api) {
    val state = rememberLoaded(api.userId) { parseReservations(api.call("reservations")) }
    var adding by remember { mutableStateOf(false) }
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
                try { api.call("cancel_reservation", body = JSONObject().put("reservation_id", r.id)); notice = "Reservation cancelled." }
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
    var item by remember { mutableStateOf(if (fixedItem > 0) fixedItem.toString() else "") }
    var client by remember { mutableStateOf("") }
    var from by remember { mutableStateOf("") }
    var until by remember { mutableStateOf("") }
    var notes by remember { mutableStateOf("") }
    var error by remember { mutableStateOf<String?>(null) }
    var busy by remember { mutableStateOf(false) }
    val scope = rememberCoroutineScope()
    val day = Regex("^\\d{4}-\\d{2}-\\d{2}$")
    FormDialog("Reserve an item", "Reserve", busy, enabled = item.isNotEmpty() && client.isNotEmpty(), onSave = {
        if (busy) return@FormDialog
        when {
            from.isNotBlank() && !day.matches(from.trim()) -> error = "Enter Needed from as year-month-day, or leave it empty."
            until.isNotBlank() && !day.matches(until.trim()) -> error = "Enter Needed until as year-month-day, or leave it empty."
            else -> scope.launchSave({ busy = it }, { error = it }) {
                val b = JSONObject().put("equipment_id", item.toInt()).put("client_id", client.toInt()).put("notes", notes.trim())
                if (from.isNotBlank()) b.put("needed_from", from.trim())
                if (until.isNotBlank()) b.put("needed_until", until.trim())
                onDone(reservationWords(api.call("add_reservation", body = b)))
            }
        }
    }, onCancel = onCancel) {
        if (choices.loading) Text("Loading…")
        ErrorText(choices.error)
        choices.data?.let { (items, clients) ->
            if (fixedItem == 0) Choice("Item", item, listOf("" to "Choose an item") + items.map { it.id.toString() to "${it.name} (${it.status})" }) { item = it }
            Choice("Client", client, listOf("" to "Choose a client") + clients.map { it.id.toString() to it.name }) { client = it }
        }
        Field("Needed from", from, { from = it }, hint = "optional, year-month-day")
        Field("Needed until", until, { until = it }, hint = "optional, year-month-day")
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
    var client by remember { mutableStateOf("") }
    var due by remember { mutableStateOf("") }
    var condition by remember { mutableStateOf(e.condition ?: "Good") }
    var signedBy by remember { mutableStateOf("") }
    var purpose by remember { mutableStateOf("") }
    var borrower by remember { mutableStateOf("") }
    var notes by remember { mutableStateOf("") }
    var error by remember { mutableStateOf<String?>(null) }
    var busy by remember { mutableStateOf(false) }
    val scope = rememberCoroutineScope()
    val conditions = api.formList("equipment_conditions", listOf("New", "Good", "Fair", "Needs Repair"))
    AlertDialog(
        onDismissRequest = onCancel,
        title = { Text("Lend " + e.name, modifier = Modifier.semantics { heading() }) },
        text = {
            Column(Modifier.verticalScroll(rememberScrollState())) {
                val list = clients.data ?: emptyList()
                if (clients.loading) Text("Loading clients…")
                ErrorText(clients.error)
                Choice("Client", client, listOf("" to "Choose a client") + list.map { it.id.toString() to it.name }) { client = it }
                Field("Due back", due, { due = it }, hint = "year-month-day; empty for the usual period")
                Choice("Condition going out", condition, conditions.map { it to it }) { condition = it }
                Field("Received by", signedBy, { signedBy = it }, hint = "the name of who took it, optional")
                Choice("Purpose", purpose, listOf("" to "Not recorded") + LOAN_PURPOSES) { purpose = it }
                Choice("Who is borrowing", borrower, listOf("" to "Not recorded") + BORROWER_TYPES) { borrower = it }
                Field("Notes", notes, { notes = it }, hint = "optional")
                ErrorText(error)
            }
        },
        confirmButton = {
            TextButton(enabled = !busy && client.isNotEmpty(), onClick = {
                scope.launchSave({ busy = it }, { error = it }) {
                    val b = JSONObject().put("equipment_id", e.id).put("client_id", client.toInt()).put("condition_out", condition).put("accepted_name", signedBy.trim())
                    if (due.isNotBlank()) b.put("due_date", due.trim())
                    if (notes.isNotBlank()) b.put("notes", notes.trim().take(255))
                    if (purpose.isNotEmpty()) b.put("purpose", purpose)
                    if (borrower.isNotEmpty()) b.put("borrower_type", borrower)
                    api.call("loan_equipment", body = b)
                    onDone(e.name + " lent.")
                }
            }) { Text(if (busy) "Saving…" else "Lend") }
        },
        dismissButton = { TextButton(onClick = onCancel) { Text("Cancel") } },
    )
}

/**
 * Device check-ins after a loan or purchase: due, then upcoming. Recording one is kept offline
 * (the server takes it only once), as in the iPhone app.
 */
@Composable
fun FollowupsScreen(api: Api) {
    val state = rememberLoaded(api.userId) { api.callCached("followups", cacheKey = "followups") }
    var chosen by remember { mutableStateOf<JSONObject?>(null) }
    var notice by remember { mutableStateOf<String?>(null) }
    Show(state, "device follow-ups") { (r, savedAt) ->
        Page {
            OfflineCopyNotice(savedAt)
            NoticeText(notice)
            val queued = api.ownQueued().filter { it.optString("action") == "save_followup" }.map { try { JSONObject(it.optString("body")).optInt("id") } catch (e: Exception) { 0 } }.toSet()
            listOf("due" to "Due now", "upcoming" to "Coming up").forEach { (k, title) ->
                val list = r.optJSONArray(k).objects()
                Heading("$title (${list.size})")
                list.forEach { f ->
                    val waiting = f.optInt("id") in queued
                    ListRow(listOf(f.optString("client_name") + ": " + f.optString("equipment_name"),
                        (if (waiting) "Recorded, waiting to send. " else "") + "${f.optInt("interval_days")}-day check, due " + dayText(f.str("due_date"))),
                        action = "record it", onClick = if (waiting) null else ({ chosen = f }))
                }
            }
        }
    }
    chosen?.let { f -> FollowupDialog(api, f, onDone = { m -> chosen = null; notice = m; state.reload() }, onCancel = { chosen = null }) }
}

@Composable
private fun FollowupDialog(api: Api, f: JSONObject, onDone: (String) -> Unit, onCancel: () -> Unit) {
    var inUse by remember { mutableStateOf("") }
    var satisfaction by remember { mutableStateOf("") }
    var barriers by remember { mutableStateOf("") }
    var notes by remember { mutableStateOf("") }
    var error by remember { mutableStateOf<String?>(null) }
    var busy by remember { mutableStateOf(false) }
    val scope = rememberCoroutineScope()
    fun send(op: String) {
        scope.launchSave({ busy = it }, { error = it }) {
            val b = JSONObject().put("id", f.optInt("id")).put("op", op)
            if (op == "complete") {
                b.put("in_use", inUse).put("barriers", barriers.trim()).put("notes", notes.trim())
                satisfaction.toIntOrNull()?.let { b.put("satisfaction", it) }
                onDone(saveOrQueue(api, "save_followup", b, "Device follow-up: " + f.optString("client_name"), "Follow-up recorded.").let { s ->
                    s.reply?.str("warning") ?: s.message
                })
            } else {
                api.call("save_followup", body = b)
                onDone(if (op == "skip") "Follow-up skipped." else "Follow-up moved 14 days later.")
            }
        }
    }
    AlertDialog(
        onDismissRequest = onCancel,
        title = { Text(f.optString("client_name") + ": " + f.optString("equipment_name"), modifier = Modifier.semantics { heading() }) },
        text = {
            Column(Modifier.verticalScroll(rememberScrollState())) {
                Choice("Still using it", inUse, listOf("" to "Not answered", "yes" to "Yes", "no" to "No")) { inUse = it }
                Choice("How satisfied", satisfaction, listOf("" to "Not answered") + (1..5).map { it.toString() to "$it of 5" }) { satisfaction = it }
                Field("Anything in the way", barriers, { barriers = it }, hint = "optional")
                Field("Notes", notes, { notes = it }, hint = "optional", singleLine = false)
                if (inUse == "no") Text("Saying no adds a case note and a follow-up task, and a recorded follow-up cannot be changed.")
                ErrorText(error)
                TextButton(onClick = { send("snooze") }) { Text("Ask again in 14 days") }
                TextButton(onClick = { send("skip") }) { Text("Skip this check") }
            }
        },
        confirmButton = { TextButton(enabled = !busy && inUse.isNotEmpty(), onClick = { send("complete") }) { Text(if (busy) "Saving…" else "Record") } },
        dismissButton = { TextButton(onClick = onCancel) { Text("Cancel") } },
    )
}
