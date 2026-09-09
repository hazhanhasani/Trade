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

    data class UpdateInfo(
        val available: Boolean,
        val versionCode: Long,
        val versionName: String,
        val url: String,
        val sha256: String,
    )

    suspend fun check(context: Context): UpdateInfo? = withContext(Dispatchers.IO) {
        val response = TradeApi(SERVER, "").updateInfo()
        if (!response.ok) return@withContext null

        val root = JSONObject(response.body)
        val android = root.optJSONObject("data")?.optJSONObject("android") ?: return@withContext null
        val remoteCode = android.optLong("version_code", 0L)
        val available = android.optBoolean("available", false)
        val url = android.optString("url").trim()
        val sha256 = android.optString("sha256").trim().lowercase()
        val currentCode = currentVersionCode(context)

        if (!available || remoteCode <= currentCode || !url.startsWith("https://") || !sha256.matches(Regex("^[a-f0-9]{64}$"))) {
            return@withContext null
        }

        UpdateInfo(
            available = true,
            versionCode = remoteCode,
            versionName = android.optString("version_name"),
            url = url,
            sha256 = sha256,
        )
    }

    suspend fun download(context: Context, info: UpdateInfo): File = withContext(Dispatchers.IO) {
        val targetDir = File(context.getExternalFilesDir(Environment.DIRECTORY_DOWNLOADS), "updates")
        if (!targetDir.exists() && !targetDir.mkdirs()) {
            throw IllegalStateException("ساخت پوشه آپدیت ممکن نشد.")
        }
        targetDir.listFiles()?.forEach { if (it.name.endsWith(".apk")) it.delete() }

        val target = File(targetDir, "Trade-${info.versionCode}.apk")
        val connection = (URL(info.url).openConnection() as HttpURLConnection).apply {
            connectTimeout = 15_000
            readTimeout = 60_000
            instanceFollowRedirects = true
            setRequestProperty("User-Agent", "Trade-Android-Updater")
            setRequestProperty("Accept", "application/vnd.android.package-archive")
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

        val actual = sha256(target)
        if (!actual.equals(info.sha256, ignoreCase = true)) {
            target.delete()
            throw SecurityException("امضای هش فایل آپدیت تأیید نشد.")
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
