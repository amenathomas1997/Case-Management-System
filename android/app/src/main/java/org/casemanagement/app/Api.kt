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
    /**
     * Work that outlives a screen (signing out, signing in person). A failure in it (a full phone,
     * 4.27.0) shows the sync notice instead of closing the app.
     */
    val scope = kotlinx.coroutines.CoroutineScope(kotlinx.coroutines.SupervisorJob() + Dispatchers.Main +
        kotlinx.coroutines.CoroutineExceptionHandler { _, e -> syncNotice = problemNotice(e) })

    /**
     * What to say about a failure in background work (4.27.0): storage (a full phone, the keystore)
     * as such; anything else plainly, logged for whoever looks into it. Work kept on the phone stays.
     */
    private fun problemNotice(e: Throwable): String {
        val storage = e is IOException || e is java.security.GeneralSecurityException || (e is ApiException && e.message == SAVE_FAILED)
        if (!storage) android.util.Log.e("CMS", "Background work failed", e)
        return if (storage) STORAGE_PROBLEM else SOMETHING_WRONG
    }
    /** Unsent offline entries of this account, and the notice about any the server refused. */
    var pendingSync by mutableIntStateOf(0)
        private set
    var syncNotice by mutableStateOf<String?>(null)

    /**
     * A document being signed in person on this phone (4.25.0), with "_client_id" and, once signed,
     * "_signed". Kept on the phone until staff unlock it, so closing or restarting the app never
     * lets the signer reach client records.
     */
    var inPersonSigning by mutableStateOf(store.string(SIGNING_KEY).let { if (it.isEmpty()) null else try { JSONObject(it) } catch (e: Exception) { null } })
        private set

    /** [prepared]: opened from a copy kept on this phone (4.26.0), so leaving it unsigned keeps it rather than withdrawing it. */
    fun beginInPerson(clientId: Int, doc: JSONObject, prepared: Boolean = false, startedAt: Long? = null) {
        val o = JSONObject(doc.toString()).put("_client_id", clientId)
        // When the server started it (its sent_at): signing is checked against the two days it allows.
        o.put("_started", startedAt ?: signingStart(doc.str("sent_at"), serverZone, System.currentTimeMillis()))
        if (prepared) o.put("_prepared", true)
        store.putNow(SIGNING_KEY, o.toString())
        inPersonSigning = o
        inPersonSending = false; inPersonError = null
    }

    /**
     * The Sign press while signing in person (4.27.0): sent from [scope], which outlives the screen,
     * so turning the phone (the screen is made again) never loses the answer. [inPersonSending] and
     * [inPersonError] are what the screen shows; a signature the server took is marked "_signed" in
     * [inPersonSigning], which is kept on the phone. "_sent" records that a Sign was sent, so the
     * hand-back checks with the server before withdrawing anything.
     */
    var inPersonSending by mutableStateOf(false)
        private set
    var inPersonError by mutableStateOf<String?>(null)
        private set

    fun signInPerson(typedName: String, png: String?, mismatch: String) {
        val d = inPersonSigning ?: return
        if (inPersonSending || d.optBoolean("_signed")) return
        val clientId = d.optInt("_client_id"); val docId = d.optInt("id")
        inPersonSending = true; inPersonError = null
        // The time it was signed here, sent with it if it has to wait for a signal.
        val signedAt = isoTime(System.currentTimeMillis())
        val sent = JSONObject(d.toString()).put("_sent", true)
        store.putNow(SIGNING_KEY, sent.toString()); inPersonSigning = sent
        scope.launch {
            try {
                val b = JSONObject().put("client_id", clientId).put("id", docId).put("typed_name", typedName)
                png?.let { b.put("signature_b64", it) }
                try {
                    call("sign_in_person", body = b)
                    if (inPersonSigning?.optInt("id") == docId) markInPersonSigned()
                    try { removePrepared(docId) } catch (e: Exception) { }
                } catch (e: ApiException) {
                    // No connection (or no answer in time): kept here, encrypted, and sent later.
                    // The name is checked here as the server checks it, so a kept signature is not refused for it.
                    if (!e.network) throw e
                    if (!typedNameMatches(d.optString("signer_name"), typedName)) throw ApiException(mismatch)
                    keepSignature(clientId, docId, d.optString("title"), typedName, png, signedAt)
                    if (inPersonSigning?.optInt("id") == docId) markInPersonSigned(offline = true)
                }
            } catch (e: ApiException) {
                inPersonError = e.message
            } catch (e: kotlinx.coroutines.CancellationException) {
                throw e
            } catch (e: Exception) {
                inPersonError = problemNotice(e)
            } finally {
                inPersonSending = false
            }
        }
    }

    /**
     * Leaving a document signed in person unsigned (4.27.0): it is withdrawn, unless the server says
     * it was signed after all (a Sign whose answer was lost). Returns what to tell staff.
     */
    suspend fun withdrawUnsigned(clientId: Int, docId: Int, title: String, checkFirst: Boolean): String {
        if (checkFirst) {
            try {
                val s = call("signature", mapOf("client_id" to clientId.toString(), "id" to docId.toString())).optJSONObject("signature")
                if (s?.str("signed_at") != null) return "$title was signed in person."
            } catch (e: ApiException) {
                return "$title could not be checked or withdrawn, so it is still waiting to be signed here. " +
                    "Check the client's Documents to sign, and withdraw it there if it is not needed."
            }
        }
        return try {
            call("withdraw_signature", body = JSONObject().put("client_id", clientId).put("id", docId))
            "$title was not signed, so the request was withdrawn."
        } catch (e: ApiException) {
            // 4.28.0: the server says when it was signed elsewhere meanwhile; only otherwise was it withdrawn earlier.
            if (e.status == 409 && e.reply?.optBoolean("signed") == true) "$title was signed, so it was not withdrawn."
            else if (e.status == 409) "$title was not signed; it had already been withdrawn."
            else "$title was not signed and could not be withdrawn, so it is still waiting to be signed here. " +
                "Withdraw it from the client's Documents to sign if it is not needed."
        }
    }

    /** [offline]: signed with no signal; the signature is kept on the phone and sent later. */
    fun markInPersonSigned(offline: Boolean = false) {
        val o = inPersonSigning?.let { JSONObject(it.toString()).put("_signed", true).put("_offline", offline) } ?: return
        store.putNow(SIGNING_KEY, o.toString())
        inPersonSigning = o
    }

    /* ---------- Signing with no signal (4.26.0) ---------- */

    /**
     * Documents started for signing in person and kept on this phone (encrypted, as everything in
     * [store]) so they can be signed in a client's home with no signal: this account's only.
     */
    var preparedSignatures by mutableStateOf(ownSigning(PREPARED_KEY))
        private set
    /** Signatures taken with no signal, waiting to be sent: this account's only. */
    var keptSignatures by mutableStateOf(ownSigning(KEPT_KEY))
        private set
    /** Why the server refused a kept signature, shown to staff until it is dealt with. */
    var signingNotice by mutableStateOf<String?>(null)

    private fun loadSigning(key: String): MutableList<JSONObject> {
        val raw = store.string(key)
        if (raw.isEmpty()) return mutableListOf()
        return try { JSONArray(raw).objects().toMutableList() } catch (e: Exception) { mutableListOf() }
    }

    private fun ownSigning(key: String): List<JSONObject> = if (userId <= 0) emptyList() else loadSigning(key).filter { it.optInt("owner") == userId }

    private suspend fun saveSigning(key: String, list: List<JSONObject>) {
        if (!store.putNowInBackground(key, JSONArray(list).toString())) throw ApiException(SAVE_FAILED)
        preparedSignatures = ownSigning(PREPARED_KEY)
        keptSignatures = ownSigning(KEPT_KEY)
    }

    private val signingLock = Mutex()

    /** Keeps a document started for signing in person, to sign later even with no signal. */
    suspend fun prepareToSign(clientId: Int, clientName: String, doc: SignatureDoc, startedAt: Long = signingStart(doc.sentAt, serverZone, System.currentTimeMillis())) = signingLock.withLock {
        if (!store.phoneCanKeepClientData) throw ApiException("This phone has no screen lock, so the app keeps nothing on it to sign later. Set a screen lock in Settings, or use Sign here now while you have a signal.")
        if (userId <= 0) throw ApiException("Could not verify the account. Reconnect, then prepare it again.")
        val list = loadSigning(PREPARED_KEY)
        list.removeAll { it.optInt("owner") == userId && it.optInt("id") == doc.id }
        list.add(doc.toJson().put("owner", userId).put("client_id", clientId).put("client_name", clientName).put("prepared_at", minOf(startedAt, System.currentTimeMillis())))
        saveSigning(PREPARED_KEY, list)
    }

    suspend fun removePrepared(id: Int) = signingLock.withLock {
        val list = loadSigning(PREPARED_KEY)
        if (list.removeAll { it.optInt("owner") == userId && it.optInt("id") == id }) saveSigning(PREPARED_KEY, list)
    }

    /** Drops this client's kept copies the server no longer has waiting to be signed here (withdrawn, or signed elsewhere). */
    suspend fun prunePrepared(clientId: Int, stillWaiting: Set<Int>) = signingLock.withLock {
        val list = loadSigning(PREPARED_KEY)
        if (list.removeAll { it.optInt("owner") == userId && it.optInt("client_id") == clientId && it.optInt("id") !in stillWaiting }) saveSigning(PREPARED_KEY, list)
    }

    /**
     * Keeps a signature taken with no signal: the typed name, the drawn signature if any, and when
     * it was signed (ISO 8601 with the offset). Sent by [flushSignatures]; removed only once the
     * server has it.
     */
    suspend fun keepSignature(clientId: Int, docId: Int, title: String, typedName: String, png: String?, signedAt: String) = signingLock.withLock {
        if (!store.phoneCanKeepClientData) throw ApiException("No signal, and this phone has no screen lock, so the signature cannot be kept on it to send later. Try again when there is a signal.")
        if (userId <= 0) throw ApiException("Could not verify the account, so the signature was not kept. Try again when there is a signal.")
        val kept = loadSigning(KEPT_KEY)
        kept.removeAll { it.optInt("owner") == userId && it.optInt("id") == docId }
        val item = JSONObject().put("owner", userId).put("client_id", clientId).put("id", docId).put("title", title)
            .put("typed_name", typedName).put("signed_at", signedAt).put("kept_at", System.currentTimeMillis())
        if (png != null) item.put("signature_b64", png)
        kept.add(item)
        saveSigning(KEPT_KEY, kept)
        val prepared = loadSigning(PREPARED_KEY)
        if (prepared.removeAll { it.optInt("owner") == userId && it.optInt("id") == docId }) saveSigning(PREPARED_KEY, prepared)
    }

    /** Removes a kept signature staff have dealt with (after the server refused it). */
    suspend fun discardKeptSignature(docId: Int) = signingLock.withLock {
        val kept = loadSigning(KEPT_KEY)
        if (kept.removeAll { it.optInt("owner") == userId && it.optInt("id") == docId }) saveSigning(KEPT_KEY, kept)
        if (keptSignatures.none { it.has("lastError") }) signingNotice = null
    }

    private val signFlushLock = Mutex()

    /**
     * Sends the signatures taken with no signal (on start, when the network returns, and from the
     * screen). Each stays on the phone until the server answers ok (repeat: it already had it); a
     * refusal is kept with the server's words so staff see it. [retryRefused]: also try again the
     * ones the server refused before (only when staff ask).
     */
    suspend fun flushSignatures(retryRefused: Boolean = false) {
        // A failure here (a full phone, 4.27.0) is said, rather than closing the app at every return to it.
        try { sendKeptSignatures(retryRefused) } catch (e: kotlinx.coroutines.CancellationException) { throw e } catch (e: Exception) { signingNotice = problemNotice(e) }
    }

    private suspend fun sendKeptSignatures(retryRefused: Boolean) = signFlushLock.withLock {
        if (userId <= 0 || token.isEmpty()) return@withLock
        val owner = userId
        val sessionToken = token
        val done = mutableSetOf<Int>()
        val refused = mutableMapOf<Int, String>()
        for (item in signingLock.withLock { loadSigning(KEPT_KEY) }) {
            if (token != sessionToken || userId != owner) break
            if (item.optInt("owner") != owner) continue
            if (item.has("lastError") && !retryRefused) continue
            val body = JSONObject().put("client_id", item.optInt("client_id")).put("id", item.optInt("id"))
                .put("typed_name", item.optString("typed_name")).put("signed_at", item.optString("signed_at"))
            item.str("signature_b64")?.let { body.put("signature_b64", it) }
            val (status, json) = try { raw("sign_in_person", emptyMap(), body, sessionToken) } catch (e: ApiException) { break }
            when {
                status == 401 -> { if (json.optBoolean("wipe")) wipeThisPhone() else expireSession(); break }
                status in 200..299 && json.optBoolean("ok") -> done.add(item.optInt("id"))
                status >= 500 || status == 429 -> break
                else -> refused[item.optInt("id")] = json.optString("error").ifEmpty { "The server did not accept it." }
            }
        }
        if (userId != owner || (done.isEmpty() && refused.isEmpty())) return@withLock
        signingLock.withLock {
            val kept = loadSigning(KEPT_KEY)
            kept.removeAll { it.optInt("owner") == owner && it.optInt("id") in done }
            for (k in kept) if (k.optInt("owner") == owner) refused[k.optInt("id")]?.let { k.put("lastError", it) }
            saveSigning(KEPT_KEY, kept)
        }
        val first = keptSignatures.firstOrNull { it.has("lastError") }
        signingNotice = first?.let { "The signature on “${it.optString("title")}” was not accepted: ${it.optString("lastError")} It is still kept on this phone, under the client's Documents to sign." }
    }

    /**
     * At sign-out (or another account signing in) only the prepared, unsigned documents go:
     * signatures already taken stay, tied to their account, and are sent when it signs in again,
     * as the offline queue is ([withKept]: a lost phone or another server, when they go too).
     */
    private fun clearSigning(withKept: Boolean) {
        store.remove(PREPARED_KEY)
        if (withKept) store.remove(KEPT_KEY)
        preparedSignatures = emptyList(); keptSignatures = if (withKept) emptyList() else ownSigning(KEPT_KEY); signingNotice = null
    }

    /** Signatures taken here and not yet sent, for this account: said before anything signs it out. */
    val unsentSignaturesWarning: String?
        get() {
            val n = keptSignatures.size
            if (n == 0) return null
            return "$n ${if (n == 1) "signature" else "signatures"} signed here with no signal ${if (n == 1) "has" else "have"} not been sent yet. " +
                "${if (n == 1) "It stays" else "They stay"} on this phone and ${if (n == 1) "is" else "are"} sent when you sign in again; " +
                "send ${if (n == 1) "it" else "them"} first from the client's Documents to sign if you can."
        }

    fun endInPerson() {
        inPersonSigning = null
        store.remove(SIGNING_KEY)
    }

    /**
     * Whether the server has the 4.25.0 actions (files with messages, sharing, documents to sign,
     * releases): its client detail then has "device_counts". Null until a client has been read.
     */
    var server425 by mutableStateOf(store.string("server425").let { if (it.isEmpty()) null else it == "1" })
        private set

    /** Notes what a client detail reply says about the server. */
    fun noteClientDetail(r: JSONObject) {
        val c = r.optJSONObject("client") ?: return
        val has = c.has("device_counts")
        if (server425 != has) { server425 = has; store.put("server425", if (has) "1" else "0") }
    }

    /** Reads one client's detail only to learn [server425], when it is not known yet. */
    suspend fun learn425(clientId: Int) {
        if (server425 != null || clientId <= 0) return
        try { noteClientDetail(call("client", mapOf("id" to clientId.toString()))) } catch (e: ApiException) { }
    }

    val isSignedIn: Boolean get() = token.isNotEmpty()

    /** Until the first answer, everything shows (the server enforces access either way). */
    fun can(perm: String) = !permsLoaded || perms.contains(perm)

    /** An AI feature this account may use (the server checks again). */
    fun aiHas(feature: String) = aiEnabled && aiFeatures.contains(feature)

    init {
        pendingSync = ownPending(loadQueue())
        org.casemanagement.app.ui.serverZoneId = serverZone
        // 4.27.0: kept lists older than two weeks, or beyond the 30 client records most recently
        // opened, go at start-up; so do temporary upload copies left by an app that was closed.
        scope.launch(Dispatchers.IO) {
            try { store.pruneCache(System.currentTimeMillis()) } catch (e: Exception) { }
            docsFolder.listFiles { f -> f.name.endsWith(".tmp") }?.forEach { it.delete() }
            // 4.28.0: opened documents are kept at most two weeks, as the kept lists are.
            val now = System.currentTimeMillis()
            docsFolder.listFiles { f -> f.isFile && docExpired(f.lastModified(), now) }?.forEach { it.delete() }
            // Where 4.26.0 and earlier made them.
            listOf("upload.tmp", "message.tmp").forEach { java.io.File(docsFolder.parentFile, it).delete() }
        }
        // A session that ended may since have been reported lost: asked about now (4.27.0).
        scope.launch { checkRefusedSession() }
    }

    /* ---------- The server ---------- */

    /** A build that names its server always uses it; the sign-in choice is only for builds without one. */
    val builtInServer: String? = BuildConfig.DEFAULT_SERVER.trim().ifEmpty { null }

    /**
     * The address typed on Sign in, used for signing in before it is kept (4.27.0): nothing of the
     * last server's is removed until signing in to the new one has worked.
     */
    private var trialServer by mutableStateOf<String?>(null)

    val base: String
        get() = (builtInServer ?: trialServer ?: store.string(SecureStore.SERVER_KEY).ifEmpty { null })
            ?.let { ServerAddress.apiUrl(it, BuildConfig.DEBUG) } ?: "https://example.invalid/api.php"

    /** Whether [address] is another server than the one kept (4.27.0: "https://", capitals and a trailing slash do not count). */
    fun isOtherServer(address: String): Boolean {
        val kept = storedServer
        return kept.isNotEmpty() && !ServerAddress.same(address, kept, BuildConfig.DEBUG)
    }

    /** Everything waiting to be sent from this phone, any account's: what signing in to another server removes. */
    val unsentOnPhone: Int get() = loadQueue().size + loadSigning(KEPT_KEY).size

    /** Signs in to [address] without keeping it yet (see [commitServer]). */
    fun tryServer(address: String) { trialServer = address.trim() }

    /** Forgets an address that did not sign in (a failed sign-in, or Start again), so nothing more goes there (4.28.0). */
    fun clearTrialServer() { trialServer = null }

    /**
     * Keeps the address signed in to. Another organisation's server: nothing of the last one's (its
     * account, its unsent entries, its kept lists) goes there, so they are removed now, after the
     * sign-in worked and after the Sign in screen warned about anything waiting to be sent.
     */
    fun commitServer(address: String) {
        trialServer = null
        val a = address.trim()
        if (!isOtherServer(a)) { if (a != storedServer) store.put(SecureStore.SERVER_KEY, a); return }
        userId = 0; userName = ""; store.remove("userId", "userName", "lastEmail")
        store.removePrefix("cache.")
        clearIdentity(keepQueue = false)
        server425 = null; store.remove("server425")
        store.put(SecureStore.SERVER_KEY, a)
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
            // 4.28.0: a redirect is never followed (it would drop the session and the body); see [MOVED].
            conn.instanceFollowRedirects = false
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
                if (status in 300..399) throw ApiException(MOVED, status, network = true)
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
        // Read before asking: signing out or a wipe meanwhile moves it on, and the reply is then not kept.
        val generation = store.cacheGeneration
        return try {
            val r = call(action, query)
            if (owner > 0 && owner == userId && store.phoneCanKeepClientData) {
                // Off the main thread, and only the 30 client records most recently opened (4.27.0).
                store.putCached(key, JSONObject().put("savedAt", System.currentTimeMillis()).put("data", r).toString(), generation)
            }
            r to null
        } catch (e: ApiException) {
            if (!e.network) { if (e.status == 403 || e.status == 404) store.remove(key); throw e }
            val saved = store.cached(key).takeIf { it.isNotEmpty() && owner > 0 && store.phoneCanKeepClientData } ?: throw e
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
        conn.instanceFollowRedirects = false
        var partial: java.io.File? = null
        try {
            conn.connectTimeout = 20_000; conn.readTimeout = 60_000
            conn.setRequestProperty("X-App-Version", APP_VERSION)
            conn.setRequestProperty("Authorization", "Bearer $sessionToken")
            val status = conn.responseCode
            if (status in 300..399) throw ApiException(MOVED, status, network = true)
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
            partial = file
            conn.inputStream.use { input -> file.outputStream().use { input.copyTo(it) } }
            // Signed out (or another account) while it downloaded: nothing of it stays on the phone.
            if (token != sessionToken) { file.delete(); throw ApiException("Your account changed, so the document was not kept.") }
            partial = null
            file
        } catch (e: IOException) {
            // 4.28.0: a download that stopped part-way leaves nothing behind.
            partial?.delete()
            throw ApiException(NO_CONNECTION, 0, network = true)
        } finally { conn.disconnect() }
    }

    /**
     * Uploads a document without holding it in memory: the file (already copied to [file], at most
     * 25 MB) is sent as base64 straight from disk inside the JSON body, with an exact length.
     */
    suspend fun uploadDocument(clientId: Int, category: String, title: String, filename: String, file: java.io.File, idem: String? = null) {
        val fields = JSONObject().put("client_id", clientId).put("category", category).put("title", title).put("filename", filename)
        // 4.28.0: a retry after a lost reply carries the same key, so the document is not added twice.
        if (idem != null) fields.put("idem_key", idem)
        postWithFile("upload_document", fields, "data_b64", file, "The document was not uploaded.")
    }

    /**
     * Sends [fields] with [file] as base64 under [fileKey], streamed from disk as uploadDocument
     * does (a message with a new file, 4.25.0). Returns the server's answer.
     */
    suspend fun postWithFile(action: String, fields: JSONObject, fileKey: String, file: java.io.File, failed: String): JSONObject = withContext(Dispatchers.IO) {
        val sessionToken = token
        val head = fields.toString().removeSuffix("}")
        val prefix = head + (if (fields.length() > 0) "," else "") + JSONObject.quote(fileKey) + ":\""
        val suffix = "\"}"
        val n = file.length()
        val b64Len = 4 * ((n + 2) / 3)
        val pre = prefix.toByteArray(Charsets.UTF_8); val suf = suffix.toByteArray(Charsets.UTF_8)
        val conn = (URL(url(action, emptyMap())).openConnection() as HttpURLConnection)
        conn.instanceFollowRedirects = false
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
            if (status in 300..399) throw ApiException(MOVED, status, network = true)
            val text = (if (status >= 400) conn.errorStream else conn.inputStream)?.bufferedReader()?.use { it.readText() } ?: ""
            val json = try { JSONObject(text) } catch (e: Exception) { JSONObject() }
            if (status == 401) withContext(Dispatchers.Main) { if (json.optBoolean("wipe")) wipeThisPhone() else expireSession() }
            if (status !in 200..299 || !json.optBoolean("ok")) throw ApiException(json.optString("error").ifEmpty { failed }, status, reply = json)
            json
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
        preparedSignatures = ownSigning(PREPARED_KEY); keptSignatures = ownSigning(KEPT_KEY)
        userName = name; store.put("userName", name)
        sessionNotice = null
        token = newToken; store.put("apiToken", newToken)
        store.remove(REFUSED_TOKEN_KEY, REFUSED_AT_KEY, REFUSED_SERVER_KEY)
    }

    /** Name, permissions and the organisation's lists; after sign-in and on each start. */
    suspend fun refreshMe() {
        val sessionToken = token
        val me = try { call("me") } catch (e: ApiException) { return }
        if (token != sessionToken || token.isEmpty()) return
        val m = parseMe(me)
        userName = m.name.ifEmpty { userName }; store.put("userName", userName)
        if (m.id > 0) { userId = m.id; store.put("userId", userId) }
        perms = m.perms; store.put("userPerms", perms.joinToString(","))
        scoped = m.scoped; store.put("userScoped", scoped)
        aiEnabled = m.aiEnabled
        aiFeatures = m.aiFeatures
        accessHold = m.accessHold
        serverVersion = m.serverVersion
        m.timezone?.takeIf { java.util.TimeZone.getAvailableIDs().contains(it) }?.let { serverZone = it; store.put("serverZone", it); org.casemanagement.app.ui.serverZoneId = it }
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
        val list = try { parseFormLists(JSONObject().put("lists", JSONObject(raw)))[name] ?: emptyList() } catch (e: Exception) { emptyList() }
        return list.ifEmpty { builtIn }
    }

    fun expireSession() {
        if (token.isEmpty()) return
        sessionNotice = "Your session ended, so sign in again. Your open screens and anything waiting to send are kept."
        if (userId > 0) reauthRequired = true
        // Kept (4.27.0), so a phone reported lost after its session ended still learns it and wipes.
        store.put(REFUSED_TOKEN_KEY, token); store.put(REFUSED_AT_KEY, System.currentTimeMillis().toString())
        // 4.28.0: and the server that refused it, the only one it is ever sent to again.
        store.put(REFUSED_SERVER_KEY, base)
        token = ""; store.remove("apiToken")
    }

    private val refusedCheck = Mutex()

    /**
     * Asks the server, with a session it refused, whether this phone was reported lost since
     * (4.27.0): on each start and while Sign in shows. Kept until signing in works, for at most 30 days.
     */
    suspend fun checkRefusedSession() = refusedCheck.withLock {
        val old = store.string(REFUSED_TOKEN_KEY)
        if (old.isEmpty()) return@withLock
        val at = store.string(REFUSED_AT_KEY).toLongOrNull() ?: 0L
        if (System.currentTimeMillis() - at > REFUSED_KEEP_MS) { store.remove(REFUSED_TOKEN_KEY, REFUSED_AT_KEY, REFUSED_SERVER_KEY); return@withLock }
        // 4.28.0: never to an address typed on Sign in (another server must not receive this session, nor wipe the phone).
        val kept = (builtInServer ?: storedServer.ifEmpty { null })?.let { ServerAddress.apiUrl(it, BuildConfig.DEBUG) }
        if (!refusedCheckAllowed(store.string(REFUSED_SERVER_KEY), kept, base)) return@withLock
        val (status, json) = try { raw("me", emptyMap(), null, old) } catch (e: Exception) { return@withLock }
        if (status == 401 && json.optBoolean("wipe") && store.string(REFUSED_TOKEN_KEY) == old) wipeThisPhone(old)
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
        endInPerson()
        clearSigning(withKept = !keepQueue)
        perms = emptyList(); permsLoaded = false; scoped = false; aiEnabled = false; aiFeatures = emptyList(); accessHold = emptyList(); serverVersion = null
        store.remove("userPerms", "userPermsLoaded", "userScoped", "formOptions")
        if (!keepQueue) store.remove(QUEUE_KEY)
        pendingSync = ownPending(loadQueue())
    }

    /** How many times this phone was wiped while the app ran (4.29.0): work begun before a wipe keeps nothing. */
    var wipes = 0
        private set

    /** [old]: the session the server answered "wipe" to (the current one, or one it refused earlier). */
    fun wipeThisPhone(old: String = token) {
        wipes++
        val cleared = store.wipeExceptServer()
        token = ""; userId = 0; userName = ""; reauthRequired = false; syncNotice = null
        clearIdentity(keepQueue = false)
        val docsGone = !docsFolder.exists()
        sessionNotice = "This phone was reported lost, so the app removed what it kept here."
        // The server keeps answering "wipe" until told it is done (4.27.0): told with that session, errors
        // aside, and only once everything is removed; otherwise it is asked again next time.
        if (old.isNotEmpty() && cleared && docsGone) scope.launch { try { raw("wipe_done", emptyMap(), JSONObject(), old) } catch (e: Exception) { } }
    }

    /* ---------- Offline entries (the iPhone app's queue, the same rules) ---------- */

    private val queueLock = Mutex()   // reading and writing the stored queue
    private val flushLock = Mutex()   // one upload at a time

    private fun loadQueue(): MutableList<JSONObject> {
        val raw = store.string(QUEUE_KEY)
        if (raw.isEmpty()) return mutableListOf()
        return try { JSONArray(raw).let { a -> MutableList(a.length()) { a.getJSONObject(it) } } } catch (e: Exception) { mutableListOf() }
    }

    private suspend fun saveQueue(q: List<JSONObject>) {
        if (!store.putNowInBackground(QUEUE_KEY, JSONArray(q).toString())) throw ApiException(SAVE_FAILED)
        pendingSync = ownPending(q)
    }

    private fun ownPending(q: List<JSONObject>) = q.count { it.optInt("owner") == userId && userId > 0 }

    fun ownQueued(): List<JSONObject> = loadQueue().filter { it.optInt("owner") == userId && userId > 0 }

    /**
     * Keeps an entry to send when there is a signal. Its idem_key goes with every try, so a reply
     * lost on the way can never save it twice.
     */
    suspend fun enqueue(action: String, body: JSONObject, label: String, owner: Int, forOwnerWhoLeft: Boolean = false) = queueLock.withLock {
        // [forOwnerWhoLeft] (4.29.0): the account changed while this was being sent; it is kept for the
        // account that wrote it (the queue is per account) and sent only when that account signs in.
        if (owner != userId && !forOwnerWhoLeft) throw ApiException("Your account changed, so this was not kept. Nothing was saved.")
        if (!store.phoneCanKeepClientData) {
            throw ApiException("This phone has no screen lock, so the app keeps nothing on it to send later. Save again when you have a signal, or set a screen lock in Settings.")
        }
        if (owner <= 0) throw ApiException("Could not verify the account for offline saving. Keep this form open and reconnect before saving.")
        val id = body.optString("idem_key").ifEmpty { UUID.randomUUID().toString() }
        body.put("idem_key", id)
        val q = loadQueue()
        if (q.none { it.optString("id") == id && it.optInt("owner") == owner }) {
            q.add(JSONObject().put("id", id).put("action", action).put("body", body.toString()).put("label", label)
                .put("owner", owner).put("savedAt", System.currentTimeMillis()))
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
    suspend fun flushQueue() {
        // A failure here (a full phone, 4.27.0) is said, rather than closing the app at every return to it.
        try { sendQueue() } catch (e: kotlinx.coroutines.CancellationException) { throw e } catch (e: Exception) { syncNotice = problemNotice(e) }
    }

    private suspend fun sendQueue() = flushLock.withLock {
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
            } catch (e: org.json.JSONException) {
                refused[item.optString("id")] = "It could not be read on this phone."; continue
            } catch (e: ApiException) {
                if (e.status in 300..399) transient = e.message
                break
            }
            when {
                status == 401 -> { if (json.optBoolean("wipe")) wipeThisPhone() else expireSession(); break }
                status in 200..299 && json.optBoolean("ok") -> done.add(item.optString("id"))
                // Already checked in (another phone, or an earlier try whose reply was lost).
                status == 409 && item.optString("action") == "check_in_session" -> done.add(item.optString("id"))
                // 4.29.0: an earlier try is still being processed: kept, and sent again later with the same key.
                status >= 500 || status == 429 || (status == 409 && json.optBoolean("processing")) -> {
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
        const val SIGNING_KEY = "inPersonSigning"
        const val PREPARED_KEY = "preparedSignatures"
        const val KEPT_KEY = "keptSignatures"
        const val NO_CONNECTION = "No connection to the server. Check your signal and try again."
        const val REFUSED_TOKEN_KEY = "refusedToken"
        const val REFUSED_AT_KEY = "refusedAt"
        const val REFUSED_SERVER_KEY = "refusedServer"
        /** A redirect, never followed (4.28.0): nothing was sent there, and anything waiting is kept. */
        const val MOVED = "The server answered from another address, so nothing was sent. The server address may have changed: " +
            "check it with your administrator. Anything waiting to send is kept on this phone."
        const val REFUSED_KEEP_MS = 30L * 24 * 60 * 60 * 1000
        const val SOMETHING_WRONG = "Something went wrong in the app. Your work is kept on this phone; it is tried again when you return to the app."
        const val SAVE_FAILED = "Could not save on this phone. Nothing was lost; try again."
        /** A background save failed (4.27.0), most often because the phone's storage is full. */
        const val STORAGE_PROBLEM = "The app could not save on this phone, so what is waiting to send stays where it was. " +
            "If the phone's storage is full, free some space; it is tried again when you return to the app."
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
