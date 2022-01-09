<?php return [
    "Testing the `Kleene Star` operator (*) in nested clauses",
    function($input) {
        return Charm\cpeg_parse(<<<'GRAMMAR'
            String = [123456789] [0123456789]*
            GRAMMAR,
            $input,
        );
    },
    [ "193", [ "1", ["9", "3"] ] ],
    [ "1", [ "1", [] ] ],
    [ "", Charm\Parsing\Error\ParseError::class ],
    [ "01234", Charm\Parsing\Error\ParseError::class ],
];
