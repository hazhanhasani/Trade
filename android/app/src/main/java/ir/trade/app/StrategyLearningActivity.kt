package ir.trade.app

import android.app.Activity
import android.content.Intent
import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
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
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.LinearProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.lightColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import ir.trade.app.data.TradeApi
import ir.trade.app.data.TradePreferences
import kotlinx.coroutines.delay
import org.json.JSONObject

class StrategyLearningActivity : ComponentActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        enableEdgeToEdge()
        setContent { StrategyLearningDashboard() }
    }
}

private val LearningBg = Color(0xFFF5F6FA)
private val LearningInk = Color(0xFF171A24)
private val LearningMuted = Color(0xFF747B8D)
private val LearningPrimary = Color(0xFF6941FF)
private val LearningGreen = Color(0xFF0B966D)
private val LearningAmber = Color(0xFFAD6908)
private val LearningRed = Color(0xFFD04444)
private val LearningGreenSoft = Color(0xFFE9F8F2)
private val LearningAmberSoft = Color(0xFFFFF6E7)
private val LearningRedSoft = Color(0xFFFFF0F0)
private val LearningPurpleSoft = Color(0xFFF1EFFF)

private data class LearningStrategyUi(
    val key: String,
    val trades: Int,
    val multiplier: Double,
    val matureProfiles: Int,
    val blockedProfiles: Int,
)

private data class LearningProfileUi(
    val strategy: String,
    val regime: String,
    val trades: Int,
    val winRate: Double,
    val avgReturn: Double,
    val profitFactor: Double,
    val lossStreak: Int,
    val multiplier: Double,
    val ready: Boolean,
    val blocked: Boolean,
)

private data class LearningUi(
    val samples: Int = 0,
    val strategies: List<LearningStrategyUi> = emptyList(),
    val profiles: List<LearningProfileUi> = emptyList(),
    val generatedAt: String = "—",
)

@Composable
private fun StrategyLearningDashboard() {
    MaterialTheme(
        colorScheme = lightColorScheme(
            primary = LearningPrimary,
            background = LearningBg,
            surface = Color.White,
            onBackground = LearningInk,
            onSurface = LearningInk,
            error = LearningRed,
        ),
    ) {
        val context = LocalContext.current
        val prefs = remember { TradePreferences(context) }
        val api = remember(prefs.serverUrl(), prefs.apiToken()) { TradeApi(prefs.serverUrl(), prefs.apiToken()) }
        var ui by remember { mutableStateOf(LearningUi()) }
        var error by remember { mutableStateOf("") }
        var loading by remember { mutableStateOf(false) }
        var refreshKey by remember { mutableIntStateOf(0) }

        suspend fun refresh() {
            if (!prefs.isConfigured()) {
                error = "ابتدا اپ Trade را به Backend متصل کن."
                return
            }
            loading = true
            error = ""
            try {
                val status = api.status()
                if (!status.ok) throw IllegalStateException(learningHttpError(status))
                val response = api.strategyLearning(240)
                if (!response.ok) throw IllegalStateException(learningHttpError(response))
                ui = parseLearning(JSONObject(response.body).optJSONObject("data") ?: JSONObject())
            } catch (e: Exception) {
                error = e.message ?: "دریافت وضعیت یادگیری ناموفق بود."
            } finally {
                loading = false
            }
        }

        LaunchedEffect(refreshKey, prefs.serverUrl(), prefs.apiToken()) {
            refresh()
            while (true) {
                delay(30_000)
                refresh()
            }
        }

        Surface(Modifier.fillMaxSize(), color = LearningBg) {
            LazyColumn(
                modifier = Modifier.fillMaxSize().statusBarsPadding().navigationBarsPadding(),
                contentPadding = PaddingValues(14.dp),
                verticalArrangement = Arrangement.spacedBy(12.dp),
            ) {
                item {
                    Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                        Column(Modifier.weight(1f)) {
                            Text("یادگیری استراتژی‌ها", style = MaterialTheme.typography.headlineSmall, fontWeight = FontWeight.Black)
                            Text("Trend • Breakout • Mean Reversion", color = LearningMuted, style = MaterialTheme.typography.bodySmall)
                        }
                        TextButton(onClick = { refreshKey++ }, enabled = !loading) { Text(if (loading) "…" else "بروزرسانی") }
                    }
                }

                if (!prefs.isConfigured()) {
                    item {
                        LearningCard {
                            Text("اتصال اپ کامل نیست", color = LearningRed, fontWeight = FontWeight.Bold)
                            Text("ابتدا Trade را به Backend متصل کن.", color = LearningMuted)
                            Button(onClick = {
                                context.startActivity(Intent(context, MainActivity::class.java))
                                (context as? Activity)?.finish()
                            }, modifier = Modifier.fillMaxWidth()) { Text("باز کردن Trade") }
                        }
                    }
                } else {
                    if (error.isNotBlank()) {
                        item {
                            Card(colors = CardDefaults.cardColors(containerColor = LearningRedSoft), shape = RoundedCornerShape(18.dp)) {
                                Text(error, color = LearningRed, modifier = Modifier.padding(15.dp))
                            }
                        }
                    }

                    item {
                        LearningCard {
                            Row(verticalAlignment = Alignment.CenterVertically) {
                                Column(Modifier.weight(1f)) {
                                    Text("${ui.samples} معامله برای یادگیری", fontWeight = FontWeight.Black)
                                    Text("فقط نتیجه خالص معاملات بسته‌شده", color = LearningMuted, style = MaterialTheme.typography.bodySmall)
                                }
                                LearningBadge("Risk ↓ only", LearningGreen, LearningGreenSoft)
                            }
                            Text(
                                "این سیستم هیچ‌وقت حجم تنظیم‌شده را بیشتر نمی‌کند. عملکرد ضعیف فقط همان استراتژی و همان نوع بازار را محتاط‌تر می‌کند.",
                                color = LearningMuted,
                                style = MaterialTheme.typography.bodySmall,
                            )
                        }
                    }

                    item { Text("سه روش اصلی", style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Black) }
                    items(ui.strategies, key = { it.key }) { strategy ->
                        StrategyLearningSummary(strategy)
                    }

                    item {
                        Spacer(Modifier.height(2.dp))
                        Text("یادگیری بر اساس نوع بازار", style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Black)
                        Text("هر ترکیب استراتژی + وضعیت بازار مستقل یاد گرفته می‌شود.", color = LearningMuted, style = MaterialTheme.typography.bodySmall)
                    }

                    if (ui.profiles.isEmpty()) {
                        item {
                            LearningCard {
                                Text("هنوز داده کافی وجود ندارد", fontWeight = FontWeight.Bold)
                                Text("تا ثبت معاملات بسته‌شده با موتور Multi‑Strategy، همه روش‌ها با حجم عادی کار می‌کنند.", color = LearningMuted)
                            }
                        }
                    } else {
                        items(ui.profiles, key = { it.strategy + ":" + it.regime }) { profile ->
                            StrategyLearningProfile(profile)
                        }
                    }
                }
            }
        }
    }
}

@Composable
private fun StrategyLearningSummary(item: LearningStrategyUi) {
    val multiplier = item.multiplier.coerceIn(0.0, 1.0)
    val statusColor = when {
        item.blockedProfiles > 0 -> LearningRed
        multiplier < 0.95 -> LearningAmber
        else -> LearningGreen
    }
    val statusBg = when {
        item.blockedProfiles > 0 -> LearningRedSoft
        multiplier < 0.95 -> LearningAmberSoft
        else -> LearningGreenSoft
    }
    LearningCard {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Box(
                Modifier.size(44.dp).background(LearningPurpleSoft, RoundedCornerShape(14.dp)),
                contentAlignment = Alignment.Center,
            ) { Text(strategyShort(item.key), color = LearningPrimary, fontWeight = FontWeight.Black) }
            Spacer(Modifier.width(10.dp))
            Column(Modifier.weight(1f)) {
                Text(strategyFa(item.key), fontWeight = FontWeight.Black)
                Text("${item.trades} معامله بسته‌شده", color = LearningMuted, style = MaterialTheme.typography.bodySmall)
            }
            LearningBadge("${(multiplier * 100).toInt()}٪", statusColor, statusBg)
        }
        LinearProgressIndicator(
            progress = { multiplier.toFloat() },
            modifier = Modifier.fillMaxWidth().height(7.dp),
            color = statusColor,
            trackColor = Color(0xFFE9ECF3),
        )
        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
            Text("پروفایل دارای داده: ${item.matureProfiles}", color = LearningMuted, style = MaterialTheme.typography.bodySmall)
            Text("متوقف: ${item.blockedProfiles}", color = if (item.blockedProfiles > 0) LearningRed else LearningMuted, style = MaterialTheme.typography.bodySmall)
        }
    }
}

@Composable
private fun StrategyLearningProfile(item: LearningProfileUi) {
    val multiplier = item.multiplier.coerceIn(0.0, 1.0)
    val status = when {
        item.blocked -> "ورود متوقف"
        !item.ready -> "جمع‌آوری داده"
        multiplier < 0.999 -> "حجم کاهش یافته"
        else -> "عادی"
    }
    val color = when {
        item.blocked -> LearningRed
        !item.ready -> LearningPrimary
        multiplier < 0.95 -> LearningAmber
        else -> LearningGreen
    }
    val bg = when {
        item.blocked -> LearningRedSoft
        !item.ready -> LearningPurpleSoft
        multiplier < 0.95 -> LearningAmberSoft
        else -> LearningGreenSoft
    }

    LearningCard {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Column(Modifier.weight(1f)) {
                Text(strategyFa(item.strategy), fontWeight = FontWeight.Black)
                Text(regimeFa(item.regime), color = LearningMuted, style = MaterialTheme.typography.bodySmall)
            }
            LearningBadge(status, color, bg)
        }
        LinearProgressIndicator(
            progress = { multiplier.toFloat() },
            modifier = Modifier.fillMaxWidth().height(7.dp),
            color = color,
            trackColor = Color(0xFFE9ECF3),
        )
        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            LearningMetric("معامله", item.trades.toString(), Modifier.weight(1f))
            LearningMetric("برد", "${one(item.winRate * 100)}٪", Modifier.weight(1f))
            LearningMetric("حجم بعدی", "${(multiplier * 100).toInt()}٪", Modifier.weight(1f))
        }
        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            LearningMetric("بازده میانگین", "${one(item.avgReturn)}٪", Modifier.weight(1f))
            LearningMetric("سود/زیان", one(item.profitFactor), Modifier.weight(1f))
            LearningMetric("زیان متوالی", item.lossStreak.toString(), Modifier.weight(1f))
        }
    }
}

@Composable
private fun LearningCard(content: @Composable ColumnScope.() -> Unit) {
    Card(shape = RoundedCornerShape(20.dp), colors = CardDefaults.cardColors(containerColor = Color.White)) {
        Column(Modifier.fillMaxWidth().padding(15.dp), verticalArrangement = Arrangement.spacedBy(10.dp), content = content)
    }
}

@Composable
private fun LearningMetric(label: String, value: String, modifier: Modifier = Modifier) {
    Column(modifier.background(Color(0xFFF5F6FA), RoundedCornerShape(12.dp)).padding(9.dp)) {
        Text(label, color = LearningMuted, style = MaterialTheme.typography.labelSmall)
        Text(value, fontWeight = FontWeight.Bold, style = MaterialTheme.typography.bodySmall)
    }
}

@Composable
private fun LearningBadge(text: String, color: Color, bg: Color) {
    Text(text, color = color, fontWeight = FontWeight.Bold, style = MaterialTheme.typography.labelSmall, modifier = Modifier.background(bg, RoundedCornerShape(999.dp)).padding(horizontal = 9.dp, vertical = 5.dp))
}

private fun parseLearning(data: JSONObject): LearningUi {
    val strategies = mutableListOf<LearningStrategyUi>()
    data.optJSONArray("strategies")?.let { rows ->
        for (i in 0 until rows.length()) {
            val row = rows.optJSONObject(i) ?: continue
            strategies += LearningStrategyUi(
                key = row.optString("strategy_key"),
                trades = row.optInt("trades"),
                multiplier = row.optDouble("size_multiplier", 1.0),
                matureProfiles = row.optInt("mature_profiles"),
                blockedProfiles = row.optInt("blocked_profiles"),
            )
        }
    }
    val profiles = mutableListOf<LearningProfileUi>()
    data.optJSONArray("profiles")?.let { rows ->
        for (i in 0 until rows.length()) {
            val row = rows.optJSONObject(i) ?: continue
            profiles += LearningProfileUi(
                strategy = row.optString("strategy_key"),
                regime = row.optString("regime"),
                trades = row.optInt("trades"),
                winRate = row.optDouble("win_rate", 0.0),
                avgReturn = row.optDouble("average_return_percent", 0.0),
                profitFactor = row.optDouble("profit_factor", 1.0),
                lossStreak = row.optInt("recent_loss_streak"),
                multiplier = row.optDouble("size_multiplier", 1.0),
                ready = row.optBoolean("learning_ready"),
                blocked = row.optBoolean("blocked"),
            )
        }
    }
    return LearningUi(
        samples = data.optInt("realized_samples"),
        strategies = strategies,
        profiles = profiles,
        generatedAt = data.optString("generated_at", "—"),
    )
}

private fun strategyFa(key: String): String = when (key) {
    "trend_momentum_v1" -> "روند و مومنتوم"
    "breakout_v1" -> "شکست محدوده"
    "mean_reversion_v1" -> "بازگشت به میانگین"
    else -> key.ifBlank { "نامشخص" }
}

private fun strategyShort(key: String): String = when (key) {
    "trend_momentum_v1" -> "TR"
    "breakout_v1" -> "BR"
    "mean_reversion_v1" -> "MR"
    else -> "AI"
}

private fun regimeFa(key: String): String = when (key) {
    "trending_up" -> "روند صعودی"
    "trending_down" -> "روند نزولی"
    "breakout_up" -> "شکست صعودی"
    "breakout_down" -> "شکست نزولی"
    "ranging" -> "بازار رنج"
    "high_volatility" -> "نوسان شدید"
    "uncertain" -> "نامطمئن"
    else -> key.ifBlank { "نامشخص" }
}

private fun one(value: Double): String = if (value.isFinite()) String.format(java.util.Locale.US, "%.1f", value) else "—"

private fun learningHttpError(response: TradeApi.Response): String {
    return try {
        val root = JSONObject(response.body)
        root.optString("message").ifBlank { root.optString("error") }.ifBlank { "HTTP ${response.code}" }
    } catch (_: Exception) {
        "HTTP ${response.code}"
    }
}
