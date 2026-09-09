package ir.trade.app.data

import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import java.net.HttpURLConnection
import java.net.URL

class TradeApi(private val baseUrl: String, private val apiToken: String) {
    data class Response(val code: Int, val body: String) { val ok: Boolean get() = code in 200..299 }

    suspend fun health(): Response = request("/api/health", false)
    suspend fun status(): Response = request("/api/status")
    suspend fun wallets(): Response = request("/api/wallets")
    suspend fun orders(): Response = request("/api/orders")

    private suspend fun request(path: String, authenticated: Boolean = true): Response = withContext(Dispatchers.IO) {
        require(baseUrl.startsWith("https://")) { "Only HTTPS server URLs are allowed" }
        val connection = (URL(baseUrl.trimEnd('/') + path).openConnection() as HttpURLConnection).apply {
            requestMethod = "GET"
            connectTimeout = 8_000
            readTimeout = 15_000
            instanceFollowRedirects = false
            setRequestProperty("Accept", "application/json")
            if (authenticated) setRequestProperty("Authorization", "Bearer $apiToken")
        }
        try {
            val code = connection.responseCode
            val stream = if (code in 200..299) connection.inputStream else connection.errorStream
            Response(code, stream?.bufferedReader()?.use { it.readText() }.orEmpty())
        } finally { connection.disconnect() }
    }
}
