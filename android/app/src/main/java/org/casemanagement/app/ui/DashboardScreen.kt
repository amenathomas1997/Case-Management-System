package org.casemanagement.app.ui

import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.navigation.NavHostController
import org.casemanagement.app.Api
import org.casemanagement.app.objects
import org.casemanagement.app.str
import java.util.Locale

/** The website's dashboard: counts, authorizations ending soon, the newest referrals and notes. */
@Composable
fun DashboardScreen(api: Api, nav: NavHostController) {
    val state = rememberLoaded(api.userId) {
        val toDo = (try { api.call("agreements").optInt("needed") } catch (e: Exception) { 0 }) to (try { api.call("training").optInt("needed") } catch (e: Exception) { 0 })
        Triple(api.call("dashboard"), try { api.call("notifications", mapOf("count_only" to "1")).optInt("unread") } catch (e: Exception) { 0 }, toDo)
    }
    // Referral deadlines (4.25.0), for those who work referrals; left out when it cannot be read.
    val deadlines = rememberLoaded(api.userId, api.can("referrals")) {
        if (api.can("referrals")) try { api.call("referral_deadlines") } catch (e: Exception) { null } else null
    }
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

            Heading("At a glance")
            ListRow(listOf("Active clients: ${d.optInt("active_clients")}"))
            ListRow(listOf("Waiting for assessment: ${d.optInt("pending_clients")}"))
            if (api.can("referrals")) ListRow(listOf("New referrals: ${d.optInt("new_referrals")}"), onClick = { nav.navigate("referrals") })
            if (api.can("billing")) ListRow(listOf("Unbilled: ${hoursText(d.optDouble("unbilled_hours", 0.0))} hours, $" + String.format(Locale.US, "%,.2f", d.optDouble("unbilled_amount", 0.0))))

            deadlines.data?.takeIf { it.optInt("referrals") > 0 || it.optInt("overdue_now") > 0 }?.let { rd ->
                Heading("Referral deadlines, last 90 days")
                ListRow(listOf("${rd.optInt("referrals")} referral${if (rd.optInt("referrals") == 1) "" else "s"}. Contacted on time: ${rd.optInt("contacted_on_time")}, late: ${rd.optInt("contacted_late")}."))
                ListRow(listOf("Waiting for contact: ${rd.optInt("waiting")}, overdue now: ${rd.optInt("overdue_now")}."), onClick = { nav.navigate("referrals") })
                ListRow(listOf("Assessed on time: ${rd.optInt("assessed_on_time")}, late: ${rd.optInt("assessed_late")}."))
            }

            val expiring = d.optJSONArray("expiring").objects()
            if (expiring.isNotEmpty()) {
                Heading("Authorizations ending soon (${d.optInt("expiring_total", expiring.size)})")
                expiring.forEach { t ->
                    ListRow(listOf(t.optString("client"), t.optString("title"),
                        (if (t.optBoolean("is_expired")) "Ended " else "Ends ") + dayText(t.str("expiration_date"))))
                }
            }
            val refs = d.optJSONArray("recent_referrals").objects()
            if (refs.isNotEmpty() && api.can("referrals")) {
                Heading("Newest referrals")
                refs.forEach { r -> ListRow(listOf(r.optString("client_name"), r.optString("reason"), r.optString("status") + ", " + whenText(r.str("created_at"))), onClick = { nav.navigate("referral/${r.optInt("id")}") }) }
            }
            val notes = d.optJSONArray("recent_notes").objects()
            if (notes.isNotEmpty()) {
                Heading("Newest case notes")
                notes.forEach { n -> ListRow(listOf(n.optString("subject"), n.optString("client_name") + ", " + n.optString("note_type"), whenText(n.str("created_at"))), onClick = { nav.navigate("client/${n.optInt("client_id")}") }) }
            }
            SecondaryButton("Refresh") { state.reload() }
            Text("")
        }
    }
}
