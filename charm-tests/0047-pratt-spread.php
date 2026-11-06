<?php
/**
 * Spread (`...`) in the alternatives of a left-recursive (Pratt) rule: operator alternatives honour it
 * like plain sequences do, in both engines. The compiled parser used to ignore it there (and so did the
 * interpreter's operator path; only the non-left-recursive alternatives spread).
 */
require_once __DIR__ . '/support/conformance.php';

return [
    'Spread in left-recursive rules, interpreted and compiled',
    'conformance_case',

    // operator alternative: spread after the operator
    [ ['E = E "+" ...A | /[0-9]/
A = "x" "y"', '1+xy'], both(['1', '+', 'x', 'y']) ],
    [ ['E = E "+" ...A* | /[0-9]/
A = "x"', '1+xx'], both(['1', '+', 'x', 'x']) ],
    [ ['E = E "+" ...A* | /[0-9]/
A = "x"', '1+'], both(['1', '+']) ],
    [ ['E = E "+" ...A* | /[0-9]/
A = "x"', '1'], both('1') ],
    // the operator's value is re-wrapped: the next round spreads nothing extra without a spread on E
    [ ['E = E "+" ...A* | /[0-9]/
A = "x"', '1+x+x'], both([['1', '+', 'x'], '+', 'x']) ],
    [ ['E = ...E "+" ...A* | /[0-9]/
A = "x"', '1+x+x'], both(['1', '+', 'x', '+', 'x']) ],
    // named spread: captured whole, and flattened too
    [ ['E = E "+" ...n:A* | /[0-9]/
A = "x"', '1+xx'], both([0 => '1', 1 => '+', 'n' => ['x', 'x'], 2 => 'x', 3 => 'x']) ],
    [ ['E = ...l:E "+" "x" | /[0-9]/', '1+x'], both(['l' => '1', 0 => '1', 1 => '+', 2 => 'x']) ],
    // spread on the left operand and on the right operand
    [ ['E = ...E "+" "x" | /[0-9]/', '1+x'], both(['1', '+', 'x']) ],
    [ ['E = E "+" ...E | /[0-9]/', '1+2'], both(['1', '+', '2']) ],
    [ ['E = E "+" ...n:E | /[0-9]/', '1+2'], both([0 => '1', 1 => '+', 'n' => '2', 2 => '2']) ],
    [ ['E = E "+" ...E | /[0-9]/', '1+2+3'], both(['1', '+', '2', '+', '3']) ],
    // spread of a group, two spreads
    [ ['E = E ...("+" "-") | /[0-9]/', '1+-'], both(['1', '+', '-']) ],
    [ ['E = E "+" ...A* ...B* | /[0-9]/
A = "x"
B = "y"', '1+xxyy'], both(['1', '+', 'x', 'x', 'y', 'y']) ],
    // no spread: unchanged
    [ ['E = E "+" A* | /[0-9]/
A = "x"', '1+xx'], both(['1', '+', ['x', 'x']]) ],
    // snippets after a spread see the flattened elements
    [ ['E = E "+" ...A* <? return $context; ?> | /[0-9]/
A = "x"', '1+xx'], both(['1', '+', 'x', 'x']) ],
    [ ['E = E "+" ...A* <? return [$_0, $_1, $_2]; ?> | /[0-9]/
A = "x"', '1+xx'], both(['1', '+', 'x']) ],
    [ ['E = E "+" ...A* <? return count($context); ?> | /[0-9]/
A = "x"', '1+xxx'], both(5) ],
    // the non-left-recursive alternatives (always spread)
    [ ['E = E "+" E | ...A "-"
A = "x"', 'x-'], both(['x', '-']) ],
    [ ['E = E "+" E | "(" ...A* ")"
A = "x"', '(xx)'], both(['(', 'x', 'x', ')']) ],
    [ ['E = E "+" E | n:"(" ...m:A* ")"
A = "x"', '(xx)'], both(['n' => '(', 'm' => ['x', 'x'], 0 => 'x', 1 => 'x', 2 => ')']) ],
    [ ['E = E "+" E | "(" ...A* ")" <? return $context; ?>
A = "x"', '(xx)'], both(['(', 'x', 'x', ')']) ],
];
