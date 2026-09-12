# Live Multi-Strategy execution

The live Nobitex engine uses the regime router as a real execution selector rather than shadow-only telemetry.

Safety invariants:
- Every routed BUY must keep positive tradable edge after explicit fees, spread, slippage reserves and residual uncertainty.
- The inactivity policy may relax only residual forecast uncertainty, capped at 0.06 percentage points after prolonged inactivity; it never relaxes explicit execution costs, liquidity/spread readiness, exposure, daily-loss, kill-switch or balance controls.
- Old near-flat positions can recycle capital after bounded holding periods, but time-based recycling does not force-close material losses.
- Strategy learning includes high-volatility momentum and remains reduction-only.

This file also provides a user-authored backend change so the full PHP Quality Gate validates the live hotfix commit before the coordinated release version is bumped.
