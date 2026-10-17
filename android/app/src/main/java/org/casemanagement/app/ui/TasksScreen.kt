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

@Composable
fun ReferralScreen(api: Api, id: Int) {
    val state = rememberLoaded(id) { api.call("referral", mapOf("id" to id.toString())) }
    var notice by remember { mutableStateOf<String?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    val scope = rememberCoroutineScope()
    Show(state, "the referral") { r ->
        val ref = r.optJSONObject("referral") ?: return@Show
        Page {
            Heading(ref.optString("client_name"), large = true)
            NoticeText(notice)
            ErrorText(error)
            ListRow(listOf("Status: " + ref.optString("status")))
            ListRow(listOf("Received " + whenText(ref.str("created_at"))))
            ref.str("reference")?.let { ListRow(listOf("Reference: $it")) }
            ref.str("counselor_name")?.let { ListRow(listOf("From: $it", ref.str("counselor_agency") ?: "", ref.str("counselor_email") ?: "", ref.str("counselor_phone") ?: "")) }
            listOfNotNull(ref.str("client_phone")?.let { "Phone: $it" }, ref.str("client_email")?.let { "Email: $it" }).forEach { ListRow(listOf(it)) }
            ref.str("assigned_name")?.let { ListRow(listOf("Assigned to $it")) }
            Heading("The referral")
            Text(ref.optString("body"))
            if (ref.optString("status") == "New") PrimaryButton("Mark as reviewed") {
                scope.launch {
                    try { api.call("review_referral", body = JSONObject().put("id", id)); notice = "Marked as reviewed."; state.reload() }
                    catch (e: ApiException) { error = e.message }
                }
            }
            Text("Converting a referral to a client, assigning and closing it are on the website for now.")
        }
    }
}

/** Conversations with clients (and their counselors) through the client portal. */
@Composable
fun MessagesScreen(api: Api, nav: NavHostController) {
    val state = rememberLoaded(api.userId) { api.call("message_threads").optJSONArray("threads").objects() }
    Show(state, "messages") { list ->
        Page {
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

@Composable
fun ThreadScreen(api: Api, clientId: Int) {
    var channel by rememberSaveable { mutableStateOf("client") }
    val state = rememberLoaded(clientId, channel) { api.call("message_thread", mapOf("client_id" to clientId.toString(), "channel" to channel)) }
    var text by rememberSaveable { mutableStateOf("") }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    var notice by remember { mutableStateOf<String?>(null) }
    val scope = rememberCoroutineScope()
    Show(state, "the conversation") { r ->
        Page {
            Heading(r.optString("client_name"), large = true)
            Choice("With", channel, listOfNotNull("client" to "The client", r.str("counselor_name")?.let { "counselor" to "The counselor, $it" })) { channel = it }
            r.optJSONArray("messages").objects().forEach { m ->
                ListRow(listOf((m.str("staff_name") ?: m.optString("sender")) + ", " + whenText(m.str("created_at")), m.optString("body")))
            }
            NoticeText(notice)
            Field("Your message", text, { text = it }, singleLine = false, minLines = 3)
            ErrorText(error)
            PrimaryButton("Send", busy = busy, enabled = text.isNotBlank()) {
                scope.launchSave({ busy = it }, { error = it }) {
                    val res = api.call("post_message", body = JSONObject().put("client_id", clientId).put("channel", channel).put("body", text.trim()))
                    text = ""
                    notice = res.str("warning") ?: "Message sent."
                    state.reload()
                }
            }
        }
    }
}
