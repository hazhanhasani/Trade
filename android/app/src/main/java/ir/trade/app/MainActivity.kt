package ir.trade.app

import android.content.Intent
import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import androidx.compose.runtime.mutableStateOf
import ir.trade.app.ui.TradeEntry

class MainActivity : ComponentActivity() {
    private val pairingUri = mutableStateOf<String?>(null)

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        enableEdgeToEdge()
        pairingUri.value = intent?.data?.toString()
        setContent {
            TradeEntry(
                pairingUri = pairingUri.value,
                onPairingHandled = { pairingUri.value = null },
            )
        }
    }

    override fun onNewIntent(intent: Intent) {
        super.onNewIntent(intent)
        setIntent(intent)
        pairingUri.value = intent.data?.toString()
    }
}
