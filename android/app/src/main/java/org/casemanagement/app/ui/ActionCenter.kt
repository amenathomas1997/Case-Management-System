package org.casemanagement.app.ui

import android.content.Context
import android.content.Intent
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.platform.LocalContext
import androidx.core.net.toUri
import androidx.navigation.NavHostController
import kotlinx.coroutines.launch
import org.casemanagement.app.Api
import org.casemanagement.app.ApiException
import org.casemanagement.app.BriefItem
import org.casemanagement.app.parseBriefing
import org.casemanagement.app.parseClients
import org.casemanagement.app.parseWorkflowActions
import org.casemanagement.app.sitePage
import org.json.JSONObject

/*
 * The action center and the AI case briefing (4.26.0), as the iPhone app has them, and the
 * website's help and privacy pages opened in the phone's browser.
 */

/** Opens a page of the organisation's website in the phone's browser; false when it cannot. */
fun openSitePage(api: Api, context: Context, path: String): Boolean {
    val url = sitePage(api.base, path) ?: return false
    return openLink(context, Intent(Intent.ACTION_VIEW, url.toUri()))
}

/** As on the website: a date before today (the server's day) is past due. */
private fun overdue(due: String): Boolean = due.take(10) < today()

/** What needs doing, from the website's action center: tasks open in the app, the rest on the website. */
@Composable
fun ActionCenterScreen(api: Api, nav: NavHostController) {
    val context = LocalContext.current
    val state = rememberLoaded(api.userId) { parseWorkflowActions(api.call("workflow_actions")) }
    var error by remember { mutableStateOf<String?>(null) }
    Page {
        ErrorText(error)
        // Only where the briefing AI feature is on: otherwise every try would end in "turned off".
        if (api.can("clients") && api.aiHas("briefing")) ListRow(listOf("AI case briefing", "A draft summary of a client's records, with links to each source"), onClick = { nav.navigate("briefing/0") })
        ListRow(listOf("All workflow tools on the website"), action = "open it in the browser", onClick = {
            error = if (openSitePage(api, context, "workflows.php")) null else "No browser on this phone can open the website."
        })
        Show(state, "action items") { items ->
            Heading("Action items (${items.size})")
            Text("Tasks open in the app's Tasks screen; the other items open on the website in the browser, where you may need to sign in.")
            if (items.isEmpty()) Text("No action items found.")
            items.forEach { item ->
                val due = item.due?.let { ", due " + dayText(it) + if (overdue(it)) ", overdue" else "" } ?: ""
                if (item.url.startsWith("tasks.php")) {
                    ListRow(listOf(item.title, item.kind + due), action = "open Tasks", onClick = { nav.navigate("tasks") })
                } else {
                    ListRow(listOf(item.title, item.kind + due), action = "open it in the browser", onClick = {
                        error = if (openSitePage(api, context, item.url)) null else "No browser on this phone can open this link."
                    })
                }
            }
        }
    }
}

/**
 * An AI draft briefing on one client's records: facts, then proposed next steps, each with links
 * to the records it is drawn from. Nothing is saved or assigned by it ([clientId] 0: choose one).
 */
@Composable
fun BriefingScreen(api: Api, clientId: Int) {
    val context = LocalContext.current
    val clients = rememberLoaded(clientId) { if (clientId > 0) emptyList() else parseClients(api.call("client_choices")) }
    var chosen by rememberSaveable { mutableStateOf(if (clientId > 0) clientId.toString() else "") }
    var adapt by rememberSaveable { mutableStateOf(false) }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    var items by remember { mutableStateOf<List<BriefItem>?>(null) }
    var omitted by remember { mutableIntStateOf(0) }
    var notice by remember { mutableStateOf<String?>(null) }
    val scope = rememberCoroutineScope()
    Page {
        if (!api.aiHas("briefing")) { Text("The AI case briefing is not switched on for your account."); return@Page }
        if (clientId == 0) {
            if (clients.loading) Text("Loading clients…")
            ErrorText(clients.error)
            val list = clients.data ?: emptyList()
            // Closed clients too, as on the website.
            if (list.isNotEmpty()) Choice("Client", chosen, listOf("" to "Choose a client") + list.map { it.id.toString() to it.name + if (it.closed) " (closed)" else "" }) { chosen = it; items = null }
        }
        Tick("Suggest homework adaptations", adapt) { adapt = it; items = null }
        Text("AI output is a draft. Verify each claim using the linked records. Nothing is saved or assigned by generating a briefing.")
        ErrorText(error)
        NoticeText(notice)
        PrimaryButton(if (busy) "Drafting… this can take up to a minute" else "Generate briefing", busy = busy, enabled = chosen.isNotEmpty()) {
            if (busy) return@PrimaryButton
            error = null; items = null; notice = null
            busy = true
            scope.launch {
                try {
                    val r = api.call("workflow_briefing", body = JSONObject().put("client_id", chosen.toInt()).put("adapt", adapt))
                    items = parseBriefing(r); omitted = r.optInt("omitted")
                } catch (e: ApiException) { error = e.message } finally { busy = false }
            }
        }
        val list = items ?: return@Page
        Announce("Briefing ready. Verify its claims against the linked records.", key = list)
        if (omitted > 0) NoticeText("$omitted older or lower-priority records were left out to fit, so the evidence is incomplete.")
        listOf(true to "Record summary", false to "Proposed next step").forEach { (fact, label) ->
            val part = list.filter { it.isFact == fact }
            if (part.isEmpty()) return@forEach
            Heading(if (fact) "From the records (${part.size})" else "Proposed next steps (${part.size})")
            part.forEachIndexed { i, item ->
                ListRow(listOf("$label ${i + 1}: " + item.text), action = "copy the text", onClick = {
                    copyText(context, item.text)
                    error = null; notice = "Copied the ${label.lowercase()} ${i + 1}."
                })
                item.sources.forEach { src ->
                    ListRow(listOf("Source: " + src.label), action = "open it in the browser", onClick = {
                        error = if (openSitePage(api, context, src.url)) null else "No browser on this phone can open this link."
                    })
                }
            }
        }
    }
}

/** The website's terms, explained in the app; the full help is on the website. */
private val GLOSSARY = listOf(
    "AT, assistive technology" to "Tools or software that help a person perform an activity.",
    "Authorization" to "Approval for a service, often with an allowed number of hours or a spending limit.",
    "BIRP note" to "A note format in four parts: Behavior, Intervention, Response, Plan.",
    "Caseload" to "The clients assigned to a staff member.",
    "Consent" to "A person's agreement to the activity described in a form.",
    "Deliverable" to "A specific item or result required by a contract.",
    "IPE, individualized plan for employment" to "A plan describing an employment goal and the services intended to help reach it.",
    "NPS, Net Promoter Score" to "A survey measure based on how likely people say they are to recommend a service.",
    "OTP, one-time password" to "A temporary sign-in code. You can paste it into the code field.",
    "PAIP note" to "A note format in four parts: Purpose, Assessment, Intervention, Plan.",
    "Passkey" to "A sign-in method that uses your device to approve access instead of typing an account password.",
    "Pre-ETS, pre-employment transition services" to "Services that help students with disabilities prepare for work and further education.",
    "ROI, release of information" to "In consent records, permission to share the information described in the release.",
    "RSA, Rehabilitation Services Administration" to "The agency name used in the app's service categories and reporting.",
    "SMART goal" to "A goal that is specific, measurable, achievable, relevant, and time-bound.",
    "SOAP note" to "A note format in four parts: Subjective, Objective, Assessment, Plan.",
    "Telepractice" to "A session provided remotely, such as through a video call.",
    "Unbilled hours" to "Hours logged on a track that are not on an invoice yet. The dollar amount beside them also counts mileage and expenses not invoiced yet.",
    "VR, vocational rehabilitation" to "Services intended to help people with disabilities prepare for, find, or keep work.",
    "VRC, vocational rehabilitation counselor" to "A counselor who helps plan and coordinate vocational rehabilitation services.",
)

/** Help and glossary: the common terms here, and the website's help, user guide and privacy policy. */
@Composable
fun HelpScreen(api: Api) {
    val context = LocalContext.current
    var error by remember { mutableStateOf<String?>(null) }
    fun open(path: String) { error = if (openSitePage(api, context, path)) null else "No browser on this phone can open the website." }
    Page {
        ErrorText(error)
        Heading("On the website")
        ListRow(listOf("Help and glossary on the website"), action = "open it in the browser", onClick = { open("help.php") })
        ListRow(listOf("User guide"), action = "open it in the browser", onClick = { open("help.php#staff-guide") })
        ListRow(listOf("Privacy policy"), action = "open it in the browser", onClick = { open("privacy.php") })
        Heading("Common terms")
        GLOSSARY.forEach { (term, meaning) -> ListRow(listOf(term, meaning)) }
    }
}
