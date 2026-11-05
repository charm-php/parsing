<?php
/**
 * Conformance (see 0030): rows added while making the two engines agree - cases the earlier files
 * did not pin down. Every row expects the interpreter and the compiled parser to give the same result.
 */
require_once __DIR__ . '/support/conformance.php';

$E = 'PARSE_ERROR';
return [
    "Conformance: engine agreement regressions",
    'conformance_case',

    // ordered choice: an alternative that can match nothing is not skipped by first-byte dispatch
    [ ['S = "x"? "a" | "b"', 'xa'], both(['x', 'a']) ],
    [ ['S = "x"? "a" | "b"', 'a'], both([null, 'a']) ],
    [ ['S = "x"? "a" | "b"', 'b'], both('b') ],
    [ ['S = !"a" | "b"', 'b'], both(null) ],
    [ ['S = ("a" | "")  "b"', 'b'], both(['', 'b']) ],
    // a later terminal still gets its turn when the suffix after an earlier one fails
    [ ['S = "a" "z" | "ab" "z"', 'abz'], both(['ab', 'z']) ],
    [ ['S = "a" "z" <? return "short"; ?> | "ab" "z" <? return "long"; ?>', 'abz'], both('long') ],

    // named captures do not take a positional slot
    [ ['S = x:"a" "b" <? return $_0 . $x; ?>', 'ab'], both('ba') ],
    [ ['S = "a" y:"b" "c" <? return $_0 . $_1 . $y; ?>', 'abc'], both('acb') ],

    // a snippet that throws surfaces as a SnippetError from both engines (also without a return)
    [ ['S = "a" <? $x = 1 / 0; ?>', 'a'], both('Charm\Parsing\Error\SnippetError: Error in snippet: Division by zero') ],
    // $MISS and $HIT are available to snippets of both engines
    [ ['S = /[0-9]+/ <? return intval($_0) < 10 ? $_0 : $MISS; ?>', '5'], both('5') ],
    [ ['S = /[0-9]+/ <? return intval($_0) < 10 ? $_0 : $MISS; ?>', '50'], both($E) ],
    // SequenceIgnore / SequenceResume / MISS inside a nested sequence
    [ ['S = "x" T "y"
T = "a" <? return new \Charm\Parsing\SequenceIgnore; ?>', 'xay'], both(['x', 'y']) ],
    [ ['S = "x" T "y"
T = "a" "b" <? return new \Charm\Parsing\SequenceResume; ?>', 'xaby'], both(['x', ['a', 'b'], 'y']) ],
    [ ['S = T | "xa"
T = "x" <? return $MISS; ?>', 'xa'], both('xa') ],

    // a commit followed by a failure inside a predicate only makes the predicate fail
    [ ['S = !("a" @ "b") "a"', 'ac'], both(['a']) ],
    [ ['S = &("a" @ "b") "a" "b"', 'ab'], both(['a', 'b']) ],
    // a commit is local to its sequence: after the sequence succeeded it is forgotten
    [ ['S = "a" ("b" @ "c") "d" | "a" "b" "c" "e"', 'abce'], both(['a', 'b', 'c', 'e']) ],

    // a pragma without its argument is reported like one with a bad argument (no "Undefined array key")
    [ ['S = "a" "b" %assoc', 'ab'], both("LogicException: Pragma directive %assoc requires an argument 'left', 'right' or 'none', e.g. '%assoc=right'") ],
    [ ['S = "a" "b" %prec', 'ab'], both("LogicException: Pragma directive %prec requires a value, e.g. '%prec=5' or '%prec=T_POW'") ],
    [ ['S = "a" "b" %prec=+', 'ab'], both('GRAMMAR_ERROR') ],

    // groups of one valueless clause
    [ ['S = (&"a") "a"', 'a'], both([null, 'a']) ],
    [ ['S = ($) ', ''], both(null) ],
    [ ['S = !(&"a") "b"', 'b'], both(['b']) ],

    // prefix operators bind to the alternative's precedence, bracketed expressions are complete expressions
    [ ['S = Expr
Expr = "-" Expr | Expr "+" Expr | N
N = /[0-9]/', '-1+2'], both([['-', '1'], '+', '2']) ],
    [ ['S = Expr
Expr = Expr "+" Expr | "-" Expr | N
N = /[0-9]/', '-1+2'], both(['-', ['1', '+', '2']]) ],
    [ ['S = Expr
Expr = Expr "+" Expr | "(" Expr ")" | "-" Expr | N
N = /[0-9]/', '-(1+2)+3'], both(['-', [['(', ['1', '+', '2'], ')'], '+', '3']]) ],

    // %assoc overrides the table; %prec=N overrides the table's level
    [ ['@prec {
  left "+"
  left "*"
}
S = Expr
Expr = Expr "+" Expr %assoc=right | Expr "*" Expr | N
N = /[0-9]/', '1+2+3'], both(['1', '+', ['2', '+', '3']]) ],
    [ ['@prec {
  left "+"
  left "*"
}
S = Expr
Expr = Expr "+" Expr %prec=3 | Expr "*" Expr | N
N = /[0-9]/', '1*2+3'], both(['1', '*', ['2', '+', '3']]) ],
    [ ['S = Expr
Expr = Expr "+" Expr %assoc=none | N
N = /[0-9]/', '1+2+3'], both([['1', '+', '2'], '+', '3']) ],

    // snippets on left-recursive alternatives
    [ ['S = Expr
Expr = Expr "+" Expr <? return $_0 + $_2; ?> | N
N = /[0-9]/ <? return intval($_0); ?>', '1+2+3'], both(6) ],
    [ ['S = Expr
Expr = Expr "+" Expr <? return $MISS; ?> | N
N = /[0-9]/', '1+2'], both('1') ],
];
