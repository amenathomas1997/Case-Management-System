package org.casemanagement.app

import org.junit.Assert.assertEquals
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
}
