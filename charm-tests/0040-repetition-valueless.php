<?php
/**
 * A value-less iteration (it matches but produces no value: `ε`, a predicate, `^`, `$`) advances
 * `*` / `+` but is never collected - the engine's internal Signal::HIT must not leak into results.
 * Both engines.
 */
require_once __DIR__ . '/support/conformance.php';

$E = 'PARSE_ERROR';
return [
    "Repetition does not collect value-less iterations",
    'conformance_case',

    [ ['S = ε*', ''], both([]) ],
    [ ['S = ε*', 'abc'], both([]) ],
    [ ['S = ε+', ''], both([]) ],
    [ ['S = ε+', 'abc'], both([]) ],
    [ ['S = (&"a")*', 'a'], both([]) ],
    [ ['S = (&"a")+', 'b'], both($E) ],
    [ ['S = (!"a")*', 'b'], both([]) ],
    [ ['S = ("a" | ε)*', 'aab'], both(['a', 'a']) ],
    [ ['S = ("a" | ε)+', 'aab'], both(['a', 'a']) ],
    [ ['S = ("a" | ε)+', 'b'], both([]) ],
    [ ['S = "x" ε* "y"', 'xy'], both(['x', [], 'y']) ],
];
