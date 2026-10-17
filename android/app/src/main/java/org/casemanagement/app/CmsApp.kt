package org.casemanagement.app

import android.app.Application

/** Holds the one API client, so every screen shares the same session and offline entries. */
class CmsApp : Application() {
    lateinit var api: Api
        private set

    override fun onCreate() {
        super.onCreate()
        api = Api(this)
    }
}
