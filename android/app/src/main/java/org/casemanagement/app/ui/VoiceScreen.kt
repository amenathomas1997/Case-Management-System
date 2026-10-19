package org.casemanagement.app.ui

import android.app.Activity
import android.content.Context
import android.content.Intent
import android.speech.RecognizerIntent
import android.speech.tts.TextToSpeech
import android.view.accessibility.AccessibilityManager
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.semantics.LiveRegionMode
import androidx.compose.ui.semantics.liveRegion
import androidx.compose.ui.semantics.semantics
import androidx.navigation.NavHostController
import kotlinx.coroutines.launch
import org.casemanagement.app.Api
import org.casemanagement.app.ApiException
import org.json.JSONArray
import org.json.JSONObject
import java.util.Locale
import java.util.UUID
import org.casemanagement.app.stillProcessing

private val NO_WORDS = setOf("no", "nope", "not", "dont", "cancel", "stop", "never", "nevermind", "wrong", "incorrect")
private val YES_WORDS = setOf("yes", "yeah", "yep", "ok", "okay", "sure", "confirm", "correct")
private val FILLER = setOf("please", "thanks", "thank", "you", "go", "ahead", "do", "it", "save", "that", "thats", "is", "right", "fine", "i", "im")

/** "yes", "no" or null for an answer to a confirmation, by the iPhone app's rules (no is checked first). */
fun yesOrNo(said: String): String? {
    val t = said.lowercase(Locale.US).replace("’", "'")
    // Numbers count as words, so "yes, 3" is unclear rather than a yes to the original amount.
    val words = Regex("[\\p{L}\\p{N}']+").findAll(t).map { it.value.replace("'", "") }.toList()
    if (Regex("[a-z]+n't\\b").containsMatchIn(t) || words.any { it in NO_WORDS }) return "no"
    if (words.isEmpty() || words.size > 4 || t.contains('?')) return null
    if (!words.all { it in YES_WORDS || it in FILLER }) return null
    return if (words.any { it in YES_WORDS } || t.contains("go ahead") || t.contains("do it") || t.contains("save it")) "yes" else null
}

/** The assistant's section names, as the app's screens. */
private val SECTION_ROUTES = mapOf(
    "dashboard" to "home", "home" to "home", "clients" to "clients", "schedule" to "schedule", "tasks" to "tasks", "referrals" to "referrals",
    "messages" to "messages", "equipment" to "equipment", "followups" to "followups", "follow-ups" to "followups", "device follow-ups" to "followups",
    "bookings" to "bookings", "procurement" to "procurement", "programs" to "programs", "accommodations" to "accommodations",
    "client requests" to "portalRequests", "portal requests" to "portalRequests", "contracts" to "contracts", "utilization" to "utilization",
    "knowledge base" to "kb", "kb" to "kb", "pipeline" to "pipeline", "compliance" to "compliance", "incidents" to "incidents",
    "invoices" to "invoices", "waitlist" to "waitlist", "alerts" to "alerts", "search" to "search",
)

private data class Turn(val role: String, val text: String)

/*
 * 4.28.0: the conversation, the question waiting for a yes, and a lost reply's key are kept across
 * rotation, a text-size change and process death, as plain text.
 */
private val turnsSaver = androidx.compose.runtime.saveable.Saver<List<Turn>, String>(
    save = { l -> org.json.JSONArray(l.map { JSONObject().put("role", it.role).put("text", it.text) }).toString() },
    restore = { t -> try { org.json.JSONArray(t).let { a -> List(a.length()) { a.getJSONObject(it).let { o -> Turn(o.optString("role"), o.optString("text")) } } } } catch (e: Exception) { emptyList() } },
)
private val pendingSaver = androidx.compose.runtime.saveable.Saver<Pair<String, JSONObject>?, String>(
    save = { p -> p?.let { JSONObject().put("op", it.first).put("params", it.second).toString() } },
    restore = { t -> try { JSONObject(t).let { o -> o.optString("op") to (o.optJSONObject("params") ?: JSONObject()) } } catch (e: Exception) { null } },
)
private val uncertainSaver = androidx.compose.runtime.saveable.Saver<Triple<String, JSONObject, String>?, String>(
    save = { u -> u?.let { JSONObject().put("op", it.first).put("params", it.second).put("idem", it.third).toString() } },
    restore = { t -> try { JSONObject(t).let { o -> Triple(o.optString("op"), o.optJSONObject("params") ?: JSONObject(), o.optString("idem")) } } catch (e: Exception) { null } },
)

/**
 * The voice assistant, as in the iPhone app: say or type what you want ("log 2 hours for Jordan"),
 * it says what it will do and saves only after you say yes. Replies are read by TalkBack when it is
 * on, and spoken aloud otherwise.
 */
@Composable
fun VoiceScreen(api: Api, nav: NavHostController, clientId: Int, clientName: String) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var turns by rememberSaveable(stateSaver = turnsSaver) { mutableStateOf(emptyList()) }
    var input by rememberSaveable { mutableStateOf("") }
    var busy by remember { mutableStateOf(false) }
    var pending by rememberSaveable(stateSaver = pendingSaver) { mutableStateOf(null) }
    var unclear by remember { mutableStateOf(0) }
    var link by remember { mutableStateOf<Pair<Int, String>?>(null) }
    var screen by remember { mutableStateOf<Pair<String, String>?>(null) }
    var retry by remember { mutableStateOf<Triple<String, JSONObject, String>?>(null) }
    // A write whose reply was lost: if the same request comes back, its key is used again, so it is never saved twice.
    var uncertain by rememberSaveable(stateSaver = uncertainSaver) { mutableStateOf(null) }
    val talkBack = remember { (context.getSystemService(Context.ACCESSIBILITY_SERVICE) as AccessibilityManager).isTouchExplorationEnabled }
    val tts = remember { mutableStateOf<TextToSpeech?>(null) }
    DisposableEffect(Unit) {
        if (!talkBack) tts.value = TextToSpeech(context) { }
        onDispose { tts.value?.shutdown() }
    }
    fun say(text: String) {
        turns = turns + Turn("assistant", text)
        if (!talkBack) tts.value?.speak(text, TextToSpeech.QUEUE_FLUSH, null, "reply")
    }

    suspend fun execute(op: String, params: JSONObject, idem: String?) {
        val body = JSONObject(params.toString()).put("do", op)
        idem?.let { body.put("idem", it) }
        try {
            val r = api.call("voice_command", body = body)
            say(r.optString("speak").ifEmpty { "Done." })
            retry = null; uncertain = null
            if (r.optString("url").startsWith("client.php")) Regex("id=(\\d+)").find(r.optString("url"))?.let { link = it.groupValues[1].toInt() to r.optString("url_label").ifEmpty { "Open the record" } }
        } catch (e: ApiException) {
            // 4.29.0: "still being processed" is not an answer either: the retry keeps its key.
            val lost = e.network || e.status >= 500 || stillProcessing(e)
            if (!lost) { say(e.message ?: "That was not saved."); retry = null; return }
            if (idem != null) {
                retry = Triple(op, params, idem); uncertain = Triple(op, params, idem)
                say(if (op == "log_hours") "I couldn't confirm the hours were logged because the reply was lost. Say yes to try again; they won't be logged twice. Or say no to stop."
                    else "I couldn't confirm the session was completed because the reply was lost. Say yes to try again; it won't be done twice. Or say no to stop.")
            } else say("I couldn't confirm that was saved because the reply was lost. Check before trying again.")
        }
    }

    fun send(textIn: String) {
        val text = textIn.trim()
        if (text.isEmpty() || busy) return
        input = ""
        turns = turns + Turn("user", text)
        scope.launch {
            busy = true
            try {
                // Answering a lost reply's retry offer.
                retry?.let { (op, params, idem) ->
                    when (yesOrNo(text)) {
                        "yes" -> { execute(op, params, idem); return@launch }
                        "no" -> { retry = null; say("Not retried. It may already have been saved, so check before trying again."); return@launch }
                        else -> { say("Please say yes to try again, or no to stop."); return@launch }
                    }
                }
                // Answering a confirmation.
                pending?.let { (op, params) ->
                    when (yesOrNo(text)) {
                        "yes" -> {
                            pending = null; unclear = 0
                            val idem = if (op == "log_hours" || op == "complete_session") {
                                uncertain?.takeIf { it.first == op && it.second.toString() == params.toString() }?.third ?: UUID.randomUUID().toString().lowercase()
                            } else null
                            execute(op, params, idem); return@launch
                        }
                        "no" -> { pending = null; unclear = 0; say("Okay, cancelled. Nothing was saved."); return@launch }
                        else -> { unclear++; say(if (unclear == 1) "Please say yes to save, or no to cancel." else "Use the Yes, save or No buttons."); return@launch }
                    }
                }
                val history = JSONArray()
                if (clientId > 0) history.put(JSONObject().put("role", "user").put("text", "I'm looking at $clientName's record."))
                turns.dropLast(1).takeLast(8).forEach { history.put(JSONObject().put("role", it.role).put("text", it.text)) }
                val r = api.call("voice_command", body = JSONObject().put("transcript", text).put("history", history))
                link = null; screen = null
                when (r.optString("action")) {
                    "confirm" -> { pending = r.optString("do") to (r.optJSONObject("params") ?: JSONObject()); unclear = 0; say(r.optString("speak")) }
                    "navigate" -> {
                        val cid = r.optInt("client_id")
                        val route = SECTION_ROUTES[r.optString("section").lowercase()]
                        when {
                            cid > 0 -> { link = cid to "Open ${r.optString("client_name").ifEmpty { "the client" }}'s record"; say(r.optString("speak").replace(Regex("^Opening[^.]*\\.?\\s*"), "").ifEmpty { "Here is the record." }) }
                            route != null -> { screen = route to r.optString("section"); say(r.optString("speak").replace(Regex("^Opening[^.]*\\.?\\s*"), "").ifEmpty { "It's ready to open." }) }
                            r.optString("query").isNotEmpty() -> { screen = "search" to "search"; say("Use Search to look for " + r.optString("query") + ".") }
                            else -> say("I can't open that from the assistant: that page is on the website.")
                        }
                    }
                    else -> {
                        say(r.optString("speak").ifEmpty { "Sorry, I didn't understand." })
                        if (r.optInt("client_id") > 0) link = r.optInt("client_id") to "Open ${r.optString("client_name").ifEmpty { "the client" }}'s record"
                    }
                }
            } catch (e: ApiException) { say(e.message ?: "Something went wrong.") } finally { busy = false }
        }
    }

    val listen = rememberLauncherForActivityResult(ActivityResultContracts.StartActivityForResult()) { res ->
        if (res.resultCode == Activity.RESULT_OK) res.data?.getStringArrayListExtra(RecognizerIntent.EXTRA_RESULTS)?.firstOrNull()?.let { send(it) }
    }

    Page {
        if (clientId > 0) Text("About $clientName.")
        turns.forEach { t -> ListRow(listOf((if (t.role == "user") "You: " else "Assistant: ") + t.text)) }
        // Each reply is read out once by TalkBack (and spoken aloud when TalkBack is off).
        Announce(turns.lastOrNull()?.takeIf { it.role == "assistant" }?.text, key = turns.size)
        if (busy) Text("Working…")
        if (pending != null || retry != null) {
            PrimaryButton(if (retry != null) "Yes, try again" else "Yes, save") { send("yes") }
            SecondaryButton(if (retry != null) "No, stop" else "No, cancel") { send("no") }
        }
        link?.let { (cid, label) -> SecondaryButton(label) { nav.navigate("client/$cid") } }
        screen?.let { (route, name) -> SecondaryButton("Open " + name.replaceFirstChar { it.uppercase() }) { nav.navigate(route) } }
        Field("Say or type a request", input, { input = it }, singleLine = false)
        PrimaryButton("Send", busy = busy, enabled = input.isNotBlank()) { send(input) }
        SecondaryButton("Speak") {
            val i = Intent(RecognizerIntent.ACTION_RECOGNIZE_SPEECH).putExtra(RecognizerIntent.EXTRA_LANGUAGE_MODEL, RecognizerIntent.LANGUAGE_MODEL_FREE_FORM)
                .putExtra(RecognizerIntent.EXTRA_PROMPT, "Say what you want to do")
            try { listen.launch(i) } catch (e: android.content.ActivityNotFoundException) { say("This phone has no speech recognition. Type your request instead.") }
        }
        Text("Nothing is saved until you say yes.")
    }
}
