package org.casemanagement.app.ui

import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.foundation.layout.padding
import androidx.compose.ui.unit.dp
import org.casemanagement.app.ApiException

/** What a screen read from the server: the data, or why it could not be read. */
class Loaded<T> {
    var data by mutableStateOf<T?>(null)
    var error by mutableStateOf<String?>(null)
    var loading by mutableStateOf(true)
    private var generation by mutableIntStateOf(0)
    /** Read again (after a change, or on Try again). */
    fun reload() { generation++ }
    internal val key get() = generation
}

/** Reads once, and again whenever [keys] change or reload() is called. */
@Composable
fun <T> rememberLoaded(vararg keys: Any?, fetch: suspend () -> T): Loaded<T> {
    val state = remember(*keys) { Loaded<T>() }
    LaunchedEffect(state.key, *keys) {
        state.loading = true
        try {
            state.data = fetch(); state.error = null
        } catch (e: ApiException) {
            state.error = e.message
        } finally {
            state.loading = false
        }
    }
    return state
}

/** The usual frame around loaded data: loading, the error with Try again, or the content. */
@Composable
fun <T> Show(state: Loaded<T>, what: String, content: @Composable (T) -> Unit) {
    val data = state.data
    when {
        data != null -> {
            // A reload that failed: say so, above what was read before.
            state.error?.let { if (!state.loading) ErrorText("Could not refresh: $it What is shown may be out of date.", takeFocus = false) }
            content(data)
        }
        state.loading -> Loading(what)
        // A plain column: Show is often inside a scrolling Page, and a scroller in a scroller fails.
        else -> androidx.compose.foundation.layout.Column(androidx.compose.ui.Modifier.padding(horizontal = 16.dp, vertical = 8.dp)) {
            ErrorText(state.error ?: "Could not load $what.", takeFocus = false)
            SecondaryButton("Try again") { state.reload() }
        }
    }
}
