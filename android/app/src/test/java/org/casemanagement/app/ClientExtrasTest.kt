package org.casemanagement.app

import org.json.JSONArray
import org.json.JSONObject
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

class ClientExtrasTest {
    private val sample = JSONArray("""[
        {"id":3,"label":"Favourite reader","hint":"","type":"choice","options":["JAWS","NVDA"],"required":true,"value":"JAWS"},
        {"id":4,"label":"Started braille","hint":"year it began","type":"date","options":[],"required":false,"value":""},
        {"id":5,"label":"Guide dog","type":"yesno","options":[],"required":false,"value":null},
        {"id":6,"label":"Odd","type":"slider","options":[],"value":"x"},
        {"id":0,"label":"No id","type":"text","value":""}
    ]""")

    @Test fun customFieldsAreRead() {
        val f = parseCustomFields(sample)
        assertEquals(listOf(3, 4, 5, 6), f.map { it.id })
        assertEquals(listOf("JAWS", "NVDA"), f[0].options)
        assertTrue(f[0].required)
        assertEquals("year it began", f[1].hint)
        assertEquals("", f[2].value)
        assertEquals(listOf("Yes", "No"), f[2].options)
        // A type the app does not know is edited as plain text.
        assertEquals("text", f[3].type)
        assertEquals(emptyList<CustomField>(), parseCustomFields(null))
    }

    @Test fun onlyChangedAnswersAreSent() {
        val f = parseCustomFields(sample)
        assertNull(customBody(f, emptyMap()))
        assertNull(customBody(f, mapOf(3 to "JAWS", 4 to " ")))
        val b = customBody(f, mapOf(3 to "", 4 to "2020-01-05 ", 5 to "Yes"))
        assertNotNull(b)
        assertEquals("", b!!.getString("3"))
        assertEquals("2020-01-05", b.getString("4"))
        assertEquals("Yes", b.getString("5"))
        assertEquals(3, b.length())
    }

    @Test fun datesAndNumbersAreChecked() {
        val f = parseCustomFields(JSONArray("""[{"id":1,"label":"Since","type":"date","value":""},{"id":2,"label":"Hours","type":"number","value":""}]"""))
        assertNull(customFieldsProblem(f, mapOf(1 to "", 2 to "")))
        assertNull(customFieldsProblem(f, mapOf(1 to "2024-02-29", 2 to "1,250.5")))
        assertTrue(customFieldsProblem(f, mapOf(1 to "Feb 29"))!!.startsWith("Since:"))
        assertTrue(customFieldsProblem(f, mapOf(2 to "lots"))!!.startsWith("Hours:"))
    }

    @Test fun requiredFieldsOnANewClient() {
        // form_options sends the fields with every value empty.
        val f = parseCustomFields(JSONArray("""[{"id":7,"label":"Referral source","type":"text","required":true,"value":""},{"id":8,"label":"Since","type":"date","required":false,"value":""}]"""))
        assertTrue(customRequiredProblem(f, emptyMap())!!.startsWith("Referral source is required"))
        assertTrue(customRequiredProblem(f, mapOf(7 to "  "))!!.startsWith("Referral source"))
        assertNull(customRequiredProblem(f, mapOf(7 to "School")))
        assertTrue(customRequiredProblem(f, mapOf(7 to "School", 8 to "soon"))!!.startsWith("Since:"))
        assertEquals("School", customBody(f, mapOf(7 to "School", 8 to ""))!!.getString("7"))
    }

    @Test fun signatureBodyParagraphsAndBullets() {
        val blocks = signatureBlocks("You agree to services.\r\n\r\nWe will:\nteach you JAWS\n• keep your records private\n\n\n  \nLast line.")
        assertEquals(3, blocks.size)
        assertEquals(BodyBlock.Paragraph("You agree to services."), blocks[0])
        assertEquals(BodyBlock.Bullets(listOf("We will:", "teach you JAWS", "keep your records private")), blocks[1])
        assertEquals(BodyBlock.Paragraph("Last line."), blocks.last())
        assertEquals(emptyList<BodyBlock>(), signatureBlocks("  \n\n "))
    }

    @Test fun deviceCountsPrefillOnlyWhenKnown() {
        assertEquals("", prefillCount(0))
        assertEquals("3", prefillCount(3))
    }

    @Test fun timeZonesInWords() {
        assertTrue(zoneWords("America/Chicago")!!.contains("Central"))
        assertNull(zoneWords(null))
        assertEquals("Mars/Base", zoneWords("Mars/Base"))
    }

    @Test fun messageFiles() {
        assertNull(messageFileLabel(JSONObject("""{"document_id":null,"document_title":null}""")))
        assertNull(messageFileLabel(JSONObject("{}")))
        assertEquals("File: Sample plan.pdf", messageFileLabel(JSONObject("""{"document_id":12,"document_title":"Sample plan.pdf"}""")))
    }
}
