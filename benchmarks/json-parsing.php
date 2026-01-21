<?php
/**
 * JSON Parsing Benchmark
 *
 * Measures parsing performance for JSON documents of various sizes.
 * Compares against PHP's native json_decode for reference.
 *
 * Future: Compare against compiled PHP parser classes.
 */

use Charm\Parsing\GrammarParser;
use Charm\Parsing\CPEGParser;

// Simplified JSON grammar for benchmarking - returns arrays instead of objects,
// and wraps booleans to avoid "return true = skip" convention.
$jsonGrammar = <<<'GRAMMAR'
JSON = _ val:Value _                <? return $val; ?>

Value = Object
      | Array
      | String
      | Number
      | "true"                      <? return ["_bool_", 1]; ?>
      | "false"                     <? return ["_bool_", 0]; ?>
      | "null"                      <? return ["_null_"]; ?>

Object = "{" _ pairs:Pairs? _ "}"   <? return ["_obj_", $pairs ?? []]; ?>
Pairs = first:Pair rest:("," _ Pair)*   <?
    $result = [$first[0] => $first[1]];
    foreach ($rest as $p) $result[$p[1][0]] = $p[1][1];
    return $result;
?>
Pair = key:String _ ":" _ val:Value <? return [$key, $val]; ?>

Array = "[" _ items:Values? _ "]"   <? return $items ?? []; ?>
Values = first:Value rest:("," _ Value)*    <?
    $result = [$first];
    foreach ($rest as $v) $result[] = $v[1];
    return $result;
?>

String = /"[^"]*"/                  <? return json_decode($_0); ?>

Number = /-?(0|[1-9][0-9]*)(\.[0-9]+)?([eE][+-]?[0-9]+)?/  <?
    return strpos($_0, '.') !== false || stripos($_0, 'e') !== false
        ? floatval($_0)
        : intval($_0);
?>

_ = /\s*/                           <? return true; ?>
GRAMMAR;

// Compile grammar once
$gp = new GrammarParser();
$grammar = $gp->parse($jsonGrammar, 'benchmark');
// prepare() is called automatically in Grammar constructor
$parser = new CPEGParser($grammar);

// Test data - avoiding booleans due to snippet return value conventions
$testData = [
    'tiny' => [
        'json' => '{"a":1}',
        'iterations' => 5000,
    ],
    'small' => [
        'json' => '{"name":"John","age":30,"city":"New York"}',
        'iterations' => 2000,
    ],
    'medium' => [
        'json' => json_encode([
            'users' => array_map(fn($i) => [
                'id' => $i,
                'name' => "User $i",
                'email' => "user$i@example.com",
                'score' => $i * 1.5,
            ], range(1, 10)),
            'meta' => [
                'total' => 10,
                'page' => 1,
                'per_page' => 10,
            ],
        ]),
        'iterations' => 200,
    ],
    'large' => [
        'json' => json_encode([
            'data' => array_map(fn($i) => [
                'id' => $i,
                'name' => "Item number $i",
                'values' => range(1, 5),
                'nested' => [
                    'level1' => [
                        'level2' => [
                            'value' => $i * 100,
                        ],
                    ],
                ],
            ], range(1, 50)),
        ]),
        'iterations' => 20,
    ],
];

echo "JSON Parsing Performance\n";
echo str_repeat("-", 70)."\n";
printf("%-10s %8s %12s %12s %12s %10s\n",
    "Size", "Bytes", "Iterations", "Charm ms", "Native ms", "Ratio");
echo str_repeat("-", 70)."\n";

$results = [];

foreach ($testData as $name => $data) {
    $json = $data['json'];
    $count = $data['iterations'];
    $bytes = strlen($json);

    // Warmup
    for ($i = 0; $i < 3; $i++) {
        $parser->parse($json, 'benchmark');
        json_decode($json);
    }

    // Benchmark Charm parser
    $start = hrtime(true);
    for ($i = 0; $i < $count; $i++) {
        $result = $parser->parse($json, 'benchmark');
    }
    $charmTime = (hrtime(true) - $start) / 1e9;
    $charmMs = ($charmTime / $count) * 1000;

    // Benchmark native json_decode
    $start = hrtime(true);
    for ($i = 0; $i < $count; $i++) {
        $result = json_decode($json);
    }
    $nativeTime = (hrtime(true) - $start) / 1e9;
    $nativeMs = ($nativeTime / $count) * 1000;

    $ratio = $charmMs / $nativeMs;

    $results[$name] = [
        'bytes' => $bytes,
        'charm_ms' => $charmMs,
        'native_ms' => $nativeMs,
        'ratio' => $ratio,
    ];

    printf("%-10s %8d %12d %12.4f %12.4f %10.1fx\n",
        $name, $bytes, $count, $charmMs, $nativeMs, $ratio);
}

echo str_repeat("-", 70)."\n";

// Verify correctness (simplified check - just verify structure)
echo "\nCorrectness Check\n";
echo str_repeat("-", 70)."\n";

$testJson = '{"string":"hello","number":42,"array":[1,2,3]}';
$charmResult = $parser->parse($testJson, 'test');

// Check structure
$isCorrect = is_array($charmResult)
    && $charmResult[0] === "_obj_"
    && is_array($charmResult[1])
    && $charmResult[1]['string'] === 'hello'
    && $charmResult[1]['number'] === 42
    && is_array($charmResult[1]['array'])
    && $charmResult[1]['array'] === [1, 2, 3];

if ($isCorrect) {
    echo "✓ Charm parser produces correct structure\n";
} else {
    echo "✗ Structure mismatch!\n";
    print_r($charmResult);
}

echo "\n";

// Throughput summary
echo "Throughput Summary\n";
echo str_repeat("-", 70)."\n";

$totalBytes = 0;
$totalTime = 0;
foreach ($testData as $name => $data) {
    $bytes = strlen($data['json']);
    $count = $data['iterations'];
    $totalBytes += $bytes * $count;
    $totalTime += $results[$name]['charm_ms'] * $count / 1000;
}

$throughput = $totalBytes / $totalTime / 1024;
printf("Charm parser throughput: %.1f KB/sec\n", $throughput);

echo "\nCompiled Parser Note\n";
echo str_repeat("-", 70)."\n";
echo "GrammarCompiler is available for expression-like grammars (~10x speedup).\n";
echo "JSON grammar uses snippets for value transformation which requires\n";
echo "full snippet compilation support. See expression benchmark for compiled\n";
echo "parser performance comparison.\n";
