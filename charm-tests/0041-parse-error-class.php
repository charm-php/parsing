<?php

use Charm\Parsing\GrammarParser;
use Charm\Parsing\Compiler\GrammarCompiler;

/**
 * Compiled parsers throw their own `{Class}ParseError extends \RuntimeException` carrying
 * `committed`, `sourceLine`, `sourceColumn`, `offset`, `expected` and `found`.
 */
$grammar = 'S = "if" @ "(" "x" ")" | "while" "x" | Word
Word = /[a-z]+/';

return [
    "Generated parsers throw {Class}ParseError with readable properties",
    function($input) use ($grammar) {
        if (!class_exists('ParseErrorCaseParser')) {
            eval('?>' . (new GrammarCompiler())->compile((new GrammarParser())->parse($grammar, 'test'), 'ParseErrorCaseParser'));
        }
        try {
            (new ParseErrorCaseParser())->parse($input);
            return 'no error';
        } catch (\RuntimeException $e) {
            return [$e instanceof ParseErrorCaseParserParseError, $e->committed, $e->sourceLine, $e->sourceColumn, $e->offset, $e->found];
        }
    },

    [ 'if(z)', [true, true, 1, 4, 3, "'z'"] ],
    [ '1', [true, false, 1, 1, 0, "'1'"] ],
];
