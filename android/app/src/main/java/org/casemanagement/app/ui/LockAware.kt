package org.casemanagement.app.ui

import androidx.compose.runtime.Composable
import androidx.compose.runtime.compositionLocalOf
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.alpha
import androidx.compose.ui.semantics.clearAndSetSemantics

/**
 * 4.34.0: true while the app lock is up. Every dialog under it stays open (what was typed in it
 * is kept) but shows nothing and is not reachable by TalkBack, so no client data shows with the lock.
 */
val LocalAppLocked = compositionLocalOf { false }

/** Hides a dialog's content while the app is locked (see [LocalAppLocked]). */
@Composable
fun Modifier.hiddenWhileLocked(): Modifier = if (LocalAppLocked.current) this.alpha(0f).clearAndSetSemantics { } else this

/**
 * The app's dialogs: Material's AlertDialog, hidden while the app lock is up. Every screen uses
 * this one (by name, in this package), so none can show client data above the lock.
 */
@Composable
fun AlertDialog(
    onDismissRequest: () -> Unit,
    confirmButton: @Composable () -> Unit,
    modifier: Modifier = Modifier,
    dismissButton: (@Composable () -> Unit)? = null,
    title: (@Composable () -> Unit)? = null,
    text: (@Composable () -> Unit)? = null,
) {
    val locked = LocalAppLocked.current
    androidx.compose.material3.AlertDialog(
        // Nothing behind the lock can be closed by a tap or Back either.
        onDismissRequest = { if (!locked) onDismissRequest() },
        confirmButton = confirmButton, modifier = modifier.hiddenWhileLocked(),
        dismissButton = dismissButton, title = title, text = text,
    )
}
