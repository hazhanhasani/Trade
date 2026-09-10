package ir.trade.app.ui

import androidx.compose.foundation.layout.ColumnScope

/**
 * Classifier alias used by TradeAppV3's slot receiver. Kotlin keeps the
 * classifier namespace separate from the imported Compose Column function,
 * so Column { ... } remains the standard composable while @Composable
 * Column.() -> Unit resolves to ColumnScope.
 */
typealias Column = ColumnScope
