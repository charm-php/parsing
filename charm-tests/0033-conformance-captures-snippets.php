<?php
/**
 * Conformance (see 0030): named captures, positional values, spread, snippets and their
 * return values, snippet errors, parser state. Expectations follow docs/snippets-and-state.md
 * (the interpreter's behaviour); where the compiled engine differs the case fails.
 */
require_once __DIR__ . '/support/conformance.php';

$E = 'PARSE_ERROR';
return [
    "Conformance: captures, spread and snippets",
    'conformance_case',

    // named captures
    [ ['S = x:"a" y:"b"', 'ab'], both(['x' => 'a', 'y' => 'b']) ],
    [ ['S = x:"a" "b"', 'ab'], both(['x' => 'a', 0 => 'b']) ],
    [ ['S = "a" x:"b" "c"', 'abc'], both([0 => 'a', 'x' => 'b', 1 => 'c']) ],
    [ ['S = x:"a"', 'a'], both('a') ],
    [ ['S = x:"a" y:"b"', 'ax'], both($E) ],
    [ ['S = x:A y:A
A = /[a-z]/', 'ab'], both(['x' => 'a', 'y' => 'b']) ],
    [ ['S = x:"a"* y:"b"?', 'aa'], both(['x' => ['a', 'a'], 'y' => null]) ],
    [ ['S = x:"a"+ y:"b"+', 'aab'], both(['x' => ['a', 'a'], 'y' => ['b']]) ],
    [ ['S = x:("a" "b") y:"c"', 'abc'], both(['x' => ['a', 'b'], 'y' => 'c']) ],
    [ ['S = x:"a" x:"b"', 'ab'], both(['x' => 'b']) ],
    [ ['S = x:"a" | y:"b"', 'b'], both('b') ],
    [ ['S = Pair
Pair = k:Name "=" v:Name
Name = /[a-z]+/', 'a=b'], both(['k' => 'a', 0 => '=', 'v' => 'b']) ],

    // spread flattens an array result into the parent and skips nulls
    [ ['S = ...A* "z"
A = "a" "b"', 'ababz'], both(['a', 'b', 'a', 'b', 'z']) ],
    [ ['S = ...A "z"
A = "a" "b"', 'abz'], both(['a', 'b', 'z']) ],
    [ ['S = "x" ...A*
A = "a"', 'xaa'], both(['x', 'a', 'a']) ],
    [ ['S = "x" ...A*
A = "a"', 'x'], both(['x']) ],
    [ ['S = ...A? "z"
A = "a" "b"', 'z'], both(['z']) ],
    [ ['S = ...A? "z"
A = "a" "b"', 'abz'], both(['a', 'b', 'z']) ],
    [ ['S = x:...A "z"
A = "a" "b"', 'abz'], both('GRAMMAR_ERROR') ],
    [ ['S = "k":A
A = "a"', 'a'], both('GRAMMAR_ERROR') ],

    // snippets: positional values and return values
    [ ['S = "a" "b" <? return $_1 . $_0; ?>', 'ab'], both('ba') ],
    [ ['S = "a" "b" "c" <? return $_0 . $_1 . $_2; ?>', 'abc'], both('abc') ],
    [ ['S = /[0-9]+/ <? return intval($_0); ?>', '42'], both(42) ],
    [ ['S = /[0-9]+/ "+" /[0-9]+/ <? return intval($_0) + intval($_2); ?>', '4+5'], both(9) ],
    [ ['S = x:"a" y:"b" <? return $y . $x; ?>', 'ab'], both('ba') ],
    [ ['S = "a" <? return null; ?>', 'a'], both(null) ],
    [ ['S = "a" <? return false; ?>', 'a'], both(false) ],
    [ ['S = "a" <? return true; ?>', 'a'], both(true) ],
    [ ['S = "a" <? return 0; ?>', 'a'], both(0) ],
    [ ['S = "a" <? return ""; ?>', 'a'], both('') ],
    [ ['S = "a" <? return []; ?>', 'a'], both([]) ],
    [ ['S = "a" <? return ["k" => $_0]; ?>', 'a'], both(['k' => 'a']) ],
    [ ['S = A+ <? return count($_0); ?>
A = "a"', 'aaa'], both(3) ],
    [ ['S = A* <? return count($_0); ?>
A = "a"', ''], both(0) ],
    [ ['S = "a"? "b" <? return $_0 === null ? "none" : $_0; ?>', 'b'], both('none') ],
    [ ['S = Item+
Item = /[a-z]/ <? return strtoupper($_0); ?>', 'abc'], both(['A', 'B', 'C']) ],
    [ ['S = "a" $ <? return $_0; ?>', 'a'], both('a') ],
    [ ['S = "a" <? return 1; ?> | "b" <? return 2; ?>', 'b'], both(2) ],
    [ ['S = "a" "b" <? return $_0; ?> | "a" "c" <? return $_1; ?>', 'ac'], both('c') ],
    [ ['S = "a" <? return 1; ?>', 'x'], both($E) ],
    // a snippet with locals and a loop
    [ ['S = Items
Items = Value ("," Value)* <?
    $list = [$_0];
    foreach ($_1 as $r) { $list[] = $r[1]; }
    return $list;
?>
Value = /[0-9]+/ <? return intval($_0); ?>', '1,2,3'], both([1, 2, 3]) ],
    // only value-producing clauses take a positional slot: ^ $ ε and predicates do not
    [ ['S = ^ "a" $ <? return $_0; ?>', 'a'], both('a') ],
    [ ['S = "a" !"b" "c" <? return $_1; ?>', 'ac'], both('c') ],
    [ ['S = &"a" "a" ε "b" <? return $_0 . $_1; ?>', 'ab'], both('ab') ],

    // snippet controlling the sequence (docs/snippets-and-state.md "Return values"; the docs list
    // MISS/SequenceIgnore/SequenceResume as not honoured by compiled parsers - a known gap, so these fail)
    [ ['S = Byte
Byte = /[0-9]+/ <? $v = intval($_0); return ($v >= 0 && $v <= 255) ? $v : \Charm\Parsing\Signal::MISS; ?>', '200'], both(200) ],
    [ ['S = Byte
Byte = /[0-9]+/ <? $v = intval($_0); return ($v >= 0 && $v <= 255) ? $v : \Charm\Parsing\Signal::MISS; ?>', '300'], both($E) ],
    [ ['S = N | W
N = /[0-9]+/ <? return intval($_0) < 10 ? intval($_0) : \Charm\Parsing\Signal::MISS; ?>
W = /[0-9]+/ <? return "big"; ?>', '50'], both('big') ],
    [ ['S = "a" <? return new \Charm\Parsing\SequenceIgnore; ?>', 'a'], both(null) ],
    [ ['S = "a" "b" <? return new \Charm\Parsing\SequenceResume; ?>', 'ab'], both(['a', 'b']) ],

    // snippet errors: anything escaping a snippet is a SnippetError in the interpreter
    [ ['S = "a" <? throw new \Exception("boom"); ?>', 'a'], both('Charm\Parsing\Error\SnippetError: Error in snippet: boom') ],
    [ ['S = "a" <? return intdiv(1, 0); ?>', 'a'], both('Charm\Parsing\Error\SnippetError: Error in snippet: Division by zero') ],
    [ ['S = <? return 1; ?> "a"', 'a'], both('GRAMMAR_ERROR') ],
    [ ['S = "a" <? $x = ; ?>', 'a'], both('GRAMMAR_ERROR') ],

    // parser state: $state is shared by all snippets for the whole parse
    [ ['S = "a" <? $state["n"] = 1; return $state["n"]; ?>', 'a'], both(1) ],
    [ ['S = Item+
Item = "a" <? $state["c"] = ($state["c"] ?? 0) + 1; return $state["c"]; ?>', 'aaa'], both([1, 2, 3]) ],
    // ... but is reset for every parse() call
    [ ['S = Item+
Item = "a" <? $state["c"] = ($state["c"] ?? 0) + 1; return $state["c"]; ?>', 'aa'], both([1, 2]) ],
    // a failed alternative has already run its snippet; state is not rolled back
    [ ['S = A | B
A = "a" "b" <? $state["t"] = ($state["t"] ?? "") . "A"; return 1; ?>
B = "a" <? $state["t"] = ($state["t"] ?? "") . "B"; return $state["t"]; ?>', 'ac'], both('B') ],
];
