package org.casemanagement.app.ui

import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.ExperimentalComposeUiApi
import androidx.compose.ui.Modifier
import androidx.compose.ui.autofill.AutofillNode
import androidx.compose.ui.autofill.AutofillType
import androidx.compose.ui.composed
import androidx.compose.ui.focus.onFocusChanged
import androidx.compose.ui.layout.boundsInWindow
import androidx.compose.ui.layout.onGloballyPositioned
import androidx.compose.ui.platform.LocalAutofill
import androidx.compose.ui.platform.LocalAutofillTree

/*
 * 4.30.0 accessibility helpers: the error summary, "still needed" wording for a form that is not
 * complete, the quiet filter for long pick lists, field labels with their hint and (required), and
 * the autofill hookup for sign-in fields (Compose UI 1.6 has no content type yet).
 */

/**
 * One summary for every problem on a form (owner rule 3): one problem is said as it is; several
 * are counted and each is listed, so nobody has to find the rest one at a time. Null when none.
 */
fun errorSummary(errors: Collection<String?>): String? {
    val list = errors.mapNotNull { it?.trim()?.takeIf { s -> s.isNotEmpty() } }.distinct()
    return when (list.size) {
        0 -> null
        1 -> list[0]
        else -> "${list.size} things to fix: " + list.joinToString(" ") { if (it.last() in ".!?") it else "$it." }
    }
}

/** "Still needed: Subject and Note." for the fields a form cannot be saved without, or null when all are there. */
fun stillNeeded(names: List<String>): String? = when (names.size) {
    0 -> null
    1 -> "Still needed: ${names[0]}."
    else -> "Still needed: " + names.dropLast(1).joinToString(", ") + " and " + names.last() + "."
}

/** The names whose condition is true: stillNeeded(missingNames("Subject" to subject.isBlank(), ...)). */
fun missingNames(vararg checks: Pair<String, Boolean>): List<String> = checks.filter { it.second }.map { it.first }

/** A pick list longer than this gets a filter field. */
const val FILTER_FROM = 15

/**
 * The options whose label contains every word typed (any order, any case, accents ignored). The
 * current choice is always kept, so the list never loses what is chosen.
 */
fun filterOptions(options: List<Pair<String, String>>, query: String, keep: String? = null): List<Pair<String, String>> {
    val words = fold(query).split(Regex("\\s+")).filter { it.isNotEmpty() }
    if (words.isEmpty()) return options
    return options.filter { (v, l) -> (keep != null && v == keep && v.isNotEmpty()) || fold(l).let { f -> words.all { f.contains(it) } } }
}

private fun fold(s: String): String =
    java.text.Normalizer.normalize(s, java.text.Normalizer.Form.NFD).replace(Regex("\\p{M}+"), "").lowercase(java.util.Locale.ROOT)

/** Where a list opens: the current choice's row, or the top. */
fun indexOfChoice(options: List<Pair<String, String>>, value: String): Int = options.indexOfFirst { it.first == value }.coerceAtLeast(0)

/**
 * A field's spoken label (owner rule 1: the hint is part of the label, not a stop of its own):
 * "Hours (required; such as 1.5)", "Subject (required)", "Miles (0 if none)".
 */
fun fieldLabelText(label: String, hint: String?, required: Boolean): String {
    val extra = listOfNotNull(if (required) "required" else null, hint?.takeIf { it.isNotBlank() })
    return if (extra.isEmpty()) label else "$label (" + extra.joinToString("; ") + ")"
}

/**
 * What a Save button checks when pressed (4.30.0): the button stays enabled, and a press while
 * something is missing shows [shown] next to the button and moves focus there.
 */
class Gate {
    var shown by mutableStateOf<String?>(null)
    var presses by mutableIntStateOf(0)

    /** True when nothing is missing; otherwise shows what is. */
    fun check(missing: String?): Boolean {
        shown = missing
        if (missing != null) presses++
        return missing == null
    }
}

/** A [Gate] whose message goes once the form is complete. */
@Composable
fun rememberGate(missing: String?): Gate {
    val g = remember { Gate() }
    // Shown only after a press; then it follows what is still missing (gone once nothing is).
    LaunchedEffect(missing) { if (g.shown != null) g.shown = missing }
    return g
}

/** The message a [Gate] shows, taking focus each time Save is pressed. */
@Composable
fun GateError(gate: Gate) = ErrorText(gate.shown, focusKey = gate.presses)

/**
 * Who has focus on a new screen (4.30.0): an error that takes focus records when it did, and the
 * title (which takes focus a moment after a screen opens) leaves it there instead of taking it back.
 */
object ScreenFocus {
    @Volatile var claimedAt: Long = 0L
    fun claim() { claimedAt = android.os.SystemClock.uptimeMillis() }
    fun claimedSince(t: Long): Boolean = claimedAt >= t
}

/** Save presses in the surrounding form dialog: an error there takes focus again on each press. */
val LocalSavePresses = androidx.compose.runtime.compositionLocalOf<Any?> { null }

/** What the phone's autofill service may put in a field. */
enum class Fill { Email, Password, NewPassword, Code }

/**
 * Lets the phone's autofill service (a password manager, the keyboard's suggestions) fill this
 * field (WCAG 1.3.5). Compose UI 1.6 needs this explicit hookup.
 */
@OptIn(ExperimentalComposeUiApi::class)
fun Modifier.autofillAs(fill: Fill, onFill: (String) -> Unit): Modifier = composed {
    val type = when (fill) {
        Fill.Email -> AutofillType.EmailAddress
        Fill.Password -> AutofillType.Password
        Fill.NewPassword -> AutofillType.NewPassword
        Fill.Code -> AutofillType.SmsOtpCode
    }
    val autofill = LocalAutofill.current
    val fill = androidx.compose.runtime.rememberUpdatedState(onFill)
    val node = remember(type) { AutofillNode(autofillTypes = listOf(type), onFill = { fill.value(it) }) }
    val tree = LocalAutofillTree.current
    LaunchedEffect(node) { tree += node }
    this
        .onGloballyPositioned { node.boundingBox = it.boundsInWindow() }
        .onFocusChanged { f ->
            if (autofill == null || node.boundingBox == null) return@onFocusChanged
            if (f.isFocused) autofill.requestAutofillForNode(node) else autofill.cancelAutofillForNode(node)
        }
}
