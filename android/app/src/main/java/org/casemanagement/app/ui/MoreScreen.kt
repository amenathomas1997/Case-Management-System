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
import org.json.JSONObject
import java.text.DateFormat
import java.util.Date

@Composable
fun MoreScreen(api: Api, nav: NavHostController) {
    Page {
        ListRow(listOf("Alerts"), onClick = { nav.navigate("alerts") })
        if (api.can("referrals")) ListRow(listOf("Referrals"), onClick = { nav.navigate("referrals") })
        if (api.can("clients")) ListRow(listOf("Messages"), onClick = { nav.navigate("messages") })
        ListRow(listOf("Offline entries" + if (api.pendingSync > 0) " (${api.pendingSync} waiting)" else ""), onClick = { nav.navigate("offline") })
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
            a.optString("group").takeIf { it.isNotEmpty() && it != "null" }?.let { ListRow(listOf("Permission group: $it")) }
            ListRow(listOf("Authenticator app: " + if (a.optBoolean("totp_enabled")) "on" else "off"))
            ListRow(listOf("Phones signed in to the app: " + a.optInt("app_sessions")))
        }
        ListRow(listOf("Server: " + ServerAddress.host(api.base)))
        ListRow(listOf("App version: " + BuildConfig.VERSION_NAME + " (" + BuildConfig.VERSION_CODE + ")"))
        api.serverVersion?.let { ListRow(listOf("Website version: $it")) }
        Text("Passwords, the authenticator app, passkeys, the calendar address and lost phones are managed under My Account on the website.")
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
