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
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.input.KeyboardType
import androidx.navigation.NavHostController
import kotlinx.coroutines.launch
import org.casemanagement.app.Api
import org.casemanagement.app.ApiException
import org.casemanagement.app.TaskItem
import org.casemanagement.app.objects
import org.casemanagement.app.parseClients
import org.casemanagement.app.parseTasks
import org.casemanagement.app.str
import org.json.JSONObject

/*
 * 4.36.0 screens and My account sections: phones signed in, alerts by text and email, passkeys,
 * calendar and meetings, my availability, reporting a privacy incident, the task form and
 * completing several tasks. Every dialog is the app's AlertDialog (LockAware.kt).
 */

/** Counts privacy incident reports filed in this run, so a "Report a privacy incident for <phone>" offer goes once one is filed. */
object PrivacyReports {
    var filed by mutableIntStateOf(0)
}

/** R1: each phone signed in to the app, with Sign out and Report lost and wipe. Null from an older server: the count only. */
@Composable
fun PhonesSection(api: Api, nav: NavHostController, count: Int) {
    val state = rememberLoaded(api.userId) { try { api.call("app_devices") } catch (e: ApiException) { null } }
    var latest by remember { mutableStateOf<List<DeviceRow>?>(null) }
    var askId by rememberSaveable { mutableIntStateOf(0) }
    var askLost by rememberSaveable { mutableStateOf(false) }
    var lostName by rememberSaveable { mutableStateOf("") }
    var filedAtOffer by rememberSaveable { mutableIntStateOf(0) }
    var idem by rememberSaveable { mutableStateOf("") }
    // Typed only for the wipe; never kept beyond the screen (a rotation forgets it).
    var password by remember { mutableStateOf("") }
    var passwordError by remember { mutableStateOf<String?>(null) }
    var dialogError by remember { mutableStateOf<String?>(null) }
    var notice by remember { mutableStateOf<String?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    var busy by remember { mutableStateOf(false) }
    var presses by remember { mutableIntStateOf(0) }
    val scope = rememberCoroutineScope()
    // A privacy incident filed since the offer was made: the offer goes.
    androidx.compose.runtime.LaunchedEffect(PrivacyReports.filed) { if (PrivacyReports.filed > filedAtOffer) lostName = "" }
    val list = latest ?: parseDevices(state.data)
    if (list == null) {
        ListRow(listOf("Phones signed in to the app: $count"))
        return
    }
    Heading("Phones signed in to the app")
    Text(state.data?.str("intro") ?: PHONES_INTRO)
    if (list.isEmpty()) Text(PHONES_NONE)
    list.forEach { d ->
        ListRow(deviceLines(d))
        val b = deviceButtons(d)
        if (b.signOut) SecondaryButton("Sign out ${d.name}") { askId = d.id; askLost = false; error = null }
        if (b.reportLost) SecondaryButton("Report lost and wipe ${d.name}") { askId = d.id; askLost = true; password = ""; passwordError = null; dialogError = null; error = null }
    }
    NoticeText(notice)
    if (lostName.isNotEmpty() && api.can("clients")) SecondaryButton("Report a privacy incident for $lostName") { nav.navigate(privacyFromLostRoute(lostName)) }
    ErrorText(error)
    list.firstOrNull { it.id == askId && askId > 0 }?.let { d ->
        val lost = askLost
        fun act() {
            if (busy) return
            presses++
            if (lost && password.isEmpty()) { passwordError = PASSWORD_NEEDED; dialogError = passwordError; return }
            busy = true; error = null; notice = null; lostName = ""; passwordError = null; dialogError = null
            scope.launch {
                try {
                    // The retry key follows the phone and the action only: the password never goes into what is kept.
                    val r = org.casemanagement.app.sendOnce(idem, { idem = it }, "device_action|${d.id}|$lost", "the phone") { k ->
                        api.call("device_action", body = deviceActionBody(d.id, lost, password).put("idem_key", k))
                    }
                    notice = r.optString("message")
                    parseDevices(r)?.let { latest = it }
                    if (r.optBoolean("lost", lost)) { lostName = d.name; filedAtOffer = PrivacyReports.filed }
                    password = ""; askId = 0
                } catch (e: ApiException) {
                    val (pw, summary) = deviceActionErrors(e.reply, e.message)
                    if (lost && pw != null) { passwordError = pw; dialogError = summary }
                    else { error = summary; askId = 0; latest = null; state.reload() }
                } finally { busy = false }
            }
        }
        if (lost) AlertDialog(
            onDismissRequest = { if (!busy) { askId = 0; password = "" } },
            title = { Text(lostConfirmTitle(d.name), modifier = Modifier.semantics { heading() }) },
            text = {
                Column(Modifier.verticalScroll(rememberScrollState())) {
                    Text(lostConfirmText(d.name))
                    Field("Your password", password, { password = it; passwordError = null }, password = true, required = true, autofill = Fill.Password, error = passwordError)
                    ErrorText(dialogError, focusKey = presses)
                }
            },
            confirmButton = { TextButton(enabled = !busy, onClick = { act() }) { Text(if (busy) "Please wait…" else "Report lost and wipe") } },
            dismissButton = { TextButton(enabled = !busy, onClick = { askId = 0; password = "" }) { Text("Cancel") } },
        )
        else Confirm(signOutConfirmTitle(d.name), SIGN_OUT_CONFIRM_TEXT, "Sign it out", { act() }, { askId = 0 })
    }
}

/** R3: the mobile number for urgent texts, and how alerts are emailed. Hidden on an older server. */
@Composable
fun AlertSettingsSection(api: Api, a: JSONObject, reload: () -> Unit) {
    if (!a.has("alert_email")) return
    var mobile by rememberSaveable { mutableStateOf(a.str("mobile") ?: "") }
    var sms by rememberSaveable { mutableStateOf(a.optBoolean("alert_sms")) }
    var email by rememberSaveable { mutableStateOf(a.optString("alert_email").ifEmpty { "daily" }) }
    var part by remember { mutableStateOf("") }
    var notice by remember { mutableStateOf<String?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    var errors by remember { mutableStateOf<Map<String, String>>(emptyMap()) }
    var busy by remember { mutableStateOf(false) }
    var presses by remember { mutableIntStateOf(0) }
    val scope = rememberCoroutineScope()
    fun save(which: String, body: JSONObject?) {
        if (busy || body == null) return
        part = which; presses++; busy = true; error = null; notice = null; errors = emptyMap()
        scope.launch {
            try {
                val r = api.call("save_alert_settings", body = body)
                notice = r.optString("message").ifEmpty { "Saved." }
                if (r.has("mobile")) mobile = r.str("mobile") ?: ""
                if (r.has("alert_sms")) sms = r.optBoolean("alert_sms")
                r.str("alert_email")?.let { email = it }
                reload()
            } catch (e: ApiException) {
                errors = replyErrors(e.reply)
                error = errorSummary(errors.values) ?: e.message
            } finally { busy = false }
        }
    }
    Heading("Your mobile number")
    Field("Mobile number", mobile, { mobile = it }, hint = "optional; for urgent alerts by text and test texts", keyboard = KeyboardType.Phone,
        autofill = Fill.Phone, error = errors["mobile"])
    if (a.optBoolean("sms_enabled")) Tick("Text me when an urgent alert comes in (at most one text every 15 minutes, never with client details)", sms) { sms = it }
    PrimaryButton("Save mobile number", busy = busy && part == "mobile") { save("mobile", alertSettingsBody(true, false, mobile, sms, email, a.optBoolean("sms_enabled"))) }
    if (part == "mobile") { NoticeText(notice); ErrorText(error, focusKey = presses) }
    Heading("How alerts reach you")
    Choice("Email me about alerts", email, alertEmailChoices(a), error = errors["alert_email"]) { email = it }
    PrimaryButton("Save alert emails", busy = busy && part == "email") { save("email", alertSettingsBody(false, true, mobile, sms, email, a.optBoolean("sms_enabled"))) }
    if (part == "email") { NoticeText(notice); ErrorText(error, focusKey = presses) }
}

/** R4: the account's passkeys, each with Remove; adding one is on the website. */
@Composable
fun PasskeysSection(api: Api, a: JSONObject, reload: () -> Unit) {
    val context = LocalContext.current
    val keys = a.optJSONArray("passkeys").objects()
    var removing by rememberSaveable { mutableIntStateOf(0) }
    var notice by remember { mutableStateOf<String?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    val scope = rememberCoroutineScope()
    Heading("Passkeys")
    if (keys.isEmpty()) Text("No passkeys on this account.")
    keys.forEach { p ->
        val name = passkeyName(p.str("name"))
        ListRow(listOf(name, passkeyDates(p.str("created"), p.str("last_used"))))
        SecondaryButton("Remove $name") { removing = p.optInt("id") }
    }
    NoticeText(notice)
    ErrorText(error)
    ListRow(listOf("Add a passkey on the website"), action = "open it in the browser", onClick = {
        error = if (openSitePage(api, context, "account.php#passkeys")) null else "No browser on this phone can open the website."
    })
    keys.firstOrNull { removing > 0 && it.optInt("id") == removing }?.let { p ->
        val name = passkeyName(p.str("name"))
        Confirm("Remove this passkey?", "You can still sign in with your password and code. Remove it from your device's saved passwords too.", "Remove “$name”", {
            scope.launch {
                error = null
                try { notice = api.call("remove_passkey", body = JSONObject().put("id", p.optInt("id"))).optString("message").ifEmpty { "Passkey removed." }; reload() }
                catch (e: ApiException) { error = e.message; reload() }
            }
        }, { removing = 0 }, no = "Keep it")
    }
}

/** R5: calendar sync and online meetings, read only, with the website to change them. */
@Composable
fun ConnectionsSection(api: Api, a: JSONObject) {
    val calendar = if (a.isNull("calendar")) null else a.optJSONObject("calendar")
    val rows = connectionsRows(calendar, a.optJSONArray("meetings"))
    if (rows.isEmpty()) return
    val context = LocalContext.current
    var error by remember { mutableStateOf<String?>(null) }
    Heading("Calendar and online meetings")
    rows.forEach { ListRow(listOf(it)) }
    ListRow(listOf("Change on the website"), action = "open it in the browser", onClick = {
        error = if (openSitePage(api, context, if (calendar != null) "account.php#calendar-h" else "account.php#meetings-h")) null else "No browser on this phone can open the website."
    })
    ErrorText(error)
}

/** R6: my own hours for self-booking, as the website's My availability. */
@Composable
fun AvailabilityScreen(api: Api) {
    val state = rememberLoaded(api.userId) {
        try { api.call("availability") } catch (e: ApiException) { throw ApiException(unknownActionText(e.message, AVAILABILITY_NOT_OFFERED) ?: "", e.status, e.network, e.reply) }
    }
    var hours by remember { mutableStateOf<List<JSONObject>?>(null) }
    var day by rememberSaveable { mutableStateOf("1") }
    // The website's defaults; kept after adding, so the next block starts from them.
    var from by rememberSaveable { mutableStateOf("09:00") }
    var to by rememberSaveable { mutableStateOf("12:00") }
    var mode by rememberSaveable { mutableStateOf("online") }
    var where by rememberSaveable { mutableStateOf("") }
    var idem by rememberSaveable { mutableStateOf("") }
    var removing by rememberSaveable { mutableIntStateOf(0) }
    var notice by remember { mutableStateOf<String?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    var errors by remember { mutableStateOf<Map<String, String>>(emptyMap()) }
    var busy by remember { mutableStateOf(false) }
    var presses by remember { mutableIntStateOf(0) }
    val scope = rememberCoroutineScope()
    HoldBackWhileSaving(busy)
    Page {
        Show(state, "your availability") { r ->
            val list = hours ?: r.optJSONArray("hours").objects()
            val days = r.optJSONArray("days").objects().mapNotNull { o -> o.str("value")?.let { it to o.optString("label") } }.ifEmpty { AVAILABILITY_DAYS }
            r.str("intro")?.let { Text(it) }
            NoticeText(notice)
            Heading("Hours (${list.size})")
            if (list.isEmpty()) Text("None yet.")
            list.forEach { h ->
                val text = h.optString("text")
                ListRow(listOf(text))
                SecondaryButton("Remove $text") { removing = h.optInt("id") }
            }
            Heading("Add hours")
            Choice("Day", day, days, error = errors["weekday"], required = true) { day = it }
            Field("From", from, { from = it }, hint = "hour:minute, such as 09:00", required = true, error = errors["from"])
            Field("To", to, { to = it }, hint = "hour:minute, such as 12:00", required = true, error = errors["to"])
            Choice("How", mode, listOf("online" to "Online", "in_person" to "In person")) { mode = it }
            if (mode == "in_person") Field("Where, for in person", where, { where = it }, required = true, error = errors["location"])
            ErrorText(error, focusKey = presses)
            PrimaryButton("Add these hours", busy = busy) {
                if (busy) return@PrimaryButton
                presses++
                errors = availabilityLocalErrors(day, from, to, mode == "in_person", where)
                if (errors.isNotEmpty()) { error = errorSummary(errors.values); return@PrimaryButton }
                busy = true; error = null; notice = null
                scope.launch {
                    try {
                        val res = api.callOnce("add_availability", availabilityBody(day, from, to, mode == "in_person", where), idem, { idem = it }, "the hours")
                        notice = res.optString("message").ifEmpty { "Hours added." }
                        res.optJSONArray("hours")?.let { hours = it.objects() } ?: state.reload()
                    } catch (e: ApiException) {
                        errors = replyErrors(e.reply)
                        error = errorSummary(errors.values) ?: e.message
                    } finally { busy = false }
                }
            }
            list.firstOrNull { removing > 0 && it.optInt("id") == removing }?.let { h ->
                val text = h.optString("text")
                Confirm("Remove $text?", "Sessions already booked in them stay booked.", "Remove", {
                    scope.launch {
                        error = null; notice = null
                        try {
                            val res = api.call("remove_availability", body = JSONObject().put("id", h.optInt("id")))
                            notice = res.optString("message").ifEmpty { "Hours removed. Sessions already booked in them stay booked." }
                            res.optJSONArray("hours")?.let { hours = it.objects() } ?: state.reload()
                        } catch (e: ApiException) { error = e.message; hours = null; state.reload() }
                    }
                }, { removing = 0 }, no = "Keep them")
            }
        }
    }
}

/** R2: report a possible privacy breach; an administrator assesses it. [kind0] and [what0] come from a phone reported lost. */
@Composable
fun PrivacyIncidentScreen(api: Api, nav: NavHostController, kind0: String, what0: String) {
    val state = rememberLoaded(api.userId) {
        try { api.call("privacy_incident_choices") } catch (e: ApiException) { throw ApiException(unknownActionText(e.message, PRIVACY_NOT_OFFERED) ?: "", e.status, e.network, e.reply) }
    }
    var kind by rememberSaveable { mutableStateOf(kind0) }
    var discovered by rememberSaveable { mutableStateOf(today()) }
    var occurred by rememberSaveable { mutableStateOf("") }
    var client by rememberSaveable { mutableStateOf("0") }
    var description by rememberSaveable { mutableStateOf(what0) }
    var info by rememberSaveable { mutableStateOf("") }
    var idem by rememberSaveable { mutableStateOf("") }
    var error by remember { mutableStateOf<String?>(null) }
    var errors by remember { mutableStateOf<Map<String, String>>(emptyMap()) }
    var busy by remember { mutableStateOf(false) }
    var presses by remember { mutableIntStateOf(0) }
    var askDiscard by remember { mutableStateOf(false) }
    val scope = rememberCoroutineScope()
    HoldBackWhileSaving(busy)
    // Back with something typed asks first (what came filled in from a lost phone does not count).
    val typed = kind != kind0 || discovered != today() || occurred.isNotBlank() || client != "0" || description != what0 || info.isNotBlank()
    androidx.activity.compose.BackHandler(enabled = typed && !busy) { askDiscard = true }
    if (askDiscard) Confirm(PRIVACY_DISCARD_TITLE, PRIVACY_DISCARD_TEXT, "Discard", { nav.popBackStack() }, { askDiscard = false }, no = "Keep editing")
    Page {
        Show(state, "the privacy incident form") { r ->
            val kinds = r.optJSONArray("kinds").let { a -> List(a?.length() ?: 0) { a!!.optString(it) } }.filter { it.isNotBlank() }
            val clients = r.optJSONArray("clients").objects().map { it.optInt("id").toString() to it.optString("name") }
            val todayIs = r.str("today") ?: today()
            Text(PRIVACY_INTRO)
            Choice("Kind", kind, listOf("" to "Choose a kind") + kinds.map { it to it }, error = errors["kind"], required = true) { kind = it }
            Field("Date discovered", discovered, { discovered = it }, hint = "year-month-day", required = true, error = errors["discovered_on"])
            Field("Date it happened, if known", occurred, { occurred = it }, hint = "year-month-day", error = errors["occurred_on"])
            Choice("Client affected, if one", client, listOf("0" to "None") + clients, error = errors["client_id"]) { client = it }
            Field("What happened", description, { description = it }, singleLine = false, minLines = 3, required = true, error = errors["description"])
            Field("Information involved", info, { info = it }, error = errors["info_involved"])
            ErrorText(error, focusKey = presses)
            PrimaryButton("Report incident", busy = busy) {
                if (busy) return@PrimaryButton
                presses++
                errors = privacyLocalErrors(kind, discovered, occurred, description, info, todayIs)
                if (errors.isNotEmpty()) { error = errorSummary(errors.values); return@PrimaryButton }
                busy = true; error = null
                scope.launch {
                    try {
                        val res = api.callOnce("report_privacy_incident", privacyBody(kind, discovered, occurred, client.toIntOrNull() ?: 0, description, info), idem, { idem = it }, "the privacy incident")
                        Flash.pending = res.optString("message").ifEmpty { "Privacy incident reported. An administrator has been told." }
                        PrivacyReports.filed++
                        nav.popBackStack()
                    } catch (e: ApiException) {
                        errors = replyErrors(e.reply)
                        error = errorSummary(errors.values) ?: e.message
                    } finally { busy = false }
                }
            }
        }
    }
}

/** R15: complete several of the open tasks shown on Tasks ([q] and [due] as filtered there), as the website's form. */
@Composable
fun BulkCompleteScreen(api: Api, nav: NavHostController, q: String = "", due: String = "any") {
    val state = rememberLoaded(api.userId, q, due) { parseTasks(api.call("tasks", tasksQuery("open", q, due))).filter { it.open } }
    var chosen by rememberSaveable { mutableStateOf<List<Int>>(emptyList()) }
    var idem by rememberSaveable { mutableStateOf("") }
    var error by remember { mutableStateOf<String?>(null) }
    var busy by remember { mutableStateOf(false) }
    var presses by remember { mutableIntStateOf(0) }
    val scope = rememberCoroutineScope()
    HoldBackWhileSaving(busy)
    Page {
        Show(state, "open tasks") { list ->
            Heading("Open tasks")
            if (list.isEmpty()) Text(BULK_EMPTY)
            list.forEach { t ->
                Tick(bulkLabel(t), t.id in chosen) { on -> chosen = if (on) chosen + t.id else chosen - t.id }
            }
            ErrorText(error, focusKey = presses)
            val picked = chosen.filter { id -> list.any { it.id == id } }
            PrimaryButton(bulkButton(picked.size), busy = busy) {
                if (busy) return@PrimaryButton
                presses++
                if (picked.isEmpty()) { error = BULK_NONE; return@PrimaryButton }
                busy = true; error = null
                scope.launch {
                    try {
                        val r = api.callOnce("complete_tasks", bulkBody(picked), idem, { idem = it }, "completing the tasks")
                        Flash.pending = r.optString("message").ifEmpty { "${picked.size} tasks completed." }
                        nav.popBackStack()
                    } catch (e: ApiException) {
                        error = e.message
                        // Nothing was completed (done meanwhile, or no longer visible): the list and the choices are read again.
                        if (e.status == 422) { chosen = emptyList(); state.reload() }
                    } finally { busy = false }
                }
            }
        }
    }
}

/** R14: add or edit a task, with the website's fields in its order. [r] is the tasks reply (who may assign, the staff). */
@Composable
fun TaskFormDialog(api: Api, r: JSONObject, existing: TaskItem?, onDone: (String) -> Unit, onCancel: () -> Unit) {
    val canAssign = r.optBoolean("can_assign")
    val canLink = r.optBoolean("can_link_client")
    var title by rememberSaveable { mutableStateOf(existing?.title ?: "") }
    var due by rememberSaveable { mutableStateOf(existing?.dueDate ?: "") }
    var details by rememberSaveable { mutableStateOf(existing?.details ?: "") }
    var client by rememberSaveable { mutableStateOf((existing?.clientId ?: 0).toString()) }
    var assign by rememberSaveable { mutableStateOf((if (existing != null) existing.assignedTo ?: 0 else api.userId).toString()) }
    var idem by rememberSaveable { mutableStateOf("") }
    var error by remember { mutableStateOf<String?>(null) }
    var fieldErrors by remember { mutableStateOf<Map<String, String>>(emptyMap()) }
    var busy by remember { mutableStateOf(false) }
    var presses by remember { mutableIntStateOf(0) }
    val scope = rememberCoroutineScope()
    val clients = rememberLoaded(api.userId, canLink) { if (canLink) try { parseClients(api.call("client_choices")).filter { !it.closed } } catch (e: ApiException) { emptyList() } else emptyList() }
    val clientOptions = listOf("0" to "None") + (clients.data ?: emptyList()).map { it.id.toString() to it.name } +
        (existing?.let { t -> t.clientId?.takeIf { id -> clients.data?.none { it.id == id } != false }?.let { listOf(it.toString() to (t.clientName ?: "This client")) } } ?: emptyList())
    val staff = listOf("0" to "Anyone") + r.optJSONArray("staff").objects().map { it.optInt("id").toString() to it.optString("name") }
    AlertDialog(
        onDismissRequest = { if (!busy) onCancel() },
        title = { Text(if (existing == null) "Add a task" else "Edit task", modifier = Modifier.semantics { heading() }) },
        text = {
            Column(Modifier.verticalScroll(rememberScrollState())) {
                Field("What needs doing?", title, { title = it }, required = true, error = fieldErrors["title"])
                Field("Due date", due, { due = it }, hint = "optional, year-month-day such as ${today()}", error = fieldErrors["due"])
                if (canLink) Choice("Client (optional)", client, clientOptions, error = fieldErrors["client"]) { client = it }
                if (canAssign) Choice("Assign to", assign, staff, error = fieldErrors["assign"]) { assign = it }
                Field("Details", details, { details = it }, hint = "optional", singleLine = false, minLines = 3)
                ErrorText(error, focusKey = presses)
            }
        },
        confirmButton = {
            TextButton(enabled = !busy, onClick = {
                presses++
                fieldErrors = listOfNotNull(
                    if (title.isBlank()) "title" to "The task needs a title: say what needs doing." else null,
                    if (due.isNotBlank() && !Regex("^\\d{4}-\\d{2}-\\d{2}$").matches(due.trim())) "due" to "Enter the due date as year-month-day, such as ${today()}, or leave it empty." else null,
                ).toMap()
                errorSummary(fieldErrors.values)?.let { error = it; return@TextButton }
                scope.launchSave({ busy = it }, { error = it }) {
                    val body = taskBody(existing?.id ?: 0, title, due, details, client.toIntOrNull() ?: 0, assign.toIntOrNull() ?: 0, canAssign, canLink)
                    val res = try {
                        if (existing != null) api.call("save_task", body = body) else api.callOnce("save_task", body, idem, { idem = it }, "the task")
                    } catch (e: ApiException) {
                        taskFieldFor(e.message)?.let { f -> fieldErrors = mapOf(f to (e.message ?: "")) }
                        throw e
                    }
                    onDone(res.optString("message").ifEmpty { if (existing == null) "Task added." else "Task saved." })
                }
            }) { Text(if (busy) "Saving…" else if (existing == null) "Add task" else "Save task") }
        },
        dismissButton = { TextButton(enabled = !busy, onClick = onCancel) { Text("Cancel") } },
    )
}
