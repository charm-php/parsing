#!/usr/bin/env php
<?php
/**
 * Charm/Parsing Benchmark Suite
 *
 * Run all benchmarks:     php benchmarks/run.php
 * Run specific benchmark: php benchmarks/run.php json
 *
 * Available benchmarks: grammar, json, expression, all
 */

require __DIR__.'/../vendor/autoload.php';

$benchmarks = [
    'grammar'    => __DIR__.'/grammar-compilation.php',
    'json'       => __DIR__.'/json-parsing.php',
    'expression' => __DIR__.'/expression-parsing.php',
];

$requested = $argv[1] ?? 'all';

echo "Charm/Parsing Benchmark Suite\n";
echo "PHP ".PHP_VERSION." | ".php_uname('s')." ".php_uname('m')."\n";
echo "JIT: ".(function_exists('opcache_get_status') && (opcache_get_status()['jit']['enabled'] ?? false) ? 'enabled' : 'disabled')."\n";
echo str_repeat("=", 70)."\n\n";

if ($requested === 'all') {
    foreach ($benchmarks as $name => $file) {
        echo "## $name\n\n";
        require $file;
        echo "\n";
    }
} elseif (isset($benchmarks[$requested])) {
    echo "## $requested\n\n";
    require $benchmarks[$requested];
} else {
    echo "Unknown benchmark: $requested\n";
    echo "Available: ".implode(', ', array_keys($benchmarks)).", all\n";
    exit(1);
}
