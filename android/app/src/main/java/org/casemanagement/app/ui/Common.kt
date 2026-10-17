package org.casemanagement.app.ui

import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ColumnScope
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.selection.selectable
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.RadioButton
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalView
import androidx.compose.ui.semantics.LiveRegionMode
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.error
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.liveRegion
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.semantics.stateDescription
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale

/*
 * Shared pieces, written for TalkBack as the iPhone app is for VoiceOver: headings are headings,
 * a hint is part of its field's label (no extra line to swipe past), each list row is one stop
 * that reads as one sentence, and errors and confirmations are read out once as they appear.
 */

@Composable
fun Heading(text: String, modifier: Modifier = Modifier, large: Boolean = false) {
    Text(
        text,
        style = if (large) MaterialTheme.typography.headlineSmall else MaterialTheme.typography.titleMedium,
        fontWeight = FontWeight.SemiBold,
        modifier = modifier.padding(top = 12.dp, bottom = 4.dp).semantics { heading() },
    )
}

/** An error, read out once when it appears (a polite live region does not cut off speech). */
@Composable
fun ErrorText(message: String?) {
    if (message.isNullOrBlank()) return
    Surface(color = MaterialTheme.colorScheme.error.copy(alpha = 0.08f), modifier = Modifier.fillMaxWidth().padding(vertical = 6.dp)) {
        Text(
            message,
            color = MaterialTheme.colorScheme.error,
            modifier = Modifier.padding(12.dp).semantics { liveRegion = LiveRegionMode.Polite; contentDescription = "Error: $message" },
        )
    }
}

/** A confirmation, read out once when it appears. */
@Composable
fun NoticeText(message: String?) {
    if (message.isNullOrBlank()) return
    Surface(color = MaterialTheme.colorScheme.primaryContainer, modifier = Modifier.fillMaxWidth().padding(vertical = 6.dp)) {
        Text(message, color = MaterialTheme.colorScheme.onPrimaryContainer, modifier = Modifier.padding(12.dp).semantics { liveRegion = LiveRegionMode.Polite })
    }
}

/** Says something to TalkBack without showing it (a screen that moved on, a list that loaded). */
@Composable
fun Announce(message: String?) {
    val view = LocalView.current
    LaunchedEffect(message) { if (!message.isNullOrBlank()) @Suppress("DEPRECATION") view.announceForAccessibility(message) }
}

@Composable
fun Loading(what: String) {
    Row(Modifier.fillMaxWidth().padding(24.dp).semantics(mergeDescendants = true) {}, horizontalArrangement = Arrangement.Center, verticalAlignment = Alignment.CenterVertically) {
        CircularProgressIndicator(Modifier.padding(end = 12.dp))
        Text("Loading $what…")
    }
}

/** A scrolling page with the usual margins. */
@Composable
fun Page(content: @Composable ColumnScope.() -> Unit) {
    Column(Modifier.fillMaxWidth().verticalScroll(rememberScrollState()).padding(horizontal = 16.dp, vertical = 8.dp), content = content)
}

/**
 * One row of a list: one stop for TalkBack, read as [spoken] (or its lines joined), and a
 * double tap opens it.
 */
@Composable
fun ListRow(lines: List<String>, spoken: String? = null, action: String? = null, state: String? = null, onClick: (() -> Unit)? = null) {
    val text = spoken ?: lines.filter { it.isNotBlank() }.joinToString(". ")
    Column(
        Modifier.fillMaxWidth().heightIn(min = 48.dp)
            // TalkBack says "double tap to <action>" itself; the label never repeats it.
            .let { if (onClick != null) it.clickable(role = Role.Button, onClickLabel = action, onClick = onClick) else it }
            .semantics(mergeDescendants = true) { contentDescription = text; if (state != null) stateDescription = state }
            .padding(vertical = 10.dp),
    ) {
        lines.filter { it.isNotBlank() }.forEachIndexed { i, l ->
            Text(l, style = if (i == 0) MaterialTheme.typography.bodyLarge else MaterialTheme.typography.bodyMedium,
                fontWeight = if (i == 0) FontWeight.SemiBold else FontWeight.Normal,
                color = if (i == 0) MaterialTheme.colorScheme.onSurface else MaterialTheme.colorScheme.onSurfaceVariant)
        }
    }
    HorizontalDivider()
}

/** A text field whose label carries its hint, so the hint is read with it and adds no stop. */
@Composable
fun Field(
    label: String, value: String, onChange: (String) -> Unit, modifier: Modifier = Modifier,
    hint: String? = null, error: String? = null, singleLine: Boolean = true, minLines: Int = 1,
    keyboard: KeyboardType = KeyboardType.Text, password: Boolean = false,
) {
    OutlinedTextField(
        value = value, onValueChange = onChange,
        label = { Text(if (hint != null) "$label ($hint)" else label) },
        singleLine = singleLine, minLines = minLines,
        isError = error != null,
        supportingText = error?.let { { Text(it) } },
        keyboardOptions = KeyboardOptions(keyboardType = if (password) KeyboardType.Password else keyboard),
        visualTransformation = if (password) androidx.compose.ui.text.input.PasswordVisualTransformation() else androidx.compose.ui.text.input.VisualTransformation.None,
        modifier = modifier.fillMaxWidth().padding(vertical = 4.dp).let { m -> if (error != null) m.semantics { error(error) } else m },
    )
}

/**
 * A choice from a list: a button that says the label and the current choice, opening a list of
 * plainly named options (no announcement on each move, as the website's quiet combo boxes).
 */
@Composable
fun Choice(label: String, value: String, options: List<Pair<String, String>>, onChange: (String) -> Unit) {
    var open by remember { mutableStateOf(false) }
    val shown = options.firstOrNull { it.first == value }?.second ?: value.ifEmpty { "None" }
    OutlinedButton(onClick = { open = true }, modifier = Modifier.fillMaxWidth().padding(vertical = 4.dp)
        .semantics { contentDescription = "$label: $shown" }) {
        Text("$label: $shown", modifier = Modifier.fillMaxWidth())
    }
    if (open) {
        AlertDialog(
            onDismissRequest = { open = false },
            title = { Text(label, modifier = Modifier.semantics { heading() }) },
            text = {
                Column(Modifier.verticalScroll(rememberScrollState())) {
                    options.forEach { (v, l) ->
                        Row(Modifier.fillMaxWidth().heightIn(min = 48.dp)
                            .selectable(selected = v == value, role = Role.RadioButton, onClick = { onChange(v); open = false }),
                            verticalAlignment = Alignment.CenterVertically) {
                            RadioButton(selected = v == value, onClick = null)
                            Text(l, modifier = Modifier.padding(start = 8.dp))
                        }
                    }
                }
            },
            confirmButton = { TextButton(onClick = { open = false }) { Text("Cancel") } },
        )
    }
}

@Composable
fun PrimaryButton(text: String, busy: Boolean = false, enabled: Boolean = true, onClick: () -> Unit) {
    Button(onClick = onClick, enabled = enabled && !busy, modifier = Modifier.fillMaxWidth().padding(vertical = 6.dp).heightIn(min = 48.dp)) {
        Text(if (busy) "Please wait…" else text)
    }
}

@Composable
fun SecondaryButton(text: String, modifier: Modifier = Modifier, onClick: () -> Unit) {
    OutlinedButton(onClick = onClick, modifier = modifier.padding(vertical = 4.dp).heightIn(min = 48.dp), contentPadding = PaddingValues(horizontal = 16.dp)) { Text(text) }
}

/** Asks before something that cannot be undone. */
@Composable
fun Confirm(title: String, text: String, yes: String, onYes: () -> Unit, onDismiss: () -> Unit) {
    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text(title) }, text = { Text(text) },
        confirmButton = { TextButton(onClick = { onDismiss(); onYes() }) { Text(yes) } },
        dismissButton = { TextButton(onClick = onDismiss) { Text("Cancel") } },
    )
}

/* ---------- Dates and times (the server's "2026-10-16 14:30:00", shown as it is meant) ---------- */

private val serverFormat = SimpleDateFormat("yyyy-MM-dd HH:mm:ss", Locale.US)
private val dayFormat = SimpleDateFormat("yyyy-MM-dd", Locale.US)

fun parseServer(s: String?): Date? = s?.let { try { serverFormat.parse(it) } catch (e: Exception) { try { dayFormat.parse(it) } catch (e: Exception) { null } } }

fun whenText(s: String?): String = parseServer(s)?.let { SimpleDateFormat("EEE, MMM d, h:mm a", Locale.US).format(it) } ?: (s ?: "")
fun timeText(s: String?): String = parseServer(s)?.let { SimpleDateFormat("h:mm a", Locale.US).format(it) } ?: ""
fun dayText(s: String?): String = parseServer(s)?.let { SimpleDateFormat("EEEE, MMMM d, yyyy", Locale.US).format(it) } ?: (s ?: "")
/** The server's time zone (Api.refreshMe sets it from me.timezone), so "today" is the server's today. */
var serverZoneId: String = "America/Chicago"

fun today(): String = SimpleDateFormat("yyyy-MM-dd", Locale.US).apply { timeZone = java.util.TimeZone.getTimeZone(serverZoneId) }.format(Date())

/** A number typed in a form, or null (NaN and infinity are not numbers here). */
fun number(s: String): Double? = s.trim().replace(',', '.').toDoubleOrNull()?.takeIf { it.isFinite() }

/** Opens a link in another app; false when no app on the phone can open it. */
fun openLink(context: android.content.Context, intent: android.content.Intent): Boolean = try {
    context.startActivity(intent); true
} catch (e: android.content.ActivityNotFoundException) { false }

/** Hours as people say them: 1.5 → "1.5", 2.0 → "2". */
fun hoursText(h: Double): String = if (h == Math.floor(h)) h.toLong().toString() else "%.2f".format(Locale.US, h).trimEnd('0').trimEnd('.')

/** Said when a screen shows the copy kept on the phone because there is no connection. */
@Composable
fun OfflineCopyNotice(savedAt: Long?) {
    if (savedAt == null) return
    val at = java.text.DateFormat.getDateTimeInstance(java.text.DateFormat.MEDIUM, java.text.DateFormat.SHORT).format(Date(savedAt))
    NoticeText("No connection. This is the copy saved on this phone at $at, so it may be out of date.")
}
