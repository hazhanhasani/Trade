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
private val Ink = Color(0xFF161922)
private val Muted = Color(0xFF737A8C)
private val Primary = Color(0xFF6C4BFF)
private val PrimarySoft = Color(0xFFF0EDFF)
private val Success = Color(0xFF119C78)
private val SuccessSoft = Color(0xFFE9F8F3)
private val Danger = Color(0xFFD74747)
private val DangerSoft = Color(0xFFFFEEEE)
private val Stroke = Color(0xFFE6E8EF)

data class ExchangeUiState(
    val credentials: Boolean = false,
    val bot: Boolean = false,
    val live: Boolean = false,
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
                BrandHeader(subtitle = "اتصال امن به موتور معاملات")
                InfoCard(
                    icon = Security,
                    title = "کلید صرافی داخل گوشی نیست",
                    text = "اپ فقط به Backend شخصی وصل می‌شود. کلیدهای Nobitex و Bitpin روی سرور باقی می‌مانند.",
                )
                OutlinedTextField(
                    value = server,
                    onValueChange = { server = it },
                    label = { Text("آدرس سرور") },
                    singleLine = true,
                    modifier = Modifier.fillMaxWidth(),
                )
                OutlinedTextField(
                    value = token,
                    onValueChange = { token = it },
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
        return ExchangeUiState(
            credentials = x.optBoolean("credentials_configured"),
            bot = x.optBoolean("bot_enabled"),
            live = x.optBoolean("live_execution_enabled"),
        )
    }

    fun refreshStatus() {
        scope.launch {
            loading = true; error = ""
            try {
                val response = api.status()
                if (!response.ok) throw IllegalStateException(readableHttpError(response))
                val data = JSONObject(response.body).getJSONObject("data")
                backendVersion = data.optString("backend_version", "-")
                killSwitch = data.optBoolean("kill_switch")
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
        message = ""; error = ""; wallets = emptyList()
        refreshWallet()
    }

    fun submitOrder() {
        val a = amount.toDoubleOrNull()
        val p = price.toDoubleOrNull()
        if (symbol.isBlank() || a == null || a <= 0 || (mode != "market" && (p == null || p <= 0))) {
            error = "نماد بازار و مقدار سفارش را درست وارد کن."; return
        }
        scope.launch {
            loading = true; error = ""; message = ""
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
                refreshStatus(); refreshWallet()
            } catch (e: Exception) {
                error = e.message ?: "ثبت سفارش ناموفق بود"
            } finally { loading = false }
        }
    }

    fun cancelOrder() {
        if (cancelId.isBlank()) { error = "شناسه سفارش را وارد کن."; return }
        scope.launch {
            loading = true; error = ""; message = ""
            try {
                val response = api.cancelOrder(cancelId.trim(), exchange)
                if (!response.ok) throw IllegalStateException(readableHttpError(response))
                message = "درخواست لغو ارسال شد."
                cancelId = ""
            } catch (e: Exception) { error = e.message ?: "لغو سفارش ناموفق بود" }
            finally { loading = false }
        }
    }

    fun setBot(enabled: Boolean) {
        scope.launch {
            loading = true; error = ""
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
            loading = true; error = ""
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
            loading = true; error = ""
            try {
                val r = api.setKillSwitch(enabled)
                if (!r.ok) throw IllegalStateException(readableHttpError(r))
                killSwitch = enabled
                message = if (enabled) "توقف اضطراری فعال شد." else "توقف اضطراری برداشته شد."
            } catch (e: Exception) { error = e.message ?: "تغییر Kill Switch ناموفق بود" }
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
            NavigationBar(containerColor = Color.White, tonalElevation = 4.dp) {
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
        Column(
            Modifier.fillMaxSize().padding(padding).statusBarsPadding(),
        ) {
            AppTopBar(
                appVersion = AppUpdateManager.currentVersionName(context),
                backendVersion = backendVersion,
                exchange = exchange,
                current = current,
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
                        lastRun = if (exchange == "nobitex") lastNobitexRun else lastBitpinRun,
                        onBot = { setBot(it) },
                        onLive = { setLive(it) },
                        onKill = { setKill(it) },
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
    onSelectExchange: (String) -> Unit,
    onRefresh: () -> Unit,
    loading: Boolean,
) {
    Column(
        Modifier.fillMaxWidth().background(Color.White).padding(horizontal = 16.dp, vertical = 12.dp),
        verticalArrangement = Arrangement.spacedBy(10.dp),
    ) {
        Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
            Surface(shape = RoundedCornerShape(14.dp), color = Primary) {
                Text("T", Modifier.padding(horizontal = 13.dp, vertical = 8.dp), color = Color.White, fontWeight = FontWeight.Black)
            }
            Spacer(Modifier.width(10.dp))
            Column(Modifier.weight(1f)) {
                Text("Trade", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.ExtraBold)
                Text("App v$appVersion  •  Backend v$backendVersion", color = Muted, style = MaterialTheme.typography.labelMedium)
            }
            IconButton(onClick = onRefresh, enabled = !loading) {
                Icon(Icons.Rounded.Refresh, contentDescription = "بروزرسانی", tint = Primary)
            }
        }
        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            FilterChip(
                selected = exchange == "nobitex",
                onClick = { onSelectExchange("nobitex") },
                label = { Text("Nobitex") },
                leadingIcon = if (exchange == "nobitex") {{ Icon(Icons.Rounded.CheckCircle, null, Modifier.size(18.dp)) }} else null,
            )
            FilterChip(
                selected = exchange == "bitpin",
                onClick = { onSelectExchange("bitpin") },
                label = { Text("Bitpin") },
            )
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
            Card(shape = RoundedCornerShape(26.dp), colors = CardDefaults.cardColors(containerColor = Ink)) {
                Column(Modifier.padding(20.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
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
                        Surface(shape = RoundedCornerShape(16.dp), color = Color(0xFF282C38)) {
                            Icon(Icons.Rounded.AutoGraph, null, Modifier.padding(12.dp), tint = Color(0xFFA996FF))
                        }
                    }
                    Text("صرافی فعال: $exchangeTitle", color = Color(0xFFBFC4D2))
                }
            }
        }
        item {
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                MetricCard("ربات", if (current.bot) "روشن" else "خاموش", current.bot, Modifier.weight(1f))
                MetricCard("Live", if (current.live) "فعال" else "خاموش", current.live, Modifier.weight(1f))
                MetricCard("ایمنی", if (killSwitch) "متوقف" else "عادی", !killSwitch, Modifier.weight(1f))
            }
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
        item {
            Card(shape = RoundedCornerShape(22.dp), colors = CardDefaults.cardColors(containerColor = CardBg)) {
                Column(Modifier.padding(17.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Icon(Icons.Rounded.CloudDone, null, tint = Primary)
                        Spacer(Modifier.width(8.dp))
                        Text("آخرین وضعیت موتور", fontWeight = FontWeight.Bold)
                    }
                    Text(lastRun, color = Muted, style = MaterialTheme.typography.bodyMedium)
                }
            }
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
                    Text("فقط موجودی‌های واقعی و قابل استفاده", color = Muted)
                }
                FilledTonalIconButton(onClick = onRefresh, enabled = !loading) { Icon(Icons.Rounded.Refresh, null) }
            }
        }
        if (wallets.isEmpty()) {
            item { EmptyCard(if (loading) "در حال دریافت کیف پول…" else "موجودی قابل نمایش پیدا نشد.") }
        } else {
            items(wallets, key = { it.code }) { item -> AssetCard(item) }
        }
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
    LazyColumn(
        Modifier.fillMaxSize(),
        contentPadding = PaddingValues(16.dp),
        verticalArrangement = Arrangement.spacedBy(12.dp),
    ) {
        item {
            Text("معامله دستی", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.ExtraBold)
            Text("ارسال سفارش مستقیم به $name", color = Muted)
        }
        if (!enabled) {
            item {
                InfoCard(
                    Icons.Rounded.Security,
                    "ارسال سفارش فعلاً قفل است",
                    when {
                        kill -> "Kill Switch روشن است."
                        !state.credentials -> "API صرافی تنظیم نشده است."
                        !state.live -> "Live Execution خاموش است."
                        else -> "سیستم آماده نیست."
                    },
                    danger = true,
                )
            }
        }
        item {
            Card(shape = RoundedCornerShape(22.dp), colors = CardDefaults.cardColors(containerColor = CardBg)) {
                Column(Modifier.padding(17.dp), verticalArrangement = Arrangement.spacedBy(11.dp)) {
                    OutlinedTextField(
                        symbol, onSymbol,
                        label = { Text("بازار") },
                        supportingText = { Text(if (name == "Nobitex") "مثل BTCIRT یا ETHUSDT" else "مثل BTC_IRT") },
                        singleLine = true,
                        modifier = Modifier.fillMaxWidth(),
                    )
                    OutlinedTextField(amount, onAmount, label = { Text("مقدار ارز") }, singleLine = true, modifier = Modifier.fillMaxWidth())
                    Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                        FilterChip(selected = side == "buy", onClick = { onSide("buy") }, label = { Text("خرید") })
                        FilterChip(selected = side == "sell", onClick = { onSide("sell") }, label = { Text("فروش") })
                    }
                    Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                        FilterChip(selected = mode == "market", onClick = { onMode("market") }, label = { Text("Market") })
                        FilterChip(selected = mode == "limit", onClick = { onMode("limit") }, label = { Text("Limit") })
                    }
                    if (mode != "market") {
                        OutlinedTextField(price, onPrice, label = { Text("قیمت") }, singleLine = true, modifier = Modifier.fillMaxWidth())
                    }
                    Button(
                        onClick = onSubmit,
                        enabled = enabled,
                        modifier = Modifier.fillMaxWidth().height(52.dp),
                        shape = RoundedCornerShape(15.dp),
                    ) { Text("ارسال سفارش واقعی", fontWeight = FontWeight.Bold) }
                }
            }
        }
        item {
            Card(shape = RoundedCornerShape(22.dp), colors = CardDefaults.cardColors(containerColor = CardBg)) {
                Column(Modifier.padding(17.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
                    Text("لغو سفارش", fontWeight = FontWeight.Bold)
                    OutlinedTextField(cancelId, onCancelId, label = { Text("Order ID / Client Order ID") }, singleLine = true, modifier = Modifier.fillMaxWidth())
                    OutlinedButton(onClick = onCancel, enabled = !loading && state.credentials && cancelId.isNotBlank(), modifier = Modifier.fillMaxWidth()) {
                        Text("لغو سفارش")
                    }
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
    lastRun: String,
    onBot: (Boolean) -> Unit,
    onLive: (Boolean) -> Unit,
    onKill: (Boolean) -> Unit,
) {
    LazyColumn(
        Modifier.fillMaxSize(),
        contentPadding = PaddingValues(16.dp),
        verticalArrangement = Arrangement.spacedBy(12.dp),
    ) {
        item {
            Text("ربات معاملات", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.ExtraBold)
            Text("کنترل موتور چندارزی IRT / USDT", color = Muted)
        }
        item {
            InfoCard(
                Icons.Rounded.AutoGraph,
                "موتور چندارزی",
                "در Nobitex بازارهای قابل معامله را اسکن می‌کند، نقدشوندگی و سیگنال را رتبه‌بندی می‌کند و فقط پس از عبور از Risk Manager سفارش می‌فرستد.",
            )
        }
        item { ToggleCard("Auto Trading $name", "اجازه اجرای خودکار موتور در Cron", current.bot, { onBot(it) }, current.credentials && !loading) }
        item { ToggleCard("Live Execution $name", "اجازه ارسال سفارش واقعی", current.live, { onLive(it) }, current.credentials && !loading) }
        item { ToggleCard("Kill Switch سراسری", "توقف فوری ارسال سفارش در هر دو صرافی", kill, { onKill(it) }, !loading, danger = true) }
        item {
            Card(shape = RoundedCornerShape(22.dp), colors = CardDefaults.cardColors(containerColor = CardBg)) {
                Column(Modifier.padding(17.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    Text("وضعیت صرافی‌ها", fontWeight = FontWeight.Bold)
                    Text("Nobitex  •  Bot ${onOff(nobitex.bot)}  •  Live ${onOff(nobitex.live)}", color = Muted)
                    Text("Bitpin  •  Bot ${onOff(bitpin.bot)}  •  Live ${onOff(bitpin.live)}", color = Muted)
                    HorizontalDivider(color = Stroke)
                    Text("آخرین چرخه", fontWeight = FontWeight.Bold)
                    Text(lastRun, color = Muted)
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
    LazyColumn(
        Modifier.fillMaxSize(),
        contentPadding = PaddingValues(16.dp),
        verticalArrangement = Arrangement.spacedBy(12.dp),
    ) {
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
                    Text("آپدیت از کانال رسمی GitHub بررسی می‌شود و به نسخه Backend وابسته نیست.", color = Muted)
                    Text(updateState.lastResult, color = if (updateState.lastResult.contains("خطا")) Danger else Success, fontWeight = FontWeight.SemiBold)
                    Button(
                        onClick = onCheckUpdate,
                        enabled = !updateState.checking,
                        modifier = Modifier.fillMaxWidth(),
                        shape = RoundedCornerShape(14.dp),
                    ) {
                        if (updateState.checking) CircularProgressIndicator(Modifier.size(18.dp), strokeWidth = 2.dp)
                        else Icon(Icons.Rounded.Refresh, null)
                        Spacer(Modifier.width(8.dp))
                        Text(if (updateState.checking) "در حال بررسی…" else "بررسی نسخه جدید")
                    }
                }
            }
        }
        item {
            OutlinedButton(
                onClick = onDisconnect,
                modifier = Modifier.fillMaxWidth().height(50.dp),
                shape = RoundedCornerShape(14.dp),
            ) {
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
        Text(
            text,
            Modifier.padding(horizontal = 10.dp, vertical = 7.dp),
            color = if (ok) Success else Danger,
            style = MaterialTheme.typography.labelMedium,
            fontWeight = FontWeight.Bold,
        )
    }
}

@Composable
private fun MetricCard(title: String, value: String, ok: Boolean, modifier: Modifier = Modifier) {
    Card(modifier, shape = RoundedCornerShape(18.dp), colors = CardDefaults.cardColors(containerColor = CardBg)) {
        Column(Modifier.padding(13.dp), verticalArrangement = Arrangement.spacedBy(5.dp)) {
            Text(title, color = Muted, style = MaterialTheme.typography.labelMedium)
            Text(value, color = if (ok) Success else Danger, fontWeight = FontWeight.ExtraBold)
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
    Card(
        Modifier.fillMaxWidth(),
        shape = RoundedCornerShape(20.dp),
        colors = CardDefaults.cardColors(containerColor = if (danger) DangerSoft else PrimarySoft),
    ) {
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
                Icon(
                    if (danger) Icons.Rounded.PowerSettingsNew else Icons.Rounded.SmartToy,
                    null,
                    Modifier.padding(10.dp),
                    tint = if (danger) Danger else Primary,
                )
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
    Card(
        Modifier.fillMaxWidth(),
        shape = RoundedCornerShape(16.dp),
        colors = CardDefaults.cardColors(containerColor = if (isError) DangerSoft else SuccessSoft),
    ) {
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
        val array = outer.optJSONArray("wallets")
            ?: outer.optJSONObject("data")?.optJSONArray("wallets")
            ?: JSONArray()
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
            if (displayBalance > 0.0000000001 || displayRialValue > 0.01) {
                rows += WalletRow(code, displayBalance, displayRialValue)
            }
        }
        rows.sortedWith(compareByDescending<WalletRow> { it.rialValueToman }.thenByDescending { it.balance })
    } catch (_: Exception) {
        emptyList()
    }
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
    return when {
        reason.isNotBlank() -> "$status — $reason"
        else -> status
    }
}

private fun formatNumber(value: Double): String {
    val formatter = NumberFormat.getNumberInstance(Locale.US)
    formatter.maximumFractionDigits = 0
    return formatter.format(value)
}

private fun formatAsset(value: Double): String {
    return when {
        value >= 1000 -> formatNumber(value)
        value >= 1 -> String.format(Locale.US, "%.4f", value).trimEnd('0').trimEnd('.')
        else -> String.format(Locale.US, "%.8f", value).trimEnd('0').trimEnd('.')
    }
}

private fun onOff(value: Boolean) = if (value) "ON" else "OFF"
