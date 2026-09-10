<?php

declare(strict_types=1);

namespace Trade\Trading;

use PDO;
use Trade\Database;

/**
 * Score-free Nobitex orchestration entry point.
 *
 * PortfolioEngine remains responsible for risk controls and order state. This
 * coordinator lets one cron cycle continue through multiple immediately-filled
 * profitable actions while reusing the same full-universe analysis cache.
 *
 * Fee accounting runs before and after trading so exits see the latest entry fee,
 * mark price and trailing-profit floor, while completed orders are converted from
 * gross PnL to net PnL after actual/estimated exchange fees.
 */
final class NobitexAutoTraderEngine
{
    private const MAX_ACTIONS_PER_TICK = 20;

    public function run(): array
    {
        NobitexUniverseScanner::resetProcessCache();
        $accounting = new NobitexTradeAccounting();
        $accountingBefore = $this->safeAccountingSync($accounting);

        $actions = [];
        $last = null;
        $accountingAfter = null;

        for ($i = 0; $i < self::MAX_ACTIONS_PER_TICK; $i++) {
            $last = (new NobitexPortfolioEngine())->run();
            $accountingAfter = $this->safeAccountingSync($accounting);
            $status = (string) ($last['status'] ?? 'unknown');

            if (in_array($status, ['buy_submitted','sell_submitted'], true)) {
                $actions[] = $this->stripLegacyScores($last);
                continue;
            }
            break;
        }

        if ($actions === []) {
            $result = $this->stripLegacyScores(is_array($last) ? $last : [
                'status'=>'no_trade',
                'exchange'=>'nobitex',
                'reason'=>'no_actionable_profit',
            ]);
            $result['accounting'] = ['before'=>$accountingBefore,'after'=>$accountingAfter];
            return $result;
        }

        if (count($actions) === 1 && is_array($last) && in_array((string) ($last['status'] ?? ''), ['waiting_order','portfolio_full'], true)) {
            $one = $actions[0];
            $one['continuation_status'] = $last['status'];
            $one['accounting'] = ['before'=>$accountingBefore,'after'=>$accountingAfter];
            return $one;
        }

        return [
            'status'=>'profit_actions_processed',
            'exchange'=>'nobitex',
            'decision_model'=>'net_edge_after_costs_and_adaptive_forecast_buffer',
            'score_based_selection'=>false,
            'actions_count'=>count($actions),
            'actions'=>$actions,
            'continuation'=>$this->stripLegacyScores(is_array($last) ? $last : []),
            'accounting'=>['before'=>$accountingBefore,'after'=>$accountingAfter],
        ];
    }

    /**
     * Retires the old score-gated first-buy path. If an older installation still
     * has that one-shot flag armed, clear it and allow the normal profit-first
     * universe engine to run in the same cron tick.
     */
    public function runBootstrapIfPending(): array
    {
        NobitexSchema::ensure();
        $pdo = Database::connection();

        if (!$this->settingIsTrue($pdo, 'nobitex_bootstrap_first_buy_pending')) {
            return ['status'=>'not_pending','exchange'=>'nobitex'];
        }

        Database::transaction(function (PDO $tx): void {
            $this->writeSetting($tx, 'nobitex_bootstrap_first_buy_pending', '0');
            $this->writeSetting($tx, 'nobitex_bootstrap_retired_at', gmdate('Y-m-d H:i:s'));
            $this->writeSetting($tx, 'nobitex_selection_model', 'net_edge_after_costs_and_adaptive_forecast_buffer');
        });

        return [
            'status'=>'not_pending',
            'exchange'=>'nobitex',
            'bootstrap'=>'retired_score_gate',
            'selection_model'=>'net_edge_after_costs_and_adaptive_forecast_buffer',
        ];
    }

    private function safeAccountingSync(NobitexTradeAccounting $accounting): array
    {
        try {
            return ['status'=>'ok'] + $accounting->sync();
        } catch (\Throwable $e) {
            return ['status'=>'deferred','error'=>mb_substr($e->getMessage(),0,240)];
        }
    }

    private function settingIsTrue(PDO $pdo, string $key): bool
    {
        $stmt = $pdo->prepare('SELECT value_text FROM settings WHERE key_name=:key LIMIT 1');
        $stmt->execute([':key'=>$key]);
        return in_array(strtolower(trim((string) ($stmt->fetchColumn() ?: '0'))), ['1','true','yes','on'], true);
    }

    private function writeSetting(PDO $pdo, string $key, string $value): void
    {
        $stmt = $pdo->prepare(
            "INSERT INTO settings (key_name,value_text,updated_at) VALUES (:key,:value,UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE value_text=VALUES(value_text),updated_at=UTC_TIMESTAMP()"
        );
        $stmt->execute([':key'=>$key, ':value'=>$value]);
    }

    private function stripLegacyScores(array $value): array
    {
        foreach (['score','signal_score','opportunity_score','minimum_score'] as $key) {
            unset($value[$key]);
        }
        foreach ($value as $key => $item) {
            if (is_array($item)) $value[$key] = $this->stripLegacyScores($item);
        }
        return $value;
    }
}
