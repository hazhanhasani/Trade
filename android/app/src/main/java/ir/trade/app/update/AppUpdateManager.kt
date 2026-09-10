package ir.trade.app.update

import android.content.Context
import android.os.Build
import android.os.Environment
import ir.trade.app.data.ReleaseContract
import ir.trade.app.data.TradeApi
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import org.json.JSONArray
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
        val backendApiContract: Int?,
        val releaseApiContract: Int?,
        val contractCompatible: Boolean,
        val missingCapabilities: List<String>,
        val synchronized: Boolean,
    )

    data class CheckResult(
        val update: UpdateInfo?,
        val status: ReleaseStatus,
    )

    private data class ParsedPayload(
        val android: JSONObject,
        val backendVersion: String?,
        val releaseVersion: String?,
        val requiredBackendVersion: String,
        val apiContract: Int?,
        val requiredApiContract: Int?,
        val capabilities: Set<String>,
        val runtimePayload: Boolean,
    )

    suspend fun check(context: Context): UpdateInfo? = checkRelease(context).update

    suspend fun checkRelease(context: Context): CheckResult = withContext(Dispatchers.IO) {
        val currentCode = currentVersionCode(context)
        val installedVersion = currentVersionName(context)
        val payloads = mutableListOf<ParsedPayload>()

        var backendVersion: String? = null
        var backendApiContract: Int? = null
        var backendCapabilities: Set<String> = emptySet()
        var stableReleaseVersion: String? = null
        var stableReleaseContract: Int? = null

        try {
            val response = TradeApi(SERVER, "").updateInfo()
            if (response.ok && response.body.isNotBlank()) {
                parsePayload(response.body)?.let { parsed ->
                    payloads += parsed
                    backendVersion = parsed.backendVersion ?: backendVersion
                    backendApiContract = parsed.apiContract ?: backendApiContract
                    backendCapabilities = parsed.capabilities.ifEmpty { backendCapabilities }
                    if (parsed.backendVersion == parsed.releaseVersion) {
                        stableReleaseVersion = parsed.releaseVersion ?: stableReleaseVersion
                        stableReleaseContract = parsed.apiContract ?: stableReleaseContract
                    }
                }
            }
        } catch (_: Exception) {
            // A direct manifest can still describe the stable release, but no APK is
            // installed until the running backend version has also been verified.
        }

        try {
            val manifest = downloadText("$DIRECT_MANIFEST?ts=${System.currentTimeMillis()}")
            parsePayload(manifest)?.let { parsed ->
                payloads += parsed
                if (isCoordinatedPayload(parsed)) {
                    stableReleaseVersion = parsed.releaseVersion ?: stableReleaseVersion
                    stableReleaseContract = parsed.apiContract ?: stableReleaseContract
                }
            }
        } catch (_: Exception) {
            // Runtime backend metadata may still be enough to show current status.
        }

        val candidates = payloads.mapNotNull { parsed ->
            if (!isCoordinatedPayload(parsed)) return@mapNotNull null

            val android = parsed.android
            val remoteCode = android.optLong("version_code", 0L)
            val versionName = android.optString("version_name").trim().ifBlank { remoteCode.toString() }
            val available = android.optBoolean("available", false)
            val url = android.optString("url").trim()
            val sha256 = android.optString("sha256").trim().lowercase()
            val runningBackendMatches = backendVersion != null && backendVersion == versionName

            if (
                available &&
                runningBackendMatches &&
                remoteCode > currentCode &&
                versionName.isNotBlank() &&
                parsed.requiredBackendVersion == versionName &&
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
        val missing = ReleaseContract.missingCapabilities(backendCapabilities).sorted()
        val contractCompatible = backendApiContract == ReleaseContract.API_CONTRACT && missing.isEmpty()
        val synchronized = backendVersion != null &&
            releaseVersion != null &&
            installedVersion == backendVersion &&
            backendVersion == releaseVersion &&
            contractCompatible

        CheckResult(
            update = update,
            status = ReleaseStatus(
                installedVersion = installedVersion,
                backendVersion = backendVersion,
                releaseVersion = releaseVersion,
                backendApiContract = backendApiContract,
                releaseApiContract = stableReleaseContract,
                contractCompatible = contractCompatible,
                missingCapabilities = missing,
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

    private fun parsePayload(raw: String): ParsedPayload? {
        return try {
            val root = JSONObject(raw)
            val data = root.optJSONObject("data")
            val android = data?.optJSONObject("android") ?: root.optJSONObject("android") ?: return null
            val runtimePayload = data?.has("backend_version") == true

            val backendVersion = data?.optString("backend_version")?.trim()?.takeIf { it.isNotBlank() }
                ?: root.optJSONObject("backend")?.optString("version")?.trim()?.takeIf { it.isNotBlank() }
                ?: root.optString("backend_version").trim().takeIf { it.isNotBlank() }

            val releaseVersion = data?.optString("release_version")?.trim()?.takeIf { it.isNotBlank() }
                ?: root.optJSONObject("release")?.optString("version")?.trim()?.takeIf { it.isNotBlank() }
                ?: android.optString("version_name").trim().takeIf { it.isNotBlank() }

            val requiredBackendVersion = android.optString("required_backend_version").trim()
                .ifBlank { android.optString("version_name").trim() }

            val dataContract = data?.optInt("api_contract", -1) ?: -1
            val releaseContract = root.optJSONObject("release")?.optInt("api_contract", -1) ?: -1
            val rootContract = root.optInt("api_contract", -1)
            val apiContract = sequenceOf(dataContract, releaseContract, rootContract)
                .firstOrNull { it >= 0 }
            val requiredApiContract = android.optInt("required_api_contract", -1)
                .takeIf { it >= 0 } ?: apiContract

            val capabilities = when {
                data?.optJSONArray("capabilities") != null -> jsonStringSet(data.optJSONArray("capabilities"))
                root.optJSONObject("release")?.optJSONArray("capabilities") != null -> jsonStringSet(root.optJSONObject("release")?.optJSONArray("capabilities"))
                root.optJSONArray("capabilities") != null -> jsonStringSet(root.optJSONArray("capabilities"))
                else -> emptySet()
            }

            ParsedPayload(
                android = android,
                backendVersion = backendVersion,
                releaseVersion = releaseVersion,
                requiredBackendVersion = requiredBackendVersion,
                apiContract = apiContract,
                requiredApiContract = requiredApiContract,
                capabilities = capabilities,
                runtimePayload = runtimePayload,
            )
        } catch (_: Exception) {
            null
        }
    }

    private fun isCoordinatedPayload(parsed: ParsedPayload): Boolean {
        val versionName = parsed.android.optString("version_name").trim()
        if (versionName.isBlank() || parsed.requiredBackendVersion != versionName) return false
        if (parsed.apiContract == null || parsed.apiContract < 1) return false
        if (parsed.requiredApiContract == null || parsed.requiredApiContract != parsed.apiContract) return false
        if (!parsed.capabilities.contains("api.capability_contract")) return false
        if (!parsed.capabilities.contains("updates.coordinated_backend_android")) return false

        return if (parsed.runtimePayload) {
            parsed.backendVersion == versionName
        } else {
            parsed.releaseVersion == versionName && parsed.backendVersion == versionName
        }
    }

    private fun jsonStringSet(array: JSONArray?): Set<String> {
        if (array == null) return emptySet()
        val values = linkedSetOf<String>()
        for (i in 0 until array.length()) {
            val value = array.optString(i).trim()
            if (value.isNotBlank()) values += value
        }
        return values
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
