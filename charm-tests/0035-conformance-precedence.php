<?php
/**
 * Conformance (see 0030): left recursion, positional precedence (first alternative binds
 * tightest), %assoc, %prec and @prec. docs/precedence-and-pragmas.md says the interpreter
 * ignores %prec and @prec; the rows still expect both paths to honour them (the docs also
 * recommend %prec=N "to stay engine-independent"), so those rows fail until that is resolved.
 * Small focused grammars; 0004 has the large one.
 */
require_once __DIR__ . '/support/conformance.php';

$E = 'PARSE_ERROR';
$N = "\nN = /[0-9]/";
return [
    "Conformance: left recursion, precedence and associativity",
    'conformance_case',

    // direct left recursion, left associative by default
    [ ['S = A $
A = A "x" | "y"', 'y'], both(['y']) ],
    [ ['S = A
A = A "x" | "y"', 'yxx'], both([['y', 'x'], 'x']) ],
    [ ['S = A $
A = A "x" | "y"', 'yxx'], both([[['y', 'x'], 'x']]) ],
    [ ['S = A
A = A "x" | "y"', 'x'], both($E) ],
    [ ['S = A
A = "y" | A "x"', 'yxx'], both([['y', 'x'], 'x']) ],
    // indirect left recursion
    [ ['S = A
A = B "x" | "y"
B = A', 'yxx'], both([['y', 'x'], 'x']) ],
    [ ['S = Expr
Expr = Expr "+" Expr | N' . $N, '1+2+3'], both([['1', '+', '2'], '+', '3']) ],
    [ ['S = Expr
Expr = Expr "-" Expr | N' . $N, '1-2-3-4'], both([[['1', '-', '2'], '-', '3'], '-', '4']) ],
    [ ['S = Expr
Expr = Expr "+" Expr | N' . $N, '1'], both('1') ],
    [ ['S = Expr $
Expr = Expr "+" Expr | N' . $N, '1+'], both($E) ],
    [ ['S = Expr
Expr = Expr "+" Expr | N' . $N, '+1'], both($E) ],

    // positional precedence: the first alternative binds tightest
    [ ['S = Expr
Expr = Expr "*" Expr | Expr "+" Expr | N' . $N, '1+2*3'], both(['1', '+', ['2', '*', '3']]) ],
    [ ['S = Expr
Expr = Expr "*" Expr | Expr "+" Expr | N' . $N, '1*2+3'], both([['1', '*', '2'], '+', '3']) ],
    [ ['S = Expr
Expr = Expr "+" Expr | Expr "*" Expr | N' . $N, '1+2*3'], both([['1', '+', '2'], '*', '3']) ],
    [ ['S = Expr
Expr = Expr "*" Expr | Expr "+" Expr | N' . $N, '1+2*3+4'], both([['1', '+', ['2', '*', '3']], '+', '4']) ],
    [ ['S = Expr
Expr = Expr [*/] Expr | Expr [+-] Expr | N' . $N, '1-2/3*4'], both(['1', '-', [['2', '/', '3'], '*', '4']]) ],

    // %assoc
    [ ['S = Expr
Expr = Expr "^" Expr %assoc=right | N' . $N, '1^2^3'], both(['1', '^', ['2', '^', '3']]) ],
    [ ['S = Expr
Expr = Expr "^" Expr %assoc=left | N' . $N, '1^2^3'], both([['1', '^', '2'], '^', '3']) ],
    [ ['S = Expr
Expr = Expr "^" Expr | N' . $N, '1^2^3'], both([['1', '^', '2'], '^', '3']) ],
    [ ['S = Expr
Expr = Expr "^" Expr %assoc=right | Expr "+" Expr | N' . $N, '1^2^3+4'], both([['1', '^', ['2', '^', '3']], '+', '4']) ],
    [ ['S = Expr
Expr = Expr "^" Expr %assoc=right | Expr "+" Expr | N' . $N, '1+2^3^4'], both(['1', '+', ['2', '^', ['3', '^', '4']]]) ],
    [ ['S = Expr
Expr = Expr "<" Expr %assoc=none | N' . $N, '1<2'], both(['1', '<', '2']) ],
    // the interpreter treats `none` like `left` (docs/precedence-and-pragmas.md)
    [ ['S = Expr
Expr = Expr "<" Expr %assoc=none | N' . $N, '1<2<3'], both([['1', '<', '2'], '<', '3']) ],

    // prefix and postfix operators
    [ ['S = Expr
Expr = "-" Expr | Expr "+" Expr | N' . $N, '-1+2'], both([['-', '1'], '+', '2']) ],
    [ ['S = Expr
Expr = "-" Expr | Expr "+" Expr | N' . $N, '--1'], both(['-', ['-', '1']]) ],
    [ ['S = Expr
Expr = "-" Expr | Expr "+" Expr | N' . $N, '1+-2'], both(['1', '+', ['-', '2']]) ],
    [ ['S = Expr
Expr = Expr "!" | N' . $N, '1!!'], both([['1', '!'], '!']) ],
    [ ['S = Expr
Expr = Expr "!" | Expr "+" Expr | N' . $N, '1!+2!'], both([['1', '!'], '+', ['2', '!']]) ],
    [ ['S = Expr
Expr = "-" Expr | Expr "*" Expr | Expr "+" Expr | N' . $N, '-1*2+3'], both([[['-', '1'], '*', '2'], '+', '3']) ],

    // grouping inside a left-recursive rule
    [ ['S = Expr
Expr = "(" Expr ")" | Expr "+" Expr | N' . $N, '(1)'], both(['(', '1', ')']) ],
    [ ['S = Expr
Expr = "(" Expr ")" | Expr "+" Expr | N' . $N, '(1+2)'], both(['(', ['1', '+', '2'], ')']) ],
    [ ['S = Expr
Expr = "(" Expr ")" | Expr "+" Expr | N' . $N, '(1+2)+3'], both([['(', ['1', '+', '2'], ')'], '+', '3']) ],
    [ ['S = Expr
Expr = Expr "+" Expr | "(" Expr ")" | N' . $N, '(1+2)+3'], both([['(', ['1', '+', '2'], ')'], '+', '3']) ],
    [ ['S = Expr
Expr = Expr "+" Expr | N | "(" Expr ")"' . $N, '(1)+2'], both([['(', '1', ')'], '+', '2']) ],
    [ ['S = Expr
Expr = Expr "*" Expr | Expr "+" Expr | "(" Expr ")" | N' . $N, '(1+2)*3'], both([['(', ['1', '+', '2'], ')'], '*', '3']) ],

    // snippets in left-recursive alternatives
    [ ['S = Expr
Expr = Expr "*" Expr <? return $_0 * $_2; ?> | Expr "+" Expr <? return $_0 + $_2; ?> | N <? return intval($_0); ?>' . $N, '2+3*4'], both(14) ],
    [ ['S = Expr
Expr = Expr "-" Expr <? return $_0 - $_2; ?> | N <? return intval($_0); ?>' . $N, '9-3-2'], both(4) ],
    [ ['S = Expr
Expr = Expr "^" Expr %assoc=right <? return $_0 ** $_2; ?> | N <? return intval($_0); ?>' . $N, '2^3^2'], both(512) ],

    // %prec=N: explicit precedence, independent of position
    [ ['S = Expr
Expr = Expr "+" Expr %prec=1 | Expr "*" Expr %prec=2 | N' . $N, '1+2*3'], both(['1', '+', ['2', '*', '3']]) ],
    [ ['S = Expr
Expr = Expr "+" Expr %prec=1 | Expr "*" Expr %prec=2 | N' . $N, '1*2+3'], both([['1', '*', '2'], '+', '3']) ],
    [ ['S = Expr
Expr = Expr "*" Expr %prec=2 | Expr "+" Expr %prec=1 | N' . $N, '1+2*3'], both(['1', '+', ['2', '*', '3']]) ],
    [ ['S = Expr
Expr = Expr "+" Expr %prec=2 | Expr "*" Expr %prec=1 | N' . $N, '1+2*3'], both([['1', '+', '2'], '*', '3']) ],

    // @prec table: later lines bind tighter
    [ ['@prec {
  left "+"
  left "*"
}
S = Expr
Expr = Expr "+" Expr | Expr "*" Expr | N' . $N, '1+2*3'], both(['1', '+', ['2', '*', '3']]) ],
    [ ['@prec {
  left "+"
  left "*"
}
S = Expr
Expr = Expr "+" Expr | Expr "*" Expr | N' . $N, '1*2+3'], both([['1', '*', '2'], '+', '3']) ],
    [ ['@prec {
  left "*"
  left "+"
}
S = Expr
Expr = Expr "+" Expr | Expr "*" Expr | N' . $N, '1+2*3'], both([['1', '+', '2'], '*', '3']) ],
    [ ['@prec {
  left "+" "-"
  left "*"
}
S = Expr
Expr = Expr "*" Expr | Expr "+" Expr | Expr "-" Expr | N' . $N, '1-2+3*4'], both([['1', '-', '2'], '+', ['3', '*', '4']]) ],
    [ ['@prec {
  left "+"
  right "^"
}
S = Expr
Expr = Expr "+" Expr | Expr "^" Expr | N' . $N, '1^2^3+4'], both([['1', '^', ['2', '^', '3']], '+', '4']) ],
    [ ['@prec {
  left "+"
  right "^"
}
S = Expr
Expr = Expr "^" Expr | Expr "+" Expr | N' . $N, '1+2^3^4'], both(['1', '+', ['2', '^', ['3', '^', '4']]]) ],
    [ ['@prec {
  right "="
  left "+"
}
S = Expr
Expr = Expr "+" Expr | Expr "=" Expr | N' . $N, '1=2=3+4'], both(['1', '=', ['2', '=', ['3', '+', '4']]]) ],
    // an unknown associativity keyword is a grammar error
    [ ['@prec {
  nope "+"
}
S = "a"', 'a'], both('GRAMMAR_ERROR') ],
    [ ['@bogus
S = "a"', 'a'], both('GRAMMAR_ERROR') ],
];
