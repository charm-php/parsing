<?php
/**
 * Expression Parsing Benchmark
 *
 * Compares expression parsing performance:
 * - Interpreted Charm parser
 * - Compiled Charm parser
 * - SQLite (as baseline)
 *
 * Usage:
 *   php expression-eval-benchmark.php "Description of change"
 *   php expression-eval-benchmark.php --no-log   # Don't append to log file
 *
 * Results are appended to expression-parser.log with timestamp and description.
 */

if (!class_exists('Charm\Parsing\GrammarParser')) {
    require __DIR__.'/../vendor/autoload.php';
}

use Charm\Parsing\GrammarParser;
use Charm\Parsing\CPEGParser;
use Charm\Parsing\Compiler\GrammarCompiler;
use Charm\Parsing\Optimizer\OptimizerPipeline;
use Charm\Parsing\Optimizer\ConsecutiveTerminalsOptimizer;

// Parse arguments
$description = null;
$noLog = false;
$useOptimizer = false;

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--no-log') {
        $noLog = true;
    } elseif ($arg === '--optimize') {
        $useOptimizer = true;
    } elseif ($description === null) {
        $description = $arg;
    }
}

if ($description === null) {
    $description = $useOptimizer ? 'With optimizer' : 'Baseline (no optimizer)';
}

$logFile = __DIR__ . '/expression-parser.log';

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

// Optionally apply optimizer
if ($useOptimizer) {
    $pipeline = new OptimizerPipeline([
        new ConsecutiveTerminalsOptimizer(),
    ]);
    $grammar = $pipeline->optimize($grammar);
}

$interpretedParser = new CPEGParser($grammar);

// Setup compiled parser
$compiler = new GrammarCompiler();
$compiledCode = $compiler->compile($grammar, 'CompiledParseBenchmark');
$tempFile = sys_get_temp_dir() . '/CompiledParseBenchmark_' . md5($compiledCode) . '.php';
file_put_contents($tempFile, $compiledCode);
require_once $tempFile;
$compiledParser = new \CompiledParseBenchmark();

// Test expressions with placeholder {N} for varying numbers
$expressionTemplates = [
    '{N} + 2',
    '10 - {N}',
    '{N} * 5',
    '20 / {N}',
    '{N} + (2 * 3)',
    '({N} + 2) * 3',
    '{N} + 2 + 3 + 4 + 5',
    '{N} * 3 * 4 * 5',
    '({N} + 2) * (3 + 4)',
    '-{N} + 10',
    '(({N} + 2) * (3 + 4)) + ((5 - 2) * (8 / 2))',
    '((({N} + 5) * 2) - 10) / 4',
];

$iterations = 1000;
$results = [];

foreach ($expressionTemplates as $template) {
    // Warmup
    for ($i = 1; $i <= 5; $i++) {
        $expr = str_replace('{N}', (string)$i, $template);
        $interpretedParser->parse($expr, 'b');
        $compiledParser->parse($expr);
        $sqlite->querySingle("SELECT $expr");
    }

    // Benchmark interpreted
    $start = hrtime(true);
    for ($i = 0; $i < $iterations; $i++) {
        $n = ($i % 9) + 1;
        $expr = str_replace('{N}', (string)$n, $template);
        $interpretedParser->parse($expr, 'b');
    }
    $interpMs = ((hrtime(true) - $start) / 1e9 / $iterations) * 1000;

    // Benchmark compiled
    $start = hrtime(true);
    for ($i = 0; $i < $iterations; $i++) {
        $n = ($i % 9) + 1;
        $expr = str_replace('{N}', (string)$n, $template);
        $compiledParser->parse($expr);
    }
    $compMs = ((hrtime(true) - $start) / 1e9 / $iterations) * 1000;

    // Benchmark SQLite
    $start = hrtime(true);
    for ($i = 0; $i < $iterations; $i++) {
        $n = ($i % 9) + 1;
        $expr = str_replace('{N}', (string)$n, $template);
        $sqlite->querySingle("SELECT $expr");
    }
    $sqlMs = ((hrtime(true) - $start) / 1e9 / $iterations) * 1000;

    $results[] = [
        'template' => $template,
        'interpreted' => $interpMs,
        'compiled' => $compMs,
        'sqlite' => $sqlMs,
    ];
}

// Calculate averages
$avgInterp = array_sum(array_column($results, 'interpreted')) / count($results);
$avgComp = array_sum(array_column($results, 'compiled')) / count($results);
$avgSql = array_sum(array_column($results, 'sqlite')) / count($results);

// Output to console
$phpVersion = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '.' . PHP_RELEASE_VERSION;
echo "Expression Parsing Benchmark (PHP $phpVersion)\n";
echo str_repeat("=", 90) . "\n";
echo "Description: $description\n";
echo "Optimizer: " . ($useOptimizer ? "ConsecutiveTerminalsOptimizer" : "None") . "\n";
echo str_repeat("=", 90) . "\n\n";

printf("%-45s %12s %12s %12s\n", "Expression", "Interpreted", "Compiled", "SQLite");
printf("%-45s %12s %12s %12s\n", "", "(ms)", "(ms)", "(ms)");
echo str_repeat("-", 90) . "\n";

foreach ($results as $r) {
    printf("%-45s %12.4f %12.4f %12.4f\n",
        substr($r['template'], 0, 45),
        $r['interpreted'],
        $r['compiled'],
        $r['sqlite']);
}

echo str_repeat("-", 90) . "\n";
printf("%-45s %12.4f %12.4f %12.4f\n", "AVERAGE", $avgInterp, $avgComp, $avgSql);
echo "\n";

printf("Interpreted vs SQLite:   %6.1fx slower\n", $avgInterp / $avgSql);
printf("Compiled vs SQLite:      %6.1fx slower\n", $avgComp / $avgSql);
printf("Compiled vs Interpreted: %6.1fx faster\n", $avgInterp / $avgComp);
echo "\n";

// Append to log file
if (!$noLog) {
    $logEntry = sprintf("# %s - %s (PHP %s)\n", date('Y-m-d H:i:s'), $description, $phpVersion);
    $logEntry .= sprintf("%-30s %12s %12s %12s %8s %8s\n",
        "Expression", "Interpreted", "Compiled", "SQLite", "I/S", "C/S");
    $logEntry .= str_repeat("-", 90) . "\n";

    foreach ($results as $r) {
        $logEntry .= sprintf("%-30s %12.4f %12.4f %12.4f %8.1fx %8.1fx\n",
            substr($r['template'], 0, 30),
            $r['interpreted'],
            $r['compiled'],
            $r['sqlite'],
            $r['interpreted'] / $r['sqlite'],
            $r['compiled'] / $r['sqlite']);
    }

    $logEntry .= str_repeat("-", 90) . "\n";
    $logEntry .= sprintf("%-30s %12.4f %12.4f %12.4f %8.1fx %8.1fx\n",
        "AVERAGE",
        $avgInterp, $avgComp, $avgSql,
        $avgInterp / $avgSql,
        $avgComp / $avgSql);
    $logEntry .= "\n";

    file_put_contents($logFile, $logEntry, FILE_APPEND);
    echo "Results appended to: $logFile\n";
}
