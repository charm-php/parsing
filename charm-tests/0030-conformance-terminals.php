<?php
/**
 * Conformance suite (0030-0037): every case runs the SAME grammar and input through the
 * interpreter (cpeg_parse) and a compiled parser, and each row's expected value is
 * [interpreted, compiled]. both($v) is the normal case; a pair of different values would
 * record a deliberate difference. Cases whose expectation is the documented/correct
 * behaviour fail when a path diverges - that is the point. 'PARSE_ERROR' is any input
 * parse failure, 'GRAMMAR_ERROR' an invalid grammar, 'HANG' a path that never terminates.
 *
 * Out of scope: string vs token mode (token mode needs the PHP lexer; see 0019/0020).
 * This file: terminals - literals, escapes, character classes, regex, anchors, nothing.
 */
require_once __DIR__ . '/support/conformance.php';

$E = 'PARSE_ERROR';
return [
    "Conformance: terminals (literals, classes, regex, anchors, nothing)",
    'conformance_case',

    // literals and escapes
    [ ['S = "ab"', 'ab'], both('ab') ],
    [ ['S = "ab"', 'ax'], both($E) ],
    [ ['S = "ab"', 'a'], both($E) ],
    [ ['S = "ab"', ''], both($E) ],
    [ ["S = 'ab'", 'ab'], both('ab') ],
    [ ['S = "a\nb"', "a\nb"], both("a\nb") ],
    [ ['S = "a\tb"', "a\tb"], both("a\tb") ],
    [ ['S = "a\rb"', "a\rb"], both("a\rb") ],
    [ ['S = "a\0b"', "a\0b"], both("a\0b") ],
    [ ['S = "a\\\\b"', 'a\\b'], both('a\\b') ],
    [ ['S = "a\"b"', 'a"b'], both('a"b') ],
    [ ["S = 'a\\'b'", "a'b"], both("a'b") ],
    [ ['S = "\'"', "'"], both("'") ],
    [ ['S = \'"\'', '"'], both('"') ],
    [ ['S = "\"a\""', '"a"'], both('"a"') ],
    [ ['S = "a\nb"', 'a\nb'], both($E) ],
    [ ['S = "é"', 'é'], both('é') ],
    [ ['S = "日本"', '日本'], both('日本') ],
    [ ['S = "日本"', '日'], both($E) ],
    [ ['S = ""', ''], both('') ],
    [ ['S = "" "a"', 'a'], both(['', 'a']) ],
    // literals are case sensitive; use an inline regex group for the insensitive variant
    [ ['S = "ab"', 'AB'], both($E) ],
    [ ['S = /(?i:ab)/', 'aB'], both('aB') ],
    [ ['S = /(?i:ab)/', 'xx'], both($E) ],
    // /foo/i is the regex /foo/ followed by the rule name i, not a flag
    [ ['S = /a/i', 'a'], both('GRAMMAR_ERROR') ],

    // character classes
    [ ['S = [a-c]', 'b'], both('b') ],
    [ ['S = [a-c]', 'd'], both($E) ],
    [ ['S = [a-c]', ''], both($E) ],
    [ ['S = [^a-c]', 'd'], both('d') ],
    [ ['S = [^a-c]', 'b'], both($E) ],
    [ ['S = [abc]+', 'cabd'], both(['c', 'a', 'b']) ],
    [ ['S = [0-9a-f]+ $', '09af'], both([['0', '9', 'a', 'f']]) ],
    [ ['S = [0-9a-f]+ $', '09ag'], both($E) ],
    [ ['S = [à-ü]', 'é'], both('é') ],
    [ ['S = [à-ü]', 'a'], both($E) ],
    [ ['S = [^a]', 'é'], both('é') ],

    // regex terminals
    [ ['S = /[0-9]+/', '123'], both('123') ],
    [ ['S = /[0-9]+/', '123abc'], both('123') ],
    [ ['S = /[0-9]+/', 'abc'], both($E) ],
    [ ['S = /a|ab/', 'ab'], both('a') ],
    [ ['S = /ab|a/', 'ab'], both('ab') ],
    [ ['S = /(a)(b)/', 'ab'], both('ab') ],
    [ ['S = /\d+\.\d+/', '3.14'], both('3.14') ],
    [ ['S = /\//', '/'], both('/') ],
    [ ['S = /[a-z]*/', '123'], both('') ],
    // anchored at the current offset, not searched
    [ ['S = "a" /b/', 'axb'], both($E) ],
    [ ['S = "a" /b/', 'ab'], both(['a', 'b']) ],
    // UTF-8 aware
    [ ['S = /./', 'é'], both('é') ],
    [ ['S = /.{2}/', 'éé'], both('éé') ],
    [ ['S = /\w+/', 'héllo'], both('héllo') ],
    [ ['S = /\p{Lu}/', 'Éa'], both('É') ],
    [ ['S = /[\s\S]/', "\n"], both("\n") ],

    // any character is a regex, `.` is not a clause
    [ ['S = /[\s\S]/ /[\s\S]/', 'éa'], both(['é', 'a']) ],
    [ ['S = .', 'a'], both('GRAMMAR_ERROR') ],

    // SOF / EOF / nothing
    [ ['S = ^ "a"', 'a'], both(['a']) ],
    [ ['S = "a" ^', 'a'], both($E) ],
    [ ['S = "a" $', 'a'], both(['a']) ],
    [ ['S = "a" $', 'ab'], both($E) ],
    [ ['S = $', ''], both(null) ],
    [ ['S = $', 'a'], both($E) ],
    [ ['S = ^ $', ''], both(null) ],
    [ ['S = ^ $', 'a'], both($E) ],
    [ ['S = ^', 'x'], both(null) ],
    [ ['S = "a" "b" $', "ab"], both(['a', 'b']) ],
    [ ['S = \nothing', 'x'], both(null) ],
    [ ['S = ε', ''], both(null) ],
    [ ['S = ε "a"', 'a'], both(['a']) ],
    [ ['S = "a" \nothing "b"', 'ab'], both(['a', 'b']) ],
    [ ['S = ε $', ''], both(null) ],
];
