package ir.trade.app.ui

import android.content.Intent
import android.net.Uri
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.AccountBalanceWallet
import androidx.compose.material.icons.rounded.AutoGraph
import androidx.compose.material.icons.rounded.CloudDone
import androidx.compose.material.icons.rounded.Dashboard
import androidx.compose.material.icons.rounded.Info
import androidx.compose.material.icons.rounded.Logout
import androidx.compose.material.icons.rounded.PlayArrow
import androidx.compose.material.icons.rounded.PowerSettingsNew
import androidx.compose.material.icons.rounded.Refresh
import androidx.compose.material.icons.rounded.Security
import androidx.compose.material.icons.rounded.Settings
import androidx.compose.material.icons.rounded.SmartToy
import androidx.compose.material.icons.rounded.SwapHoriz
import androidx.compose.material.icons.rounded.Update
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.FilledTonalButton
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.LinearProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.NavigationBar
import androidx.compose.material3.NavigationBarItem
import androidx.compose.material3.NavigationBarItemDefaults
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Surface
import androidx.compose.material3.Switch
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.lightColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import ir.trade.app.BuildConfig
import ir.trade.app.data.TradeApi
import ir.trade.app.data.TradePreferences
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import org.json.JSONArray
import org.json.JSONObject
import java.text.NumberFormat
import java.util.Locale
import kotlin.math.abs

private val V3Bg = Color(0xFFF3F5F9)
private val V3Ink = Color(0xFF141725)
private val V3Muted = Color(0xFF72798B)
private val V3Primary = Color(0xFF6246EA)
private val V3Primary2 = Color(0xFF8068F5)
private val V3Success = Color(0xFF0B966D)
private val V3Danger = Color(0xFFD04444)
private val V3Warning = Color(0xFFAD6908)
private val V3Blue = Color(0xFF2563EB)
private val V3Stroke = Color(0xFFE6E9F0)
private val V3Soft = Color(0xFFF1EFFF)
private val V3SuccessSoft = Color(0xFFE8F7F1)
private val V3DangerSoft = Color(0xFFFFF0F0)
private val V3WarningSoft = Color(0xFFFFF6E7)

private data class V3Position(
    val symbol: String,
    val quote: String,
    val amount: Double,
    val entry: Double,
    val status: String,
)

private data class V3Exchange(
    val credentials: Boolean = false,
    val bot: Boolean = false,
    val live: Boolean = false,
    val active: Int = 0,
    val max: Int = 0,
    val remaining: Int = 0,
    val pending: Int = 0,
    val maxPending: Int = 0,
    val effectiveEntry: Double = 0.0,
    val exposureLimit: Double = 0.0,
    val pnlToday: Double = 0.0,
    val pnlTotal: Double = 0.0,
    val winRate: Double = 0.0,
    val quote: String = "IRT",
    val decision: String = "هنوز تصمیمی ثبت نشده",
    val signal: String = "هنوز سیگنالی ثبت نشده",
    val positions: List<V3Position> = emptyList(),
)

private data class V3Wallet(val code: String, val balance: Double, val value: Double)

private data class V3Global(
    val status: String = "unknown",
    val portfolioIrt: Double = 0.0,
    val exposureIrt: Double = 0.0,
    val exposurePercent: Double = 0.0,
    val usdtToIrt: Double? = null,
)

private data class V3Analytics(
    val todayIrt: Double = 0.0,
    val weekIrt: Double = 0.0,
    val monthIrt: Double = 0.0,
    val winRateIrt: Double = 0.0,
    val profitFactorIrt: Double = 0.0,
    val todayUsdt: Double = 0.0,
    val weekUsdt: Double = 0.0,
    val monthUsdt: Double = 0.0,
    val edgeSamples: Int = 0,
    val expectedEdge: Double? = null,
    val realizedReturn: Double? = null,
    val hitRate: Double? = null,
    val bestAsset: String = "—",
    val worstAsset: String = "—",
    val dailyIrt: List<Pair<String, Double>> = emptyList(),
)

private data class V3Notification(
    val id: Long,
    val priority: String,
    val title: String,
    val body: String,
    val createdAt: String,
    val unread: Boolean,
)

private data class V3Nav(val title: String, val icon: ImageVector)

@Composable
fun TradeAppV3() {
    val context = LocalContext.current
    val prefs = remember { TradePreferences(context) }
    var configured by remember { mutableStateOf(prefs.isConfigured()) }

    MaterialTheme(
        colorScheme = lightColorScheme(
            primary = V3Primary,
            secondary = V3Success,
            background = V3Bg,
            surface = Color.White,
            onBackground = V3Ink,
            onSurface = V3Ink,
            error = V3Danger,
        ),
    ) {
        Surface(Modifier.fillMaxSize(), color = V3Bg) {
            if (configured) {
                V3Shell(prefs) {
                    prefs.clear()
                    configured = false
                }
            } else {
                TradeAppV2()
            }
        }
    }
}

@Composable
private fun V3Shell(prefs: TradePreferences, onDisconnect: () -> Unit) {
    val scope = rememberCoroutineScope()
    val context = LocalContext.current
    val api = remember(prefs.serverUrl(), prefs.apiToken()) {
        TradeApi(prefs.serverUrl(), prefs.apiToken())
    }
    val updateState = LocalUpdateUiState.current
    val requestUpdate = LocalRequestUpdateCheck.current

    var tab by rememberSaveable { mutableIntStateOf(0) }
    var exchange by rememberSaveable { mutableStateOf("nobitex") }
    var morePage by rememberSaveable { mutableStateOf("root") }
    var nobitex by remember { mutableStateOf(V3Exchange()) }
    var bitpin by remember { mutableStateOf(V3Exchange()) }
    var kill by remember { mutableStateOf(false) }
    var cronHealthy by remember { mutableStateOf(false) }
    var cronAge by remember { mutableStateOf<Long?>(null) }
    var backendVersion by remember { mutableStateOf("-") }
    var compatible by remember { mutableStateOf(false) }
    var global by remember { mutableStateOf(V3Global()) }
    var analytics by remember { mutableStateOf(V3Analytics()) }
    var notifications by remember { mutableStateOf<List<V3Notification>>(emptyList()) }
    var unread by remember { mutableIntStateOf(0) }
    var wallets by remember { mutableStateOf<List<V3Wallet>>(emptyList()) }
    var loading by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf("") }
    var message by remember { mutableStateOf("") }
    var rotationText by remember { mutableStateOf("Rotation هنوز بررسی نشده") }

    var symbol by rememberSaveable { mutableStateOf("BTCIRT") }
    var amount by rememberSaveable { mutableStateOf("") }
    var price by rememberSaveable { mutableStateOf("") }
    var side by rememberSaveable { mutableStateOf("buy") }
    var mode by rememberSaveable { mutableStateOf("market") }
    var cancelId by rememberSaveable { mutableStateOf("") }

    val current = if (exchange == "nobitex") nobitex else bitpin
    val exchangeTitle = if (exchange == "nobitex") "Nobitex" else "Bitpin"

    suspend fun refreshExtended() {
        try {
            val g = api.globalPortfolio()
            if (g.ok) global = parseV3Global(JSONObject(g.body))

            val a = api.analytics(30)
            if (a.ok) analytics = parseV3Analytics(JSONObject(a.body))

            val n = api.notifications(60, false)
            if (n.ok) {
                val parsed = parseV3Notifications(JSONObject(n.body))
                unread = parsed.first
                notifications = parsed.second
            }

            val r = api.rotationStatus(10)
            if (r.ok) {
                val data = JSONObject(r.body).optJSONObject("data") ?: JSONObject()
                rotationText = v3Rotation(data)
            }
        } catch (_: Exception) {
            // Core status remains available when an optional analytics panel fails.
        }
    }

    fun refreshWallet() {
        scope.launch {
            try {
                val response = api.wallets(exchange)
                if (!response.ok) throw IllegalStateException(v3HttpError(response))
                wallets = parseV3Wallets(response.body)
            } catch (e: Exception) {
                error = e.message ?: "دریافت کیف پول ناموفق بود"
            }
        }
    }

    fun refreshAll() {
        scope.launch {
            loading = true
            error = ""
            try {
                val response = api.status()
                if (!response.ok) throw IllegalStateException(v3HttpError(response))
                val data = JSONObject(response.body).optJSONObject("data") ?: JSONObject(response.body)
                backendVersion = data.optString("backend_version", "-")
                kill = data.optBoolean("kill_switch")
                val health = data.optJSONObject("cron_health")
                cronHealthy = health?.optBoolean("healthy") == true
                cronAge = health?.optLong("age_seconds", -1L)?.takeIf { it >= 0 }
                nobitex = parseV3Exchange(data, "nobitex")
                bitpin = parseV3Exchange(data, "bitpin")
                compatible = api.isContractCompatible()

                refreshExtended()

                val walletResponse = api.wallets(exchange)
                if (walletResponse.ok) wallets = parseV3Wallets(walletResponse.body)
            } catch (e: Exception) {
                error = e.message ?: "دریافت وضعیت Backend ناموفق بود"
            } finally {
                loading = false
            }
        }
    }

    fun selectExchange(value: String) {
        exchange = value
        symbol = if (value == "nobitex") "BTCIRT" else "BTC_IRT"
        wallets = emptyList()
        refreshWallet()
    }

    fun setBot(enabled: Boolean) {
        scope.launch {
            loading = true
            try {
                val response = api.setExchangeBot(exchange, enabled)
                if (!response.ok) throw IllegalStateException(v3HttpError(response))
                message = "ربات $exchangeTitle ${if (enabled) "فعال" else "غیرفعال"} شد."
                refreshAll()
            } catch (e: Exception) {
                error = e.message ?: "تغییر Bot ناموفق بود"
            } finally {
                loading = false
            }
        }
    }

    fun setLive(enabled: Boolean) {
        scope.launch {
            loading = true
            try {
                val response = api.setExchangeLive(exchange, enabled)
                if (!response.ok) throw IllegalStateException(v3HttpError(response))
                message = "Live $exchangeTitle ${if (enabled) "فعال" else "غیرفعال"} شد."
                refreshAll()
            } catch (e: Exception) {
                error = e.message ?: "تغییر Live ناموفق بود"
            } finally {
                loading = false
            }
        }
    }

    fun setKill(enabled: Boolean) {
        scope.launch {
            loading = true
            try {
                val response = api.setKillSwitch(enabled)
                if (!response.ok) throw IllegalStateException(v3HttpError(response))
                message = if (enabled) "توقف اضطراری فعال شد." else "توقف اضطراری برداشته شد."
                refreshAll()
            } catch (e: Exception) {
                error = e.message ?: "تغییر Kill Switch ناموفق بود"
            } finally {
                loading = false
            }
        }
    }

    fun runNow() {
        scope.launch {
            loading = true
            try {
                val response = api.runExchange(exchange)
                if (!response.ok) throw IllegalStateException(v3HttpError(response))
                message = "چرخه $exchangeTitle اجرا شد."
                refreshAll()
            } catch (e: Exception) {
                error = e.message ?: "اجرای چرخه ناموفق بود"
            } finally {
                loading = false
            }
        }
    }

    fun submitOrder() {
        val parsedAmount = amount.toDoubleOrNull()
        val parsedPrice = price.toDoubleOrNull()
        if (symbol.isBlank() || parsedAmount == null || parsedAmount <= 0 ||
            (mode != "market" && (parsedPrice == null || parsedPrice <= 0))
        ) {
            error = "نماد، مقدار و قیمت سفارش را بررسی کن."
            return
        }

        scope.launch {
            loading = true
            try {
                val payload = JSONObject()
                    .put("exchange", exchange)
                    .put("symbol", symbol.trim().uppercase())
                    .put("amount1", parsedAmount)
                    .put("mode", mode)
                    .put("type", side)
                if (parsedPrice != null && parsedPrice > 0) payload.put("price", parsedPrice)
                val response = api.createOrder(payload.toString())
                if (!response.ok) throw IllegalStateException(v3HttpError(response))
                message = "سفارش واقعی ارسال شد."
                refreshAll()
            } catch (e: Exception) {
                error = e.message ?: "ثبت سفارش ناموفق بود"
            } finally {
                loading = false
            }
        }
    }

    fun cancelOrder() {
        if (cancelId.isBlank()) {
            error = "شناسه سفارش را وارد کن."
            return
        }
        scope.launch {
            loading = true
            try {
                val response = api.cancelOrder(cancelId.trim(), exchange)
                if (!response.ok) throw IllegalStateException(v3HttpError(response))
                cancelId = ""
                message = "درخواست لغو ارسال شد."
                refreshAll()
            } catch (e: Exception) {
                error = e.message ?: "لغو سفارش ناموفق بود"
            } finally {
                loading = false
            }
        }
    }

    fun markRead(id: Long? = null, all: Boolean = false) {
        scope.launch {
            try {
                api.markNotificationRead(id, all)
                val response = api.notifications(60, false)
                if (response.ok) {
                    val parsed = parseV3Notifications(JSONObject(response.body))
                    unread = parsed.first
                    notifications = parsed.second
                }
            } catch (e: Exception) {
                error = e.message ?: "بروزرسانی اعلان ناموفق بود"
            }
        }
    }

    LaunchedEffect(Unit) { refreshAll() }
    LaunchedEffect(Unit) {
        while (true) {
            delay(60_000)
            try {
                val response = api.notifications(60, false)
                if (response.ok) {
                    val parsed = parseV3Notifications(JSONObject(response.body))
                    unread = parsed.first
                    notifications = parsed.second
                }
            } catch (_: Exception) {
            }
        }
    }

    val nav = listOf(
        V3Nav("داشبورد", Icons.Rounded.Dashboard),
        V3Nav("پرتفو", Icons.Rounded.AccountBalanceWallet),
        V3Nav("معامله", Icons.Rounded.SwapHoriz),
        V3Nav("اتوماسیون", Icons.Rounded.SmartToy),
        V3Nav("بیشتر", Icons.Rounded.Settings),
    )

    Scaffold(
        containerColor = V3Bg,
        bottomBar = {
            NavigationBar(containerColor = Color.White, tonalElevation = 8.dp) {
                nav.forEachIndexed { index, item ->
                    NavigationBarItem(
                        selected = tab == index,
                        onClick = {
                            tab = index
                            if (index != 4) morePage = "root"
                        },
                        icon = { Icon(item.icon, item.title) },
                        label = { Text(item.title, maxLines = 1) },
                        colors = NavigationBarItemDefaults.colors(
                            selectedIconColor = V3Primary,
                            selectedTextColor = V3Primary,
                            indicatorColor = V3Soft,
                            unselectedIconColor = Color(0xFF9CA3AF),
                            unselectedTextColor = V3Muted,
                        ),
                    )
                }
            }
        },
    ) { padding ->
        Column(
            Modifier
                .fillMaxSize()
                .padding(padding)
                .statusBarsPadding()
                .navigationBarsPadding(),
        ) {
            V3TopBar(
                exchange = exchange,
                state = current,
                cronHealthy = cronHealthy,
                unread = unread,
                loading = loading,
                onExchange = ::selectExchange,
                onRefresh = ::refreshAll,
            )

            if (error.isNotBlank()) {
                Box(Modifier.padding(horizontal = 14.dp, vertical = 4.dp)) {
                    V3Banner("خطا", error, V3Danger, V3DangerSoft)
                }
            }
            if (message.isNotBlank()) {
                Box(Modifier.padding(horizontal = 14.dp, vertical = 4.dp)) {
                    V3Banner("انجام شد", message, V3Success, V3SuccessSoft)
                }
            }

            Box(Modifier.weight(1f)) {
                when (tab) {
                    0 -> V3Dashboard(current, global, analytics, cronHealthy, cronAge, kill, compatible)
                    1 -> V3Portfolio(exchangeTitle, current, wallets, global, loading, ::refreshWallet)
                    2 -> V3Trade(
                        name = exchangeTitle,
                        state = current,
                        kill = kill,
                        compatible = compatible,
                        loading = loading,
                        symbol = symbol,
                        amount = amount,
                        price = price,
                        side = side,
                        mode = mode,
                        cancelId = cancelId,
                        onSymbol = { symbol = it },
                        onAmount = { amount = it },
                        onPrice = { price = it },
                        onSide = { side = it },
                        onMode = { mode = it },
                        onCancelId = { cancelId = it },
                        onSubmit = ::submitOrder,
                        onCancel = ::cancelOrder,
                    )
                    3 -> V3Automation(
                        name = exchangeTitle,
                        state = current,
                        kill = kill,
                        compatible = compatible,
                        loading = loading,
                        rotationText = rotationText,
                        onBot = ::setBot,
                        onLive = ::setLive,
                        onRun = ::runNow,
                        onRotation = {
                            context.startActivity(Intent(Intent.ACTION_VIEW, Uri.parse("trade://rotation")))
                        },
                    )
                    else -> when (morePage) {
                        "analytics" -> V3AnalyticsScreen(analytics) { morePage = "root" }
                        "notifications" -> V3NotificationsScreen(
                            notifications = notifications,
                            unread = unread,
                            onBack = { morePage = "root" },
                            onRead = { markRead(it, false) },
                            onReadAll = { markRead(null, true) },
                        )
                        else -> V3More(
                            kill = kill,
                            cronHealthy = cronHealthy,
                            cronAge = cronAge,
                            backendVersion = backendVersion,
                            compatible = compatible,
                            unread = unread,
                            updateState = updateState,
                            onAnalytics = { morePage = "analytics" },
                            onNotifications = { morePage = "notifications" },
                            onKill = ::setKill,
                            onUpdate = requestUpdate,
                            onDisconnect = onDisconnect,
                        )
                    }
                }
            }
        }
    }
}

@Composable
private fun V3TopBar(
    exchange: String,
    state: V3Exchange,
    cronHealthy: Boolean,
    unread: Int,
    loading: Boolean,
    onExchange: (String) -> Unit,
    onRefresh: () -> Unit,
) {
    Column(
        Modifier
            .fillMaxWidth()
            .background(Color.White)
            .padding(horizontal = 15.dp, vertical = 9.dp),
        verticalArrangement = Arrangement.spacedBy(8.dp),
    ) {
        Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
            Box(
                Modifier
                    .size(42.dp)
                    .clip(RoundedCornerShape(14.dp))
                    .background(Brush.linearGradient(listOf(V3Primary, V3Primary2))),
                contentAlignment = Alignment.Center,
            ) {
                Text("T", color = Color.White, fontWeight = FontWeight.Black)
            }
            Spacer(Modifier.width(10.dp))
            Column(Modifier.weight(1f)) {
                Text("Trade Cockpit", fontWeight = FontWeight.Black, style = MaterialTheme.typography.titleLarge)
                Text(
                    if (state.bot && state.live) "موتور $exchange فعال" else "کنترل معاملات",
                    color = V3Muted,
                    style = MaterialTheme.typography.labelMedium,
                )
            }
            if (unread > 0) V3Pill("$unread اعلان", V3Warning, V3WarningSoft)
            Spacer(Modifier.width(5.dp))
            V3Pill(
                if (cronHealthy) "LIVE" else "CHECK",
                if (cronHealthy) V3Success else V3Warning,
                if (cronHealthy) V3SuccessSoft else V3WarningSoft,
            )
            IconButton(onClick = onRefresh, enabled = !loading) {
                Icon(Icons.Rounded.Refresh, null, tint = V3Primary)
            }
        }
        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            V3Segment("Nobitex", exchange == "nobitex", Modifier.weight(1f)) { onExchange("nobitex") }
            V3Segment("Bitpin", exchange == "bitpin", Modifier.weight(1f)) { onExchange("bitpin") }
        }
    }
}

@Composable
private fun V3Dashboard(
    current: V3Exchange,
    global: V3Global,
    analytics: V3Analytics,
    cronHealthy: Boolean,
    cronAge: Long?,
    kill: Boolean,
    compatible: Boolean,
) {
    LazyColumn(
        Modifier.fillMaxSize(),
        contentPadding = PaddingValues(16.dp),
        verticalArrangement = Arrangement.spacedBy(12.dp),
    ) {
        item {
            Text("داشبورد", style = MaterialTheme.typography.headlineSmall, fontWeight = FontWeight.Black)
            Text("فقط آمار و وضعیت؛ بدون تنظیم یا کنترل عملیاتی", color = V3Muted)
        }
        item {
            Card(shape = RoundedCornerShape(28.dp), colors = CardDefaults.cardColors(containerColor = Color.Transparent)) {
                Box(
                    Modifier
                        .fillMaxWidth()
                        .background(Brush.linearGradient(listOf(Color(0xFF111827), Color(0xFF30286B), V3Primary)))
                        .padding(20.dp),
                ) {
                    Column {
                        Text("ارزش پورتفوی یکپارچه", color = Color(0xFFC7CBD6))
                        Text(
                            if (global.status == "ok") "${v3Number(global.portfolioIrt)} IRT" else "—",
                            color = Color.White,
                            fontWeight = FontWeight.Black,
                            style = MaterialTheme.typography.headlineMedium,
                        )
                        Spacer(Modifier.height(12.dp))
                        Row(horizontalArrangement = Arrangement.spacedBy(7.dp)) {
                            V3DarkPill("Exposure ${v3One(global.exposurePercent)}%")
                            V3DarkPill("${current.active}${if (current.max > 0) "/${current.max}" else ""} Position")
                            V3DarkPill(if (kill) "STOP" else "RUN")
                        }
                    }
                }
            }
        }
        item {
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                V3Stat("PnL امروز", v3Compact(current.pnlToday), current.pnlToday >= 0, Modifier.weight(1f))
                V3Stat("Win Rate", "${v3One(current.winRate)}%", current.winRate >= 50, Modifier.weight(1f))
                V3Stat("Pending", "${current.pending}/${current.maxPending}", current.pending == 0, Modifier.weight(1f))
            }
        }
        item {
            V3Panel {
                Text("ریسک و ظرفیت", fontWeight = FontWeight.Black)
                Text("Global Portfolio Exposure", color = V3Muted, style = MaterialTheme.typography.bodySmall)
                V3Progress((global.exposurePercent / 100.0).toFloat().coerceIn(0f, 1f))
                Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
                    Text("${v3One(global.exposurePercent)}% درگیر", fontWeight = FontWeight.Bold)
                    Text("سقف ${v3One(current.exposureLimit)}%", color = V3Muted)
                }
            }
        }
        item {
            V3Panel {
                Text("عملکرد ۳۰ روزه", fontWeight = FontWeight.Black)
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    V3Metric("هفته IRT", v3Compact(analytics.weekIrt), Modifier.weight(1f))
                    V3Metric("ماه IRT", v3Compact(analytics.monthIrt), Modifier.weight(1f))
                    V3Metric("Profit Factor", v3One(analytics.profitFactorIrt), Modifier.weight(1f))
                }
            }
        }
        item {
            V3Panel {
                Text("وضعیت سیستم", fontWeight = FontWeight.Black)
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    V3Metric("Cron", if (cronHealthy) "سالم" else "بررسی", Modifier.weight(1f))
                    V3Metric("آخرین اجرا", cronAge?.let { "${it}s" } ?: "—", Modifier.weight(1f))
                    V3Metric("Contract", if (compatible) "هماهنگ" else "ناسازگار", Modifier.weight(1f))
                }
            }
        }
        item {
            V3Panel {
                Text("آخرین تصمیم ربات", fontWeight = FontWeight.Black)
                Text(current.decision, color = V3Muted, style = MaterialTheme.typography.bodySmall)
                HorizontalDivider(color = V3Stroke)
                Text(current.signal, color = V3Muted, style = MaterialTheme.typography.bodySmall)
            }
        }
    }
}

@Composable
private fun V3Portfolio(
    name: String,
    current: V3Exchange,
    wallets: List<V3Wallet>,
    global: V3Global,
    loading: Boolean,
    onRefresh: () -> Unit,
) {
    LazyColumn(
        Modifier.fillMaxSize(),
        contentPadding = PaddingValues(16.dp),
        verticalArrangement = Arrangement.spacedBy(10.dp),
    ) {
        item {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Column(Modifier.weight(1f)) {
                    Text("پرتفوی $name", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Black)
                    Text("دارایی، پوزیشن و Exposure", color = V3Muted)
                }
                IconButton(onClick = onRefresh, enabled = !loading) { Icon(Icons.Rounded.Refresh, null) }
            }
        }
        if (name == "Nobitex") {
            item {
                V3Panel {
                    Text("Global Exposure", fontWeight = FontWeight.Black)
                    Text("IRT + USDT → IRT", color = V3Muted, style = MaterialTheme.typography.bodySmall)
                    Text("${v3One(global.exposurePercent)}% • ${v3Number(global.exposureIrt)} IRT درگیر", fontWeight = FontWeight.Black)
                    V3Progress((global.exposurePercent / 100.0).toFloat().coerceIn(0f, 1f))
                    Text(
                        global.usdtToIrt?.let { "USDT/IRT ${v3Number(it)}" } ?: "نرخ تبدیل در این لحظه موجود نیست",
                        color = V3Muted,
                        style = MaterialTheme.typography.bodySmall,
                    )
                }
            }
        }
        item {
            V3Panel {
                Text("پوزیشن‌های فعال • ${current.active}${if (current.max > 0) "/${current.max}" else ""}", fontWeight = FontWeight.Black)
                if (current.positions.isEmpty()) {
                    Text("پوزیشن فعالی نیست.", color = V3Muted)
                } else {
                    current.positions.forEach { position ->
                        Row(Modifier.fillMaxWidth().padding(vertical = 5.dp), verticalAlignment = Alignment.CenterVertically) {
                            Column(Modifier.weight(1f)) {
                                Text(position.symbol, fontWeight = FontWeight.Bold)
                                Text(
                                    "${v3Compact(position.amount)} • ورود ${v3Compact(position.entry)}",
                                    color = V3Muted,
                                    style = MaterialTheme.typography.bodySmall,
                                )
                            }
                            V3Pill(position.status, V3Blue, Color(0xFFEDF4FF))
                        }
                    }
                }
            }
        }
        item { Text("کیف پول", fontWeight = FontWeight.Black, style = MaterialTheme.typography.titleMedium) }
        if (wallets.isEmpty()) {
            item { Text(if (loading) "در حال دریافت…" else "دارایی قابل نمایش پیدا نشد.", color = V3Muted) }
        } else {
            items(wallets, key = { it.code }) { wallet ->
                Card(shape = RoundedCornerShape(18.dp), colors = CardDefaults.cardColors(containerColor = Color.White)) {
                    Row(Modifier.fillMaxWidth().padding(14.dp), verticalAlignment = Alignment.CenterVertically) {
                        Box(
                            Modifier.size(42.dp).clip(RoundedCornerShape(13.dp)).background(V3Soft),
                            contentAlignment = Alignment.Center,
                        ) {
                            Text(wallet.code.take(3), color = V3Primary, fontWeight = FontWeight.Black)
                        }
                        Spacer(Modifier.width(10.dp))
                        Column(Modifier.weight(1f)) {
                            Text(wallet.code, fontWeight = FontWeight.Bold)
                            Text(v3Compact(wallet.balance), color = V3Muted)
                        }
                        Text(if (wallet.value > 0) v3Number(wallet.value) else "—", fontWeight = FontWeight.Bold)
                    }
                }
            }
        }
    }
}

@Composable
private fun V3Trade(
    name: String,
    state: V3Exchange,
    kill: Boolean,
    compatible: Boolean,
    loading: Boolean,
    symbol: String,
    amount: String,
    price: String,
    side: String,
    mode: String,
    cancelId: String,
    onSymbol: (String) -> Unit,
    onAmount: (String) -> Unit,
    onPrice: (String) -> Unit,
    onSide: (String) -> Unit,
    onMode: (String) -> Unit,
    onCancelId: (String) -> Unit,
    onSubmit: () -> Unit,
    onCancel: () -> Unit,
) {
    val enabled = compatible && state.credentials && state.live && !kill && !loading
    LazyColumn(
        Modifier.fillMaxSize(),
        contentPadding = PaddingValues(16.dp),
        verticalArrangement = Arrangement.spacedBy(11.dp),
    ) {
        item {
            Text("معامله", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Black)
            Text("سفارش دستی واقعی روی $name", color = V3Muted)
        }
        item {
            val reason = when {
                kill -> "Kill Switch فعال است."
                !compatible -> "App و Backend هماهنگ نیستند."
                !state.credentials -> "API صرافی تنظیم نشده."
                !state.live -> "Live Execution خاموش است."
                else -> "آماده ارسال سفارش."
            }
            V3Banner(
                if (enabled) "Execution آماده است" else "Execution قفل است",
                reason,
                if (enabled) V3Success else V3Warning,
                if (enabled) V3SuccessSoft else V3WarningSoft,
            )
        }
        item {
            V3Panel {
                OutlinedTextField(symbol, onSymbol, label = { Text("نماد بازار") }, singleLine = true, modifier = Modifier.fillMaxWidth())
                OutlinedTextField(amount, onAmount, label = { Text("مقدار") }, singleLine = true, modifier = Modifier.fillMaxWidth())
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    V3Choice("خرید", side == "buy", V3Success, Modifier.weight(1f)) { onSide("buy") }
                    V3Choice("فروش", side == "sell", V3Danger, Modifier.weight(1f)) { onSide("sell") }
                }
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    V3Choice("Market", mode == "market", V3Primary, Modifier.weight(1f)) { onMode("market") }
                    V3Choice("Limit", mode == "limit", V3Blue, Modifier.weight(1f)) { onMode("limit") }
                }
                if (mode != "market") {
                    OutlinedTextField(price, onPrice, label = { Text("قیمت") }, singleLine = true, modifier = Modifier.fillMaxWidth())
                }
                Button(onClick = onSubmit, enabled = enabled, modifier = Modifier.fillMaxWidth().height(52.dp)) {
                    Text("ارسال سفارش واقعی", fontWeight = FontWeight.Bold)
                }
            }
        }
        item {
            V3Panel {
                Text("لغو سفارش", fontWeight = FontWeight.Black)
                OutlinedTextField(
                    cancelId,
                    onCancelId,
                    label = { Text("Order ID / Client Order ID") },
                    singleLine = true,
                    modifier = Modifier.fillMaxWidth(),
                )
                OutlinedButton(
                    onClick = onCancel,
                    enabled = state.credentials && cancelId.isNotBlank() && !loading,
                    modifier = Modifier.fillMaxWidth(),
                ) { Text("لغو سفارش") }
            }
        }
    }
}

@Composable
private fun V3Automation(
    name: String,
    state: V3Exchange,
    kill: Boolean,
    compatible: Boolean,
    loading: Boolean,
    rotationText: String,
    onBot: (Boolean) -> Unit,
    onLive: (Boolean) -> Unit,
    onRun: () -> Unit,
    onRotation: () -> Unit,
) {
    LazyColumn(
        Modifier.fillMaxSize(),
        contentPadding = PaddingValues(16.dp),
        verticalArrangement = Arrangement.spacedBy(11.dp),
    ) {
        item {
            Text("اتوماسیون", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Black)
            Text("Bot، Live، Intelligence و Rotation", color = V3Muted)
        }
        item { V3Toggle("Auto Trading $name", "اجرای خودکار در Cron", state.bot, Icons.Rounded.SmartToy, V3Primary, state.credentials && !loading, onBot) }
        item { V3Toggle("Live Execution $name", "اجازه ارسال سفارش واقعی", state.live, Icons.Rounded.Security, V3Success, state.credentials && !loading, onLive) }
        item {
            V3Panel {
                Text("Portfolio Intelligence", fontWeight = FontWeight.Black)
                Text("Drawdown + Correlation + Strategy Learning", color = V3Muted, style = MaterialTheme.typography.bodySmall)
                Text(
                    "Entry ${v3One(state.effectiveEntry)}% • Global cap ${v3One(state.exposureLimit)}% • Pending ${state.pending}/${state.maxPending}",
                    color = V3Muted,
                )
            }
        }
        item {
            V3Panel {
                Text("Portfolio Rotation", fontWeight = FontWeight.Black)
                Text(rotationText, color = V3Muted, style = MaterialTheme.typography.bodySmall)
                OutlinedButton(onClick = onRotation, modifier = Modifier.fillMaxWidth()) { Text("جزئیات Rotation") }
            }
        }
        item {
            Button(
                onClick = onRun,
                enabled = compatible && !loading && state.credentials && state.bot && state.live && !kill,
                modifier = Modifier.fillMaxWidth().height(52.dp),
            ) {
                Icon(Icons.Rounded.PlayArrow, null)
                Spacer(Modifier.width(7.dp))
                Text("اجرای یک چرخه", fontWeight = FontWeight.Bold)
            }
        }
        if (kill) {
            item { V3Banner("توقف اضطراری فعال است", "برای تغییر Kill Switch به بخش «بیشتر» برو.", V3Danger, V3DangerSoft) }
        }
    }
}

@Composable
private fun V3More(
    kill: Boolean,
    cronHealthy: Boolean,
    cronAge: Long?,
    backendVersion: String,
    compatible: Boolean,
    unread: Int,
    updateState: UpdateUiState,
    onAnalytics: () -> Unit,
    onNotifications: () -> Unit,
    onKill: (Boolean) -> Unit,
    onUpdate: () -> Unit,
    onDisconnect: () -> Unit,
) {
    LazyColumn(
        Modifier.fillMaxSize(),
        contentPadding = PaddingValues(16.dp),
        verticalArrangement = Arrangement.spacedBy(11.dp),
    ) {
        item {
            Text("بیشتر", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Black)
            Text("سیستم، تحلیل عملکرد، اعلان‌ها و Release", color = V3Muted)
        }
        item {
            Row(horizontalArrangement = Arrangement.spacedBy(9.dp)) {
                V3ActionCard("تحلیل عملکرد", "PnL • Edge • Strategy", Icons.Rounded.AutoGraph, V3Blue, Modifier.weight(1f), onAnalytics)
                V3ActionCard("اعلان‌ها", "$unread خوانده‌نشده", Icons.Rounded.Info, V3Warning, Modifier.weight(1f), onNotifications)
            }
        }
        item { V3Toggle("Kill Switch سراسری", "توقف سفارش‌های جدید", kill, Icons.Rounded.PowerSettingsNew, V3Danger, true, onKill) }
        item {
            V3Panel {
                Text("System Health", fontWeight = FontWeight.Black)
                V3Row("Cron", if (cronHealthy) "سالم" else "نیازمند بررسی")
                V3Row("آخرین اجرا", cronAge?.let { "${it}s قبل" } ?: "—")
                V3Row("Backend", "v$backendVersion")
                V3Row("App", "v${BuildConfig.VERSION_NAME}")
                V3Row("API Contract", if (compatible) "هماهنگ" else "ناسازگار")
            }
        }
        item {
            V3Banner(
                if (updateState.versionsSynchronized) "Release هماهنگ است" else "وضعیت Release",
                updateState.lastResult,
                if (updateState.versionsSynchronized) V3Success else V3Primary,
                if (updateState.versionsSynchronized) V3SuccessSoft else V3Soft,
            )
        }
        item {
            Button(onClick = onUpdate, enabled = !updateState.checking, modifier = Modifier.fillMaxWidth().height(50.dp)) {
                if (updateState.checking) CircularProgressIndicator(Modifier.size(18.dp), strokeWidth = 2.dp)
                else Icon(Icons.Rounded.Update, null)
                Spacer(Modifier.width(7.dp))
                Text(if (updateState.checking) "در حال بررسی…" else "بررسی نسخه جدید")
            }
        }
        item {
            OutlinedButton(onClick = onDisconnect, modifier = Modifier.fillMaxWidth()) {
                Icon(Icons.Rounded.Logout, null)
                Spacer(Modifier.width(7.dp))
                Text("قطع اتصال این گوشی")
            }
        }
    }
}

@Composable
private fun V3AnalyticsScreen(analytics: V3Analytics, onBack: () -> Unit) {
    LazyColumn(
        Modifier.fillMaxSize(),
        contentPadding = PaddingValues(16.dp),
        verticalArrangement = Arrangement.spacedBy(11.dp),
    ) {
        item {
            TextButton(onClick = onBack) { Text("بازگشت") }
            Text("Performance Analytics", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Black)
            Text("نتایج واقعی Fee-aware", color = V3Muted)
        }
        item {
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                V3Stat("امروز IRT", v3Compact(analytics.todayIrt), analytics.todayIrt >= 0, Modifier.weight(1f))
                V3Stat("هفته IRT", v3Compact(analytics.weekIrt), analytics.weekIrt >= 0, Modifier.weight(1f))
                V3Stat("ماه IRT", v3Compact(analytics.monthIrt), analytics.monthIrt >= 0, Modifier.weight(1f))
            }
        }
        item {
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                V3Metric("Win Rate", "${v3One(analytics.winRateIrt)}%", Modifier.weight(1f))
                V3Metric("Profit Factor", v3One(analytics.profitFactorIrt), Modifier.weight(1f))
                V3Metric("Edge Samples", analytics.edgeSamples.toString(), Modifier.weight(1f))
            }
        }
        item {
            V3Panel {
                Text("USDT PnL", fontWeight = FontWeight.Black)
                V3Row("امروز", v3Compact(analytics.todayUsdt))
                V3Row("هفته", v3Compact(analytics.weekUsdt))
                V3Row("ماه", v3Compact(analytics.monthUsdt))
            }
        }
        item {
            V3Panel {
                Text("Edge Calibration", fontWeight = FontWeight.Black)
                V3Row("Expected Edge", analytics.expectedEdge?.let { "${v3One(it)}%" } ?: "—")
                V3Row("Realized Return", analytics.realizedReturn?.let { "${v3One(it)}%" } ?: "—")
                V3Row("Directional Hit", analytics.hitRate?.let { "${v3One(it)}%" } ?: "—")
            }
        }
        item {
            V3Panel {
                Text("Asset Performance", fontWeight = FontWeight.Black)
                V3Row("بهترین", analytics.bestAsset)
                V3Row("ضعیف‌ترین", analytics.worstAsset)
            }
        }
        item {
            V3Panel {
                Text("روزهای اخیر IRT", fontWeight = FontWeight.Black)
                analytics.dailyIrt.takeLast(10).forEach { (day, pnl) ->
                    V3Row(day, v3Compact(pnl), pnl >= 0)
                }
            }
        }
    }
}

@Composable
private fun V3NotificationsScreen(
    notifications: List<V3Notification>,
    unread: Int,
    onBack: () -> Unit,
    onRead: (Long) -> Unit,
    onReadAll: () -> Unit,
) {
    LazyColumn(
        Modifier.fillMaxSize(),
        contentPadding = PaddingValues(16.dp),
        verticalArrangement = Arrangement.spacedBy(9.dp),
    ) {
        item {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Column(Modifier.weight(1f)) {
                    Text("اعلان‌ها", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Black)
                    Text("$unread خوانده‌نشده", color = V3Muted)
                }
                TextButton(onClick = onReadAll, enabled = unread > 0) { Text("خواندن همه") }
                TextButton(onClick = onBack) { Text("بازگشت") }
            }
        }
        if (notifications.isEmpty()) {
            item { Text("اعلانی ثبت نشده است.", color = V3Muted) }
        } else {
            items(notifications, key = { it.id }) { notification ->
                Card(
                    shape = RoundedCornerShape(18.dp),
                    colors = CardDefaults.cardColors(containerColor = if (notification.unread) V3Soft else Color.White),
                ) {
                    Column(Modifier.fillMaxWidth().padding(14.dp), verticalArrangement = Arrangement.spacedBy(5.dp)) {
                        Row(verticalAlignment = Alignment.CenterVertically) {
                            Text(notification.title, fontWeight = if (notification.unread) FontWeight.Black else FontWeight.Bold, modifier = Modifier.weight(1f))
                            if (notification.unread) V3Pill("NEW", V3Primary, V3Soft)
                        }
                        Text(notification.body, color = V3Muted, style = MaterialTheme.typography.bodySmall)
                        Text(notification.createdAt, color = V3Muted, style = MaterialTheme.typography.labelSmall)
                        if (notification.unread) {
                            TextButton(onClick = { onRead(notification.id) }) { Text("خواندم") }
                        }
                    }
                }
            }
        }
    }
}

@Composable
private fun V3Panel(content: @Composable Column.() -> Unit) {
    Card(shape = RoundedCornerShape(21.dp), colors = CardDefaults.cardColors(containerColor = Color.White)) {
        Column(
            Modifier.fillMaxWidth().padding(15.dp),
            verticalArrangement = Arrangement.spacedBy(9.dp),
        ) {
            content()
        }
    }
}

@Composable
private fun V3Stat(title: String, value: String, good: Boolean, modifier: Modifier = Modifier) {
    Card(modifier, shape = RoundedCornerShape(18.dp), colors = CardDefaults.cardColors(containerColor = Color.White)) {
        Column(Modifier.padding(12.dp)) {
            Text(title, color = V3Muted, style = MaterialTheme.typography.labelSmall, maxLines = 1)
            Text(
                value,
                color = if (good) V3Success else V3Danger,
                fontWeight = FontWeight.Black,
                maxLines = 1,
                overflow = TextOverflow.Ellipsis,
            )
        }
    }
}

@Composable
private fun V3Metric(title: String, value: String, modifier: Modifier = Modifier) {
    Box(modifier.clip(RoundedCornerShape(14.dp)).background(Color(0xFFF7F8FA)).padding(11.dp)) {
        Column {
            Text(title, color = V3Muted, style = MaterialTheme.typography.labelSmall)
            Text(value, fontWeight = FontWeight.Black, maxLines = 1, overflow = TextOverflow.Ellipsis)
        }
    }
}

@Composable
private fun V3Progress(value: Float) {
    LinearProgressIndicator(
        progress = { value },
        modifier = Modifier.fillMaxWidth().height(9.dp).clip(RoundedCornerShape(20.dp)),
        color = V3Primary,
        trackColor = Color(0xFFEDF0F5),
    )
}

@Composable
private fun V3Banner(title: String, text: String, tone: Color, background: Color) {
    Card(shape = RoundedCornerShape(18.dp), colors = CardDefaults.cardColors(containerColor = background)) {
        Column(Modifier.fillMaxWidth().padding(13.dp)) {
            Text(title, color = tone, fontWeight = FontWeight.Black)
            Text(text, color = V3Muted, style = MaterialTheme.typography.bodySmall)
        }
    }
}

@Composable
private fun V3Pill(text: String, tone: Color, background: Color) {
    Surface(shape = RoundedCornerShape(100.dp), color = background) {
        Text(
            text,
            Modifier.padding(horizontal = 8.dp, vertical = 4.dp),
            color = tone,
            style = MaterialTheme.typography.labelSmall,
            fontWeight = FontWeight.Bold,
        )
    }
}

@Composable
private fun V3DarkPill(text: String) {
    Surface(shape = RoundedCornerShape(100.dp), color = Color.White.copy(alpha = 0.11f)) {
        Text(
            text,
            Modifier.padding(horizontal = 9.dp, vertical = 5.dp),
            color = Color.White,
            style = MaterialTheme.typography.labelSmall,
            fontWeight = FontWeight.Bold,
        )
    }
}

@Composable
private fun V3Segment(title: String, selected: Boolean, modifier: Modifier, onClick: () -> Unit) {
    FilledTonalButton(
        onClick = onClick,
        modifier = modifier.height(40.dp),
        shape = RoundedCornerShape(13.dp),
        colors = ButtonDefaults.filledTonalButtonColors(
            containerColor = if (selected) V3Soft else Color(0xFFF7F8FA),
            contentColor = if (selected) V3Primary else V3Muted,
        ),
    ) {
        Text(title, fontWeight = if (selected) FontWeight.Black else FontWeight.Medium)
    }
}

@Composable
private fun V3Choice(title: String, selected: Boolean, tone: Color, modifier: Modifier, onClick: () -> Unit) {
    FilledTonalButton(
        onClick = onClick,
        modifier = modifier,
        colors = ButtonDefaults.filledTonalButtonColors(
            containerColor = if (selected) tone.copy(alpha = 0.13f) else Color(0xFFF7F8FA),
            contentColor = if (selected) tone else V3Muted,
        ),
    ) {
        Text(title, fontWeight = if (selected) FontWeight.Black else FontWeight.Medium)
    }
}

@Composable
private fun V3Toggle(
    title: String,
    text: String,
    checked: Boolean,
    icon: ImageVector,
    tone: Color,
    enabled: Boolean,
    onChange: (Boolean) -> Unit,
) {
    Card(shape = RoundedCornerShape(20.dp), colors = CardDefaults.cardColors(containerColor = Color.White)) {
        Row(Modifier.fillMaxWidth().padding(14.dp), verticalAlignment = Alignment.CenterVertically) {
            Box(
                Modifier.size(40.dp).clip(RoundedCornerShape(13.dp)).background(tone.copy(alpha = 0.1f)),
                contentAlignment = Alignment.Center,
            ) { Icon(icon, null, tint = tone) }
            Spacer(Modifier.width(10.dp))
            Column(Modifier.weight(1f)) {
                Text(title, fontWeight = FontWeight.Black)
                Text(text, color = V3Muted, style = MaterialTheme.typography.bodySmall)
            }
            Switch(checked = checked, onCheckedChange = onChange, enabled = enabled)
        }
    }
}

@Composable
private fun V3ActionCard(
    title: String,
    text: String,
    icon: ImageVector,
    tone: Color,
    modifier: Modifier,
    onClick: () -> Unit,
) {
    OutlinedButton(
        onClick = onClick,
        modifier = modifier.height(105.dp),
        shape = RoundedCornerShape(20.dp),
        contentPadding = PaddingValues(10.dp),
    ) {
        Column(horizontalAlignment = Alignment.CenterHorizontally) {
            Icon(icon, null, tint = tone)
            Spacer(Modifier.height(6.dp))
            Text(title, fontWeight = FontWeight.Black)
            Text(text, color = V3Muted, style = MaterialTheme.typography.labelSmall, maxLines = 2)
        }
    }
}

@Composable
private fun V3Row(title: String, value: String, good: Boolean? = null) {
    Row(Modifier.fillMaxWidth().padding(vertical = 5.dp)) {
        Text(title, color = V3Muted, modifier = Modifier.weight(1f))
        Text(
            value,
            color = when (good) {
                true -> V3Success
                false -> V3Danger
                null -> V3Ink
            },
            fontWeight = FontWeight.Bold,
            maxLines = 1,
            overflow = TextOverflow.Ellipsis,
        )
    }
}

private fun parseV3Exchange(data: JSONObject, name: String): V3Exchange {
    val exchange = data.optJSONObject("exchanges")?.optJSONObject(name) ?: JSONObject()
    val performance = exchange.optJSONObject("performance") ?: JSONObject()
    val capacity = exchange.optJSONObject("portfolio_capacity") ?: JSONObject()
    val array = exchange.optJSONArray("active_positions") ?: JSONArray()
    val positions = mutableListOf<V3Position>()
    for (i in 0 until array.length()) {
        val row = array.optJSONObject(i) ?: continue
        positions += V3Position(
            symbol = row.optString("symbol"),
            quote = row.optString("quote_asset"),
            amount = row.optDouble("amount", 0.0),
            entry = row.optDouble("entry_price", 0.0),
            status = row.optString("status"),
        )
    }
    return V3Exchange(
        credentials = exchange.optBoolean("credentials_configured"),
        bot = exchange.optBoolean("bot_enabled"),
        live = exchange.optBoolean("live_execution_enabled"),
        active = exchange.optInt("active_position_count"),
        max = capacity.optInt("max_positions"),
        remaining = capacity.optInt("remaining_position_slots"),
        pending = capacity.optInt("pending_orders"),
        maxPending = capacity.optInt("max_pending_orders"),
        effectiveEntry = capacity.optDouble("effective_position_percent"),
        exposureLimit = capacity.optDouble("portfolio_exposure_limit_percent"),
        pnlToday = performance.optDouble("today_realized_pnl"),
        pnlTotal = performance.optDouble("total_realized_pnl"),
        winRate = performance.optDouble("win_rate_percent"),
        quote = performance.optString("quote_asset", "IRT"),
        decision = v3Decision(exchange.optJSONObject("last_decision")),
        signal = v3Signal(exchange.optJSONObject("latest_signal")),
        positions = positions,
    )
}

private fun parseV3Global(root: JSONObject): V3Global {
    val data = root.optJSONObject("data") ?: root
    return V3Global(
        status = data.optString("status", "unknown"),
        portfolioIrt = data.optDouble("portfolio_value_irt", 0.0),
        exposureIrt = data.optDouble("exposure_irt", 0.0),
        exposurePercent = data.optDouble("exposure_percent", 0.0),
        usdtToIrt = if (data.has("usdt_to_irt_rate") && !data.isNull("usdt_to_irt_rate")) data.optDouble("usdt_to_irt_rate") else null,
    )
}

private fun parseV3Analytics(root: JSONObject): V3Analytics {
    val data = root.optJSONObject("data") ?: JSONObject()
    val summaries = data.optJSONObject("summary_by_quote") ?: JSONObject()
    val irt = summaries.optJSONObject("IRT") ?: JSONObject()
    val usdt = summaries.optJSONObject("USDT") ?: JSONObject()
    val edge = data.optJSONObject("edge_calibration") ?: JSONObject()
    val best = data.optJSONObject("best_asset")
    val worst = data.optJSONObject("worst_asset")
    val dailyArray = data.optJSONObject("daily_series_by_quote")?.optJSONArray("IRT") ?: JSONArray()
    val daily = mutableListOf<Pair<String, Double>>()
    for (i in 0 until dailyArray.length()) {
        val row = dailyArray.optJSONObject(i) ?: continue
        daily += row.optString("day") to row.optDouble("net_pnl", 0.0)
    }

    fun assetLabel(value: JSONObject?): String {
        if (value == null) return "—"
        return "${value.optString("asset")}/${value.optString("quote_asset")} • ${v3Compact(value.optDouble("net_pnl", 0.0))}"
    }

    return V3Analytics(
        todayIrt = irt.optDouble("today_net_pnl"),
        weekIrt = irt.optDouble("week_net_pnl"),
        monthIrt = irt.optDouble("month_net_pnl"),
        winRateIrt = irt.optDouble("win_rate_percent"),
        profitFactorIrt = irt.optDouble("profit_factor"),
        todayUsdt = usdt.optDouble("today_net_pnl"),
        weekUsdt = usdt.optDouble("week_net_pnl"),
        monthUsdt = usdt.optDouble("month_net_pnl"),
        edgeSamples = edge.optInt("samples"),
        expectedEdge = if (edge.isNull("average_expected_edge_percent")) null else edge.optDouble("average_expected_edge_percent"),
        realizedReturn = if (edge.isNull("average_realized_return_percent")) null else edge.optDouble("average_realized_return_percent"),
        hitRate = if (edge.isNull("directional_hit_rate_percent")) null else edge.optDouble("directional_hit_rate_percent"),
        bestAsset = assetLabel(best),
        worstAsset = assetLabel(worst),
        dailyIrt = daily,
    )
}

private fun parseV3Notifications(root: JSONObject): Pair<Int, List<V3Notification>> {
    val data = root.optJSONObject("data") ?: JSONObject()
    val array = data.optJSONArray("items") ?: JSONArray()
    val rows = mutableListOf<V3Notification>()
    for (i in 0 until array.length()) {
        val row = array.optJSONObject(i) ?: continue
        rows += V3Notification(
            id = row.optLong("id"),
            priority = row.optString("priority"),
            title = row.optString("title"),
            body = row.optString("body"),
            createdAt = row.optString("created_at"),
            unread = row.optBoolean("unread"),
        )
    }
    return data.optInt("unread_count") to rows
}

private fun parseV3Wallets(body: String): List<V3Wallet> {
    val root = JSONObject(body)
    val data = root.opt("data")
    val array = when (data) {
        is JSONArray -> data
        is JSONObject -> data.optJSONArray("wallets") ?: data.optJSONArray("data") ?: JSONArray()
        else -> JSONArray()
    }
    val rows = mutableListOf<V3Wallet>()
    for (i in 0 until array.length()) {
        val row = array.optJSONObject(i) ?: continue
        val code = row.optString("currency", row.optString("asset", row.optString("currencyCode"))).uppercase()
        if (code.isBlank()) continue
        val balance = when {
            row.has("activeBalance") -> row.optDouble("activeBalance")
            row.has("available") -> row.optDouble("available")
            row.has("free") -> row.optDouble("free")
            else -> row.optDouble("balance")
        }
        val value = when {
            row.has("rialValue") -> row.optDouble("rialValue")
            row.has("rial_value") -> row.optDouble("rial_value")
            row.has("irtValue") -> row.optDouble("irtValue")
            else -> row.optDouble("valueRls")
        }
        if (balance > 0 || value > 0) rows += V3Wallet(code, balance, value)
    }
    return rows
}

private fun v3Decision(data: JSONObject?): String {
    if (data == null) return "هنوز تصمیمی ثبت نشده"
    val parts = mutableListOf<String>()
    val status = data.optString("status", "-")
    val reason = data.optString("reason", data.optString("error", ""))
    val selected = data.optJSONObject("selected")?.optString("symbol").orEmpty()
    if (status.isNotBlank()) parts += status
    if (reason.isNotBlank()) parts += reason
    if (selected.isNotBlank()) parts += selected
    return parts.joinToString(" • ")
}

private fun v3Signal(data: JSONObject?): String {
    if (data == null) return "هنوز سیگنالی ثبت نشده"
    val parts = mutableListOf<String>()
    parts += data.optString("symbol", "-")
    parts += data.optString("action", "hold").uppercase()
    val details = data.optJSONObject("details")
    val edge = details?.optDouble("tradable_net_edge_percent", Double.NaN) ?: Double.NaN
    if (!edge.isNaN()) parts += "Edge ${v3One(edge)}%"
    return parts.joinToString(" • ")
}

private fun v3Rotation(data: JSONObject): String {
    val parts = mutableListOf<String>()
    parts += data.optString("status", "-")
    val weak = data.optJSONObject("weakest_position")?.optString("symbol").orEmpty()
    val best = data.optJSONObject("best_candidate")?.optString("symbol").orEmpty()
    if (weak.isNotBlank()) parts += "ضعیف: $weak"
    if (best.isNotBlank()) parts += "جایگزین: $best"
    return parts.joinToString(" • ")
}

private fun v3HttpError(response: TradeApi.Response): String {
    return try {
        val json = JSONObject(response.body)
        json.optString("message").ifBlank {
            json.optString("error").ifBlank { "HTTP ${response.code}" }
        }
    } catch (_: Exception) {
        "HTTP ${response.code}"
    }
}

private fun v3One(value: Double): String {
    return if (value.isFinite()) String.format(Locale.US, "%.1f", value) else "—"
}

private fun v3Compact(value: Double): String {
    if (!value.isFinite()) return "—"
    val magnitude = abs(value)
    return when {
        magnitude >= 1_000_000_000 -> String.format(Locale.US, "%.2fB", value / 1_000_000_000)
        magnitude >= 1_000_000 -> String.format(Locale.US, "%.2fM", value / 1_000_000)
        magnitude >= 1_000 -> String.format(Locale.US, "%.1fK", value / 1_000)
        else -> String.format(Locale.US, "%.4f", value).trimEnd('0').trimEnd('.')
    }
}

private fun v3Number(value: Double): String {
    if (!value.isFinite()) return "—"
    return NumberFormat.getNumberInstance(Locale.US).apply { maximumFractionDigits = 2 }.format(value)
}
