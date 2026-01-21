<?php
use Charm\Parsing\GrammarParser;
use Charm\Parsing\Compiler\GrammarCompiler;

return [
    "Testing the GrammarCompiler that generates standalone PHP parser classes",
    function($input) {

        static $compiledParser = null;

        if ($compiledParser === null) {
            // Parse the grammar
            $gp = new GrammarParser();
            $grammar = $gp->parse(<<<'GRAMMAR'
                Expr = Expr "*" Expr
                     | Expr "/" Expr
                     | Expr "+" Expr
                     | Expr "-" Expr
                     | Number
                Number = /[0-9]+/
                GRAMMAR,
                'test'
            );

            // Compile to PHP code
            $compiler = new GrammarCompiler();
            $code = $compiler->compile($grammar, 'CompiledExprParser');

            // Write to temp file and include
            $tempFile = sys_get_temp_dir() . '/CompiledExprParser_' . md5($code) . '.php';
            if (!file_exists($tempFile)) {
                file_put_contents($tempFile, $code);
            }
            require_once $tempFile;

            $compiledParser = new \CompiledExprParser();
        }

        return $compiledParser->parse($input);
    },
    // Simple numbers
    [ "1", "1" ],
    [ "42", "42" ],
    [ "123", "123" ],

    // Two operand expressions
    [ "1+2", ["1", "+", "2"] ],
    [ "3*4", ["3", "*", "4"] ],
    [ "10/2", ["10", "/", "2"] ],
    [ "5-3", ["5", "-", "3"] ],

    // Operator precedence (* and / bind tighter than + and -)
    [ "1+2*3", ["1", "+", ["2", "*", "3"]] ],
    [ "2*3+4", [["2", "*", "3"], "+", "4"] ],
    [ "1+2*3+4", [["1", "+", ["2", "*", "3"]], "+", "4"] ],
    [ "1*2+3*4", [["1", "*", "2"], "+", ["3", "*", "4"]] ],

    // Left associativity
    [ "1+2+3", [["1", "+", "2"], "+", "3"] ],
    [ "1-2-3", [["1", "-", "2"], "-", "3"] ],
    [ "2*3*4", [["2", "*", "3"], "*", "4"] ],
    [ "8/4/2", [["8", "/", "4"], "/", "2"] ],

    // Mixed precedence and associativity
    // Note: In this grammar, + has higher precedence than -, and * higher than /
    // So 1+2-3 parses as (1+2)-3, but 1-2+3 parses as 1-(2+3)
    [ "1+2-3", [["1", "+", "2"], "-", "3"] ],
    [ "1-2+3", ["1", "-", ["2", "+", "3"]] ],
    [ "2*3/4", [["2", "*", "3"], "/", "4"] ],
    [ "2/3*4", ["2", "/", ["3", "*", "4"]] ],
];
