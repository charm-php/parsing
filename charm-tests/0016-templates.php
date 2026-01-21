<?php
return [
    "Testing grammar templates/generics (integrated with cpeg_parse)",
    function($args) {
        [$grammar, $input] = $args;
        // Templates are automatically expanded by cpeg_parse
        return Charm\cpeg_parse($grammar, $input);
    },

    // Basic template with one parameter
    [ ['Paren<T> = "(" T ")"
        Expr = Paren<Number>
        Number = /[0-9]+/', '(42)'], ['(', '42', ')'] ],

    // Template with two parameters
    [ ['List<T, Sep> = T (Sep T)*
        Nums = List<Number, ",">
        Number = /[0-9]+/', '1,2,3'], ['1', [[',', '2'], [',', '3']]] ],

    // Multiple template instantiations (Expr must be first for entry point)
    [ ['Wrap<L, R, T> = L T R
        Expr = Paren | Bracket
        Paren = Wrap<"(", ")", Number>
        Bracket = Wrap<"[", "]", Number>
        Number = /[0-9]+/', '(5)'], ['(', '5', ')'] ],

    [ ['Wrap<L, R, T> = L T R
        Expr = Paren | Bracket
        Paren = Wrap<"(", ")", Number>
        Bracket = Wrap<"[", "]", Number>
        Number = /[0-9]+/', '[5]'], ['[', '5', ']'] ],

    // Nested template instantiation
    [ ['Paren<T> = "(" T ")"
        List<T, Sep> = T (Sep T)*
        Expr = Paren<List<Number, ",">>
        Number = /[0-9]+/', '(1,2)'], ['(', ['1', [[',', '2']]], ')'] ],

    // Template with string literal argument containing special chars
    [ ['Between<L, R, T> = L T R
        Arrow = Between<"<", ">", Number>
        Number = /[0-9]+/', '<42>'], ['<', '42', '>'] ],

    // Reusing same template multiple times
    [ ['Opt<T> = T | \\nothing
        Expr = "a" Opt<"b"> Opt<"c"> "d"', 'abd'], ['a', 'b', 'd'] ],

    [ ['Opt<T> = T | \\nothing
        Expr = "a" Opt<"b"> Opt<"c"> "d"', 'ad'], ['a', 'd'] ],

    // Template used in choice
    [ ['Paren<T> = "(" T ")"
        Bracket<T> = "[" T "]"
        Expr = Paren<Number> | Bracket<Number>
        Number = /[0-9]+/', '[7]'], ['[', '7', ']'] ],
];
