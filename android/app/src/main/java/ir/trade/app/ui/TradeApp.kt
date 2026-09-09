package ir.trade.app.ui

import androidx.compose.foundation.layout.*
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.unit.dp
import ir.trade.app.data.TradeApi
import ir.trade.app.data.TradePreferences
import kotlinx.coroutines.launch
import org.json.JSONObject

@Composable
fun TradeApp() {
    MaterialTheme {
        val context = LocalContext.current
        val prefs = remember { TradePreferences(context) }
        var configured by remember { mutableStateOf(prefs.isConfigured()) }
        Surface(Modifier.fillMaxSize()) {
            if (!configured) SetupScreen(prefs) { configured = true }
            else DashboardScreen(prefs) { prefs.clear(); configured = false }
        }
    }
}

@Composable
private fun SetupScreen(prefs: TradePreferences, onSaved: () -> Unit) {
    var server by remember { mutableStateOf(prefs.serverUrl()) }
    var token by remember { mutableStateOf(prefs.apiToken()) }
    var error by remember { mutableStateOf("") }
    Box(Modifier.fillMaxSize().padding(22.dp), contentAlignment = Alignment.Center) {
        Card(Modifier.fillMaxWidth()) {
            Column(Modifier.padding(22.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
                Text("Trade", style = MaterialTheme.typography.headlineMedium, fontWeight = FontWeight.Bold)
                Text("اتصال امن به پنل cPanel")
                OutlinedTextField(server, { server = it }, label = { Text("Server URL") }, singleLine = true, modifier = Modifier.fillMaxWidth())
                OutlinedTextField(token, { token = it }, label = { Text("App API Token") }, singleLine = true, visualTransformation = PasswordVisualTransformation(), modifier = Modifier.fillMaxWidth())
                if (error.isNotBlank()) Text(error, color = MaterialTheme.colorScheme.error)
                Button(onClick = {
                    try { prefs.save(server.trim(), token.trim()); onSaved() }
                    catch (_: IllegalArgumentException) { error = "آدرس سرور باید HTTPS باشد." }
                }, enabled = server.isNotBlank() && token.isNotBlank(), modifier = Modifier.fillMaxWidth()) { Text("ذخیره و اتصال") }
            }
        }
    }
}

@Composable
private fun DashboardScreen(prefs: TradePreferences, onDisconnect: () -> Unit) {
    val scope = rememberCoroutineScope()
    val api = remember { TradeApi(prefs.serverUrl(), prefs.apiToken()) }
    var loading by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf("") }
    var message by remember { mutableStateOf("") }
    var mode by remember { mutableStateOf("...") }
    var connected by remember { mutableStateOf(false) }
    var ordersLogged by remember { mutableIntStateOf(0) }
    var killSwitch by remember { mutableStateOf(false) }

    var marketId by remember { mutableStateOf("") }
    var amount by remember { mutableStateOf("") }
    var price by remember { mutableStateOf("") }
    var side by remember { mutableStateOf("buy") }
    var orderMode by remember { mutableStateOf("limit") }

    fun refresh() {
        scope.launch {
            loading = true; error = ""; message = ""
            try {
                val response = api.status()
                if (!response.ok) throw IllegalStateException("HTTP ${response.code}: ${response.body}")
                val data = JSONObject(response.body).getJSONObject("data")
                mode = data.optString("mode", "live_disabled")
                connected = data.optBoolean("credentials_configured")
                ordersLogged = data.optInt("orders_logged")
                killSwitch = data.optBoolean("kill_switch")
            } catch (e: Exception) { error = e.message ?: "خطای ارتباط" }
            finally { loading = false }
        }
    }

    fun submitOrder() {
        val market = marketId.toIntOrNull()
        val amountValue = amount.toDoubleOrNull()
        val priceValue = price.toDoubleOrNull()
        if (market == null || market <= 0 || amountValue == null || amountValue <= 0 || priceValue == null || priceValue <= 0) {
            error = "Market ID، مقدار و قیمت را درست وارد کن."
            return
        }
        scope.launch {
            loading = true; error = ""; message = ""
            try {
                val payload = JSONObject()
                    .put("market", market)
                    .put("amount1", amountValue)
                    .put("price", priceValue)
                    .put("mode", orderMode)
                    .put("type", side)
                    .toString()
                val response = api.createOrder(payload)
                if (!response.ok) throw IllegalStateException("HTTP ${response.code}: ${response.body}")
                val root = JSONObject(response.body)
                val exchange = root.optJSONObject("data")?.optJSONObject("exchange")
                val id = exchange?.optString("id", exchange.optString("order_id", "")) ?: ""
                message = if (id.isNotBlank()) "سفارش واقعی ثبت شد. ID: $id" else "سفارش واقعی ثبت شد."
                refresh()
            } catch (e: Exception) { error = e.message ?: "ثبت سفارش ناموفق بود" }
            finally { loading = false }
        }
    }

    fun updateKillSwitch(enabled: Boolean) {
        scope.launch {
            loading = true; error = ""; message = ""
            try {
                val response = api.setKillSwitch(enabled)
                if (!response.ok) throw IllegalStateException("HTTP ${response.code}: ${response.body}")
                killSwitch = enabled
                message = if (enabled) "Kill Switch فعال شد؛ سفارش جدید متوقف است." else "Kill Switch غیرفعال شد."
            } catch (e: Exception) { error = e.message ?: "خطا در تغییر Kill Switch" }
            finally { loading = false }
        }
    }

    LaunchedEffect(Unit) { refresh() }

    Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(18.dp), verticalArrangement = Arrangement.spacedBy(14.dp)) {
        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween, verticalAlignment = Alignment.CenterVertically) {
            Column {
                Text("Trade", style = MaterialTheme.typography.headlineMedium, fontWeight = FontWeight.Bold)
                Text(prefs.serverUrl())
                Text("حالت: $mode")
            }
            TextButton(onClick = onDisconnect) { Text("قطع اتصال") }
        }

        if (error.isNotBlank()) Card { Text(error, Modifier.padding(14.dp), color = MaterialTheme.colorScheme.error) }
        if (message.isNotBlank()) Card { Text(message, Modifier.padding(14.dp)) }

        Card(Modifier.fillMaxWidth()) {
            Column(Modifier.padding(18.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                Text("وضعیت سرور", fontWeight = FontWeight.Bold)
                Text(if (connected) "Bitpin API: متصل" else "Bitpin API: تنظیم نشده")
                Text("سفارش‌های ثبت‌شده: $ordersLogged")
                Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                    Switch(checked = killSwitch, onCheckedChange = { updateKillSwitch(it) }, enabled = !loading)
                    Text(if (killSwitch) "Kill Switch روشن" else "Kill Switch خاموش")
                }
            }
        }

        Card(Modifier.fillMaxWidth()) {
            Column(Modifier.padding(18.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
                Text("ثبت سفارش Live", fontWeight = FontWeight.Bold)
                OutlinedTextField(marketId, { marketId = it }, label = { Text("Bitpin Market ID") }, singleLine = true, modifier = Modifier.fillMaxWidth())
                OutlinedTextField(amount, { amount = it }, label = { Text("Amount 1") }, singleLine = true, modifier = Modifier.fillMaxWidth())
                OutlinedTextField(price, { price = it }, label = { Text("Price") }, singleLine = true, modifier = Modifier.fillMaxWidth())

                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    FilterChip(selected = side == "buy", onClick = { side = "buy" }, label = { Text("Buy") })
                    FilterChip(selected = side == "sell", onClick = { side = "sell" }, label = { Text("Sell") })
                }
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    FilterChip(selected = orderMode == "limit", onClick = { orderMode = "limit" }, label = { Text("Limit") })
                    FilterChip(selected = orderMode == "market", onClick = { orderMode = "market" }, label = { Text("Market") })
                }

                Button(
                    onClick = { submitOrder() },
                    enabled = !loading && connected && mode == "live" && !killSwitch,
                    modifier = Modifier.fillMaxWidth()
                ) { Text(if (loading) "در حال ارسال..." else "ارسال سفارش واقعی") }
            }
        }

        Button(onClick = { refresh() }, modifier = Modifier.fillMaxWidth(), enabled = !loading) {
            Text(if (loading) "در حال دریافت..." else "بروزرسانی وضعیت")
        }
    }
}
