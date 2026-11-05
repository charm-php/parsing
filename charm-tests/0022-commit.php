<?php

use Charm\Parsing\GrammarParser;
use Charm\Parsing\Compiler\GrammarCompiler;

/**
 * The commit operator `@`: once a sequence has passed it, a later failure in that sequence
 * is a parse error, not a reason to try the next alternative. Interpreter and compiled parser.
 * Before 0.1.2 the interpreter threw "Class CommittedError not found".
 */
$grammar = 'S = "if" @ "(" "x" ")" | "while" @ "(" "x" ")" | "if" "y" | Word
Word = /[a-z]+/';

return [
    "Commit operator @: no backtracking past the commit, in both parsers",
    function($args) use ($grammar) {
        [$input, $mode] = $args;
        if ($mode === 'interp') {
            return \Charm\cpeg_parse($grammar, $input);
        }
        if (!class_exists('CommitParser')) {
            eval('?>' . (new GrammarCompiler())->compile((new GrammarParser())->parse($grammar, 'test'), 'CommitParser'));
        }
        return (new CommitParser())->parse($input);
    },

    [ ['if(x)', 'interp'], ['if', '(', 'x', ')'] ],
    [ ['if(x)', 'compiled'], ['if', '(', 'x', ')'] ],
    [ ['while(x)', 'interp'], ['while', '(', 'x', ')'] ],
    [ ['while(x)', 'compiled'], ['while', '(', 'x', ')'] ],
    // the commit is never reached, so the other alternatives are still tried
    [ ['foo', 'interp'], 'foo' ],
    [ ['foo', 'compiled'], 'foo' ],
    // "if" "y" would match, but the first alternative committed after "if"
    [ ['ify', 'interp'], Charm\Parsing\Error\State\CommittedError::class ],
    [ ['ify', 'compiled'], \RuntimeException::class ],
    [ ['if(z)', 'interp'], Charm\Parsing\Error\State\CommittedError::class ],
    [ ['if(z)', 'compiled'], \RuntimeException::class ],
];
