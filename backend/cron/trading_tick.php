<?php

declare(strict_types=1);

// Backward-compatible dedicated trading cron entry point.
// The main tick already performs updater + live auto-trading + run logging.
require __DIR__ . '/tick.php';
