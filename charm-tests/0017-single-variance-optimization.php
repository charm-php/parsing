<?php
/**
 * Tests for the single-variance-point compiler optimization.
 *
 * This optimization combines OrderedChoice alternatives that differ only
 * by a single terminal into one combined regex with dispatch.
 *
 * Run with CHARM_COMPILED=1 to test the compiled parser optimization.
 */
use function Charm\cpeg_parse;

return [
    "Testing single-variance-point optimization (combined regex + dispatch)",
    function($input) {
        [$grammar, $source] = $input;
        return cpeg_parse($grammar, $source);
    },

    // === Pure terminal alternatives (like Number rule) ===

    // Number-style: multiple regex alternatives with different snippets
    [
        [<<<'GRAMMAR'
            Number = /0x[0-9a-fA-F]+/  <? return ['hex', $_0]; ?>
                   | /0b[01]+/         <? return ['bin', $_0]; ?>
                   | /0o[0-7]+/        <? return ['oct', $_0]; ?>
                   | /[0-9]+/          <? return ['dec', $_0]; ?>
        GRAMMAR, "0xFF"],
        ['hex', '0xFF']
    ],
    [
        [<<<'GRAMMAR'
            Number = /0x[0-9a-fA-F]+/  <? return ['hex', $_0]; ?>
                   | /0b[01]+/         <? return ['bin', $_0]; ?>
                   | /0o[0-7]+/        <? return ['oct', $_0]; ?>
                   | /[0-9]+/          <? return ['dec', $_0]; ?>
        GRAMMAR, "0b1010"],
        ['bin', '0b1010']
    ],
    [
        [<<<'GRAMMAR'
            Number = /0x[0-9a-fA-F]+/  <? return ['hex', $_0]; ?>
                   | /0b[01]+/         <? return ['bin', $_0]; ?>
                   | /0o[0-7]+/        <? return ['oct', $_0]; ?>
                   | /[0-9]+/          <? return ['dec', $_0]; ?>
        GRAMMAR, "0o755"],
        ['oct', '0o755']
    ],
    [
        [<<<'GRAMMAR'
            Number = /0x[0-9a-fA-F]+/  <? return ['hex', $_0]; ?>
                   | /0b[01]+/         <? return ['bin', $_0]; ?>
                   | /0o[0-7]+/        <? return ['oct', $_0]; ?>
                   | /[0-9]+/          <? return ['dec', $_0]; ?>
        GRAMMAR, "42"],
        ['dec', '42']
    ],

    // String-style: two regex alternatives
    [
        [<<<'GRAMMAR'
            String = /"[^"]*"/   <? return ['double', $_0]; ?>
                   | /'[^']*'/   <? return ['single', $_0]; ?>
        GRAMMAR, '"hello"'],
        ['double', '"hello"']
    ],
    [
        [<<<'GRAMMAR'
            String = /"[^"]*"/   <? return ['double', $_0]; ?>
                   | /'[^']*'/   <? return ['single', $_0]; ?>
        GRAMMAR, "'world'"],
        ['single', "'world'"]
    ],

    // Text alternatives (keywords)
    [
        [<<<'GRAMMAR'
            Keyword = "true"   <? return true; ?>
                    | "false"  <? return false; ?>
                    | "null"   <? return null; ?>
        GRAMMAR, "true"],
        true
    ],
    [
        [<<<'GRAMMAR'
            Keyword = "true"   <? return true; ?>
                    | "false"  <? return false; ?>
                    | "null"   <? return null; ?>
        GRAMMAR, "false"],
        false
    ],
    [
        [<<<'GRAMMAR'
            Keyword = "true"   <? return true; ?>
                    | "false"  <? return false; ?>
                    | "null"   <? return null; ?>
        GRAMMAR, "null"],
        null
    ],

    // === Mixed: some alternatives with snippets, some without ===

    [
        [<<<'GRAMMAR'
            Value = "true"   <? return true; ?>
                  | "false"  <? return false; ?>
                  | "null"
        GRAMMAR, "null"],
        'null'  // No snippet = returns the matched string
    ],

    // === Alternatives with prefix and suffix ===

    // Common prefix, varying operator, common suffix
    [
        [<<<'GRAMMAR'
            BinOp = "x" "+" "y"  <? return ['add', $_0, $_2]; ?>
                  | "x" "-" "y"  <? return ['sub', $_0, $_2]; ?>
                  | "x" "*" "y"  <? return ['mul', $_0, $_2]; ?>
        GRAMMAR, "x+y"],
        ['add', 'x', 'y']
    ],
    [
        [<<<'GRAMMAR'
            BinOp = "x" "+" "y"  <? return ['add', $_0, $_2]; ?>
                  | "x" "-" "y"  <? return ['sub', $_0, $_2]; ?>
                  | "x" "*" "y"  <? return ['mul', $_0, $_2]; ?>
        GRAMMAR, "x-y"],
        ['sub', 'x', 'y']
    ],
    [
        [<<<'GRAMMAR'
            BinOp = "x" "+" "y"  <? return ['add', $_0, $_2]; ?>
                  | "x" "-" "y"  <? return ['sub', $_0, $_2]; ?>
                  | "x" "*" "y"  <? return ['mul', $_0, $_2]; ?>
        GRAMMAR, "x*y"],
        ['mul', 'x', 'y']
    ],

    // With named captures in prefix/suffix
    [
        [<<<'GRAMMAR'
            Assign = lhs:/[a-z]+/ _ "+=" _ rhs:/[0-9]+/  <? return ['add_assign', $lhs, $rhs]; ?>
                   | lhs:/[a-z]+/ _ "-=" _ rhs:/[0-9]+/  <? return ['sub_assign', $lhs, $rhs]; ?>
                   | lhs:/[a-z]+/ _ "=" _ rhs:/[0-9]+/   <? return ['assign', $lhs, $rhs]; ?>
            _ = /[ \t]*/
        GRAMMAR, "x += 5"],
        ['add_assign', 'x', '5']
    ],
    [
        [<<<'GRAMMAR'
            Assign = lhs:/[a-z]+/ _ "+=" _ rhs:/[0-9]+/  <? return ['add_assign', $lhs, $rhs]; ?>
                   | lhs:/[a-z]+/ _ "-=" _ rhs:/[0-9]+/  <? return ['sub_assign', $lhs, $rhs]; ?>
                   | lhs:/[a-z]+/ _ "=" _ rhs:/[0-9]+/   <? return ['assign', $lhs, $rhs]; ?>
            _ = /[ \t]*/
        GRAMMAR, "y = 10"],
        ['assign', 'y', '10']
    ],

    // === With fallback alternative ===

    [
        [<<<'GRAMMAR'
            Literal = /0x[0-9a-fA-F]+/  <? return ['hex', $_0]; ?>
                    | /[0-9]+/          <? return ['dec', $_0]; ?>
                    | Identifier
            Identifier = /[a-z]+/       <? return ['id', $_0]; ?>
        GRAMMAR, "0xAB"],
        ['hex', '0xAB']
    ],
    [
        [<<<'GRAMMAR'
            Literal = /0x[0-9a-fA-F]+/  <? return ['hex', $_0]; ?>
                    | /[0-9]+/          <? return ['dec', $_0]; ?>
                    | Identifier
            Identifier = /[a-z]+/       <? return ['id', $_0]; ?>
        GRAMMAR, "123"],
        ['dec', '123']
    ],
    [
        [<<<'GRAMMAR'
            Literal = /0x[0-9a-fA-F]+/  <? return ['hex', $_0]; ?>
                    | /[0-9]+/          <? return ['dec', $_0]; ?>
                    | Identifier
            Identifier = /[a-z]+/       <? return ['id', $_0]; ?>
        GRAMMAR, "foo"],
        ['id', 'foo']
    ],

    // === Edge case: only 2 alternatives ===

    [
        [<<<'GRAMMAR'
            Bool = "true"   <? return true; ?>
                 | "false"  <? return false; ?>
        GRAMMAR, "true"],
        true
    ],
    [
        [<<<'GRAMMAR'
            Bool = "true"   <? return true; ?>
                 | "false"  <? return false; ?>
        GRAMMAR, "false"],
        false
    ],
];
