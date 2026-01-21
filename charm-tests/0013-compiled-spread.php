<?php
use Charm\Parsing\GrammarParser;
use Charm\Parsing\Compiler\GrammarCompiler;

return [
    "Testing the ... spread operator in compiled parsers",
    function($args) {
        [$grammar, $input] = $args;

        static $parsers = [];

        $key = md5($grammar);
        if (!isset($parsers[$key])) {
            // Parse the grammar
            $gp = new GrammarParser();
            $grammarObj = $gp->parse($grammar, 'test');

            // Compile to PHP code
            $compiler = new GrammarCompiler();
            $className = 'CompiledSpreadParser_' . $key;
            $code = $compiler->compile($grammarObj, $className);

            // Write to temp file and include
            $tempFile = sys_get_temp_dir() . '/' . $className . '.php';
            if (!file_exists($tempFile)) {
                file_put_contents($tempFile, $code);
            }
            require_once $tempFile;

            $parsers[$key] = new $className();
        }

        return $parsers[$key]->parse($input);
    },

    // Basic spread with kleene star
    [ ['R = ...("a" "b")* "c"', 'ababc'], ['a', 'b', 'a', 'b', 'c'] ],

    // Spread with kleene plus (needs to be in sequence context)
    [ ['R = ...("x" "y")+ "end"', 'xyxyend'], ['x', 'y', 'x', 'y', 'end'] ],

    // Spread with single repetition
    [ ['R = ...("a" "b")* "z"', 'abz'], ['a', 'b', 'z'] ],

    // Spread with zero repetitions (empty array spread)
    [ ['R = ...("a" "b")* "z"', 'z'], ['z'] ],

    // Spread via rule reference
    [ ['R = ...Pair* "end"; Pair = "a" "b"', 'ababend'], ['a', 'b', 'a', 'b', 'end'] ],

    // Multiple spreads in sequence
    [ ['R = ...("a")* ":" ...("b")*', 'aa:bbb'], ['a', 'a', ':', 'b', 'b', 'b'] ],

    // Spread with optional that matches
    [ ['R = ...("x" "y")? "z"', 'xyz'], ['x', 'y', 'z'] ],

    // Spread with optional that does not match - null should be skipped
    [ ['R = ...("x" "y")? "z"', 'z'], ['z'] ],
];
