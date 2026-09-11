<?php

declare(strict_types=1);

// Coordinated stable release: Profit-First cross-checks each tradable Nobitex asset against its IRT spot, USDT spot/global reference and Nobitex USDT/IRT conversion rate with bounded basis-aware adjustments. Confirmed BUY/SELL Bale notifications use an immediate idempotent durable outbox and remain retryable until successfully sent while Bale is enabled and configured.
return '1.4.28';
