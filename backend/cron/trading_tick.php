<?php

declare(strict_types=1);

require_once __DIR__ . '/../public/bootstrap.php';

use Trade\Trading\SignalEngine;

// Automated trading scheduler entry point.
// Live execution remains protected by trading.enabled and kill switch.

$engine = new SignalEngine();

// Market fetching and execution will be connected here.
// This worker is designed for cPanel cron execution.

