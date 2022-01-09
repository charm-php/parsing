<?php return [
    "Testing the `Kleene Cross` operator (+)",
    function($input) {
        return Charm\cpeg_parse(<<<'GRAMMAR'
            ArgList = (/\w+/ /\s*/)+
            GRAMMAR,
            $input,
        );
    },
    [ "193", [ ["193", ""] ] ],
    [ "1", [ ["1", ""] ] ],
    [ "", Charm\Parsing\Error\ParseError::class ],
    [ "1 234", [ ["1", " "], ["234", ""] ] ],
];
