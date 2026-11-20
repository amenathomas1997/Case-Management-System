package org.casemanagement.app

import org.casemanagement.app.ui.afterClosureShown
import org.casemanagement.app.ui.assessmentDateRule
import org.casemanagement.app.ui.assessmentRowText
import org.casemanagement.app.ui.attendanceText
import org.casemanagement.app.ui.cancelMoreConfirm
import org.casemanagement.app.ui.clientsCutText
import org.casemanagement.app.ui.clientsHeading
import org.casemanagement.app.ui.clientsQuery
import org.casemanagement.app.ui.completionBody
import org.casemanagement.app.ui.filtersOnCount
import org.casemanagement.app.ui.followupAnswer
import org.casemanagement.app.ui.followupConfirm
import org.casemanagement.app.ui.generateFormat
import org.casemanagement.app.ui.generatorSource
import org.casemanagement.app.ui.groupAttendLabel
import org.casemanagement.app.ui.groupBody
import org.casemanagement.app.ui.groupClientLabel
import org.casemanagement.app.ui.groupClientsRule
import org.casemanagement.app.ui.groupCompleteBody
import org.casemanagement.app.ui.groupConfirmText
import org.casemanagement.app.ui.groupCannotRecordText
import org.casemanagement.app.ui.groupFieldErrors
import org.casemanagement.app.ui.homeworkFromText
import org.casemanagement.app.ui.homeworkToText
import org.casemanagement.app.ui.nextInQueue
import org.casemanagement.app.ui.nextNoteWords
import org.casemanagement.app.ui.realDate
import org.casemanagement.app.ui.stringsFromText
import org.casemanagement.app.ui.stringsToText
import org.casemanagement.app.ui.textTooLong
import org.casemanagement.app.ui.offerTimePast
import org.casemanagement.app.ui.groupHoursRule
import org.casemanagement.app.ui.holdDateRule
import org.casemanagement.app.ui.holdDateShown
import org.casemanagement.app.ui.homeworkDueRule
import org.casemanagement.app.ui.homeworkEditBody
import org.casemanagement.app.ui.homeworkEditConfirm
import org.casemanagement.app.ui.homeworkEditErrorField
import org.casemanagement.app.ui.hoursRule
import org.casemanagement.app.ui.languageChoices
import org.casemanagement.app.ui.logHoursFieldErrors
import org.casemanagement.app.ui.logHoursMessage
import org.casemanagement.app.ui.logHoursPayload
import org.casemanagement.app.ui.mergeNotePages
import org.casemanagement.app.ui.noteRowLines
import org.casemanagement.app.ui.offerConfirmText
import org.casemanagement.app.ui.offerFieldErrors
import org.casemanagement.app.ui.offerSentFields
import org.casemanagement.app.ui.offerTimeText
import org.casemanagement.app.ui.offerTimesBody
import org.casemanagement.app.ui.olderNotesLabel
import org.casemanagement.app.ui.onHoldLine
import org.casemanagement.app.ui.onsetAgeRule
import org.casemanagement.app.ui.requestRowLines
import org.casemanagement.app.ui.serverAtLeast
import org.casemanagement.app.ui.sessionActions
import org.casemanagement.app.ui.surveyRatingRule
import org.casemanagement.app.ui.visionText
import org.casemanagement.app.ui.visitChoicesShown
import org.json.JSONObject
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

/** 4.35.0: field casework in the app (notes, clients, sessions), as the website. Made-up names only. */
class V435Test {
    private val start = 1_000_000_000L
    private val end = start + 3_600_000L

    @Test fun serverVersionGate() {
        assertTrue(serverAtLeast("4.35.0", "4.35")); assertTrue(serverAtLeast("4.36.1", "4.35")); assertTrue(serverAtLeast("5.0", "4.35"))
        assertFalse(serverAtLeast("4.34.9", "4.35")); assertFalse(serverAtLeast("3.9", "4.35"))
        assertTrue(serverAtLeast(null, "4.35"))
    }

    /* ---------- Q1: group booking ---------- */

    @Test fun groupBodySerialisesClientsAndTracks() {
        val b = groupBody(listOf(3, 5), mapOf(5 to 9, 7 to 2), 4, "2026-11-20 ", "14:30", 1.5, " Room 2 ", "", remote = false, link = "https://x", ackHash = null)
        assertEquals("3,5", b.getString("client_ids"))
        // Only the ticked clients' picks are sent.
        assertEquals("""{"5":9}""", b.getJSONObject("tracks").toString())
        assertEquals("2026-11-20", b.getString("date")); assertEquals("Room 2", b.getString("location")); assertEquals(0, b.getInt("is_remote"))
        assertFalse(b.has("meeting_url")); assertFalse(b.has("authorization_ack_hash"))
        val approved = groupBody(listOf(3, 5), emptyMap(), 4, "2026-11-20", "14:30", 1.5, "", "", remote = true, link = "https://meet.example/a", ackHash = "abc")
        assertFalse(approved.has("tracks"))
        assertEquals("https://meet.example/a", approved.getString("meeting_url")); assertEquals("abc", approved.getString("authorization_ack_hash"))
    }

    @Test fun groupClientLabelSaysWhereHoursGo() {
        assertEquals("Synthetic Client (no active training track, so no hours will be logged)", groupClientLabel("Synthetic Client", emptyList()))
        assertEquals("Synthetic Client — Braille basics", groupClientLabel("Synthetic Client", listOf("Braille basics")))
        // Several tracks: the picker underneath names the one chosen.
        assertEquals("Synthetic Client", groupClientLabel("Synthetic Client", listOf("Braille basics", "Older track")))
        assertTrue(groupClientsRule(1)!!.contains("two or more")); assertNull(groupClientsRule(2))
    }

    /* ---------- Q2: group attendance ---------- */

    @Test fun groupConfirmMatchesTheWebsite() {
        assertEquals("Record attendance for this group session? 2 of 3 clients attended and will be marked Completed, and 1 of them will have 1.25 hours logged to their training track and billed. 1 will be recorded as No Show, with no hours logged.",
            groupConfirmText(2, 3, 1, "1.25"))
        assertEquals("Record attendance for this group session? 3 of 3 clients attended and will be marked Completed, and 3 of them will have hours logged to their training track and billed. 0 will be recorded as No Show, with no hours logged.",
            groupConfirmText(3, 3, 3, ""))
        assertEquals("Record attendance for this group session? 2 of 2 clients attended and will be marked Completed, with no hours logged. 0 will be recorded as No Show, with no hours logged.",
            groupConfirmText(2, 2, 2, "0"))
    }

    @Test fun groupAttendanceInputs() {
        assertEquals("Synthetic Client attended (hours logged to Braille basics)", groupAttendLabel("Synthetic Client", "Braille basics", true))
        assertEquals("Synthetic Client attended (no training track, so no hours are logged)", groupAttendLabel("Synthetic Client", null, false))
        assertNull(groupHoursRule("")); assertNull(groupHoursRule("1,5")); assertTrue(groupHoursRule("30") != null); assertTrue(groupHoursRule("abc") != null)
        val b = groupCompleteBody("g-1", listOf(10, 11, 12), setOf(12, 10), " 1.5 ")
        assertEquals("[10,11,12]", b.getJSONArray("listed").toString()); assertEquals("[10,12]", b.getJSONArray("attended").toString()); assertEquals("1.5", b.getString("hours"))
    }

    @Test fun sessionActionsOfferGroupOnlyAfterTheStart() {
        val before = sessionActions("Scheduled", start, end, start - 60_000L, hasTrack = true, groupUid = "g-1")
        assertFalse("group" in before); assertTrue("cancel_group" in before)
        val after = sessionActions("Scheduled", start, end, start + 60_000L, hasTrack = true, groupUid = "g-1")
        assertTrue("group" in after)
        assertFalse("group" in sessionActions("Scheduled", start, end, start + 60_000L, hasTrack = true))
    }

    /* ---------- Q3: goal and service when completing ---------- */

    @Test fun completionBodyCarriesGoalAndServiceOnlyWhenChosen() {
        val plain = completionBody(4, "completed", 1.0, 0.0)
        assertFalse(plain.has("goal_id")); assertFalse(plain.has("rsa_category"))
        val chosen = completionBody(4, "completed", 1.0, 0.0, goalId = 7, category = "Independent living")
        assertEquals(7, chosen.getInt("goal_id")); assertEquals("Independent living", chosen.getString("rsa_category"))
        // Hours already logged: no new log, so neither is sent (as the website).
        assertFalse(completionBody(4, "completed_logged", 1.0, 0.0, goalId = 7, category = "X").has("goal_id"))
        assertEquals(true to false, visitChoicesShown(1, 1)); assertEquals(false to true, visitChoicesShown(0, 2)); assertEquals(false to false, visitChoicesShown(0, 0))
    }

    /* ---------- Q4: log hours ---------- */

    @Test fun hoursBlankWithMilesStillNeedsHoursOrANote() {
        // The server: blank hours with miles and no note is not travel only (that is 0 with miles).
        assertEquals("Enter the hours worked, or write a session note to save the note on its own.", hoursRule("", 12.0, ""))
        assertNull(hoursRule("0", 12.0, ""))
    }

    @Test fun groupTrackErrorsOnTheirFields() {
        val m = groupFieldErrors(JSONObject("""{"ok":false,"error":"x","errors":{"track_5":"Training track for Synthetic B: choose one of their active tracks.","client_ids":"c","staff_id":"s"}}"""))
        assertEquals("Training track for Synthetic B: choose one of their active tracks.", m["track_5"])
        assertEquals("c", m["clients"]); assertEquals("s", m["staff_id"])
        assertTrue(groupFieldErrors(null).isEmpty())
        assertEquals("This group has fewer than two clients still scheduled. Record each one from the schedule.", groupCannotRecordText(true))
        assertEquals("It can be completed once it has started, with who attended.", groupCannotRecordText(false))
    }

    @Test fun savedStateHelpersRoundTrip() {
        val hw = listOf(JSONObject().put("title", "Read chapter 2").put("instructions", "Aloud").put("due", "2026-12-01"), JSONObject().put("title", "Practise"))
        val back = homeworkFromText(homeworkToText(hw))
        assertEquals(listOf("Read chapter 2", "Practise"), back.map { it.getString("title") })
        assertEquals("2026-12-01", back[0].getString("due")); assertEquals("", back[1].getString("due"))
        assertTrue(homeworkFromText("not json").isEmpty())
        assertEquals(listOf("Is the date right?", ""), stringsFromText(stringsToText(listOf("Is the date right?", ""))))
        assertTrue(stringsFromText("").isEmpty())
    }

    @Test fun groupNotesWalkThrough() {
        val q = listOf(NoteToWrite(10, 1, "Synthetic A", 3, null, "s"), NoteToWrite(11, 2, "Synthetic B", 0, null, "s"), NoteToWrite(12, 3, "Synthetic C", 4, null, "s"))
        assertEquals(11 to 2, nextInQueue(q, 10)!!.let { it.first.sessionId to it.second })
        assertEquals(12 to 3, nextInQueue(q, 11)!!.let { it.first.sessionId to it.second })
        assertNull(nextInQueue(q, 12)); assertNull(nextInQueue(q, 99))
        assertEquals(" Next: Synthetic B, 2 of 3.", nextNoteWords("Synthetic B", 2, 3))
    }

    @Test fun datesAndLengths() {
        assertTrue(realDate("2026-11-19", 2031)); assertFalse(realDate("2026-02-30", 2031)); assertFalse(realDate("0026-01-01", 2031)); assertFalse(realDate("2062-01-01", 2031))
        assertFalse(realDate("26-1-1", 2031))
        assertNull(textTooLong(" " + "a".repeat(1000) + " ", 1000)); assertEquals("1,000", textTooLong("a".repeat(1001), 1000))
        // Characters as the server counts them: an emoji is one.
        assertNull(textTooLong("\uD83D\uDE00".repeat(1000), 1000))
        assertTrue(offerTimePast("2026-11-19 09:00", "2026-11-19 09:00")); assertFalse(offerTimePast("2026-11-19 09:01", "2026-11-19 09:00"))
    }

    @Test fun hoursRuleNoteOnly() {
        assertNull(hoursRule("", 0.0, "Talked about the plan."))
        assertEquals("Enter the hours worked, or write a session note to save the note on its own.", hoursRule(" ", 0.0, ""))
        assertNull(hoursRule("1.5", 0.0, ""))
        assertTrue(hoursRule("0", 0.0, "a note")!!.contains("0 with miles for travel only"))
    }

    @Test fun logHoursMessages() {
        assertEquals("Session note saved. No hours were logged.", logHoursMessage(0.0, true, true))
        assertEquals("Travel logged with no hours, and the session note saved.", logHoursMessage(0.0, true, false))
        assertEquals("1.5 hours logged and the session note saved.", logHoursMessage(1.5, true, false))
        assertEquals("2 hours logged.", logHoursMessage(2.0, false, false))
    }

    @Test fun logHoursPayloadShapes() {
        val noteOnly = logHoursPayload(3, " ", "2026-11-19", 0.0, "", "", "", 0, " A note ", "k")
        assertEquals("", noteOnly.getString("hours")); assertEquals("A note", noteOnly.getString("session_note"))
        assertFalse(noteOnly.has("goal_id")); assertFalse(noteOnly.has("service_type")); assertFalse(noteOnly.has("rsa_category"))
        val full = logHoursPayload(3, "1.25", "2026-11-19", 4.0, "Practised", "Rehab Teaching", "Independent living", 8, "", "k")
        assertEquals(1.25, full.getDouble("hours"), 0.0); assertEquals(8, full.getInt("goal_id"))
        assertEquals("Rehab Teaching", full.getString("service_type")); assertFalse(full.has("session_note"))
        // The server's errors map (fail_field): each key on its field, mileage on Miles.
        val m = logHoursFieldErrors(JSONObject("""{"ok":false,"error":"g","errors":{"goal_id":"Goal this session advanced: that goal is not on this track.","rsa_category":"r","service_type":"s"}}"""))
        assertEquals(setOf("goal_id", "rsa_category", "service_type"), m.keys)
        // An older server's single message is tied by its start.
        assertEquals(setOf("goal_id"), logHoursFieldErrors(JSONObject("""{"ok":false}"""), "Goal this session advanced: that goal is not on this track.").keys)
    }

    /* ---------- Q5: cancel more ---------- */

    @Test fun cancelSeriesAndGroupOnlyForTheirRows() {
        val single = sessionActions("Scheduled", start, end, start - 60_000L, hasTrack = true)
        assertFalse("cancel_series" in single); assertFalse("cancel_group" in single)
        val series = sessionActions("Scheduled", start, end, start - 60_000L, hasTrack = true, inSeries = true)
        assertTrue("cancel_series" in series); assertFalse("cancel_group" in series)
        assertTrue(sessionActions("Cancelled", start, end, start, hasTrack = true, inSeries = true, groupUid = "g").isEmpty())
        assertEquals("Cancel this group session on Fri for every client in it? Each client is sent a calendar cancellation.", cancelMoreConfirm("group", "Synthetic Client", "Fri"))
        assertEquals("Cancel this session with Synthetic Client on Fri, Nov 20, 2:30 PM and every later session in its weekly series? The client is sent a calendar cancellation.",
            cancelMoreConfirm("series", "Synthetic Client", "Fri, Nov 20, 2:30 PM"))
    }

    /* ---------- Q6: client requests ---------- */

    @Test fun requestRowsShowSessionDueAndOffers() {
        val q = JSONObject("""{"client_name":"Synthetic Client","type_label":"Reschedule","status":"New","created_at":"2026-11-18 09:00:00",
            "session_start":"2026-11-20 14:30:00","due_on":"2026-11-21","offered":[{"start_at":"2026-11-22 10:00:00"}],"details":"Mornings please"}""")
        val lines = requestRowLines(q)
        assertEquals("Synthetic Client: Reschedule", lines[0])
        assertTrue(lines.any { it.startsWith("session Fri, Nov 20") })
        assertTrue(lines.any { it == "due Saturday, November 21, 2026" })
        assertTrue(lines.any { it.startsWith("Offered: Sun, Nov 22") })
        assertEquals("Mornings please", lines.last())
        assertEquals("New, Wed, Nov 18, 9:00 AM", lines[1])
        // No date sent: the status alone, never "New, ".
        assertEquals(listOf("A: B", "New"), requestRowLines(JSONObject("""{"client_name":"A","type_label":"B","status":"New"}""")))
    }

    @Test fun offerTimesBodyDropsBlanks() {
        assertEquals("2026-11-20 14:30", offerTimeText("2026-11-20 2:30 PM")); assertEquals("2026-11-20 09:05", offerTimeText("2026-11-20 9:05"))
        assertNull(offerTimeText("2026-02-30 10:00")); assertNull(offerTimeText("tomorrow"))
        val times = listOf("2026-11-20 14:30", "", "2026-11-21 9:00")
        val b = offerTimesBody(5, times, 60, 2, 0, true, " Office ")
        assertEquals("""["2026-11-20 14:30","2026-11-21 09:00"]""", b.getJSONArray("times").toString())
        assertEquals(60, b.getInt("minutes")); assertFalse(b.has("track_id")); assertEquals(1, b.getInt("remote")); assertEquals("Office", b.getString("location"))
        assertFalse(offerTimesBody(5, times, null, 2, 3, false, "").has("minutes"))
        // The server's time2 is the second time sent: the form's third field here.
        assertEquals(mapOf("time3" to "x", "staff_id" to "y"), offerFieldErrors(mapOf("time2" to "x", "staff_id" to "y"), offerSentFields(times)))
        assertEquals("Offer these times to Synthetic Client: Fri, Nov 20, 2:30 PM; Sat, Nov 21, 9:00 AM? They choose one in their portal.",
            offerConfirmText(listOf("Fri, Nov 20, 2:30 PM", "Sat, Nov 21, 9:00 AM"), "Synthetic Client"))
    }

    /* ---------- Q7: tech assessments ---------- */

    @Test fun assessmentDateRule() {
        assertEquals("Assessment date is required.", assessmentDateRule(" ", "2026-11-19"))
        assertEquals("Enter the assessment date as a valid date (YYYY-MM-DD) from the year 2000 onwards.", assessmentDateRule("1999-12-31", "2026-11-19"))
        assertEquals("Enter the assessment date as a valid date (YYYY-MM-DD) from the year 2000 onwards.", assessmentDateRule("2026-13-01", "2026-11-19"))
        assertEquals("The assessment date cannot be in the future. Check the year.", assessmentDateRule("2026-11-20", "2026-11-19"))
        assertNull(assessmentDateRule("2026-11-19", "2026-11-19"))
        assertEquals("Thursday, November 19, 2026: Screen Reader", assessmentRowText("2026-11-19", "Screen Reader"))
    }

    /* ---------- Q8: older notes ---------- */

    @Test fun mergeNotePagesKeepsOrderWithoutDuplicates() {
        fun n(id: Int) = NoteItem(id, "Note $id", "Case Note", null, null, "")
        assertEquals(listOf(5, 4, 3, 2), mergeNotePages(listOf(n(5), n(4), n(3)), listOf(n(3), n(2))).map { it.id })
        assertEquals("Load older notes (30 more)", olderNotesLabel(80, 50))
        assertEquals("Load older notes (0 more)", olderNotesLabel(40, 50))
    }

    /* ---------- Q9: after closure ---------- */

    @Test fun afterClosureHelpers() {
        assertEquals("", followupAnswer("")); assertEquals("yes", followupAnswer("yes")); assertEquals("no", followupAnswer("no")); assertEquals("", followupAnswer("maybe"))
        assertEquals("Save the 30-day check for Synthetic Client?", followupConfirm(30, "Synthetic Client", ""))
        assertEquals("Save the 60-day check for Synthetic Client? A re-referral task will be added.", followupConfirm(60, "Synthetic Client", "yes"))
        // The client reply's after_closure: null for an open client, the state for a closed one.
        val open = JSONObject("""{"ok":true,"after_closure":null}""")
        assertFalse(afterClosureShown(open.opt("after_closure"))); assertFalse(afterClosureShown(JSONObject("{}").opt("after_closure")))
        val closed = JSONObject("""{"ok":true,"after_closure":{"surveys":[],"followups":[{"id":1,"interval_days":30,"status":"Pending","due_now":true}],"by_phone":false}}""")
        assertTrue(afterClosureShown(closed.opt("after_closure")))
        assertEquals("Satisfaction: enter a rating from 1 to 10.", surveyRatingRule("Satisfaction", "11")); assertNull(surveyRatingRule("Goals met", "7"))
    }

    /* ---------- Q10, Q11: client details ---------- */

    @Test fun clientFieldRules() {
        assertNull(onsetAgeRule("")); assertNull(onsetAgeRule("0")); assertNull(onsetAgeRule("120"))
        assertEquals("Age when vision loss began: enter a whole number of years from 0 to 120, or leave it blank.", onsetAgeRule("130"))
        assertTrue(onsetAgeRule("4.5") != null)
        assertEquals(listOf("en", "es"), languageChoices().map { it.first })
        assertTrue(holdDateShown("On Hold")); assertFalse(holdDateShown("Active Training"))
        assertNull(holdDateRule("", "2026-11-19")); assertNull(holdDateRule("2026-12-01", "2026-11-19"))
        assertEquals("Enter the expected return date as today or later, or leave it blank.", holdDateRule("2026-11-18", "2026-11-19"))
    }

    @Test fun detailLines() {
        assertEquals("Attendance: 3 completed, 1 no-show, 0 cancelled", attendanceText(JSONObject("""{"completed":3,"no_show":1,"cancelled":0}""")))
        assertEquals("Attendance: 1 completed, 2 no-shows, 1 cancelled", attendanceText(JSONObject("""{"completed":1,"no_show":2,"cancelled":1}""")))
        assertNull(attendanceText(JSONObject("""{"completed":0,"no_show":0,"cancelled":0}"""))); assertNull(attendanceText(null))
        assertEquals("On Hold: waiting for the authorization. Expected back Monday, December 7, 2026.", onHoldLine("waiting for the authorization", "2026-12-07"))
        assertEquals("On Hold: surgery.", onHoldLine("surgery", null)); assertNull(onHoldLine(" ", null))
        assertEquals("Vision: Low vision, since age 12", visionText("Low vision", 12)); assertEquals("Vision: Not recorded, since age 3", visionText(null, 3))
        assertNull(visionText("", null))
    }

    /* ---------- Q12: the note editor ---------- */

    @Test fun generatorUsesRoughFirst() {
        assertEquals("rough words", generatorSource(" rough words ", "body", listOf("a", "", "", "")))
        assertEquals("body\na\nd", generatorSource("", " body ", listOf("a", "", " ", "d")))
        assertEquals("", generatorSource("", "", listOf("", "", "", "")))
        assertEquals("BIRP", generateFormat("free")); assertEquals("SOAP", generateFormat("SOAP"))
    }

    @Test fun homeworkDueRuleUsesTheServerWords() {
        assertNull(homeworkDueRule(1, "", 2026)); assertNull(homeworkDueRule(1, "2026-12-01", 2026))
        assertEquals("Homework item 2: enter a valid due date with a four-digit year from 2000 to 2031, or clear it.", homeworkDueRule(2, "2062-01-01", 2026))
        assertTrue(homeworkDueRule(1, "26-1-1", 2026) != null)
    }

    @Test fun noteRowSaysFormatTrackAndEditor() {
        val n = NoteItem(1, "Session on Nov 19", "Case Note", "2026-11-19 10:00:00", "Sam Synthetic", "", noteFormat = "BIRP",
            trackTitle = "Braille basics", editor = "Pat Example", updatedAt = "2026-11-19 11:00:00")
        val lines = noteRowLines(n)
        assertEquals("Session on Nov 19", lines[0])
        assertEquals("Case Note, BIRP, Thu, Nov 19, 10:00 AM, by Sam Synthetic", lines[1])
        assertEquals("Track: Braille basics", lines[2])
        assertEquals("Edited by Pat Example on Thu, Nov 19, 11:00 AM", lines[3])
        assertEquals(2, noteRowLines(NoteItem(2, "Plain", "Case Note", "2026-11-19 10:00:00", null, "")).size)
    }

    /* ---------- Q13: client list filters ---------- */

    @Test fun clientsQuerySendsOnlySetFilters() {
        val q = clientsQuery(mapOf("status" to "On Hold", "who" to null, "type" to "", "agency" to "State VR", "school" to " ", "office" to ""), " ann ")
        assertEquals(mapOf("with_filters" to "1", "q" to "ann", "status" to "On Hold", "agency" to "State VR"), q)
        // A caseload chosen here is sent, even Everyone's clients ("").
        assertEquals("", clientsQuery(mapOf("who" to ""), "")["who"])
        assertEquals(0, filtersOnCount(mapOf("who" to ""), ""))
        assertEquals(2, filtersOnCount(mapOf("who" to "me", "school" to "North"), ""))
        assertEquals(0, filtersOnCount(mapOf("who" to "me"), "me"))
    }

    @Test fun clientsHeadingWording() {
        assertEquals("Clients matching the filters (12)", clientsHeading(12, 12, true))
        assertEquals("Clients matching the filters (340)", clientsHeading(200, 340, true))
        assertEquals("All clients (5)", clientsHeading(5, 5, false))
        assertEquals("Clients matching ann (2)", clientsHeading(2, 2, false, "ann"))
        assertEquals("Showing the first 200 of 1,340 matching clients, in name order. Search by name or narrow the filters to find the rest.", clientsCutText(200, 1340, true))
        assertNull(clientsCutText(5, 5, true))
    }

    /* ---------- Q14: homework edit ---------- */

    @Test fun homeworkEditErrors() {
        assertEquals("reload", homeworkEditErrorField("This assignment changed. Reload it before saving."))
        assertEquals("title", homeworkEditErrorField("Use a title up to 190 characters and instructions up to 65,535 bytes."))
        assertEquals("due", homeworkEditErrorField("Use a valid due date with a four-digit year from 2000 to 2031."))
        assertNull(homeworkEditErrorField("Homework not found.")); assertNull(homeworkEditErrorField(null))
    }

    @Test fun homeworkEditBodySendsTheToken() {
        val b = homeworkEditBody(9, " Read chapter 2 ", "", " ", "tok")
        assertEquals(9, b.getInt("id")); assertEquals("Read chapter 2", b.getString("title")); assertEquals("", b.getString("due_date")); assertEquals("tok", b.getString("edit_token"))
        assertEquals("", homeworkEditBody(9, "t", "", "", null).getString("edit_token"))
        assertEquals("Save the changes to Read? Changing the title or instructions clears the client's report that it is done.", homeworkEditConfirm("Read", true, true))
        assertEquals("Save the changes to Read?", homeworkEditConfirm("Read", true, false))
    }
}
