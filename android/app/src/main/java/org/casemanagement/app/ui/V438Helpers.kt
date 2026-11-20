package org.casemanagement.app.ui

import org.casemanagement.app.InvoiceAging
import org.casemanagement.app.InvoiceRow
import org.casemanagement.app.objects
import org.casemanagement.app.str
import org.casemanagement.app.strings
import org.json.JSONArray
import org.json.JSONObject

/*
 * 4.38.0: the pure parts of the release's screens (billing and administration): request bodies,
 * wording, error mapping and the rows' sentences, kept apart from Compose so V438Test checks them
 * against the server's replies. Money is only formatted here ([money]), never added up or rounded:
 * every amount and total is the server's, computed as the website page computes it.
 */

/** "1 invoice", "2 invoices". */
fun countWords(n: Int, one: String, many: String = one + "s"): String = "$n ${if (n == 1) one else many}"

/* ---------------- T1: the invoice list ---------------- */

/** Hours as the website's fmt_hours says them: "1 hour", "12.5 hours". */
fun hoursWords(h: Double): String = hoursText(h) + if (h == 1.0) " hour" else " hours"

/**
 * An invoice row's lines, as the website's row cells in order: the client cell ("{number}: {client}",
 * then "{track} · Authorization {auth or none}"), the amount, the status (the server's owed sentence,
 * else the status and a closed reason) and the dates (the server's lines; built from the dates for
 * a 4.37.0 server).
 */
fun invoiceRowLines(i: InvoiceRow): List<String> {
    val status = i.owedText?.takeIf { it.isNotBlank() }
        ?: (i.status + if ((i.status == "Denied" || i.status == "Void") && !i.closedReason.isNullOrBlank()) ": ${i.closedReason}" else "")
    val dates = i.dateLines.ifEmpty {
        listOfNotNull(
            i.createdAt.takeIf { it.isNotBlank() }?.let { "Created " + shortDate(it) },
            i.submittedAt?.let { "Submitted " + shortDate(it) },
            i.dueDate?.takeIf { i.status == "Submitted" || i.status == "Paid" }?.let { "Due " + shortDate(it) },
            i.paidAt?.let { "Last payment " + shortDate(it) },
        )
    }
    return listOf(
        "${i.number}: ${i.clientName}",
        "${i.trackTitle} · Authorization ${i.authNumber?.takeIf { it.isNotBlank() } ?: "none"}",
        money(i.totalAmount) + ", " + hoursWords(i.hours),
        status,
        dates.joinToString(", "),
    )
}

/** "Invoices (page 2 of 3, 650 in all)", or "Invoices (12)" when they fit on one page. */
fun invoicePagingCaption(page: Int, pages: Int, total: Int): String =
    if (pages > 1) "Invoices (page $page of $pages, $total in all)" else "Invoices ($total)"

/** The status filter, as the website's "Show" (the status names, never "Drafts"). */
fun invoiceStatusChoices(): List<Pair<String, String>> =
    listOf("" to "All invoices", "Draft" to "Draft", "Submitted" to "Submitted", "Paid" to "Paid", "Denied" to "Denied", "Void" to "Void")

const val INVOICE_PAYMENTS_NOTE = "A draft can be marked submitted here. Recording payments and write-offs, and closing denied invoices, are on the website."
const val INVOICE_ONE = "Showing one invoice."
const val INVOICE_NOT_FOUND = "That invoice was not found, or it is not on your caseload."

/* ---------------- T2: filters and search ---------------- */

/** The list's filters, as the website's form (status, agency, invoice month, number or client). */
data class InvoiceFilters(val status: String = "", val agency: String = "", val month: String = "", val q: String = "") {
    val any: Boolean get() = status.isNotBlank() || agency.isNotBlank() || month.isNotBlank() || q.isNotBlank()
}

/** The `invoices` query: blank filters left out; [id] (one invoice) sends nothing else, as the website's ?id=. */
fun invoicesQuery(f: InvoiceFilters, page: Int, id: Int = 0): Map<String, String> {
    if (id > 0) return mapOf("id" to id.toString())
    return listOfNotNull(
        f.status.trim().takeIf { it.isNotEmpty() }?.let { "status" to it },
        f.agency.trim().takeIf { it.isNotEmpty() }?.let { "agency" to it },
        f.month.trim().takeIf { it.isNotEmpty() }?.let { "month" to it },
        f.q.trim().takeIf { it.isNotEmpty() }?.let { "q" to it },
        page.takeIf { it > 1 }?.let { "page" to it.toString() },
    ).toMap()
}

/** The agency filter: "All agencies", the list's agencies, and a kept agency not in it as "{agency} (no invoices)". */
fun agencyChoices(list: List<String>, kept: String): List<Pair<String, String>> {
    val shown = list.filter { it.isNotBlank() }.distinct()
    return listOf("" to "All agencies") + shown.map { it to it } +
        (if (kept.isNotBlank() && kept !in shown) listOf(kept to "$kept (no invoices)") else emptyList())
}

/** Where a search hit opens in the app, or null when it cannot (an invoice needs billing). */
fun searchRoute(kind: String, id: Int, canBilling: Boolean, client: Int = 0, track: Int = 0): String? = when (kind) {
    "referral" -> "referral/$id"
    "client" -> "client/$id"
    "track" -> if (client > 0) "track/$client/$id" else null
    "goal" -> if (client > 0 && track > 0) "track/$client/$track" else if (client > 0) "client/$client" else null
    "invoice" -> if (canBilling && id > 0) "invoices?id=$id" else null
    else -> if (client > 0) "client/$client" else null
}

/* ---------------- T3: outstanding ---------------- */

const val OUTSTANDING_HEADING = "Outstanding (submitted and not yet settled)"
const val NOTHING_OUTSTANDING = "Nothing is outstanding."

/**
 * One agency's line: "{agency}: {$owed} owed, {$overdue} past due. {n} invoice(s)[ ({k} part-paid)],
 * oldest {d} days." The past-due amount is always said (the website always shows the column), except
 * from a 4.37.0 server, which does not send it.
 */
fun agingLine(a: InvoiceAging): String {
    val agency = a.agency.ifBlank { "No agency" }
    val owed = "$agency: ${money(a.amount)} owed" + (a.overdue?.let { ", ${money(it)} past due" } ?: "") + "."
    val count = countWords(a.count, "invoice") + (if (a.partPaid > 0) " (${a.partPaid} part-paid)" else "")
    return "$owed $count" + (a.oldestDays?.let { ", oldest ${countWords(it, "day")}" } ?: "") + "."
}

/* ---------------- T4: several drafts submitted ---------------- */

/** The drafts the form offers: oldest first (created, then id), 200 at most, as the website's form. */
fun firstDrafts(rows: List<InvoiceRow>): List<InvoiceRow> =
    rows.filter { it.status == "Draft" }.sortedWith(compareBy<InvoiceRow>({ it.createdAt }, { it.id })).take(200)

fun bulkSubmitBody(ids: Collection<Int>, date: String): JSONObject =
    JSONObject().put("invoice_ids", JSONArray(ids.sorted())).put("submitted_on", date.trim())

/** "Mark 3 invoices submitted as sent on Nov 19, 2026?" ([date] year-month-day). */
fun bulkConfirmTitle(n: Int, date: String): String = "Mark ${countWords(n, "invoice")} submitted as sent on ${shortDate(date)}?"

const val BULK_CONFIRM_TEXT = "This starts the aging clock on each and cannot be undone."
const val BULK_NONE_TICKED = "Tick at least one draft invoice."
const val BULK_BAD_DATE = "Enter the date they were sent as a full date, not after today."

/** The form's field for a server error key: "ids" (Draft invoices) or "date" (Date they were sent). */
fun bulkFieldFor(key: String): String? = when (key) {
    "invoice_ids", "bulk_ids" -> "ids"
    "submitted_on", "bulk_submitted_on" -> "date"
    else -> null
}

/** A draft's tick label: "{number}, {client}, {$amount}". */
fun draftTickLabel(i: InvoiceRow): String = "${i.number}, ${i.clientName}, ${money(i.totalAmount)}"

/* ---------------- T5: the invoice document ---------------- */

fun invoiceDocumentQuery(id: Int): Map<String, String> = mapOf("invoice_id" to id.toString())

fun openInvoiceLabel(number: String): String = "Open invoice $number"

/* ---------------- T6: incoming authorization emails ---------------- */

const val AUTH_MENU_SUBTITLE = "Approved authorization emails: paste or forward one, match the client and start the track."
const val AUTH_INTRO = "Paste an approved authorization email, or forward it to the CMS mailbox, and the track form is filled in from it for you to check."
const val AUTH_NONE_WAITING = "No authorization emails are waiting."
const val AUTH_NOT_APPROVED = "This email does not say the authorization was approved. Check it with the counselor before starting a track."
const val AUTH_ANOTHER = "Another client, chosen below"
const val AUTH_EMPTY_PASTE = "Paste the authorization email first."

/** The review's client choices: each match (its id and the server's text), then "Another client, chosen below" ("0") last. */
fun authReviewClientChoices(matches: List<JSONObject>): List<Pair<String, String>> =
    matches.filter { it.optInt("id") > 0 }.map { it.optInt("id").toString() to it.optString("text") } + ("0" to AUTH_ANOTHER)

/** start_authorization_track's body: the chosen match, or the other client when "Another client" ("0") is chosen. */
fun startAuthBody(id: Int, choice: String, other: String, setCounselor: Boolean): JSONObject {
    val client = if (choice == "0" || choice.isBlank()) other.toIntOrNull() ?: 0 else choice.toIntOrNull() ?: 0
    return JSONObject().put("id", id).put("client_id", client).put("set_counselor", setCounselor)
}

/** The counselor tick's label, saying it stays unconfirmed when the email's address is not a confirmed one. */
fun authCounselorLabel(text: String, confirmed: Boolean): String =
    "Record $text as the client's VR counselor" + if (confirmed) "" else " (unconfirmed until checked on the client's edit page)"

/** The track form's starting values from the server's prefill: only the form's own fields (incoming_auth is sent apart). */
fun prefillToTrackForm(prefill: JSONObject?): Map<String, String> {
    if (prefill == null) return emptyMap()
    return TRACK_FORM_KEYS.filter { prefill.has(it) && !prefill.isNull(it) }.associateWith { prefill.optString(it) }
}

fun dismissAuthTitle(number: String): String = "Dismiss authorization $number without starting a track?"

/* ---------------- T7: several sessions at once ---------------- */

const val PLAN_SECTION = "More than one session: several days a week, a list of dates, or the whole authorization"
const val PLAN_APPROVAL = "I reviewed these warnings and approve this booking despite them."

/**
 * The plan fields of schedule_session's body for [pattern] ("weekly", "days", "dates", "fill"):
 * fields the pattern does not use are left out; [ok] is the preview's hash, once ticked.
 */
fun planBody(pattern: String, days: Collection<Int>, until: String, dates: String, ok: String?): JSONObject {
    val b = JSONObject().put("plan_pattern", pattern.ifBlank { "weekly" })
    if (pattern == "days" || pattern == "fill") b.put("plan_days", JSONArray(days.filter { it in 1..7 }.sorted()))
    if (pattern == "days" && until.isNotBlank()) b.put("plan_until", until.trim())
    if (pattern == "dates") b.put("plan_dates", dates.trim())
    if (!ok.isNullOrBlank()) b.put("plan_ok", ok)
    return b
}

/** The booking form's field for a server error key. */
fun planFieldFor(key: String): String = when (key) {
    "client_id" -> "client"
    "track_id" -> "track"
    "repeat_weeks" -> "weeks"
    "meeting_url" -> "link"
    "authorization_ack", "authorization_ack_hash" -> "authorization_ack"
    else -> key
}

fun previewHeading(n: Int): String = "The sessions this books ($n)"

fun bookTheseLabel(n: Int): String = "Book these ${countWords(n, "session")}"

/** How many weeks the repeat may be: 1 to 26 weekly; 1 to 52 for several days a week (session_plan's rule). */
fun weeksRange(pattern: String): IntRange = if (pattern == "days") 1..52 else 1..26

/** The website's sentence (session_plan.php) for a number of weeks out of [weeksRange]. */
fun weeksError(pattern: String): String =
    if (pattern == "days") "Give the last date, or a number of weeks from 1 to 52." else "Enter a number of weeks from 1 to 26 (1 is a single session)."

const val PLAN_NO_DAYS = "Choose at least one day of the week."
const val PLAN_NO_DATES = "Write at least one date, one per line."

/** The preview a 422 carries: its hash, hours and the session lines; null when none. */
data class PlanPreview(val hash: String, val hours: Double, val sessions: List<String>)

fun planPreview(reply: JSONObject?): PlanPreview? {
    val p = reply?.optJSONObject("plan_preview") ?: return null
    val hash = p.str("hash") ?: return null
    return PlanPreview(hash, p.optDouble("hours", 0.0), p.optJSONArray("sessions").strings())
}

/* ---------------- T8: the meeting link ---------------- */

const val MEETING_LABEL = "Make the meeting link (in the instructor's own connected account, instead of typing one)"

/** "No" first, then the set-up providers by the server's labels ("In Zoom", "In Microsoft Teams"). */
fun meetingChoices(providers: List<JSONObject>): List<Pair<String, String>> =
    listOf("" to "No") + providers.mapNotNull { p -> p.str("key")?.let { it to (p.str("label") ?: it) } }

/** The meeting fields of the body: a provider makes the link (no typed link is sent with it); otherwise the typed link. */
fun meetingBody(remote: Boolean, provider: String, link: String): JSONObject {
    val b = JSONObject()
    if (!remote) return b
    if (provider.isNotBlank()) b.put("meeting_make", provider)
    else if (link.isNotBlank()) b.put("meeting_url", link.trim())
    return b
}

/* ---------------- T9: the counselor portal ---------------- */

/**
 * A counselor's row: "{badge}, {agency}, {n} client(s)", then the server's status lines (built from
 * expires_at and last_used_at for an older server).
 */
fun counselorLines(c: JSONObject): List<String> {
    val badge = when { c.optBoolean("portal_active") -> "Portal active"; c.optBoolean("portal_expired") -> "Portal expired"; else -> "Not invited" }
    val first = listOfNotNull(badge, c.str("agency"), countWords(c.optInt("clients"), "client")).joinToString(", ")
    val status = if (c.has("status_lines")) c.optJSONArray("status_lines").strings()
        // The badge already says the state: a status line that only repeats it is left out.
        .filter { it.trim().lowercase() !in setOf("active", "expired", "not invited") }
    else when {
        c.optBoolean("portal_active") -> listOf(c.str("expires_at")?.let { "Expires " + shortDate(it) } ?: "Does not expire",
            c.str("last_used_at")?.let { "Last used " + shortDate(it) } ?: "Never opened")
        c.optBoolean("portal_expired") -> listOf("Expired " + shortDate(c.str("expires_at")) + "; the counselor needs a new link")
        else -> emptyList()
    }
    return listOf(first) + status
}

/** The row's buttons, as the website's: (kind, label), kind "send" or "off". */
fun counselorButtons(c: JSONObject): List<Pair<String, String>> = when {
    c.optBoolean("portal_active") -> listOf("send" to "Resend / reset link", "off" to "Turn off link")
    c.optBoolean("portal_expired") -> listOf("send" to "Send a new link")
    else -> listOf("send" to "Send portal link")
}

/** (title, text, yes) of the confirmation for [kind] "send" or "off". */
fun counselorConfirm(kind: String, who: String): Triple<String, String, String> =
    if (kind == "off") Triple("Turn off the portal link for $who?", "It stops working at once, and they need a new link to get back in.", "Turn off link")
    else Triple("Email a portal link to $who?", "Any previous link stops working.", "Send link")

/* ---------------- T10, T11: contracts ---------------- */

val DELIVERABLE_STATUSES = listOf("Pending", "In Progress", "Submitted", "Accepted")

/** The statuses a deliverable can be moved to (its current one left out). */
fun deliverableStatusChoices(current: String): List<String> = DELIVERABLE_STATUSES.filter { it != current }

/** The contract's summary lines: spent and percent (the server's), remaining, funder, number, dates, notes. */
fun contractDetailLines(r: JSONObject): List<String> {
    val c = r.optJSONObject("contract") ?: JSONObject()
    val amount = c.optDouble("amount", 0.0).takeIf { !it.isNaN() } ?: 0.0
    val spent = r.optDouble("spent", 0.0).takeIf { !it.isNaN() } ?: 0.0
    // The percent is the server's (contract_pct); an older server's is worked in cents as the website does.
    val pct: Int? = if (r.has("pct")) (if (r.isNull("pct")) null else r.optInt("pct")) else if (amount > 0) percentInCents(spent, amount) else null
    val lines = mutableListOf("Spent ${money(spent)} of ${money(amount)}" + (pct?.let { " ($it%)" } ?: " (no budget set)"))
    if (r.has("remaining") && !r.isNull("remaining")) lines += "Remaining ${money(r.optDouble("remaining", 0.0))}" + if (r.optBoolean("over_budget")) " (over budget)" else ""
    c.str("funder")?.let { lines += "Funder: $it" }
    c.str("contract_number")?.let { lines += "Contract number: $it" }
    c.str("start_date")?.let { lines += "Starts " + shortDate(it) }
    val days = r.str("days_text")?.trim()?.removePrefix("—")?.trim()?.removePrefix("(")?.removeSuffix(")")?.trim()
    c.str("end_date")?.let { lines += "Ends " + shortDate(it) + (days?.takeIf { d -> d.isNotEmpty() }?.let { d -> ", $d" } ?: "") }
    c.str("notes")?.let { lines += "Notes: $it" }
    return lines
}

/** A deliverable's lines: title, "{status}[, due {date}][, overdue]", its notes. */
fun deliverableLines(d: JSONObject): List<String> = listOfNotNull(
    d.optString("title"),
    d.optString("status") + (d.str("due_date")?.let { ", due " + shortDate(it) } ?: "") + if (d.optBoolean("overdue")) ", overdue" else "",
    d.str("notes"),
)

/** A spend entry: "{date}, {$x}, {memo}, by {name}". */
fun spendLine(s: JSONObject): String =
    listOfNotNull(shortDate(s.str("spent_on")), money(s.optDouble("amount", 0.0)), s.str("memo"), s.str("by")?.let { "by $it" }).joinToString(", ")

/** A contract in the list: name; "{$spent} of {$amount}[ ({pct}%)], funder"; the end date with the server's phrase; open deliverables. */
fun contractRowLines(c: JSONObject): List<String> = listOfNotNull(
    c.optString("name"),
    money(c.optDouble("spent", 0.0)) + " of " + money(c.optDouble("amount", 0.0)) + (if (c.has("pct") && !c.isNull("pct")) " (${c.optInt("pct")}%)" else "") + (c.str("funder")?.let { ", $it" } ?: ""),
    c.str("end_date")?.let { "Ends " + shortDate(it) + (c.str("ends_text")?.let { t -> " $t" } ?: "") },
    if (c.has("open_deliverables")) countWords(c.optInt("open_deliverables"), "open deliverable") else null,
)

/** The contract forms' field for a server error key. */
fun contractFieldFor(key: String): String = when (key) {
    "spent_on", "due_date" -> "date"
    "start_date" -> "start"
    "end_date" -> "end"
    else -> key
}

/** contract_update's body for [op]: set_budget {amount}, set_active {active}, delete_deliverable {deliverable_id}, delete_spend {spend_id}. */
fun contractOpBody(op: String, contractId: Int, value: Any?): JSONObject {
    val b = JSONObject().put("contract_id", contractId).put("op", op)
    when (op) {
        "set_budget" -> b.put("amount", value.toString())
        "set_active" -> b.put("active", value == true)
        "delete_deliverable" -> b.put("deliverable_id", (value as? Number)?.toInt() ?: 0)
        "delete_spend" -> b.put("spend_id", (value as? Number)?.toInt() ?: 0)
    }
    return b
}

/** "Remove the deliverable {title}?" or "Remove the spend entry {$x} on {date}?". */
fun removeConfirmTitle(kind: String, title: String?, amount: Double?, date: String?): String =
    if (kind == "spend") "Remove the spend entry ${money(amount ?: 0.0)} on ${shortDate(date)}?" else "Remove the deliverable ${title ?: ""}?"

fun contractShowChoices(): List<Pair<String, String>> = listOf("active" to "Active contracts", "closed" to "Closed contracts")

/* ---------------- T12: access bookings ---------------- */

val BOOKING_STATUSES = listOf("Requested", "Confirmed", "Completed", "Cancelled")

fun bookingShowChoices(): List<Pair<String, String>> = listOf("upcoming" to "Upcoming", "past" to "Including past", "cancelled" to "Cancelled")

/** The `bookings` query for [view]; past also sends a 4.37.0 server's past=1. */
fun bookingsQuery(view: String): Map<String, String> = when (view) {
    // The history alone (past=1, as a 4.37.0 server and the iPhone ask for it).
    "past" -> mapOf("past" to "1")
    "cancelled" -> mapOf("view" to "cancelled")
    else -> mapOf("view" to "upcoming")
}

/** A booking's label: the server's type_label (CART's long name), else its service type. */
fun bookingLabel(b: JSONObject): String = b.str("type_label") ?: b.optString("service_type")

/** A booking's lines: "{label} for {client}", "{status}, {when}", "{n} minutes", "Estimated cost {$x}", provider, location, notes. */
fun bookingLines(b: JSONObject): List<String> = listOfNotNull(
    bookingLabel(b) + " for " + b.optString("client_name"),
    b.optString("status") + ", " + (b.str("when_text") ?: b.str("needed_at")?.let { whenText(it) } ?: "no date set"),
    b.optInt("duration_min").takeIf { it > 0 }?.let { "$it minutes" },
    if (b.has("cost") && !b.isNull("cost")) "Estimated cost " + money(b.optDouble("cost", 0.0)) else null,
    b.str("provider")?.let { "Provider: $it" },
    b.str("location")?.let { "Location: $it" },
    b.str("notes")?.let { "Notes: $it" },
)

/** The statuses a booking can be set to: a cancelled one can be restored to the other three. */
fun restoreChoices(status: String): List<String> = BOOKING_STATUSES.filter { it != status }

/** (title, text) of the cancel confirmation. */
fun bookingCancelConfirm(label: String, client: String): Pair<String, String> =
    "Cancel the $label booking for $client?" to "It moves to the cancelled list, where it can be restored."

/** The service choices: names sent, labels shown (the server's booking_type_labels). */
fun bookingTypeChoices(types: List<String>, labels: JSONObject?): List<Pair<String, String>> =
    types.map { t -> t to (labels?.str(t) ?: t) }

/* ---------------- T13: utilization ---------------- */

/** A staff row: "{name}: {util}%[ at or above target]" or "{name}: no target", then "{d} of {e} expected hours · {n} clients · {$v} value". */
fun utilizationRowText(r: JSONObject): List<String> {
    val name = r.optString("name")
    val noTarget = r.optBoolean("no_target") || r.isNull("util")
    val util = r.optInt("util")
    val atOrAbove = if (r.has("at_or_above")) r.optBoolean("at_or_above") else util >= 100
    val head = if (noTarget) "$name: no target" else "$name: $util%" + if (atOrAbove) " at or above target" else ""
    val d = hoursText(r.optDouble("delivered", 0.0)); val e = hoursText(r.optDouble("expected", 0.0))
    return listOf(head, "$d of $e expected hours · ${countWords(r.optInt("clients"), "client")} · ${money(r.optDouble("value", 0.0))} value")
}

/** The period choices from the server's presets, in its order. */
fun periodChoices(presets: List<JSONObject>): List<Pair<String, String>> =
    presets.mapNotNull { p -> p.str("key")?.let { it to (p.str("label") ?: it) } }

/** The `utilization` query: the preset, with the dates only for Custom dates. */
fun utilizationQuery(preset: String, from: String, to: String): Map<String, String> {
    if (preset.isBlank()) return emptyMap()
    if (preset != "custom") return mapOf("period" to preset)
    return listOfNotNull("period" to "custom", from.trim().takeIf { it.isNotEmpty() }?.let { "from" to it }, to.trim().takeIf { it.isNotEmpty() }?.let { "to" to it }).toMap()
}

/** "4", "4.3" weeks, as the website's round($weeks, 1). */
fun weeksText(w: Double): String = hoursText(Math.round(w * 10) / 10.0)

/* ---------------- T14: service profitability ---------------- */

/** One row of the report: a track, or a group's totals (the server's, never added up here). */
data class ProfitRow(val label: String, val invoiced: Double, val received: Double, val cost: Double, val margin: Double, val unpriced: Double,
    /** A track's "Service / funder" column. */
    val sub: String? = null)

private fun JSONObject.amount(k: String): Double = optDouble(k, 0.0).takeIf { !it.isNaN() } ?: 0.0

private fun profitRow(label: String, o: JSONObject) =
    ProfitRow(label, o.amount("invoiced"), o.amount("received"), o.amount("cost"), o.amount("margin"), o.amount("unpriced_hours"))

fun profitTrackRows(reply: JSONObject): List<ProfitRow> = reply.optJSONArray("rows").objects().map { r ->
    profitRow(r.optString("client") + " / " + r.optString("title"), r)
        .copy(sub = listOf(r.str("service") ?: "", r.str("funder") ?: "").joinToString(" / ").takeIf { it != " / " })
}

/**
 * The server's group totals for [key] ("service" or "contract"), in its order: a list of rows (named
 * by label, name or the key), or an object of totals by name. Null from a server that sends none.
 */
fun profitGroups(reply: JSONObject, key: String): List<ProfitRow>? {
    val g = reply.optJSONObject("groups") ?: return null
    if (!g.has(key)) return null
    g.optJSONArray(key)?.let { a ->
        return a.objects().map { o -> profitRow(o.str("label") ?: o.str("name") ?: o.str(key) ?: "", o) }
    }
    val o = g.optJSONObject(key) ?: return emptyList()
    return o.keys().asSequence().toList().mapNotNull { k -> o.optJSONObject(k)?.let { profitRow(k, it) } }
}

/** "{label}: submitted to funder {$x}, received {$y}, direct cost {$z}, margin {$m}[, {h} hours missing cost rate]". */
fun profitRowText(r: ProfitRow): String =
    "${r.label.ifBlank { "Not set" }}${r.sub?.let { " ($it)" } ?: ""}: submitted to funder ${money(r.invoiced)}, received ${money(r.received)}, direct cost ${money(r.cost)}, margin ${money(r.margin)}" +
        if (r.unpriced > 0) ", ${hoursText(r.unpriced)} hours missing cost rate" else ""

fun profitGroupChoices(): List<Pair<String, String>> = listOf("track" to "Training track", "service" to "Service", "contract" to "Linked contract")

const val PROFIT_EMPTY = "No invoiced tracks to report yet."

/* ---------------- T15: data quality and the documentation audit ---------------- */

const val DATA_QUALITY_SUBTITLE = "Likely duplicate clients and records to tidy before a report is due."
const val DOC_AUDIT_EMPTY = "No documentation gaps found."

fun sectionHeading(heading: String, n: Int): String = "$heading ($n)"

/** A data-quality row: "{name}: {detail}". */
fun dataQualityRow(r: JSONObject): String = r.optString("name") + (r.str("detail")?.let { ": $it" } ?: "")

/** A documentation-audit row: the client's name, then each finding. */
fun docAuditRow(r: JSONObject): List<String> = listOf(r.optString("name")) + r.optJSONArray("findings").strings()

/* ---------------- T16: organisation and note types ---------------- */

/** The website's built-in note types (core.php NOTE_TYPES), in its order, when the server sent none. */
fun noteTypeFallback(): List<String> = listOf("Case Note", "Phone Call", "Progress Report", "Monthly Summary", "Intake", "Addendum")

/** The account summary's lines: "Organisation: {org}" first (when the server says it), then the email. */
fun accountSummary(org: String?, email: String?): List<String> = listOfNotNull(
    org?.takeIf { it.isNotBlank() }?.let { "Organisation: $it" },
    email?.let { "Email: $it" },
)
