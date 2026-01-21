<?php
/**
 * Expression Evaluation Benchmark
 *
 * Compares expression parsing and evaluation performance:
 * - Interpreted Charm parser
 * - Compiled Charm parser
 * - SQLite (as baseline)
 *
 * Uses expressions compatible with SQLite syntax (no ** operator).
 * All expressions use explicit parentheses to ensure consistent precedence.
 */

if (!class_exists('Charm\Parsing\GrammarParser')) {
    require __DIR__.'/../vendor/autoload.php';
}

use Charm\Parsing\GrammarParser;
use Charm\Parsing\CPEGParser;
use Charm\Parsing\Compiler\GrammarCompiler;

// Grammar that returns AST (no evaluation) - measures pure parse time
$astGrammar = <<<'GRAMMAR'
Calc = ^ _ expr:Expr _ $                              <? return $expr; ?>

Expr "expression"
    = left:Expr _ op:"+" _ right:Term                 <? return [$op, $left, $right]; ?>
    | left:Expr _ op:"-" _ right:Term                 <? return [$op, $left, $right]; ?>
    | Term

Term "term"
    = left:Term _ op:"*" _ right:Factor               <? return [$op, $left, $right]; ?>
    | left:Term _ op:"/" _ right:Factor               <? return [$op, $left, $right]; ?>
    | left:Term _ op:"%" _ right:Factor               <? return [$op, $left, $right]; ?>
    | Factor

Factor "factor"
    = "(" _ e:Expr _ ")"                              <? return $e; ?>
    | "-" _ f:Factor                                  <? return ['neg', $f]; ?>
    | Number

Number "number"
    = /[0-9]+/                                        <? return intval($_0); ?>

_ = /[ \t]*/                                          %silent
GRAMMAR;

// Setup SQLite
$sqlite = new SQLite3(':memory:');

// Setup interpreted parser
$gp = new GrammarParser();
$grammar = $gp->parse($astGrammar, 'parse-benchmark');
$interpretedParser = new CPEGParser($grammar);

// Setup compiled parser
$compiler = new GrammarCompiler();
$compiledCode = $compiler->compile($grammar, 'CompiledParseBenchmark');
$tempFile = sys_get_temp_dir() . '/CompiledParseBenchmark_' . md5($compiledCode) . '.php';
if (!file_exists($tempFile)) {
    file_put_contents($tempFile, $compiledCode);
}
require_once $tempFile;
$compiledParser = new \CompiledParseBenchmark();

// Test expression templates with a placeholder {N} that gets replaced with varying numbers
// This prevents SQLite from caching query plans
$expressionTemplates = [
    // Simple
    '{N} + 2',
    '10 - {N}',
    '{N} * 5',
    '20 / {N}',
    '17 % {N}',

    // Precedence (explicit parens)
    '{N} + (2 * 3)',
    '({N} + 2) * 3',
    '10 - ({N} / 2)',
    '(10 - {N}) / 2',

    // Longer chains
    '{N} + 2 + 3 + 4 + 5',
    '(({N} + 2) + 3) + 4 + 5',
    '{N} * 3 * 4 * 5',
    '120 / {N} / 2 / 2',

    // Mixed operations with explicit precedence
    '({N} + 2) * (3 + 4)',
    '(({N} * 3) + (4 * 5))',
    '({N} + (20 * 3)) - 5',
    '((100 / {N}) + (50 / 5))',

    // Negation
    '-{N} + 10',
    '10 + (-{N})',
    '-({N} + 4)',

    // Complex
    '(({N} + 2) * (3 + 4)) + ((5 - 2) * (8 / 2))',
    '((({N} + 5) * 2) - 10) / 4',
    '100 - (({N} + 30) * (40 / 20))',
];

echo "Expression Parsing Benchmark (parse time only, no evaluation)\n";
echo str_repeat("=", 85) . "\n";
echo "Comparing: Interpreted Charm | Compiled Charm | SQLite (baseline)\n";
echo str_repeat("=", 85) . "\n\n";

// Quick sanity check - verify parsers produce AST
$testExpr = '1 + 2 * 3';
$interpResult = $interpretedParser->parse($testExpr, 'test');
$compResult = $compiledParser->parse($testExpr);

echo "Sanity Check (AST for '$testExpr'):\n";
echo "  Interpreted: " . json_encode($interpResult) . "\n";
echo "  Compiled:    " . json_encode($compResult) . "\n";

$astMatch = $interpResult === $compResult;
echo "  Match: " . ($astMatch ? "OK" : "MISMATCH") . "\n\n";

// Benchmark with varying numbers to prevent query caching
echo "Performance Benchmark (varying numbers to prevent caching)\n";
echo str_repeat("-", 85) . "\n";
printf("%-40s %12s %12s %12s\n", "Expression Template", "Interpreted", "Compiled", "SQLite");
printf("%-40s %12s %12s %12s\n", "", "(ms)", "(ms)", "(ms)");
echo str_repeat("-", 85) . "\n";

$iterations = 1000;
$totals = ['interpreted' => 0, 'compiled' => 0, 'sqlite' => 0];

foreach ($expressionTemplates as $template) {
    // Warmup with different numbers
    for ($i = 1; $i <= 10; $i++) {
        $expr = str_replace('{N}', (string)$i, $template);
        $interpretedParser->parse($expr, 'b');
        $compiledParser->parse($expr);
        $sqlite->querySingle("SELECT $expr");
    }

    // Benchmark interpreted - each iteration uses a different number
    $start = hrtime(true);
    for ($i = 0; $i < $iterations; $i++) {
        $n = ($i % 9) + 1; // 1-9 to avoid division by zero
        $expr = str_replace('{N}', (string)$n, $template);
        $interpretedParser->parse($expr, 'b');
    }
    $interpMs = ((hrtime(true) - $start) / 1e9 / $iterations) * 1000;

    // Benchmark compiled - each iteration uses a different number
    $start = hrtime(true);
    for ($i = 0; $i < $iterations; $i++) {
        $n = ($i % 9) + 1;
        $expr = str_replace('{N}', (string)$n, $template);
        $compiledParser->parse($expr);
    }
    $compMs = ((hrtime(true) - $start) / 1e9 / $iterations) * 1000;

    // Benchmark SQLite - each iteration uses a different number
    $start = hrtime(true);
    for ($i = 0; $i < $iterations; $i++) {
        $n = ($i % 9) + 1;
        $expr = str_replace('{N}', (string)$n, $template);
        $sqlite->querySingle("SELECT $expr");
    }
    $sqlMs = ((hrtime(true) - $start) / 1e9 / $iterations) * 1000;

    $totals['interpreted'] += $interpMs;
    $totals['compiled'] += $compMs;
    $totals['sqlite'] += $sqlMs;

    printf("%-40s %12.4f %12.4f %12.4f\n",
        substr($template, 0, 40), $interpMs, $compMs, $sqlMs);
}

$count = count($expressionTemplates);
echo str_repeat("-", 85) . "\n";
printf("%-40s %12.4f %12.4f %12.4f\n",
    "AVERAGE",
    $totals['interpreted'] / $count,
    $totals['compiled'] / $count,
    $totals['sqlite'] / $count);

echo "\n";

// Summary with ratios
echo "Performance Ratios (lower is better)\n";
echo str_repeat("-", 85) . "\n";

$avgInterp = $totals['interpreted'] / $count;
$avgComp = $totals['compiled'] / $count;
$avgSql = $totals['sqlite'] / $count;

printf("Interpreted vs SQLite:  %.1fx slower\n", $avgInterp / $avgSql);
printf("Compiled vs SQLite:     %.1fx slower\n", $avgComp / $avgSql);
printf("Compiled vs Interpreted: %.1fx faster\n", $avgInterp / $avgComp);

echo "\n";

// Throughput test with longer expressions (varying numbers)
echo "Throughput Test (complex expressions, varying numbers)\n";
echo str_repeat("-", 85) . "\n";

$complexTemplates = [
    '(({N} + 2) * (3 + 4)) + ((5 - 2) * (8 / 2))',
    '((({N} + 5) * 2) - 10) / 4 + (((8 * 2) - 6) / 2)',
    '100 - (({N} + 30) * (40 / 20)) + ((15 * 4) - (30 / 2))',
    '((({N} + 1) * (2 + 2)) * ((3 + 3) * (4 + 4)))',
    '(({N} / 2) + (20 / 4)) * ((30 / 6) + (40 / 8))',
];

$throughputIterations = 5000;
$throughputResults = ['interpreted' => 0, 'compiled' => 0, 'sqlite' => 0];

foreach ($complexTemplates as $template) {
    // Interpreted - varying numbers
    $start = hrtime(true);
    for ($i = 0; $i < $throughputIterations; $i++) {
        $n = ($i % 9) + 1;
        $expr = str_replace('{N}', (string)$n, $template);
        $interpretedParser->parse($expr, 'b');
    }
    $throughputResults['interpreted'] += (hrtime(true) - $start) / 1e9;

    // Compiled - varying numbers
    $start = hrtime(true);
    for ($i = 0; $i < $throughputIterations; $i++) {
        $n = ($i % 9) + 1;
        $expr = str_replace('{N}', (string)$n, $template);
        $compiledParser->parse($expr);
    }
    $throughputResults['compiled'] += (hrtime(true) - $start) / 1e9;

    // SQLite - varying numbers
    $start = hrtime(true);
    for ($i = 0; $i < $throughputIterations; $i++) {
        $n = ($i % 9) + 1;
        $expr = str_replace('{N}', (string)$n, $template);
        $sqlite->querySingle("SELECT $expr");
    }
    $throughputResults['sqlite'] += (hrtime(true) - $start) / 1e9;
}

$totalOps = count($complexTemplates) * $throughputIterations;
printf("Interpreted: %8d ops in %.2f sec = %8.0f ops/sec\n",
    $totalOps, $throughputResults['interpreted'],
    $totalOps / $throughputResults['interpreted']);
printf("Compiled:    %8d ops in %.2f sec = %8.0f ops/sec\n",
    $totalOps, $throughputResults['compiled'],
    $totalOps / $throughputResults['compiled']);
printf("SQLite:      %8d ops in %.2f sec = %8.0f ops/sec\n",
    $totalOps, $throughputResults['sqlite'],
    $totalOps / $throughputResults['sqlite']);

echo "\n";
echo "Note: SQLite includes query parsing overhead but benefits from C implementation.\n";
echo "Charm parsers are pure PHP but offer AST access and customization.\n";
