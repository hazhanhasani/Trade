<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Trade\Trading\NobitexAdaptiveExecutionPolicy;

function assertTrue(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}

$warmup=[
    'learning_ready'=>false,
    'execution_penalty_percent'=>0.30,
    'profile'=>['p75_slippage_percent'=>0.40,'partial_fill_rate'=>0.50,'reprice_rate'=>0.50],
];
assertTrue(NobitexAdaptiveExecutionPolicy::shouldForceLimit($warmup)===false,'Warmup samples must not force adaptive execution changes.');

$clean=[
    'learning_ready'=>true,
    'execution_penalty_percent'=>0.03,
    'profile'=>['p75_slippage_percent'=>0.08,'partial_fill_rate'=>0.05,'reprice_rate'=>0.10],
];
assertTrue(NobitexAdaptiveExecutionPolicy::shouldForceLimit($clean)===false,'Clean execution history should preserve the planner mode.');

$slippage=[
    'learning_ready'=>true,
    'execution_penalty_percent'=>0.11,
    'profile'=>['p75_slippage_percent'=>0.10,'partial_fill_rate'=>0.10,'reprice_rate'=>0.10],
];
assertTrue(NobitexAdaptiveExecutionPolicy::shouldForceLimit($slippage)===true,'High learned execution penalty should force bounded limit execution.');

$tailSlip=[
    'learning_ready'=>true,
    'execution_penalty_percent'=>0.04,
    'profile'=>['p75_slippage_percent'=>0.16,'partial_fill_rate'=>0.10,'reprice_rate'=>0.10],
];
assertTrue(NobitexAdaptiveExecutionPolicy::shouldForceLimit($tailSlip)===true,'Adverse p75 slippage should force bounded limit execution.');

$partial=[
    'learning_ready'=>true,
    'execution_penalty_percent'=>0.04,
    'profile'=>['p75_slippage_percent'=>0.08,'partial_fill_rate'=>0.30,'reprice_rate'=>0.10],
];
assertTrue(NobitexAdaptiveExecutionPolicy::shouldForceLimit($partial)===true,'High partial-fill rate should harden execution.');

$reprice=[
    'learning_ready'=>true,
    'execution_penalty_percent'=>0.04,
    'profile'=>['p75_slippage_percent'=>0.08,'partial_fill_rate'=>0.10,'reprice_rate'=>0.40],
];
assertTrue(NobitexAdaptiveExecutionPolicy::shouldForceLimit($reprice)===true,'High reprice rate should harden execution.');

echo "Adaptive Execution Policy v1 regression tests passed.\n";
