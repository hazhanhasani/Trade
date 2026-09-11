<?php

declare(strict_types=1);

// Coordinated release: Profit-First now cross-checks each tradable Nobitex asset against its IRT spot, USDT spot/global reference and Nobitex USDT/IRT conversion rate with bounded basis-aware adjustments; confirmed BUY/SELL Bale deliveries use an immediate durable outbox and keep retrying until sent while Bale is enabled and configured.
return '1.4.28';
