<?php
/**
 * Conformance (see 0030): sequence, ordered choice, ? * + repetition, grouping, value shapes.
 * Token mode is out of scope (needs the PHP lexer; see 0019/0020).
 */
require_once __DIR__ . '/support/conformance.php';

$E = 'PARSE_ERROR';
return [
    "Conformance: sequence, choice, repetition, grouping and value shapes",
    'conformance_case',

    // sequence
    [ ['S = "a" "b"', 'ab'], both(['a', 'b']) ],
    [ ['S = "a" "b" "c"', 'abc'], both(['a', 'b', 'c']) ],
    [ ['S = "a" "b"', 'a'], both($E) ],
    [ ['S = "a" "b"', 'ba'], both($E) ],
    [ ['S = "a" /[0-9]/ "c"', 'a1c'], both(['a', '1', 'c']) ],
    // the goal rule does not have to consume all input; add `$` to require it
    [ ['S = "a"', 'ab'], both('a') ],
    [ ['S = "a" $', 'ab'], both($E) ],

    // value shape: a lone clause is a scalar, a sequence is an array, zero-width clauses add nothing
    [ ['S = "a"', 'a'], both('a') ],
    [ ['S = ("a")', 'a'], both('a') ],
    [ ['S = "a" ε', 'a'], both('a') ],
    [ ['S = ^ "a" $', 'a'], both('a') ],
    [ ['S = ("a" "b")', 'ab'], both(['a', 'b']) ],
    [ ['S = ("a" "b") "c"', 'abc'], both([['a', 'b'], 'c']) ],
    [ ['S = "a" ("b" "c")', 'abc'], both(['a', ['b', 'c']]) ],
    [ ['S = "a" ("b" ("c" "d"))', 'abcd'], both(['a', ['b', ['c', 'd']]]) ],
    [ ['S = A
A = "a"', 'a'], both('a') ],
    [ ['S = A "b"
A = "a"', 'ab'], both(['a', 'b']) ],
    [ ['S = A A
A = "a" "b"', 'abab'], both([['a', 'b'], ['a', 'b']]) ],
    [ ['S = ε', 'a'], both(null) ],

    // ordered choice
    [ ['S = "a" | "b"', 'a'], both('a') ],
    [ ['S = "a" | "b"', 'b'], both('b') ],
    [ ['S = "a" | "b"', 'c'], both($E) ],
    [ ['S = "a" / "b"', 'b'], both('b') ],
    [ ['S = "a" | "b" | "c"', 'c'], both('c') ],
    // first success wins, not longest
    [ ['S = "a" | "ab"', 'ab'], both('a') ],
    [ ['S = "ab" | "a"', 'ab'], both('ab') ],
    [ ['S = "a" | "ab" $', 'ab'], both('a') ],
    [ ['S = ("a" | "ab") $', 'ab'], both($E) ],
    [ ['S = ("a" "b") | ("a" "c")', 'ac'], both(['a', 'c']) ],
    [ ['S = "a" "b" | "a" "c"', 'ac'], both(['a', 'c']) ],
    [ ['S = "a" ("b" | "c") "d"', 'acd'], both(['a', 'c', 'd']) ],
    [ ['S = "a" ("b" | "c") "d"', 'aed'], both($E) ],
    [ ['S = A | B
A = "x" "y"
B = "x" "z"', 'xz'], both(['x', 'z']) ],
    [ ['S = "" | "a"', 'a'], both('') ],
    [ ['S = "a" | ε', 'b'], both(null) ],
    // an alternative that always succeeds (here a star) shadows everything after it
    [ ['S = ("a" "b")* | "a" "c"', 'ac'], both([]) ],
    [ ['S = "a"? | "b"', 'b'], both(null) ],

    // optional
    [ ['S = "a"?', 'a'], both('a') ],
    [ ['S = "a"?', 'b'], both(null) ],
    [ ['S = "a"?', ''], both(null) ],
    [ ['S = "a"? "b"', 'ab'], both(['a', 'b']) ],
    [ ['S = "a"? "b"', 'b'], both([null, 'b']) ],
    [ ['S = "a"? "a"', 'a'], both($E) ],   // greedy: the optional takes the only "a" and never gives it back
    [ ['S = "a"? "b"?', ''], both([null, null]) ],
    [ ['S = ("a" "b")?', 'ab'], both(['a', 'b']) ],
    [ ['S = ("a" "b")?', 'ac'], both(null) ],
    [ ['S = ("a" "b")? "a"', 'ac'], both([null, 'a']) ],
    [ ['S = ("a"?)?', 'a'], both('a') ],

    // zero or more
    [ ['S = "a"*', 'aaa'], both(['a', 'a', 'a']) ],
    [ ['S = "a"*', ''], both([]) ],
    [ ['S = "a"*', 'b'], both([]) ],
    [ ['S = "a"* "b"', 'aab'], both([['a', 'a'], 'b']) ],
    [ ['S = "a"* "b"', 'b'], both([[], 'b']) ],
    [ ['S = "a"* "a"', 'aaa'], both($E) ],
    [ ['S = ("a" "b")*', 'abab'], both([['a', 'b'], ['a', 'b']]) ],
    [ ['S = ("a" "b")*', 'aba'], both([['a', 'b']]) ],
    [ ['S = ("a" | "b")*', 'abba'], both(['a', 'b', 'b', 'a']) ],
    [ ['S = "a"* "b"*', 'aabb'], both([['a', 'a'], ['b', 'b']]) ],
    [ ['S = A*
A = /[0-9]/', '123'], both(['1', '2', '3']) ],
    [ ['S = /[a-z]*/ "!"', '!'], both(['', '!']) ],

    // one or more
    [ ['S = "a"+', 'aaa'], both(['a', 'a', 'a']) ],
    [ ['S = "a"+', 'a'], both(['a']) ],
    [ ['S = "a"+', ''], both($E) ],
    [ ['S = "a"+', 'b'], both($E) ],
    [ ['S = "a"+ "b"', 'aab'], both([['a', 'a'], 'b']) ],
    [ ['S = "a"+ "b"*', 'aab'], both([['a', 'a'], ['b']]) ],
    [ ['S = "a"+ "b"*', 'aa'], both([['a', 'a'], []]) ],
    [ ['S = ("a" | "b")+ "c"', 'abc'], both([['a', 'b'], 'c']) ],
    [ ['S = (A B)+
A = "a"
B = "b"', 'ababa'], both([['a', 'b'], ['a', 'b']]) ],

    // nested repetition
    [ ['S = ("a"+)+', 'aa'], both([['a', 'a']]) ],
    [ ['S = ("a"+ "b")+', 'aabab'], both([[['a', 'a'], 'b'], [['a'], 'b']]) ],
    [ ['S = ("a"* "b")*', 'abb'], both([[['a'], 'b'], [[], 'b']]) ],
    [ ['S = ("a" "b"?)+', 'aab'], both([['a', null], ['a', 'b']]) ],
    [ ['S = ("a"? "b")*', 'bab'], both([[null, 'b'], ['a', 'b']]) ],

    // repetition of something that can match the empty string must terminate
    [ ['S = ("a"*)*', 'aaa'], both([['a', 'a', 'a']]) ],
    [ ['S = ("a"?)*', 'aaa'], both(['a', 'a', 'a']) ],
    [ ['S = ("a"*)+', 'aaa'], both([['a', 'a', 'a']]) ],
    [ ['S = ε*', 'a'], both([]) ],
];
