package org.casemanagement.app.ui

import androidx.compose.foundation.clickable
import androidx.compose.foundation.focusable
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.lazy.rememberLazyListState
import androidx.compose.foundation.selection.selectableGroup
import androidx.compose.ui.focus.FocusRequester
import androidx.compose.ui.focus.focusRequester
import androidx.compose.ui.text.style.TextOverflow
import kotlinx.coroutines.delay
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
import androidx.compose.ui.semantics.clearAndSetSemantics
import androidx.compose.ui.semantics.text
import androidx.compose.ui.text.AnnotatedString
import androidx.compose.ui.text.SpanStyle
import androidx.compose.ui.text.intl.LocaleList
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

/**
 * [text] marked as written in [lang] (a language code such as "es"), so TalkBack reads it with that
 * language's voice (WCAG 3.1.2); plain text when [lang] is empty.
 */
fun inLanguage(text: String, lang: String?): AnnotatedString =
    if (lang.isNullOrBlank()) AnnotatedString(text)
    else AnnotatedString(text, listOf(AnnotatedString.Range(SpanStyle(localeList = LocaleList(lang)), 0, text.length)))

/**
 * An error. 4.30.0: it takes focus when it appears (owner rule 3), so TalkBack lands on it and it
 * scrolls into view, read once from there (no live region as well, which would read it twice).
 * [focusKey] changes when the same message should take focus again (Save pressed a second time).
 * [takeFocus] = false for a status line that must not move the person (a sync notice): read once
 * as a polite live region instead. [lang]: see [inLanguage].
 */
@Composable
fun ErrorText(message: String?, lang: String? = null, takeFocus: Boolean = true, focusKey: Any? = null) {
    if (message.isNullOrBlank()) return
    val focus = remember { FocusRequester() }
    val pressKey = focusKey ?: LocalSavePresses.current
    if (takeFocus) LaunchedEffect(message, pressKey) {
        // A moment for the pressed button's own feedback, then the summary.
        delay(150)
        try { focus.requestFocus(); ScreenFocus.claim() } catch (e: IllegalStateException) { /* not placed yet */ }
    }
    val spoken = Modifier.padding(12.dp)
        .let { if (takeFocus) it.focusRequester(focus).focusable() else it }
    Surface(color = MaterialTheme.colorScheme.error.copy(alpha = 0.08f), modifier = Modifier.fillMaxWidth().padding(vertical = 6.dp)) {
        if (lang.isNullOrBlank()) Text(
            message,
            color = MaterialTheme.colorScheme.error,
            modifier = spoken.semantics { if (!takeFocus) liveRegion = LiveRegionMode.Polite; contentDescription = "Error: $message" },
        ) else Text(
            inLanguage(message, lang),
            color = MaterialTheme.colorScheme.error,
            // Read as text (a description would lose the language); "Error" is the same word in Spanish.
            modifier = spoken.clearAndSetSemantics { if (!takeFocus) liveRegion = LiveRegionMode.Polite; text = inLanguage("Error: $message", lang) },
        )
    }
}

/** A confirmation, read out once when it appears. [lang]: see [inLanguage]. */
@Composable
fun NoticeText(message: String?, lang: String? = null) {
    if (message.isNullOrBlank()) return
    Surface(color = MaterialTheme.colorScheme.primaryContainer, modifier = Modifier.fillMaxWidth().padding(vertical = 6.dp)) {
        Text(inLanguage(message, lang), color = MaterialTheme.colorScheme.onPrimaryContainer, modifier = Modifier.padding(12.dp).semantics { liveRegion = LiveRegionMode.Polite })
    }
}

/** Says something to TalkBack without showing it (a screen that moved on, a list that loaded). */
@Composable
fun Announce(message: String?, key: Any? = message) {
    val view = LocalView.current
    LaunchedEffect(key) { if (!message.isNullOrBlank()) @Suppress("DEPRECATION") view.announceForAccessibility(message) }
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
    // Read as sentences: a line that already ends a sentence ("Sent a file.") gets no second full stop.
    val shown = lines.filter { it.isNotBlank() }
    val text = spoken ?: shown.mapIndexed { i, l -> if (i < shown.size - 1 && l.trimEnd().lastOrNull() !in listOf('.', '!', '?', ':')) "$l." else l }.joinToString(" ")
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

/**
 * A text field whose label carries its hint, so the hint is read with it and adds no stop.
 * 4.30.0: [required] says "(required)" in the label, seen and heard (owner rule 4). The floated
 * label shows on one line (a long one no longer overlaps the typed text at large font sizes); the
 * hint shows in full under the field, and is still read as part of the label (owner rule 1). The
 * error under the field is read once, through error() (not a second time as supporting text).
 * [autofill]: what the phone's autofill service may fill in (email, password, code).
 */
@Composable
fun Field(
    label: String, value: String, onChange: (String) -> Unit, modifier: Modifier = Modifier,
    hint: String? = null, error: String? = null, singleLine: Boolean = true, minLines: Int = 1,
    keyboard: KeyboardType = KeyboardType.Text, password: Boolean = false, lang: String? = null,
    required: Boolean = false, autofill: Fill? = null,
) {
    val spokenLabel = fieldLabelText(label, hint, required)
    val shownLabel = if (required) "$label (required)" else label
    val shownHint = hint?.takeIf { it.isNotBlank() }
    // At a large font size a long label is shown in full above the field, wrapping as it needs
    // (seen only: the field's own label says it), as the floated label is kept to one line.
    val largeFont = androidx.compose.ui.platform.LocalDensity.current.fontScale >= 1.3f
    if (largeFont && shownLabel.length > 20) Text(
        inLanguage(shownLabel, lang), style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onSurfaceVariant,
        modifier = Modifier.fillMaxWidth().padding(top = 8.dp).clearAndSetSemantics { },
    )
    OutlinedTextField(
        value = value, onValueChange = onChange,
        label = {
            Text(inLanguage(shownLabel, lang), maxLines = 1, overflow = TextOverflow.Ellipsis,
                modifier = Modifier.clearAndSetSemantics { text = inLanguage(spokenLabel, lang) })
        },
        singleLine = singleLine, minLines = minLines,
        isError = error != null,
        supportingText = if (error == null && shownHint == null) null else {
            {
                // Seen only: the hint is in the label, and the error is said by error() below.
                Column(Modifier.clearAndSetSemantics { }) {
                    if (shownHint != null) Text(inLanguage(shownHint, lang))
                    if (error != null) Text(inLanguage(error, lang), color = MaterialTheme.colorScheme.error)
                }
            }
        },
        keyboardOptions = KeyboardOptions(keyboardType = if (password) KeyboardType.Password else keyboard),
        visualTransformation = if (password) androidx.compose.ui.text.input.PasswordVisualTransformation() else androidx.compose.ui.text.input.VisualTransformation.None,
        modifier = modifier.fillMaxWidth().padding(vertical = 4.dp)
            .let { m -> if (autofill != null) m.autofillAs(autofill, onChange) else m }
            .let { m -> if (error != null) m.semantics { error(error) } else m },
    )
}

/**
 * A choice from a list: a button that says the label and the current choice, opening a list of
 * plainly named options (no announcement on each move, as the website's quiet combo boxes).
 * 4.30.0: a long list (more than [FILTER_FROM]) has a Filter field at the top that filters quietly
 * (nothing is announced while typing), and the list opens at the current choice, which has focus.
 * [required] says "(required)" with the label.
 */
@Composable
fun Choice(label: String, value: String, options: List<Pair<String, String>>, error: String? = null, required: Boolean = false, onChange: (String) -> Unit) {
    var open by remember { mutableStateOf(false) }
    val shownLabel = if (required) "$label (required)" else label
    val shown = options.firstOrNull { it.first == value }?.second ?: value.ifEmpty { "None" }
    OutlinedButton(onClick = { open = true }, modifier = Modifier.fillMaxWidth().padding(vertical = 4.dp)
        // 4.28.0: an error belongs to the choice itself (read with it), not to a line of its own.
        .semantics { contentDescription = "$shownLabel: $shown"; if (error != null) error(error) }) {
        Text("$shownLabel: $shown", modifier = Modifier.fillMaxWidth())
    }
    if (error != null) Text(error, color = MaterialTheme.colorScheme.error, style = MaterialTheme.typography.bodySmall,
        modifier = Modifier.padding(start = 16.dp, bottom = 4.dp).clearAndSetSemantics { })
    if (open) {
        var query by remember { mutableStateOf("") }
        val long = options.size > FILTER_FROM
        val list = if (long) filterOptions(options, query, keep = value) else options
        val start = remember { indexOfChoice(options, value) }
        val listState = rememberLazyListState(initialFirstVisibleItemIndex = start)
        val current = remember { FocusRequester() }
        LaunchedEffect(Unit) {
            delay(200)
            try { current.requestFocus() } catch (e: IllegalStateException) { /* the chosen row is not shown */ }
        }
        AlertDialog(
            onDismissRequest = { open = false },
            title = { Text(label, modifier = Modifier.semantics { heading() }) },
            text = {
                Column {
                    if (long) OutlinedTextField(value = query, onValueChange = { query = it }, singleLine = true,
                        label = { Text("Filter the list") }, modifier = Modifier.fillMaxWidth().padding(bottom = 4.dp))
                    if (list.isEmpty()) Text("Nothing matches. Change the filter.", modifier = Modifier.padding(vertical = 8.dp))
                    LazyColumn(state = listState, modifier = Modifier.heightIn(max = 420.dp).selectableGroup()) {
                        items(list) { (v, l) ->
                            Row(Modifier.fillMaxWidth().heightIn(min = 48.dp)
                                .let { if (v == value) it.focusRequester(current) else it }
                                .selectable(selected = v == value, role = Role.RadioButton, onClick = { onChange(v); open = false }),
                                verticalAlignment = Alignment.CenterVertically) {
                                RadioButton(selected = v == value, onClick = null)
                                Text(l, modifier = Modifier.padding(start = 8.dp))
                            }
                        }
                    }
                }
            },
            confirmButton = { TextButton(onClick = { open = false }) { Text("Cancel") } },
        )
    }
}

/**
 * The main button of a form. 4.30.0: a form that is not complete keeps its button enabled (a
 * disabled button says nothing about why, and Switch Access and Voice Access cannot reach it):
 * [missing] says what is still needed, and a press shows it under the button and moves focus there.
 * [enabled] = false is kept for a button that truly cannot act. While [busy], [busyText] shows and
 * is said once.
 */
@Composable
fun PrimaryButton(text: String, busy: Boolean = false, enabled: Boolean = true, lang: String? = null, busyText: String = "Please wait…",
                  missing: String? = null, announceBusy: Boolean = false, onClick: () -> Unit) {
    val gate = rememberGate(missing)
    Button(onClick = { if (gate.check(missing)) onClick() }, enabled = enabled && !busy, modifier = Modifier.fillMaxWidth().padding(vertical = 6.dp).heightIn(min = 48.dp)) {
        Text(inLanguage(if (busy) busyText else text, lang))
    }
    if (announceBusy) Announce(if (busy) busyText else null, key = busy)
    if (missing != null) ErrorText(gate.shown, lang = lang, focusKey = gate.presses)
}

@Composable
fun SecondaryButton(text: String, modifier: Modifier = Modifier, lang: String? = null, onClick: () -> Unit) {
    OutlinedButton(onClick = onClick, modifier = modifier.padding(vertical = 4.dp).heightIn(min = 48.dp), contentPadding = PaddingValues(horizontal = 16.dp)) { Text(inLanguage(text, lang)) }
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
