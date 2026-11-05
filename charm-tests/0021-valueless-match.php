<?php

use Charm\Parsing\GrammarParser;
use Charm\Parsing\Compiler\GrammarCompiler;

/**
 * A goal rule that matches without producing a value (predicates only) must give the same
 * result from a compiled parser as from the interpreter: null, never the internal Signal.
 * 0.1.0 shipped compiled parsers returning true here; 0.1.1 restored null.
 */
return [
    "Value-less match: compiled parse() and cpeg_parse() agree (null)",
    function($args) {
        [$grammar, $input] = $args;
        $interpreted = \Charm\cpeg_parse($grammar, $input);
        $className = 'ValuelessParser_' . md5($grammar);
        if (!class_exists($className)) {
            $g = (new GrammarParser())->parse($grammar, 'test');
            eval('?>' . (new GrammarCompiler())->compile($g, $className));
        }
        $compiled = (new $className())->parse($input);
        return [$interpreted, $compiled];
    },

    [ ['S = &"a"', 'a'], [null, null] ],
    [ ['S = !"b"', 'a'], [null, null] ],
    // a match with a value is unaffected
    [ ['S = "a"', 'a'], ['a', 'a'] ],
];
