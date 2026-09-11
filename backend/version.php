<?php

declare(strict_types=1);

// Coordinated release: dashboard account truth + no-flash theme debug. Admin reads full Nobitex spot-wallet valuation through the shared cache, clearly separates exchange wallet truth from Trade's realized PnL ledger, explains portfolio_full capacity, fixes dark-mode contrast/FOUC, and rate-limits repeated routine Bale info fingerprints without suppressing local diagnostics.
return '1.4.24';
