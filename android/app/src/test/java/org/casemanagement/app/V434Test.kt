package org.casemanagement.app

import org.casemanagement.app.ui.FOLLOWUP_ANSWERS
import org.casemanagement.app.ui.TRANSLATE_LANGS
import org.casemanagement.app.ui.aiNotices
import org.casemanagement.app.ui.aiReviewWords
import org.casemanagement.app.ui.appLockWords
import org.casemanagement.app.ui.appendDraftBelow
import org.casemanagement.app.ui.bookableFilter
import org.casemanagement.app.ui.calendarButtonName
import org.casemanagement.app.ui.checkInWindow
import org.casemanagement.app.ui.codePointCount
import org.casemanagement.app.ui.consentRowWords
import org.casemanagement.app.ui.defaultReturnDate
import org.casemanagement.app.ui.duplicateRefusal
import org.casemanagement.app.ui.followupRecordedWords
import org.casemanagement.app.ui.hoursRule
import org.casemanagement.app.ui.incidentDateNotFuture
import org.casemanagement.app.ui.loanConditionOptions
import org.casemanagement.app.ui.moneyText
import org.casemanagement.app.ui.passwordErrorKeys
import org.casemanagement.app.ui.pastOrToday
import org.casemanagement.app.ui.percentInCents
import org.casemanagement.app.ui.reopenedWords
import org.casemanagement.app.ui.reportSentMessage
import org.casemanagement.app.ui.reservationCancelledWords
import org.casemanagement.app.ui.returnConditionOptions
import org.casemanagement.app.ui.roleWords
import org.casemanagement.app.ui.rsaDefaultFor
import org.casemanagement.app.ui.scheduleCutText
import org.casemanagement.app.ui.searchGroupHeading
import org.casemanagement.app.ui.serverFieldErrors
import org.casemanagement.app.ui.sessionActions
import org.casemanagement.app.ui.trackFormDefaults
import org.casemanagement.app.ui.translateLangName
import org.json.JSONObject
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test
import org.casemanagement.app.ui.completionBody
import org.casemanagement.app.ui.logHoursFieldErrors
import org.casemanagement.app.ui.loggedChoiceLabel
import org.casemanagement.app.ui.reportFieldErrors
import org.casemanagement.app.ui.reportPayload

/** 4.34.0: the apps behave as the website does (PARITY-AUDIT-2026-11-19). Made-up names only. */
class V434Test {
    /* ---------- P1, P2, P5: case notes ---------- */

    @Test fun parseNoteReadsEditFields() {
        val n = parseNote(JSONObject("""{"id":7,"subject":"Phone call","note_type":"Case Note","created_at":"2026-11-01 10:00:00","author":"Synthetic Staff",
            "body":"B: x","locked":true,"can_edit":false,"addendum_to":null,"note_version":"abc123","track_id":4,"note_format":"BIRP",
            "sections":["one","two","three","four"],"editor":"Other Staff","track_title":"Screen reader basics"}"""))
        assertTrue(n.locked); assertFalse(n.canEdit); assertNull(n.addendumTo)
        assertEquals("abc123", n.noteVersion); assertEquals(4, n.trackId); assertEquals("BIRP", n.noteFormat)
        assertEquals(listOf("one", "two", "three", "four"), n.sections)
        assertEquals("Other Staff", n.editor); assertEquals("Screen reader basics", n.trackTitle)
        // An older server's note (none of the new keys) still reads, as a free-text note.
        val old = parseNote(JSONObject("""{"id":3,"subject":"S","note_type":"Case Note","body":"x"}"""))
        assertFalse(old.locked); assertFalse(old.canEdit); assertEquals("free", old.noteFormat); assertTrue(old.sections.isEmpty())
    }

    @Test fun addendumSubject() {
        assertEquals("Addendum: Phone call", addendumSubject("Phone call"))
        assertEquals("Addendum: Phone call", addendumSubject("Addendum: Phone call"))
        assertEquals("Addendum: Phone call", addendumSubject("  Phone call "))
    }

    @Test fun notesHeading() {
        assertEquals("Case notes (12)", notesHeading(12, 12))
        assertEquals("Case notes (latest 50 of 73)", notesHeading(50, 73))
        assertEquals("Case notes (0)", notesHeading(0, 0))
    }

    @Test fun freeTextWithSectionsIsRefused() {
        assertTrue(sectionsLost("free", listOf("", "kept text", "", "")))
        assertFalse(sectionsLost("free", listOf("", " ", "", "")))
        assertFalse(sectionsLost("BIRP", listOf("a", "b", "c", "d")))
        assertTrue(FREE_TEXT_SECTIONS_LOST.startsWith("Free text is chosen as the note format"))
    }

    @Test fun noteSavedMessage() {
        assertEquals("Case note saved. 1 homework item added to the client portal.", noteSavedMessage(JSONObject("""{"ok":true,"homework_created":1}""")))
        assertEquals("Case note saved. 2 homework items added to the client portal.", noteSavedMessage(JSONObject("""{"ok":true,"homework_created":2,"warning":null}""")))
        assertEquals("Case note saved. That session is not completed, so the note was saved on its own.",
            noteSavedMessage(JSONObject("""{"ok":true,"homework_created":0,"warning":"That session is not completed, so the note was saved on its own."}""")))
        // Kept offline: no reply, so the queued words stay.
        assertNull(noteSavedMessage(null))
    }

    /* ---------- P3, P4, P6: the client form and consent ---------- */

    @Test fun ipeDateRule() {
        assertTrue(pastOrToday("", "2026-11-19"))
        assertTrue(pastOrToday("2026-11-19", "2026-11-19"))
        assertTrue(pastOrToday("2020-02-29", "2026-11-19"))
        assertFalse(pastOrToday("2026-11-20", "2026-11-19"))
        assertFalse(pastOrToday("2021-02-30", "2026-11-19"))
        assertFalse(pastOrToday("11/19/2026", "2026-11-19"))
    }

    @Test fun duplicateRefusalIsRead() {
        assertTrue(duplicateRefusal(409, JSONObject("""{"ok":false,"duplicate":true,"error":"x","errors":{"name":"x"}}""")))
        assertFalse(duplicateRefusal(409, JSONObject("""{"ok":false,"conflict":true}""")))
        assertFalse(duplicateRefusal(422, JSONObject("""{"ok":false,"duplicate":true}""")))
        assertFalse(duplicateRefusal(409, null))
    }

    @Test fun fieldErrorsFromA422() {
        val r = JSONObject("""{"ok":false,"error":"Guardian name is required.","errors":{"guardian_name":"Guardian name is required.","guardian_email":"Enter a valid guardian email address."}}""")
        assertEquals(mapOf("guardian_name" to "Guardian name is required.", "guardian_email" to "Enter a valid guardian email address."), serverFieldErrors(r))
        // No errors map: the message is tied by how it starts.
        assertEquals(mapOf("ipe_date" to "IPE date: enter a valid date (YYYY-MM-DD), today or earlier."),
            serverFieldErrors(JSONObject("""{"ok":false}"""), listOf("IPE date" to "ipe_date"), "IPE date: enter a valid date (YYYY-MM-DD), today or earlier."))
        assertNull(serverFieldErrors(null, emptyList(), "Something else."))
    }

    /* ---------- P7: search ---------- */

    @Test fun searchGroupHeading() {
        assertEquals("Notes (3)", searchGroupHeading("Notes", 3, false))
        assertEquals("Notes (first 25 shown, more match)", searchGroupHeading("Notes", 25, true))
    }

    /* ---------- P11, P35: the track form and the report ---------- */

    @Test fun trackFormDefaults() {
        val f = JSONObject("""{"service_types":["Assistive Technology","Orientation and Mobility"],"rsa_defaults":{"Assistive Technology":"Rehabilitation Technology","Orientation and Mobility":"Orientation and Mobility"},
            "billing_modes":[{"value":"vr","label":"VR or agency authorization"},{"value":"private","label":"Private pay (the client pays)"},{"value":"none","label":"No charge"}],
            "private_rate":"55.00","defaults":{"billing_mode":"private","hourly_rate":"40.00"}}""")
        val d = trackFormDefaults(f)
        assertEquals("private", d["billing_mode"]); assertEquals("40.00", d["hourly_rate"]); assertEquals("0", d["mileage_rate"])
        assertEquals("Assistive Technology", d["service_type"]); assertEquals("Rehabilitation Technology", d["rsa_category"])
        assertEquals("Orientation and Mobility", rsaDefaultFor(f, "Orientation and Mobility"))
        // An older server: VR, no rate, the server's own RSA default.
        val old = trackFormDefaults(JSONObject("""{"service_types":["Assistive Technology"]}"""))
        assertEquals("vr", old["billing_mode"]); assertEquals("", old["hourly_rate"]); assertEquals("", old["rsa_category"])
        assertNull(rsaDefaultFor(JSONObject(), "Assistive Technology"))
    }

    @Test fun appendDraftBelow() {
        assertEquals("Draft.", appendDraftBelow("", "Draft."))
        assertEquals("Mine.\n\nDraft.", appendDraftBelow("Mine.  ", " Draft."))
        assertEquals("Mine.", appendDraftBelow("Mine.", "  "))
    }

    @Test fun reportSentWords() {
        assertEquals("“Final report” was signed and emailed to vrc@example.org. A copy was sent to you. A copy is in the client's portal.",
            reportSentMessage(JSONObject("""{"ok":true,"sent_to":"vrc@example.org","report_subject":"Final report","copied":true,"portal_shared":true,"release_warning":null}""")))
        assertEquals("The report was signed and emailed to vrc@example.org.", reportSentMessage(JSONObject("""{"ok":true,"sent_to":"vrc@example.org"}""")))
    }

    /* ---------- P13, P15: booking and the schedule ---------- */

    @Test fun bookableFilter() {
        val rows = listOf(JSONObject("""{"id":1,"status":"Waitlist","bookable":false}"""), JSONObject("""{"id":2,"status":"Active Training","bookable":true}"""),
            JSONObject("""{"id":3,"status":"Closed - Other"}"""), JSONObject("""{"id":4,"status":"Waitlist"}"""))
        // The server's flag when sent; otherwise (an older server) the Closed rule.
        assertEquals(listOf(2, 4), bookableFilter(rows).map { it.optInt("id") })
    }

    @Test fun sessionActionsBeforeStart() {
        val start = 1_000_000_000L; val end = start + 3_600_000L
        val before = sessionActions("Scheduled", start, end, start - 3_600_000L, hasTrack = true)
        assertFalse("complete" in before); assertFalse("no_show" in before); assertFalse("check_in" in before)
        assertTrue("move" in before); assertTrue("cancel" in before); assertTrue("hours" in before)
        val during = sessionActions("Scheduled", start, end, start + 60_000L, hasTrack = false)
        assertTrue("complete" in during); assertTrue("no_show" in during); assertTrue("check_in" in during); assertFalse("hours" in during)
        // Ended and waiting for an outcome: Move is still offered, as on the website.
        val after = sessionActions("Scheduled", start, end, end + 3_600_000L, hasTrack = true)
        assertTrue("move" in after); assertTrue("complete" in after); assertFalse("check_in" in after)
        assertTrue(sessionActions("Completed", start, end, end + 1, hasTrack = true).isEmpty())
        assertFalse("check_in" in sessionActions("Scheduled", start, end, start, hasTrack = true, checkedIn = true))
    }

    @Test fun checkInWindow() {
        val start = 1_000_000_000L; val end = start + 3_600_000L
        assertFalse(checkInWindow(start, end, start - 31 * 60_000L))
        assertTrue(checkInWindow(start, end, start - 30 * 60_000L))
        assertTrue(checkInWindow(start, end, end))
        assertFalse(checkInWindow(start, end, end + 1))
        assertFalse(checkInWindow(null, end, start))
    }

    @Test fun scheduleCut() {
        assertEquals("Showing the next 100 of 140 sessions.", scheduleCutText(100, 140))
        assertNull(scheduleCutText(40, 40))
    }

    /* ---------- P16: log hours ---------- */

    @Test fun hoursRule() {
        assertNull(hoursRule(1.5, 0.0))
        assertNull(hoursRule(0.0, 12.0))
        assertNull(hoursRule(1.25, null))
        assertTrue(hoursRule(0.0, 0.0)!!.contains("0 with miles for travel only"))
        assertTrue(hoursRule(25.0, 0.0) != null)
        assertTrue(hoursRule(null, 0.0) != null)
        assertTrue(hoursRule(1.255, 0.0)!!.contains("two decimals"))
    }

    /* ---------- P18, P37, P38: equipment ---------- */

    @Test fun followupAnswerChoices() {
        assertEquals(listOf("yes", "no", "unknown"), FOLLOWUP_ANSWERS.map { it.first })
        assertEquals("Recorded. Because the device is no longer in use, a case note and a follow-up task were created.",
            followupRecordedWords(JSONObject("""{"ok":true,"abandoned":true}"""), "Follow-up recorded."))
        assertEquals("Saved, but the task could not be added.", followupRecordedWords(JSONObject("""{"ok":true,"abandoned":true,"warning":"Saved, but the task could not be added."}"""), "x"))
        assertEquals("Kept offline.", followupRecordedWords(null, "Kept offline."))
    }

    @Test fun defaultReturnDate() {
        assertEquals("2026-12-03", defaultReturnDate("2026-11-19", 14))
        assertEquals("2027-01-18", defaultReturnDate("2026-11-19", 60))
        assertEquals("", defaultReturnDate("not a date", 14))
    }

    @Test fun conditionStartsNotRecorded() {
        val out = loanConditionOptions(listOf("New", "Good"))
        assertEquals("" to "Not recorded", out.first()); assertEquals(3, out.size)
        assertEquals("" to "Unchanged", returnConditionOptions(listOf("Good", "Needs Repair")).first())
    }

    @Test fun reservationPromotion() {
        assertEquals("Reservation cancelled. The item is now held for the client who has waited longest on its waitlist.", reservationCancelledWords(JSONObject("""{"ok":true,"promoted":true}""")))
        assertEquals("Reservation cancelled.", reservationCancelledWords(JSONObject("""{"ok":true}""")))
    }

    /* ---------- P19, P20, P21: alerts, tasks, conversations ---------- */

    @Test fun markReadIds() {
        val items = listOf(AlertItem(5, "a", null, true), AlertItem(4, "b", null, false), AlertItem(3, "c", null, true))
        assertEquals("5,3", markReadIds(items))
        assertEquals("Mark these 2 as read", markReadLabel(2, 9))
        assertEquals("Mark all as read", markReadLabel(2, 2))
    }

    @Test fun doneTaskDueText() {
        val done = parseTasks(JSONObject("""{"tasks":[{"id":1,"title":"Call","status":"Done","days_left":-4,"due_date":"2026-11-15"}]}""")).first()
        assertEquals("due 2026-11-15", doneTaskDueText(done))
        val open = parseTasks(JSONObject("""{"tasks":[{"id":2,"title":"Call","status":"Open","days_left":-4,"due_date":"2026-11-15"}]}""")).first()
        assertEquals("overdue by 4 days", doneTaskDueText(open))
        val none = parseTasks(JSONObject("""{"tasks":[{"id":3,"title":"Call","status":"Done","days_left":null,"due_date":null}]}""")).first()
        assertEquals("no due date", doneTaskDueText(none))
    }

    @Test fun startChannel() {
        assertEquals("counselor", startChannel(0, 2))
        assertEquals("client", startChannel(1, 2))
        assertEquals("client", startChannel(0, 0))
        val t = parseMessageThreads(JSONObject("""{"threads":[{"client_id":9,"client_name":"Synthetic Client","unread":3,"unread_client":1,"unread_counselor":2}]}""")).first()
        assertEquals("1 unread from the client, 2 unread from the counselor", threadUnreadWords(t))
        // An older server's single count is the client's.
        val old = parseMessageThreads(JSONObject("""{"threads":[{"client_id":9,"client_name":"Synthetic Client","unread":3}]}""")).first()
        assertEquals(3, old.unreadClient); assertEquals(0, old.unreadCounselor)
    }

    @Test fun counselorChoiceAlwaysOffered() {
        assertEquals(listOf("client" to "The client", "counselor" to "The counselor"), threadChannelOptions(null))
        assertEquals("The counselor, Synthetic Counselor", threadChannelOptions("Synthetic Counselor")[1].second)
    }

    /* ---------- P22, P23, P41: incidents, compliance, invoices ---------- */

    @Test fun incidentDateNotFuture() {
        assertTrue(incidentDateNotFuture("2026-11-19", "2026-11-19"))
        assertTrue(incidentDateNotFuture("2026-01-02", "2026-11-19"))
        assertFalse(incidentDateNotFuture("2026-11-20", "2026-11-19"))
    }

    @Test fun consentRowWords() {
        val adult = consentRowWords(JSONObject("""{"client_id":1,"client":"Synthetic Client","guardian_name":"Synthetic Guardian","kind":"Adult",
            "label":"Signed by a guardian before the client turned 18","date":"2026-03-04","pending_sent_at":null,"action":"Request the client's own consent"}"""))
        assertEquals("Synthetic Client, guardian Synthetic Guardian", adult[0])
        assertEquals("Signed by a guardian before the client turned 18, client turned 18 on Wednesday, March 4, 2026", adult[1])
        assertEquals("Request the client's own consent", adult.last())
        val expired = consentRowWords(JSONObject("""{"client":"Synthetic Client","kind":"Expired","label":"Expired","date":"2026-10-01","action":"Renew consent"}"""))
        assertEquals("Expired, ended Thursday, October 1, 2026", expired[1])
    }

    /* ---------- P26: My Account ---------- */

    @Test fun passwordErrorKeys() {
        val e = JSONObject("""{"current_password":"Your current password is not right.","new_password":"Too short.","confirm_password":"Not the same.","ack_passkeys":"Tick it."}""")
        assertEquals(mapOf("current" to "Your current password is not right.", "new" to "Too short.", "again" to "Not the same.", "ack" to "Tick it."), passwordErrorKeys(e))
        assertTrue(passwordErrorKeys(null).isEmpty())
    }

    @Test fun calendarButtonName() {
        assertEquals("Make a new calendar address", calendarButtonName(true))
        assertEquals("Create my calendar address", calendarButtonName(false))
    }

    @Test fun roleWords() {
        assertEquals("Administrator", roleWords("admin", null))
        assertEquals("Staff, permission group Instructors", roleWords("staff", "Instructors"))
        assertEquals("Staff", roleWords("staff", "null"))
    }

    /* ---------- P28, P29, P32, P33: AI tools ---------- */

    @Test fun askNotices() {
        val r = JSONObject("""{"ok":true,"text":"x","truncated":true,"caseload_cut":true}""")
        val n = aiNotices("ask", r)
        assertEquals(2, n.size)
        assertTrue(n[0].startsWith("Incomplete: the AI stopped before finishing this answer"))
        assertTrue(n[1].startsWith("Note: only part of the caseload fit"))
        assertTrue(aiNotices("ask", JSONObject("""{"ok":true,"text":"x"}""")).isEmpty())
        val risk = aiNotices("risk", JSONObject("""{"ok":true,"period_from":"2026-10-01","period_to":"2026-11-19","truncated":true}"""))
        assertEquals("Period: 2026-10-01 to 2026-11-19.", risk[0])
        assertTrue(risk[1].contains("were not assessed"))
    }

    @Test fun translateSendsNames() {
        assertEquals("Somali", translateLangName("so"))
        assertEquals("Chinese (Simplified)", translateLangName("zh-Hans"))
        assertEquals(11, TRANSLATE_LANGS.size)
    }

    @Test fun codePointCount() {
        assertEquals(5, codePointCount("hello"))
        // An emoji is two UTF-16 units but one character.
        assertEquals(1, codePointCount("😀"))
    }

    @Test fun aiReviewWords() {
        assertTrue(aiReviewWords(true).endsWith("Client identifiers are stripped before client data is sent to the provider."))
        assertTrue(aiReviewWords(false).endsWith("Identifier redaction is currently off."))
        assertEquals("Staff review every AI draft.", aiReviewWords(null))
        assertEquals(true, parseMe(JSONObject("""{"id":1,"ai_redaction":true}""")).aiRedaction)
        assertNull(parseMe(JSONObject("""{"id":1}""")).aiRedaction)
    }

    /* ---------- P36, P39: procurement and money ---------- */

    @Test fun moneyText() {
        assertNull(moneyText("12,50"))
        assertEquals("1250.00", moneyText("$1,250"))
        assertEquals("2995.00", moneyText("$2,995.00"))
        assertEquals("24.50", moneyText("24.5"))
        // 7 digits before the point, as api_money; a contract's amounts take 10.
        assertEquals("1234567.00", moneyText("1,234,567"))
        assertNull(moneyText("12,345,678"))
        assertEquals("1234567890.00", moneyText("1,234,567,890", org.casemanagement.app.ui.CONTRACT_DIGITS))
        assertNull(moneyText("12345678901", org.casemanagement.app.ui.CONTRACT_DIGITS))
        assertNull(moneyText("1,23"))
        assertNull(moneyText("abc"))
    }

    @Test fun percentInCents() {
        assertEquals(29, percentInCents(290.0, 1000.0))
        assertEquals(29, percentInCents(0.29, 1.0))
        assertEquals(99, percentInCents(995.0, 1000.0))
        assertEquals(0, percentInCents(10.0, 0.0))
    }

    @Test fun reopenedWords() {
        assertEquals("Request reopened. Status updated to Ordered.", reopenedWords(JSONObject("""{"ok":true,"status":"Ordered"}""")))
        assertEquals("Request reopened.", reopenedWords(JSONObject("""{"ok":true}""")))
    }

    /* ---------- The app lock ---------- */

    @Test fun appLockTiming() {
        assertFalse(shouldRelock(null))
        assertFalse(shouldRelock(120_000L))
        assertTrue(shouldRelock(120_001L))
        assertTrue(appLockWords(true).contains("after 2 minutes away"))
        assertTrue(appLockWords(false).startsWith("This phone has no screen lock"))
        // Strong biometrics or the screen lock from API 30; below it, the only pairing the phone offers.
        assertEquals(androidx.biometric.BiometricManager.Authenticators.BIOMETRIC_STRONG or androidx.biometric.BiometricManager.Authenticators.DEVICE_CREDENTIAL, appLockAuthenticators(30))
        assertEquals(androidx.biometric.BiometricManager.Authenticators.BIOMETRIC_WEAK or androidx.biometric.BiometricManager.Authenticators.DEVICE_CREDENTIAL, appLockAuthenticators(28))
    }

    /* ---------- Review fixes ---------- */

    @Test fun appLockStateMachine() {
        val l = AppLockState(startSignedIn = true)
        // A kept session starts locked and asks once by itself.
        assertTrue(l.locked); assertTrue(l.takeAutoPrompt()); assertFalse(l.takeAutoPrompt())
        val g = l.beginAuth()!!
        assertNull(l.beginAuth())
        assertTrue(l.authEnded(g, ok = true)); assertFalse(l.locked)
        // Away less than the grace period: still open; longer: locked, asked once.
        l.wentAway(0L); l.cameBack(true, 60_000L); assertFalse(l.locked)
        l.wentAway(0L); l.cameBack(true, 200_000L); assertTrue(l.locked); assertTrue(l.takeAutoPrompt())
        // Cancelling the phone's own PIN screen (Android 8 and 9: a screen of its own) is not time away,
        // so the prompt does not open again by itself in a loop; Unlock is waited for.
        val g2 = l.beginAuth()!!
        l.wentAway(10_000L); l.cameBack(true, 400_000L)
        assertTrue(l.authEnded(g2, ok = false)); assertTrue(l.locked)
        assertFalse(l.takeAutoPrompt())
        // A real return to a locked app asks once more.
        l.wentAway(500_000L); l.cameBack(true, 510_000L); assertTrue(l.takeAutoPrompt())
    }

    @Test fun appLockSurvivesRecreation() {
        val l = AppLockState(startSignedIn = true)
        assertTrue(l.takeAutoPrompt())
        val g = l.beginAuth()!!
        // The screen is made again while the prompt is up: Unlock works again and the prompt reopens.
        l.activityGone()
        assertFalse(l.authenticating); assertTrue(l.takeAutoPrompt())
        // The old prompt's answer no longer counts; the new one does.
        assertFalse(l.authEnded(g, ok = true)); assertTrue(l.locked)
        val g2 = l.beginAuth()!!
        assertTrue(l.authEnded(g2, ok = true)); assertFalse(l.locked)
        // Sign-in and sign-out leave nothing locked.
        val m = AppLockState(startSignedIn = true); m.signedOut(); assertFalse(m.locked); assertFalse(m.takeAutoPrompt())
        val n = AppLockState(startSignedIn = true); n.signedIn(); assertFalse(n.locked)
        // Coming back signed out never locks.
        val o = AppLockState(startSignedIn = false); o.wentAway(0L); o.cameBack(false, 999_999L); assertFalse(o.locked)
    }

    @Test fun reportPayloadKeys() {
        val send = reportPayload(5, "final", period = true, lastReport = "2026-10-01", intro = " Hi ", barriers = "b", recommendations = "r",
            signature = " Synthetic Staff ", recipient = " vrc@example.org ", ccSelf = true, sharePortal = false)
        assertEquals(5, send.getInt("track_id")); assertEquals("final", send.getString("report_kind")); assertEquals(1, send.getInt("period"))
        assertEquals("Hi", send.getString("intro")); assertEquals("Synthetic Staff", send.getString("signature"))
        assertEquals("vrc@example.org", send.getString("recipient")); assertEquals(1, send.getInt("cc_self")); assertEquals(0, send.getInt("share_portal"))
        assertEquals("b", send.getString("barriers")); assertEquals("r", send.getString("recommendations"))
        // No last report: the period is off. Empty recipient: left out (the counselor on file).
        val p = reportPayload(5, "progress", period = true, lastReport = null, recipient = "  ")
        assertEquals(0, p.getInt("period")); assertFalse(p.has("recipient")); assertFalse(p.has("signature")); assertFalse(p.has("cc_self"))
        assertEquals("progress", reportPayload(5, "other", false, null).getString("report_kind"))
    }

    @Test fun reportRefusalsOnTheirFields() {
        assertEquals(setOf("signature"), reportFieldErrors("Type your full name (Synthetic Staff) to sign the report.").keys)
        assertEquals(setOf("recipient"), reportFieldErrors("The counselor email on this client has not been confirmed. Confirm it on the client's record first.").keys)
        assertEquals(setOf("recipient"), reportFieldErrors("Enter a valid recipient email address.").keys)
        assertTrue(reportFieldErrors("Track not found.").isEmpty())
    }

    @Test fun editBecomesAddendum() {
        assertEquals("addendum", noteEditOutcome(409, JSONObject("""{"ok":false,"locked":true,"error":"This note is locked."}""")))
        assertEquals("conflict", noteEditOutcome(409, JSONObject("""{"ok":false,"conflict":true,"note_version":"x"}""")))
        assertEquals("error", noteEditOutcome(422, JSONObject("""{"ok":false,"locked":true}""")))
        assertEquals("This note is locked. Your changes are in the addendum form below.", lockedToAddendumMessage("This note is locked."))
        assertEquals("This note is locked. Your changes are in the addendum form below.", lockedToAddendumMessage(null))
        assertEquals("Behavior: one\n\nPlan: four\n\nNarrative: more", typedNoteText("BIRP", "more", listOf("one", "", " ", "four"), listOf("Behavior", "Intervention", "Response", "Plan")))
        assertEquals("plain", typedNoteText("free", " plain ", listOf("x", "", "", ""), null))
    }

    @Test fun homeworkErrorsGoToTheRowSent() {
        // Rows 0, 2 and 3 were sent (row 1 unticked): the server's item 2 is row 2.
        val r = JSONObject("""{"ok":false,"errors":{"hw_due_2":"Homework item 2: enter a valid due date.","note_content":"x"}}""")
        assertEquals(mapOf(2 to "Homework item 2: enter a valid due date."), homeworkRowErrors(r, listOf(0, 2, 3)))
        assertTrue(homeworkRowErrors(JSONObject("""{"ok":false,"errors":{"hw_due_9":"x"}}"""), listOf(0)).isEmpty())
        assertTrue(homeworkRowErrors(null, listOf(0)).isEmpty())
    }

    @Test fun completionChoices() {
        val logged = completionBody(4, "completed_logged", 1.5, 12.0)
        assertFalse(logged.has("hours")); assertFalse(logged.has("mileage")); assertEquals("completed_logged", logged.getString("outcome"))
        val separate = completionBody(4, "completed_separate", 1.5, 12.0)
        assertEquals(1.5, separate.getDouble("hours"), 0.0); assertEquals(12.0, separate.getDouble("mileage"), 0.0)
        assertFalse(completionBody(4, "completed", 1.0, 0.0).has("mileage"))
        assertEquals("Completed, hours already logged", loggedChoiceLabel(0, 1))
        assertEquals("Completed, hours already logged as entry 2", loggedChoiceLabel(1, 3))
    }

    @Test fun logHoursErrorsOnTheirFields() {
        val m = logHoursFieldErrors(JSONObject("""{"ok":false,"errors":{"hours":"h","mileage":"m","description":"d"}}"""))
        assertEquals(mapOf("hours" to "h", "miles" to "m", "description" to "d"), m)
        assertTrue(logHoursFieldErrors(null).isEmpty())
    }
}
