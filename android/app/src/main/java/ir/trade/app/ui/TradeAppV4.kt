package ir.trade.app.ui

import android.app.Activity
import android.hardware.biometrics.BiometricPrompt
import android.os.Build
import android.os.CancellationSignal
import androidx.annotation.RequiresApi
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
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
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Assessment
import androidx.compose.material.icons.rounded.AutoGraph
import androidx.compose.material.icons.rounded.Home
import androidx.compose.material.icons.rounded.Lock
import androidx.compose.material.icons.rounded.Notifications
import androidx.compose.material.icons.rounded.Refresh
import androidx.compose.material.icons.rounded.Settings
import androidx.compose.material.icons.rounded.ShowChart
import androidx.compose.material.icons.rounded.SwapHoriz
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.FilledTonalButton
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
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
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import ir.trade.app.BuildConfig
import ir.trade.app.TradeAlertWorker
import ir.trade.app.data.TradeApi
import ir.trade.app.data.TradePreferences
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import org.json.JSONArray
import org.json.JSONObject
import java.text.NumberFormat
import java.util.Locale
import kotlin.math.abs

private val V4Bg = Color(0xFFF4F6FA)
private val V4Ink = Color(0xFF151827)
private val V4Muted = Color(0xFF747B8E)
private val V4Primary = Color(0xFF5E49E8)
private val V4PrimarySoft = Color(0xFFF0EEFF)
private val V4Green = Color(0xFF087A5B)
private val V4GreenSoft = Color(0xFFE9F7F1)
private val V4Red = Color(0xFFC33F46)
private val V4RedSoft = Color(0xFFFFEFF0)
private val V4Amber = Color(0xFFA66300)
private val V4AmberSoft = Color(0xFFFFF5E5)
private val V4Blue = Color(0xFF2864D7)
private val V4BlueSoft = Color(0xFFEDF4FF)
private val V4Stroke = Color(0xFFE5E8F0)

private data class V4Nav(val label: String, val icon: ImageVector)

@Composable
fun TradeAppV4() {
    val context = LocalContext.current
    val prefs = remember { TradePreferences(context) }
    val scope = rememberCoroutineScope()
    val api = remember(prefs.serverUrl(), prefs.apiToken()) { TradeApi(prefs.serverUrl(), prefs.apiToken()) }

    var nav by rememberSaveable { mutableIntStateOf(0) }
    var snapshotJson by rememberSaveable { mutableStateOf(prefs.offlineSnapshot()) }
    var loading by remember { mutableStateOf(snapshotJson.isNullOrBlank()) }
    var offline by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    var locked by remember { mutableStateOf(prefs.biometricEnabled() && Build.VERSION.SDK_INT >= 28) }
    var historyJson by remember { mutableStateOf<String?>(null) }
    var replayJson by remember { mutableStateOf<String?>(null) }
    var emergencyConfirm by remember { mutableStateOf<String?>(null) }

    suspend fun refresh() {
        loading = snapshotJson.isNullOrBlank()
        error = null
        try {
            val status = api.status()
            check(status.ok) { "Backend ${status.code}" }
            check(api.isContractCompatible()) { "نسخه Backend و اپ هماهنگ نیست." }
            val response = api.commandCenter()
            check(response.ok) { "Command Center ${response.code}" }
            val data = unwrap(response.body)
            snapshotJson = data.toString()
            prefs.saveOfflineSnapshot(data.toString())
            offline = false
        } catch (e: Exception) {
            offline = !snapshotJson.isNullOrBlank()
            error = if (offline) "نمایش آخرین Snapshot ذخیره‌شده" else (e.message ?: "خطا در دریافت اطلاعات")
        } finally {
            loading = false
        }
    }

    LaunchedEffect(Unit) {
        refresh()
        while (true) {
            delay(30_000)
            refresh()
        }
    }

    LaunchedEffect(nav) {
        if (nav == 4 && historyJson == null) {
            runCatching { api.settingsHistory(30) }.getOrNull()?.takeIf { it.ok }?.let { historyJson = unwrapArrayResponse(it.body).toString() }
        }
    }

    MaterialTheme(
        colorScheme = lightColorScheme(
            primary = V4Primary,
            surface = Color.White,
            background = V4Bg,
            onSurface = V4Ink,
            onBackground = V4Ink,
        ),
    ) {
        if (locked) {
            BiometricGate(
                onUnlocked = { locked = false },
                onDisable = { prefs.setBiometricEnabled(false); locked = false },
            )
            return@MaterialTheme
        }

        val root = remember(snapshotJson) { snapshotJson?.let { runCatching { JSONObject(it) }.getOrNull() } }
        val tabs = listOf(
            V4Nav("خانه", Icons.Rounded.Home),
            V4Nav("بازار", Icons.Rounded.ShowChart),
            V4Nav("معاملات", Icons.Rounded.SwapHoriz),
            V4Nav("گزارش", Icons.Rounded.Assessment),
            V4Nav("تنظیمات", Icons.Rounded.Settings),
        )

        Scaffold(
            containerColor = V4Bg,
            bottomBar = {
                NavigationBar(containerColor = Color.White, modifier = Modifier.navigationBarsPadding()) {
                    tabs.forEachIndexed { index, item ->
                        NavigationBarItem(
                            selected = nav == index,
                            onClick = { nav = index },
                            icon = { Icon(item.icon, contentDescription = item.label) },
                            label = { Text(item.label) },
                            colors = NavigationBarItemDefaults.colors(
                                selectedIconColor = V4Primary,
                                selectedTextColor = V4Primary,
                                indicatorColor = V4PrimarySoft,
                                unselectedIconColor = V4Muted,
                                unselectedTextColor = V4Muted,
                            ),
                        )
                    }
                }
            },
        ) { padding ->
            Column(Modifier.fillMaxSize().padding(padding).statusBarsPadding()) {
                V4TopBar(
                    offline = offline,
                    loading = loading,
                    onRefresh = { scope.launch { refresh() } },
                )
                if (error != null) {
                    V4Banner(error.orEmpty(), if (offline) V4AmberSoft else V4RedSoft, if (offline) V4Amber else V4Red)
                }
                if (root == null && loading) {
                    Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) { CircularProgressIndicator() }
                } else if (root == null) {
                    Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) { Text(error ?: "اطلاعاتی موجود نیست") }
                } else {
                    when (nav) {
                        0 -> V4Home(root, offline)
                        1 -> V4Market(root)
                        2 -> V4Trades(root, onReplay = { id -> scope.launch {
                            val r = runCatching { api.tradeReplay(id) }.getOrNull()
                            replayJson = if (r?.ok == true) unwrap(r.body).toString() else "{\"error\":\"Replay unavailable\"}"
                        } })
                        3 -> V4Reports(root, api, onRefresh = { scope.launch { refresh() } })
                        else -> V4Settings(
                            root = root,
                            api = api,
                            prefs = prefs,
                            historyJson = historyJson,
                            onHistoryChanged = { historyJson = it },
                            onReload = { scope.launch { refresh() } },
                            onConfirmEmergency = { emergencyConfirm = it },
                            onLockNow = { if (prefs.biometricEnabled() && Build.VERSION.SDK_INT >= 28) locked = true },
                        )
                    }
                }
            }
        }

        if (emergencyConfirm != null) {
            val mode = emergencyConfirm!!
            AlertDialog(
                onDismissRequest = { emergencyConfirm = null },
                title = { Text("تأیید کنترل اضطراری") },
                text = { Text(emergencyDescription(mode)) },
                confirmButton = {
                    Button(
                        onClick = {
                            emergencyConfirm = null
                            scope.launch {
                                val r = runCatching { api.setEmergency(mode) }.getOrNull()
                                if (r?.ok != true) error = "اعمال حالت اضطراری ناموفق بود"
                                refresh()
                            }
                        },
                        colors = ButtonDefaults.buttonColors(containerColor = if (mode == "full_stop") V4Red else V4Primary),
                    ) { Text("اعمال") }
                },
                dismissButton = { TextButton(onClick = { emergencyConfirm = null }) { Text("لغو") } },
            )
        }

        if (replayJson != null) {
            val replay = runCatching { JSONObject(replayJson!!) }.getOrNull()
            AlertDialog(
                onDismissRequest = { replayJson = null },
                title = { Text("Trade Replay") },
                text = {
                    LazyColumn(Modifier.fillMaxWidth().height(420.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                        val steps = jsonObjects(replay?.optJSONArray("steps"))
                        if (steps.isEmpty()) item { Text(replay?.optString("error", "داده‌ای برای Replay نیست") ?: "—") }
                        items(steps) { step ->
                            V4MiniCard {
                                Text(step.optString("text", "رویداد"), fontWeight = FontWeight.Bold)
                                Text(step.optString("time", ""), color = V4Muted)
                            }
                        }
                    }
                },
                confirmButton = { TextButton(onClick = { replayJson = null }) { Text("بستن") } },
            )
        }
    }
}

@Composable
private fun V4TopBar(offline: Boolean, loading: Boolean, onRefresh: () -> Unit) {
    Row(
        Modifier.fillMaxWidth().background(Color.White).padding(horizontal = 18.dp, vertical = 12.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Column(Modifier.weight(1f)) {
            Text("Trade", fontWeight = FontWeight.Black, color = V4Ink, style = MaterialTheme.typography.titleLarge)
            Text("Command Center • v${BuildConfig.RELEASE_VERSION}${if (offline) " • Offline Snapshot" else ""}", color = if (offline) V4Amber else V4Muted, style = MaterialTheme.typography.labelMedium)
        }
        IconButton(onClick = onRefresh, enabled = !loading) {
            if (loading) CircularProgressIndicator(Modifier.size(22.dp), strokeWidth = 2.dp) else Icon(Icons.Rounded.Refresh, "بروزرسانی", tint = V4Primary)
        }
    }
}

@Composable
private fun V4Home(root: JSONObject, offline: Boolean) {
    val headline = root.optJSONObject("headline") ?: JSONObject()
    val strip = root.optJSONObject("status_strip") ?: JSONObject()
    val decision = root.optJSONObject("decision_explainability") ?: JSONObject()
    val alerts = jsonObjects(root.optJSONArray("alerts"))
    val activity = jsonObjects(root.optJSONArray("activity_timeline"))
    LazyColumn(
        Modifier.fillMaxSize(),
        contentPadding = PaddingValues(14.dp, 12.dp, 14.dp, 24.dp),
        verticalArrangement = Arrangement.spacedBy(12.dp),
    ) {
        item {
            V4Hero(
                title = when (headline.optString("bot_state")) {
                    "live" -> "ربات فعال"
                    "closing" -> "در حال بستن تدریجی"
                    "buys_paused" -> "خرید متوقف"
                    "full_stop" -> "توقف کامل"
                    else -> "ربات آماده نیست"
                },
                subtitle = "${headline.optInt("active_positions")}/${headline.optInt("effective_max_positions")} پوزیشن • ریسک ${strip.optString("risk", "—")}",
                chips = listOf(
                    "API ${strip.optString("api", "—")}",
                    "Circuit ${strip.optString("circuit", "—")}",
                    "Cron ${if (strip.optBoolean("cron_healthy")) "OK" else "CHECK"}",
                ),
            )
        }
        item {
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp), modifier = Modifier.fillMaxWidth()) {
                V4Metric("ارزش کل", money(headline.optDouble("portfolio_value_irt")), "تومان", Modifier.weight(1f))
                V4Metric("PnL امروز", signedMoney(headline.optDouble("today_net_pnl_irt")), "تومان", Modifier.weight(1f))
            }
        }
        item {
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp), modifier = Modifier.fillMaxWidth()) {
                V4Metric("PnL ماه", signedMoney(headline.optDouble("month_net_pnl_irt")), "تومان", Modifier.weight(1f))
                V4Metric("Drawdown", "${fmt(headline.optDouble("current_drawdown_percent"), 2)}%", "جاری", Modifier.weight(1f))
            }
        }
        if (offline) item { V4Banner("اطلاعات آفلاین است و برای تصمیم اجرایی نباید به‌عنوان وضعیت لحظه‌ای صرافی استفاده شود.", V4AmberSoft, V4Amber) }
        item { V4SectionTitle("چرا این تصمیم؟", "آخرین توضیح موتور") }
        item {
            V4Card {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Column(Modifier.weight(1f)) {
                        Text(decision.optString("symbol", "بدون نماد"), fontWeight = FontWeight.Black, style = MaterialTheme.typography.titleLarge)
                        Text(decision.optString("reason_fa", decision.optString("reason", "هنوز تصمیمی ثبت نشده")), color = V4Muted)
                    }
                    V4Pill(decision.optString("action", "—"), V4PrimarySoft, V4Primary)
                }
                Spacer(Modifier.height(10.dp))
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    V4TinyMetric("Edge", percentOrDash(decision, "tradable_edge_percent"), Modifier.weight(1f))
                    V4TinyMetric("Spread", percentOrDash(decision, "spread_percent"), Modifier.weight(1f))
                    V4TinyMetric("Score", decision.opt("score")?.toString() ?: "—", Modifier.weight(1f))
                }
                Text("Strategy: ${decision.optString("strategy", "—")}", color = V4Muted, style = MaterialTheme.typography.labelMedium)
            }
        }
        item { V4SectionTitle("هشدارها", if (alerts.isEmpty()) "همه‌چیز عادی است" else "${alerts.size} مورد نیازمند توجه") }
        if (alerts.isEmpty()) item { V4Banner("هشدار مهمی فعال نیست.", V4GreenSoft, V4Green) }
        items(alerts.take(8)) { alert ->
            val critical = alert.optString("priority") == "critical"
            V4Card(container = if (critical) V4RedSoft else V4AmberSoft) {
                Text(alert.optString("title", "هشدار"), fontWeight = FontWeight.Bold, color = if (critical) V4Red else V4Amber)
                Text(alert.optString("body", ""), color = V4Ink)
            }
        }
        item { V4SectionTitle("Activity Timeline", "تصمیم‌ها و معاملات به زبان ساده") }
        items(activity.take(12)) { event ->
            V4MiniCard {
                Text(event.optString("text_fa", "رویداد معاملاتی"), fontWeight = FontWeight.SemiBold)
                Text(event.optString("created_at_utc", ""), color = V4Muted, style = MaterialTheme.typography.labelSmall)
            }
        }
    }
}

@Composable
private fun V4Market(root: JSONObject) {
    val ranking = jsonObjects(root.optJSONArray("opportunity_ranking"))
    val radar = jsonObjects(root.optJSONArray("market_radar"))
    LazyColumn(
        Modifier.fillMaxSize(),
        contentPadding = PaddingValues(14.dp, 12.dp, 14.dp, 24.dp),
        verticalArrangement = Arrangement.spacedBy(10.dp),
    ) {
        item { V4SectionTitle("Market Radar", "Excellent / Good / Watch / Avoid") }
        val categories = listOf("excellent" to "Excellent", "good" to "Good", "watch" to "Watch", "avoid" to "Avoid")
        items(categories) { (key, label) ->
            val matches = radar.filter { it.optString("category") == key }.take(6)
            V4Card(container = categoryColor(key)) {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Text(label, fontWeight = FontWeight.Black, modifier = Modifier.weight(1f))
                    Text("${matches.size}", color = V4Muted)
                }
                if (matches.isEmpty()) Text("موردی در این گروه نیست.", color = V4Muted)
                matches.forEach { item ->
                    HorizontalDivider(color = V4Stroke)
                    Row(Modifier.fillMaxWidth().padding(vertical = 8.dp), verticalAlignment = Alignment.CenterVertically) {
                        Column(Modifier.weight(1f)) {
                            Text(item.optString("symbol"), fontWeight = FontWeight.Bold)
                            Text("${item.optString("strategy", "—")} • ${item.optString("regime", "—")}", color = V4Muted, maxLines = 1, overflow = TextOverflow.Ellipsis)
                        }
                        Text("Edge ${fmt(item.optDouble("edge_percent"), 2)}%", color = if (item.optDouble("edge_percent") >= 0) V4Green else V4Red)
                    }
                }
            }
        }
        item { V4SectionTitle("۱۰ فرصت برتر", "رتبه‌بندی نمایشی؛ سفارش خرید نیست") }
        items(ranking) { item ->
            V4Card {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Column(Modifier.weight(1f)) {
                        Text(item.optString("symbol"), fontWeight = FontWeight.Black, style = MaterialTheme.typography.titleMedium)
                        Text("${item.optString("action")} • ${item.optString("category")} • Score ${item.optInt("score")}", color = V4Muted)
                    }
                    Column(horizontalAlignment = Alignment.End) {
                        Text("${fmt(item.optDouble("edge_percent"), 3)}%", fontWeight = FontWeight.Bold, color = if (item.optDouble("edge_percent") >= 0) V4Green else V4Red)
                        Text("Spread ${fmt(item.optDouble("spread_percent"), 3)}%", color = V4Muted)
                    }
                }
            }
        }
        if (ranking.isEmpty()) item { V4Banner("هنوز داده کافی برای رتبه‌بندی بازار ثبت نشده است.", V4BlueSoft, V4Blue) }
    }
}

@Composable
private fun V4Trades(root: JSONObject, onReplay: (Long) -> Unit) {
    val positions = jsonObjects(root.optJSONArray("positions"))
    val activity = jsonObjects(root.optJSONArray("activity_timeline"))
    val heat = root.optJSONObject("risk_heatmap") ?: JSONObject()
    val cells = jsonObjects(heat.optJSONArray("cells")).filter { it.optString("x") < it.optString("y") }
    LazyColumn(
        Modifier.fillMaxSize(),
        contentPadding = PaddingValues(14.dp, 12.dp, 14.dp, 24.dp),
        verticalArrangement = Arrangement.spacedBy(10.dp),
    ) {
        item { V4SectionTitle("پوزیشن‌های فعال", "برای Trade Replay روی کارت بزن") }
        if (positions.isEmpty()) item { V4Banner("پوزیشن فعالی وجود ندارد.", V4BlueSoft, V4Blue) }
        items(positions) { p ->
            val pnl = p.optDouble("unrealized_net_pnl_percent")
            V4Card(modifier = Modifier.clickable { onReplay(p.optLong("id")) }) {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Column(Modifier.weight(1f)) {
                        Text(p.optString("symbol"), fontWeight = FontWeight.Black, style = MaterialTheme.typography.titleLarge)
                        Text("${p.optString("status")} • ${p.optLong("holding_seconds") / 60} دقیقه", color = V4Muted)
                    }
                    Text("${if (pnl >= 0) "+" else ""}${fmt(pnl, 2)}%", fontWeight = FontWeight.Black, color = if (pnl >= 0) V4Green else V4Red)
                }
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    V4TinyMetric("Entry", fmt(p.optDouble("entry_price"), 4), Modifier.weight(1f))
                    V4TinyMetric("Mark", fmt(p.optDouble("mark_price"), 4), Modifier.weight(1f))
                    V4TinyMetric("Fee", fmt(p.optDouble("total_estimated_fees_quote"), 4), Modifier.weight(1f))
                }
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    V4TinyMetric("SL", fmt(p.optDouble("stop_loss"), 4), Modifier.weight(1f))
                    V4TinyMetric("TP", fmt(p.optDouble("take_profit"), 4), Modifier.weight(1f))
                    V4TinyMetric("Exit", p.optString("probable_exit_reason", "—"), Modifier.weight(1f))
                }
            }
        }
        item { V4SectionTitle("Risk Heatmap", "همبستگی پوزیشن‌های فعال") }
        if (cells.isEmpty()) item { Text("برای Heatmap حداقل دو پوزیشن با داده کافی لازم است.", color = V4Muted) }
        items(cells.take(30)) { cell ->
            val risk = cell.optString("risk", "unknown")
            V4MiniCard {
                Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                    Text("${cell.optString("x")} / ${cell.optString("y")}", fontWeight = FontWeight.Bold, modifier = Modifier.weight(1f))
                    V4Pill(if (cell.isNull("correlation")) "—" else fmt(cell.optDouble("correlation"), 3), categoryColor(risk), when (risk) { "high" -> V4Red; "medium" -> V4Amber; else -> V4Green })
                }
            }
        }
        item { V4SectionTitle("معاملات اخیر", "Timeline تاییدشده") }
        items(activity.take(20)) { event ->
            V4MiniCard {
                Text(event.optString("text_fa", "رویداد"), fontWeight = FontWeight.SemiBold)
                Text(event.optString("created_at_utc", ""), color = V4Muted)
            }
        }
    }
}

@Composable
private fun V4Reports(root: JSONObject, api: TradeApi, onRefresh: () -> Unit) {
    val scope = rememberCoroutineScope()
    val performance = root.optJSONObject("performance") ?: JSONObject()
    val reports = root.optJSONObject("reports") ?: JSONObject()
    val strategies = jsonObjects(performance.optJSONArray("by_strategy"))
    val coins = jsonObjects(performance.optJSONArray("by_coin"))
    val curve = jsonObjects(root.optJSONArray("equity_curve"))
    val shadow = root.optJSONObject("shadow") ?: JSONObject()
    var positionPct by rememberSaveable { mutableStateOf("2") }
    var stopPct by rememberSaveable { mutableStateOf("3") }
    var takePct by rememberSaveable { mutableStateOf("5") }
    var labResult by remember { mutableStateOf<JSONObject?>(null) }
    var labBusy by remember { mutableStateOf(false) }

    LazyColumn(
        Modifier.fillMaxSize(),
        contentPadding = PaddingValues(14.dp, 12.dp, 14.dp, 24.dp),
        verticalArrangement = Arrangement.spacedBy(10.dp),
    ) {
        item { V4SectionTitle("گزارش عملکرد", "روزانه، هفتگی و ماهانه") }
        item {
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp), modifier = Modifier.fillMaxWidth()) {
                V4Metric("امروز", money(reports.optJSONObject("daily")?.optDouble("net_pnl") ?: 0.0), "تومان", Modifier.weight(1f))
                V4Metric("هفته", money(reports.optJSONObject("weekly")?.optDouble("net_pnl") ?: 0.0), "تومان", Modifier.weight(1f))
            }
        }
        item {
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp), modifier = Modifier.fillMaxWidth()) {
                V4Metric("ماه", money(reports.optJSONObject("monthly")?.optDouble("net_pnl") ?: 0.0), "تومان", Modifier.weight(1f))
                V4Metric("Profit Factor", fmt(reports.optDouble("profit_factor"), 2), "تحقق‌یافته", Modifier.weight(1f))
            }
        }
        item { V4SectionTitle("Equity Curve", "PnL تجمعی تحقق‌یافته") }
        item { V4EquityBars(curve) }
        item { V4SectionTitle("Performance by Strategy", "استراتژی و پروفایل") }
        items(strategies.take(12)) { s ->
            V4MiniCard {
                Row(Modifier.fillMaxWidth()) {
                    Column(Modifier.weight(1f)) {
                        Text(s.optString("strategy_key", "—"), fontWeight = FontWeight.Bold, maxLines = 1, overflow = TextOverflow.Ellipsis)
                        Text(s.optString("profile_key", "—"), color = V4Muted, maxLines = 1, overflow = TextOverflow.Ellipsis)
                    }
                    Column(horizontalAlignment = Alignment.End) {
                        Text("${s.optInt("trades")} معامله")
                        Text("Avg ${fmt(s.optDouble("average_return_percent"), 3)}%", color = if (s.optDouble("average_return_percent") >= 0) V4Green else V4Red)
                    }
                }
            }
        }
        item { V4SectionTitle("Performance by Coin", "بهترین و ضعیف‌ترین دارایی‌ها") }
        items(coins.take(12)) { c ->
            V4MiniCard {
                Row(Modifier.fillMaxWidth()) {
                    Text(c.optString("asset", "—"), fontWeight = FontWeight.Black, modifier = Modifier.weight(1f))
                    Text("${c.optInt("trades")} trade • Win ${fmt(c.optDouble("win_rate_percent"), 1)}%")
                }
            }
        }
        item { V4SectionTitle("Shadow Evaluation", "سیگنال‌های ۱۵ / ۶۰ / ۲۴۰ دقیقه") }
        item {
            V4Card {
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    V4TinyMetric("Samples", shadow.optInt("samples").toString(), Modifier.weight(1f))
                    V4TinyMetric("Avg 60m", if (shadow.isNull("average_return_60m")) "—" else "${fmt(shadow.optDouble("average_return_60m"), 3)}%", Modifier.weight(1f))
                    V4TinyMetric("Positive", if (shadow.isNull("positive_60m_rate_percent")) "—" else "${fmt(shadow.optDouble("positive_60m_rate_percent"), 1)}%", Modifier.weight(1f))
                }
            }
        }
        item { V4SectionTitle("Strategy Lab", "What-if تاریخی؛ بدون سفارش واقعی") }
        item {
            V4Card {
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    OutlinedTextField(positionPct, { positionPct = it }, label = { Text("Position %") }, modifier = Modifier.weight(1f), singleLine = true)
                    OutlinedTextField(stopPct, { stopPct = it }, label = { Text("SL %") }, modifier = Modifier.weight(1f), singleLine = true)
                    OutlinedTextField(takePct, { takePct = it }, label = { Text("TP %") }, modifier = Modifier.weight(1f), singleLine = true)
                }
                Button(
                    onClick = {
                        scope.launch {
                            labBusy = true
                            val body = JSONObject().put("position_percent", positionPct.toDoubleOrNull() ?: 2.0).put("stop_loss_percent", stopPct.toDoubleOrNull() ?: 3.0).put("take_profit_percent", takePct.toDoubleOrNull() ?: 5.0)
                            val r = runCatching { api.strategyLab(body.toString()) }.getOrNull()
                            labResult = if (r?.ok == true) unwrap(r.body) else JSONObject().put("error", "Strategy Lab unavailable")
                            labBusy = false
                        }
                    }, enabled = !labBusy, modifier = Modifier.fillMaxWidth(),
                ) { if (labBusy) CircularProgressIndicator(Modifier.size(18.dp), strokeWidth = 2.dp) else Text("اجرای سناریو") }
                labResult?.let { result ->
                    HorizontalDivider(color = V4Stroke)
                    if (result.has("error")) Text(result.optString("error"), color = V4Red) else {
                        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                            V4TinyMetric("Win", "${fmt(result.optDouble("win_rate_percent"), 1)}%", Modifier.weight(1f))
                            V4TinyMetric("Avg", "${fmt(result.optDouble("average_simulated_return_percent"), 3)}%", Modifier.weight(1f))
                            V4TinyMetric("Max DD", "${fmt(result.optDouble("max_drawdown_percent"), 2)}%", Modifier.weight(1f))
                        }
                        Text(result.optString("note"), color = V4Muted, style = MaterialTheme.typography.labelSmall)
                    }
                }
            }
        }
    }
}

@Composable
private fun V4Settings(
    root: JSONObject,
    api: TradeApi,
    prefs: TradePreferences,
    historyJson: String?,
    onHistoryChanged: (String?) -> Unit,
    onReload: () -> Unit,
    onConfirmEmergency: (String) -> Unit,
    onLockNow: () -> Unit,
) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    val settings = root.optJSONObject("settings") ?: JSONObject()
    val current = settings.optJSONObject("current") ?: JSONObject()
    val presets = settings.optJSONObject("presets") ?: JSONObject()
    val rules = root.optJSONObject("notification_rules") ?: JSONObject()
    val shadow = root.optJSONObject("shadow") ?: JSONObject()
    val emergency = root.optJSONObject("emergency") ?: JSONObject()
    val history = historyJson?.let { runCatching { JSONArray(it) }.getOrNull() }?.let(::jsonObjects) ?: emptyList()

    var alertsEnabled by remember { mutableStateOf(prefs.alertsEnabled()) }
    var biometric by remember { mutableStateOf(prefs.biometricEnabled()) }
    var shadowEnabled by remember(root.toString()) { mutableStateOf(shadow.optBoolean("enabled", true)) }
    var minPriority by remember(root.toString()) { mutableStateOf(rules.optString("min_priority", "warning")) }
    var busy by remember { mutableStateOf(false) }
    var customPosition by remember(root.toString()) { mutableStateOf(current.optDouble("position_percent", 2.0).toString()) }
    var customExposure by remember(root.toString()) { mutableStateOf(current.optDouble("nobitex_portfolio_exposure_percent", 35.0).toString()) }
    var customMax by remember(root.toString()) { mutableStateOf(current.optInt("nobitex_max_positions", 6).toString()) }
    var customDaily by remember(root.toString()) { mutableStateOf(current.optDouble("daily_loss_limit_percent", 2.0).toString()) }
    var preview by remember { mutableStateOf<JSONObject?>(null) }

    fun reloadHistory() {
        scope.launch {
            val r = runCatching { api.settingsHistory(30) }.getOrNull()
            if (r?.ok == true) onHistoryChanged(unwrapArrayResponse(r.body).toString())
        }
    }

    LazyColumn(
        Modifier.fillMaxSize(),
        contentPadding = PaddingValues(14.dp, 12.dp, 14.dp, 24.dp),
        verticalArrangement = Arrangement.spacedBy(10.dp),
    ) {
        item { V4SectionTitle("کنترل اضطراری", "وضعیت فعلی: ${emergency.optString("mode", "normal")}") }
        item {
            V4Card(container = if (emergency.optString("mode") == "normal") Color.White else V4RedSoft) {
                listOf(
                    "normal" to "حالت عادی",
                    "pause_buys" to "توقف خرید",
                    "graceful_close" to "بستن تدریجی",
                    "full_stop" to "توقف کامل",
                ).chunked(2).forEach { row ->
                    Row(horizontalArrangement = Arrangement.spacedBy(8.dp), modifier = Modifier.fillMaxWidth()) {
                        row.forEach { (mode, label) ->
                            OutlinedButton(onClick = { onConfirmEmergency(mode) }, modifier = Modifier.weight(1f)) { Text(label) }
                        }
                    }
                }
            }
        }
        item { V4SectionTitle("Presetها", "محافظه‌کار، متعادل یا تهاجمی") }
        item {
            V4Card {
                listOf("safe", "balanced", "aggressive").forEach { key ->
                    val preset = presets.optJSONObject(key) ?: JSONObject()
                    Row(Modifier.fillMaxWidth().padding(vertical = 4.dp), verticalAlignment = Alignment.CenterVertically) {
                        Column(Modifier.weight(1f)) {
                            Text(preset.optString("label", key), fontWeight = FontWeight.Bold)
                            Text("Position ${fmt(preset.optDouble("position_percent"),1)}% • Exposure ${fmt(preset.optDouble("nobitex_portfolio_exposure_percent"),0)}%", color = V4Muted)
                        }
                        FilledTonalButton(
                            onClick = {
                                scope.launch {
                                    busy = true
                                    runCatching { api.applyPreset(key) }
                                    reloadHistory(); onReload(); busy = false
                                }
                            }, enabled = !busy,
                        ) { Text("اعمال") }
                    }
                }
            }
        }
        item { V4SectionTitle("تنظیمات سفارشی + Preview", "قبل از ذخیره تغییر ریسک را ببین") }
        item {
            V4Card {
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    OutlinedTextField(customPosition, { customPosition = it }, label = { Text("Buy %") }, modifier = Modifier.weight(1f), singleLine = true)
                    OutlinedTextField(customExposure, { customExposure = it }, label = { Text("Exposure %") }, modifier = Modifier.weight(1f), singleLine = true)
                }
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    OutlinedTextField(customMax, { customMax = it }, label = { Text("Max positions") }, modifier = Modifier.weight(1f), singleLine = true)
                    OutlinedTextField(customDaily, { customDaily = it }, label = { Text("Daily loss %") }, modifier = Modifier.weight(1f), singleLine = true)
                }
                val body = JSONObject()
                    .put("position_percent", customPosition.toDoubleOrNull() ?: current.optDouble("position_percent",2.0))
                    .put("nobitex_portfolio_exposure_percent", customExposure.toDoubleOrNull() ?: current.optDouble("nobitex_portfolio_exposure_percent",35.0))
                    .put("nobitex_max_positions", customMax.toIntOrNull() ?: current.optInt("nobitex_max_positions",6))
                    .put("daily_loss_limit_percent", customDaily.toDoubleOrNull() ?: current.optDouble("daily_loss_limit_percent",2.0))
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    OutlinedButton(onClick = { scope.launch { val r=runCatching{api.previewSettings(body.toString())}.getOrNull();preview=if(r?.ok==true)unwrap(r.body) else null } }, modifier = Modifier.weight(1f)) { Text("Preview") }
                    Button(onClick = { scope.launch { busy=true; val r=runCatching{api.updateBotSettings(body.toString())}.getOrNull(); if(r?.ok==true){reloadHistory();onReload()};busy=false } }, enabled=!busy, modifier=Modifier.weight(1f)) { Text("ذخیره") }
                }
                preview?.let { p ->
                    val before=p.optJSONObject("risk_before")?:JSONObject();val after=p.optJSONObject("risk_after")?:JSONObject()
                    V4Banner("Risk ${fmt(before.optDouble("score"),1)} → ${fmt(after.optDouble("score"),1)} • ${p.optString("impact")}", V4BlueSoft, V4Blue)
                }
            }
        }
        item { V4SectionTitle("Shadow / Paper", "ارزیابی بدون افزایش ریسک") }
        item {
            V4SettingRow("Shadow evaluation", "نتیجه سیگنال‌ها را بعداً مقایسه می‌کند", shadowEnabled) { enabled ->
                shadowEnabled = enabled
                scope.launch { runCatching { api.setShadowMode(enabled) }; onReload() }
            }
        }
        item { V4SectionTitle("اعلان‌های هوشمند", "WorkManager هر ۱۵ دقیقه هشدارهای مهم را بررسی می‌کند") }
        item {
            V4SettingRow("اعلان پس‌زمینه", "Critical / Warning طبق قوانین پنل", alertsEnabled) { enabled ->
                alertsEnabled = enabled
                prefs.setAlertsEnabled(enabled)
                if (enabled) TradeAlertWorker.schedule(context) else TradeAlertWorker.cancel(context)
            }
        }
        item {
            V4Card {
                Text("حداقل Priority", fontWeight = FontWeight.Bold)
                Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                    listOf("info", "success", "warning", "critical").forEach { p ->
                        FilledTonalButton(onClick = {
                            minPriority = p
                            scope.launch {
                                val body = JSONObject().put("min_priority", p).put("enabled", true)
                                runCatching { api.updateNotificationRules(body.toString()) }
                                onReload()
                            }
                        }, colors = ButtonDefaults.filledTonalButtonColors(containerColor = if (minPriority == p) V4PrimarySoft else Color.White)) { Text(p) }
                    }
                }
            }
        }
        item { V4SectionTitle("امنیت اپ", "Biometric Lock") }
        item {
            V4SettingRow("قفل بیومتریک", if (Build.VERSION.SDK_INT >= 28) "برای بازکردن اپ اثرانگشت/بیومتریک بخواه" else "در Android این دستگاه پشتیبانی نمی‌شود", biometric, enabled = Build.VERSION.SDK_INT >= 28) { enabled ->
                biometric = enabled; prefs.setBiometricEnabled(enabled); if (enabled) onLockNow()
            }
        }
        item { V4SectionTitle("Offline Snapshot", "آخرین Command Center به‌صورت رمزگذاری‌شده روی دستگاه") }
        item {
            V4Card {
                Text(if (prefs.offlineSnapshotAt() > 0) "Snapshot ذخیره شده است." else "هنوز Snapshot ذخیره نشده است.", fontWeight = FontWeight.Bold)
                Text("این Snapshot فقط برای مشاهده آفلاین است و مبنای اجرای معامله نیست.", color = V4Muted)
            }
        }
        item { V4SectionTitle("Settings History + Rollback", "${history.size} Snapshot اخیر") }
        items(history.take(15)) { h ->
            V4MiniCard {
                Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                    Column(Modifier.weight(1f)) {
                        Text("#${h.optLong("id")} • ${h.optString("label")}", fontWeight = FontWeight.Bold)
                        Text("${h.optString("source")} • ${h.optString("created_at")}", color = V4Muted)
                    }
                    TextButton(onClick = {
                        scope.launch {
                            busy = true
                            runCatching { api.rollbackSettings(h.optLong("id")) }
                            reloadHistory(); onReload(); busy = false
                        }
                    }, enabled = !busy) { Text("Rollback") }
                }
            }
        }
    }
}

@Composable
private fun BiometricGate(onUnlocked: () -> Unit, onDisable: () -> Unit) {
    val context = LocalContext.current
    var message by remember { mutableStateOf("برای ورود هویت خود را تأیید کن") }
    var launched by remember { mutableStateOf(false) }

    LaunchedEffect(Unit) {
        if (!launched && Build.VERSION.SDK_INT >= 28) {
            launched = true
            val activity = context as? Activity
            if (activity == null) onDisable() else launchBiometric(activity, onUnlocked) { message = it }
        }
    }
    Surface(Modifier.fillMaxSize(), color = V4Bg) {
        Box(Modifier.fillMaxSize().padding(24.dp), contentAlignment = Alignment.Center) {
            V4Card {
                Icon(Icons.Rounded.Lock, null, tint = V4Primary, modifier = Modifier.size(42.dp))
                Text("Trade قفل است", fontWeight = FontWeight.Black, style = MaterialTheme.typography.headlineSmall)
                Text(message, color = V4Muted)
                if (Build.VERSION.SDK_INT < 28) Button(onClick = onDisable) { Text("غیرفعال‌کردن قفل") }
                else OutlinedButton(onClick = {
                    val activity = context as? Activity ?: return@OutlinedButton
                    launchBiometric(activity, onUnlocked) { message = it }
                }) { Text("تلاش دوباره") }
            }
        }
    }
}

@RequiresApi(28)
private fun launchBiometric(activity: Activity, onSuccess: () -> Unit, onError: (String) -> Unit) {
    val prompt = BiometricPrompt.Builder(activity)
        .setTitle("Trade")
        .setSubtitle("تأیید هویت برای ورود")
        .setNegativeButton("لغو", activity.mainExecutor) { _, _ -> onError("احراز هویت لغو شد") }
        .build()
    prompt.authenticate(CancellationSignal(), activity.mainExecutor, object : BiometricPrompt.AuthenticationCallback() {
        override fun onAuthenticationSucceeded(result: BiometricPrompt.AuthenticationResult?) { onSuccess() }
        override fun onAuthenticationError(errorCode: Int, errString: CharSequence?) { onError(errString?.toString() ?: "خطای بیومتریک") }
        override fun onAuthenticationFailed() { onError("احراز هویت ناموفق بود؛ دوباره امتحان کن") }
    })
}

@Composable
private fun V4Hero(title: String, subtitle: String, chips: List<String>) {
    Card(colors = CardDefaults.cardColors(containerColor = V4Ink), shape = RoundedCornerShape(24.dp)) {
        Column(Modifier.fillMaxWidth().padding(20.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Icon(Icons.Rounded.AutoGraph, null, tint = Color(0xFFBDB4FF), modifier = Modifier.size(34.dp))
                Spacer(Modifier.size(10.dp))
                Column(Modifier.weight(1f)) {
                    Text(title, color = Color.White, fontWeight = FontWeight.Black, style = MaterialTheme.typography.headlineSmall)
                    Text(subtitle, color = Color(0xFFC7CAD4))
                }
            }
            Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) { chips.take(3).forEach { V4Pill(it, Color(0xFF292D42), Color.White) } }
        }
    }
}

@Composable
private fun V4Card(modifier: Modifier = Modifier, container: Color = Color.White, content: @Composable Column.() -> Unit) {
    Card(modifier.fillMaxWidth(), shape = RoundedCornerShape(20.dp), colors = CardDefaults.cardColors(containerColor = container), elevation = CardDefaults.cardElevation(defaultElevation = 0.dp), border = androidx.compose.foundation.BorderStroke(1.dp, V4Stroke)) {
        Column(Modifier.fillMaxWidth().padding(15.dp), verticalArrangement = Arrangement.spacedBy(9.dp), content = content)
    }
}

@Composable
private fun V4MiniCard(content: @Composable Column.() -> Unit) {
    Column(Modifier.fillMaxWidth().background(Color.White, RoundedCornerShape(14.dp)).padding(12.dp), verticalArrangement = Arrangement.spacedBy(4.dp), content = content)
}

@Composable
private fun V4Metric(label: String, value: String, hint: String, modifier: Modifier = Modifier) {
    Card(modifier, shape = RoundedCornerShape(18.dp), colors = CardDefaults.cardColors(containerColor = Color.White), border = androidx.compose.foundation.BorderStroke(1.dp, V4Stroke)) {
        Column(Modifier.padding(14.dp)) {
            Text(label, color = V4Muted, style = MaterialTheme.typography.labelMedium)
            Text(value, color = V4Ink, fontWeight = FontWeight.Black, style = MaterialTheme.typography.titleLarge, maxLines = 1, overflow = TextOverflow.Ellipsis)
            Text(hint, color = V4Muted, style = MaterialTheme.typography.labelSmall)
        }
    }
}

@Composable
private fun V4TinyMetric(label: String, value: String, modifier: Modifier = Modifier) {
    Column(modifier.background(V4Bg, RoundedCornerShape(12.dp)).padding(9.dp)) {
        Text(label, color = V4Muted, style = MaterialTheme.typography.labelSmall)
        Text(value, fontWeight = FontWeight.Bold, maxLines = 1, overflow = TextOverflow.Ellipsis)
    }
}

@Composable
private fun V4Pill(text: String, bg: Color, fg: Color) {
    Text(text, modifier = Modifier.background(bg, RoundedCornerShape(999.dp)).padding(horizontal = 10.dp, vertical = 6.dp), color = fg, style = MaterialTheme.typography.labelMedium, maxLines = 1)
}

@Composable
private fun V4Banner(text: String, bg: Color, fg: Color) {
    Text(text, modifier = Modifier.fillMaxWidth().padding(horizontal = 14.dp, vertical = 5.dp).background(bg, RoundedCornerShape(13.dp)).padding(11.dp), color = fg, style = MaterialTheme.typography.bodySmall)
}

@Composable
private fun V4SectionTitle(title: String, subtitle: String) {
    Column(Modifier.padding(top = 4.dp)) {
        Text(title, fontWeight = FontWeight.Black, style = MaterialTheme.typography.titleLarge)
        Text(subtitle, color = V4Muted, style = MaterialTheme.typography.bodySmall)
    }
}

@Composable
private fun V4SettingRow(title: String, subtitle: String, checked: Boolean, enabled: Boolean = true, onChange: (Boolean) -> Unit) {
    V4Card {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Column(Modifier.weight(1f)) {
                Text(title, fontWeight = FontWeight.Bold)
                Text(subtitle, color = V4Muted, style = MaterialTheme.typography.bodySmall)
            }
            Switch(checked = checked, onCheckedChange = onChange, enabled = enabled)
        }
    }
}

@Composable
private fun V4EquityBars(curve: List<JSONObject>) {
    V4Card {
        if (curve.isEmpty()) {
            Text("داده کافی برای نمودار وجود ندارد.", color = V4Muted)
            return@V4Card
        }
        val values = curve.map { it.optDouble("cumulative_net_pnl") }
        val min = values.minOrNull() ?: 0.0
        val max = values.maxOrNull() ?: 0.0
        val span = (max - min).takeIf { abs(it) > 0.000001 } ?: 1.0
        Row(Modifier.fillMaxWidth().height(150.dp), horizontalArrangement = Arrangement.spacedBy(2.dp), verticalAlignment = Alignment.Bottom) {
            curve.takeLast(30).forEach { point ->
                val normalized = ((point.optDouble("cumulative_net_pnl") - min) / span).coerceIn(0.0, 1.0)
                Box(Modifier.weight(1f).height((20 + normalized * 120).dp).background(if (point.optDouble("cumulative_net_pnl") >= 0) V4Green else V4Red, RoundedCornerShape(topStart = 3.dp, topEnd = 3.dp)))
            }
        }
        Text("کمینه ${money(min)} • بیشینه ${money(max)} تومان", color = V4Muted, style = MaterialTheme.typography.labelSmall)
    }
}

private fun unwrap(body: String): JSONObject {
    val root = JSONObject(body)
    return root.optJSONObject("data") ?: root
}

private fun unwrapArrayResponse(body: String): JSONArray {
    val root = JSONObject(body)
    return root.optJSONArray("data") ?: JSONArray()
}

private fun jsonObjects(array: JSONArray?): List<JSONObject> {
    if (array == null) return emptyList()
    return buildList {
        for (i in 0 until array.length()) array.optJSONObject(i)?.let(::add)
    }
}

private fun categoryColor(category: String): Color = when (category.lowercase()) {
    "excellent", "low" -> V4GreenSoft
    "good" -> V4BlueSoft
    "watch", "medium" -> V4AmberSoft
    "avoid", "high", "critical" -> V4RedSoft
    else -> V4Bg
}

private fun fmt(value: Double, digits: Int = 2): String = String.format(Locale.US, "%.${digits}f", value)
private fun money(value: Double): String = NumberFormat.getNumberInstance(Locale.US).apply { maximumFractionDigits = 0 }.format(value)
private fun signedMoney(value: Double): String = (if (value > 0) "+" else "") + money(value)
private fun percentOrDash(obj: JSONObject, key: String): String = if (!obj.has(key) || obj.isNull(key)) "—" else "${fmt(obj.optDouble(key), 3)}%"
private fun emergencyDescription(mode: String): String = when (mode) {
    "normal" -> "حالت اضطراری لغو و ورودهای جدید دوباره طبق قوانین عادی مجاز می‌شوند."
    "pause_buys" -> "خریدهای جدید متوقف می‌شوند؛ خروج‌ها و Reconcile همچنان فعال می‌مانند."
    "graceful_close" -> "خرید متوقف می‌شود و پوزیشن‌های باز به‌صورت ترتیبی و کنترل‌شده بسته می‌شوند. پس از پایان، خرید همچنان متوقف می‌ماند."
    "full_stop" -> "Kill Switch فعال می‌شود. از این گزینه فقط وقتی توقف کامل لازم است استفاده کن."
    else -> "اعمال کنترل اضطراری"
}
