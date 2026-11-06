<?php
/**
 * Single-value sequences collapse (0.2.0): a sequence (rule body or parenthesised group) in which exactly
 * one element can produce a value returns that value itself instead of a one-element list. "Can produce
 * a value" is decided from the grammar, never the input: predicates, ^, $, \nothing, @, snippets and
 * references to %silent rules never do. Optional elements, spreads, named captures and references to
 * rules that only sometimes return a value are not value-less. Both engines call
 * Sequence::collapses(), and every row runs through both of them.
 */
require_once __DIR__ . '/support/conformance.php';

$E = 'PARSE_ERROR';
return [
    'Sequence collapse, interpreted and compiled',
    'conformance_case',

    // the value-less kinds
    [ ['S = "a" $', 'a'], both('a') ],
    [ ['S = ^ "a"', 'a'], both('a') ],
    [ ['S = "a" \nothing', 'a'], both('a') ],
    [ ['S = "a" @', 'a'], both('a') ],
    [ ['S = &"a" "a"', 'a'], both('a') ],
    [ ['S = "a" !"b"', 'a'], both('a') ],
    [ ['S = "a" !"b"', 'ab'], both($E) ],
    [ ['S = Letter+ _
Letter = /[a-z]/
_ = /\s*/ %silent', 'ab  '], both(['a', 'b']) ],
    [ ['S = _ A _
A = "a"
_ = /\s*/ %silent', ' a '], both('a') ],
    [ ['S = A _
A = "a"
_ = " "* %silent', 'a  '], both('a') ],

    // a silent rule is not value-less when it is not %silent
    [ ['S = "a" _
_ = " "*', 'a '], both(['a', [' ']]) ],

    // (a rule that is itself optional can match nothing: the compiled parser used to skip it at the end of input)
    [ ['S = "a" _
_ = " "* %silent', 'a'], both('a') ],
    [ ['S = "a" _
_ = " "*', 'a'], both(['a', []]) ],

    // zero value-producing elements: still a value-less match
    [ ['S = &"a"', 'a'], both(null) ],
    [ ['S = ^ $', ''], both(null) ],
    [ ['S = _ _
_ = /\s*/ %silent', ''], both(null) ],

    // groups collapse on their own; the outer sequence counts them as one element
    [ ['S = ("a" _) "b"
_ = " "* %silent', 'a b'], both(['a', 'b']) ],
    [ ['S = ("a" _) _
_ = " "* %silent', 'a '], both('a') ],
    [ ['S = ("a" _)+
_ = " "* %silent', 'a a '], both(['a', 'a']) ],
    [ ['S = (!"," /[a-z]/)* ","', 'ab,'], both([['a', 'b'], ',']) ],

    // an optional element can produce a value (null when missing)
    [ ['S = "a" "b"?', 'a'], both(['a', null]) ],
    [ ['S = "a" "b"?', 'ab'], both(['a', 'b']) ],
    [ ['S = _ "b"?
_ = " "* %silent', ' b'], both('b') ],
    [ ['S = _ "b"?
_ = " "* %silent', ' '], both(null) ],

    // a spread element can produce several values: never collapses
    [ ['S = ...A _
A = /[a-z]/+
_ = " "* %silent', 'ab '], both(['a', 'b']) ],
    [ ['S = ...A _
A = /[a-z]/
_ = " "* %silent', 'a '], both(['a']) ],

    // a named capture makes the result an array: never collapses
    [ ['S = n:A _
A = "a"
_ = " "* %silent', 'a '], both(['n' => 'a']) ],
    [ ['S = n:A $
A = "a"', 'a'], both(['n' => 'a']) ],

    // snippets: the numbering and the return value are what the snippet makes of them
    [ ['S = "a" _ <? return $_0; ?>
_ = " "* %silent', 'a '], both('a') ],
    [ ['S = "a" _ <? return [$_0]; ?>
_ = " "* %silent', 'a '], both(['a']) ],
    [ ['S = "a" $ <? return [$_0]; ?>', 'a'], both(['a']) ],
    [ ['S = "a" _ "b" <? return [$_0, $_1]; ?>
_ = " "* %silent', 'a b'], both(['a', 'b']) ],

    // a rule that only sometimes has a value is a value-producing element
    [ ['S = A "b"
A = "a" <? return $_0 === "a" ? new \Charm\Parsing\SequenceIgnore : $_0; ?>', 'ab'], both(['b']) ],
    [ ['S = A _
A = "a" <? return new \Charm\Parsing\SequenceIgnore; ?>
_ = " "* %silent', 'a '], both(null) ],
    [ ['S = A _
A = "a" <? return $_0; ?>
_ = " "* %silent', 'a '], both('a') ],

    // nested rules: every level collapses on its own
    [ ['S = A $
A = B _
B = "x" _
_ = " "* %silent', 'x '], both('x') ],
    [ ['S = A "!"
A = B _
B = "x" _
_ = " "* %silent', 'x !'], both(['x', '!']) ],

    // left recursion: operator alternatives stay lists, the other alternatives are ordinary sequences
    [ ['E = E "+" E | _ /[0-9]/
_ = " "* %silent', ' 1'], both('1') ],
    [ ['E = E "+" E | /[0-9]/', '1+2'], both(['1', '+', '2']) ],
    [ ['E = E "+" E | /[0-9]/', '1'], both('1') ],
    [ ['S = E $
E = E "+" E | /[0-9]/', '1+2'], both(['1', '+', '2']) ],
    [ ['S = E $
E = E "+" E | /[0-9]/', '1+2+3'], both([['1', '+', '2'], '+', '3']) ],
    [ ['E = E _ "+" E | "-" E | /[0-9]/
_ = " "* %silent', '1 +2'], both(['1', '+', '2']) ],
    [ ['E = E _ "+" E | "-" E | /[0-9]/
_ = " "* %silent', '-1'], both(['-', '1']) ],
    [ ['E = E _ "+" E | _ "(" E ")" | /[0-9]/
_ = " "* %silent', ' (1)'], both(['(', '1', ')']) ],
];
