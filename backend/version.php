<?php

declare(strict_types=1);

// Residual-dust recovery: exchange-minimum leftovers from partial exits no longer
// consume active position capacity forever. Trade-owned residuals are classified
// against live Nobitex order rules before each fast cycle and are swept later only
// when they become independently sellable; no synthetic exit/PnL is fabricated.
return '1.4.42';
