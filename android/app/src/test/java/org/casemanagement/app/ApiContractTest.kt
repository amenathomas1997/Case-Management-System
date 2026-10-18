package org.casemanagement.app

import org.json.JSONObject
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

/**
 * The app's own parsing (Parsing.kt and the 4.25.0 helpers) run over real replies captured from
 * the server (src/test/resources/api, written by tests/v426_cases.php, which also checks on every
 * server test run that the server still sends every field these samples have). Each test asserts
 * what its screen shows is read: names present, ids above zero, the lists the screen lists.
 */
class ApiContractTest {
    private fun reply(name: String): JSONObject {
        val stream = javaClass.classLoader!!.getResourceAsStream("api/$name.json") ?: error("No sample api/$name.json")
        val r = JSONObject(stream.bufferedReader(Charsets.UTF_8).use { it.readText() })
        assertTrue("$name: the sample is an ok reply", r.optBoolean("ok"))
        return r
    }

    private fun assertIds(what: String, ids: List<Int>) {
        assertTrue("$what: the list is not empty", ids.isNotEmpty())
        assertTrue("$what: every id is above zero", ids.all { it > 0 })
    }

    private fun assertNames(what: String, names: List<String>) {
        assertTrue("$what: every name is present", names.isNotEmpty() && names.all { it.isNotBlank() && it != "null" })
    }

    @Test fun me() {
        val m = parseMe(reply("me"))
        assertTrue(m.id > 0)
        assertNames("me", listOf(m.name))
        assertTrue("clients" in m.perms)
        assertNotNull(m.serverVersion)
        assertEquals("America/Chicago", m.timezone)
    }

    @Test fun formOptions() {
        val r = reply("form_options")
        val lists = parseFormLists(r)
        listOf("note_types", "equipment_categories", "equipment_conditions", "maintenance_types", "client_statuses").forEach {
            assertTrue("form_options: $it", lists[it]?.isNotEmpty() == true)
        }
        val custom = parseCustomFields(r.optJSONArray("custom_fields"))
        assertIds("custom fields", custom.map { it.id })
        assertNames("custom fields", custom.map { it.label })
        val offices = parseOffices(r)
        assertIds("offices", offices.map { it.id })
        assertNames("offices", offices.map { it.name })
    }

    @Test fun clientsAndChoices() {
        val clients = parseClients(reply("clients"))
        assertIds("clients", clients.map { it.id })
        assertNames("clients", clients.map { it.name })
        assertTrue(clients.all { it.status.isNotBlank() })
        val choices = parseClients(reply("client_choices"))
        assertIds("client_choices", choices.map { it.id })
        assertNames("client_choices", choices.map { it.name })
        // Closed clients are left out when choosing one for something new.
        val open = openClientChoices(reply("client_choices"))
        assertTrue(open.isNotEmpty() && open.none { it.status.startsWith("Closed") })
    }

    @Test fun client() {
        val d = parseClientDetail(reply("client"))
        assertNotNull(d)
        d!!
        assertTrue(d.id > 0)
        assertNames("client", listOf(d.name, d.status))
        assertTrue("4.25.0 detail", d.has425)
        assertTrue(d.signaturesWaiting > 0)
        assertIds("notes", d.notes.map { it.id })
        assertNames("note subjects", d.notes.map { it.subject })
        assertTrue(d.notesTotal >= d.notes.size)
        assertIds("releases", d.releases.map { it.id })
        assertNames("release recipients", d.releases.map { it.recipientName })
        assertTrue(d.customFields.isNotEmpty() && d.customFields.all { it.first.isNotBlank() && it.second.isNotBlank() })
        // The many single fields the screen reads straight from the record.
        assertEquals(d.name, d.raw.str("name"))
    }

    @Test fun clientHistory() {
        val h = parseClientHistory(reply("client_history"))
        assertIds("history", h.map { it.id })
        assertNames("history", h.map { it.newStatus })
        assertTrue(h.all { it.changedAt != null && it.changedBy.isNotBlank() })
    }

    @Test fun schedule() {
        val s = parseSchedule(reply("schedule"))
        assertIds("sessions", s.map { it.id })
        assertNames("sessions", s.map { it.client })
        assertTrue(s.all { it.startAt != null && it.endAt != null && it.status.isNotBlank() })
        assertTrue(s.any { it.isRemote && it.meetingUrl != null })
    }

    @Test fun tracks() {
        val t = parseTracks(reply("tracks"))
        assertIds("tracks", t.map { it.id })
        assertNames("track titles", t.map { it.title })
        assertNames("track clients", t.map { it.client })
        assertTrue(t.all { it.clientId > 0 })
        assertTrue(t.any { it.visitCategories.isNotEmpty() })
    }

    @Test fun goals() {
        // The captured track has no goals yet; the list must still read, as an empty one.
        assertEquals(emptyList<Goal>(), parseGoals(reply("goals")))
        val g = parseGoals(JSONObject("""{"ok":true,"goals":[{"id":4,"specific_goal":"Read email with JAWS","measurable_metric":"3 of 4 tries","focus_area":null,
            "status":"In Progress","target_date":"2026-12-01","progress_pct":"40"},{"id":0,"specific_goal":"no id"}]}"""))
        assertEquals(1, g.size)
        assertEquals(40, g[0].progressPct)
        assertNull(g[0].focusArea)
        val t = parseGoalTemplates(reply("goal_templates"))
        assertIds("goal templates", t.templates.map { it.id })
        assertNames("goal templates", t.templates.map { it.specificGoal })
        assertTrue(t.categories.isNotEmpty())
        assertTrue(t.more)
    }

    @Test fun workLogs() {
        val l = parseWorkLogs(reply("work_logs"))
        assertIds("work logs", l.map { it.id })
        assertNames("work logs", l.map { it.description })
        assertEquals(1.5, l[0].hours, 0.001)
        assertTrue(l[0].isBilled)
        assertNotNull(l[0].workDate)
    }

    @Test fun messages() {
        val threads = parseMessageThreads(reply("message_threads"))
        assertTrue(threads.isNotEmpty() && threads.all { it.clientId > 0 })
        assertNames("threads", threads.map { it.clientName })
        val t = parseMessageThread(reply("message_thread"))
        assertNames("thread", listOf(t.clientName))
        assertIds("messages", t.messages.map { it.id })
        assertNames("message senders", t.messages.map { it.who })
        val withFile = t.messages.first { it.documentId != null }
        assertEquals("File: plan.pdf", withFile.fileLabel)
        assertNotNull(t.textBlocked)
    }

    @Test fun documentsAndHomework() {
        val d = parseDocuments(reply("documents"))
        assertIds("documents", d.map { it.id })
        assertNames("documents", d.map { it.title })
        assertEquals("Shared with the client and the counselor", sharedWords(d[0]))
        assertFalse(d[0].portalFixed)
        // No homework on the captured client: an empty list, read without error.
        assertEquals(emptyList<HomeworkItem>(), parseHomework(reply("homework")))
    }

    @Test fun signatures() {
        val s = parseSignatures(reply("signatures"))
        assertIds("signatures", s.items.map { it.id })
        assertNames("signatures", s.items.map { it.title })
        assertTrue(s.items.all { it.status.isNotBlank() })
        assertTrue(s.items.any { it.signedHere && it.open })
        assertEquals(listOf("services", "loan", "document"), s.kinds.map { it.first })
        assertTrue(s.kinds.all { it.second.isNotBlank() })
        val doc = parseSignatureReply(reply("signature"))
        assertNotNull(doc)
        doc!!
        assertTrue(doc.id > 0)
        assertNames("signature", listOf(doc.title, doc.body, doc.signerName, doc.org))
        assertNull(doc.lang)
        // Kept on the phone and read back (a prepared document) without losing anything.
        assertEquals(doc, parseSignatureDoc(doc.toJson()))
    }

    @Test fun releaseChoices() {
        val c = parseReleaseChoices(reply("release_choices"))
        assertTrue(c.recipientTypes.isNotEmpty() && c.infoItems.isNotEmpty())
        assertTrue(c.validityDays > 0)
    }

    @Test fun referrals() {
        val d = parseReferralDeadlines(reply("referral_deadlines"))
        assertTrue(d.referrals > 0)
        assertTrue(d.worthShowing)
        val r = parseReferrals(reply("referrals"))
        assertIds("referrals", r.map { it.id })
        assertNames("referrals", r.map { it.clientName })
        assertTrue(r.all { it.status.isNotBlank() })
    }

    @Test fun tasks() {
        val t = parseTasks(reply("tasks"))
        assertIds("tasks", t.map { it.id })
        assertNames("tasks", t.map { it.title })
        assertTrue(t.any { it.open && it.daysLeft != null })
        assertTrue(t.any { it.canOpenClient && it.clientId != null && it.clientName != null })
        assertEquals("due today", dueWords(0))
        assertEquals("overdue by 1 day", dueWords(-1))
        assertEquals("no due date", dueWords(null))
    }

    @Test fun notifications() {
        val a = parseNotifications(reply("notifications"))
        assertIds("alerts", a.items.map { it.id })
        assertNames("alerts", a.items.map { it.message })
        assertTrue(a.unread > 0 && a.items.any { it.unread })
    }

    @Test fun dashboard() {
        val d = parseDashboard(reply("dashboard"))
        assertTrue(d.activeClients > 0)
        assertIds("expiring", d.expiring.map { it.id })
        assertNames("expiring", d.expiring.map { it.client })
        assertTrue(d.expiringTotal >= d.expiring.size)
        assertIds("recent referrals", d.recentReferrals.map { it.id })
        assertNames("recent referrals", d.recentReferrals.map { it.clientName })
        assertIds("recent notes", d.recentNotes.map { it.id })
        assertTrue(d.recentNotes.all { it.clientId > 0 && it.subject.isNotBlank() })
    }

    @Test fun equipment() {
        val e = parseEquipment(reply("equipment"))
        assertIds("equipment", e.map { it.id })
        assertNames("equipment", e.map { it.name })
        assertTrue(e.any { it.status == "On Loan" && it.canReturn && it.borrower != null })
        assertTrue(distinctItems(e).map { it.id }.toSet().size == distinctItems(e).size)
        val d = parseEquipmentDetail(reply("equipment_detail"))
        assertNotNull(d)
        d!!
        assertTrue(d.id > 0)
        assertNames("equipment detail", listOf(d.name, d.status, d.condition))
        assertNull(d.serial)
        assertEquals(125.5, d.value!!, 0.001)
        assertEquals(36, d.usefulLifeMonths)
        assertNull(d.intervalDays)
        assertTrue(d.maintenance.isNotEmpty() && d.maintenance.all { it.serviceType.isNotBlank() && it.serviceDate != null })
        assertEquals(10.5, d.maintenance[0].cost!!, 0.001)
    }

    @Test fun reservations() {
        val r = parseReservations(reply("reservations"))
        assertIds("reservations", r.map { it.id })
        assertNames("reservations", r.map { it.itemName })
        assertNames("reservation clients", r.map { it.clientName })
        assertEquals(setOf("Reserved", "Waitlist"), r.map { it.status }.toSet())
        assertEquals("Item reserved.", reservationWords(JSONObject("""{"ok":true,"status":"Reserved"}""")))
        assertEquals("Added to the waitlist. The item is not available now.", reservationWords(JSONObject("""{"ok":true,"status":"Waitlist"}""")))
    }
}
