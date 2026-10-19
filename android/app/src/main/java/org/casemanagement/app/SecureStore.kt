package org.casemanagement.app

import android.app.KeyguardManager
import android.content.Context
import android.content.SharedPreferences
import androidx.core.content.edit
import androidx.security.crypto.EncryptedSharedPreferences
import androidx.security.crypto.MasterKey
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import org.json.JSONObject

/**
 * What the app keeps on the phone: the session, the account's permissions, the organisation's
 * lists and unsent offline entries. All of it is encrypted with a key held in the phone's
 * hardware keystore (as the iPhone app uses the Keychain), and none of it goes into backups
 * (see res/xml/backup_rules.xml).
 *
 * Since 4.27.0 it is four encrypted files, not one: the offline queue, the signing copies and the
 * kept lists each have their own, so saving a large client record never rewrites the queue (and
 * the reverse). Which file a key lives in is decided here; callers use the same keys as before.
 */
class SecureStore(context: Context) {
    private val app = context.applicationContext
    private val main: SharedPreferences
    private val queue: SharedPreferences
    private val signing: SharedPreferences
    private val cache: SharedPreferences

    init {
        val opened = try {
            openAll()
        } catch (e: Exception) {
            // The key was lost (a restored phone, some system updates): what was kept cannot be read,
            // so it is removed and the person signs in again, rather than the app failing every launch.
            FILES.forEach { app.deleteSharedPreferences(it) }
            try { java.security.KeyStore.getInstance("AndroidKeyStore").apply { load(null) }.deleteEntry(MasterKey.DEFAULT_MASTER_KEY_ALIAS) } catch (x: Exception) { }
            openAll()
        }
        main = opened[0]; queue = opened[1]; signing = opened[2]; cache = opened[3]
        migrate()
    }

    private fun openAll(): List<SharedPreferences> {
        val key = MasterKey.Builder(app).setKeyScheme(MasterKey.KeyScheme.AES256_GCM).build()
        return FILES.map {
            EncryptedSharedPreferences.create(app, it, key,
                EncryptedSharedPreferences.PrefKeyEncryptionScheme.AES256_SIV,
                EncryptedSharedPreferences.PrefValueEncryptionScheme.AES256_GCM)
        }
    }

    /** The file a key belongs in (4.27.0). */
    private fun homeFor(key: String): SharedPreferences = when {
        key == Api.QUEUE_KEY -> queue
        key == Api.PREPARED_KEY || key == Api.KEPT_KEY || key == Api.SIGNING_KEY -> signing
        key.startsWith(CACHE_PREFIX) -> cache
        else -> main
    }

    /**
     * The file a key is read from and written to: its own file, except while a copy kept by 4.26.0
     * or earlier is still in the main file (a move that did not finish), when that copy is used, so
     * nothing waiting to send is ever hidden or overwritten.
     */
    private fun fileFor(key: String): SharedPreferences {
        val home = homeFor(key)
        return if (home !== main && main.contains(key)) main else home
    }

    /**
     * Moves what 4.26.0 and earlier kept in the one file into its own file. Each key leaves the main
     * file only after its new copy is confirmed written; until then it is used where it is. Kept lists
     * beyond the limits are dropped rather than moved. A failure leaves everything where it was.
     */
    private fun migrate() {
        try {
            val moving = main.all.filter { (k, v) -> homeFor(k) !== main && v is String }
            if (moving.isEmpty()) return
            val saved = moving.filterKeys { it.startsWith(CACHE_PREFIX) }.mapValues { (_, v) -> savedAt(v as String) }
            val dropped = CachePolicy.toDrop(saved, System.currentTimeMillis())
            for ((home, entries) in moving.entries.filter { it.key !in dropped }.groupBy { homeFor(it.key) }) {
                // The main file's copy is the one in use, so it replaces any earlier partial copy.
                val ok = home.edit().apply { entries.forEach { putString(it.key, it.value as String) } }.commit()
                if (ok) main.edit(commit = true) { entries.forEach { remove(it.key) } }
            }
            if (dropped.isNotEmpty()) main.edit(commit = true) { dropped.forEach { remove(it) } }
        } catch (e: Exception) {
            // One copy that cannot be read must not stop the app opening: it is used where it is.
        }
    }

    private fun savedAt(json: String): Long = try { JSONObject(json).optLong("savedAt") } catch (e: Exception) { 0L }

    fun string(key: String): String = fileFor(key).getString(key, "") ?: ""
    fun int(key: String): Int = fileFor(key).getInt(key, 0)
    fun bool(key: String): Boolean = fileFor(key).getBoolean(key, false)
    fun put(key: String, value: String) = fileFor(key).edit { putString(key, value) }
    fun put(key: String, value: Int) = fileFor(key).edit { putInt(key, value) }
    fun put(key: String, value: Boolean) = fileFor(key).edit { putBoolean(key, value) }
    fun remove(vararg keys: String) = keys.groupBy { fileFor(it) }.forEach { (file, ks) -> file.edit { ks.forEach { remove(it) } } }

    /**
     * Kept lists belong to one session (4.27.0): signing out, another account and a wipe move this on,
     * under [cacheLock], so a reply still being saved in the background is not kept afterwards.
     */
    private val cacheLock = Any()
    @Volatile var cacheGeneration = 0L
        private set

    /** Removes every key starting with [prefix] (an account's saved lists). */
    fun removePrefix(prefix: String) = synchronized(cacheLock) {
        cacheGeneration++
        listOf(main, cache).forEach { file ->
            val ks = file.all.keys.filter { it.startsWith(prefix) }
            if (ks.isNotEmpty()) file.edit { ks.forEach { remove(it) } }
        }
    }

    /** Written at once (the offline queue must not be lost if the app is closed straight after). */
    fun putNow(key: String, value: String): Boolean = fileFor(key).edit().putString(key, value).commit()

    /** [putNow], encrypted and written off the main thread (4.27.0). */
    suspend fun putNowInBackground(key: String, value: String): Boolean = withContext(Dispatchers.IO) {
        try { putNow(key, value) } catch (e: Exception) { false }
    }

    /**
     * Everything, on a phone reported lost. The server address stays, so sign-in does not ask again.
     * True when every file was cleared on disk.
     */
    // commit, not apply: whether it reached the disk decides whether the server is told the wipe is done.
    @android.annotation.SuppressLint("ApplySharedPref", "UseKtx")
    fun wipeExceptServer(): Boolean = synchronized(cacheLock) {
        cacheGeneration++
        val server = string(SERVER_KEY)
        val cleared = listOf(queue, signing, cache, main).map { try { it.edit().clear().commit() } catch (e: Exception) { false } }.all { it }
        if (server.isNotEmpty()) put(SERVER_KEY, server)
        cleared
    }

    /* ---------- Kept lists (4.27.0: limited, see CachePolicy) ---------- */

    /**
     * Keeps a copy of a list for when there is no signal, off the main thread; then holds the client
     * records to the limit. Not kept when the session moved on since [generation] was read.
     */
    suspend fun putCached(key: String, value: String, generation: Long) = withContext(Dispatchers.IO) {
        try {
            synchronized(cacheLock) {
                if (generation != cacheGeneration) return@withContext
                cache.edit { putString(key, value) }
            }
            if (CachePolicy.isClientRecord(key)) pruneCache(System.currentTimeMillis(), checkAge = false)
        } catch (e: Exception) { }
    }

    suspend fun cached(key: String): String = withContext(Dispatchers.IO) { try { string(key) } catch (e: Exception) { "" } }

    /** Removes kept copies past the limits: at start-up every one older than two weeks, and always the client records beyond the most recent. */
    fun pruneCache(now: Long, checkAge: Boolean = true) {
        val saved = cache.all.mapNotNull { (k, v) ->
            if (!k.startsWith(CACHE_PREFIX)) return@mapNotNull null
            k to (if (v is String) savedAt(v) else 0L)
        }.toMap()
        val drop = CachePolicy.toDrop(saved, now, checkAge)
        if (drop.isNotEmpty()) cache.edit { drop.forEach { remove(it) } }
    }

    /**
     * Client details are kept on this phone (offline entries, saved lists) only when it has a
     * screen lock, as the iPhone app requires a passcode (3.0.39).
     */
    val phoneCanKeepClientData: Boolean
        get() = (app.getSystemService(Context.KEYGUARD_SERVICE) as KeyguardManager).isDeviceSecure

    companion object {
        const val SERVER_KEY = "serverAddress"
        const val FILE = "cms_secure"
        const val CACHE_PREFIX = "cache."
        /** The main file first; then the offline queue, the signing copies and the kept lists (4.27.0). */
        val FILES = listOf(FILE, "cms_queue", "cms_signing", "cms_cache")
    }
}
