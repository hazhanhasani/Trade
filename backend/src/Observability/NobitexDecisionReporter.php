<?php

declare(strict_types=1);

namespace Trade\Observability;

use Trade\Updater;

/**
 * Converts the Nobitex runtime decision payload into human-readable forensic
 * messages. The full structured decision remains in bot_runs.summary_json;
 * these messages make the most important rejection path visible in Bale.
 */
final class NobitexDecisionReporter
{
    public function report(array $result, ?string $runId = null): void
    {
        $status = (string)($result['status'] ?? 'unknown');
        if ($status !== 'no_trade') return;

        $backendVersion = Updater::currentVersion();
        $reason = (string)($result['reason'] ?? 'unknown');
        $candidates = is_array($result['top_candidates'] ?? null) ? $result['top_candidates'] : [];
        $rejections = is_array($result['rejections'] ?? null) ? $result['rejections'] : [];
        $rejectionMap = [];
        $reasonCounts = [];
        foreach ($rejections as $row) {
            if (!is_array($row)) continue;
            $symbol = strtoupper(trim((string)($row['symbol'] ?? '')));
            $r = (string)($row['reason'] ?? 'unknown');
            if ($symbol !== '' && !isset($rejectionMap[$symbol])) $rejectionMap[$symbol] = $row;
            $reasonCounts[$r] = ($reasonCounts[$r] ?? 0) + 1;
        }

        arsort($reasonCounts);
        $reasonSummary = [];
        foreach ($reasonCounts as $key => $count) $reasonSummary[] = $key . '=' . $count;

        $summary = [
            'Backend: ' . $backendVersion . ' | مدل اصلی BUY: Profit-First v5',
            'تصمیم خرید Nobitex: هیچ سفارش BUY جدیدی ثبت نشد.',
            'علت نهایی: ' . $reason . ' — ' . self::reasonFa($reason),
            'کاندیداهای گزارش‌شده: ' . count($candidates) . ' | ردهای ثبت‌شده: ' . count($rejections),
            'پوزیشن فعال: ' . (string)($result['active_positions'] ?? '—') . '/' . (string)($result['max_positions'] ?? '—')
                . ' | Pending: ' . (string)($result['pending_orders'] ?? '—') . '/' . (string)($result['max_pending_orders'] ?? '—'),
            'محل تصمیم: backend/src/Trading/NobitexInternalSignalEngine.php → Profit-First v5 | سپس NobitexPortfolioEngine.php → entryBudget()',
        ];
        if ($reasonSummary !== []) $summary[] = 'خلاصه دلایل رد: ' . implode(' | ', array_slice($reasonSummary, 0, 8));
        $summary[] = 'نکته: Profit-First ابتدا هزینه‌های صریح اجرا را از Gross کم می‌کند؛ Buffer جدید فقط عدم‌قطعیت باقی‌مانده مدل را پوشش می‌دهد و هزینه‌ها را دوباره شارژ نمی‌کند. سپس Risk/Balance/Capacity بررسی می‌شود.';

        ErrorReporter::log(
            implode("\n", $summary),
            'nobitex_decision_trace',
            [
                'run_id'=>$runId,
                'status'=>$status,
                'exchange'=>'nobitex',
                'symbol'=>$candidates[0]['symbol'] ?? null,
                'decision_reason'=>$reason,
                'candidate_count'=>count($candidates),
                'rejection_count'=>count($rejections),
                'backend_version'=>$backendVersion,
                'primary_entry_model'=>'profit_first_v5',
                'multi_strategy_role'=>'shadow_diagnostics',
            ],
            'info'
        );

        foreach (array_chunk(array_slice($candidates, 0, 8), 4) as $chunkIndex => $chunk) {
            $lines = ['جزئیات کاندیداهای BUY — بخش ' . ($chunkIndex + 1) . ' | Backend ' . $backendVersion];
            foreach ($chunk as $i => $candidate) {
                if (!is_array($candidate)) continue;
                $symbol = strtoupper((string)($candidate['symbol'] ?? 'UNKNOWN'));
                $rej = is_array($rejectionMap[$symbol] ?? null) ? $rejectionMap[$symbol] : [];
                $r = (string)($rej['reason'] ?? 'not_explicitly_recorded');
                $score = $rej['score'] ?? $candidate['score'] ?? null;
                $line = ($chunkIndex * 4 + $i + 1) . ') ' . $symbol
                    . ' | Signal=' . strtoupper((string)($candidate['signal'] ?? 'hold'))
                    . ($score !== null ? ' | Score=' . self::num($score, 2) : '')
                    . ' | Strategy=' . (string)($candidate['strategy_key'] ?? '—')
                    . ' | Regime=' . (string)($candidate['market_regime'] ?? '—')
                    . ' | Gross=' . self::pct($candidate['expected_gross_move_percent'] ?? null)
                    . ' | Cost=' . self::pct($candidate['estimated_roundtrip_cost_percent'] ?? null)
                    . ' | NetEdge=' . self::pct($candidate['expected_net_edge_percent'] ?? null)
                    . ' | Buffer=' . self::pct($candidate['required_edge_buffer_percent'] ?? null)
                    . ' | TradableEdge=' . self::pct($candidate['tradable_net_edge_percent'] ?? null)
                    . ' | Spread=' . self::pct($candidate['spread_percent'] ?? null)
                    . ' | Vol=' . self::pct($candidate['volatility_percent'] ?? null)
                    . ' | Liq×=' . self::num($candidate['liquidity_multiple'] ?? '—', 2)
                    . ' | نتیجه=رد'
                    . ' | علت=' . $r . ' — ' . self::reasonFa($r);

                $buffer = is_array($candidate['forecast_uncertainty_buffer'] ?? null) ? $candidate['forecast_uncertainty_buffer'] : [];
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
                    'effective_position_percent'=>'Position%',
                    'portfolio_exposure_percent'=>'Exposure%',
                    'exposure_capacity'=>'ExposureCapacity',
                    'minimum_order'=>'MinOrder',
                    'budget'=>'Budget',
                    'available_quote'=>'AvailableQuote',
                ] as $key => $label) {
                    if (array_key_exists($key, $rej) && $rej[$key] !== null) {
                        $line .= ' | ' . $label . '=' . self::num($rej[$key], 6);
                    }
                }
                $lines[] = $line;
            }

            ErrorReporter::log(
                implode("\n", $lines),
                'nobitex_candidate_rejections',
                [
                    'run_id'=>$runId,
                    'status'=>$status,
                    'exchange'=>'nobitex',
                    'symbol'=>$chunk[0]['symbol'] ?? null,
                    'chunk'=>$chunkIndex + 1,
                    'backend_version'=>$backendVersion,
                ],
                'info'
            );
        }

        $extra = [];
        $candidateSet = $this->candidateSymbolSet($candidates);
        foreach ($rejections as $row) {
            if (!is_array($row)) continue;
            $symbol = strtoupper((string)($row['symbol'] ?? ''));
            if ($symbol === '' || isset($candidateSet[$symbol])) continue;
            $r = (string)($row['reason'] ?? 'unknown');
            $extra[] = $symbol . ': ' . $r . ' — ' . self::reasonFa($r);
            if (count($extra) >= 6) break;
        }
        if ($extra !== []) {
            ErrorReporter::log(
                'Backend: ' . $backendVersion . "\nردهای تکمیلی خارج از Top Candidates:\n" . implode("\n", $extra),
                'nobitex_candidate_rejections_extra',
                ['run_id'=>$runId,'status'=>$status,'exchange'=>'nobitex','backend_version'=>$backendVersion],
                'info'
            );
        }
    }

    /** @return array<string,bool> */
    private function candidateSymbolSet(array $candidates): array
    {
        $out = [];
        foreach ($candidates as $c) {
            if (!is_array($c)) continue;
            $s = strtoupper(trim((string)($c['symbol'] ?? '')));
            if ($s !== '') $out[$s] = true;
        }
        return $out;
    }

    private static function reasonFa(string $reason): string
    {
        return match ($reason) {
            'no_candidate_passed_signal_and_risk_filters' => 'هیچ بازار بررسی‌شده‌ای هم‌زمان Edge مثبت قابل معامله و تمام کنترل‌های اجرایی/ریسک را پاس نکرد.',
            'no_eligible_markets' => 'در این Tick بازار واجد شرایط اولیه برای تحلیل/ورود پیدا نشد.',
            'positive_tradable_net_edge_after_costs_and_buffer' => 'مدل Profit-First بعد از تمام هزینه‌ها و Buffer هنوز Edge مثبت دارد و BUY مجاز است.',
            'edge_below_adaptive_safety_buffer' => 'بعد از کسر هزینه‌های واقعی اجرا، حاشیه باقی‌مانده از Buffer عدم‌قطعیت پیش‌بینی عبور نکرده است.',
            'expected_forward_move_negative_after_exit_cost' => 'برآورد حرکت آینده پس از هزینه خروج منفی است و جهت سیگنال به SELL متمایل شده است.',
            'liquidity_not_executable' => 'عمق نقدشوندگی نسبت به حداقل سفارش کافی نیست و اجرای امن سفارش تضمین نمی‌شود.',
            'spread_not_executable' => 'Spread فعلی از سقف پویا برای اجرای معامله بزرگ‌تر است.',
            'market_quality_not_ready' => 'یکی از مؤلفه‌های کیفیت اجرای بازار معتبر/قابل استفاده نیست.',
            'already_positioned' => 'همین دارایی از قبل پوزیشن فعال دارد و ورود تکراری مسدود است.',
            'range_reversion_not_entry_ready' => 'این علت مربوط به Shadow/نسخه چنداستراتژی است و دیگر به‌تنهایی گیت اصلی Profit-First را نمی‌بندد.',
            'strategy_edge_below_execution_costs' => 'این علت مربوط به مدل چنداستراتژی است؛ در مسیر اصلی، Edge Profit-First بعد از کل هزینه‌ها ملاک است.',
            'insufficient_balance' => 'موجودی Quote لازم برای این بازار کافی نیست.',
            'daily_loss_limit_reached' => 'حد زیان روزانه تنظیم‌شده فعال شده و BUY جدید را متوقف کرده است.',
            'symbol_cooldown_active' => 'Cooldown همین نماد هنوز تمام نشده است.',
            'strategy_profile_persistently_unprofitable' => 'یادگیری استراتژی این پروفایل را با داده فعلی زیان‌ده تشخیص داده و ورود را رد کرده است.',
            'strategy_learning_unavailable' => 'لایه یادگیری استراتژی در این تصمیم قابل استفاده نبوده است.',
            'strategy_learning_minimum_order_conflict' => 'حجم کاهش‌یافته توسط Risk/Learning از حداقل سفارش صرافی کوچک‌تر شده است.',
            'portfolio_exposure_limit_reached' => 'سقف مجاز سرمایه درگیر پر شده است.',
            'minimum_order_exceeds_budget' => 'حداقل ارزش سفارش صرافی از بودجه مجاز این معامله بیشتر است.',
            'minimum_order_rounding' => 'بعد از گردکردن مقدار/قیمت، سفارش به حداقل معتبر صرافی نمی‌رسد.',
            'pending_order_capacity_reached' => 'ظرفیت سفارش‌های Pending پر است و ورود تازه تا تعیین تکلیف آن‌ها متوقف است.',
            'configured_position_capacity_reached' => 'تعداد پوزیشن‌های فعال به سقف واقعی تنظیم‌شده کاربر رسیده است.',
            'effective_position_capacity_reached' => 'نام قدیمی محدودیت ظرفیت است؛ در نسخه جدید Adaptive فقط حجم خرید را نرم کاهش می‌دهد و سقف سخت همان مقدار تنظیم‌شده است.',
            'no_quote_balance' => 'هیچ موجودی قابل استفاده IRT/USDT برای ورود وجود ندارد.',
            'high_volatility_alignment_below_threshold',
            'high_volatility_efficiency_below_threshold',
            'high_volatility_directional_move_below_threshold',
            'high_volatility_momentum_1m_not_positive',
            'high_volatility_momentum_5m_not_positive',
            'high_volatility_momentum_15m_negative',
            'high_volatility_orderbook_adverse',
            'high_volatility_rsi_1m_exhausted',
            'high_volatility_rsi_1m_extreme',
            'high_volatility_rsi_5m_exhausted',
            'high_volatility_not_directional_enough',
            'high_volatility_edge_not_positive' => 'این وضعیت در Shadow Multi-Strategy ثبت شده است؛ در نسخه اصلاح‌شده به‌تنهایی BUY اصلی Profit-First را قفل نمی‌کند.',
            'not_explicitly_recorded' => 'این Candidate در Top List دیده شده ولی دلیل جداگانه‌ای در آرایه Rejections ثبت نشده است.',
            default => 'یکی از شروط سیگنال، اجرا یا ریسک این Candidate را برای BUY نپذیرفته است.',
        };
    }

    private static function pct(mixed $value): string
    {
        if ($value === null || !is_numeric($value)) return '—';
        $v = (float)$value;
        return ($v > 0 ? '+' : '') . number_format($v, 4, '.', '') . '%';
    }

    private static function num(mixed $value, int $precision): string
    {
        if (!is_numeric($value)) return (string)$value;
        return rtrim(rtrim(number_format((float)$value, $precision, '.', ''), '0'), '.');
    }
}
