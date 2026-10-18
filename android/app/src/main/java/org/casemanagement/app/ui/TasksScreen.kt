package org.casemanagement.app.ui

import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
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
import org.casemanagement.app.messageFileLabel
import org.json.JSONObject
import java.util.UUID

/** Your open tasks, soonest first; complete one (kept offline when there is no signal) or add one. */
@Composable
fun TasksScreen(api: Api, nav: NavHostController) {
    var show by rememberSaveable { mutableStateOf("open") }
    val state = rememberLoaded(show) { api.call("tasks", mapOf("show" to show)) }
    var chosen by remember { mutableStateOf<JSONObject?>(null) }
    var adding by remember { mutableStateOf(false) }
    var notice by remember { mutableStateOf<String?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    // Tasks with a change waiting to send from this phone, and whether it reopens them.
    val queued: Map<Int, Boolean> = remember(api.pendingSync) {
        api.ownQueued().filter { it.optString("action") == "complete_task" }.mapNotNull { q ->
            try { org.json.JSONObject(q.optString("body")).let { b -> b.optInt("id") to b.optBoolean("reopen") } } catch (e: Exception) { null }
        }.toMap()
    }
    val scope = rememberCoroutineScope()

    Show(state, "tasks") { r ->
        val tasks = r.optJSONArray("tasks").objects()
        Page {
            NoticeText(notice)
            ErrorText(error)
            PrimaryButton("Add a task") { adding = true }
            Choice("Show", show, listOf("open" to "Open tasks", "all" to "All tasks, including done")) { show = it }
            Heading("${if (show == "open") "Open tasks" else "All tasks"} (${tasks.size})")
            if (tasks.isEmpty()) Text("No tasks.")
            tasks.forEach { t ->
                val id = t.optInt("id")
                val due = t.optInt("days_left", Int.MIN_VALUE).let { d ->
                    when {
                        t.isNull("days_left") || d == Int.MIN_VALUE -> "no due date"
                        d < 0 -> "overdue by ${-d} ${if (d == -1) "day" else "days"}"
                        d == 0 -> "due today"
                        d == 1 -> "due tomorrow"
                        else -> "due in $d days"
                    }
                }
                val status = queued[id]?.let { reopen -> if (reopen) "Reopened, waiting to send" else "Done, waiting to send" } ?: t.optString("status")
                ListRow(listOf(t.optString("title"), listOfNotNull(status, due, t.str("client_name"), t.str("assigned_name")?.let { "for $it" }).joinToString(", ")),
                    action = "see what you can do", onClick = if (id in queued) null else ({ chosen = t }))
            }
        }
    }

    chosen?.let { t ->
        val open = t.optString("status") == "Open"
        AlertDialog(
            onDismissRequest = { chosen = null },
            title = { Text(t.optString("title"), modifier = Modifier.semantics { heading() }) },
            text = {
                Column {
                    t.str("details")?.let { Text(it) }
                    TextButton(onClick = {
                        chosen = null; error = null
                        scope.launch {
                            try {
                                val body = JSONObject().put("id", t.optInt("id")).put("idem_key", UUID.randomUUID().toString())
                                if (!open) body.put("reopen", true)
                                val s = saveOrQueue(api, "complete_task", body, (if (open) "Task done: " else "Reopen task: ") + t.optString("title").take(40),
                                    if (open) "Task completed." else "Task reopened.")
                                notice = s.message
                                if (s.reply != null) state.reload()
                            } catch (e: ApiException) { error = e.message }
                        }
                    }) { Text(if (open) "Mark as done" else "Reopen") }
                    if (t.optBoolean("can_open_client") && !t.isNull("client_id")) {
                        TextButton(onClick = { chosen = null; nav.navigate("client/${t.optInt("client_id")}") }) { Text("Open " + t.optString("client_name")) }
                    }
                }
            },
            confirmButton = { TextButton(onClick = { chosen = null }) { Text("Close") } },
        )
    }

    if (adding) AddTaskDialog(api, onDone = { m -> adding = false; notice = m; state.reload() }, onCancel = { adding = false })
}

@Composable
private fun AddTaskDialog(api: Api, onDone: (String) -> Unit, onCancel: () -> Unit) {
    var title by remember { mutableStateOf("") }
    var due by remember { mutableStateOf("") }
    var details by remember { mutableStateOf("") }
    var error by remember { mutableStateOf<String?>(null) }
    var busy by remember { mutableStateOf(false) }
    val scope = rememberCoroutineScope()
    AlertDialog(
        onDismissRequest = onCancel,
        title = { Text("Add a task", modifier = Modifier.semantics { heading() }) },
        text = {
            Column(Modifier.verticalScroll(rememberScrollState())) {
                Field("Task", title, { title = it })
                Field("Due date", due, { due = it }, hint = "optional, year-month-day such as ${today()}")
                Field("Details", details, { details = it }, hint = "optional", singleLine = false, minLines = 3)
                ErrorText(error)
            }
        },
        confirmButton = {
            TextButton(enabled = !busy && title.isNotBlank(), onClick = {
                if (due.isNotBlank() && !Regex("^\\d{4}-\\d{2}-\\d{2}$").matches(due.trim())) { error = "Enter the due date as year-month-day, such as ${today()}, or leave it empty."; return@TextButton }
                scope.launchSave({ busy = it }, { error = it }) {
                    val r = api.call("save_task", body = JSONObject().put("title", title.trim()).put("due_date", due.trim()).put("details", details.trim()))
                    onDone(r.optString("message").ifEmpty { "Task added." })
                }
            }) { Text(if (busy) "Saving…" else "Add") }
        },
        dismissButton = { TextButton(onClick = onCancel) { Text("Cancel") } },
    )
}

/** Alerts, newest first; reading them marks them read. */
@Composable
fun AlertsScreen(api: Api) {
    val state = rememberLoaded(api.userId) { api.call("notifications") }
    var notice by remember { mutableStateOf<String?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    val scope = rememberCoroutineScope()
    Show(state, "alerts") { r ->
        val list = r.optJSONArray("notifications").objects()
        Page {
            NoticeText(notice)
            ErrorText(error)
            val unread = r.optInt("unread")
            if (unread > 0) PrimaryButton("Mark all $unread as read") {
                scope.launch {
                    try { notice = api.call("mark_notifications_read", body = JSONObject().put("all", true)).optString("message").ifEmpty { "All marked as read." }; state.reload() }
                    catch (e: ApiException) { error = e.message }
                }
            }
            Heading("Alerts (${list.size})")
            if (list.isEmpty()) Text("No alerts.")
            list.forEach { n -> ListRow(listOf((if (n.optBoolean("unread")) "Unread: " else "") + n.optString("message"), whenText(n.str("created_at")))) }
        }
    }
}

/** Referrals, open first, as on the website's Referrals page. */
@Composable
fun ReferralsScreen(api: Api, nav: NavHostController) {
    var status by rememberSaveable { mutableStateOf("open") }
    val state = rememberLoaded(status) { api.call("referrals", mapOf("status" to status)).optJSONArray("referrals").objects() }
    Page {
        Choice("Show", status, listOf("open" to "Open referrals", "converted" to "Converted to clients", "closed" to "Closed", "all" to "All")) { status = it }
        Show(state, "referrals") { list ->
            Heading("Referrals (${list.size})")
            if (list.isEmpty()) Text("No referrals.")
            list.forEach { r ->
                ListRow(listOf(r.optString("client_name"), r.optString("status") + ", " + whenText(r.str("created_at")),
                    listOfNotNull(r.str("reason"), r.str("counselor_agency"), r.str("assigned_name")?.let { "assigned to $it" } ?: "unassigned").joinToString(", ")),
                    onClick = { nav.navigate("referral/${r.optInt("id")}") })
            }
        }
    }
}

/** Conversations with clients (and their counselors) through the client portal. */
@Composable
fun MessagesScreen(api: Api, nav: NavHostController) {
    val state = rememberLoaded(api.userId) { api.call("message_threads").optJSONArray("threads").objects() }
    val clients = rememberLoaded(api.userId) {
        api.call("client_choices").optJSONArray("clients").objects().filter { !it.optString("status").startsWith("Closed") }
    }
    Show(state, "messages") { list ->
        Page {
            // A new conversation: any client's thread opens from here.
            val choices = clients.data ?: emptyList()
            if (choices.isNotEmpty()) Choice("Start a conversation with", "", listOf("" to "Choose a client") + choices.map { it.optInt("id").toString() to it.optString("name") }) { id ->
                if (id.isNotEmpty()) nav.navigate("thread/$id")
            }
            Heading("Conversations (${list.size})")
            if (list.isEmpty()) Text("No messages.")
            list.forEach { t ->
                val unread = t.optInt("unread")
                ListRow(listOf(t.optString("client_name"), (if (unread > 0) "$unread unread, " else "") + "last " + whenText(t.str("last_at"))),
                    onClick = { nav.navigate("thread/${t.optInt("client_id")}") })
            }
        }
    }
}

/** A file going with a reply: one of the client's documents, or a new one from the phone. */
private sealed class Attachment {
    abstract val name: String
    data class Doc(val id: Int, override val name: String) : Attachment()
    data class NewFile(val uri: android.net.Uri, override val name: String) : Attachment()
}

@Composable
fun ThreadScreen(api: Api, clientId: Int) {
    val context = androidx.compose.ui.platform.LocalContext.current
    var channel by rememberSaveable { mutableStateOf("client") }
    val state = rememberLoaded(clientId, channel) { api.learn425(clientId); api.call("message_thread", mapOf("client_id" to clientId.toString(), "channel" to channel)) }
    var text by rememberSaveable { mutableStateOf("") }
    var busy by remember { mutableStateOf(false) }
    var opening by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    var notice by remember { mutableStateOf<String?>(null) }
    var byText by rememberSaveable { mutableStateOf(false) }
    var attach by remember { mutableStateOf<Attachment?>(null) }
    var choosingDoc by remember { mutableStateOf(false) }
    // One key per message typed, so a send retried after a lost reply is posted (and texted) once.
    var sendKey by rememberSaveable { mutableStateOf(java.util.UUID.randomUUID().toString()) }
    val scope = rememberCoroutineScope()
    val pick = androidx.activity.compose.rememberLauncherForActivityResult(androidx.activity.result.contract.ActivityResultContracts.OpenDocument()) { uri ->
        if (uri != null) {
            val name = pickedName(context, uri)
            if (name.substringAfterLast('.', "").lowercase() !in UPLOAD_TYPES) error = "That file type is not allowed. Choose a PDF, Word, Excel, PowerPoint, text, CSV or picture file."
            else { attach = Attachment.NewFile(uri, name); error = null; notice = "$name attached." }
        }
    }
    Show(state, "the conversation") { r ->
        Page {
            Heading(r.optString("client_name"), large = true)
            Choice("With", channel, listOfNotNull("client" to "The client", r.str("counselor_name")?.let { "counselor" to "The counselor, $it" })) { channel = it }
            r.optJSONArray("messages").objects().forEach { m ->
                val who = m.str("staff_name") ?: when (m.optString("sender")) { "client" -> r.optString("client_name"); "counselor" -> r.str("counselor_name") ?: "The counselor"; else -> "Staff" }
                val file = messageFileLabel(m)
                ListRow(listOfNotNull(who + ", " + whenText(m.str("created_at")) + (if (m.str("via") == "text") ", by text" else ""), m.optString("body"), file),
                    action = if (file != null) "open the file" else null,
                    onClick = if (file == null) null else ({
                        if (!opening) {
                            opening = true; error = null
                            scope.launch {
                                try { openDocument(api, context, m.optInt("document_id"))?.let { error = it } } catch (e: ApiException) { error = e.message } finally { opening = false }
                            }
                        }
                    }))
            }
            NoticeText(notice)
            Field("Your message", text, { text = it }, hint = if (attach != null) "optional with a file" else null, singleLine = false, minLines = 3)
            val a = attach
            // Files with messages need a 4.25.0 server (an older one would drop the file unsaid).
            if (a == null && api.server425 == true) {
                SecondaryButton("Choose one of their documents") { choosingDoc = true }
                SecondaryButton("Add a new file") { pick.launch(arrayOf("*/*")) }
            } else if (a != null) {
                Text("Attached: ${a.name}. It is shared with " + (if (channel == "client") "the client" else "the counselor") + " when you send.")
                SecondaryButton("Remove the attachment") { attach = null; notice = "Attachment removed." }
            }
            // Texts (4.17.0): offered when the client agreed to them; otherwise why not.
            val textable = channel == "client" && r.optBoolean("text_enabled")
            if (textable && r.str("text_blocked") == null) Tick("Also send it as a text message", byText) { byText = it }
            else if (textable) Text("Not by text: " + r.optString("text_blocked"))
            ErrorText(error)
            PrimaryButton("Send", busy = busy, enabled = text.isNotBlank() || attach != null) {
                scope.launchSave({ busy = it }, { error = it }) {
                    val body = JSONObject().put("client_id", clientId).put("channel", channel).put("body", text.trim())
                        .put("by_text", byText && channel == "client" && r.str("text_blocked") == null).put("idem_key", sendKey)
                    val res = when (val at = attach) {
                        null -> api.call("post_message", body = body)
                        is Attachment.Doc -> api.call("post_message", body = body.put("document_id", at.id))
                        is Attachment.NewFile -> {
                            val tmp = java.io.File(context.cacheDir, "message.tmp")
                            try {
                                checkCopied(copyPicked(context, at.uri, tmp))
                                val short = shortName(at.name)
                                try {
                                    api.postWithFile("post_message", body.put("filename", short), "file_b64", tmp, "The message was not sent.")
                                } catch (e: ApiException) {
                                    if (e.network) throw ApiException("The connection dropped before the server answered, so it isn't known whether the message was sent. Check the conversation for “$short” before sending it again.")
                                    throw e
                                }
                            } finally { tmp.delete() }
                        }
                    }
                    text = ""
                    byText = false
                    attach = null
                    sendKey = java.util.UUID.randomUUID().toString()
                    notice = res.str("warning") ?: "Message sent."
                    state.reload()
                }
            }
        }
    }
    if (choosingDoc) {
        val docs = rememberLoaded(clientId) { api.call("documents", mapOf("client_id" to clientId.toString())).optJSONArray("documents").objects() }
        AlertDialog(
            onDismissRequest = { choosingDoc = false },
            title = { Text("Choose one of their documents", modifier = Modifier.semantics { heading() }) },
            text = {
                Column(Modifier.verticalScroll(rememberScrollState())) {
                    if (docs.loading) Text("Loading documents…")
                    ErrorText(docs.error)
                    val list = docs.data ?: emptyList()
                    if (!docs.loading && list.isEmpty() && docs.error == null) Text("This client has no documents yet. Use Add a new file instead.")
                    list.forEach { d ->
                        val name = d.optString("title").ifEmpty { d.optString("orig_name") }
                        ListRow(listOf(name, d.optString("category") + ", " + whenText(d.str("created_at"))), action = "attach it", onClick = {
                            attach = Attachment.Doc(d.optInt("id"), name); choosingDoc = false; error = null; notice = "$name attached."
                        })
                    }
                }
            },
            confirmButton = { TextButton(onClick = { choosingDoc = false }) { Text("Cancel") } },
        )
    }
}
