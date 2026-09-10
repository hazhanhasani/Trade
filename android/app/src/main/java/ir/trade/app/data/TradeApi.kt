package ir.trade.app.data

import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import org.json.JSONObject
import java.net.HttpURLConnection
import java.net.URL
import java.net.URLEncoder

class TradeApi(private val baseUrl: String, private val apiToken: String) {
    data class Response(val code: Int, val body: String) { val ok: Boolean get() = code in 200..299 }

    @Volatile private var contractLoaded = false
    @Volatile private var backendApiContract: Int? = null
    @Volatile private var backendCapabilities: Set<String> = emptySet()

    suspend fun health(): Response = request("GET", "/api/health", authenticated = false)
    suspend fun updateInfo(): Response = request("GET", "/api/update", authenticated = false)
    suspend fun pair(code: String): Response = request(
        "POST", "/api/pair", JSONObject().put("code", code).toString(), authenticated = false,
    )

    suspend fun status(): Response {
        val response = request("GET", "/api/status")
        if (response.ok) captureContract(response)
        return response
    }

    suspend fun exchanges(): Response = request("GET", "/api/exchanges")
    suspend fun botStatus(): Response = request("GET", "/api/bot")
    suspend fun rotationStatus(limit: Int = 20): Response {
        requireCapability("trading.portfolio_rotation_monitor")
        return request("GET", "/api/bot/rotation?limit=${limit.coerceIn(1, 50)}")
    }
    suspend fun markets(exchange: String = "bitpin"): Response = request("GET", "/api/markets?exchange=${exchangeArg(exchange)}")
    suspend fun wallets(exchange: String = "bitpin"): Response = request("GET", "/api/wallets?exchange=${exchangeArg(exchange)}")
    suspend fun orders(exchange: String = "bitpin"): Response = request("GET", "/api/orders?exchange=${exchangeArg(exchange)}")

    suspend fun createOrder(json: String): Response {
        requireCapability("trading.manual_orders")
        return request("POST", "/api/orders", json)
    }

    suspend fun cancelOrder(orderId: String, exchange: String = "bitpin"): Response {
        requireCapability("trading.manual_orders")
        return request("DELETE", "/api/orders/${URLEncoder.encode(orderId, Charsets.UTF_8.name())}?exchange=${exchangeArg(exchange)}")
    }

    suspend fun setKillSwitch(enabled: Boolean): Response {
        requireCapability("trading.kill_switch")
        return request("POST", "/api/kill-switch", "{\"enabled\":$enabled}")
    }

    suspend fun setExchangeBot(exchange: String, enabled: Boolean): Response {
        requireCapability("trading.auto_trading")
        return request("POST", "/api/exchanges/${exchangeArg(exchange)}/bot", "{\"enabled\":$enabled}")
    }

    suspend fun setExchangeLive(exchange: String, enabled: Boolean): Response {
        requireCapability("trading.live_execution_controls")
        return request("POST", "/api/exchanges/${exchangeArg(exchange)}/live", "{\"enabled\":$enabled}")
    }

    suspend fun runExchange(exchange: String): Response {
        requireCapability("trading.auto_trading")
        return request("POST", "/api/exchanges/${exchangeArg(exchange)}/run", "{}")
    }

    fun isContractCompatible(): Boolean =
        contractLoaded &&
            backendApiContract == ReleaseContract.API_CONTRACT &&
            ReleaseContract.missingCapabilities(backendCapabilities).isEmpty()

    fun apiContract(): Int? = backendApiContract

    fun capabilities(): Set<String> = backendCapabilities

    fun missingCapabilities(): Set<String> = ReleaseContract.missingCapabilities(backendCapabilities)

    private fun captureContract(response: Response) {
        try {
            val root = JSONObject(response.body)
            val data = root.optJSONObject("data") ?: root
            backendApiContract = data.optInt("api_contract", -1).takeIf { it >= 0 }
            val array = data.optJSONArray("capabilities")
            val capabilities = linkedSetOf<String>()
            if (array != null) {
                for (i in 0 until array.length()) {
                    val value = array.optString(i).trim()
                    if (value.isNotBlank()) capabilities += value
                }
            }
            backendCapabilities = capabilities
            contractLoaded = true
        } catch (_: Exception) {
            backendApiContract = null
            backendCapabilities = emptySet()
            contractLoaded = true
        }
    }

    private fun requireCapability(capability: String) {
        check(contractLoaded) {
            "وضعیت سازگاری Backend هنوز بررسی نشده است؛ ابتدا وضعیت برنامه را بروزرسانی کن."
        }
        check(backendApiContract == ReleaseContract.API_CONTRACT) {
            "نسخه API Backend با این نسخه اپ سازگار نیست. Backend=${backendApiContract ?: "نامشخص"} / App=${ReleaseContract.API_CONTRACT}"
        }
        check(backendCapabilities.contains(capability)) {
            "Backend قابلیت موردنیاز «$capability» را اعلام نکرده است؛ عملیات برای جلوگیری از اجرای ناسازگار متوقف شد."
        }
    }

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
            setRequestProperty("X-Trade-App-Api-Contract", ReleaseContract.API_CONTRACT.toString())
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
