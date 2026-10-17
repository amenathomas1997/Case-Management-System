package org.casemanagement.app

import org.casemanagement.app.ui.time24
import org.casemanagement.app.ui.yesOrNo
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Test

class VoiceAnswerTest {
    @Test fun plainYesAndNo() {
        assertEquals("yes", yesOrNo("yes"))
        assertEquals("yes", yesOrNo("Yes please"))
        assertEquals("yes", yesOrNo("go ahead"))
        assertEquals("no", yesOrNo("no"))
        assertEquals("no", yesOrNo("don't"))
        assertEquals("no", yesOrNo("yes, no wait"))
    }

    @Test fun anythingElseIsUnclear() {
        assertNull(yesOrNo("yes but change the date to Friday"))
        assertNull(yesOrNo("yes?"))
        assertNull(yesOrNo("maybe"))
        assertNull(yesOrNo(""))
        // A number with the yes is a change, not a yes to the original amount.
        assertNull(yesOrNo("yes, 3"))
        assertNull(yesOrNo("ok 2"))
    }

    @Test fun timesAreReadAsPeopleWriteThem() {
        assertEquals("14:30", time24("2:30 PM"))
        assertEquals("09:05", time24("9:05"))
        assertEquals("00:15", time24("12:15 am"))
        assertEquals("12:00", time24("12pm"))
        assertNull(time24("25:00"))
        assertNull(time24("2:61 pm"))
    }
}
