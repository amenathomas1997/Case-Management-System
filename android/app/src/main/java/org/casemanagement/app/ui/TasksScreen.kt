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
import org.casemanagement.app.TaskItem
import org.casemanagement.app.dueWords
import org.casemanagement.app.openClientChoices
import org.casemanagement.app.parseDocuments
import org.casemanagement.app.parseMessageThread
import org.casemanagement.app.parseMessageThreads
import org.casemanagement.app.parseNotifications
import org.casemanagement.app.parseReferrals
import org.casemanagement.app.parseTasks
import org.casemanagement.app.sendOnce
import org.json.JSONObject
import java.util.UUID

/** Your open tasks, soonest first; complete one (kept offline when there is no signal) or add one. */
@Composable
fun TasksScreen(api: Api, nav: NavHostController) {
    var show by rememberSaveable { mutableStateOf("open") }
    val state = rememberLoaded(show) { api.call("tasks", mapOf("show" to show)) }
    var chosen by remember { mutableStateOf<TaskItem?>(null) }
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
        val tasks = parseTasks(r)
        Page {
            NoticeText(notice)
            ErrorText(error)
            PrimaryButton("Add a task") { adding = true }
            Choice("Show", show, listOf("open" to "Open tasks", "all" to "All tasks, including done")) { show = it }
            Heading("${if (show == "open") "Open tasks" else "All tasks"} (${tasks.size})")
            if (tasks.isEmpty()) Text("No tasks.")
            tasks.forEach { t ->
                val id = t.id
                val status = queued[id]?.let { reopen -> if (reopen) "Reopened, waiting to send" else "Done, waiting to send" } ?: t.status
                ListRow(listOf(t.title, listOfNotNull(status, dueWords(t.daysLeft), t.clientName, t.assignedName?.let { "for $it" }).joinToString(", ")),
                    action = "see what you can do", onClick = if (id in queued) null else ({ chosen = t }))
            }
        }
    }

    chosen?.let { t ->
        val open = t.open
        AlertDialog(
            onDismissRequest = { chosen = null },
            title = { Text(t.title, modifier = Modifier.semantics { heading() }) },
            text = {
                Column {
                    t.details?.let { Text(it) }
                    TextButton(onClick = {
                        chosen = null; error = null
                        scope.launch {
                            try {
                                val body = JSONObject().put("id", t.id).put("idem_key", UUID.randomUUID().toString())
                                if (!open) body.put("reopen", true)
                                val s = saveOrQueue(api, "complete_task", body, (if (open) "Task done: " else "Reopen task: ") + t.title.take(40),
                                    if (open) "Task completed." else "Task reopened.")
                                notice = s.message
                                if (s.reply != null) state.reload()
                            } catch (e: ApiException) { error = e.message }
                        }
                    }) { Text(if (open) "Mark as done" else "Reopen") }
                    if (t.canOpenClient && t.clientId != null) {
                        TextButton(onClick = { chosen = null; nav.navigate("client/${t.clientId}") }) { Text("Open " + (t.clientName ?: "the client")) }
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
    // Kept across rotation and process death (4.28.0).
    var title by rememberSaveable { mutableStateOf("") }
    var due by rememberSaveable { mutableStateOf("") }
    var details by rememberSaveable { mutableStateOf("") }
    var idem by rememberSaveable { mutableStateOf("") }
    var error by remember { mutableStateOf<String?>(null) }
    var dueError by remember { mutableStateOf<String?>(null) }
    var titleError by remember { mutableStateOf<String?>(null) }
    var busy by remember { mutableStateOf(false) }
    var presses by remember { androidx.compose.runtime.mutableIntStateOf(0) }
    val scope = rememberCoroutineScope()
    AlertDialog(
        onDismissRequest = onCancel,
        title = { Text("Add a task", modifier = Modifier.semantics { heading() }) },
        text = {
            Column(Modifier.verticalScroll(rememberScrollState())) {
                Field("Task", title, { title = it }, required = true, error = titleError)
                Field("Due date", due, { due = it }, hint = "optional, year-month-day such as ${today()}", error = dueError)
                Field("Details", details, { details = it }, hint = "optional", singleLine = false, minLines = 3)
                ErrorText(error, focusKey = presses)
            }
        },
        confirmButton = {
            TextButton(enabled = !busy, onClick = {
                presses++
                titleError = if (title.isBlank()) "Name the task." else null
                dueError = if (due.isNotBlank() && !Regex("^\\d{4}-\\d{2}-\\d{2}$").matches(due.trim())) "Enter the due date as year-month-day, such as ${today()}, or leave it empty." else null
                errorSummary(listOf(titleError, dueError))?.let { error = it; return@TextButton }
                scope.launchSave({ busy = it }, { error = it }) {
                    val r = api.callOnce("save_task", JSONObject().put("title", title.trim()).put("due_date", due.trim()).put("details", details.trim()), idem, { idem = it }, "the task")
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
    val state = rememberLoaded(api.userId) { parseNotifications(api.call("notifications")) }
    var notice by remember { mutableStateOf<String?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    val scope = rememberCoroutineScope()
    Show(state, "alerts") { r ->
        val list = r.items
        Page {
            NoticeText(notice)
            ErrorText(error)
            val unread = r.unread
            if (unread > 0) PrimaryButton("Mark all $unread as read") {
                scope.launch {
                    try { notice = api.call("mark_notifications_read", body = JSONObject().put("all", true)).optString("message").ifEmpty { "All marked as read." }; state.reload() }
                    catch (e: ApiException) { error = e.message }
                }
            }
            Heading("Alerts (${list.size})")
            if (list.isEmpty()) Text("No alerts.")
            list.forEach { n -> ListRow(listOf((if (n.unread) "Unread: " else "") + n.message, whenText(n.createdAt))) }
        }
    }
}

/** Referrals, open first, as on the website's Referrals page. */
@Composable
fun ReferralsScreen(api: Api, nav: NavHostController) {
    var status by rememberSaveable { mutableStateOf("open") }
    val state = rememberLoaded(status) { parseReferrals(api.call("referrals", mapOf("status" to status))) }
    Page {
        Choice("Show", status, listOf("open" to "Open referrals", "converted" to "Converted to clients", "closed" to "Closed", "all" to "All")) { status = it }
        Show(state, "referrals") { list ->
            Heading("Referrals (${list.size})")
            if (list.isEmpty()) Text("No referrals.")
            list.forEach { r ->
                ListRow(listOf(r.clientName, r.status + ", " + whenText(r.createdAt),
                    listOfNotNull(r.reason, r.counselorAgency, r.assignedName?.let { "assigned to $it" } ?: "unassigned").joinToString(", ")),
                    onClick = { nav.navigate("referral/${r.id}") })
            }
        }
    }
}

/** Conversations with clients (and their counselors) through the client portal. */
@Composable
fun MessagesScreen(api: Api, nav: NavHostController) {
    val state = rememberLoaded(api.userId) { parseMessageThreads(api.call("message_threads")) }
    val clients = rememberLoaded(api.userId) { openClientChoices(api.call("client_choices")) }
    Show(state, "messages") { list ->
        Page {
            // A new conversation: any client's thread opens from here.
            val choices = clients.data ?: emptyList()
            if (choices.isNotEmpty()) Choice("Start a conversation with", "", listOf("" to "Choose a client") + choices.map { it.id.toString() to it.name }) { id ->
                if (id.isNotEmpty()) nav.navigate("thread/$id")
            }
            Heading("Conversations (${list.size})")
            if (list.isEmpty()) Text("No messages.")
            list.forEach { t ->
                val unread = t.unread
                ListRow(listOf(t.clientName, (if (unread > 0) "$unread unread, " else "") + "last " + whenText(t.lastAt)),
                    onClick = { nav.navigate("thread/${t.clientId}") })
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
    // One key per message as it is (4.28.0: channel, text, text-message choice and attachment), so a send
    // retried after a lost reply is posted (and texted) once, and an edited one is a new message (see sendOnce).
    var sendKey by rememberSaveable { mutableStateOf("") }
    val scope = rememberCoroutineScope()
    val pick = androidx.activity.compose.rememberLauncherForActivityResult(androidx.activity.result.contract.ActivityResultContracts.OpenDocument()) { uri ->
        if (uri != null) {
            val name = pickedName(context, uri)
            if (name.substringAfterLast('.', "").lowercase() !in UPLOAD_TYPES) error = "That file type is not allowed. Choose a PDF, Word, Excel, PowerPoint, text, CSV or picture file."
            else { attach = Attachment.NewFile(uri, name); error = null; notice = "$name attached." }
        }
    }
    Show(state, "the conversation") { raw ->
        val r = parseMessageThread(raw)
        Page {
            Heading(r.clientName, large = true)
            Choice("With", channel, listOfNotNull("client" to "The client", r.counselorName?.let { "counselor" to "The counselor, $it" })) { channel = it }
            r.messages.forEach { m ->
                val file = m.fileLabel
                ListRow(listOfNotNull(m.who + ", " + whenText(m.createdAt) + (if (m.byText) ", by text" else ""), m.body, file),
                    action = if (file != null) "open the file" else null,
                    onClick = if (file == null) null else ({
                        if (!opening) {
                            opening = true; error = null
                            scope.launch {
                                try { openDocument(api, context, m.documentId ?: 0)?.let { error = it } } catch (e: ApiException) { error = e.message } finally { opening = false }
                            }
                        }
                    }))
            }
            NoticeText(notice)
            Field("Your message", text, { text = it }, hint = if (attach != null) "optional with a file" else null, singleLine = false, minLines = 3, required = attach == null)
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
            val textable = channel == "client" && r.textEnabled
            if (textable && r.textBlocked == null) Tick("Also send it as a text message", byText) { byText = it }
            else if (textable) Text("Not by text: " + r.textBlocked)
            ErrorText(error)
            PrimaryButton("Send", busy = busy, missing = if (text.isBlank() && attach == null) "Still needed: a message or a file." else null) {
                if (busy) return@PrimaryButton
                scope.launchSave({ busy = it }, { error = it }) {
                    val body = JSONObject().put("client_id", clientId).put("channel", channel).put("body", text.trim())
                        .put("by_text", byText && channel == "client" && r.textBlocked == null)
                    val at = attach
                    val print = "post_message|" + body.toString() + "|" + when (at) {
                        null -> ""
                        is Attachment.Doc -> "doc:${at.id}"
                        is Attachment.NewFile -> "file:${at.uri}:${at.name}"
                    }
                    val res = when (at) {
                        null -> sendOnce(sendKey, { sendKey = it }, print, "the message") { k -> api.call("post_message", body = body.put("idem_key", k)) }
                        is Attachment.Doc -> sendOnce(sendKey, { sendKey = it }, print, "the message") { k -> api.call("post_message", body = body.put("document_id", at.id).put("idem_key", k)) }
                        is Attachment.NewFile -> {
                            val tmp = java.io.File(api.docsFolder, "message.tmp")   // emptied at sign-out and a wipe (4.27.0)
                            try {
                                checkCopied(copyPicked(context, at.uri, tmp))
                                val short = shortName(at.name)
                                sendOnce(sendKey, { sendKey = it }, print + ":" + tmp.length(), "the message with “$short”") { k ->
                                    api.postWithFile("post_message", body.put("filename", short).put("idem_key", k), "file_b64", tmp, "The message was not sent.")
                                }
                            } finally { tmp.delete() }
                        }
                    }
                    text = ""
                    byText = false
                    attach = null
                    sendKey = ""
                    // A repeat the server recognised (an earlier try whose reply was lost) is said as such.
                    notice = res.str("warning") ?: if (res.optBoolean("repeat")) "This message had already been sent from an earlier try, so it was not sent twice." else "Message sent."
                    state.reload()
                }
            }
        }
    }
    if (choosingDoc) {
        val docs = rememberLoaded(clientId) { parseDocuments(api.call("documents", mapOf("client_id" to clientId.toString()))) }
        AlertDialog(
            onDismissRequest = { choosingDoc = false },
            title = { Text("Choose one of their documents", modifier = Modifier.semantics { heading() }) },
            text = {
                Column(Modifier.verticalScroll(rememberScrollState())) {
                    if (docs.loading) Text("Loading documents…")
                    ErrorText(docs.error, takeFocus = false)
                    val list = docs.data ?: emptyList()
                    if (!docs.loading && list.isEmpty() && docs.error == null) Text("This client has no documents yet. Use Add a new file instead.")
                    list.forEach { d ->
                        val name = d.title
                        ListRow(listOf(name, d.category + ", " + whenText(d.createdAt)), action = "attach it", onClick = {
                            attach = Attachment.Doc(d.id, name); choosingDoc = false; error = null; notice = "$name attached."
                        })
                    }
                }
            },
            confirmButton = { TextButton(onClick = { choosingDoc = false }) { Text("Cancel") } },
        )
    }
}
