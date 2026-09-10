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
    val installedVersion: String = "-",
    val backendVersion: String = "-",
    val releaseVersion: String = "-",
    val versionsSynchronized: Boolean = false,
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
    var state by remember { mutableStateOf(UpdateUiState(checking = true, message = "در حال بررسی نسخه مشترک اپ و Backend…")) }

    LaunchedEffect(trigger) {
        state = state.copy(checking = true, message = "در حال بررسی نسخه مشترک اپ و Backend…")
        try {
            val result = AppUpdateManager.checkRelease(context)
            val info = result.update
            val release = result.status
            val backend = release.backendVersion ?: "نامشخص"
            val stable = release.releaseVersion ?: "نامشخص"

            if (info != null) {
                state = UpdateUiState(
                    checking = true,
                    message = "نسخه هماهنگ ${info.versionName} پیدا شد؛ در حال دانلود امن…",
                    lastResult = "اپ ${release.installedVersion} → ${info.versionName} • Backend $backend",
                    installedVersion = release.installedVersion,
                    backendVersion = backend,
                    releaseVersion = stable,
                    versionsSynchronized = false,
                )
                val apk = AppUpdateManager.download(context, info)
                state = state.copy(
                    checking = false,
                    message = "نسخه ${info.versionName} دانلود شد؛ نصب را تأیید کن.",
                    lastResult = "آپدیت ${info.versionName} دانلود شد • Backend $backend",
                )
                onInstallReady(apk)
            } else if (release.synchronized) {
                val version = release.releaseVersion ?: release.installedVersion
                state = UpdateUiState(
                    checking = false,
                    message = "اپ و Backend هماهنگ‌اند • v$version",
                    lastResult = "هماهنگ • اپ و Backend v$version",
                    installedVersion = release.installedVersion,
                    backendVersion = backend,
                    releaseVersion = stable,
                    versionsSynchronized = true,
                )
                delay(2200)
                state = state.copy(message = "")
            } else {
                state = UpdateUiState(
                    checking = false,
                    message = "نسخه‌ها در حال همگام‌سازی‌اند؛ اپ ${release.installedVersion} • Backend $backend • Release $stable",
                    lastResult = "نیاز به همگام‌سازی • اپ ${release.installedVersion} • Backend $backend • Release $stable",
                    installedVersion = release.installedVersion,
                    backendVersion = backend,
                    releaseVersion = stable,
                    versionsSynchronized = false,
                )
            }
        } catch (e: Exception) {
            state = state.copy(
                checking = false,
                message = "بررسی آپدیت ناموفق بود: ${e.message ?: "خطای نامشخص"}",
                lastResult = "خطا در بررسی نسخه مشترک",
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
