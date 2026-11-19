package org.casemanagement.app

import android.os.Build
import android.os.SystemClock
import androidx.biometric.BiometricManager
import androidx.biometric.BiometricPrompt
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.core.content.ContextCompat
import androidx.fragment.app.FragmentActivity

/** 4.34.0: how long the app may be away before it locks again (the iPhone's 2 minutes). */
const val APP_LOCK_GRACE_MS = 120_000L

/** 4.34.0: whether coming back after [awayMs] (null: it never left) locks the app again. */
fun shouldRelock(awayMs: Long?, graceMs: Long = APP_LOCK_GRACE_MS): Boolean = awayMs != null && awayMs > graceMs

/** 4.34.0: the phone's own lock the app asks for: strong biometrics or the screen lock (API 30+); below 30 the only pairing the phone offers. */
fun appLockAuthenticators(sdk: Int = Build.VERSION.SDK_INT): Int =
    if (sdk >= 30) BiometricManager.Authenticators.BIOMETRIC_STRONG or BiometricManager.Authenticators.DEVICE_CREDENTIAL
    else BiometricManager.Authenticators.BIOMETRIC_WEAK or BiometricManager.Authenticators.DEVICE_CREDENTIAL

/**
 * 4.34.0: the app lock's rules, with no Android in them (so they are unit tested): when it locks,
 * when the prompt opens by itself (once per return to the app, never in a loop), and which answer
 * from the prompt still counts (one from before the screen was made again does not).
 */
class AppLockState(startSignedIn: Boolean) {
    var locked = startSignedIn
        private set
    var needsPrompt = startSignedIn
        private set
    var authenticating = false
        private set
    /** Moves on with each prompt opened; an answer carrying an older one is ignored. */
    var generation = 0
        private set
    private var awaySince: Long? = null

    /** A password and code sign-in is the unlock; signing out leaves nothing to lock. */
    fun signedIn() { locked = false; needsPrompt = false; authenticating = false; generation++; awaySince = null }
    fun signedOut() = signedIn()

    /**
     * The Activity stopped (not for a rotation). While the prompt is up this is the phone's own PIN
     * screen (Android 8 and 9 open it as a screen of its own), not time away from the app.
     */
    fun wentAway(now: Long) { if (!authenticating && awaySince == null) awaySince = now }

    /** The Activity started again: locks after the grace period; a locked app asks again by itself once. */
    fun cameBack(signedIn: Boolean, now: Long) {
        val since = awaySince
        awaySince = null
        if (!signedIn) { locked = false; needsPrompt = false; authenticating = false; return }
        if (!locked && shouldRelock(since?.let { now - it })) { locked = true; needsPrompt = true }
        else if (locked && since != null) needsPrompt = true
    }

    /** True once per return (onResume): the prompt opens by itself; after that it waits for Unlock. */
    fun takeAutoPrompt(): Boolean {
        if (!locked || !needsPrompt || authenticating) return false
        needsPrompt = false
        return true
    }

    /** A prompt is opening: its generation, or null when one is already up (or nothing is locked). */
    fun beginAuth(): Int? {
        if (!locked || authenticating) return null
        authenticating = true
        generation++
        return generation
    }

    /** The prompt's answer; false (ignored) when it belongs to an earlier prompt. */
    fun authEnded(gen: Int, ok: Boolean): Boolean {
        if (gen != generation || !authenticating) return false
        authenticating = false
        if (ok) locked = false
        return true
    }

    /** Nothing to lock with on this phone: not locked against its own staff. */
    fun unlockWithoutPrompt() { locked = false; authenticating = false; needsPrompt = false }

    /**
     * The Activity is made again (rotation, text size, dark mode) or goes: a prompt that was up is
     * forgotten (its answer would come to a screen that is gone) and opens again on the new screen.
     */
    fun activityGone() {
        if (!authenticating) return
        authenticating = false
        generation++
        if (locked) needsPrompt = true
    }
}

/**
 * The app lock (4.34.0), as the iPhone app's AppLock: a session kept on the phone starts locked;
 * after more than two minutes away the app locks again; signing in with the password and code is
 * itself the unlock. It unlocks with this phone's fingerprint, face or screen lock (BiometricPrompt).
 * While locked nothing of the client records is shown or reachable by TalkBack (see RootScreen);
 * the open screens stay underneath, so a half-written note is kept. A phone with no screen lock
 * cannot lock the app: it is said once, and the app is not locked against its own staff. When the
 * phone cannot check its lock at all, Sign out on the lock screen is the way out.
 * Kept in the Application, so turning the phone (a new Activity) does not unlock it.
 */
class AppLock(private val store: SecureStore, startSignedIn: Boolean) {
    private val state = AppLockState(startSignedIn)
    // Compose reads these; they follow [state] after every change.
    var locked by mutableStateOf(startSignedIn)
        private set
    /** Why the last unlock did not work (shown on the lock screen, which stays). */
    var problem by mutableStateOf<String?>(null)
        private set
    /** Said once on a phone with nothing to lock with. */
    var noLockWarning by mutableStateOf<String?>(null)
    private var prompt: BiometricPrompt? = null

    private fun sync() { locked = state.locked; if (!locked) problem = null }

    fun signedIn() { state.signedIn(); sync() }
    fun signedOut() { state.signedOut(); sync() }
    fun wentAway() = state.wentAway(SystemClock.elapsedRealtime())
    fun cameBack(signedIn: Boolean) { state.cameBack(signedIn, SystemClock.elapsedRealtime()); sync() }

    /** onDestroy: a prompt that is up is closed, and opens again on the new screen (see [AppLockState.activityGone]). */
    fun activityGone() {
        if (state.authenticating) try { prompt?.cancelAuthentication() } catch (e: Exception) { }
        prompt = null
        state.activityGone()
    }

    /** Asks for the phone's lock by itself, once per return to the app (from onResume). */
    fun promptIfNeeded(activity: FragmentActivity) { if (state.takeAutoPrompt()) authenticate(activity) }

    /** The Unlock button, and the prompt that opens by itself. */
    fun authenticate(activity: FragmentActivity) {
        if (!state.locked || state.authenticating) return
        val allowed = appLockAuthenticators()
        when (BiometricManager.from(activity).canAuthenticate(allowed)) {
            BiometricManager.BIOMETRIC_SUCCESS -> {}
            BiometricManager.BIOMETRIC_ERROR_NONE_ENROLLED -> {
                // Nothing to lock with: not locked against staff, but said (once).
                warnIfNoScreenLock(activity)
                state.unlockWithoutPrompt(); sync()
                return
            }
            else -> { problem = CANNOT_CHECK; return }
        }
        store.remove(WARNED_KEY)
        val gen = state.beginAuth() ?: return
        problem = null
        val p = BiometricPrompt(activity, ContextCompat.getMainExecutor(activity), object : BiometricPrompt.AuthenticationCallback() {
            override fun onAuthenticationSucceeded(result: BiometricPrompt.AuthenticationResult) {
                if (state.authEnded(gen, ok = true)) sync()
            }
            override fun onAuthenticationError(errorCode: Int, errString: CharSequence) {
                if (!state.authEnded(gen, ok = false)) return
                problem = when (errorCode) {
                    BiometricPrompt.ERROR_LOCKOUT, BiometricPrompt.ERROR_LOCKOUT_PERMANENT ->
                        "Too many tries. Unlock the phone itself with its screen lock, or wait a while, then choose Unlock."
                    else -> "Unlock didn't succeed. Choose Unlock to try again."
                }
            }
        })
        prompt = p
        p.authenticate(BiometricPrompt.PromptInfo.Builder().setTitle("Unlock your client records")
            .setSubtitle("Use this phone's fingerprint, face or screen lock.").setAllowedAuthenticators(allowed).build())
    }

    /** Says once (from onResume, while signed in) when the phone has nothing to lock the app with. */
    fun warnIfNoScreenLock(activity: FragmentActivity) {
        if (BiometricManager.from(activity).canAuthenticate(appLockAuthenticators()) != BiometricManager.BIOMETRIC_ERROR_NONE_ENROLLED) return
        if (store.bool(WARNED_KEY)) return
        store.put(WARNED_KEY, true)
        noLockWarning = NO_SCREEN_LOCK
    }

    companion object {
        private const val WARNED_KEY = "noScreenLockWarned"
        const val NO_SCREEN_LOCK = "This phone has no screen lock, so the app cannot lock. Set a screen lock in the phone's Settings to protect client records."
        const val CANNOT_CHECK = "This phone cannot check your fingerprint, face or screen lock right now, so your client records stay locked. Unlock the phone itself, or wait a while, then choose Unlock; or sign out and sign in again with your password and code."
    }
}
