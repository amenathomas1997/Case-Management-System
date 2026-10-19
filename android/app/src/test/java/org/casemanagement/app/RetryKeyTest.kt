package org.casemanagement.app

import org.junit.Assert.assertEquals
import org.junit.Assert.assertNotEquals
import org.junit.Test

/** 4.27.0: a retry keeps its key only while the request is unchanged. */
class RetryKeyTest {
    private var n = 0
    private val next = { "key${++n}" }

    @Test fun sameRequestKeepsItsKey() {
        val first = retryKey("", """{"a":1}""", next)
        assertEquals("key1", retryKeyId(first))
        assertEquals(first, retryKey(first, """{"a":1}""", next))
    }

    @Test fun anyChangeGivesANewKey() {
        val first = retryKey("", """{"title":"Loan"}""", next)
        val changed = retryKey(first, """{"title":"Loan2"}""", next)
        assertNotEquals(retryKeyId(first), retryKeyId(changed))
    }

    @Test fun answeredStartsAgain() {
        assertEquals("key1", retryKeyId(retryKey("", "x", next)))
        assertEquals("key2", retryKeyId(retryKey("", "x", next)))
    }
}
