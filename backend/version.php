<?php

declare(strict_types=1);

// Residual inventory recovery: partial-exit leftovers and final SELL amount-step
// remainders are reconciled before live decisions. Unsellable bot-owned balances
// stop consuming active slots; sellable residuals continue their original exit;
// recently closed rows can recover mathematically known wallet dust without
// fabricating an exit price or realized PnL.
// 1.4.44 republishes the same hardened runtime after transient Actions startup failures.
return '1.4.44';
