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
    var email by rememberSaveable { mutableStateOf("") }
    var password by remember { mutableStateOf("") }
    var code by rememberSaveable { mutableStateOf("") }
    var challenge by rememberSaveable { mutableStateOf("") }
    var awaitingCode by rememberSaveable { mutableStateOf(false) }
    var codeHelp by rememberSaveable { mutableStateOf("") }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    var notice by remember { mutableStateOf<String?>(null) }
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
                if (ServerAddress.apiUrl(server) == null) {
                    error = "Enter your organisation's web address, such as cms.example.org. It must be a secure (https) address."
                    return
                }
                api.switchServer(server)
            }
            val step = api.call("login", body = JSONObject().put("email", email.trim()).put("password", password))
            challenge = step.optString("login_challenge")
            val sent = step.optBoolean("email_sent", true)
            val minutes = step.optInt("code_expires_minutes", 10)
            val totp = step.optBoolean("totp_available")
            codeHelp = when {
                !sent -> "The code email could not be sent. Enter the code from your authenticator app."
                totp -> "Enter the 6-digit code we emailed you (it works for $minutes minutes), or the code from your authenticator app."
                else -> "Enter the 6-digit code we emailed you. It works for $minutes minutes."
            }
            code = ""
            awaitingCode = true
            notice = if (resend) "A new code was sent. Codes sent earlier no longer work." else "Password accepted. $codeHelp"
        } catch (e: ApiException) {
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
            val r = api.call("verify", body = body)
            api.finishSignIn(r.optString("token"), r.optInt("user_id"), r.optString("name"))
        } catch (e: ApiException) {
            error = e.message
        } finally {
            busy = false
        }
    }

    LaunchedEffect(awaitingCode) { if (awaitingCode) try { codeFocus.requestFocus() } catch (e: Exception) { } }

    Surface(Modifier.fillMaxSize().safeDrawingPadding().imePadding()) {
        Page {
            Heading(BuildConfig.SIGNIN_TITLE, large = true)
            api.sessionNotice?.let { NoticeText(it) }
            if (!awaitingCode) {
                if (asksForServer) {
                    Field("Your organisation's web address", server, { server = it }, hint = "for example cms.example.org", keyboard = KeyboardType.Uri)
                }
                Field("Email", email, { email = it }, keyboard = KeyboardType.Email)
                Field("Password", password, { password = it }, password = true)
                ErrorText(error)
                PrimaryButton("Continue", busy = busy, enabled = email.isNotBlank() && password.isNotEmpty()) { scope.launch { passwordStep(false) } }
                if (!asksForServer) Text("Signing in to ${host()}", style = MaterialTheme.typography.bodySmall, modifier = Modifier.padding(top = 8.dp))
                if (reauth) SecondaryButton("Sign in as someone else instead") { api.abandonReauth() }
            } else {
                NoticeText(notice)
                Field("Sign-in code", code, { code = it.filter { c -> c.isDigit() }.take(8) }, hint = codeHelp, keyboard = KeyboardType.NumberPassword,
                    modifier = Modifier.focusRequester(codeFocus))
                ErrorText(error)
                PrimaryButton("Sign in", busy = busy, enabled = code.length >= 6) { scope.launch { verify() } }
                SecondaryButton("Send a new code") { scope.launch { passwordStep(true) } }
                SecondaryButton("Start again") { awaitingCode = false; code = ""; password = ""; error = null; notice = null }
            }
        }
    }
}
