package ir.trade.app.data

import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import org.json.JSONObject
import java.net.HttpURLConnection
import java.net.URL
import java.net.URLEncoder

class TradeApi(private val baseUrl: String, private val apiToken: String) {
    data class Response(val code: Int, val body: String) { val ok: Boolean get() = code in 200..299 }

    suspend fun health(): Response = request("GET", "/api/health", authenticated = false)
    suspend fun updateInfo(): Response = request("GET", "/api/update", authenticated = false)
    suspend fun pair(code: String): Response = request(
        "POST", "/api/pair", JSONObject().put("code", code).toString(), authenticated = false,
    )
    suspend fun status(): Response = request("GET", "/api/status")
    suspend fun exchanges(): Response = request("GET", "/api/exchanges")
    suspend fun botStatus(): Response = request("GET", "/api/bot")
    suspend fun markets(exchange: String = "bitpin"): Response = request("GET", "/api/markets?exchange=${exchangeArg(exchange)}")
    suspend fun wallets(exchange: String = "bitpin"): Response = request("GET", "/api/wallets?exchange=${exchangeArg(exchange)}")
    suspend fun orders(exchange: String = "bitpin"): Response = request("GET", "/api/orders?exchange=${exchangeArg(exchange)}")
    suspend fun createOrder(json: String): Response = request("POST", "/api/orders", json)
    suspend fun cancelOrder(orderId: String, exchange: String = "bitpin"): Response = request("DELETE", "/api/orders/${URLEncoder.encode(orderId, Charsets.UTF_8.name())}?exchange=${exchangeArg(exchange)}")
    suspend fun setKillSwitch(enabled: Boolean): Response = request("POST", "/api/kill-switch", "{\"enabled\":$enabled}")
    suspend fun setExchangeBot(exchange: String, enabled: Boolean): Response = request("POST", "/api/exchanges/${exchangeArg(exchange)}/bot", "{\"enabled\":$enabled}")
    suspend fun setExchangeLive(exchange: String, enabled: Boolean): Response = request("POST", "/api/exchanges/${exchangeArg(exchange)}/live", "{\"enabled\":$enabled}")
    suspend fun runExchange(exchange: String): Response = request("POST", "/api/exchanges/${exchangeArg(exchange)}/run", "{}")

    private fun exchangeArg(exchange: String): String {
        val normalized = exchange.lowercase().trim()
        require(normalized == "bitpin" || normalized == "nobitex") { "Unsupported exchange" }
        return normalized
    }

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
            readTimeout = 20_000
            instanceFollowRedirects = true
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
