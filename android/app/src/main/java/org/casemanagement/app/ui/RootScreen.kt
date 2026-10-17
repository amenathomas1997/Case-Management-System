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
import androidx.compose.material3.NavigationBar
import androidx.compose.material3.NavigationBarItem
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.material3.TopAppBar
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
fun RootScreen(api: Api) {
    if (!api.isSignedIn && !api.reauthRequired) {
        LoginScreen(api)
        return
    }
    // Another account starts from fresh screens; the same account signing back in keeps them.
    androidx.compose.runtime.key(api.userId) { MainScreen(api) }
    if (api.reauthRequired) {
        // Above the open screens, which stay as they were (a half-written note is kept).
        androidx.compose.ui.window.Dialog(onDismissRequest = {}, properties = androidx.compose.ui.window.DialogProperties(usePlatformDefaultWidth = false, dismissOnBackPress = false)) {
            LoginScreen(api, reauth = true)
        }
    }
}

private data class Tab(val route: String, val label: String, val icon: ImageVector)

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun MainScreen(api: Api) {
    val nav = rememberNavController()
    LaunchedEffect(api.token.isNotEmpty()) { if (api.isSignedIn) { api.refreshMe(); api.flushQueue() } }
    val tabs = buildList {
        add(Tab("home", "Home", Icons.Filled.Home))
        if (api.can("clients")) add(Tab("clients", "Clients", Icons.Filled.Person))
        if (api.can("schedule")) add(Tab("schedule", "Schedule", Icons.Filled.DateRange))
        add(Tab("tasks", "Tasks", Icons.Filled.List))
        add(Tab("more", "More", Icons.Filled.Menu))
    }
    val entry by nav.currentBackStackEntryAsState()
    val route = entry?.destination?.route ?: "home"
    val isTab = tabs.any { it.route == route }
    val title = titles[route.substringBefore('/')] ?: ""
    // A confirmation left by a form shows on the screen it returns to, until another one opens.
    LaunchedEffect(route) {
        val p = Flash.pending
        if (p != null) { Flash.message = p; Flash.pending = null; Flash.shownOn = route }
        else if (Flash.shownOn != route) { Flash.message = null; Flash.shownOn = null }
    }

    Scaffold(
        topBar = {
            TopAppBar(
                title = { Text(title, modifier = Modifier.semantics { heading() }) },
                navigationIcon = {
                    if (!isTab) IconButton(onClick = { nav.popBackStack() }) { Icon(Icons.AutoMirrored.Filled.ArrowBack, contentDescription = "Back") }
                },
            )
        },
        bottomBar = {
            NavigationBar {
                tabs.forEach { t ->
                    NavigationBarItem(
                        selected = route == t.route,
                        onClick = {
                            nav.navigate(t.route) {
                                popUpTo(nav.graph.findStartDestination().id) { saveState = true }
                                launchSingleTop = true; restoreState = true
                            }
                        },
                        icon = { Icon(t.icon, contentDescription = null) },
                        label = { Text(t.label) },
                    )
                }
            }
        },
    ) { padding ->
        Column(Modifier.padding(padding).fillMaxSize()) {
            api.syncNotice?.let { n ->
                Box(Modifier.padding(horizontal = 16.dp)) { ErrorText(n) }
            }
            Flash.message?.let { m ->
                Box(Modifier.padding(horizontal = 16.dp)) { NoticeText(m) }
            }
            Graph(api, nav)
        }
    }
}

private val titles = mapOf(
    "home" to "Home", "clients" to "Clients", "client" to "Client", "note" to "Add a case note", "hours" to "Log hours",
    "schedule" to "Schedule", "tasks" to "Tasks", "more" to "More", "alerts" to "Alerts", "referrals" to "Referrals",
    "referral" to "Referral", "messages" to "Messages", "thread" to "Conversation", "account" to "My account", "offline" to "Offline entries",
    "clientEdit" to "Client details", "track" to "Training track", "trackEdit" to "Track details", "newSession" to "Schedule a session",
    "equipment" to "Equipment", "followups" to "Device follow-ups", "docs" to "Documents and homework", "search" to "Search",
    "pipeline" to "Pipeline", "waitlist" to "Waitlist", "compliance" to "Compliance", "invoices" to "Invoices", "incidents" to "Incidents",
)

@Composable
private fun Graph(api: Api, nav: NavHostController) {
    NavHost(nav, startDestination = "home") {
        composable("home") { DashboardScreen(api, nav) }
        composable("clients") { ClientsScreen(api, nav) }
        composable("client/{id}") { ClientScreen(api, nav, it.arguments?.getString("id")?.toIntOrNull() ?: 0) }
        composable("note/{clientId}") { AddNoteScreen(api, nav, it.arguments?.getString("clientId")?.toIntOrNull() ?: 0) }
        composable("hours/{clientId}/{trackId}") {
            LogHoursScreen(api, nav, it.arguments?.getString("clientId")?.toIntOrNull() ?: 0, it.arguments?.getString("trackId")?.toIntOrNull() ?: 0)
        }
        composable("schedule") { ScheduleScreen(api, nav) }
        composable("tasks") { TasksScreen(api, nav) }
        composable("more") { MoreScreen(api, nav) }
        composable("alerts") { AlertsScreen(api) }
        composable("referrals") { ReferralsScreen(api, nav) }
        composable("referral/{id}") { ReferralScreen(api, nav, it.arguments?.getString("id")?.toIntOrNull() ?: 0) }
        composable("clientEdit/{id}") { ClientEditScreen(api, nav, it.arguments?.getString("id")?.toIntOrNull() ?: 0) }
        composable("track/{clientId}/{trackId}") { TrackScreen(api, nav, it.arguments?.getString("clientId")?.toIntOrNull() ?: 0, it.arguments?.getString("trackId")?.toIntOrNull() ?: 0) }
        composable("trackEdit/{clientId}/{trackId}") { TrackEditScreen(api, nav, it.arguments?.getString("clientId")?.toIntOrNull() ?: 0, it.arguments?.getString("trackId")?.toIntOrNull() ?: 0) }
        composable("newSession") { NewSessionScreen(api, nav) }
        composable("equipment") { EquipmentScreen(api) }
        composable("followups") { FollowupsScreen(api) }
        composable("docs/{clientId}") { DocumentsScreen(api, it.arguments?.getString("clientId")?.toIntOrNull() ?: 0) }
        composable("search") { SearchScreen(api, nav) }
        composable("pipeline") { PipelineScreen(api, nav) }
        composable("waitlist") { WaitlistScreen(api) }
        composable("compliance") { ComplianceScreen(api, nav) }
        composable("invoices") { InvoicesScreen(api) }
        composable("incidents") { IncidentsScreen(api) }
        composable("messages") { MessagesScreen(api, nav) }
        composable("thread/{clientId}") { ThreadScreen(api, it.arguments?.getString("clientId")?.toIntOrNull() ?: 0) }
        composable("account") { AccountScreen(api, nav) }
        composable("offline") { OfflineScreen(api) }
    }
}
