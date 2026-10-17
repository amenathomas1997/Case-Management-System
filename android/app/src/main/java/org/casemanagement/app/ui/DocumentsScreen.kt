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
import org.casemanagement.app.objects
import org.casemanagement.app.str
import org.json.JSONObject

/** A client's documents (opened in another app) and homework (complete it, or assign new). */
@Composable
fun DocumentsScreen(api: Api, clientId: Int) {
    val context = LocalContext.current
    val state = rememberLoaded(clientId) {
        val docs = api.call("documents", mapOf("client_id" to clientId.toString())).optJSONArray("documents").objects()
        val hw = api.call("homework", mapOf("client_id" to clientId.toString())).optJSONArray("homework").objects()
        docs to hw
    }
    var notice by remember { mutableStateOf<String?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    var busy by remember { mutableStateOf(false) }
    var completing by remember { mutableStateOf<JSONObject?>(null) }
    var assigning by remember { mutableStateOf(false) }
    val scope = rememberCoroutineScope()
    Show(state, "documents and homework") { (docs, hw) ->
        Page {
            NoticeText(notice)
            ErrorText(error)
            Heading("Documents (${docs.size})")
            if (docs.isEmpty()) Text("No documents.")
            docs.forEach { d ->
                ListRow(listOf(d.optString("title").ifEmpty { d.optString("orig_name") }, d.optString("category") + ", " + whenText(d.str("created_at"))),
                    action = "open it", onClick = {
                        if (busy) return@ListRow
                        error = null; busy = true
                        scope.launch {
                            try {
                                val f = api.download(d.optInt("id"), api.docsFolder)
                                val uri = FileProvider.getUriForFile(context, context.packageName + ".files", f)
                                val type = MimeTypeMap.getSingleton().getMimeTypeFromExtension(f.extension.lowercase()) ?: "application/octet-stream"
                                val i = Intent(Intent.ACTION_VIEW).setDataAndType(uri, type).addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION)
                                if (!openLink(context, Intent.createChooser(i, "Open with"))) error = "No app on this phone can open this kind of file."
                            } catch (e: ApiException) { error = e.message } finally { busy = false }
                        }
                    })
            }
            val queued = api.ownQueued().filter { it.optString("action") == "complete_homework" }.map { try { JSONObject(it.optString("body")).optInt("id") } catch (e: Exception) { 0 } }.toSet()
            Heading("Homework (${hw.size})")
            if (hw.isEmpty()) Text("No homework.")
            hw.forEach { h ->
                val waiting = h.optInt("id") in queued
                val status = if (waiting) "Completed, waiting to send" else h.optString("status")
                ListRow(listOf(h.optString("title"), listOfNotNull(status, h.str("due_date")?.let { "due " + dayText(it) }, h.str("track_title")).joinToString(", "), h.str("instructions") ?: ""),
                    action = "mark it completed", onClick = if (h.optString("status") == "Assigned" && !waiting) ({ completing = h }) else null)
            }
            if (api.can("clients")) SecondaryButton("Assign homework") { assigning = true }
        }
    }
    completing?.let { h ->
        Confirm("Mark “${h.optString("title")}” completed?", "It disappears from the client portal.", "Mark completed", {
            scope.launch {
                try { notice = saveOrQueue(api, "complete_homework", JSONObject().put("id", h.optInt("id")), "Homework done: " + h.optString("title").take(40), "Homework marked completed.").message; state.reload() }
                catch (e: ApiException) { error = e.message }
            }
        }, { completing = null })
    }
    if (assigning) AssignHomeworkDialog(api, clientId, onDone = { m -> assigning = false; notice = m; state.reload() }, onCancel = { assigning = false })
}

@Composable
private fun AssignHomeworkDialog(api: Api, clientId: Int, onDone: (String) -> Unit, onCancel: () -> Unit) {
    val tracks = rememberLoaded(clientId) { api.call("tracks").optJSONArray("tracks").objects().filter { it.optInt("client_id") == clientId } }
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
                if (list.isNotEmpty()) Choice("Training track", track, listOf("" to "Choose a track") + list.map { it.optInt("id").toString() to it.optString("title") }) { track = it }
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
