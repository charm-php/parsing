<?php

use Charm\Parsing\GrammarParser;
use Charm\Parsing\Compiler\GrammarCompiler;
use Charm\Parsing\Clause\{Token, Literal};
use Charm\Parsing\Lexers\PhpLexer;
use Charm\Parsing\State;

$lexer = '@lexer Charm\\Parsing\\Lexers\\PhpLexer';

return [
    "Token mode: custom template (-t), @prec directive, Token/Literal clause API, State tokens + tokenData",
    function($args) {
        [$what, $arg] = $args;
        switch ($what) {
            case 'template':
                // A custom template replaces the built-in one entirely
                $tpl = tempnam(sys_get_temp_dir(), 'tpl');
                file_put_contents($tpl, "<?php\n// custom template for {{GRAMMAR_NAME}}\n{{NAMESPACE}}\n{{USE_STATEMENTS}}\n{{CLASS_DECLARATION}} {}\n{{SIGNAL_ENUM}}\n");
                $g = (new GrammarParser())->parse($arg, 'test');
                $code = (new GrammarCompiler())->compile($g, 'X', ['template' => $tpl]);
                unlink($tpl);
                return str_contains($code, '// custom template for') && !str_contains($code, 'function parse(');
            case 'default-template':
                $g = (new GrammarParser())->parse($arg, 'test');
                $code = (new GrammarCompiler())->compile($g, 'X');
                return [
                    str_contains($code, '\\Charm\\Parsing\\TokensInterface'),
                    str_contains($code, 'PhpLexer'),
                    str_contains($code, 'Generated token-mode parser'),
                ];
            case 'string-mode':
                $g = (new GrammarParser())->parse($arg, 'test');
                return [$g->isTokenMode(), str_contains((new GrammarCompiler())->compile($g, 'X'), 'TokensInterface')];
            case 'clauses':
                $t = new Token('T_LNUMBER', \Charm\Parsing\Clause::NONE, null);
                $l = new Literal('+', \Charm\Parsing\Clause::NONE, null);
                return [$t->getTokenName(), $t->asString(), $t->toGrammarString(), $l->getLiteral(), $l->asString(), $l->toGrammarString()];
            case 'prec':
                $g = (new GrammarParser())->parse($arg, 'test');
                return [$g->hasPrecedenceTable(), $g->getPrecedence('+'), $g->getPrecedence('*'), $g->getPrecedence('T_POW')];
            case 'state':
                $tokens = (new PhpLexer())->lex('<?php $a = 1;');
                $s = new State();
                $s->setStateTokens($tokens);
                $r = [$s->isTokenMode(), $s->length, $s->tokens === $tokens->getTokensArray(), $s->tokenData === $tokens,
                      $s->getSourceString(), $s->getByteOffsetAt(1), $s->tokenData->getValue(0)];
                $s->setStateString('abc');
                $r[] = [$s->isTokenMode(), $s->tokens, $s->tokenData, $s->length, $s->getSourceString()];
                return $r;
        }
    },

    [ ['template', "$lexer\nS = T_LNUMBER"], true ],
    [ ['default-template', "$lexer\nS = T_LNUMBER"], [true, true, true] ],
    [ ['string-mode', 'S = "a" "b"'], [false, false] ],
    [ ['clauses', null], ['T_LNUMBER', 'T_LNUMBER', 'T_LNUMBER', '+', '"+"', '"+"'] ],
    [ ['prec', "$lexer
@prec {
  left \"+\"
  left \"*\"
  right T_POW
}
S = T_LNUMBER"], [true, ['level' => 1, 'assoc' => 'left'], ['level' => 2, 'assoc' => 'left'], ['level' => 3, 'assoc' => 'right']] ],
    [ ['state', null], [true, 4, true, true, '<?php $a = 1;', 9, '$a', [false, null, null, 3, 'abc']] ],
];
