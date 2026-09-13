<?php

declare(strict_types=1);

// Managed residual inventory is converted only from Trade-owned dust rows into
// IRT/Toman whenever Nobitex minimum/step rules allow it. Manual/external SELLs
// are reconciled before each fast decision run so released slots can be replaced.
// Expected BUY-rate safety throttles remain enforced but are reported as normal
// no-trade backpressure instead of false cron failures.
// Android 1.4.48 hardens duplicate LazyColumn keys, fixes Row pill sizing so
// decision text cannot collapse to one-character columns, and localizes common
// backend decision reasons when reason_fa is unavailable.
// 1.4.49 separates current actionable alerts from stale notification history,
// resolves failed-run warnings after recovery, and expires transient scan warnings.
// 1.4.50 makes trailing-exit classification fee-aware and waits for finalized
// net SELL accounting before publishing confirmed trade notifications.
return '1.4.50';
