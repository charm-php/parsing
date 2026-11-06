<?php
/**
 * Conformance (see 0030): %silent, %assoc argument validation, the `_` whitespace convention,
 * multi-rule grammars with recursion.
 */
require_once __DIR__ . '/support/conformance.php';

$E = 'PARSE_ERROR';
$ws = "\n_ = /\\s*/ %silent";
return [
    "Conformance: %silent, pragma errors, whitespace convention, recursive rules",
    'conformance_case',

    // %silent rules contribute no value
    [ ['S = "a" _ "b"' . $ws, 'a  b'], both(['a', 'b']) ],
    [ ['S = "a" _ "b"' . $ws, 'ab'], both(['a', 'b']) ],
    [ ['S = "a" _ "b"' . $ws, "a\n\t b"], both(['a', 'b']) ],
    [ ['S = "a" _ "b"' . $ws, 'a  c'], both($E) ],
    [ ['S = _ "a" _' . $ws, '  a  '], both('a') ],
    [ ['S = _ "a" _ $' . $ws, '  a  x'], both($E) ],
    [ ['S = "a" _ "b"
_ = /\s*/', 'a  b'], both(['a', '  ', 'b']) ],
    [ ['S = "a" _ "b" <? return $_0 . $_1; ?>' . $ws, 'a  b'], both('ab') ],
    [ ['S = A+' . $ws . '
A = /[a-z]/ %silent', 'abc'], both(['a', 'b', 'c']) ],
    // a silent rule referenced by name is captured regardless
    [ ['S = "a" w:_ "b"' . $ws, 'a  b'], both([0 => 'a', 'w' => '  ', 1 => 'b']) ],
    // the usual token-with-trailing-whitespace pattern
    [ ['List = Num ("," Num)* $
Num = /[0-9]+/ _ <? return intval($_0); ?>' . $ws, '1 ,2 ,3 '], both([1, [[',', 2], [',', 3]]]) ],
    [ ['Call = Name _ "(" _ Name _ ")"
Name = /[a-z]+/' . $ws, 'f ( x )'], both(['f', '(', 'x', ')']) ],
    // %silent on a rule that is a repeated group
    [ ['S = "a" Sp "b"
Sp = (" " | "\t")+ %silent', "a \t b"], both(['a', 'b']) ],
    // silent on the pragma-bearing alternative only
    [ ['S = "a" X "b"
X = "x" %silent | "y"', 'axb'], both(['a', 'x', 'b']) ],   // the pragma binds to the alternative, not to rule X
    [ ['S = "a" X "b"
X = "x" %silent | "y"', 'ayb'], both(['a', 'y', 'b']) ],

    // pragma validation (grammar time)
    [ ['S = "a" %foo', 'a'], both('LogicException: Unknown pragma directive %foo. Supported: %assoc=left|right|none, %prec=N, %silent') ],
    [ ['S = "a" "b" %assoc=sideways', 'ab'], both("LogicException: Pragma directive %assoc requires an argument 'left', 'right' or 'none', e.g. '%assoc=right'") ],

    // multi-rule grammars with recursion
    [ ['Paren = "(" Paren ")" | "x"', '((x))'], both(['(', ['(', 'x', ')'], ')']) ],
    [ ['Paren = "(" Paren ")" | "x"', '((x)'], both($E) ],
    [ ['Paren = "(" Paren ")" | "x"', 'x'], both('x') ],
    [ ['List = "[" (Item ("," Item)*)? "]"
Item = List | /[0-9]+/', '[1,[2,3],[]]'],
        both(['[', ['1', [[',', ['[', ['2', [[',', '3']]], ']']], [',', ['[', null, ']']]]], ']']) ],
    [ ['List = "[" (Item ("," Item)*)? "]"
Item = List | /[0-9]+/', '[1,'], both($E) ],
    [ ['E = T (("+" | "-") T)*
T = F (("*" | "/") F)*
F = "(" E ")" | /[0-9]+/', '1+2*3'], both([['1', []], [['+', ['2', [['*', '3']]]]]]) ],
    // mutual recursion
    [ ['A = "a" B | "e"
B = "b" A | "e"', 'abae'], both(['a', ['b', ['a', 'e']]]) ],
    [ ['A = "a" B | "e"
B = "b" A | "e"', 'abb'], both($E) ],
    // rule ordering: the first rule is the goal
    [ ['S = T
T = "t"', 't'], both('t') ],
    [ ['T = "t"
S = "s"', 't'], both('t') ],
    [ ['T = "t"
S = "s"', 's'], both($E) ],
];
