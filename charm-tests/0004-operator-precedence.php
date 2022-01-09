<?php return [
    "Testing operator precedence parsing with expressions, various clause types, EOF/SOF operators and %assoc pragmas",
    function($input) {
        return Charm\cpeg_parse(<<<'GRAMMAR'
                Calculate "formula"
                =   ^ Expr $                        <? return $_0; ?>

                Expr "expression"
                =   Expr "**" _ Expr                                                        %assoc=right
                |   ("++" | "--" | [-+~@]) _ Expr
                |   Expr ("++" | "--") _
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
            GRAMMAR,

            $input,
        );
    },
    [ "1+2+3", [ [ 1, "+", 2 ], "+", 3 ] ],
    [ "1", 1 ],
    [ "1**2**3", [ 1, "**", [ 2, "**", 3 ] ] ],
    [ "1**2+3**4^-5*2/3/4/5-7**2", [[[1,"**",2],"+",[3,"**",4]],"^",["-",[[[[[5,"*",2],"/",3],"/",4],"/",5],"-",[7,"**",2]]]] ],
    [ "1^2^3^4^5", [[[[1,"^",2],"^",3],"^",4],"^",5] ],
    [ "-10", [ "-", 10 ] ],
    [ "!!!!10", ["!",["!",["!",["!",10]]]] ],
    [ "10++", [10,"++"] ],
    [ "++10++", ["++",[10,"++"]] ],
    [ "1 ?? 2 ?? 3 ?? 4 ? 100 ?? 200 : 1000 ?? 2000", [[1,"??",[2,"??",[3,"??",4]]],"?",[100,"??",200],":",[1000,"??",2000]] ],
];
