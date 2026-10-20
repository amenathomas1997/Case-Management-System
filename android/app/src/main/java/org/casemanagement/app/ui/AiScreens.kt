package org.casemanagement.app.ui

import android.content.Context
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
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
import org.casemanagement.app.objects
import org.casemanagement.app.str
import org.json.JSONObject
import java.util.UUID

/*
 * The AI tools, as the iPhone app has them. Every result is a draft that staff read before using;
 * nothing the AI writes is saved on its own. The AI can take up to a minute.
 */

private const val AI_WAIT = "This can take up to a minute."
/** What a busy AI button says, and what is said once when it is pressed (4.30.0). */
private const val AI_WORKING = "Working… $AI_WAIT"

/**
 * An AI result: its blocks (headings, list items), a note, and Copy. Read out once when it arrives.
 * [lang]: the language the result is written in (a translation), so TalkBack reads it with that
 * language's voice (4.30.0).
 */
@Composable
fun AiResult(title: String, text: String?, footer: String? = null, lang: String? = null) {
    if (text.isNullOrBlank()) return
    val context = LocalContext.current
    var copied by remember(text) { mutableStateOf(false) }
    Heading(title)
    Announce("Result ready.", key = text)
    MarkdownBlocks(text, lang)
    footer?.let { Text(it) }
    SecondaryButton("Copy the result") { copyText(context, text); copied = true }
    if (copied) NoticeText("Result copied.")
}

fun copyText(context: Context, text: String) {
    val clip = android.content.ClipData.newPlainText("Result", text)
    if (android.os.Build.VERSION.SDK_INT >= 33) clip.description.extras = android.os.PersistableBundle().apply { putBoolean("android.content.extra.IS_SENSITIVE", true) }
    (context.getSystemService(Context.CLIPBOARD_SERVICE) as android.content.ClipboardManager).setPrimaryClip(clip)
}

/** The general AI tools: plain language, translation, curriculum, ask about the caseload or knowledge base, risk review. */
@Composable
fun AiToolsScreen(api: Api) {
    val tools = buildList {
        if (api.aiHas("remediate")) add("remediate" to "Plain language, simplify, summarize or alt text")
        if (api.aiHas("translate")) add("translate" to "Translate")
        if (api.aiHas("ask") && api.can("clients")) add("ask" to "Ask about your caseload")
        if (api.aiHas("kb")) add("kb" to "Ask the knowledge base")
        if (api.aiHas("curriculum") && api.can("templates")) add("curriculum" to "Draft a curriculum")
        if (api.aiHas("risk") && api.can("analytics")) add("risk" to "Review clients at risk")
    }
    var tool by rememberSaveable { mutableStateOf(tools.firstOrNull()?.first ?: "") }
    var input by rememberSaveable { mutableStateOf("") }
    var mode by rememberSaveable { mutableStateOf("plain") }
    var lang by rememberSaveable { mutableStateOf("es") }
    var level by rememberSaveable { mutableStateOf("Beginner") }
    var focus by rememberSaveable { mutableStateOf("") }
    // Kept as text, so a turned phone does not lose the result.
    var resultText by rememberSaveable { mutableStateOf<String?>(null) }
    val result = resultText?.let { JSONObject(it) }
    var error by remember { mutableStateOf<String?>(null) }
    var busy by remember { mutableStateOf(false) }
    val scope = rememberCoroutineScope()
    Page {
        if (!api.aiEnabled) { Text("The AI assistant is not configured. An administrator can set it up on the website."); return@Page }
        if (tools.isEmpty()) { Text("No general AI tools are switched on for your account."); return@Page }
        Choice("Tool", tool, tools) { tool = it; resultText = null; error = null }
        when (tool) {
            "remediate" -> {
                Choice("Make it", mode, listOf("plain" to "Plain language", "simplify" to "Simplify", "summary" to "Summarize", "largeprint" to "Large print format", "alt" to "Alt text")) { mode = it; resultText = null }
                Field("Your text", input, { input = it; resultText = null }, hint = "sent to the AI provider as it is", singleLine = false, minLines = 5, required = true)
            }
            "translate" -> {
                Choice("Into", lang, listOf("es" to "Spanish", "ar" to "Arabic", "zh-Hans" to "Chinese (Simplified)", "vi" to "Vietnamese", "ru" to "Russian", "fr" to "French", "ht" to "Haitian Creole", "so" to "Somali", "ne" to "Nepali", "ko" to "Korean", "tl" to "Tagalog")) { lang = it; resultText = null }
                Field("Text to translate", input, { input = it; resultText = null }, hint = "up to 6,000 characters", singleLine = false, minLines = 5, required = true)
            }
            "ask" -> Field("Your question", input, { input = it; resultText = null }, hint = "client names are never sent to the AI", singleLine = false, required = true)
            "kb" -> Field("Your question", input, { input = it; resultText = null }, singleLine = false, required = true)
            "curriculum" -> {
                Field("Technology", input, { input = it; resultText = null }, hint = "such as JAWS", required = true)
                Choice("Level", level, listOf("Beginner", "Intermediate", "Advanced").map { it to it }) { level = it; resultText = null }
                Field("Focus", focus, { focus = it; resultText = null }, hint = "optional", singleLine = false)
            }
            "risk" -> Text("Reviews your active clients who have gone longest without a session.")
        }
        ErrorText(error)
        val needed = when (tool) { "risk" -> null; "translate" -> "Text to translate"; "curriculum" -> "Technology"; "remediate" -> "Your text"; else -> "Your question" }
        PrimaryButton("Ask the AI", busy = busy, busyText = AI_WORKING, announceBusy = true, missing = if (needed != null && input.isBlank()) stillNeeded(listOf(needed)) else null) {
            if (tool == "translate" && input.length > 6000) { error = "The content is too long to translate at once (maximum 6,000 characters; it has ${input.length}). Split it into parts and translate each one."; return@PrimaryButton }
            error = null; resultText = null
            scope.launch {
                busy = true
                try {
                    resultText = when (tool) {
                        "remediate" -> api.call("ai_remediate", body = JSONObject().put("mode", mode).put("content", input))
                        "translate" -> api.call("ai_translate", body = JSONObject().put("lang", lang).put("content", input))
                        "ask" -> api.call("ai_ask", body = JSONObject().put("question", input.trim()))
                        "kb" -> api.call("ai_kb", body = JSONObject().put("question", input.trim()))
                        "curriculum" -> api.call("ai_curriculum", body = JSONObject().put("tech", input.trim()).put("level", level).put("focus", focus.trim()))
                        else -> api.call("ai_risk", body = JSONObject())
                    }.toString()
                } catch (e: ApiException) { error = e.message } finally { busy = false }
            }
        }
        result?.let { r ->
            val footer = when (tool) {
                "remediate" -> if (r.optBoolean("truncated")) "Incomplete: the result stops before the end of your text; split it and try again." else null
                "kb" -> r.optJSONArray("sources").objects().takeIf { it.isNotEmpty() }?.joinToString(", ", prefix = "Grounded in: ") { it.optString("title") }
                "curriculum" -> "Create the full curriculum on the website's Templates page."
                "risk" -> if (r.optInt("assessed") < r.optInt("total_active")) "${r.optInt("assessed")} of ${r.optInt("total_active")} active clients assessed: only those with no session yet or the longest time since their last session fit one review." else null
                "ask" -> "Read-only; check before acting."
                else -> null
            }
            // A translation is read in the language it was put into (owner rule 5).
            AiResult("Result", r.optString("text"), footer, lang = if (tool == "translate") lang else null)
            Text("Staff review every AI draft.")
        }
    }
}

/**
 * AI help for one client: device recommendations, a progress summary, an intake assessment draft
 * (reviewed and saved by staff) and a case summary (reviewed, then saved as a case note).
 */
@Composable
fun ClientAiScreen(api: Api, nav: NavHostController, clientId: Int) {
    val tools = buildList {
        if (api.aiHas("recommend")) add("recommend" to "Device recommendations")
        if (api.aiHas("progress")) add("progress" to "Progress summary")
        if (api.aiHas("intake")) add("intake" to "Draft an intake assessment")
        if (api.aiHas("draft")) add("case" to "Case summary to save as a note")
    }
    var tool by rememberSaveable { mutableStateOf(tools.firstOrNull()?.first ?: "") }
    var goals by rememberSaveable { mutableStateOf("") }
    var limits by rememberSaveable { mutableStateOf("") }
    var tried by rememberSaveable { mutableStateOf("") }
    var resultText by rememberSaveable { mutableStateOf<String?>(null) }
    val result = resultText?.let { JSONObject(it) }
    var draft by rememberSaveable { mutableStateOf("") }
    // Saved as JSON text, so a turned phone keeps the edited draft.
    var fields by rememberSaveable(stateSaver = androidx.compose.runtime.saveable.Saver<Map<String, String>, String>(
        save = { JSONObject(it).toString() },
        restore = { t -> JSONObject(t).let { f -> f.keys().asSequence().associateWith { f.optString(it) } } },
    )) { mutableStateOf(emptyMap()) }
    // 4.29.0: kept across rotation, so a saved assessment never offers Save again (a second record).
    var saved by rememberSaveable { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    var busy by remember { mutableStateOf(false) }
    var asking by remember { mutableStateOf(false) }
    val idem = rememberSaveable { UUID.randomUUID().toString() }
    // 4.28.0: the assessment's own retry key (see sendOnce).
    var assessKey by rememberSaveable { mutableStateOf("") }
    val scope = rememberCoroutineScope()
    HoldBackWhileSaving(busy)
    Page {
        if (tools.isEmpty()) { Text("No AI help for clients is switched on for your account."); return@Page }
        Choice("AI help", tool, tools) { tool = it; resultText = null; error = null; saved = false }
        if (tool == "intake") {
            Field("Goals", goals, { goals = it }, hint = "what the person wants to do", singleLine = false)
            Field("Limitations", limits, { limits = it }, hint = "optional", singleLine = false)
            Field("What they have tried", tried, { tried = it }, hint = "optional", singleLine = false)
        }
        ErrorText(error)
        // 4.29.0: asking has its own busy state, so Back is never held while the AI works.
        PrimaryButton("Ask the AI", busy = asking || busy, busyText = if (asking) AI_WORKING else "Please wait…", announceBusy = asking,
            missing = if (tool == "intake" && listOf(goals, limits, tried).all { it.isBlank() }) "Still needed: Goals, Limitations or What they have tried." else null) {
            if (asking) return@PrimaryButton
            error = null; resultText = null; saved = false
            scope.launch {
                asking = true
                try {
                    val r = when (tool) {
                        "recommend" -> api.call("ai_recommend", body = JSONObject().put("client_id", clientId))
                        "progress" -> api.call("ai_progress", body = JSONObject().put("client_id", clientId))
                        "intake" -> api.call("ai_intake", body = JSONObject().put("client_id", clientId).put("goals", goals.trim()).put("limitations", limits.trim()).put("tried", tried.trim()).put("context", ""))
                        else -> api.call("ai_draft", body = JSONObject().put("type", "case").put("client_id", clientId))
                    }
                    resultText = r.toString()
                    draft = r.optString("text")
                    fields = r.optJSONObject("fields")?.let { f -> f.keys().asSequence().associateWith { f.optString(it) } } ?: emptyMap()
                } catch (e: ApiException) { error = e.message } finally { asking = false }
            }
        }
        val r = result ?: return@Page
        when (tool) {
            "recommend" -> AiResult("Recommendations", r.optString("text"), "Create a device request to pursue a recommendation.")
            "progress" -> AiResult("Progress summary", r.optString("text"))
            "intake" -> {
                if (fields["summary"].isNullOrBlank()) { ErrorText("The assistant's reply could not be read. Try again, or add the assessment yourself."); return@Page }
                Heading("Draft assessment: review each field, then save")
                Announce("Result ready.", key = resultText)
                val techs = api.formList("tech_types", listOf("Screen Reader", "Magnification", "Braille Display", "Speech-to-Text", "Independent Living Skills", "Braille Literacy", "Low Vision Devices", "Rehabilitation Technology", "Other"))
                Field("Primary disability", fields["primary_disability"] ?: "", { fields = fields + ("primary_disability" to it) })
                Choice("Primary technology", fields["primary_tech"] ?: "Other", techs.map { it to it }) { fields = fields + ("primary_tech" to it) }
                Field("Hardware", fields["hardware_details"] ?: "", { fields = fields + ("hardware_details" to it) })
                Field("Software and version", fields["software_version"] ?: "", { fields = fields + ("software_version" to it) })
                Field("Summary", fields["summary"] ?: "", { fields = fields + ("summary" to it) }, singleLine = false, minLines = 6)
                r.str("instruments")?.let { Text("Suggested instruments: $it") }
                if (saved) NoticeText("Assessment saved.") else PrimaryButton("Save the assessment", busy = busy) {
                    scope.launchSave({ busy = it }, { error = it }) {
                        val b = JSONObject().put("client_id", clientId).put("assessment_date", today())
                        fields.forEach { (k, v) -> b.put(k, v.trim()) }
                        api.callOnce("add_assessment", b, assessKey, { assessKey = it }, "the assessment"); saved = true
                    }
                }
            }
            else -> {
                Heading("Case summary: review and edit before saving")
                Announce("Result ready. The AI never saves on its own.", key = resultText)
                if (r.optBoolean("truncated")) ErrorText("This summary is incomplete: the AI stopped before the end. Review it closely and add what is missing, or draft it again.")
                Field("Summary", draft, { draft = it }, singleLine = false, minLines = 8, required = true)
                PrimaryButton("Save as a case note", busy = busy, missing = if (draft.isBlank()) stillNeeded(listOf("Summary")) else null) {
                    scope.launchSave({ busy = it }, { error = it }) {
                        val s = saveOrQueue(api, "add_note", JSONObject().put("client_id", clientId).put("note_type", "Case Note").put("note_format", "free")
                            .put("subject", "Case summary (AI draft, reviewed)").put("body", draft).put("idem_key", idem), "Case note: case summary", "Case summary saved as a note.")
                        Flash.pending = s.message
                        nav.popBackStack()
                    }
                }
            }
        }
    }
}
