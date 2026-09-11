from pathlib import Path

engine = Path('backend/src/Trading/NobitexPortfolioEngine.php')
text = engine.read_text()

old_regular = """            $reference = (float) (($market['best_ask'] ?? 0) > 0 ? $market['best_ask'] : $market['price']);
            $priceCeiling = $reference * 1.008;
            $amount = $this->floorAmount(((float) $decision['budget']) / $priceCeiling, (int) $market['base_precision']);
            $orderValue = $amount * $priceCeiling;
            $minOrder = (float) ($market['min_order_quote'] ?? 0);
            if ($amount <= 0 || $orderValue <= 0 || ($minOrder > 0 && $orderValue + 0.000001 < $minOrder)) {
                $rejections[] = ['symbol'=>$symbol,'reason'=>'minimum_order_rounding'];
                continue;
            }
"""
new_regular = """            $reference = (float) (($market['best_ask'] ?? 0) > 0 ? $market['best_ask'] : $market['price']);
            $priceCeiling = $reference * 1.008;
            $sizing = NobitexOrderSizing::forEntry(
                (float) $decision['budget'],
                (float) ($decision['hard_entry_cap'] ?? $decision['budget']),
                $priceCeiling,
                (float) ($market['min_order_quote'] ?? 0),
                (int) $market['base_precision']
            );
            if (!($sizing['allowed'] ?? false)) {
                $rejections[] = [
                    'symbol'=>$symbol,
                    'reason'=>'minimum_order_rounding',
                    'sizing_reason'=>$sizing['reason'] ?? null,
                    'budget'=>$decision['budget'] ?? null,
                    'hard_entry_cap'=>$decision['hard_entry_cap'] ?? null,
                    'required_order_value'=>$sizing['required_order_value'] ?? null,
                ];
                continue;
            }
            $amount = (float) $sizing['amount'];
"""
if old_regular not in text:
    raise SystemExit('regular entry sizing block not found')
text = text.replace(old_regular, new_regular, 1)

old_bootstrap = """        $reference = (float) (($chosen['best_ask'] ?? 0) > 0 ? $chosen['best_ask'] : $chosen['price']);
        $priceCeiling = $reference * 1.008;
        $amount = $this->floorAmount(((float) $budget['budget']) / $priceCeiling, (int) $chosen['base_precision']);
        $minOrder = (float) ($chosen['min_order_quote'] ?? 0);
        $value = $amount * $priceCeiling;
        if ($amount <= 0 || ($minOrder > 0 && $value + 0.000001 < $minOrder)) {
            return $this->bootstrapBlocked($pdo, 'minimum_order_rounding', ['order_value'=>$value,'minimum_order'=>$minOrder]);
        }
"""
new_bootstrap = """        $reference = (float) (($chosen['best_ask'] ?? 0) > 0 ? $chosen['best_ask'] : $chosen['price']);
        $priceCeiling = $reference * 1.008;
        $sizing = NobitexOrderSizing::forEntry(
            (float) $budget['budget'],
            (float) ($budget['hard_entry_cap'] ?? $budget['budget']),
            $priceCeiling,
            (float) ($chosen['min_order_quote'] ?? 0),
            (int) $chosen['base_precision']
        );
        if (!($sizing['allowed'] ?? false)) {
            return $this->bootstrapBlocked($pdo, 'minimum_order_rounding', [
                'sizing_reason'=>$sizing['reason'] ?? null,
                'budget'=>$budget['budget'] ?? null,
                'hard_entry_cap'=>$budget['hard_entry_cap'] ?? null,
                'required_order_value'=>$sizing['required_order_value'] ?? null,
                'minimum_order'=>$chosen['min_order_quote'] ?? 0,
            ]);
        }
        $amount = (float) $sizing['amount'];
"""
if old_bootstrap not in text:
    raise SystemExit('bootstrap sizing block not found')
text = text.replace(old_bootstrap, new_bootstrap, 1)

old_budget = """        $desired = $portfolio * ($effectivePerPositionPct / 100.0);
        $perPositionCap = $portfolio * ((float) $settings['max_position_percent'] / 100.0);
        $minimum = max(0.0, (float) ($market['min_order_quote'] ?? 0));
"""
new_budget = """        $desired = $portfolio * ($effectivePerPositionPct / 100.0);
        $perPositionCap = $portfolio * ((float) $settings['max_position_percent'] / 100.0);
        $hardEntryCap = min($perPositionCap, $capacity, $quoteAvailable * 0.985);
        $context['hard_entry_cap'] = round($hardEntryCap, 8);
        $minimum = max(0.0, (float) ($market['min_order_quote'] ?? 0));
"""
if old_budget not in text:
    raise SystemExit('entry budget cap block not found')
text = text.replace(old_budget, new_budget, 1)

old_budget_line = "        $budget = min(max($desired, $minimumWithMargin), $perPositionCap, $capacity, $quoteAvailable * 0.985);"
new_budget_line = "        $budget = min(max($desired, $minimumWithMargin), $hardEntryCap);"
if old_budget_line not in text:
    raise SystemExit('entry budget calculation not found')
text = text.replace(old_budget_line, new_budget_line, 1)
engine.write_text(text)

Path('backend/src/Trading/NobitexOrderSizing.php').write_text(r'''<?php

declare(strict_types=1);

namespace Trade\Trading;

/**
 * Converts an approved quote budget into an exchange-valid base amount.
 * Normal sizing remains floor-rounded. If one precision step makes an otherwise
 * valid BUY miss the exchange minimum, it may round up exactly one step while
 * remaining inside the portfolio engine's hard risk/balance cap.
 */
final class NobitexOrderSizing
{
    public static function forEntry(float $budget, float $hardCap, float $price, float $minimumOrder, int $precision): array
    {
        $budget = max(0.0, $budget);
        $hardCap = max(0.0, $hardCap);
        $price = max(0.0, $price);
        $minimumOrder = max(0.0, $minimumOrder);
        $precision = max(0, min(18, $precision));

        if (!is_finite($budget) || !is_finite($hardCap) || !is_finite($price) || !is_finite($minimumOrder) || $price <= 0.0 || $hardCap <= 0.0) {
            return ['allowed'=>false,'reason'=>'invalid_sizing_inputs'];
        }

        $effectiveBudget = min($budget, $hardCap);
        $factor = 10 ** $precision;
        $step = 1.0 / $factor;
        $targetAmount = $effectiveBudget / $price;
        $floorAmount = floor(($targetAmount * $factor) + 1.0e-12) / $factor;
        $floorValue = $floorAmount * $price;
        $epsilon = max(1.0e-6, $hardCap * 1.0e-12);

        if ($floorAmount > 0.0 && ($minimumOrder <= 0.0 || $floorValue + $epsilon >= $minimumOrder)) {
            return ['allowed'=>true,'reason'=>'floor_amount_valid','amount'=>$floorAmount,'order_value'=>$floorValue,'rounded_up'=>false,'precision_step'=>$step];
        }
        if ($minimumOrder <= 0.0) {
            return ['allowed'=>false,'reason'=>'rounded_amount_is_zero','required_order_value'=>0.0];
        }

        $minimumAmount = ceil((($minimumOrder / $price) * $factor) - 1.0e-12) / $factor;
        $minimumValue = $minimumAmount * $price;
        if ($minimumAmount > $floorAmount + $step + 1.0e-12) {
            return ['allowed'=>false,'reason'=>'minimum_order_requires_more_than_one_precision_step','required_order_value'=>$minimumValue,'floor_order_value'=>$floorValue,'precision_step'=>$step];
        }
        if ($minimumValue > $hardCap + $epsilon) {
            return ['allowed'=>false,'reason'=>'minimum_order_rounding_exceeds_hard_cap','required_order_value'=>$minimumValue,'hard_entry_cap'=>$hardCap,'precision_step'=>$step];
        }

        return ['allowed'=>true,'reason'=>'rounded_up_one_step_to_exchange_minimum','amount'=>$minimumAmount,'order_value'=>$minimumValue,'rounded_up'=>true,'planned_budget'=>$budget,'hard_entry_cap'=>$hardCap,'precision_step'=>$step];
    }
}
''')

Path('backend/tests/nobitex_minimum_order_rounding_test.php').write_text(r'''<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Trade\Trading\NobitexOrderSizing;

function assertMinimumSizing(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$recoverable = NobitexOrderSizing::forEntry(51_500.0, 60_000.0, 5_500.0, 50_000.0, 0);
assertMinimumSizing(($recoverable['allowed'] ?? false) === true, 'one-step minimum-order rounding should be recoverable');
assertMinimumSizing(($recoverable['rounded_up'] ?? false) === true, 'recoverable sizing should report the upward precision step');
assertMinimumSizing(abs((float)($recoverable['amount'] ?? 0.0) - 10.0) < 1e-9, 'amount should rise exactly one integer precision step');
assertMinimumSizing((float)($recoverable['order_value'] ?? 0.0) >= 50_000.0, 'rounded order must satisfy the exchange minimum');

$capBlocked = NobitexOrderSizing::forEntry(51_500.0, 53_000.0, 5_500.0, 50_000.0, 0);
assertMinimumSizing(($capBlocked['allowed'] ?? true) === false, 'round-up must remain blocked when it would exceed the hard cap');
assertMinimumSizing(($capBlocked['reason'] ?? '') === 'minimum_order_rounding_exceeds_hard_cap', 'hard-cap rejection reason should be explicit');

$alreadyValid = NobitexOrderSizing::forEntry(55_000.0, 60_000.0, 5_500.0, 50_000.0, 1);
assertMinimumSizing(($alreadyValid['allowed'] ?? false) === true, 'already-valid floor sizing should remain valid');
assertMinimumSizing(($alreadyValid['rounded_up'] ?? true) === false, 'already-valid sizing must not be rounded upward');

$multiStep = NobitexOrderSizing::forEntry(10_000.0, 60_000.0, 5_500.0, 50_000.0, 0);
assertMinimumSizing(($multiStep['allowed'] ?? true) === false, 'helper must not make a multi-step sizing jump');

$engine = file_get_contents(dirname(__DIR__) . '/src/Trading/NobitexPortfolioEngine.php');
assertMinimumSizing(is_string($engine) && substr_count($engine, 'NobitexOrderSizing::forEntry(') >= 2, 'portfolio and bootstrap paths must both use safe minimum-order sizing');
assertMinimumSizing(str_contains((string)$engine, "'hard_entry_cap'"), 'entry budget must expose the hard entry cap');

echo "Nobitex minimum-order rounding regression tests passed.\n";
''')

workflow = Path('.github/workflows/php-lint.yml')
wf = workflow.read_text()
marker = "      - name: Nobitex order value guard regression tests\n        run: php backend/tests/nobitex_order_value_guard_test.php\n"
addition = marker + "      - name: Nobitex minimum-order rounding regression tests\n        run: php backend/tests/nobitex_minimum_order_rounding_test.php\n"
if 'Nobitex minimum-order rounding regression tests' not in wf:
    if marker not in wf:
        raise SystemExit('quality-gate insertion point not found')
    workflow.write_text(wf.replace(marker, addition, 1))
