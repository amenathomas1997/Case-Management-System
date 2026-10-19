package org.casemanagement.app

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

/** 4.27.0: the client records kept for when there is no signal are limited (M21). */
class CachePolicyTest {
    private val day = 24L * 60 * 60 * 1000
    private val now = 100 * day

    @Test fun clientRecordKeys() {
        assertTrue(CachePolicy.isClientRecord("cache.7.client.42"))
        assertFalse(CachePolicy.isClientRecord("cache.7.clients"))
        assertFalse(CachePolicy.isClientRecord("cache.7.schedule"))
        assertFalse(CachePolicy.isClientRecord("cache.7.client.42.extra"))
    }

    @Test fun onlyTheMostRecentClientsAreKept() {
        val saved = (1..35).associate { "cache.7.client.$it" to now - it * 1000L } + ("cache.7.clients" to now - 99_000L)
        val drop = CachePolicy.toDrop(saved, now, checkAge = false)
        assertEquals((31..35).map { "cache.7.client.$it" }.toSet(), drop)
    }

    @Test fun thirtyOrFewerAreAllKept() {
        val saved = (1..30).associate { "cache.7.client.$it" to now - it * 1000L }
        assertTrue(CachePolicy.toDrop(saved, now).isEmpty())
    }

    @Test fun oldUnreadableAndFutureCopiesGoAtStartUp() {
        val saved = mapOf(
            "cache.7.client.1" to now - 15 * day,
            "cache.7.schedule" to now - 20 * day,
            "cache.7.client.2" to 0L,
            "cache.7.client.3" to now + 3 * day,
            "cache.7.client.4" to now - 13 * day,
            "cache.7.clients" to now - day,
        )
        assertEquals(setOf("cache.7.client.1", "cache.7.schedule", "cache.7.client.2", "cache.7.client.3"), CachePolicy.toDrop(saved, now))
        // After an ordinary save only the number counts.
        assertTrue(CachePolicy.toDrop(saved, now, checkAge = false).isEmpty())
    }
}
