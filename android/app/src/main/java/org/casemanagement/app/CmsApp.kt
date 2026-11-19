package org.casemanagement.app

import android.app.Application
import android.net.ConnectivityManager
import android.net.Network
import kotlinx.coroutines.launch

/** Holds the one API client, so every screen shares the same session and offline entries. */
class CmsApp : Application() {
    lateinit var api: Api
        private set
    /** 4.34.0: the app lock, kept here so a new Activity (a turned phone) does not unlock it. */
    lateinit var lock: AppLock
        private set

    override fun onCreate() {
        super.onCreate()
        api = Api(this)
        // A session kept on the phone starts locked, as on the iPhone.
        lock = AppLock(api.store, startSignedIn = api.isSignedIn)
        api.onSignedIn = { lock.signedIn() }
        // The connection coming back sends what was kept without a signal: offline entries and
        // signatures taken in a client's home (4.26.0).
        try {
            getSystemService(ConnectivityManager::class.java)?.registerDefaultNetworkCallback(object : ConnectivityManager.NetworkCallback() {
                override fun onAvailable(network: Network) {
                    api.scope.launch { api.flushQueue(); api.flushSignatures() }
                }
            })
        } catch (e: SecurityException) { }
    }
}
