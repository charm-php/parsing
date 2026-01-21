<?php
/**
 * Grammar Compilation Benchmark
 *
 * Measures how fast CPEG parses grammar definitions and prepares them for parsing.
 * This is the "compile time" cost that happens once per grammar.
 *
 * Future: Compare against compiled PHP parser classes that skip this step entirely.
 */

use Charm\Parsing\GrammarParser;
use Charm\Parsing\CPEGParser;
use Charm\Parsing\Grammar;

// Simple grammar - just a few rules
$simpleGrammar = <<<'GRAMMAR'
Number = /[0-9]+/           <? return intval($_0); ?>
GRAMMAR;

// Medium grammar - typical expression parser
$mediumGrammar = <<<'GRAMMAR'
Expr = Expr "+" Expr        <? return $_0 + $_2; ?>
     | Expr "-" Expr        <? return $_0 - $_2; ?>
     | Expr "*" Expr        <? return $_0 * $_2; ?>
     | Expr "/" Expr        <? return $_0 / $_2; ?>
     | "(" Expr ")"         <? return $_1; ?>
     | Number
Number = /[0-9]+/ _         <? return intval($_0); ?>
_ = /\s*/
GRAMMAR;

// Complex grammar - PHP-like expression precedence
$complexGrammar = <<<'GRAMMAR'
Calculate "formula"
=   ^ Expr $                        <? return $_0; ?>

Expr "expression"
=   Expr "**" _ Expr                                                        %assoc=right
|   ("++" | "--" | [-+~@]) _ Expr
|   Expr ("++" | "--") _
|   Expr "instanceof" _ Expr
|   "!" Expr
|   Expr [*/%] _ Expr
|   Expr [-+] _ Expr
|   Expr /<<|>>/ _ Expr
|   Expr "." _ Expr
|   Expr /<=|>=|<|>/ _ Expr                                                 %assoc=none
|   Expr /===|==|!==|!=|<>|<=>/ _ Expr                                      %assoc=none
|   Expr "&" _ Expr
|   Expr "^" _ Expr
|   Expr "|" _ Expr
|   Expr "&&" _ Expr
|   Expr "||" _ Expr
|   Expr "??" _ Expr                                                        %assoc=right
|   Expr "?" _ Expr ":" _ Expr                                              %assoc=none
|   Expr /=|\+=|-=|\*\*=|\*=|\/=|\.=|%=|&=|\|=|\^=|<<=|>>=|\?\?=/ _ Expr    %assoc=right
|   "yield" _ "from" _ Expr
|   "yield" _ Expr
|   "print" _ Expr
|   Expr "and" _ Expr
|   Expr "xor" _ Expr
|   Expr "or" _ Expr
|   Primary

Primary
=   "(" _ Expr _ ")"                <? return $_1; ?>
|   (FLOAT / INTEGER) _             <? return $_0; ?>

FLOAT
=   /(0|[1-9][0-9]*)(\.[0-9]+)/     <? return floatval($_0); ?>

INTEGER
=   /(0|[1-9][0-9]*)/               <? return intval($_0); ?>

_
=   /\s*/                           <? return true; ?>
GRAMMAR;

// JSON grammar (simplified - wraps booleans to avoid snippet conventions)
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

$grammars = [
    'simple'   => $simpleGrammar,
    'medium'   => $mediumGrammar,
    'complex'  => $complexGrammar,
    'json'     => $jsonGrammar,
];

$iterations = [
    'simple'   => 1000,
    'medium'   => 500,
    'complex'  => 100,
    'json'     => 200,
];

echo "Grammar Compilation (GrammarParser parses grammar, Grammar constructor prepares it)\n";
echo str_repeat("-", 70)."\n";

$results = [];

foreach ($grammars as $name => $grammarText) {
    $count = $iterations[$name];
    $grammarSize = strlen($grammarText);

    // Warmup
    for ($i = 0; $i < 3; $i++) {
        GrammarParser::clearCache();
        $gp = new GrammarParser();
        $grammar = $gp->parse($grammarText, 'benchmark');
    }

    // Benchmark
    $start = hrtime(true);
    for ($i = 0; $i < $count; $i++) {
        GrammarParser::clearCache();
        $gp = new GrammarParser();
        $grammar = $gp->parse($grammarText, 'benchmark');
    }
    $elapsed = (hrtime(true) - $start) / 1e9;

    $perSec = $count / $elapsed;
    $msPerGrammar = ($elapsed / $count) * 1000;

    $results[$name] = [
        'iterations' => $count,
        'elapsed' => $elapsed,
        'per_sec' => $perSec,
        'ms_per_grammar' => $msPerGrammar,
        'grammar_bytes' => $grammarSize,
    ];

    printf("%-12s %6d iterations  %8.2f ms/grammar  %8.1f grammars/sec  (%d bytes)\n",
        $name, $count, $msPerGrammar, $perSec, $grammarSize);
}

echo "\n";

// Measure serialization (for caching compiled grammars)
echo "Grammar Serialization (for caching)\n";
echo str_repeat("-", 70)."\n";

foreach ($grammars as $name => $grammarText) {
    $gp = new GrammarParser();
    $grammar = $gp->parse($grammarText, 'benchmark');
    // prepare() is called automatically in Grammar constructor

    // Serialize
    $start = hrtime(true);
    for ($i = 0; $i < 100; $i++) {
        $serialized = serialize($grammar);
    }
    $serializeTime = (hrtime(true) - $start) / 1e9 / 100 * 1000;

    // Unserialize
    $start = hrtime(true);
    for ($i = 0; $i < 100; $i++) {
        $restored = unserialize($serialized);
    }
    $unserializeTime = (hrtime(true) - $start) / 1e9 / 100 * 1000;

    printf("%-12s serialize: %6.2f ms  unserialize: %6.2f ms  size: %d bytes\n",
        $name, $serializeTime, $unserializeTime, strlen($serialized));
}

echo "\n";

// Compiled Parser - one-time generation cost
echo "Compiled Parser Generation\n";
echo str_repeat("-", 70)."\n";

use Charm\Parsing\Compiler\GrammarCompiler;

$compiler = new GrammarCompiler();

foreach ($grammars as $name => $grammarText) {
    $gp = new GrammarParser();
    $grammar = $gp->parse($grammarText, 'benchmark');

    // Measure compilation time
    $start = hrtime(true);
    for ($i = 0; $i < 100; $i++) {
        $code = $compiler->compile($grammar, 'Benchmark' . ucfirst($name));
    }
    $compileTime = (hrtime(true) - $start) / 1e9 / 100 * 1000;

    printf("%-12s compile: %6.2f ms  output: %d bytes\n",
        $name, $compileTime, strlen($code));
}

echo "\nCompiled parsers:\n";
echo "  - Skip grammar parsing at runtime (grammar baked into PHP code)\n";
echo "  - Zero 'compile time' overhead after initial generation\n";
echo "  - Standalone classes with no library dependencies\n";
echo "  - Support opcache preloading for instant startup\n";
echo "  - ~10x faster parsing than interpreted (see expression benchmark)\n";
