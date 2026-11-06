<?php
/**
 * `...name:Clause`: the name gets the whole value, and the elements are spread into the sequence, so
 * `$_0`, `$_1` ... after a spread are the elements. Both engines (compiled parsers used to leave `$name`
 * undefined and hand the whole list over as `$_0`).
 */
require_once __DIR__ . '/support/conformance.php';

return [
    "Named spread and positions after a spread",
    'conformance_case',

    [ ['S = ...p:A* <? return [$p, $_0, $_1]; ?>
A = "a"', 'aa'], both([['a', 'a'], 'a', 'a']) ],
    [ ['S = ...p:A* <? return [$p, $_0 ?? 0]; ?>
A = "a"', ''], both([[], 0]) ],
    [ ['S = ...p:A+ <? return [$p, $_1]; ?>
A = "a"', 'aaa'], both([['a', 'a', 'a'], 'a']) ],
    [ ['S = x:"x" ...p:A+ <? return [$x, $p, $_0, $_1, $_2 ?? 0]; ?>
A = "a"', 'xaa'], both(['x', ['a', 'a'], 'a', 'a', 0]) ],
    [ ['S = ...p:(A B)* <? return [$p, $_0, $_1]; ?>
A = "a"
B = "b"', 'abab'], both([[['a', 'b'], ['a', 'b']], 'a', 'b']) ],
    // a plain spread: unchanged except that $_N are the elements in both engines
    [ ['S = ...A* <? return [$_0, $_1]; ?>
A = "a"', 'aa'], both(['a', 'a']) ],
    [ ['S = "x" ...A+ "y" <? return [$_0, $_3]; ?>
A = "a"', 'xaay'], both(['x', 'y']) ],
    [ ['S = ...A*
A = "a"', 'aa'], both(['a', 'a']) ],
    [ ['S = ...p:A* "!"
A = "a"', 'aa!'], both([ 'p' => ['a', 'a'], 0 => 'a', 1 => 'a', 2 => '!']) ],
];
