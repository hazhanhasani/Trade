<?php

declare(strict_types=1);

namespace Trade\Trading\Strategy;

interface NobitexStrategyInterface
{
    public function key(): string;

    /**
     * @return array{
     *   key:string,
     *   eligible:bool,
     *   entry_allowed:bool,
     *   exit_bias:bool,
     *   gross_edge_percent:float,
     *   confidence:int,
     *   reason:string,
     *   holding_horizon_minutes:int,
     *   diagnostics:array
     * }
     */
    public function evaluate(array $context, array $regime): array;
}
