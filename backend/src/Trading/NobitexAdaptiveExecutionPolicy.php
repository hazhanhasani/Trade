<?php

declare(strict_types=1);

namespace Trade\Trading;

use PDO;

/**
 * Reduction-only adaptive execution policy.
 *
 * Historical execution quality and external market consensus may tighten a
 * fresh BUY plan, but can never loosen its hard price bound, lower the required
 * edge, manufacture positive edge or promote Limit to Market. Safety exits are
 * intentionally outside this policy.
 */
final class NobitexAdaptiveExecutionPolicy
{
    // Preserve the stable runtime/release identifier. External consensus is an
    // additional guard inside v1, not a replacement execution contract.
    public const MODEL = 'adaptive_execution_policy_v1';

    public function apply(PDO $pdo, array $market, array $signal, array $plan): array
    {
        $learning = (new NobitexExecutionLearning())->assessSignal($pdo, $signal, $market);
        $penalty = max(0.0, min(0.45, (float)($learning['execution_penalty_percent'] ?? 0.0)));
        $rawEdge = is_numeric($signal['tradable_net_edge_percent'] ?? null)
            ? (float)$signal['tradable_net_edge_percent']
            : 0.0;

        $strategyLearning = (new NobitexStrategyLearning())->assessSignal($pdo, $signal);
        if (!($strategyLearning['allowed'] ?? false)) {
            return [
                'allowed'=>false,
                'reason'=>(string)($strategyLearning['reason'] ?? 'strategy_learning_blocked'),
                'model'=>self::MODEL,
                'plan'=>$plan,
                'strategy_learning'=>$strategyLearning,
                'execution_learning'=>$learning,
            ];
        }

        $calibratedEdge = is_numeric($strategyLearning['calibrated_tradable_net_edge_percent'] ?? null)
            ? (float)$strategyLearning['calibrated_tradable_net_edge_percent']
            : $rawEdge;

        // External venues are read-only intelligence. Their evidence is allowed
        // to consume edge or veto an extreme Nobitex premium, never to add edge.
        try { $external = (new NobitexExternalMarketOracle())->assess($market); }
        catch (\Throwable $e) {
            $external = [
                'available'=>false,'quality_ready'=>false,'hard_block'=>false,
                'reason'=>'external_market_oracle_error','penalty_percent'=>0.0,
                'error'=>mb_substr($e->getMessage(),0,220),'model'=>NobitexExternalMarketOracle::MODEL,
            ];
        }
        $externalPenalty = max(0.0, min(0.30, (float)($external['penalty_percent'] ?? 0.0)));
        if (($external['hard_block'] ?? false) === true) {
            return [
                'allowed'=>false,
                'reason'=>(string)($external['reason'] ?? 'external_market_consensus_blocked'),
                'model'=>self::MODEL,
                'external_market_model'=>NobitexExternalMarketOracle::MODEL,
                'raw_tradable_net_edge_percent'=>round($rawEdge,4),
                'calibrated_tradable_net_edge_percent'=>round($calibratedEdge,4),
                'execution_penalty_percent'=>round($penalty,4),
                'external_market_penalty_percent'=>round($externalPenalty,4),
                'effective_tradable_net_edge_percent'=>round($calibratedEdge-$penalty-$externalPenalty,4),
                'plan'=>$plan,'strategy_learning'=>$strategyLearning,
                'execution_learning'=>$learning,'external_market'=>$external,
            ];
        }

        $effectiveEdge = $calibratedEdge - $penalty - $externalPenalty;
        if ($rawEdge <= 0.0 || $calibratedEdge <= 0.0 || $effectiveEdge <= 0.0) {
            return [
                'allowed'=>false,
                'reason'=>$externalPenalty > 0.0 ? 'external_market_and_execution_edge_consumed' : 'adaptive_execution_edge_consumed',
                'model'=>self::MODEL,
                'external_market_model'=>NobitexExternalMarketOracle::MODEL,
                'raw_tradable_net_edge_percent'=>round($rawEdge, 4),
                'calibrated_tradable_net_edge_percent'=>round($calibratedEdge, 4),
                'execution_penalty_percent'=>round($penalty, 4),
                'external_market_penalty_percent'=>round($externalPenalty, 4),
                'effective_tradable_net_edge_percent'=>round($effectiveEdge, 4),
                'plan'=>$plan,
                'strategy_learning'=>$strategyLearning,
                'execution_learning'=>$learning,
                'external_market'=>$external,
            ];
        }

        $hardened = $plan;
        $forcedLimit = false;
        $profile = is_array($learning['profile'] ?? null) ? $learning['profile'] : [];
        $p75 = max(0.0, (float)($profile['p75_slippage_percent'] ?? 0.0));
        $partialRate = max(0.0, min(1.0, (float)($profile['partial_fill_rate'] ?? 0.0)));
        $repriceRate = max(0.0, min(1.0, (float)($profile['reprice_rate'] ?? 0.0)));
        $learningReady = (bool)($learning['learning_ready'] ?? false);

        // If real fills repeatedly consume meaningful edge, or external markets
        // show a measurable premium, a market plan is downgraded to the already
        // computed bounded marketable limit. This is one-way hardening.
        $poorExecution = $learningReady && (
            $penalty >= 0.10
            || $p75 >= 0.14
            || $partialRate >= 0.25
            || $repriceRate >= 0.35
        );
        $externalCaution = ($external['quality_ready'] ?? false) === true && $externalPenalty >= 0.08;
        if (($hardened['mode'] ?? '') === 'market' && ($poorExecution || $externalCaution)) {
            $hardened['mode'] = 'limit';
            $hardened['limit_price'] = self::boundedLimitPrice($hardened);
            $hardened['max_reprices'] = 1;
            $hardened['reprice_policy'] = 'one_bounded_reprice';
            $hardened['reason'] = $externalCaution
                ? 'external_market_consensus_forced_bounded_limit'
                : 'execution_learning_forced_bounded_limit';
            $forcedLimit = true;
        }

        $hardened['adaptive_execution_model'] = self::MODEL;
        $hardened['external_market_model'] = NobitexExternalMarketOracle::MODEL;
        $hardened['execution_learning_penalty_percent'] = round($penalty, 4);
        $hardened['external_market_penalty_percent'] = round($externalPenalty, 4);
        $hardened['effective_tradable_net_edge_percent'] = round($effectiveEdge, 4);
        $hardened['learning_forced_limit'] = $forcedLimit;

        return [
            'allowed'=>true,
            'reason'=>$forcedLimit ? 'execution_plan_hardened' : 'execution_plan_accepted',
            'model'=>self::MODEL,
            'external_market_model'=>NobitexExternalMarketOracle::MODEL,
            'raw_tradable_net_edge_percent'=>round($rawEdge, 4),
            'calibrated_tradable_net_edge_percent'=>round($calibratedEdge, 4),
            'execution_penalty_percent'=>round($penalty, 4),
            'external_market_penalty_percent'=>round($externalPenalty, 4),
            'effective_tradable_net_edge_percent'=>round($effectiveEdge, 4),
            'forced_limit'=>$forcedLimit,
            'plan'=>$hardened,
            'strategy_learning'=>$strategyLearning,
            'execution_learning'=>$learning,
            'external_market'=>$external,
        ];
    }

    /** Pure helper kept public for deterministic regression tests. */
    public static function shouldForceLimit(array $learning): bool
    {
        if (!(bool)($learning['learning_ready'] ?? false)) return false;
        $profile = is_array($learning['profile'] ?? null) ? $learning['profile'] : [];
        $penalty = max(0.0, (float)($learning['execution_penalty_percent'] ?? 0.0));
        $p75 = max(0.0, (float)($profile['p75_slippage_percent'] ?? 0.0));
        $partial = max(0.0, min(1.0, (float)($profile['partial_fill_rate'] ?? 0.0)));
        $reprice = max(0.0, min(1.0, (float)($profile['reprice_rate'] ?? 0.0)));
        return $penalty >= 0.10 || $p75 >= 0.14 || $partial >= 0.25 || $reprice >= 0.35;
    }

    private static function boundedLimitPrice(array $plan): float
    {
        $side = strtolower((string)($plan['side'] ?? 'buy')) === 'sell' ? 'sell' : 'buy';
        $reference = max(0.00000001, (float)($plan['reference_price'] ?? 0.0));
        $hard = max(0.00000001, (float)($plan['hard_price_limit'] ?? $reference));
        $existing = max(0.00000001, (float)($plan['limit_price'] ?? $reference));

        if ($side === 'buy') return min($hard, $existing);
        return max($hard, $existing);
    }
}
