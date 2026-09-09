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

        // One-time migration from early builds that stored the app token as plain text.
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

        val cipher = Cipher.getInstance("AES/GCM/NoPadding")
        cipher.init(Cipher.ENCRYPT_MODE, getOrCreateKey())
        val ciphertext = cipher.doFinal(apiToken.trim().toByteArray(Charsets.UTF_8))

        prefs.edit()
            .putString("server_url", serverUrl.trimEnd('/'))
            .putString("api_token_enc", Base64.encodeToString(ciphertext, Base64.NO_WRAP))
            .putString("api_token_iv", Base64.encodeToString(cipher.iv, Base64.NO_WRAP))
            .remove("api_token")
            .apply()
    }

    fun clear() {
        prefs.edit()
            .remove("api_token")
            .remove("api_token_enc")
            .remove("api_token_iv")
            .putString("server_url", defaultServer)
            .apply()
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
