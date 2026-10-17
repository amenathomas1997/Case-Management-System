package org.casemanagement.app

import android.content.Context
import android.os.Build
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.sync.Mutex
import kotlinx.coroutines.sync.withLock
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import org.json.JSONArray
import org.json.JSONObject
import java.io.IOException
import java.net.HttpURLConnection
import java.net.SocketTimeoutException
import java.net.URL
import java.net.URLEncoder
import java.util.UUID

/** A refusal or failure, said in words the person can act on. */
/** [reply] is the server's whole answer, for refusals that carry more (hours already logged). */
class ApiException(message: String, val status: Int = 0, val network: Boolean = false, val reply: JSONObject? = null) : Exception(message)

/**
 * The website's API (api.php), as the iPhone app uses it: the same sign-in, the same actions and
 * the same answers, so the Android app, the iPhone app and the website always show one set of
 * records. Nothing is kept here that the server does not also have, except unsent offline entries.
 */
class Api(context: Context) {
    val store = SecureStore(context)
    /** Where opened documents are kept while signed in (emptied whenever the account changes). */
    val docsFolder = java.io.File(context.applicationContext.cacheDir, "docs")

    var token by mutableStateOf(store.string("apiToken"))
        private set
    var userId by mutableIntStateOf(store.int("userId"))
        private set
    var userName by mutableStateOf(store.string("userName"))
        private set
    var perms by mutableStateOf(store.string("userPerms").split(',').filter { it.isNotEmpty() })
        private set
    var permsLoaded by mutableStateOf(store.bool("userPermsLoaded"))
        private set
    var scoped by mutableStateOf(store.bool("userScoped"))
        private set
    var aiEnabled by mutableStateOf(false)
        private set
    /** What must be finished before client records open (4.16.0): the agreements and training named. */
    var accessHold by mutableStateOf<List<String>>(emptyList())
        private set
    /** The AI features switched on for this account (draft, recommend, intake, voice and the rest). */
    var aiFeatures by mutableStateOf<List<String>>(emptyList())
        private set
    var serverVersion by mutableStateOf<String?>(null)
        private set
    /** Why the app went back to Sign in by itself (an expired session), shown there once. */
    var sessionNotice by mutableStateOf<String?>(null)
    /**
     * The session ended while an account was signed in: sign-in is asked for above the open
     * screens, which stay as they are (a half-written note is kept), as in the iPhone app.
     */
    var reauthRequired by mutableStateOf(false)
        private set
    /** The server's time zone (me.timezone), for "today" and dates sent to it. */
    var serverZone by mutableStateOf(store.string("serverZone").ifEmpty { "America/Chicago" })
        private set
    /** Work that outlives a screen (signing out). */
    val scope = kotlinx.coroutines.CoroutineScope(kotlinx.coroutines.SupervisorJob() + Dispatchers.Main)
    /** Unsent offline entries of this account, and the notice about any the server refused. */
    var pendingSync by mutableIntStateOf(0)
        private set
    var syncNotice by mutableStateOf<String?>(null)

    val isSignedIn: Boolean get() = token.isNotEmpty()

    /** Until the first answer, everything shows (the server enforces access either way). */
    fun can(perm: String) = !permsLoaded || perms.contains(perm)

    /** An AI feature this account may use (the server checks again). */
    fun aiHas(feature: String) = aiEnabled && aiFeatures.contains(feature)

    init {
        pendingSync = ownPending(loadQueue())
        org.casemanagement.app.ui.serverZoneId = serverZone
    }

    /* ---------- The server ---------- */

    /** A build that names its server always uses it; the sign-in choice is only for builds without one. */
    val builtInServer: String? = BuildConfig.DEFAULT_SERVER.trim().ifEmpty { null }

    val base: String
        get() = (builtInServer ?: store.string(SecureStore.SERVER_KEY).ifEmpty { null })
            ?.let { ServerAddress.apiUrl(it, BuildConfig.DEBUG) } ?: "https://example.invalid/api.php"

    /** Another organisation's server: nothing of the last one's (its account, its unsent entries) goes there. */
    fun switchServer(address: String) {
        if (address.trim() == store.string(SecureStore.SERVER_KEY)) return
        userId = 0; userName = ""; store.remove("userId", "userName", "lastEmail")
        store.removePrefix("cache.")
        clearIdentity(keepQueue = false)
        store.put(SecureStore.SERVER_KEY, address.trim())
    }

    val storedServer: String get() = store.string(SecureStore.SERVER_KEY)

    /* ---------- Requests ---------- */

    private fun url(action: String, query: Map<String, String>): String {
        val q = StringBuilder("action=").append(enc(action))
        for ((k, v) in query) q.append('&').append(enc(k)).append('=').append(enc(v))
        return "$base?$q"
    }

    // URLEncoder writes a space as "+", which PHP reads back as a space; a typed "+" is %2B.
    private fun enc(s: String) = URLEncoder.encode(s, "UTF-8").replace("+", "%20")

    private fun waitsOnAI(action: String, body: JSONObject?) =
        action.startsWith("ai_") || action == "at_ai_draft" || action == "workflow_briefing" || body?.has("ai_draft") == true ||
            (action == "voice_command" && body?.has("do") != true)

    /** One request; returns the status and the decoded reply (an empty object when it is not JSON). */
    private suspend fun raw(action: String, query: Map<String, String>, body: JSONObject?, sessionToken: String): Pair<Int, JSONObject> =
        withContext(Dispatchers.IO) {
            val conn = (URL(url(action, query)).openConnection() as HttpURLConnection)
            try {
                val ai = waitsOnAI(action, body)
                conn.connectTimeout = 20_000
                conn.readTimeout = if (ai) 100_000 else 45_000
                conn.setRequestProperty("X-App-Version", APP_VERSION)
                conn.setRequestProperty("Accept", "application/json")
                if (sessionToken.isNotEmpty()) conn.setRequestProperty("Authorization", "Bearer $sessionToken")
                if (body != null) {
                    conn.requestMethod = "POST"
                    conn.doOutput = true
                    conn.setRequestProperty("Content-Type", "application/json")
                    conn.outputStream.use { it.write(body.toString().toByteArray(Charsets.UTF_8)) }
                } else {
                    conn.requestMethod = "GET"
                }
                val status = conn.responseCode
                val stream = if (status >= 400) conn.errorStream else conn.inputStream
                val text = stream?.bufferedReader(Charsets.UTF_8)?.use { it.readText() } ?: ""
                val json = try { JSONObject(text) } catch (e: Exception) { JSONObject() }
                status to json
            } catch (e: SocketTimeoutException) {
                if (waitsOnAI(action, body)) throw ApiException("The AI service took too long to answer. Try again.", 0, network = true)
                throw ApiException(NO_CONNECTION, 0, network = true)
            } catch (e: IOException) {
                throw ApiException(NO_CONNECTION, 0, network = true)
            } finally {
                conn.disconnect()
            }
        }

    /**
     * Sends one action with the current session. A refused session (401) returns to Sign in,
     * saying why; a phone reported lost removes what the app keeps here and signs out.
     */
    suspend fun call(action: String, query: Map<String, String> = emptyMap(), body: JSONObject? = null): JSONObject {
        val sessionToken = token
        val (status, json) = try {
            raw(action, query, body, sessionToken)
        } catch (e: ApiException) {
            if (token != sessionToken) throw ApiException("Your account changed. Please reload this screen.")
            throw e
        }
        if (token != sessionToken) throw ApiException("Your account changed. Please reload this screen.")
        if (status == 401 && json.optBoolean("wipe")) {
            wipeThisPhone()
            throw ApiException(json.optString("error").ifEmpty { "This phone was reported lost, so the app removed what it kept here and signed out." }, 401)
        }
        if (status == 401 && action != "login" && action != "verify") {
            expireSession()
            throw ApiException("Your session ended, so this was not saved. Sign in again (the form stays as it is), then save again.", 401)
        }
        if (!json.optBoolean("ok", status in 200..299) || status !in 200..299) {
            throw ApiException(json.optString("error").ifEmpty { if (status >= 500) "Something went wrong on the server. Nothing was saved." else "Unexpected response from the server." }, status, reply = json)
        }
        return json
    }

    /**
     * [call] for a list the app keeps a copy of (clients, a client, the schedule, follow-ups), as
     * the iPhone app does: the reply is kept for this account, and with no connection the last copy
     * is returned with when it was saved, so the screen can say it is not current. Only on a phone
     * with a screen lock; a refusal (no longer on this caseload) removes the copy.
     */
    suspend fun callCached(action: String, query: Map<String, String> = emptyMap(), cacheKey: String): Pair<JSONObject, Long?> {
        val owner = userId
        val key = "cache.$owner.$cacheKey"
        return try {
            val r = call(action, query)
            if (owner > 0 && owner == userId && store.phoneCanKeepClientData) {
                store.put(key, JSONObject().put("savedAt", System.currentTimeMillis()).put("data", r).toString())
            }
            r to null
        } catch (e: ApiException) {
            if (!e.network) { if (e.status == 403 || e.status == 404) store.remove(key); throw e }
            val saved = store.string(key).takeIf { it.isNotEmpty() && owner > 0 && store.phoneCanKeepClientData } ?: throw e
            val o = try { JSONObject(saved) } catch (x: Exception) { throw e }
            (o.optJSONObject("data") ?: throw e) to o.optLong("savedAt")
        }
    }

    /**
     * A client's document, saved in the app's own temporary folder (removed at sign-out) so
     * another app can open it. Returns the file.
     */
    suspend fun download(documentId: Int, folder: java.io.File): java.io.File = withContext(Dispatchers.IO) {
        if (!store.phoneCanKeepClientData) throw ApiException("This phone has no screen lock, so documents are not saved on it to open. Open it on the website, or set a screen lock in Settings.")
        val sessionToken = token
        val conn = (URL(url("download_document", mapOf("id" to documentId.toString()))).openConnection() as HttpURLConnection)
        try {
            conn.connectTimeout = 20_000; conn.readTimeout = 60_000
            conn.setRequestProperty("X-App-Version", APP_VERSION)
            conn.setRequestProperty("Authorization", "Bearer $sessionToken")
            val status = conn.responseCode
            if (status != 200) {
                val json = try { JSONObject(conn.errorStream?.bufferedReader()?.readText() ?: "") } catch (e: Exception) { JSONObject() }
                if (status == 401) withContext(Dispatchers.Main) { if (json.optBoolean("wipe")) wipeThisPhone() else expireSession() }
                throw ApiException(json.optString("error").ifEmpty { "The document could not be downloaded." }, status)
            }
            val original = Regex("filename=\"?([^\";]+)").find(conn.getHeaderField("Content-Disposition") ?: "")?.groupValues?.get(1)
                ?.replace(Regex("[^A-Za-z0-9._ -]"), "_")?.trim('.', ' ')?.take(80)
            // The document's number first: two documents with one name never overwrite each other.
            val name = "$documentId-" + (original?.ifEmpty { null } ?: "document")
            folder.mkdirs()
            val file = java.io.File(folder, name)
            conn.inputStream.use { input -> file.outputStream().use { input.copyTo(it) } }
            // Signed out (or another account) while it downloaded: nothing of it stays on the phone.
            if (token != sessionToken) { file.delete(); throw ApiException("Your account changed, so the document was not kept.") }
            file
        } catch (e: IOException) {
            throw ApiException(NO_CONNECTION, 0, network = true)
        } finally { conn.disconnect() }
    }

    /**
     * Uploads a document without holding it in memory: the file (already copied to [file], at most
     * 25 MB) is sent as base64 straight from disk inside the JSON body, with an exact length.
     */
    suspend fun uploadDocument(clientId: Int, category: String, title: String, filename: String, file: java.io.File) = withContext(Dispatchers.IO) {
        val sessionToken = token
        val prefix = "{\"client_id\":$clientId,\"category\":${JSONObject.quote(category)},\"title\":${JSONObject.quote(title)},\"filename\":${JSONObject.quote(filename)},\"data_b64\":\""
        val suffix = "\"}"
        val n = file.length()
        val b64Len = 4 * ((n + 2) / 3)
        val pre = prefix.toByteArray(Charsets.UTF_8); val suf = suffix.toByteArray(Charsets.UTF_8)
        val conn = (URL(url("upload_document", emptyMap())).openConnection() as HttpURLConnection)
        try {
            conn.connectTimeout = 20_000; conn.readTimeout = 120_000
            conn.requestMethod = "POST"; conn.doOutput = true
            conn.setRequestProperty("X-App-Version", APP_VERSION)
            conn.setRequestProperty("Authorization", "Bearer $sessionToken")
            conn.setRequestProperty("Content-Type", "application/json")
            conn.setFixedLengthStreamingMode(pre.size + b64Len + suf.size)
            conn.outputStream.use { out ->
                out.write(pre)
                // Base64 in whole 3-byte groups, written as it is read.
                file.inputStream().buffered().use { input ->
                    val enc = java.util.Base64.getEncoder()
                    val buf = ByteArray(3 * 16384)
                    var carry = ByteArray(0)
                    while (true) {
                        val r = input.read(buf); if (r < 0) break
                        val chunk = carry + buf.copyOf(r)
                        val whole = chunk.size - chunk.size % 3
                        out.write(enc.encode(chunk.copyOf(whole)))
                        carry = chunk.copyOfRange(whole, chunk.size)
                    }
                    if (carry.isNotEmpty()) out.write(enc.encode(carry))
                }
                out.write(suf)
            }
            val status = conn.responseCode
            val text = (if (status >= 400) conn.errorStream else conn.inputStream)?.bufferedReader()?.use { it.readText() } ?: ""
            val json = try { JSONObject(text) } catch (e: Exception) { JSONObject() }
            if (status == 401) withContext(Dispatchers.Main) { if (json.optBoolean("wipe")) wipeThisPhone() else expireSession() }
            if (status !in 200..299 || !json.optBoolean("ok")) throw ApiException(json.optString("error").ifEmpty { "The document was not uploaded." }, status)
        } catch (e: IOException) {
            throw ApiException(NO_CONNECTION, 0, network = true)
        } finally { conn.disconnect() }
    }

    /* ---------- Sign-in ---------- */

    /** The email last signed in with, offered when signing back in. */
    val lastEmail: String get() = store.string("lastEmail")

    fun finishSignIn(newToken: String, newUserId: Int, name: String, email: String) {
        reauthRequired = false
        store.put("lastEmail", email)
        if (newUserId != userId) { store.removePrefix("cache."); clearIdentity(); syncNotice = null }
        userId = newUserId; store.put("userId", newUserId)
        userName = name; store.put("userName", name)
        sessionNotice = null
        token = newToken; store.put("apiToken", newToken)
    }

    /** Name, permissions and the organisation's lists; after sign-in and on each start. */
    suspend fun refreshMe() {
        val sessionToken = token
        val me = try { call("me") } catch (e: ApiException) { return }
        if (token != sessionToken || token.isEmpty()) return
        userName = me.optString("name", userName); store.put("userName", userName)
        if (me.has("id")) { userId = me.optInt("id", userId); store.put("userId", userId) }
        perms = me.optJSONArray("perms").strings(); store.put("userPerms", perms.joinToString(","))
        scoped = me.optBoolean("scoped"); store.put("userScoped", scoped)
        aiEnabled = me.optBoolean("ai_enabled")
        aiFeatures = me.optJSONArray("ai_features").strings()
        accessHold = me.optJSONArray("access_hold").strings()
        serverVersion = me.optString("server_version").ifEmpty { null }
        me.optString("timezone").takeIf { it.isNotEmpty() && java.util.TimeZone.getAvailableIDs().contains(it) }?.let { serverZone = it; store.put("serverZone", it); org.casemanagement.app.ui.serverZoneId = it }
        permsLoaded = true; store.put("userPermsLoaded", true)
        try {
            val lists = call("form_options").optJSONObject("lists")
            if (lists != null && token == sessionToken) store.put("formOptions", lists.toString())
        } catch (e: ApiException) {
            if (!e.network) store.remove("formOptions")
        }
    }

    /** "Please update" when the website is a newer major release than this app. */
    val updateNotice: String?
        get() {
            val s = serverVersion?.substringBefore('.')?.toIntOrNull() ?: return null
            val a = BuildConfig.VERSION_NAME.substringBefore('.').toIntOrNull() ?: return null
            return if (s > a) "The website is version $serverVersion and this app is ${BuildConfig.VERSION_NAME}. Please update the app to use everything the website offers." else null
        }

    /** A list set on Lists and time limits, or the built-in one when the server sent none. */
    fun formList(name: String, builtIn: List<String>): List<String> {
        val raw = store.string("formOptions")
        if (raw.isEmpty()) return builtIn
        val list = try { JSONObject(raw).optJSONArray(name).strings() } catch (e: Exception) { emptyList() }
        return list.ifEmpty { builtIn }
    }

    fun expireSession() {
        if (token.isEmpty()) return
        sessionNotice = "Your session ended, so sign in again. Your open screens and anything waiting to send are kept."
        if (userId > 0) reauthRequired = true
        token = ""; store.remove("apiToken")
    }

    /** Sign in as someone else instead of signing back in: the open screens close. */
    fun abandonReauth() {
        reauthRequired = false
        sessionNotice = null
    }

    /** Signs out here and ends the session on the server. Offline entries of this account are kept. */
    fun signOut() {
        val old = token
        token = ""; store.remove("apiToken")
        reauthRequired = false; sessionNotice = null; syncNotice = null
        store.removePrefix("cache.")
        clearIdentity(keepQueue = true)
        if (old.isNotEmpty()) scope.launch { try { raw("logout", emptyMap(), JSONObject(), old) } catch (e: Exception) { } }
    }

    private fun clearIdentity(keepQueue: Boolean = true) {
        docsFolder.deleteRecursively()
        perms = emptyList(); permsLoaded = false; scoped = false; aiEnabled = false; aiFeatures = emptyList(); accessHold = emptyList(); serverVersion = null
        store.remove("userPerms", "userPermsLoaded", "userScoped", "formOptions")
        if (!keepQueue) store.remove(QUEUE_KEY)
        pendingSync = ownPending(loadQueue())
    }

    fun wipeThisPhone() {
        store.wipeExceptServer()
        token = ""; userId = 0; userName = ""; reauthRequired = false; syncNotice = null
        clearIdentity(keepQueue = false)
        sessionNotice = "This phone was reported lost, so the app removed what it kept here."
    }

    /* ---------- Offline entries (the iPhone app's queue, the same rules) ---------- */

    private val queueLock = Mutex()   // reading and writing the stored queue
    private val flushLock = Mutex()   // one upload at a time

    private fun loadQueue(): MutableList<JSONObject> {
        val raw = store.string(QUEUE_KEY)
        if (raw.isEmpty()) return mutableListOf()
        return try { JSONArray(raw).let { a -> MutableList(a.length()) { a.getJSONObject(it) } } } catch (e: Exception) { mutableListOf() }
    }

    private fun saveQueue(q: List<JSONObject>) {
        if (!store.putNow(QUEUE_KEY, JSONArray(q).toString())) throw ApiException("Could not save on this phone. Nothing was lost; try again.")
        pendingSync = ownPending(q)
    }

    private fun ownPending(q: List<JSONObject>) = q.count { it.optInt("owner") == userId && userId > 0 }

    fun ownQueued(): List<JSONObject> = loadQueue().filter { it.optInt("owner") == userId && userId > 0 }

    /**
     * Keeps an entry to send when there is a signal. Its idem_key goes with every try, so a reply
     * lost on the way can never save it twice.
     */
    suspend fun enqueue(action: String, body: JSONObject, label: String, owner: Int) = queueLock.withLock {
        if (owner != userId) throw ApiException("Your account changed, so this was not kept. Nothing was saved.")
        if (!store.phoneCanKeepClientData) {
            throw ApiException("This phone has no screen lock, so the app keeps nothing on it to send later. Save again when you have a signal, or set a screen lock in Settings.")
        }
        if (userId <= 0) throw ApiException("Could not verify the account for offline saving. Keep this form open and reconnect before saving.")
        val id = body.optString("idem_key").ifEmpty { UUID.randomUUID().toString() }
        body.put("idem_key", id)
        val q = loadQueue()
        if (q.none { it.optString("id") == id && it.optInt("owner") == userId }) {
            q.add(JSONObject().put("id", id).put("action", action).put("body", body.toString()).put("label", label)
                .put("owner", userId).put("savedAt", System.currentTimeMillis()))
        }
        saveQueue(q)
    }

    suspend fun discardQueued(id: String) = queueLock.withLock {
        val q = loadQueue()
        q.removeAll { it.optString("id") == id && it.optInt("owner") == userId }
        saveQueue(q)
        if (pendingSync == 0) syncNotice = null
    }

    /** Sends this account's entries in order; keeps every one the server has not confirmed. */
    suspend fun flushQueue() = flushLock.withLock {
        if (userId <= 0 || token.isEmpty()) return@withLock
        val owner = userId
        val sessionToken = token
        val done = mutableSetOf<String>()
        val refused = mutableMapOf<String, String>()
        var transient: String? = null
        for (item in queueLock.withLock { loadQueue() }) {
            if (token != sessionToken || userId != owner) break
            if (item.optInt("owner") != owner) continue
            val (status, json) = try {
                raw(item.optString("action"), emptyMap(), JSONObject(item.optString("body")), sessionToken)
            } catch (e: ApiException) { break }
            when {
                status == 401 -> { if (json.optBoolean("wipe")) wipeThisPhone() else expireSession(); break }
                status in 200..299 && json.optBoolean("ok") -> done.add(item.optString("id"))
                // Already checked in (another phone, or an earlier try whose reply was lost).
                status == 409 && item.optString("action") == "check_in_session" -> done.add(item.optString("id"))
                status >= 500 || status == 429 -> {
                    transient = "“${item.optString("label")}” could not sync yet and is still saved on this phone. " +
                        json.optString("error").ifEmpty { "It will retry automatically when you reconnect." }
                    break
                }
                else -> refused[item.optString("id")] = json.optString("error").ifEmpty { "The server did not accept it." }
            }
        }
        if (userId != owner) return@withLock
        // Read again, so entries kept while this upload ran are not lost.
        val current = queueLock.withLock {
            val q = loadQueue()
            q.removeAll { it.optInt("owner") == owner && done.contains(it.optString("id")) }
            for (item in q) refused[item.optString("id")]?.let { if (item.optInt("owner") == owner) item.put("lastError", it) }
            saveQueue(q)
            q
        }
        val firstRefused = current.firstOrNull { it.optInt("owner") == owner && it.has("lastError") }
        syncNotice = transient ?: firstRefused?.let {
            "“${it.optString("label")}” was not accepted: ${it.optString("lastError")} It is kept under Offline entries on this phone."
        }
    }

    companion object {
        const val QUEUE_KEY = "offlineQueue"
        const val NO_CONNECTION = "No connection to the server. Check your signal and try again."
        val APP_VERSION = "${BuildConfig.VERSION_NAME} (${BuildConfig.VERSION_CODE}) Android"
        val deviceName: String get() = listOf(Build.MANUFACTURER.replaceFirstChar { it.uppercase() }, Build.MODEL).distinct().joinToString(" ").take(60)
    }
}

/** A JSON array of strings as a list (null or odd values give an empty list). */
fun JSONArray?.strings(): List<String> = if (this == null) emptyList() else List(length()) { optString(it) }.filter { it.isNotEmpty() }

/** A JSON array of objects as a list. */
fun JSONArray?.objects(): List<JSONObject> = if (this == null) emptyList() else (0 until length()).mapNotNull { optJSONObject(it) }

/** A string that may be JSON null, as Kotlin null. */
fun JSONObject.str(key: String): String? = if (isNull(key)) null else optString(key).ifEmpty { null }
