package ir.trade.app.ui

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.AccountBalanceWallet
import androidx.compose.material.icons.rounded.AutoGraph
import androidx.compose.material.icons.rounded.CheckCircle
import androidx.compose.material.icons.rounded.CloudDone
import androidx.compose.material.icons.rounded.Dashboard
import androidx.compose.material.icons.rounded.Logout
import androidx.compose.material.icons.rounded.PlayArrow
import androidx.compose.material.icons.rounded.PowerSettingsNew
import androidx.compose.material.icons.rounded.Refresh
import androidx.compose.material.icons.rounded.Security
import androidx.compose.material.icons.rounded.Settings
import androidx.compose.material.icons.rounded.SmartToy
import androidx.compose.material.icons.rounded.SwapHoriz
import androidx.compose.material.icons.rounded.Update
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import ir.trade.app.BuildConfig
import ir.trade.app.data.TradeApi
import ir.trade.app.data.TradePreferences
import ir.trade.app.update.AppUpdateManager
import kotlinx.coroutines.launch
import org.json.JSONArray
import org.json.JSONObject
import java.text.NumberFormat
import java.util.Locale

private val AppBg = Color(0xFFF6F7FB)
private val CardBg = Color(0xFFFFFFFF)
private val Ink = Color(0xFF171A24)
private val Muted = Color(0xFF73798B)
private val Primary = Color(0xFF6941FF)
private val PrimarySoft = Color(0xFFF0EDFF)
private val Success = Color(0xFF0D966F)
private val SuccessSoft = Color(0xFFEAF8F3)
private val Danger = Color(0xFFD14343)
private val DangerSoft = Color(0xFFFFF0F0)
private val Warning = Color(0xFFB46A00)
private val WarningSoft = Color(0xFFFFF7E5)
private val Stroke = Color(0xFFE7E9F0)

data class ExchangeUiState(
    val credentials: Boolean = false,
    val bot: Boolean = false,
    val live: Boolean = false,
    val activePositions: Int = 0,
    val maxPositions: Int = 0,
    val remainingPositionSlots: Int = 0,
    val effectivePositionPercent: Double = 0.0,
    val exposureLimitPercent: Double = 0.0,
    val pendingOrders: Int = 0,
    val maxPendingOrders: Int = 0,
    val pendingTimeoutSeconds: Int = 0,
    val pnlToday: Double = 0.0,
    val pnlTotal: Double = 0.0,
    val winRate: Double = 0.0,
    val quote: String = "IRT",
    val latestSignal: String = "هنوز سیگنالی ثبت نشده",
    val lastDecision: String = "هنوز تصمیمی ثبت نشده",
    val latestOrder: String = "هنوز سفارشی ثبت نشده",
)

data class WalletRow(
    val code: String,
    val balance: Double,
    val rialValueToman: Double,
)

private data class NavItem(val title: String, val icon: ImageVector)

@Composable
fun TradeApp() {
    MaterialTheme(
        colorScheme = lightColorScheme(
            primary = Primary,
            secondary = Success,
            background = AppBg,
            surface = CardBg,
            onBackground = Ink,
            onSurface = Ink,
            error = Danger,
        ),
    ) {
        val context = LocalContext.current
        val prefs = remember { TradePreferences(context) }
        var configured by remember { mutableStateOf(prefs.isConfigured()) }
        Surface(Modifier.fillMaxSize(), color = AppBg) {
            if (!configured) SetupScreen(prefs) { configured = true }
            else DashboardShell(prefs) { prefs.clear(); configured = false }
        }
    }
}

@Composable
private fun SetupScreen(prefs: TradePreferences, onSaved: () -> Unit) {
    var server by rememberSaveable { mutableStateOf(prefs.serverUrl()) }
    var token by rememberSaveable { mutableStateOf(prefs.apiToken()) }
    var error by rememberSaveable { mutableStateOf("") }

    Box(
        Modifier.fillMaxSize().statusBarsPadding().navigationBarsPadding().padding(20.dp),
        contentAlignment = Alignment.Center,
    ) {
        Card(
            Modifier.fillMaxWidth(),
            shape = RoundedCornerShape(28.dp),
            colors = CardDefaults.cardColors(containerColor = CardBg),
        ) {
            Column(Modifier.padding(24.dp), verticalArrangement = Arrangement.spacedBy(14.dp)) {
                BrandHeader("اتصال امن به موتور معاملات")
                InfoCard(
                    Icons.Rounded.Security,
                    "کلید صرافی داخل گوشی نیست",
                    "اپ فقط به Backend شخصی وصل می‌شود و کلیدهای صرافی روی سرور باقی می‌مانند.",
                )
                OutlinedTextField(server, { server = it }, label = { Text("آدرس سرور") }, singleLine = true, modifier = Modifier.fillMaxWidth())
                OutlinedTextField(
                    token,
                    { token = it },
                    label = { Text("App API Token") },
                    singleLine = true,
                    visualTransformation = PasswordVisualTransformation(),
                    modifier = Modifier.fillMaxWidth(),
                )
                if (error.isNotBlank()) MessageCard(error, true)
                Button(
                    onClick = {
                        try { prefs.save(server.trim(), token.trim()); onSaved() }
                        catch (_: IllegalArgumentException) { error = "آدرس سرور باید HTTPS باشد." }
                    },
                    enabled = server.isNotBlank() && token.isNotBlank(),
                    modifier = Modifier.fillMaxWidth().height(52.dp),
                    shape = RoundedCornerShape(15.dp),
                ) { Text("اتصال به Trade", fontWeight = FontWeight.Bold) }
            }
        }
    }
}

@Composable
private fun DashboardShell(prefs: TradePreferences, onDisconnect: () -> Unit) {
    val scope = rememberCoroutineScope()
    val context = LocalContext.current
    val api = remember(prefs.serverUrl(), prefs.apiToken()) { TradeApi(prefs.serverUrl(), prefs.apiToken()) }
    val updateState = LocalUpdateUiState.current
    val requestUpdate = LocalRequestUpdateCheck.current

    var tab by rememberSaveable { mutableIntStateOf(0) }
    var exchange by rememberSaveable { mutableStateOf("nobitex") }
    var bitpin by remember { mutableStateOf(ExchangeUiState()) }
    var nobitex by remember { mutableStateOf(ExchangeUiState()) }
    var killSwitch by remember { mutableStateOf(false) }
    var cronHealthy by remember { mutableStateOf(false) }
    var cronText by remember { mutableStateOf("در حال بررسی Cron…") }
    var backendVersion by remember { mutableStateOf("-") }
    var loading by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf("") }
    var message by remember { mutableStateOf("") }
    var wallets by remember { mutableStateOf<List<WalletRow>>(emptyList()) }
    var lastNobitexRun by remember { mutableStateOf("هنوز داده‌ای نیست") }
    var lastBitpinRun by remember { mutableStateOf("هنوز داده‌ای نیست") }

    var symbol by rememberSaveable { mutableStateOf("BTCIRT") }
    var amount by rememberSaveable { mutableStateOf("") }
    var price by rememberSaveable { mutableStateOf("") }
    var side by rememberSaveable { mutableStateOf("buy") }
    var mode by rememberSaveable { mutableStateOf("market") }
    var cancelId by rememberSaveable { mutableStateOf("") }

    val current = if (exchange == "nobitex") nobitex else bitpin
    val exchangeTitle = if (exchange == "nobitex") "Nobitex" else "Bitpin"

    fun parseExchangeState(data: JSONObject, name: String): ExchangeUiState {
        val x = data.optJSONObject("exchanges")?.optJSONObject(name) ?: JSONObject()
        val performance = x.optJSONObject("performance") ?: JSONObject()
        val capacity = x.optJSONObject("portfolio_capacity") ?: JSONObject()
        return ExchangeUiState(
            credentials = x.optBoolean("credentials_configured"),
            bot = x.optBoolean("bot_enabled"),
            live = x.optBoolean("live_execution_enabled"),
            activePositions = x.optInt("active_position_count", 0),
            maxPositions = capacity.optInt("max_positions", 0),
            remainingPositionSlots = capacity.optInt("remaining_position_slots", 0),
            effectivePositionPercent = capacity.optDouble("effective_position_percent", 0.0),
            exposureLimitPercent = capacity.optDouble("portfolio_exposure_limit_percent", 0.0),
            pendingOrders = capacity.optInt("pending_orders", 0),
            maxPendingOrders = capacity.optInt("max_pending_orders", 0),
            pendingTimeoutSeconds = capacity.optInt("pending_timeout_seconds", 0),
            pnlToday = performance.optDouble("today_realized_pnl", 0.0),
            pnlTotal = performance.optDouble("total_realized_pnl", 0.0),
            winRate = performance.optDouble("win_rate_percent", 0.0),
            quote = performance.optString("quote_asset", "IRT"),
            latestSignal = signalDescription(x.optJSONObject("latest_signal")),
            lastDecision = decisionDescription(x.optJSONObject("last_decision")),
            latestOrder = orderDescription(x.optJSONObject("latest_order")),
        )
    }

    fun refreshStatus() {
        scope.launch {
            loading = true
            error = ""
            try {
                val response = api.status()
                if (!response.ok) throw IllegalStateException(readableHttpError(response))
                val data = JSONObject(response.body).getJSONObject("data")
                backendVersion = data.optString("backend_version", "-")
                killSwitch = data.optBoolean("kill_switch")
                val health = data.optJSONObject("cron_health")
                cronHealthy = health?.optBoolean("healthy") == true
                val age = health?.optLong("age_seconds", -1L) ?: -1L
                cronText = when {
                    cronHealthy && age >= 0 -> "Cron سالم • ${age} ثانیه از آخرین اجرا"
                    age >= 0 -> "Cron نیازمند بررسی • ${age} ثانیه از آخرین اجرا"
                    else -> "هنوز اجرای Cron ثبت نشده"
                }
                bitpin = parseExchangeState(data, "bitpin")
                nobitex = parseExchangeState(data, "nobitex")
                val summary = data.optJSONObject("last_run")?.optJSONObject("summary")
                val runs = summary?.optJSONObject("exchanges")
                lastNobitexRun = runDescription(runs?.optJSONObject("nobitex"))
                lastBitpinRun = runDescription(runs?.optJSONObject("bitpin"))
            } catch (e: Exception) {
                error = e.message ?: "خطا در دریافت وضعیت Backend"
            } finally { loading = false }
        }
    }

    fun refreshWallet() {
        scope.launch {
            error = ""
            try {
                val response = api.wallets(exchange)
                if (!response.ok) throw IllegalStateException(readableHttpError(response))
                wallets = parseWalletRows(response.body)
            } catch (e: Exception) {
                error = e.message ?: "دریافت کیف پول ناموفق بود"
            }
        }
    }

    fun selectExchange(value: String) {
        exchange = value
        symbol = if (value == "nobitex") "BTCIRT" else "BTC_IRT"
        message = ""
        error = ""
        wallets = emptyList()
        refreshWallet()
    }

    fun submitOrder() {
        val a = amount.toDoubleOrNull()
        val p = price.toDoubleOrNull()
        if (symbol.isBlank() || a == null || a <= 0 || (mode != "market" && (p == null || p <= 0))) {
            error = "نماد بازار و مقدار سفارش را درست وارد کن."
            return
        }
        scope.launch {
            loading = true
            error = ""
            message = ""
            try {
                val payload = JSONObject()
                    .put("exchange", exchange)
                    .put("symbol", symbol.trim().uppercase())
                    .put("amount1", a)
                    .put("mode", mode)
                    .put("type", side)
                if (p != null && p > 0) payload.put("price", p)
                val response = api.createOrder(payload.toString())
                if (!response.ok) throw IllegalStateException(readableHttpError(response))
                message = "سفارش واقعی در $exchangeTitle ارسال شد."
                refreshStatus()
                refreshWallet()
            } catch (e: Exception) {
                error = e.message ?: "ثبت سفارش ناموفق بود"
            } finally { loading = false }
        }
    }

    fun cancelOrder() {
        if (cancelId.isBlank()) { error = "شناسه سفارش را وارد کن."; return }
        scope.launch {
            loading = true
            error = ""
            message = ""
            try {
                val response = api.cancelOrder(cancelId.trim(), exchange)
                if (!response.ok) throw IllegalStateException(readableHttpError(response))
                message = "درخواست لغو ارسال شد."
                cancelId = ""
                refreshStatus()
            } catch (e: Exception) { error = e.message ?: "لغو سفارش ناموفق بود" }
            finally { loading = false }
        }
    }

    fun setBot(enabled: Boolean) {
        scope.launch {
            loading = true
            error = ""
            try {
                val r = api.setExchangeBot(exchange, enabled)
                if (!r.ok) throw IllegalStateException(readableHttpError(r))
                message = "ربات $exchangeTitle ${if (enabled) "فعال" else "غیرفعال"} شد."
                refreshStatus()
            } catch (e: Exception) { error = e.message ?: "تغییر وضعیت ربات ناموفق بود" }
            finally { loading = false }
        }
    }

    fun setLive(enabled: Boolean) {
        scope.launch {
            loading = true
            error = ""
            try {
                val r = api.setExchangeLive(exchange, enabled)
                if (!r.ok) throw IllegalStateException(readableHttpError(r))
                message = "معاملات واقعی $exchangeTitle ${if (enabled) "فعال" else "غیرفعال"} شد."
                refreshStatus()
            } catch (e: Exception) { error = e.message ?: "تغییر Live ناموفق بود" }
            finally { loading = false }
        }
    }

    fun setKill(enabled: Boolean) {
        scope.launch {
            loading = true
            error = ""
            try {
                val r = api.setKillSwitch(enabled)
                if (!r.ok) throw IllegalStateException(readableHttpError(r))
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
            error = ""
            message = ""
            try {
                val r = api.runExchange(exchange)
                if (!r.ok) throw IllegalStateException(readableHttpError(r))
                val result = JSONObject(r.body).optJSONObject("data")
                message = "چرخه $exchangeTitle: ${runDescription(result)}"
                refreshStatus()
                refreshWallet()
            } catch (e: Exception) { error = e.message ?: "اجرای چرخه ناموفق بود" }
            finally { loading = false }
        }
    }

    LaunchedEffect(Unit) {
        refreshStatus()
        refreshWallet()
    }

    val nav = listOf(
        NavItem("خانه", Icons.Rounded.Dashboard),
        NavItem("دارایی‌ها", Icons.Rounded.AccountBalanceWallet),
        NavItem("معامله", Icons.Rounded.SwapHoriz),
        NavItem("ربات", Icons.Rounded.SmartToy),
        NavItem("تنظیمات", Icons.Rounded.Settings),
    )

    Scaffold(
        containerColor = AppBg,
        bottomBar = {
            NavigationBar(containerColor = Color.White, tonalElevation = 3.dp) {
                nav.forEachIndexed { index, item ->
                    NavigationBarItem(
                        selected = tab == index,
                        onClick = { tab = index },
                        icon = { Icon(item.icon, contentDescription = item.title) },
                        label = { Text(item.title, maxLines = 1) },
                        colors = NavigationBarItemDefaults.colors(
                            selectedIconColor = Primary,
                            selectedTextColor = Primary,
                            indicatorColor = PrimarySoft,
                            unselectedIconColor = Muted,
                            unselectedTextColor = Muted,
                        ),
                    )
                }
            }
        },
    ) { padding ->
        Column(Modifier.fillMaxSize().padding(padding).statusBarsPadding()) {
            AppTopBar(
                appVersion = AppUpdateManager.currentVersionName(context),
                backendVersion = backendVersion,
                exchange = exchange,
                current = current,
                cronHealthy = cronHealthy,
                onSelectExchange = { selectExchange(it) },
                onRefresh = { refreshStatus(); refreshWallet() },
                loading = loading,
            )

            if (error.isNotBlank()) Box(Modifier.padding(horizontal = 14.dp, vertical = 6.dp)) { MessageCard(error, true) }
            if (message.isNotBlank()) Box(Modifier.padding(horizontal = 14.dp, vertical = 6.dp)) { MessageCard(message, false) }

            Box(Modifier.weight(1f)) {
                when (tab) {
                    0 -> HomeScreen(
                        exchangeTitle = exchangeTitle,
                        current = current,
                        killSwitch = killSwitch,
                        cronText = cronText,
                        cronHealthy = cronHealthy,
                        wallets = wallets,
                        lastRun = if (exchange == "nobitex") lastNobitexRun else lastBitpinRun,
                        onAssets = { tab = 1 },
                        onTrade = { tab = 2 },
                        onBot = { tab = 3 },
                    )
                    1 -> AssetsScreen(exchangeTitle, wallets, loading, onRefresh = { refreshWallet() })
                    2 -> TradeScreen(
                        name = exchangeTitle,
                        state = current,
                        kill = killSwitch,
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
                        onSubmit = { submitOrder() },
                        onCancel = { cancelOrder() },
                    )
                    3 -> BotScreen(
                        name = exchangeTitle,
                        current = current,
                        bitpin = bitpin,
                        nobitex = nobitex,
                        kill = killSwitch,
                        loading = loading,
                        cronText = cronText,
                        lastRun = if (exchange == "nobitex") lastNobitexRun else lastBitpinRun,
                        onBot = { setBot(it) },
                        onLive = { setLive(it) },
                        onKill = { setKill(it) },
                        onRunNow = { runNow() },
                    )
                    else -> SettingsScreen(
                        appVersion = "${BuildConfig.VERSION_NAME} (${BuildConfig.VERSION_CODE})",
                        backendVersion = backendVersion,
                        updateState = updateState,
                        onCheckUpdate = requestUpdate,
                        onDisconnect = onDisconnect,
                    )
                }
            }
        }
    }
}

@Composable
private fun AppTopBar(
    appVersion: String,
    backendVersion: String,
    exchange: String,
    current: ExchangeUiState,
    cronHealthy: Boolean,
    onSelectExchange: (String) -> Unit,
    onRefresh: () -> Unit,
    loading: Boolean,
) {
    Column(
        Modifier.fillMaxWidth().background(Color.White).padding(horizontal = 16.dp, vertical = 12.dp),
        verticalArrangement = Arrangement.spacedBy(10.dp),
    ) {
        Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
            Surface(shape = RoundedCornerShape(15.dp), color = Primary) {
                Text("T", Modifier.padding(horizontal = 14.dp, vertical = 9.dp), color = Color.White, fontWeight = FontWeight.Black)
            }
            Spacer(Modifier.width(10.dp))
            Column(Modifier.weight(1f)) {
                Text("Trade", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.ExtraBold)
                Text("App v$appVersion  •  Backend v$backendVersion", color = Muted, style = MaterialTheme.typography.labelMedium)
            }
            StatusDot(cronHealthy)
            IconButton(onClick = onRefresh, enabled = !loading) {
                Icon(Icons.Rounded.Refresh, contentDescription = "بروزرسانی", tint = Primary)
            }
        }
        Row(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalAlignment = Alignment.CenterVertically) {
            FilterChip(
                selected = exchange == "nobitex",
                onClick = { onSelectExchange("nobitex") },
                label = { Text("Nobitex") },
                leadingIcon = if (exchange == "nobitex") ({ Icon(Icons.Rounded.CheckCircle, null, Modifier.size(18.dp)) }) else null,
            )
            FilterChip(selected = exchange == "bitpin", onClick = { onSelectExchange("bitpin") }, label = { Text("Bitpin") })
            Spacer(Modifier.weight(1f))
            StatusChip(if (current.credentials) "API آماده" else "API قطع", current.credentials)
        }
    }
}

@Composable
private fun HomeScreen(
    exchangeTitle: String,
    current: ExchangeUiState,
    killSwitch: Boolean,
    cronText: String,
    cronHealthy: Boolean,
    wallets: List<WalletRow>,
    lastRun: String,
    onAssets: () -> Unit,
    onTrade: () -> Unit,
    onBot: () -> Unit,
) {
    val total = wallets.sumOf { it.rialValueToman }
    LazyColumn(
        Modifier.fillMaxSize(),
        contentPadding = PaddingValues(16.dp),
        verticalArrangement = Arrangement.spacedBy(14.dp),
    ) {
        item {
            Card(shape = RoundedCornerShape(28.dp), colors = CardDefaults.cardColors(containerColor = Ink)) {
                Column(Modifier.padding(20.dp), verticalArrangement = Arrangement.spacedBy(13.dp)) {
                    Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween, verticalAlignment = Alignment.CenterVertically) {
                        Column {
                            Text("ارزش تقریبی دارایی‌ها", color = Color(0xFFBFC4D2), style = MaterialTheme.typography.labelLarge)
                            Text(
                                if (total > 0) "${formatNumber(total)} تومان" else "—",
                                color = Color.White,
                                style = MaterialTheme.typography.headlineMedium,
                                fontWeight = FontWeight.ExtraBold,
                            )
                        }
                        Surface(shape = RoundedCornerShape(17.dp), color = Color(0xFF292D39)) {
                            Icon(Icons.Rounded.AutoGraph, null, Modifier.padding(13.dp), tint = Color(0xFFB3A4FF))
                        }
                    }
                    Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                        DarkChip(exchangeTitle)
                        DarkChip(if (current.maxPositions > 0) "${current.activePositions}/${current.maxPositions} پوزیشن" else "${current.activePositions} پوزیشن")
                        DarkChip(if (killSwitch) "متوقف" else "Live")
                    }
                }
            }
        }
        item {
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(9.dp)) {
                MetricCard("پوزیشن", if (current.maxPositions > 0) "${current.activePositions}/${current.maxPositions}" else current.activePositions.toString(), true, Modifier.weight(1f))
                MetricCard("Win Rate", "${formatOne(current.winRate)}%", current.winRate >= 50.0, Modifier.weight(1f))
                MetricCard("Bot", if (current.bot) "ON" else "OFF", current.bot, Modifier.weight(1f))
            }
        }
        if (exchangeTitle == "Nobitex" && current.maxPositions > 0) {
            item {
                Card(shape = RoundedCornerShape(22.dp), colors = CardDefaults.cardColors(containerColor = PrimarySoft)) {
                    Column(Modifier.fillMaxWidth().padding(15.dp), verticalArrangement = Arrangement.spacedBy(9.dp)) {
                        Text("ظرفیت هوشمند پرتفوی", fontWeight = FontWeight.Bold)
                        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                            CapacityValue("ورود جدید", "${formatOne(current.effectivePositionPercent)}%", Modifier.weight(1f))
                            CapacityValue("Exposure", "${formatOne(current.exposureLimitPercent)}%", Modifier.weight(1f))
                            CapacityValue("Pending", "${current.pendingOrders}/${current.maxPendingOrders}", Modifier.weight(1f))
                        }
                        Text("${current.remainingPositionSlots} اسلات باقی‌مانده • Timeout سفارش Pending: ${current.pendingTimeoutSeconds} ثانیه", color = Muted, style = MaterialTheme.typography.bodySmall)
                    }
                }
            }
        }
        item {
            Card(shape = RoundedCornerShape(22.dp), colors = CardDefaults.cardColors(containerColor = if (cronHealthy) SuccessSoft else WarningSoft)) {
                Row(Modifier.fillMaxWidth().padding(15.dp), verticalAlignment = Alignment.CenterVertically) {
                    Icon(Icons.Rounded.CloudDone, null, tint = if (cronHealthy) Success else Warning)
                    Spacer(Modifier.width(10.dp))
                    Column {
                        Text("سلامت موتور", fontWeight = FontWeight.Bold)
                        Text(cronText, color = Muted, style = MaterialTheme.typography.bodySmall)
                    }
                }
            }
        }
        item {
            SectionTitle("تصمیم و سیگنال")
            Spacer(Modifier.height(7.dp))
            InsightCard("آخرین تصمیم", current.lastDecision)
            Spacer(Modifier.height(8.dp))
            InsightCard("آخرین سیگنال", current.latestSignal)
        }
        item {
            SectionTitle("دسترسی سریع")
            Spacer(Modifier.height(8.dp))
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                QuickAction("دارایی‌ها", Icons.Rounded.AccountBalanceWallet, onAssets, Modifier.weight(1f))
                QuickAction("معامله", Icons.Rounded.SwapHoriz, onTrade, Modifier.weight(1f))
                QuickAction("ربات", Icons.Rounded.SmartToy, onBot, Modifier.weight(1f))
            }
        }
        item { InsightCard("آخرین چرخه", lastRun) }
    }
}

@Composable
private fun CapacityValue(title: String, value: String, modifier: Modifier = Modifier) {
    Surface(modifier = modifier, shape = RoundedCornerShape(14.dp), color = Color.White) {
        Column(Modifier.padding(10.dp), verticalArrangement = Arrangement.spacedBy(3.dp)) {
            Text(title, color = Muted, style = MaterialTheme.typography.labelSmall)
            Text(value, color = Primary, fontWeight = FontWeight.ExtraBold)
        }
    }
}

@Composable
private fun AssetsScreen(name: String, wallets: List<WalletRow>, loading: Boolean, onRefresh: () -> Unit) {
    LazyColumn(
        Modifier.fillMaxSize(),
        contentPadding = PaddingValues(16.dp),
        verticalArrangement = Arrangement.spacedBy(10.dp),
    ) {
        item {
            Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                Column(Modifier.weight(1f)) {
                    Text("دارایی‌های $name", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.ExtraBold)
                    Text("موجودی واقعی و قابل استفاده", color = Muted)
                }
                FilledTonalIconButton(onClick = onRefresh, enabled = !loading) { Icon(Icons.Rounded.Refresh, null) }
            }
        }
        if (wallets.isEmpty()) item { EmptyCard(if (loading) "در حال دریافت کیف پول…" else "موجودی قابل نمایش پیدا نشد.") }
        else items(wallets, key = { it.code }) { AssetCard(it) }
    }
}

@Composable
private fun AssetCard(item: WalletRow) {
    Card(shape = RoundedCornerShape(20.dp), colors = CardDefaults.cardColors(containerColor = CardBg)) {
        Row(Modifier.fillMaxWidth().padding(16.dp), verticalAlignment = Alignment.CenterVertically) {
            Surface(shape = RoundedCornerShape(15.dp), color = PrimarySoft) {
                Text(item.code.take(4), Modifier.padding(horizontal = 11.dp, vertical = 10.dp), color = Primary, fontWeight = FontWeight.ExtraBold)
            }
            Spacer(Modifier.width(12.dp))
            Column(Modifier.weight(1f)) {
                Text(item.code, fontWeight = FontWeight.Bold, style = MaterialTheme.typography.titleMedium)
                if (item.rialValueToman > 0) Text("≈ ${formatNumber(item.rialValueToman)} تومان", color = Muted)
            }
            Text(formatAsset(item.balance), fontWeight = FontWeight.Bold)
        }
    }
}

@Composable
private fun TradeScreen(
    name: String,
    state: ExchangeUiState,
    kill: Boolean,
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
    val enabled = !loading && state.credentials && state.live && !kill
    LazyColumn(Modifier.fillMaxSize(), contentPadding = PaddingValues(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
        item {
            Text("معامله دستی", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.ExtraBold)
            Text("ارسال مستقیم سفارش به $name", color = Muted)
        }
        if (!enabled) item {
            InfoCard(
                Icons.Rounded.Security,
                "ارسال سفارش فعلاً قفل است",
                when {
                    kill -> "Kill Switch روشن است."
                    !state.credentials -> "API صرافی تنظیم نشده است."
                    !state.live -> "Live Execution خاموش است."
                    else -> "سیستم آماده نیست."
                },
                true,
            )
        }
        item {
            Card(shape = RoundedCornerShape(22.dp), colors = CardDefaults.cardColors(containerColor = CardBg)) {
                Column(Modifier.padding(17.dp), verticalArrangement = Arrangement.spacedBy(11.dp)) {
                    OutlinedTextField(symbol, onSymbol, label = { Text("بازار") }, supportingText = { Text(if (name == "Nobitex") "مثل BTCIRT یا ETHUSDT" else "مثل BTC_IRT") }, singleLine = true, modifier = Modifier.fillMaxWidth())
                    OutlinedTextField(amount, onAmount, label = { Text("مقدار ارز") }, singleLine = true, modifier = Modifier.fillMaxWidth())
                    Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                        FilterChip(side == "buy", { onSide("buy") }, label = { Text("خرید") })
                        FilterChip(side == "sell", { onSide("sell") }, label = { Text("فروش") })
                    }
                    Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                        FilterChip(mode == "market", { onMode("market") }, label = { Text("Market") })
                        FilterChip(mode == "limit", { onMode("limit") }, label = { Text("Limit") })
                    }
                    if (mode != "market") OutlinedTextField(price, onPrice, label = { Text("قیمت") }, singleLine = true, modifier = Modifier.fillMaxWidth())
                    Button(onClick = onSubmit, enabled = enabled, modifier = Modifier.fillMaxWidth().height(52.dp), shape = RoundedCornerShape(15.dp)) {
                        Text("ارسال سفارش واقعی", fontWeight = FontWeight.Bold)
                    }
                }
            }
        }
        item {
            Card(shape = RoundedCornerShape(22.dp), colors = CardDefaults.cardColors(containerColor = CardBg)) {
                Column(Modifier.padding(17.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
                    Text("لغو سفارش", fontWeight = FontWeight.Bold)
                    OutlinedTextField(cancelId, onCancelId, label = { Text("Order ID / Client Order ID") }, singleLine = true, modifier = Modifier.fillMaxWidth())
                    OutlinedButton(onClick = onCancel, enabled = !loading && state.credentials && cancelId.isNotBlank(), modifier = Modifier.fillMaxWidth()) { Text("لغو سفارش") }
                }
            }
        }
    }
}

@Composable
private fun BotScreen(
    name: String,
    current: ExchangeUiState,
    bitpin: ExchangeUiState,
    nobitex: ExchangeUiState,
    kill: Boolean,
    loading: Boolean,
    cronText: String,
    lastRun: String,
    onBot: (Boolean) -> Unit,
    onLive: (Boolean) -> Unit,
    onKill: (Boolean) -> Unit,
    onRunNow: () -> Unit,
) {
    LazyColumn(Modifier.fillMaxSize(), contentPadding = PaddingValues(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
        item {
            Text("ربات معاملات", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.ExtraBold)
            Text("موتور چندارزی IRT / USDT با کنترل ریسک", color = Muted)
        }
        item {
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(9.dp)) {
                MetricCard("پوزیشن", if (current.maxPositions > 0) "${current.activePositions}/${current.maxPositions}" else current.activePositions.toString(), true, Modifier.weight(1f))
                MetricCard("Win Rate", "${formatOne(current.winRate)}%", current.winRate >= 50, Modifier.weight(1f))
                MetricCard("PnL ${current.quote}", formatCompact(current.pnlTotal), current.pnlTotal >= 0, Modifier.weight(1f))
            }
        }
        if (name == "Nobitex" && current.maxPositions > 0) {
            item {
                Card(shape = RoundedCornerShape(22.dp), colors = CardDefaults.cardColors(containerColor = PrimarySoft)) {
                    Column(Modifier.fillMaxWidth().padding(15.dp), verticalArrangement = Arrangement.spacedBy(9.dp)) {
                        Text("مدیریت ظرفیت خودکار", fontWeight = FontWeight.Bold)
                        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                            CapacityValue("ورود", "${formatOne(current.effectivePositionPercent)}%", Modifier.weight(1f))
                            CapacityValue("Exposure", "${formatOne(current.exposureLimitPercent)}%", Modifier.weight(1f))
                            CapacityValue("Pending", "${current.pendingOrders}/${current.maxPendingOrders}", Modifier.weight(1f))
                        }
                        Text("${current.remainingPositionSlots} اسلات خالی • Watchdog: ${current.pendingTimeoutSeconds} ثانیه", color = Muted, style = MaterialTheme.typography.bodySmall)
                    }
                }
            }
        }
        item { InsightCard("آخرین تصمیم $name", current.lastDecision) }
        item { InsightCard("آخرین سیگنال", current.latestSignal) }
        item { InsightCard("آخرین سفارش", current.latestOrder) }
        item { ToggleCard("Auto Trading $name", "اجازه اجرای خودکار موتور در Cron", current.bot, onBot, current.credentials && !loading) }
        item { ToggleCard("Live Execution $name", "اجازه ارسال سفارش واقعی", current.live, onLive, current.credentials && !loading) }
        item { ToggleCard("Kill Switch سراسری", "توقف فوری سفارش‌های جدید در هر دو صرافی", kill, onKill, !loading, true) }
        item {
            Button(
                onClick = onRunNow,
                enabled = !loading && current.credentials && current.bot && current.live && !kill,
                modifier = Modifier.fillMaxWidth().height(52.dp),
                shape = RoundedCornerShape(15.dp),
            ) {
                Icon(Icons.Rounded.PlayArrow, null)
                Spacer(Modifier.width(8.dp))
                Text("اجرای یک چرخه ربات", fontWeight = FontWeight.Bold)
            }
        }
        item {
            Card(shape = RoundedCornerShape(22.dp), colors = CardDefaults.cardColors(containerColor = CardBg)) {
                Column(Modifier.padding(17.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    Text("سلامت و صرافی‌ها", fontWeight = FontWeight.Bold)
                    Text(cronText, color = Muted)
                    HorizontalDivider(color = Stroke)
                    Text("Nobitex • Bot ${onOff(nobitex.bot)} • Live ${onOff(nobitex.live)} • ${nobitex.activePositions}${if (nobitex.maxPositions > 0) "/${nobitex.maxPositions}" else ""} پوزیشن • Pending ${nobitex.pendingOrders}/${nobitex.maxPendingOrders}", color = Muted)
                    Text("Bitpin • Bot ${onOff(bitpin.bot)} • Live ${onOff(bitpin.live)} • ${bitpin.activePositions} پوزیشن", color = Muted)
                    HorizontalDivider(color = Stroke)
                    Text("آخرین چرخه: $lastRun", color = Muted, style = MaterialTheme.typography.bodySmall)
                }
            }
        }
    }
}

@Composable
private fun SettingsScreen(
    appVersion: String,
    backendVersion: String,
    updateState: UpdateUiState,
    onCheckUpdate: () -> Unit,
    onDisconnect: () -> Unit,
) {
    LazyColumn(Modifier.fillMaxSize(), contentPadding = PaddingValues(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
        item {
            Text("تنظیمات", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.ExtraBold)
            Text("نسخه‌ها، آپدیت و اتصال", color = Muted)
        }
        item {
            Card(shape = RoundedCornerShape(22.dp), colors = CardDefaults.cardColors(containerColor = CardBg)) {
                Column(Modifier.padding(17.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
                    SettingRow(Icons.Rounded.Update, "نسخه اپلیکیشن", "v$appVersion")
                    HorizontalDivider(color = Stroke)
                    SettingRow(Icons.Rounded.CloudDone, "نسخه Backend", "v$backendVersion")
                }
            }
        }
        item {
            Card(shape = RoundedCornerShape(22.dp), colors = CardDefaults.cardColors(containerColor = CardBg)) {
                Column(Modifier.padding(17.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
                    Text("آپدیت برنامه", fontWeight = FontWeight.Bold)
                    Text("نسخه اپ و Backend از یک Release هماهنگ بررسی و منتشر می‌شوند.", color = Muted)
                    Text(updateState.lastResult, color = if (updateState.lastResult.contains("خطا")) Danger else Success, fontWeight = FontWeight.SemiBold)
                    Button(onClick = onCheckUpdate, enabled = !updateState.checking, modifier = Modifier.fillMaxWidth(), shape = RoundedCornerShape(14.dp)) {
                        if (updateState.checking) CircularProgressIndicator(Modifier.size(18.dp), strokeWidth = 2.dp) else Icon(Icons.Rounded.Refresh, null)
                        Spacer(Modifier.width(8.dp))
                        Text(if (updateState.checking) "در حال بررسی…" else "بررسی نسخه جدید")
                    }
                }
            }
        }
        item {
            OutlinedButton(onClick = onDisconnect, modifier = Modifier.fillMaxWidth().height(50.dp), shape = RoundedCornerShape(14.dp)) {
                Icon(Icons.Rounded.Logout, null)
                Spacer(Modifier.width(8.dp))
                Text("قطع اتصال این گوشی")
            }
        }
    }
}

@Composable
private fun BrandHeader(subtitle: String) {
    Row(verticalAlignment = Alignment.CenterVertically) {
        Surface(shape = RoundedCornerShape(16.dp), color = Primary) {
            Text("T", Modifier.padding(horizontal = 14.dp, vertical = 10.dp), color = Color.White, fontWeight = FontWeight.Black)
        }
        Spacer(Modifier.width(12.dp))
        Column {
            Text("Trade", style = MaterialTheme.typography.headlineSmall, fontWeight = FontWeight.ExtraBold)
            Text(subtitle, color = Muted)
        }
    }
}

@Composable
private fun SectionTitle(text: String) = Text(text, style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold)

@Composable
private fun StatusChip(text: String, ok: Boolean) {
    Surface(shape = RoundedCornerShape(100.dp), color = if (ok) SuccessSoft else DangerSoft) {
        Text(text, Modifier.padding(horizontal = 10.dp, vertical = 7.dp), color = if (ok) Success else Danger, style = MaterialTheme.typography.labelMedium, fontWeight = FontWeight.Bold)
    }
}

@Composable
private fun StatusDot(ok: Boolean) {
    Surface(shape = RoundedCornerShape(100.dp), color = if (ok) SuccessSoft else WarningSoft) {
        Text(if (ok) "●" else "!", Modifier.padding(horizontal = 9.dp, vertical = 5.dp), color = if (ok) Success else Warning, fontWeight = FontWeight.Black)
    }
}

@Composable
private fun DarkChip(text: String) {
    Surface(shape = RoundedCornerShape(100.dp), color = Color(0xFF2A2E3A)) {
        Text(text, Modifier.padding(horizontal = 10.dp, vertical = 6.dp), color = Color(0xFFD5D8E3), style = MaterialTheme.typography.labelMedium)
    }
}

@Composable
private fun MetricCard(title: String, value: String, ok: Boolean, modifier: Modifier = Modifier) {
    Card(modifier, shape = RoundedCornerShape(18.dp), colors = CardDefaults.cardColors(containerColor = CardBg)) {
        Column(Modifier.padding(13.dp), verticalArrangement = Arrangement.spacedBy(5.dp)) {
            Text(title, color = Muted, style = MaterialTheme.typography.labelSmall, maxLines = 1)
            Text(value, color = if (ok) Success else Danger, fontWeight = FontWeight.ExtraBold, maxLines = 1, overflow = TextOverflow.Ellipsis)
        }
    }
}

@Composable
private fun InsightCard(title: String, text: String) {
    Card(shape = RoundedCornerShape(20.dp), colors = CardDefaults.cardColors(containerColor = CardBg)) {
        Column(Modifier.fillMaxWidth().padding(15.dp), verticalArrangement = Arrangement.spacedBy(5.dp)) {
            Text(title, fontWeight = FontWeight.Bold)
            Text(text, color = Muted, style = MaterialTheme.typography.bodySmall)
        }
    }
}

@Composable
private fun QuickAction(title: String, icon: ImageVector, onClick: () -> Unit, modifier: Modifier = Modifier) {
    FilledTonalButton(
        onClick = onClick,
        modifier = modifier.height(82.dp),
        shape = RoundedCornerShape(18.dp),
        colors = ButtonDefaults.filledTonalButtonColors(containerColor = PrimarySoft, contentColor = Primary),
        contentPadding = PaddingValues(8.dp),
    ) {
        Column(horizontalAlignment = Alignment.CenterHorizontally) {
            Icon(icon, null)
            Spacer(Modifier.height(5.dp))
            Text(title, maxLines = 1, overflow = TextOverflow.Ellipsis)
        }
    }
}

@Composable
private fun InfoCard(icon: ImageVector, title: String, text: String, danger: Boolean = false) {
    Card(Modifier.fillMaxWidth(), shape = RoundedCornerShape(20.dp), colors = CardDefaults.cardColors(containerColor = if (danger) DangerSoft else PrimarySoft)) {
        Row(Modifier.padding(15.dp), verticalAlignment = Alignment.Top) {
            Icon(icon, null, tint = if (danger) Danger else Primary)
            Spacer(Modifier.width(10.dp))
            Column {
                Text(title, fontWeight = FontWeight.Bold, color = if (danger) Danger else Ink)
                Spacer(Modifier.height(3.dp))
                Text(text, color = Muted, style = MaterialTheme.typography.bodySmall)
            }
        }
    }
}

@Composable
private fun ToggleCard(title: String, text: String, checked: Boolean, onChange: (Boolean) -> Unit, enabled: Boolean, danger: Boolean = false) {
    Card(shape = RoundedCornerShape(20.dp), colors = CardDefaults.cardColors(containerColor = CardBg)) {
        Row(Modifier.fillMaxWidth().padding(16.dp), verticalAlignment = Alignment.CenterVertically) {
            Surface(shape = RoundedCornerShape(14.dp), color = if (danger) DangerSoft else PrimarySoft) {
                Icon(if (danger) Icons.Rounded.PowerSettingsNew else Icons.Rounded.SmartToy, null, Modifier.padding(10.dp), tint = if (danger) Danger else Primary)
            }
            Spacer(Modifier.width(12.dp))
            Column(Modifier.weight(1f)) {
                Text(title, fontWeight = FontWeight.Bold)
                Text(text, color = Muted, style = MaterialTheme.typography.bodySmall)
            }
            Switch(checked = checked, onCheckedChange = onChange, enabled = enabled)
        }
    }
}

@Composable
private fun SettingRow(icon: ImageVector, title: String, value: String) {
    Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
        Icon(icon, null, tint = Primary)
        Spacer(Modifier.width(11.dp))
        Text(title, Modifier.weight(1f), fontWeight = FontWeight.SemiBold)
        Text(value, color = Muted)
    }
}

@Composable
private fun EmptyCard(text: String) {
    Card(shape = RoundedCornerShape(20.dp), colors = CardDefaults.cardColors(containerColor = CardBg)) {
        Text(text, Modifier.fillMaxWidth().padding(24.dp), color = Muted)
    }
}

@Composable
private fun MessageCard(text: String, isError: Boolean) {
    Card(Modifier.fillMaxWidth(), shape = RoundedCornerShape(16.dp), colors = CardDefaults.cardColors(containerColor = if (isError) DangerSoft else SuccessSoft)) {
        Text(text, Modifier.padding(13.dp), color = if (isError) Danger else Success, fontWeight = FontWeight.SemiBold)
    }
}

private fun readableHttpError(response: TradeApi.Response): String = try {
    val r = JSONObject(response.body)
    r.optString("message").ifBlank { r.optString("error") }.ifBlank { "HTTP ${response.code}" }
} catch (_: Exception) {
    "HTTP ${response.code}: ${response.body.take(220)}"
}

private fun parseWalletRows(raw: String): List<WalletRow> {
    return try {
        val root = JSONObject(raw)
        val outer = root.optJSONObject("data") ?: root
        val array = outer.optJSONArray("wallets") ?: outer.optJSONObject("data")?.optJSONArray("wallets") ?: JSONArray()
        val rows = mutableListOf<WalletRow>()
        for (i in 0 until array.length()) {
            val row = array.optJSONObject(i) ?: continue
            val rawCode = row.optString("currency").ifBlank { row.optString("asset") }.ifBlank { row.optString("currencyCode") }.uppercase()
            if (rawCode.isBlank()) continue
            val available = firstNumber(row, "activeBalance", "available", "free", "balance")
            val rialValue = firstNumber(row, "rialBalance", "rial_balance", "valueRls", "value_rial") / 10.0
            val code = if (rawCode == "RLS") "IRT" else rawCode
            val displayBalance = if (rawCode == "RLS") available / 10.0 else available
            val displayRialValue = if (rialValue > 0) rialValue else if (rawCode == "RLS") displayBalance else 0.0
            if (displayBalance > 0.0000000001 || displayRialValue > 0.01) rows += WalletRow(code, displayBalance, displayRialValue)
        }
        rows.sortedWith(compareByDescending<WalletRow> { it.rialValueToman }.thenByDescending { it.balance })
    } catch (_: Exception) { emptyList() }
}

private fun firstNumber(row: JSONObject, vararg keys: String): Double {
    for (key in keys) {
        if (!row.has(key)) continue
        val raw = row.opt(key)
        val value = when (raw) {
            is Number -> raw.toDouble()
            is String -> raw.toDoubleOrNull()
            else -> null
        }
        if (value != null && value.isFinite()) return value
    }
    return 0.0
}

private fun runDescription(run: JSONObject?): String {
    if (run == null) return "هنوز داده‌ای از اجرای Cron ثبت نشده است."
    val status = run.optString("status", "-")
    val reason = run.optString("reason").ifBlank { run.optString("error") }
    val selected = run.optJSONObject("selected")
    val symbol = selected?.optString("symbol").orEmpty()
    val score = selected?.optInt("signal_score", Int.MIN_VALUE) ?: Int.MIN_VALUE
    val base = statusFa(status)
    val reasonText = if (reason.isNotBlank()) " • ${reasonFa(reason)}" else ""
    val selectedText = if (symbol.isNotBlank()) " • $symbol${if (score != Int.MIN_VALUE) " • Score $score" else ""}" else ""
    return base + reasonText + selectedText
}

private fun signalDescription(signal: JSONObject?): String {
    if (signal == null) return "هنوز سیگنالی ثبت نشده"
    val symbol = signal.optString("symbol", "-")
    val action = signal.optString("action", "hold").uppercase()
    val score = signal.optInt("score", 0)
    val details = signal.optJSONObject("details")
    val confidence = details?.optInt("confidence", -1) ?: -1
    val reason = details?.optString("reason").orEmpty()
    return "$symbol • $action • Score $score${if (confidence >= 0) " • Confidence $confidence%" else ""}${if (reason.isNotBlank()) " • ${reasonFa(reason)}" else ""}"
}

private fun decisionDescription(decision: JSONObject?): String = runDescription(decision)

private fun orderDescription(order: JSONObject?): String {
    if (order == null) return "هنوز سفارشی ثبت نشده"
    val market = order.optString("market_code", "-")
    val side = order.optString("side", "-").uppercase()
    val status = order.optString("status", "-")
    val error = order.optString("error_text")
    return "$market • $side • $status${if (error.isNotBlank()) " • $error" else ""}"
}

private fun statusFa(value: String): String = when (value) {
    "buy_submitted", "first_buy_submitted" -> "سفارش خرید ارسال شد"
    "sell_submitted" -> "سفارش فروش ارسال شد"
    "holding_position" -> "در حال نگهداری پوزیشن"
    "waiting_order" -> "در انتظار تکمیل سفارش"
    "portfolio_full" -> "ظرفیت پورتفو تکمیل است"
    "no_trade" -> "فعلاً معامله‌ای انجام نمی‌شود"
    "blocked" -> "اجرای ربات مسدود است"
    "disabled" -> "ربات خاموش است"
    "failed" -> "خطا"
    else -> value
}

private fun reasonFa(value: String): String = when (value) {
    "no_candidate_passed_signal_and_risk_filters" -> "هیچ بازار فعلی از فیلتر سیگنال و ریسک عبور نکرد"
    "no_eligible_markets" -> "بازار واجد شرایط پیدا نشد"
    "score_below_threshold" -> "امتیاز هنوز به حد ورود نرسیده"
    "buy_score_without_confirmation" -> "تأیید روند کامل نشده"
    "spread_too_wide" -> "Spread زیاد است"
    "volatility_too_high" -> "نوسان کوتاه‌مدت زیاد است"
    "symbol_cooldown_active" -> "Cooldown بازار فعال است"
    "daily_loss_limit_reached" -> "حد زیان روزانه فعال شده"
    "portfolio_exposure_limit_reached" -> "سقف سرمایه درگیر پرتفوی تکمیل است"
    "pending_order_capacity_reached" -> "ظرفیت سفارش‌های Pending تکمیل است"
    "minimum_order_rounding" -> "مبلغ کمتر از حداقل سفارش است"
    "minimum_order_exceeds_budget" -> "حداقل سفارش از بودجه این پوزیشن بیشتر است"
    "already_positioned" -> "برای این دارایی پوزیشن فعال وجود دارد"
    "bot_disabled" -> "Bot خاموش است"
    "live_execution_disabled" -> "Live خاموش است"
    "kill_switch" -> "Kill Switch فعال است"
    "credentials_missing" -> "API تنظیم نشده"
    "no_quote_balance" -> "موجودی IRT/USDT کافی نیست"
    "multi_factor_buy_confirmed" -> "خرید چندعاملی تأیید شده"
    "multi_factor_sell_confirmed" -> "فروش چندعاملی تأیید شده"
    else -> value.replace('_', ' ')
}

private fun formatNumber(value: Double): String {
    val formatter = NumberFormat.getNumberInstance(Locale.US)
    formatter.maximumFractionDigits = 0
    return formatter.format(value)
}

private fun formatOne(value: Double): String = String.format(Locale.US, "%.1f", value)

private fun formatCompact(value: Double): String = when {
    kotlin.math.abs(value) >= 1_000_000 -> String.format(Locale.US, "%.1fM", value / 1_000_000.0)
    kotlin.math.abs(value) >= 1_000 -> String.format(Locale.US, "%.1fK", value / 1_000.0)
    else -> String.format(Locale.US, "%.2f", value)
}

private fun formatAsset(value: Double): String = when {
    value >= 1000 -> formatNumber(value)
    value >= 1 -> String.format(Locale.US, "%.4f", value).trimEnd('0').trimEnd('.')
    else -> String.format(Locale.US, "%.8f", value).trimEnd('0').trimEnd('.')
}

private fun onOff(value: Boolean) = if (value) "ON" else "OFF"
