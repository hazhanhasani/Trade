package ir.trade.app.ui

import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.Card
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import ir.trade.app.update.AppUpdateManager
import java.io.File

@Composable
fun UpdateGate(
    onInstallReady: (File) -> Unit,
    content: @Composable () -> Unit,
) {
    val context = LocalContext.current
    var status by remember { mutableStateOf("") }
    var checking by remember { mutableStateOf(true) }

    LaunchedEffect(Unit) {
        try {
            status = "بررسی نسخه جدید…"
            val info = AppUpdateManager.check(context)
            if (info != null) {
                status = "نسخه ${info.versionName.ifBlank { info.versionCode.toString() }} در حال دانلود است…"
                val apk = AppUpdateManager.download(context, info)
                status = "آپدیت آماده نصب است"
                onInstallReady(apk)
            } else {
                status = ""
            }
        } catch (e: Exception) {
            status = "آپدیت خودکار فعلاً در دسترس نیست: ${e.message ?: "خطای نامشخص"}"
        } finally {
            checking = false
        }
    }

    Box(modifier = Modifier.fillMaxSize()) {
        content()

        if (status.isNotBlank()) {
            Card(
                modifier = Modifier
                    .align(Alignment.TopCenter)
                    .padding(top = 52.dp, start = 16.dp, end = 16.dp),
                shape = RoundedCornerShape(16.dp),
            ) {
                androidx.compose.foundation.layout.Row(
                    modifier = Modifier.padding(horizontal = 14.dp, vertical = 10.dp),
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    if (checking) {
                        CircularProgressIndicator(
                            modifier = Modifier.padding(end = 10.dp),
                            strokeWidth = 2.dp,
                        )
                    }
                    Text(
                        text = status,
                        style = MaterialTheme.typography.bodySmall,
                        fontWeight = FontWeight.Medium,
                    )
                }
            }
        }
    }
}
