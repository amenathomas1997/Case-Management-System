package org.casemanagement.app.ui

import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.navigation.NavHostController
import org.casemanagement.app.Api
import org.casemanagement.app.ApiException
import org.casemanagement.app.parseNotesToWrite
import org.casemanagement.app.parseDashboard
import org.casemanagement.app.parseReferralDeadlines
import java.util.Locale

/** The website's dashboard: counts, authorizations ending soon, the newest referrals and notes. */
@Composable
fun DashboardScreen(api: Api, nav: NavHostController) {
    val state = rememberLoaded(api.userId) {
        val d = parseDashboard(api.call("dashboard"))
        // 4.36.0: the counts come with the dashboard; a 4.35.0 server is asked for them as before.
        val toDo = if (d.agreementsNeeded != null) d.agreementsNeeded to (d.trainingNeeded ?: 0)
            else (try { api.call("agreements").optInt("needed") } catch (e: Exception) { 0 }) to (try { api.call("training").optInt("needed") } catch (e: Exception) { 0 })
        Triple(d, try { api.call("notifications", mapOf("count_only" to "1")).optInt("unread") } catch (e: Exception) { 0 }, toDo)
    }
    // Referral deadlines (4.25.0), for those who work referrals; left out when it cannot be read.
    val deadlines = rememberLoaded(api.userId, api.can("referrals")) {
        if (api.can("referrals")) try { parseReferralDeadlines(api.call("referral_deadlines")) } catch (e: Exception) { null } else null
    }
    // 4.31.0: completed sessions whose case note is not written yet; hidden on an older server
    // (Unknown action) or when it cannot be read.
    val toWrite = rememberLoaded(api.userId) { try { parseNotesToWrite(api.call("notes_to_write")) } catch (e: ApiException) { null } }
    Show(state, "the dashboard") { (d, unread, toSign) ->
        Page {
            Heading("Hello, ${api.userName.substringBefore(' ')}", large = true)
            api.updateNotice?.let { NoticeText(it) }
            if (api.pendingSync > 0) ListRow(listOf("${api.pendingSync} offline ${if (api.pendingSync == 1) "entry" else "entries"} waiting to send"), onClick = { nav.navigate("offline") })
            if (api.accessHold.isNotEmpty()) {
                NoticeText("Client records open once you finish: " + api.accessHold.joinToString(", ") + ".")
                // 4.36.0: both, as the website's banner links them.
                ListRow(listOf("My agreements"), onClick = { nav.navigate("agreements") })
                ListRow(listOf("My training"), onClick = { nav.navigate("training") })
            }
            val (sign, train) = toSign
            // 4.36.0: the website's banners in its order: credentials to renew, training, agreements.
            bannerRows(sign, train, d.credentialsDue ?: emptyList()).forEach { b ->
                ListRow(listOf(b.heading, b.text), action = "open it", onClick = { nav.navigate(b.route) })
            }
            if (unread > 0) ListRow(listOf("$unread unread ${if (unread == 1) "alert" else "alerts"}"), onClick = { nav.navigate("alerts") })

            // 4.36.0: Today, as the website's dashboard: what is due, each opening its list.
            d.today?.let { items ->
                Heading(d.todayHeading ?: "Today")
                if (items.isEmpty()) Text("Nothing due today.")
                todayRows(items).forEach { row ->
                    ListRow(listOf(row.text), action = if (row.route != null) "open it" else null,
                        onClick = row.route?.let { rt -> { nav.navigate(rt) { launchSingleTop = true } } })
                }
            }

            toWrite.data?.takeIf { it.items.isNotEmpty() && api.can("clients") }?.let { w ->
                Heading("Notes to write (${w.total})")
                w.items.take(10).forEach { n ->
                    ListRow(listOf(n.clientName, n.subject), action = "write the note", onClick = { nav.navigate(n.route) })
                }
                if (w.total > 10 && api.can("schedule")) ListRow(listOf("All ${w.total} notes to write are on the Schedule"), action = "open the Schedule", onClick = { nav.navigate("schedule") { launchSingleTop = true } })
            }
            // 4.36.0: Your week, as under the website's Notes to write.
            d.weekText?.let { ListRow(listOf(it)) }

            Heading("At a glance")
            // 4.36.0: the client counts only with the clients permission, as the website.
            atAGlanceRows(d, api.can("clients"), api.can("referrals"), api.can("billing"),
                "${hoursText(d.unbilledHours)} hours, $" + String.format(Locale.US, "%,.2f", d.unbilledAmount)).forEach { (text, route) ->
                ListRow(listOf(text), onClick = route?.let { rt -> { nav.navigate(rt) } })
            }
            // 4.36.0: My tasks, as the website's: the first open ones, then the whole list.
            d.tasks?.takeIf { dashboardTasksShown(it) }?.let { t ->
                Heading(t.heading)
                t.items.forEach { x -> ListRow(listOf(x.line), action = "open the task list", onClick = { nav.navigate("taskList") }) }
                ListRow(listOf("Open the task list"), onClick = { nav.navigate("taskList") })
            }

            deadlines.data?.takeIf { it.worthShowing }?.let { rd ->
                Heading("Referral deadlines, last 90 days")
                ListRow(listOf("${rd.referrals} referral${if (rd.referrals == 1) "" else "s"}. Contacted on time: ${rd.contactedOnTime}, late: ${rd.contactedLate}."))
                ListRow(listOf("Waiting for contact: ${rd.waiting}, overdue now: ${rd.overdueNow}."), onClick = { nav.navigate("referrals") })
                ListRow(listOf("Assessed on time: ${rd.assessedOnTime}, late: ${rd.assessedLate}."))
            }

            if (d.expiring.isNotEmpty()) {
                Heading("Authorizations ending soon (${d.expiringTotal})")
                d.expiring.forEach { t ->
                    // 4.36.0: each opens the client, as the website's row links them.
                    ListRow(listOf(t.client, t.title, (if (t.expired) "Ended " else "Ends ") + dayText(t.expirationDate)),
                        action = "open the client", onClick = if (t.clientId > 0 && api.can("clients")) ({ nav.navigate("client/${t.clientId}") }) else null)
                }
            }
            if (d.recentReferrals.isNotEmpty() && api.can("referrals")) {
                Heading("Newest referrals")
                d.recentReferrals.forEach { r -> ListRow(listOf(r.clientName, r.reason, r.status + ", " + whenText(r.createdAt)), onClick = { nav.navigate("referral/${r.id}") }) }
            }
            if (d.recentNotes.isNotEmpty()) {
                Heading("Newest case notes")
                d.recentNotes.forEach { n -> ListRow(listOf(n.subject, n.clientName + ", " + n.noteType, whenText(n.createdAt)), onClick = { nav.navigate("client/${n.clientId}") }) }
            }
            SecondaryButton("Refresh") { state.reload(); toWrite.reload() }
            Text("")
        }
    }
}
