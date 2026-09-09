package ir.trade.app.data

import android.content.Context

class TradePreferences(context: Context) {
    private val prefs = context.getSharedPreferences("trade_server", Context.MODE_PRIVATE)
    private val defaultServer = "https://rado-taxi.sbs"

    fun serverUrl(): String = prefs.getString("server_url", defaultServer) ?: defaultServer
    fun apiToken(): String = prefs.getString("api_token", "") ?: ""
    fun isConfigured(): Boolean = serverUrl().startsWith("https://") && apiToken().isNotBlank()

    fun save(serverUrl: String, apiToken: String) {
        require(serverUrl.startsWith("https://")) { "HTTPS is required" }
        prefs.edit()
            .putString("server_url", serverUrl.trimEnd('/'))
            .putString("api_token", apiToken.trim())
            .apply()
    }

    fun clear() {
        prefs.edit().remove("api_token").putString("server_url", defaultServer).apply()
    }
}
