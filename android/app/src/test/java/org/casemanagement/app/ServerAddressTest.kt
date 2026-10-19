package org.casemanagement.app

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Assert.assertNull
import org.junit.Test

class ServerAddressTest {
    @Test fun plainHostBecomesApiAddress() =
        assertEquals("https://cms.example.org/api.php", ServerAddress.apiUrl("cms.example.org"))

    @Test fun folderIsKept() =
        assertEquals("https://example.org/cms/api.php", ServerAddress.apiUrl("example.org/cms"))

    @Test fun pageAddressUsesItsFolder() =
        assertEquals("https://example.org/cms/api.php", ServerAddress.apiUrl("https://Example.org/cms/login.php"))

    @Test fun apiAddressIsKept() =
        assertEquals("https://example.org/api.php", ServerAddress.apiUrl("https://example.org/API.php"))

    @Test fun debugBuildsMayUseThisComputerOverHttp() {
        assertEquals("http://10.0.2.2:8099/api.php", ServerAddress.apiUrl("http://10.0.2.2:8099", allowDevHttp = true))
        assertNull(ServerAddress.apiUrl("http://10.0.2.2:8099"))
        assertNull(ServerAddress.apiUrl("http://example.org", allowDevHttp = true))
    }

    @Test fun insecureOrOddAddressesAreRefused() {
        assertNull(ServerAddress.apiUrl("http://example.org"))
        assertNull(ServerAddress.apiUrl("ftp://example.org"))
        assertNull(ServerAddress.apiUrl("good.org@evil.com"))
        assertNull(ServerAddress.apiUrl("https//example.org"))
        assertNull(ServerAddress.apiUrl("nodot"))
        assertNull(ServerAddress.apiUrl(""))
        assertNull(ServerAddress.apiUrl("exa mple.org"))
    }

    // 4.27.0 (H6): editing the address on Sign in is the same server unless it really differs.
    @Test fun sameServerWhateverTheSpelling() {
        assertTrue(ServerAddress.same("cms.example.org", "https://CMS.Example.org/"))
        assertTrue(ServerAddress.same("example.org/cms", "https://example.org/cms/"))
        assertTrue(ServerAddress.same("example.org/cms/", "example.org/cms/login.php"))
        assertTrue(ServerAddress.same(" cms.example.org ", "cms.example.org:443"))
        assertTrue(ServerAddress.same("https://cms.example.org/api.php", "cms.example.org"))
        assertTrue(ServerAddress.same("http://10.0.2.2:8099", "http://10.0.2.2:8099/", allowDevHttp = true))
    }

    @Test fun differentServersAreDifferent() {
        assertFalse(ServerAddress.same("cms.example.org", "cms.example.com"))
        assertFalse(ServerAddress.same("example.org/cms", "example.org"))
        assertFalse(ServerAddress.same("example.org", "example.org:8443"))
        assertFalse(ServerAddress.same("not an address", "not an address"))
        assertFalse(ServerAddress.same("", ""))
    }
}
