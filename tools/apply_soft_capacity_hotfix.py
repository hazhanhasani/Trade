from pathlib import Path

# 1) Global risk: configured max positions is the only hard count gate.
global_risk = Path('backend/src/Trading/NobitexGlobalRiskRuntime.php')
text = global_risk.read_text()

old = """        $configuredMax=$this->settingInt($pdo,'nobitex_max_positions',5,1,20);
        $effectiveMax=$this->effectiveMaxPositions($pdo,$configuredMax);
        $base=$pdo->query('SELECT position_percent,max_position_percent FROM autotrade_settings WHERE id=1')->fetch() ?: [];
        $configured=min(max(0.1,(float)($base['position_percent']??5.0)),max(0.1,(float)($base['max_position_percent']??10.0)));
        $effective=min($configured,$limit/max(1,$effectiveMax));
"""
new = """        $configuredMax=$this->settingInt($pdo,'nobitex_max_positions',5,1,20);
        // Adaptive capacity is advisory from 1.4.21 onward: it reduces the
        // size of future entries, but it must never lower the user's hard
        // configured position-count ceiling or prevent the market scan.
        $adaptiveSoftMax=$this->effectiveMaxPositions($pdo,$configuredMax);
        $base=$pdo->query('SELECT position_percent,max_position_percent FROM autotrade_settings WHERE id=1')->fetch() ?: [];
        $configured=min(max(0.1,(float)($base['position_percent']??5.0)),max(0.1,(float)($base['max_position_percent']??10.0)));
        $effective=min($configured,$limit/max(1,$configuredMax));
"""
if old not in text:
    raise SystemExit('global risk activation capacity block not found')
text = text.replace(old, new, 1)

old = """        if(count($positions)>=$effectiveMax)$multiplier=0.0;
"""
new = """        if(count($positions)>=$configuredMax)$multiplier=0.0;
"""
if old not in text:
    raise SystemExit('global risk activation hard-count block not found')
text = text.replace(old, new, 1)

old = """            'configured_max_positions'=>$configuredMax,
            'effective_max_positions'=>$effectiveMax,
            'active_positions'=>count($positions),
"""
new = """            'configured_max_positions'=>$configuredMax,
            // Keep the legacy key for API compatibility, but expose explicit
            // soft-cap semantics so diagnostics no longer imply a hard block.
            'effective_max_positions'=>$adaptiveSoftMax,
            'adaptive_soft_max_positions'=>$adaptiveSoftMax,
            'adaptive_position_size_multiplier'=>round(max(0.25,min(1.0,$adaptiveSoftMax/max(1,$configuredMax))),4),
            'capacity_mode'=>'configured_hard_cap_adaptive_soft_sizing',
            'active_positions'=>count($positions),
"""
if old not in text:
    raise SystemExit('global risk snapshot capacity fields not found')
text = text.replace(old, new, 1)

old = """            'entry_block_reason'=>$this->entryBlockReason($pdo,$valuation,$capacityRls,count($positions),$effectiveMax),
"""
new = """            'entry_block_reason'=>$this->entryBlockReason($pdo,$valuation,$capacityRls,count($positions),$configuredMax),
"""
if old not in text:
    raise SystemExit('global risk entryBlockReason call not found')
text = text.replace(old, new, 1)

old = """        $configuredMax=$this->settingInt($pdo,'nobitex_max_positions',5,1,20);
        $effectiveMax=$this->effectiveMaxPositions($pdo,$configuredMax);
        $positionRows=$pdo->query(\"SELECT id FROM nobitex_autotrade_positions WHERE status IN ('pending_open','open','pending_close') ORDER BY id ASC LIMIT 30\")->fetchAll();
"""
new = """        $configuredMax=$this->settingInt($pdo,'nobitex_max_positions',5,1,20);
        $adaptiveSoftMax=$this->effectiveMaxPositions($pdo,$configuredMax);
        $positionRows=$pdo->query(\"SELECT id FROM nobitex_autotrade_positions WHERE status IN ('pending_open','open','pending_close') ORDER BY id ASC LIMIT 30\")->fetchAll();
"""
if old not in text:
    raise SystemExit('global risk fresh-buy capacity variables not found')
text = text.replace(old, new, 1)

old = """        if(count($positionRows)>=$effectiveMax){
            throw new NobitexCandidateRejectedException($symbol,'effective_position_capacity_reached',[
                'active_positions'=>count($positionRows),'configured_max_positions'=>$configuredMax,'effective_max_positions'=>$effectiveMax,
            ]);
        }
"""
new = """        if(count($positionRows)>=$configuredMax){
            throw new NobitexCandidateRejectedException($symbol,'configured_position_capacity_reached',[
                'active_positions'=>count($positionRows),
                'configured_max_positions'=>$configuredMax,
                'effective_max_positions'=>$adaptiveSoftMax,
                'adaptive_soft_max_positions'=>$adaptiveSoftMax,
                'capacity_mode'=>'configured_hard_cap_adaptive_soft_sizing',
            ]);
        }
"""
if old not in text:
    raise SystemExit('global risk fresh-buy hard-count block not found')
text = text.replace(old, new, 1)

old = """                'configured_max_positions'=>$configuredMax,
                'effective_max_positions'=>$effectiveMax,
"""
new = """                'configured_max_positions'=>$configuredMax,
                'effective_max_positions'=>$adaptiveSoftMax,
                'adaptive_soft_max_positions'=>$adaptiveSoftMax,
                'capacity_mode'=>'configured_hard_cap_adaptive_soft_sizing',
"""
if old not in text:
    raise SystemExit('global risk exposure rejection capacity fields not found')
text = text.replace(old, new, 1)

old = """            'configured_max_positions'=>$configuredMax,
            'effective_max_positions'=>$effectiveMax,
            'valuation'=>$valuation,
"""
new = """            'configured_max_positions'=>$configuredMax,
            'effective_max_positions'=>$adaptiveSoftMax,
            'adaptive_soft_max_positions'=>$adaptiveSoftMax,
            'capacity_mode'=>'configured_hard_cap_adaptive_soft_sizing',
            'valuation'=>$valuation,
"""
if old not in text:
    raise SystemExit('global risk allowed capacity fields not found')
text = text.replace(old, new, 1)

global_risk.write_text(text)

# 2) Capacity manager: adaptive capacity must never force a reduction SELL.
capacity = Path('backend/src/Trading/NobitexCapacityManager.php')
text = capacity.read_text()
old = """        $configured = $this->intSetting($pdo,'nobitex_max_positions',5,1,20);
        $effective = min($configured,$this->intSetting($pdo,'nobitex_effective_max_positions',$configured,1,20));
        $positions = $pdo->query(\"SELECT * FROM nobitex_autotrade_positions WHERE status IN ('pending_open','open','pending_close') ORDER BY id ASC LIMIT 30\")->fetchAll();
        $activeCount = count($positions);
        if ($activeCount <= $effective) return $this->result('no_reduction','within_effective_limit',['active_positions'=>$activeCount,'configured_max'=>$configured,'effective_max'=>$effective]);
"""
new = """        $configured = $this->intSetting($pdo,'nobitex_max_positions',5,1,20);
        $adaptiveSoftMax = min($configured,$this->intSetting($pdo,'nobitex_effective_max_positions',$configured,1,20));
        // Only the user's configured max is a hard count limit. Adaptive
        // capacity is a sizing hint and must not liquidate an otherwise valid
        // position merely because recent performance became defensive.
        $effective = $configured;
        $positions = $pdo->query(\"SELECT * FROM nobitex_autotrade_positions WHERE status IN ('pending_open','open','pending_close') ORDER BY id ASC LIMIT 30\")->fetchAll();
        $activeCount = count($positions);
        if ($activeCount <= $effective) return $this->result('no_reduction','within_configured_limit',[
            'active_positions'=>$activeCount,
            'configured_max'=>$configured,
            'effective_max'=>$adaptiveSoftMax,
            'adaptive_soft_max'=>$adaptiveSoftMax,
            'capacity_mode'=>'configured_hard_cap_adaptive_soft_sizing',
        ]);
"""
if old not in text:
    raise SystemExit('capacity manager limit block not found')
text = text.replace(old, new, 1)

# Add explicit soft-cap context to forced-reduction diagnostics (which now only
# happen above the configured hard max).
text = text.replace(
    "'active_positions'=>$activeCount,'configured_max'=>$configured,'effective_max'=>$effective,'excess_positions'=>max(0,$activeCount-$effective),",
    "'active_positions'=>$activeCount,'configured_max'=>$configured,'effective_max'=>$effective,'adaptive_soft_max'=>$adaptiveSoftMax,'excess_positions'=>max(0,$activeCount-$effective),",
)
text = text.replace(
    "'active_positions_before'=>$activeCount,'configured_max'=>$configured,'effective_max'=>$effective,",
    "'active_positions_before'=>$activeCount,'configured_max'=>$configured,'effective_max'=>$effective,'adaptive_soft_max'=>$adaptiveSoftMax,",
)
capacity.write_text(text)

# 3) Portfolio entry sizing: adaptive capacity becomes a soft size multiplier.
portfolio = Path('backend/src/Trading/NobitexPortfolioEngine.php')
text = portfolio.read_text()
old = """        $portfolioMaxPct = $this->floatSetting($pdo, 'nobitex_portfolio_exposure_percent', 60.0, 10.0, 90.0);
        $maxPositions = $this->intSetting($pdo, 'nobitex_max_positions', 5, 1, 20);
        $capacity = max(0.0, ($portfolio * ($portfolioMaxPct / 100.0)) - $exposure);
"""
new = """        $portfolioMaxPct = $this->floatSetting($pdo, 'nobitex_portfolio_exposure_percent', 60.0, 10.0, 90.0);
        $maxPositions = $this->intSetting($pdo, 'nobitex_max_positions', 5, 1, 20);
        $adaptiveSoftMax = min($maxPositions, $this->intSetting($pdo, 'nobitex_effective_max_positions', $maxPositions, 1, 20));
        $adaptivePositionMultiplier = max(0.25, min(1.0, $adaptiveSoftMax / max(1, $maxPositions)));
        $capacity = max(0.0, ($portfolio * ($portfolioMaxPct / 100.0)) - $exposure);
"""
if old not in text:
    raise SystemExit('portfolio entry budget capacity block not found')
text = text.replace(old, new, 1)

old = """        $baseEffectivePerPositionPct = min($configuredPerPositionPct, $slotAlignedPct);
        $effectivePerPositionPct = $baseEffectivePerPositionPct * $learningMultiplier;
"""
new = """        $baseEffectivePerPositionPct = min($configuredPerPositionPct, $slotAlignedPct);
        // Adaptive capacity is deliberately soft: it scales new position size
        // instead of lowering the hard number of positions the user configured.
        $effectivePerPositionPct = $baseEffectivePerPositionPct * $learningMultiplier * $adaptivePositionMultiplier;
"""
if old not in text:
    raise SystemExit('portfolio effective position sizing block not found')
text = text.replace(old, new, 1)

old = """            'strategy_learning_multiplier'=>round($learningMultiplier, 4),
            'strategy_learning_reason'=>$learning['reason'] ?? null,
"""
new = """            'strategy_learning_multiplier'=>round($learningMultiplier, 4),
            'strategy_learning_reason'=>$learning['reason'] ?? null,
            'adaptive_soft_max_positions'=>$adaptiveSoftMax,
            'adaptive_position_size_multiplier'=>round($adaptivePositionMultiplier, 4),
            'capacity_mode'=>'configured_hard_cap_adaptive_soft_sizing',
"""
if old not in text:
    raise SystemExit('portfolio sizing context insertion point not found')
text = text.replace(old, new, 1)
portfolio.write_text(text)

# 4) Orchestrator must recognize the new hard-cap reason while retaining the
# legacy reason for old deployments during rolling update.
auto = Path('backend/src/Trading/NobitexAutoTraderEngine.php')
text = auto.read_text()
old = """                if (str_starts_with($reason, 'global_portfolio_') || in_array($reason, ['runtime_entry_circuit_open','effective_position_capacity_reached'], true)) {
"""
new = """                if (str_starts_with($reason, 'global_portfolio_') || in_array($reason, ['runtime_entry_circuit_open','effective_position_capacity_reached','configured_position_capacity_reached'], true)) {
"""
if old not in text:
    raise SystemExit('autotrader hard-risk reason list not found')
text = text.replace(old, new, 1)

old = """                        'reason'=>$reason,
                        'global_risk'=>$e->assessment(),
"""
new = """                        'reason'=>$reason,
                        'active_positions'=>$e->assessment()['active_positions'] ?? null,
                        'max_positions'=>$e->assessment()['configured_max_positions'] ?? null,
                        'adaptive_soft_max_positions'=>$e->assessment()['adaptive_soft_max_positions'] ?? ($e->assessment()['effective_max_positions'] ?? null),
                        'capacity_mode'=>$e->assessment()['capacity_mode'] ?? null,
                        'global_risk'=>$e->assessment(),
"""
if old not in text:
    raise SystemExit('autotrader hard-risk diagnostic block not found')
text = text.replace(old, new, 1)
auto.write_text(text)

# 5) Regression coverage.
test = Path('backend/tests/nobitex_soft_capacity_test.php')
test.write_text(r'''<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Trade\Trading\NobitexRuntimeSafety;

function assertSoftCapacity(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

// Defensive history should still produce an adaptive advisory below the user's
// configured 8 slots, proving the adaptive signal itself is still alive.
$adaptive = NobitexRuntimeSafety::adaptiveMaxPositions(8, [-1.0, -0.9, -0.8, -0.7, -0.6]);
assertSoftCapacity((int)($adaptive['effective'] ?? 0) === 4, 'defensive adaptive capacity should remain 4/8 for sizing guidance');

$global = file_get_contents(dirname(__DIR__) . '/src/Trading/NobitexGlobalRiskRuntime.php');
assertSoftCapacity(is_string($global), 'global risk source should be readable');
assertSoftCapacity(str_contains($global, 'if(count($positions)>=$configuredMax)$multiplier=0.0;'), 'global activation must hard-block only at configured max');
assertSoftCapacity(str_contains($global, 'if(count($positionRows)>=$configuredMax){'), 'fresh BUY guard must hard-block only at configured max');
assertSoftCapacity(str_contains($global, "'capacity_mode'=>'configured_hard_cap_adaptive_soft_sizing'"), 'global diagnostics must expose soft-cap mode');

$capacity = file_get_contents(dirname(__DIR__) . '/src/Trading/NobitexCapacityManager.php');
assertSoftCapacity(is_string($capacity), 'capacity manager source should be readable');
assertSoftCapacity(str_contains($capacity, '$effective = $configured;'), 'forced reductions must target configured hard max, not adaptive soft max');
assertSoftCapacity(str_contains($capacity, "'within_configured_limit'"), 'within configured max must not trigger adaptive reduction');

$portfolio = file_get_contents(dirname(__DIR__) . '/src/Trading/NobitexPortfolioEngine.php');
assertSoftCapacity(is_string($portfolio), 'portfolio engine source should be readable');
assertSoftCapacity(str_contains($portfolio, '$adaptivePositionMultiplier = max(0.25, min(1.0, $adaptiveSoftMax / max(1, $maxPositions)));'), 'adaptive capacity must produce a soft size multiplier');
assertSoftCapacity(str_contains($portfolio, '$effectivePerPositionPct = $baseEffectivePerPositionPct * $learningMultiplier * $adaptivePositionMultiplier;'), 'adaptive multiplier must scale future BUY size');

$auto = file_get_contents(dirname(__DIR__) . '/src/Trading/NobitexAutoTraderEngine.php');
assertSoftCapacity(is_string($auto) && str_contains($auto, "'configured_position_capacity_reached'"), 'orchestrator must recognize configured hard-cap rejection');
assertSoftCapacity(str_contains($auto, "'adaptive_soft_max_positions'"), 'hard-cap diagnostics must include adaptive advisory');

echo "Nobitex soft adaptive capacity regression tests passed.\n";
''')
