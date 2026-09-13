<?php

declare(strict_types=1);

// Managed residual inventory is converted only from Trade-owned dust rows into
// IRT/Toman whenever Nobitex minimum/step rules allow it. Manual/external SELLs
// are reconciled before each fast decision run so released slots can be replaced.
// Expected BUY-rate safety throttles remain enforced but are reported as normal
// no-trade backpressure instead of false cron failures. 1.4.47 aligns the full
// observability regression suite with the managed-dust ownership safety model.
return '1.4.47';
