package org.casemanagement.app

/**
 * How much of the server's lists the phone keeps for when there is no signal (4.27.0): the client
 * records most recently opened (at most [MAX_CLIENTS]), and nothing older than [MAX_AGE_MS]. The
 * keys are "cache.<account>.<what>", a client record being "cache.<account>.client.<id>".
 */
object CachePolicy {
    const val MAX_CLIENTS = 30
    const val MAX_AGE_MS = 14L * 24 * 60 * 60 * 1000
    private const val DAY_MS = 24L * 60 * 60 * 1000
    private val clientKey = Regex("""^cache\.\d+\.client\.\d+$""")

    fun isClientRecord(key: String) = clientKey.matches(key)

    /**
     * The kept copies to remove, given when each was saved ([saved]: key to time in milliseconds;
     * 0 when it cannot be read). [checkAge] is false after an ordinary save, when only the number
     * of client records is held to the limit.
     */
    fun toDrop(saved: Map<String, Long>, now: Long, checkAge: Boolean = true, maxClients: Int = MAX_CLIENTS, maxAgeMs: Long = MAX_AGE_MS): Set<String> {
        val drop = mutableSetOf<String>()
        if (checkAge) {
            // Unreadable, too old, or dated more than a day ahead (a phone clock that was wrong).
            for ((k, at) in saved) if (at <= 0 || now - at > maxAgeMs || at > now + DAY_MS) drop.add(k)
        }
        saved.entries.filter { isClientRecord(it.key) && it.key !in drop }
            .sortedByDescending { it.value }
            .drop(maxClients)
            .forEach { drop.add(it.key) }
        return drop
    }
}
