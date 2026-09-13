<?php

declare(strict_types=1);

// Managed residual inventory is now converted only from Trade-owned dust rows
// into IRT/Toman whenever Nobitex minimum/step rules allow it. Manual/external
// SELLs are reconciled before each fast decision run so released slots can be
// replaced without waiting for stale internal position state. Expected BUY-rate
// safety throttles are no-trade decisions instead of false cron failures.
return '1.4.45';
