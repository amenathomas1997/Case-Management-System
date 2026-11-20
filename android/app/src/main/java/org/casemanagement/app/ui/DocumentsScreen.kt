package org.casemanagement.app.ui

import android.content.Intent
import android.webkit.MimeTypeMap
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
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
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.semantics
import androidx.core.content.FileProvider
import kotlinx.coroutines.launch
import org.casemanagement.app.Api
import org.casemanagement.app.ApiException
import org.casemanagement.app.DocumentItem
import org.casemanagement.app.HomeworkItem
import org.casemanagement.app.parseDocuments
import org.casemanagement.app.parseHomework
import org.casemanagement.app.parseTracks
import org.casemanagement.app.sharedWords
import org.casemanagement.app.str
import org.json.JSONObject

/** A client's documents (opened in another app) and homework (complete it, or assign new). */
@Composable
fun DocumentsScreen(api: Api, clientId: Int) {
    val context = LocalContext.current
    val state = rememberLoaded(clientId) {
        api.learn425(clientId)
        val docs = parseDocuments(api.call("documents", mapOf("client_id" to clientId.toString())))
        val hw = parseHomework(api.call("homework", mapOf("client_id" to clientId.toString())))
        docs to hw
    }
    var notice by remember { mutableStateOf<String?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    var busy by remember { mutableStateOf(false) }
    var completing by remember { mutableStateOf<HomeworkItem?>(null) }
    // 4.35.0: an assigned item's choices (complete it or edit it), and the one being edited (kept across rotation).
    var hwChosen by remember { mutableStateOf<HomeworkItem?>(null) }
    var editingId by rememberSaveable { androidx.compose.runtime.mutableIntStateOf(0) }
    var assigning by rememberSaveable { mutableStateOf(false) }
    var uploading by rememberSaveable { mutableStateOf(false) }
    var chosen by remember { mutableStateOf<DocumentItem?>(null) }
    val scope = rememberCoroutineScope()
    fun share(d: DocumentItem, with: String, on: Boolean) {
        chosen = null; error = null; notice = null
        scope.launch {
            try {
                api.call("share_document", body = JSONObject().put("client_id", clientId).put("document_id", d.id).put("with", with).put("on", on))
                val title = d.title
                notice = when {
                    with == "portal" && on -> "$title is shared with the client. It shows in their portal."
                    with == "portal" -> "$title is no longer shared with the client."
                    on -> "$title is shared with the counselor. It shows in the counselor portal."
                    else -> "$title is no longer shared with the counselor."
                }
                state.reload()
            } catch (e: ApiException) { error = e.message }
        }
    }
    Show(state, "documents and homework") { (docs, hw) ->
        Page {
            NoticeText(notice)
            ErrorText(error)
            Heading("Documents (${docs.size})")
            if (api.can("clients")) SecondaryButton("Upload a document") { uploading = true }
            if (docs.isEmpty()) Text("No documents.")
            docs.forEach { d ->
                ListRow(listOf(d.title, d.category + ", " + whenText(d.createdAt), sharedWords(d)),
                    action = if (api.server425 == true) "open it or share it" else "open it", onClick = {
                        // Sharing needs a 4.25.0 server; on an older one the row opens the document as before.
                        if (api.server425 == true) chosen = d
                        else if (!busy) {
                            error = null; busy = true
                            scope.launch { try { openDocument(api, context, d.id)?.let { error = it } } catch (e: ApiException) { error = e.message } finally { busy = false } }
                        }
                    })
            }
            val queued = api.ownQueued().filter { it.optString("action") == "complete_homework" }.map { try { JSONObject(it.optString("body")).optInt("id") } catch (e: Exception) { 0 } }.toSet()
            Heading("Homework (${hw.size})")
            if (hw.isEmpty()) Text("No homework.")
            hw.forEach { h ->
                val waiting = h.id in queued
                val status = if (waiting) "Completed, waiting to send" else h.status
                ListRow(listOf(h.title, listOfNotNull(status, h.dueDate?.let { "due " + dayText(it) }, h.trackTitle).joinToString(", "), h.instructions ?: ""),
                    action = if (api.can("clients")) "complete it or edit it" else "mark it completed",
                    onClick = if (h.status == "Assigned" && !waiting) ({ if (api.can("clients")) hwChosen = h else completing = h }) else null)
            }
            if (api.can("clients")) SecondaryButton("Assign homework") { assigning = true }
        }
    }
    completing?.let { h ->
        Confirm("Mark “${h.title}” completed?", "It disappears from the client portal.", "Mark completed", {
            scope.launch {
                try { notice = saveOrQueue(api, "complete_homework", JSONObject().put("id", h.id), "Homework done: " + h.title.take(40), "Homework marked completed.").message; state.reload() }
                catch (e: ApiException) { error = e.message }
            }
        }, { completing = null })
    }
    hwChosen?.let { h ->
        AlertDialog(
            onDismissRequest = { hwChosen = null },
            title = { Text(h.title, modifier = Modifier.semantics { heading() }) },
            text = {
                Column {
                    TextButton(onClick = { hwChosen = null; completing = h }) { Text("Mark it completed") }
                    TextButton(onClick = { hwChosen = null; editingId = h.id }) { Text("Edit homework: ${h.title}") }
                }
            },
            confirmButton = { TextButton(onClick = { hwChosen = null }) { Text("Close") } },
        )
    }
    state.data?.second?.firstOrNull { editingId != 0 && it.id == editingId }?.let { h ->
        androidx.compose.runtime.key(org.casemanagement.app.dialogKey("homework-edit", editingId)) {
            EditHomeworkDialog(api, clientId, h, onDone = { m -> editingId = 0; notice = m; state.reload() }, onCancel = { editingId = 0 })
        }
    }
    chosen?.let { d ->
        val portal = d.portalShared
        val counselor = d.counselorShared
        // A Resource, or a file the client sent, is always in their portal.
        val portalFixed = d.portalFixed
        AlertDialog(
            onDismissRequest = { chosen = null },
            title = { Text(d.title, modifier = Modifier.semantics { heading() }) },
            text = {
                Column {
                    Text(sharedWords(d) + ".")
                    TextButton(onClick = {
                        chosen = null
                        if (busy) return@TextButton
                        error = null; busy = true
                        scope.launch {
                            try { openDocument(api, context, d.id)?.let { error = it } } catch (e: ApiException) { error = e.message } finally { busy = false }
                        }
                    }) { Text("Open it") }
                    if (api.can("clients")) {
                        if (!portalFixed) TextButton(onClick = { share(d, "portal", !portal) }) { Text(if (portal) "Stop sharing with the client" else "Share with the client") }
                        TextButton(onClick = { share(d, "counselor", !counselor) }) { Text(if (counselor) "Stop sharing with the counselor" else "Share with the counselor") }
                    }
                }
            },
            confirmButton = { TextButton(onClick = { chosen = null }) { Text("Close") } },
        )
    }
    if (uploading) UploadDocumentDialog(api, clientId, onDone = { m -> uploading = false; notice = m; state.reload() }, onCancel = { uploading = false })
    if (assigning) AssignHomeworkDialog(api, clientId, onDone = { m -> assigning = false; notice = m; state.reload() }, onCancel = { assigning = false })
}

/**
 * Downloads a client's document and opens it in another app (through the app's FileProvider).
 * Returns why it could not be opened, or null.
 */
suspend fun openDocument(api: Api, context: android.content.Context, id: Int): String? {
    val f = api.download(id, api.docsFolder)
    val uri = FileProvider.getUriForFile(context, context.packageName + ".files", f)
    val type = MimeTypeMap.getSingleton().getMimeTypeFromExtension(f.extension.lowercase()) ?: "application/octet-stream"
    val i = Intent(Intent.ACTION_VIEW).setDataAndType(uri, type).addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION)
    return if (!openLink(context, Intent.createChooser(i, "Open with"))) "No app on this phone can open this kind of file." else null
}

@Composable
private fun AssignHomeworkDialog(api: Api, clientId: Int, onDone: (String) -> Unit, onCancel: () -> Unit) {
    val tracks = rememberLoaded(clientId) { parseTracks(api.call("tracks")).filter { it.clientId == clientId } }
    var track by rememberSaveable { mutableStateOf("") }
    var title by rememberSaveable { mutableStateOf("") }
    var instructions by rememberSaveable { mutableStateOf("") }
    var due by rememberSaveable { mutableStateOf("") }
    var idem by rememberSaveable { mutableStateOf("") }
    var error by remember { mutableStateOf<String?>(null) }
    var dueError by remember { mutableStateOf<String?>(null) }
    var trackError by remember { mutableStateOf<String?>(null) }
    var titleError by remember { mutableStateOf<String?>(null) }
    var busy by remember { mutableStateOf(false) }
    var presses by remember { androidx.compose.runtime.mutableIntStateOf(0) }
    val scope = rememberCoroutineScope()
    AlertDialog(
        onDismissRequest = onCancel,
        title = { Text("Assign homework", modifier = Modifier.semantics { heading() }) },
        text = {
            Column(Modifier.verticalScroll(rememberScrollState())) {
                val list = tracks.data ?: emptyList()
                if (!tracks.loading && list.isEmpty()) Text("This client has no active training track, so homework cannot be assigned yet.")
                if (list.isNotEmpty()) Choice("Training track", track, listOf("" to "Choose a track") + list.map { it.id.toString() to it.title }, error = trackError, required = true) { track = it }
                Field("Title", title, { title = it }, required = true, error = titleError)
                Field("Instructions", instructions, { instructions = it }, hint = "optional", singleLine = false, minLines = 3)
                Field("Due date", due, { due = it }, hint = "year-month-day, optional", error = dueError)
                ErrorText(error, focusKey = presses)
            }
        },
        confirmButton = {
            TextButton(enabled = !busy && (tracks.data ?: emptyList()).isNotEmpty(), onClick = {
                if (busy) return@TextButton
                presses++
                // 4.30.0: every problem at once, each on its field and all in the summary.
                trackError = if (track.isEmpty()) "Choose a training track." else null
                titleError = if (title.isBlank()) "Give the homework a title." else null
                dueError = if (due.isNotBlank() && !realDate(due)) "Due date must be a valid date (YYYY-MM-DD)." else null
                errorSummary(listOf(trackError, titleError, dueError))?.let { error = it; return@TextButton }
                scope.launchSave({ busy = it }, { error = it }) {
                    // 4.28.0: a retry after a lost reply assigns it once (see sendOnce).
                    api.callOnce("assign_homework", JSONObject().put("track_id", track.toInt()).put("title", title.trim()).put("instructions", instructions.trim()).put("due_date", due.trim()),
                        idem, { idem = it }, "the homework")
                    onDone("Homework assigned. It shows in the client portal.")
                }
            }) { Text(if (busy) "Saving…" else "Assign") }
        },
        dismissButton = { TextButton(onClick = onCancel) { Text("Cancel") } },
    )
}

/**
 * 4.35.0: edit an assigned homework item (title, instructions, due date), as the website and the
 * iPhone app (at_homework_update with its edit token). Changing the title or instructions clears
 * the client's report that it is done, and the Save question says so.
 */
@Composable
private fun EditHomeworkDialog(api: Api, clientId: Int, h: HomeworkItem, onDone: (String) -> Unit, onCancel: () -> Unit) {
    var title by rememberSaveable { mutableStateOf(h.title) }
    var instructions by rememberSaveable { mutableStateOf(h.instructions ?: "") }
    var due by rememberSaveable { mutableStateOf(h.dueDate?.take(10) ?: "") }
    // The version the form was filled from (replaced by Reload).
    var token by rememberSaveable { mutableStateOf(h.editToken ?: "") }
    var baseTitle by rememberSaveable { mutableStateOf(h.title) }
    var baseInstructions by rememberSaveable { mutableStateOf(h.instructions ?: "") }
    var reported by rememberSaveable { mutableStateOf(h.clientReportedAt != null) }
    var kept by rememberSaveable { mutableStateOf<String?>(null) }
    var stale by remember { mutableStateOf(false) }
    var titleError by remember { mutableStateOf<String?>(null) }
    var dueError by remember { mutableStateOf<String?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    var asking by remember { mutableStateOf(false) }
    var busy by remember { mutableStateOf(false) }
    var presses by remember { androidx.compose.runtime.mutableIntStateOf(0) }
    val scope = rememberCoroutineScope()
    val textChanged = title.trim() != baseTitle.trim() || instructions.trim() != baseInstructions.trim()
    FormDialog("Edit homework: ${h.title}", "Save", busy, onSave = {
        if (busy) return@FormDialog
        presses++
        titleError = if (title.isBlank()) "Title: give the homework a title." else null
        dueError = if (due.isNotBlank() && !realDate(due)) "Use a valid due date with a four-digit year from 2000 to ${currentYear() + 5}." else null
        error = errorSummary(listOf(titleError, dueError))
        if (error == null) asking = true
    }, onCancel = onCancel) {
        if (reported) Text("The client reported this done. Changing the title or instructions clears that report; changing only the due date keeps it.")
        Field("Title", title, { title = it }, required = true, error = titleError)
        Field("Instructions", instructions, { instructions = it }, hint = "optional", singleLine = false, minLines = 3)
        Field("Due date", due, { due = it }, hint = "optional, year-month-day", error = dueError)
        ErrorText(error, focusKey = presses)
        if (stale) SecondaryButton(if (busy) "Reloading…" else "Reload") {
            if (busy) return@SecondaryButton
            busy = true
            scope.launch {
                try {
                    val fresh = parseHomework(api.call("homework", mapOf("client_id" to clientId.toString()))).firstOrNull { it.id == h.id }
                        ?: throw ApiException("This assignment is no longer listed for the client.")
                    kept = listOfNotNull("Title: " + title.trim(), instructions.trim().takeIf { it.isNotEmpty() }?.let { "Instructions: $it" },
                        due.trim().takeIf { it.isNotEmpty() }?.let { "Due date: $it" }).joinToString("\n")
                    title = fresh.title; instructions = fresh.instructions ?: ""; due = fresh.dueDate?.take(10) ?: ""
                    token = fresh.editToken ?: ""; baseTitle = fresh.title; baseInstructions = fresh.instructions ?: ""; reported = fresh.clientReportedAt != null
                    stale = false; error = null
                } catch (e: ApiException) { presses++; error = e.message } finally { busy = false }
            }
        }
        kept?.let { k ->
            Heading("Your text, not saved")
            androidx.compose.foundation.text.selection.SelectionContainer { Text(k) }
        }
    }
    if (asking) Confirm(homeworkEditConfirm(h.title, reported, textChanged), "It shows in the client portal as changed.", "Save the changes", {
        scope.launchSave({ busy = it }, { }) {
            try {
                api.call("at_homework_update", body = homeworkEditBody(h.id, title, instructions, due, token))
                onDone("Homework updated.")
            } catch (e: ApiException) {
                presses++
                when (homeworkEditErrorField(e.message)) {
                    "title" -> titleError = e.message
                    "due" -> dueError = e.message
                    "reload" -> stale = true
                }
                error = e.message
            }
        }
    }, { asking = false })
}
