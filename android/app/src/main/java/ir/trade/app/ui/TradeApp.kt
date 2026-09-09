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
private val TradeGreen = Color(0xFF0FAF83)
private val TradeBackground = Color(0xFFF5F7FB)
private val TradeText = Color(0xFF172033)
private val TradeMuted = Color(0xFF68748A)

data class ExchangeUiState(
    val credentials: Boolean = false,
    val bot: Boolean = false,
    val live: Boolean = false,
)

@Composable
fun TradeApp() {
    MaterialTheme(
        colorScheme = lightColorScheme(
            primary = TradeBlue,
            tertiary = TradeGreen,
            background = TradeBackground,
            surface = Color.White,
            onBackground = TradeText,
            onSurface = TradeText,
        ),
    ) {
        val context = LocalContext.current
        val prefs = remember { TradePreferences(context) }
        var configured by remember { mutableStateOf(prefs.isConfigured()) }
        Surface(Modifier.fillMaxSize(), color = MaterialTheme.colorScheme.background) {
            if (!configured) SetupScreen(prefs) { configured = true }
            else DashboardScreen(prefs) { prefs.clear(); configured = false }
        }
    }
}

@Composable
private fun SetupScreen(prefs: TradePreferences, onSaved: () -> Unit) {
    var server by rememberSaveable { mutableStateOf(prefs.serverUrl()) }
    var token by rememberSaveable { mutableStateOf(prefs.apiToken()) }
    var error by rememberSaveable { mutableStateOf("") }
    Box(Modifier.fillMaxSize().statusBarsPadding().navigationBarsPadding().padding(20.dp), contentAlignment = Alignment.Center) {
        Card(Modifier.fillMaxWidth(), shape = RoundedCornerShape(24.dp)) {
            Column(Modifier.padding(22.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
                Text("Trade", style = MaterialTheme.typography.headlineMedium, fontWeight = FontWeight.Bold)
                Text("کنترل امن Bitpin و Nobitex از طریق Backend شخصی", color = TradeMuted)
                InfoBox("امنیت", "کلیدهای اصلی صرافی داخل گوشی ذخیره نمی‌شوند. اپ فقط App API Token مربوط به rado-taxi.sbs را نگه می‌دارد.")
                OutlinedTextField(server, { server = it }, label = { Text("آدرس سرور") }, singleLine = true, modifier = Modifier.fillMaxWidth())
                OutlinedTextField(token, { token = it }, label = { Text("App API Token") }, singleLine = true, visualTransformation = PasswordVisualTransformation(), modifier = Modifier.fillMaxWidth())
                if (error.isNotBlank()) MessageCard(error, true)
                Button(
                    onClick = {
                        try { prefs.save(server.trim(), token.trim()); onSaved() }
                        catch (_: IllegalArgumentException) { error = "آدرس سرور باید HTTPS باشد." }
                    },
                    enabled = server.isNotBlank() && token.isNotBlank(),
                    modifier = Modifier.fillMaxWidth(),
                ) { Text("ذخیره و ورود") }
            }
        }
    }
}

@Composable
private fun DashboardScreen(prefs: TradePreferences, onDisconnect: () -> Unit) {
    val scope = rememberCoroutineScope()
    val api = remember(prefs.serverUrl(), prefs.apiToken()) { TradeApi(prefs.serverUrl(), prefs.apiToken()) }
    var tab by rememberSaveable { mutableIntStateOf(0) }
    var exchange by rememberSaveable { mutableStateOf("nobitex") }
    var bitpin by remember { mutableStateOf(ExchangeUiState()) }
    var nobitex by remember { mutableStateOf(ExchangeUiState()) }
    var killSwitch by remember { mutableStateOf(false) }
    var backendVersion by remember { mutableStateOf("-") }
    var loading by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf("") }
    var message by remember { mutableStateOf("") }
    var dataText by remember { mutableStateOf("") }
    var dataTitle by remember { mutableStateOf("") }
    var symbol by rememberSaveable { mutableStateOf("TONUSDT") }
    var amount by rememberSaveable { mutableStateOf("") }
    var price by rememberSaveable { mutableStateOf("") }
    var side by rememberSaveable { mutableStateOf("buy") }
    var mode by rememberSaveable { mutableStateOf("limit") }
    var cancelId by rememberSaveable { mutableStateOf("") }

    fun selectedState(): ExchangeUiState = if (exchange == "nobitex") nobitex else bitpin
    fun selectedName(): String = if (exchange == "nobitex") "Nobitex" else "Bitpin"

    fun refreshStatus() {
        scope.launch {
            loading = true; error = ""
            try {
                val response = api.status()
                if (!response.ok) throw IllegalStateException(readableHttpError(response))
                val data = JSONObject(response.body).getJSONObject("data")
                backendVersion = data.optString("backend_version", "-")
                killSwitch = data.optBoolean("kill_switch")
                val exchanges = data.optJSONObject("exchanges") ?: JSONObject()
                fun parse(name: String): ExchangeUiState {
                    val x = exchanges.optJSONObject(name) ?: JSONObject()
                    return ExchangeUiState(
                        credentials = x.optBoolean("credentials_configured"),
                        bot = x.optBoolean("bot_enabled"),
                        live = x.optBoolean("live_execution_enabled"),
                    )
                }
                bitpin = parse("bitpin"); nobitex = parse("nobitex")
            } catch (e: Exception) { error = e.message ?: "خطا در دریافت وضعیت" }
            finally { loading = false }
        }
    }

    fun selectExchange(value: String) {
        exchange = value
        symbol = if (value == "nobitex") "TONUSDT" else "TON_USDT"
        dataText = ""; dataTitle = ""; message = ""; error = ""
    }

    fun loadData(kind: String) {
        scope.launch {
            loading = true; error = ""; message = ""
            try {
                val response = when (kind) {
                    "wallets" -> api.wallets(exchange)
                    "markets" -> api.markets(exchange)
                    else -> api.orders(exchange)
                }
                if (!response.ok) throw IllegalStateException(readableHttpError(response))
                dataText = prettyJson(response.body)
                dataTitle = when (kind) { "wallets" -> "کیف پول ${selectedName()}"; "markets" -> "بازارهای ${selectedName()}"; else -> "سفارش‌های ${selectedName()}" }
            } catch (e: Exception) { error = e.message ?: "دریافت اطلاعات ناموفق بود" }
            finally { loading = false }
        }
    }

    fun submitOrder() {
        val a = amount.toDoubleOrNull(); val p = price.toDoubleOrNull()
        if (symbol.isBlank() || a == null || a <= 0 || (mode != "market" && (p == null || p <= 0))) {
            error = "نماد بازار، مقدار و قیمت معتبر را وارد کن."; return
        }
        scope.launch {
            loading = true; error = ""; message = ""
            try {
                val payload = JSONObject()
                    .put("exchange", exchange).put("symbol", symbol.trim().uppercase())
                    .put("amount1", a).put("mode", mode).put("type", side)
                if (p != null && p > 0) payload.put("price", p)
                val response = api.createOrder(payload.toString())
                if (!response.ok) throw IllegalStateException(readableHttpError(response))
                message = "سفارش واقعی در ${selectedName()} ثبت شد."
                refreshStatus()
            } catch (e: Exception) { error = e.message ?: "ثبت سفارش ناموفق بود" }
            finally { loading = false }
        }
    }

    fun cancelOrder() {
        if (cancelId.isBlank()) { error = "Order ID را وارد کن."; return }
        scope.launch {
            loading = true; error = ""; message = ""
            try {
                val response = api.cancelOrder(cancelId.trim(), exchange)
                if (!response.ok) throw IllegalStateException(readableHttpError(response))
                message = "درخواست لغو در ${selectedName()} ارسال شد."; cancelId = ""
            } catch (e: Exception) { error = e.message ?: "لغو سفارش ناموفق بود" }
            finally { loading = false }
        }
    }

    fun setBot(enabled: Boolean) {
        scope.launch {
            loading = true; error = ""
            try { val r = api.setExchangeBot(exchange, enabled); if (!r.ok) throw IllegalStateException(readableHttpError(r)); message = "Bot ${selectedName()} ${if (enabled) "فعال" else "غیرفعال"} شد."; refreshStatus() }
            catch (e: Exception) { error = e.message ?: "تغییر Bot ناموفق بود" }
            finally { loading = false }
        }
    }

    fun setLive(enabled: Boolean) {
        scope.launch {
            loading = true; error = ""
            try { val r = api.setExchangeLive(exchange, enabled); if (!r.ok) throw IllegalStateException(readableHttpError(r)); message = "Live ${selectedName()} ${if (enabled) "فعال" else "غیرفعال"} شد."; refreshStatus() }
            catch (e: Exception) { error = e.message ?: "تغییر Live ناموفق بود" }
            finally { loading = false }
        }
    }

    fun setKill(enabled: Boolean) {
        scope.launch {
            loading = true; error = ""
            try { val r=api.setKillSwitch(enabled); if(!r.ok)throw IllegalStateException(readableHttpError(r));killSwitch=enabled;message=if(enabled)"توقف اضطراری سراسری فعال شد." else "توقف اضطراری برداشته شد." }
            catch(e:Exception){error=e.message?:"تغییر Kill Switch ناموفق بود"}
            finally{loading=false}
        }
    }

    LaunchedEffect(Unit) { refreshStatus() }
    val current = selectedState()

    Column(Modifier.fillMaxSize().statusBarsPadding().navigationBarsPadding()) {
        Column(Modifier.fillMaxWidth().background(Color.White).padding(14.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween, verticalAlignment = Alignment.CenterVertically) {
                Column { Text("Trade", style = MaterialTheme.typography.headlineSmall, fontWeight = FontWeight.ExtraBold); Text("Backend v$backendVersion • GRAM (TON)", color = TradeMuted, style = MaterialTheme.typography.bodySmall) }
                StatusPill(if (current.credentials) "${selectedName()} آماده" else "${selectedName()} بدون API", current.credentials)
            }
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                FilterChip(selected=exchange=="nobitex",onClick={selectExchange("nobitex")},label={Text("Nobitex")})
                FilterChip(selected=exchange=="bitpin",onClick={selectExchange("bitpin")},label={Text("Bitpin")})
                TextButton(onClick=onDisconnect){Text("اتصال اپ")}
            }
        }
        val tabs=listOf("خانه","داده‌ها","معامله","ربات‌ها")
        ScrollableTabRow(selectedTabIndex=tab,edgePadding=8.dp,containerColor=Color.White,divider={}){tabs.forEachIndexed{i,t->Tab(selected=tab==i,onClick={tab=i},text={Text(t,fontWeight=if(tab==i)FontWeight.Bold else FontWeight.Normal)})}}
        if(error.isNotBlank())Box(Modifier.padding(12.dp)){MessageCard(error,true)}
        if(message.isNotBlank())Box(Modifier.padding(horizontal=12.dp,vertical=4.dp)){MessageCard(message,false)}
        Box(Modifier.weight(1f)) {
            when(tab){
                0->HomeTab(exchange,current,bitpin,nobitex,killSwitch,loading,{refreshStatus()},{tab=1},{tab=3})
                1->DataTab(selectedName(),loading,dataTitle,dataText,{loadData("wallets")},{loadData("markets")},{loadData("orders")})
                2->TradeTab(selectedName(),current,killSwitch,loading,symbol,amount,price,side,mode,cancelId,{symbol=it},{amount=it},{price=it},{side=it},{mode=it},{cancelId=it},{submitOrder()},{cancelOrder()})
                else->BotTab(selectedName(),current,bitpin,nobitex,killSwitch,loading,{setBot(it)},{setLive(it)},{setKill(it)},{refreshStatus()})
            }
        }
    }
}

@Composable private fun HomeTab(exchange:String,current:ExchangeUiState,bitpin:ExchangeUiState,nobitex:ExchangeUiState,kill:Boolean,loading:Boolean,onRefresh:()->Unit,onData:()->Unit,onBots:()->Unit){
    Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(16.dp),verticalArrangement=Arrangement.spacedBy(12.dp)){
        Text("داشبورد",style=MaterialTheme.typography.titleLarge,fontWeight=FontWeight.Bold)
        Row(Modifier.fillMaxWidth(),horizontalArrangement=Arrangement.spacedBy(10.dp)){MetricCard("صرافی",if(exchange=="nobitex")"Nobitex" else "Bitpin",Modifier.weight(1f));MetricCard("API",if(current.credentials)"آماده" else "تنظیم نشده",Modifier.weight(1f))}
        Row(Modifier.fillMaxWidth(),horizontalArrangement=Arrangement.spacedBy(10.dp)){MetricCard("Bot",if(current.bot)"ON" else "OFF",Modifier.weight(1f));MetricCard("Live",if(current.live)"ON" else "OFF",Modifier.weight(1f))}
        InfoBox("وضعیت دو صرافی","Nobitex: Bot ${if(nobitex.bot)"ON" else "OFF"} / Live ${if(nobitex.live)"ON" else "OFF"} • Bitpin: Bot ${if(bitpin.bot)"ON" else "OFF"} / Live ${if(bitpin.live)"ON" else "OFF"}. Kill Switch: ${if(kill)"فعال" else "خاموش"}.")
        ActionCard("اطلاعات حساب و بازار","Wallet، Market و Orderهای صرافی انتخاب‌شده را ببین.","باز کردن داده‌ها",onData)
        ActionCard("کنترل ربات‌ها","Bitpin و Nobitex مستقل روشن و خاموش می‌شوند.","مدیریت Bot و Live",onBots)
        OutlinedButton(onClick=onRefresh,enabled=!loading,modifier=Modifier.fillMaxWidth()){Text(if(loading)"در حال بررسی..." else "بروزرسانی وضعیت")}
    }
}

@Composable private fun DataTab(name:String,loading:Boolean,title:String,text:String,onWallets:()->Unit,onMarkets:()->Unit,onOrders:()->Unit){
    Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(16.dp),verticalArrangement=Arrangement.spacedBy(12.dp)){
        Text("داده‌های $name",style=MaterialTheme.typography.titleLarge,fontWeight=FontWeight.Bold)
        Row(horizontalArrangement=Arrangement.spacedBy(8.dp)){OutlinedButton(onClick=onWallets,enabled=!loading){Text("Wallet")};OutlinedButton(onClick=onMarkets,enabled=!loading){Text("Markets")};OutlinedButton(onClick=onOrders,enabled=!loading){Text("Orders")}}
        if(text.isNotBlank())Card(Modifier.fillMaxWidth(),shape=RoundedCornerShape(18.dp)){Column(Modifier.padding(14.dp),verticalArrangement=Arrangement.spacedBy(8.dp)){Text(title,fontWeight=FontWeight.Bold);Text(text.take(8000),style=MaterialTheme.typography.bodySmall,color=TradeMuted);if(text.length>8000)Text("خروجی خلاصه شده است.",color=TradeMuted)}}
    }
}

@Composable private fun TradeTab(name:String,state:ExchangeUiState,kill:Boolean,loading:Boolean,symbol:String,amount:String,price:String,side:String,mode:String,cancelId:String,onSymbol:(String)->Unit,onAmount:(String)->Unit,onPrice:(String)->Unit,onSide:(String)->Unit,onMode:(String)->Unit,onCancelId:(String)->Unit,onSubmit:()->Unit,onCancel:()->Unit){
    val enabled=!loading&&state.credentials&&state.live&&!kill
    Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(16.dp),verticalArrangement=Arrangement.spacedBy(12.dp)){
        Text("معامله $name",style=MaterialTheme.typography.titleLarge,fontWeight=FontWeight.Bold)
        if(!enabled)InfoBox("ارسال سفارش غیرفعال",when{kill->"Kill Switch سراسری روشن است.";!state.credentials->"API این صرافی روی Backend تنظیم نشده است.";!state.live->"Live Execution این صرافی خاموش است.";else->"سیستم آماده نیست."})
        Card(Modifier.fillMaxWidth(),shape=RoundedCornerShape(20.dp)){Column(Modifier.padding(16.dp),verticalArrangement=Arrangement.spacedBy(9.dp)){
            OutlinedTextField(symbol,onSymbol,label={Text("Market Symbol")},supportingText={Text(if(name=="Nobitex")"مثال: TONUSDT" else "مثال: TON_USDT")},singleLine=true,modifier=Modifier.fillMaxWidth())
            OutlinedTextField(amount,onAmount,label={Text("مقدار GRAM/TON")},singleLine=true,modifier=Modifier.fillMaxWidth())
            OutlinedTextField(price,onPrice,label={Text("قیمت / Reference Price")},singleLine=true,modifier=Modifier.fillMaxWidth())
            Row(horizontalArrangement=Arrangement.spacedBy(8.dp)){FilterChip(selected=side=="buy",onClick={onSide("buy")},label={Text("خرید")});FilterChip(selected=side=="sell",onClick={onSide("sell")},label={Text("فروش")})}
            Row(horizontalArrangement=Arrangement.spacedBy(8.dp)){FilterChip(selected=mode=="limit",onClick={onMode("limit")},label={Text("Limit")});FilterChip(selected=mode=="market",onClick={onMode("market")},label={Text("Market")})}
            Button(onClick=onSubmit,enabled=enabled,modifier=Modifier.fillMaxWidth()){Text("ارسال سفارش واقعی")}
        }}
        Card(Modifier.fillMaxWidth(),shape=RoundedCornerShape(20.dp)){Column(Modifier.padding(16.dp),verticalArrangement=Arrangement.spacedBy(9.dp)){Text("لغو سفارش",fontWeight=FontWeight.Bold);OutlinedTextField(cancelId,onCancelId,label={Text("Order ID / Client Order ID")},singleLine=true,modifier=Modifier.fillMaxWidth());OutlinedButton(onClick=onCancel,enabled=!loading&&state.credentials&&cancelId.isNotBlank(),modifier=Modifier.fillMaxWidth()){Text("لغو سفارش")}}}
    }
}

@Composable private fun BotTab(name:String,current:ExchangeUiState,bitpin:ExchangeUiState,nobitex:ExchangeUiState,kill:Boolean,loading:Boolean,onBot:(Boolean)->Unit,onLive:(Boolean)->Unit,onKill:(Boolean)->Unit,onRefresh:()->Unit){
    Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(16.dp),verticalArrangement=Arrangement.spacedBy(12.dp)){
        Text("ربات‌ها و ایمنی",style=MaterialTheme.typography.titleLarge,fontWeight=FontWeight.Bold)
        InfoBox("کنترل مستقل","Nobitex: ${if(nobitex.bot)"Bot ON" else "Bot OFF"} • Bitpin: ${if(bitpin.bot)"Bot ON" else "Bot OFF"}. تنظیم این بخش فقط روی $name اعمال می‌شود.")
        ControlCard("Auto Trading $name","Cron اجازه اجرای موتور این صرافی را داشته باشد.",current.bot,{onBot(it)},current.credentials&&!loading)
        ControlCard("Live Execution $name","اجازه ارسال سفارش واقعی از Backend به این صرافی.",current.live,{onLive(it)},current.credentials&&!loading)
        ControlCard("Kill Switch سراسری","با روشن‌شدن، ارسال سفارش در هر دو صرافی متوقف می‌شود.",kill,{onKill(it)},!loading)
        InfoBox("کلیدهای صرافی","Public/Private Key نوبیتکس و API Key/Secret بیت‌پین فقط در پنل وب Backend مدیریت می‌شوند و وارد APK نمی‌شوند.")
        OutlinedButton(onClick=onRefresh,enabled=!loading,modifier=Modifier.fillMaxWidth()){Text("بررسی دوباره")}
    }
}

@Composable private fun ControlCard(title:String,text:String,checked:Boolean,onChange:(Boolean)->Unit,enabled:Boolean){Card(Modifier.fillMaxWidth(),shape=RoundedCornerShape(20.dp)){Row(Modifier.padding(16.dp),horizontalArrangement=Arrangement.SpaceBetween,verticalAlignment=Alignment.CenterVertically){Column(Modifier.weight(1f)){Text(title,fontWeight=FontWeight.Bold);Text(text,color=TradeMuted,style=MaterialTheme.typography.bodySmall)};Switch(checked=checked,onCheckedChange=onChange,enabled=enabled)}}}
@Composable private fun MetricCard(title:String,value:String,modifier:Modifier=Modifier){Card(modifier,shape=RoundedCornerShape(18.dp)){Column(Modifier.padding(14.dp)){Text(title,color=TradeMuted,style=MaterialTheme.typography.labelMedium);Text(value,fontWeight=FontWeight.ExtraBold,style=MaterialTheme.typography.titleMedium)}}}
@Composable private fun ActionCard(title:String,description:String,button:String,onClick:()->Unit){Card(Modifier.fillMaxWidth(),shape=RoundedCornerShape(20.dp)){Column(Modifier.padding(16.dp)){Text(title,fontWeight=FontWeight.Bold);Text(description,color=TradeMuted);TextButton(onClick=onClick){Text(button)}}}}
@Composable private fun InfoBox(title:String,text:String){Card(Modifier.fillMaxWidth(),shape=RoundedCornerShape(16.dp),colors=CardDefaults.cardColors(containerColor=Color(0xFFEEF5FF))){Column(Modifier.padding(14.dp)){Text(title,fontWeight=FontWeight.Bold,color=Color(0xFF174A9C));Text(text,color=Color(0xFF365A8C),style=MaterialTheme.typography.bodySmall)}}}
@Composable private fun MessageCard(text:String,isError:Boolean){Card(Modifier.fillMaxWidth(),shape=RoundedCornerShape(14.dp),colors=CardDefaults.cardColors(containerColor=if(isError)Color(0xFFFFEEEE)else Color(0xFFECFFF6))){Text(text,Modifier.padding(13.dp),color=if(isError)Color(0xFF9E2424)else Color(0xFF176548))}}
@Composable private fun StatusPill(text:String,ok:Boolean){Surface(shape=RoundedCornerShape(100.dp),color=if(ok)Color(0xFFE7F9F2)else Color(0xFFFFEEEE)){Text(text,Modifier.padding(horizontal=10.dp,vertical=7.dp),color=if(ok)Color(0xFF087857)else Color(0xFF9E2424),style=MaterialTheme.typography.labelMedium,fontWeight=FontWeight.Bold)}}

private fun readableHttpError(response:TradeApi.Response):String=try{val r=JSONObject(response.body);r.optString("message").ifBlank{r.optString("error")}.ifBlank{"HTTP ${response.code}"}}catch(_:Exception){"HTTP ${response.code}: ${response.body.take(300)}"}
private fun prettyJson(raw:String):String=try{val t=raw.trim();if(t.startsWith("["))JSONArray(t).toString(2)else JSONObject(t).toString(2)}catch(_:Exception){raw}
