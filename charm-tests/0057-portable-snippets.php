<?php
/**
 * Portable snippets: the declarative subset of snippets (a single `return` of an array literal, labels,
 * `$_offset`, `$_line`, `$_column`, `$x ?? literal`) runs in both PHP engines and in the C, JavaScript and
 * Python targets with the same tree. Snippets outside the subset are rejected by the targets, naming the rule.
 */
require_once __DIR__ . '/support/conformance.php';

use Charm\Parsing\Compiler\Portable\{Program, UnsupportedGrammar};
use Charm\Parsing\{GrammarParser, GrammarPreprocessor};

function portable_reject(array $args): string {
    try {
        Program::fromGrammar((new GrammarParser())->parse((new GrammarPreprocessor())->process($args[0]), 'test'));
        return 'accepted';
    } catch (UnsupportedGrammar $e) {
        $m = $e->getMessage();
        foreach ($args[1] as $needle) {
            if (!str_contains($m, $needle)) {
                return "message lacks `$needle`: $m";
            }
        }
        return 'rejected';
    }
}

$ws = "\n_ = /\\s*/ %silent";

return [
    "Portable snippets: same tree in PHP and the portable targets",
    function (array $args) {
        return $args[0] === 'reject' ? portable_reject([$args[1], $args[2]]) : conformance_case([$args[1], $args[2]]);
    },

    // $_offset is where the rule started, not where the snippet's element did
    [ ['tree', 'S = _ n:/[0-9]+/ <? return [\'num\', $n, \'_offset\' => $_offset]; ?>' . $ws, '  42'], both(['num', '42', '_offset' => 0]) ],
    [ ['tree', 'S = _ A' . "\n" . 'A = n:/[0-9]+/ <? return [\'num\', $n, \'_offset\' => $_offset]; ?>' . $ws, '  42'], both(['num', '42', '_offset' => 2]) ],
    // line and column are 1-based, columns count bytes
    [ ['tree', 'S = a:A "\n" b:B <? return [$a, $b]; ?>
A = "x" <? return [\'A\', \'_line\' => $_line, \'_column\' => $_column]; ?>
B = "é" "x" <? return [\'B\', \'_line\' => $_line, \'_column\' => $_column, \'at\' => $_offset]; ?>', "x\néx"],
        both([['A', '_line' => 1, '_column' => 1], ['B', '_line' => 2, '_column' => 1, 'at' => 2]]) ],
    [ ['tree', 'S = "ab" "\n" "  " A' . "\n" . 'A = "c" <? return [$_line, $_column, $_offset]; ?>', "ab\n  c"], both(['ab', "\n", '  ', [2, 3, 5]]) ],
    // pass-through, optional labels, defaults
    [ ['tree', 'S = x:"a" y:"b" <? return $y; ?>', 'ab'], both('b') ],
    [ ['tree', 'S = x:"a" y:"b"? <? return [$y ?? \'none\', $x ?? \'q\', $z ?? []]; ?>', 'a'], both(['none', 'a', []]) ],
    [ ['tree', 'S = x:"a" y:"b"? <? return [$y ?? \'none\', $x ?? \'q\', $z ?? []]; ?>', 'ab'], both(['b', 'a', []]) ],
    [ ['tree', 'S = x:"a" y:"b"? <? return $y; ?>', 'a'], both(null) ],
    // literals and nesting; keys and positions mix like PHP arrays
    [ ['tree', 'S = "a" <? return [true, false, null, 42, -7, \'str\', "dq\n", []]; ?>', 'a'], both([true, false, null, 42, -7, 'str', "dq\n", []]) ],
    [ ['tree', 'S = n:/[0-9]+/ <? return [\'k\' => [\'a\' => $n, \'b\' => [1, 2]], 3]; ?>', '5'], both(['k' => ['a' => '5', 'b' => [1, 2]], 3]) ],
    [ ['tree', 'S = n:/[0-9]+/ <? return ["x", \'k\' => $n, "y"]; ?>', '5'], both(['x', 'k' => '5', 'y']) ],
    [ ['tree', 'S = n:"a"+ <? return $n; ?>', 'aaa'], both(['a', 'a', 'a']) ],
    // inside groups, repetitions and choices
    [ ['tree', 'S = (a:"a" b:"b" <? return [\'ab\', $b]; ?>) "c"', 'abc'], both([['ab', 'b'], 'c']) ],
    [ ['tree', 'S = P+' . "\n" . 'P = a:"a" <? return [\'a\', $_offset]; ?> | b:"b" <? return [\'b\', $_offset]; ?>', 'abba'], both([['a', 0], ['b', 1], ['b', 2], ['a', 3]]) ],
    // left-recursive rules: every operator node starts where the expression started
    [ ['tree', 'E = l:E _ op:"+" _ r:T <? return [\'bin\', $op, $l, $r, \'_offset\' => $_offset]; ?> | T
T = n:/[0-9]+/ <? return [\'num\', $n]; ?>' . $ws, '1 + 2 + 3'],
        both(['bin', '+', ['bin', '+', ['num', '1'], ['num', '2'], '_offset' => 0], ['num', '3'], '_offset' => 0]) ],
    [ ['tree', 'E = op:"-" e:E <? return [\'neg\', $e, $_offset]; ?> | l:E "*" r:E <? return [\'mul\', $l, $r]; ?> | n:/[0-9]+/ <? return $n; ?>', '-2*3'],
        both(['mul', ['neg', '2', 0], '3']) ],
    // a commit after the operator
    [ ['tree', 'E = l:E _ op:"+" @ _ r:T <? return [$op, $l, $r]; ?> | T
T = /[0-9]+/' . $ws, '1 + 2'], both(['+', '1', '2']) ],
    [ ['tree', 'E = l:E _ op:"+" @ _ r:T <? return [$op, $l, $r]; ?> | T
T = /[0-9]+/' . $ws, '1 +'], both('PARSE_ERROR') ],

    // outside the subset: rejected by the targets, with the rule and the snippet in the message
    [ ['reject', 'S = "a" <? return strtoupper($_0); ?>', ['Rule S', 'strtoupper']], 'rejected' ],
    [ ['reject', 'S = "a" X
X = "b" <? $x = 1; return $x; ?>', ['Rule X', '$x = 1']], 'rejected' ],
    [ ['reject', 'S = n:"a" <? return $typo; ?>', ['Rule S', '$typo', 'labels: n']], 'rejected' ],
    [ ['reject', 'S = "a" <? return $_0; ?>', ['Rule S', '$_0']], 'rejected' ],
    [ ['reject', 'S = n:"a" <? return $n . "x"; ?>', ['Rule S']], 'rejected' ],
    [ ['reject', 'S = n:"a" <? return [1.5]; ?>', ['Rule S']], 'rejected' ],
    [ ['reject', 'S = n:"a" <? return $n ?? $n; ?>', ['Rule S', 'variable']], 'rejected' ],
    [ ['reject', 'S = n:"a" <? return [\'a\' => 1, \'a\' => 2]; ?>', ['Rule S', 'twice']], 'rejected' ],
    [ ['reject', 'S = n:"a" <? return [$n, \'k\' => [$n ?? null]]; ?>', ['Rule S']], 'accepted' ],
];
