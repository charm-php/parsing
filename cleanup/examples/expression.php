<?php
require(dirname(__DIR__).'/vendor/autoload.php');

if (ini_get('zend.assertions') === '0') {
    echo "ENABLING zend.assertions\n";
    ini_set('zend.assertions', '1');
}
$t = microtime(true);
$grammarParser = new Charm\Parsing\GrammarParser();
echo "Construct GrammarParser: ".(microtime(true)-$t)."\n";$t = microtime(true);
$grammar = $grammarParser->parse(<<<'GRAMMAR'

    Calculate "formula"
    =   ^ Expr $                        <? return $_0; ?>

    Expr "expression"
    =   Expr "**" _ Expr                                                        %assoc=right
    |   ("++" | "--" | [-+~@]) _ Expr
    |   Expr "instanceof" _ Expr
    |   "!" Expr
    |   Expr [*/%] _ Expr
    |   Expr [-+] _ Expr
    |   Expr /<<|>>/ _ Expr
    |   Expr "." _ Expr
    |   Expr /<=|>=|<|>/ _ Expr                                                 %assoc=none
    |   Expr /===|==|!==|!=|<>|<=>/ _ Expr                                      %assoc=none
    |   Expr "&" _ Expr
    |   Expr "^" _ Expr
    |   Expr "|" _ Expr
    |   Expr "&&" _ Expr
    |   Expr "||" _ Expr
    |   Expr "??" _ Expr                                                        %assoc=right
    |   Expr "?" _ Expr ":" _ Expr                                              %assoc=none
    |   Expr /=|\+=|-=|\*\*=|\*=|\/=|\.=|%=|&=|\|=|\^=|<<=|>>=|\?\?=/ _ Expr    %assoc=right
    |   "yield" _ "from" _ Expr
    |   "yield" _ Expr
    |   "print" _ Expr
    |   Expr "and" _ Expr
    |   Expr "xor" _ Expr
    |   Expr "or" _ Expr
    |   Primary

    Primary
    =   (FLOAT / INTEGER) _             <? return $_0; ?>

    FLOAT
    =   /(0|[1-9][0-9]*)(\.[0-9]+)/     <? return floatval($_0); ?>

    INTEGER
    =   /(0|[1-9][0-9]*)/               <? return intval($_0); ?>

    _
    =   /\s*/                           <? return true; ?>

GRAMMAR, __FILE__);
echo "Parse Grammar: ".(microtime(true)-$t)."\n";$t = microtime(true);

/*
echo "GRAMMAR:\n";
echo json_encode($grammar, JSON_PRETTY_PRINT)."\n";
echo "----------------\n";
*/
$parser = new Charm\Parsing\CPEGParser($grammar, null, new Charm\Parsing\Options(['trace' => true]));
echo "Construct CPEGParser: ".(microtime(true)-$t)."\n";$t = microtime(true);

//echo "PARSING:\n";
$ast = $parser->parse("10+10 *= 10 *= 10", __FILE__, __LINE__);
echo "Parse expression: ".(microtime(true)-$t)."\n";$t = microtime(true);
/*
1 * 2
3 * 4
2 + 12
5 / 6
14 - 0.83333333333333
*/
