<?php

use Charm\Parsing\GrammarParser;
use Charm\Parsing\Compiler\GrammarCompiler;

return [
    "Token mode: @lexer directive, Token and Literal clauses, compiled token parser",
    function($args) {
        [$grammar, $input] = $args;

        static $parsers = [];
        $key = md5($grammar);
        if (!isset($parsers[$key])) {
            $g = (new GrammarParser())->parse($grammar, 'test');
            if (!$g->isTokenMode()) {
                return 'not token mode';
            }
            $className = 'CompiledTokenParser_' . $key;
            $code = (new GrammarCompiler())->compile($g, $className);
            $tempFile = sys_get_temp_dir() . '/' . $className . '.php';
            file_put_contents($tempFile, $code);
            require_once $tempFile;
            $parsers[$key] = new $className();
        }
        try {
            return $parsers[$key]->parse($input);
        } catch (\RuntimeException $e) {
            return 'error: ' . substr($e->getMessage(), 0, 16);
        }
    },

    // Token (T_LNUMBER) and Literal ("+") clauses; values are the token text
    [ ["@lexer Charm\\Parsing\\Lexers\\PhpLexer\nSum = T_LNUMBER \"+\" T_LNUMBER", '<?php 1+2;'], ['1', '+', '2'] ],
    [ ["@lexer Charm\\Parsing\\Lexers\\PhpLexer\nSum = T_LNUMBER \"+\" T_LNUMBER", '<?php 10 + 20;'], ['10', '+', '20'] ],

    // Repetition of a Token clause, with a literal separator
    [ ["@lexer Charm\\Parsing\\Lexers\\PhpLexer\nList = T_LNUMBER (\",\" T_LNUMBER)*", '<?php 1,2,3;'], ['1', [[',', '2'], [',', '3']]] ],

    // A Token clause does not match a different token type
    [ ["@lexer Charm\\Parsing\\Lexers\\PhpLexer\nS = T_LNUMBER \"+\" T_LNUMBER", '<?php 1+"a";'], 'error: Parse error at l' ],
];
