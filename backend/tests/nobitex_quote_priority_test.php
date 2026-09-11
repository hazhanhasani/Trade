<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$scannerPath = $root . '/src/Trading/NobitexUniverseScanner.php';
$portfolioPath = $root . '/src/Trading/NobitexPortfolioEngine.php';

$scanner = file_get_contents($scannerPath);
$portfolio = file_get_contents($portfolioPath);

if (!is_string($scanner) || $scanner === '') {
    fwrite(STDERR, "Cannot read NobitexUniverseScanner.php\n");
    exit(1);
}
if (!is_string($portfolio) || $portfolio === '') {
    fwrite(STDERR, "Cannot read NobitexPortfolioEngine.php\n");
    exit(1);
}

$preferredPos = strpos($scanner, "\$aPreferred = ((\$a['quote_asset'] ?? '') === \$preferredQuote) ? 1 : 0;");
$buyPos = strpos($scanner, "\$aBuy = ((\$a['signal']['action'] ?? '') === 'buy') ? 1 : 0;");
$tradableEdgePos = strpos($scanner, "tradable_net_edge_percent");

if ($preferredPos === false || $buyPos === false || $preferredPos >= $buyPos) {
    fwrite(STDERR, "Preferred/funded quote must be ranked before BUY/edge ordering.\n");
    exit(1);
}
if ($tradableEdgePos === false) {
    fwrite(STDERR, "Scanner ranking must use tradable post-cost edge.\n");
    exit(1);
}
if (!str_contains($scanner, 'unfunded USDT BUY cannot')) {
    fwrite(STDERR, "Quote-priority safety intent marker is missing.\n");
    exit(1);
}
if (!str_contains($portfolio, "\$preferredQuote = \$irt > 0 ? 'IRT' : 'USDT';")) {
    fwrite(STDERR, "Portfolio engine no longer derives preferred quote from live wallet balance.\n");
    exit(1);
}
if (!str_contains($portfolio, 'foreach ($candidates as $market)')) {
    fwrite(STDERR, "Portfolio engine must continue through the full candidate list after a rejection.\n");
    exit(1);
}

fwrite(STDOUT, "Nobitex funded quote priority regression OK\n");
