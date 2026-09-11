<?php

declare(strict_types=1);

namespace Trade\Observability;

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
        if ($status !== 'no_trade') {
            return;
        }

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
        foreach ($reasonCounts as $key => $count) {
            $reasonSummary[] = $key . '=' . $count;
        }

        $summary = [
            'تصمیم خرید Nobitex: هیچ سفارش BUY جدیدی ثبت نشد.',
            'علت نهایی: ' . $reason . ' — ' . self::reasonFa($reason),
            'کاندیداهای گزارش‌شده: ' . count($candidates) . ' | ردهای ثبت‌شده: ' . count($rejections),
            'پوزیشن فعال: ' . (string)($result['active_positions'] ?? '—') . '/' . (string)($result['max_positions'] ?? '—')
                . ' | Pending: ' . (string)($result['pending_orders'] ?? '—') . '/' . (string)($result['max_pending_orders'] ?? '—'),
            'محل تصمیم: backend/src/Trading/NobitexPortfolioEngine.php → runLocked() / entryBudget()',
        ];
        if ($reasonSummary !== []) {
            $summary[] = 'خلاصه دلایل رد: ' . implode(' | ', array_slice($reasonSummary, 0, 8));
        }
        $summary[] = 'نکته: Edge فقط وقتی قابل معامله است که خود Strategy اجازه ورود داده باشد؛ Edge خام یا مدل تشخیصی به‌تنهایی مجوز BUY نیست.';

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
            ],
            'info'
        );

        foreach (array_chunk(array_slice($candidates, 0, 8), 4) as $chunkIndex => $chunk) {
            $lines = ['جزئیات کاندیداهای BUY — بخش ' . ($chunkIndex + 1)];
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
                    . ' | RawEdge=' . self::pct($candidate['expected_net_edge_percent'] ?? null)
                    . ' | TradableEdge=' . self::pct($candidate['tradable_net_edge_percent'] ?? null)
                    . ' | RequiredBuffer=' . self::pct($candidate['required_edge_buffer_percent'] ?? null)
                    . ' | Spread=' . self::pct($candidate['spread_percent'] ?? null)
                    . ' | نتیجه=رد'
                    . ' | علت=' . $r . ' — ' . self::reasonFa($r);

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
                ],
                'info'
            );
        }

        $extra = [];
        foreach ($rejections as $row) {
            if (!is_array($row)) continue;
            $symbol = strtoupper((string)($row['symbol'] ?? ''));
            if ($symbol === '' || array_key_exists($symbol, $this->candidateSymbolSet($candidates))) continue;
            $r = (string)($row['reason'] ?? 'unknown');
            $extra[] = $symbol . ': ' . $r . ' — ' . self::reasonFa($r);
            if (count($extra) >= 6) break;
        }
        if ($extra !== []) {
            ErrorReporter::log(
                "ردهای تکمیلی خارج از Top Candidates:\n" . implode("\n", $extra),
                'nobitex_candidate_rejections_extra',
                ['run_id'=>$runId,'status'=>$status,'exchange'=>'nobitex'],
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
            'no_candidate_passed_signal_and_risk_filters' => 'هیچ بازار بررسی‌شده‌ای همه شروط سیگنال، هزینه اجرا و ریسک را هم‌زمان پاس نکرد.',
            'no_eligible_markets' => 'در این Tick بازار واجد شرایط اولیه برای تحلیل/ورود پیدا نشد.',
            'already_positioned' => 'همین دارایی از قبل پوزیشن فعال دارد و ورود تکراری مسدود است.',
            'range_reversion_not_entry_ready' => 'بازار رنج است اما شرایط برگشت به میانگین هنوز نقطه ورود معتبر نساخته است.',
            'strategy_edge_below_execution_costs' => 'برتری پیش‌بینی‌شده بعد از Spread، Buffer و هزینه اجرا برای معامله کافی نیست.',
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
            'no_quote_balance' => 'هیچ موجودی قابل استفاده IRT/USDT برای ورود وجود ندارد.',
            'high_volatility_alignment_below_threshold' => 'هم‌جهتی تایم‌فریم‌ها در بازار پرنوسان کمتر از حد لازم است؛ حرکت هنوز تأیید چندتایم‌فریمی ندارد.',
            'high_volatility_efficiency_below_threshold' => 'Efficiency حرکت پایین است؛ بخش زیادی از نوسان رفت‌وبرگشتی/نویز است و روند جهت‌دار قابل اتکا نیست.',
            'high_volatility_directional_move_below_threshold' => 'حرکت جهت‌دار ۳۰ دقیقه‌ای برای ورود در وضعیت پرنوسان هنوز به حد لازم نرسیده است.',
            'high_volatility_momentum_1m_not_positive' => 'مومنتوم کوتاه‌مدت ۱ دقیقه مثبت نیست و تأیید ورود سریع وجود ندارد.',
            'high_volatility_momentum_5m_not_positive' => 'مومنتوم ۵ دقیقه مثبت نیست و حرکت پایدار کوتاه‌مدت تأیید نشده است.',
            'high_volatility_momentum_15m_negative' => 'مومنتوم ۱۵ دقیقه منفی است و جهت بزرگ‌تر با BUY هم‌سو نیست.',
            'high_volatility_orderbook_adverse' => 'عدم‌تعادل Order Book به‌شکل واضح علیه خریدار است و فشار فروش برای ورود زیاد است.',
            // Historical v2 code kept for old stored runs.
            'high_volatility_rsi_1m_exhausted' => 'در نسخه قبلی، RSI یک‌دقیقه‌ای داغ به‌صورت سخت BUY را رد می‌کرد.',
            'high_volatility_rsi_1m_extreme' => 'RSI یک‌دقیقه‌ای به ناحیه افراطی رسیده؛ حتی در روند قوی، ورود تازه برای جلوگیری از تعقیب سقف مسدود است.',
            'high_volatility_rsi_5m_exhausted' => 'RSI پنج‌دقیقه‌ای وارد ناحیه فرسودگی شده و ورود جدید پرریسک است.',
            'high_volatility_not_directional_enough' => 'بازار پرنوسان است اما مجموعه شروط جهت‌داری برای BUY کامل نشده است.',
            'high_volatility_edge_not_positive' => 'ساختار جهت‌دار تأیید شده ولی Edge اقتصادی نهایی هنوز مثبت نیست.',
            'not_explicitly_recorded' => 'این Candidate در Top List دیده شده ولی دلیل جداگانه‌ای در آرایه Rejections ثبت نشده است.',
            default => 'Guard یا شرط داخلی موتور معامله این Candidate را برای BUY نپذیرفته است.',
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
