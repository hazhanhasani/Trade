package ir.trade.app.ui

import android.net.Uri
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import ir.trade.app.data.TradeApi
import ir.trade.app.data.TradePreferences
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import org.json.JSONObject

private const val TRUSTED_SERVER = "https://rado-taxi.sbs"

@Composable
fun TradeEntry(pairingUri: String?, onPairingHandled: () -> Unit) {
    val context = LocalContext.current
    val prefs = remember { TradePreferences(context) }
    var pairing by remember(pairingUri) { mutableStateOf(!pairingUri.isNullOrBlank()) }
    var error by remember(pairingUri) { mutableStateOf("") }
    var paired by remember(pairingUri) { mutableStateOf(false) }

    LaunchedEffect(pairingUri) {
        if (pairingUri.isNullOrBlank()) return@LaunchedEffect
        try {
            val uri = Uri.parse(pairingUri)
            if (uri.scheme != "trade" || uri.host != "pair") {
                throw IllegalArgumentException("لینک اتصال معتبر نیست.")
            }
            val code = uri.getQueryParameter("code")?.trim().orEmpty()
            if (code.isBlank()) throw IllegalArgumentException("کد اتصال در لینک وجود ندارد.")

            val response = TradeApi(TRUSTED_SERVER, "").pair(code)
            if (!response.ok) {
                val message = try {
                    val root = JSONObject(response.body)
                    root.optString("message").ifBlank { root.optString("error") }
                } catch (_: Exception) { "" }
                throw IllegalStateException(message.ifBlank { "اتصال خودکار ناموفق بود (HTTP ${response.code})." })
            }

            val data = JSONObject(response.body).getJSONObject("data")
            val token = data.getString("token")
            val server = data.optString("server_url", TRUSTED_SERVER).trimEnd('/')
            if (server != TRUSTED_SERVER) throw SecurityException("آدرس سرور تأیید نشد.")

            withContext(Dispatchers.IO) { prefs.save(server, token) }
            paired = true
            pairing = false
            onPairingHandled()
        } catch (e: Exception) {
            error = e.message ?: "اتصال خودکار ناموفق بود."
            pairing = false
        }
    }

    when {
        paired -> TradeApp()
        pairing -> PairingStatusCard("در حال اتصال امن به پنل…", null)
        error.isNotBlank() -> PairingStatusCard(
            title = "اتصال خودکار انجام نشد",
            error = error,
            action = {
                error = ""
                onPairingHandled()
            },
        )
        else -> TradeApp()
    }
}

@Composable
private fun PairingStatusCard(title: String, error: String?, action: (() -> Unit)? = null) {
    Surface(modifier = Modifier.fillMaxSize(), color = MaterialTheme.colorScheme.background) {
        Box(
            modifier = Modifier
                .fillMaxSize()
                .statusBarsPadding()
                .navigationBarsPadding()
                .padding(22.dp),
            contentAlignment = Alignment.Center,
        ) {
            Card(
                modifier = Modifier.fillMaxWidth(),
                shape = RoundedCornerShape(24.dp),
            ) {
                Column(
                    modifier = Modifier.padding(22.dp),
                    verticalArrangement = Arrangement.spacedBy(12.dp),
                ) {
                    Text("Trade", style = MaterialTheme.typography.headlineMedium, fontWeight = FontWeight.ExtraBold)
                    Text(title, style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold)
                    if (error == null) {
                        LinearProgressIndicator(modifier = Modifier.fillMaxWidth())
                        Text("کد یک‌بارمصرف از rado-taxi.sbs بررسی می‌شود و توکن اپ به‌صورت امن روی گوشی ذخیره خواهد شد.")
                    } else {
                        Text(error, color = MaterialTheme.colorScheme.error)
                        if (action != null) {
                            OutlinedButton(onClick = action, modifier = Modifier.fillMaxWidth()) {
                                Text("بازگشت به صفحه اتصال")
                            }
                        }
                    }
                }
            }
        }
    }
}
