package org.casemanagement.app

import org.casemanagement.app.ui.*
import org.json.JSONArray
import org.json.JSONObject
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test
import java.io.File

/**
 * 4.38.0: the pure parts of the release's screens, checked against the server contract's reply
 * shapes and the website's sentences. Money is only formatted (the server's numbers, already
 * rounded there); nothing here reads today's date or the phone's time zone.
 */
class V438Test {

    private fun invoice(json: String) = parseInvoiceRow(JSONObject(json))

    /** A JSON object compared by its keys and values, whatever order the keys are kept in. */
    private fun canon(v: Any?): Any? = when (v) {
        is JSONObject -> v.keys().asSequence().sorted().associateWith { canon(v.get(it)) }
        is JSONArray -> (0 until v.length()).map { canon(v.get(it)) }
        else -> v
    }
    private fun assertJson(expected: String, actual: JSONObject) = assertEquals(canon(JSONObject(expected)), canon(actual))

    /* ---------------- T1 ---------------- */

    private val submitted = """{"id":7,"invoice_number":"INV-0007","status":"Submitted","client_id":3,"client_name":"Synthetic Client",
        "track_title":"Screen reader training","auth_number":"A-123","funding_agency":"Synthetic VR","hours":10.5,"total_amount":1250.01,
        "paid_amount":100.0,"balance":1149.99,"short_paid":true,"written_off":0.01,"due_date":"2026-11-16","closed_reason":null,
        "days_outstanding":33,"days_overdue":3,"created_at":"2026-10-01","submitted_at":"2026-10-17","paid_at":"2026-11-01",
        "payment_ref":null,"can_open_client":true,"owed_text":"$1,149.99 owed (part-paid), 3 days past due",
        "date_lines":["Created Oct 1, 2026","Submitted Oct 17, 2026","Due Nov 16, 2026","Last payment Nov 1, 2026"]}"""

    @Test fun invoiceRowLinesAreTheWebsitesCellsWithTheServersSentences() {
        val i = invoice(submitted)
        assertEquals(listOf(
            "INV-0007: Synthetic Client",
            "Screen reader training · Authorization A-123",
            "$1,250.01, 10.5 hours",
            "$1,149.99 owed (part-paid), 3 days past due",
            "Created Oct 1, 2026, Submitted Oct 17, 2026, Due Nov 16, 2026, Last payment Nov 1, 2026",
        ), invoiceRowLines(i))
        assertEquals(3, i.daysOverdue)
    }

    @Test fun invoiceRowWithoutAuthorizationOrOwedText() {
        // A Draft: the server's balance 0 and owed_text null; no authorization says "none".
        val d = invoice("""{"id":8,"invoice_number":"INV-0008","status":"Draft","client_name":"Synthetic Client","track_title":"T","auth_number":null,
            "hours":1,"total_amount":12500000,"balance":0,"owed_text":null,"date_lines":["Created Nov 2, 2026"],"days_overdue":null,"created_at":"2026-11-02 09:00:00"}""")
        val lines = invoiceRowLines(d)
        assertEquals("T · Authorization none", lines[1])
        assertEquals("$12,500,000.00, 1 hour", lines[2])
        assertEquals("Draft", lines[3])
        assertEquals("Created Nov 2, 2026", lines[4])
        assertEquals("2026-11-02", d.createdAt)
        assertNull(d.daysOverdue)
        // Denied with its reason.
        val denied = invoice("""{"id":9,"invoice_number":"INV-0009","status":"Denied","closed_reason":"Outside the authorization","client_name":"C","track_title":"T","total_amount":5,"created_at":"2026-10-01"}""")
        assertEquals("Denied: Outside the authorization", invoiceRowLines(denied)[3])
    }

    @Test fun invoiceRowFromA437ServerBuildsTheDates() {
        val old = invoice("""{"id":1,"invoice_number":"INV-1","status":"Paid","client_name":"C","track_title":"T","auth_number":"X","hours":2,
            "total_amount":1250.005,"balance":0,"created_at":"2026-10-01","submitted_at":"2026-10-02","due_date":"2026-11-01","paid_at":"2026-10-20"}""")
        assertEquals("Created Oct 1, 2026, Submitted Oct 2, 2026, Due Nov 1, 2026, Last payment Oct 20, 2026", invoiceRowLines(old)[4])
        assertEquals("Paid", invoiceRowLines(old)[3])
    }

    @Test fun invoicePagingAndStatusChoices() {
        assertEquals("Invoices (page 2 of 2, 301 in all)", invoicePagingCaption(2, 2, 301))
        assertEquals("Invoices (12)", invoicePagingCaption(1, 1, 12))
        val choices = invoiceStatusChoices()
        assertEquals(listOf("All invoices", "Draft", "Submitted", "Paid", "Denied", "Void"), choices.map { it.second })
        assertFalse(choices.any { it.second == "Drafts" })
        assertEquals("", choices.first().first)
    }

    @Test fun invoicesReplyParsesPagingUnbilledAndFilters() {
        val r = JSONObject("""{"ok":true,"invoices":[$submitted],"status":"","total":301,"page":2,"pages":2,"outstanding":[],"unbilled":4250.5,
            "filters":{"status":"","agency":"Synthetic VR","month":"","q":""},"agencies":["Synthetic VR","Other VR"],"heading":"All invoices for Synthetic VR",
            "requested":false,"found":null}""")
        val d = parseInvoices(r)
        assertEquals(2, d.page); assertEquals(2, d.pages); assertEquals(301, d.total)
        assertEquals(4250.5, d.unbilled!!, 0.0)
        assertEquals("All invoices for Synthetic VR", d.heading)
        assertNull(d.found)
        assertEquals(listOf("Synthetic VR", "Other VR"), d.agencies)
        val one = parseInvoices(JSONObject("""{"ok":true,"invoices":[],"total":0,"page":1,"pages":1,"outstanding":[],"unbilled":0,"requested":true,"found":false}"""))
        assertEquals(false, one.found)
        assertTrue(one.requested)
    }

    /* ---------------- T2 ---------------- */

    @Test fun invoicesQueryLeavesBlankFiltersOut() {
        assertEquals(emptyMap<String, String>(), invoicesQuery(InvoiceFilters(), 1))
        assertEquals(mapOf("status" to "Draft", "agency" to "Synthetic VR", "month" to "2026-10", "q" to "50%", "page" to "2"),
            invoicesQuery(InvoiceFilters("Draft", " Synthetic VR ", "2026-10", "50%"), 2))
        // One invoice: only its id, as the website's ?id= (the other filters are ignored there).
        assertEquals(mapOf("id" to "5"), invoicesQuery(InvoiceFilters("Paid", "X", "", ""), 3, id = 5))
    }

    @Test fun agencyChoicesKeepAChosenAgencyWithNoInvoices() {
        assertEquals(listOf("" to "All agencies", "A" to "A", "B" to "B"), agencyChoices(listOf("A", "B", ""), ""))
        assertEquals(listOf("" to "All agencies", "A" to "A", "Gone VR" to "Gone VR (no invoices)"), agencyChoices(listOf("A"), "Gone VR"))
        assertEquals(3, agencyChoices(listOf("A", "B"), "A").size)
    }

    @Test fun searchOpensAnInvoiceForBillingStaffOnly() {
        assertEquals("invoices?id=12", searchRoute("invoice", 12, true))
        assertNull(searchRoute("invoice", 12, false))
        assertEquals("client/4", searchRoute("client", 4, false))
        assertEquals("track/4/9", searchRoute("track", 9, false, client = 4))
        assertNull(searchRoute("track", 9, false))
        assertEquals("track/4/2", searchRoute("goal", 30, false, client = 4, track = 2))
        assertEquals("referral/6", searchRoute("referral", 6, false))
        assertEquals("client/4", searchRoute("note", 77, false, client = 4))
    }

    /* ---------------- T3 ---------------- */

    @Test fun agingLineAlwaysSaysPastDue() {
        val r = parseInvoices(JSONObject("""{"invoices":[],"outstanding":[
            {"funding_agency":"Synthetic VR","count":2,"amount":2400.01,"overdue":1149.99,"part_paid":1,"oldest_days":40},
            {"funding_agency":"Other VR","count":1,"amount":12500000,"overdue":0,"part_paid":0,"oldest_days":1},
            {"funding_agency":"","count":1,"amount":5,"oldest_days":null}]}"""))
        assertEquals("Synthetic VR: $2,400.01 owed, $1,149.99 past due. 2 invoices (1 part-paid), oldest 40 days.", agingLine(r.outstanding[0]))
        assertEquals("Other VR: $12,500,000.00 owed, $0.00 past due. 1 invoice, oldest 1 day.", agingLine(r.outstanding[1]))
        // A 4.37.0 server sends no past-due amount: nothing is said rather than a wrong $0.00.
        assertEquals("No agency: $5.00 owed. 1 invoice.", agingLine(r.outstanding[2]))
        assertEquals("Outstanding (submitted and not yet settled)", OUTSTANDING_HEADING)
    }

    /* ---------------- T4 ---------------- */

    @Test fun firstDraftsAreOldestFirstAndAtMost200() {
        fun draft(id: Int, created: String) = invoice("""{"id":$id,"invoice_number":"INV-$id","status":"Draft","created_at":"$created","total_amount":1}""")
        val rows = listOf(draft(4, "2026-11-02"), draft(2, "2026-10-01"), draft(9, "2026-10-01"),
            invoice("""{"id":1,"invoice_number":"INV-1","status":"Submitted","created_at":"2026-01-01","total_amount":1}"""), draft(3, "2026-10-15"))
        // The website's form: drafts only, oldest created first, then by id.
        assertEquals(listOf(2, 9, 3, 4), firstDrafts(rows).map { it.id })
        val many = (1..250).map { draft(it, "2026-10-01") }
        assertEquals((1..200).toList(), firstDrafts(many).map { it.id })
        assertEquals("INV-7, Synthetic Client, $1,250.01", draftTickLabel(invoice(submitted).copy(number = "INV-7")))
    }

    @Test fun bulkSubmitBodyAndConfirmation() {
        val b = bulkSubmitBody(setOf(9, 3, 5), " 2026-11-18 ")
        assertEquals("[3,5,9]", b.getJSONArray("invoice_ids").toString())
        assertEquals("2026-11-18", b.getString("submitted_on"))
        assertEquals("", bulkSubmitBody(listOf(1), "").getString("submitted_on"))
        assertEquals("Mark 3 invoices submitted as sent on Nov 18, 2026?", bulkConfirmTitle(3, "2026-11-18"))
        assertEquals("Mark 1 invoice submitted as sent on Nov 18, 2026?", bulkConfirmTitle(1, "2026-11-18"))
        assertEquals("This starts the aging clock on each and cannot be undone.", BULK_CONFIRM_TEXT)
    }

    @Test fun bulkErrorsGoToTheirFields() {
        // The server's 422: {"ok":false,"error":"Tick at least one draft invoice.","errors":{"invoice_ids":"Tick at least one draft invoice."}}
        val reply = JSONObject("""{"ok":false,"error":"Tick at least one draft invoice.","errors":{"invoice_ids":"Tick at least one draft invoice.","submitted_on":"Enter the date they were sent as a full date, not after today."}}""")
        val mapped = replyErrors(reply).mapNotNull { (k, v) -> bulkFieldFor(k)?.let { it to v } }.toMap()
        assertEquals(mapOf("ids" to BULK_NONE_TICKED, "date" to BULK_BAD_DATE), mapped)
        assertEquals("ids", bulkFieldFor("bulk_ids"))
        assertNull(bulkFieldFor("other"))
    }

    /* ---------------- T5 ---------------- */

    @Test fun invoiceDocumentQueryAndLabel() {
        assertEquals(mapOf("invoice_id" to "42"), invoiceDocumentQuery(42))
        assertEquals("Open invoice INV-0042", openInvoiceLabel("INV-0042"))
    }

    /* ---------------- T6 ---------------- */

    @Test fun reviewClientChoicesEndWithAnotherClient() {
        val m = JSONArray("""[{"id":4,"text":"Synthetic Client (Active Training, born Jan 2, 1990, same counselor)"},{"id":5,"text":"Synthetic Client (Closed)"}]""").objects()
        val c = authReviewClientChoices(m)
        assertEquals(listOf("4", "5", "0"), c.map { it.first })
        assertEquals("Another client, chosen below", c.last().second)
        assertEquals(listOf("0" to AUTH_ANOTHER), authReviewClientChoices(emptyList()))
    }

    @Test fun startBodyUsesTheMatchOrTheOtherClient() {
        assertJson("""{"id":3,"client_id":4,"set_counselor":true}""", startAuthBody(3, "4", "9", true))
        assertJson("""{"id":3,"client_id":9,"set_counselor":false}""", startAuthBody(3, "0", "9", false))
        assertEquals(0, startAuthBody(3, "0", "0", false).getInt("client_id"))
        assertEquals("Record Synthetic Counselor sc@example.org as the client's VR counselor (unconfirmed until checked on the client's edit page)",
            authCounselorLabel("Synthetic Counselor sc@example.org", false))
        assertEquals("Record X as the client's VR counselor", authCounselorLabel("X", true))
        assertEquals("Dismiss authorization A-77 without starting a track?", dismissAuthTitle("A-77"))
    }

    @Test fun prefillGoesIntoTheTrackFormsFields() {
        val p = JSONObject("""{"title":"Screen reader training","billing_mode":"vr","auth_number":"A-77","funding_agency":"Synthetic VR",
            "authorized_amount":"4500.00","authorized_hours":"40","hourly_rate":"85.00","start_date":"2026-11-01","expiration_date":"2027-04-30",
            "incoming_auth":"12","mileage_rate":"0.655","rsa_category":"Assistive technology services"}""")
        val f = prefillToTrackForm(p)
        assertFalse(f.containsKey("incoming_auth"))
        assertTrue(f.keys.all { it in TRACK_FORM_KEYS })
        assertEquals(11, f.size)
        assertEquals("A-77", f["auth_number"]); assertEquals("0.655", f["mileage_rate"])
        assertTrue(prefillToTrackForm(null).isEmpty())
    }

    @Test fun authorizationAlertOpensTheReview() {
        assertEquals("authorization/5", alertRoute("authorizations.php?id=5", { true }))
        assertEquals("authorizations", alertRoute("authorizations.php", { true }))
        assertNull(alertRoute("authorizations.php?id=5", { it != "clients" }))
    }

    /* ---------------- T7 ---------------- */

    @Test fun planBodyLeavesUnusedFieldsOut() {
        assertJson("""{"plan_pattern":"weekly"}""", planBody("weekly", setOf(1, 3), "2026-12-01", "x", null))
        assertJson("""{"plan_pattern":"days","plan_days":[1,3],"plan_until":"2026-12-01","plan_ok":"abc"}""", planBody("days", setOf(3, 1, 9), " 2026-12-01 ", "x", "abc"))
        assertJson("""{"plan_pattern":"days","plan_days":[2]}""", planBody("days", setOf(2), "", "", null))
        assertJson("""{"plan_pattern":"dates","plan_dates":"2026-11-03\n2026-11-05 2:30 PM"}""", planBody("dates", emptySet(), "", "2026-11-03\n2026-11-05 2:30 PM\n", null))
        assertJson("""{"plan_pattern":"fill","plan_days":[5]}""", planBody("fill", setOf(5), "2026-12-01", "", ""))
    }

    @Test fun planPreviewAndFields() {
        val reply = JSONObject("""{"ok":false,"error":"Check the 4 sessions listed below (4 hours), then tick Book these 4 sessions and schedule again.",
            "errors":{"plan_ok":"Check the 4 sessions listed below (4 hours), then tick Book these 4 sessions and schedule again."},
            "plan_preview":{"hash":"f00d","hours":4.0,"sessions":["Mon, November 23, 2026 2:00 PM to 3:00 PM","Wed, November 25, 2026 2:00 PM to 3:00 PM","Mon, November 30, 2026 2:00 PM to 3:00 PM","Wed, December 2, 2026 2:00 PM to 3:00 PM"]}}""")
        val p = planPreview(reply)!!
        assertEquals("f00d", p.hash); assertEquals(4, p.sessions.size); assertEquals(4.0, p.hours, 0.0)
        assertNull(planPreview(JSONObject("""{"ok":false}""")))
        assertEquals("The sessions this books (4)", previewHeading(4))
        assertEquals("Book these 4 sessions", bookTheseLabel(4))
        assertEquals("plan_days", planFieldFor("plan_days")); assertEquals("weeks", planFieldFor("repeat_weeks"))
        assertEquals("client", planFieldFor("client_id")); assertEquals("track", planFieldFor("track_id")); assertEquals("meeting_make", planFieldFor("meeting_make"))
        assertEquals("plan_pattern", planFieldFor("plan_pattern")); assertEquals("authorization_ack", planFieldFor("authorization_ack"))
        assertEquals("Make the meeting link (in the instructor's own connected account, instead of typing one)", MEETING_LABEL)
        assertEquals(1..26, weeksRange("weekly")); assertEquals(1..52, weeksRange("days")); assertEquals(1..26, weeksRange("dates"))
        assertEquals("Enter a number of weeks from 1 to 26 (1 is a single session).", weeksError("weekly"))
        assertEquals("I reviewed these warnings and approve this booking despite them.", PLAN_APPROVAL)
    }

    /* ---------------- T8 ---------------- */

    @Test fun meetingChoicesAndBody() {
        val providers = JSONArray("""[{"key":"zoom","label":"In Zoom"},{"key":"microsoft","label":"In Microsoft Teams"}]""").objects()
        assertEquals(listOf("" to "No", "zoom" to "In Zoom", "microsoft" to "In Microsoft Teams"), meetingChoices(providers))
        assertJson("""{"meeting_make":"zoom"}""", meetingBody(true, "zoom", "https://typed.example"))
        assertJson("""{"meeting_url":"https://typed.example"}""", meetingBody(true, "", " https://typed.example "))
        assertEquals(0, meetingBody(false, "zoom", "https://typed.example").length())
    }

    /* ---------------- T9 ---------------- */

    @Test fun counselorLinesFromTheServerOrBuilt() {
        val active = JSONObject("""{"email":"sc@example.org","name":"Synthetic Counselor","agency":"Synthetic VR","clients":1,"portal_active":true,"portal_expired":false,
            "expires_at":"2027-01-01 00:00:00","last_used_at":null,"status_lines":["Expires Jan 1, 2027","Never opened"]}""")
        assertEquals(listOf("Portal active, Synthetic VR, 1 client", "Expires Jan 1, 2027", "Never opened"), counselorLines(active))
        val old = JSONObject("""{"email":"x@example.org","name":"X","agency":null,"clients":3,"portal_active":true,"expires_at":null,"last_used_at":"2026-11-02 10:00:00"}""")
        assertEquals(listOf("Portal active, 3 clients", "Does not expire", "Last used Nov 2, 2026"), counselorLines(old))
        val expired = JSONObject("""{"email":"y@example.org","clients":0,"portal_active":false,"portal_expired":true,"expires_at":"2026-10-01 00:00:00"}""")
        assertEquals(listOf("Portal expired, 0 clients", "Expired Oct 1, 2026; the counselor needs a new link"), counselorLines(expired))
        assertEquals(listOf("Not invited, 2 clients"), counselorLines(JSONObject("""{"clients":2,"status_lines":[]}""")))
    }

    @Test fun counselorButtonsAndConfirmations() {
        assertEquals(listOf("send" to "Resend / reset link", "off" to "Turn off link"), counselorButtons(JSONObject("""{"portal_active":true}""")))
        assertEquals(listOf("send" to "Send a new link"), counselorButtons(JSONObject("""{"portal_expired":true}""")))
        assertEquals(listOf("send" to "Send portal link"), counselorButtons(JSONObject("{}")))
        assertEquals(Triple("Email a portal link to Synthetic Counselor?", "Any previous link stops working.", "Send link"), counselorConfirm("send", "Synthetic Counselor"))
        assertEquals("Turn off the portal link for Synthetic Counselor?", counselorConfirm("off", "Synthetic Counselor").first)
        assertEquals("It stops working at once, and they need a new link to get back in.", counselorConfirm("off", "X").second)
    }

    /* ---------------- T10 ---------------- */

    @Test fun contractDetailLinesUseTheServersPercentAndRemaining() {
        val r = JSONObject("""{"ok":true,"contract":{"id":1,"name":"Synthetic Grant","funder":"Synthetic Funder","contract_number":"C-9","amount":10000,
            "start_date":"2026-01-01","end_date":"2026-12-31","status":"Active","notes":"Quarterly report due"},"spent":2900,"pct":29,"remaining":7100,
            "over_budget":false,"days_text":"42 days to end","deliverables":[],"spend":[]}""")
        assertEquals(listOf("Spent $2,900.00 of $10,000.00 (29%)", "Remaining $7,100.00", "Funder: Synthetic Funder", "Contract number: C-9",
            "Starts Jan 1, 2026", "Ends Dec 31, 2026, 42 days to end", "Notes: Quarterly report due"), contractDetailLines(r))
        val over = JSONObject("""{"contract":{"amount":0},"spent":12.5,"pct":null,"remaining":-12.5,"over_budget":true}""")
        assertEquals(listOf("Spent $12.50 of $0.00 (no budget set)", "Remaining $-12.50 (over budget)"), contractDetailLines(over))
        // A 4.37.0 server: the percent is worked in cents, as the website's, and no remaining line.
        assertEquals(listOf("Spent $2,900.00 of $10,000.00 (29%)"), contractDetailLines(JSONObject("""{"contract":{"amount":10000},"spent":2900}""")))
    }

    @Test fun deliverableAndSpendRows() {
        assertEquals(listOf("Pending", "Submitted", "Accepted"), deliverableStatusChoices("In Progress"))
        assertEquals(DELIVERABLE_STATUSES, deliverableStatusChoices("Unknown"))
        assertEquals(listOf("Q3 report", "Pending, due Oct 1, 2026, overdue", "Send to the funder"),
            deliverableLines(JSONObject("""{"id":1,"title":"Q3 report","status":"Pending","due_date":"2026-10-01","notes":"Send to the funder","overdue":true}""")))
        assertEquals("Nov 2, 2026, $1,250.01, Laptops, by Synthetic Billing",
            spendLine(JSONObject("""{"id":4,"spent_on":"2026-11-02","amount":1250.01,"memo":"Laptops","by":"Synthetic Billing"}""")))
        assertEquals("Nov 2, 2026, $5.00", spendLine(JSONObject("""{"spent_on":"2026-11-02","amount":5,"memo":null,"by":null}""")))
        assertEquals("date", contractFieldFor("spent_on")); assertEquals("amount", contractFieldFor("amount")); assertEquals("end", contractFieldFor("end_date"))
        assertEquals(listOf("Synthetic Grant", "$2,900.00 of $10,000.00 (29%), Synthetic Funder", "Ends Dec 31, 2026 — ending soon", "2 open deliverables"),
            contractRowLines(JSONObject("""{"id":1,"name":"Synthetic Grant","funder":"Synthetic Funder","amount":10000,"spent":2900,"end_date":"2026-12-31","pct":29,"open_deliverables":2,"ends_text":"— ending soon"}""")))
    }

    /* ---------------- T11 ---------------- */

    @Test fun contractOpBodies() {
        assertJson("""{"contract_id":3,"op":"set_budget","amount":"12000.00"}""", contractOpBody("set_budget", 3, "12000.00"))
        assertJson("""{"contract_id":3,"op":"set_active","active":false}""", contractOpBody("set_active", 3, false))
        assertJson("""{"contract_id":3,"op":"delete_deliverable","deliverable_id":8}""", contractOpBody("delete_deliverable", 3, 8))
        assertJson("""{"contract_id":3,"op":"delete_spend","spend_id":11}""", contractOpBody("delete_spend", 3, 11))
        assertEquals("Remove the deliverable Q3 report?", removeConfirmTitle("deliverable", "Q3 report", null, null))
        assertEquals("Remove the spend entry $1,250.01 on Nov 2, 2026?", removeConfirmTitle("spend", null, 1250.01, "2026-11-02"))
        assertEquals(listOf("active" to "Active contracts", "closed" to "Closed contracts"), contractShowChoices())
        // Money typed as the website takes it (10 digits for contracts), and refused as it refuses it.
        assertEquals("12500000.00", moneyText("$12,500,000.00", CONTRACT_DIGITS))
        assertNull(moneyText("12,50", CONTRACT_DIGITS))
    }

    /* ---------------- T12 ---------------- */

    @Test fun bookingLinesAndChoices() {
        val b = JSONObject("""{"id":5,"service_type":"CART captioning","type_label":"CART (real-time captioning)","provider":"Synthetic Captions","needed_at":"2026-11-20 14:00:00",
            "when_text":"Fri, Nov 20, 2026, 2:00 PM","duration_min":90,"location":"Room 2","status":"Confirmed","cost":12345678.9,"notes":"Bring the laptop","client_name":"Synthetic Client","client_id":3}""")
        assertEquals(listOf("CART (real-time captioning) for Synthetic Client", "Confirmed, Fri, Nov 20, 2026, 2:00 PM", "90 minutes", "Estimated cost $12,345,678.90",
            "Provider: Synthetic Captions", "Location: Room 2", "Notes: Bring the laptop"), bookingLines(b))
        val undated = JSONObject("""{"service_type":"Interpreter","client_name":"C","status":"Requested","needed_at":null,"duration_min":0,"cost":null}""")
        assertEquals(listOf("Interpreter for C", "Requested, no date set"), bookingLines(undated))
        assertEquals(listOf("Requested", "Confirmed", "Completed"), restoreChoices("Cancelled"))
        assertEquals(listOf("Confirmed", "Completed", "Cancelled"), restoreChoices("Requested"))
        assertEquals("Cancel the CART (real-time captioning) booking for Synthetic Client?" to "It moves to the cancelled list, where it can be restored.",
            bookingCancelConfirm("CART (real-time captioning)", "Synthetic Client"))
        assertEquals(mapOf("view" to "upcoming"), bookingsQuery("upcoming"))
        // The history alone: past=1 with no view, as the iPhone asks for it.
        assertEquals(mapOf("past" to "1"), bookingsQuery("past"))
        assertEquals(mapOf("view" to "cancelled"), bookingsQuery("cancelled"))
        assertEquals(listOf("CART captioning" to "CART (real-time captioning)", "Other" to "Other"),
            bookingTypeChoices(listOf("CART captioning", "Other"), JSONObject("""{"CART captioning":"CART (real-time captioning)"}""")))
        // The cost as the website's 8-digit rule, with "$" and commas.
        assertEquals("12345678.90", moneyText("$12,345,678.90", 8))
        assertNull(moneyText("123,456,789.00", 8))
    }

    /* ---------------- T13 ---------------- */

    @Test fun utilizationRowsAndQuery() {
        assertEquals(listOf("Synthetic Instructor: 100% at or above target", "40 of 40 expected hours · 3 clients · $3,400.01 value"),
            utilizationRowText(JSONObject("""{"name":"Synthetic Instructor","weekly":10,"delivered":40,"value":3400.01,"clients":3,"expected":40,"util":100,"no_target":false,"at_or_above":true}""")))
        assertEquals("Synthetic Billing: 85%", utilizationRowText(JSONObject("""{"name":"Synthetic Billing","delivered":34,"expected":40,"util":85,"no_target":false,"at_or_above":false,"clients":1,"value":0}"""))[0])
        assertEquals(listOf("Synthetic Admin: no target", "2.5 of 0 expected hours · 1 client · $0.00 value"),
            utilizationRowText(JSONObject("""{"name":"Synthetic Admin","delivered":2.5,"expected":0,"util":0,"no_target":true,"clients":1,"value":0}""")))
        val presets = JSONArray("""[{"key":"this_month","label":"This month"},{"key":"this_quarter","label":"This quarter"},{"key":"custom","label":"Custom dates"}]""").objects()
        assertEquals(listOf("this_month" to "This month", "this_quarter" to "This quarter", "custom" to "Custom dates"), periodChoices(presets))
        assertEquals(mapOf("period" to "this_quarter"), utilizationQuery("this_quarter", "2026-01-01", "2026-02-01"))
        assertEquals(mapOf("period" to "custom", "from" to "2026-01-01", "to" to "2026-02-01"), utilizationQuery("custom", " 2026-01-01", "2026-02-01 "))
        assertEquals(emptyMap<String, String>(), utilizationQuery("", "2026-01-01", ""))
        assertEquals("4.3", weeksText(4.2857)); assertEquals("4", weeksText(4.0))
        assertEquals("12.5 hours", hoursWords(12.5)); assertEquals("1 hour", hoursWords(1.0))
    }

    /* ---------------- T14 ---------------- */

    @Test fun profitRowsAreTheServersFigures() {
        val reply = JSONObject("""{"ok":true,"rows":[{"id":1,"title":"Screen reader","client":"Synthetic Client","service":"Assistive Technology","funder":"Synthetic VR",
            "contract":"Synthetic Grant","invoiced":1250.01,"received":1000,"cost":300.5,"margin":949.51,"unpriced_hours":2.5}],
            "groups":{"service":[{"label":"Assistive Technology","invoiced":1250.01,"received":1000,"cost":300.5,"margin":949.51,"unpriced_hours":2.5}],
                      "contract":[{"label":"Synthetic Grant","invoiced":1250.01,"received":1000,"cost":300.5,"margin":949.51,"unpriced_hours":0}]}}""")
        val tracks = profitTrackRows(reply)
        assertEquals("Synthetic Client / Screen reader (Assistive Technology / Synthetic VR): submitted to funder $1,250.01, received $1,000.00, direct cost $300.50, margin $949.51, 2.5 hours missing cost rate",
            profitRowText(tracks[0]))
        assertEquals("Assistive Technology", profitGroups(reply, "service")!![0].label)
        assertEquals("Synthetic Grant: submitted to funder $1,250.01, received $1,000.00, direct cost $300.50, margin $949.51", profitRowText(profitGroups(reply, "contract")!![0]))
        assertNull(profitGroups(JSONObject("""{"rows":[]}"""), "service"))
        assertEquals("margin $-50.00", profitRowText(ProfitRow("X", 0.0, 0.0, 50.0, -50.0, 0.0)).substringAfterLast(", "))
        assertEquals(listOf("Training track", "Service", "Linked contract"), profitGroupChoices().map { it.second })
    }

    /* ---------------- T15 ---------------- */

    @Test fun dataQualityAndAuditRows() {
        assertEquals("Likely duplicate clients (2)", sectionHeading("Likely duplicate clients", 2))
        assertEquals("Synthetic Client: no active track", dataQualityRow(JSONObject("""{"client_id":3,"name":"Synthetic Client","detail":"no active track"}""")))
        assertEquals(listOf("Synthetic Client", "No tech assessment on file.", "No progress note in 60 days."),
            docAuditRow(JSONObject("""{"client_id":3,"name":"Synthetic Client","findings":["No tech assessment on file.","No progress note in 60 days."]}""")))
        assertEquals("No documentation gaps found.", DOC_AUDIT_EMPTY)
        // Screen readers would read the website's old emoji as "thumbs up sign".
        assertFalse(DOC_AUDIT_EMPTY.any { Character.isSurrogate(it) })
    }

    /* ---------------- T16 ---------------- */

    @Test fun noteTypesAndAccount() {
        assertEquals(listOf("Case Note", "Phone Call", "Progress Report", "Monthly Summary", "Intake", "Addendum"), noteTypeFallback())
        assertEquals(listOf("Organisation: Synthetic Agency", "Email: s@example.org"), accountSummary("Synthetic Agency", "s@example.org"))
        assertTrue(accountSummary("Synthetic Agency", null)[0].startsWith("Organisation: "))
        assertTrue(accountSummary(" ", null).isEmpty())
        assertEquals("Synthetic Agency", parseMe(JSONObject("""{"id":1,"name":"N","perms":[],"org":"Synthetic Agency"}""")).org)
        assertNull(parseMe(JSONObject("""{"id":1,"name":"N","perms":[]}""")).org)
    }

    /* ---------------- A retry key reused with other details (409 idem_conflict) ---------------- */

    @Test fun idemConflictIsSaidDropsTheKeyAndIsNeverASuccess() {
        val reply = JSONObject("""{"ok":false,"error":"$IDEM_CONFLICT","idem_conflict":true}""")
        val e = ApiException(IDEM_CONFLICT, 409, reply = reply)
        assertTrue(idemConflict(e))
        assertFalse(stillProcessing(e))
        var kept = "old-key"
        var sent = false
        val thrown = try {
            kotlinx.coroutines.runBlocking { sendOnce("", { kept = it }, "submit_invoices{}", "the invoices") { throw e } }
            null
        } catch (x: ApiException) { x }
        assertEquals(IDEM_CONFLICT, thrown?.message)
        assertEquals(409, thrown?.status)
        assertEquals("", kept)
        assertFalse(sent)
        // The next deliberate send makes a new key.
        kotlinx.coroutines.runBlocking { sendOnce(kept, { kept = it }, "submit_invoices{}", "x") { idem -> sent = idem.isNotEmpty() } }
        assertTrue(sent)
        // No sentence from the server: the app's copy of it.
        val bare = try { kotlinx.coroutines.runBlocking { sendOnce("", { }, "f", "x") { throw ApiException("", 409, reply = JSONObject("""{"idem_conflict":true}""")) } }; null } catch (x: ApiException) { x }
        assertEquals(IDEM_CONFLICT, bare?.message)
        // A "still processing" 409 is still kept for a retry with the same key.
        assertTrue(stillProcessing(ApiException("Still being processed.", 409, reply = JSONObject("""{"processing":true}"""))))
        // An offline entry keeps the server's reason (it is not dropped as sent).
        assertEquals(IDEM_CONFLICT, idemConflictReason(409, reply))
        assertEquals(IDEM_CONFLICT, idemConflictReason(409, JSONObject("""{"idem_conflict":true}""")))
        assertNull(idemConflictReason(409, JSONObject("""{"processing":true}""")))
        assertNull(idemConflictReason(200, reply))
        assertTrue(File("src/main/java/org/casemanagement/app/Api.kt").readText().contains("idemConflictReason(status, json)"))
    }

    /* ---------------- Every dialog is the lock-aware one; hints sit in the labels ---------------- */

    @Test fun noDialogInThisReleaseShowsAboveTheAppLock() {
        val dir = File("src/main/java/org/casemanagement/app/ui")
        assertTrue("run from the app module", dir.isDirectory)
        val changed = listOf("V438Helpers.kt", "V438Screens.kt", "ReportsScreens.kt", "NewSessionScreen.kt", "MoreTools.kt", "ProgramsScreens.kt",
            "TrackScreen.kt", "MoreScreen.kt", "AtServicesScreen.kt", "ClientsScreen.kt", "V436Helpers.kt", "V437Screens.kt")
        changed.forEach { name ->
            val t = File(dir, name).readText()
            assertFalse(name, t.contains("androidx.compose.material3.AlertDialog") || (t.contains("import androidx.compose.material3.*") && t.contains("AlertDialog(")))
        }
        val screens = File(dir, "V438Screens.kt").readText()
        assertFalse(screens.contains("supportingText"))
        // Typed text and the open dialogs are kept across rotation.
        assertTrue(Regex("var text by rememberSaveable").containsMatchIn(screens))
        // No money is added up on the phone: the helpers only format the server's numbers.
        val helpers = File(dir, "V438Helpers.kt").readText()
        assertFalse(Regex("sumOf|\\.sum\\(\\)").containsMatchIn(helpers + screens))
        // The old invoice wording is gone.
        assertFalse(File(dir, "ReportsScreens.kt").readText().contains("Showing the newest"))
        assertFalse(File(dir, "ProgramsScreens.kt").readText().contains("Cancelled bookings are removed from this list."))
        assertFalse(File(dir, "MoreTools.kt").readText().contains("well below target"))
    }
}
