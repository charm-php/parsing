<?php
namespace Charm\Parsing\Library;

class ExpressionParser extends \Charm\Parser {
    const OPTIONS = [ 'trace' => false ];
    const GRAMMAR = <<<'CPEG'

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

CPEG;
}
