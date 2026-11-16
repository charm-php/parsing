<?php
/**
 * Error message quality, identical in every engine (interpreter, PHP compiled parser, C, JavaScript, Python):
 *  - a literal "/" is shown as "/", not as "\/"
 *  - the expected list is not cut short, however long it is
 *  - a committed sequence reports what it was waiting for first (`expected ";"`), then what else could have continued
 *  - a described rule stands for its alternatives (`an operator`)
 *  - the interpreter and the compiled parser put the error at the same 1-based line and column
 */
require_once __DIR__ . '/support/conformance.php';

/** What each engine says about a failing input: the position (checked across all engines and targets) plus both messages. */
function engine_messages(array $args): array {
    [$grammar, $input] = $args;
    [$interpreted, $compiled] = conformance_position([$grammar, $input]);
    $messages = [];
    foreach ([0, 1] as $compiledEngine) {
        $compiledEngine ? putenv('CHARM_COMPILED=1') : putenv('CHARM_COMPILED');
        try {
            \Charm\cpeg_parse($grammar, $input);
            $messages[] = 'no error';
        } catch (\RuntimeException $e) {
            $messages[] = $e->getMessage();
        }
    }
    putenv('CHARM_COMPILED');
    return [$interpreted === $compiled ? $interpreted : [$interpreted, $compiled], $messages];
}

$ops = 'S = "{" _ "let" @ _ Expr _ ";" _ "}" $
Expr = left:Expr _ op:Op _ right:Expr | Num
Op "an operator" = "+" | "-" | "*" | "/" | "%"
Num = /[0-9]+/
_ = /\s*/ %silent';

$many = 'S = "a" @ _ T
T = "q1" | "q2" | "q3" | "q4" | "q5" | "q6" | "q7" | "q8" | "q9" | "r1" | "r2" | "r3" | "r4" | "r5" | "r6" | "r7" | "r8" | "r9" | "s1" | "s2" | "s3" | "s4" | "s5" | "s6" | "s7" | "s8" | "s9"
_ = /\s*/ %silent';

return [
    "Error messages: same text in every engine",
    'engine_messages',

    // "/" stays "/"
    [ ['S = "/" | "x"', '?'],
      ['ERROR@1:1', [
          'Failed parsing "S" on parsed source:1:1, unexpected "?", expected "/" or "x"',
          'Parse error at line 1, column 1: expected "/" or "x", found \'?\'']] ],
    // the committed sequence's own expectation first, then the described rule standing for the operators
    [ [$ops, '{ let 1 2 }'], ['ERROR@1:9', [
          'Expected ";" in S on parsed source:1:9, found string `2` on line 1 column 9',
          'Parse error at line 1, column 9: expected ";" or an operator, found \'2\'']] ],
    // a long expected list is complete (the C target used to cut it at 255 bytes)
    [ [$many, 'a z'], ['ERROR@1:3', [
          'Expected T in S on parsed source:1:3, found string `z` on line 1 column 3',
          'Parse error at line 1, column 3: expected "q1" or "q2" or "q3" or "q4" or "q5" or "q6" or "q7" or "q8" or "q9" or "r1" or "r2" or "r3" or "r4" or "r5" or "r6" or "r7" or "r8" or "r9" or "s1" or "s2" or "s3" or "s4" or "s5" or "s6" or "s7" or "s8" or "s9", found \'z\'']] ],
    // the interpreter and the compiled parsers agree on a 1-based column across lines
    [ ['S = "a" @ "\n" "b"', "a\nc"], ['ERROR@2:1', [
          'Expected "b" in S on parsed source:2:1, found string `c` on line 2 column 1',
          'Parse error at line 2, column 1: expected "b", found \'c\'']] ],
];
