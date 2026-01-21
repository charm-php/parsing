<?php return [
    "Testing the \\nothing clause (epsilon production)",
    function($args) {
        [$grammar, $input] = $args;
        return Charm\cpeg_parse($grammar, $input);
    },

    // Basic \nothing - always succeeds, returns true (excluded from sequence arrays)
    [ ['R = "a" \\nothing "b"', 'ab'], ['a', 'b'] ],

    // \nothing as fallback in choice - returns Signal::HIT when chosen
    [ ['R = "x" | \\nothing', 'x'], 'x' ],
    [ ['R = "x" | \\nothing', ''], \Charm\Parsing\Signal::HIT ],

    // \nothing in optional context (a bit redundant but should work)
    [ ['R = "a" \\nothing? "b"', 'ab'], ['a', 'b'] ],

    // \nothing for empty alternatives - true is filtered from sequences
    [ ['R = "prefix" Rest; Rest = "suffix" | \\nothing', 'prefixsuffix'], ['prefix', 'suffix'] ],
    [ ['R = "prefix" Rest; Rest = "suffix" | \\nothing', 'prefix'], ['prefix'] ],

    // Using ε character (also works)
    [ ['R = "a" ε "b"', 'ab'], ['a', 'b'] ],

    // \nothing with spread - spreads nothing
    [ ['R = "a" ...\\nothing "b"', 'ab'], ['a', 'b'] ],

    // Multi-rule grammar with \nothing as optional match
    [ ['R = "(" Opt ")"
        Opt = "x" | \\nothing', '(x)'], ['(', 'x', ')'] ],
    [ ['R = "(" Opt ")"
        Opt = "x" | \\nothing', '()'], ['(', ')'] ],
];
