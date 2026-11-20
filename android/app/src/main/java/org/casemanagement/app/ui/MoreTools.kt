package org.casemanagement.app.ui

import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.padding
import androidx.compose.ui.unit.dp
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.semantics
import androidx.navigation.NavHostController
import kotlinx.coroutines.launch
import org.casemanagement.app.Api
import org.casemanagement.app.ApiException
import org.casemanagement.app.objects
import org.casemanagement.app.str
import org.casemanagement.app.strings
import org.json.JSONObject
import androidx.compose.runtime.key
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import org.casemanagement.app.dialogKey

/* ---------------- Knowledge base ---------------- */

@Composable
fun KnowledgeScreen(api: Api, nav: NavHostController) {
    var q by rememberSaveable { mutableStateOf("") }
    var search by rememberSaveable { mutableStateOf("") }
    val state = rememberLoaded(search) { api.call("kb_articles", if (search.isEmpty()) emptyMap() else mapOf("q" to search)).optJSONArray("articles").objects() }
    Page {
        Field("Search articles", q, { q = it })
        SecondaryButton("Search") { search = q.trim() }
        if (api.can("templates")) SecondaryButton("Write an article") { nav.navigate("kbEdit/0") }
        // 4.37.0 (S26): asking the knowledge base, from its list (as the website).
        if (api.aiHas("kb")) SecondaryButton("Ask the knowledge base") { nav.navigate("aiTools?tool=kb") }
        Show(state, "articles") { list ->
            Announce(if (search.isNotEmpty()) "${list.size} ${if (list.size == 1) "article" else "articles"} found." else null)
            Heading("Articles (${list.size})")
            if (list.isEmpty()) Text(kbEmptyText(search.isNotEmpty()))
            list.forEach { a -> ListRow(listOf(a.optString("title"), listOfNotNull(kbMeta(a.str("category"), a.str("updated_at"), a.str("author")).ifEmpty { null }, if (a.optBoolean("is_public") || a.optInt("is_public") == 1) "Shared with clients" else null).joinToString(", ")), action = "read it", onClick = { nav.navigate("kb/${a.optInt("id")}") }) }
        }
    }
}

/** An article, its Markdown shown as headings, list items and paragraphs. */
@Composable
fun KnowledgeArticleScreen(api: Api, nav: NavHostController, id: Int) {
    val state = rememberLoaded(id) { api.call("kb_article", mapOf("id" to id.toString())).optJSONObject("article") ?: JSONObject() }
    var deleting by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    val scope = rememberCoroutineScope()
    Show(state, "the article") { a ->
        Page {
            Heading(a.optString("title"), large = true)
            ErrorText(error)
            // 4.37.0 (S26): the website's meta line, and the iPhone's article rules.
            kbMeta(a.str("category"), a.str("updated_at"), a.str("author")).ifEmpty { null }?.let { Text(it) }
            if (a.optBoolean("is_public") || a.optInt("is_public") == 1) Text("Shared with clients")
            MarkdownBlocks(a.optString("body"))
            if (api.can("templates")) {
                SecondaryButton("Edit the article") { nav.navigate("kbEdit/$id") }
                SecondaryButton("Delete the article") { deleting = true }
            }
        }
    }
    if (deleting) Confirm("Delete this article permanently?", KB_DELETE_TEXT, "Delete permanently", {
        scope.launch { try { api.call("delete_kb", body = JSONObject().put("id", id)); Flash.pending = "Article deleted."; nav.popBackStack() } catch (e: ApiException) { error = e.message } }
    }, { deleting = false })
}

/**
 * Markdown as screen-reader friendly blocks: # lines are headings, -, * and • lines list items,
 * "1." lines numbered steps, and a paragraph's lines read together (4.37.0: the iPhone's kbBlocks
 * rules). [lang]: the language the text is written in (a translation), marked on every block so
 * TalkBack reads it with that language's voice (4.30.0, owner rule 5).
 */
@Composable
fun MarkdownBlocks(text: String, lang: String? = null) {
    kbBlocks(text.replace("**", "")).forEach { b ->
        when (b) {
            is KbBlock.Heading -> Text(
                inLanguage(b.text, lang),
                style = androidx.compose.material3.MaterialTheme.typography.titleMedium,
                fontWeight = androidx.compose.ui.text.font.FontWeight.SemiBold,
                modifier = Modifier.padding(top = 12.dp, bottom = 4.dp).semantics { heading() },
            )
            is KbBlock.Paragraph -> Text(inLanguage(b.text, lang), modifier = Modifier.padding(vertical = 4.dp))
            is KbBlock.Item -> Text(inLanguage("${b.marker} ${b.text}", lang), modifier = Modifier.padding(vertical = 2.dp))
        }
    }
}


@Composable
fun KnowledgeEditScreen(api: Api, nav: NavHostController, id: Int) {
    val state = rememberLoaded(id) { if (id > 0) api.call("kb_article", mapOf("id" to id.toString())).optJSONObject("article") ?: JSONObject() else JSONObject() }
    Show(state, "the article") { a ->
        var title by rememberSaveable { mutableStateOf(a.str("title") ?: "") }
        var category by rememberSaveable { mutableStateOf(a.str("category") ?: "") }
        var body by rememberSaveable { mutableStateOf(a.str("body") ?: "") }
        var public by rememberSaveable { mutableStateOf(a.optBoolean("is_public") || a.optInt("is_public") == 1) }
        var idem by rememberSaveable { mutableStateOf("") }
        var error by remember { mutableStateOf<String?>(null) }
        var busy by remember { mutableStateOf(false) }
        val scope = rememberCoroutineScope()
        Page {
            Field("Title", title, { title = it }, required = true)
            Field("Category", category, { category = it }, hint = "optional")
            Field("Article", body, { body = it }, hint = KB_EDITOR_HINT, singleLine = false, minLines = 8)
            Tick(KB_PUBLIC_TICK, public) { public = it }
            ErrorText(error)
            PrimaryButton("Save", busy = busy, missing = if (title.isBlank()) stillNeeded(listOf("Title")) else null) {
                scope.launchSave({ busy = it }, { error = it }) {
                    val b = JSONObject().put("title", title.trim()).put("category", category.trim()).put("body", body).put("is_public", if (public) 1 else 0)
                    if (id > 0) b.put("id", id)
                    // 4.28.0: a new article is added once, even when a reply was lost (see sendOnce).
                    if (id > 0) api.call("save_kb", body = b) else api.callOnce("save_kb", b, idem, { idem = it }, "the article")
                    Flash.pending = "Article saved."
                    nav.popBackStack()
                }
            }
        }
    }
}

/* ---------------- Counselor portal ---------------- */

@Composable
fun CounselorsScreen(api: Api) {
    val state = rememberLoaded(api.userId) { api.call("counselors").optJSONArray("counselors").objects() }
    // 4.38.0 (T9): the counselor whose buttons are shown, and the confirmation asked (kind "send" or "off"), by email, kept across rotation.
    var chosenEmail by rememberSaveable { mutableStateOf("") }
    var askEmail by rememberSaveable { mutableStateOf("") }
    var askKind by rememberSaveable { mutableStateOf("") }
    // 4.28.0: the link is emailed once even when a reply was lost (see sendOnce).
    var sendKey by rememberSaveable { mutableStateOf("") }
    var busy by remember { mutableStateOf(false) }
    var notice by remember { mutableStateOf<String?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    var presses by remember { mutableIntStateOf(0) }
    val scope = rememberCoroutineScope()
    fun who(c: JSONObject) = c.optString("name").ifEmpty { c.optString("email") }
    Show(state, "counselors") { list ->
        Page {
            NoticeText(notice)
            ErrorText(error, focusKey = presses)
            Heading("VR counselors (${list.size})")
            list.forEach { c ->
                ListRow(listOf(who(c)) + counselorLines(c) + c.optString("email"), action = "send or turn off the portal link", onClick = { chosenEmail = c.optString("email") })
            }
        }
    }
    val list = state.data ?: emptyList()
    list.firstOrNull { chosenEmail.isNotEmpty() && it.optString("email") == chosenEmail }?.let { c ->
        AlertDialog(onDismissRequest = { chosenEmail = "" }, title = { Text(who(c), modifier = Modifier.semantics { heading() }) },
            text = { Column(Modifier.verticalScroll(rememberScrollState())) {
                counselorButtons(c).forEach { (kind, label) -> TextButton(onClick = { chosenEmail = ""; askEmail = c.optString("email"); askKind = kind }) { Text(label) } }
            } },
            confirmButton = { TextButton(onClick = { chosenEmail = "" }) { Text("Close") } })
    }
    list.firstOrNull { askEmail.isNotEmpty() && it.optString("email") == askEmail }?.let { c ->
        val (title, text, yes) = counselorConfirm(askKind, who(c))
        val send = askKind != "off"
        Confirm(title, text, yes, {
            if (busy) return@Confirm
            presses++
            scope.launchSave({ busy = it }, { error = it; if (it != null) notice = null }) {
                val r = if (send) api.callOnce("send_vrc_portal", JSONObject().put("email", c.optString("email")).put("name", c.optString("name")), sendKey, { sendKey = it }, "the portal link")
                    else api.call("revoke_vrc_portal", body = JSONObject().put("email", c.optString("email")))
                // The website's sentence; an older server sends none.
                error = null
                notice = r.str("message") ?: if (send) "Portal link emailed to ${who(c)}. Any previous link no longer works."
                    else "Portal link turned off for ${c.optString("email")}. It no longer opens, even in a portal session that is already open; send a new link when they need access again."
                state.reload()
            }
        }, { askEmail = ""; askKind = "" })
    }
}


/* ---------------- Client portal requests ---------------- */

@Composable
fun PortalRequestsScreen(api: Api, nav: NavHostController) {
    var all by rememberSaveable { mutableStateOf(false) }
    val state = rememberLoaded(all) { api.call("portal_requests", if (all) mapOf("all" to "1") else emptyMap()) }
    // 4.35.0: times offered on the Offer times screen: the list is read again (keeping what it shows meanwhile).
    var offersSeen by remember { mutableIntStateOf(RequestOffers.count) }
    androidx.compose.runtime.LaunchedEffect(RequestOffers.count) {
        if (RequestOffers.count != offersSeen) { offersSeen = RequestOffers.count; state.reload() }
    }
    var chosen by remember { mutableStateOf<JSONObject?>(null) }
    // 4.29.0: the request whose outcome is being written (its id and the status), kept across rotation.
    var outcomeId by rememberSaveable { mutableIntStateOf(0) }
    var outcomeTo by rememberSaveable { mutableStateOf("") }
    var notice by remember { mutableStateOf<String?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    val scope = rememberCoroutineScope()
    fun set(r: JSONObject, to: String, outcome: String?) {
        error = null
        scope.launch {
            try {
                val b = JSONObject().put("id", r.optInt("id")).put("to", to)
                outcome?.let { b.put("outcome", it) }
                notice = api.call("portal_request_update", body = b).optString("message").ifEmpty { "Request marked $to." }
                state.reload()
            } catch (e: ApiException) { error = e.message }
        }
    }
    Page {
        Tick("Show finished requests too", all) { all = it }
        NoticeText(notice)
        ErrorText(error)
        Show(state, "client requests") { r ->
            val list = r.optJSONArray("requests").objects()
            Heading("Requests (${list.size})" + if (r.optInt("new_count") > 0) ", ${r.optInt("new_count")} new" else "")
            if (list.isEmpty()) Text("No requests.")
            // 4.35.0: the linked session, the due date and the times offered, as on the iPhone.
            list.forEach { q -> ListRow(requestRowLines(q), action = "change its status", onClick = { chosen = q }) }
        }
    }
    chosen?.let { q ->
        val needsOutcome = q.optString("type") in setOf("records", "correction")
        AlertDialog(onDismissRequest = { chosen = null }, title = { Text(q.optString("client_name") + ": " + q.optString("type_label"), modifier = Modifier.semantics { heading() }) },
            text = { Column(Modifier.verticalScroll(rememberScrollState())) {
                listOf("New", "In Progress", "Done", "Declined").filter { it != q.optString("status") }.forEach { s ->
                    TextButton(onClick = { chosen = null; if (needsOutcome && (s == "Done" || s == "Declined")) { outcomeId = q.optInt("id"); outcomeTo = s } else set(q, s, null) }) { Text("Mark it $s") }
                }
                // 4.35.0: times offered for an open appointment or reschedule request (the client chooses one in their portal).
                if (q.optBoolean("can_offer") && api.can("clients")) TextButton(onClick = { chosen = null; nav.navigate("offerTimes/${q.optInt("id")}") }) {
                    Text(if ((q.optJSONArray("offered")?.length() ?: 0) > 0) "Offer different times" else "Offer times")
                }
                if (api.can("clients")) TextButton(onClick = { chosen = null; nav.navigate("client/${q.optInt("client_id")}") }) { Text("Open the client record") }
            } },
            confirmButton = { TextButton(onClick = { chosen = null }) { Text("Close") } })
    }
    state.data?.optJSONArray("requests").objects().firstOrNull { outcomeId != 0 && it.optInt("id") == outcomeId }?.let { q ->
        val to = outcomeTo
        key(dialogKey("portal-outcome", outcomeId)) {
            // 4.29.0: the outcome text is kept across rotation; the dialog stays open, with the text,
            // until the server took it, and a refusal is shown on the field.
            var text by rememberSaveable { mutableStateOf(q.str("outcome") ?: "") }
            var busy by remember { mutableStateOf(false) }
            var fieldError by remember { mutableStateOf<String?>(null) }
            FormDialog(if (to == "Declined") "Why is the request refused?" else "How was it handled?", "Save", busy, missing = if (text.isBlank()) stillNeeded(listOf("Outcome the client sees")) else null, onSave = {
                if (busy) return@FormDialog
                fieldError = null
                scope.launchSave({ busy = it }, { fieldError = it }) {
                    val b = JSONObject().put("id", q.optInt("id")).put("to", to).put("outcome", text.trim())
                    notice = api.call("portal_request_update", body = b).optString("message").ifEmpty { "Request marked $to." }
                    error = null; outcomeId = 0; state.reload()
                }
            }, onCancel = { outcomeId = 0 }) {
                Field("Outcome the client sees", text, { text = it }, hint = "the client sees this in their portal", singleLine = false, error = fieldError, required = true)
                ErrorText(fieldError)
            }
        }
    }
}

/* ---------------- Staff utilization ---------------- */

@Composable
fun UtilizationScreen(api: Api) {
    // 4.38.0 (T13): the website's period presets, remembered in the same setting the website uses
    // (the server remembers a chosen period); an empty choice opens on the remembered period.
    var preset by rememberSaveable { mutableStateOf("") }
    var from by rememberSaveable { mutableStateOf("") }
    var to by rememberSaveable { mutableStateOf("") }
    var query by rememberSaveable(stateSaver = StringMapSaver) { mutableStateOf(emptyMap<String, String>()) }
    var run by rememberSaveable { mutableIntStateOf(0) }
    val state = rememberLoaded(run, query) { api.call("utilization", query) }
    // The period the server used is shown as chosen when the screen opens.
    androidx.compose.runtime.LaunchedEffect(state.data) {
        val r = state.data ?: return@LaunchedEffect
        if (preset.isEmpty()) r.str("period")?.let { p -> preset = p; if (p == "custom") { from = r.optString("from"); to = r.optString("to") } }
    }
    Page {
        val presets = periodChoices(state.data?.optJSONArray("presets").objects())
        if (presets.isNotEmpty()) {
            Choice("Period", preset, presets) { preset = it }
            if (preset == "custom") {
                Field("From", from, { from = it }, hint = "year-month-day")
                Field("To", to, { to = it }, hint = "year-month-day")
            }
        } else {
            // An older server: dates only.
            Field("From", from, { from = it }, hint = "year-month-day; empty for the last 28 days")
            Field("To", to, { to = it }, hint = "year-month-day")
        }
        SecondaryButton("Run report") {
            query = if (presets.isNotEmpty()) utilizationQuery(preset, from, to) else utilizationQuery("custom", from, to) - "period"
            run++
        }
        Show(state, "utilization") { r ->
            Heading("Summary for ${shortDate(r.str("from"))} to ${shortDate(r.str("to"))}")
            r.optJSONArray("notes").strings().forEach { Text(it) }
            ListRow(listOf("Team utilization ${r.optInt("org_util")}%"))
            ListRow(listOf("Billable hours delivered ${hoursWords(r.optDouble("total_delivered", 0.0))}"))
            ListRow(listOf("Billable value ${money(r.optDouble("total_value", 0.0))}"))
            if (r.has("weeks")) ListRow(listOf("Weeks in period ${weeksText(r.optDouble("weeks", 0.0))}"))
            (r.str("scoped_note") ?: if (r.optBoolean("caseload_scoped") && !r.has("scoped_note")) "Activity is limited to your assigned clients. Expected hours remain the staff members’ full capacity targets." else null)?.let { Text(it) }
            Heading("Staff utilization")
            val rows = r.optJSONArray("rows").objects()
            if (rows.isEmpty()) Text("No active staff.")
            rows.forEach { s -> ListRow(utilizationRowText(s)) }
            if (r.optDouble("former_staff_delivered", 0.0) > 0) ListRow(listOf("Former or unrecorded staff",
                "${hoursText(r.optDouble("former_staff_delivered", 0.0))} hours · ${money(r.optDouble("former_staff_value", 0.0))} value"))
            r.str("footer")?.let { Text(it) }
        }
    }
}

/* ---------------- Documentation review ---------------- */

@Composable
fun DocReviewScreen(api: Api, nav: NavHostController) {
    val state = rememberLoaded(api.userId) { api.call("doc_review").optJSONArray("items").objects() }
    Show(state, "the documentation review") { list ->
        Page {
            Heading("Notes to look at (${list.size})")
            if (list.isEmpty()) Text("Nothing to review.")
            list.forEach { i -> ListRow(listOf(i.optString("client_name") + ": " + (i.str("subject") ?: "Case note"), i.optString("flagged_for"), whenText(i.str("created_at"))), action = "open the client", onClick = { nav.navigate("client/${i.optInt("client_id")}") }) }
        }
    }
}
