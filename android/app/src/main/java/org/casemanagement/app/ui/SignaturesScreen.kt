package org.casemanagement.app.ui

import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.AnnotatedString
import androidx.compose.ui.text.SpanStyle
import androidx.compose.ui.text.intl.LocaleList
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import androidx.compose.ui.window.Dialog
import androidx.compose.ui.window.DialogProperties
import androidx.navigation.NavHostController
import kotlinx.coroutines.launch
import org.casemanagement.app.Api
import org.casemanagement.app.ApiException
import org.casemanagement.app.BodyBlock
import org.casemanagement.app.SIGN_WINDOW_MS
import org.casemanagement.app.SignatureItem
import org.casemanagement.app.isoTime
import org.casemanagement.app.parseReleaseChoices
import org.casemanagement.app.parseSignatureDoc
import org.casemanagement.app.parseSignatureReply
import org.casemanagement.app.parseSignatures
import org.casemanagement.app.signingStart
import org.casemanagement.app.stillSignable
import org.casemanagement.app.typedNameMatches
import org.casemanagement.app.signatureBlocks
import org.casemanagement.app.str
import org.json.JSONArray
import org.json.JSONObject

/**
 * A client's documents to sign (4.25.0): what was sent and its state, a new one emailed for
 * signing, or signed here and now on this phone, handed to the client. Since 4.26.0 a document
 * can be prepared while there is a signal and signed later with none (in a client's home): the
 * prepared copies and the signatures waiting to send are listed first, and show with no signal.
 */
@Composable
fun SignaturesScreen(api: Api, clientId: Int) {
    val state = rememberLoaded(clientId) {
        val r = parseSignatures(api.call("signatures", mapOf("client_id" to clientId.toString())))
        val c = try { api.call("client", mapOf("id" to clientId.toString())).optJSONObject("client") } catch (e: ApiException) { null }
        // Kept copies the server no longer has waiting (withdrawn, or signed elsewhere) are dropped.
        api.prunePrepared(clientId, r.items.filter { it.signedHere }.map { it.id }.toSet())
        r to (c ?: JSONObject())
    }
    var notice by remember { mutableStateOf<String?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    var busy by remember { mutableStateOf(false) }
    var chosen by remember { mutableStateOf<SignatureItem?>(null) }
    var chosenPrepared by remember { mutableStateOf<JSONObject?>(null) }
    var chosenKept by remember { mutableStateOf<JSONObject?>(null) }
    var withdrawing by remember { mutableStateOf<SignatureItem?>(null) }
    var kind by rememberSaveable { mutableStateOf("services") }
    var role by rememberSaveable { mutableStateOf("Client") }
    var signerName by rememberSaveable { mutableStateOf<String?>(null) }
    var signerEmail by rememberSaveable { mutableStateOf<String?>(null) }
    var loan by rememberSaveable { mutableStateOf("") }
    var title by rememberSaveable { mutableStateOf("") }
    var body by rememberSaveable { mutableStateOf("") }
    val scope = rememberCoroutineScope()
    val prepared = api.preparedSignatures.filter { it.optInt("client_id") == clientId }
    val kept = api.keptSignatures.filter { it.optInt("client_id") == clientId }

    Page {
        NoticeText(notice)
        ErrorText(error)
        // Kept on this phone: shown even when the list below cannot be read (no signal).
        if (kept.isNotEmpty()) {
            Heading("Signed here, waiting to send (${kept.size})")
            kept.forEach { k ->
                val refused = k.str("lastError")
                ListRow(listOfNotNull(k.optString("title"), "Signed by " + k.optString("typed_name") + " at " + isoWhen(k.optString("signed_at")),
                    refused?.let { "Not accepted: $it" } ?: "Kept on this phone, encrypted. It is sent automatically when there is a connection."),
                    action = "see what you can do", onClick = { chosenKept = k })
            }
            SecondaryButton("Send now") {
                if (busy) return@SecondaryButton
                error = null; notice = null
                busy = true
                scope.launch {
                    api.flushSignatures(retryRefused = true)
                    busy = false
                    val left = api.keptSignatures.count { it.optInt("client_id") == clientId }
                    notice = when {
                        left == 0 -> "Sent. The server has every signature taken here."
                        api.keptSignatures.any { it.optInt("client_id") == clientId && it.has("lastError") } -> null
                        else -> "Not sent yet: there may be no signal. They are still kept on this phone."
                    }
                    if (left < kept.size) state.reload()
                }
            }
        }
        if (prepared.isNotEmpty()) {
            Heading("Ready to sign here, even with no signal (${prepared.size})")
            val now = System.currentTimeMillis()
            prepared.forEach { p ->
                val at = p.optLong("prepared_at")
                ListRow(listOf(p.optString("title"), "For " + p.optString("signer_name"),
                    if (stillSignable(at, now)) "Can be signed until " + java.text.DateFormat.getDateTimeInstance(java.text.DateFormat.MEDIUM, java.text.DateFormat.SHORT).format(java.util.Date(at + SIGN_WINDOW_MS))
                    else "Prepared more than two days ago, so it can no longer be signed. Prepare it again."),
                    action = "see what you can do", onClick = { chosenPrepared = p })
            }
        }

        Show(state, "documents to sign") { (r, c) ->
            val list = r.items
            // Who signs starts as the client (or their guardian), as the website's form fills it.
            fun nameFor(rl: String) = (if (rl == "Guardian") c.str("guardian_name") else c.str("name")) ?: ""
            fun emailFor(rl: String) = (if (rl == "Guardian") c.str("guardian_email") else c.str("email")) ?: ""
            val name = signerName ?: nameFor(role)
            val email = signerEmail ?: emailFor(role)
            fun request(): JSONObject? {
                val b = JSONObject().put("client_id", clientId).put("kind", kind).put("signer_role", role).put("signer_name", name.trim()).put("signer_email", email.trim())
                when (kind) {
                    "loan" -> { if (loan.isEmpty()) { error = "Choose the device on loan."; return null }; b.put("loan_id", loan.toInt()) }
                    "document" -> {
                        if (title.isBlank() || body.isBlank()) { error = "Give the document a title and write what they agree to."; return null }
                        b.put("title", title.trim()).put("body", body.trim())
                    }
                }
                if (name.isBlank()) { error = "Enter the full name of the person who signs."; return null }
                return b
            }
            fun clearForm() { title = ""; body = ""; loan = "" }
            Heading("Documents to sign (${list.size})")
            if (list.isEmpty()) Text("Nothing has been sent to sign yet.")
            list.forEach { s ->
                val waitingHere = kept.any { it.optInt("id") == s.id }
                ListRow(listOf(s.title,
                    listOfNotNull(if (waitingHere) "Signed here, waiting to send" else s.status, s.method.ifEmpty { null }, s.signerName?.let { "for $it" }).joinToString(", "),
                    s.signedAt?.let { "Signed " + whenText(it) + (s.signedName?.let { n -> " as $n" } ?: "") + (if (s.hasDrawnSignature) ", with a drawn signature" else "") }
                        ?: s.sentAt?.let { "Sent " + whenText(it) } ?: ""),
                    action = "see what you can do", onClick = if (s.open && !waitingHere) ({ chosen = s }) else null)
            }

            Heading("Prepare a document to sign")
            Choice("What to sign", kind, r.kinds) { kind = it; error = null }
            if (kind == "loan") {
                if (r.openLoans.isEmpty()) Text("This client has no equipment on loan.")
                else Choice("Device on loan", loan, listOf("" to "Choose a device") + r.openLoans.map { it.id.toString() to (it.name + (it.serial?.let { s -> ", serial $s" } ?: "")) }) { loan = it }
            }
            if (kind == "document") {
                Field("Title", title, { title = it })
                Field("What they agree to", body, { body = it }, hint = "a blank line starts a new paragraph", singleLine = false, minLines = 5)
            }
            Choice("Who signs", role, listOf("Client" to "The client", "Guardian" to "Their guardian")) { role = it; signerName = null; signerEmail = null }
            Field("Their full name", name, { signerName = it })
            Field("Their email", email, { signerEmail = it }, hint = "needed to send by email", keyboard = KeyboardType.Email)
            PrimaryButton("Sign here now", busy = busy) {
                if (busy) return@PrimaryButton
                val b = request() ?: return@PrimaryButton
                scope.launchSave({ busy = it }, { error = it }) {
                    notice = null
                    val res = api.call("start_signature", body = b)
                    clearForm()
                    api.beginInPerson(clientId, res)
                }
            }
            SecondaryButton("Prepare to sign later, even with no signal") {
                if (busy) return@SecondaryButton
                val b = request() ?: return@SecondaryButton
                scope.launchSave({ busy = it }, { error = it }) {
                    notice = null
                    val doc = parseSignatureDoc(api.call("start_signature", body = b)) ?: throw ApiException("The server's answer could not be read. Nothing was kept on this phone.")
                    try { api.prepareToSign(clientId, c.str("name") ?: "", doc) } finally { state.reload() }
                    clearForm()
                    notice = "“${doc.title}” is ready to sign on this phone, even with no signal, for two days. It is listed above."
                }
            }
            SecondaryButton("Send by email") {
                if (busy) return@SecondaryButton
                if (email.isBlank()) { error = "Enter their email address to send it by email, or choose Sign here now."; return@SecondaryButton }
                val b = request() ?: return@SecondaryButton
                scope.launchSave({ busy = it }, { error = it }) {
                    notice = null
                    api.call("send_signature", body = b)
                    clearForm()
                    notice = "Sent to ${email.trim()} to sign. It is listed above as Awaiting signature."
                    state.reload()
                }
            }
        }
    }

    chosen?.let { s ->
        val c = state.data?.second ?: JSONObject()
        AlertDialog(
            onDismissRequest = { chosen = null },
            title = { Text(s.title, modifier = Modifier.semantics { heading() }) },
            text = {
                Column {
                    Text(s.status + ".")
                    if (s.signedHere) {
                        TextButton(onClick = {
                            chosen = null; error = null
                            scope.launch {
                                try {
                                    val d = api.call("signature", mapOf("client_id" to clientId.toString(), "id" to s.id.toString())).optJSONObject("signature")
                                    // Its start is the list's sent_at (the signature action does not send it).
                                    if (d != null) api.beginInPerson(clientId, d, startedAt = signingStart(s.sentAt, serverZoneId, System.currentTimeMillis()))
                                } catch (e: ApiException) { error = e.message }
                            }
                        }) { Text("Sign here now") }
                        if (prepared.none { it.optInt("id") == s.id }) TextButton(onClick = {
                            chosen = null; error = null; notice = null
                            scope.launch {
                                try {
                                    val d = parseSignatureReply(api.call("signature", mapOf("client_id" to clientId.toString(), "id" to s.id.toString())))
                                        ?: throw ApiException("The document could not be read. Nothing was kept on this phone.")
                                    // The two days the server allows run from when it was started, not from now.
                                    api.prepareToSign(clientId, c.str("name") ?: "", d, signingStart(s.sentAt, serverZoneId, System.currentTimeMillis()))
                                    notice = "“${d.title}” is ready to sign on this phone, even with no signal. It is listed above."
                                } catch (e: ApiException) { error = e.message }
                            }
                        }) { Text("Keep on this phone to sign with no signal") }
                    }
                    TextButton(onClick = { chosen = null; withdrawing = s }) { Text("Withdraw it") }
                }
            },
            confirmButton = { TextButton(onClick = { chosen = null }) { Text("Close") } },
        )
    }
    chosenPrepared?.let { p ->
        val id = p.optInt("id")
        val signable = stillSignable(p.optLong("prepared_at"), System.currentTimeMillis())
        AlertDialog(
            onDismissRequest = { chosenPrepared = null },
            title = { Text(p.optString("title"), modifier = Modifier.semantics { heading() }) },
            text = {
                Column {
                    Text(if (signable) "Ready to sign here, even with no signal. For ${p.optString("signer_name")}."
                        else "Prepared more than two days ago, so the server would not accept a signature now. Remove it, then prepare it again.")
                    if (signable) TextButton(onClick = {
                        chosenPrepared = null; error = null; notice = null
                        api.beginInPerson(clientId, JSONObject(p.toString()).apply { remove("owner"); remove("prepared_at"); remove("client_name") }, prepared = true, startedAt = p.optLong("prepared_at"))
                    }) { Text("Sign here now") }
                    TextButton(onClick = {
                        chosenPrepared = null
                        scope.launch { api.removePrepared(id); notice = "Removed from this phone. It is still waiting to be signed here on the website; withdraw it there or below if it is not needed." }
                    }) { Text("Remove from this phone") }
                }
            },
            confirmButton = { TextButton(onClick = { chosenPrepared = null }) { Text("Close") } },
        )
    }
    chosenKept?.let { k ->
        val refused = k.str("lastError")
        AlertDialog(
            onDismissRequest = { chosenKept = null },
            title = { Text(k.optString("title"), modifier = Modifier.semantics { heading() }) },
            text = {
                Column {
                    Text(refused?.let { "The server did not accept this signature: $it" }
                        ?: "Signed with no signal and kept on this phone, encrypted. It is sent automatically when there is a connection.")
                    if (refused != null) TextButton(onClick = {
                        chosenKept = null
                        scope.launch { api.discardKeptSignature(k.optInt("id")); notice = "The kept signature was removed from this phone." }
                    }) { Text("Remove it from this phone") }
                }
            },
            confirmButton = { TextButton(onClick = { chosenKept = null }) { Text("Close") } },
        )
    }
    withdrawing?.let { s ->
        Confirm("Withdraw “${s.title}”?", "It can no longer be signed. A link already emailed stops working.", "Withdraw", {
            scope.launch {
                try {
                    api.call("withdraw_signature", body = JSONObject().put("client_id", clientId).put("id", s.id))
                    api.removePrepared(s.id)
                    notice = s.title + " withdrawn."; state.reload()
                } catch (e: ApiException) { error = e.message }
            }
        }, { withdrawing = null })
    }
    // Back from signing in person (after staff unlocked the phone): read the list again.
    androidx.compose.runtime.LaunchedEffect(api.inPersonSigning == null) { if (api.inPersonSigning == null && state.data != null) state.reload() }
}

/** A time kept as ISO 8601 (2026-10-18T14:05:00-05:00), as people say it. */
private fun isoWhen(iso: String): String = try {
    val d = java.text.SimpleDateFormat("yyyy-MM-dd'T'HH:mm:ssXXX", java.util.Locale.US).parse(iso)
    if (d == null) iso else java.text.DateFormat.getDateTimeInstance(java.text.DateFormat.MEDIUM, java.text.DateFormat.SHORT).format(d)
} catch (e: Exception) { iso }

/** Text marked with its language, so TalkBack reads Spanish in a Spanish voice where it can. */
private fun inLanguage(text: String, lang: String?): AnnotatedString =
    if (lang.isNullOrBlank()) AnnotatedString(text)
    else AnnotatedString(text, listOf(AnnotatedString.Range(SpanStyle(localeList = LocaleList(lang)), 0, text.length)))

/** The signing view's words, in English or Spanish as the document is. */
private class SignWords(es: Boolean, org: String, name: String) {
    val we = if (es) "En este documento, “nosotros” significa $org y “usted” significa la persona que firma." else "In this document, “we” means $org and “you” means the person who signs."
    val nameIs = if (es) "El nombre en este documento es $name." else "The name on this document is $name."
    val agree = if (es) "Al firmar, usted acepta lo siguiente:" else "When you sign, you agree to the following:"
    val typeLabel = if (es) "Escriba su nombre completo para firmar" else "Type your full name to sign"
    val typeHint = if (es) "No importa si las mayúsculas, los acentos o la puntuación son diferentes." else "Capital letters, accents and punctuation do not need to match."
    val draw = if (es) "Opcional: también puede firmar con el dedo abajo. Su nombre escrito es suficiente." else "Optional: you may also sign with a finger below. Your typed name is enough."
    val sign = if (es) "Firmar" else "Sign"
    val thanks = if (es) "Gracias. Quedó firmado. Devuelva el teléfono, por favor." else "Thank you. It is signed. Please hand the phone back."
    val thanksOffline = if (es) "Gracias. Quedó firmado. No hay señal ahora, así que se enviará cuando el teléfono tenga conexión. Devuelva el teléfono, por favor."
        else "Thank you. It is signed. There is no signal now, so it will be sent when the phone has a connection. Please hand the phone back."
    val tooLate = if (es) "Este documento se preparó hace más de dos días, así que ya no se puede firmar. No se guardó nada. Devuelva el teléfono al personal, por favor."
        else "This document was started more than two days ago, so it can no longer be signed. Nothing was kept. Please hand the phone back to staff."
    val mismatch = if (es) "El nombre escrito no coincide con el nombre en este documento: $name. Escríbalo de nuevo."
        else "The name typed does not match the name on this document: $name. Type it again."
    val done = if (es) "Listo" else "Done"
    val handBack = if (es) "Devolver sin firmar" else "Hand back without signing"
}

/**
 * Signing in person (4.25.0), above everything in the app while [Api.inPersonSigning] is set: the
 * whole screen the client is handed, usable entirely with TalkBack (a typed name is all that is
 * needed; drawing is optional). Leaving it in any way (Back, hand back, done, the app restarted)
 * asks for the phone's own lock first, so the signer never reaches client records; a phone with
 * no screen lock can only sign staff out. Left unsigned, the request is withdrawn, unless it was
 * prepared to sign later (4.26.0), when it stays ready on the phone. With no signal (4.26.0) the
 * signature is kept on the phone, encrypted, and sent when the connection returns; the unlock is
 * the same either way, as it needs no connection.
 */
@Composable
fun InPersonSigningHost(api: Api) {
    val d = api.inPersonSigning ?: return
    val clientId = d.optInt("_client_id")
    val signed = d.optBoolean("_signed")
    val prepared = d.optBoolean("_prepared")
    var typed by rememberSaveable(d.optInt("id")) { mutableStateOf("") }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    // Asking for the staff member's unlock (true), shown instead of the document.
    var locked by rememberSaveable(d.optInt("id")) { mutableStateOf(false) }
    var lockError by remember { mutableStateOf<String?>(null) }
    var noLock by remember { mutableStateOf(false) }
    val pad = remember(d.optInt("id")) { SignatureState() }
    val density = LocalDensity.current.density
    val scope = rememberCoroutineScope()
    val context = androidx.compose.ui.platform.LocalContext.current
    val lang = d.str("lang")
    val w = SignWords(lang == "es", d.optString("org"), d.optString("signer_name"))

    fun finish() {
        val now = api.inPersonSigning
        val wasSigned = now?.optBoolean("_signed") == true
        val offline = now?.optBoolean("_offline") == true
        if (!wasSigned && !prepared) api.scope.launch {
            try { api.call("withdraw_signature", body = JSONObject().put("client_id", clientId).put("id", d.optInt("id"))) } catch (e: Exception) { }
        }
        Flash.message = d.optString("title") + when {
            wasSigned && offline -> " was signed in person with no signal. The signature is kept on this phone, encrypted, and sent automatically when there is a connection."
            wasSigned -> " was signed in person."
            prepared -> " was not signed. It stays ready to sign on this phone."
            else -> " was not signed, so the request was withdrawn."
        }
        api.endInPerson()
    }
    val credential = androidx.activity.compose.rememberLauncherForActivityResult(androidx.activity.result.contract.ActivityResultContracts.StartActivityForResult()) { res ->
        if (res.resultCode == android.app.Activity.RESULT_OK) finish() else lockError = "Not unlocked. Staff: unlock to continue."
    }
    fun unlock() {
        lockError = null
        val activity = context.findActivity()
        val allowed = androidx.biometric.BiometricManager.Authenticators.BIOMETRIC_WEAK or androidx.biometric.BiometricManager.Authenticators.DEVICE_CREDENTIAL
        if (activity is androidx.fragment.app.FragmentActivity && androidx.biometric.BiometricManager.from(context).canAuthenticate(allowed) == androidx.biometric.BiometricManager.BIOMETRIC_SUCCESS) {
            val prompt = androidx.biometric.BiometricPrompt(activity, androidx.core.content.ContextCompat.getMainExecutor(context), object : androidx.biometric.BiometricPrompt.AuthenticationCallback() {
                override fun onAuthenticationSucceeded(result: androidx.biometric.BiometricPrompt.AuthenticationResult) { finish() }
                override fun onAuthenticationError(errorCode: Int, errString: CharSequence) { lockError = "Not unlocked: $errString. Staff: unlock to continue." }
            })
            prompt.authenticate(androidx.biometric.BiometricPrompt.PromptInfo.Builder().setTitle("Staff: unlock to continue")
                .setSubtitle("Use this phone's lock to return to the app.").setAllowedAuthenticators(allowed).build())
            return
        }
        val keyguard = context.getSystemService(android.content.Context.KEYGUARD_SERVICE) as android.app.KeyguardManager
        @Suppress("DEPRECATION")
        val intent = if (keyguard.isDeviceSecure) keyguard.createConfirmDeviceCredentialIntent("Staff: unlock to continue", "Use this phone's lock to return to the app.") else null
        if (intent != null) credential.launch(intent) else noLock = true
    }
    fun leave() { if (!busy) { locked = true; unlock() } }

    Dialog(onDismissRequest = {}, properties = DialogProperties(usePlatformDefaultWidth = false, dismissOnBackPress = false, dismissOnClickOutside = false)) {
        androidx.activity.compose.BackHandler { if (!locked) leave() }
        Surface(Modifier.fillMaxSize(), color = MaterialTheme.colorScheme.background) {
            Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(horizontal = 16.dp, vertical = 16.dp)) {
                if (noLock) {
                    Heading("Hand the phone back to staff", large = true)
                    NoticeText("Staff: this phone has no screen lock, so the app cannot check it is you. Sign out to leave the signing screen; then sign in again.")
                    PrimaryButton("Sign out of the app") {
                        // Withdrawn first while the session still works, then signed out (which also ends the signing view).
                        val wasSigned = api.inPersonSigning?.optBoolean("_signed") == true
                        api.scope.launch {
                            if (!wasSigned) try { api.call("withdraw_signature", body = JSONObject().put("client_id", clientId).put("id", d.optInt("id"))) } catch (e: Exception) { }
                            api.signOut()
                        }
                    }
                    return@Column
                }
                if (locked) {
                    Heading("Staff: unlock to continue", large = true)
                    Announce("Staff: unlock to continue.")
                    Text("Use this phone's lock (fingerprint, face, PIN, pattern or password) to return to the app.")
                    ErrorText(lockError)
                    PrimaryButton("Unlock") { unlock() }
                    return@Column
                }
                Text(inLanguage(d.optString("title"), lang), style = MaterialTheme.typography.headlineSmall, modifier = Modifier.semantics { heading() })
                if (signed) {
                    NoticeText(if (d.optBoolean("_offline")) w.thanksOffline else w.thanks)
                    PrimaryButton(w.done) { leave() }
                    return@Column
                }
                Text(inLanguage(w.we, lang), modifier = Modifier.padding(top = 8.dp))
                Text(inLanguage(w.nameIs, lang), modifier = Modifier.padding(top = 8.dp))
                Text(inLanguage(w.agree, lang), modifier = Modifier.padding(vertical = 8.dp))
                signatureBlocks(d.optString("body")).forEach { b ->
                    when (b) {
                        is BodyBlock.Paragraph -> Text(inLanguage(b.text, lang), modifier = Modifier.padding(vertical = 6.dp))
                        is BodyBlock.Bullets -> b.items.forEach { Text(inLanguage("• $it", lang), modifier = Modifier.padding(start = 8.dp, top = 2.dp, bottom = 2.dp)) }
                    }
                }
                Field(w.typeLabel, typed, { typed = it }, hint = w.typeHint)
                SignaturePad(pad, caption = w.draw)
                ErrorText(error)
                PrimaryButton(w.sign, busy = busy, enabled = typed.isNotBlank()) {
                    if (busy) return@PrimaryButton
                    // Checked as Sign is pressed: the server takes it only within two days of its start.
                    val started = d.optLong("_started")
                    if (started > 0 && !stillSignable(started, System.currentTimeMillis())) { error = w.tooLate; return@PrimaryButton }
                    scope.launchSave({ busy = it }, { error = it }) {
                        // The time it was signed here, sent with it if it has to wait for a signal.
                        val signedAt = isoTime(System.currentTimeMillis())
                        val name = typed.trim()
                        val png = pad.png(density)
                        val b = JSONObject().put("client_id", clientId).put("id", d.optInt("id")).put("typed_name", name)
                        png?.let { b.put("signature_b64", it) }
                        try {
                            api.call("sign_in_person", body = b)
                            api.removePrepared(d.optInt("id"))
                            api.markInPersonSigned()
                        } catch (e: ApiException) {
                            // No connection (or no answer in time): kept here, encrypted, and sent later.
                            // The name is checked here as the server checks it, so a kept signature is not refused for it.
                            if (!e.network) throw e
                            if (!typedNameMatches(d.optString("signer_name"), name)) throw ApiException(w.mismatch)
                            api.keepSignature(clientId, d.optInt("id"), d.optString("title"), name, png, signedAt)
                            api.markInPersonSigned(offline = true)
                        }
                    }
                }
                SecondaryButton(w.handBack) { leave() }
            }
        }
    }
}

/** The activity a Compose context belongs to (a dialog's context wraps it). */
private tailrec fun android.content.Context.findActivity(): android.app.Activity? = when (this) {
    is android.app.Activity -> this
    is android.content.ContextWrapper -> baseContext.findActivity()
    else -> null
}

/** A release of information request (4.25.0), emailed to the client or guardian to sign. */
@Composable
fun ReleaseRequestScreen(api: Api, nav: NavHostController, clientId: Int) {
    val state = rememberLoaded(clientId) {
        val choices = parseReleaseChoices(api.call("release_choices"))
        val c = try { api.call("client", mapOf("id" to clientId.toString())).optJSONObject("client") } catch (e: ApiException) { null }
        choices to (c ?: JSONObject())
    }
    var role by rememberSaveable { mutableStateOf("Client") }
    var signerName by rememberSaveable { mutableStateOf<String?>(null) }
    var signerEmail by rememberSaveable { mutableStateOf<String?>(null) }
    var recipientType by rememberSaveable { mutableStateOf("") }
    var recipientName by rememberSaveable { mutableStateOf("") }
    var recipientEmail by rememberSaveable { mutableStateOf("") }
    var info by rememberSaveable { mutableStateOf(listOf<String>()) }
    var other by rememberSaveable { mutableStateOf("") }
    var purpose by rememberSaveable { mutableStateOf("") }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    val scope = rememberCoroutineScope()
    Show(state, "the release form") { (ch, c) ->
        val name = signerName ?: ((if (role == "Guardian") c.str("guardian_name") else c.str("name")) ?: "")
        val email = signerEmail ?: ((if (role == "Guardian") c.str("guardian_email") else c.str("email")) ?: "")
        Page {
            Heading("Request a release of information", large = true)
            val days = ch.validityDays
            if (days > 0) Text("It is emailed to the person who signs, and lasts $days days once signed.")
            Choice("Who signs", role, listOf("Client" to "The client", "Guardian" to "Their guardian")) { role = it; signerName = null; signerEmail = null }
            Field("Their full name", name, { signerName = it })
            Field("Their email", email, { signerEmail = it }, keyboard = KeyboardType.Email)
            Heading("Who receives the information")
            Choice("Kind of recipient", recipientType, listOf("" to "Choose one") + ch.recipientTypes.map { it to it }) { recipientType = it }
            Field("Recipient name", recipientName, { recipientName = it }, hint = "person or organisation")
            Field("Recipient email", recipientEmail, { recipientEmail = it }, hint = "optional", keyboard = KeyboardType.Email)
            Heading("Information to share")
            ch.infoItems.forEach { item ->
                Tick(item, item in info) { on -> info = if (on) info + item else info - item }
            }
            Field("Other information", other, { other = it }, hint = "optional")
            Field("Why it is shared", purpose, { purpose = it })
            ErrorText(error)
            PrimaryButton("Email the release to sign", busy = busy, enabled = name.isNotBlank() && email.isNotBlank() && recipientType.isNotEmpty() && recipientName.isNotBlank() && purpose.isNotBlank()) {
                if (info.isEmpty() && other.isBlank()) { error = "Tick at least one kind of information, or describe it under Other information."; return@PrimaryButton }
                scope.launchSave({ busy = it }, { error = it }) {
                    val b = JSONObject().put("client_id", clientId).put("signer_role", role).put("signer_name", name.trim()).put("signer_email", email.trim())
                        .put("recipient_type", recipientType).put("recipient_name", recipientName.trim()).put("purpose", purpose.trim()).put("info", JSONArray(info))
                    if (recipientEmail.isNotBlank()) b.put("recipient_email", recipientEmail.trim())
                    if (other.isNotBlank()) b.put("info_other", other.trim())
                    api.call("request_release", body = b)
                    Flash.pending = "Release emailed to ${email.trim()} to sign."
                    nav.popBackStack()
                }
            }
        }
    }
}
