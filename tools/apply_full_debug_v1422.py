from pathlib import Path

# 1) Profit-First: keep explicit execution costs, but make the extra buffer
# uncertainty-only so fees/spread/slippage are not charged twice.
engine = Path('backend/src/Trading/NobitexInternalSignalEngine.php')
text = engine.read_text()
text = text.replace('    private const MIN_EDGE_BUFFER_PERCENT = 0.20;\n    private const MAX_EDGE_BUFFER_PERCENT = 1.10;',
                    '    private const MIN_EDGE_BUFFER_PERCENT = 0.12;\n    private const MAX_EDGE_BUFFER_PERCENT = 0.55;', 1)
old = """        $edgeBuffer = $this->clamp(
            0.20
                + min(0.30, $estimatedCost * 0.30)
                + min(0.35, $volatility * 0.08)
                + min(0.20, $disagreementPenalty * 0.60),
            self::MIN_EDGE_BUFFER_PERCENT,
            self::MAX_EDGE_BUFFER_PERCENT
        );
        $tradableNetEdge = $netEdge - $edgeBuffer;
"""
new = """        // NetEdge already subtracts fee + spread + volatility slippage +
        // liquidity slippage + adverse flow. The extra margin below therefore
        // represents FORECAST uncertainty only; repeating a percentage of the
        // already-subtracted execution costs here used to saturate the buffer at
        // 0.85% across most volatile markets and suppressed otherwise positive
        // post-cost opportunities.
        $forecastBuffer = self::forecastUncertaintyBuffer(
            $spreadCost,
            $liquiditySlippageReserve,
            $adverseFlowReserve,
            $volatility,
            $disagreementPenalty,
            $exhaustionPenalty
        );
        $edgeBuffer = (float) $forecastBuffer['total_percent'];
        $tradableNetEdge = $netEdge - $edgeBuffer;
"""
if old not in text:
    raise SystemExit('Profit-First edge-buffer block not found')
text = text.replace(old, new, 1)
text = text.replace("'decision_model'=>'profit_first_net_edge_v5_restored'", "'decision_model'=>'profit_first_net_edge_v5_uncertainty_buffer_v2'", 2)
text = text.replace("'source'=>'nobitex_profit_first_v5_shadow_multi_strategy_v1'", "'source'=>'nobitex_profit_first_v5_uncertainty_buffer_v2_shadow_multi_strategy_v1'", 2)
old = """            'minimum_net_edge_percent'=>round($edgeBuffer, 4),
            'market_regime'=>$marketRegime,
"""
new = """            'minimum_net_edge_percent'=>round($edgeBuffer, 4),
            'forecast_uncertainty_buffer'=>$forecastBuffer,
            'market_regime'=>$marketRegime,
"""
if old not in text:
    raise SystemExit('Profit-First return buffer insertion point not found')
text = text.replace(old, new, 1)
old = """                'adaptive_forecast_buffer_percent'=>round($edgeBuffer, 4),
                'regime_uncertainty_buffer_percent'=>0.0,
"""
new = """                'adaptive_forecast_buffer_percent'=>round($edgeBuffer, 4),
                'forecast_buffer_model'=>'uncertainty_only_v2',
                'forecast_buffer_base_percent'=>$forecastBuffer['base_percent'],
                'forecast_buffer_friction_uncertainty_percent'=>$forecastBuffer['friction_uncertainty_percent'],
                'forecast_buffer_volatility_uncertainty_percent'=>$forecastBuffer['volatility_uncertainty_percent'],
                'forecast_buffer_disagreement_uncertainty_percent'=>$forecastBuffer['disagreement_uncertainty_percent'],
                'forecast_buffer_exhaustion_uncertainty_percent'=>$forecastBuffer['exhaustion_uncertainty_percent'],
                'regime_uncertainty_buffer_percent'=>0.0,
"""
if old not in text:
    raise SystemExit('Profit-First cost-model buffer block not found')
text = text.replace(old, new, 1)
marker = """    private function baseRoundtripFeePercent(array $market): float
    {
"""
helper = """    /**
     * Residual model/forecast uncertainty after explicit execution costs have
     * already been deducted from gross edge. Kept public for deterministic
     * regression tests and forensic diagnostics.
     *
     * @return array{model:string,base_percent:float,friction_uncertainty_percent:float,volatility_uncertainty_percent:float,disagreement_uncertainty_percent:float,exhaustion_uncertainty_percent:float,total_percent:float}
     */
    public static function forecastUncertaintyBuffer(
        float $spreadCost,
        float $liquidityReserve,
        float $adverseFlowReserve,
        float $volatility,
        float $disagreementPenalty,
        float $exhaustionPenalty
    ): array {
        $spreadCost = max(0.0, $spreadCost);
        $liquidityReserve = max(0.0, $liquidityReserve);
        $adverseFlowReserve = max(0.0, $adverseFlowReserve);
        $volatility = max(0.0, $volatility);
        $disagreementPenalty = max(0.0, $disagreementPenalty);
        $exhaustionPenalty = max(0.0, $exhaustionPenalty);

        $base = 0.12;
        $friction = min(0.12,
            ($spreadCost * 0.10)
            + ($liquidityReserve * 0.10)
            + ($adverseFlowReserve * 0.10)
        );
        $volatilityUncertainty = min(0.18, sqrt($volatility) * 0.07);
        $disagreement = min(0.12, $disagreementPenalty * 0.50);
        $exhaustion = min(0.08, $exhaustionPenalty * 0.25);
        $total = max(
            self::MIN_EDGE_BUFFER_PERCENT,
            min(self::MAX_EDGE_BUFFER_PERCENT, $base + $friction + $volatilityUncertainty + $disagreement + $exhaustion)
        );

        return [
            'model'=>'uncertainty_only_v2',
            'base_percent'=>round($base, 4),
            'friction_uncertainty_percent'=>round($friction, 4),
            'volatility_uncertainty_percent'=>round($volatilityUncertainty, 4),
            'disagreement_uncertainty_percent'=>round($disagreement, 4),
            'exhaustion_uncertainty_percent'=>round($exhaustion, 4),
            'total_percent'=>round($total, 4),
        ];
    }

""" + marker
if marker not in text:
    raise SystemExit('Profit-First helper insertion marker not found')
text = text.replace(marker, helper, 1)
# notReady should expose the same field shape.
old = """            'minimum_net_edge_percent'=>0.0,
            'market_regime'=>['regime'=>'uncertain','confidence'=>0,'entry_enabled'=>false,'metrics'=>['reason'=>$reason]],
"""
new = """            'minimum_net_edge_percent'=>0.0,
            'forecast_uncertainty_buffer'=>[
                'model'=>'uncertainty_only_v2','base_percent'=>0.0,'friction_uncertainty_percent'=>0.0,
                'volatility_uncertainty_percent'=>0.0,'disagreement_uncertainty_percent'=>0.0,
                'exhaustion_uncertainty_percent'=>0.0,'total_percent'=>0.0,
            ],
            'market_regime'=>['regime'=>'uncertain','confidence'=>0,'entry_enabled'=>false,'metrics'=>['reason'=>$reason]],
"""
if old not in text:
    raise SystemExit('Profit-First notReady buffer insertion point not found')
text = text.replace(old, new, 1)
engine.write_text(text)

# 2) Strategy learning must actually learn the live primary strategy.
learning = Path('backend/src/Trading/NobitexStrategyLearning.php')
text = learning.read_text()
old = """    private const STRATEGIES = [
        'trend_momentum_v1',
        'breakout_v1',
        'mean_reversion_v1',
    ];
"""
new = """    private const STRATEGIES = [
        'profit_first_v5',
        'trend_momentum_v1',
        'breakout_v1',
        'mean_reversion_v1',
    ];
"""
if old not in text:
    raise SystemExit('Strategy learning strategy list not found')
text = text.replace(old, new, 1)
old = """        if (!in_array($strategy, self::STRATEGIES, true)) {
"""
new = """        if (!self::supportsStrategy($strategy)) {
"""
if old not in text:
    raise SystemExit('Strategy learning applicability gate not found')
text = text.replace(old, new, 1)
marker = """    public static function strategyKey(array $signal): string
    {
"""
helper = """    public static function supportsStrategy(string $strategy): bool
    {
        return in_array(trim($strategy), self::STRATEGIES, true);
    }

""" + marker
if marker not in text:
    raise SystemExit('Strategy learning helper marker not found')
text = text.replace(marker, helper, 1)
learning.write_text(text)

# 3) Remove the stale legacy name from configured hard-cap diagnostics.
global_risk = Path('backend/src/Trading/NobitexGlobalRiskRuntime.php')
text = global_risk.read_text()
old = """    private function entryBlockReason(PDO $pdo,array $valuation,float $capacityRls,int $active,int $effectiveMax):?string
    {
        if($this->entryCircuitOpen($pdo))return'runtime_entry_circuit_open';
        if($active>=$effectiveMax)return'effective_position_capacity_reached';
"""
new = """    private function entryBlockReason(PDO $pdo,array $valuation,float $capacityRls,int $active,int $configuredMax):?string
    {
        if($this->entryCircuitOpen($pdo))return'runtime_entry_circuit_open';
        if($active>=$configuredMax)return'configured_position_capacity_reached';
"""
if old not in text:
    raise SystemExit('Global-risk legacy capacity reason block not found')
text = text.replace(old, new, 1)
global_risk.write_text(text)

# 4) Carry full economic diagnostics into no-trade candidate summaries.
portfolio = Path('backend/src/Trading/NobitexPortfolioEngine.php')
text = portfolio.read_text()
old = """                'market_regime'=>$signal['market_regime']['regime'] ?? null,
                'expected_net_edge_percent'=>$signal['expected_net_edge_percent'] ?? null,
                'tradable_net_edge_percent'=>$signal['tradable_net_edge_percent'] ?? null,
                'required_edge_buffer_percent'=>$signal['required_edge_buffer_percent'] ?? null,
                'spread_percent'=>$c['spread_percent'] ?? null,
"""
new = """                'market_regime'=>$signal['market_regime']['regime'] ?? null,
                'expected_gross_move_percent'=>$signal['expected_gross_move_percent'] ?? null,
                'estimated_roundtrip_cost_percent'=>$signal['estimated_roundtrip_cost_percent'] ?? null,
                'expected_net_edge_percent'=>$signal['expected_net_edge_percent'] ?? null,
                'tradable_net_edge_percent'=>$signal['tradable_net_edge_percent'] ?? null,
                'required_edge_buffer_percent'=>$signal['required_edge_buffer_percent'] ?? null,
                'forecast_uncertainty_buffer'=>is_array($signal['forecast_uncertainty_buffer'] ?? null) ? $signal['forecast_uncertainty_buffer'] : [],
                'volatility_percent'=>$signal['indicators']['volatility_percent'] ?? null,
                'liquidity_multiple'=>$signal['execution_quality']['liquidity_multiple'] ?? null,
                'orderbook_imbalance'=>$signal['execution_quality']['orderbook_imbalance'] ?? null,
                'dynamic_max_spread_percent'=>$signal['execution_quality']['dynamic_max_spread_percent'] ?? null,
                'spread_percent'=>$c['spread_percent'] ?? null,
"""
if old not in text:
    raise SystemExit('Portfolio candidate summary block not found')
text = text.replace(old, new, 1)
portfolio.write_text(text)

# 5) Decision reporter: show why edge was consumed instead of only the final number.
reporter = Path('backend/src/Observability/NobitexDecisionReporter.php')
text = reporter.read_text()
old = """                    . ' | Regime=' . (string)($candidate['market_regime'] ?? '—')
                    . ' | NetEdge=' . self::pct($candidate['expected_net_edge_percent'] ?? null)
                    . ' | TradableEdge=' . self::pct($candidate['tradable_net_edge_percent'] ?? null)
                    . ' | RequiredBuffer=' . self::pct($candidate['required_edge_buffer_percent'] ?? null)
                    . ' | Spread=' . self::pct($candidate['spread_percent'] ?? null)
                    . ' | نتیجه=رد'
"""
new = """                    . ' | Regime=' . (string)($candidate['market_regime'] ?? '—')
                    . ' | Gross=' . self::pct($candidate['expected_gross_move_percent'] ?? null)
                    . ' | Cost=' . self::pct($candidate['estimated_roundtrip_cost_percent'] ?? null)
                    . ' | NetEdge=' . self::pct($candidate['expected_net_edge_percent'] ?? null)
                    . ' | Buffer=' . self::pct($candidate['required_edge_buffer_percent'] ?? null)
                    . ' | TradableEdge=' . self::pct($candidate['tradable_net_edge_percent'] ?? null)
                    . ' | Spread=' . self::pct($candidate['spread_percent'] ?? null)
                    . ' | Vol=' . self::pct($candidate['volatility_percent'] ?? null)
                    . ' | Liq×=' . self::num($candidate['liquidity_multiple'] ?? '—', 2)
                    . ' | نتیجه=رد'
"""
if old not in text:
    raise SystemExit('Decision reporter candidate line block not found')
text = text.replace(old, new, 1)
# Append decomposed buffer parts when available.
old = """                foreach ([
                    'strategy_learning_multiplier'=>'Learning×',
"""
new = """                $buffer = is_array($candidate['forecast_uncertainty_buffer'] ?? null) ? $candidate['forecast_uncertainty_buffer'] : [];
                if ($buffer !== []) {
                    $line .= ' | BufferParts='
                        . 'base:' . self::pct($buffer['base_percent'] ?? null)
                        . ',friction:' . self::pct($buffer['friction_uncertainty_percent'] ?? null)
                        . ',vol:' . self::pct($buffer['volatility_uncertainty_percent'] ?? null)
                        . ',dis:' . self::pct($buffer['disagreement_uncertainty_percent'] ?? null)
                        . ',exh:' . self::pct($buffer['exhaustion_uncertainty_percent'] ?? null);
                }

                foreach ([
                    'strategy_learning_multiplier'=>'Learning×',
"""
if old not in text:
    raise SystemExit('Decision reporter buffer-parts insertion point not found')
text = text.replace(old, new, 1)
text = text.replace(
    "'edge_below_adaptive_safety_buffer' => 'پیش‌بینی حرکت پس از کارمزد، Spread، Slippage و حاشیه خطای تطبیقی Edge مثبت کافی برای BUY نساخته است.',",
    "'edge_below_adaptive_safety_buffer' => 'بعد از کسر هزینه‌های واقعی اجرا، حاشیه باقی‌مانده از Buffer عدم‌قطعیت پیش‌بینی عبور نکرده است.',",
    1,
)
needle = """            'pending_order_capacity_reached' => 'ظرفیت سفارش‌های Pending پر است و ورود تازه تا تعیین تکلیف آن‌ها متوقف است.',
"""
replacement = needle + """            'configured_position_capacity_reached' => 'تعداد پوزیشن‌های فعال به سقف واقعی تنظیم‌شده کاربر رسیده است.',
            'effective_position_capacity_reached' => 'نام قدیمی محدودیت ظرفیت است؛ در نسخه جدید Adaptive فقط حجم خرید را نرم کاهش می‌دهد و سقف سخت همان مقدار تنظیم‌شده است.',
"""
if needle not in text:
    raise SystemExit('Decision reporter capacity reason insertion point not found')
text = text.replace(needle, replacement, 1)
text = text.replace(
    "'نکته: تصمیم اصلی BUY دوباره Profit-First است؛ Regime/Strategy فقط Shadow diagnostics هستند. ورود با Edge قابل معامله مثبت بعد از کارمزد/Spread/Slippage/Buffer و سپس Risk/Balance/Capacity تعیین می‌شود.';",
    "'نکته: Profit-First ابتدا هزینه‌های صریح اجرا را از Gross کم می‌کند؛ Buffer جدید فقط عدم‌قطعیت باقی‌مانده مدل را پوشش می‌دهد و هزینه‌ها را دوباره شارژ نمی‌کند. سپس Risk/Balance/Capacity بررسی می‌شود.';",
    1,
)
reporter.write_text(text)

# 6) Canonical runtime model names.
models = Path('backend/src/Trading/NobitexRuntimeModels.php')
text = models.read_text()
text = text.replace("public const DECISION = 'profit_first_net_edge_v5_restored';", "public const DECISION = 'profit_first_net_edge_v5_uncertainty_buffer_v2';", 1)
text = text.replace("public const SELECTION = 'positive_tradable_net_edge_after_costs_and_buffer_v5';", "public const SELECTION = 'positive_tradable_net_edge_after_explicit_costs_uncertainty_buffer_v2';", 1)
models.write_text(text)

# 7) Regression tests for the exact bugs found in /debug.
test = Path('backend/tests/nobitex_full_debug_v1422_test.php')
test.write_text(r'''<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Trade\Trading\NobitexInternalSignalEngine;
use Trade\Trading\NobitexStrategyLearning;

function assertDebug1422(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$low = NobitexInternalSignalEngine::forecastUncertaintyBuffer(0.05, 0.02, 0.0, 0.20, 0.0, 0.0);
$high = NobitexInternalSignalEngine::forecastUncertaintyBuffer(1.50, 0.35, 0.20, 8.0, 0.40, 0.30);
assertDebug1422(($low['model'] ?? '') === 'uncertainty_only_v2', 'forecast buffer model id mismatch');
assertDebug1422((float)$low['total_percent'] >= 0.12, 'low uncertainty buffer must retain a positive floor');
assertDebug1422((float)$high['total_percent'] > (float)$low['total_percent'], 'higher uncertainty should require a larger buffer');
assertDebug1422((float)$high['total_percent'] <= 0.55, 'forecast-only buffer must not return to the old 0.85% saturation');

assertDebug1422(NobitexStrategyLearning::supportsStrategy('profit_first_v5'), 'live Profit-First strategy must participate in learning/calibration');
assertDebug1422(NobitexStrategyLearning::supportsStrategy('breakout_v1'), 'legacy strategy learning must remain compatible');

$global = file_get_contents(dirname(__DIR__) . '/src/Trading/NobitexGlobalRiskRuntime.php');
assertDebug1422(is_string($global) && str_contains($global, "return'configured_position_capacity_reached'"), 'configured hard-cap diagnostics must use the configured-cap reason');

$portfolio = file_get_contents(dirname(__DIR__) . '/src/Trading/NobitexPortfolioEngine.php');
foreach (['expected_gross_move_percent','estimated_roundtrip_cost_percent','forecast_uncertainty_buffer','volatility_percent','liquidity_multiple'] as $needle) {
    assertDebug1422(is_string($portfolio) && str_contains($portfolio, "'{$needle}'"), 'candidate diagnostics missing ' . $needle);
}

$reporter = file_get_contents(dirname(__DIR__) . '/src/Observability/NobitexDecisionReporter.php');
assertDebug1422(is_string($reporter) && str_contains($reporter, 'BufferParts='), 'Bale forensic report must expose buffer decomposition');
assertDebug1422(str_contains((string)$reporter, "'configured_position_capacity_reached'"), 'Bale report must explain configured hard-cap reason');

echo "Trade 1.4.22 full debug regression tests passed.\n";
''')

# Add the regression to the standard quality gate.
workflow = Path('.github/workflows/php-lint.yml')
wf = workflow.read_text()
marker = "      - name: Nobitex adaptive soft-capacity regression tests\n        run: php backend/tests/nobitex_soft_capacity_test.php\n"
addition = marker + "      - name: Trade 1.4.22 full debug regression tests\n        run: php backend/tests/nobitex_full_debug_v1422_test.php\n"
if 'Trade 1.4.22 full debug regression tests' not in wf:
    if marker not in wf:
        raise SystemExit('Quality-gate insertion point not found')
    workflow.write_text(wf.replace(marker, addition, 1))
