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
        val toDo = (try { api.call("agreements").optInt("needed") } catch (e: Exception) { 0 }) to (try { api.call("training").optInt("needed") } catch (e: Exception) { 0 })
        Triple(parseDashboard(api.call("dashboard")), try { api.call("notifications", mapOf("count_only" to "1")).optInt("unread") } catch (e: Exception) { 0 }, toDo)
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
            if (api.accessHold.isNotEmpty()) NoticeText("Client records open once you finish: " + api.accessHold.joinToString(", ") + ".")
            val (sign, train) = toSign
            if (sign > 0) ListRow(listOf(if (sign == 1) "An agreement to sign" else "$sign agreements to sign"), action = "read and sign", onClick = { nav.navigate("agreements") })
            if (train > 0) ListRow(listOf(if (train == 1) "Training to take" else "$train trainings to take"), action = "open My training", onClick = { nav.navigate("training") })
            if (unread > 0) ListRow(listOf("$unread unread ${if (unread == 1) "alert" else "alerts"}"), onClick = { nav.navigate("alerts") })

            toWrite.data?.takeIf { it.items.isNotEmpty() && api.can("clients") }?.let { w ->
                Heading("Notes to write (${w.total})")
                w.items.take(10).forEach { n ->
                    ListRow(listOf(n.clientName, n.subject), action = "write the note", onClick = { nav.navigate(n.route) })
                }
                if (w.total > 10 && api.can("schedule")) ListRow(listOf("All ${w.total} notes to write are on the Schedule"), action = "open the Schedule", onClick = { nav.navigate("schedule") { launchSingleTop = true } })
            }

            Heading("At a glance")
            ListRow(listOf("Active clients: ${d.activeClients}"))
            ListRow(listOf("Waiting for assessment: ${d.pendingClients}"))
            if (api.can("referrals")) ListRow(listOf("New referrals: ${d.newReferrals}"), onClick = { nav.navigate("referrals") })
            if (api.can("billing")) ListRow(listOf("Unbilled: ${hoursText(d.unbilledHours)} hours, $" + String.format(Locale.US, "%,.2f", d.unbilledAmount)))

            deadlines.data?.takeIf { it.worthShowing }?.let { rd ->
                Heading("Referral deadlines, last 90 days")
                ListRow(listOf("${rd.referrals} referral${if (rd.referrals == 1) "" else "s"}. Contacted on time: ${rd.contactedOnTime}, late: ${rd.contactedLate}."))
                ListRow(listOf("Waiting for contact: ${rd.waiting}, overdue now: ${rd.overdueNow}."), onClick = { nav.navigate("referrals") })
                ListRow(listOf("Assessed on time: ${rd.assessedOnTime}, late: ${rd.assessedLate}."))
            }

            if (d.expiring.isNotEmpty()) {
                Heading("Authorizations ending soon (${d.expiringTotal})")
                d.expiring.forEach { t ->
                    ListRow(listOf(t.client, t.title, (if (t.expired) "Ended " else "Ends ") + dayText(t.expirationDate)))
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
