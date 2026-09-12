package ir.trade.app

import android.app.Activity
import android.content.Intent
import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import ir.trade.app.data.TradeApi
import ir.trade.app.data.TradePreferences
import kotlinx.coroutines.delay
import org.json.JSONArray
import org.json.JSONObject
import java.util.Locale

class RotationActivity : ComponentActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        enableEdgeToEdge()
        setContent { RotationDashboard() }
    }
}

private val RotationBg = Color(0xFFF6F7FB)
private val RotationCard = Color.White
private val RotationInk = Color(0xFF171A24)
private val RotationMuted = Color(0xFF73798B)
private val RotationPrimary = Color(0xFF6941FF)
private val RotationGreen = Color(0xFF0D966F)
private val RotationGreenSoft = Color(0xFFEAF8F3)
private val RotationAmber = Color(0xFFB46A00)
private val RotationAmberSoft = Color(0xFFFFF7E5)
private val RotationRed = Color(0xFFD14343)
private val RotationRedSoft = Color(0xFFFFF0F0)
private val RotationStroke = Color(0xFFE7E9F0)
private val RotationFaLocale = Locale("fa", "IR")

private data class RotationUi(
    val state: String = "loading",
    val reason: String = "در حال دریافت وضعیت چرخش سبد…",
    val activePositions: Int = 0,
    val maxPositions: Int = 0,
    val pendingOrders: Int = 0,
    val weakestSymbol: String = "—",
    val weakestEdge: Double? = null,
    val weakestPnl: Double? = null,
    val bestSymbol: String = "—",
    val bestEdge: Double? = null,
    val executionQuality: Double? = null,
    val advantage: Double? = null,
    val required: Double? = null,
    val surplus: Double? = null,
    val cooldownSeconds: Int = 0,
    val history: List<RotationHistoryUi> = emptyList(),
    val generatedAt: String = "—",
)

private data class RotationHistoryUi(
    val time: String,
    val event: String,
    val victim: String,
    val candidate: String,
    val advantage: Double?,
)

@Composable
private fun RotationDashboard() {
    MaterialTheme(
        colorScheme = lightColorScheme(
            primary = RotationPrimary,
            background = RotationBg,
            surface = RotationCard,
            onBackground = RotationInk,
            onSurface = RotationInk,
            error = RotationRed,
        ),
    ) {
        val context = LocalContext.current
        val prefs = remember { TradePreferences(context) }
        val api = remember(prefs.serverUrl(), prefs.apiToken()) { TradeApi(prefs.serverUrl(), prefs.apiToken()) }
        var ui by remember { mutableStateOf(RotationUi()) }
        var error by remember { mutableStateOf("") }
        var refreshing by remember { mutableStateOf(false) }
        var refreshKey by remember { mutableIntStateOf(0) }

        suspend fun refresh() {
            if (!prefs.isConfigured()) {
                error = "ابتدا اپ Trade را به بک‌اند متصل کن."
                return
            }
            refreshing = true
            error = ""
            try {
                val status = api.status()
                if (!status.ok) throw IllegalStateException(httpError(status))
                val response = api.rotationStatus(20)
                if (!response.ok) throw IllegalStateException(httpError(response))
                val data = JSONObject(response.body).getJSONObject("data")
                ui = parseRotation(data)
            } catch (e: Exception) {
                error = e.message ?: "دریافت وضعیت چرخش سبد ناموفق بود."
            } finally {
                refreshing = false
            }
        }

        LaunchedEffect(refreshKey, prefs.serverUrl(), prefs.apiToken()) {
            refresh()
            while (true) {
                delay(20_000)
                refresh()
            }
        }

        Surface(Modifier.fillMaxSize(), color = RotationBg) {
            LazyColumn(
                modifier = Modifier.fillMaxSize().statusBarsPadding().navigationBarsPadding().padding(horizontal = 14.dp),
                contentPadding = PaddingValues(top = 14.dp, bottom = 28.dp),
                verticalArrangement = Arrangement.spacedBy(12.dp),
            ) {
                item {
                    Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                        Column(Modifier.weight(1f)) {
                            Text("چرخش هوشمند سبد", style = MaterialTheme.typography.headlineSmall, fontWeight = FontWeight.ExtraBold)
                            Text("نوبیتکس • پیش‌نمایش تصمیم زنده", color = RotationMuted, style = MaterialTheme.typography.bodySmall)
                        }
                        TextButton(onClick = { refreshKey++ }, enabled = !refreshing) { Text(if (refreshing) "…" else "بروزرسانی") }
                    }
                }

                if (!prefs.isConfigured()) {
                    item {
                        RotationCardBlock {
                            Text("اتصال اپ کامل نیست", fontWeight = FontWeight.Bold, color = RotationRed)
                            Spacer(Modifier.height(8.dp))
                            Text("ابتدا صفحه اصلی Trade را باز کن و اتصال امن بک‌اند را انجام بده.")
                            Spacer(Modifier.height(10.dp))
                            Button(onClick = {
                                context.startActivity(Intent(context, MainActivity::class.java))
                                (context as? Activity)?.finish()
                            }, modifier = Modifier.fillMaxWidth()) { Text("باز کردن Trade") }
                        }
                    }
                } else {
                    if (error.isNotBlank()) {
                        item {
                            Card(colors = CardDefaults.cardColors(containerColor = RotationRedSoft), shape = RoundedCornerShape(18.dp)) {
                                Text(error, color = RotationRed, modifier = Modifier.padding(15.dp))
                            }
                        }
                    }

                    item { RotationStateCard(ui, refreshing) }
                    item { RotationComparisonCard(ui) }
                    item { RotationDecisionCard(ui) }
                    item {
                        Text("تاریخچه چرخش سبد", style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.ExtraBold)
                    }
                    if (ui.history.isEmpty()) {
                        item { RotationCardBlock { Text("هنوز رویداد چرخش سبد ثبت نشده است.", color = RotationMuted) } }
                    } else {
                        items(ui.history) { row -> RotationHistoryCard(row) }
                    }
                    item {
                        Card(colors = CardDefaults.cardColors(containerColor = Color(0xFFEEF5FF)), shape = RoundedCornerShape(16.dp)) {
                            Text(
                                "این صفحه فقط پیش‌نمایش است. موتور واقعی قبل از تعویض دوباره بازار را اسکن می‌کند و وضعیت اجرای زنده، سود/زیان، سفارش‌های در انتظار، وقفه زمانی و هزینه خروج را مجدداً بررسی می‌کند.",
                                modifier = Modifier.padding(14.dp),
                                style = MaterialTheme.typography.bodySmall,
                                color = Color(0xFF244C93),
                            )
                        }
                    }
                }
            }
        }
    }
}

@Composable
private fun RotationStateCard(ui: RotationUi, refreshing: Boolean) {
    val ready = ui.state == "ready"
    val blocked = ui.state == "blocked" || ui.state == "disabled" || ui.state == "error"
    val container = when {
        ready -> RotationGreenSoft
        blocked -> RotationRedSoft
        else -> RotationAmberSoft
    }
    val accent = when {
        ready -> RotationGreen
        blocked -> RotationRed
        else -> RotationAmber
    }
    Card(colors = CardDefaults.cardColors(containerColor = container), shape = RoundedCornerShape(20.dp)) {
        Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
            Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                Text("وضعیت چرخش", fontWeight = FontWeight.ExtraBold, modifier = Modifier.weight(1f))
                Text(stateFa(ui.state), color = accent, fontWeight = FontWeight.Bold)
            }
            Text(ui.reason, style = MaterialTheme.typography.bodyMedium)
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                RotationMetric("پوزیشن", "${faIntRotation(ui.activePositions)}/${faIntRotation(ui.maxPositions)}", Modifier.weight(1f))
                RotationMetric("در انتظار", faIntRotation(ui.pendingOrders), Modifier.weight(1f))
                RotationMetric("وقفه", "${faIntRotation(ui.cooldownSeconds)} ثانیه", Modifier.weight(1f))
            }
            Text("آخرین محاسبه: ${ui.generatedAt}${if (refreshing) " • در حال بروزرسانی" else ""}", color = RotationMuted, style = MaterialTheme.typography.bodySmall)
        }
    }
}

@Composable
private fun RotationComparisonCard(ui: RotationUi) {
    RotationCardBlock {
        Text("مقایسه فرصت", style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.ExtraBold)
        Spacer(Modifier.height(10.dp))
        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(9.dp)) {
            RotationOpportunity(
                title = "ضعیف‌ترین پوزیشن",
                symbol = ui.weakestSymbol,
                edge = ui.weakestEdge,
                secondary = ui.weakestPnl?.let { "سود/زیان خالص ${pct(it)}" } ?: "سود/زیان —",
                container = RotationAmberSoft,
                modifier = Modifier.weight(1f),
            )
            RotationOpportunity(
                title = "بهترین جایگزین",
                symbol = ui.bestSymbol,
                edge = ui.bestEdge,
                secondary = ui.executionQuality?.let { "کیفیت ${one(it)}" } ?: "کیفیت —",
                container = RotationGreenSoft,
                modifier = Modifier.weight(1f),
            )
        }
    }
}

@Composable
private fun RotationDecisionCard(ui: RotationUi) {
    RotationCardBlock {
        Text("تصمیم تعویض", style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.ExtraBold)
        Spacer(Modifier.height(10.dp))
        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            RotationMetric("اختلاف برتری", ui.advantage?.let(::pct) ?: "—", Modifier.weight(1f))
            RotationMetric("حد لازم", ui.required?.let(::pct) ?: "—", Modifier.weight(1f))
            RotationMetric("مازاد", ui.surplus?.let(::pct) ?: "—", Modifier.weight(1f))
        }
        Spacer(Modifier.height(10.dp))
        val rotate = ui.state == "ready" && ui.surplus != null && ui.surplus >= 0
        Text(
            if (rotate) "تعویض آماده است — اجرای واقعی هنوز باید اسکن تازه را تأیید کند." else "نگهداری — در حال حاضر تعویض توجیه کافی ندارد.",
            color = if (rotate) RotationGreen else RotationMuted,
            fontWeight = FontWeight.Bold,
        )
    }
}

@Composable
private fun RotationHistoryCard(row: RotationHistoryUi) {
    RotationCardBlock {
        Row(Modifier.fillMaxWidth()) {
            Column(Modifier.weight(1f)) {
                Text(eventFa(row.event), fontWeight = FontWeight.Bold)
                Text(row.time, color = RotationMuted, style = MaterialTheme.typography.bodySmall)
            }
            row.advantage?.let { Text(pct(it), color = RotationPrimary, fontWeight = FontWeight.Bold) }
        }
        Spacer(Modifier.height(8.dp))
        Text("${row.victim}  →  ${row.candidate}", style = MaterialTheme.typography.bodyMedium)
    }
}

@Composable
private fun RotationOpportunity(
    title: String,
    symbol: String,
    edge: Double?,
    secondary: String,
    container: Color,
    modifier: Modifier = Modifier,
) {
    Column(modifier.background(container, RoundedCornerShape(15.dp)).padding(12.dp)) {
        Text(title, color = RotationMuted, style = MaterialTheme.typography.labelSmall)
        Text(symbol, style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.ExtraBold)
        Text(edge?.let { "لبه ${pct(it)}" } ?: "لبه —", color = RotationPrimary, fontWeight = FontWeight.Bold)
        Text(secondary, color = RotationMuted, style = MaterialTheme.typography.bodySmall)
    }
}

@Composable
private fun RotationMetric(title: String, value: String, modifier: Modifier = Modifier) {
    Column(modifier.background(Color.White.copy(alpha = 0.72f), RoundedCornerShape(12.dp)).padding(10.dp)) {
        Text(title, color = RotationMuted, style = MaterialTheme.typography.labelSmall)
        Text(value, fontWeight = FontWeight.Bold, style = MaterialTheme.typography.bodyMedium)
    }
}

@Composable
private fun RotationCardBlock(content: @Composable ColumnScope.() -> Unit) {
    Card(
        colors = CardDefaults.cardColors(containerColor = RotationCard),
        shape = RoundedCornerShape(20.dp),
        border = androidx.compose.foundation.BorderStroke(1.dp, RotationStroke),
    ) {
        Column(Modifier.fillMaxWidth().padding(16.dp), content = content)
    }
}

private fun parseRotation(data: JSONObject): RotationUi {
    val portfolio = data.optJSONObject("portfolio") ?: JSONObject()
    val weak = data.optJSONObject("weakest_position")
    val best = data.optJSONObject("best_replacement")
    val plan = data.optJSONObject("preview_plan") ?: JSONObject()
    val cooldown = data.optJSONObject("cooldown") ?: JSONObject()
    val historyJson = data.optJSONArray("history") ?: JSONArray()
    val history = buildList {
        for (i in 0 until minOf(historyJson.length(), 12)) {
            val row = historyJson.optJSONObject(i) ?: continue
            val ctx = row.optJSONObject("context") ?: JSONObject()
            val victim = ctx.optJSONObject("victim")
            val candidate = ctx.optJSONObject("candidate")
            add(
                RotationHistoryUi(
                    time = row.optString("created_at", "—"),
                    event = row.optString("event_name", "rotation"),
                    victim = victim?.optString("symbol")?.takeIf { it.isNotBlank() }
                        ?: ctx.optString("symbol", "—"),
                    candidate = candidate?.optString("symbol")?.takeIf { it.isNotBlank() } ?: "—",
                    advantage = ctx.optNullableDouble("advantage_percent"),
                ),
            )
        }
    }
    return RotationUi(
        state = data.optString("state", "watching"),
        reason = reasonFa(data.optString("reason", "rotation_preview_unavailable")),
        activePositions = portfolio.optInt("active_positions", 0),
        maxPositions = portfolio.optInt("max_positions", 0),
        pendingOrders = portfolio.optInt("pending_orders", 0),
        weakestSymbol = weak?.optString("symbol", "—") ?: "—",
        weakestEdge = weak?.optNullableDouble("forward_edge_percent"),
        weakestPnl = weak?.optNullableDouble("unrealized_net_pnl_percent"),
        bestSymbol = best?.optString("symbol", "—") ?: "—",
        bestEdge = best?.optNullableDouble("tradable_net_edge_percent"),
        executionQuality = best?.optNullableDouble("execution_quality_score"),
        advantage = plan.optNullableDouble("advantage_percent"),
        required = plan.optNullableDouble("required_advantage_percent"),
        surplus = plan.optNullableDouble("advantage_surplus_percent"),
        cooldownSeconds = cooldown.optInt("remaining_seconds", 0),
        history = history,
        generatedAt = data.optString("generated_at", "—"),
    )
}

private fun JSONObject.optNullableDouble(key: String): Double? {
    if (!has(key) || isNull(key)) return null
    val value = optDouble(key, Double.NaN)
    return value.takeIf { it.isFinite() }
}

private fun pct(value: Double): String = String.format(RotationFaLocale, "%.3f%%", value)
private fun one(value: Double): String = String.format(RotationFaLocale, "%.1f", value)
private fun faIntRotation(value: Int): String = String.format(RotationFaLocale, "%d", value)

private fun stateFa(state: String): String = when (state) {
    "ready" -> "آماده جایگزینی"
    "standby" -> "آماده‌باش"
    "cooldown" -> "وقفه زمانی"
    "blocked" -> "مسدود"
    "waiting_data" -> "انتظار داده"
    "disabled" -> "غیرفعال"
    else -> "در حال پایش"
}

private fun reasonFa(reason: String): String = when (reason) {
    "superior_opportunity_after_rotation_costs" -> "فرصت جایگزین پس از هزینه‌های تعویض، برتری کافی دارد."
    "portfolio_has_free_slot" -> "سبد هنوز جای خالی دارد و تعویض لازم نیست."
    "pending_order_present" -> "تا تعیین تکلیف سفارش در انتظار، تعویض متوقف است."
    "rotation_cooldown_active" -> "وقفه زمانی تعویض هنوز تمام نشده است."
    "no_profitable_replacement_candidate" -> "فرصت خرید جایگزین سودمند پیدا نشده است."
    "no_rotation_eligible_position" -> "هیچ پوزیشن فعلی شرایط تعویض را ندارد."
    "replacement_advantage_insufficient" -> "اختلاف لبه برای جبران هزینه تعویض کافی نیست."
    "position_signal_data_stale" -> "داده پایش پوزیشن‌ها برای پیش‌نمایش تازه نیست."
    "rotation_disabled" -> "چرخش هوشمند سبد غیرفعال است."
    "kill_switch" -> "توقف اضطراری فعال است."
    "rotation_preview_unavailable" -> "پیش‌نمایش چرخش در دسترس نیست."
    else -> reason
}

private fun eventFa(event: String): String = when (event) {
    "nobitex.rotation.sell_submitted" -> "فروش برای تعویض ارسال شد"
    "nobitex.rotation.local_state_conflict" -> "نیاز به همگام‌سازی وضعیت"
    "nobitex.rotation.position_scan_failed" -> "خطا در پایش پوزیشن"
    else -> event
}

private fun httpError(response: TradeApi.Response): String {
    return try {
        val root = JSONObject(response.body)
        root.optString("message").ifBlank { root.optString("error") }.ifBlank { "HTTP ${response.code}" }
    } catch (_: Exception) {
        "HTTP ${response.code}"
    }
}
