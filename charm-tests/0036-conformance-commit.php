<?php
/**
 * Conformance (see 0030): the commit operator `@` - once a sequence has passed it, a later
 * failure in that sequence is a parse error, never a reason to try another alternative.
 * 0022 holds the original two-path regression; these generalize it.
 */
require_once __DIR__ . '/support/conformance.php';

$E = 'PARSE_ERROR';
$stmt = 'S = Stmt+
Stmt = "if" @ "(" N ")" | "x" ";"
N = /[0-9]/';
return [
    "Conformance: commit operator @",
    'conformance_case',

    // without the commit the second alternative would match
    [ ['S = "a" @ "b" | "a" "c"', 'ab'], both(['a', 'b']) ],
    [ ['S = "a" @ "b" | "a" "c"', 'ac'], both($E) ],
    [ ['S = "a" "b" | "a" "c"', 'ac'], both(['a', 'c']) ],
    [ ['S = "a" @ "b" | "c"', 'c'], both('c') ],
    [ ['S = "a" @ "b"', 'a'], both($E) ],
    [ ['S = "a" @ "b"', 'ab'], both(['a', 'b']) ],
    // a trailing commit is harmless
    [ ['S = "a" @', 'a'], both('a') ],
    // commit position: earlier alternatives still backtrack, later ones are never reached
    [ ['S = "a" "b" | "a" @ "c" | "a" "d"', 'ab'], both(['a', 'b']) ],
    [ ['S = "a" "b" | "a" @ "c" | "a" "d"', 'ad'], both($E) ],
    [ ['S = "a" "b" @ "c" | "a" "b" "d"', 'abd'], both($E) ],
    [ ['S = "a" @ "b" "c" | "a"', 'abc'], both(['a', 'b', 'c']) ],
    [ ['S = "a" @ "b" "c" | "a"', 'abx'], both($E) ],
    // a commit before anything else commits to the alternative on entry
    [ ['S = @ "a" | "b"', 'a'], both('a') ],
    [ ['S = @ "a" | "b"', 'b'], both($E) ],

    // in nested sequences and groups
    [ ['S = "a" ("b" @ "c" | "b" "d")', 'abd'], both($E) ],
    [ ['S = "a" ("b" @ "c" | "b" "d")', 'abc'], both(['a', ['b', 'c']]) ],
    [ ['S = "a" ("b" @ "c" | "b" "d") | "ab" "e"', 'abe'], both($E) ],
    [ ['S = ("a" @ "b" | "a" "c") "d"', 'acd'], both($E) ],
    [ ['S = ("a" @ "b" | "a" "c") "d"', 'abd'], both([['a', 'b'], 'd']) ],
    // through rule references
    [ ['S = A | "x"
A = "x" @ "y"', 'xy'], both(['x', 'y']) ],
    [ ['S = A | "x"
A = "x" @ "y"', 'xz'], both($E) ],
    [ ['S = X "!" | Y
X = "a" @ "b"
Y = "a" "c"', 'ac'], both($E) ],
    [ ['S = X "!" | Y
X = "a" @ "b"
Y = "a" "c"', 'ab!'], both([['a', 'b'], '!']) ],
    // a commit inside an alternative that succeeded is discarded: later failures can backtrack
    [ ['S = A "z" | "xy" "q"
A = "x" @ "y"', 'xyq'], both(['xy', 'q']) ],
    [ ['S = A "z" | "xy" "q"
A = "x" @ "y"', 'xyz'], both([['x', 'y'], 'z']) ],

    // inside repetition
    [ ['S = ("a" @ "b")*', 'abab'], both([['a', 'b'], ['a', 'b']]) ],
    [ ['S = ("a" @ "b")* "a" "c"', 'abac'], both($E) ],
    [ ['S = ("a" @ "b")+ | "a" "c"', 'ac'], both($E) ],
    [ ['S = ("a" @ "b")? "a" "c"', 'ac'], both($E) ],
    [ ['S = ("a" @ "b")? "a" "c"', 'abac'], both([['a', 'b'], 'a', 'c']) ],
    [ ['S = ("a" "b" @ "c")* "a" "b" "d"', 'abd'], both($E) ],
    [ ['S = ("a" "b" @ "c")* "a" "b" "d"', 'abcabd'], both($E) ],
    [ ['S = ("a" @ "b" | "a" "c")+', 'abac'], both($E) ],
    [ ['S = ("a" @ "b" | "a" "c")+', 'abab'], both([['a', 'b'], ['a', 'b']]) ],
    [ [$stmt, 'x;if(1)x;'], both([['x', ';'], ['if', '(', '1', ')'], ['x', ';']]) ],
    [ [$stmt, 'x;if(z)'], both($E) ],
    [ [$stmt, 'x;y'], both([['x', ';']]) ],
];
