<?php
namespace Charm;

function cpeg_parse(string $expression, string $subject, array $initialState=[]) {
    static $cache = null;
    if ($cache === null) {
        $cache = [];
    }

    $hash = md5($expression);
    if (!isset($cache[$hash])) {
        $cache[$hash] = cpeg_create($expression);
        if (count($cache) > 20) {
            // we're apparently parsing a lot of different grammars, so avoid memory leaks
            array_shift($cache);
        }
    }
    foreach (debug_backtrace(0) as $t) {
        if ($t['file'] !== __FILE__) {
            break;
        }
    }

    $result = $cache[$hash]->parse($subject, $t['file'], $t['line'], 0, $initialState);

    return $result;
}

/**
 * Create a parser instance from an expression.
 */
function cpeg_create(string $expression): Parsing\CPEGParser {
    static $grammarParser = null;
    if ($grammarParser === null) {
        $grammarParser = new Parsing\GrammarParser();
    }
    foreach (debug_backtrace(0) as $t) {
        if ($t['file'] !== __FILE__) {
            break;
        }
    }
    $filename = $t['file'];
    $lineOffset = $t['line'];

    $grammar = $grammarParser->parse($expression, $filename, $lineOffset);

    return new Parsing\CPEGParser($grammar, null, new Parsing\Options(['trace' => true]));
}
