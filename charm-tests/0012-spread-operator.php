<?php return [
    "Testing the ... spread operator for flattening array results",
    function($args) {
        [$grammar, $input] = $args;
        return Charm\cpeg_parse($grammar, $input);
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

    // Spread on single literal value (passes through unchanged in sequence)
    [ ['R = ..."a" "b"', 'ab'], ['a', 'b'] ],

    // Spread on ordered choice with repetition
    [ ['R = ...("a" | "b")* "end"', 'abaend'], ['a', 'b', 'a', 'end'] ],
];
