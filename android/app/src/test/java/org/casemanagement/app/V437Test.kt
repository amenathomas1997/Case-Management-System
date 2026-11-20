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
 * 4.37.0: the pure parts of the release's screens, checked against the server contract's reply
 * shapes and the website's sentences. Nothing here reads today's date or the phone's time zone.
 */
class V437Test {

    private fun item(id: Int, name: String, status: String, overdue: Boolean = false, retired: Boolean = status == "Retired") =
        EquipmentItem(id, name, null, null, null, status, null, null, null, null, overdue, false, retired)

    /* S1 */

    @Test fun statusButtonsFollowTheServersMovesInOrder() {
        assertEquals(listOf("Repair" to "Mark in repair", "Retired" to "Retire Synthetic Magnifier"),
            statusButtons(listOf("Repair", "Retired"), "Synthetic Magnifier"))
        assertEquals(listOf("Available" to "Back in service", "Retired" to "Retire Synthetic Magnifier"),
            statusButtons(listOf("Available", "Retired"), "Synthetic Magnifier"))
        // An unknown move from a later server is not offered; none from Retired or on loan.
        assertEquals(emptyList<Pair<String, String>>(), statusButtons(listOf("Lost"), "X"))
        assertEquals(emptyList<Pair<String, String>>(), statusButtons(emptyList(), "X"))
    }

    @Test fun retireConfirmationIsTheWebsitesWording() {
        val holds = "2 reservations and waitlist entries for this item will be cancelled, and each client's instructor and the staff who made them will be notified. Putting the item back into service later does not restore them."
        assertEquals("Retire Synthetic Magnifier?", retireConfirmTitle("Synthetic Magnifier"))
        assertEquals("$RETIRE_FIRST_SENTENCE $holds", retireConfirmText("Synthetic Magnifier", holds))
        assertEquals("$RETIRE_FIRST_SENTENCE No reservations or waitlist entries are affected.", retireConfirmText("X", "No reservations or waitlist entries are affected."))
        assertEquals(RETIRE_FIRST_SENTENCE, retireConfirmText("X", ""))
        assertTrue(RETIRE_FIRST_SENTENCE.startsWith("A retired item can no longer be loaned out or reserved."))
    }

    @Test fun statusBodyCarriesTheConfirmation() {
        val b = statusBody(7, "Retired", true)
        assertEquals(7, b.getInt("equipment_id")); assertEquals("Retired", b.getString("to")); assertTrue(b.getBoolean("confirm_retire"))
        assertFalse(statusBody(7, "Repair", false).getBoolean("confirm_retire"))
    }

    @Test fun equipmentDetailReadsTheNewKeysAndAnOlderServer() {
        val r = JSONObject("""{"ok":true,"item":{"id":4,"name":"Synthetic Display","status":"Available","item_condition":"Good","book_value":0},
            "maintenance":[],"on_loan":false,"in_workflow":false,"holds":2,"status_actions":["Repair","Retired"],
            "retire_text":"2 reservations and waitlist entries for this item will be cancelled.","manageable":true}""")
        val d = parseEquipmentDetail(r)!!
        assertEquals(listOf("Repair", "Retired"), d.statusActions); assertEquals(2, d.holds); assertTrue(d.manageable)
        val old = parseEquipmentDetail(JSONObject("""{"ok":true,"item":{"id":4,"name":"X","status":"Available","item_condition":"Good"},"maintenance":[]}"""))!!
        assertNull(old.statusActions)
    }

    /* S2 */

    @Test fun retiredItemsGoUnderNotAvailableLast() {
        val rows = listOf(item(1, "A", "Retired"), item(2, "B", "Available"), item(3, "C", "On Loan"), item(4, "D", "Repair"), item(5, "E", "On Loan", overdue = true))
        val g = equipmentGroups(rows)
        assertEquals(listOf(5, 3), g.onLoan.map { it.id })
        assertEquals(listOf(2), g.available.map { it.id })
        assertEquals(listOf(4, 1), g.notAvailable.map { it.id })
        assertEquals(listOf("A, retired"), notAvailableLines(rows[0]))
        assertEquals(listOf("D", "Repair"), notAvailableLines(rows[3]))
        assertEquals(listOf("Service and details"), retiredRowActions())
    }

    @Test fun equipmentListReadsRetired() {
        val list = parseEquipment(JSONObject("""{"equipment":[{"id":1,"name":"Old","status":"Retired","retired":true},{"id":2,"name":"New","status":"Available"}]}"""))
        assertTrue(list[0].retired); assertFalse(list[1].retired)
    }

    /* S3 */

    @Test fun newItemBodyLeavesBlanksOut() {
        val b = equipmentBody(" Synthetic Reader ", "Other", "", "Good", "", 0, "", " 2026-01-05 ", " 48 ", "Vision")
        assertEquals("Synthetic Reader", b.getString("name"))
        assertEquals("2026-01-05", b.getString("purchase_date")); assertEquals("48", b.getString("useful_life_months")); assertEquals("Vision", b.getString("at_type"))
        val plain = equipmentBody("X", "Other", "", "Good", "", 0, "", "", "", "")
        assertFalse(plain.has("purchase_date")); assertFalse(plain.has("useful_life_months")); assertFalse(plain.has("at_type"))
        assertEquals("Good", plain.getString("condition"))
    }

    @Test fun newItemErrorsMapToTheirFields() {
        val reply = JSONObject("""{"ok":false,"error":"Useful life: enter a whole number of months from 1 to 600, or leave it blank for 36.",
            "errors":{"useful_life_months":"Useful life: enter a whole number of months from 1 to 600, or leave it blank for 36.","purchase_date":"Purchase date cannot be in the future.","maintenance_interval_days":"x","name":"Item name must be 190 characters or fewer."}}""")
        val m = mapErrors(reply, ::equipmentFieldFor)
        assertEquals("Purchase date cannot be in the future.", m["purchase"])
        assertTrue(m["life"]!!.startsWith("Useful life:")); assertEquals("x", m["interval"]); assertTrue(m.containsKey("name"))
    }

    @Test fun atTypesStartGuessed() {
        val c = atTypeChoices(emptyList())
        assertEquals("" to "Guessed from the category", c.first()); assertEquals(11, c.size)
        assertEquals(listOf("" to "Guessed from the category", "Vision" to "Vision"), atTypeChoices(listOf("Vision")))
    }

    /* S4 */

    @Test fun closingARequest() {
        assertEquals("Close Synthetic Display as denied?", closeTitle("Synthetic Display", "Denied"))
        assertEquals("Close Synthetic Display as cancelled?", closeTitle("Synthetic Display", "Cancelled"))
        assertEquals("Mark denied", closeYes("Denied")); assertEquals("Cancel request", closeYes("Cancelled"))
        val b = closeBody("Denied", "  Funding was refused. ")
        assertEquals("status", b.getString("op")); assertEquals("Denied", b.getString("to")); assertEquals("Funding was refused.", b.getString("notes"))
    }

    @Test fun deliveryAddsToInventoryOnlyWithTheEquipmentPermission() {
        val fields = JSONObject().put("serial", "SN-1").put("delivered_at", "2026-01-02")
        assertTrue(deliverBody(fields, true, true).getBoolean("add_to_inventory"))
        assertFalse(deliverBody(fields, true, false).has("add_to_inventory"))
        assertFalse(deliverBody(fields, false, true).getBoolean("add_to_inventory"))
        assertEquals("deliver", deliverBody(fields, false, false).getString("op"))
        assertFalse(fields.has("op"))
    }

    /* S5 */

    @Test fun draftsNeverReplaceTypedText() {
        assertEquals("Draft.", mergeDraft("", "Draft."))
        assertEquals("Draft.", mergeDraft("   ", "Draft."))
        assertEquals("Typed.\nDraft.", mergeDraft("Typed.", "Draft."))
        assertEquals("Typed.\nDraft.", mergeDraft("Typed.\n", "Draft."))
    }

    @Test fun justificationRequestAndNotice() {
        val b = justificationBody(9, " Synthetic Display ", "Braille", "Typed.")
        assertEquals("justification", b.getString("type")); assertEquals(9, b.getInt("client_id")); assertEquals("Synthetic Display", b.getString("device_name"))
        assertEquals("Braille", b.getString("category")); assertEquals("Typed.", b.getString("text"))
        assertEquals("Justification drafted. Review and edit it before creating the request.", justificationNotice(JSONObject("""{"ok":true,"text":"x","truncated":false,"shortened":false}""")))
        assertEquals("Justification drafted. Review and edit it before creating the request. The draft was shortened to fit 4,000 characters. Incomplete: the AI stopped before the end. Review it closely.",
            justificationNotice(JSONObject("""{"ok":true,"text":"x","truncated":true,"shortened":true}""")))
    }

    /* S6 */

    @Test fun startRequestPrefillCutsAtALineEnd() {
        assertEquals("Short.", startRequestPrefill(" Short. "))
        val long = "a".repeat(3990) + "\n" + "b".repeat(50)
        assertEquals("a".repeat(3990), startRequestPrefill(long))
        assertEquals(4000, startRequestPrefill("c".repeat(5000)).length)
        assertEquals("Review the recommendations, then start a device request for any you want to pursue.", recommendFooter(true))
        assertFalse(recommendFooter(false).contains("device request"))
        assertEquals("Start a device request for Synthetic Client", startRequestLabel("Synthetic Client"))
    }

    /* S7 */

    @Test fun evaluationReport() {
        val b = evalSectionsBody(mapOf("background" to "B", "findings" to "F"))
        assertEquals("B", b.getString("background")); assertEquals("", b.getString("recommendations")); assertEquals(4, b.length())
        assertEquals("to", evalFieldFor("to")); assertEquals("findings", evalFieldFor("findings")); assertEquals("", evalFieldFor("other"))
        assertEquals("Email the signed evaluation report to vrc@example.org?", evalConfirmTitle(" vrc@example.org "))
        assertEquals("Evaluation report for the assessment of Jan 5, 2026", evalButtonLabel("Jan 5, 2026"))
        val r = JSONObject("""{"ok":true,"sections":[{"key":"background","label":"Background","text":"Seen on 2026-01-05.","default":true},{"key":"findings","label":"Evaluation findings","text":"","default":false}]}""")
        assertEquals(mapOf("background" to "Seen on 2026-01-05.", "findings" to ""), evalSections(r))
        val err = JSONObject("""{"ok":false,"error":"Replace the placeholder [CLIENT] with the actual detail before emailing.","errors":{"findings":"Replace the placeholder [CLIENT] with the actual detail before emailing.","signature":"Type your full name (Synthetic Instructor) to sign before emailing."}}""")
        val m = mapErrors(err, ::evalFieldFor)
        assertTrue(m["findings"]!!.contains("[CLIENT]")); assertTrue(m["signature"]!!.startsWith("Type your full name"))
    }

    /* S8 */

    @Test fun worksiteDraftSendsTheTypedFields() {
        val b = worksiteAiBody(3, "Clerk", "Filing", "Glare", "Typed.")
        assertEquals(3, b.getInt("id")); assertEquals("Clerk", b.getString("job_title")); assertEquals("Filing", b.getString("job_tasks"))
        assertEquals("Glare", b.getString("barriers")); assertEquals("Typed.", b.getString("recommendations"))
    }

    /* S9 */

    @Test fun formatWithAi() {
        val b = formatBody(5, "SOAP", "Narrative.", listOf("S", "", "A"))
        assertEquals("format", b.getString("type")); assertEquals("SOAP", b.getString("format")); assertEquals(4, b.getJSONArray("sections").length())
        assertEquals("", b.getJSONArray("sections").getString(3))
        assertEquals(listOf("new S", "O typed", "A", "P"), applyFormatted(listOf("S", "O typed", "A", ""), listOf("new S", "", "  ", "P")))
        assertEquals("Suggest goals with AI for the assessment of Jan 5, 2026", goalsButtonLabel("Jan 5, 2026"))
        assertTrue(formattedMessage("BIRP").startsWith("Formatted as BIRP — review each section, then save."))
    }

    /* S10 */

    @Test fun reviewGoals() {
        val g = parseReviewGoals(JSONObject("""{"ok":true,"goals":[{"id":11,"text":"Use JAWS to read email","track_id":2,"track":"Synthetic track","status":"In Progress","pct":40}]}"""))!!
        assertEquals("Use JAWS to read email, Synthetic track: 40% (In Progress)", goalRowText(g[0]))
        assertEquals("Record progress for Use JAWS to read email", recordProgressLabel(g[0]))
        assertEquals(60, recordProgressLabel(g[0].copy(text = "x".repeat(100))).removePrefix("Record progress for ").length)
        assertNull(parseReviewGoals(JSONObject("""{"ok":true,"text":"x"}""")))
        assertEquals("Incomplete: this review was cut short, so goals after the last one it mentions were not reviewed.", progressNotice())
    }

    /* S11 */

    @Test fun templatesFillOnlyEmptyFieldsAndAskOverTypedOnes() {
        val tpl = mapOf("primary_tech" to "Screen Reader", "hardware_details" to "Laptop", "software_version" to "JAWS 2026", "summary" to "Template summary")
        val empty = templateFill(mapOf("primary_tech" to "", "hardware_details" to "", "software_version" to "", "summary" to ""), tpl)
        assertFalse(empty.asks); assertEquals("Laptop", empty.values["hardware_details"]); assertEquals("Screen Reader", empty.values["primary_tech"])
        val typed = templateFill(mapOf("primary_tech" to "", "hardware_details" to "Desktop", "software_version" to "", "summary" to ""), tpl)
        assertTrue(typed.asks); assertEquals("Desktop", typed.values["hardware_details"]); assertEquals("JAWS 2026", typed.values["software_version"])
        assertEquals("Laptop", templateReplace(mapOf("hardware_details" to "Desktop"), tpl)["hardware_details"])
        assertEquals("Replace what you typed with the template Synthetic template?", templateAsk("Synthetic template"))
        assertTrue(attachmentAllowed("Report.PDF")); assertTrue(attachmentAllowed("scan.jpeg")); assertFalse(attachmentAllowed("tool.exe")); assertFalse(attachmentAllowed("noext"))
        assertFalse(attachmentAllowed("photo.heic"))
    }

    /* S12 */

    @Test fun amendmentLetter() {
        val m = amendmentMailto("vrc@example.org", "Synthetic Client", "Dear counselor,\nMore hours.")
        assertTrue(m.startsWith("mailto:vrc%40example.org?subject=Authorization%20amendment%20request%20%E2%80%94%20Synthetic%20Client&body="))
        assertTrue(m.contains("Dear%20counselor%2C%0AMore%20hours."))
        assertTrue(replaceDraftAsk(true, separateBox = false)); assertFalse(replaceDraftAsk(true, separateBox = true)); assertFalse(replaceDraftAsk(false))
        val lines = amendmentBurnDown(JSONObject("""{"worked":30,"authorized":40,"pct":75,"remaining":10,"weekly_rate":2.5,"run_out":"2026-03-01","expiration":"2026-06-30","suggest_add":10,"funds_authorized":0}"""))
        assertEquals("Hours used: 30.00 of 40.00 hours (75%)", lines[0]); assertEquals("Recent pace: 2.50 hours per week", lines[2])
        assertEquals("Suggested top-up: 10 hours", lines.last())
    }

    /* S13 */

    @Test fun amendmentRequestAndReply() {
        val b = recordAmendmentBody(4, " 2026-01-05 ", "VR <vrc@example.org>", "10", "", "", "Letter")
        assertEquals(4, b.getInt("track_id")); assertEquals("2026-01-05", b.getString("requested_on")); assertEquals("10", b.getString("hours")); assertEquals("Letter", b.getString("letter"))
        assertEquals("hours", amendmentFieldFor("hours")); assertEquals("", amendmentFieldFor("bogus"))
        val r = replyBody(4, 8, "Approved", "2026-01-20", " ok ")
        assertEquals(8, r.getInt("request_id")); assertEquals("Approved", r.getString("status")); assertEquals("ok", r.getString("note"))
        val h = parseAmendmentHistory(JSONObject("""{"history":[{"id":8,"requested_on":"2026-01-05","status":"Approved","text":"Requested Jan 5, 2026: 10 more hours. Approved.","awaiting":false,"needs_change":true}]}"""))!!
        assertTrue(h[0].needsChange); assertFalse(h[0].awaiting)
        assertNull(parseAmendmentHistory(JSONObject("{}")))
        assertEquals("trackEdit/2/4?amend=8&reason=VR%20Approved%20the%20amendment", trackEditRoute(2, 4, 8, "VR Approved the amendment"))
    }

    /* S14 */

    @Test fun letters() {
        val b = letterBody(3, "doc", " Welcome ", "Dear Synthetic,", "es", true)
        assertEquals("doc", b.getString("how")); assertEquals("Welcome", b.getString("title")); assertEquals("es", b.getString("lang")); assertTrue(b.getBoolean("to_portal"))
        assertEquals("body", letterFieldFor("body")); assertEquals("", letterFieldFor("x"))
        assertNull(switchLetterAsk(false, "Welcome letter"))
        assertEquals("Open the Missed appointment instead? Your changes to this letter are lost.", switchLetterAsk(true, "Missed appointment"))
        assertEquals(7, LETTERS_BUILT_IN.size)
        assertEquals("It still contains the placeholder [CLIENT]: replace it with the actual detail before sending.", placeholderNote("Dear [CLIENT], [not on file]"))
        assertNull(placeholderNote("Address: [not on file]."))
    }

    /* S15 */

    @Test fun releasesAndConsent() {
        val base = JSONObject().put("client_id", 3).put("signer_name", "Synthetic Client")
        val paper = releaseBody("Paper", " 2026-01-05 ", base)
        assertEquals("Paper", paper.getString("method")); assertEquals("2026-01-05", paper.getString("signed_on"))
        val email = releaseBody("Email", "2026-01-05", base)
        assertEquals("Email", email.getString("method")); assertFalse(email.has("signed_on")); assertFalse(base.has("method"))
        assertEquals("Record that the release for Synthetic Agency is withdrawn?", withdrawReleaseTitle("Synthetic Agency"))
        assertEquals("Record that Synthetic Guardian has withdrawn the guardian consent signed on Jan 5, 2026? The consent stops counting as current, and a new one can then be requested.",
            consentWithdrawText("Synthetic Guardian", "Jan 5, 2026"))
        assertEquals("name", releaseFieldFor("signer_name")); assertEquals("signed_on", releaseFieldFor("signed_on"))
    }

    /* S16 */

    @Test fun uploadsFollowTheWebsite() {
        assertTrue(uploadAllowed("pdf")); assertTrue(uploadAllowed(".XLSX")); assertFalse(uploadAllowed("heic")); assertFalse(uploadAllowed("exe"))
        assertTrue(isHeic("HEIC")); assertTrue(isHeic("heif")); assertFalse(isHeic("jpg"))
        assertFalse("heic" in UPLOAD_TYPES)
        assertEquals("Move the document \"Synthetic report\" to the trash?", trashConfirmTitle("Synthetic report"))
        assertEquals("Move Synthetic report to the trash", deleteButtonLabel("Synthetic report"))
        assertTrue(parseDocuments(JSONObject("""{"documents":[{"id":1,"title":"A","category":"Other","can_delete":true},{"id":2,"title":"B","category":"IPE","can_delete":false}]}""")).map { it.canDelete } == listOf(true, false))
    }

    /* S17 */

    @Test fun portalConfirmationIsTheServers() {
        val r = JSONObject("""{"client":{},"portal_link":{"state":"active","until":"2026-07-01","text":"A portal link is active until Jul 1, 2026.","confirm_text":"Email Synthetic Client a private portal link? Any previous link will stop working. This client's case is closed, so their portal is read-only."}}""")
        assertTrue(portalConfirm(r, "fallback").contains("read-only"))
        assertEquals("fallback", portalConfirm(JSONObject("{}"), "fallback"))
        assertEquals("Turn off Synthetic Client's portal link?", portalOffTitle("Synthetic Client"))
    }

    /* S18 */

    @Test fun timelineGroupsByMonthInOrder() {
        val ev = parseTimeline(JSONObject("""{"events":[{"date":"2026-02-03","month":"February 2026","kind":"note","text":"Case note"},
            {"date":"2026-02-01","month":"February 2026","kind":"session","text":"Session"},{"date":"2026-01-20","month":"January 2026","kind":"status","text":"Active"}]}"""))
        val g = timelineGroups(ev)
        assertEquals(listOf("February 2026", "January 2026"), g.map { it.first }); assertEquals(2, g[0].second.size)
    }

    /* S19 */

    @Test fun referralFilters() {
        assertEquals(mapOf("status" to "open", "who" to "me"), referralsQuery("open", "me"))
        assertEquals("Showing the newest 100 of 140 matching referrals. Narrow the filters to find older ones.", referralsCutText(100, 140))
        assertNull(referralsCutText(4, 4))
        assertEquals("4 open referrals.", referralsCountText("open", 4)); assertEquals("1 referral.", referralsCountText("all", 1))
        assertEquals(listOf("Open (New or Reviewed)", "Converted", "Closed without a client", "All statuses"), REFERRAL_STATUSES.map { it.second })
        assertEquals(listOf("All referrals", "Assigned to me", "Unassigned"), REFERRAL_WHO.map { it.second })
        assertEquals("Contact overdue since Jan 4, 2026", parseReferrals(JSONObject("""{"referrals":[{"id":1,"client_name":"Synthetic Client","status":"New","contact_text":"Contact overdue since Jan 4, 2026"}]}"""))[0].contactText)
    }

    /* S20 */

    @Test fun pipelineClosed() {
        val c = parsePipelineClosed(JSONObject("""{"closed":[{"id":3,"name":"Synthetic Client","status":"Closed - Successful"}]}"""))!!
        assertEquals(ClosedClient(3, "Synthetic Client", "Closed - Successful"), c[0])
        assertNull(parsePipelineClosed(JSONObject("{}")))
        assertEquals("Closed in last 90 days (1)", closedHeading(1))
    }

    /* S21 */

    @Test fun cohorts() {
        val b = cohortBody(" Synthetic cohort ", "", 0, "2026-09-01", "", " Notes ")
        assertEquals("Synthetic cohort", b.getString("name")); assertEquals(0, b.getInt("coordinator_id")); assertEquals("Notes", b.getString("notes"))
        assertEquals("coordinator_id", cohortFieldFor("coordinator_id")); assertEquals("", cohortFieldFor("x"))
        assertEquals("Delete the session Workplace Readiness, Jan 5, 2026, and its attendance?", deleteSessionTitle("Workplace Readiness", "Jan 5, 2026"))
        assertEquals("Delete session: Workplace Readiness, Jan 5, 2026", deleteSessionLabel("Workplace Readiness", "Jan 5, 2026"))
    }

    /* S22 */

    @Test fun instructorMatching() {
        assertEquals(mapOf("expertise" to "JAWS", "languages" to "Spanish", "areas" to "", "date" to "2026-01-05", "modality" to "Remote"),
            matchesBody(" JAWS ", "Spanish", "", "2026-01-05", "Remote"))
        val m = JSONObject("""{"id":1,"name":"Synthetic Instructor","matched":"jaws","missing":"","available_hours":37.5,"capacity_hours":40.0,"availability":"Weekdays"}""")
        assertEquals(listOf("Synthetic Instructor", "Matched: jaws. Missing: None.", "37.5 estimated hours available of 40 this week. Weekdays"), matchLine(m))
        assertEquals("Matched: None specified. Missing: braille.", matchLine(JSONObject("""{"name":"X","matched":"","missing":"braille","available_hours":0,"capacity_hours":0}"""))[1])
        assertEquals("No available instructor profiles.", matchesCount(0)); assertEquals("1 matching instructor", matchesCount(1)); assertEquals("3 matching instructors", matchesCount(3))
    }

    /* S23 */

    @Test fun knowledgeBaseAsk() {
        val b = kbBody(" How do I pair a display? ", true)
        assertEquals("How do I pair a display?", b.getString("question")); assertTrue(b.getBoolean("general"))
        assertEquals("Answer (general knowledge, not from your articles)", kbCaption(true)); assertEquals("Answer", kbCaption(false))
        val miss = JSONObject("""{"ok":false,"error":"No article covers this yet.","errors":{"question":"No article covers this yet."},"steps":["Try fewer or more general words, for example the product name or the task.","Add an article on the Knowledge page, then ask again.","Or tick “answer from general knowledge” above and ask again."]}""")
        assertEquals(KB_STEPS_BUILT_IN, miss.stringList("steps"))
    }

    /* S24 */

    @Test fun translateOther() {
        assertEquals("Hmong", translateTarget("other", " Hmong "))
        assertEquals("Somali", translateTarget("so", "ignored"))
        assertEquals("language", translateFieldFor("language")); assertEquals("content", translateFieldFor("content")); assertEquals("", translateFieldFor("x"))
    }

    /* S25 */

    @Test fun remediation() {
        val b = remediateBody("largeprint", "Text", 5)
        assertEquals(5, b.getInt("client_id")); assertEquals("largeprint", b.getString("mode"))
        assertFalse(remediateBody("plain", "Text", null).has("client_id"))
        assertEquals("Targeting the client's preferred format: Large print.", remediateTarget("Large print"))
        assertNull(remediateTarget(null))
        assertEquals(listOf("plain", "simplify", "summary", "largeprint", "alt"), REMEDIATE_MODES.map { it.first })
    }

    /* S26 */

    @Test fun kbBlocksFollowTheIphoneRules() {
        val b = kbBlocks("## Setup\nFirst line\nsecond line\n\n1. Plug it in\n2. Turn it on\ncontinued\n- A bullet\n\n---\nEnd")
        assertEquals(KbBlock.Heading("Setup"), b[0])
        assertEquals(KbBlock.Paragraph("First line\nsecond line"), b[1])
        assertEquals(KbBlock.Item("1.", "Plug it in"), b[2])
        assertEquals(KbBlock.Item("2.", "Turn it on\ncontinued"), b[3])
        assertEquals(KbBlock.Item("•", "A bullet"), b[4])
        assertEquals(KbBlock.Paragraph("End"), b[5])
        assertEquals(KbBlock.Item("3.", "Third"), kbBlocks("3. Third")[0])
        assertEquals("Braille · updated Jan 5, 2026 · Synthetic Author", kbMeta("Braille", "2026-01-05 10:00:00", "Synthetic Author"))
        assertEquals("updated Jan 5, 2026", kbMeta(null, "2026-01-05", null))
        assertEquals("No articles match your search.", kbEmptyText(true)); assertEquals("No articles yet.", kbEmptyText(false))
    }

    @Test fun flagsReadBooleansAndNumbers() {
        val o = JSONObject("""{"a":true,"b":1,"c":0,"d":"1","e":null}""")
        assertTrue(flag(o, "a")); assertTrue(flag(o, "b")); assertFalse(flag(o, "c")); assertTrue(flag(o, "d")); assertFalse(flag(o, "e")); assertTrue(flag(o, "missing", true))
        assertEquals(listOf("x"), JSONObject().put("l", JSONArray().put("x").put("")).stringList("l"))
    }

    /* Review fixes: the decisions the screens make, as pure functions. */

    @Test fun anEditedAmendmentLetterIsNeverReplaced() {
        val server = "Server letter."
        // Edited, and the server keeps it: the draft goes in the separate box; the letter stays edited.
        val sep = amendmentDraftOutcome("My letter.", "My letter.", server, true, JSONObject("""{"letter":"My letter.","ai_draft_text":"AI letter.","ai_note":"Your letter is unchanged."}"""))
        assertEquals(LetterDraft("My letter.", server, "AI letter."), sep)
        // Edited, and no ai_draft_text (the AI failed or is off): nothing changes, still edited.
        val failed = amendmentDraftOutcome("My letter.", "My letter.", server, true, JSONObject("""{"letter":"Server letter.","ai_note":"The AI is not available."}"""))
        assertEquals(LetterDraft("My letter.", server, null), failed)
        // An older server, after "Replace with a new draft": replaced.
        assertEquals(LetterDraft("AI letter.", "AI letter.", null), amendmentDraftOutcome("My letter.", "My letter.", server, false, JSONObject("""{"letter":"AI letter."}""")))
        // Unedited: the draft is the letter; text typed meanwhile is kept with the draft below.
        assertEquals(LetterDraft("AI letter.", "AI letter.", null), amendmentDraftOutcome(server, server, server, true, JSONObject("""{"letter":"AI letter."}""")))
        assertEquals(LetterDraft("Server letter. More\nAI letter.", server, null), amendmentDraftOutcome(server, "Server letter. More", server, true, JSONObject("""{"letter":"AI letter."}""")))
        // An empty reply letter changes nothing.
        assertEquals(LetterDraft(server, server, null), amendmentDraftOutcome(server, server, server, true, JSONObject("""{"letter":""}""")))
    }

    @Test fun textTypedWhileTheAiWorksIsKept() {
        assertEquals("Typed.\nDraft.", mergeAfterDraft("Typed.", "Typed.", "Typed.\nDraft.", "Draft."))
        assertEquals("Typed. More\nDraft.", mergeAfterDraft("Typed.", "Typed. More", "Typed.\nDraft.", "Draft."))
        assertEquals("Typed. More", mergeAfterDraft("Typed.", "Typed. More", "Typed.", ""))
        val sent = mapOf("background" to "Default.", "findings" to "Mine.")
        val now = mapOf("background" to "Default. Added", "findings" to "Mine.")
        val got = mapOf("background" to "AI background.", "findings" to "Mine.")
        assertEquals(mapOf("background" to "Default. Added\nAI background.", "findings" to "Mine."), mergeSections(sent, now, got))
        assertEquals(mapOf("background" to "AI background.", "findings" to "Mine."), mergeSections(sent, sent, got))
    }

    @Test fun aSendThatWorkedClosesWithTheServersSentence() {
        assertEquals("Evaluation report emailed to vrc@example.org and logged on the client's file.",
            closeAfterSend(JSONObject("""{"ok":true,"message":"Evaluation report emailed to vrc@example.org and logged on the client's file."}"""), "fallback"))
        assertEquals("The letter was kept as a case note.", closeAfterSend(JSONObject("""{"ok":true}"""), "The letter was kept as a case note."))
        assertEquals("fallback", closeAfterSend(null, "fallback"))
    }

    @Test fun referralHeadingIsNotCountedTwice() {
        assertEquals("Open referrals (4)", referralsHeadingText("Open referrals (4)", "open", 4))
        assertEquals("Converted referrals (2)", referralsHeadingText(null, "converted", 2))
        assertEquals("1 open referral.", referralsCountText("open", 1))
    }

    @Test fun historyRowsCarryTheirReasonPrefill() {
        val h = parseAmendmentHistory(JSONObject("""{"history":[{"id":8,"requested_on":"2026-01-05","status":"Approved","text":"x","awaiting":false,"needs_change":true,
            "reason_prefill":"VR Approved the amendment requested 2026-01-05 (reply 2026-01-20)"},{"id":9,"status":"","text":"y","awaiting":true,"needs_change":false}]}"""))!!
        assertEquals("VR Approved the amendment requested 2026-01-05 (reply 2026-01-20)", h[0].reasonPrefill)
        assertEquals("", h[1].reasonPrefill)
    }

    @Test fun amendmentDatesAreTodayOrEarlier() {
        assertNull(pastDateError("2026-01-05", "2026-01-05", SENT_ON_RULE))
        assertEquals(SENT_ON_RULE, pastDateError("2026-01-06", "2026-01-05", SENT_ON_RULE))
        assertEquals(REPLIED_ON_RULE, pastDateError("2026-02-30", "2026-03-05", REPLIED_ON_RULE))
        assertEquals(REPLIED_ON_RULE, pastDateError("", "2026-03-05", REPLIED_ON_RULE))
    }

    @Test fun filesAndPhotos() {
        assertEquals(1.0, heicScale(3000, 2000, 4096), 0.0)
        assertEquals(0.5, heicScale(8192, 6000, 4096), 0.0001)
        assertEquals("letter-3-Welcome_letter.html", safeFileName("letter-3", "Welcome/letter.html".replace("/", "_"), "x"))
        assertEquals("evaluation-4-report.html", safeFileName("evaluation-4", "../../report.html", "x"))
        assertEquals("assessment-2-attachment", safeFileName("assessment-2", null, "attachment"))
        assertEquals("assessment-2-attachment", safeFileName("assessment-2", "..", "attachment"))
        val html = letterHtml("Welcome <letter>", "Dear Synthetic,\n\nLine one\nline two", "es")
        assertTrue(html.contains("<html lang=\"es\">")); assertTrue(html.contains("<title>Welcome &lt;letter&gt;</title>"))
        assertTrue(html.contains("<p>Dear Synthetic,</p>")); assertTrue(html.contains("<p>Line one<br>line two</p>"))
        assertEquals("Language of the letter (the wording below is in English)", letterLangLabel(false))
        assertTrue(letterLangLabel(true).contains("this client prefers Spanish"))
        assertEquals("5 estimated hours available of 10 this week.", matchLine(JSONObject("""{"name":"X","available_hours":5,"capacity_hours":10,"availability":null}"""))[2])
    }

    /* Every dialog is the lock-aware one (LockAware.kt). */

    @Test fun noDialogOutsideLockAwareShowsAboveTheAppLock() {
        val dir = File("src/main/java/org/casemanagement/app/ui")
        val files = dir.listFiles()!!.filter { it.name.endsWith(".kt") && it.name != "LockAware.kt" }
        val offenders = files.filter { f ->
            val t = f.readText()
            val dialogs = Regex("BasicAlertDialog|ModalBottomSheet|BottomSheetScaffold|material3\\.AlertDialog").containsMatchIn(t) && f.name != "RootScreen.kt"
            // A full-screen window.Dialog is allowed only when its content is hidden while locked (the signing screen) or it is the lock itself.
            val window = (t.contains("ui.window.Dialog") || Regex("(?<![A-Za-z.])Dialog\\(").containsMatchIn(t)) && f.name != "RootScreen.kt" && !t.contains("hiddenWhileLocked()")
            dialogs || window
        }.map { it.name }
        assertEquals(emptyList<String>(), offenders)
    }

    /* Every dialog in this release's files is the lock-aware one (LockAware.kt). */

    @Test fun noDialogInThisReleaseShowsAboveTheAppLock() {
        val dir = File("src/main/java/org/casemanagement/app/ui")
        assertTrue("run from the app module", dir.isDirectory)
        val changed = listOf("V437Helpers.kt", "V437Screens.kt", "EquipmentScreen.kt", "ProcurementScreens.kt", "TechAssessments.kt", "TrackScreen.kt",
            "ClientsScreen.kt", "SignaturesScreen.kt", "DocumentsScreen.kt", "SignatureAndUpload.kt", "ReferralScreen.kt", "TasksScreen.kt",
            "ReportsScreens.kt", "ProgramsScreens.kt", "AtServicesScreen.kt", "AiScreens.kt", "MoreTools.kt")
        changed.forEach { name ->
            val t = File(dir, name).readText()
            assertFalse(name, t.contains("androidx.compose.material3.AlertDialog") || (t.contains("import androidx.compose.material3.*") && t.contains("AlertDialog(")))
        }
        // No separate hint line in the new screens: hints sit inside the labels.
        assertFalse(File(dir, "V437Screens.kt").readText().contains("supportingText"))
    }
}
