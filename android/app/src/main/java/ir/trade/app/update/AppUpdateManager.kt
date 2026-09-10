package ir.trade.app.update

import android.content.Context
import android.os.Build
import android.os.Environment
import ir.trade.app.data.TradeApi
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import org.json.JSONObject
import java.io.File
import java.net.HttpURLConnection
import java.net.URL
import java.security.MessageDigest

object AppUpdateManager {
    private const val SERVER = "https://rado-taxi.sbs"
    private const val DIRECT_MANIFEST = "https://github.com/hazhanhasani/Trade/releases/download/trade-latest/latest.json"

    data class UpdateInfo(
        val available: Boolean,
        val versionCode: Long,
        val versionName: String,
        val url: String,
        val sha256: String,
    )

    suspend fun check(context: Context): UpdateInfo? = withContext(Dispatchers.IO) {
        val currentCode = currentVersionCode(context)
        val payloads = mutableListOf<String>()

        // Primary path: installed backend. This lets self-hosted deployments override
        // release metadata if needed.
        try {
            val response = TradeApi(SERVER, "").updateInfo()
            if (response.ok && response.body.isNotBlank()) payloads += response.body
        } catch (_: Exception) {
            // Direct release fallback below keeps Android updates working even while
            // the backend itself is stale, temporarily unavailable or mid-update.
        }

        try {
            payloads += downloadText("$DIRECT_MANIFEST?ts=${System.currentTimeMillis()}")
        } catch (_: Exception) {
            // If backend metadata was available we can still continue with it.
        }

        for (raw in payloads) {
            val android = parseAndroid(raw) ?: continue
            val remoteCode = android.optLong("version_code", 0L)
            val available = android.optBoolean("available", false)
            val url = android.optString("url").trim()
            val sha256 = android.optString("sha256").trim().lowercase()
            if (
                available &&
                remoteCode > currentCode &&
                url.startsWith("https://") &&
                sha256.matches(Regex("^[a-f0-9]{64}$"))
            ) {
                return@withContext UpdateInfo(
                    available = true,
                    versionCode = remoteCode,
                    versionName = android.optString("version_name").ifBlank { remoteCode.toString() },
                    url = url,
                    sha256 = sha256,
                )
            }
        }
        null
    }

    suspend fun download(context: Context, info: UpdateInfo): File = withContext(Dispatchers.IO) {
        val targetDir = File(context.getExternalFilesDir(Environment.DIRECTORY_DOWNLOADS), "updates")
        if (!targetDir.exists() && !targetDir.mkdirs()) {
            throw IllegalStateException("ساخت پوشه آپدیت ممکن نشد.")
        }
        targetDir.listFiles()?.forEach { if (it.name.endsWith(".apk")) it.delete() }

        val target = File(targetDir, "Trade-${info.versionCode}.apk")
        val connection = (URL(info.url + if (info.url.contains('?')) "&ts=${System.currentTimeMillis()}" else "?ts=${System.currentTimeMillis()}").openConnection() as HttpURLConnection).apply {
            connectTimeout = 15_000
            readTimeout = 90_000
            instanceFollowRedirects = true
            setRequestProperty("User-Agent", "Trade-Android-Updater/${currentVersionName(context)}")
            setRequestProperty("Accept", "application/vnd.android.package-archive")
            setRequestProperty("Cache-Control", "no-cache")
        }

        try {
            val code = connection.responseCode
            if (code !in 200..299) throw IllegalStateException("دانلود آپدیت ناموفق بود (HTTP $code).")
            connection.inputStream.use { input ->
                target.outputStream().use { output -> input.copyTo(output) }
            }
        } finally {
            connection.disconnect()
        }

        if (target.length() < 100_000) {
            target.delete()
            throw IllegalStateException("فایل APK دانلودشده معتبر نیست.")
        }

        val actual = sha256(target)
        if (!actual.equals(info.sha256, ignoreCase = true)) {
            target.delete()
            throw SecurityException("SHA-256 فایل آپدیت تأیید نشد.")
        }
        target
    }

    fun currentVersionCode(context: Context): Long {
        val packageInfo = context.packageManager.getPackageInfo(context.packageName, 0)
        return if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.P) {
            packageInfo.longVersionCode
        } else {
            @Suppress("DEPRECATION") packageInfo.versionCode.toLong()
        }
    }

    fun currentVersionName(context: Context): String {
        val packageInfo = context.packageManager.getPackageInfo(context.packageName, 0)
        return packageInfo.versionName?.takeIf { it.isNotBlank() } ?: "-"
    }

    private fun parseAndroid(raw: String): JSONObject? {
        return try {
            val root = JSONObject(raw)
            root.optJSONObject("data")?.optJSONObject("android")
                ?: root.optJSONObject("android")
        } catch (_: Exception) {
            null
        }
    }

    private fun downloadText(url: String): String {
        val connection = (URL(url).openConnection() as HttpURLConnection).apply {
            connectTimeout = 10_000
            readTimeout = 20_000
            instanceFollowRedirects = true
            setRequestProperty("User-Agent", "Trade-Android-Updater")
            setRequestProperty("Accept", "application/json")
            setRequestProperty("Cache-Control", "no-cache")
        }
        try {
            val code = connection.responseCode
            if (code !in 200..299) throw IllegalStateException("Update manifest HTTP $code")
            return connection.inputStream.bufferedReader().use { it.readText() }
        } finally {
            connection.disconnect()
        }
    }

    private fun sha256(file: File): String {
        val digest = MessageDigest.getInstance("SHA-256")
        file.inputStream().use { input ->
            val buffer = ByteArray(64 * 1024)
            while (true) {
                val read = input.read(buffer)
                if (read <= 0) break
                digest.update(buffer, 0, read)
            }
        }
        return digest.digest().joinToString("") { "%02x".format(it) }
    }
}
