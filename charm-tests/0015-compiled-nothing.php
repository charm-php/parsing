<?php

use Charm\Parsing\GrammarParser;
use Charm\Parsing\Compiler\GrammarCompiler;

return [
    "Testing compiled parser with \\nothing clause",
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
            $className = 'CompiledNothingParser_' . $key;
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

    // Basic \nothing - always succeeds, returns true (excluded from sequence arrays)
    [ ['R = "a" \\nothing "b"', 'ab'], ['a', 'b'] ],

    // \nothing as fallback in choice - returns true when chosen
    [ ['R = "x" | \\nothing', 'x'], 'x' ],
    [ ['R = "x" | \\nothing', ''], true ],

    // \nothing in optional context
    [ ['R = "a" \\nothing? "b"', 'ab'], ['a', 'b'] ],

    // \nothing for empty alternatives - true is filtered from sequences
    [ ['R = "prefix" Rest
        Rest = "suffix" | \\nothing', 'prefixsuffix'], ['prefix', 'suffix'] ],
    [ ['R = "prefix" Rest
        Rest = "suffix" | \\nothing', 'prefix'], ['prefix'] ],

    // \nothing with spread - spreads nothing
    [ ['R = "a" ...\\nothing "b"', 'ab'], ['a', 'b'] ],
];
