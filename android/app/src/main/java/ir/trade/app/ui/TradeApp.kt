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
                Text("اتصال به پنل مانیتورینگ cPanel")
                OutlinedTextField(server, { server = it }, label = { Text("https://trade.example.com") }, singleLine = true, modifier = Modifier.fillMaxWidth())
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
    var mode by remember { mutableStateOf("...") }
    var connected by remember { mutableStateOf(false) }
    var ordersLogged by remember { mutableStateOf(0) }

    fun refresh() {
        scope.launch {
            loading = true; error = ""
            try {
                val response = api.status()
                if (!response.ok) throw IllegalStateException("HTTP ${response.code}")
                val data = JSONObject(response.body).getJSONObject("data")
                mode = data.optString("mode", "read_only")
                connected = data.optBoolean("credentials_configured")
                ordersLogged = data.optInt("orders_logged")
            } catch (e: Exception) { error = e.message ?: "خطای ارتباط" }
            finally { loading = false }
        }
    }

    LaunchedEffect(Unit) { refresh() }

    Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(18.dp), verticalArrangement = Arrangement.spacedBy(14.dp)) {
        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween, verticalAlignment = Alignment.CenterVertically) {
            Column { Text("Trade", style = MaterialTheme.typography.headlineMedium, fontWeight = FontWeight.Bold); Text("حالت: $mode") }
            TextButton(onClick = onDisconnect) { Text("قطع اتصال") }
        }
        if (error.isNotBlank()) Card { Text(error, Modifier.padding(14.dp), color = MaterialTheme.colorScheme.error) }
        Card(Modifier.fillMaxWidth()) { Column(Modifier.padding(18.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
            Text("وضعیت سرور", fontWeight = FontWeight.Bold)
            Text(if (connected) "Bitpin API: متصل" else "Bitpin API: تنظیم نشده")
            Text("سفارش‌های ثبت‌شده در پایگاه داده: $ordersLogged")
            Text("این نسخه فقط خواندنی است و سفارش جدید ارسال نمی‌کند.")
        } }
        Button(onClick = { refresh() }, modifier = Modifier.fillMaxWidth(), enabled = !loading) { Text(if (loading) "در حال دریافت..." else "بروزرسانی وضعیت") }
    }
}
