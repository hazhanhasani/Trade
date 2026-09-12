<?php

declare(strict_types=1);

// Live trading hardening: the regime router now participates in real cost-aware
// execution, bounded anti-starvation may relax only residual forecast uncertainty,
// older near-flat positions may recycle capital without forcing material losses,
// high-volatility strategy learning is live, and all existing execution/risk gates
// remain authoritative alongside the 1.4.40 database/app/debug hardening.
return '1.4.41';
