package org.casemanagement.app.ui

import androidx.compose.foundation.isSystemInDarkTheme
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.darkColorScheme
import androidx.compose.material3.lightColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.ui.graphics.Color

// The iPhone app's colours (CMSBrand and the rest): navy on white, light blue on near-black.
// Every text colour here is 4.5:1 or more against its background.
private val Light = lightColorScheme(
    primary = Color(0xFF1E3A8A), onPrimary = Color.White,
    primaryContainer = Color(0xFFDCE4FB), onPrimaryContainer = Color(0xFF0F1F4D),
    secondary = Color(0xFF0F5F5C), onSecondary = Color.White,
    error = Color(0xFF9B1C1C), onError = Color.White,
    background = Color.White, onBackground = Color(0xFF111827),
    surface = Color.White, onSurface = Color(0xFF111827),
    surfaceVariant = Color(0xFFF1F3F8), onSurfaceVariant = Color(0xFF374151),
    outline = Color(0xFF6B7280),
)
private val Dark = darkColorScheme(
    primary = Color(0xFFC2D0FB), onPrimary = Color(0xFF0F1F4D),
    primaryContainer = Color(0xFF253A75), onPrimaryContainer = Color(0xFFE3E9FD),
    secondary = Color(0xFF7FD6D1), onSecondary = Color(0xFF00302E),
    error = Color(0xFFFFB4AB), onError = Color(0xFF690005),
    background = Color(0xFF111318), onBackground = Color(0xFFE5E7EB),
    surface = Color(0xFF111318), onSurface = Color(0xFFE5E7EB),
    surfaceVariant = Color(0xFF1F2430), onSurfaceVariant = Color(0xFFD1D5DB),
    outline = Color(0xFF9CA3AF),
)

@Composable
fun CmsTheme(content: @Composable () -> Unit) {
    MaterialTheme(colorScheme = if (isSystemInDarkTheme()) Dark else Light, content = content)
}
