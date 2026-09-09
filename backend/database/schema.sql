CREATE TABLE IF NOT EXISTS admins (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS settings (
    key_name VARCHAR(120) PRIMARY KEY,
    value_text LONGTEXT NULL,
    updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS app_tokens (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    label VARCHAR(120) NOT NULL,
    token_hash CHAR(64) NOT NULL UNIQUE,
    created_by_admin_id BIGINT UNSIGNED NULL,
    last_used_at DATETIME NULL,
    revoked_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_app_token_active (revoked_at, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS app_pairings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    token_id BIGINT UNSIGNED NOT NULL,
    pairing_code_hash CHAR(64) NOT NULL UNIQUE,
    token_enc LONGTEXT NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_pairing_expiry (used_at, expires_at),
    CONSTRAINT fk_pairing_token FOREIGN KEY (token_id) REFERENCES app_tokens(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS exchange_credentials (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    exchange_name VARCHAR(50) NOT NULL UNIQUE,
    api_key_enc TEXT NOT NULL,
    secret_key_enc TEXT NOT NULL,
    access_token_enc LONGTEXT NULL,
    refresh_token_enc LONGTEXT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS strategy_rules (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(160) NOT NULL,
    market_code VARCHAR(80) NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 0,
    rule_json LONGTEXT NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_strategy_enabled (enabled, market_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS signals (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    strategy_id BIGINT UNSIGNED NULL,
    market_code VARCHAR(80) NOT NULL,
    signal_type VARCHAR(20) NOT NULL,
    confidence DECIMAL(8,4) NULL,
    payload_json LONGTEXT NULL,
    consumed_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_signal_queue (consumed_at, created_at),
    CONSTRAINT fk_signal_strategy FOREIGN KEY (strategy_id) REFERENCES strategy_rules(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS orders (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    local_id VARCHAR(64) NOT NULL UNIQUE,
    exchange_name VARCHAR(50) NOT NULL,
    exchange_order_id VARCHAR(160) NULL,
    identifier VARCHAR(190) NULL,
    market_code VARCHAR(80) NOT NULL,
    side VARCHAR(20) NOT NULL,
    order_mode VARCHAR(40) NOT NULL,
    amount DECIMAL(36,18) NOT NULL DEFAULT 0,
    price DECIMAL(36,18) NULL,
    status VARCHAR(40) NOT NULL,
    source VARCHAR(60) NOT NULL,
    request_json LONGTEXT NULL,
    response_json LONGTEXT NULL,
    error_text TEXT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_order_exchange (exchange_name, exchange_order_id),
    INDEX idx_order_created (created_at),
    INDEX idx_order_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS trades (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    exchange_trade_id VARCHAR(190) NULL,
    exchange_order_id VARCHAR(190) NULL,
    market_code VARCHAR(80) NOT NULL,
    side VARCHAR(20) NOT NULL,
    amount DECIMAL(36,18) NOT NULL DEFAULT 0,
    price DECIMAL(36,18) NOT NULL DEFAULT 0,
    fee DECIMAL(36,18) NOT NULL DEFAULT 0,
    realized_pnl DECIMAL(36,18) NOT NULL DEFAULT 0,
    raw_json LONGTEXT NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_exchange_trade (exchange_trade_id),
    INDEX idx_trade_created (created_at),
    INDEX idx_trade_market (market_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bot_runs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    run_id VARCHAR(64) NOT NULL UNIQUE,
    status VARCHAR(30) NOT NULL,
    summary_json LONGTEXT NULL,
    started_at DATETIME NOT NULL,
    finished_at DATETIME NULL,
    INDEX idx_bot_started (started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_name VARCHAR(120) NOT NULL,
    actor VARCHAR(100) NULL,
    ip_address VARCHAR(64) NULL,
    context_json LONGTEXT NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_audit_event (event_name, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS autotrade_settings (
    id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    enabled TINYINT(1) NOT NULL DEFAULT 0,
    quote_asset VARCHAR(20) NOT NULL DEFAULT 'USDT',
    risk_profile VARCHAR(20) NOT NULL DEFAULT 'balanced',
    position_percent DECIMAL(8,4) NOT NULL DEFAULT 5.0000,
    max_position_percent DECIMAL(8,4) NOT NULL DEFAULT 10.0000,
    stop_loss_percent DECIMAL(8,4) NOT NULL DEFAULT 3.0000,
    take_profit_percent DECIMAL(8,4) NOT NULL DEFAULT 6.0000,
    daily_loss_limit_percent DECIMAL(8,4) NOT NULL DEFAULT 5.0000,
    min_signal_score SMALLINT NOT NULL DEFAULT 60,
    cooldown_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 15,
    last_trade_at DATETIME NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_autotrade_enabled (enabled)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS autotrade_signals (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    market_id BIGINT UNSIGNED NOT NULL,
    symbol VARCHAR(80) NOT NULL,
    asset VARCHAR(20) NOT NULL DEFAULT 'GRAM',
    quote_asset VARCHAR(20) NOT NULL,
    action VARCHAR(12) NOT NULL,
    score SMALLINT NOT NULL,
    price DECIMAL(36,18) NOT NULL,
    details_json LONGTEXT NULL,
    executed TINYINT(1) NOT NULL DEFAULT 0,
    order_local_id VARCHAR(64) NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_autotrade_signal_created (created_at),
    INDEX idx_autotrade_signal_action (action, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS autotrade_positions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    market_id BIGINT UNSIGNED NOT NULL,
    symbol VARCHAR(80) NOT NULL,
    asset VARCHAR(20) NOT NULL DEFAULT 'GRAM',
    quote_asset VARCHAR(20) NOT NULL,
    amount DECIMAL(36,18) NOT NULL DEFAULT 0,
    entry_price DECIMAL(36,18) NOT NULL DEFAULT 0,
    stop_loss DECIMAL(36,18) NOT NULL DEFAULT 0,
    take_profit DECIMAL(36,18) NOT NULL DEFAULT 0,
    status VARCHAR(30) NOT NULL,
    entry_identifier VARCHAR(80) NOT NULL,
    entry_order_local_id VARCHAR(64) NULL,
    entry_exchange_order_id VARCHAR(190) NULL,
    exit_identifier VARCHAR(80) NULL,
    exit_order_local_id VARCHAR(64) NULL,
    exit_exchange_order_id VARCHAR(190) NULL,
    exit_price DECIMAL(36,18) NULL,
    realized_pnl DECIMAL(36,18) NULL,
    opened_at DATETIME NULL,
    closed_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_autotrade_position_status (status, created_at),
    UNIQUE KEY uq_autotrade_entry_identifier (entry_identifier)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS autotrade_pnl (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    position_id BIGINT UNSIGNED NOT NULL,
    pnl DECIMAL(36,18) NOT NULL,
    pnl_percent DECIMAL(18,8) NOT NULL,
    quote_asset VARCHAR(20) NOT NULL,
    entry_price DECIMAL(36,18) NOT NULL,
    exit_price DECIMAL(36,18) NOT NULL,
    amount DECIMAL(36,18) NOT NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_autotrade_pnl_created (created_at),
    CONSTRAINT fk_autotrade_pnl_position FOREIGN KEY (position_id) REFERENCES autotrade_positions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS autotrade_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    level VARCHAR(20) NOT NULL,
    event_name VARCHAR(120) NOT NULL,
    context_json LONGTEXT NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_autotrade_event_created (created_at),
    INDEX idx_autotrade_event_name (event_name, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
