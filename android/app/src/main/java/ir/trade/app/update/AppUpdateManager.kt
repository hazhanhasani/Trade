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

    data class ReleaseStatus(
        val installedVersion: String,
        val backendVersion: String?,
        val releaseVersion: String?,
        val synchronized: Boolean,
    )

    data class CheckResult(
        val update: UpdateInfo?,
        val status: ReleaseStatus,
    )

    /** Backward-compatible shortcut for callers that only care about an APK update. */
    suspend fun check(context: Context): UpdateInfo? = checkRelease(context).update

    /**
     * Checks both the installed backend and the stable release manifest.
     * The highest valid signed-asset candidate wins, while the returned status lets
     * the UI make backend/app rollout drift visible instead of silently hiding it.
     */
    suspend fun checkRelease(context: Context): CheckResult = withContext(Dispatchers.IO) {
        val currentCode = currentVersionCode(context)
        val installedVersion = currentVersionName(context)
        val payloads = mutableListOf<String>()
        var backendVersion: String? = null
        var stableReleaseVersion: String? = null

        // Primary path: installed backend. This reports the version that is actually
        // running on cPanel while still carrying the stable Android release metadata.
        try {
            val response = TradeApi(SERVER, "").updateInfo()
            if (response.ok && response.body.isNotBlank()) {
                payloads += response.body
                backendVersion = parseBackendVersion(response.body) ?: backendVersion
                stableReleaseVersion = parseReleaseVersion(response.body) ?: stableReleaseVersion
            }
        } catch (_: Exception) {
            // Direct stable manifest remains the fallback when the backend is offline,
            // stale or in the middle of a self-update.
        }

        try {
            val manifest = downloadText("$DIRECT_MANIFEST?ts=${System.currentTimeMillis()}")
            payloads += manifest
            val manifestBackend = parseBackendVersion(manifest)
            val manifestRelease = parseReleaseVersion(manifest)

            // A stable release is publishable only when backend and Android share the
            // same semantic version. Ignore a malformed/mixed release for installation.
            if (manifestBackend != null && manifestRelease != null && manifestBackend == manifestRelease) {
                stableReleaseVersion = manifestRelease
            }
        } catch (_: Exception) {
            // Backend metadata may still be enough to continue.
        }

        val candidates = payloads.mapNotNull { raw ->
            val android = parseAndroid(raw) ?: return@mapNotNull null
            val remoteCode = android.optLong("version_code", 0L)
            val versionName = android.optString("version_name").trim().ifBlank { remoteCode.toString() }
            val available = android.optBoolean("available", false)
            val url = android.optString("url").trim()
            val sha256 = android.optString("sha256").trim().lowercase()

            if (
                available &&
                remoteCode > currentCode &&
                versionName.isNotBlank() &&
                url.startsWith("https://") &&
                sha256.matches(Regex("^[a-f0-9]{64}$"))
            ) {
                UpdateInfo(
                    available = true,
                    versionCode = remoteCode,
                    versionName = versionName,
                    url = url,
                    sha256 = sha256,
                )
            } else {
                null
            }
        }

        val update = candidates.maxByOrNull { it.versionCode }
        val releaseVersion = update?.versionName ?: stableReleaseVersion
        val synchronized = backendVersion != null &&
            releaseVersion != null &&
            installedVersion == backendVersion &&
            backendVersion == releaseVersion

        CheckResult(
            update = update,
            status = ReleaseStatus(
                installedVersion = installedVersion,
                backendVersion = backendVersion,
                releaseVersion = releaseVersion,
                synchronized = synchronized,
            ),
        )
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

    private fun parseBackendVersion(raw: String): String? {
        return try {
            val root = JSONObject(raw)
            root.optJSONObject("data")?.optString("backend_version")?.trim()?.takeIf { it.isNotBlank() }
                ?: root.optJSONObject("backend")?.optString("version")?.trim()?.takeIf { it.isNotBlank() }
                ?: root.optString("backend_version").trim().takeIf { it.isNotBlank() }
        } catch (_: Exception) {
            null
        }
    }

    private fun parseReleaseVersion(raw: String): String? {
        return try {
            val root = JSONObject(raw)
            val android = root.optJSONObject("data")?.optJSONObject("android") ?: root.optJSONObject("android")
            android?.optString("version_name")?.trim()?.takeIf { it.isNotBlank() }
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
