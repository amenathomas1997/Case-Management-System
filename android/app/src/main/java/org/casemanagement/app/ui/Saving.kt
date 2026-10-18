package org.casemanagement.app.ui

import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.launch
import org.casemanagement.app.Api
import org.casemanagement.app.ApiException
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
 */
suspend fun saveOrQueue(api: Api, action: String, body: JSONObject, label: String, saved: String = "Saved.", offlineExtras: (JSONObject) -> Unit = {}): Saved {
    val owner = api.userId
    return try {
        val r = api.call(action, body = body)
        Saved(if (r.optBoolean("deduplicated")) "This was already saved from an earlier try, so it was not added twice." else r.optString("message").ifEmpty { saved }, r)
    } catch (e: ApiException) {
        // Already checked in (another phone, or an earlier try whose reply was lost): it is recorded.
        if (e.status == 409 && action == "check_in_session") return Saved(e.message ?: "Already checked in.", JSONObject())
        if (!e.network) throw e
        offlineExtras(body)
        api.enqueue(action, body, label, owner)
        Saved("No signal. This is kept on this phone and saved when you are back online.", null)
    }
}
