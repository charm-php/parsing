<?php
/**
 * A left-recursive alternative whose part after the rule can match nothing (`E = E A? | "x"`) used to loop
 * forever in both engines (and exhaust the stack). An operator alternative that consumes nothing now ends
 * the precedence loop and is not taken, like an empty iteration of `*`.
 */
require_once __DIR__ . '/support/conformance.php';

return [
    "Left recursion: operator alternative that consumes nothing",
    'conformance_position',

    [ ['E = E A? | "x"
A = "+"', 'x++'], both([['x', '+'], '+']) ],
    [ ['E = E A? | "x"
A = "+"', 'x'], both('x') ],
    [ ['E = E (A "-")* | "x"
A = "+"', 'x+-+-'], both(['x', [['+', '-'], ['+', '-']]]) ],
    [ ['E = E (A "-")* | "x"
A = "+"', 'x'], both('x') ],
    [ ['E = E "!"? | "x"', 'x!!y'], both([['x', '!'], '!']) ],
    [ ['E = E "!"? | "x"', 'y'], both('ERROR@1:1') ],
    // the same without the quantifier, for reference
    [ ['E = E "!" | "x"', 'x!!'], both([['x', '!'], '!']) ],
];
