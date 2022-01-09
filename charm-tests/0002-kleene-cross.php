<?php return [
    "Testing the `Kleene Cross` operator (+)",
    function($input) {
        return Charm\cpeg_parse(<<<'GRAMMAR'
            String = [0123456789]+
            GRAMMAR,
            $input,
        );
    },
    [ "193", [ "1", "9", "3" ] ],
    [ "1", [ "1" ] ],
    [ "", Charm\Parsing\Error\ParseError::class ],
    [ "1 234", [ "1" ] ],
];
