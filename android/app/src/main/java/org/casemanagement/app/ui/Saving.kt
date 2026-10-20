package org.casemanagement.app.ui

import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.ExperimentalCoroutinesApi
import kotlinx.coroutines.async
import kotlinx.coroutines.launch
import org.casemanagement.app.Api
import org.casemanagement.app.ApiException
import org.casemanagement.app.isoTime
import org.casemanagement.app.sendOnce
import org.casemanagement.app.stillProcessing
import org.json.JSONObject

/**
 * A confirmation shown (and read out) on the screen the person returns to after saving: set
 * [pending] before leaving the form; it shows on the next screen and goes once they move on.
 */
object Flash {
    var pending: String? = null
    var message by mutableStateOf<String?>(null)
    var shownOn: String? = null
}

/**
 * [Api.call] for a create or send that must never happen twice (4.28.0): sends idem_key, the
 * same one only while the request is unchanged (see [sendOnce]). [key] is the form's kept key
 * (rememberSaveable) and [setKey] stores it; [what] names it for the lost-reply message.
 */
suspend fun Api.callOnce(action: String, body: JSONObject, key: String, setKey: (String) -> Unit, what: String): JSONObject =
    sendOnce(key, setKey, action + body.toString(), what) { idem -> call(action, body = body.put("idem_key", idem)) }

/** What saveOrQueue did, in words, and the server's reply when it was sent now. */
class Saved(val message: String, val reply: JSONObject?)

/** Runs a save: busy while it runs, and its refusal shown as the form's error. */
fun CoroutineScope.launchSave(setBusy: (Boolean) -> Unit, setError: (String?) -> Unit, block: suspend () -> Unit): kotlinx.coroutines.Job {
    // Busy at once, before the coroutine starts: a second tap in the same moment is turned away
    // by the caller's busy check rather than sending twice (4.26.0).
    setBusy(true); setError(null)
    return launch {
        try { block() } catch (e: ApiException) { setError(e.message) } finally { setBusy(false) }
    }
}

/**
 * Saves now, or with no signal keeps it on the phone to send later (the same entry, with the same
 * idem_key, so it can never be saved twice). Says which happened.
 *
 * 4.29.0: the send-or-keep runs in [Api.scope], which outlives the screen, so leaving the form while
 * it saves (Back, or a tap outside a dialog) never loses the entry: it is sent or kept either way,
 * and what happened is said on the screen the person went to. A queued case note carries the time
 * it was written (recorded_at), and "still being processed" keeps it to send again.
 */
suspend fun saveOrQueue(api: Api, action: String, body: JSONObject, label: String, saved: String = "Saved.", offlineExtras: (JSONObject) -> Unit = {}): Saved {
    val owner = api.userId
    // The session it was sent with, and the wipe count (4.29.0): an outcome is said only to the same
    // account in the same session, and nothing is kept after a wipe.
    val session = api.token
    val wipes = api.wipes
    val writtenAt = isoTime(System.currentTimeMillis())
    val work = api.scope.async { sendOrKeep(api, action, body, label, saved, owner, session, wipes, writtenAt, offlineExtras) }
    return try {
        work.await()
    } catch (e: CancellationException) {
        // The screen was left while saving: the work goes on, and its outcome is said where the person is now,
        // but only to the account that wrote it, still in the same session (never one account's labels to another).
        work.invokeOnCompletion { failure ->
            if (failure is CancellationException) return@invokeOnCompletion
            if (api.userId != owner || api.token != session || api.token.isEmpty()) return@invokeOnCompletion
            if (failure != null) { api.syncNotice = "“$label” was not saved: " + (failure.message ?: "it was refused."); return@invokeOnCompletion }
            @OptIn(ExperimentalCoroutinesApi::class)
            val r = work.getCompleted()
            // Kept to send later: said, since the form that would have said it is gone.
            if (r.reply == null) api.syncNotice = "“$label”: " + r.message
        }
        throw e
    }
}

private suspend fun sendOrKeep(api: Api, action: String, body: JSONObject, label: String, saved: String, owner: Int, session: String, wipes: Int,
                               writtenAt: String, offlineExtras: (JSONObject) -> Unit): Saved {
    fun keepForLater() {
        offlineExtras(body)
        // A case note sent later keeps the time it was written here (the server shows both when they differ).
        if (action == "add_note" && !body.has("recorded_at")) body.put("recorded_at", writtenAt)
    }
    return try {
        val r = api.call(action, body = body)
        Saved(if (r.optBoolean("deduplicated")) "This was already saved from an earlier try, so it was not added twice." else r.optString("message").ifEmpty { saved }, r)
    } catch (e: ApiException) {
        // 4.29.0: the session changed while this was sent (signed out, another account, the session ended):
        // whether it was saved is not known, so it is kept for the account that wrote it, with its retry key,
        // and sent when that account is signed in again. Never after a wipe.
        if (api.token != session) {
            if (api.wipes != wipes || owner <= 0) throw e
            keepForLater()
            api.enqueue(action, body, label, owner, forOwnerWhoLeft = true)
            return Saved("The account changed while this was being sent, so it is kept on this phone and sent when that account signs in again. It is never saved twice.", null)
        }
        // Already checked in (another phone, or an earlier try whose reply was lost): it is recorded.
        if (e.status == 409 && action == "check_in_session") return Saved(e.message ?: "Already checked in.", JSONObject())
        val processing = stillProcessing(e)
        if (!e.network && !processing) throw e
        keepForLater()
        api.enqueue(action, body, label, owner, forOwnerWhoLeft = api.userId != owner)
        if (processing) return Saved("The server is still saving an earlier try of this. It is kept on this phone and sent again with the same key, so it is never saved twice.", null)
        // A redirect (4.28.0) is not "no signal": its own words say what to check.
        if (e.status in 300..399) return Saved((e.message ?: "") + " This entry is kept on this phone.", null)
        Saved("No signal. This is kept on this phone and saved when you are back online.", null)
    }
}

/**
 * While a save is under way (4.29.0), the first Back says the save will finish even if they leave,
 * and a second Back leaves (the save itself finishes either way; see [saveOrQueue]).
 */
@androidx.compose.runtime.Composable
fun HoldBackWhileSaving(busy: Boolean) {
    var warned by androidx.compose.runtime.remember { mutableStateOf(false) }
    androidx.compose.runtime.LaunchedEffect(busy) { if (!busy) warned = false }
    val view = androidx.compose.ui.platform.LocalView.current
    androidx.activity.compose.BackHandler(enabled = busy && !warned) {
        warned = true
        // 4.30.0: said once: TalkBack reads the toast, so there is no announcement as well.
        android.widget.Toast.makeText(view.context, "Still saving; it will finish even if you leave. Press Back again to leave.", android.widget.Toast.LENGTH_LONG).show()
    }
}
