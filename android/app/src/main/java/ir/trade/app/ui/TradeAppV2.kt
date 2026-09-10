package ir.trade.app.ui

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.AccountBalanceWallet
import androidx.compose.material.icons.rounded.AutoGraph
import androidx.compose.material.icons.rounded.Bolt
import androidx.compose.material.icons.rounded.CheckCircle
import androidx.compose.material.icons.rounded.CloudDone
import androidx.compose.material.icons.rounded.Dashboard
import androidx.compose.material.icons.rounded.ErrorOutline
import androidx.compose.material.icons.rounded.Logout
import androidx.compose.material.icons.rounded.PlayArrow
import androidx.compose.material.icons.rounded.PowerSettingsNew
import androidx.compose.material.icons.rounded.Refresh
import androidx.compose.material.icons.rounded.Security
import androidx.compose.material.icons.rounded.Settings
import androidx.compose.material.icons.rounded.Shield
import androidx.compose.material.icons.rounded.SmartToy
import androidx.compose.material.icons.rounded.SwapHoriz
import androidx.compose.material.icons.rounded.TrendingUp
import androidx.compose.material.icons.rounded.Update
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.LayoutDirection
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.compose.ui.platform.LocalLayoutDirection
import ir.trade.app.BuildConfig
import ir.trade.app.data.ReleaseContract
import ir.trade.app.data.TradeApi
import ir.trade.app.data.TradePreferences
import ir.trade.app.update.AppUpdateManager
import kotlinx.coroutines.launch
import org.json.JSONArray
import org.json.JSONObject
import java.text.NumberFormat
import java.util.Locale

private val V2Bg = Color(0xFFF3F5FA)
private val V2Surface = Color.White
private val V2Ink = Color(0xFF111827)
private val V2Muted = Color(0xFF6B7280)
private val V2Primary = Color(0xFF5B3DF5)
private val V2Primary2 = Color(0xFF7C5CFC)
private val V2Blue = Color(0xFF2563EB)
private val V2Cyan = Color(0xFF0891B2)
private val V2Success = Color(0xFF0C9B72)
private val V2Danger = Color(0xFFD84A4A)
private val V2Warning = Color(0xFFB96C08)
private val V2Stroke = Color(0xFFE5E7EB)
private val V2Soft = Color(0xFFF0EDFF)
private val V2SuccessSoft = Color(0xFFE9F8F2)
private val V2DangerSoft = Color(0xFFFFEEEE)
private val V2WarningSoft = Color(0xFFFFF6E5)

private data class V2ExchangeState(
    val credentials: Boolean = false,
    val bot: Boolean = false,
    val live: Boolean = false,
    val activePositions: Int = 0,
    val maxPositions: Int = 0,
    val remainingSlots: Int = 0,
    val effectivePositionPercent: Double = 0.0,
    val exposureLimitPercent: Double = 0.0,
    val pendingOrders: Int = 0,
    val maxPendingOrders: Int = 0,
    val pendingTimeoutSeconds: Int = 0,
    val pnlToday: Double = 0.0,
    val pnlTotal: Double = 0.0,
    val winRate: Double = 0.0,
    val quote: String = "IRT",
    val latestSignal: String = "هنوز سیگنال ثبت نشده",
    val lastDecision: String = "هنوز تصمیم ثبت نشده",
    val latestOrder: String = "هنوز سفارشی ثبت نشده",
)

private data class V2WalletRow(
    val code: String,
    val balance: Double,
    val rialValueToman: Double,
)

private data class V2NavItem(val title: String, val icon: ImageVector)

@Composable
fun TradeAppV2() {
    val context = LocalContext.current
    val prefs = remember { TradePreferences(context) }
    var configured by remember { mutableStateOf(prefs.isConfigured()) }

    val colors = lightColorScheme(
        primary = V2Primary,
        secondary = V2Success,
        background = V2Bg,
        surface = V2Surface,
        onBackground = V2Ink,
        onSurface = V2Ink,
        error = V2Danger,
    )

    CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Rtl) {
        MaterialTheme(colorScheme = colors) {
            Surface(Modifier.fillMaxSize(), color = V2Bg) {
                if (!configured) {
                    V2SetupScreen(prefs) { configured = true }
                } else {
                    V2Dashboard(prefs) {
                        prefs.clear()
                        configured = false
                    }
                }
            }
        }
    }
}

@Composable
private fun V2SetupScreen(prefs: TradePreferences, onSaved: () -> Unit) {
    var server by rememberSaveable { mutableStateOf(prefs.serverUrl()) }
    var token by rememberSaveable { mutableStateOf(prefs.apiToken()) }
    var error by rememberSaveable { mutableStateOf("") }

    Box(
        Modifier
            .fillMaxSize()
            .background(Brush.verticalGradient(listOf(Color(0xFF111827), Color(0xFF20263A), V2Bg)))
            .statusBarsPadding()
            .navigationBarsPadding()
            .padding(20.dp),
        contentAlignment = Alignment.Center,
    ) {
        Card(
            Modifier.fillMaxWidth(),
            shape = RoundedCornerShape(30.dp),
            colors = CardDefaults.cardColors(containerColor = Color.White),
            elevation = CardDefaults.cardElevation(defaultElevation = 10.dp),
        ) {
            Column(Modifier.padding(24.dp), verticalArrangement = Arrangement.spacedBy(15.dp)) {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Box(
                        Modifier
                            .size(52.dp)
                            .clip(RoundedCornerShape(17.dp))
                            .background(Brush.linearGradient(listOf(V2Primary, V2Primary2))),
                        contentAlignment = Alignment.Center,
                    ) {
                        Text("T", color = Color.White, fontSize = 24.sp, fontWeight = FontWeight.Black)
                    }
                    Spacer(Modifier.width(12.dp))
                    Column {
                        Text("Trade", style = MaterialTheme.typography.headlineSmall, fontWeight = FontWeight.Black)
                        Text("Secure Trading Cockpit", color = V2Muted, style = MaterialTheme.typography.bodySmall)
                    }
                }

                V2InfoBanner(
                    icon = Icons.Rounded.Security,
                    title = "اتصال امن به موتور معاملات",
                    text = "کلید صرافی داخل گوشی ذخیره نمی‌شود؛ اپ فقط با Backend شخصی ارتباط دارد.",
                    tone = V2Primary,
                    background = V2Soft,
                )

                OutlinedTextField(
                    value = server,
                    onValueChange = { server = it },
                    modifier = Modifier.fillMaxWidth(),
                    label = { Text("آدرس Backend") },
                    singleLine = true,
                    shape = RoundedCornerShape(16.dp),
                )
                OutlinedTextField(
                    value = token,
                    onValueChange = { token = it },
                    modifier = Modifier.fillMaxWidth(),
                    label = { Text("App API Token") },
                    singleLine = true,
                    visualTransformation = PasswordVisualTransformation(),
                    shape = RoundedCornerShape(16.dp),
                )
                if (error.isNotBlank()) {
                    V2InfoBanner(Icons.Rounded.ErrorOutline, "اتصال انجام نشد", error, V2Danger, V2DangerSoft)
                }
                Button(
                    onClick = {
                        try {
                            prefs.save(server.trim(), token.trim())
                            onSaved()
                        } catch (_: IllegalArgumentException) {
                            error = "آدرس سرور باید HTTPS و توکن معتبر باشد."
                        }
                    },
                    enabled = server.isNotBlank() && token.isNotBlank(),
                    modifier = Modifier.fillMaxWidth().height(54.dp),
                    shape = RoundedCornerShape(17.dp),
                ) {
                    Icon(Icons.Rounded.Shield, null)
                    Spacer(Modifier.width(8.dp))
                    Text("ورود امن به Trade", fontWeight = FontWeight.Bold)
                }
            }
        }
    }
}

@Composable
private fun V2Dashboard(prefs: TradePreferences, onDisconnect: () -> Unit) {
    val scope = rememberCoroutineScope()
    val context = LocalContext.current
    val api = remember(prefs.serverUrl(), prefs.apiToken()) { TradeApi(prefs.serverUrl(), prefs.apiToken()) }
    val updateState = LocalUpdateUiState.current
    val requestUpdate = LocalRequestUpdateCheck.current

    var tab by rememberSaveable { mutableIntStateOf(0) }
    var exchange by rememberSaveable { mutableStateOf("nobitex") }
    var nobitex by remember { mutableStateOf(V2ExchangeState()) }
    var bitpin by remember { mutableStateOf(V2ExchangeState()) }
    var killSwitch by remember { mutableStateOf(false) }
    var cronHealthy by remember { mutableStateOf(false) }
    var cronText by remember { mutableStateOf("در حال بررسی موتور…") }
    var backendVersion by remember { mutableStateOf("-") }
    var loading by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf("") }
    var message by remember { mutableStateOf("") }
    var wallets by remember { mutableStateOf<List<V2WalletRow>>(emptyList()) }
    var lastNobitexRun by remember { mutableStateOf("هنوز چرخه‌ای ثبت نشده") }
    var lastBitpinRun by remember { mutableStateOf("هنوز چرخه‌ای ثبت نشده") }
    var intelligenceReady by remember { mutableStateOf(false) }

    var symbol by rememberSaveable { mutableStateOf("BTCIRT") }
    var amount by rememberSaveable { mutableStateOf("") }
    var price by rememberSaveable { mutableStateOf("") }
    var side by rememberSaveable { mutableStateOf("buy") }
    var mode by rememberSaveable { mutableStateOf("market") }
    var cancelId by rememberSaveable { mutableStateOf("") }

    val current = if (exchange == "nobitex") nobitex else bitpin
    val exchangeTitle = if (exchange == "nobitex") "Nobitex" else "Bitpin"
    val totalWallet = wallets.sumOf { it.rialValueToman }
    val compatible = api.isContractCompatible()

    fun parseState(data: JSONObject, name: String): V2ExchangeState {
        val x = data.optJSONObject("exchanges")?.optJSONObject(name) ?: JSONObject()
        val p = x.optJSONObject("performance") ?: JSONObject()
        val c = x.optJSONObject("portfolio_capacity") ?: JSONObject()
        return V2ExchangeState(
            credentials = x.optBoolean("credentials_configured"),
            bot = x.optBoolean("bot_enabled"),
            live = x.optBoolean("live_execution_enabled"),
            activePositions = x.optInt("active_position_count", 0),
            maxPositions = c.optInt("max_positions", 0),
            remainingSlots = c.optInt("remaining_position_slots", 0),
            effectivePositionPercent = c.optDouble("effective_position_percent", 0.0),
            exposureLimitPercent = c.optDouble("portfolio_exposure_limit_percent", 0.0),
            pendingOrders = c.optInt("pending_orders", 0),
            maxPendingOrders = c.optInt("max_pending_orders", 0),
            pendingTimeoutSeconds = c.optInt("pending_timeout_seconds", 0),
            pnlToday = p.optDouble("today_realized_pnl", 0.0),
            pnlTotal = p.optDouble("total_realized_pnl", 0.0),
            winRate = p.optDouble("win_rate_percent", 0.0),
            quote = p.optString("quote_asset", "IRT"),
            latestSignal = v2SignalText(x.optJSONObject("latest_signal")),
            lastDecision = v2DecisionText(x.optJSONObject("last_decision")),
            latestOrder = v2OrderText(x.optJSONObject("latest_order")),
        )
    }

    fun refreshStatus() {
        scope.launch {
            loading = true
            error = ""
            try {
                val response = api.status()
                if (!response.ok) throw IllegalStateException(v2HttpError(response))
                val data = JSONObject(response.body).optJSONObject("data") ?: JSONObject(response.body)
                backendVersion = data.optString("backend_version", "-")
                killSwitch = data.optBoolean("kill_switch")
                val health = data.optJSONObject("cron_health")
                cronHealthy = health?.optBoolean("healthy") == true
                val age = health?.optLong("age_seconds", -1L) ?: -1L
                cronText = when {
                    cronHealthy && age >= 0 -> "موتور سالم • آخرین اجرا ${age} ثانیه قبل"
                    age >= 0 -> "Cron نیازمند بررسی • ${age} ثانیه از آخرین اجرا"
                    else -> "هنوز اجرای Cron ثبت نشده"
                }
                nobitex = parseState(data, "nobitex")
                bitpin = parseState(data, "bitpin")
                intelligenceReady = api.capabilities().contains("trading.portfolio_intelligence_v2")
                val runs = data.optJSONObject("last_run")?.optJSONObject("summary")?.optJSONObject("exchanges")
                lastNobitexRun = v2RunText(runs?.optJSONObject("nobitex"))
                lastBitpinRun = v2RunText(runs?.optJSONObject("bitpin"))
            } catch (e: Exception) {
                error = e.message ?: "خطا در دریافت وضعیت Backend"
            } finally {
                loading = false
            }
        }
    }

    fun refreshWallet() {
        scope.launch {
            try {
                val response = api.wallets(exchange)
                if (!response.ok) throw IllegalStateException(v2HttpError(response))
                wallets = v2Wallets(response.body)
            } catch (e: Exception) {
                error = e.message ?: "دریافت کیف پول ناموفق بود"
            }
        }
    }

    fun selectExchange(value: String) {
        exchange = value
        symbol = if (value == "nobitex") "BTCIRT" else "BTC_IRT"
        wallets = emptyList()
        message = ""
        error = ""
        refreshWallet()
    }

    fun setBot(enabled: Boolean) {
        scope.launch {
            loading = true
            try {
                val r = api.setExchangeBot(exchange, enabled)
                if (!r.ok) throw IllegalStateException(v2HttpError(r))
                message = "ربات $exchangeTitle ${if (enabled) "فعال" else "غیرفعال"} شد."
                refreshStatus()
            } catch (e: Exception) { error = e.message ?: "تغییر وضعیت ربات ناموفق بود" }
            finally { loading = false }
        }
    }

    fun setLive(enabled: Boolean) {
        scope.launch {
            loading = true
            try {
                val r = api.setExchangeLive(exchange, enabled)
                if (!r.ok) throw IllegalStateException(v2HttpError(r))
                message = "Live Execution $exchangeTitle ${if (enabled) "فعال" else "غیرفعال"} شد."
                refreshStatus()
            } catch (e: Exception) { error = e.message ?: "تغییر Live ناموفق بود" }
            finally { loading = false }
        }
    }

    fun setKill(enabled: Boolean) {
        scope.launch {
            loading = true
            try {
                val r = api.setKillSwitch(enabled)
                if (!r.ok) throw IllegalStateException(v2HttpError(r))
                killSwitch = enabled
                message = if (enabled) "توقف اضطراری فعال شد." else "توقف اضطراری برداشته شد."
                refreshStatus()
            } catch (e: Exception) { error = e.message ?: "تغییر Kill Switch ناموفق بود" }
            finally { loading = false }
        }
    }

    fun runNow() {
        scope.launch {
            loading = true
            try {
                val r = api.runExchange(exchange)
                if (!r.ok) throw IllegalStateException(v2HttpError(r))
                val result = JSONObject(r.body).optJSONObject("data")
                message = "چرخه اجرا شد: ${v2RunText(result)}"
                refreshStatus()
                refreshWallet()
            } catch (e: Exception) { error = e.message ?: "اجرای چرخه ناموفق بود" }
            finally { loading = false }
        }
    }

    fun submitOrder() {
        val a = amount.toDoubleOrNull()
        val p = price.toDoubleOrNull()
        if (symbol.isBlank() || a == null || a <= 0 || (mode != "market" && (p == null || p <= 0))) {
            error = "نماد، مقدار و قیمت سفارش را بررسی کن."
            return
        }
        scope.launch {
            loading = true
            try {
                val payload = JSONObject()
                    .put("exchange", exchange)
                    .put("symbol", symbol.trim().uppercase())
                    .put("amount1", a)
                    .put("mode", mode)
                    .put("type", side)
                if (p != null && p > 0) payload.put("price", p)
                val r = api.createOrder(payload.toString())
                if (!r.ok) throw IllegalStateException(v2HttpError(r))
                message = "سفارش واقعی برای $exchangeTitle ارسال شد."
                refreshStatus()
                refreshWallet()
            } catch (e: Exception) { error = e.message ?: "ثبت سفارش ناموفق بود" }
            finally { loading = false }
        }
    }

    fun cancelOrder() {
        if (cancelId.isBlank()) { error = "شناسه سفارش را وارد کن."; return }
        scope.launch {
            loading = true
            try {
                val r = api.cancelOrder(cancelId.trim(), exchange)
                if (!r.ok) throw IllegalStateException(v2HttpError(r))
                cancelId = ""
                message = "درخواست لغو سفارش ارسال شد."
                refreshStatus()
            } catch (e: Exception) { error = e.message ?: "لغو سفارش ناموفق بود" }
            finally { loading = false }
        }
    }

    LaunchedEffect(Unit) {
        refreshStatus()
        refreshWallet()
    }

    val nav = listOf(
        V2NavItem("خانه", Icons.Rounded.Dashboard),
        V2NavItem("پرتفو", Icons.Rounded.AccountBalanceWallet),
        V2NavItem("معامله", Icons.Rounded.SwapHoriz),
        V2NavItem("اتوماسیون", Icons.Rounded.SmartToy),
        V2NavItem("تنظیمات", Icons.Rounded.Settings),
    )

    Scaffold(
        containerColor = V2Bg,
        bottomBar = {
            NavigationBar(containerColor = Color.White, tonalElevation = 8.dp) {
                nav.forEachIndexed { index, item ->
                    NavigationBarItem(
                        selected = tab == index,
                        onClick = { tab = index },
                        icon = { Icon(item.icon, item.title) },
                        label = { Text(item.title, maxLines = 1) },
                        colors = NavigationBarItemDefaults.colors(
                            selectedIconColor = V2Primary,
                            selectedTextColor = V2Primary,
                            indicatorColor = V2Soft,
                            unselectedIconColor = Color(0xFF9CA3AF),
                            unselectedTextColor = V2Muted,
                        ),
                    )
                }
            }
        },
    ) { padding ->
        Column(Modifier.fillMaxSize().padding(padding).statusBarsPadding()) {
            V2TopBar(
                exchange = exchange,
                state = current,
                cronHealthy = cronHealthy,
                loading = loading,
                onExchange = { selectExchange(it) },
                onRefresh = { refreshStatus(); refreshWallet() },
            )

            if (error.isNotBlank()) {
                Box(Modifier.padding(horizontal = 14.dp, vertical = 5.dp)) {
                    V2InfoBanner(Icons.Rounded.ErrorOutline, "خطا", error, V2Danger, V2DangerSoft)
                }
            }
            if (message.isNotBlank()) {
                Box(Modifier.padding(horizontal = 14.dp, vertical = 5.dp)) {
                    V2InfoBanner(Icons.Rounded.CheckCircle, "انجام شد", message, V2Success, V2SuccessSoft)
                }
            }

            Box(Modifier.weight(1f)) {
                when (tab) {
                    0 -> V2Overview(
                        exchangeTitle, current, totalWallet, killSwitch, cronText, cronHealthy,
                        intelligenceReady, compatible,
                        if (exchange == "nobitex") lastNobitexRun else lastBitpinRun,
                        onPortfolio = { tab = 1 }, onTrade = { tab = 2 }, onBot = { tab = 3 },
                    )
                    1 -> V2Portfolio(exchangeTitle, current, wallets, loading) { refreshWallet() }
                    2 -> V2Trade(
                        exchangeTitle, current, killSwitch, compatible, loading,
                        symbol, amount, price, side, mode, cancelId,
                        onSymbol = { symbol = it }, onAmount = { amount = it }, onPrice = { price = it },
                        onSide = { side = it }, onMode = { mode = it }, onCancelId = { cancelId = it },
                        onSubmit = { submitOrder() }, onCancel = { cancelOrder() },
                    )
                    3 -> V2Automation(
                        exchangeTitle, current, nobitex, bitpin, killSwitch, loading, cronText,
                        intelligenceReady, if (exchange == "nobitex") lastNobitexRun else lastBitpinRun,
                        onBot = { setBot(it) }, onLive = { setLive(it) }, onKill = { setKill(it) }, onRun = { runNow() },
                    )
                    else -> V2Settings(
                        AppUpdateManager.currentVersionName(context), backendVersion, updateState,
                        onUpdate = requestUpdate, onDisconnect = onDisconnect,
                    )
                }
            }
        }
    }
}

@Composable
private fun V2TopBar(
    exchange: String,
    state: V2ExchangeState,
    cronHealthy: Boolean,
    loading: Boolean,
    onExchange: (String) -> Unit,
    onRefresh: () -> Unit,
) {
    Column(
        Modifier.fillMaxWidth().background(Color.White).padding(horizontal = 16.dp, vertical = 10.dp),
        verticalArrangement = Arrangement.spacedBy(9.dp),
    ) {
        Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
            Box(
                Modifier.size(42.dp).clip(RoundedCornerShape(14.dp))
                    .background(Brush.linearGradient(listOf(V2Primary, V2Primary2))),
                contentAlignment = Alignment.Center,
            ) { Text("T", color = Color.White, fontWeight = FontWeight.Black, fontSize = 20.sp) }
            Spacer(Modifier.width(10.dp))
            Column(Modifier.weight(1f)) {
                Text("Trade Cockpit", fontWeight = FontWeight.Black, style = MaterialTheme.typography.titleLarge)
                Text(
                    when {
                        !state.credentials -> "API نیازمند تنظیم"
                        !state.live -> "Live Execution خاموش"
                        !state.bot -> "ربات در حالت آماده‌باش"
                        else -> "موتور معاملات فعال"
                    },
                    color = V2Muted,
                    style = MaterialTheme.typography.labelMedium,
                )
            }
            V2MiniStatus(if (cronHealthy) "LIVE" else "CHECK", cronHealthy)
            IconButton(onClick = onRefresh, enabled = !loading) {
                Icon(Icons.Rounded.Refresh, "بروزرسانی", tint = V2Primary)
            }
        }
        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            V2ExchangeButton("Nobitex", exchange == "nobitex", Modifier.weight(1f)) { onExchange("nobitex") }
            V2ExchangeButton("Bitpin", exchange == "bitpin", Modifier.weight(1f)) { onExchange("bitpin") }
        }
    }
}

@Composable
private fun V2ExchangeButton(title: String, selected: Boolean, modifier: Modifier = Modifier, onClick: () -> Unit) {
    FilledTonalButton(
        onClick = onClick,
        modifier = modifier.height(42.dp),
        shape = RoundedCornerShape(14.dp),
        colors = ButtonDefaults.filledTonalButtonColors(
            containerColor = if (selected) V2Soft else Color(0xFFF7F7FA),
            contentColor = if (selected) V2Primary else V2Muted,
        ),
    ) {
        if (selected) Icon(Icons.Rounded.CheckCircle, null, Modifier.size(17.dp))
        if (selected) Spacer(Modifier.width(5.dp))
        Text(title, fontWeight = if (selected) FontWeight.Bold else FontWeight.Medium)
    }
}

@Composable
private fun V2Overview(
    exchangeTitle: String,
    current: V2ExchangeState,
    totalWallet: Double,
    killSwitch: Boolean,
    cronText: String,
    cronHealthy: Boolean,
    intelligenceReady: Boolean,
    compatible: Boolean,
    lastRun: String,
    onPortfolio: () -> Unit,
    onTrade: () -> Unit,
    onBot: () -> Unit,
) {
    LazyColumn(
        Modifier.fillMaxSize(),
        contentPadding = PaddingValues(16.dp),
        verticalArrangement = Arrangement.spacedBy(14.dp),
    ) {
        item {
            Card(
                shape = RoundedCornerShape(28.dp),
                colors = CardDefaults.cardColors(containerColor = Color.Transparent),
                elevation = CardDefaults.cardElevation(defaultElevation = 8.dp),
            ) {
                Box(
                    Modifier.fillMaxWidth()
                        .background(Brush.linearGradient(listOf(Color(0xFF111827), Color(0xFF30256F), V2Primary)))
                        .padding(20.dp),
                ) {
                    Column(verticalArrangement = Arrangement.spacedBy(16.dp)) {
                        Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                            Column(Modifier.weight(1f)) {
                                Text("ارزش تقریبی دارایی‌ها", color = Color(0xFFD1D5DB), style = MaterialTheme.typography.labelLarge)
                                Spacer(Modifier.height(4.dp))
                                Text(
                                    if (totalWallet > 0) "${v2Number(totalWallet)} تومان" else "—",
                                    color = Color.White,
                                    style = MaterialTheme.typography.headlineMedium,
                                    fontWeight = FontWeight.Black,
                                )
                            }
                            Box(
                                Modifier.size(50.dp).clip(RoundedCornerShape(17.dp)).background(Color.White.copy(alpha = 0.12f)),
                                contentAlignment = Alignment.Center,
                            ) { Icon(Icons.Rounded.AutoGraph, null, tint = Color.White, modifier = Modifier.size(26.dp)) }
                        }
                        Row(horizontalArrangement = Arrangement.spacedBy(7.dp)) {
                            V2DarkPill(exchangeTitle)
                            V2DarkPill(if (current.bot && current.live && !killSwitch) "AUTO LIVE" else "STANDBY")
                            V2DarkPill(if (intelligenceReady) "AI RISK v2" else "RISK BASIC")
                        }
                    }
                }
            }
        }

        item {
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(9.dp)) {
                V2Kpi("PnL امروز", v2Compact(current.pnlToday), current.pnlToday >= 0, Icons.Rounded.TrendingUp, Modifier.weight(1f))
                V2Kpi("Win Rate", "${v2One(current.winRate)}%", current.winRate >= 50, Icons.Rounded.Bolt, Modifier.weight(1f))
                V2Kpi("پوزیشن", if (current.maxPositions > 0) "${current.activePositions}/${current.maxPositions}" else current.activePositions.toString(), true, Icons.Rounded.AccountBalanceWallet, Modifier.weight(1f))
            }
        }

        if (exchangeTitle == "Nobitex" && current.maxPositions > 0) {
            item { V2CapacityCard(current) }
        }

        item {
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                V2QuickAction("پرتفو", "موجودی و ظرفیت", Icons.Rounded.AccountBalanceWallet, V2Blue, onPortfolio, Modifier.weight(1f))
                V2QuickAction("معامله", "سفارش دستی", Icons.Rounded.SwapHoriz, V2Primary, onTrade, Modifier.weight(1f))
                V2QuickAction("اتوماسیون", "ربات و Live", Icons.Rounded.SmartToy, V2Success, onBot, Modifier.weight(1f))
            }
        }

        item {
            V2SectionHeader("وضعیت موتور", if (cronHealthy) "سالم" else "نیازمند بررسی")
            V2InfoBanner(
                Icons.Rounded.CloudDone,
                if (cronHealthy) "Cron و Backend در دسترس‌اند" else "سلامت Cron را بررسی کن",
                cronText,
                if (cronHealthy) V2Success else V2Warning,
                if (cronHealthy) V2SuccessSoft else V2WarningSoft,
            )
        }

        if (!compatible) {
            item {
                V2InfoBanner(
                    Icons.Rounded.Security,
                    "API Contract ناسازگار",
                    "عملیات حساس تا هماهنگ‌شدن App و Backend قفل می‌ماند.",
                    V2Danger,
                    V2DangerSoft,
                )
            }
        }

        item {
            V2SectionHeader("تصمیم‌گیری", "آخرین داده واقعی")
            V2Insight("آخرین تصمیم", current.lastDecision, Icons.Rounded.AutoGraph, V2Primary)
            Spacer(Modifier.height(9.dp))
            V2Insight("آخرین سیگنال", current.latestSignal, Icons.Rounded.Bolt, V2Cyan)
            Spacer(Modifier.height(9.dp))
            V2Insight("آخرین چرخه", lastRun, Icons.Rounded.Refresh, V2Blue)
        }
    }
}

@Composable
private fun V2CapacityCard(current: V2ExchangeState) {
    val used = if (current.maxPositions > 0) current.activePositions.toFloat() / current.maxPositions.toFloat() else 0f
    Card(
        shape = RoundedCornerShape(24.dp),
        colors = CardDefaults.cardColors(containerColor = Color.White),
        elevation = CardDefaults.cardElevation(defaultElevation = 3.dp),
    ) {
        Column(Modifier.padding(17.dp), verticalArrangement = Arrangement.spacedBy(11.dp)) {
            Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                Box(
                    Modifier.size(39.dp).clip(RoundedCornerShape(13.dp)).background(V2Soft),
                    contentAlignment = Alignment.Center,
                ) { Icon(Icons.Rounded.Shield, null, tint = V2Primary) }
                Spacer(Modifier.width(9.dp))
                Column(Modifier.weight(1f)) {
                    Text("Portfolio Intelligence", fontWeight = FontWeight.Black)
                    Text("ظرفیت، Exposure و Pending", color = V2Muted, style = MaterialTheme.typography.bodySmall)
                }
                V2MiniStatus("v2", true)
            }
            V2Progress(used.coerceIn(0f, 1f))
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
                Text("${current.activePositions} فعال", color = V2Ink, fontWeight = FontWeight.Bold)
                Text("${current.remainingSlots} اسلات آزاد", color = V2Muted)
            }
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                V2SmallMetric("اندازه ورود", "${v2One(current.effectivePositionPercent)}%", Modifier.weight(1f))
                V2SmallMetric("Exposure سقف", "${v2One(current.exposureLimitPercent)}%", Modifier.weight(1f))
                V2SmallMetric("Pending", "${current.pendingOrders}/${current.maxPendingOrders}", Modifier.weight(1f))
            }
            Text("Watchdog سفارش: ${current.pendingTimeoutSeconds} ثانیه • Smart Candidate Fallback فعال", color = V2Muted, style = MaterialTheme.typography.bodySmall)
        }
    }
}

@Composable
private fun V2Portfolio(name: String, current: V2ExchangeState, wallets: List<V2WalletRow>, loading: Boolean, onRefresh: () -> Unit) {
    val total = wallets.sumOf { it.rialValueToman }
    LazyColumn(
        Modifier.fillMaxSize(),
        contentPadding = PaddingValues(16.dp),
        verticalArrangement = Arrangement.spacedBy(11.dp),
    ) {
        item {
            Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                Column(Modifier.weight(1f)) {
                    Text("پرتفوی $name", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Black)
                    Text("موجودی واقعی و ظرفیت سرمایه", color = V2Muted)
                }
                FilledTonalIconButton(onClick = onRefresh, enabled = !loading) { Icon(Icons.Rounded.Refresh, null) }
            }
        }
        item {
            Card(shape = RoundedCornerShape(23.dp), colors = CardDefaults.cardColors(containerColor = V2Ink)) {
                Row(Modifier.fillMaxWidth().padding(18.dp), verticalAlignment = Alignment.CenterVertically) {
                    Column(Modifier.weight(1f)) {
                        Text("ارزش نمایش‌پذیر کیف پول", color = Color(0xFFBFC4D2), style = MaterialTheme.typography.bodySmall)
                        Text(if (total > 0) "${v2Number(total)} تومان" else "—", color = Color.White, fontWeight = FontWeight.Black, style = MaterialTheme.typography.headlineSmall)
                    }
                    Text("${wallets.size} Asset", color = Color(0xFFD8D4FF), fontWeight = FontWeight.Bold)
                }
            }
        }
        if (name == "Nobitex" && current.maxPositions > 0) item { V2CapacityCard(current) }
        if (wallets.isEmpty()) {
            item { V2Empty(if (loading) "در حال دریافت دارایی‌ها…" else "دارایی قابل نمایش پیدا نشد.") }
        } else {
            items(wallets, key = { it.code }) { row -> V2AssetRow(row) }
        }
    }
}

@Composable
private fun V2AssetRow(row: V2WalletRow) {
    Card(shape = RoundedCornerShape(20.dp), colors = CardDefaults.cardColors(containerColor = Color.White)) {
        Row(Modifier.fillMaxWidth().padding(15.dp), verticalAlignment = Alignment.CenterVertically) {
            Box(
                Modifier.size(45.dp).clip(RoundedCornerShape(15.dp)).background(Color(0xFFF3F4F6)),
                contentAlignment = Alignment.Center,
            ) { Text(row.code.take(3), fontWeight = FontWeight.Black, color = V2Primary) }
            Spacer(Modifier.width(11.dp))
            Column(Modifier.weight(1f)) {
                Text(row.code, fontWeight = FontWeight.Bold)
                Text("موجودی ${v2Compact(row.balance)}", color = V2Muted, style = MaterialTheme.typography.bodySmall)
            }
            Text(if (row.rialValueToman > 0) "${v2Number(row.rialValueToman)} تومان" else "—", fontWeight = FontWeight.Bold)
        }
    }
}

@Composable
private fun V2Trade(
    name: String,
    state: V2ExchangeState,
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
    LazyColumn(Modifier.fillMaxSize(), contentPadding = PaddingValues(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
        item {
            Text("معامله دستی", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Black)
            Text("سفارش واقعی روی $name", color = V2Muted)
        }
        item {
            V2InfoBanner(
                Icons.Rounded.Security,
                if (enabled) "Execution آماده است" else "Execution قفل است",
                when {
                    kill -> "Kill Switch روشن است."
                    !compatible -> "App و Backend از نظر Contract هماهنگ نیستند."
                    !state.credentials -> "API صرافی تنظیم نشده است."
                    !state.live -> "Live Execution خاموش است."
                    else -> "سیستم آماده ارسال سفارش واقعی است."
                },
                if (enabled) V2Success else V2Warning,
                if (enabled) V2SuccessSoft else V2WarningSoft,
            )
        }
        item {
            Card(shape = RoundedCornerShape(24.dp), colors = CardDefaults.cardColors(containerColor = Color.White)) {
                Column(Modifier.padding(17.dp), verticalArrangement = Arrangement.spacedBy(11.dp)) {
                    OutlinedTextField(symbol, onSymbol, label = { Text("نماد بازار") }, singleLine = true, modifier = Modifier.fillMaxWidth(), shape = RoundedCornerShape(15.dp))
                    OutlinedTextField(amount, onAmount, label = { Text("مقدار ارز") }, singleLine = true, modifier = Modifier.fillMaxWidth(), shape = RoundedCornerShape(15.dp))
                    Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                        V2Choice("خرید", side == "buy", V2Success, Modifier.weight(1f)) { onSide("buy") }
                        V2Choice("فروش", side == "sell", V2Danger, Modifier.weight(1f)) { onSide("sell") }
                    }
                    Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                        V2Choice("Market", mode == "market", V2Primary, Modifier.weight(1f)) { onMode("market") }
                        V2Choice("Limit", mode == "limit", V2Blue, Modifier.weight(1f)) { onMode("limit") }
                    }
                    if (mode != "market") {
                        OutlinedTextField(price, onPrice, label = { Text("قیمت") }, singleLine = true, modifier = Modifier.fillMaxWidth(), shape = RoundedCornerShape(15.dp))
                    }
                    Button(onClick = onSubmit, enabled = enabled, modifier = Modifier.fillMaxWidth().height(54.dp), shape = RoundedCornerShape(17.dp)) {
                        Icon(Icons.Rounded.Bolt, null)
                        Spacer(Modifier.width(7.dp))
                        Text("ارسال سفارش واقعی", fontWeight = FontWeight.Bold)
                    }
                }
            }
        }
        item {
            Card(shape = RoundedCornerShape(22.dp), colors = CardDefaults.cardColors(containerColor = Color.White)) {
                Column(Modifier.padding(17.dp), verticalArrangement = Arrangement.spacedBy(9.dp)) {
                    Text("لغو سفارش", fontWeight = FontWeight.Black)
                    OutlinedTextField(cancelId, onCancelId, label = { Text("Order ID / Client Order ID") }, singleLine = true, modifier = Modifier.fillMaxWidth(), shape = RoundedCornerShape(15.dp))
                    OutlinedButton(onClick = onCancel, enabled = state.credentials && cancelId.isNotBlank() && !loading, modifier = Modifier.fillMaxWidth(), shape = RoundedCornerShape(15.dp)) { Text("ارسال درخواست لغو") }
                }
            }
        }
    }
}

@Composable
private fun V2Automation(
    name: String,
    current: V2ExchangeState,
    nobitex: V2ExchangeState,
    bitpin: V2ExchangeState,
    kill: Boolean,
    loading: Boolean,
    cronText: String,
    intelligenceReady: Boolean,
    lastRun: String,
    onBot: (Boolean) -> Unit,
    onLive: (Boolean) -> Unit,
    onKill: (Boolean) -> Unit,
    onRun: () -> Unit,
) {
    LazyColumn(Modifier.fillMaxSize(), contentPadding = PaddingValues(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
        item {
            Text("Automation Center", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Black)
            Text("کنترل موتور چندارزی، Intelligence و اجرای واقعی", color = V2Muted)
        }
        item {
            Card(shape = RoundedCornerShape(26.dp), colors = CardDefaults.cardColors(containerColor = Color.Transparent)) {
                Box(
                    Modifier.fillMaxWidth().background(Brush.linearGradient(listOf(Color(0xFF1F2937), Color(0xFF312E81)))).padding(18.dp),
                ) {
                    Column(verticalArrangement = Arrangement.spacedBy(12.dp)) {
                        Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                            Icon(Icons.Rounded.SmartToy, null, tint = Color.White, modifier = Modifier.size(30.dp))
                            Spacer(Modifier.width(10.dp))
                            Column(Modifier.weight(1f)) {
                                Text("$name Auto Trader", color = Color.White, fontWeight = FontWeight.Black, style = MaterialTheme.typography.titleMedium)
                                Text(if (current.bot && current.live && !kill) "در حال اجرای خودکار" else "در حالت آماده‌باش", color = Color(0xFFD1D5DB))
                            }
                            V2MiniStatus(if (current.bot && current.live && !kill) "ACTIVE" else "PAUSED", current.bot && current.live && !kill)
                        }
                        if (name == "Nobitex") {
                            Text(if (intelligenceReady) "Portfolio Intelligence v2 + Smart Candidate Fallback" else "Intelligence capability در Backend دیده نشد", color = Color(0xFFE9D5FF), style = MaterialTheme.typography.bodySmall)
                        }
                    }
                }
            }
        }
        if (name == "Nobitex" && current.maxPositions > 0) item { V2CapacityCard(current) }
        item { V2ToggleCard("Auto Trading $name", "اجازه اجرای خودکار در Cron", current.bot, Icons.Rounded.SmartToy, V2Primary, current.credentials && !loading, onBot) }
        item { V2ToggleCard("Live Execution $name", "اجازه ارسال سفارش واقعی به صرافی", current.live, Icons.Rounded.Bolt, V2Success, current.credentials && !loading, onLive) }
        item { V2ToggleCard("Kill Switch سراسری", "توقف فوری سفارش‌های جدید هر دو صرافی", kill, Icons.Rounded.PowerSettingsNew, V2Danger, !loading, onKill) }
        item {
            Button(
                onClick = onRun,
                enabled = !loading && current.credentials && current.bot && current.live && !kill,
                modifier = Modifier.fillMaxWidth().height(54.dp),
                shape = RoundedCornerShape(17.dp),
            ) {
                Icon(Icons.Rounded.PlayArrow, null)
                Spacer(Modifier.width(8.dp))
                Text("اجرای یک چرخه اکنون", fontWeight = FontWeight.Bold)
            }
        }
        item { V2Insight("آخرین تصمیم $name", current.lastDecision, Icons.Rounded.AutoGraph, V2Primary) }
        item { V2Insight("آخرین سفارش", current.latestOrder, Icons.Rounded.SwapHoriz, V2Blue) }
        item { V2Insight("آخرین چرخه", lastRun, Icons.Rounded.Refresh, V2Cyan) }
        item {
            Card(shape = RoundedCornerShape(22.dp), colors = CardDefaults.cardColors(containerColor = Color.White)) {
                Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    Text("System Matrix", fontWeight = FontWeight.Black)
                    Text(cronText, color = V2Muted)
                    HorizontalDivider(color = V2Stroke)
                    V2SystemRow("Nobitex", nobitex)
                    V2SystemRow("Bitpin", bitpin)
                }
            }
        }
    }
}

@Composable
private fun V2Settings(
    appVersion: String,
    backendVersion: String,
    updateState: UpdateUiState,
    onUpdate: () -> Unit,
    onDisconnect: () -> Unit,
) {
    LazyColumn(Modifier.fillMaxSize(), contentPadding = PaddingValues(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
        item {
            Text("تنظیمات و Release", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Black)
            Text("نسخه هماهنگ App + Backend", color = V2Muted)
        }
        item {
            Card(shape = RoundedCornerShape(23.dp), colors = CardDefaults.cardColors(containerColor = Color.White)) {
                Column(Modifier.padding(17.dp), verticalArrangement = Arrangement.spacedBy(13.dp)) {
                    V2SettingRow(Icons.Rounded.Update, "نسخه اپ", "v$appVersion")
                    HorizontalDivider(color = V2Stroke)
                    V2SettingRow(Icons.Rounded.CloudDone, "نسخه Backend", "v$backendVersion")
                    HorizontalDivider(color = V2Stroke)
                    V2SettingRow(Icons.Rounded.Security, "API Contract", "${updateState.backendApiContract ?: "?"}/${ReleaseContract.API_CONTRACT}")
                }
            }
        }
        item {
            V2InfoBanner(
                Icons.Rounded.Update,
                if (updateState.versionsSynchronized) "Release هماهنگ است" else "وضعیت Release",
                updateState.lastResult,
                if (updateState.versionsSynchronized) V2Success else V2Primary,
                if (updateState.versionsSynchronized) V2SuccessSoft else V2Soft,
            )
        }
        item {
            Button(onClick = onUpdate, enabled = !updateState.checking, modifier = Modifier.fillMaxWidth().height(52.dp), shape = RoundedCornerShape(16.dp)) {
                if (updateState.checking) CircularProgressIndicator(Modifier.size(18.dp), strokeWidth = 2.dp) else Icon(Icons.Rounded.Refresh, null)
                Spacer(Modifier.width(8.dp))
                Text(if (updateState.checking) "در حال بررسی…" else "بررسی نسخه جدید", fontWeight = FontWeight.Bold)
            }
        }
        item {
            OutlinedButton(onClick = onDisconnect, modifier = Modifier.fillMaxWidth().height(50.dp), shape = RoundedCornerShape(16.dp)) {
                Icon(Icons.Rounded.Logout, null)
                Spacer(Modifier.width(8.dp))
                Text("قطع اتصال این گوشی")
            }
        }
        item { Text("Build ${BuildConfig.VERSION_NAME} (${BuildConfig.VERSION_CODE})", color = V2Muted, style = MaterialTheme.typography.bodySmall) }
    }
}

@Composable
private fun V2SystemRow(name: String, s: V2ExchangeState) {
    Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
        Column(Modifier.weight(1f)) {
            Text(name, fontWeight = FontWeight.Bold)
            Text("${s.activePositions}${if (s.maxPositions > 0) "/${s.maxPositions}" else ""} پوزیشن • Pending ${s.pendingOrders}/${s.maxPendingOrders}", color = V2Muted, style = MaterialTheme.typography.bodySmall)
        }
        V2MiniStatus(if (s.bot && s.live) "LIVE" else if (s.credentials) "READY" else "OFF", s.credentials && s.bot && s.live)
    }
}

@Composable
private fun V2Kpi(title: String, value: String, good: Boolean, icon: ImageVector, modifier: Modifier = Modifier) {
    Card(modifier, shape = RoundedCornerShape(20.dp), colors = CardDefaults.cardColors(containerColor = Color.White)) {
        Column(Modifier.padding(13.dp), verticalArrangement = Arrangement.spacedBy(7.dp)) {
            Icon(icon, null, tint = if (good) V2Success else V2Danger, modifier = Modifier.size(20.dp))
            Text(title, color = V2Muted, style = MaterialTheme.typography.labelSmall, maxLines = 1)
            Text(value, color = if (good) V2Success else V2Danger, fontWeight = FontWeight.Black, maxLines = 1, overflow = TextOverflow.Ellipsis)
        }
    }
}

@Composable
private fun V2SmallMetric(title: String, value: String, modifier: Modifier = Modifier) {
    Box(modifier.clip(RoundedCornerShape(14.dp)).background(Color(0xFFF7F7FA)).padding(10.dp)) {
        Column {
            Text(title, color = V2Muted, style = MaterialTheme.typography.labelSmall)
            Text(value, color = V2Ink, fontWeight = FontWeight.Black)
        }
    }
}

@Composable
private fun V2QuickAction(title: String, subtitle: String, icon: ImageVector, tone: Color, onClick: () -> Unit, modifier: Modifier = Modifier) {
    FilledTonalButton(
        onClick = onClick,
        modifier = modifier.height(102.dp),
        shape = RoundedCornerShape(21.dp),
        colors = ButtonDefaults.filledTonalButtonColors(containerColor = tone.copy(alpha = 0.10f), contentColor = tone),
        contentPadding = PaddingValues(10.dp),
    ) {
        Column(horizontalAlignment = Alignment.CenterHorizontally) {
            Icon(icon, null, modifier = Modifier.size(25.dp))
            Spacer(Modifier.height(7.dp))
            Text(title, fontWeight = FontWeight.Black, maxLines = 1)
            Text(subtitle, style = MaterialTheme.typography.labelSmall, maxLines = 1, overflow = TextOverflow.Ellipsis)
        }
    }
}

@Composable
private fun V2Insight(title: String, text: String, icon: ImageVector, tone: Color) {
    Card(shape = RoundedCornerShape(20.dp), colors = CardDefaults.cardColors(containerColor = Color.White)) {
        Row(Modifier.fillMaxWidth().padding(15.dp), verticalAlignment = Alignment.Top) {
            Box(Modifier.size(38.dp).clip(RoundedCornerShape(13.dp)).background(tone.copy(alpha = 0.10f)), contentAlignment = Alignment.Center) {
                Icon(icon, null, tint = tone, modifier = Modifier.size(20.dp))
            }
            Spacer(Modifier.width(10.dp))
            Column(Modifier.weight(1f)) {
                Text(title, fontWeight = FontWeight.Black)
                Spacer(Modifier.height(4.dp))
                Text(text, color = V2Muted, style = MaterialTheme.typography.bodySmall)
            }
        }
    }
}

@Composable
private fun V2ToggleCard(title: String, text: String, checked: Boolean, icon: ImageVector, tone: Color, enabled: Boolean, onChange: (Boolean) -> Unit) {
    Card(shape = RoundedCornerShape(21.dp), colors = CardDefaults.cardColors(containerColor = Color.White)) {
        Row(Modifier.fillMaxWidth().padding(15.dp), verticalAlignment = Alignment.CenterVertically) {
            Box(Modifier.size(42.dp).clip(RoundedCornerShape(14.dp)).background(tone.copy(alpha = 0.10f)), contentAlignment = Alignment.Center) {
                Icon(icon, null, tint = tone)
            }
            Spacer(Modifier.width(11.dp))
            Column(Modifier.weight(1f)) {
                Text(title, fontWeight = FontWeight.Black)
                Text(text, color = V2Muted, style = MaterialTheme.typography.bodySmall)
            }
            Switch(checked = checked, onCheckedChange = onChange, enabled = enabled)
        }
    }
}

@Composable
private fun V2InfoBanner(icon: ImageVector, title: String, text: String, tone: Color, background: Color) {
    Card(Modifier.fillMaxWidth(), shape = RoundedCornerShape(19.dp), colors = CardDefaults.cardColors(containerColor = background)) {
        Row(Modifier.padding(14.dp), verticalAlignment = Alignment.Top) {
            Icon(icon, null, tint = tone, modifier = Modifier.size(22.dp))
            Spacer(Modifier.width(9.dp))
            Column(Modifier.weight(1f)) {
                Text(title, color = V2Ink, fontWeight = FontWeight.Bold)
                Spacer(Modifier.height(2.dp))
                Text(text, color = V2Muted, style = MaterialTheme.typography.bodySmall)
            }
        }
    }
}

@Composable
private fun V2Choice(title: String, selected: Boolean, tone: Color, modifier: Modifier = Modifier, onClick: () -> Unit) {
    FilledTonalButton(
        onClick = onClick,
        modifier = modifier,
        shape = RoundedCornerShape(14.dp),
        colors = ButtonDefaults.filledTonalButtonColors(
            containerColor = if (selected) tone.copy(alpha = 0.14f) else Color(0xFFF7F7FA),
            contentColor = if (selected) tone else V2Muted,
        ),
    ) { Text(title, fontWeight = if (selected) FontWeight.Black else FontWeight.Medium) }
}

@Composable
private fun V2MiniStatus(text: String, ok: Boolean) {
    Surface(shape = RoundedCornerShape(100.dp), color = if (ok) V2SuccessSoft else V2WarningSoft) {
        Text(text, Modifier.padding(horizontal = 9.dp, vertical = 5.dp), color = if (ok) V2Success else V2Warning, style = MaterialTheme.typography.labelSmall, fontWeight = FontWeight.Black)
    }
}

@Composable
private fun V2DarkPill(text: String) {
    Surface(shape = RoundedCornerShape(100.dp), color = Color.White.copy(alpha = 0.12f)) {
        Text(text, Modifier.padding(horizontal = 9.dp, vertical = 6.dp), color = Color.White, style = MaterialTheme.typography.labelSmall, fontWeight = FontWeight.Bold)
    }
}

@Composable
private fun V2Progress(progress: Float) {
    Box(Modifier.fillMaxWidth().height(9.dp).clip(RoundedCornerShape(100.dp)).background(Color(0xFFEDEEF3))) {
        Box(
            Modifier.fillMaxHeight().fillMaxWidth(progress.coerceIn(0f, 1f)).clip(RoundedCornerShape(100.dp))
                .background(Brush.horizontalGradient(listOf(V2Primary, V2Cyan))),
        )
    }
}

@Composable
private fun V2SectionHeader(title: String, caption: String) {
    Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
        Text(title, fontWeight = FontWeight.Black, style = MaterialTheme.typography.titleMedium, modifier = Modifier.weight(1f))
        Text(caption, color = V2Muted, style = MaterialTheme.typography.labelSmall)
    }
    Spacer(Modifier.height(8.dp))
}

@Composable
private fun V2SettingRow(icon: ImageVector, title: String, value: String) {
    Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
        Box(Modifier.size(39.dp).clip(RoundedCornerShape(13.dp)).background(V2Soft), contentAlignment = Alignment.Center) {
            Icon(icon, null, tint = V2Primary, modifier = Modifier.size(20.dp))
        }
        Spacer(Modifier.width(10.dp))
        Text(title, Modifier.weight(1f), fontWeight = FontWeight.Bold)
        Text(value, color = V2Muted)
    }
}

@Composable
private fun V2Empty(text: String) {
    Card(shape = RoundedCornerShape(22.dp), colors = CardDefaults.cardColors(containerColor = Color.White)) {
        Column(Modifier.fillMaxWidth().padding(24.dp), horizontalAlignment = Alignment.CenterHorizontally) {
            Icon(Icons.Rounded.AccountBalanceWallet, null, tint = Color(0xFFB0B6C3), modifier = Modifier.size(34.dp))
            Spacer(Modifier.height(8.dp))
            Text(text, color = V2Muted)
        }
    }
}

private fun v2Wallets(body: String): List<V2WalletRow> {
    return try {
        val root = JSONObject(body)
        val data = root.opt("data")
        val arr: JSONArray? = when (data) {
            is JSONArray -> data
            is JSONObject -> data.optJSONArray("wallets") ?: data.optJSONArray("items")
            else -> root.optJSONArray("wallets")
        }
        if (arr == null) return emptyList()
        buildList {
            for (i in 0 until arr.length()) {
                val o = arr.optJSONObject(i) ?: continue
                val code = o.optString("currency", o.optString("asset", o.optString("code", ""))).uppercase()
                if (code.isBlank()) continue
                val balance = listOf("activeBalance", "available", "free", "balance")
                    .firstNotNullOfOrNull { key -> o.opt(key)?.toString()?.toDoubleOrNull() } ?: 0.0
                val rial = listOf("rial_value_toman", "toman_value", "estimated_toman_value", "value_toman")
                    .firstNotNullOfOrNull { key -> o.opt(key)?.toString()?.toDoubleOrNull() } ?: 0.0
                add(V2WalletRow(code, balance, rial))
            }
        }.sortedByDescending { it.rialValueToman }
    } catch (_: Exception) { emptyList() }
}

private fun v2SignalText(o: JSONObject?): String {
    if (o == null || o.length() == 0) return "هنوز سیگنال ثبت نشده"
    val symbol = o.optString("symbol", "—")
    val action = o.optString("action", "hold")
    val details = o.optJSONObject("details") ?: runCatching { JSONObject(o.optString("details_json", "{}")) }.getOrNull()
    val edge = details?.optDouble("tradable_net_edge_percent", Double.NaN) ?: Double.NaN
    return buildString {
        append(symbol)
        append(" • ")
        append(when (action.lowercase()) { "buy" -> "BUY"; "sell" -> "SELL"; else -> "HOLD" })
        if (edge.isFinite()) append(" • Edge ${v2One(edge)}%")
    }
}

private fun v2DecisionText(o: JSONObject?): String {
    if (o == null || o.length() == 0) return "هنوز تصمیم ثبت نشده"
    val status = o.optString("status", "—")
    val reason = o.optString("reason", "")
    val selected = o.optJSONObject("selected")
    return buildString {
        append(v2StatusFa(status))
        if (selected != null && selected.optString("symbol").isNotBlank()) append(" • ${selected.optString("symbol")}")
        if (reason.isNotBlank()) append(" • ${v2ReasonFa(reason)}")
    }
}

private fun v2OrderText(o: JSONObject?): String {
    if (o == null || o.length() == 0) return "هنوز سفارشی ثبت نشده"
    val market = o.optString("market_code", o.optString("symbol", "—"))
    val side = o.optString("side", o.optString("type", "—"))
    val status = o.optString("status", "—")
    return "$market • ${side.uppercase()} • $status"
}

private fun v2RunText(o: JSONObject?): String {
    if (o == null || o.length() == 0) return "هنوز چرخه‌ای ثبت نشده"
    val status = o.optString("status", "—")
    val reason = o.optString("reason", "")
    val selected = o.optJSONObject("selected")?.optString("symbol", "").orEmpty()
    val intelligence = o.optJSONObject("intelligence")
    val rejected = intelligence?.optJSONObject("smart_candidate_fallback")?.optJSONArray("rejected_candidates")?.length() ?: 0
    return buildString {
        append(v2StatusFa(status))
        if (selected.isNotBlank()) append(" • $selected")
        if (rejected > 0) append(" • Fallback از $rejected گزینه")
        if (reason.isNotBlank()) append(" • ${v2ReasonFa(reason)}")
    }
}

private fun v2StatusFa(s: String): String = when (s) {
    "buy_submitted", "first_buy_submitted" -> "خرید ارسال شد"
    "sell_submitted" -> "فروش ارسال شد"
    "profit_actions_processed" -> "اقدامات معاملاتی پردازش شد"
    "waiting_order" -> "در انتظار سفارش"
    "portfolio_full" -> "پرتفو تکمیل است"
    "no_trade" -> "بدون معامله"
    "blocked" -> "مسدود"
    "success" -> "موفق"
    else -> s
}

private fun v2ReasonFa(s: String): String = when (s) {
    "smart_candidate_fallback_exhausted" -> "گزینه‌های جایگزین این Tick تمام شدند"
    "portfolio_intelligence_buy_blocked" -> "Portfolio Intelligence خرید را متوقف کرد"
    "portfolio_correlation_cluster_limit" -> "همبستگی پرتفو بیش از حد است"
    "strategy_profile_underperforming" -> "پروفایل استراتژی عملکرد ضعیف دارد"
    "pending_order_capacity_reached" -> "ظرفیت Pending تکمیل است"
    "portfolio_exposure_limit_reached" -> "سقف Exposure تکمیل است"
    "no_candidate_passed_signal_and_risk_filters" -> "هیچ Candidate همه فیلترها را پاس نکرد"
    "no_eligible_markets" -> "بازار قابل اجرا پیدا نشد"
    "minimum_order_rounding" -> "سفارش کمتر از حداقل بازار است"
    "minimum_order_exceeds_budget" -> "حداقل سفارش از بودجه بیشتر است"
    "symbol_cooldown_active" -> "Cooldown نماد فعال است"
    "already_positioned" -> "برای این دارایی پوزیشن فعال وجود دارد"
    "kill_switch" -> "توقف اضطراری فعال است"
    "live_execution_disabled" -> "Live Execution خاموش است"
    "bot_disabled" -> "ربات خاموش است"
    else -> s.replace('_', ' ')
}

private fun v2HttpError(r: TradeApi.Response): String {
    val message = runCatching {
        val root = JSONObject(r.body)
        root.optString("message").ifBlank { root.optString("error") }
    }.getOrDefault("")
    return message.ifBlank { "HTTP ${r.code}" }
}

private fun v2Number(value: Double): String = NumberFormat.getNumberInstance(Locale.US).apply { maximumFractionDigits = 0 }.format(value)
private fun v2One(value: Double): String = String.format(Locale.US, "%.1f", value)
private fun v2Compact(value: Double): String {
    val abs = kotlin.math.abs(value)
    val suffix = when {
        abs >= 1_000_000_000 -> Pair(value / 1_000_000_000.0, "B")
        abs >= 1_000_000 -> Pair(value / 1_000_000.0, "M")
        abs >= 1_000 -> Pair(value / 1_000.0, "K")
        else -> Pair(value, "")
    }
    return String.format(Locale.US, "%.2f%s", suffix.first, suffix.second)
}
