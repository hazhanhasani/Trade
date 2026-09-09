package ir.trade.app.ui

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.unit.dp
import ir.trade.app.data.TradeApi
import ir.trade.app.data.TradePreferences
import kotlinx.coroutines.launch
import org.json.JSONArray
import org.json.JSONObject

private val TradeBlue = Color(0xFF0B63F6)
private val TradeCyan = Color(0xFF19A9F5)
private val TradeGreen = Color(0xFF0FAF83)
private val TradeBackground = Color(0xFFF5F7FB)
private val TradeSurface = Color(0xFFFFFFFF)
private val TradeText = Color(0xFF172033)
private val TradeMuted = Color(0xFF68748A)

@Composable
fun TradeApp() {
    val colors = lightColorScheme(
        primary = TradeBlue,
        secondary = TradeCyan,
        tertiary = TradeGreen,
        background = TradeBackground,
        surface = TradeSurface,
        onBackground = TradeText,
        onSurface = TradeText,
    )

    MaterialTheme(colorScheme = colors) {
        val context = LocalContext.current
        val prefs = remember { TradePreferences(context) }
        var configured by remember { mutableStateOf(prefs.isConfigured()) }

        Surface(
            modifier = Modifier.fillMaxSize(),
            color = MaterialTheme.colorScheme.background,
        ) {
            if (!configured) {
                SetupScreen(prefs) { configured = true }
            } else {
                DashboardScreen(prefs) {
                    prefs.clear()
                    configured = false
                }
            }
        }
    }
}

@Composable
private fun SetupScreen(prefs: TradePreferences, onSaved: () -> Unit) {
    var server by rememberSaveable { mutableStateOf(prefs.serverUrl()) }
    var token by rememberSaveable { mutableStateOf(prefs.apiToken()) }
    var error by rememberSaveable { mutableStateOf("") }

    Box(
        modifier = Modifier
            .fillMaxSize()
            .statusBarsPadding()
            .navigationBarsPadding()
            .padding(20.dp),
        contentAlignment = Alignment.Center,
    ) {
        Card(
            modifier = Modifier.fillMaxWidth(),
            shape = RoundedCornerShape(24.dp),
            colors = CardDefaults.cardColors(containerColor = Color.White),
            elevation = CardDefaults.cardElevation(defaultElevation = 2.dp),
        ) {
            Column(
                modifier = Modifier.padding(22.dp),
                verticalArrangement = Arrangement.spacedBy(12.dp),
            ) {
                Text("Trade", style = MaterialTheme.typography.headlineMedium, fontWeight = FontWeight.Bold)
                Text("مدیریت امن حساب Bitpin از طریق سرور شخصی", color = TradeMuted)

                InfoBox(
                    title = "نحوه اتصال",
                    text = "کلید اصلی Bitpin داخل گوشی ذخیره نمی‌شود. اپ فقط با App API Token به rado-taxi.sbs متصل می‌شود.",
                )

                OutlinedTextField(
                    value = server,
                    onValueChange = { server = it },
                    label = { Text("آدرس سرور") },
                    supportingText = { Text("پیش‌فرض: https://rado-taxi.sbs") },
                    singleLine = true,
                    modifier = Modifier.fillMaxWidth(),
                )

                OutlinedTextField(
                    value = token,
                    onValueChange = { token = it },
                    label = { Text("App API Token") },
                    supportingText = { Text("توکنی که Installer بعد از نصب نمایش می‌دهد") },
                    singleLine = true,
                    visualTransformation = PasswordVisualTransformation(),
                    modifier = Modifier.fillMaxWidth(),
                )

                if (error.isNotBlank()) {
                    MessageCard(error, isError = true)
                }

                Button(
                    onClick = {
                        try {
                            prefs.save(server.trim(), token.trim())
                            onSaved()
                        } catch (_: IllegalArgumentException) {
                            error = "آدرس سرور باید با HTTPS شروع شود."
                        }
                    },
                    enabled = server.isNotBlank() && token.isNotBlank(),
                    modifier = Modifier.fillMaxWidth(),
                ) {
                    Text("ذخیره و ورود به داشبورد")
                }
            }
        }
    }
}

@Composable
private fun DashboardScreen(prefs: TradePreferences, onDisconnect: () -> Unit) {
    val scope = rememberCoroutineScope()
    val api = remember(prefs.serverUrl(), prefs.apiToken()) { TradeApi(prefs.serverUrl(), prefs.apiToken()) }

    var selectedTab by rememberSaveable { mutableIntStateOf(0) }
    var loading by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf("") }
    var message by remember { mutableStateOf("") }

    var mode by remember { mutableStateOf("...") }
    var connected by remember { mutableStateOf(false) }
    var ordersLogged by remember { mutableIntStateOf(0) }
    var killSwitch by remember { mutableStateOf(false) }
    var capitalAsset by remember { mutableStateOf("-") }

    var walletsText by remember { mutableStateOf("") }
    var marketsText by remember { mutableStateOf("") }
    var ordersText by remember { mutableStateOf("") }
    var walletsCount by remember { mutableIntStateOf(0) }
    var marketsCount by remember { mutableIntStateOf(0) }
    var ordersCount by remember { mutableIntStateOf(0) }

    var marketId by rememberSaveable { mutableStateOf("") }
    var amount by rememberSaveable { mutableStateOf("") }
    var price by rememberSaveable { mutableStateOf("") }
    var side by rememberSaveable { mutableStateOf("buy") }
    var orderMode by rememberSaveable { mutableStateOf("limit") }
    var cancelOrderId by rememberSaveable { mutableStateOf("") }

    fun refreshStatus() {
        scope.launch {
            loading = true
            error = ""
            try {
                val response = api.status()
                if (!response.ok) throw IllegalStateException(readableHttpError(response))
                val data = JSONObject(response.body).getJSONObject("data")
                mode = data.optString("mode", "live_disabled")
                connected = data.optBoolean("credentials_configured")
                ordersLogged = data.optInt("orders_logged")
                killSwitch = data.optBoolean("kill_switch")
                capitalAsset = data.optString("capital_asset", "-").uppercase()
            } catch (e: Exception) {
                error = e.message ?: "خطا در دریافت وضعیت سرور"
            } finally {
                loading = false
            }
        }
    }

    fun loadData(kind: String) {
        scope.launch {
            loading = true
            error = ""
            message = ""
            try {
                val response = when (kind) {
                    "wallets" -> api.wallets()
                    "markets" -> api.markets()
                    else -> api.orders()
                }
                if (!response.ok) throw IllegalStateException(readableHttpError(response))
                val pretty = prettyJson(response.body)
                val count = collectionCount(response.body)
                when (kind) {
                    "wallets" -> {
                        walletsText = pretty
                        walletsCount = count
                        message = "اطلاعات کیف پول از Bitpin دریافت شد."
                    }
                    "markets" -> {
                        marketsText = pretty
                        marketsCount = count
                        message = "لیست بازارهای Bitpin دریافت شد."
                    }
                    else -> {
                        ordersText = pretty
                        ordersCount = count
                        message = "لیست سفارش‌ها دریافت شد."
                    }
                }
            } catch (e: Exception) {
                error = e.message ?: "دریافت اطلاعات ناموفق بود"
            } finally {
                loading = false
            }
        }
    }

    fun updateKillSwitch(enabled: Boolean) {
        scope.launch {
            loading = true
            error = ""
            message = ""
            try {
                val response = api.setKillSwitch(enabled)
                if (!response.ok) throw IllegalStateException(readableHttpError(response))
                killSwitch = enabled
                message = if (enabled) {
                    "توقف اضطراری فعال شد؛ درخواست سفارش جدید از اپ متوقف است."
                } else {
                    "توقف اضطراری غیرفعال شد."
                }
            } catch (e: Exception) {
                error = e.message ?: "تغییر وضعیت ایمنی ناموفق بود"
            } finally {
                loading = false
            }
        }
    }

    fun submitOrder() {
        val market = marketId.toIntOrNull()
        val amountValue = amount.toDoubleOrNull()
        val priceValue = price.toDoubleOrNull()

        if (market == null || market <= 0 || amountValue == null || amountValue <= 0 || priceValue == null || priceValue <= 0) {
            error = "Market ID، مقدار و قیمت باید عدد معتبر و بزرگ‌تر از صفر باشند."
            return
        }

        scope.launch {
            loading = true
            error = ""
            message = ""
            try {
                val payload = JSONObject()
                    .put("market", market)
                    .put("amount1", amountValue)
                    .put("price", priceValue)
                    .put("mode", orderMode)
                    .put("type", side)
                    .toString()

                val response = api.createOrder(payload)
                if (!response.ok) throw IllegalStateException(readableHttpError(response))

                val root = JSONObject(response.body)
                val exchange = root.optJSONObject("data")?.optJSONObject("exchange")
                val id = exchange?.optString("id").orEmpty().ifBlank {
                    exchange?.optString("order_id").orEmpty()
                }
                message = if (id.isNotBlank()) "سفارش ثبت شد. شناسه: $id" else "سفارش ثبت شد."
                refreshStatus()
            } catch (e: Exception) {
                error = e.message ?: "ثبت سفارش ناموفق بود"
            } finally {
                loading = false
            }
        }
    }

    fun cancelOrder() {
        val id = cancelOrderId.trim()
        if (id.isBlank()) {
            error = "شناسه سفارش را وارد کن."
            return
        }
        scope.launch {
            loading = true
            error = ""
            message = ""
            try {
                val response = api.cancelOrder(id)
                if (!response.ok) throw IllegalStateException(readableHttpError(response))
                message = "درخواست لغو سفارش ارسال شد."
                cancelOrderId = ""
                loadData("orders")
            } catch (e: Exception) {
                error = e.message ?: "لغو سفارش ناموفق بود"
            } finally {
                loading = false
            }
        }
    }

    LaunchedEffect(Unit) { refreshStatus() }

    Column(
        modifier = Modifier
            .fillMaxSize()
            .statusBarsPadding()
            .navigationBarsPadding(),
    ) {
        AppHeader(
            server = prefs.serverUrl(),
            connected = connected,
            loading = loading,
            onRefresh = { refreshStatus() },
            onDisconnect = onDisconnect,
        )

        val tabs = listOf("خانه", "داده‌ها", "معامله", "ایمنی")
        ScrollableTabRow(
            selectedTabIndex = selectedTab,
            edgePadding = 12.dp,
            containerColor = Color.White,
            divider = {},
        ) {
            tabs.forEachIndexed { index, title ->
                Tab(
                    selected = selectedTab == index,
                    onClick = { selectedTab = index },
                    text = { Text(title, fontWeight = if (selectedTab == index) FontWeight.Bold else FontWeight.Normal) },
                )
            }
        }

        if (error.isNotBlank()) {
            Box(Modifier.padding(horizontal = 16.dp, vertical = 8.dp)) {
                MessageCard(error, isError = true)
            }
        }
        if (message.isNotBlank()) {
            Box(Modifier.padding(horizontal = 16.dp, vertical = 8.dp)) {
                MessageCard(message, isError = false)
            }
        }

        Box(modifier = Modifier.weight(1f)) {
            when (selectedTab) {
                0 -> HomeTab(
                    mode = mode,
                    connected = connected,
                    capitalAsset = capitalAsset,
                    ordersLogged = ordersLogged,
                    killSwitch = killSwitch,
                    onOpenData = { selectedTab = 1 },
                    onOpenTrade = { selectedTab = 2 },
                    onOpenSafety = { selectedTab = 3 },
                )

                1 -> DataTab(
                    loading = loading,
                    walletsCount = walletsCount,
                    marketsCount = marketsCount,
                    ordersCount = ordersCount,
                    walletsText = walletsText,
                    marketsText = marketsText,
                    ordersText = ordersText,
                    onWallets = { loadData("wallets") },
                    onMarkets = { loadData("markets") },
                    onOrders = { loadData("orders") },
                )

                2 -> TradeTab(
                    loading = loading,
                    connected = connected,
                    mode = mode,
                    killSwitch = killSwitch,
                    marketId = marketId,
                    amount = amount,
                    price = price,
                    side = side,
                    orderMode = orderMode,
                    cancelOrderId = cancelOrderId,
                    onMarketId = { marketId = it },
                    onAmount = { amount = it },
                    onPrice = { price = it },
                    onSide = { side = it },
                    onOrderMode = { orderMode = it },
                    onSubmit = { submitOrder() },
                    onCancelId = { cancelOrderId = it },
                    onCancelOrder = { cancelOrder() },
                    onOpenMarkets = {
                        selectedTab = 1
                        loadData("markets")
                    },
                )

                else -> SafetyTab(
                    connected = connected,
                    killSwitch = killSwitch,
                    loading = loading,
                    onKillSwitch = { updateKillSwitch(it) },
                    onRefresh = { refreshStatus() },
                )
            }
        }
    }
}

@Composable
private fun AppHeader(
    server: String,
    connected: Boolean,
    loading: Boolean,
    onRefresh: () -> Unit,
    onDisconnect: () -> Unit,
) {
    Column(
        modifier = Modifier
            .fillMaxWidth()
            .background(Color.White)
            .padding(horizontal = 18.dp, vertical = 14.dp),
        verticalArrangement = Arrangement.spacedBy(7.dp),
    ) {
        Row(
            modifier = Modifier.fillMaxWidth(),
            horizontalArrangement = Arrangement.SpaceBetween,
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Column {
                Text("Trade", style = MaterialTheme.typography.headlineSmall, fontWeight = FontWeight.ExtraBold)
                Text("TON / GRAM • Bitpin", color = TradeMuted, style = MaterialTheme.typography.bodySmall)
            }
            StatusPill(if (connected) "API متصل" else "API قطع", connected)
        }
        Text(server, color = TradeMuted, style = MaterialTheme.typography.labelSmall)
        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            OutlinedButton(onClick = onRefresh, enabled = !loading) {
                Text(if (loading) "در حال بررسی..." else "بروزرسانی")
            }
            TextButton(onClick = onDisconnect) { Text("تغییر اتصال") }
        }
    }
}

@Composable
private fun HomeTab(
    mode: String,
    connected: Boolean,
    capitalAsset: String,
    ordersLogged: Int,
    killSwitch: Boolean,
    onOpenData: () -> Unit,
    onOpenTrade: () -> Unit,
    onOpenSafety: () -> Unit,
) {
    Column(
        modifier = Modifier
            .fillMaxSize()
            .verticalScroll(rememberScrollState())
            .padding(16.dp),
        verticalArrangement = Arrangement.spacedBy(12.dp),
    ) {
        Text("داشبورد", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Bold)
        Text("خلاصه وضعیت حساب و مسیرهای اصلی برنامه", color = TradeMuted)

        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(10.dp)) {
            MetricCard("دارایی پایه", capitalAsset.ifBlank { "-" }, Modifier.weight(1f))
            MetricCard("حالت", if (mode == "live") "Live" else "غیرفعال", Modifier.weight(1f))
        }
        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(10.dp)) {
            MetricCard("API", if (connected) "متصل" else "قطع", Modifier.weight(1f))
            MetricCard("سفارش ثبت‌شده", ordersLogged.toString(), Modifier.weight(1f))
        }

        InfoBox(
            title = "این اپ چه کار می‌کند؟",
            text = "اپ موبایل رابط امن با Backend روی rado-taxi.sbs است. مشاهده وضعیت، اطلاعات حساب، بازارها و سفارش‌ها از همین‌جا انجام می‌شود؛ کلید اصلی Bitpin روی سرور باقی می‌ماند.",
        )

        ActionCard(
            title = "اطلاعات حساب و بازار",
            description = "کیف پول، بازارهای Bitpin و سفارش‌های موجود را ببین.",
            button = "باز کردن داده‌ها",
            onClick = onOpenData,
        )
        ActionCard(
            title = "مدیریت سفارش",
            description = "فرم سفارش و لغو سفارش در یک بخش جدا و واضح قرار گرفته است.",
            button = "رفتن به معامله",
            onClick = onOpenTrade,
        )
        ActionCard(
            title = "ایمنی",
            description = if (killSwitch) "توقف اضطراری اکنون روشن است." else "توقف اضطراری اکنون خاموش است.",
            button = "کنترل ایمنی",
            onClick = onOpenSafety,
        )
    }
}

@Composable
private fun DataTab(
    loading: Boolean,
    walletsCount: Int,
    marketsCount: Int,
    ordersCount: Int,
    walletsText: String,
    marketsText: String,
    ordersText: String,
    onWallets: () -> Unit,
    onMarkets: () -> Unit,
    onOrders: () -> Unit,
) {
    var details by rememberSaveable { mutableStateOf("wallets") }

    Column(
        modifier = Modifier
            .fillMaxSize()
            .verticalScroll(rememberScrollState())
            .padding(16.dp),
        verticalArrangement = Arrangement.spacedBy(12.dp),
    ) {
        Text("داده‌های Bitpin", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Bold)
        Text("اطلاعات مستقیم از API؛ برای دریافت هر بخش دکمه همان کارت را بزن.", color = TradeMuted)

        DataCard("کیف پول", "موجودی و اطلاعات Wallet", walletsCount, loading, {
            details = "wallets"
            onWallets()
        })
        DataCard("بازارها", "لیست Marketها و شناسه بازار", marketsCount, loading, {
            details = "markets"
            onMarkets()
        })
        DataCard("سفارش‌ها", "سفارش‌های حساب", ordersCount, loading, {
            details = "orders"
            onOrders()
        })

        val text = when (details) {
            "markets" -> marketsText
            "orders" -> ordersText
            else -> walletsText
        }
        if (text.isNotBlank()) {
            Card(
                modifier = Modifier.fillMaxWidth(),
                shape = RoundedCornerShape(18.dp),
            ) {
                Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    Text("جزئیات پاسخ", fontWeight = FontWeight.Bold)
                    Text(
                        text = text.take(5000),
                        style = MaterialTheme.typography.bodySmall,
                        color = TradeMuted,
                    )
                    if (text.length > 5000) Text("نمایش خلاصه شده است.", color = TradeMuted)
                }
            }
        }
    }
}

@Composable
private fun TradeTab(
    loading: Boolean,
    connected: Boolean,
    mode: String,
    killSwitch: Boolean,
    marketId: String,
    amount: String,
    price: String,
    side: String,
    orderMode: String,
    cancelOrderId: String,
    onMarketId: (String) -> Unit,
    onAmount: (String) -> Unit,
    onPrice: (String) -> Unit,
    onSide: (String) -> Unit,
    onOrderMode: (String) -> Unit,
    onSubmit: () -> Unit,
    onCancelId: (String) -> Unit,
    onCancelOrder: () -> Unit,
    onOpenMarkets: () -> Unit,
) {
    val enabled = !loading && connected && mode == "live" && !killSwitch

    Column(
        modifier = Modifier
            .fillMaxSize()
            .verticalScroll(rememberScrollState())
            .padding(16.dp),
        verticalArrangement = Arrangement.spacedBy(12.dp),
    ) {
        Text("مدیریت سفارش", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Bold)
        Text("ورودی‌ها را قبل از ارسال دوباره بررسی کن.", color = TradeMuted)

        if (!enabled) {
            InfoBox(
                title = "ارسال سفارش در دسترس نیست",
                text = when {
                    killSwitch -> "توقف اضطراری روشن است. برای فعال‌شدن ارسال، ابتدا از بخش ایمنی وضعیت را بررسی کن."
                    !connected -> "اتصال API Bitpin تنظیم نشده یا در دسترس نیست."
                    mode != "live" -> "Live Trading روی Backend فعال نیست."
                    else -> "سیستم در حال پردازش است."
                },
            )
        }

        Card(modifier = Modifier.fillMaxWidth(), shape = RoundedCornerShape(20.dp)) {
            Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
                Text("سفارش جدید", fontWeight = FontWeight.Bold)
                OutlinedTextField(
                    value = marketId,
                    onValueChange = onMarketId,
                    label = { Text("Market ID") },
                    supportingText = { Text("شناسه را از بخش «داده‌ها ← بازارها» پیدا کن") },
                    singleLine = true,
                    modifier = Modifier.fillMaxWidth(),
                )
                OutlinedTextField(
                    value = amount,
                    onValueChange = onAmount,
                    label = { Text("مقدار") },
                    singleLine = true,
                    modifier = Modifier.fillMaxWidth(),
                )
                OutlinedTextField(
                    value = price,
                    onValueChange = onPrice,
                    label = { Text("قیمت") },
                    singleLine = true,
                    modifier = Modifier.fillMaxWidth(),
                )

                Text("جهت سفارش", color = TradeMuted)
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    FilterChip(selected = side == "buy", onClick = { onSide("buy") }, label = { Text("خرید") })
                    FilterChip(selected = side == "sell", onClick = { onSide("sell") }, label = { Text("فروش") })
                }

                Text("نوع سفارش", color = TradeMuted)
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    FilterChip(selected = orderMode == "limit", onClick = { onOrderMode("limit") }, label = { Text("Limit") })
                    FilterChip(selected = orderMode == "market", onClick = { onOrderMode("market") }, label = { Text("Market") })
                }

                OutlinedButton(onClick = onOpenMarkets, modifier = Modifier.fillMaxWidth()) {
                    Text("مشاهده لیست بازارها و Market ID")
                }
                Button(onClick = onSubmit, enabled = enabled, modifier = Modifier.fillMaxWidth()) {
                    Text(if (loading) "در حال ارسال..." else "ارسال سفارش")
                }
            }
        }

        Card(modifier = Modifier.fillMaxWidth(), shape = RoundedCornerShape(20.dp)) {
            Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
                Text("لغو سفارش", fontWeight = FontWeight.Bold)
                OutlinedTextField(
                    value = cancelOrderId,
                    onValueChange = onCancelId,
                    label = { Text("Order ID") },
                    supportingText = { Text("شناسه سفارش را از بخش سفارش‌ها بردار") },
                    singleLine = true,
                    modifier = Modifier.fillMaxWidth(),
                )
                OutlinedButton(
                    onClick = onCancelOrder,
                    enabled = !loading && connected && cancelOrderId.isNotBlank(),
                    modifier = Modifier.fillMaxWidth(),
                ) {
                    Text("ارسال درخواست لغو")
                }
            }
        }
    }
}

@Composable
private fun SafetyTab(
    connected: Boolean,
    killSwitch: Boolean,
    loading: Boolean,
    onKillSwitch: (Boolean) -> Unit,
    onRefresh: () -> Unit,
) {
    Column(
        modifier = Modifier
            .fillMaxSize()
            .verticalScroll(rememberScrollState())
            .padding(16.dp),
        verticalArrangement = Arrangement.spacedBy(12.dp),
    ) {
        Text("ایمنی و کنترل", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Bold)
        Text("کنترل‌های حساس برنامه در این بخش متمرکز شده‌اند.", color = TradeMuted)

        Card(modifier = Modifier.fillMaxWidth(), shape = RoundedCornerShape(20.dp)) {
            Column(Modifier.padding(18.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
                Text("توقف اضطراری", fontWeight = FontWeight.Bold)
                Text(
                    "با روشن‌کردن این گزینه، Backend قبل از پذیرش درخواست سفارش جدید آن را متوقف می‌کند.",
                    color = TradeMuted,
                )
                Row(
                    verticalAlignment = Alignment.CenterVertically,
                    horizontalArrangement = Arrangement.spacedBy(12.dp),
                ) {
                    Switch(
                        checked = killSwitch,
                        onCheckedChange = onKillSwitch,
                        enabled = connected && !loading,
                    )
                    Text(if (killSwitch) "روشن — سفارش جدید متوقف" else "خاموش")
                }
            }
        }

        InfoBox(
            title = "امنیت کلیدها",
            text = "API Key و Secret بیت‌پین باید فقط روی cPanel نگهداری شوند. APK فقط App API Token را نگه می‌دارد و ارتباط HTTP معمولی نیز غیرفعال است.",
        )

        OutlinedButton(onClick = onRefresh, enabled = !loading, modifier = Modifier.fillMaxWidth()) {
            Text("بررسی دوباره وضعیت سرور")
        }
    }
}

@Composable
private fun MetricCard(title: String, value: String, modifier: Modifier = Modifier) {
    Card(modifier = modifier, shape = RoundedCornerShape(18.dp)) {
        Column(Modifier.padding(15.dp), verticalArrangement = Arrangement.spacedBy(5.dp)) {
            Text(title, color = TradeMuted, style = MaterialTheme.typography.labelMedium)
            Text(value, fontWeight = FontWeight.ExtraBold, style = MaterialTheme.typography.titleMedium)
        }
    }
}

@Composable
private fun ActionCard(title: String, description: String, button: String, onClick: () -> Unit) {
    Card(modifier = Modifier.fillMaxWidth(), shape = RoundedCornerShape(20.dp)) {
        Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
            Text(title, fontWeight = FontWeight.Bold)
            Text(description, color = TradeMuted)
            TextButton(onClick = onClick) { Text(button) }
        }
    }
}

@Composable
private fun DataCard(
    title: String,
    description: String,
    count: Int,
    loading: Boolean,
    onClick: () -> Unit,
) {
    Card(modifier = Modifier.fillMaxWidth(), shape = RoundedCornerShape(20.dp)) {
        Row(
            modifier = Modifier.padding(16.dp),
            horizontalArrangement = Arrangement.spacedBy(12.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Column(Modifier.weight(1f)) {
                Text(title, fontWeight = FontWeight.Bold)
                Text(description, color = TradeMuted, style = MaterialTheme.typography.bodySmall)
                if (count > 0) Text("تعداد آیتم: $count", color = TradeBlue, style = MaterialTheme.typography.labelMedium)
            }
            OutlinedButton(onClick = onClick, enabled = !loading) { Text("دریافت") }
        }
    }
}

@Composable
private fun InfoBox(title: String, text: String) {
    Card(
        modifier = Modifier.fillMaxWidth(),
        shape = RoundedCornerShape(16.dp),
        colors = CardDefaults.cardColors(containerColor = Color(0xFFEEF5FF)),
    ) {
        Column(Modifier.padding(14.dp), verticalArrangement = Arrangement.spacedBy(5.dp)) {
            Text(title, fontWeight = FontWeight.Bold, color = Color(0xFF174A9C))
            Text(text, color = Color(0xFF365A8C), style = MaterialTheme.typography.bodySmall)
        }
    }
}

@Composable
private fun MessageCard(text: String, isError: Boolean) {
    Card(
        modifier = Modifier.fillMaxWidth(),
        shape = RoundedCornerShape(14.dp),
        colors = CardDefaults.cardColors(
            containerColor = if (isError) Color(0xFFFFEEEE) else Color(0xFFECFFF6),
        ),
    ) {
        Text(
            text = text,
            modifier = Modifier.padding(13.dp),
            color = if (isError) Color(0xFF9E2424) else Color(0xFF176548),
        )
    }
}

@Composable
private fun StatusPill(text: String, ok: Boolean) {
    Surface(
        shape = RoundedCornerShape(100.dp),
        color = if (ok) Color(0xFFE7F9F2) else Color(0xFFFFEEEE),
    ) {
        Text(
            text = text,
            modifier = Modifier.padding(horizontal = 11.dp, vertical = 7.dp),
            color = if (ok) Color(0xFF087857) else Color(0xFF9E2424),
            style = MaterialTheme.typography.labelMedium,
            fontWeight = FontWeight.Bold,
        )
    }
}

private fun readableHttpError(response: TradeApi.Response): String {
    return try {
        val root = JSONObject(response.body)
        root.optString("message").ifBlank { root.optString("error") }.ifBlank { "HTTP ${response.code}" }
    } catch (_: Exception) {
        "HTTP ${response.code}: ${response.body.take(300)}"
    }
}

private fun prettyJson(raw: String): String {
    return try {
        val trimmed = raw.trim()
        if (trimmed.startsWith("[")) JSONArray(trimmed).toString(2) else JSONObject(trimmed).toString(2)
    } catch (_: Exception) {
        raw
    }
}

private fun collectionCount(raw: String): Int {
    return try {
        val root = JSONObject(raw)
        countNode(root.opt("data"))
    } catch (_: Exception) {
        0
    }
}

private fun countNode(node: Any?): Int {
    return when (node) {
        is JSONArray -> node.length()
        is JSONObject -> {
            val candidates = listOf("results", "items", "wallets", "markets", "orders", "data")
            for (key in candidates) {
                val value = node.opt(key)
                if (value is JSONArray) return value.length()
            }
            node.length()
        }
        else -> 0
    }
}
