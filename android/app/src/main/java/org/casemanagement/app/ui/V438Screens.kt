package org.casemanagement.app.ui

import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.text.selection.SelectionContainer
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.key
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.navigation.NavHostController
import org.casemanagement.app.Api
import org.casemanagement.app.ApiException
import org.casemanagement.app.InvoiceRow
import org.casemanagement.app.dialogKey
import org.casemanagement.app.idsFromText
import org.casemanagement.app.idsToText
import org.casemanagement.app.objects
import org.casemanagement.app.parseInvoices
import org.casemanagement.app.str
import org.casemanagement.app.strings
import org.json.JSONObject

/*
 * 4.38.0 screens: invoices (filters, paging, the owed sentences, several drafts submitted, the
 * invoice itself), incoming authorization emails, service profitability, data quality and the
 * documentation audit, as the website and the iPhone app. Every dialog is the app's AlertDialog
 * (LockAware.kt); typed text survives rotation; a create or send goes once (callOnce), and a
 * second press after it went finds the dialog or button gone.
 */

/** A smaller heading inside a dialog or a section (still a heading for TalkBack). */
@Composable
private fun SubHeading(text: String) {
    Text(text, fontWeight = FontWeight.SemiBold, modifier = Modifier.padding(top = 8.dp).semantics { heading() })
}

/* ---------------- T1-T5: invoices ---------------- */

/** The invoice as the website's row data, for the one-invoice submit dialog (4.34.0). */
private fun InvoiceRow.asJson(): JSONObject = JSONObject().put("id", id).put("invoice_number", number).put("created_at", createdAt)

/** Invoices: the website's filters, the owed sentences, what each agency owes, paging, and drafts marked submitted. */
@Composable
fun InvoicesScreen(api: Api, nav: NavHostController, invoiceId: Int = 0) {
    // One invoice (from search), until "Show all invoices".
    var single by rememberSaveable { mutableIntStateOf(invoiceId) }
    // What is typed, and what was applied (the list follows the applied filters, as the website's Apply).
    var status by rememberSaveable { mutableStateOf("") }
    var agency by rememberSaveable { mutableStateOf("") }
    var month by rememberSaveable { mutableStateOf("") }
    var q by rememberSaveable { mutableStateOf("") }
    var applied by rememberSaveable(stateSaver = StringMapSaver) { mutableStateOf(emptyMap<String, String>()) }
    var page by rememberSaveable { mutableIntStateOf(1) }
    val filters = InvoiceFilters(applied["status"] ?: "", applied["agency"] ?: "", applied["month"] ?: "", applied["q"] ?: "")
    val state = rememberLoaded(applied, page, single) { parseInvoices(api.call("invoices", invoicesQuery(filters, page, single))) }
    var chosenId by rememberSaveable { mutableIntStateOf(0) }
    var submitId by rememberSaveable { mutableIntStateOf(0) }
    var bulk by rememberSaveable { mutableStateOf(false) }
    var notice by remember { mutableStateOf<String?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    fun apply(f: InvoiceFilters) {
        applied = mapOf("status" to f.status, "agency" to f.agency.trim(), "month" to f.month.trim(), "q" to f.q.trim())
        page = 1; notice = null; error = null
    }
    // A 4.37.0 server sends no heading: it has no filters, several-at-once or invoice document, so they are not offered.
    val modern = state.data?.heading != null
    Page {
        if (single > 0) {
            state.data?.let { d ->
                // An older server ignores id and sends the whole list: never call that "one invoice".
                val one = modern && d.requested && d.found != false && d.rows.size == 1 && d.rows[0].id == single
                Text(if (one) INVOICE_ONE else INVOICE_NOT_FOUND)
            }
            SecondaryButton("Show all invoices") { single = 0; notice = null; error = null }
        } else if (modern) {
            Choice("Show", status, invoiceStatusChoices()) { status = it }
            Choice("Agency", agency, agencyChoices(state.data?.agencies ?: emptyList(), agency)) { agency = it }
            Field("Invoice month", month, { month = it }, hint = "year and month it was created, as YYYY-MM")
            Field("Invoice number or client", q, { q = it })
            PrimaryButton("Apply filters") { apply(InvoiceFilters(status, agency, month, q)) }
            if (filters.any || status.isNotEmpty() || agency.isNotEmpty() || month.isNotBlank() || q.isNotBlank()) SecondaryButton("Clear filters") {
                status = ""; agency = ""; month = ""; q = ""; apply(InvoiceFilters())
            }
        }
        Text(INVOICE_PAYMENTS_NOTE)
        NoticeText(notice)
        ErrorText(error)
        Show(state, "invoices") { d ->
            val rowsShown = if (single > 0) d.rows.filter { it.id == single } else d.rows
            if (single == 0) {
                d.unbilled?.let { ListRow(listOf("Unbilled work: " + money(it))) }
                Heading(OUTSTANDING_HEADING)
                if (d.outstanding.isEmpty()) Text(NOTHING_OUTSTANDING)
                d.outstanding.forEach { a -> ListRow(listOf(agingLine(a))) }
                Heading(d.heading ?: "Invoices")
                if (d.pages > 1) Text(invoicePagingCaption(d.page, d.pages, d.total))
                if (d.rows.isEmpty()) Text("No invoices.")
                if (modern && firstDrafts(d.rows).size > 1) SecondaryButton("Mark several drafts submitted") { bulk = true }
            }
            rowsShown.forEach { i -> ListRow(invoiceRowLines(i), action = "choose what to do with it", onClick = { chosenId = i.id }) }
            // Separate stops after the list, as the website's links.
            if (single == 0 && d.page > 1) SecondaryButton("Newer invoices") { page = d.page - 1; notice = null; error = null }
            if (single == 0 && d.page < d.pages) SecondaryButton("Older invoices") { page = d.page + 1; notice = null; error = null }
        }
    }
    val rows = state.data?.rows ?: emptyList()
    rows.firstOrNull { chosenId != 0 && it.id == chosenId }?.let { i ->
        AlertDialog(onDismissRequest = { chosenId = 0 }, title = { Text("${i.number}: ${i.clientName}", modifier = Modifier.semantics { heading() }) },
            text = {
                Column(Modifier.verticalScroll(rememberScrollState())) {
                    if (modern) TextButton(onClick = { chosenId = 0; nav.navigate("invoiceDocument/${i.id}") }) { Text(openInvoiceLabel(i.number)) }
                    if (i.status == "Draft") TextButton(onClick = { chosenId = 0; submitId = i.id }) { Text("Mark submitted") }
                    if (i.canOpenClient && api.can("clients")) TextButton(onClick = { chosenId = 0; nav.navigate("client/${i.clientId}") }) { Text("Open the client record") }
                }
            },
            confirmButton = { TextButton(onClick = { chosenId = 0 }) { Text("Close") } })
    }
    rows.firstOrNull { submitId != 0 && it.id == submitId }?.let { i ->
        key(dialogKey("submit", i.id)) {
            SubmitInvoiceDialog(api, i.asJson(), onDone = { m -> submitId = 0; error = null; notice = m; state.reload() },
                onRefused = { m -> submitId = 0; notice = null; error = m; state.reload() }, onCancel = { submitId = 0 })
        }
    }
    if (bulk) {
        val drafts = firstDrafts(rows)
        BulkSubmitDialog(api, drafts, onDone = { m -> bulk = false; error = null; notice = m; state.reload() },
            onRefused = { m -> bulk = false; notice = null; error = m; state.reload() }, onCancel = { bulk = false })
    }
}

/** T4: several drafts marked submitted, with the website's bulk_submit rules, after a confirmation naming the count and the date. */
@Composable
private fun BulkSubmitDialog(api: Api, drafts: List<InvoiceRow>, onDone: (String) -> Unit, onRefused: (String) -> Unit, onCancel: () -> Unit) {
    var chosen by rememberSaveable { mutableStateOf("") }
    var date by rememberSaveable { mutableStateOf("") }
    var asking by rememberSaveable { mutableStateOf(false) }
    var idem by rememberSaveable { mutableStateOf("") }
    var errors by remember { mutableStateOf<Map<String, String>>(emptyMap()) }
    var error by remember { mutableStateOf<String?>(null) }
    var busy by remember { mutableStateOf(false) }
    val scope = rememberCoroutineScope()
    val shownIds = drafts.map { it.id }.toSet()
    val ids = idsFromText(chosen).filter { it in shownIds }.toSet()
    FormDialog("Mark several drafts submitted", "Mark the chosen invoices submitted", busy, onSave = {
        if (busy) return@FormDialog
        errors = listOfNotNull(
            if (ids.isEmpty()) "ids" to BULK_NONE_TICKED else null,
            if (date.isNotBlank() && !realDayNotFuture(date, today())) "date" to BULK_BAD_DATE else null,
        ).toMap()
        error = errorSummary(errors.values)
        if (error == null) asking = true
    }, onCancel = onCancel) {
        SubHeading("Draft invoices")
        SecondaryButton("Choose all shown drafts") { chosen = idsToText(shownIds); errors = errors - "ids" }
        SecondaryButton("Choose none") { chosen = "" }
        drafts.forEachIndexed { n, d ->
            Tick(draftTickLabel(d), d.id in ids, error = if (n == 0) errors["ids"] else null) { on ->
                chosen = idsToText(if (on) ids + d.id else ids - d.id); errors = errors - "ids"
            }
        }
        Field("Date they were sent", date, { date = it; errors = errors - "date" }, hint = "year-month-day; empty for today", error = errors["date"])
        ErrorText(error)
    }
    if (asking) Confirm(bulkConfirmTitle(ids.size, date.trim().ifEmpty { today() }), BULK_CONFIRM_TEXT, "Mark them submitted", onYes = {
        scope.launchSave({ busy = it }, { error = it }) {
            try {
                val r = api.callOnce("submit_invoices", bulkSubmitBody(ids, date), idem, { idem = it }, "marking the invoices submitted")
                onDone(r.optString("message").ifEmpty { "${countWords(r.optInt("changed"), "invoice")} marked submitted." })
            } catch (e: ApiException) {
                val mapped = replyErrors(e.reply).mapNotNull { (k, v) -> bulkFieldFor(k)?.let { it to v } }.toMap()
                if (mapped.isNotEmpty()) errors = mapped
                // Changed meanwhile (another person submitted one): the list is read again.
                if (e.status == 409) { onRefused(e.message ?: "The invoices changed meanwhile."); return@launchSave }
                throw e
            }
        }
    }, onDismiss = { asking = false }, no = "Keep them as drafts")
}

/** T5: the invoice as the website's accessible document: its text, shared as the HTML file, and copied. */
@Composable
fun InvoiceDocumentScreen(api: Api, id: Int) {
    val state = rememberLoaded(id) { api.call("invoice_document", invoiceDocumentQuery(id)) }
    Show(state, "the invoice") { r ->
        Page { ServerDocument(api, r.str("title") ?: "Invoice", r, "invoice-$id", "Share the invoice (HTML file)", "Copy invoice", copied = "Invoice copied.") }
    }
}

/* ---------------- T6: incoming authorization emails ---------------- */

/**
 * The track form's prefill from start_authorization_track, by the email's id, handed to the track
 * form once (the form then keeps it across rotation and process death itself).
 */
object AuthPrefill {
    private val kept = mutableMapOf<Int, String>()
    fun put(id: Int, json: String) { synchronized(kept) { kept[id] = json } }
    fun take(id: Int): String? = synchronized(kept) { kept.remove(id) }
}

@Composable
fun AuthorizationsScreen(api: Api, nav: NavHostController) {
    val state = rememberLoaded(api.userId) { api.call("incoming_authorizations") }
    var text by rememberSaveable { mutableStateOf("") }
    var idem by rememberSaveable { mutableStateOf("") }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    var fieldError by remember { mutableStateOf<String?>(null) }
    var notice by remember { mutableStateOf<String?>(null) }
    var presses by remember { mutableIntStateOf(0) }
    val scope = rememberCoroutineScope()
    HoldBackWhileSaving(busy)
    Page {
        Text(AUTH_INTRO)
        NoticeText(notice)
        Show(state, "authorization emails") { r ->
            val rows = r.optJSONArray("rows").objects()
            Heading("Waiting (${r.optInt("total", rows.size)})")
            if (rows.isEmpty()) Text(AUTH_NONE_WAITING)
            rows.forEach { a -> ListRow(listOf(a.optString("text")), action = "review it", onClick = { nav.navigate("authorization/${a.optInt("id")}") }) }
        }
        Heading("Paste an authorization email")
        Field("The authorization email, including the line Authorization Number", text, { text = it; fieldError = null }, singleLine = false, minLines = 6,
            required = true, error = fieldError, enabled = !busy)
        ErrorText(error, focusKey = presses)
        PrimaryButton("Read the email", busy = busy, busyText = "Reading…", announceBusy = true) {
            if (busy) return@PrimaryButton
            presses++
            if (text.isBlank()) { fieldError = AUTH_EMPTY_PASTE; error = AUTH_EMPTY_PASTE; notice = null; return@PrimaryButton }
            scope.launchSave({ busy = it }, { error = it; if (it != null) notice = null }) {
                try {
                    val r = api.callOnce("read_authorization_email", JSONObject().put("email_text", text), idem, { idem = it }, "the authorization email")
                    // Read: the text goes, so a second press cannot read it again.
                    text = ""; error = null
                    val id = if (r.isNull("id")) 0 else r.optInt("id")
                    if (id > 0) { r.str("message")?.let { Flash.pending = it }; nav.navigate("authorization/$id") }
                    else { notice = r.str("message"); state.reload() }
                } catch (e: ApiException) {
                    replyErrors(e.reply)["email_text"]?.let { fieldError = it }
                    throw e
                }
            }
        }
    }
}

@Composable
fun AuthorizationReviewScreen(api: Api, nav: NavHostController, id: Int) {
    val state = rememberLoaded(id) { api.call("incoming_authorization", mapOf("id" to id.toString())) }
    Show(state, "the authorization email") { r -> AuthorizationReview(api, nav, id, r) { state.reload() } }
}

@Composable
private fun AuthorizationReview(api: Api, nav: NavHostController, id: Int, r: JSONObject, reload: () -> Unit) {
    val number = r.optString("auth_number")
    val matches = r.optJSONArray("matches").objects()
    val others = r.optJSONArray("other_clients").objects()
    val preset = r.optInt("preset_client_id")
    val presetMatched = preset > 0 && matches.any { it.optInt("id") == preset }
    val counselor = r.optJSONObject("counselor")
    var choice by rememberSaveable(id) { mutableStateOf(if (presetMatched) preset.toString() else "0") }
    var other by rememberSaveable(id) { mutableStateOf(if (preset > 0 && !presetMatched) preset.toString() else "0") }
    var setCounselor by rememberSaveable(id) { mutableStateOf(counselor?.optBoolean("preticked") == true) }
    var showEmail by rememberSaveable { mutableStateOf(false) }
    var asking by rememberSaveable { mutableStateOf(false) }
    var startKey by rememberSaveable { mutableStateOf("") }
    var dismissKey by rememberSaveable { mutableStateOf("") }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    var clientError by remember { mutableStateOf<String?>(null) }
    var presses by remember { mutableIntStateOf(0) }
    val scope = rememberCoroutineScope()
    val open = r.optString("status").ifEmpty { "New" } == "New"
    HoldBackWhileSaving(busy)
    Page {
        Heading("Authorization $number", large = true)
        r.str("status_text")?.let { Text(it) }
        if (r.optBoolean("not_approved")) Text(AUTH_NOT_APPROVED)
        r.optJSONArray("already").strings().forEach { Text(it) }
        ErrorText(error, focusKey = presses)
        val details = r.optJSONArray("details").objects()
        details.forEach { d -> ListRow(listOf(d.optString("label") + ": " + d.optString("value"))) }
        if (open) {
            if (matches.isEmpty()) {
                val name = details.firstOrNull { it.optString("label") == "Client name in the email" }?.str("value")?.takeIf { it != "not given" }
                if (name != null) Text("No client record is named $name. Add the client first, or choose another client below.")
            }
            Choice("Which client is this for?", choice, authReviewClientChoices(matches), error = if (choice != "0") clientError else null, required = true) {
                choice = it; clientError = null
            }
            if (choice == "0") Choice("Another client", other, listOf("0" to "None") + others.map { it.optInt("id").toString() to it.optString("name") }, error = clientError) {
                other = it; clientError = null
            }
            counselor?.let { c -> Tick(authCounselorLabel(c.optString("text"), c.optBoolean("confirmed")), setCounselor) { setCounselor = it } }
            PrimaryButton("Start the track from this authorization", busy = busy, busyText = "Starting…", announceBusy = true) {
                if (busy) return@PrimaryButton
                presses++
                val body = startAuthBody(id, choice, other, setCounselor && counselor != null)
                if (body.optInt("client_id") <= 0) { clientError = "Choose the client this authorization is for."; error = clientError; return@PrimaryButton }
                scope.launchSave({ busy = it }, { error = it }) {
                    try {
                        val s = api.callOnce("start_authorization_track", body, startKey, { startKey = it }, "starting the track")
                        AuthPrefill.put(id, JSONObject().put("prefill", s.optJSONObject("prefill") ?: JSONObject()).put("heading", s.str("heading") ?: JSONObject.NULL).toString())
                        // The counselor notices are said once, on the track form.
                        s.optJSONArray("notices").strings().takeIf { it.isNotEmpty() }?.let { Flash.pending = it.joinToString(" ") }
                        nav.navigate("trackEdit/${s.optInt("client_id", body.optInt("client_id"))}/0?auth=$id")
                    } catch (e: ApiException) {
                        replyErrors(e.reply)["client_id"]?.let { clientError = it }
                        // Already handled elsewhere: read again, so it shows as it is now.
                        if (e.status == 409) reload()
                        throw e
                    }
                }
            }
            SecondaryButton("Dismiss", enabled = !busy) { asking = true }
        }
        SecondaryButton(if (showEmail) "Hide the email as received" else "The email as received") { showEmail = !showEmail }
        if (showEmail) SelectionContainer { Text(r.optString("email_text")) }
    }
    if (asking) Confirm(dismissAuthTitle(number), "", "Dismiss", onYes = {
        scope.launchSave({ busy = it }, { error = it }) {
            try {
                val d = api.callOnce("dismiss_authorization", JSONObject().put("id", id), dismissKey, { dismissKey = it }, "dismissing the authorization email")
                Flash.pending = d.optString("message").ifEmpty { "Authorization $number dismissed." }
                // Back to the list (also when the review was opened from an alert), where the message shows.
                if (!nav.popBackStack("authorizations", inclusive = false)) { nav.popBackStack(); nav.navigate("authorizations") }
            } catch (e: ApiException) {
                presses++
                if (e.status == 409) reload()
                throw e
            }
        }
    }, onDismiss = { asking = false }, no = "Keep it waiting")
}

/* ---------------- T14: service profitability ---------------- */

const val PROFIT_INTRO = "Submitted and paid invoice totals minus recorded expenses and labor cost. Missing labor rates make the margin incomplete. This excludes unrecorded overhead and unbilled revenue."
const val PROFIT_FOOTER = "Tracks linked to multiple contracts are grouped as unallocated rather than counted against each contract."

@Composable
fun ProfitabilityScreen(api: Api) {
    val state = rememberLoaded(api.userId) { api.call("at_profitability") }
    var group by rememberSaveable { mutableStateOf("track") }
    Show(state, "service profitability") { r ->
        Page {
            Text(r.str("intro") ?: PROFIT_INTRO)
            // Group totals are the server's (the website's at_profit_groups); an older server sends none, so the tracks alone are shown.
            val grouped = r.has("groups")
            if (grouped) Choice("Group by", group, profitGroupChoices()) { group = it }
            val by = if (grouped) group else "track"
            val rows = if (by == "track") profitTrackRows(r) else profitGroups(r, by) ?: emptyList()
            Heading(when (by) { "service" -> "By service"; "contract" -> "By linked contract"; else -> "Profitability by training track" })
            if (rows.isEmpty()) Text(PROFIT_EMPTY)
            rows.forEach { ListRow(listOf(profitRowText(it))) }
            if (by == "contract") Text(r.str("footer") ?: PROFIT_FOOTER)
        }
    }
}

/* ---------------- T15: data quality and the documentation audit ---------------- */

@Composable
fun DataQualityScreen(api: Api, nav: NavHostController) {
    val state = rememberLoaded(api.userId) { api.call("data_quality") }
    Show(state, "data quality") { r ->
        Page {
            r.str("summary")?.let { Text(it) }
            r.optJSONArray("sections").objects().forEach { s ->
                val rows = s.optJSONArray("rows").objects()
                Heading(sectionHeading(s.optString("heading"), rows.size))
                s.str("explanation")?.let { Text(it) }
                if (rows.isEmpty()) Text("None.")
                rows.forEach { row ->
                    val cid = row.optInt("client_id")
                    ListRow(listOf(dataQualityRow(row)), action = "open the client", onClick = if (cid > 0 && api.can("clients")) ({ nav.navigate("client/$cid") }) else null)
                }
            }
        }
    }
}

@Composable
fun DocAuditScreen(api: Api, nav: NavHostController) {
    val state = rememberLoaded(api.userId) { api.call("doc_audit") }
    Show(state, "the documentation audit") { r ->
        Page {
            r.str("intro")?.let { Text(it) }
            ListRow(listOf("Active clients: ${r.optInt("active")}"))
            ListRow(listOf("With gaps: ${r.optInt("with_gaps")}"))
            ListRow(listOf("Total findings: ${r.optInt("total_findings")}"))
            val rows = r.optJSONArray("rows").objects()
            if (rows.isEmpty()) Text(r.str("empty") ?: DOC_AUDIT_EMPTY)
            rows.forEach { row ->
                val cid = row.optInt("client_id")
                ListRow(docAuditRow(row), action = "open the client", onClick = if (cid > 0) ({ nav.navigate("client/$cid") }) else null)
            }
            val checks = r.optJSONArray("checks").strings()
            if (checks.isNotEmpty()) {
                Heading("What this checks")
                checks.forEach { Text("• $it", modifier = Modifier.padding(vertical = 2.dp)) }
            }
            r.str("footnote")?.let { Text(it, modifier = Modifier.padding(top = 8.dp)) }
        }
    }
}
