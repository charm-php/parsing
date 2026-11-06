<?php
/**
 * `$context` in a snippet is the sequence's collected array so far (names and numbers), in both engines;
 * compiled snippets used to have it undefined. Each row's expectation is [interpreted, compiled].
 */
require_once __DIR__ . '/support/conformance.php';

return [
    '$context in snippets, interpreted and compiled',
    'conformance_case',

    [ ['S = "a" "b" <? return $context; ?>', 'ab'], both(['a', 'b']) ],
    [ ['S = x:"a" "b" y:"c" <? return $context; ?>', 'abc'], both(['x' => 'a', 0 => 'b', 'y' => 'c']) ],
    [ ['S = "a" ...A* <? return $context; ?>
A = "b"', 'abb'], both(['a', 'b', 'b']) ],
    [ ['S = ...n:A* "z" <? return $context; ?>
A = "b"', 'bbz'], both(['n' => ['b', 'b'], 'b', 'b', 'z']) ],
    [ ['S = "a" <? return count($context); ?>', 'a'], both(1) ],
    [ ['S = "a"? "b" <? return $context; ?>', 'b'], both([null, 'b']) ],
    // it is a copy: changing it does not change the sequence
    [ ['S = "a" <? $context[] = 5; return $context; ?>', 'a'], both(['a', 5]) ],
    [ ['S = "a" <? return [$context, $_0]; ?>', 'a'], both([['a'], 'a']) ],
    // three alternatives that differ in one terminal (the compiler's shortcut for those)
    [ ['S = "a" <? return $context; ?> | "b" <? return $context; ?> | "c" <? return $context; ?>', 'c'], both(['c']) ],
    [ ['S = "a" B C <? return $context; ?>
B = "b"
C = "c" <? return $context; ?>', 'abc'], both(['a', 'b', ['c']]) ],
    [ ['S = k:("a" <? return $context; ?>) "b"', 'ab'], both(['k' => ['a'], 0 => 'b']) ],
    // left-recursive (Pratt) rules
    [ ['E = E "+" E <? return $context; ?>
  | n:/[0-9]/ <? return $context; ?>', '1+1'], both([['n' => '1'], '+', ['n' => '1']]) ],
    [ ['E = E "+" n:E <? return $context; ?>
  | /[0-9]/', '1+2'], both([0 => '1', 1 => '+', 'n' => '2']) ],
    [ ['E = l:E "+" E <? return $context; ?>
  | /[0-9]/', '1+2'], both(['l' => '1', 0 => '+', 1 => '2']) ],
    // the snippet returns $context itself
    [ ['E = E "+" E <? return array_sum([count($context)]); ?>
  | /[0-9]/', '1+2'], both(3) ],
];
