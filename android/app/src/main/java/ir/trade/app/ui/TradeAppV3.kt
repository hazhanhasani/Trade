package ir.trade.app.ui

import android.content.Intent
import android.net.Uri
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.*
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
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import org.json.JSONArray
import org.json.JSONObject
import java.text.NumberFormat
import java.util.Locale

private val V3Bg = Color(0xFFF3F5F9)
private val V3Surface = Color.White
private val V3Ink = Color(0xFF141725)
private val V3Muted = Color(0xFF72798B)
private val V3Primary = Color(0xFF6246EA)
private val V3Primary2 = Color(0xFF8068F5)
private val V3Success = Color(0xFF0B966D)
private val V3Danger = Color(0xFFD04444)
private val V3Warning = Color(0xFFAD6908)
private val V3Blue = Color(0xFF2563EB)
private val V3Cyan = Color(0xFF0891B2)
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
    CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Rtl) {
        MaterialTheme(
            colorScheme = lightColorScheme(
                primary = V3Primary, secondary = V3Success, background = V3Bg,
                surface = V3Surface, onBackground = V3Ink, onSurface = V3Ink, error = V3Danger,
            ),
        ) {
            Surface(Modifier.fillMaxSize(), color = V3Bg) {
                if (!configured) TradeAppV2()
                else V3Shell(prefs) { prefs.clear(); configured = false }
            }
        }
    }
}

@Composable
private fun V3Shell(prefs: TradePreferences, onDisconnect: () -> Unit) {
    val scope = rememberCoroutineScope()
    val context = LocalContext.current
    val api = remember(prefs.serverUrl(), prefs.apiToken()) { TradeApi(prefs.serverUrl(), prefs.apiToken()) }
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

    fun parseExchange(data: JSONObject, name: String): V3Exchange {
        val x = data.optJSONObject("exchanges")?.optJSONObject(name) ?: JSONObject()
        val p = x.optJSONObject("performance") ?: JSONObject()
        val c = x.optJSONObject("portfolio_capacity") ?: JSONObject()
        val positionsJson = x.optJSONArray("active_positions") ?: JSONArray()
        val positions = buildList {
            for (i in 0 until positionsJson.length()) {
                val row = positionsJson.optJSONObject(i) ?: continue
                add(V3Position(
                    symbol = row.optString("symbol"), quote = row.optString("quote_asset"),
                    amount = row.optDouble("amount", 0.0), entry = row.optDouble("entry_price", 0.0), status = row.optString("status"),
                ))
            }
        }
        return V3Exchange(
            credentials = x.optBoolean("credentials_configured"), bot = x.optBoolean("bot_enabled"), live = x.optBoolean("live_execution_enabled"),
            active = x.optInt("active_position_count"), max = c.optInt("max_positions"), remaining = c.optInt("remaining_position_slots"),
            pending = c.optInt("pending_orders"), maxPending = c.optInt("max_pending_orders"), effectiveEntry = c.optDouble("effective_position_percent"),
            exposureLimit = c.optDouble("portfolio_exposure_limit_percent"), pnlToday = p.optDouble("today_realized_pnl"),
            pnlTotal = p.optDouble("total_realized_pnl"), winRate = p.optDouble("win_rate_percent"), quote = p.optString("quote_asset", "IRT"),
            decision = v3Decision(x.optJSONObject("last_decision")), signal = v3Signal(x.optJSONObject("latest_signal")), positions = positions,
        )
    }

    fun parseGlobal(root: JSONObject): V3Global {
        val d = root.optJSONObject("data") ?: root
        return V3Global(
            status = d.optString("status", "unknown"), portfolioIrt = d.optDouble("portfolio_value_irt", 0.0),
            exposureIrt = d.optDouble("exposure_irt", 0.0), exposurePercent = d.optDouble("exposure_percent", 0.0),
            usdtToIrt = if (d.has("usdt_to_irt_rate") && !d.isNull("usdt_to_irt_rate")) d.optDouble("usdt_to_irt_rate") else null,
        )
    }

    fun parseAnalytics(root: JSONObject): V3Analytics {
        val d = root.optJSONObject("data") ?: JSONObject()
        val summaries = d.optJSONObject("summary_by_quote") ?: JSONObject()
        val irt = summaries.optJSONObject("IRT") ?: JSONObject()
        val usdt = summaries.optJSONObject("USDT") ?: JSONObject()
        val edge = d.optJSONObject("edge_calibration") ?: JSONObject()
        val best = d.optJSONObject("best_asset")
        val worst = d.optJSONObject("worst_asset")
        val dailyArray = d.optJSONObject("daily_series_by_quote")?.optJSONArray("IRT") ?: JSONArray()
        val daily = buildList {
            for (i in 0 until dailyArray.length()) {
                val r = dailyArray.optJSONObject(i) ?: continue
                add(r.optString("day") to r.optDouble("net_pnl", 0.0))
            }
        }
        fun assetLabel(x: JSONObject?): String = if (x == null) "—" else "${x.optString("asset")}/${x.optString("quote_asset")} • ${v3Compact(x.optDouble("net_pnl",0.0))}"
        return V3Analytics(
            todayIrt=irt.optDouble("today_net_pnl"),weekIrt=irt.optDouble("week_net_pnl"),monthIrt=irt.optDouble("month_net_pnl"),
            winRateIrt=irt.optDouble("win_rate_percent"),profitFactorIrt=irt.optDouble("profit_factor"),todayUsdt=usdt.optDouble("today_net_pnl"),
            weekUsdt=usdt.optDouble("week_net_pnl"),monthUsdt=usdt.optDouble("month_net_pnl"),edgeSamples=edge.optInt("samples"),
            expectedEdge=if(edge.isNull("average_expected_edge_percent"))null else edge.optDouble("average_expected_edge_percent"),
            realizedReturn=if(edge.isNull("average_realized_return_percent"))null else edge.optDouble("average_realized_return_percent"),
            hitRate=if(edge.isNull("directional_hit_rate_percent"))null else edge.optDouble("directional_hit_rate_percent"),
            bestAsset=assetLabel(best),worstAsset=assetLabel(worst),dailyIrt=daily,
        )
    }

    fun parseNotifications(root: JSONObject): Pair<Int,List<V3Notification>> {
        val d=root.optJSONObject("data")?:JSONObject();val arr=d.optJSONArray("items")?:JSONArray();val rows=buildList {
            for(i in 0 until arr.length()) { val r=arr.optJSONObject(i)?:continue; add(V3Notification(r.optLong("id"),r.optString("priority"),r.optString("title"),r.optString("body"),r.optString("created_at"),r.optBoolean("unread"))) }
        };return d.optInt("unread_count") to rows
    }

    suspend fun refreshExtended() {
        try {
            val g=api.globalPortfolio();if(g.ok)global=parseGlobal(JSONObject(g.body))
            val a=api.analytics(30);if(a.ok)analytics=parseAnalytics(JSONObject(a.body))
            val n=api.notifications(60,false);if(n.ok){val parsed=parseNotifications(JSONObject(n.body));unread=parsed.first;notifications=parsed.second}
            val r=api.rotationStatus(10);if(r.ok){val d=JSONObject(r.body).optJSONObject("data")?:JSONObject();rotationText=v3Rotation(d)}
        } catch (_: Exception) { /* core status remains usable when one optional panel is unavailable */ }
    }

    fun refreshWallet() {
        scope.launch {
            try { val r=api.wallets(exchange);if(!r.ok)throw IllegalStateException(v3HttpError(r));wallets=v3Wallets(r.body) }
            catch(e:Exception){error=e.message?:"دریافت کیف پول ناموفق بود"}
        }
    }

    fun refreshAll() {
        scope.launch {
            loading=true;error=""
            try {
                val r=api.status();if(!r.ok)throw IllegalStateException(v3HttpError(r));val data=JSONObject(r.body).optJSONObject("data")?:JSONObject(r.body)
                backendVersion=data.optString("backend_version","-");kill=data.optBoolean("kill_switch");val health=data.optJSONObject("cron_health");cronHealthy=health?.optBoolean("healthy")==true;cronAge=health?.optLong("age_seconds",-1L)?.takeIf{it>=0}
                nobitex=parseExchange(data,"nobitex");bitpin=parseExchange(data,"bitpin");compatible=api.isContractCompatible()
                refreshExtended()
                val w=api.wallets(exchange);if(w.ok)wallets=v3Wallets(w.body)
            }catch(e:Exception){error=e.message?:"دریافت وضعیت Backend ناموفق بود"}finally{loading=false}
        }
    }

    fun selectExchange(value:String){exchange=value;symbol=if(value=="nobitex")"BTCIRT" else "BTC_IRT";wallets=emptyList();refreshWallet()}
    fun setBot(enabled:Boolean){scope.launch{loading=true;try{val r=api.setExchangeBot(exchange,enabled);if(!r.ok)throw IllegalStateException(v3HttpError(r));message="ربات $exchangeTitle ${if(enabled)"فعال" else "غیرفعال"} شد.";refreshAll()}catch(e:Exception){error=e.message?:"تغییر Bot ناموفق بود"}finally{loading=false}}}
    fun setLive(enabled:Boolean){scope.launch{loading=true;try{val r=api.setExchangeLive(exchange,enabled);if(!r.ok)throw IllegalStateException(v3HttpError(r));message="Live $exchangeTitle ${if(enabled)"فعال" else "غیرفعال"} شد.";refreshAll()}catch(e:Exception){error=e.message?:"تغییر Live ناموفق بود"}finally{loading=false}}}
    fun setKill(enabled:Boolean){scope.launch{loading=true;try{val r=api.setKillSwitch(enabled);if(!r.ok)throw IllegalStateException(v3HttpError(r));message=if(enabled)"توقف اضطراری فعال شد." else "توقف اضطراری برداشته شد.";refreshAll()}catch(e:Exception){error=e.message?:"تغییر Kill Switch ناموفق بود"}finally{loading=false}}}
    fun runNow(){scope.launch{loading=true;try{val r=api.runExchange(exchange);if(!r.ok)throw IllegalStateException(v3HttpError(r));message="چرخه $exchangeTitle اجرا شد.";refreshAll()}catch(e:Exception){error=e.message?:"اجرای چرخه ناموفق بود"}finally{loading=false}}}
    fun submitOrder(){val a=amount.toDoubleOrNull();val p=price.toDoubleOrNull();if(symbol.isBlank()||a==null||a<=0||(mode!="market"&&(p==null||p<=0))){error="نماد، مقدار و قیمت سفارش را بررسی کن.";return};scope.launch{loading=true;try{val payload=JSONObject().put("exchange",exchange).put("symbol",symbol.trim().uppercase()).put("amount1",a).put("mode",mode).put("type",side);if(p!=null&&p>0)payload.put("price",p);val r=api.createOrder(payload.toString());if(!r.ok)throw IllegalStateException(v3HttpError(r));message="سفارش واقعی ارسال شد.";refreshAll()}catch(e:Exception){error=e.message?:"ثبت سفارش ناموفق بود"}finally{loading=false}}}
    fun cancelOrder(){if(cancelId.isBlank()){error="شناسه سفارش را وارد کن.";return};scope.launch{loading=true;try{val r=api.cancelOrder(cancelId.trim(),exchange);if(!r.ok)throw IllegalStateException(v3HttpError(r));cancelId="";message="درخواست لغو ارسال شد.";refreshAll()}catch(e:Exception){error=e.message?:"لغو سفارش ناموفق بود"}finally{loading=false}}}
    fun markRead(id:Long?=null,all:Boolean=false){scope.launch{try{api.markNotificationRead(id,all);val n=api.notifications(60,false);if(n.ok){val parsed=parseNotifications(JSONObject(n.body));unread=parsed.first;notifications=parsed.second}}catch(e:Exception){error=e.message?:"بروزرسانی اعلان ناموفق بود"}}}

    LaunchedEffect(Unit){refreshAll()}
    LaunchedEffect(Unit){while(true){delay(60_000);try{val n=api.notifications(60,false);if(n.ok){val parsed=parseNotifications(JSONObject(n.body));unread=parsed.first;notifications=parsed.second}}catch(_:Exception){}}}

    val nav=listOf(V3Nav("داشبورد",Icons.Rounded.Dashboard),V3Nav("پرتفو",Icons.Rounded.AccountBalanceWallet),V3Nav("معامله",Icons.Rounded.SwapHoriz),V3Nav("اتوماسیون",Icons.Rounded.SmartToy),V3Nav("بیشتر",Icons.Rounded.GridView))

    Scaffold(containerColor=V3Bg,bottomBar={NavigationBar(containerColor=Color.White,tonalElevation=8.dp){nav.forEachIndexed{index,item->NavigationBarItem(selected=tab==index,onClick={tab=index;if(index!=4)morePage="root"},icon={Icon(item.icon,item.title)},label={Text(item.title,maxLines=1)},colors=NavigationBarItemDefaults.colors(selectedIconColor=V3Primary,selectedTextColor=V3Primary,indicatorColor=V3Soft,unselectedIconColor=Color(0xFF9CA3AF),unselectedTextColor=V3Muted))}}}){padding->
        Column(Modifier.fillMaxSize().padding(padding).statusBarsPadding()){
            V3TopBar(exchange,current,cronHealthy,unread,loading,{selectExchange(it)},{refreshAll()})
            if(error.isNotBlank())Box(Modifier.padding(horizontal=14.dp,vertical=4.dp)){V3Banner("خطا",error,V3Danger,V3DangerSoft)}
            if(message.isNotBlank())Box(Modifier.padding(horizontal=14.dp,vertical=4.dp)){V3Banner("انجام شد",message,V3Success,V3SuccessSoft)}
            Box(Modifier.weight(1f)){
                when(tab){
                    0->V3Dashboard(current,global,analytics,cronHealthy,cronAge,kill,compatible)
                    1->V3Portfolio(exchangeTitle,current,wallets,global,loading){refreshWallet()}
                    2->V3Trade(exchangeTitle,current,kill,compatible,loading,symbol,amount,price,side,mode,cancelId,{symbol=it},{amount=it},{price=it},{side=it},{mode=it},{cancelId=it},{submitOrder()},{cancelOrder()})
                    3->V3Automation(exchangeTitle,current,kill,compatible,loading,rotationText,{setBot(it)},{setLive(it)},{runNow()},{context.startActivity(Intent(Intent.ACTION_VIEW,Uri.parse("trade://rotation")))})
                    else->when(morePage){
                        "analytics"->V3AnalyticsScreen(analytics){morePage="root"}
                        "notifications"->V3NotificationsScreen(notifications,unread,{morePage="root"},{markRead(it,false)},{markRead(null,true)})
                        else->V3More(kill,cronHealthy,cronAge,backendVersion,compatible,unread,updateState,{morePage="analytics"},{morePage="notifications"},{setKill(it)},requestUpdate,onDisconnect)
                    }
                }
            }
        }
    }
}

@Composable private fun V3TopBar(exchange:String,state:V3Exchange,cronHealthy:Boolean,unread:Int,loading:Boolean,onExchange:(String)->Unit,onRefresh:()->Unit){
    Column(Modifier.fillMaxWidth().background(Color.White).padding(horizontal=15.dp,vertical=9.dp),verticalArrangement=Arrangement.spacedBy(8.dp)){
        Row(Modifier.fillMaxWidth(),verticalAlignment=Alignment.CenterVertically){Box(Modifier.size(42.dp).clip(RoundedCornerShape(14.dp)).background(Brush.linearGradient(listOf(V3Primary,V3Primary2))),contentAlignment=Alignment.Center){Text("T",color=Color.White,fontWeight=FontWeight.Black,fontSize=20.sp)};Spacer(Modifier.width(10.dp));Column(Modifier.weight(1f)){Text("Trade Cockpit",fontWeight=FontWeight.Black,style=MaterialTheme.typography.titleLarge);Text(if(state.bot&&state.live)"موتور $exchange فعال" else "کنترل معاملات",color=V3Muted,style=MaterialTheme.typography.labelMedium)};if(unread>0)V3Pill("$unread اعلان",V3Warning,V3WarningSoft);Spacer(Modifier.width(5.dp));V3Pill(if(cronHealthy)"LIVE" else "CHECK",if(cronHealthy)V3Success else V3Warning,if(cronHealthy)V3SuccessSoft else V3WarningSoft);IconButton(onClick=onRefresh,enabled=!loading){Icon(Icons.Rounded.Refresh,null,tint=V3Primary)}}
        Row(horizontalArrangement=Arrangement.spacedBy(8.dp)){V3Segment("Nobitex",exchange=="nobitex",Modifier.weight(1f)){onExchange("nobitex")};V3Segment("Bitpin",exchange=="bitpin",Modifier.weight(1f)){onExchange("bitpin")}}
    }
}

@Composable private fun V3Dashboard(current:V3Exchange,global:V3Global,a:V3Analytics,cronHealthy:Boolean,cronAge:Long?,kill:Boolean,compatible:Boolean){
    LazyColumn(Modifier.fillMaxSize(),contentPadding=PaddingValues(16.dp),verticalArrangement=Arrangement.spacedBy(12.dp)){
        item{Text("داشبورد",style=MaterialTheme.typography.headlineSmall,fontWeight=FontWeight.Black);Text("فقط آمار و وضعیت؛ بدون تنظیم یا کنترل عملیاتی",color=V3Muted)}
        item{Card(shape=RoundedCornerShape(28.dp),colors=CardDefaults.cardColors(containerColor=Color.Transparent)){Box(Modifier.fillMaxWidth().background(Brush.linearGradient(listOf(Color(0xFF111827),Color(0xFF30286B),V3Primary))).padding(20.dp)){Column{Text("ارزش پورتفوی یکپارچه",color=Color(0xFFC7CBD6));Text(if(global.status=="ok")"${v3Number(global.portfolioIrt)} IRT" else "—",color=Color.White,fontWeight=FontWeight.Black,style=MaterialTheme.typography.headlineMedium);Spacer(Modifier.height(12.dp));Row(horizontalArrangement=Arrangement.spacedBy(7.dp)){V3DarkPill("Exposure ${v3One(global.exposurePercent)}%");V3DarkPill("${current.active}${if(current.max>0)"/${current.max}" else ""} Position");V3DarkPill(if(kill)"STOP" else "RUN")}}}}}
        item{Row(horizontalArrangement=Arrangement.spacedBy(8.dp)){V3Stat("PnL امروز",v3Compact(current.pnlToday),current.pnlToday>=0,Modifier.weight(1f));V3Stat("Win Rate","${v3One(current.winRate)}%",current.winRate>=50,Modifier.weight(1f));V3Stat("Pending","${current.pending}/${current.maxPending}",current.pending==0,Modifier.weight(1f))}}
        item{V3CardTitle("ریسک و ظرفیت","Global Portfolio Exposure");V3Progress((global.exposurePercent/100.0).toFloat().coerceIn(0f,1f));Row(Modifier.fillMaxWidth(),horizontalArrangement=Arrangement.SpaceBetween){Text("${v3One(global.exposurePercent)}% درگیر",fontWeight=FontWeight.Bold);Text("سقف ${v3One(current.exposureLimit)}%",color=V3Muted)}}
        item{V3CardTitle("عملکرد ۳۰ روزه","Fee-aware");Row(horizontalArrangement=Arrangement.spacedBy(8.dp)){V3Metric("هفته IRT",v3Compact(a.weekIrt),Modifier.weight(1f));V3Metric("ماه IRT",v3Compact(a.monthIrt),Modifier.weight(1f));V3Metric("Profit Factor",v3One(a.profitFactorIrt),Modifier.weight(1f))}}
        item{V3CardTitle("وضعیت سیستم","Read only");Row(horizontalArrangement=Arrangement.spacedBy(8.dp)){V3Metric("Cron",if(cronHealthy)"سالم" else "بررسی",Modifier.weight(1f));V3Metric("آخرین اجرا",cronAge?.let{"${it}s"}?:"—",Modifier.weight(1f));V3Metric("Contract",if(compatible)"هماهنگ" else "ناسازگار",Modifier.weight(1f))}}
        item{V3CardTitle("آخرین تصمیم ربات",null);Text(current.decision,color=V3Muted,style=MaterialTheme.typography.bodySmall);HorizontalDivider(Modifier.padding(vertical=10.dp),color=V3Stroke);Text(current.signal,color=V3Muted,style=MaterialTheme.typography.bodySmall)}
    }
}

@Composable private fun V3Portfolio(name:String,current:V3Exchange,wallets:List<V3Wallet>,global:V3Global,loading:Boolean,onRefresh:()->Unit){
    LazyColumn(Modifier.fillMaxSize(),contentPadding=PaddingValues(16.dp),verticalArrangement=Arrangement.spacedBy(10.dp)){
        item{Row(verticalAlignment=Alignment.CenterVertically){Column(Modifier.weight(1f)){Text("پرتفوی $name",style=MaterialTheme.typography.titleLarge,fontWeight=FontWeight.Black);Text("دارایی، پوزیشن و Exposure",color=V3Muted)};IconButton(onClick=onRefresh,enabled=!loading){Icon(Icons.Rounded.Refresh,null)}}}
        if(name=="Nobitex")item{V3CardTitle("Global Exposure","IRT + USDT → IRT");Text("${v3One(global.exposurePercent)}% • ${v3Number(global.exposureIrt)} IRT درگیر",fontWeight=FontWeight.Black);V3Progress((global.exposurePercent/100).toFloat().coerceIn(0f,1f));Text(global.usdtToIrt?.let{"USDT/IRT ${v3Number(it)}"}?:"نرخ تبدیل در این لحظه موجود نیست",color=V3Muted,style=MaterialTheme.typography.bodySmall)}
        item{V3CardTitle("پوزیشن‌های فعال","${current.active}${if(current.max>0)"/${current.max}" else ""}");if(current.positions.isEmpty())Text("پوزیشن فعالی نیست.",color=V3Muted)else current.positions.forEach{p->Row(Modifier.fillMaxWidth().padding(vertical=7.dp),verticalAlignment=Alignment.CenterVertically){Column(Modifier.weight(1f)){Text(p.symbol,fontWeight=FontWeight.Bold);Text("${v3Compact(p.amount)} • ورود ${v3Compact(p.entry)}",color=V3Muted,style=MaterialTheme.typography.bodySmall)};V3Pill(p.status,V3Blue,Color(0xFFEDF4FF))}}}
        item{Text("کیف پول",fontWeight=FontWeight.Black,style=MaterialTheme.typography.titleMedium)}
        if(wallets.isEmpty())item{Text(if(loading)"در حال دریافت…" else "دارایی قابل نمایش پیدا نشد.",color=V3Muted)}else items(wallets,key={it.code}){w->Card(shape=RoundedCornerShape(18.dp),colors=CardDefaults.cardColors(containerColor=Color.White)){Row(Modifier.fillMaxWidth().padding(14.dp),verticalAlignment=Alignment.CenterVertically){Box(Modifier.size(42.dp).clip(RoundedCornerShape(13.dp)).background(V3Soft),contentAlignment=Alignment.Center){Text(w.code.take(3),color=V3Primary,fontWeight=FontWeight.Black)};Spacer(Modifier.width(10.dp));Column(Modifier.weight(1f)){Text(w.code,fontWeight=FontWeight.Bold);Text(v3Compact(w.balance),color=V3Muted)};Text(if(w.value>0)v3Number(w.value) else "—",fontWeight=FontWeight.Bold)}}}
    }
}

@Composable private fun V3Trade(name:String,state:V3Exchange,kill:Boolean,compatible:Boolean,loading:Boolean,symbol:String,amount:String,price:String,side:String,mode:String,cancelId:String,onSymbol:(String)->Unit,onAmount:(String)->Unit,onPrice:(String)->Unit,onSide:(String)->Unit,onMode:(String)->Unit,onCancelId:(String)->Unit,onSubmit:()->Unit,onCancel:()->Unit){
    val enabled=compatible&&state.credentials&&state.live&&!kill&&!loading
    LazyColumn(Modifier.fillMaxSize(),contentPadding=PaddingValues(16.dp),verticalArrangement=Arrangement.spacedBy(11.dp)){
        item{Text("معامله",style=MaterialTheme.typography.titleLarge,fontWeight=FontWeight.Black);Text("سفارش دستی واقعی روی $name",color=V3Muted)}
        item{V3Banner(if(enabled)"Execution آماده است" else "Execution قفل است",when{kill->"Kill Switch فعال است.";!compatible->"App و Backend هماهنگ نیستند.";!state.credentials->"API صرافی تنظیم نشده.";!state.live->"Live Execution خاموش است.";else->"آماده ارسال سفارش."},if(enabled)V3Success else V3Warning,if(enabled)V3SuccessSoft else V3WarningSoft)}
        item{V3Card{OutlinedTextField(symbol,onSymbol,label={Text("نماد بازار")},singleLine=true,modifier=Modifier.fillMaxWidth());OutlinedTextField(amount,onAmount,label={Text("مقدار")},singleLine=true,modifier=Modifier.fillMaxWidth());Row(horizontalArrangement=Arrangement.spacedBy(8.dp)){V3Choice("خرید",side=="buy",V3Success,Modifier.weight(1f)){onSide("buy")};V3Choice("فروش",side=="sell",V3Danger,Modifier.weight(1f)){onSide("sell")}};Row(horizontalArrangement=Arrangement.spacedBy(8.dp)){V3Choice("Market",mode=="market",V3Primary,Modifier.weight(1f)){onMode("market")};V3Choice("Limit",mode=="limit",V3Blue,Modifier.weight(1f)){onMode("limit")}};if(mode!="market")OutlinedTextField(price,onPrice,label={Text("قیمت")},singleLine=true,modifier=Modifier.fillMaxWidth());Button(onClick=onSubmit,enabled=enabled,modifier=Modifier.fillMaxWidth().height(52.dp)){Text("ارسال سفارش واقعی",fontWeight=FontWeight.Bold)}}}
        item{V3Card{Text("لغو سفارش",fontWeight=FontWeight.Black);OutlinedTextField(cancelId,onCancelId,label={Text("Order ID / Client Order ID")},singleLine=true,modifier=Modifier.fillMaxWidth());OutlinedButton(onClick=onCancel,enabled=state.credentials&&cancelId.isNotBlank()&&!loading,modifier=Modifier.fillMaxWidth()){Text("لغو سفارش")}}}
    }
}

@Composable private fun V3Automation(name:String,state:V3Exchange,kill:Boolean,compatible:Boolean,loading:Boolean,rotationText:String,onBot:(Boolean)->Unit,onLive:(Boolean)->Unit,onRun:()->Unit,onRotation:()->Unit){
    LazyColumn(Modifier.fillMaxSize(),contentPadding=PaddingValues(16.dp),verticalArrangement=Arrangement.spacedBy(11.dp)){
        item{Text("اتوماسیون",style=MaterialTheme.typography.titleLarge,fontWeight=FontWeight.Black);Text("Bot، Live، Intelligence و Rotation",color=V3Muted)}
        item{V3Toggle("Auto Trading $name","اجرای خودکار در Cron",state.bot,Icons.Rounded.SmartToy,V3Primary,state.credentials&&!loading,onBot)}
        item{V3Toggle("Live Execution $name","اجازه ارسال سفارش واقعی",state.live,Icons.Rounded.Bolt,V3Success,state.credentials&&!loading,onLive)}
        item{V3CardTitle("Portfolio Intelligence","Drawdown + Correlation + Strategy Learning");Text("Entry ${v3One(state.effectiveEntry)}% • Global cap ${v3One(state.exposureLimit)}% • Pending ${state.pending}/${state.maxPending}",color=V3Muted)}
        item{V3CardTitle("Portfolio Rotation","Opportunity Replacement");Text(rotationText,color=V3Muted,style=MaterialTheme.typography.bodySmall);Spacer(Modifier.height(9.dp));OutlinedButton(onClick=onRotation,modifier=Modifier.fillMaxWidth()){Text("جزئیات Rotation")}}
        item{Button(onClick=onRun,enabled=compatible&&!loading&&state.credentials&&state.bot&&state.live&&!kill,modifier=Modifier.fillMaxWidth().height(52.dp)){Icon(Icons.Rounded.PlayArrow,null);Spacer(Modifier.width(7.dp));Text("اجرای یک چرخه",fontWeight=FontWeight.Bold)}}
        if(kill)item{V3Banner("توقف اضطراری فعال است","برای تغییر Kill Switch به بخش «بیشتر ← سیستم» برو.",V3Danger,V3DangerSoft)}
    }
}

@Composable private fun V3More(kill:Boolean,cronHealthy:Boolean,cronAge:Long?,backendVersion:String,compatible:Boolean,unread:Int,updateState:UpdateUiState,onAnalytics:()->Unit,onNotifications:()->Unit,onKill:(Boolean)->Unit,onUpdate:()->Unit,onDisconnect:()->Unit){
    LazyColumn(Modifier.fillMaxSize(),contentPadding=PaddingValues(16.dp),verticalArrangement=Arrangement.spacedBy(11.dp)){
        item{Text("بیشتر",style=MaterialTheme.typography.titleLarge,fontWeight=FontWeight.Black);Text("سیستم، تحلیل عملکرد، اعلان‌ها و Release",color=V3Muted)}
        item{Row(horizontalArrangement=Arrangement.spacedBy(9.dp)){V3ActionCard("تحلیل عملکرد","PnL • Edge • Strategy",Icons.Rounded.Insights,V3Blue,Modifier.weight(1f),onAnalytics);V3ActionCard("اعلان‌ها","$unread خوانده‌نشده",Icons.Rounded.Notifications,V3Warning,Modifier.weight(1f),onNotifications)}}
        item{V3Toggle("Kill Switch سراسری","توقف سفارش‌های جدید؛ خروج‌های ایمنی طبق Backend مدیریت می‌شوند",kill,Icons.Rounded.PowerSettingsNew,V3Danger,true,onKill)}
        item{V3CardTitle("System Health",null);V3Row("Cron",if(cronHealthy)"سالم" else "نیازمند بررسی");V3Row("آخرین اجرا",cronAge?.let{"${it}s قبل"}?:"—");V3Row("Backend","v$backendVersion");V3Row("App","v${BuildConfig.VERSION_NAME}");V3Row("API Contract",if(compatible)"هماهنگ" else "ناسازگار")}
        item{V3Banner(if(updateState.versionsSynchronized)"Release هماهنگ است" else "وضعیت Release",updateState.lastResult,if(updateState.versionsSynchronized)V3Success else V3Primary,if(updateState.versionsSynchronized)V3SuccessSoft else V3Soft)}
        item{Button(onClick=onUpdate,enabled=!updateState.checking,modifier=Modifier.fillMaxWidth().height(50.dp)){Icon(Icons.Rounded.Update,null);Spacer(Modifier.width(7.dp));Text(if(updateState.checking)"در حال بررسی…" else "بررسی نسخه جدید")}}
        item{OutlinedButton(onClick=onDisconnect,modifier=Modifier.fillMaxWidth()){Icon(Icons.Rounded.Logout,null);Spacer(Modifier.width(7.dp));Text("قطع اتصال این گوشی")}}
    }
}

@Composable private fun V3AnalyticsScreen(a:V3Analytics,onBack:()->Unit){
    LazyColumn(Modifier.fillMaxSize(),contentPadding=PaddingValues(16.dp),verticalArrangement=Arrangement.spacedBy(11.dp)){
        item{Row(verticalAlignment=Alignment.CenterVertically){IconButton(onClick=onBack){Icon(Icons.Rounded.ArrowForward,null)};Column{Text("Performance Analytics",style=MaterialTheme.typography.titleLarge,fontWeight=FontWeight.Black);Text("نتایج واقعی Fee-aware",color=V3Muted)}}}
        item{Row(horizontalArrangement=Arrangement.spacedBy(8.dp)){V3Stat("امروز IRT",v3Compact(a.todayIrt),a.todayIrt>=0,Modifier.weight(1f));V3Stat("هفته IRT",v3Compact(a.weekIrt),a.weekIrt>=0,Modifier.weight(1f));V3Stat("ماه IRT",v3Compact(a.monthIrt),a.monthIrt>=0,Modifier.weight(1f))}}
        item{Row(horizontalArrangement=Arrangement.spacedBy(8.dp)){V3Metric("Win Rate","${v3One(a.winRateIrt)}%",Modifier.weight(1f));V3Metric("Profit Factor",v3One(a.profitFactorIrt),Modifier.weight(1f));V3Metric("Edge Samples",a.edgeSamples.toString(),Modifier.weight(1f))}}
        item{V3CardTitle("USDT PnL",null);V3Row("امروز",v3Compact(a.todayUsdt));V3Row("هفته",v3Compact(a.weekUsdt));V3Row("ماه",v3Compact(a.monthUsdt))}
        item{V3CardTitle("Edge Calibration","Expected vs Realized");V3Row("Expected Edge",a.expectedEdge?.let{"${v3One(it)}%"}?:"—");V3Row("Realized Return",a.realizedReturn?.let{"${v3One(it)}%"}?:"—");V3Row("Directional Hit",a.hitRate?.let{"${v3One(it)}%"}?:"—")}
        item{V3CardTitle("Asset Performance",null);V3Row("بهترین",a.bestAsset);V3Row("ضعیف‌ترین",a.worstAsset)}
        item{V3CardTitle("روزهای اخیر IRT",null);a.dailyIrt.takeLast(10).forEach{(day,pnl)->V3Row(day,v3Compact(pnl),pnl>=0)}}
    }
}

@Composable private fun V3NotificationsScreen(items:List<V3Notification>,unread:Int,onBack:()->Unit,onRead:(Long)->Unit,onReadAll:()->Unit){
    LazyColumn(Modifier.fillMaxSize(),contentPadding=PaddingValues(16.dp),verticalArrangement=Arrangement.spacedBy(9.dp)){
        item{Row(verticalAlignment=Alignment.CenterVertically){IconButton(onClick=onBack){Icon(Icons.Rounded.ArrowForward,null)};Column(Modifier.weight(1f)){Text("اعلان‌ها",style=MaterialTheme.typography.titleLarge,fontWeight=FontWeight.Black);Text("$unread خوانده‌نشده",color=V3Muted)};TextButton(onClick=onReadAll,enabled=unread>0){Text("خواندن همه")}}}
        if(items.isEmpty())item{Text("اعلانی ثبت نشده است.",color=V3Muted)}else items(items,key={it.id}){n->Card(onClick={if(n.unread)onRead(n.id)},shape=RoundedCornerShape(18.dp),colors=CardDefaults.cardColors(containerColor=if(n.unread)V3Soft else Color.White)){Row(Modifier.fillMaxWidth().padding(14.dp),verticalAlignment=Alignment.Top){Box(Modifier.size(38.dp).clip(RoundedCornerShape(12.dp)).background(v3PriorityTone(n.priority).copy(alpha=.12f)),contentAlignment=Alignment.Center){Icon(Icons.Rounded.Notifications,null,tint=v3PriorityTone(n.priority),modifier=Modifier.size(20.dp))};Spacer(Modifier.width(10.dp));Column(Modifier.weight(1f)){Row{Text(n.title,fontWeight=if(n.unread)FontWeight.Black else FontWeight.Bold,modifier=Modifier.weight(1f));if(n.unread)V3Pill("NEW",V3Primary,V3Soft)};Text(n.body,color=V3Muted,style=MaterialTheme.typography.bodySmall);Text(n.createdAt,color=V3Muted,style=MaterialTheme.typography.labelSmall)}}}}
    }
}

@Composable private fun V3Card(content:@Composable ColumnScope.()->Unit){Card(shape=RoundedCornerShape(22.dp),colors=CardDefaults.cardColors(containerColor=Color.White)){Column(Modifier.fillMaxWidth().padding(16.dp),verticalArrangement=Arrangement.spacedBy(10.dp),content=content)}}
@Composable private fun V3CardTitle(title:String,subtitle:String?){Card(shape=RoundedCornerShape(21.dp),colors=CardDefaults.cardColors(containerColor=Color.White)){Column(Modifier.fillMaxWidth().padding(15.dp)){Text(title,fontWeight=FontWeight.Black);if(subtitle!=null)Text(subtitle,color=V3Muted,style=MaterialTheme.typography.bodySmall)}}}
@Composable private fun V3Stat(title:String,value:String,good:Boolean,modifier:Modifier=Modifier){Card(modifier,shape=RoundedCornerShape(18.dp),colors=CardDefaults.cardColors(containerColor=Color.White)){Column(Modifier.padding(12.dp)){Text(title,color=V3Muted,style=MaterialTheme.typography.labelSmall,maxLines=1);Text(value,color=if(good)V3Success else V3Danger,fontWeight=FontWeight.Black,maxLines=1,overflow=TextOverflow.Ellipsis)}}}
@Composable private fun V3Metric(title:String,value:String,modifier:Modifier=Modifier){Box(modifier.clip(RoundedCornerShape(14.dp)).background(Color.White).padding(11.dp)){Column{Text(title,color=V3Muted,style=MaterialTheme.typography.labelSmall);Text(value,fontWeight=FontWeight.Black,maxLines=1,overflow=TextOverflow.Ellipsis)}}}
@Composable private fun V3Progress(value:Float){LinearProgressIndicator(progress={value},modifier=Modifier.fillMaxWidth().height(9.dp).clip(RoundedCornerShape(20.dp)),color=V3Primary,trackColor=Color(0xFFEDF0F5))}
@Composable private fun V3Banner(title:String,text:String,tone:Color,bg:Color){Card(shape=RoundedCornerShape(18.dp),colors=CardDefaults.cardColors(containerColor=bg)){Column(Modifier.fillMaxWidth().padding(13.dp)){Text(title,color=tone,fontWeight=FontWeight.Black);Text(text,color=V3Muted,style=MaterialTheme.typography.bodySmall)}}}
@Composable private fun V3Pill(text:String,tone:Color,bg:Color){Surface(shape=RoundedCornerShape(100.dp),color=bg){Text(text,Modifier.padding(horizontal=8.dp,vertical=4.dp),color=tone,style=MaterialTheme.typography.labelSmall,fontWeight=FontWeight.Bold)}}
@Composable private fun V3DarkPill(text:String){Surface(shape=RoundedCornerShape(100.dp),color=Color.White.copy(alpha=.11f)){Text(text,Modifier.padding(horizontal=9.dp,vertical=5.dp),color=Color.White,style=MaterialTheme.typography.labelSmall,fontWeight=FontWeight.Bold)}}
@Composable private fun V3Segment(title:String,selected:Boolean,modifier:Modifier,onClick:()->Unit){FilledTonalButton(onClick=onClick,modifier=modifier.height(40.dp),shape=RoundedCornerShape(13.dp),colors=ButtonDefaults.filledTonalButtonColors(containerColor=if(selected)V3Soft else Color(0xFFF7F8FA),contentColor=if(selected)V3Primary else V3Muted)){Text(title,fontWeight=if(selected)FontWeight.Black else FontWeight.Medium)}}
@Composable private fun V3Choice(title:String,selected:Boolean,tone:Color,modifier:Modifier,onClick:()->Unit){FilledTonalButton(onClick=onClick,modifier=modifier,colors=ButtonDefaults.filledTonalButtonColors(containerColor=if(selected)tone.copy(alpha=.13f) else Color(0xFFF7F8FA),contentColor=if(selected)tone else V3Muted)){Text(title,fontWeight=if(selected)FontWeight.Black else FontWeight.Medium)}}
@Composable private fun V3Toggle(title:String,text:String,checked:Boolean,icon:ImageVector,tone:Color,enabled:Boolean,onChange:(Boolean)->Unit){Card(shape=RoundedCornerShape(20.dp),colors=CardDefaults.cardColors(containerColor=Color.White)){Row(Modifier.fillMaxWidth().padding(14.dp),verticalAlignment=Alignment.CenterVertically){Box(Modifier.size(40.dp).clip(RoundedCornerShape(13.dp)).background(tone.copy(alpha=.1f)),contentAlignment=Alignment.Center){Icon(icon,null,tint=tone)};Spacer(Modifier.width(10.dp));Column(Modifier.weight(1f)){Text(title,fontWeight=FontWeight.Black);Text(text,color=V3Muted,style=MaterialTheme.typography.bodySmall)};Switch(checked,onChange,enabled=enabled)}}}
@Composable private fun V3ActionCard(title:String,text:String,icon:ImageVector,tone:Color,modifier:Modifier,onClick:()->Unit){Card(onClick=onClick,modifier=modifier,shape=RoundedCornerShape(20.dp),colors=CardDefaults.cardColors(containerColor=Color.White)){Column(Modifier.padding(14.dp),verticalArrangement=Arrangement.spacedBy(8.dp)){Icon(icon,null,tint=tone);Text(title,fontWeight=FontWeight.Black);Text(text,color=V3Muted,style=MaterialTheme.typography.bodySmall,maxLines=2)}}}
@Composable private fun V3Row(title:String,value:String,good:Boolean?=null){Row(Modifier.fillMaxWidth().padding(vertical=6.dp)){Text(title,color=V3Muted,modifier=Modifier.weight(1f));Text(value,color=when(good){true->V3Success;false->V3Danger;null->V3Ink},fontWeight=FontWeight.Bold,maxLines=1,overflow=TextOverflow.Ellipsis)}}

private fun v3PriorityTone(p:String)=when(p){"critical"->V3Danger;"warning"->V3Warning;"success"->V3Success;else->V3Blue}
private fun v3Decision(d:JSONObject?):String{if(d==null)return"هنوز تصمیمی ثبت نشده";val status=d.optString("status","-");val reason=d.optString("reason",d.optString("error",""));val selected=d.optJSONObject("selected")?.optString("symbol").orEmpty();return listOf(status,reason,selected).filter{it.isNotBlank()}.joinToString(" • ")}
private fun v3Signal(d:JSONObject?):String{if(d==null)return"هنوز سیگنالی ثبت نشده";val details=d.optJSONObject("details");val edge=details?.optDouble("tradable_net_edge_percent",Double.NaN)?:Double.NaN;return buildString{append(d.optString("symbol","-"));append(" • ");append(d.optString("action","hold").uppercase());if(!edge.isNaN())append(" • Edge ${v3One(edge)}%")}}
private fun v3Rotation(d:JSONObject):String{val status=d.optString("status","-");val weak=d.optJSONObject("weakest_position")?.optString("symbol").orEmpty();val best=d.optJSONObject("best_candidate")?.optString("symbol").orEmpty();return listOf(status,if(weak.isNotBlank())"ضعیف: $weak" else "",if(best.isNotBlank())"جایگزین: $best" else "").filter{it.isNotBlank()}.joinToString(" • ")}
private fun v3HttpError(r:TradeApi.Response):String=try{val j=JSONObject(r.body);j.optString("message").ifBlank{j.optString("error").ifBlank{"HTTP ${r.code}"}}}catch(_:Exception){"HTTP ${r.code}"}
private fun v3Wallets(body:String):List<V3Wallet>{val root=JSONObject(body);val data=root.opt("data");val arr=when(data){is JSONArray->data;is JSONObject->data.optJSONArray("wallets")?:data.optJSONArray("data")?:JSONArray();else->JSONArray()};return buildList{for(i in 0 until arr.length()){val r=arr.optJSONObject(i)?:continue;val code=r.optString("currency",r.optString("asset",r.optString("currencyCode"))).uppercase();if(code.isBlank())continue;val balance=listOf("activeBalance","available","free","balance").firstNotNullOfOrNull{k->if(r.has(k))r.optDouble(k) else null}?:0.0;val value=listOf("rialValue","rial_value","irtValue","valueRls").firstNotNullOfOrNull{k->if(r.has(k))r.optDouble(k) else null}?:0.0;if(balance>0||value>0)add(V3Wallet(code,balance,value))}}}
private fun v3One(v:Double)=if(v.isFinite())String.format(Locale.US,"%.1f",v) else "—"
private fun v3Compact(v:Double):String{if(!v.isFinite())return"—";val a=kotlin.math.abs(v);return when{a>=1_000_000_000->String.format(Locale.US,"%.2fB",v/1_000_000_000);a>=1_000_000->String.format(Locale.US,"%.2fM",v/1_000_000);a>=1_000->String.format(Locale.US,"%.1fK",v/1_000);else->String.format(Locale.US,"%.4f",v).trimEnd('0').trimEnd('.')}}
private fun v3Number(v:Double):String=if(v.isFinite())NumberFormat.getNumberInstance(Locale.US).apply{maximumFractionDigits=2}.format(v) else "—"
