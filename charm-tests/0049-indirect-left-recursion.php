<?php
/**
 * Indirect left recursion: a rule that reaches itself in left position through another rule
 * (`A = B "x" | "y"`, `B = A "z"`) is seed-grown in both engines (Rule::parse() / the generated
 * parseX() wrapper). Before, the interpreter treated `B "x"` as if `B` were `A` itself and returned the
 * non-recursive prefix, and a compiled parser could hang.
 */
require_once __DIR__ . '/support/conformance.php';

$ab = 'A = B "x" | "y"
B = A "z"';
$abw = 'A = B "x" | "y"
B = A "z" | "w"';
$call = 'E = Call | Num
Call = E "(" E? ")" <? return ["call", $_0, $_2]; ?>
Num = /[0-9]+/';

return [
    "Indirect left recursion (seed growing)",
    'conformance_position',

    // the documented example: grown as far as the input allows, grouping to the left
    [ [$ab, 'yzx'], both([['y', 'z'], 'x']) ],
    [ [$ab, 'yzxzx'], both([[[['y', 'z'], 'x'], 'z'], 'x']) ],
    // the seed alone
    [ [$ab, 'y'], both('y') ],
    [ [$ab, 'yz'], both('y') ],
    // nothing to seed with
    [ [$ab, 'q'], both('ERROR@1:1') ],
    // two recursive alternatives in the other rule (used to crash the interpreter / hang when compiled)
    [ ['A = B "x" | "y"
B = A "z" | A "w"', 'ywxzx'], both([[[['y', 'w'], 'x'], 'z'], 'x']) ],
    // the other rule can also provide the seed; a stale intermediate result is not reused
    [ [$abw, 'wxzx'], both([[['w', 'x'], 'z'], 'x']) ],
    [ [$abw, 'wzx'], both('ERROR@1:2') ],
    // entering the cycle from the other rule
    [ ['B = A "z" | "w"
A = B "x" | "y"', 'wxzxz'], both([[[['w', 'x'], 'z'], 'x'], 'z']) ],
    // the grown result is what the caller sees: $ after it, and the longest attempt sets the error position
    [ ['S = A $
A = B "x" | "y"
B = A "z"', 'yzx'], both([['y', 'z'], 'x']) ],
    [ ['S = A $
A = B "x" | "y"
B = A "z"', 'yzxz'], both('ERROR@1:5') ],
    // a cycle through an optional prefix is still a cycle
    [ ['A = "-"? B "x" | "y"
B = A "z"', 'yzx'], both([null, ['y', 'z'], 'x']) ],
    [ ['A = "-"? B "x" | "y"
B = A "z"', '-yzx'], both(['-', ['y', 'z'], 'x']) ],
    // direct and indirect recursion in the same rule: the precedence loop handles `A "x"`, growing handles `B "y"`
    [ ['A = A "x" | B "y" | "z"
B = A "w"', 'zwyx'], both([[['z', 'w'], 'y'], 'x']) ],
    // postfix call syntax through a helper rule, with a snippet
    [ [$call, '1(2)(3)'], both(['call', ['call', '1', '2'], '3']) ],
    [ [$call, '1()'], both(['call', '1', null]) ],
    [ [$call, '1(2)('], both(['call', '1', '2']) ],
    // snippets in a grown rule run once per growth step; $state keeps every write (as always)
    [ ['A = B "x" <? $state["n"] = ($state["n"] ?? 0) + 1; return $_0 . $_1 . $state["n"]; ?> | "y"
B = A "z" <? return $_0 . $_1; ?>', 'yzxzx'], both('yzx1zx2') ],
    // in a cycle an alternative's precedence has no effect: the right operand is parsed greedily
    [ ['E = T
T = E "*" E %prec=2 | E "+" E %prec=1 | /[0-9]/', '1+2*3'], both(['1', '+', ['2', '*', '3']]) ],
    [ ['E = T
T = E "*" E %prec=2 | E "+" E %prec=1 | /[0-9]/', '1*2+3'], both(['1', '*', ['2', '+', '3']]) ],
    // a rule that only calls itself after consuming input is not a cycle (plain recursion)
    [ ['A = "(" B ")" | "y"
B = A "z"', '(yz)'], both(['(', ['y', 'z'], ')']) ],
];
