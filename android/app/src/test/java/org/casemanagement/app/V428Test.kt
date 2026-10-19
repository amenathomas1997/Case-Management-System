package org.casemanagement.app

import kotlinx.coroutines.runBlocking
import org.json.JSONObject
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNotEquals
import org.junit.Assert.assertTrue
import org.junit.Assert.fail
import org.junit.Test

/** 4.28.0: retry keys on creates and sends, kept signatures, file resets, kept documents, refused sessions, field errors, reloaded clients. */
class V428Test {
    private var key = ""

    @Test fun aLostReplyKeepsTheKeyAndSaysTheOutcomeIsUnknown() = runBlocking {
        val sent = mutableListOf<String>()
        try {
            sendOnce(key, { key = it }, "add_incident{\"a\":1}", "the incident report") { k -> sent.add(k); throw ApiException(Api.NO_CONNECTION, 0, network = true) }
            fail("expected a refusal")
        } catch (e: ApiException) {
            assertEquals(unknownOutcome("the incident report"), e.message)
            assertTrue(e.network)
        }
        assertTrue(key.isNotEmpty())
        // The same request again: the same key, and an answer starts again.
        sendOnce(key, { key = it }, "add_incident{\"a\":1}", "the incident report") { k -> sent.add(k); JSONObject().put("ok", true) }
        assertEquals(sent[0], sent[1])
        assertEquals("", key)
    }

    @Test fun aChangedRequestGetsANewKey() = runBlocking {
        val sent = mutableListOf<String>()
        try { sendOnce(key, { key = it }, "post_message|hello|", "the message") { k -> sent.add(k); throw ApiException("x", 0, network = true) } } catch (e: ApiException) { }
        try { sendOnce(key, { key = it }, "post_message|hello, edited|", "the message") { k -> sent.add(k); throw ApiException("x", 0, network = true) } } catch (e: ApiException) { }
        assertNotEquals(sent[0], sent[1])
    }

    @Test fun aRefusalStartsAgainAndKeepsItsWords() = runBlocking {
        try {
            sendOnce(key, { key = it }, "save_task{}", "the task") { throw ApiException("The task needs a title.", 422) }
            fail("expected a refusal")
        } catch (e: ApiException) { assertEquals("The task needs a title.", e.message) }
        assertEquals("", key)
    }

    @Test fun aRedirectIsSaidAsItIs() = runBlocking {
        try {
            sendOnce(key, { key = it }, "add_booking{}", "the booking") { throw ApiException(Api.MOVED, 302, network = true) }
            fail("expected a refusal")
        } catch (e: ApiException) { assertEquals(Api.MOVED, e.message) }
    }

    @Test fun strokesSurviveTheRoundTrip() {
        val strokes = listOf(listOf(1f to 2f, 3f to 4f, 5.5f to 6.5f), listOf(10f to 20f))
        val (w, h, back) = decodeStrokes(encodeStrokes(640, 180, strokes))
        assertEquals(640, w); assertEquals(180, h)
        assertEquals(strokes, back)
        assertEquals(Triple(1, 1, emptyList<List<Pair<Float, Float>>>()), decodeStrokes(null))
        // A damaged array keeps the whole strokes before the damage.
        val (_, _, cut) = decodeStrokes(floatArrayOf(10f, 10f, 1f, 1f, 1f, 5f, 2f))
        assertEquals(listOf(listOf(1f to 1f)), cut)
    }

    @Test fun onlyTheFileThatFailedIsStartedAgain() {
        assertEquals(false to listOf(3), filesToReset(listOf(true, true, true, false)))
        assertEquals(false to emptyList<Int>(), filesToReset(listOf(true, true, true, true)))
        assertEquals(true to listOf(0, 1, 2, 3), filesToReset(listOf(false, false, false, false)))
    }

    @Test fun keptDocumentsGoAfterTwoWeeks() {
        val now = 2_000_000_000_000L
        assertFalse(docExpired(now - 13L * 24 * 60 * 60 * 1000, now))
        assertTrue(docExpired(now - 15L * 24 * 60 * 60 * 1000, now))
    }

    @Test fun aRefusedSessionGoesOnlyToItsOwnServer() {
        val a = "https://a.example.org/api.php"; val b = "https://b.example.org/api.php"
        assertTrue(refusedCheckAllowed(a, a, a))
        assertFalse(refusedCheckAllowed(a, a, b))
        // An entry from 4.27.0 (no server kept with it): only the kept server.
        assertTrue(refusedCheckAllowed("", a, a))
        assertFalse(refusedCheckAllowed("", a, b))
        assertFalse(refusedCheckAllowed("", null, a))
    }

    @Test fun eachCustomProblemIsOnItsField() {
        val fields = listOf(
            CustomField(1, "Referral date", "", "date", emptyList(), false, ""),
            CustomField(2, "Household size", "", "number", emptyList(), false, ""),
            CustomField(3, "Funding", "", "text", emptyList(), true, ""),
        )
        val p = customProblemsByField(fields, mapOf(1 to "March", 2 to "four"), requireAnswers = true)
        assertEquals(setOf(1, 2, 3), p.keys)
        assertTrue(p.getValue(3).contains("required"))
        // An edit does not ask for required answers; good answers are no problem.
        assertEquals(emptyMap<Int, String>(), customProblemsByField(fields, mapOf(1 to "2026-03-14", 2 to "4"), requireAnswers = false))
    }

    @Test fun aReloadKeepsWhatWasTypedAndTakesTheRest() {
        assertEquals("Typed here", mergeReloaded("Typed here", "Old", "Saved elsewhere"))
        assertEquals("Saved elsewhere", mergeReloaded("Old", "Old", "Saved elsewhere"))
        assertEquals(7, mergeReloaded(3, 3, 7))
    }

    @Test fun theSnapshotKeepsTheFormsFieldsAndWhenItWasRead() {
        val c = JSONObject().put("name", "Sam Example").put("updated_at", "2026-10-18 09:00:00").put("assigned_to", JSONObject.NULL)
            .put("custom_fields_edit", org.json.JSONArray().put(JSONObject().put("id", 1)))
        val s = clientSnapshot(c)
        assertEquals("2026-10-18 09:00:00", s.str("updated_at"))
        assertTrue(s.isNull("assigned_to"))
        assertFalse(s.has("custom_fields_edit"))
    }

    @Test fun aKeptSignatureIsFittedToTheNewPad() {
        val s = listOf(listOf(100f to 50f, 200f to 100f))
        assertEquals(listOf(listOf(50f to 200f, 100f to 400f)), scaleStrokes(s, 400, 100, 200, 400))
        // The unknown size a restored pad starts from, or the same size, leaves it as it is.
        assertEquals(s, scaleStrokes(s, 1, 1, 200, 400))
        assertEquals(s, scaleStrokes(s, 400, 100, 400, 100))
    }

    @Test fun aReloadedFormMergesEachField() {
        val merged = mergeReloadedMap(mapOf("employer" to "Typed Co", "barriers" to "old"), mapOf("employer" to "Old Co", "barriers" to "old"),
            mapOf("employer" to "Their Co", "barriers" to "theirs", "outcome" to "new"))
        assertEquals(mapOf("employer" to "Typed Co", "barriers" to "theirs", "outcome" to "new"), merged)
    }

    @Test fun origHoldsTheOpenedValuesOfTheSentKeys() {
        val sent = JSONObject().put("id", 5).put("employer", "Typed Co").put("status", "Complete")
        val opened = JSONObject().put("employer", "Old Co").put("status", "Draft").put("updated_at", "2026-10-18 09:00:00")
        val o = openedValues(sent, opened)
        assertEquals("Old Co", o.optString("employer")); assertEquals("Draft", o.optString("status"))
        assertFalse(o.has("id")); assertFalse(o.has("updated_at"))
    }
}
