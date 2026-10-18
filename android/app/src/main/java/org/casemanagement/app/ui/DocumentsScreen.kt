package org.casemanagement.app.ui

import android.content.Intent
import android.webkit.MimeTypeMap
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
    var assigning by remember { mutableStateOf(false) }
    var uploading by remember { mutableStateOf(false) }
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
                    action = "mark it completed", onClick = if (h.status == "Assigned" && !waiting) ({ completing = h }) else null)
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
    var track by remember { mutableStateOf("") }
    var title by remember { mutableStateOf("") }
    var instructions by remember { mutableStateOf("") }
    var due by remember { mutableStateOf("") }
    var error by remember { mutableStateOf<String?>(null) }
    var busy by remember { mutableStateOf(false) }
    val scope = rememberCoroutineScope()
    AlertDialog(
        onDismissRequest = onCancel,
        title = { Text("Assign homework", modifier = Modifier.semantics { heading() }) },
        text = {
            Column(Modifier.verticalScroll(rememberScrollState())) {
                val list = tracks.data ?: emptyList()
                if (!tracks.loading && list.isEmpty()) Text("This client has no active training track, so homework cannot be assigned yet.")
                if (list.isNotEmpty()) Choice("Training track", track, listOf("" to "Choose a track") + list.map { it.id.toString() to it.title }) { track = it }
                Field("Title", title, { title = it })
                Field("Instructions", instructions, { instructions = it }, hint = "optional", singleLine = false, minLines = 3)
                Field("Due date", due, { due = it }, hint = "year-month-day, optional")
                ErrorText(error)
            }
        },
        confirmButton = {
            TextButton(enabled = !busy && track.isNotEmpty() && title.isNotBlank(), onClick = {
                scope.launchSave({ busy = it }, { error = it }) {
                    api.call("assign_homework", body = JSONObject().put("track_id", track.toInt()).put("title", title.trim()).put("instructions", instructions.trim()).put("due_date", due.trim()))
                    onDone("Homework assigned. It shows in the client portal.")
                }
            }) { Text(if (busy) "Saving…" else "Assign") }
        },
        dismissButton = { TextButton(onClick = onCancel) { Text("Cancel") } },
    )
}
