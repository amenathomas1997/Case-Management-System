package org.casemanagement.app

import java.net.URI

/**
 * The organisation's server, as the iPhone app reads it (API.apiURL, 4.6.0): "cms.example.org",
 * "example.org/cms" or "https://example.org/cms/login.php" all become https://…/api.php.
 * Anything that is not https, or has no host, is refused rather than guessed at.
 */
object ServerAddress {
    /** Plain http to this computer, for a debug build talking to a local test server (never in a release build). */
    private val devHosts = setOf("10.0.2.2", "localhost", "127.0.0.1")

    fun apiUrl(raw: String, allowDevHttp: Boolean = false): String? {
        var s = raw.trim()
        if (allowDevHttp && s.lowercase().startsWith("http://")) {
            val uri = try { URI(s) } catch (e: Exception) { return null }
            val host = uri.host?.lowercase() ?: return null
            if (host !in devHosts || uri.rawUserInfo != null) return null
            val port = if (uri.port > 0) ":${uri.port}" else ""
            return "http://$host$port/api.php"
        }
        // No user name or password part, no backslash, no space: "good.org@evil.com" would
        // quietly send everything to evil.com.
        if (s.isEmpty() || s.contains('@') || s.contains('\\') || s.contains(' ')) return null
        val lower = s.lowercase()
        if (lower.contains("://") && !lower.startsWith("https://")) return null
        s = when {
            lower.startsWith("https://") -> "https://" + s.substring(8)
            lower.startsWith("http:") || lower.startsWith("https:") || lower.startsWith("http//") || lower.startsWith("https//") -> return null
            else -> "https://$s"
        }
        val uri = try { URI(s) } catch (e: Exception) { return null }
        val host = uri.host?.lowercase() ?: return null
        if (uri.scheme != "https" || host.isEmpty() || !(host.contains('.') || host == "localhost")) return null
        if (uri.rawUserInfo != null) return null
        var path = uri.rawPath ?: ""
        if (path.lowercase().endsWith("/api.php")) path = path.dropLast(8) + "/api.php"
        if (!path.endsWith("/api.php")) {
            if (path.endsWith(".php")) path = path.substringBeforeLast('/')
            if (!path.endsWith("/")) path += "/"
            path += "api.php"
        }
        // 4.27.0: https's own port (443) is the same server as no port.
        val port = if (uri.port > 0 && uri.port != 443) ":${uri.port}" else ""
        return "https://$host$port$path"
    }

    /**
     * Whether two typed addresses name the same server (4.27.0): compared as the API address each
     * becomes, so "https://", capitals in the host and a trailing slash make no difference.
     * An address that cannot be read is never the same as anything.
     */
    fun same(a: String, b: String, allowDevHttp: Boolean = false): Boolean {
        val x = apiUrl(a, allowDevHttp) ?: return false
        return x == apiUrl(b, allowDevHttp)
    }

    /** The host to name to the person ("Signing in to cms.example.org"). */
    fun host(apiUrl: String): String = try { URI(apiUrl).host ?: apiUrl } catch (e: Exception) { apiUrl }
}
