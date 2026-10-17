package org.casemanagement.app

import android.app.KeyguardManager
import android.content.Context
import android.content.SharedPreferences
import androidx.security.crypto.EncryptedSharedPreferences
import androidx.security.crypto.MasterKey

/**
 * What the app keeps on the phone: the session, the account's permissions, the organisation's
 * lists and unsent offline entries. All of it is encrypted with a key held in the phone's
 * hardware keystore (as the iPhone app uses the Keychain), and none of it goes into backups
 * (see res/xml/backup_rules.xml).
 */
class SecureStore(context: Context) {
    private val app = context.applicationContext
    private val prefs: SharedPreferences = try {
        open()
    } catch (e: Exception) {
        // The key was lost (a restored phone, some system updates): what was kept cannot be read,
        // so it is removed and the person signs in again, rather than the app failing every launch.
        app.deleteSharedPreferences(FILE)
        try { java.security.KeyStore.getInstance("AndroidKeyStore").apply { load(null) }.deleteEntry(MasterKey.DEFAULT_MASTER_KEY_ALIAS) } catch (x: Exception) { }
        open()
    }

    private fun open(): SharedPreferences = EncryptedSharedPreferences.create(
        app, FILE,
        MasterKey.Builder(app).setKeyScheme(MasterKey.KeyScheme.AES256_GCM).build(),
        EncryptedSharedPreferences.PrefKeyEncryptionScheme.AES256_SIV,
        EncryptedSharedPreferences.PrefValueEncryptionScheme.AES256_GCM,
    )

    fun string(key: String): String = prefs.getString(key, "") ?: ""
    fun int(key: String): Int = prefs.getInt(key, 0)
    fun bool(key: String): Boolean = prefs.getBoolean(key, false)
    fun put(key: String, value: String) = prefs.edit().putString(key, value).apply()
    fun put(key: String, value: Int) = prefs.edit().putInt(key, value).apply()
    fun put(key: String, value: Boolean) = prefs.edit().putBoolean(key, value).apply()
    fun remove(vararg keys: String) = prefs.edit().apply { keys.forEach { remove(it) } }.apply()

    /** Removes every key starting with [prefix] (an account's saved lists). */
    fun removePrefix(prefix: String) = prefs.edit().apply { prefs.all.keys.filter { it.startsWith(prefix) }.forEach { remove(it) } }.apply()

    /** Written at once (the offline queue must not be lost if the app is closed straight after). */
    fun putNow(key: String, value: String): Boolean = prefs.edit().putString(key, value).commit()

    /** Everything, on a phone reported lost. The server address stays, so sign-in does not ask again. */
    fun wipeExceptServer() {
        val server = string(SERVER_KEY)
        prefs.edit().clear().commit()
        if (server.isNotEmpty()) put(SERVER_KEY, server)
    }

    /**
     * Client details are kept on this phone (offline entries, saved lists) only when it has a
     * screen lock, as the iPhone app requires a passcode (3.0.39).
     */
    val phoneCanKeepClientData: Boolean
        get() = (app.getSystemService(Context.KEYGUARD_SERVICE) as KeyguardManager).isDeviceSecure

    companion object {
        const val SERVER_KEY = "serverAddress"
        const val FILE = "cms_secure"
    }
}
