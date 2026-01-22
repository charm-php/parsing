<?php
/**
 * Tests for the ConsecutiveTerminalsOptimizer.
 *
 * This optimizer merges consecutive Text/Regex terminals in OrderedChoice
 * clauses into a single combined regex, reducing the number of alternatives.
 *
 * Run with CHARM_COMPILED=1 to test with compiled parser.
 */
use function Charm\cpeg_parse;

return [
    "Testing ConsecutiveTerminalsOptimizer (merged keyword/terminal alternatives)",
    function($input) {
        [$grammar, $source] = $input;
        return cpeg_parse($grammar, $source);
    },

    // === Multiple text literals (keywords) ===

    // Simple keyword matching - all should work after merging
    [
        [<<<'GRAMMAR'
            Keyword = "break" | "continue" | "return" | "throw"
        GRAMMAR, "break"],
        'break'
    ],
    [
        [<<<'GRAMMAR'
            Keyword = "break" | "continue" | "return" | "throw"
        GRAMMAR, "continue"],
        'continue'
    ],
    [
        [<<<'GRAMMAR'
            Keyword = "break" | "continue" | "return" | "throw"
        GRAMMAR, "return"],
        'return'
    ],
    [
        [<<<'GRAMMAR'
            Keyword = "break" | "continue" | "return" | "throw"
        GRAMMAR, "throw"],
        'throw'
    ],

    // Keywords with snippets - cannot be merged by ConsecutiveTerminalsOptimizer
    // but may be handled by single-variance optimization
    [
        [<<<'GRAMMAR'
            Bool = "true"  <? return true; ?>
                 | "false" <? return false; ?>
        GRAMMAR, "true"],
        true
    ],
    [
        [<<<'GRAMMAR'
            Bool = "true"  <? return true; ?>
                 | "false" <? return false; ?>
        GRAMMAR, "false"],
        false
    ],

    // === Non-consecutive terminals (with rule reference between) ===
    // "int" | "float" get merged, then SomeRule breaks, then "string" | "bool" get merged

    [
        [<<<'GRAMMAR'
            Type = "int" | "float" | Complex | "string" | "bool"
            Complex = "complex"
        GRAMMAR, "int"],
        'int'
    ],
    [
        [<<<'GRAMMAR'
            Type = "int" | "float" | Complex | "string" | "bool"
            Complex = "complex"
        GRAMMAR, "float"],
        'float'
    ],
    [
        [<<<'GRAMMAR'
            Type = "int" | "float" | Complex | "string" | "bool"
            Complex = "complex"
        GRAMMAR, "complex"],
        'complex'
    ],
    [
        [<<<'GRAMMAR'
            Type = "int" | "float" | Complex | "string" | "bool"
            Complex = "complex"
        GRAMMAR, "string"],
        'string'
    ],
    [
        [<<<'GRAMMAR'
            Type = "int" | "float" | Complex | "string" | "bool"
            Complex = "complex"
        GRAMMAR, "bool"],
        'bool'
    ],

    // === Multiple regex patterns ===

    [
        [<<<'GRAMMAR'
            Token = /[a-z]+/ | /[A-Z]+/ | /[0-9]+/
        GRAMMAR, "hello"],
        'hello'
    ],
    [
        [<<<'GRAMMAR'
            Token = /[a-z]+/ | /[A-Z]+/ | /[0-9]+/
        GRAMMAR, "WORLD"],
        'WORLD'
    ],
    [
        [<<<'GRAMMAR'
            Token = /[a-z]+/ | /[A-Z]+/ | /[0-9]+/
        GRAMMAR, "12345"],
        '12345'
    ],

    // === Mixed text and regex ===

    [
        [<<<'GRAMMAR'
            Value = "null" | "true" | "false" | /[0-9]+/ | /"[^"]*"/
        GRAMMAR, "null"],
        'null'
    ],
    [
        [<<<'GRAMMAR'
            Value = "null" | "true" | "false" | /[0-9]+/ | /"[^"]*"/
        GRAMMAR, "true"],
        'true'
    ],
    [
        [<<<'GRAMMAR'
            Value = "null" | "true" | "false" | /[0-9]+/ | /"[^"]*"/
        GRAMMAR, "42"],
        '42'
    ],
    [
        [<<<'GRAMMAR'
            Value = "null" | "true" | "false" | /[0-9]+/ | /"[^"]*"/
        GRAMMAR, '"hello"'],
        '"hello"'
    ],

    // === Simple keyword alternatives used in sequence ===
    // Tests that merged keywords work correctly when used with other rules

    [
        [<<<'GRAMMAR'
            Statement = Keyword _ ";"
            Keyword = "break" | "continue" | "return"
            _ = /[ \t]*/
        GRAMMAR, "break ;"],
        ['break', ' ', ';']
    ],
    [
        [<<<'GRAMMAR'
            Statement = Keyword _ ";"
            Keyword = "break" | "continue" | "return"
            _ = /[ \t]*/
        GRAMMAR, "continue;"],
        ['continue', '', ';']
    ],
    [
        [<<<'GRAMMAR'
            Statement = Keyword _ ";"
            Keyword = "break" | "continue" | "return"
            _ = /[ \t]*/
        GRAMMAR, "return ;"],
        ['return', ' ', ';']
    ],

    // === Longer keyword list (stress test) ===
    // Note: "instanceof" must come before "in" to match correctly (longer match first)

    [
        [<<<'GRAMMAR'
            JSKeyword = "break" | "case" | "catch" | "continue" | "debugger"
                      | "default" | "delete" | "do" | "else" | "finally"
                      | "for" | "function" | "if" | "instanceof" | "in"
                      | "new" | "return" | "switch" | "this" | "throw"
                      | "try" | "typeof" | "var" | "void" | "while" | "with"
        GRAMMAR, "function"],
        'function'
    ],
    [
        [<<<'GRAMMAR'
            JSKeyword = "break" | "case" | "catch" | "continue" | "debugger"
                      | "default" | "delete" | "do" | "else" | "finally"
                      | "for" | "function" | "if" | "instanceof" | "in"
                      | "new" | "return" | "switch" | "this" | "throw"
                      | "try" | "typeof" | "var" | "void" | "while" | "with"
        GRAMMAR, "instanceof"],
        'instanceof'
    ],
    [
        [<<<'GRAMMAR'
            JSKeyword = "break" | "case" | "catch" | "continue" | "debugger"
                      | "default" | "delete" | "do" | "else" | "finally"
                      | "for" | "function" | "if" | "instanceof" | "in"
                      | "new" | "return" | "switch" | "this" | "throw"
                      | "try" | "typeof" | "var" | "void" | "while" | "with"
        GRAMMAR, "with"],
        'with'
    ],
];
