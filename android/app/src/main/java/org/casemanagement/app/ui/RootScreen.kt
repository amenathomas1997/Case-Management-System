package org.casemanagement.app.ui

import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.padding
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material.icons.filled.DateRange
import androidx.compose.material.icons.filled.Home
import androidx.compose.material.icons.filled.List
import androidx.compose.material.icons.filled.Menu
import androidx.compose.material.icons.filled.Person
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.NavigationBarDefaults
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TopAppBarDefaults
import androidx.compose.foundation.background
import androidx.compose.foundation.focusable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.layout.windowInsetsPadding
import androidx.compose.foundation.selection.selectable
import androidx.compose.foundation.selection.selectableGroup
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.runtime.remember
import androidx.compose.ui.Alignment
import androidx.compose.ui.draw.clip
import androidx.compose.ui.focus.FocusRequester
import androidx.compose.ui.focus.focusRequester
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.draw.alpha
import androidx.compose.ui.semantics.clearAndSetSemantics
import androidx.compose.foundation.verticalScroll
import kotlinx.coroutines.delay
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.unit.dp
import androidx.navigation.NavGraph.Companion.findStartDestination
import androidx.navigation.NavHostController
import androidx.navigation.compose.NavHost
import androidx.navigation.compose.composable
import androidx.navigation.compose.currentBackStackEntryAsState
import androidx.navigation.compose.rememberNavController
import org.casemanagement.app.Api

/** Sign in, or the app: the tabs mirror the website's permission groups (the server enforces access either way). */
@Composable
fun RootScreen(api: Api, lock: org.casemanagement.app.AppLock? = null) {
    if (!api.isSignedIn && !api.reauthRequired) {
        LoginScreen(api)
        return
    }
    // 4.34.0: behind the app lock the screens stay (a half-written note is kept) but are neither
    // drawn nor reachable by TalkBack; the lock screen is a window above everything.
    val hidden = lock?.locked == true
    // Every dialog under the lock stays open (what was typed is kept) but shows nothing (LockAware.kt).
    androidx.compose.runtime.CompositionLocalProvider(LocalAppLocked provides hidden) {
        Box(Modifier.fillMaxSize().then(if (hidden) Modifier.alpha(0f).clearAndSetSemantics { } else Modifier)) {
            // Another account starts from fresh screens; the same account signing back in keeps them.
            androidx.compose.runtime.key(api.userId) { MainScreen(api) }
        }
        // Signing in person: above every screen until staff unlock the phone (hidden too under the lock).
        InPersonSigningHost(api)
        if (api.reauthRequired) {
            // Above the open screens, which stay as they were (a half-written note is kept).
            androidx.compose.ui.window.Dialog(onDismissRequest = {}, properties = androidx.compose.ui.window.DialogProperties(usePlatformDefaultWidth = false, dismissOnBackPress = false)) {
                LoginScreen(api, reauth = true)
            }
        }
    }
    if (lock != null) {
        if (hidden) LockScreen(api, lock)
        lock.noLockWarning?.let { w ->
            androidx.compose.material3.AlertDialog(onDismissRequest = { lock.noLockWarning = null },
                title = { Text("No screen lock", modifier = Modifier.semantics { heading() }) }, text = { Text(w) },
                confirmButton = { androidx.compose.material3.TextButton(onClick = { lock.noLockWarning = null }) { Text("OK") } })
        }
    }
}

/**
 * 4.34.0: the app lock's screen, a full window above every screen and dialog: TalkBack lands on
 * "Client records are locked", then the Unlock button, which asks for the phone's own lock.
 */
@Composable
private fun LockScreen(api: Api, lock: org.casemanagement.app.AppLock) {
    var askSignOut by remember { mutableStateOf(false) }
    val activity = androidx.compose.ui.platform.LocalContext.current.findActivity() as? androidx.fragment.app.FragmentActivity
    val headingFocus = remember { FocusRequester() }
    androidx.compose.ui.window.Dialog(onDismissRequest = {}, properties = androidx.compose.ui.window.DialogProperties(
        usePlatformDefaultWidth = false, dismissOnBackPress = false, dismissOnClickOutside = false)) {
        Surface(Modifier.fillMaxSize(), color = MaterialTheme.colorScheme.background) {
            Column(Modifier.fillMaxSize().verticalScroll(androidx.compose.foundation.rememberScrollState()).padding(24.dp),
                horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.Center) {
                Text("Client records are locked", style = MaterialTheme.typography.headlineSmall, fontWeight = FontWeight.SemiBold, textAlign = TextAlign.Center,
                    modifier = Modifier.focusRequester(headingFocus).focusable().semantics { heading() })
                Text("Unlock with this phone's fingerprint, face or screen lock.", textAlign = TextAlign.Center, modifier = Modifier.padding(top = 12.dp))
                lock.problem?.let { p -> ErrorText(p) }
                androidx.compose.material3.Button(onClick = { activity?.let { lock.authenticate(it) } },
                    modifier = Modifier.padding(top = 16.dp).heightIn(min = 48.dp)) { Text("Unlock") }
                // 4.34.0: the way out when the phone cannot check its own lock, in one sentence.
                Text("If this phone cannot unlock the app, sign out and sign in again with your password and code; anything waiting to send stays on this phone.",
                    textAlign = TextAlign.Center, modifier = Modifier.padding(top = 24.dp))
                androidx.compose.material3.OutlinedButton(onClick = { askSignOut = true }, modifier = Modifier.padding(top = 8.dp).heightIn(min = 48.dp)) { Text("Sign out") }
            }
        }
        // Inside the lock's window, so it shows above it.
        if (askSignOut) Confirm("Sign out?", listOfNotNull(api.unsentSignaturesWarning,
            "Open screens close, and you sign in again with your password and a code.").joinToString(" "), "Sign out",
            { lock.signedOut(); api.signOut() }, { askSignOut = false })
    }
    // TalkBack starts on the heading (read once, as focus lands there); Unlock is the next stop.
    LaunchedEffect(Unit) {
        delay(300)
        try { headingFocus.requestFocus() } catch (e: IllegalStateException) { /* not placed yet */ }
    }
}

private data class Tab(val route: String, val label: String, val icon: ImageVector)

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun MainScreen(api: Api) {
    val nav = rememberNavController()
    LaunchedEffect(api.token.isNotEmpty()) { if (api.isSignedIn) { api.refreshMe(); api.flushQueue(); api.flushSignatures() } }
    val tabs = buildList {
        add(Tab("home", "Home", Icons.Filled.Home))
        if (api.can("clients")) add(Tab("clients", "Clients", Icons.Filled.Person))
        if (api.can("schedule")) add(Tab("schedule", "Schedule", Icons.Filled.DateRange))
        add(Tab("tasks", "Tasks", Icons.Filled.List))
        add(Tab("more", "More", Icons.Filled.Menu))
    }
    val entry by nav.currentBackStackEntryAsState()
    // 4.36.0: a route's optional query ("tasks?due={due}") is not part of its name.
    val route = entry?.destination?.route?.substringBefore('?') ?: "home"
    val isTab = tabs.any { it.route == route }
    // 4.36.0: the tab lit is the last tab shown (the one a screen was opened from), so a list opened
    // from Home keeps Home lit.
    var lastTab by androidx.compose.runtime.saveable.rememberSaveable { mutableStateOf("home") }
    androidx.compose.runtime.SideEffect { if (isTab && lastTab != route) lastTab = route }
    val tabRoute = if (isTab) route else lastTab
    // 4.31.0: a session's note says where you are: "Case note for Jane Doe, session on Oct 20".
    val sessionNote = (entry?.arguments?.getString("session")?.toIntOrNull() ?: 0) > 0
    val title = if (sessionNote) org.casemanagement.app.sessionNoteTitle(entry?.arguments?.getString("client"), entry?.arguments?.getString("subject"))
        else if (route.startsWith("note/") && route.contains("/edit/")) "Edit case note"
        else if (route.startsWith("note/") && route.contains("/addendum/")) "Add addendum"
        else titles[route.substringBefore('/')] ?: ""
    // Each screen opened, by its back stack entry (two clients are two screens, though one route).
    val entryKey = entry?.id ?: "home"
    val titleFocus = remember { FocusRequester() }
    val backDispatcher = androidx.activity.compose.LocalOnBackPressedDispatcherOwner.current?.onBackPressedDispatcher
    val noticeFocus = remember { FocusRequester() }
    // 4.30.0: the confirmation that takes focus on this screen (with the screen's name), if any.
    var flashFocused by remember { mutableStateOf(false) }
    LaunchedEffect(entryKey) {
        val opened = android.os.SystemClock.uptimeMillis()
        // A confirmation left by a form shows on the screen it returns to, until another one opens.
        val p = Flash.pending
        if (p != null) { Flash.message = p; Flash.pending = null; Flash.shownOn = entryKey }
        else if (Flash.shownOn != entryKey) { Flash.message = null; Flash.shownOn = null }
        flashFocused = p != null
        // 4.30.0: a new screen is said, and TalkBack starts on it: on the confirmation when a form
        // just left one (read with the screen's name), otherwise on the title heading. An error
        // that already took focus on this screen keeps it.
        delay(300)
        if (ScreenFocus.claimedSince(opened)) return@LaunchedEffect
        try { if (p != null) noticeFocus.requestFocus() else titleFocus.requestFocus() } catch (e: IllegalStateException) { /* not placed yet */ }
    }

    Scaffold(
        // 4.35.0: the title bar's Back goes through the system Back, so a form that asks before
        // discarding (or says it is still saving) asks here too.
        topBar = { TitleBar(title, showBack = !isTab, focus = titleFocus) { backDispatcher?.onBackPressed() ?: nav.popBackStack() } },
        bottomBar = {
            TabBar(tabs, tabRoute) { t ->
                nav.navigate(t.route) {
                    popUpTo(nav.graph.findStartDestination().id) { saveState = true }
                    launchSingleTop = true; restoreState = true
                }
            }
        },
    ) { padding ->
        Column(Modifier.padding(padding).fillMaxSize()) {
            // Status lines, not refusals of something just pressed: read once, without moving the person.
            api.syncNotice?.let { n ->
                Box(Modifier.padding(horizontal = 16.dp)) { ErrorText(n, takeFocus = false) }
            }
            api.signingNotice?.let { n ->
                Box(Modifier.padding(horizontal = 16.dp)) { ErrorText(n, takeFocus = false) }
            }
            Flash.message?.let { m ->
                Box(Modifier.padding(horizontal = 16.dp)) {
                    if (flashFocused) Surface(color = MaterialTheme.colorScheme.primaryContainer, modifier = Modifier.fillMaxWidth().padding(vertical = 6.dp)) {
                        // Takes focus instead of the title, so it is read once and not cut off by the title.
                        Text(m, color = MaterialTheme.colorScheme.onPrimaryContainer,
                            modifier = Modifier.padding(12.dp).focusRequester(noticeFocus).focusable()
                                .semantics { contentDescription = if (title.isNotEmpty()) "$title. $m" else m })
                    } else NoticeText(m)
                }
            }
            Graph(api, nav)
        }
    }
}

/**
 * The title bar (4.30.0): it grows with the title, so a long title at a large font size wraps
 * instead of being cut off. The title is the screen's heading and takes focus on each new screen.
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun TitleBar(title: String, showBack: Boolean, focus: FocusRequester, onBack: () -> Unit) {
    Surface(color = MaterialTheme.colorScheme.surface) {
        Row(
            Modifier.fillMaxWidth().windowInsetsPadding(TopAppBarDefaults.windowInsets).heightIn(min = 64.dp).padding(horizontal = 4.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            if (showBack) IconButton(onClick = onBack) { Icon(Icons.AutoMirrored.Filled.ArrowBack, contentDescription = "Back") }
            else Spacer(Modifier.width(12.dp))
            Text(
                title, style = MaterialTheme.typography.titleLarge, color = MaterialTheme.colorScheme.onSurface,
                modifier = Modifier.weight(1f).padding(horizontal = 8.dp, vertical = 8.dp)
                    .focusRequester(focus).focusable().semantics { heading() },
            )
        }
    }
}

/**
 * The tabs (4.30.0): the bar grows with its labels, so at a large font size a label wraps onto a
 * second line instead of being cut off. Each tab is one stop, read as its label.
 */
@Composable
private fun TabBar(tabs: List<Tab>, route: String, onPick: (Tab) -> Unit) {
    Surface(color = NavigationBarDefaults.containerColor, tonalElevation = NavigationBarDefaults.Elevation) {
        Row(
            Modifier.fillMaxWidth().windowInsetsPadding(NavigationBarDefaults.windowInsets).heightIn(min = 80.dp).selectableGroup(),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            tabs.forEach { t ->
                val selected = route == t.route
                Column(
                    Modifier.weight(1f).heightIn(min = 80.dp)
                        .selectable(selected = selected, role = Role.Tab, onClick = { onPick(t) })
                        .padding(horizontal = 2.dp, vertical = 10.dp),
                    horizontalAlignment = Alignment.CenterHorizontally,
                    verticalArrangement = Arrangement.Center,
                ) {
                    Box(
                        Modifier.clip(RoundedCornerShape(16.dp))
                            .background(if (selected) MaterialTheme.colorScheme.primaryContainer else Color.Transparent)
                            .padding(horizontal = 20.dp, vertical = 4.dp),
                    ) {
                        Icon(t.icon, contentDescription = null, tint = if (selected) MaterialTheme.colorScheme.onPrimaryContainer else MaterialTheme.colorScheme.onSurfaceVariant)
                    }
                    Text(
                        t.label, style = MaterialTheme.typography.labelMedium, textAlign = TextAlign.Center,
                        fontWeight = if (selected) FontWeight.SemiBold else FontWeight.Normal,
                        color = if (selected) MaterialTheme.colorScheme.onSurface else MaterialTheme.colorScheme.onSurfaceVariant,
                        modifier = Modifier.padding(top = 4.dp),
                    )
                }
            }
        }
    }
}

private val titles = mapOf(
    "home" to "Home", "clients" to "Clients", "client" to "Client", "note" to "Add a case note", "hours" to "Log hours",
    "schedule" to "Schedule", "tasks" to "Tasks", "more" to "More", "alerts" to "Alerts", "referrals" to "Referrals",
    "referral" to "Referral", "messages" to "Messages", "thread" to "Conversation", "account" to "My account", "offline" to "Offline entries",
    "clientEdit" to "Client details", "track" to "Training track", "trackEdit" to "Track details", "report" to "Email the report", "newSession" to "Schedule a session",
    "newGroupSession" to "Schedule a group session", "offerTimes" to "Offer times", "assessments" to "Tech assessments",
    "equipment" to "Equipment", "followups" to "Device follow-ups", "docs" to "Documents and homework", "search" to "Search",
    "pipeline" to "Pipeline", "waitlist" to "Waitlist", "compliance" to "Compliance", "invoices" to "Invoices", "incidents" to "Incidents",
    "voice" to "Voice assistant", "clientAi" to "AI help", "aiTools" to "AI tools", "scored" to "Scored assessment", "scoredList" to "Scored assessments",
    "scoredNew" to "Give an assessment", "competency" to "Competencies", "at" to "AT services", "atRecord" to "AT service", "atEdit" to "AT service",
    "programs" to "Pre-ETS programs", "cohort" to "Cohort", "contracts" to "Grants and contracts", "contract" to "Contract", "bookings" to "Bookings",
    "procurement" to "Device procurement", "deviceRequest" to "Device request", "accommodations" to "Worksite accommodations", "worksite" to "Worksite assessment",
    "training" to "My training", "trainingTake" to "Training", "credentials" to "My credentials", "agreements" to "My agreements", "agreement" to "Agreement", "agreementCopy" to "Signed copy", "kb" to "Knowledge base", "kbEdit" to "Article", "counselors" to "Counselor portal", "portalRequests" to "Client requests", "utilization" to "Utilization", "docReview" to "Documentation review",
    "signatures" to "Documents to sign", "release" to "Release of information", "history" to "Status history",
    "trackNote" to "Add a case note", "goal" to "Progress history", "equipmentItem" to "Equipment item", "reservations" to "Reservations",
    "actions" to "Action center", "briefing" to "AI case briefing", "help" to "Help and glossary",
    "evalReport" to "Evaluation report", "deviceLetter" to "Justification letter", "worksiteReport" to "Recommendation report",
    "amendment" to "Burn-down", "letters" to "Write a letter", "timeline" to "Timeline", "atMatches" to "Find instructor matches",
    "tasksBulk" to "Complete several tasks", "taskList" to "Tasks", "availability" to "My availability", "privacyIncident" to "Report a privacy incident",
    "invoiceDocument" to "Invoice", "authorizations" to "Incoming authorizations", "authorization" to "Authorization email",
    "profitability" to "Service profitability", "dataQuality" to "Data quality", "docAudit" to "Documentation audit",
)

/** 4.36.0: an optional text argument of a route's query. */
private fun textArg(name: String, default: String? = null) =
    androidx.navigation.navArgument(name) { type = androidx.navigation.NavType.StringType; nullable = true; defaultValue = default }

/** The optional arguments of a session's note (4.31.0): text, so a missing one reads as empty. */
private val noteArgs = listOf("subject", "session", "client").map { name ->
    androidx.navigation.navArgument(name) { type = androidx.navigation.NavType.StringType; nullable = true; defaultValue = null }
}

private fun n0(e: androidx.navigation.NavBackStackEntry, k: String): Int = e.arguments?.getString(k)?.toIntOrNull() ?: 0

@Composable
private fun Graph(api: Api, nav: NavHostController) {
    NavHost(nav, startDestination = "home") {
        composable("home") { DashboardScreen(api, nav) }
        composable("clients") { ClientsScreen(api, nav) }
        composable("client/{id}") { ClientScreen(api, nav, it.arguments?.getString("id")?.toIntOrNull() ?: 0) }
        // 4.31.0: a session's note (from Complete, Write it now or Notes to write) carries its subject,
        // session and client's name; a plain "note/5" still opens an empty note.
        composable(org.casemanagement.app.NOTE_ROUTE, arguments = noteArgs) {
            AddNoteScreen(api, nav, it.arguments?.getString("clientId")?.toIntOrNull() ?: 0, 0,
                it.arguments?.getString("session")?.toIntOrNull() ?: 0, it.arguments?.getString("subject") ?: "")
        }
        // 4.34.0: editing a saved note, and an addendum to a locked one.
        composable("note/{clientId}/edit/{noteId}") {
            AddNoteScreen(api, nav, n0(it, "clientId"), editNoteId = n0(it, "noteId"))
        }
        composable("note/{clientId}/addendum/{noteId}") {
            AddNoteScreen(api, nav, n0(it, "clientId"), addendumTo = n0(it, "noteId"))
        }
        composable("hours/{clientId}/{trackId}") {
            LogHoursScreen(api, nav, it.arguments?.getString("clientId")?.toIntOrNull() ?: 0, it.arguments?.getString("trackId")?.toIntOrNull() ?: 0)
        }
        composable("schedule") { ScheduleScreen(api, nav) }
        // 4.36.0: the Today panel and alerts open the list on a due filter.
        composable("tasks?due={due}", arguments = listOf(textArg("due", "any"))) { TasksScreen(api, nav, it.arguments?.getString("due") ?: "any") }
        // The same list opened from Today, My tasks or an alert: a screen of its own, with Back.
        composable("taskList?due={due}", arguments = listOf(textArg("due", "any"))) { TasksScreen(api, nav, it.arguments?.getString("due") ?: "any") }
        composable("tasksBulk?q={q}&due={due}", arguments = listOf(textArg("q", ""), textArg("due", "any"))) {
            BulkCompleteScreen(api, nav, it.arguments?.getString("q") ?: "", it.arguments?.getString("due") ?: "any")
        }
        composable("availability") { AvailabilityScreen(api) }
        composable("privacyIncident?kind={kind}&what={what}", arguments = listOf(textArg("kind"), textArg("what"))) {
            PrivacyIncidentScreen(api, nav, it.arguments?.getString("kind") ?: "", it.arguments?.getString("what") ?: "")
        }
        composable("more") { MoreScreen(api, nav) }
        composable("alerts") { AlertsScreen(api, nav) }
        composable("referrals") { ReferralsScreen(api, nav) }
        composable("referral/{id}") { ReferralScreen(api, nav, it.arguments?.getString("id")?.toIntOrNull() ?: 0) }
        composable("clientEdit/{id}") { ClientEditScreen(api, nav, it.arguments?.getString("id")?.toIntOrNull() ?: 0) }
        composable("track/{clientId}/{trackId}") { TrackScreen(api, nav, it.arguments?.getString("clientId")?.toIntOrNull() ?: 0, it.arguments?.getString("trackId")?.toIntOrNull() ?: 0) }
        composable("report/{clientId}/{trackId}") { SendReportScreen(api, nav, n0(it, "clientId"), n0(it, "trackId")) }
        // 4.37.0 (S13): an approved amendment request linked to the edit, with its reason filled in.
        // 4.38.0 (T6): a new track from an authorization email (auth = the email's id).
        composable("trackEdit/{clientId}/{trackId}?amend={amend}&reason={reason}&auth={auth}", arguments = listOf(textArg("amend", "0"), textArg("reason", ""), textArg("auth", "0"))) {
            TrackEditScreen(api, nav, it.arguments?.getString("clientId")?.toIntOrNull() ?: 0, it.arguments?.getString("trackId")?.toIntOrNull() ?: 0,
                amendRequest = it.arguments?.getString("amend")?.toIntOrNull() ?: 0, reasonPrefill = it.arguments?.getString("reason") ?: "",
                authId = it.arguments?.getString("auth")?.toIntOrNull() ?: 0)
        }
        composable("newSession") { NewSessionScreen(api, nav) }
        // 4.35.0: a group session, times offered for a client's request, a client's tech assessments.
        composable("newGroupSession") { GroupSessionScreen(api, nav) }
        composable("offerTimes/{id}") { OfferTimesScreen(api, nav, n0(it, "id")) }
        composable("assessments/{clientId}") { TechAssessmentsScreen(api, n0(it, "clientId"), nav) }
        // 4.37.0 screens.
        composable("evalReport/{assessmentId}") { EvalReportScreen(api, nav, n0(it, "assessmentId")) }
        composable("deviceLetter/{id}?editable={editable}", arguments = listOf(textArg("editable", "0"))) { DeviceLetterScreen(api, n0(it, "id"), it.arguments?.getString("editable") == "1") }
        composable("worksiteReport/{id}") { WorksiteReportScreen(api, n0(it, "id")) }
        composable("amendment/{clientId}/{trackId}") { AmendmentScreen(api, nav, n0(it, "clientId"), n0(it, "trackId")) }
        composable("letters/{clientId}") { LettersScreen(api, nav, n0(it, "clientId")) }
        composable("timeline/{clientId}") { ClientTimelineScreen(api, n0(it, "clientId")) }
        composable("atMatches") { AtMatchesScreen(api) }
        composable("equipment") { EquipmentScreen(api, nav) }
        composable("equipmentItem/{id}") { EquipmentDetailScreen(api, it.arguments?.getString("id")?.toIntOrNull() ?: 0, nav) }
        composable("reservations") { ReservationsScreen(api) }
        composable(org.casemanagement.app.TRACK_NOTE_ROUTE, arguments = noteArgs) {
            AddNoteScreen(api, nav, it.arguments?.getString("clientId")?.toIntOrNull() ?: 0, it.arguments?.getString("trackId")?.toIntOrNull() ?: 0,
                it.arguments?.getString("session")?.toIntOrNull() ?: 0, it.arguments?.getString("subject") ?: "")
        }
        composable("goal/{id}/{text}") { GoalHistoryScreen(api, it.arguments?.getString("id")?.toIntOrNull() ?: 0, it.arguments?.getString("text") ?: "") }
        composable("actions") { ActionCenterScreen(api, nav) }
        composable("briefing/{clientId}") { BriefingScreen(api, it.arguments?.getString("clientId")?.toIntOrNull() ?: 0) }
        composable("help") { HelpScreen(api) }
        composable("followups") { FollowupsScreen(api) }
        composable("docs/{clientId}") { DocumentsScreen(api, it.arguments?.getString("clientId")?.toIntOrNull() ?: 0) }
        composable("search") { SearchScreen(api, nav) }
        composable("pipeline") { PipelineScreen(api, nav) }
        composable("waitlist") { WaitlistScreen(api) }
        composable("compliance") { ComplianceScreen(api, nav) }
        // 4.38.0 (T2, T5): one invoice (from search), and the invoice itself.
        composable("invoices?id={id}", arguments = listOf(textArg("id", "0"))) { InvoicesScreen(api, nav, it.arguments?.getString("id")?.toIntOrNull() ?: 0) }
        composable("invoiceDocument/{id}") { InvoiceDocumentScreen(api, n0(it, "id")) }
        // 4.38.0 (T6, T14, T15).
        composable("authorizations") { AuthorizationsScreen(api, nav) }
        composable("authorization/{id}") { AuthorizationReviewScreen(api, nav, n0(it, "id")) }
        composable("profitability") { ProfitabilityScreen(api) }
        composable("dataQuality") { DataQualityScreen(api, nav) }
        composable("docAudit") { DocAuditScreen(api, nav) }
        composable("incidents") { IncidentsScreen(api, nav) }
        val n = { e: androidx.navigation.NavBackStackEntry, k: String -> e.arguments?.getString(k)?.toIntOrNull() ?: 0 }
        composable("voice/{clientId}/{name}") { VoiceScreen(api, nav, n(it, "clientId"), it.arguments?.getString("name") ?: "") }
        composable("clientAi/{clientId}") { ClientAiScreen(api, nav, n(it, "clientId")) }
        composable("aiTools?tool={tool}", arguments = listOf(textArg("tool", ""))) { AiToolsScreen(api, it.arguments?.getString("tool") ?: "") }
        composable("scoredList/{clientId}") { ScoredAssessmentsScreen(api, nav, n(it, "clientId")) }
        composable("scored/{id}") { ScoredAssessmentScreen(api, n(it, "id")) }
        composable("scoredNew/{clientId}") { ScoredNewScreen(api, nav, n(it, "clientId")) }
        composable("competency/{clientId}") { CompetencyScreen(api, n(it, "clientId")) }
        composable("at") { AtServicesScreen(api, nav) }
        composable("at/{kind}") { AtListScreen(api, nav, it.arguments?.getString("kind") ?: "") }
        composable("atRecord/{id}") { AtRecordScreen(api, nav, n(it, "id")) }
        composable("atEdit/{kind}/{id}/{parent}") { AtEditScreen(api, nav, it.arguments?.getString("kind") ?: "", n(it, "id"), n(it, "parent")) }
        composable("programs") { ProgramsScreen(api, nav) }
        composable("cohort/{id}") { CohortScreen(api, n(it, "id")) }
        composable("contracts") { ContractsScreen(api, nav) }
        composable("contract/{id}") { ContractScreen(api, n(it, "id")) }
        composable("bookings") { BookingsScreen(api) }
        composable("procurement") { ProcurementScreen(api, nav) }
        composable("deviceRequest/{id}") { DeviceRequestScreen(api, n(it, "id"), nav) }
        composable("accommodations") { AccommodationsScreen(api, nav) }
        composable("worksite/{id}") { WorksiteScreen(api, nav, n(it, "id")) }
        composable("kb") { KnowledgeScreen(api, nav) }
        composable("kb/{id}") { KnowledgeArticleScreen(api, nav, n(it, "id")) }
        composable("kbEdit/{id}") { KnowledgeEditScreen(api, nav, n(it, "id")) }
        composable("counselors") { CounselorsScreen(api) }
        composable("portalRequests") { PortalRequestsScreen(api, nav) }
        composable("utilization") { UtilizationScreen(api) }
        composable("docReview") { DocReviewScreen(api, nav) }
        composable("agreements") { AgreementsScreen(api, nav) }
        composable("training") { TrainingListScreen(api, nav) }
        composable("trainingTake/{id}") { TrainingTakeScreen(api, nav, n(it, "id")) }
        composable("credentials") { CredentialsScreen(api) }
        composable("agreement/{id}") { AgreementScreen(api, nav, n(it, "id")) }
        composable("agreementCopy/{id}") { AgreementCopyScreen(api, n(it, "id")) }
        composable("messages") { MessagesScreen(api, nav) }
        // 4.34.0: opened on the channel with the unread messages.
        composable("thread/{clientId}?channel={channel}", arguments = listOf(androidx.navigation.navArgument("channel") { type = androidx.navigation.NavType.StringType; nullable = true; defaultValue = null })) {
            ThreadScreen(api, it.arguments?.getString("clientId")?.toIntOrNull() ?: 0, it.arguments?.getString("channel") ?: "client")
        }
        composable("signatures/{clientId}") { SignaturesScreen(api, n(it, "clientId")) }
        composable("release/{clientId}") { ReleaseRequestScreen(api, nav, n(it, "clientId")) }
        composable("history/{clientId}") { StatusHistoryScreen(api, n(it, "clientId")) }
        composable("account") { AccountScreen(api, nav) }
        composable("offline") { OfflineScreen(api, nav) }
    }
}
