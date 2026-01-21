<?php
/**
 * Expression Parsing Benchmark
 *
 * Measures parsing performance for arithmetic expressions with proper precedence.
 * Tests left-recursion handling and the Pratt parsing algorithm.
 * Compares interpreted vs compiled PHP parser classes.
 */

use Charm\Parsing\GrammarParser;
use Charm\Parsing\CPEGParser;
use Charm\Parsing\Compiler\GrammarCompiler;

// Simple calculator grammar (4 operators, no parentheses)
$simpleGrammar = <<<'GRAMMAR'
Expr = Expr "*" Expr    <? return $_0 * $_2; ?>
     | Expr "/" Expr    <? return $_0 / $_2; ?>
     | Expr "+" Expr    <? return $_0 + $_2; ?>
     | Expr "-" Expr    <? return $_0 - $_2; ?>
     | Number
Number = /[0-9]+/       <? return intval($_0); ?>
GRAMMAR;

// Full calculator grammar (with parentheses and power operator)
$fullGrammar = <<<'GRAMMAR'
Expr = Expr "**" Expr   %assoc=right    <? return pow($_0, $_2); ?>
     | Expr "*" Expr                    <? return $_0 * $_2; ?>
     | Expr "/" Expr                    <? return $_0 / $_2; ?>
     | Expr "+" Expr                    <? return $_0 + $_2; ?>
     | Expr "-" Expr                    <? return $_0 - $_2; ?>
     | Primary
Primary = "(" Expr ")"                  <? return $_1; ?>
        | Number
Number = /[0-9]+/                       <? return intval($_0); ?>
GRAMMAR;

// Complex grammar with whitespace and more operators
$complexGrammar = <<<'GRAMMAR'
Calc = ^ _ expr:Expr _ $                            <? return $expr; ?>

Expr = left:Expr _ "**" _ right:Expr   %assoc=right <? return pow($left, $right); ?>
     | left:Expr _ "*" _ right:Expr                 <? return $left * $right; ?>
     | left:Expr _ "/" _ right:Expr                 <? return $left / $right; ?>
     | left:Expr _ "%" _ right:Expr                 <? return $left % $right; ?>
     | left:Expr _ "+" _ right:Expr                 <? return $left + $right; ?>
     | left:Expr _ "-" _ right:Expr                 <? return $left - $right; ?>
     | "-" _ val:Expr                               <? return -$val; ?>
     | "+" _ val:Expr                               <? return +$val; ?>
     | Primary

Primary = "(" _ inner:Expr _ ")"                    <? return $inner; ?>
        | Number

Number = /[0-9]+(\.[0-9]+)?/                        <? return floatval($_0); ?>

_ = /\s*/                                           <? return true; ?>
GRAMMAR;

// Compile grammars
$parsers = [];
foreach (['simple' => $simpleGrammar, 'full' => $fullGrammar, 'complex' => $complexGrammar] as $name => $grammarText) {
    $gp = new GrammarParser();
    $grammar = $gp->parse($grammarText, 'benchmark');
    // prepare() is called automatically in Grammar constructor
    $parsers[$name] = new CPEGParser($grammar);
}

// Test expressions
$expressions = [
    // Simple expressions
    'simple' => [
        ['1+2', 3, 5000],
        ['1+2+3', 6, 3000],
        ['1+2*3', 7, 3000],
        ['1*2+3*4', 14, 2000],
        ['1+2+3+4+5', 15, 1000],
        ['1*2*3*4*5', 120, 1000],
        ['10-3-2', 5, 2000],
        ['100/10/2', 5, 2000],
    ],
    // Expressions with parentheses
    'full' => [
        ['(1+2)*3', 9, 3000],
        ['2**3**2', 512, 2000],
        ['(2**3)**2', 64, 2000],
        ['((1+2))', 3, 2000],
        ['(1+2)*(3+4)', 21, 1000],
        ['2*((3+4)*5)', 70, 1000],
        ['((1+2)*(3+4))*(5+6)', 231, 500],
    ],
    // Complex expressions with whitespace
    'complex' => [
        ['1 + 2', 3, 3000],
        ['1 + 2 * 3', 7, 2000],
        ['( 1 + 2 ) * 3', 9, 2000],
        ['-10 + 5', -5, 2000],
        ['2 ** 3 ** 2', 512, 1000],
        ['10.5 + 2.5', 13.0, 2000],
        ['100 / 10 / 2', 5, 2000],
        ['1 + 2 + 3 + 4 + 5 + 6 + 7 + 8 + 9 + 10', 55, 500],
    ],
];

echo "Expression Parsing Performance\n";
echo str_repeat("=", 70)."\n\n";

$totalResults = [];

foreach ($expressions as $parserName => $tests) {
    $parser = $parsers[$parserName];

    echo "$parserName grammar:\n";
    echo str_repeat("-", 70)."\n";
    printf("%-30s %10s %10s %12s\n", "Expression", "Expected", "Iterations", "Time (ms)");
    echo str_repeat("-", 70)."\n";

    $parserTotalTime = 0;
    $parserTotalIterations = 0;

    foreach ($tests as [$expr, $expected, $iterations]) {
        // Warmup
        for ($i = 0; $i < 5; $i++) {
            $parser->parse($expr, 'benchmark');
        }

        // Verify correctness first
        $result = $parser->parse($expr, 'benchmark');
        if ($result != $expected) {
            printf("%-30s ERROR: got %s, expected %s\n",
                substr($expr, 0, 30), var_export($result, true), var_export($expected, true));
            continue;
        }

        // Benchmark
        $start = hrtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            $parser->parse($expr, 'benchmark');
        }
        $elapsed = (hrtime(true) - $start) / 1e9;
        $msPerParse = ($elapsed / $iterations) * 1000;

        $parserTotalTime += $elapsed;
        $parserTotalIterations += $iterations;

        printf("%-30s %10s %10d %12.4f\n",
            substr($expr, 0, 30), $expected, $iterations, $msPerParse);
    }

    $avgMs = ($parserTotalTime / $parserTotalIterations) * 1000;
    echo str_repeat("-", 70)."\n";
    printf("%-30s %10s %10d %12.4f\n", "AVERAGE", "", $parserTotalIterations, $avgMs);

    $totalResults[$parserName] = [
        'total_time' => $parserTotalTime,
        'total_iterations' => $parserTotalIterations,
        'avg_ms' => $avgMs,
    ];

    echo "\n";
}

// Summary
echo "Summary\n";
echo str_repeat("=", 70)."\n";
printf("%-15s %15s %15s %15s\n", "Grammar", "Iterations", "Total Time", "Avg ms/parse");
echo str_repeat("-", 70)."\n";

foreach ($totalResults as $name => $data) {
    printf("%-15s %15d %15.2f s %15.4f\n",
        $name, $data['total_iterations'], $data['total_time'], $data['avg_ms']);
}

echo "\n";

// Comparison: hand-coded eval vs parser
echo "Reference: eval() for simple expressions\n";
echo str_repeat("-", 70)."\n";

$evalExpressions = ['1+2', '1+2*3', '10-3-2', '1+2+3+4+5'];
foreach ($evalExpressions as $expr) {
    $iterations = 10000;

    $start = hrtime(true);
    for ($i = 0; $i < $iterations; $i++) {
        $result = eval("return $expr;");
    }
    $elapsed = (hrtime(true) - $start) / 1e9;
    $msPerEval = ($elapsed / $iterations) * 1000;

    $parserMs = 0;
    foreach ($expressions['simple'] as [$testExpr, $expected, $testIterations]) {
        if ($testExpr === $expr) {
            // Re-run benchmark for this expression
            $start = hrtime(true);
            for ($i = 0; $i < $iterations; $i++) {
                $parsers['simple']->parse($expr, 'benchmark');
            }
            $parserMs = ((hrtime(true) - $start) / 1e9 / $iterations) * 1000;
            break;
        }
    }

    $ratio = $parserMs / $msPerEval;
    printf("%-20s eval: %.4f ms  parser: %.4f ms  ratio: %.1fx\n",
        $expr, $msPerEval, $parserMs, $ratio);
}

// Compiled Parser Benchmark
echo "\nCompiled Parser Comparison\n";
echo str_repeat("=", 70)."\n\n";

// Grammar for compiled parser (without snippets - returns AST)
$compiledGrammar = <<<'GRAMMAR'
Expr = Expr "*" Expr
     | Expr "/" Expr
     | Expr "+" Expr
     | Expr "-" Expr
     | Number
Number = /[0-9]+/
GRAMMAR;

$gp = new GrammarParser();
$grammar = $gp->parse($compiledGrammar, 'compiled-benchmark');
$interpretedParser = new CPEGParser($grammar);

// Compile the grammar
$compiler = new GrammarCompiler();
$compiledCode = $compiler->compile($grammar, 'CompiledExprBenchmark');
$tempFile = sys_get_temp_dir() . '/CompiledExprBenchmark_' . md5($compiledCode) . '.php';
if (!file_exists($tempFile)) {
    file_put_contents($tempFile, $compiledCode);
}
require_once $tempFile;
$compiledParser = new \CompiledExprBenchmark();

echo "Interpreted vs Compiled (AST output, no evaluation):\n";
echo str_repeat("-", 70)."\n";
printf("%-25s %12s %12s %10s\n", "Expression", "Interpreted", "Compiled", "Speedup");
echo str_repeat("-", 70)."\n";

$compiledTests = [
    ['1+2', 10000],
    ['1+2*3', 10000],
    ['1+2+3+4+5', 5000],
    ['1*2+3*4-5/6', 5000],
    ['1+2+3+4+5+6+7+8+9+10', 2000],
];

$totalInterpMs = 0;
$totalCompMs = 0;
$totalIterations = 0;

foreach ($compiledTests as [$expr, $iterations]) {
    // Warmup
    for ($i = 0; $i < 10; $i++) {
        $interpretedParser->parse($expr, 'b');
        $compiledParser->parse($expr);
    }

    // Benchmark interpreted
    $start = hrtime(true);
    for ($i = 0; $i < $iterations; $i++) {
        $interpretedParser->parse($expr, 'b');
    }
    $interpMs = ((hrtime(true) - $start) / 1e9 / $iterations) * 1000;

    // Benchmark compiled
    $start = hrtime(true);
    for ($i = 0; $i < $iterations; $i++) {
        $compiledParser->parse($expr);
    }
    $compMs = ((hrtime(true) - $start) / 1e9 / $iterations) * 1000;

    $speedup = $interpMs / $compMs;
    $totalInterpMs += $interpMs * $iterations;
    $totalCompMs += $compMs * $iterations;
    $totalIterations += $iterations;

    printf("%-25s %10.4f ms %10.4f ms %9.1fx\n", $expr, $interpMs, $compMs, $speedup);
}

$avgInterpMs = $totalInterpMs / $totalIterations;
$avgCompMs = $totalCompMs / $totalIterations;
$avgSpeedup = $avgInterpMs / $avgCompMs;

echo str_repeat("-", 70)."\n";
printf("%-25s %10.4f ms %10.4f ms %9.1fx\n", "AVERAGE", $avgInterpMs, $avgCompMs, $avgSpeedup);

echo "\nCompiled parser advantages:\n";
echo "  - No clause object method calls\n";
echo "  - Inlined precedence/Pratt logic\n";
echo "  - Native PHP control flow\n";
echo "  - Can be opcache preloaded\n";
