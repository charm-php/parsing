<?php return [
    "Testing lookahead predicates (& and !)",
    function($args) {
        [$input, $type] = $args;
        $grammars = [
            // NOT predicate - don't match "stop"
            'not-stop' => 'Word = !"stop" /[a-z]+/',
            // AND predicate - must start with "hello"
            'and-hello' => 'Word = &"hello" /[a-z]+/',
            // AND predicate with regex
            'and-letter' => 'Test = &/[a-z]/ /[a-z]+/',
            // NOT with end-of-input
            'not-eof' => 'Chars = (!$ /[a-z]/)+',
            // NOT with keywords
            'not-keyword' => 'Ident = !("if" | "while" | "for") /[a-z]+/',
        ];
        return Charm\cpeg_parse($grammars[$type], $input);
    },

    // NOT predicate (!) - negative lookahead
    [ ["hello", "not-stop"], ["hello"] ],
    [ ["world", "not-stop"], ["world"] ],
    [ ["stop", "not-stop"], Charm\Parsing\Error\ParseError::class ],

    // AND predicate (&) - positive lookahead
    [ ["hello", "and-hello"], ["hello"] ],
    [ ["world", "and-hello"], Charm\Parsing\Error\ParseError::class ],

    // Predicate doesn't consume input
    [ ["hello", "and-letter"], ["hello"] ],
    [ ["123", "and-letter"], Charm\Parsing\Error\ParseError::class ],

    // NOT predicate with end-of-input check
    [ ["abc", "not-eof"], [["a"], ["b"], ["c"]] ],

    // Combining NOT with keywords
    [ ["myvar", "not-keyword"], ["myvar"] ],
    [ ["if", "not-keyword"], Charm\Parsing\Error\ParseError::class ],
    [ ["while", "not-keyword"], Charm\Parsing\Error\ParseError::class ],
];
