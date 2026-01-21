<?php return [
    "Testing snippet error handling",
    function($args) {
        [$input, $type] = $args;
        $grammars = [
            'undefined' => 'Num = /[0-9]+/ <? return $undefined; ?>',
            'working' => 'Num = /[0-9]+/ <? return intval($_0); ?>',
            'div-zero' => 'Num = /[0-9]+/ <? return 1 / intval($_0); ?>',
            'return-false' => 'Test = /[a-z]+/ <? return false; ?>',
            'return-true' => 'Test = /[a-z]+/ <? return true; ?>',
            'named-capture' => 'Test = name:/[a-z]+/ <? return strtoupper($name); ?>',
            'positional' => 'Expr = /[0-9]+/ "+" /[0-9]+/ <? return intval($_0) + intval($_2); ?>',
        ];
        return Charm\cpeg_parse($grammars[$type], $input);
    },

    // Undefined variable in snippet
    [ ["42", "undefined"], Charm\Parsing\Error\SnippetError::class ],

    // Working snippet for comparison
    [ ["42", "working"], 42 ],

    // Division by zero
    [ ["0", "div-zero"], Charm\Parsing\Error\SnippetError::class ],

    // Snippet returning false now returns the boolean value false (not a parse failure)
    [ ["hello", "return-false"], false ],

    // Snippet returning true now returns the boolean value true (not skipped as "empty success")
    [ ["hello", "return-true"], true ],

    // Snippet can access named captures
    [ ["hello", "named-capture"], "HELLO" ],

    // Snippet can access positional captures
    [ ["3+4", "positional"], 7 ],
];
