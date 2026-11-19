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
    private val lock get() = (application as CmsApp).lock

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        // Client records never show in the recent-apps preview or in screenshots taken by other apps.
        window.setFlags(WindowManager.LayoutParams.FLAG_SECURE, WindowManager.LayoutParams.FLAG_SECURE)
        enableEdgeToEdge()
        setContent { CmsTheme { RootScreen(api, lock) } }
    }

    // 4.34.0: the app lock counts the time away, and locks again before anything is drawn on return.
    override fun onStart() {
        super.onStart()
        lock.cameBack(api.isSignedIn || api.reauthRequired)
    }

    // 4.34.0: a prompt that was up when the screen is made again (rotation, text size, dark mode)
    // is closed here and opens again on the new screen, so Unlock never stops working.
    override fun onDestroy() {
        lock.activityGone()
        super.onDestroy()
    }

    override fun onStop() {
        super.onStop()
        if (!isChangingConfigurations) lock.wentAway()
    }

    override fun onResume() {
        super.onResume()
        if (api.isSignedIn || api.reauthRequired) { lock.promptIfNeeded(this); if (!lock.locked) lock.warnIfNoScreenLock(this) }
        // Coming back to the app retries anything written without a signal.
        lifecycleScope.launch { api.flushQueue(); api.flushSignatures() }
    }
}
