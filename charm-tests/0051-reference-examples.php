<?php
/**
 * REFERENCE.md and the chapters under docs/ state what each operator, pragma and snippet variable yields; these are
 * the examples, run through both engines (see 0030). Errors are compared as 'ERROR@line:column' (1-based).
 */
require_once __DIR__ . '/support/conformance.php';

/** The expected value, written as JSON. */
function ref_json(string $json) { return json_decode($json, true); }

return [
    "Reference examples: the values REFERENCE.md documents",
    'conformance_position',

    // readme-calc
    [ ["Calc = _ expr:Expr \$  <? return \$expr; ?>\n\nExpr = Expr \"*\" _ Expr   <? return \$_0 * \$_2; ?>\n     | Expr \"+\" _ Expr   <? return \$_0 + \$_2; ?>\n     | \"(\" _ Expr \")\" _  <? return \$_1; ?>\n     | Number\n\nNumber = /[0-9]+/ _      <? return (int) \$_0; ?>\n\n_ = /\\s*/ %silent", '2 + 3 * (4 + 1)'], both(ref_json('17')) ],
    // readme-calc-error
    [ ["Calc = _ expr:Expr \$  <? return \$expr; ?>\n\nExpr = Expr \"*\" _ Expr   <? return \$_0 * \$_2; ?>\n     | Expr \"+\" _ Expr   <? return \$_0 + \$_2; ?>\n     | \"(\" _ Expr \")\" _  <? return \$_1; ?>\n     | Number\n\nNumber = /[0-9]+/ _      <? return (int) \$_0; ?>\n\n_ = /\\s*/ %silent", '2 + * 3'], both('ERROR@1:5') ],
    // readme-tree
    [ ['Expr = Expr "+" Expr | /[0-9]+/', '1+2+3'], both(ref_json('[["1","+","2"],"+","3"]')) ],
    // text
    [ ['S = "ab"', 'ab'], both(ref_json('"ab"')) ],
    // text-single
    [ ['S = \'ab\'', 'ab'], both(ref_json('"ab"')) ],
    // text-esc
    [ ['S = "a\\"b\\n"', "a\"b\n"], both(ref_json('"a\\"b\\n"')) ],
    // regex
    [ ['S = /[a-z]+/', 'abc1'], both(ref_json('"abc"')) ],
    // regex-anchored
    [ ['S = "x" /[a-z]+/', 'x1abc'], both('ERROR@1:2') ],
    // class
    [ ['S = [a-c]+', 'abcd'], both(ref_json('["a","b","c"]')) ],
    // nocase
    [ ['S = /(?i:select)\\b/', 'SeLeCt x'], both(ref_json('"SeLeCt"')) ],
    // sof
    [ ['S = ^ "a"', 'a'], both(ref_json('"a"')) ],
    // eof
    [ ['S = "a" $', 'a'], both(ref_json('"a"')) ],
    // eof-fail
    [ ['S = "a" $', 'ab'], both('ERROR@1:2') ],
    // nothing
    [ ['S = "a" \\nothing "b"', 'ab'], both(ref_json('["a","b"]')) ],
    // epsilon
    [ ['S = "a" ε', 'a'], both(ref_json('"a"')) ],
    // ref
    [ ["S = Digit Digit\nDigit = /[0-9]/", '12'], both(ref_json('["1","2"]')) ],
    // seq
    [ ['S = "a" "b" "c"', 'abc'], both(ref_json('["a","b","c"]')) ],
    // choice
    [ ['S = "a" | "b"', 'b'], both(ref_json('"b"')) ],
    // choice-slash
    [ ['S = "a" / "b"', 'b'], both(ref_json('"b"')) ],
    // opt-hit
    [ ['S = "a"?', 'a'], both(ref_json('"a"')) ],
    // opt-miss
    [ ['S = "a"?', ''], both(ref_json('null')) ],
    // star
    [ ['S = "a"*', 'aaa'], both(ref_json('["a","a","a"]')) ],
    // star-empty
    [ ['S = "a"*', ''], both(ref_json('[]')) ],
    // plus
    [ ['S = "a"+', 'aaa'], both(ref_json('["a","a","a"]')) ],
    // plus-fail
    [ ['S = "a"+', ''], both('ERROR@1:1') ],
    // group
    [ ['S = ("a" | "b") "c"', 'bc'], both(ref_json('["b","c"]')) ],
    // group-angle
    [ ['S = <"a" | "b"> "c"', 'bc'], both(ref_json('["b","c"]')) ],
    // and
    [ ['S = &"a" "a"', 'a'], both(ref_json('"a"')) ],
    // not
    [ ['S = !"b" "a"', 'a'], both(ref_json('"a"')) ],
    // not-fail
    [ ['S = !"a" "a"', 'a'], both('ERROR@1:1') ],
    // named
    [ ['S = x:"a" y:"b"', 'ab'], both(ref_json('{"x":"a","y":"b"}')) ],
    // named-one
    [ ['S = x:"a"', 'a'], both(ref_json('"a"')) ],
    // named-mixed
    [ ['S = x:"a" "b" "c"', 'abc'], both(ref_json('{"x":"a","0":"b","1":"c"}')) ],
    // spread-seq
    [ ["S = ...A \"z\"\nA = \"a\" \"b\"", 'abz'], both(ref_json('["a","b","z"]')) ],
    // spread-rep
    [ ['S = ...("a" "b")* "z"', 'ababz'], both(ref_json('["a","b","a","b","z"]')) ],
    // spread-nospread
    [ ['S = ("a" "b")* "z"', 'ababz'], both(ref_json('[[["a","b"],["a","b"]],"z"]')) ],
    // spread-single
    [ ['S = ...("a" "b")*', 'abab'], both(ref_json('[["a","b"],["a","b"]]')) ],
    // spread-two-levels
    [ ["S = \"z\" ...A\nA = \"a\" (\"b\" \"c\")", 'zabc'], both(ref_json('["z","a","b","c"]')) ],
    // spread-list
    [ ["S = A ...(\",\" A)*\nA = /[a-z]/", 'a,b,c'], both(ref_json('["a",",","b",",","c"]')) ],
    // spread-opt-null
    [ ["S = ...A? \"z\"\nA = \"a\" \"b\"", 'z'], both(ref_json('["z"]')) ],
    // single-seq
    [ ['S = ("a")', 'a'], both(ref_json('"a"')) ],
    // one-value-seq
    [ ['S = !"b" "a"', 'a'], both(ref_json('"a"')) ],
    // silent-seq
    [ ["S = \"a\" _ %silent\n_ = /\\s*/ %silent", 'a  '], both(ref_json('"a"')) ],
    // silent-two
    [ ["S = \"a\" _ \"b\"\n_ = /\\s*/ %silent", 'a b'], both(ref_json('["a","b"]')) ],
    // hit-top
    [ ['S = ^ $', ''], both(ref_json('null')) ],
    // hit-pred
    [ ['S = &"a"', 'a'], both(ref_json('null')) ],
    // hit-group
    [ ['S = "x" (&"a") "a"', 'xa'], both(ref_json('["x","a"]')) ],
    // hit-rep
    [ ['S = (&"a")+', 'a'], both(ref_json('[]')) ],
    // valueless-rep
    [ ['S = ("a" | ε)*', 'aab'], both(ref_json('["a","a"]')) ],
    // choice-first
    [ ["S = A | \"x\"\nA = \"x\" | \\nothing", 'x'], both(ref_json('"x"')) ],
    // choice-empty
    [ ['S = "" | "a"', 'a'], both(ref_json('""')) ],
    // choice-empty-opt
    [ ['S = "a"? | "b"', 'b'], both(ref_json('null')) ],
    // rep-nothing
    [ ['S = ("a"*)*', 'aaa'], both(ref_json('[["a","a","a"]]')) ],
    // rep-nothing-q
    [ ['S = ("a"?)*', 'aaa'], both(ref_json('["a","a","a"]')) ],
    // rep-eps
    [ ['S = ε*', ''], both(ref_json('[]')) ],
    // rep-plus-first
    [ ['S = ε+', ''], both(ref_json('[]')) ],
    // rep-stop
    [ ['S = ("a" | "b")* "c"', 'abac'], both(ref_json('[["a","b","a"],"c"]')) ],
    // pred-noslot
    [ ['S = &"a" "a" !"b" /[a-z]/ <? return [$_0, $_1]; ?>', 'ac'], both(ref_json('["a","c"]')) ],
    // pred-quant
    [ ['S = &"a"* "a"', 'a'], both(ref_json('"a"')) ],
    // pred-stack
    [ ['S = !!"a" "a"', 'a'], both(ref_json('"a"')) ],
    // pred-nested
    [ ['S = &!"b" "a"', 'a'], both(ref_json('"a"')) ],
    // commit-basic
    [ ['S = "a" @ "b" | "a" "c"', 'ac'], both('ERROR@1:2') ],
    // commit-ok
    [ ['S = "a" @ "b" | "a" "c"', 'ab'], both(ref_json('["a","b"]')) ],
    // commit-forgotten
    [ ["S = A \"z\" | \"xy\" \"q\"\nA = \"x\" @ \"y\"", 'xyq'], both(ref_json('["xy","q"]')) ],
    // commit-first
    [ ['S = @ "a" | "b"', 'b'], both('ERROR@1:1') ],
    // commit-star
    [ ['S = ("a" @ "b")* "c"', 'abac'], both('ERROR@1:4') ],
    // commit-opt
    [ ['S = ("a" @ "b")? "a"', 'a'], both('ERROR@1:2') ],
    // commit-pred
    [ ['S = !("a" @ "b") "a"', 'a'], both(ref_json('"a"')) ],
    // snip-basic
    [ ['S = /[0-9]+/ <? return (int) $_0; ?>', '42'], both(ref_json('42')) ],
    // snip-named
    [ ['S = x:/[0-9]+/ "+" y:/[0-9]+/ <? return $x + $y; ?>', '1+2'], both(ref_json('3')) ],
    // snip-miss
    [ ['S = /[0-9]+/ <? return (int) $_0 > 9 ? $MISS : $_0; ?> | /[a-z]+/', '12'], both('ERROR@1:3') ],
    // snip-miss2
    [ ['S = /[0-9]+/ <? return (int) $_0 > 9 ? $MISS : $_0; ?> | /[0-9]+/', '12'], both(ref_json('"12"')) ],
    // snip-null
    [ ['S = "a" <? return null; ?>', 'a'], both(ref_json('null')) ],
    // snip-false
    [ ['S = "a" <? return false; ?>', 'a'], both(ref_json('false')) ],
    // snip-ignore
    [ ["S = \"x\" A \"y\"\nA = \"a\" <? return new \\Charm\\Parsing\\SequenceIgnore(); ?>", 'xay'], both(ref_json('["x","y"]')) ],
    // snip-resume
    [ ['S = "a" <? return new \\Charm\\Parsing\\SequenceResume(); ?>', 'a'], both(ref_json('["a"]')) ],
    // snip-state
    [ ["S = A A\nA = \"a\" <? \$n = \$state[\"n\"] ?? 0; \$state[\"n\"] = \$n + 1; return \$n; ?>", 'aa'], both(ref_json('[0,1]')) ],
    // snip-silent-slot
    [ ["S = \"a\" _ \"b\" <? return \$_1; ?>\n_ = /\\s*/ %silent", 'a b'], both(ref_json('"b"')) ],
    // prec-pos
    [ ['E = E "*" E | E "+" E | /[0-9]/', '1+2*3'], both(ref_json('["1","+",["2","*","3"]]')) ],
    // prec-pos2
    [ ['E = E "+" E | E "*" E | /[0-9]/', '1+2*3'], both(ref_json('[["1","+","2"],"*","3"]')) ],
    // assoc-left
    [ ['E = E "-" E | /[0-9]/', '1-2-3'], both(ref_json('[["1","-","2"],"-","3"]')) ],
    // assoc-right
    [ ['E = E "^" E %assoc=right | /[0-9]/', '1^2^3'], both(ref_json('["1","^",["2","^","3"]]')) ],
    // assoc-none
    [ ['E = E "<" E %assoc=none | /[0-9]/', '1<2<3'], both(ref_json('[["1","<","2"],"<","3"]')) ],
    // prec-num
    [ ['E = E "+" E %prec=2 | E "*" E %prec=1 | /[0-9]/', '1+2*3'], both(ref_json('[["1","+","2"],"*","3"]')) ],
    // prec-table
    [ ["@prec {\n  left \"+\" \"-\"\n  left \"*\"\n}\nE = E \"*\" E | E \"+\" E | /[0-9]/", '1+2*3'], both(ref_json('["1","+",["2","*","3"]]')) ],
    // prec-table-name
    [ ["@prec {\n  left PLUS\n  left TIMES\n}\nE = E /\\*/ E %prec=TIMES | E /\\+/ E %prec=PLUS | /[0-9]/", '1+2*3'], both(ref_json('["1","+",["2","*","3"]]')) ],
    // prec-table-right
    [ ["@prec {\n  right \"^\"\n}\nE = E \"^\" E | /[0-9]/", '1^2^3'], both(ref_json('["1","^",["2","^","3"]]')) ],
    // prec-assoc-override
    [ ["@prec {\n  left \"^\"\n}\nE = E \"^\" E %assoc=right | /[0-9]/", '1^2^3'], both(ref_json('["1","^",["2","^","3"]]')) ],
    // prec-prefix
    [ ['E = "-" E | E "+" E | /[0-9]/', '-1+2'], both(ref_json('[["-","1"],"+","2"]')) ],
    // prec-prefix2
    [ ['E = E "+" E | "-" E | /[0-9]/', '-1+2'], both(ref_json('["-",["1","+","2"]]')) ],
    // prec-bracket
    [ ['E = "(" E ")" | E "+" E | /[0-9]/', '(1+2)+3'], both(ref_json('[["(",["1","+","2"],")"],"+","3"]')) ],
    // prec-postfix
    [ ['E = E "!" | E "+" E | /[0-9]/', '1+2!'], both(ref_json('["1","+",["2","!"]]')) ],
    // template
    [ ["S = Paren<Num>\nParen<T> = \"(\" T \")\"\nNum = /[0-9]/", '(1)'], both(ref_json('["(","1",")"]')) ],
    // template-list
    [ ["S = List<Num, \",\">\nList<T, Sep> = T (Sep T)*\nNum = /[0-9]/", '1,2'], both(ref_json('["1",[[",","2"]]]')) ],
    // silent-named
    [ ["S = ws:_ \"a\"\n_ = /\\s*/ %silent", ' a'], both(ref_json('{"ws":" ","0":"a"}')) ],
    // err-furthest
    [ ['S = "ab" | "ac"', 'ax'], both('ERROR@1:1') ],
    // err-eof
    [ ['S = "a" "b"', 'a'], both('ERROR@1:2') ],
];
