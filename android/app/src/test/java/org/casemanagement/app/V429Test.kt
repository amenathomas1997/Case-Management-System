package org.casemanagement.app

import kotlinx.coroutines.runBlocking
import org.json.JSONObject
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNotEquals
import org.junit.Assert.assertTrue
import org.junit.Assert.fail
import org.junit.Test

/** 4.29.0: dialog keys, kept retry keys while processing, list totals, kept ticks and ratings, uniform signature scaling. */
class V429Test {
    @Test fun dialogKeysDifferByItem() {
        assertEquals(dialogKey("correct", 7), dialogKey("correct", 7))
        assertNotEquals(dialogKey("correct", 7), dialogKey("correct", 8))
        assertNotEquals(dialogKey("correct", 7), dialogKey("progress", 7))
    }

    @Test fun stillProcessingKeepsTheRetryKey() = runBlocking {
        var key = ""
        val sent = mutableListOf<String>()
        val busy = ApiException("This is still being processed. Try again in a moment.", 409, reply = JSONObject().put("ok", false).put("processing", true))
        assertTrue(stillProcessing(busy))
        try {
            sendOnce(key, { key = it }, "add_goal{\"a\":1}", "the goal") { k -> sent.add(k); throw busy }
            fail("expected a refusal")
        } catch (e: ApiException) { assertEquals(409, e.status) }
        assertTrue(key.isNotEmpty())
        // The try a moment later carries the same key.
        sendOnce(key, { key = it }, "add_goal{\"a\":1}", "the goal") { k -> sent.add(k); JSONObject() }
        assertEquals(sent[0], sent[1])
        assertEquals("", key)
    }

    @Test fun otherRefusalsStartANewKey() = runBlocking {
        var key = ""
        val refused = ApiException("Title is required.", 422, reply = JSONObject().put("ok", false))
        assertFalse(stillProcessing(refused))
        assertFalse(stillProcessing(ApiException("Someone else changed it.", 409, reply = JSONObject().put("conflict", true))))
        try { sendOnce(key, { key = it }, "x", "it") { throw refused } } catch (e: ApiException) { }
        assertEquals("", key)
    }

    @Test fun listsSayWhenTheyAreCut() {
        assertEquals(340, listTotal(JSONObject().put("total", 340).put("limit", 200), 200))
        assertEquals(60, listTotal(JSONObject().put("sessions_total", 60), 50, "sessions_total"))
        assertEquals(12, listTotal(JSONObject(), 12))
        assertEquals(12, listTotal(null, 12))
        assertEquals("Hours logged (latest 200 of 340)", countLabel("Hours logged", 200, 340))
        assertEquals("Hours logged (12)", countLabel("Hours logged", 12, 12))
        assertEquals("All clients (first 200 of 250)", countLabel("All clients", 200, 250, "first"))
    }

    @Test fun ticksAndRatingsSurviveAsText() {
        assertEquals(setOf(3, 1, 2), idsFromText(idsToText(setOf(3, 1, 2))))
        assertEquals(emptySet<Int>(), idsFromText(idsToText(emptySet())))
        val m = mapOf(5 to 2, 1 to 4)
        assertEquals(m, ratingsFromText(ratingsToText(m)))
        assertEquals(emptyMap<Int, Int>(), ratingsFromText(""))
    }

    @Test fun aTurnedSignatureIsNeverStretched() {
        // Portrait 400x180 to landscape 900x180: the same factor both ways (1), centred across.
        val s = listOf(listOf(0f to 0f, 400f to 180f))
        assertEquals(listOf(listOf(250f to 0f, 650f to 180f)), scaleStrokes(s, 400, 180, 900, 180))
    }
}
