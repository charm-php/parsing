<?php
/**
 * Regression rows for bugs found while writing real grammars (SkeinLang):
 *  - a commit (`@`) inside an alternative of a directly left-recursive rule crashed the interpreter
 *  - CommittedError messages counted lines from 0 and named rules instead of their descriptions
 *  - an alternative that is a single labelled clause returns the bare value (documented collapse rule)
 */
require_once __DIR__ . '/support/conformance.php';

$add = 'Add = left:Add _ op:"+" @ _ right:Num | Num
Num = /[0-9]+/
_ = /\s*/ %silent';

function interpreter_message(array $args): string {
    try {
        \Charm\cpeg_parse($args[0], $args[1]);
        return 'no error';
    } catch (\RuntimeException $e) {
        return $e->getMessage();
    }
}

return [
    "Engine fixes: commit in a Pratt alternative, committed-error messages, single labelled clause",
    function (array $args) {
        return $args[0] === 'message' ? interpreter_message([$args[1], $args[2]]) : conformance_position([$args[1], $args[2]]);
    },

    // `@` after the operator of a left-recursive rule: valid input parses in both engines and all targets
    [ ['tree', $add, '1 + 2'], both(['left' => '1', 'op' => '+', 'right' => '2']) ],
    [ ['tree', $add, '1 + 2 + 3'], both(['left' => ['left' => '1', 'op' => '+', 'right' => '2'], 'op' => '+', 'right' => '3']) ],
    [ ['tree', $add, '7'], both('7') ],
    // ... and a failure after the commit is an error at the right place, not a backtrack
    [ ['tree', $add, '1 +'], both('ERROR@1:4') ],
    [ ['tree', $add, '1 + x'], both('ERROR@1:5') ],

    // committed errors: 1-based line and a rule's description instead of its name
    [ ['message', 'S = "a" @ "b"', 'ac'], 'Expected "b" in S on parsed source:1:2, found string `c` on line 1 column 2' ],
    [ ['message', "S = \"a\" \"\\n\" @ \"b\"", "a\nx"], 'Expected "b" in S on parsed source:2:1, found string `x` on line 2 column 1' ],
    [ ['message', 'S "a statement" = "a" @ Semi
Semi "a semicolon" = ";"', 'a,'], 'Expected a semicolon in a statement on parsed source:1:2, found symbol `,` on line 1 column 2' ],

    // the interpreter says what was expected at the failing position
    [ ['message', 'S = "a" ("b" | "c")', 'ax'], 'Failed parsing "S" on parsed source:1:2, unexpected "x", expected "b" or "c"' ],
    [ ['message', 'S = "a" T
T "a thing" = "b" | "c"', 'ax'], 'Failed parsing "S" on parsed source:1:2, unexpected "x", expected a thing' ],

    // a single labelled clause is its bare value (the sequence collapses); a second value-producing element keeps the label
    [ ['tree', 'P = number:/[0-9]+/ | name:/[a-z]+/', '12'], both('12') ],
    [ ['tree', 'P = number:/[0-9]+/ NONE | name:/[a-z]+/ NONE
NONE = \nothing %silent', '12'], both(['number' => '12']) ],
];
