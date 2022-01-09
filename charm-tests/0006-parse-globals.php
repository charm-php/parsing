<?php return [
    "Testing the \$state variable in snippets",
    function($input) {
        $state = [
            'counter' => 0,
        ];

        return Charm\cpeg_parse(<<<'GRAMMAR'
            DotCounter
            =   ^ Dots $                        <? return $_0; ?>

            Dots
            =   Dot+

            Dot
            =   "."                             <? return $state['counter']++; ?>

            GRAMMAR,

            $input,
            $state
        );
    },
    [ "...", [0,1,2] ],
];
