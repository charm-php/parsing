<?php
/**
 * docs/tutorial.md: every ```php block followed by an ```output block is run from the repository root and must
 * print exactly that output; the ```cpeg block must equal example-grammars/calc.cpeg.
 */
$root = dirname(__DIR__);
preg_match_all('/^```([^\n]*)\n(.*?)^```[ \t]*$/ms', file_get_contents($root . '/docs/tutorial.md'), $m, PREG_SET_ORDER);

$rows = [];
foreach ($m as $i => [, $lang, $body]) {
    $lang = trim($lang);
    $next = $m[$i + 1] ?? null;
    if ($lang === 'php' && $next && trim($next[1]) === 'output') {
        $rows[] = [['php', $body], rtrim($next[2], "\n")];
    } elseif ($lang === 'cpeg') {
        $rows[] = [['grammar', $body], true];
    }
}

return array_merge(['Tutorial: docs/tutorial.md examples run and print what the page says', function (array $args) use ($root) {
    [$kind, $body] = $args;
    if ($kind === 'grammar') {
        return trim($body) === trim(file_get_contents($root . '/example-grammars/calc.cpeg'));
    }
    chdir($root);
    ob_start();
    try {
        (function () use ($body) { eval($body); })();
    } finally {
        $out = ob_get_clean();
    }
    return rtrim($out, "\n");
}], $rows);
