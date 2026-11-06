<?php
/**
 * Reading a variable that does not exist in a snippet (`$_5`, a typo, a named capture's `$_0`) is a
 * `SnippetError` naming the variable in both engines; compiled parsers used to read null. Rows give the
 * error class; if the two engines' messages differ the raw messages are returned, so the row fails.
 */
use Charm\Parsing\Error\SnippetError;

require_once __DIR__ . '/support/conformance.php';

return [
    "Undefined snippet variables raise SnippetError in both engines",
    function (array $args) {
        $r = conformance_run($args);
        if ($r[0] !== $r[1]) {
            return $r;
        }
        return array_map(fn($v) => is_string($v) && str_starts_with($v, SnippetError::class . ': ') ? SnippetError::class : $v, $r);
    },

    [ ['S = x:"a" y:"b" <? return $_0; ?>', 'ab'], both(SnippetError::class) ],
    [ ['S = "a" <? return $_1; ?>', 'a'], both(SnippetError::class) ],
    [ ['S = "a" <? return $typo; ?>', 'a'], both(SnippetError::class) ],
    [ ['S = "a" <? return [$_0, $_1 ?? 0]; ?>', 'a'], both(['a', 0]) ],
    [ ['S = "a" <? return isset($_1) ? 1 : 2; ?>', 'a'], both(2) ],
    [ ['S = "a" <? $_1 = 5; return $_1; ?>', 'a'], both(5) ],
    [ ['S = "a" <? $n = 5; return $n . $_0; ?>', 'a'], both('5a') ],
    [ ['S = "a" <? if (false) { return $_9; } return 1; ?>', 'a'], both(1) ],
    [ ['S = "x" ...A+ "y" <? return $_5; ?>
A = "a"', 'xaay'], both(SnippetError::class) ],
];
