package org.casemanagement.app

import org.casemanagement.app.ui.loginCodeHelp
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Test

/** 4.27.0: an account with an authenticator app is asked for that code only. */
class LoginCodeHelpTest {
    @Test fun authenticatorOnlySaysNothingOfEmail() {
        val t = loginCodeHelp(emailSent = false, totpAvailable = true, totpRequired = true, minutes = 10)
        assertEquals("Enter the 6-digit code from your authenticator app.", t)
        assertFalse(t.contains("email"))
    }

    @Test fun emailedCodeAsBefore() {
        assertEquals("Enter the 6-digit code we emailed you. It works for 10 minutes.", loginCodeHelp(true, false, false, 10))
        assertEquals("The code email could not be sent. Enter the code from your authenticator app.", loginCodeHelp(false, true, false, 10))
    }
}
