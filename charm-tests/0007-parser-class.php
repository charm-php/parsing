<?php return [
    "Testing a parser class instance",
    function($input) {

        $parser = new class extends \Charm\Parser {
            const GRAMMAR = <<<'G'

            Expr
                = Expr "^" Expr                 %assoc=right        // right associative expression
                                                <?
                                                    return $_0 ^ $_2;
                                                ?>
                | Expr ("*" | "/") Expr         <?
                                                    if ($_1 == "*")
                                                        return $_0 * $_2;
                                                    else
                                                        return $_0 / $_2;
                                                ?>
                | Expr ("+" | "-") Expr         <?
                                                    if ($_1 == "+")
                                                        return $_0 + $_2;
                                                    else
                                                        return $_0 - $_2;
                                                ?>
                | Number

            Number
                = /[1-9][0-9]*|0/               <? return intval($_0); ?>

            G;
        };

        return $parser->parse($input, __FILE__);
    },
    [ "1+2", 3 ],
];
