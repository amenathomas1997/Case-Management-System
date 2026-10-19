package org.casemanagement.app.ui

import android.graphics.Bitmap
import android.graphics.Canvas as AndroidCanvas
import android.graphics.Paint
import android.net.Uri
import android.provider.OpenableColumns
import android.util.Base64
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.Canvas
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.gestures.detectDragGestures
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.Path
import androidx.compose.ui.graphics.StrokeCap
import androidx.compose.ui.graphics.drawscope.Stroke
import androidx.compose.ui.input.pointer.pointerInput
import androidx.compose.ui.layout.onSizeChanged
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.unit.IntSize
import androidx.compose.ui.unit.dp
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import org.casemanagement.app.Api
import org.casemanagement.app.ApiException
import org.json.JSONObject
import java.io.ByteArrayOutputStream

/**
 * A signature pad for the client, as the iPhone app's: black ink on white, sent as a PNG (no
 * "data:" prefix) only when there is enough ink to be a signature. A typed name alone also counts
 * as the client's acknowledgement.
 */
class SignatureState {
    val strokes = mutableStateOf<List<List<Offset>>>(emptyList())
    var padSize = IntSize(1, 1)

    /** Total ink length in pixels. */
    val ink: Float get() = strokes.value.sumOf { s -> s.zipWithNext().sumOf { (a, b) -> (b - a).getDistance().toDouble() } }.toFloat()

    fun clear() { strokes.value = emptyList() }

    /** The PNG as base64, or null when there is too little ink (under about 60 points). */
    fun png(density: Float): String? {
        if (ink < 60 * density) return null
        val w = 1200; val h = 440
        val bmp = Bitmap.createBitmap(w, h, Bitmap.Config.ARGB_8888)
        val c = AndroidCanvas(bmp)
        c.drawColor(android.graphics.Color.WHITE)
        val scale = minOf(w.toFloat() / padSize.width.coerceAtLeast(1), h.toFloat() / padSize.height.coerceAtLeast(1))
        val p = Paint().apply { color = android.graphics.Color.BLACK; strokeWidth = 6f; style = Paint.Style.STROKE; strokeCap = Paint.Cap.ROUND; isAntiAlias = true }
        strokes.value.forEach { s -> s.zipWithNext().forEach { (a, b) -> c.drawLine(a.x * scale, a.y * scale, b.x * scale, b.y * scale, p) } }
        val out = ByteArrayOutputStream()
        bmp.compress(Bitmap.CompressFormat.PNG, 100, out)
        return Base64.encodeToString(out.toByteArray(), Base64.NO_WRAP)
    }
}

@Composable
fun SignaturePad(state: SignatureState, caption: String = "Client signature: hand the phone to the client to sign below with a finger.") {
    Text(caption)
    Canvas(
        Modifier.fillMaxWidth().height(180.dp).background(Color.White).border(2.dp, MaterialTheme.colorScheme.outline)
            .onSizeChanged { state.padSize = it }
            .semantics { contentDescription = (if (state.strokes.value.isEmpty()) "Signature area, empty. The client signs here with a finger." else "Signature area, signed.") + " With TalkBack on, pause TalkBack while the client signs, or use the typed name instead." }
            .pointerInput(Unit) {
                detectDragGestures(
                    onDragStart = { o -> state.strokes.value = state.strokes.value + listOf(listOf(o)) },
                    onDrag = { change, _ -> val all = state.strokes.value; state.strokes.value = all.dropLast(1) + listOf(all.last() + change.position) },
                )
            },
    ) {
        state.strokes.value.forEach { s ->
            if (s.size > 1) drawPath(Path().apply { moveTo(s[0].x, s[0].y); s.drop(1).forEach { lineTo(it.x, it.y) } }, Color.Black, style = Stroke(width = 3.dp.toPx(), cap = StrokeCap.Round))
        }
    }
    if (state.strokes.value.isNotEmpty()) SecondaryButton("Clear the signature") { state.clear() }
}

val UPLOAD_TYPES = setOf("pdf", "doc", "docx", "xls", "xlsx", "ppt", "pptx", "txt", "rtf", "csv", "png", "jpg", "jpeg", "heic")

/** The name of a file chosen with the phone's file picker. */
fun pickedName(context: android.content.Context, uri: Uri): String =
    context.contentResolver.query(uri, arrayOf(OpenableColumns.DISPLAY_NAME), null, null, null)?.use { c -> if (c.moveToFirst()) c.getString(0) else null } ?: "document"

/** A file name the server keeps whole (190 characters at most), its extension kept. */
fun shortName(name: String): String {
    val ext = name.substringAfterLast('.', "")
    return if (name.length > 190) name.take(185 - ext.length) + "." + ext else name
}

/**
 * Copies a chosen file to [tmp] in the app's temporary folder, never more than 25 MB: the bytes
 * copied, -1 when it is larger, -2 when it could not be read.
 */
suspend fun copyPicked(context: android.content.Context, uri: Uri, tmp: java.io.File): Long {
    val limit = 25L * 1024 * 1024
    val size = context.contentResolver.query(uri, arrayOf(OpenableColumns.SIZE), null, null, null)?.use { c -> if (c.moveToFirst() && !c.isNull(0)) c.getLong(0) else -1L } ?: -1L
    if (size > limit) return -1L
    return withContext(Dispatchers.IO) {
        try {
            tmp.parentFile?.mkdirs()
            context.contentResolver.openInputStream(uri)?.use { input ->
                tmp.outputStream().use { out ->
                    val buf = ByteArray(64 * 1024); var total = 0L
                    while (true) { val r = input.read(buf); if (r < 0) break; total += r; if (total > limit) return@withContext -1L; out.write(buf, 0, r) }
                    total
                }
            } ?: -2L
        } catch (e: java.io.IOException) { -2L } catch (e: SecurityException) { -2L }
    }
}

/** Says why a copied file cannot be sent. */
fun checkCopied(copied: Long) {
    if (copied == -2L) throw ApiException("The file could not be read.")
    if (copied < 0) throw ApiException("That file is larger than 25 MB.")
    if (copied == 0L) throw ApiException("That file is empty.")
}

/** Upload a document to a client's record: chosen with the phone's file picker, up to 25 MB. */
@Composable
fun UploadDocumentDialog(api: Api, clientId: Int, onDone: (String) -> Unit, onCancel: () -> Unit) {
    val context = LocalContext.current
    val cats = api.formList("doc_categories", listOf("Authorization", "IPE", "Release", "Report", "Resource", "Other"))
    var file by remember { mutableStateOf<Pair<Uri, String>?>(null) }
    var category by remember { mutableStateOf("Other") }
    var title by remember { mutableStateOf("") }
    var error by remember { mutableStateOf<String?>(null) }
    var busy by remember { mutableStateOf(false) }
    val scope = rememberCoroutineScope()
    val pick = rememberLauncherForActivityResult(ActivityResultContracts.OpenDocument()) { uri ->
        if (uri != null) {
            file = uri to pickedName(context, uri)
        }
    }
    FormDialog("Upload a document", "Upload", busy, file != null, onSave = {
        val (uri, name) = file ?: return@FormDialog
        val ext = name.substringAfterLast('.', "").lowercase()
        if (ext !in UPLOAD_TYPES) { error = "That file type is not allowed. Choose a PDF, Word, Excel, PowerPoint, text, CSV or picture file."; return@FormDialog }
        scope.launchSave({ busy = it }, { error = it }) {
            // In the documents folder, emptied at sign-out, another account and a wipe (4.27.0); made inside the try, so it always goes.
            val tmp = java.io.File(api.docsFolder, "upload.tmp")
            try {
                checkCopied(copyPicked(context, uri, tmp))
                val short = shortName(name)
                try {
                    api.uploadDocument(clientId, category, title.trim(), short, tmp)
                } catch (e: ApiException) {
                    if (e.network) throw ApiException("The connection dropped before the server answered, so it isn't known whether the document was uploaded. Check the client's documents for “$short” before uploading it again.")
                    throw e
                }
            } finally { tmp.delete() }
            onDone("Document uploaded.")
        }
    }, onCancel = onCancel) {
        SecondaryButton(file?.let { "Chosen: ${it.second}. Choose another file" } ?: "Choose a file") { pick.launch(arrayOf("*/*")) }
        Choice("Category", category, cats.map { it to it }) { category = it }
        if (category == "Resource") Text("Resource documents are shared in the client's portal.")
        Field("Title", title, { title = it }, hint = "optional; the file name is used if empty")
        ErrorText(error)
    }
}
