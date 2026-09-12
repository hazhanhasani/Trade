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
import androidx.compose.foundation.lazy.LazyListState
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.lazy.rememberLazyListState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Assessment
import androidx.compose.material.icons.rounded.AutoGraph
import androidx.compose.material.icons.rounded.Home
import androidx.compose.material.icons.rounded.Lock
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
import androidx.compose.runtime.CompositionLocalProvider
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
import androidx.compose.ui.platform.LocalLayoutDirection
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.LayoutDirection
import androidx.compose.ui.unit.dp
import ir.trade.app.BuildConfig
import ir.trade.app.TradeAlertWorker
import ir.trade.app.data.TradeApi
import ir.trade.app.data.TradePreferences
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
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
private val FaLocale = Locale("fa", "IR")
private const val LIVE_REFRESH_MS = 30_000L

private data class V4Nav(val label: String, val icon: ImageVector)

@Composable
fun TradeAppV4() {
    val context = LocalContext.current
    val prefs = remember { TradePreferences(context) }
    val scope = rememberCoroutineScope()
    val api = remember(prefs.serverUrl(), prefs.apiToken()) { TradeApi(prefs.serverUrl(), prefs.apiToken()) }

    var nav by rememberSaveable { mutableIntStateOf(0) }
    // The command-center JSON can become large. It must stay out of Android's
    // saved-instance-state Bundle; the encrypted offline copy already persists it.
    var snapshotJson by remember { mutableStateOf(prefs.offlineSnapshot()) }
    var loading by remember { mutableStateOf(snapshotJson.isNullOrBlank()) }
    var refreshing by remember { mutableStateOf(false) }
    var offline by remember { mutableStateOf(!snapshotJson.isNullOrBlank()) }
    var error by remember { mutableStateOf<String?>(null) }
    var locked by remember { mutableStateOf(prefs.biometricEnabled() && Build.VERSION.SDK_INT >= 28) }
    var historyJson by remember { mutableStateOf<String?>(null) }
    var replayJson by remember { mutableStateOf<String?>(null) }
    var emergencyConfirm by remember { mutableStateOf<String?>(null) }

    // Keep each page where the user left it instead of jumping to the top whenever
    // tabs are switched or a live refresh recomposes the screen.
    val homeScroll = rememberLazyListState()
    val marketScroll = rememberLazyListState()
    val tradesScroll = rememberLazyListState()
    val reportsScroll = rememberLazyListState()
    val settingsScroll = rememberLazyListState()

    suspend fun refresh() {
        if (refreshing) return
        refreshing = true
        loading = snapshotJson.isNullOrBlank()
        error = null
        try {
            val status = api.status()
            check(status.ok) { "Backend ${status.code}" }
            check(api.isContractCompatible()) { "نسخه Backend و اپ هماهنگ نیست." }
            val response = api.commandCenter()
            check(response.ok) { "مرکز فرمان ${response.code}" }
            val data = unwrap(response.body)
            val serialized = data.toString()
            snapshotJson = serialized
            withContext(Dispatchers.IO) { prefs.saveOfflineSnapshot(serialized) }
            offline = false
        } catch (e: Exception) {
            offline = !snapshotJson.isNullOrBlank()
            error = if (offline) "نمایش آخرین نسخه ذخیره‌شده" else (e.message ?: "خطا در دریافت اطلاعات")
        } finally {
            loading = false
            refreshing = false
        }
    }

    LaunchedEffect(Unit) {
        refresh()
        while (true) {
            delay(LIVE_REFRESH_MS)
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

        CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Rtl) {
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
                        loading = loading || refreshing,
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
                            0 -> V4Home(root, offline, homeScroll)
                            1 -> V4Market(root, marketScroll)
                            2 -> V4Trades(root, tradesScroll, onReplay = { id -> scope.launch {
                                val r = runCatching { api.tradeReplay(id) }.getOrNull()
                                replayJson = if (r?.ok == true) unwrap(r.body).toString() else "{\"error\":\"بازپخش معامله در دسترس نیست\"}"
                            } })
                            3 -> V4Reports(root, api, reportsScroll)
                            else -> V4Settings(
                                root = root,
                                api = api,
                                prefs = prefs,
                                historyJson = historyJson,
                                listState = settingsScroll,
                                onHistoryChanged = { historyJson = it },
                                onReload = { scope.launch { refresh() } },
                                onConfirmEmergency = { emergencyConfirm = it },
                                onLockNow = { if (prefs.biometricEnabled() && Build.VERSION.SDK_INT >= 28) locked = true },
                            )
                        }
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
                title = { Text("بازپخش معامله") },
                text = {
                    LazyColumn(Modifier.fillMaxWidth().height(420.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                        val steps = jsonObjects(replay?.optJSONArray("steps"))
                        if (steps.isEmpty()) item { Text(replay?.optString("error", "داده‌ای برای بازپخش نیست") ?: "—") }
                        items(steps, key = { "replay:${it.optString("time")}:${it.optString("text")}" }) { step ->
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
            Text(
                "${if (offline) "نسخه آفلاین" else "داده زنده"} • نسخه ${BuildConfig.RELEASE_VERSION}",
                color = if (offline) V4Amber else V4Green,
                style = MaterialTheme.typography.labelMedium,
            )
        }
        IconButton(onClick = onRefresh, enabled = !loading) {
            if (loading) CircularProgressIndicator(Modifier.size(22.dp), strokeWidth = 2.dp) else Icon(Icons.Rounded.Refresh, "بروزرسانی", tint = V4Primary)
        }
    }
}

@Composable
private fun V4Home(root: JSONObject, offline: Boolean, listState: LazyListState) {
    val headline = root.optJSONObject("headline") ?: JSONObject()
    val strip = root.optJSONObject("status_strip") ?: JSONObject()
    val decision = root.optJSONObject("decision_explainability") ?: JSONObject()
    val alerts = jsonObjects(root.optJSONArray("alerts"))
    val activity = jsonObjects(root.optJSONArray("activity_timeline"))
    LazyColumn(
        state = listState,
        modifier = Modifier.fillMaxSize(),
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
                subtitle = "${faInt(headline.optInt("active_positions"))}/${faInt(headline.optInt("effective_max_positions"))} پوزیشن • ریسک ${riskFa(strip.optString("risk", "—"))}",
                chips = listOf(
                    "API ${statusFa(strip.optString("api", "—"))}",
                    "مدار ریسک ${statusFa(strip.optString("circuit", "—"))}",
                    "زمان‌بندی ${if (strip.optBoolean("cron_healthy")) "سالم" else "نیازمند بررسی"}",
                ),
            )
        }
        item {
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp), modifier = Modifier.fillMaxWidth()) {
                V4Metric("ارزش کل", money(headline.optDouble("portfolio_value_irt")), "تومان • کیف پول زنده", Modifier.weight(1f))
                V4Metric("سود/زیان امروز ربات", signedMoney(headline.optDouble("today_net_pnl_irt")), "تومان • بازارهای تومانی", Modifier.weight(1f))
            }
        }
        item {
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp), modifier = Modifier.fillMaxWidth()) {
                V4Metric("سود/زیان کل ربات", signedMoney(headline.optDouble("total_realized_pnl_irt")), "تومان • بازارهای تومانی", Modifier.weight(1f))
                V4Metric("افت ارزش کیف پول", "${fmt(headline.optDouble("current_drawdown_percent"), 2)}٪", "بدون تعدیل واریز/برداشت", Modifier.weight(1f))
            }
        }
        item {
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp), modifier = Modifier.fillMaxWidth()) {
                V4Metric("نرخ برد", "${fmt(headline.optDouble("win_rate_percent"), 1)}٪", "${faInt(headline.optInt("closed_positions"))} معامله بسته", Modifier.weight(1f))
                V4Metric("در انتظار", "${faInt(headline.optInt("pending_orders"))}/${faInt(headline.optInt("max_pending_orders"))}", "سفارش در انتظار", Modifier.weight(1f))
            }
        }
        if (!offline) item { V4Banner("همگام با داده زنده پنل • بروزرسانی خودکار هر ۳۰ ثانیه", V4GreenSoft, V4Green) }
        if (offline) item { V4Banner("اطلاعات آفلاین است و برای تصمیم اجرایی نباید به‌عنوان وضعیت لحظه‌ای صرافی استفاده شود.", V4AmberSoft, V4Amber) }
        item { V4SectionTitle("چرا این تصمیم؟", "آخرین توضیح موتور") }
        item {
            V4Card {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Column(Modifier.weight(1f)) {
                        Text(decision.optString("symbol", "بدون نماد"), fontWeight = FontWeight.Black, style = MaterialTheme.typography.titleLarge)
                        Text(decision.optString("reason_fa", decision.optString("reason", "هنوز تصمیمی ثبت نشده")), color = V4Muted)
                    }
                    V4Pill(actionFa(decision.optString("action", "—")), V4PrimarySoft, V4Primary)
                }
                Spacer(Modifier.height(10.dp))
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    V4TinyMetric("لبه خالص", percentOrDash(decision, "tradable_edge_percent"), Modifier.weight(1f))
                    V4TinyMetric("اختلاف خرید/فروش", percentOrDash(decision, "spread_percent"), Modifier.weight(1f))
                    V4TinyMetric("امتیاز", decision.opt("score")?.toString()?.let(::faDigits) ?: "—", Modifier.weight(1f))
                }
                Text("استراتژی: ${decision.optString("strategy", "—")}", color = V4Muted, style = MaterialTheme.typography.labelMedium)
            }
        }
        item { V4SectionTitle("هشدارها", if (alerts.isEmpty()) "همه‌چیز عادی است" else "${faInt(alerts.size)} مورد نیازمند توجه") }
        if (alerts.isEmpty()) item { V4Banner("هشدار مهمی فعال نیست.", V4GreenSoft, V4Green) }
        items(alerts.take(8), key = { "alert:${it.optString("category")}:${it.optString("title")}:${it.optString("created_at")}" }) { alert ->
            val critical = alert.optString("priority") == "critical"
            V4Card(container = if (critical) V4RedSoft else V4AmberSoft) {
                Text(alert.optString("title", "هشدار"), fontWeight = FontWeight.Bold, color = if (critical) V4Red else V4Amber)
                Text(alert.optString("body", ""), color = V4Ink)
            }
        }
        item { V4SectionTitle("تایم‌لاین فعالیت", "تصمیم‌ها و معاملات به زبان ساده") }
        items(activity.take(12), key = { "home:${it.optLong("id", -1)}:${it.optString("created_at_utc")}:${it.optString("text_fa")}" }) { event ->
            V4MiniCard {
                Text(event.optString("text_fa", "رویداد معاملاتی"), fontWeight = FontWeight.SemiBold)
                Text(event.optString("created_at_iran", event.optString("created_at_utc", "")), color = V4Muted, style = MaterialTheme.typography.labelSmall)
            }
        }
    }
}

@Composable
private fun V4Market(root: JSONObject, listState: LazyListState) {
    val ranking = jsonObjects(root.optJSONArray("opportunity_ranking"))
    val radar = jsonObjects(root.optJSONArray("market_radar"))
    LazyColumn(
        state = listState,
        modifier = Modifier.fillMaxSize(),
        contentPadding = PaddingValues(14.dp, 12.dp, 14.dp, 24.dp),
        verticalArrangement = Arrangement.spacedBy(10.dp),
    ) {
        item { V4SectionTitle("رادار بازار", "عالی / خوب / زیرنظر / پرریسک") }
        val categories = listOf("excellent" to "عالی", "good" to "خوب", "watch" to "زیرنظر", "avoid" to "پرریسک")
        items(categories, key = { it.first }) { (key, label) ->
            val matches = radar.filter { it.optString("category") == key }.take(6)
            V4Card(container = categoryColor(key)) {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Text(label, fontWeight = FontWeight.Black, modifier = Modifier.weight(1f))
                    Text(faInt(matches.size), color = V4Muted)
                }
                if (matches.isEmpty()) Text("موردی در این گروه نیست.", color = V4Muted)
                matches.forEach { item ->
                    HorizontalDivider(color = V4Stroke)
                    Row(Modifier.fillMaxWidth().padding(vertical = 8.dp), verticalAlignment = Alignment.CenterVertically) {
                        Column(Modifier.weight(1f)) {
                            Text(item.optString("symbol"), fontWeight = FontWeight.Bold)
                            Text("${item.optString("strategy", "—")} • ${regimeFa(item.optString("regime", "—"))}", color = V4Muted, maxLines = 1, overflow = TextOverflow.Ellipsis)
                        }
                        Text("لبه ${fmt(item.optDouble("edge_percent"), 2)}٪", color = if (item.optDouble("edge_percent") >= 0) V4Green else V4Red)
                    }
                }
            }
        }
        item { V4SectionTitle("۱۰ فرصت برتر", "رتبه‌بندی نمایشی؛ سفارش خرید نیست") }
        items(ranking, key = { "rank:${it.optString("symbol")}:${it.optString("quote_asset")}" }) { item ->
            V4Card {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Column(Modifier.weight(1f)) {
                        Text(item.optString("symbol"), fontWeight = FontWeight.Black, style = MaterialTheme.typography.titleMedium)
                        Text("${actionFa(item.optString("action"))} • ${categoryFa(item.optString("category"))} • امتیاز ${faInt(item.optInt("score"))}", color = V4Muted)
                    }
                    Column(horizontalAlignment = Alignment.End) {
                        Text("${fmt(item.optDouble("edge_percent"), 3)}٪", fontWeight = FontWeight.Bold, color = if (item.optDouble("edge_percent") >= 0) V4Green else V4Red)
                        Text("اختلاف ${fmt(item.optDouble("spread_percent"), 3)}٪", color = V4Muted)
                    }
                }
            }
        }
        if (ranking.isEmpty()) item { V4Banner("هنوز داده کافی برای رتبه‌بندی بازار ثبت نشده است.", V4BlueSoft, V4Blue) }
    }
}

@Composable
private fun V4Trades(root: JSONObject, listState: LazyListState, onReplay: (Long) -> Unit) {
    val positions = jsonObjects(root.optJSONArray("positions"))
    val activity = jsonObjects(root.optJSONArray("activity_timeline"))
    val heat = root.optJSONObject("risk_heatmap") ?: JSONObject()
    val cells = jsonObjects(heat.optJSONArray("cells")).filter { it.optString("x") < it.optString("y") }
    LazyColumn(
        state = listState,
        modifier = Modifier.fillMaxSize(),
        contentPadding = PaddingValues(14.dp, 12.dp, 14.dp, 24.dp),
        verticalArrangement = Arrangement.spacedBy(10.dp),
    ) {
        item { V4SectionTitle("پوزیشن‌های فعال", "برای بازپخش معامله روی کارت بزن") }
        if (positions.isEmpty()) item { V4Banner("پوزیشن فعالی وجود ندارد.", V4BlueSoft, V4Blue) }
        items(positions, key = { "position:${it.optLong("id")}" }) { p ->
            val pnl = p.optDouble("unrealized_net_pnl_percent")
            V4Card(modifier = Modifier.clickable { onReplay(p.optLong("id")) }) {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Column(Modifier.weight(1f)) {
                        Text(p.optString("symbol"), fontWeight = FontWeight.Black, style = MaterialTheme.typography.titleLarge)
                        Text("${positionStatusFa(p.optString("status"))} • ${faInt(p.optLong("holding_seconds") / 60)} دقیقه", color = V4Muted)
                    }
                    Text("${if (pnl >= 0) "+" else ""}${fmt(pnl, 2)}٪", fontWeight = FontWeight.Black, color = if (pnl >= 0) V4Green else V4Red)
                }
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    V4TinyMetric("ورود", fmt(positionMoney(p, "entry_price"), 4), Modifier.weight(1f))
                    V4TinyMetric("قیمت فعلی", fmt(positionMoney(p, "mark_price"), 4), Modifier.weight(1f))
                    V4TinyMetric("کارمزد", fmt(positionMoney(p, "total_estimated_fees_quote"), 4), Modifier.weight(1f))
                }
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    V4TinyMetric("حد ضرر", fmt(positionMoney(p, "stop_loss"), 4), Modifier.weight(1f))
                    V4TinyMetric("حد سود", fmt(positionMoney(p, "take_profit"), 4), Modifier.weight(1f))
                    V4TinyMetric("خروج محتمل", exitReasonFa(p.optString("probable_exit_reason", "—")), Modifier.weight(1f))
                }
            }
        }
        item { V4SectionTitle("نقشه حرارتی ریسک", "همبستگی بر پایه نمونه‌های زمانی هم‌تراز") }
        if (cells.isEmpty()) item { Text("برای نقشه حرارتی حداقل دو پوزیشن با داده کافی لازم است.", color = V4Muted) }
        items(cells.take(30), key = { "heat:${it.optString("x")}:${it.optString("y")}" }) { cell ->
            val risk = cell.optString("risk", "unknown")
            V4MiniCard {
                Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                    Column(Modifier.weight(1f)) {
                        Text("${cell.optString("x")} / ${cell.optString("y")}", fontWeight = FontWeight.Bold)
                        Text("${faInt(cell.optInt("samples"))} بازده هم‌زمان", color = V4Muted, style = MaterialTheme.typography.labelSmall)
                    }
                    V4Pill(if (cell.isNull("correlation")) "—" else fmt(cell.optDouble("correlation"), 3), categoryColor(risk), when (risk) { "high" -> V4Red; "medium" -> V4Amber; else -> V4Green })
                }
            }
        }
        item { V4SectionTitle("معاملات اخیر", "تایم‌لاین تأییدشده") }
        items(activity.take(20), key = { "trade:${it.optLong("id", -1)}:${it.optString("created_at_utc")}:${it.optString("text_fa")}" }) { event ->
            V4MiniCard {
                Text(event.optString("text_fa", "رویداد"), fontWeight = FontWeight.SemiBold)
                Text(event.optString("created_at_iran", event.optString("created_at_utc", "")), color = V4Muted)
            }
        }
    }
}

@Composable
private fun V4Reports(root: JSONObject, api: TradeApi, listState: LazyListState) {
    val scope = rememberCoroutineScope()
    val performance = root.optJSONObject("performance") ?: JSONObject()
    val reports = root.optJSONObject("reports") ?: JSONObject()
    val byQuote = reports.optJSONObject("by_quote") ?: JSONObject()
    val irtReport = byQuote.optJSONObject("IRT") ?: JSONObject()
    val usdtReport = byQuote.optJSONObject("USDT") ?: JSONObject()
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
        state = listState,
        modifier = Modifier.fillMaxSize(),
        contentPadding = PaddingValues(14.dp, 12.dp, 14.dp, 24.dp),
        verticalArrangement = Arrangement.spacedBy(10.dp),
    ) {
        item { V4SectionTitle("گزارش عملکرد", "سود/زیان هر ارز پایه جداگانه نمایش داده می‌شود") }
        item {
            V4Card {
                Text("بازارهای تومانی", fontWeight = FontWeight.Black)
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp), modifier = Modifier.fillMaxWidth()) {
                    V4TinyMetric("امروز", signedMoney(irtReport.optJSONObject("daily")?.optDouble("net_pnl") ?: reports.optJSONObject("daily")?.optDouble("net_pnl") ?: 0.0), Modifier.weight(1f))
                    V4TinyMetric("هفته", signedMoney(irtReport.optJSONObject("weekly")?.optDouble("net_pnl") ?: reports.optJSONObject("weekly")?.optDouble("net_pnl") ?: 0.0), Modifier.weight(1f))
                    V4TinyMetric("ماه", signedMoney(irtReport.optJSONObject("monthly")?.optDouble("net_pnl") ?: reports.optJSONObject("monthly")?.optDouble("net_pnl") ?: 0.0), Modifier.weight(1f))
                }
                Text("واحد: تومان", color = V4Muted, style = MaterialTheme.typography.labelSmall)
            }
        }
        item {
            V4Card {
                Text("بازارهای USDT", fontWeight = FontWeight.Black)
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp), modifier = Modifier.fillMaxWidth()) {
                    V4TinyMetric("امروز", signedDecimal(usdtReport.optJSONObject("daily")?.optDouble("net_pnl") ?: 0.0, 4), Modifier.weight(1f))
                    V4TinyMetric("هفته", signedDecimal(usdtReport.optJSONObject("weekly")?.optDouble("net_pnl") ?: 0.0, 4), Modifier.weight(1f))
                    V4TinyMetric("ماه", signedDecimal(usdtReport.optJSONObject("monthly")?.optDouble("net_pnl") ?: 0.0, 4), Modifier.weight(1f))
                }
                Text("واحد: USDT • برای جلوگیری از خطای تبدیل، با تومان جمع نمی‌شود.", color = V4Muted, style = MaterialTheme.typography.labelSmall)
            }
        }
        item {
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp), modifier = Modifier.fillMaxWidth()) {
                V4Metric("ضریب سود تومانی", fmt(reports.optDouble("profit_factor"), 2), "تحقق‌یافته", Modifier.weight(1f))
                V4Metric("نرخ برد تومانی", "${fmt(reports.optDouble("win_rate_percent"), 1)}٪", "معاملات بسته", Modifier.weight(1f))
            }
        }
        item { V4SectionTitle("نمودار ارزش کیف پول", "کل بازه موجود با نمونه‌برداری نمایشی") }
        item { V4EquityBars(curve) }
        item { V4SectionTitle("عملکرد بر اساس استراتژی", "استراتژی و پروفایل") }
        items(strategies.take(12), key = { "strategy:${it.optString("strategy_key")}:${it.optString("profile_key")}:${it.optString("quote_asset")}" }) { s ->
            V4MiniCard {
                Row(Modifier.fillMaxWidth()) {
                    Column(Modifier.weight(1f)) {
                        Text(s.optString("strategy_key", "—"), fontWeight = FontWeight.Bold, maxLines = 1, overflow = TextOverflow.Ellipsis)
                        Text(s.optString("profile_key", "—"), color = V4Muted, maxLines = 1, overflow = TextOverflow.Ellipsis)
                    }
                    Column(horizontalAlignment = Alignment.End) {
                        Text("${faInt(s.optInt("trades"))} معامله")
                        Text("میانگین ${fmt(s.optDouble("average_return_percent"), 3)}٪", color = if (s.optDouble("average_return_percent") >= 0) V4Green else V4Red)
                    }
                }
            }
        }
        item { V4SectionTitle("عملکرد بر اساس دارایی", "بهترین و ضعیف‌ترین دارایی‌ها بر اساس بازده درصدی") }
        items(coins.take(12), key = { "coin:${it.optString("asset")}:${it.optString("quote_asset")}" }) { c ->
            V4MiniCard {
                Row(Modifier.fillMaxWidth()) {
                    Text(c.optString("asset", "—"), fontWeight = FontWeight.Black, modifier = Modifier.weight(1f))
                    Text("${faInt(c.optInt("trades"))} معامله • برد ${fmt(c.optDouble("win_rate_percent"), 1)}٪")
                }
            }
        }
        item { V4SectionTitle("ارزیابی آزمایشی", "سیگنال‌های ۱۵ / ۶۰ / ۲۴۰ دقیقه") }
        item {
            V4Card {
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    V4TinyMetric("نمونه", faInt(shadow.optInt("samples")), Modifier.weight(1f))
                    V4TinyMetric("میانگین ۶۰دقیقه", if (shadow.isNull("average_return_60m")) "—" else "${fmt(shadow.optDouble("average_return_60m"), 3)}٪", Modifier.weight(1f))
                    V4TinyMetric("مثبت", if (shadow.isNull("positive_60m_rate_percent")) "—" else "${fmt(shadow.optDouble("positive_60m_rate_percent"), 1)}٪", Modifier.weight(1f))
                }
            }
        }
        item { V4SectionTitle("آزمایشگاه استراتژی", "سناریوی تاریخی؛ بدون سفارش واقعی") }
        item {
            V4Card {
                Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    OutlinedTextField(positionPct, { positionPct = it }, label = { Text("درصد پوزیشن") }, modifier = Modifier.fillMaxWidth(), singleLine = true)
                    OutlinedTextField(stopPct, { stopPct = it }, label = { Text("حد ضرر ٪") }, modifier = Modifier.fillMaxWidth(), singleLine = true)
                    OutlinedTextField(takePct, { takePct = it }, label = { Text("حد سود ٪") }, modifier = Modifier.fillMaxWidth(), singleLine = true)
                }
                Button(
                    onClick = {
                        scope.launch {
                            labBusy = true
                            val body = JSONObject()
                                .put("position_percent", localizedDoubleOrNull(positionPct) ?: 2.0)
                                .put("stop_loss_percent", localizedDoubleOrNull(stopPct) ?: 3.0)
                                .put("take_profit_percent", localizedDoubleOrNull(takePct) ?: 5.0)
                            val r = runCatching { api.strategyLab(body.toString()) }.getOrNull()
                            labResult = if (r?.ok == true) unwrap(r.body) else JSONObject().put("error", "آزمایشگاه استراتژی در دسترس نیست")
                            labBusy = false
                        }
                    }, enabled = !labBusy, modifier = Modifier.fillMaxWidth(),
                ) { if (labBusy) CircularProgressIndicator(Modifier.size(18.dp), strokeWidth = 2.dp) else Text("اجرای سناریو") }
                labResult?.let { result ->
                    HorizontalDivider(color = V4Stroke)
                    if (result.has("error")) Text(result.optString("error"), color = V4Red) else {
                        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                            V4TinyMetric("برد", "${fmt(result.optDouble("win_rate_percent"), 1)}٪", Modifier.weight(1f))
                            V4TinyMetric("میانگین", "${fmt(result.optDouble("average_simulated_return_percent"), 3)}٪", Modifier.weight(1f))
                            V4TinyMetric("بیشترین افت", "${fmt(result.optDouble("max_drawdown_percent"), 2)}٪", Modifier.weight(1f))
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
    listState: LazyListState,
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
    var shadowEnabled by rememberSaveable { mutableStateOf(shadow.optBoolean("enabled", true)) }
    var minPriority by rememberSaveable { mutableStateOf(rules.optString("min_priority", "warning")) }
    var busy by remember { mutableStateOf(false) }
    var settingsDirty by rememberSaveable { mutableStateOf(false) }
    var customPosition by rememberSaveable { mutableStateOf(current.optDouble("position_percent", 2.0).toString()) }
    var customExposure by rememberSaveable { mutableStateOf(current.optDouble("nobitex_portfolio_exposure_percent", 35.0).toString()) }
    var customMax by rememberSaveable { mutableStateOf(current.optInt("nobitex_max_positions", 6).toString()) }
    var customDaily by rememberSaveable { mutableStateOf(current.optDouble("daily_loss_limit_percent", 2.0).toString()) }
    var preview by remember { mutableStateOf<JSONObject?>(null) }

    LaunchedEffect(current.toString(), settingsDirty, busy) {
        if (!settingsDirty && !busy) {
            customPosition = current.optDouble("position_percent", 2.0).toString()
            customExposure = current.optDouble("nobitex_portfolio_exposure_percent", 35.0).toString()
            customMax = current.optInt("nobitex_max_positions", 6).toString()
            customDaily = current.optDouble("daily_loss_limit_percent", 2.0).toString()
        }
    }
    LaunchedEffect(rules.toString(), shadow.toString(), busy) {
        if (!busy) {
            minPriority = rules.optString("min_priority", "warning")
            shadowEnabled = shadow.optBoolean("enabled", true)
        }
    }

    fun reloadHistory() {
        scope.launch {
            val r = runCatching { api.settingsHistory(30) }.getOrNull()
            if (r?.ok == true) onHistoryChanged(unwrapArrayResponse(r.body).toString())
        }
    }

    LazyColumn(
        state = listState,
        modifier = Modifier.fillMaxSize(),
        contentPadding = PaddingValues(14.dp, 12.dp, 14.dp, 24.dp),
        verticalArrangement = Arrangement.spacedBy(10.dp),
    ) {
        item { V4Banner("دامنه اجرای فعلی: معاملات Spot فقط در Nobitex. منابع دیگر فقط داده بازار هستند و Futures/معاملات اهرمی در این مسیر اجرا نمی‌شوند.", V4BlueSoft, V4Blue) }
        item { V4SectionTitle("کنترل اضطراری", "وضعیت فعلی: ${emergencyModeFa(emergency.optString("mode", "normal"))}") }
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
        item { V4SectionTitle("پروفایل‌های آماده", "محافظه‌کار، متعادل یا تهاجمی") }
        item {
            V4Card {
                listOf("safe", "balanced", "aggressive").forEach { key ->
                    val preset = presets.optJSONObject(key) ?: JSONObject()
                    Row(Modifier.fillMaxWidth().padding(vertical = 4.dp), verticalAlignment = Alignment.CenterVertically) {
                        Column(Modifier.weight(1f)) {
                            Text(preset.optString("label", key), fontWeight = FontWeight.Bold)
                            Text("هر خرید ${fmt(preset.optDouble("position_percent"),1)}٪ • سقف درگیری ${fmt(preset.optDouble("nobitex_portfolio_exposure_percent"),0)}٪", color = V4Muted)
                        }
                        FilledTonalButton(
                            onClick = {
                                scope.launch {
                                    busy = true
                                    val r = runCatching { api.applyPreset(key) }.getOrNull()
                                    if (r?.ok == true) settingsDirty = false
                                    reloadHistory(); onReload(); busy = false
                                }
                            }, enabled = !busy,
                        ) { Text("اعمال") }
                    }
                }
            }
        }
        item { V4SectionTitle("تنظیمات سفارشی و پیش‌نمایش", "قبل از ذخیره، اثر تغییر ریسک را ببین") }
        item {
            V4Card {
                Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    OutlinedTextField(customPosition, { customPosition = it; settingsDirty = true }, label = { Text("درصد هر خرید") }, modifier = Modifier.fillMaxWidth(), singleLine = true)
                    OutlinedTextField(customExposure, { customExposure = it; settingsDirty = true }, label = { Text("سقف سرمایه درگیر") }, modifier = Modifier.fillMaxWidth(), singleLine = true)
                    OutlinedTextField(customMax, { customMax = it; settingsDirty = true }, label = { Text("حداکثر پوزیشن") }, modifier = Modifier.fillMaxWidth(), singleLine = true)
                    OutlinedTextField(customDaily, { customDaily = it; settingsDirty = true }, label = { Text("حد زیان روزانه") }, modifier = Modifier.fillMaxWidth(), singleLine = true)
                }
                val body = JSONObject()
                    .put("position_percent", localizedDoubleOrNull(customPosition) ?: current.optDouble("position_percent",2.0))
                    .put("nobitex_portfolio_exposure_percent", localizedDoubleOrNull(customExposure) ?: current.optDouble("nobitex_portfolio_exposure_percent",35.0))
                    .put("nobitex_max_positions", localizedIntOrNull(customMax) ?: current.optInt("nobitex_max_positions",6))
                    .put("daily_loss_limit_percent", localizedDoubleOrNull(customDaily) ?: current.optDouble("daily_loss_limit_percent",2.0))
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    OutlinedButton(onClick = { scope.launch { val r=runCatching{api.previewSettings(body.toString())}.getOrNull();preview=if(r?.ok==true)unwrap(r.body) else null } }, modifier = Modifier.weight(1f)) { Text("پیش‌نمایش") }
                    Button(onClick = { scope.launch { busy=true; val r=runCatching{api.updateBotSettings(body.toString())}.getOrNull(); if(r?.ok==true){settingsDirty=false;reloadHistory();onReload()};busy=false } }, enabled=!busy, modifier=Modifier.weight(1f)) { Text("ذخیره") }
                }
                preview?.let { p ->
                    val before=p.optJSONObject("risk_before")?:JSONObject();val after=p.optJSONObject("risk_after")?:JSONObject()
                    V4Banner("ریسک ${fmt(before.optDouble("score"),1)} ← ${fmt(after.optDouble("score"),1)} • ${impactFa(p.optString("impact"))}", V4BlueSoft, V4Blue)
                }
            }
        }
        item { V4SectionTitle("ارزیابی آزمایشی", "بررسی سیگنال‌ها بدون افزایش ریسک") }
        item {
            V4SettingRow("ارزیابی سایه", "نتیجه سیگنال‌ها را بعداً مقایسه می‌کند", shadowEnabled) { enabled ->
                shadowEnabled = enabled
                scope.launch { runCatching { api.setShadowMode(enabled) }; onReload() }
            }
        }
        item { V4SectionTitle("اعلان‌های هوشمند", "اندروید طبق محدودیت سیستم حداقل هر ۱۵ دقیقه در پس‌زمینه بررسی می‌کند") }
        item {
            V4SettingRow("اعلان پس‌زمینه", "هشدارهای مهم طبق قوانین پنل", alertsEnabled) { enabled ->
                alertsEnabled = enabled
                prefs.setAlertsEnabled(enabled)
                if (enabled) TradeAlertWorker.schedule(context) else TradeAlertWorker.cancel(context)
            }
        }
        item {
            V4Card {
                Text("حداقل اولویت", fontWeight = FontWeight.Bold)
                listOf("info", "success", "warning", "critical").chunked(2).forEach { row ->
                    Row(horizontalArrangement = Arrangement.spacedBy(6.dp), modifier = Modifier.fillMaxWidth()) {
                        row.forEach { p ->
                            FilledTonalButton(onClick = {
                                minPriority = p
                                scope.launch {
                                    val body = JSONObject().put("min_priority", p).put("enabled", true)
                                    runCatching { api.updateNotificationRules(body.toString()) }
                                    onReload()
                                }
                            }, modifier = Modifier.weight(1f), colors = ButtonDefaults.filledTonalButtonColors(containerColor = if (minPriority == p) V4PrimarySoft else Color.White)) { Text(priorityFa(p)) }
                        }
                    }
                }
            }
        }
        item { V4SectionTitle("امنیت اپ", "قفل بیومتریک") }
        item {
            V4SettingRow("قفل بیومتریک", if (Build.VERSION.SDK_INT >= 28) "برای بازکردن اپ اثرانگشت/بیومتریک بخواه" else "در نسخه اندروید این دستگاه پشتیبانی نمی‌شود", biometric, enabled = Build.VERSION.SDK_INT >= 28) { enabled ->
                biometric = enabled; prefs.setBiometricEnabled(enabled); if (enabled) onLockNow()
            }
        }
        item { V4SectionTitle("نسخه آفلاین", "آخرین وضعیت مرکز فرمان به‌صورت رمزگذاری‌شده روی دستگاه") }
        item {
            V4Card {
                Text(if (prefs.offlineSnapshotAt() > 0) "نسخه ذخیره‌شده موجود است." else "هنوز نسخه آفلاین ذخیره نشده است.", fontWeight = FontWeight.Bold)
                Text("این نسخه فقط برای مشاهده آفلاین است و مبنای اجرای معامله نیست.", color = V4Muted)
            }
        }
        item { V4SectionTitle("تاریخچه تنظیمات و بازگردانی", "${faInt(history.size)} نسخه اخیر") }
        items(history.take(15), key = { "history:${it.optLong("id")}" }) { h ->
            V4MiniCard {
                Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                    Column(Modifier.weight(1f)) {
                        Text("#${faDigits(h.optLong("id").toString())} • ${h.optString("label")}", fontWeight = FontWeight.Bold)
                        Text("${h.optString("source")} • ${h.optString("created_at")}", color = V4Muted)
                    }
                    TextButton(onClick = {
                        scope.launch {
                            busy = true
                            val r = runCatching { api.rollbackSettings(h.optLong("id")) }.getOrNull()
                            if (r?.ok == true) settingsDirty = false
                            reloadHistory(); onReload(); busy = false
                        }
                    }, enabled = !busy) { Text("بازگردانی") }
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
            Column(verticalArrangement = Arrangement.spacedBy(6.dp)) { chips.take(3).forEach { V4Pill(it, Color(0xFF292D42), Color.White) } }
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
            Text(value, color = V4Ink, fontWeight = FontWeight.Black, style = MaterialTheme.typography.titleLarge, maxLines = 2, overflow = TextOverflow.Ellipsis)
            Text(hint, color = V4Muted, style = MaterialTheme.typography.labelSmall)
        }
    }
}

@Composable
private fun V4TinyMetric(label: String, value: String, modifier: Modifier = Modifier) {
    Column(modifier.background(V4Bg, RoundedCornerShape(12.dp)).padding(9.dp)) {
        Text(label, color = V4Muted, style = MaterialTheme.typography.labelSmall)
        Text(value, fontWeight = FontWeight.Bold, maxLines = 2, overflow = TextOverflow.Ellipsis)
    }
}

@Composable
private fun V4Pill(text: String, bg: Color, fg: Color) {
    Text(text, modifier = Modifier.fillMaxWidth().background(bg, RoundedCornerShape(12.dp)).padding(horizontal = 10.dp, vertical = 6.dp), color = fg, style = MaterialTheme.typography.labelMedium, maxLines = 2, overflow = TextOverflow.Ellipsis)
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
        val window = downsample(curve, 48)
        val metricKey = if (window.any { it.has("portfolio_value_toman") }) "portfolio_value_toman" else "cumulative_net_pnl"
        val values = window.map { it.optDouble(metricKey) }
        val min = values.minOrNull() ?: 0.0
        val max = values.maxOrNull() ?: 0.0
        val span = (max - min).takeIf { abs(it) > 0.000001 } ?: 1.0
        Row(Modifier.fillMaxWidth().height(150.dp), horizontalArrangement = Arrangement.spacedBy(2.dp), verticalAlignment = Alignment.Bottom) {
            window.forEach { point ->
                val value = point.optDouble(metricKey)
                val normalized = ((value - min) / span).coerceIn(0.0, 1.0)
                Box(Modifier.weight(1f).height((20 + normalized * 120).dp).background(if (metricKey == "portfolio_value_toman" || value >= 0) V4Green else V4Red, RoundedCornerShape(topStart = 3.dp, topEnd = 3.dp)))
            }
        }
        Text("${faInt(curve.size)} نمونه • نمایش فشرده ${faInt(window.size)} نقطه • کمینه ${money(min)} • بیشینه ${money(max)} تومان", color = V4Muted, style = MaterialTheme.typography.labelSmall)
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

private fun downsample(points: List<JSONObject>, maxPoints: Int): List<JSONObject> {
    if (points.size <= maxPoints || maxPoints < 2) return points
    val result = ArrayList<JSONObject>(maxPoints)
    for (i in 0 until maxPoints) {
        val index = ((i.toLong() * (points.size - 1)) / (maxPoints - 1)).toInt()
        result += points[index]
    }
    return result
}

private fun normalizeLocalizedNumber(value: String): String = value
    .trim()
    .map { c ->
        when (c) {
            in '۰'..'۹' -> ('0'.code + (c.code - '۰'.code)).toChar()
            in '٠'..'٩' -> ('0'.code + (c.code - '٠'.code)).toChar()
            '٫', '٬', ',' -> if (c == '٫') '.' else if (c == '٬') '\u0000' else '.'
            else -> c
        }
    }
    .filter { it != '\u0000' && !it.isWhitespace() }
    .joinToString("")

private fun localizedDoubleOrNull(value: String): Double? = normalizeLocalizedNumber(value).toDoubleOrNull()
private fun localizedIntOrNull(value: String): Int? = normalizeLocalizedNumber(value).toIntOrNull()

private fun categoryColor(category: String): Color = when (category.lowercase()) {
    "excellent", "low" -> V4GreenSoft
    "good" -> V4BlueSoft
    "watch", "medium" -> V4AmberSoft
    "avoid", "high", "critical" -> V4RedSoft
    else -> V4Bg
}

private fun categoryFa(value: String): String = when (value.lowercase()) {
    "excellent" -> "عالی"
    "good" -> "خوب"
    "watch" -> "زیرنظر"
    "avoid" -> "پرریسک"
    else -> value.ifBlank { "—" }
}

private fun regimeFa(value: String): String = when (value.lowercase()) {
    "bull", "bullish", "trend_up" -> "صعودی"
    "bear", "bearish", "trend_down" -> "نزولی"
    "range", "ranging", "sideways" -> "خنثی"
    "volatile", "high_volatility" -> "پرنوسان"
    else -> value.ifBlank { "—" }
}

private fun actionFa(value: String): String = when (value.lowercase()) {
    "buy" -> "خرید"
    "sell" -> "فروش"
    "hold" -> "صبر"
    "skip" -> "رد"
    else -> value.ifBlank { "—" }
}

private fun positionStatusFa(value: String): String = when (value.lowercase()) {
    "open" -> "باز"
    "pending_open" -> "در انتظار بازشدن"
    "pending_close" -> "در انتظار بسته‌شدن"
    "closed" -> "بسته"
    else -> value.ifBlank { "—" }
}

private fun exitReasonFa(value: String): String = when (value.lowercase()) {
    "take_profit" -> "حد سود"
    "stop_loss" -> "حد ضرر"
    "trailing_profit", "trailing_stop" -> "تریلینگ سود"
    "rotation" -> "چرخش پرتفوی"
    "manual" -> "دستی"
    else -> value.ifBlank { "—" }
}

private fun priorityFa(value: String): String = when (value.lowercase()) {
    "info" -> "اطلاعات"
    "success" -> "موفق"
    "warning" -> "هشدار"
    "critical" -> "بحرانی"
    else -> value
}

private fun riskFa(value: String): String = when (value.lowercase()) {
    "low" -> "کم"
    "medium" -> "متوسط"
    "high" -> "زیاد"
    "critical" -> "بحرانی"
    else -> value
}

private fun statusFa(value: String): String = when (value.lowercase()) {
    "ready", "on", "closed" -> "آماده"
    "off" -> "خاموش"
    "open" -> "باز"
    "missing" -> "تنظیم‌نشده"
    else -> value
}

private fun emergencyModeFa(value: String): String = when (value.lowercase()) {
    "normal" -> "عادی"
    "pause_buys" -> "توقف خرید"
    "graceful_close" -> "بستن تدریجی"
    "full_stop" -> "توقف کامل"
    else -> value
}

private fun impactFa(value: String): String = when (value.lowercase()) {
    "higher_risk" -> "ریسک بیشتر"
    "lower_risk" -> "ریسک کمتر"
    "similar_risk" -> "ریسک مشابه"
    else -> value
}

private fun positionMoney(position: JSONObject, key: String): Double {
    val raw = position.optDouble(key)
    val displayUnit = position.optString("display_unit", "")
    if (displayUnit.equals("TOMAN", ignoreCase = true)) return raw
    return if (position.optString("quote_asset", "").equals("IRT", ignoreCase = true)) raw / 10.0 else raw
}

private fun fmt(value: Double, digits: Int = 2): String = NumberFormat.getNumberInstance(FaLocale).apply {
    isGroupingUsed = false
    minimumFractionDigits = digits
    maximumFractionDigits = digits
}.format(value)
private fun money(value: Double): String = NumberFormat.getNumberInstance(FaLocale).apply { maximumFractionDigits = 0 }.format(value)
private fun signedMoney(value: Double): String = (if (value > 0) "+" else "") + money(value)
private fun signedDecimal(value: Double, digits: Int): String = (if (value > 0) "+" else "") + fmt(value, digits)
private fun faInt(value: Number): String = NumberFormat.getIntegerInstance(FaLocale).format(value)
private fun faDigits(value: String): String = value.map { c -> if (c in '0'..'9') "۰۱۲۳۴۵۶۷۸۹"[c - '0'] else c }.joinToString("")
private fun percentOrDash(obj: JSONObject, key: String): String = if (!obj.has(key) || obj.isNull(key)) "—" else "${fmt(obj.optDouble(key), 3)}٪"
private fun emergencyDescription(mode: String): String = when (mode) {
    "normal" -> "حالت اضطراری لغو می‌شود. اگر Kill Switch پیش از حالت توقف کامل به‌صورت دستی فعال بوده باشد، فعال باقی می‌ماند."
    "pause_buys" -> "خریدهای جدید متوقف می‌شوند؛ خروج‌ها و همگام‌سازی همچنان فعال می‌مانند."
    "graceful_close" -> "خرید متوقف می‌شود و پوزیشن‌های باز به‌صورت ترتیبی و کنترل‌شده بسته می‌شوند. پس از پایان، خرید همچنان متوقف می‌ماند."
    "full_stop" -> "Kill Switch فعال می‌شود. خروج از این حالت فقط Kill Switchای را خاموش می‌کند که خود حالت اضطراری روشن کرده باشد."
    else -> "اعمال کنترل اضطراری"
}
