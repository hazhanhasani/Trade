<?php

declare(strict_types=1);

// Execution architecture cleanup: Nobitex is the only execution exchange;
// Bitpin is now a pure public Market Data source with all legacy execution
// code, database state and credentials removed during upgrade.
return '1.4.32';
