package ir.trade.app.data

import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import org.json.JSONObject
import java.net.HttpURLConnection
import java.net.URL
import java.net.URLEncoder

class TradeApi(private val baseUrl: String, private val apiToken: String) {
    data class Response(val code: Int, val body: String) { val ok: Boolean get() = code in 200..299 }

    @Volatile private var contractLoaded = false
    @Volatile private var backendApiContract: Int? = null
    @Volatile private var backendCapabilities: Set<String> = emptySet()
    @Volatile private var latestStatusDataJson: String? = null

    suspend fun health(): Response = request("GET", "/api/health", authenticated = false)
    suspend fun updateInfo(): Response = request("GET", "/api/update", authenticated = false)
    suspend fun pair(code: String): Response = request("POST", "/api/pair", JSONObject().put("code", code).toString(), authenticated = false)

    suspend fun status(): Response { val response=request("GET","/api/status");if(response.ok){captureContract(response);cacheStatusData(response)};return response }
    suspend fun commandCenter(): Response { requireCapability("analytics.command_center_v1");val response=request("GET","/api/command-center");return if(response.ok)mergeLivePanelStatus(response)else response }
    suspend fun settingsHistory(limit:Int=30):Response{requireCapability("trading.settings_presets_history_v1");return request("GET","/api/settings/history?limit=${limit.coerceIn(1,80)}")}
    suspend fun previewSettings(json:String):Response{requireCapability("trading.settings_risk_preview_v1");return request("POST","/api/settings/preview",json)}
    suspend fun updateBotSettings(json:String):Response{requireCapability("trading.auto_trading");return request("POST","/api/bot/settings",json)}
    suspend fun applyPreset(name:String):Response{requireCapability("trading.settings_presets_history_v1");return request("POST","/api/settings/preset",JSONObject().put("name",name).toString())}
    suspend fun rollbackSettings(historyId:Long):Response{requireCapability("trading.settings_presets_history_v1");return request("POST","/api/settings/rollback",JSONObject().put("history_id",historyId).toString())}
    suspend fun setEmergency(mode:String):Response{requireCapability("trading.emergency_modes_v1");require(mode in setOf("normal","pause_buys","graceful_close","full_stop")){"Unsupported emergency mode"};return request("POST","/api/emergency",JSONObject().put("mode",mode).toString())}
    suspend fun notificationRules():Response{requireCapability("notifications.rules_v1");return request("GET","/api/notification-rules")}
    suspend fun updateNotificationRules(json:String):Response{requireCapability("notifications.rules_v1");return request("POST","/api/notification-rules",json)}
    suspend fun setShadowMode(enabled:Boolean):Response{requireCapability("trading.shadow_evaluation_v1");return request("POST","/api/shadow-mode",JSONObject().put("enabled",enabled).toString())}
    suspend fun strategyLab(json:String):Response{requireCapability("trading.strategy_lab_v1");return request("POST","/api/strategy-lab",json)}
    suspend fun tradeReplay(positionId:Long):Response{requireCapability("trading.trade_replay_v1");return request("GET","/api/trade-replay/$positionId")}
    suspend fun timeline(limit:Int=80):Response=request("GET","/api/timeline?limit=${limit.coerceIn(10,250)}")
    suspend fun exchanges():Response=request("GET","/api/exchanges")
    suspend fun botStatus():Response=request("GET","/api/bot")
    suspend fun rotationStatus(limit:Int=20):Response{requireCapability("trading.portfolio_rotation_monitor_v1");return request("GET","/api/bot/rotation?limit=${limit.coerceIn(1,50)}")}
    suspend fun strategyLearning(limit:Int=240):Response{requireCapability("trading.strategy_learning_v2");return request("GET","/api/bot/strategy-learning?limit=${limit.coerceIn(30,500)}")}
    suspend fun edgeCalibration():Response{requireCapability("trading.adaptive_edge_calibration_v1");return request("GET","/api/bot/edge-calibration")}
    suspend fun globalPortfolio():Response{requireCapability("trading.global_portfolio_exposure_v1");return request("GET","/api/portfolio/global")}
    suspend fun analytics(days:Int=30):Response{requireCapability("analytics.performance_v1");return request("GET","/api/analytics?days=${days.coerceIn(7,180)}")}
    suspend fun notifications(limit:Int=50,unreadOnly:Boolean=false):Response{requireCapability("notifications.center_v1");return request("GET","/api/notifications?limit=${limit.coerceIn(1,100)}&unread=${if(unreadOnly)1 else 0}")}
    suspend fun markNotificationRead(id:Long?=null,all:Boolean=false):Response{requireCapability("notifications.center_v1");val body=JSONObject();if(all)body.put("all",true)else body.put("id",requireNotNull(id){"Notification id is required"});return request("POST","/api/notifications/read",body.toString())}

    suspend fun markets(exchange:String="nobitex"):Response=request("GET","/api/markets?exchange=${exchangeArg(exchange)}")
    suspend fun wallets(exchange:String="nobitex"):Response=request("GET","/api/wallets?exchange=${exchangeArg(exchange)}")
    suspend fun orders(exchange:String="nobitex"):Response=request("GET","/api/orders?exchange=${exchangeArg(exchange)}")
    suspend fun createOrder(json:String):Response{requireCapability("trading.manual_orders");return request("POST","/api/orders",json)}
    suspend fun cancelOrder(orderId:String,exchange:String="nobitex"):Response{requireCapability("trading.manual_orders");return request("DELETE","/api/orders/${URLEncoder.encode(orderId,Charsets.UTF_8.name())}?exchange=${exchangeArg(exchange)}")}
    suspend fun setKillSwitch(enabled:Boolean):Response{requireCapability("trading.kill_switch");return request("POST","/api/kill-switch","{\"enabled\":$enabled}")}
    suspend fun setExchangeBot(exchange:String,enabled:Boolean):Response{requireCapability("trading.auto_trading");return request("POST","/api/exchanges/${exchangeArg(exchange)}/bot","{\"enabled\":$enabled}")}
    suspend fun setExchangeLive(exchange:String,enabled:Boolean):Response{requireCapability("trading.live_execution_controls");return request("POST","/api/exchanges/${exchangeArg(exchange)}/live","{\"enabled\":$enabled}")}
    suspend fun runExchange(exchange:String):Response{requireCapability("trading.auto_trading");return request("POST","/api/exchanges/${exchangeArg(exchange)}/run","{}")}
    suspend fun marketData(asset:String="USDT",quote:String="IRT",force:Boolean=false):Response=request("GET","/api/market-data?asset=${URLEncoder.encode(asset.uppercase(),Charsets.UTF_8.name())}&quote=${URLEncoder.encode(quote.uppercase(),Charsets.UTF_8.name())}&force=${if(force)1 else 0}")

    fun isContractCompatible():Boolean=contractLoaded&&backendApiContract==ReleaseContract.API_CONTRACT&&ReleaseContract.missingCapabilities(backendCapabilities).isEmpty()
    fun apiContract():Int?=backendApiContract
    fun capabilities(): Set<String> = backendCapabilities
    fun missingCapabilities(): Set<String> = ReleaseContract.missingCapabilities(backendCapabilities)

    private fun cacheStatusData(response:Response){latestStatusDataJson=try{val root=JSONObject(response.body);(root.optJSONObject("data")?:root).toString()}catch(_:Exception){null}}
    private fun mergeLivePanelStatus(response:Response):Response{
        val cached=latestStatusDataJson?:return response
        return try{
            val root=JSONObject(response.body);val data=root.optJSONObject("data")?:root;val status=JSONObject(cached);val nobitex=status.optJSONObject("exchanges")?.optJSONObject("nobitex");val performance=nobitex?.optJSONObject("performance");val capacity=nobitex?.optJSONObject("portfolio_capacity");val global=status.optJSONObject("global_portfolio");val cron=status.optJSONObject("cron_health");val headline=data.optJSONObject("headline")?:JSONObject().also{data.put("headline",it)};val strip=data.optJSONObject("status_strip")?:JSONObject().also{data.put("status_strip",it)}
            fun number(obj:JSONObject?,key:String):Double?{if(obj==null||!obj.has(key)||obj.isNull(key))return null;val value=obj.optDouble(key,Double.NaN);return value.takeIf{it.isFinite()}}
            (number(global,"wallet_total_toman")?:number(global,"portfolio_value_irt"))?.let{headline.put("portfolio_value_irt",it)};global?.let{data.put("global_portfolio",it)}
            number(performance,"today_realized_pnl")?.let{today->headline.put("today_net_pnl_irt",today);headline.put("today_realized_bot_pnl_irt",today);val reports=data.optJSONObject("reports")?:JSONObject().also{data.put("reports",it)};reports.put("daily",JSONObject().put("net_pnl",today).put("unit","TOMAN").put("scope","live_panel_realized_bot_pnl"))}
            number(performance,"total_realized_pnl")?.let{total->headline.put("total_realized_pnl_irt",total);headline.put("total_realized_bot_pnl_irt",total);val reports=data.optJSONObject("reports")?:JSONObject().also{data.put("reports",it)};reports.put("total",JSONObject().put("net_pnl",total).put("unit","TOMAN").put("scope","live_panel_realized_bot_pnl"))}
            number(performance,"win_rate_percent")?.let{winRate->headline.put("win_rate_percent",winRate);val reports=data.optJSONObject("reports")?:JSONObject().also{data.put("reports",it)};reports.put("win_rate_percent",winRate)}
            performance?.let{headline.put("closed_positions",it.optInt("closed_positions",0));headline.put("winning_positions",it.optInt("winning_positions",0))}
            capacity?.let{headline.put("active_positions",it.optInt("active_positions",headline.optInt("active_positions",0)));headline.put("configured_max_positions",it.optInt("max_positions",headline.optInt("configured_max_positions",0)));headline.put("pending_orders",it.optInt("pending_orders",0));headline.put("max_pending_orders",it.optInt("max_pending_orders",0));strip.put("positions",it.optInt("active_positions",0))}
            nobitex?.let{strip.put("bot",if(it.optBoolean("bot_enabled",false))"live" else "off");strip.put("api",if(it.optBoolean("credentials_configured",false))"ready" else "missing");strip.put("live_execution",if(it.optBoolean("live_execution_enabled",false))"on" else "off")};cron?.let{strip.put("cron_healthy",it.optBoolean("healthy",false))}
            val livePanel=JSONObject().put("source","api_status_bot_controller").put("synced",true);performance?.let{livePanel.put("performance",it)};capacity?.let{livePanel.put("portfolio_capacity",it)};nobitex?.optJSONObject("latest_signal")?.let{livePanel.put("latest_signal",it)};nobitex?.optJSONObject("latest_order")?.let{livePanel.put("latest_order",it)};status.optJSONObject("time_iran")?.let{livePanel.put("time_iran",it)};data.put("live_panel",livePanel);data.put("live_source","api_status_panel_truth");Response(response.code,root.toString())
        }catch(_:Exception){response}
    }
    private fun captureContract(response:Response){try{val root=JSONObject(response.body);val data=root.optJSONObject("data")?:root;backendApiContract=data.optInt("api_contract",-1).takeIf{it>=0};val array=data.optJSONArray("capabilities");val capabilities=linkedSetOf<String>();if(array!=null)for(i in 0 until array.length()){val value=array.optString(i).trim();if(value.isNotBlank())capabilities+=value};backendCapabilities=capabilities;contractLoaded=true}catch(_:Exception){backendApiContract=null;backendCapabilities=emptySet();contractLoaded=true}}
    private fun requireCapability(capability:String){check(contractLoaded){"وضعیت سازگاری Backend هنوز بررسی نشده است؛ ابتدا وضعیت برنامه را بروزرسانی کن."};check(backendApiContract==ReleaseContract.API_CONTRACT){"نسخه API Backend با این نسخه اپ سازگار نیست. Backend=${backendApiContract?:"نامشخص"} / App=${ReleaseContract.API_CONTRACT}"};check(backendCapabilities.contains(capability)){"Backend قابلیت موردنیاز «$capability» را اعلام نکرده است؛ عملیات برای جلوگیری از اجرای ناسازگار متوقف شد."}}
    private fun exchangeArg(exchange:String):String{val normalized=exchange.lowercase().trim();require(normalized=="nobitex"){"Unsupported execution exchange"};return normalized}
    private suspend fun request(method:String,path:String,body:String?=null,authenticated:Boolean=true):Response=withContext(Dispatchers.IO){require(baseUrl.startsWith("https://")){"Only HTTPS server URLs are allowed"};val connection=(URL(baseUrl.trimEnd('/')+path).openConnection() as HttpURLConnection).apply{requestMethod=method;connectTimeout=8_000;readTimeout=20_000;instanceFollowRedirects=true;setRequestProperty("Accept","application/json");setRequestProperty("Cache-Control","no-cache, no-store, max-age=0");setRequestProperty("Pragma","no-cache");setRequestProperty("X-Trade-App-Api-Contract",ReleaseContract.API_CONTRACT.toString());if(authenticated)setRequestProperty("Authorization","Bearer $apiToken");if(body!=null){doOutput=true;setRequestProperty("Content-Type","application/json")}};try{if(body!=null)connection.outputStream.use{it.write(body.toByteArray(Charsets.UTF_8))};val code=connection.responseCode;val stream=if(code in 200..299)connection.inputStream else connection.errorStream;Response(code,stream?.bufferedReader()?.use{it.readText()}.orEmpty())}finally{connection.disconnect()}}
}
