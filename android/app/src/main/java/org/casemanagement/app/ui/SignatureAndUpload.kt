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
import androidx.compose.ui.semantics.text
import androidx.compose.runtime.saveable.Saver
import androidx.compose.runtime.saveable.rememberSaveable
import org.casemanagement.app.decodeStrokes
import org.casemanagement.app.sendOnce
import org.casemanagement.app.encodeStrokes
import org.casemanagement.app.scaleStrokes
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

    companion object {
        /** Keeps the drawing as plain numbers (see encodeStrokes), so it outlives rotation and process death (4.28.0). */
        val Saver: Saver<SignatureState, FloatArray> = Saver(
            save = { st -> encodeStrokes(st.padSize.width, st.padSize.height, st.strokes.value.map { s -> s.map { it.x to it.y } }) },
            restore = { a ->
                val (w, h, strokes) = decodeStrokes(a)
                SignatureState().apply { padSize = IntSize(w.coerceAtLeast(1), h.coerceAtLeast(1)); this.strokes.value = strokes.map { s -> s.map { (x, y) -> Offset(x, y) } } }
            },
        )
    }

    /** Total ink length in pixels. */
    val ink: Float get() = strokes.value.sumOf { s -> s.zipWithNext().sumOf { (a, b) -> (b - a).getDistance().toDouble() } }.toFloat()

    fun clear() { strokes.value = emptyList() }

    /** The pad's new size: what is drawn is fitted to it (4.28.0), so a signature kept across rotation is never cut off. */
    fun resize(size: IntSize) {
        if (size == padSize) return
        if (strokes.value.isNotEmpty()) strokes.value = scaleStrokes(strokes.value.map { s -> s.map { it.x to it.y } }, padSize.width, padSize.height, size.width, size.height)
            .map { s -> s.map { (x, y) -> Offset(x, y) } }
        padSize = size
    }

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

/** A [SignatureState] kept across rotation, a text-size change and process death (4.28.0); [keys] start a new one. */
@Composable
fun rememberSignatureState(vararg keys: Any?): SignatureState = rememberSaveable(*keys, saver = SignatureState.Saver) { SignatureState() }

/** [lang] "es": the caption, the pad's description and its button are in Spanish and marked so (4.28.0). */
@Composable
fun SignaturePad(state: SignatureState, caption: String = "Client signature: hand the phone to the client to sign below with a finger.", lang: String? = null) {
    val es = lang == "es"
    val described = if (es) {
        (if (state.strokes.value.isEmpty()) "Área de firma, vacía. Firme aquí con el dedo." else "Área de firma, firmada.") +
            " Con TalkBack activado, pause TalkBack mientras firma, o use solo el nombre escrito."
    } else {
        (if (state.strokes.value.isEmpty()) "Signature area, empty. The client signs here with a finger." else "Signature area, signed.") +
            " With TalkBack on, pause TalkBack while the client signs, or use the typed name instead."
    }
    Text(inLanguage(caption, lang))
    Canvas(
        Modifier.fillMaxWidth().height(180.dp).background(Color.White).border(2.dp, MaterialTheme.colorScheme.outline)
            .onSizeChanged { state.resize(it) }
            // A description cannot carry a language, so another language's is given as the pad's text.
            .semantics { if (lang.isNullOrBlank()) contentDescription = described else text = inLanguage(described, lang) }
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
    if (state.strokes.value.isNotEmpty()) SecondaryButton(if (es) "Borrar la firma" else "Clear the signature", lang = lang) { state.clear() }
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
    // Kept across rotation and process death (4.28.0); the chosen file as its address and name.
    var fileUri by rememberSaveable { mutableStateOf<Uri?>(null) }
    var fileName by rememberSaveable { mutableStateOf("") }
    val file = fileUri?.let { it to fileName }
    var category by rememberSaveable { mutableStateOf("Other") }
    var title by rememberSaveable { mutableStateOf("") }
    var idem by rememberSaveable { mutableStateOf("") }
    var error by remember { mutableStateOf<String?>(null) }
    var busy by remember { mutableStateOf(false) }
    val scope = rememberCoroutineScope()
    val pick = rememberLauncherForActivityResult(ActivityResultContracts.OpenDocument()) { uri ->
        if (uri != null) {
            fileUri = uri; fileName = pickedName(context, uri)
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
                // 4.28.0: the same key while the same file goes with the same details (see sendOnce).
                val print = listOf("upload_document", clientId, category, title.trim(), short, tmp.length(), uri).joinToString("|")
                sendOnce(idem, { idem = it }, print, "the document “$short”") { key -> api.uploadDocument(clientId, category, title.trim(), short, tmp, key) }
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
