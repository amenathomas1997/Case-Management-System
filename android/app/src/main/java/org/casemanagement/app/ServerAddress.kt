package org.casemanagement.app

import java.net.URI

/**
 * The organisation's server, as the iPhone app reads it (API.apiURL, 4.6.0): "cms.example.org",
 * "example.org/cms" or "https://example.org/cms/login.php" all become https://…/api.php.
 * Anything that is not https, or has no host, is refused rather than guessed at.
 */
object ServerAddress {
    fun apiUrl(raw: String): String? {
        var s = raw.trim()
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
        val port = if (uri.port > 0) ":${uri.port}" else ""
        return "https://$host$port$path"
    }

    /** The host to name to the person ("Signing in to cms.example.org"). */
    fun host(apiUrl: String): String = try { URI(apiUrl).host ?: apiUrl } catch (e: Exception) { apiUrl }
}
