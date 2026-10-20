package org.casemanagement.app.ui

import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.safeDrawingPadding
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.focus.FocusRequester
import androidx.compose.ui.focus.focusRequester
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import kotlinx.coroutines.launch
import org.casemanagement.app.Api
import org.casemanagement.app.ApiException
import org.casemanagement.app.BuildConfig
import org.casemanagement.app.ServerAddress
import org.json.JSONObject

/**
 * Sign in as on the website and the iPhone app: email and password, then the code that was
 * emailed (or the authenticator app's). A build that names no server asks for the address first.
 */
@Composable
fun LoginScreen(api: Api, reauth: Boolean = false) {
    val scope = rememberCoroutineScope()
    // Signing back in to the same account never changes the server.
    val asksForServer = api.builtInServer == null && !reauth
    var server by rememberSaveable { mutableStateOf(api.storedServer) }
    var email by rememberSaveable { mutableStateOf(if (reauth) api.lastEmail else "") }
    var leaving by remember { mutableStateOf(false) }
    var password by remember { mutableStateOf("") }
    var code by rememberSaveable { mutableStateOf("") }
    var challenge by rememberSaveable { mutableStateOf("") }
    var awaitingCode by rememberSaveable { mutableStateOf(false) }
    var codeHelp by rememberSaveable { mutableStateOf("") }
    // 4.27.0: an account with an authenticator app signs in with its code only (no emailed code).
    var totpOnly by rememberSaveable { mutableStateOf(false) }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    var notice by remember { mutableStateOf<String?>(null) }
    // 4.27.0: the other server the person agreed to sign in to, after being told what it removes.
    var agreedServer by rememberSaveable { mutableStateOf("") }
    var askSwitch by remember { mutableStateOf<Int?>(null) }
    val codeFocus = remember { FocusRequester() }

    fun host() = ServerAddress.host(api.base)

    suspend fun passwordStep(resend: Boolean) {
        if (password.isEmpty()) {
            // The password is never kept beyond the screen (a rotated screen forgets it): ask again.
            awaitingCode = false; code = ""; notice = null
            error = "Enter your password again to get a new code."
            return
        }
        busy = true; error = null
        try {
            if (asksForServer) {
                if (ServerAddress.apiUrl(server, BuildConfig.DEBUG) == null) {
                    error = "Enter your organisation's web address, such as cms.example.org. It must be a secure (https) address."
                    return
                }
                // Signing in to another server removes what is waiting to send: said first, with how much.
                val waiting = api.unsentOnPhone
                if (api.isOtherServer(server) && waiting > 0 && !ServerAddress.same(server, agreedServer, BuildConfig.DEBUG)) {
                    askSwitch = waiting
                    return
                }
                // Nothing is removed yet: only once signing in to it has worked (4.27.0).
                api.tryServer(server)
            }
            val step = api.call("login", body = JSONObject().put("email", email.trim()).put("password", password))
            challenge = step.optString("login_challenge")
            val sent = step.optBoolean("email_sent", true)
            val minutes = step.optInt("code_expires_minutes", 10)
            totpOnly = step.optBoolean("totp_required")
            codeHelp = loginCodeHelp(sent, step.optBoolean("totp_available"), totpOnly, minutes)
            code = ""
            awaitingCode = true
            notice = if (resend && !totpOnly) "A new code was sent. Codes sent earlier no longer work." else "Password accepted. $codeHelp"
        } catch (e: ApiException) {
            // 4.28.0: an address that did not sign in is not kept in use.
            api.clearTrialServer()
            error = (if (asksForServer && !resend) "Signing in to ${host()} did not work. Check the address if it is new. " else "") + e.message
        } finally {
            busy = false
        }
    }

    suspend fun verify() {
        busy = true; error = null
        try {
            val body = JSONObject().put("email", email.trim()).put("code", code.filter { it.isDigit() })
                .put("device_name", Api.deviceName)
            if (challenge.isNotEmpty()) body.put("login_challenge", challenge)
            if (asksForServer) api.tryServer(server)
            val r = api.call("verify", body = body)
            // Signed in: another server's entries and account go now, not before (4.27.0).
            if (asksForServer) api.commitServer(server)
            api.finishSignIn(r.optString("token"), r.optInt("user_id"), r.optString("name"), email.trim())
        } catch (e: ApiException) {
            // Set again by the next try (4.28.0).
            if (asksForServer) api.clearTrialServer()
            error = e.message
        } finally {
            busy = false
        }
    }

    // A session that ended may since have been reported lost (4.27.0): asked about while this shows.
    LaunchedEffect(Unit) { while (true) { api.checkRefusedSession(); kotlinx.coroutines.delay(60_000) } }
    LaunchedEffect(awaitingCode) { if (awaitingCode) try { codeFocus.requestFocus() } catch (e: Exception) { } }

    Surface(Modifier.fillMaxSize().safeDrawingPadding().imePadding()) {
        Page {
            Heading(BuildConfig.SIGNIN_TITLE, large = true)
            api.sessionNotice?.let { NoticeText(it) }
            // Signing back in as someone else leaves this account's unsent signatures waiting: said first.
            if (reauth) api.unsentSignaturesWarning?.let { NoticeText("$it Sign in as ${api.userName.ifEmpty { "yourself" }} to send them now.") }
            if (!awaitingCode) {
                if (asksForServer) {
                    Field("Your organisation's web address", server, { server = it }, hint = "for example cms.example.org", keyboard = KeyboardType.Uri, required = true)
                }
                // 4.30.0: a password manager or the autofill service can fill these in.
                Field("Email", email, { email = it }, keyboard = KeyboardType.Email, required = true, autofill = Fill.Email)
                Field("Password", password, { password = it }, password = true, required = true, autofill = Fill.Password)
                ErrorText(error)
                PrimaryButton("Continue", busy = busy, missing = stillNeeded(missingNames("Your organisation's web address" to (asksForServer && server.isBlank()), "Email" to email.isBlank(), "Password" to password.isEmpty()))) { scope.launch { passwordStep(false) } }
                if (!asksForServer) Text("Signing in to ${host()}", style = MaterialTheme.typography.bodySmall, modifier = Modifier.padding(top = 8.dp))
                if (reauth) SecondaryButton("Sign in as someone else instead") {
                    if (api.unsentSignaturesWarning != null && !leaving) leaving = true else api.abandonReauth()
                }
                askSwitch?.let { n ->
                    val items = if (n == 1) "1 item" else "$n items"
                    val before = ServerAddress.host(ServerAddress.apiUrl(api.storedServer, BuildConfig.DEBUG) ?: api.storedServer)
                    Confirm("Sign in to a different server?",
                        "This phone has $items waiting to be sent to $before (offline entries, or signatures taken with no signal). " +
                            "Signing in to a different server removes ${if (n == 1) "it" else "them"} from this phone, and ${if (n == 1) "it is" else "they are"} lost. " +
                            "To keep ${if (n == 1) "it" else "them"}, choose Cancel, sign in to $before and let ${if (n == 1) "it" else "them"} send first.",
                        "Remove ${if (n == 1) "it" else "them"} and continue",
                        { agreedServer = server; scope.launch { passwordStep(false) } }, { askSwitch = null })
                }
                if (leaving) Confirm("Sign in as someone else?", (api.unsentSignaturesWarning ?: "") + " Documents prepared to sign on this phone are removed.", "Sign in as someone else",
                    { api.abandonReauth() }, { leaving = false })
            } else {
                NoticeText(notice)
                // The instruction was just read in the notice above, so the field's own hint is short.
                Field("Sign-in code", code, { code = it.filter { c -> c.isDigit() }.take(8) }, hint = "6 digits", keyboard = KeyboardType.NumberPassword, required = true, autofill = Fill.Code,
                    modifier = Modifier.focusRequester(codeFocus))
                ErrorText(error)
                PrimaryButton("Sign in", busy = busy, missing = if (code.length >= 6) null else "Enter the 6-digit code first.") { scope.launch { verify() } }
                if (!totpOnly) SecondaryButton("Send a new code") { scope.launch { passwordStep(true) } }
                SecondaryButton("Start again") { api.clearTrialServer(); awaitingCode = false; code = ""; password = ""; error = null; notice = null }
            }
        }
    }
}

/**
 * What the code step says (4.27.0): [totpRequired] (an account with an authenticator app) asks for
 * that app's code only, as the emailed code does not work for it.
 */
fun loginCodeHelp(emailSent: Boolean, totpAvailable: Boolean, totpRequired: Boolean, minutes: Int): String = when {
    totpRequired -> "Enter the 6-digit code from your authenticator app."
    !emailSent -> "The code email could not be sent. Enter the code from your authenticator app."
    totpAvailable -> "Enter the 6-digit code we emailed you (it works for $minutes minutes), or the code from your authenticator app."
    else -> "Enter the 6-digit code we emailed you. It works for $minutes minutes."
}
