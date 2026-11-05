<?php
/**
 * Conformance (see 0030): where a failed parse reports its error, as 'ERROR@line:column'
 * (the interpreter's 0-based column is normalized to the compiled parser's 1-based one).
 * Expected values are the interpreter's (the reference): the furthest point the parse reached.
 * Columns count bytes on both paths, so multibyte input shifts them.
 */
require_once __DIR__ . '/support/conformance.php';

return [
    "Conformance: error positions (line:column)",
    'conformance_position',

    // failing at the very start
    [ ['S = "a" "b"', ''], both('ERROR@1:1') ],
    [ ['S = "a"+ $', 'b'], both('ERROR@1:1') ],
    [ ['S = $ "a"', 'a'], both('ERROR@1:1') ],
    [ ['S = "abc"', 'abx'], both('ERROR@1:1') ],

    // failing in the middle of a sequence: the furthest point reached
    [ ['S = "a" "b"', 'ax'], both('ERROR@1:2') ],
    [ ['S = "a" "b"', 'a'], both('ERROR@1:2') ],
    [ ['S = "a" "b" "c"', 'abx'], both('ERROR@1:3') ],
    [ ['S = "ab" "c"', 'abx'], both('ERROR@1:3') ],
    [ ['S = /ab/ "c"', 'abx'], both('ERROR@1:3') ],
    [ ['S = [a-b]+ "c"', 'abx'], both('ERROR@1:3') ],
    [ ['S = ("a" | "b")+ "c"', 'abx'], both('ERROR@1:3') ],
    [ ['S = "hello" "world"', 'hello world'], both('ERROR@1:6') ],
    [ ['S = A B
A = "a"
B = "b"', 'ac'], both('ERROR@1:2') ],

    // after a trailing-input check
    [ ['S = "a"+ $', 'aab'], both('ERROR@1:3') ],
    [ ['S = A+ $
A = "ab"', 'ababa'], both('ERROR@1:5') ],
    [ ['S = Word+ $
Word = /[a-z]+/ " "?', 'ab cd 1'], both('ERROR@1:7') ],

    // the deepest alternative wins, not the first or last one tried
    [ ['S = "a" "b" | "a" "c"', 'ad'], both('ERROR@1:2') ],
    [ ['S = A | B
A = "a" "b"
B = "a" "c"', 'ad'], both('ERROR@1:2') ],
    [ ['S = "a" "b" "c" | "a" "x"', 'abz'], both('ERROR@1:3') ],

    // line counting
    [ ['S = "a" "\n" "b"', "a\nx"], both('ERROR@2:1') ],
    [ ['S = "a" "\n" "b" "c"', "a\nbx"], both('ERROR@2:2') ],
    [ ['S = L+ $
L = /[a-z]+/ "\n"', "ab\ncd\nef1"], both('ERROR@3:3') ],
    [ ['S = L+ $
L = /[a-z]+/ "\n"', "ab\r\ncd"], both('ERROR@1:3') ],
    [ ['S = L+ $
L = /[a-z]*/ "\n"', "ab\n\n\nx"], both('ERROR@4:2') ],

    // multibyte input: columns are byte columns on both paths
    [ ['S = "é" "b"', 'éx'], both('ERROR@1:3') ],
    [ ['S = "é" "b" "c"', 'ébx'], both('ERROR@1:4') ],
    [ ['S = A+ $
A = /[à-ü]/', 'éèx'], both('ERROR@1:5') ],
    [ ['S = /[é]+/ "b"', "éé\nx"], both('ERROR@1:5') ],
    [ ['S = "日" "本"', '日x'], both('ERROR@1:4') ],
    [ ['S = L+ $
L = /[^\n]+/ "\n"', "日本\nè\nx"], both('ERROR@3:2') ],

    // rule descriptions do not move the position
    [ ['S = Num
Num "a number" = /[0-9]+/', 'x'], both('ERROR@1:1') ],
    // a successful parse is not an error
    [ ['S = "a" "b"', 'ab'], both(['a', 'b']) ],

    // a failing predicate on a terminal is not itself a failure position: the error is where the
    // enclosing sequence started (the interpreter's trace never sees the predicate's terminal)
    [ ['S = "a" &"b"', 'ac'], both('ERROR@1:1') ],
    [ ['S = "a" !"c"', 'ac'], both('ERROR@1:1') ],
    [ ['S = (/[ab]/+ &/[ab]/)+ "z"', 'abcc'], both('ERROR@1:1') ],
    // ...but a sequence that fails because of a predicate is a failure position of its own
    [ ['S = ("a" R)+ "z"
R = !"a" "b"', 'aab'], both('ERROR@1:2') ],
    // the attempt that ends a repetition of a terminal is not a failure position; what follows is
    [ ['S = /[ab]/+ "z"', 'abc'], both('ERROR@1:3') ],
    [ ['S = "a"* "b"', 'aax'], both('ERROR@1:3') ],
    [ ['S = ("a" "b")+ "c"', 'abax'], both('ERROR@1:4') ],
];
