package org.casemanagement.app

import android.app.Application
import android.net.ConnectivityManager
import android.net.Network
import kotlinx.coroutines.launch

/** Holds the one API client, so every screen shares the same session and offline entries. */
class CmsApp : Application() {
    lateinit var api: Api
        private set

    override fun onCreate() {
        super.onCreate()
        api = Api(this)
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
