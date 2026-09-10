package ir.trade.app.ui

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.Card
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.compositionLocalOf
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import ir.trade.app.update.AppUpdateManager
import kotlinx.coroutines.delay
import java.io.File

data class UpdateUiState(
    val checking: Boolean = false,
    val message: String = "",
    val lastResult: String = "هنوز بررسی نشده",
)

val LocalUpdateUiState = compositionLocalOf { UpdateUiState() }
val LocalRequestUpdateCheck = compositionLocalOf<() -> Unit> { {} }

@Composable
fun UpdateGate(
    onInstallReady: (File) -> Unit,
    content: @Composable () -> Unit,
) {
    val context = LocalContext.current
    var trigger by remember { mutableIntStateOf(0) }
    var state by remember { mutableStateOf(UpdateUiState(checking = true, message = "در حال بررسی نسخه جدید…")) }

    LaunchedEffect(trigger) {
        state = state.copy(checking = true, message = "در حال بررسی نسخه جدید…")
        try {
            val info = AppUpdateManager.check(context)
            if (info != null) {
                state = UpdateUiState(
                    checking = true,
                    message = "نسخه ${info.versionName} پیدا شد؛ در حال دانلود امن…",
                    lastResult = "نسخه ${info.versionName} آماده دریافت است",
                )
                val apk = AppUpdateManager.download(context, info)
                state = UpdateUiState(
                    checking = false,
                    message = "نسخه ${info.versionName} دانلود شد؛ نصب را تأیید کن.",
                    lastResult = "آپدیت ${info.versionName} دانلود شد",
                )
                onInstallReady(apk)
            } else {
                state = UpdateUiState(
                    checking = false,
                    message = "برنامه به‌روز است.",
                    lastResult = "آخرین نسخه نصب است",
                )
                delay(2200)
                state = state.copy(message = "")
            }
        } catch (e: Exception) {
            state = UpdateUiState(
                checking = false,
                message = "بررسی آپدیت ناموفق بود: ${e.message ?: "خطای نامشخص"}",
                lastResult = "خطا در بررسی آپدیت",
            )
        }
    }

    CompositionLocalProvider(
        LocalUpdateUiState provides state,
        LocalRequestUpdateCheck provides { trigger++ },
    ) {
        Box(modifier = Modifier.fillMaxSize()) {
            content()

            if (state.message.isNotBlank()) {
                Card(
                    modifier = Modifier
                        .align(Alignment.TopCenter)
                        .padding(top = 48.dp, start = 14.dp, end = 14.dp),
                    shape = RoundedCornerShape(18.dp),
                ) {
                    Row(
                        modifier = Modifier.padding(horizontal = 14.dp, vertical = 11.dp),
                        verticalAlignment = Alignment.CenterVertically,
                        horizontalArrangement = Arrangement.spacedBy(10.dp),
                    ) {
                        if (state.checking) CircularProgressIndicator(strokeWidth = 2.dp)
                        Text(
                            text = state.message,
                            style = MaterialTheme.typography.bodySmall,
                            fontWeight = FontWeight.SemiBold,
                        )
                    }
                }
            }
        }
    }
}
