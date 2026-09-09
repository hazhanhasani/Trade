package ir.trade.app.data

import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import org.json.JSONObject
import java.net.HttpURLConnection
import java.net.URL

class TradeApi(private val baseUrl: String, private val apiToken: String) {
    data class Response(val code: Int, val body: String) { val ok: Boolean get() = code in 200..299 }

    suspend fun health(): Response = request("GET", "/api/health", authenticated = false)
    suspend fun pair(code: String): Response = request(
        "POST",
        "/api/pair",
        JSONObject().put("code", code).toString(),
        authenticated = false,
    )
    suspend fun status(): Response = request("GET", "/api/status")
    suspend fun markets(): Response = request("GET", "/api/markets")
    suspend fun wallets(): Response = request("GET", "/api/wallets")
    suspend fun orders(): Response = request("GET", "/api/orders")
    suspend fun createOrder(json: String): Response = request("POST", "/api/orders", json)
    suspend fun cancelOrder(orderId: String): Response = request("DELETE", "/api/orders/${orderId}")
    suspend fun setKillSwitch(enabled: Boolean): Response = request("POST", "/api/kill-switch", "{\"enabled\":$enabled}")

    private suspend fun request(
        method: String,
        path: String,
        body: String? = null,
        authenticated: Boolean = true,
    ): Response = withContext(Dispatchers.IO) {
        require(baseUrl.startsWith("https://")) { "Only HTTPS server URLs are allowed" }
        val connection = (URL(baseUrl.trimEnd('/') + path).openConnection() as HttpURLConnection).apply {
            requestMethod = method
            connectTimeout = 8_000
            readTimeout = 15_000
            instanceFollowRedirects = false
            setRequestProperty("Accept", "application/json")
            if (authenticated) setRequestProperty("Authorization", "Bearer $apiToken")
            if (body != null) {
                doOutput = true
                setRequestProperty("Content-Type", "application/json")
            }
        }
        try {
            if (body != null) connection.outputStream.use { it.write(body.toByteArray(Charsets.UTF_8)) }
            val code = connection.responseCode
            val stream = if (code in 200..299) connection.inputStream else connection.errorStream
            Response(code, stream?.bufferedReader()?.use { it.readText() }.orEmpty())
        } finally {
            connection.disconnect()
        }
    }
}
