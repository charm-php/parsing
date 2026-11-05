<?php
/**
 * Conformance (see 0030): & and ! lookahead predicates. 0010 covers a few through the
 * interpreter only; these run the same grammars through both paths.
 */
require_once __DIR__ . '/support/conformance.php';

$E = 'PARSE_ERROR';
return [
    "Conformance: and/not predicates",
    'conformance_case',

    // and-predicate
    [ ['S = &"a" "a"', 'a'], both(['a']) ],
    [ ['S = &"a" "b"', 'a'], both($E) ],
    [ ['S = &"a" "b"', 'b'], both($E) ],
    [ ['S = &"ab" "a" "b"', 'ab'], both(['a', 'b']) ],
    [ ['S = &"ab" "a" "c"', 'ac'], both($E) ],
    [ ['S = &/[0-9]/ /[0-9a-z]+/', '9z'], both(['9z']) ],
    [ ['S = &("a" "b") "a"', 'ab'], both(['a']) ],
    [ ['S = &("a" "b") "a"', 'ac'], both($E) ],
    [ ['S = &A A
A = "a"', 'a'], both(['a']) ],
    // a lone predicate is a value-less match
    [ ['S = &"a"', 'a'], both(null) ],
    [ ['S = &"a"', 'b'], both($E) ],
    [ ['S = &"a" "a" "b"', 'ab'], both(['a', 'b']) ],

    // not-predicate
    [ ['S = !"a" "b"', 'b'], both(['b']) ],
    [ ['S = !"a" "a"', 'a'], both($E) ],
    [ ['S = !"a"', 'b'], both(null) ],
    [ ['S = !"a"', 'a'], both($E) ],
    [ ['S = !"a"', ''], both(null) ],
    [ ['S = "a" !"b"', 'ac'], both(['a']) ],
    [ ['S = "a" !"b"', 'ab'], both($E) ],
    [ ['S = "a" !"b" /[a-z]/', 'ac'], both(['a', 'c']) ],
    [ ['S = !("a" "b") "a"', 'ac'], both(['a']) ],
    [ ['S = !("a" "b") "a"', 'ab'], both($E) ],
    [ ['S = !/[0-9]/ /[a-z0-9]+/', 'a1'], both(['a1']) ],
    [ ['S = !/[0-9]/ /[a-z0-9]+/', '1a'], both($E) ],
    [ ['S = !("if" | "while") /[a-z]+/', 'iffy'], both($E) ],
    [ ['S = !("if" | "while") /[a-z]+/', 'foo'], both(['foo']) ],
    [ ['S = !"if" !"while" /[a-z]+/', 'foo'], both(['foo']) ],
    [ ['S = !"if" !"while" /[a-z]+/', 'while'], both($E) ],

    // predicates against EOF
    [ ['S = !$ "a"', 'a'], both(['a']) ],
    [ ['S = !$ "a"', ''], both($E) ],
    [ ['S = "a" !$', 'a'], both($E) ],
    [ ['S = "a" !$', 'ab'], both(['a']) ],
    [ ['S = "a" &$', 'a'], both(['a']) ],
    [ ['S = "a" &$', 'ab'], both($E) ],
    [ ['S = (!$ /[a-z]/)+', 'abc'], both([['a'], ['b'], ['c']]) ],
    [ ['S = "a" "b" !$', 'ab'], both($E) ],

    // predicates inside repetition (the classic "anything up to X")
    [ ['S = (!"," /[a-z]/)* ","', 'abc,'], both([[['a'], ['b'], ['c']], ',']) ],
    [ ['S = (!"," /[a-z]/)* ","', 'abc'], both($E) ],
    [ ['S = (!"," /[a-z]/)+', ',a'], both($E) ],
    [ ['S = (&/[a-z0-9]/ /[a-z0-9]/)+', 'a1'], both([['a'], ['1']]) ],

    // predicates consume nothing, also when the inner clause matches several characters
    [ ['S = &"abc" /[a-z]+/', 'abc'], both(['abc']) ],
    [ ['S = !"abd" /[a-z]+/', 'abc'], both(['abc']) ],
    [ ['S = &"abc" !"abd" "ab" "c"', 'abc'], both(['ab', 'c']) ],

    // predicates in choices
    [ ['S = &"a" "a" | "b"', 'b'], both('b') ],
    [ ['S = !"a" "b" | "a"', 'a'], both('a') ],
    [ ['S = Kw | Ident
Kw = "if" !/[a-z]/
Ident = /[a-z]+/', 'iffy'], both('iffy') ],
    [ ['S = Kw | Ident
Kw = "if" !/[a-z]/
Ident = /[a-z]+/', 'if'], both(['if']) ],

    // stacked predicates: a predicate whose operand is itself a predicate
    [ ['S = !(!"a") "a"', 'a'], both(['a']) ],
    [ ['S = !(&"a") "b"', 'b'], both(['b']) ],
    [ ['S = !!"a" "a"', 'a'], both(['a']) ],
    [ ['S = &(&"a") "a"', 'a'], both(['a']) ],
    [ ['S = &(&"a") "b"', 'a'], both($E) ],
];
