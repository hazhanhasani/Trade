package ir.trade.app

import android.content.Intent
import android.net.Uri
import android.os.Build
import android.os.Bundle
import android.provider.Settings
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import androidx.compose.runtime.mutableStateOf
import androidx.core.content.FileProvider
import ir.trade.app.ui.TradeEntry
import ir.trade.app.ui.UpdateGate
import java.io.File

class MainActivity : ComponentActivity() {
    private val pairingUri = mutableStateOf<String?>(null)
    private var pendingUpdate: File? = null
    private var installerOpenedFor: String? = null

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        enableEdgeToEdge()
        pairingUri.value = intent?.data?.toString()
        setContent {
            UpdateGate(onInstallReady = { requestUpdateInstall(it) }) {
                TradeEntry(
                    pairingUri = pairingUri.value,
                    onPairingHandled = { pairingUri.value = null },
                )
            }
        }
    }

    override fun onNewIntent(intent: Intent) {
        super.onNewIntent(intent)
        setIntent(intent)
        pairingUri.value = intent.data?.toString()
    }

    override fun onResume() {
        super.onResume()
        val file = pendingUpdate ?: return
        if (canInstallPackages()) {
            openPackageInstaller(file)
        }
    }

    private fun requestUpdateInstall(file: File) {
        pendingUpdate = file
        if (!canInstallPackages()) {
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
                startActivity(
                    Intent(
                        Settings.ACTION_MANAGE_UNKNOWN_APP_SOURCES,
                        Uri.parse("package:$packageName"),
                    ),
                )
            }
            return
        }
        openPackageInstaller(file)
    }

    private fun canInstallPackages(): Boolean {
        return Build.VERSION.SDK_INT < Build.VERSION_CODES.O || packageManager.canRequestPackageInstalls()
    }

    private fun openPackageInstaller(file: File) {
        if (!file.isFile) return
        if (installerOpenedFor == file.absolutePath) return
        installerOpenedFor = file.absolutePath
        pendingUpdate = null

        val uri = FileProvider.getUriForFile(this, "$packageName.fileprovider", file)
        val intent = Intent(Intent.ACTION_VIEW).apply {
            setDataAndType(uri, "application/vnd.android.package-archive")
            addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION)
            addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
        }
        startActivity(intent)
    }
}
