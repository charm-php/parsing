<?php
/**
 * `"select"i` / `'select'i`: a literal followed directly by `i` matches in any letter case and returns
 * the input's spelling. Rows give the value, or ERROR@line:column (1-based) for a parse error, through
 * both engines.
 */
require_once __DIR__ . '/support/conformance.php';

return [
    'Case-insensitive literals, interpreted and compiled',
    // ['toGrammarString', $grammar] rows give the grammar as printed, which must parse to the same printed grammar
    function (array $args) {
        if ($args[0] !== 'toGrammarString') {
            return conformance_position($args);
        }
        $parse = fn(string $g) => (string) (new \Charm\Parsing\GrammarParser())->parse($g, 'test');
        $printed = $parse($args[1]);
        return $parse($printed) === $printed ? $printed : "not stable:\n$printed";
    },

    [ ['S = "select"i', 'select'], both('select') ],
    [ ['S = "select"i', 'SELECT'], both('SELECT') ],
    [ ['S = "select"i', 'SeLeCt'], both('SeLeCt') ],
    [ ['S = \'select\'i', 'sELECT'], both('sELECT') ],
    [ ['S = "SELECT"i', 'select'], both('select') ],
    [ ['S = "select"i', 'selec'], both('ERROR@1:1') ],
    [ ['S = "select"i', 'selectx'], both('select') ],
    [ ['S = "select"i', ''], both('ERROR@1:1') ],
    // without the i it is still exact
    [ ['S = "select"', 'SELECT'], both('ERROR@1:1') ],
    // `i` must follow the quote directly and stand alone; otherwise it is the next clause as before
    [ ['S = "a" i
i = "b"', 'ab'], both(['a', 'b']) ],
    [ ['S = "a"id
id = "b"', 'ab'], both(['a', 'b']) ],
    [ ['S = "a"i "b"', 'Ab'], both(['A', 'b']) ],
    // quantifiers, names, predicates, spread, groups
    [ ['S = "ab"i+', 'abABaB'], both(['ab', 'AB', 'aB']) ],
    [ ['S = "ab"i*', ''], both([]) ],
    [ ['S = "ab"i? "c"', 'Abc'], both(['Ab', 'c']) ],
    [ ['S = "ab"i? "c"', 'c'], both([null, 'c']) ],
    [ ['S = k:"and"i+ $', 'AndAND'], both(['k' => ['And', 'AND']]) ],
    [ ['S = k:"and"i "x"', 'ANDx'], both(['k' => 'AND', 'x']) ],
    [ ['S = !"x"i "a"', 'a'], both('a') ],
    [ ['S = !"x"i "a"', 'Xa'], both('ERROR@1:1') ],
    [ ['S = &"x"i "X"', 'X'], both('X') ],
    [ ['S = ("a"i "b"i)+', 'aBAb'], both([['a', 'B'], ['A', 'b']]) ],
    [ ['S = ...("a"i "b"i) "c"', 'AB' . 'c'], both(['A', 'B', 'c']) ],
    // inside choices: the compiled first-byte switch must try both cases
    [ ['S = "select"i "x" | "from"i "y" | "z"', 'SELECTx'], both(['SELECT', 'x']) ],
    [ ['S = "select"i "x" | "from"i "y" | "z"', 'frOMy'], both(['frOM', 'y']) ],
    [ ['S = "select"i "x" | "from"i "y" | "z"', 'z'], both('z') ],
    [ ['S = "select"i "x" | "from"i "y" | "z"', 'Z'], both('ERROR@1:1') ],
    [ ['S = "select"i | "set"i | "from"i', 'SET'], both('SET') ],
    [ ['S = "set"i | "select"i | "from"i', 'Select'], both('Select') ],
    // the shape the compiler turns into one regex when the terminals differ (it must not for these)
    [ ['S = A "!" | B "!"
A = "ab"i
B = "cd"i', 'CD!'], both(['CD', '!']) ],
    [ ['S = "x" ("ab"i | "cd"i | "ef"i) "y"', 'xEFy'], both(['x', 'EF', 'y']) ],
    [ ['S = ("select"i | "set"i | "from"i) /\b/', 'From'], both(['From', '']) ],
    [ ['S = "select"i /\b/', 'selectx'], both('ERROR@1:7') ],
    [ ['S = "select"i /\b/ " " "x"', 'SELECT x'], both(['SELECT', '', ' ', 'x']) ],
    [ ['S = "select"i /\b/', 'select'], both(['select', '']) ],
    // non-ASCII: folded by Unicode rules, the length of the input's spelling is used
    [ ['S = "é"i', 'É'], both('É') ],
    [ ['S = "éa"i+', 'ÉAéA'], both(['ÉA', 'éA']) ],
    [ ['S = "я"i "x"', 'Яx'], both(['Я', 'x']) ],
    [ ['S = "я"i "x" | "a"', 'a'], both('a') ],
    [ ['S = "ÅR"i', 'år'], both('år') ],
    // ASCII literals fold ASCII letters only
    [ ['S = "k"i', "\u{212A}"], both('ERROR@1:1') ],
    // other things the flag survives
    [ ['S = x:("a"i)', 'A'], both('A') ],
    [ ['S = Kw+
Kw = "go"i', 'GOgo'], both(['GO', 'go']) ],
    [ ['S = "a"i <? return strtolower($_0); ?>', 'A'], both('a') ],
    [ ['E = E "+"i E | /[0-9]/', '1+2'], both(['1', '+', '2']) ],
    // the grammar printed back
    [ ['toGrammarString', 'S = "sel"i+ !"x"i \'a"b\'i? B
B = "b"'], "S ::=\n    \"sel\"i+ !\"x\"i \"a\\\"b\"i? B\n\nB ::=\n    \"b\"\n" ],
    // errors
    [ ['@lexer Foo
S = "a"i', 'a'], both('GRAMMAR_ERROR') ],
    [ ['S = "a"?i', 'a'], both('GRAMMAR_ERROR') ],
];
