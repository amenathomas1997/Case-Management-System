package org.casemanagement.app.ui

import android.content.ClipData
import android.content.ClipboardManager
import android.content.Context
import android.os.Build
import android.os.PersistableBundle
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.platform.LocalContext
import androidx.navigation.NavHostController
import kotlinx.coroutines.launch
import org.casemanagement.app.Api
import org.casemanagement.app.BuildConfig
import org.casemanagement.app.ServerAddress
import org.casemanagement.app.str
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import org.json.JSONObject
import java.text.DateFormat
import java.util.Date

@Composable
fun MoreScreen(api: Api, nav: NavHostController) {
    // Grouped under headings, so a screen reader can jump from group to group.
    Page {
        Heading("Everyday")
        if (api.can("clients")) ListRow(listOf("Search"), onClick = { nav.navigate("search") })
        ListRow(listOf("Alerts"), onClick = { nav.navigate("alerts") })
        if (api.can("referrals")) ListRow(listOf("Referrals"), onClick = { nav.navigate("referrals") })
        if (api.can("clients")) ListRow(listOf("Messages"), onClick = { nav.navigate("messages") })
        if (api.can("clients")) ListRow(listOf("Client portal requests"), onClick = { nav.navigate("portalRequests") })
        if (api.aiHas("voice") && api.can("clients")) ListRow(listOf("Voice assistant"), onClick = { nav.navigate("voice/0/-") })
        if (api.aiEnabled) ListRow(listOf("AI tools"), onClick = { nav.navigate("aiTools") })
        ListRow(listOf("Offline entries" + if (api.pendingSync > 0) " (${api.pendingSync} waiting)" else ""), onClick = { nav.navigate("offline") })

        Heading("Services")
        if (api.can("clients")) ListRow(listOf("AT services"), onClick = { nav.navigate("at") })
        if (api.can("equipment")) ListRow(listOf("Equipment"), onClick = { nav.navigate("equipment") })
        if (api.can("clients")) ListRow(listOf("Device follow-ups"), onClick = { nav.navigate("followups") })
        if (api.can("procurement")) ListRow(listOf("Device procurement"), onClick = { nav.navigate("procurement") })
        if (api.can("clients")) ListRow(listOf("Worksite accommodations"), onClick = { nav.navigate("accommodations") })
        if (api.can("schedule")) ListRow(listOf("Interpreter and transport bookings"), onClick = { nav.navigate("bookings") })
        if (api.can("programs")) ListRow(listOf("Pre-ETS programs"), onClick = { nav.navigate("programs") })
        ListRow(listOf("Knowledge base"), onClick = { nav.navigate("kb") })

        Heading("Reports and records")
        if (api.can("clients")) ListRow(listOf("Pipeline"), onClick = { nav.navigate("pipeline") })
        if (api.can("referrals")) ListRow(listOf("Waitlist"), onClick = { nav.navigate("waitlist") })
        if (api.can("clients")) ListRow(listOf("Compliance"), onClick = { nav.navigate("compliance") })
        if (api.can("clients")) ListRow(listOf("Documentation review"), onClick = { nav.navigate("docReview") })
        if (api.can("clients")) ListRow(listOf("Incidents"), onClick = { nav.navigate("incidents") })
        if (api.can("billing")) ListRow(listOf("Invoices"), onClick = { nav.navigate("invoices") })
        if (api.can("billing")) ListRow(listOf("Grants and contracts"), onClick = { nav.navigate("contracts") })
        if (api.can("analytics")) ListRow(listOf("Staff utilization"), onClick = { nav.navigate("utilization") })

        Heading("Account and administration")
        if (api.can("admin")) ListRow(listOf("Counselor portal"), onClick = { nav.navigate("counselors") })
        ListRow(listOf("My agreements"), onClick = { nav.navigate("agreements") })
        ListRow(listOf("My account"), onClick = { nav.navigate("account") })
        Text("Everything else is on the website, ${ServerAddress.host(api.base)}.")
    }
}

/** Who is signed in, where, which build; sign out. */
@Composable
fun AccountScreen(api: Api, nav: NavHostController) {
    val state = rememberLoaded(api.userId) { api.call("account") }
    var confirm by remember { mutableStateOf(false) }
    val scope = rememberCoroutineScope()
    Page {
        Heading(api.userName, large = true)
        Show(state, "your account") { a ->
            ListRow(listOf("Email: " + a.optString("email")))
            ListRow(listOf("Server: " + ServerAddress.host(api.base)))
            ListRow(listOf("App version: " + BuildConfig.VERSION_NAME + " (" + BuildConfig.VERSION_CODE + ")"))
            api.serverVersion?.let { ListRow(listOf("Website version: $it")) }
            AccountSecurity(api, a) { state.reload() }
            a.optString("group").takeIf { it.isNotEmpty() && it != "null" }?.let { ListRow(listOf("Permission group: $it")) }
            ListRow(listOf("Authenticator app: " + if (a.optBoolean("totp_enabled")) "on" else "off"))
            ListRow(listOf("Phones signed in to the app: " + a.optInt("app_sessions")))
        }
        Text("Passkeys and lost phones are managed under My Account on the website.")
        if (api.pendingSync > 0) {
            Text("${api.pendingSync} offline ${if (api.pendingSync == 1) "entry is" else "entries are"} waiting to send. They stay on this phone after signing out and send when you sign in again.")
        }
        PrimaryButton("Sign out") { confirm = true }
    }
    if (confirm) Confirm("Sign out?", "You will need your password and a code to sign in again.", "Sign out", { api.signOut() }, { confirm = false })
}

/**
 * Entries kept on this phone without a signal: what each says, and why the server refused it if it
 * did. Each can be sent now, copied to enter on the website, or discarded.
 */
@Composable
fun OfflineScreen(api: Api) {
    var version by remember { mutableIntStateOf(0) }
    var busy by remember { mutableStateOf(false) }
    var discard by remember { mutableStateOf<JSONObject?>(null) }
    var notice by remember { mutableStateOf<String?>(null) }
    val scope = rememberCoroutineScope()
    val context = LocalContext.current
    val items = remember(version, api.pendingSync, api.syncNotice) { api.ownQueued() }
    Page {
        Heading("Waiting to send (${items.size})", large = true)
        NoticeText(notice)
        if (items.isEmpty()) Text("Nothing is waiting to send.")
        else PrimaryButton("Send now", busy = busy) {
            scope.launch {
                busy = true
                api.flushQueue()
                busy = false; version++
                notice = if (api.pendingSync == 0) "Everything was sent." else api.syncNotice ?: "Some entries are still waiting; there may be no signal."
            }
        }
        items.forEach { item ->
            val saved = item.optLong("savedAt").takeIf { it > 0 }?.let { DateFormat.getDateTimeInstance(DateFormat.MEDIUM, DateFormat.SHORT).format(Date(it)) } ?: ""
            val err = item.optString("lastError").takeIf { item.has("lastError") }
            ListRow(listOfNotNull(item.optString("label"), "Kept $saved", err?.let { "Not accepted: $it" }), action = "copy or discard it", onClick = { discard = item })
        }
    }
    discard?.let { item ->
        val text = readable(item)
        androidx.compose.material3.AlertDialog(
            onDismissRequest = { discard = null },
            title = { Text(item.optString("label")) },
            text = { Text(text) },
            confirmButton = {
                androidx.compose.material3.TextButton(onClick = {
                    copyProtected(context, text); notice = "Copied. Paste it on the website to enter it there."; discard = null
                }) { Text("Copy") }
            },
            dismissButton = {
                androidx.compose.material3.TextButton(onClick = {
                    scope.launch { api.discardQueued(item.optString("id")); version++; notice = "Entry discarded." }; discard = null
                }) { Text("Discard") }
            },
        )
    }
}

/** What an entry says, as plain text. */
private fun readable(item: JSONObject): String {
    val b = try { JSONObject(item.optString("body")) } catch (e: Exception) { return item.optString("label") }
    val keys = listOf("subject" to "Subject", "body" to "Note", "date" to "Date", "hours" to "Hours", "mileage" to "Miles", "description" to "What was done")
    return keys.mapNotNull { (k, l) -> b.optString(k).takeIf { it.isNotEmpty() }?.let { "$l: $it" } }.joinToString("\n").ifEmpty { item.optString("label") }
}

/** Copies text marked sensitive, so the keyboard's clipboard preview does not show it. */
private fun copyProtected(context: Context, text: String) {
    val clip = ClipData.newPlainText("Offline entry", text)
    if (Build.VERSION.SDK_INT >= 33) clip.description.extras = PersistableBundle().apply { putBoolean("android.content.extra.IS_SENSITIVE", true) }
    (context.getSystemService(Context.CLIPBOARD_SERVICE) as ClipboardManager).setPrimaryClip(clip)
}

/** Password, sign-out everywhere, the calendar address and the authenticator app, as the iPhone app's Account screen. */
@Composable
private fun AccountSecurity(api: Api, a: JSONObject, reload: () -> Unit) {
    var open by remember { mutableStateOf("") }
    var current by remember { mutableStateOf("") }
    var new1 by remember { mutableStateOf("") }
    var new2 by remember { mutableStateOf("") }
    var ackPasskeys by remember { mutableStateOf(false) }
    var password by remember { mutableStateOf("") }
    var code by remember { mutableStateOf("") }
    var setup by remember { mutableStateOf<JSONObject?>(null) }
    var calAddress by remember { mutableStateOf<String?>(null) }
    var notice by remember { mutableStateOf<String?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    var busy by remember { mutableStateOf(false) }
    var confirmEverywhere by remember { mutableStateOf(false) }
    val scope = rememberCoroutineScope()
    val context = LocalContext.current
    fun fieldError(e: org.casemanagement.app.ApiException) = e.reply?.optJSONObject("errors")?.let { o -> o.keys().asSequence().firstOrNull()?.let { o.optString(it) } } ?: e.message
    fun act(block: suspend () -> String) {
        error = null
        scope.launch {
            busy = true
            try { notice = block(); password = ""; code = ""; reload() } catch (e: org.casemanagement.app.ApiException) { error = fieldError(e) } finally { busy = false }
        }
    }
    Heading("Security")
    NoticeText(notice)
    ErrorText(error)
    Choice("Security setting to change", open, listOf("" to "Choose one", "password" to "My password", "totp" to "The authenticator app",
        "cal" to "My calendar address", "everywhere" to "Sign out of the app on every device").let { l -> if (a.optBoolean("can_schedule")) l else l.filter { it.first != "cal" } }) { open = it; error = null; notice = null }
    when (open) {
        "password" -> {
            Field("Current password", current, { current = it }, password = true)
            Field("New password", new1, { new1 = it }, hint = "at least 10 characters", password = true)
            Field("New password again", new2, { new2 = it }, password = true)
            val passkeys = a.optJSONArray("passkeys")?.length() ?: 0
            if (passkeys > 0) Tick("I understand my $passkeys passkeys are removed", ackPasskeys) { ackPasskeys = it }
            PrimaryButton("Change password", busy = busy) {
                when {
                    new1.length < 10 -> error = "The new password needs at least 10 characters."
                    new1 != new2 -> error = "The two new passwords are not the same."
                    passkeys > 0 && !ackPasskeys -> error = "Tick that you understand your passkeys are removed."
                    else -> act {
                        val r = api.call("change_password", body = JSONObject().put("current_password", current).put("new_password", new1).put("confirm_password", new2).put("ack_passkeys", ackPasskeys))
                        api.signOut()
                        api.sessionNotice = "You were signed out because your password was changed. " + r.optString("message")
                        ""
                    }
                }
            }
        }
        "totp" -> if (a.optBoolean("totp_enabled")) {
            Field("Your password", password, { password = it }, password = true)
            PrimaryButton("Turn off the authenticator app", busy = busy) { act { api.call("totp_disable", body = JSONObject().put("password", password)).optString("message").ifEmpty { "Authenticator app turned off." } } }
        } else {
            val s = setup
            if (s == null) PrimaryButton("Set up an authenticator app", busy = busy) {
                error = null
                scope.launch { try { setup = api.call("totp_begin", body = JSONObject()) } catch (e: org.casemanagement.app.ApiException) { error = e.message } }
            } else {
                val secret = s.optString("secret")
                // Spelled one character at a time, so TalkBack does not read the groups as words.
                Text("In your authenticator app, add an account with this key: ${secret.chunked(4).joinToString(" ")}. Account name: ${s.optString("account_name")}.",
                    modifier = androidx.compose.ui.Modifier.semantics { contentDescription = "In your authenticator app, add an account with this key, spelled: " + secret.toList().joinToString(", ") + ". Account name: " + s.optString("account_name") + "." })
                SecondaryButton("Copy the key") { copyProtected(context, secret); notice = "Key copied. Paste it into your authenticator app." }
                SecondaryButton("Open in my authenticator app") { if (!openLink(context, android.content.Intent(android.content.Intent.ACTION_VIEW, android.net.Uri.parse(s.optString("otpauth_uri"))))) error = "No authenticator app on this phone opens setup links; type the key instead." }
                Field("The 6-digit code it shows", code, { code = it.filter { c -> c.isDigit() }.take(6) }, keyboard = androidx.compose.ui.text.input.KeyboardType.NumberPassword)
                Field("Your password", password, { password = it }, password = true)
                // The setup is kept until the code is accepted, so a mistyped code can be tried again with the same key.
                PrimaryButton("Turn on", busy = busy, enabled = code.length == 6) { act { val m = api.call("totp_confirm", body = JSONObject().put("password", password).put("code", code)).optString("message").ifEmpty { "Authenticator app turned on." }; setup = null; m } }
            }
        }
        "cal" -> {
            Field("Your password", password, { password = it }, password = true)
            SecondaryButton("Show my calendar address") { act { val r = api.call("cal_feed_show", body = JSONObject().put("password", password)); calAddress = r.str("address"); r.optString("message").ifEmpty { if (calAddress == null) "You have no calendar address yet." else "Your calendar address is below." } } }
            SecondaryButton("Make a new calendar address") { act { val r = api.call("cal_feed_regen", body = JSONObject().put("password", password)); calAddress = r.str("address"); r.optString("message").ifEmpty { "New calendar address made. The old one no longer works." } } }
            calAddress?.let { addr ->
                Text(addr)
                SecondaryButton("Copy the address") { copyProtected(context, addr); notice = "Address copied. Add it as a subscribed calendar." }
            }
        }
        "everywhere" -> {
            Field("Your password", password, { password = it }, password = true)
            PrimaryButton("Sign out everywhere", busy = busy) { confirmEverywhere = true }
        }
    }
    if (confirmEverywhere) Confirm("Sign out of the app on every device?", "Every phone signed in to the app, this one too, has to sign in again. Website sessions are not affected.", "Sign out everywhere", {
        act { val r = api.call("logout_all", body = JSONObject().put("password", password)); api.signOut(); api.sessionNotice = r.optString("message"); "" }
    }, { confirmEverywhere = false })
}
