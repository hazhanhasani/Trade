package ir.trade.app.data

import android.content.Context
import android.security.keystore.KeyGenParameterSpec
import android.security.keystore.KeyProperties
import android.util.Base64
import java.security.KeyStore
import javax.crypto.Cipher
import javax.crypto.KeyGenerator
import javax.crypto.SecretKey
import javax.crypto.spec.GCMParameterSpec

class TradePreferences(context: Context) {
    private val prefs = context.getSharedPreferences("trade_server", Context.MODE_PRIVATE)
    private val defaultServer = "https://rado-taxi.sbs"
    private val keyAlias = "trade_app_token_key_v1"

    fun serverUrl(): String = prefs.getString("server_url", defaultServer) ?: defaultServer

    fun apiToken(): String {
        val encrypted = prefs.getString("api_token_enc", null)
        val iv = prefs.getString("api_token_iv", null)
        if (!encrypted.isNullOrBlank() && !iv.isNullOrBlank()) {
            return runCatching { decrypt(encrypted, iv) }.getOrDefault("")
        }

        val legacy = prefs.getString("api_token", "").orEmpty()
        if (legacy.isNotBlank()) {
            runCatching {
                save(serverUrl(), legacy)
                prefs.edit().remove("api_token").apply()
            }
        }
        return legacy
    }

    fun isConfigured(): Boolean = serverUrl().startsWith("https://") && apiToken().isNotBlank()

    fun save(serverUrl: String, apiToken: String) {
        require(serverUrl.startsWith("https://")) { "HTTPS is required" }
        require(apiToken.isNotBlank()) { "API token is required" }
        val encrypted = encrypt(apiToken.trim())
        prefs.edit()
            .putString("server_url", serverUrl.trimEnd('/'))
            .putString("api_token_enc", encrypted.first)
            .putString("api_token_iv", encrypted.second)
            .remove("api_token")
            .apply()
    }

    fun saveOfflineSnapshot(json: String) {
        if (json.isBlank()) return
        val encrypted = encrypt(json)
        prefs.edit()
            .putString("offline_snapshot_enc", encrypted.first)
            .putString("offline_snapshot_iv", encrypted.second)
            .putLong("offline_snapshot_at", System.currentTimeMillis())
            .apply()
    }

    fun offlineSnapshot(): String? {
        val encrypted = prefs.getString("offline_snapshot_enc", null) ?: return null
        val iv = prefs.getString("offline_snapshot_iv", null) ?: return null
        return runCatching { decrypt(encrypted, iv) }.getOrNull()
    }

    fun offlineSnapshotAt(): Long = prefs.getLong("offline_snapshot_at", 0L)

    fun biometricEnabled(): Boolean = prefs.getBoolean("biometric_enabled", false)
    fun setBiometricEnabled(enabled: Boolean) { prefs.edit().putBoolean("biometric_enabled", enabled).apply() }

    fun alertsEnabled(): Boolean = prefs.getBoolean("alerts_enabled", true)
    fun setAlertsEnabled(enabled: Boolean) { prefs.edit().putBoolean("alerts_enabled", enabled).apply() }

    fun lastAlertId(): Long = prefs.getLong("last_alert_id", 0L)
    fun setLastAlertId(id: Long) { prefs.edit().putLong("last_alert_id", id).apply() }

    fun clear() {
        prefs.edit()
            .remove("api_token")
            .remove("api_token_enc")
            .remove("api_token_iv")
            .remove("offline_snapshot_enc")
            .remove("offline_snapshot_iv")
            .remove("offline_snapshot_at")
            .putString("server_url", defaultServer)
            .apply()
    }

    private fun encrypt(plain: String): Pair<String, String> {
        val cipher = Cipher.getInstance("AES/GCM/NoPadding")
        cipher.init(Cipher.ENCRYPT_MODE, getOrCreateKey())
        val ciphertext = cipher.doFinal(plain.toByteArray(Charsets.UTF_8))
        return Base64.encodeToString(ciphertext, Base64.NO_WRAP) to Base64.encodeToString(cipher.iv, Base64.NO_WRAP)
    }

    private fun decrypt(ciphertextB64: String, ivB64: String): String {
        val cipher = Cipher.getInstance("AES/GCM/NoPadding")
        val iv = Base64.decode(ivB64, Base64.NO_WRAP)
        val ciphertext = Base64.decode(ciphertextB64, Base64.NO_WRAP)
        cipher.init(Cipher.DECRYPT_MODE, getOrCreateKey(), GCMParameterSpec(128, iv))
        return cipher.doFinal(ciphertext).toString(Charsets.UTF_8)
    }

    private fun getOrCreateKey(): SecretKey {
        val keyStore = KeyStore.getInstance("AndroidKeyStore").apply { load(null) }
        (keyStore.getKey(keyAlias, null) as? SecretKey)?.let { return it }

        val generator = KeyGenerator.getInstance(KeyProperties.KEY_ALGORITHM_AES, "AndroidKeyStore")
        generator.init(
            KeyGenParameterSpec.Builder(
                keyAlias,
                KeyProperties.PURPOSE_ENCRYPT or KeyProperties.PURPOSE_DECRYPT,
            )
                .setBlockModes(KeyProperties.BLOCK_MODE_GCM)
                .setEncryptionPaddings(KeyProperties.ENCRYPTION_PADDING_NONE)
                .setKeySize(256)
                .build(),
        )
        return generator.generateKey()
    }
}
