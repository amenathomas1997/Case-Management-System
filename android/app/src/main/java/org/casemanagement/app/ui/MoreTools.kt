package org.casemanagement.app.ui

import androidx.compose.foundation.layout.Column
import androidx.compose.material3.AlertDialog
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
import org.json.JSONObject

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
        Show(state, "articles") { list ->
            Announce(if (search.isNotEmpty()) "${list.size} ${if (list.size == 1) "article" else "articles"} found." else null)
            Heading("Articles (${list.size})")
            if (list.isEmpty()) Text("No articles found.")
            list.forEach { a -> ListRow(listOf(a.optString("title"), listOfNotNull(a.str("category"), if (a.optBoolean("is_public") || a.optInt("is_public") == 1) "public" else null).joinToString(", ")), action = "read it", onClick = { nav.navigate("kb/${a.optInt("id")}") }) }
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
            a.str("category")?.let { Text("Category: $it") }
            MarkdownBlocks(a.optString("body"))
            if (api.can("templates")) {
                SecondaryButton("Edit the article") { nav.navigate("kbEdit/$id") }
                SecondaryButton("Delete the article") { deleting = true }
            }
        }
    }
    if (deleting) Confirm("Delete this article?", "This cannot be undone.", "Delete article", {
        scope.launch { try { api.call("delete_kb", body = JSONObject().put("id", id)); Flash.pending = "Article deleted."; nav.popBackStack() } catch (e: ApiException) { error = e.message } }
    }, { deleting = false })
}

/** Markdown as screen-reader friendly blocks: # lines are headings, -, * and • lines list items. */
@Composable
fun MarkdownBlocks(text: String) {
    text.replace("\r", "").split("\n").map { it.trim() }.filter { it.isNotEmpty() && it != "---" }.forEach { line ->
        val h = Regex("^(#{1,6})\\s+(.*)$").find(line)
        when {
            h != null -> Heading(h.groupValues[2].replace("**", ""))
            Regex("^[-*•]\\s+").containsMatchIn(line) -> Text("• " + line.replaceFirst(Regex("^[-*•]\\s+"), "").replace("**", ""))
            else -> Text(line.replace("**", ""), modifier = androidx.compose.ui.Modifier.semantics { })
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
        var error by remember { mutableStateOf<String?>(null) }
        var busy by remember { mutableStateOf(false) }
        val scope = rememberCoroutineScope()
        Page {
            Field("Title", title, { title = it })
            Field("Category", category, { category = it }, hint = "optional")
            Field("Article", body, { body = it }, hint = "# starts a heading, - a list item", singleLine = false, minLines = 8)
            Tick("Shown to clients in their portal", public) { public = it }
            ErrorText(error)
            PrimaryButton("Save", busy = busy, enabled = title.isNotBlank()) {
                scope.launchSave({ busy = it }, { error = it }) {
                    val b = JSONObject().put("title", title.trim()).put("category", category.trim()).put("body", body).put("is_public", if (public) 1 else 0)
                    if (id > 0) b.put("id", id)
                    api.call("save_kb", body = b)
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
    var chosen by remember { mutableStateOf<JSONObject?>(null) }
    var ask by remember { mutableStateOf<Pair<JSONObject, Boolean>?>(null) }
    var notice by remember { mutableStateOf<String?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    val scope = rememberCoroutineScope()
    Show(state, "counselors") { list ->
        Page {
            NoticeText(notice)
            ErrorText(error)
            Heading("VR counselors (${list.size})")
            list.forEach { c ->
                val badge = when { c.optBoolean("portal_active") -> "Portal active"; c.optBoolean("portal_expired") -> "Portal expired"; else -> "Not invited" }
                ListRow(listOf(c.optString("name").ifEmpty { c.optString("email") }, listOfNotNull(badge, c.str("agency"), "${c.optInt("clients")} clients").joinToString(", "), c.optString("email")),
                    action = "send or turn off the portal link", onClick = { chosen = c })
            }
        }
    }
    chosen?.let { c ->
        val name = c.optString("name").ifEmpty { c.optString("email") }
        AlertDialog(onDismissRequest = { chosen = null }, title = { Text(name, modifier = Modifier.semantics { heading() }) },
            text = { Column {
                TextButton(onClick = { chosen = null; ask = c to true }) { Text(if (c.optBoolean("portal_active")) "Resend or reset the link" else if (c.optBoolean("portal_expired")) "Send a new link" else "Send the portal link") }
                if (c.optBoolean("portal_active")) TextButton(onClick = { chosen = null; ask = c to false }) { Text("Turn off the link") }
            } },
            confirmButton = { TextButton(onClick = { chosen = null }) { Text("Close") } })
    }
    ask?.let { (c, send) ->
        val name = c.optString("name").ifEmpty { c.optString("email") }
        Confirm(if (send) "Email a portal link to $name?" else "Turn off the portal link for $name?",
            if (send) "Any previous link stops working." else "It stops working at once, and they need a new link to get back in.",
            if (send) "Send link" else "Turn off link", {
                scope.launch {
                    try {
                        if (send) { api.call("send_vrc_portal", body = JSONObject().put("email", c.optString("email")).put("name", c.optString("name"))); notice = "Portal link emailed to $name." }
                        else { api.call("revoke_vrc_portal", body = JSONObject().put("email", c.optString("email"))); notice = "Portal link turned off for $name. Send a new link when they need access again." }
                        state.reload()
                    } catch (e: ApiException) { error = e.message }
                }
            }, { ask = null })
    }
}


/* ---------------- Client portal requests ---------------- */

@Composable
fun PortalRequestsScreen(api: Api, nav: NavHostController) {
    var all by rememberSaveable { mutableStateOf(false) }
    val state = rememberLoaded(all) { api.call("portal_requests", if (all) mapOf("all" to "1") else emptyMap()) }
    var chosen by remember { mutableStateOf<JSONObject?>(null) }
    var outcomeFor by remember { mutableStateOf<Pair<JSONObject, String>?>(null) }
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
            list.forEach { q -> ListRow(listOf(q.optString("client_name") + ": " + q.optString("type_label"), q.optString("status") + ", " + whenText(q.str("created_at")), q.str("details") ?: ""), action = "change its status", onClick = { chosen = q }) }
        }
    }
    chosen?.let { q ->
        val needsOutcome = q.optString("type") in setOf("records", "correction")
        AlertDialog(onDismissRequest = { chosen = null }, title = { Text(q.optString("client_name") + ": " + q.optString("type_label"), modifier = Modifier.semantics { heading() }) },
            text = { Column {
                listOf("New", "In Progress", "Done", "Declined").filter { it != q.optString("status") }.forEach { s ->
                    TextButton(onClick = { chosen = null; if (needsOutcome && (s == "Done" || s == "Declined")) outcomeFor = q to s else set(q, s, null) }) { Text("Mark it $s") }
                }
                if (api.can("clients")) TextButton(onClick = { chosen = null; nav.navigate("client/${q.optInt("client_id")}") }) { Text("Open the client record") }
            } },
            confirmButton = { TextButton(onClick = { chosen = null }) { Text("Close") } })
    }
    outcomeFor?.let { (q, to) ->
        var text by remember(q) { mutableStateOf(q.str("outcome") ?: "") }
        FormDialog(if (to == "Declined") "Why is the request refused?" else "How was it handled?", "Save", false, text.isNotBlank(), onSave = { outcomeFor = null; set(q, to, text.trim()) }, onCancel = { outcomeFor = null }) {
            Field("Outcome the client sees", text, { text = it }, hint = "the client sees this in their portal", singleLine = false)
        }
    }
}

/* ---------------- Staff utilization ---------------- */

@Composable
fun UtilizationScreen(api: Api) {
    var from by rememberSaveable { mutableStateOf("") }
    var to by rememberSaveable { mutableStateOf("") }
    var run by rememberSaveable { mutableStateOf(0) }
    val state = rememberLoaded(run) { api.call("utilization", listOfNotNull(from.trim().takeIf { it.isNotEmpty() }?.let { "from" to it }, to.trim().takeIf { it.isNotEmpty() }?.let { "to" to it }).toMap()) }
    Page {
        Field("From", from, { from = it }, hint = "year-month-day; empty for the last 28 days")
        Field("To", to, { to = it }, hint = "year-month-day")
        SecondaryButton("Show") { run++ }
        Show(state, "utilization") { r ->
            Heading("${dayText(r.str("from"))} to ${dayText(r.str("to"))}")
            ListRow(listOf((if (r.optBoolean("caseload_scoped")) "Caseload / capacity: " else "Organisation: ") + "${r.optInt("org_util")}%", "${hoursText(r.optDouble("total_delivered", 0.0))} hours delivered, worth " + money(r.optDouble("total_value", 0.0))))
            val rows = r.optJSONArray("rows").objects()
            val band = { u: Int -> when { u >= 85 -> "on target"; u >= 60 -> "below target"; else -> "well below target" } }
            rows.filter { !it.optBoolean("no_target") }.forEach { s -> ListRow(listOf(s.optString("name") + ": ${s.optInt("util")}%, ${band(s.optInt("util"))}", "${hoursText(s.optDouble("delivered", 0.0))} of ${hoursText(s.optDouble("expected", 0.0))} expected hours, ${s.optInt("clients")} clients")) }
            val none = rows.filter { it.optBoolean("no_target") }
            if (none.isNotEmpty()) {
                Heading("No weekly target")
                none.forEach { s -> ListRow(listOf(s.optString("name"), "${hoursText(s.optDouble("delivered", 0.0))} hours, ${s.optInt("clients")} clients")) }
            }
            if (r.optDouble("former_staff_delivered", 0.0) > 0) Text("Former staff delivered ${hoursText(r.optDouble("former_staff_delivered", 0.0))} hours in this period, counted in the totals.")
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
