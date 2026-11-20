package org.casemanagement.app.ui

import android.content.Context
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
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
    if (copied) NoticeText(COPIED_NOTE)
}

/**
 * Copies client text as the iPhone's copyProtected does (4.37.0): marked sensitive (Android 13 and
 * later, so the keyboard's preview does not show it), and cleared from the clipboard after 10
 * minutes and at sign-out, if the clipboard still holds this copy.
 */
fun copyText(context: Context, text: String) {
    val clip = android.content.ClipData.newPlainText("Result", text)
    if (android.os.Build.VERSION.SDK_INT >= 33) clip.description.extras = android.os.PersistableBundle().apply { putBoolean("android.content.extra.IS_SENSITIVE", true) }
    (context.getSystemService(Context.CLIPBOARD_SERVICE) as android.content.ClipboardManager).setPrimaryClip(clip)
    ClipGuard.copied(context.applicationContext, text)
}

/** What the app put on the clipboard, cleared after [CLEAR_AFTER_MS] and at sign-out while it is still there. */
object ClipGuard {
    const val CLEAR_AFTER_MS = 10 * 60 * 1000L
    private var app: Context? = null
    private var last: String? = null
    private val handler by lazy { android.os.Handler(android.os.Looper.getMainLooper()) }
    private val clearer = Runnable { clear() }

    fun copied(context: Context, text: String) {
        app = context; last = text
        handler.removeCallbacks(clearer); handler.postDelayed(clearer, CLEAR_AFTER_MS)
    }

    /** Empties the clipboard when it still holds the app's last copy. */
    fun clear() {
        val c = app ?: return
        val text = last ?: return
        try {
            val cm = c.getSystemService(Context.CLIPBOARD_SERVICE) as android.content.ClipboardManager
            val now = cm.primaryClip?.takeIf { it.itemCount > 0 }?.getItemAt(0)?.text?.toString()
            if (now == text) {
                if (android.os.Build.VERSION.SDK_INT >= 28) cm.clearPrimaryClip() else cm.setPrimaryClip(android.content.ClipData.newPlainText("", ""))
            }
        } catch (e: Exception) { /* the clipboard is not readable in the background: left to the 10 minutes */ }
        last = null
    }
}

/** Said after a copy, as the iPhone says it. */
const val COPIED_NOTE = "Copied. It is cleared from the clipboard in 10 minutes."

/** 4.34.0: the languages offered for translation: the code (for TalkBack's voice) and the name sent to the server. */
val TRANSLATE_LANGS = listOf("es" to "Spanish", "ar" to "Arabic", "zh-Hans" to "Chinese (Simplified)", "vi" to "Vietnamese", "ru" to "Russian",
    "fr" to "French", "ht" to "Haitian Creole", "so" to "Somali", "ne" to "Nepali", "ko" to "Korean", "tl" to "Tagalog")

/** 4.34.0: the language name the server's prompt needs ("Somali", not "so"). */
fun translateLangName(code: String): String = TRANSLATE_LANGS.firstOrNull { it.first == code }?.second ?: code

/** 4.34.0: characters as people count them (an emoji or a CJK supplement letter is one), as the server counts them. */
fun codePointCount(s: String): Int = s.codePointCount(0, s.length)

/**
 * 4.34.0: the notices that go with an AI result, as the website says them: an answer the AI cut
 * off, a caseload that did not fit, the period a risk review covers.
 */
fun aiNotices(tool: String, r: JSONObject): List<String> = listOfNotNull(
    if (tool == "risk" && r.has("period_from")) "Period: ${r.optString("period_from")} to ${r.optString("period_to")}." else null,
    if (r.optBoolean("truncated")) when (tool) {
        "ask" -> "Incomplete: the AI stopped before finishing this answer, so clients after the last line are missing. Narrow the question and ask again."
        "risk" -> "Incomplete: the AI stopped before finishing this list, so the clients after the last item were not assessed."
        "translate" -> "Incomplete: the translation stops before the end of your text; split it and translate each part."
        "curriculum" -> "Incomplete: the AI stopped before finishing the lesson plan. Narrow the focus and draft it again."
        "progress" -> progressNotice()
        else -> "Incomplete: the result stops before the end of your text; split it and try again."
    } else null,
    if (tool == "ask" && r.optBoolean("caseload_cut")) "Note: only part of the caseload fit in one question — narrow the question for larger caseloads." else null,
)

/** 4.34.0: what staff are told under every AI result, with whether identifiers are stripped (null: an older server). */
fun aiReviewWords(redaction: Boolean?): String = when (redaction) {
    true -> "Staff review every AI draft before it is used. Client identifiers are stripped before client data is sent to the provider."
    false -> "Staff review every AI draft before it is used. Identifier redaction is currently off."
    null -> "Staff review every AI draft."
}

/** The general AI tools: plain language, translation, curriculum, ask about the caseload or knowledge base, risk review. */
@Composable
fun AiToolsScreen(api: Api, initialTool: String = "") {
    val tools = buildList {
        if (api.aiHas("remediate")) add("remediate" to "Plain language, simplify, summarize or alt text")
        if (api.aiHas("translate")) add("translate" to "Translate")
        if (api.aiHas("ask") && api.can("clients")) add("ask" to "Ask about your caseload")
        if (api.aiHas("kb")) add("kb" to "Ask the knowledge base")
        if (api.aiHas("curriculum") && api.can("templates")) add("curriculum" to "Draft a curriculum")
        if (api.aiHas("risk") && api.can("analytics")) add("risk" to "Review clients at risk")
    }
    var tool by rememberSaveable { mutableStateOf(tools.firstOrNull { it.first == initialTool }?.first ?: tools.firstOrNull()?.first ?: "") }
    var input by rememberSaveable { mutableStateOf("") }
    var mode by rememberSaveable { mutableStateOf("plain") }
    var lang by rememberSaveable { mutableStateOf("es") }
    var level by rememberSaveable { mutableStateOf("Beginner") }
    var focus by rememberSaveable { mutableStateOf("") }
    // 4.37.0: the general-knowledge answer (S23), the typed language (S24), and errors tied to their fields.
    var general by rememberSaveable { mutableStateOf(false) }
    var otherLang by rememberSaveable { mutableStateOf("") }
    var errors by remember { mutableStateOf<Map<String, String>>(emptyMap()) }
    var steps by remember { mutableStateOf<List<String>>(emptyList()) }
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
                Choice("Make it", mode, REMEDIATE_MODES) { mode = it; resultText = null }
                Field("Content", input, { input = it; resultText = null }, hint = "sent to the AI provider as it is", singleLine = false, minLines = 5, required = true, error = errors["content"])
            }
            "translate" -> {
                Choice("Into", lang, TRANSLATE_LANGS + (TRANSLATE_OTHER to "Other (type below)")) { lang = it; resultText = null }
                if (lang == TRANSLATE_OTHER) Field("Other language", otherLang, { otherLang = it; resultText = null }, hint = OTHER_LANGUAGE_HINT, required = true, error = errors["language"])
                Field("Text to translate", input, { input = it; resultText = null }, hint = "up to 6,000 characters", singleLine = false, minLines = 5, required = true, error = errors["content"])
            }
            "ask" -> Field("Your question", input, { input = it; resultText = null }, hint = "client names are never sent to the AI", singleLine = false, required = true)
            "kb" -> {
                Field("Your question", input, { input = it; resultText = null; steps = emptyList() }, singleLine = false, required = true, error = errors["question"])
                Tick(KB_GENERAL_TICK, general) { general = it; resultText = null }
            }
            "curriculum" -> {
                Field("Technology", input, { input = it; resultText = null }, hint = "such as JAWS", required = true)
                Choice("Level", level, listOf("Beginner", "Intermediate", "Advanced").map { it to it }) { level = it; resultText = null }
                Field("Focus", focus, { focus = it; resultText = null }, hint = "optional", singleLine = false)
            }
            "risk" -> Text("Reviews your active clients who have gone longest without a session.")
        }
        // A knowledge-base miss: the server's sentence and the website's three steps, read as one.
        ErrorText(error?.let { e -> (listOf(e) + steps).joinToString(" ") })
        val needed = when (tool) { "risk" -> null; "translate" -> "Text to translate"; "curriculum" -> "Technology"; "remediate" -> "Content"; else -> "Your question" }
        PrimaryButton(if (tool == "curriculum") "Draft lesson plan" else "Ask the AI", busy = busy, busyText = AI_WORKING, announceBusy = true, missing = if (needed != null && input.isBlank()) stillNeeded(listOf(needed)) else null) {
            val chars = codePointCount(input)
            errors = emptyMap(); steps = emptyList()
            if (tool == "translate" && chars > 6000) { errors = mapOf("content" to "That is too much text to translate at once: shorten it to 6,000 characters or fewer (it has $chars), or split it into parts and translate each one."); error = errors["content"]; return@PrimaryButton }
            if (tool == "translate" && lang == TRANSLATE_OTHER && otherLang.isBlank()) { errors = mapOf("language" to "Type the language to translate into."); error = errors["language"]; return@PrimaryButton }
            error = null; resultText = null
            scope.launch {
                busy = true
                try {
                    resultText = when (tool) {
                        "remediate" -> api.call("ai_remediate", body = JSONObject().put("mode", mode).put("content", input))
                        // 4.34.0: the language's name, as the website sends it (the server also maps a code).
                        "translate" -> api.call("ai_translate", body = JSONObject().put("lang", translateTarget(lang, otherLang)).put("content", input))
                        "ask" -> api.call("ai_ask", body = JSONObject().put("question", input.trim()))
                        "kb" -> api.call("ai_kb", body = kbBody(input, general))
                        "curriculum" -> api.call("ai_curriculum", body = JSONObject().put("tech", input.trim()).put("level", level).put("focus", focus.trim()))
                        else -> api.call("ai_risk", body = JSONObject())
                    }.toString()
                } catch (e: ApiException) {
                    errors = mapErrors(e.reply) { k -> if (tool == "translate") translateFieldFor(k).ifEmpty { k } else k }
                    steps = if (tool == "kb") (e.reply?.stringList("steps") ?: emptyList()) else emptyList()
                    error = errorSummary(errors.values) ?: e.message
                } finally { busy = false }
            }
        }
        result?.let { r ->
            val footer = when (tool) {
                "kb" -> r.optJSONArray("sources").objects().takeIf { it.isNotEmpty() }?.joinToString(", ", prefix = "Grounded in: ") { it.optString("title") }
                "curriculum" -> "Save it as a curriculum on the website: AI tools › Curriculum generator."
                "risk" -> if (r.optInt("assessed") < r.optInt("total_active")) "${r.optInt("assessed")} of ${r.optInt("total_active")} active clients assessed: only those with no session yet or the longest time since their last session fit one review." else null
                "ask" -> "Read-only; check before acting."
                else -> null
            }
            // 4.34.0: the notices are part of the result text (no extra stop), before it.
            val notices = aiNotices(tool, r)
            // A translation is read in the language it was put into (owner rule 5); its notices are in English, so they go in the footer.
            if (tool == "translate") AiResult("Result", r.optString("text"), listOfNotNull(notices.joinToString(" ").ifEmpty { null }, footer).joinToString(" ").ifEmpty { null }, lang = if (lang == TRANSLATE_OTHER) null else lang)
            else if (tool == "kb") AiResult(kbCaption(flag(r, "general")), (notices + r.optString("text")).filter { it.isNotBlank() }.joinToString("\n\n"), footer)
            else AiResult("Result", (notices + r.optString("text")).filter { it.isNotBlank() }.joinToString("\n\n"), footer)
            Text(aiReviewWords(api.aiRedaction))
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
        // 4.37.0 (S25): remediation aimed at this client's preferred format.
        if (api.aiHas("remediate") && api.can("clients")) add("remediate" to "Make a document accessible for this client")
    }
    // 4.37.0: the client's name, for "Start a device request for <client>" and the remediation label.
    val clientName = rememberLoaded(clientId) { try { clientChoices(api, includeClosed = true).firstOrNull { it.optInt("id") == clientId }?.optString("name") } catch (e: ApiException) { null } }.data
    var startingRequest by rememberSaveable { mutableStateOf(false) }
    var progressGoalId by rememberSaveable { mutableIntStateOf(0) }
    var progressNoticeText by remember { mutableStateOf<String?>(null) }
    var remMode by rememberSaveable { mutableStateOf("plain") }
    var remContent by rememberSaveable { mutableStateOf("") }
    var remError by remember { mutableStateOf<String?>(null) }
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
    // 4.35.0: the assessment's date is asked (today to start with), as the website's intake.
    var assessDate by rememberSaveable { mutableStateOf(today()) }
    var assessDateError by remember { mutableStateOf<String?>(null) }
    var assessPresses by remember { androidx.compose.runtime.mutableIntStateOf(0) }
    val scope = rememberCoroutineScope()
    HoldBackWhileSaving(busy)
    Page {
        if (tools.isEmpty()) { Text("No AI help for clients is switched on for your account."); return@Page }
        Choice("AI help", tool, tools.map { (k, l) -> k to if (k == "remediate" && clientName != null) "Make a document accessible for $clientName" else l }) { tool = it; resultText = null; error = null; saved = false }
        if (tool == "remediate") {
            Choice("Make it", remMode, REMEDIATE_MODES) { remMode = it; resultText = null }
            Field("Content", remContent, { remContent = it; resultText = null; remError = null }, hint = "sent to the AI provider as it is", singleLine = false, minLines = 5, required = true, error = remError)
        }
        if (tool == "intake") {
            Field("Goals", goals, { goals = it }, hint = "what the person wants to do", singleLine = false)
            Field("Limitations", limits, { limits = it }, hint = "optional", singleLine = false)
            Field("What they have tried", tried, { tried = it }, hint = "optional", singleLine = false)
        }
        // 4.35.0: the one summary (the assessment date refusal too), taking focus on each Save press.
        ErrorText(error, focusKey = assessPresses)
        // 4.29.0: asking has its own busy state, so Back is never held while the AI works.
        PrimaryButton("Ask the AI", busy = asking || busy, busyText = if (asking) AI_WORKING else "Please wait…", announceBusy = asking,
            missing = if (tool == "intake" && listOf(goals, limits, tried).all { it.isBlank() }) "Still needed: Goals, Limitations or What they have tried."
                else if (tool == "remediate" && remContent.isBlank()) stillNeeded(listOf("Content")) else null) {
            if (asking) return@PrimaryButton
            error = null; resultText = null; saved = false
            scope.launch {
                asking = true
                try {
                    val r = when (tool) {
                        "recommend" -> api.call("ai_recommend", body = JSONObject().put("client_id", clientId))
                        "progress" -> api.call("ai_progress", body = JSONObject().put("client_id", clientId))
                        "remediate" -> api.call("ai_remediate", body = remediateBody(remMode, remContent, clientId))
                        "intake" -> api.call("ai_intake", body = JSONObject().put("client_id", clientId).put("goals", goals.trim()).put("limitations", limits.trim()).put("tried", tried.trim()).put("context", ""))
                        else -> api.call("ai_draft", body = JSONObject().put("type", "case").put("client_id", clientId))
                    }
                    resultText = r.toString()
                    draft = r.optString("text")
                    fields = r.optJSONObject("fields")?.let { f -> f.keys().asSequence().associateWith { f.optString(it) } } ?: emptyMap()
                } catch (e: ApiException) {
                    remError = if (tool == "remediate") replyErrors(e.reply)["content"] else null
                    error = remError ?: e.message
                } finally { asking = false }
            }
        }
        val r = result ?: return@Page
        when (tool) {
            "recommend" -> {
                // 4.37.0 (S6): the website's notice and footer, and a request started from here.
                AiResult("Recommendations", (listOfNotNull(if (flag(r, "truncated")) AI_INCOMPLETE else null) + r.optString("text")).joinToString("\n\n"), recommendFooter(api.can("procurement")))
                if (api.can("procurement")) SecondaryButton(startRequestLabel(clientName ?: "this client")) { startingRequest = true }
            }
            "remediate" -> AiResult("Result", (listOfNotNull(remediateTarget(r.str("format"))) + aiNotices("remediate", r) + r.optString("text")).filter { it.isNotBlank() }.joinToString("\n\n"))
            "progress" -> {
                // 4.37.0 (S10): the website's incomplete notice, and each goal's progress recorded from here.
                AiResult("Progress summary", (listOfNotNull(if (flag(r, "truncated")) progressNotice() else null) + r.optString("text")).filter { it.isNotBlank() }.joinToString("\n\n"),
                    if (r.has("goals")) PROGRESS_CAPTION else null)
                parseReviewGoals(r)?.let { goals ->
                    NoticeText(progressNoticeText)
                    ReviewGoalRows(goals) { g -> progressNoticeText = null; progressGoalId = g.id }
                    goals.firstOrNull { it.id == progressGoalId && progressGoalId > 0 }?.let { g ->
                        androidx.compose.runtime.key(org.casemanagement.app.dialogKey("review-progress", g.id)) {
                            ProgressDialog(api, org.casemanagement.app.Goal(g.id, g.text, "", null, g.status, null, g.pct),
                                onDone = { m -> progressGoalId = 0; progressNoticeText = m }, onCancel = { progressGoalId = 0 })
                        }
                    }
                }
            }
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
                if (!saved) Field("Assessment date", assessDate, { assessDate = it; assessDateError = null }, hint = "year-month-day", error = assessDateError, required = true)
                if (saved) NoticeText("Assessment saved.") else PrimaryButton("Save the assessment", busy = busy) {
                    assessPresses++
                    assessDateError = assessmentDateRule(assessDate)
                    if (assessDateError != null) { error = assessDateError; return@PrimaryButton }
                    scope.launchSave({ busy = it }, { error = it }) {
                        val b = JSONObject().put("client_id", clientId)
                        fields.forEach { (k, v) -> b.put(k, v.trim()) }
                        b.put("assessment_date", assessDate.trim())
                        try { api.callOnce("add_assessment", b, assessKey, { assessKey = it }, "the assessment"); saved = true }
                        catch (e: ApiException) { serverFieldErrors(e.reply)?.get("assessment_date")?.let { assessDateError = it }; throw e }
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
    if (startingRequest) {
        val r = result
        AddDeviceRequestDialog(api, onDone = { id -> startingRequest = false; nav.navigate("deviceRequest/$id") }, onCancel = { startingRequest = false },
            initialClientId = clientId, initialJustification = startRequestPrefill(r?.optString("text") ?: ""))
    }
}
