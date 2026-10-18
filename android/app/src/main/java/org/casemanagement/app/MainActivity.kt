package org.casemanagement.app

import android.os.Bundle
import android.view.WindowManager
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import androidx.fragment.app.FragmentActivity
import androidx.lifecycle.lifecycleScope
import kotlinx.coroutines.launch
import org.casemanagement.app.ui.CmsTheme
import org.casemanagement.app.ui.RootScreen

// A FragmentActivity, so the phone's own lock (BiometricPrompt) can be asked for.
class MainActivity : FragmentActivity() {
    private val api get() = (application as CmsApp).api

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        // Client records never show in the recent-apps preview or in screenshots taken by other apps.
        window.setFlags(WindowManager.LayoutParams.FLAG_SECURE, WindowManager.LayoutParams.FLAG_SECURE)
        enableEdgeToEdge()
        setContent { CmsTheme { RootScreen(api) } }
    }

    override fun onResume() {
        super.onResume()
        // Coming back to the app retries anything written without a signal.
        lifecycleScope.launch { api.flushQueue() }
    }
}
