package org.casemanagement.app

import org.casemanagement.app.ui.FILTER_FROM
import org.casemanagement.app.ui.Gate
import org.casemanagement.app.ui.errorSummary
import org.casemanagement.app.ui.fieldLabelText
import org.casemanagement.app.ui.filterOptions
import org.casemanagement.app.ui.indexOfChoice
import org.casemanagement.app.ui.missingNames
import org.casemanagement.app.ui.stillNeeded
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

/** 4.30.0 accessibility: the error summary, "still needed", the pick-list filter and field labels. */
class V430Test {
    @Test fun summaryListsEveryProblem() {
        assertNull(errorSummary(emptyList()))
        assertNull(errorSummary(listOf(null, " ")))
        assertEquals("Enter the hours.", errorSummary(listOf(null, "Enter the hours.")))
        assertEquals("2 things to fix: Enter the date. Enter the hours.", errorSummary(listOf("Enter the date.", null, "Enter the hours.")))
        // A message without a full stop gets one; the same message twice is listed once.
        assertEquals("2 things to fix: Choose a client. Say what was done.", errorSummary(listOf("Choose a client", "Say what was done.", "Say what was done.")))
    }

    @Test fun stillNeededNamesEachMissingField() {
        assertNull(stillNeeded(emptyList()))
        assertEquals("Still needed: Subject.", stillNeeded(listOf("Subject")))
        assertEquals("Still needed: Subject and Note.", stillNeeded(listOf("Subject", "Note")))
        assertEquals("Still needed: Name, Email and Purpose.", stillNeeded(listOf("Name", "Email", "Purpose")))
        assertEquals(listOf("Note"), missingNames("Subject" to false, "Note" to true))
        assertNull(stillNeeded(missingNames("Subject" to false)))
    }

    @Test fun filterMatchesEveryWordQuietly() {
        val clients = listOf("" to "Choose a client", "1" to "José García", "2" to "Jose Smith", "3" to "Ann Lee", "4" to "Lee Ann Brown")
        assertEquals(clients, filterOptions(clients, "  "))
        // Accents and case are ignored.
        assertEquals(listOf("1", "2"), filterOptions(clients, "JOSE").map { it.first })
        // Every word, in any order.
        assertEquals(listOf("3", "4"), filterOptions(clients, "ann lee").map { it.first })
        assertEquals(listOf("4"), filterOptions(clients, "brown ann").map { it.first })
        assertTrue(filterOptions(clients, "nobody").isEmpty())
        // The current choice stays in the list.
        assertEquals(listOf("3", "4"), filterOptions(clients, "brown", keep = "3").map { it.first }.sorted())
        assertEquals(15, FILTER_FROM)
    }

    @Test fun listOpensAtTheCurrentChoice() {
        val states = listOf("" to "Choose a state", "AL" to "Alabama", "TX" to "Texas")
        assertEquals(2, indexOfChoice(states, "TX"))
        assertEquals(0, indexOfChoice(states, ""))
        assertEquals(0, indexOfChoice(states, "ZZ"))
    }

    @Test fun labelsCarryRequiredAndTheHint() {
        assertEquals("Subject", fieldLabelText("Subject", null, false))
        assertEquals("Subject (required)", fieldLabelText("Subject", null, true))
        assertEquals("Miles driven (0 if none)", fieldLabelText("Miles driven", "0 if none", false))
        assertEquals("Hours (required; such as 1.5)", fieldLabelText("Hours", "such as 1.5", true))
        assertEquals("Notes", fieldLabelText("Notes", " ", false))
    }

    @Test fun gateShowsWhatIsMissingOnEachPress() {
        val g = Gate()
        assertFalse(g.check("Still needed: Subject."))
        assertEquals("Still needed: Subject.", g.shown)
        assertFalse(g.check("Still needed: Subject."))
        // A second press counts again, so the message takes focus again.
        assertEquals(2, g.presses)
        assertTrue(g.check(null))
        assertNull(g.shown)
    }
}
