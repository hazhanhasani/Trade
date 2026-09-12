package ir.trade.app

import android.Manifest
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.os.Build
import androidx.core.app.NotificationCompat
import androidx.core.app.NotificationManagerCompat
import androidx.core.content.ContextCompat
import androidx.work.Constraints
import androidx.work.CoroutineWorker
import androidx.work.ExistingPeriodicWorkPolicy
import androidx.work.NetworkType
import androidx.work.PeriodicWorkRequestBuilder
import androidx.work.WorkManager
import androidx.work.WorkerParameters
import ir.trade.app.data.TradeApi
import ir.trade.app.data.TradePreferences
import org.json.JSONObject
import java.util.concurrent.TimeUnit

class TradeAlertWorker(appContext: Context, params: WorkerParameters) : CoroutineWorker(appContext, params) {
    override suspend fun doWork(): Result {
        val prefs = TradePreferences(applicationContext)
        if (!prefs.alertsEnabled() || !prefs.isConfigured()) return Result.success()

        val api = TradeApi(prefs.serverUrl(), prefs.apiToken())
        val status = runCatching { api.status() }.getOrNull() ?: return Result.retry()
        if (!status.ok || !api.isContractCompatible()) return Result.retry()

        val rulesResponse = runCatching { api.notificationRules() }.getOrNull()
        val rules = if (rulesResponse?.ok == true) {
            runCatching { JSONObject(rulesResponse.body).optJSONObject("data") ?: JSONObject() }.getOrDefault(JSONObject())
        } else JSONObject()
        if (!rules.optBoolean("enabled", true)) return Result.success()

        // Keep unread notifications pending on the server when Android permission
        // is missing. Advancing the local cursor here used to silently discard
        // alerts before the user granted POST_NOTIFICATIONS.
        if (!canNotify()) return Result.success()

        val response = runCatching { api.notifications(100, true) }.getOrNull() ?: return Result.retry()
        if (!response.ok) return Result.retry()
        val data = runCatching { JSONObject(response.body).optJSONObject("data") }.getOrNull() ?: return Result.success()
        val items = data.optJSONArray("items") ?: return Result.success()
        if (items.length() == 0) return Result.success()

        val minPriority = priorityValue(rules.optString("min_priority", "warning"))
        val categories = mutableSetOf<String>()
        rules.optJSONArray("categories")?.let { array ->
            for (i in 0 until array.length()) array.optString(i).takeIf { it.isNotBlank() }?.let(categories::add)
        }

        val received = mutableListOf<JSONObject>()
        for (i in 0 until items.length()) {
            val item = items.optJSONObject(i) ?: continue
            if (item.optLong("id", 0L) > 0L) received += item
        }
        if (received.isEmpty()) return Result.success()

        val eligible = received
            .filter { item ->
                val priority = item.optString("priority", "info")
                val category = item.optString("category", "system")
                priorityValue(priority) >= minPriority &&
                    (categories.isEmpty() || category in categories || priority == "critical")
            }
            .sortedBy { it.optLong("id", 0L) }

        ensureChannel()
        val visible = eligible.takeLast(MAX_VISIBLE_NOTIFICATIONS)
        visible.forEach { item ->
            val id = item.optLong("id", 0L)
            showNotification(
                title = item.optString("title", "Trade"),
                body = item.optString("body", "رویداد جدید معاملاتی ثبت شد."),
                critical = item.optString("priority") == "critical",
                notificationId = notificationIdFor(id),
            )
        }
        if (eligible.size > MAX_VISIBLE_NOTIFICATIONS) {
            val hidden = eligible.size - MAX_VISIBLE_NOTIFICATIONS
            showNotification(
                title = "هشدارهای جدید Trade",
                body = "$hidden هشدار دیگر ثبت شد؛ آخرین موارد مهم نمایش داده شدند.",
                critical = eligible.any { it.optString("priority") == "critical" },
                notificationId = SUMMARY_NOTIFICATION_ID,
            )
        }

        // Acknowledge exactly the rows returned in this bounded batch. Never use
        // all=true here: there may be older unread rows beyond the 100-row page.
        val receivedIds = received.map { it.optLong("id", 0L) }.filter { it > 0L }
        val ack = runCatching { api.markNotificationsRead(receivedIds) }.getOrNull()
        if (ack?.ok != true) return Result.retry()

        prefs.setLastAlertId(receivedIds.maxOrNull() ?: 0L)
        return Result.success()
    }

    private fun canNotify(): Boolean =
        Build.VERSION.SDK_INT < 33 || ContextCompat.checkSelfPermission(applicationContext, Manifest.permission.POST_NOTIFICATIONS) == PackageManager.PERMISSION_GRANTED

    private fun ensureChannel() {
        if (Build.VERSION.SDK_INT >= 26) {
            val manager = applicationContext.getSystemService(NotificationManager::class.java)
            manager.createNotificationChannel(
                NotificationChannel(CHANNEL_ID, "هشدارهای Trade", NotificationManager.IMPORTANCE_HIGH).apply {
                    description = "هشدارهای ریسک، اجرا و معاملات Trade"
                },
            )
        }
    }

    private fun showNotification(title: String, body: String, critical: Boolean, notificationId: Int) {
        val intent = Intent(applicationContext, MainActivity::class.java).apply {
            flags = Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TOP
        }
        val pending = PendingIntent.getActivity(
            applicationContext,
            notificationId,
            intent,
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE,
        )
        val notification = NotificationCompat.Builder(applicationContext, CHANNEL_ID)
            .setSmallIcon(R.mipmap.ic_launcher)
            .setContentTitle(title)
            .setContentText(body)
            .setStyle(NotificationCompat.BigTextStyle().bigText(body))
            .setPriority(if (critical) NotificationCompat.PRIORITY_MAX else NotificationCompat.PRIORITY_HIGH)
            .setAutoCancel(true)
            .setContentIntent(pending)
            .build()
        NotificationManagerCompat.from(applicationContext).notify(notificationId, notification)
    }

    private fun notificationIdFor(id: Long): Int = (id % (Int.MAX_VALUE - 10_000)).toInt().coerceAtLeast(1)

    private fun priorityValue(value: String): Int = when (value.lowercase()) {
        "critical" -> 3
        "warning" -> 2
        "success" -> 1
        else -> 0
    }

    companion object Scheduler {
        private const val CHANNEL_ID = "trade_alerts_v1"
        private const val WORK_NAME = "trade_smart_alerts"
        private const val MAX_VISIBLE_NOTIFICATIONS = 6
        private const val SUMMARY_NOTIFICATION_ID = 2_147_470_001

        fun schedule(context: Context) {
            val prefs = TradePreferences(context)
            if (!prefs.alertsEnabled()) {
                WorkManager.getInstance(context).cancelUniqueWork(WORK_NAME)
                return
            }
            val request = PeriodicWorkRequestBuilder<TradeAlertWorker>(15, TimeUnit.MINUTES)
                .setConstraints(Constraints.Builder().setRequiredNetworkType(NetworkType.CONNECTED).build())
                .build()
            WorkManager.getInstance(context).enqueueUniquePeriodicWork(
                WORK_NAME,
                ExistingPeriodicWorkPolicy.UPDATE,
                request,
            )
        }

        fun cancel(context: Context) {
            WorkManager.getInstance(context).cancelUniqueWork(WORK_NAME)
        }
    }
}
