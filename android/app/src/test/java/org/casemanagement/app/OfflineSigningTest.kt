package org.casemanagement.app

import org.json.JSONObject
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test
import org.casemanagement.app.ui.launchSave
import java.util.TimeZone

/** Signing with no signal (4.26.0) and the other 4.26.0 helpers: the plain rules, apart from Android. */
class OfflineSigningTest {
    @Test fun namesMatchAsTheServerChecks() {
        assertTrue(typedNameMatches("María José O'Neil", "maria jose oneil"))
        assertTrue(typedNameMatches("María José O'Neil", "  MARIA-JOSÉ O NEIL "))
        assertTrue(typedNameMatches("Anna Strauß", "anna strauss"))
        assertFalse(typedNameMatches("María José O'Neil", "Maria Oneil"))
        assertFalse(typedNameMatches("Sam Lee", ""))
        assertFalse(typedNameMatches("Sam Lee", "x".repeat(121)))
        // A name with no letters or digits is compared as typed, ignoring case.
        assertTrue(typedNameMatches("—", "—"))
    }

    @Test fun signedTimeHasItsOffset() {
        // 2026-10-18 19:05:00 UTC is 14:05 in Chicago (daylight time, five hours behind).
        val t = 1_792_350_300_000L
        assertEquals("2026-10-18T14:05:00-05:00", isoTime(t, TimeZone.getTimeZone("America/Chicago")))
        assertEquals("2026-10-18T19:05:00Z", isoTime(t, TimeZone.getTimeZone("UTC")))
    }

    @Test fun preparedDocumentsLastTwoDays() {
        val start = 1_000_000_000_000L
        assertTrue(stillSignable(start, start + 60_000))
        assertTrue(stillSignable(start, start + SIGN_WINDOW_MS - 120_000))
        assertFalse(stillSignable(start, start + SIGN_WINDOW_MS))
        assertFalse(stillSignable(0, start))
    }

    @Test fun sitePagesStayOnTheSite() {
        assertEquals("https://cms.example.org/track.php?id=3", sitePage("https://cms.example.org/api.php", "track.php?id=3"))
        assertEquals("https://example.org/cms/help.php#staff-guide", sitePage("https://example.org/cms/api.php", "help.php#staff-guide"))
        assertEquals("http://10.0.2.2:8099/privacy.php", sitePage("http://10.0.2.2:8099/api.php", "privacy.php"))
        assertNull(sitePage("https://cms.example.org/api.php", "https://evil.example/x"))
        assertNull(sitePage("https://cms.example.org/api.php", "//evil.example/x"))
        assertNull(sitePage("https://cms.example.org/api.php", "javascript:alert(1)"))
        assertNull(sitePage("https://cms.example.org/api.php", "/etc/passwd"))
        assertNull(sitePage("https://cms.example.org/api.php", ""))
    }

    @Test fun serviceIntervals() {
        assertEquals(0, intervalDays("0"))
        assertEquals(365, intervalDays(" 365 "))
        assertEquals(3650, intervalDays("3650"))
        assertNull(intervalDays("3651"))
        assertNull(intervalDays("7.5"))
        assertNull(intervalDays("-1"))
        assertNull(intervalDays(""))
    }

    @Test fun briefingWithItsSources() {
        val r = JSONObject("""{"ok":true,"omitted":0,"items":[
            {"text":"Goal 1 is in progress.","type":"fact","sources":{"G2":{"label":"Goal #2","url":"track.php?id=3"},"N1":{"label":"Note #1 — Visit","url":"client.php?id=5"}}},
            {"text":"Plan a follow-up visit.","type":"suggestion","sources":{"T3":{"label":"Braille track","url":"track.php?id=3"}}}]}""")
        val b = parseBriefing(r)
        assertEquals(2, b.size)
        assertTrue(b[0].isFact)
        assertFalse(b[1].isFact)
        assertEquals(listOf("G2", "N1"), b[0].sources.map { it.id })
        assertEquals("Note #1 — Visit", b[0].sources[1].label)
        assertEquals("track.php?id=3", b[1].sources[0].url)
        val a = parseWorkflowActions(JSONObject("""{"ok":true,"items":[{"kind":"Task","title":"Call Sam","due":"2026-10-17","url":"tasks.php#task-act-4","priority":2},
            {"kind":"Referral","title":"Pat (received 2026-10-01)","due":null,"url":"referrals.php?view=9","priority":2}]}"""))
        assertEquals(listOf("Task", "Referral"), a.map { it.kind })
        assertNull(a[1].due)
    }

    @Test fun theWindowRunsFromTheServersStart() {
        // The server's "2026-10-18 12:01:00" in Chicago is 17:01 UTC.
        val start = serverTimeMillis("2026-10-18 12:01:00", "America/Chicago")!!
        assertEquals(1_792_342_860_000L, start)
        assertNull(serverTimeMillis(null, "America/Chicago"))
        assertNull(serverTimeMillis("not a time", "America/Chicago"))
        // An older server sends no sent_at: the phone's time stands in.
        assertEquals(42L, signingStart(null, "America/Chicago", 42L))
        assertEquals(start, signingStart("2026-10-18 12:01:00", "America/Chicago", 42L))
        // Signable until two days after the server's start, less the one-minute margin.
        assertTrue(stillSignable(start, start + SIGN_WINDOW_MS - 61_000))
        assertFalse(stillSignable(start, start + SIGN_WINDOW_MS - 59_000))
    }

    @Test fun aPreparedDocumentKeepsItsStart() {
        val doc = parseSignatureDoc(JSONObject("""{"ok":true,"id":7,"title":"Consent","body":"Text","lang":"es","signer_name":"Sam Lee","org":"AT Services","sent_at":"2026-10-18 12:01:00"}"""))!!
        assertEquals("2026-10-18 12:01:00", doc.sentAt)
        assertEquals(doc, parseSignatureDoc(doc.toJson()))
        assertNull(parseSignatureDoc(JSONObject("""{"id":0}""")))
    }

    @Test fun busyIsSetBeforeTheSaveRuns() {
        // A scope whose work never runs: busy must already be true when launchSave returns,
        // so a second tap in the same moment is turned away.
        val never = kotlinx.coroutines.CoroutineScope(java.util.concurrent.Executor { }.let { e -> object : kotlinx.coroutines.CoroutineDispatcher() {
            override fun dispatch(context: kotlin.coroutines.CoroutineContext, block: Runnable) = e.execute(block)
        } })
        var busy = false
        var ran = false
        never.launchSave({ busy = it }, { }) { ran = true }
        assertTrue(busy)
        assertFalse(ran)
    }
}

