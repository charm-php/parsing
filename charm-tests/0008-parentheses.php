<?php return [
    "Testing parentheses in left-recursive expressions",
    function($input) {
        return Charm\cpeg_parse(<<<'GRAMMAR'
                Calculate "formula"
                =   ^ Expr $                        <? return $_0; ?>

                Expr "expression"
                =   Expr "**" Expr                  %assoc=right
                |   Expr "*" Expr
                |   Expr "/" Expr
                |   Expr "+" Expr
                |   Expr "-" Expr
                |   Primary

                Primary
                =   "(" Expr ")"                    <? return $_1; ?>
                |   INTEGER                         <? return $_0; ?>

                INTEGER
                =   /[0-9]+/                        <? return intval($_0); ?>
            GRAMMAR,

            $input,
        );
    },
    // Basic precedence still works
    [ "1+2*3",          [1, "+", [2, "*", 3]] ],
    // Parentheses override precedence
    [ "(1+2)*3",        [[1, "+", 2], "*", 3] ],
    // Right associativity still works
    [ "2**3**2",        [2, "**", [3, "**", 2]] ],
    // Parentheses override right associativity
    [ "(2**3)**2",      [[2, "**", 3], "**", 2] ],
    // Left associativity
    [ "10-3-2",         [[10, "-", 3], "-", 2] ],
    // Parentheses on right
    [ "10-(3-2)",       [10, "-", [3, "-", 2]] ],
    // Nested parentheses
    [ "((1+2))",        [1, "+", 2] ],
    // Parentheses on both sides
    [ "(1+2)*(3+4)",    [[1, "+", 2], "*", [3, "+", 4]] ],
    // Deeply nested
    [ "2*((3+4)*5)",    [2, "*", [[3, "+", 4], "*", 5]] ],
];
