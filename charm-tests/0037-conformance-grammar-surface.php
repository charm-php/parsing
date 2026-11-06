<?php
/**
 * Conformance (see 0030): grammar-text surface - comments, assignment operators, rule
 * descriptions, group brackets, templates, duplicate/unknown rules, unicode input.
 */
require_once __DIR__ . '/support/conformance.php';

$E = 'PARSE_ERROR';
$G = 'GRAMMAR_ERROR';
return [
    "Conformance: grammar text surface, templates, unicode input",
    'conformance_case',

    // comments
    [ ['S = "a" # trailing
', 'a'], both('a') ],
    [ ['# leading
S = "a"', 'a'], both('a') ],
    [ ['S = "a" // trailing
', 'a'], both('a') ],
    [ ['S = /* inline */ "a"', 'a'], both('a') ],
    [ ['S = "a" /* multi
line */ "b"', 'ab'], both(['a', 'b']) ],
    [ ['S = "#" "//"', '#//'], both(['#', '//']) ],
    [ ['S = /#+/', '##'], both('##') ],

    // assignment operators and terminators
    [ ['S ::= "a"', 'a'], both('a') ],
    [ ['S := "a"', 'a'], both('a') ],
    [ ['S -> "a"', 'a'], both('a') ],
    [ ['S = "a" ;', 'a'], both('a') ],
    [ ['S = A ; A = "a" ;', 'a'], both('a') ],
    [ ['S = A
A = "a"', 'a'], both('a') ],
    [ ['S = A
A ::= "a" | "b"', 'b'], both('b') ],

    // rule descriptions do not change what is matched
    [ ['S "the thing" = "a"', 'a'], both('a') ],
    [ ['S "the thing" = "a"', 'b'], both($E) ],

    // groups: ( ) < > { }
    [ ['S = ("a" "b")+', 'abab'], both([['a', 'b'], ['a', 'b']]) ],
    [ ['S = <"a" "b">+', 'abab'], both([['a', 'b'], ['a', 'b']]) ],
    [ ['S = {"a" "b"}+', 'abab'], both([['a', 'b'], ['a', 'b']]) ],
    [ ['S = [ab]+', 'abba'], both(['a', 'b', 'b', 'a']) ],

    // grammar errors
    [ ['Dup = "a"
Dup = "b"', 'a'], both($G) ],
    [ ['S = Missing', 'a'], both($G) ],
    [ ['S = ("a"', 'a'], both($G) ],
    [ ['S = ', 'a'], both($G) ],
    [ ['S = /(/', 'a'], both('GRAMMAR_ERROR') ],

    // templates (expanded by cpeg_parse; the helper expands them for the compiled path too)
    [ ['Paren<T> = "(" T ")"
S = Paren<N>
N = /[0-9]+/', '(12)'], both(['(', '12', ')']) ],
    [ ['List<T,S> = T (S T)*
S = List<N, ",">
N = /[0-9]+/', '1,2,3'], both(['1', [[',', '2'], [',', '3']]]) ],
    [ ['Paren<T> = "(" T ")"
S = Paren<Paren<N>>
N = /[0-9]+/', '((1))'], both(['(', ['(', '1', ')'], ')']) ],
    [ ['Paren<T> = "(" T ")"
S = Paren<N>
N = /[0-9]+/', '(x)'], both($E) ],
    [ ['Paren<T> = "(" T ")"
S = Paren<N, N>
N = /[0-9]+/', '(1)'], both("RuntimeException: Template 'Paren' expects 1 arguments, got 2") ],

    // unicode input
    [ ['S = /\w+/ " " /\w+/', 'blåbær grøt'], both(['blåbær', ' ', 'grøt']) ],
    [ ['S = Ch+
Ch = /./', 'å日🙂'], both(['å', '日', '🙂']) ],
    [ ['S = "🙂" "日"', '🙂日'], both(['🙂', '日']) ],
    [ ['S = [æøå]+', 'åøæ'], both(['å', 'ø', 'æ']) ],
    [ ['S = [^æøå]', 'å'], both($E) ],
    [ ['S = (!"日" /./)* "日"', 'åø日'], both([['å', 'ø'], '日']) ],
    [ ['S = /\X/ /\X/', "e\u{0301}x"], both(["e\u{0301}", 'x']) ],
    [ ['S = "日本語" $', '日本語'], both('日本語') ],
    [ ['S = "日本語" $', '日本'], both($E) ],
    [ ['S = Line ("\n" Line)* $
Line = /[^\n]*/', "å\nø\n日"], both(['å', [["\n", 'ø'], ["\n", '日']]]) ],
];
