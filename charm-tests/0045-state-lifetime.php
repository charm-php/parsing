<?php
/**
 * `$state` starts from the initial state given to every `parse()`: a compiled parser used to keep what
 * the previous parse on the same object had left (the interpreter always started afresh). `$state` is not
 * rolled back when the parser backtracks - in both engines.
 */
use Charm\Parsing\Compiler\GrammarCompiler;
use Charm\Parsing\GrammarParser;
use Charm\Parsing\GrammarPreprocessor;

require_once __DIR__ . '/support/conformance.php';

$counter = 'S = "a" <? $state["n"] = ($state["n"] ?? 0) + 1; return $state["n"]; ?>';
$backtrack = 'S = A "x" | B
A = "a" <? $state["n"] = ($state["n"] ?? 0) + 1; return 1; ?>
B = "a" <? return $state["n"]; ?>';

return [
    "State lifetime",
    function (array $args) {
        [$grammar, $inputs, $seed] = $args;
        $interpreted = array_map(fn($i) => \Charm\cpeg_parse($grammar, $i, $seed), $inputs);
        $class = 'StateLifetime_' . substr(md5($grammar . serialize($seed)), 0, 8);
        if (!class_exists($class)) {
            $g = (new GrammarParser())->parse((new GrammarPreprocessor())->process($grammar), 'test');
            eval('?>' . (new GrammarCompiler())->compile($g, $class));
        }
        $parser = new $class();
        $compiled = array_map(fn($i) => $parser->parse($i, $seed), $inputs);
        return [$interpreted, $compiled];
    },

    // two parses on one compiled instance, no state passed: each starts empty
    [ [$counter, ['a', 'a', 'a'], []], both([1, 1, 1]) ],
    // the initial state is the starting point of every parse
    [ [$counter, ['a', 'a'], ['n' => 10]], both([11, 11]) ],
    // $state is not rolled back on backtracking: A's snippet ran before "x" failed
    [ [$backtrack, ['a'], []], both([1]) ],
    [ [$backtrack, ['a', 'a'], []], both([1, 1]) ],
];
