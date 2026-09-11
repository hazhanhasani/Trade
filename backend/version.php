<?php

declare(strict_types=1);

// Coordinated release: dashboard account truth + no-flash theme debug. Admin reads the full Nobitex spot-wallet valuation through the shared cache, clearly separates exchange wallet truth from Trade realized-PnL statistics, explains portfolio_full capacity, fixes dark-mode contrast/FOUC, and rate-limits repeated routine Bale info fingerprints without suppressing local diagnostics. Release validation includes the dedicated 1.4.24 dashboard/theme regression gate.
return '1.4.24';
