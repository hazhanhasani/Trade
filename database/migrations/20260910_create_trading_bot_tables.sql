CREATE TABLE IF NOT EXISTS bot_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    asset VARCHAR(20) DEFAULT 'GRAM',
    mode ENUM('disabled','paper','live') DEFAULT 'paper',
    risk_level ENUM('safe','balanced','aggressive') DEFAULT 'balanced',
    max_trade_amount DECIMAL(18,8) DEFAULT 0,
    enabled TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS trade_signals (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    asset VARCHAR(20) DEFAULT 'GRAM',
    signal ENUM('BUY','SELL','HOLD') NOT NULL,
    score DECIMAL(5,2) DEFAULT 0,
    strategy VARCHAR(100),
    price DECIMAL(18,8),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS positions (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    asset VARCHAR(20) DEFAULT 'GRAM',
    entry_price DECIMAL(18,8),
    amount DECIMAL(18,8),
    stop_loss DECIMAL(18,8),
    take_profit DECIMAL(18,8),
    status VARCHAR(30) DEFAULT 'open',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS profit_history (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    profit DECIMAL(18,8) DEFAULT 0,
    balance DECIMAL(18,8) DEFAULT 0,
    source VARCHAR(50),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);