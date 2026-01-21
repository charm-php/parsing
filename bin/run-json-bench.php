#!/usr/bin/env php
<?php
/**
 * JSON Parser Benchmark Script
 *
 * Usage: bin/run-json-bench.php "Comment describing this benchmark run"
 *
 * Benchmarks json_decode vs compiled JsonParser vs interpreted CPEGParser
 * Results are appended to benchmarks/json-parser.log
 */

require __DIR__ . '/../vendor/autoload.php';

$comment = $argv[1] ?? 'No comment';
$duration = 1.0; // seconds per benchmark

$testCases = [
    'string' => '"hello world"',
    'number' => '12345.6789',
    'array' => '[1, 2, 3, 4, 5]',
    'object' => '{"name": "John", "age": 30}',
    'nested' => '{"users": [{"id": 1, "name": "Alice"}, {"id": 2, "name": "Bob"}], "count": 2}',
];

// Setup parsers
$grammar = file_get_contents(__DIR__ . '/../example-grammars/json.cpeg');
$grammarParser = new Charm\Parsing\GrammarParser();
$grammarObj = $grammarParser->parse($grammar, 'json.cpeg');
$interpreted = new Charm\Parsing\CPEGParser($grammarObj);
$compiled = new Charm\Parsing\Library\JsonParser();

/**
 * Run benchmark for $duration seconds, return parses per second
 */
function benchmark(callable $fn, float $duration): float {
    $count = 0;
    $start = hrtime(true);
    $end = $start + ($duration * 1e9);

    while (hrtime(true) < $end) {
        $fn();
        $count++;
    }

    $elapsed = (hrtime(true) - $start) / 1e9;
    return $count / $elapsed;
}

// Warmup
foreach ($testCases as $json) {
    json_decode($json);
    $compiled->parse($json);
    $interpreted->parse($json, 'bench');
}

// Run benchmarks
$results = [];
foreach ($testCases as $name => $json) {
    fprintf(STDERR, "Benchmarking: %s...\n", $name);

    $jsonDecode = benchmark(fn() => json_decode($json), $duration);
    $compiledPs = benchmark(fn() => $compiled->parse($json), $duration);
    $interpretedPs = benchmark(fn() => $interpreted->parse($json, 'bench'), $duration);

    $results[$name] = [
        'json_decode' => $jsonDecode,
        'compiled' => $compiledPs,
        'interpreted' => $interpretedPs,
    ];
}

// Format output
$timestamp = date('Y-m-d H:i:s');
$phpVersion = PHP_VERSION;
$jitEnabled = function_exists('opcache_get_status')
    && ($status = opcache_get_status(false))
    && ($status['jit']['enabled'] ?? false);
$jitLabel = $jitEnabled ? ', JIT enabled' : '';

$output = "# $timestamp - $comment (PHP $phpVersion$jitLabel)\n";
$output .= sprintf("%-12s %12s %12s %12s %8s %8s\n",
    'Test', 'json_decode', 'Compiled', 'Interpreted', 'C/J', 'I/C');
$output .= str_repeat('-', 70) . "\n";

foreach ($results as $name => $r) {
    $cjRatio = $r['json_decode'] / $r['compiled'];
    $icRatio = $r['compiled'] / $r['interpreted'];

    $output .= sprintf("%-12s %10.0f/s %10.0f/s %10.0f/s %7.1fx %7.1fx\n",
        $name,
        $r['json_decode'],
        $r['compiled'],
        $r['interpreted'],
        $cjRatio,
        $icRatio
    );
}
$output .= "\n";

// Print to stdout
echo $output;

// Append to log file
$logDir = __DIR__ . '/../benchmarks';
if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
}
file_put_contents("$logDir/json-parser.log", $output, FILE_APPEND);

fprintf(STDERR, "Results appended to benchmarks/json-parser.log\n");
