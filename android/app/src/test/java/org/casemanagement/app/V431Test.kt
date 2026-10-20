package org.casemanagement.app

import org.json.JSONObject
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Test

/** 4.31.0: confirming a session lands in its case note (routes, subjects, titles, notes_to_write). */
class V431Test {
    @Test fun subjectFromTheServerOrMadeHere() {
        assertEquals("Session on 20 Oct", sessionNoteSubject("Session on 20 Oct", "2026-10-20"))
        // An older server (no note_subject), or a completion kept offline: made from the date.
        assertEquals("Session on Oct 20", sessionNoteSubject(null, "2026-10-20"))
        assertEquals("Session on Oct 5", sessionNoteSubject(" ", "2026-10-05 14:30:00"))
        assertEquals("Session note", sessionNoteSubject(null, null))
        assertEquals("Session note", sessionNoteSubject(null, "not a date"))
    }

    @Test fun titleSaysWhereYouAre() {
        assertEquals("Case note for Jane Doe, session on Oct 20", sessionNoteTitle("Jane Doe", "Session on Oct 20"))
        assertEquals("Case note for Jane Doe", sessionNoteTitle(" Jane Doe ", ""))
        assertEquals("Case note, session on Oct 20", sessionNoteTitle(null, "Session on Oct 20"))
        assertEquals("Add a case note", sessionNoteTitle(null, null))
    }

    @Test fun routeArgumentsAreEncodedForNavigation() {
        assertEquals("Session%20on%20Oct%2020", routeArg("Session on Oct 20"))
        assertEquals("A%26B%3Dc%3Fd%2Fe%23f%2Bg", routeArg("A&B=c?d/e#f+g"))
        assertEquals("Jos%C3%A9%20Garc%C3%ADa", routeArg("José García"))
        assertEquals("a-b_c.d~e", routeArg("a-b_c.d~e"))
    }

    @Test fun routeGoesToTheTrackOrTheClient() {
        assertEquals("trackNote/5/9?subject=Session%20on%20Oct%2020&session=77&client=Jane%20Doe",
            sessionNoteRoute(5, 9, 77, "Session on Oct 20", "Jane Doe"))
        assertEquals("note/5?subject=Session%20on%20Oct%2020&session=77&client=O%27Neil%20%26%20Co",
            sessionNoteRoute(5, 0, 77, "Session on Oct 20", "O'Neil & Co"))
        // The patterns the graph registers take the same arguments.
        assertEquals("note/{clientId}?subject={subject}&session={session}&client={client}", NOTE_ROUTE)
        assertEquals("trackNote/{clientId}/{trackId}?subject={subject}&session={session}&client={client}", TRACK_NOTE_ROUTE)
    }

    @Test fun completeReplyGivesTheNoteToWrite() {
        val reply = JSONObject("""{"ok":true,"client_id":5,"client_name":"Jane Doe","track_id":9,"session_id":77,"session_date":"2026-10-20","note_subject":"Session on Oct 20","note_pending":true}""")
        val n = parseNoteToWrite(reply)!!
        assertEquals(NoteToWrite(77, 5, "Jane Doe", 9, "2026-10-20", "Session on Oct 20"), n)
        // A session with no track: track_id null.
        assertEquals(0, parseNoteToWrite(JSONObject(reply.toString()).put("track_id", JSONObject.NULL))!!.trackId)
    }

    @Test fun olderServerOrOfflineUsesTheScheduleRow() {
        val row = JSONObject("""{"id":77,"client":"Jane Doe","client_id":5,"track_id":9,"start_at":"2026-10-20 09:00:00","status":"Scheduled"}""")
        val old = parseNoteToWrite(JSONObject("""{"ok":true}"""), row)!!
        assertEquals(NoteToWrite(77, 5, "Jane Doe", 9, "2026-10-20", "Session on Oct 20"), old)
        assertEquals(old, parseNoteToWrite(JSONObject(), row))
        assertEquals("trackNote/5/9?subject=Session%20on%20Oct%2020&session=77&client=Jane%20Doe", old.route)
        assertNull(parseNoteToWrite(JSONObject()))
    }

    @Test fun notesToWriteList() {
        val r = JSONObject("""{"ok":true,"total":3,"sessions":[
            {"session_id":77,"client_id":5,"client_name":"Jane Doe","track_id":null,"session_date":"2026-10-20","start_at":"2026-10-20 09:00:00","note_subject":"Session on Oct 20"},
            {"session_id":76,"client_id":6,"client_name":"Sam Lee","track_id":4,"session_date":"2026-10-19","start_at":"2026-10-19 13:00:00"}]}""")
        val w = parseNotesToWrite(r)
        assertEquals(3, w.total)
        assertEquals(listOf("Session on Oct 20", "Session on Oct 19"), w.items.map { it.subject })
        assertEquals("note/5?subject=Session%20on%20Oct%2020&session=77&client=Jane%20Doe", w.items[0].route)
        assertEquals(4, w.items[1].trackId)
        // Total never below the rows listed; an empty reply is an empty list.
        assertEquals(2, parseNotesToWrite(JSONObject(r.toString()).put("total", 0)).total)
        assertEquals(0, parseNotesToWrite(JSONObject("{}")).items.size)
    }
}
