<?php
/**
 * Turns docs/tutorial-sql.md into test rows (see 0050), so the tutorial cannot rot.
 *
 * Fenced blocks of the tutorial are the spec:
 *   ```cpeg     rules to add or replace; a rule is replaced in place by name, a new one is appended,
 *               and a rule whose chunk starts with a `# entry` comment line becomes the first rule.
 *               An `@prec { }` header replaces the grammar's header.
 *   ```sql      an input, immediately followed by
 *   ```json     the AST both engines must produce for it with the grammar as it stands at that point, or
 *   ```error    the compiled parser's exact failure message (the interpreter must fail at the same line:column).
 *   ```php      a program, run from the repository root, immediately followed by
 *   ```output   its exact output.
 * A php block followed by anything else is shown, not run. Everything else (```bash, ```text, ...) is prose.
 *
 * @return array{rows: list<array>, grammar: string}
 */
function tutorial_rows(string $markdownFile): array {
    preg_match_all('/^```([^\n]*)\n(.*?)^```[ \t]*$/ms', file_get_contents($markdownFile), $m, PREG_SET_ORDER);
    $header = '';
    $rules = []; // name => chunk text; PHP arrays keep insertion order
    $rows = [];
    $pending = null; // [kind, payload]
    $grammar = function () use (&$header, &$rules) {
        return ($header === '' ? '' : $header . "\n\n") . implode("\n\n", $rules) . "\n";
    };
    foreach ($m as [, $lang, $body]) {
        $lang = trim($lang);
        $body = rtrim($body, "\n");
        if ($pending !== null) {
            [$kind, $payload, $g] = $pending;
            $pending = null;
            if ($kind === 'sql' && $lang === 'json') {
                $rows[] = [['parse', $g, $payload], tutorial_expect(json_decode($body, true, 512, JSON_THROW_ON_ERROR))];
                continue;
            }
            if ($kind === 'sql' && $lang === 'error') {
                $rows[] = [['error', $g, $payload], [$body, true]];
                continue;
            }
            if ($kind === 'php' && $lang === 'output') {
                $rows[] = [['php', '', $payload], $body];
                continue;
            }
            if ($kind === 'sql') {
                throw new \LogicException("tutorial: an sql block must be followed by a json or error block, found '$lang'");
            }
            // a php block without an output block is shown, not run, and this block is read normally
        }
        if ($lang === 'cpeg') {
            if (preg_match('/^@prec\s*\{.*?\}/ms', $body, $h)) {
                $header = $h[0];
                $body = ltrim(substr($body, strlen($h[0])));
            }
            // split into rule chunks: a rule starts at column 0 with `Name [description] =`
            $chunks = preg_split('/^(?=(?:# entry\n)?[A-Za-z_]\w*(?:[ \t]+"[^"]*")?[ \t]*(?:=|::=|:=|->)(?!>))(?<!# entry\n)/m', $body, -1, PREG_SPLIT_NO_EMPTY);
            foreach ($chunks as $chunk) {
                $chunk = trim($chunk);
                $entry = false;
                if (preg_match('/^# entry\n/', $chunk)) {
                    $entry = true;
                    $chunk = substr($chunk, 8);
                }
                if (!preg_match('/^([A-Za-z_]\w*)/', $chunk, $n)) {
                    continue;
                }
                $rules[$n[1]] = $chunk;
                if ($entry) {
                    $rules = [$n[1] => $chunk] + $rules;
                }
            }
        } elseif ($lang === 'sql') {
            $pending = ['sql', $body, $grammar()];
        } elseif ($lang === 'php') {
            $pending = ['php', $body, ''];
        }
    }
    $final = $grammar();
    $rows[] = [['grammar', $final, ''], true];
    $rows[] = [['standalone', $final, 'SELECT a FROM t WHERE x = ? AND y IN (?, ?)'], true];
    return ['rows' => $rows, 'grammar' => $final];
}

/** The expected [interpreted, compiled] pair for a JSON expectation. */
function tutorial_expect(mixed $v): array {
    return [$v, $v];
}

/** Test function of 0050: ['parse'|'error'|'php'|'grammar'|'standalone', grammar, input]. */
function tutorial_case(array $args): mixed {
    require_once __DIR__ . '/conformance.php';
    [$kind, $grammar, $input] = $args;
    if ($kind === 'parse') {
        // the tutorial's grammar numbers `?` parameters through $state, so it is always run with this state
        return conformance_run([$grammar, $input], false, ['params' => 0]);
    }
    if ($kind === 'error') {
        [$i, $c] = conformance_run([$grammar, $input], true, ['params' => 0]);
        $class = 'TutorialErr_' . substr(md5($grammar), 0, 8);
        if (!class_exists($class)) {
            $g = (new \Charm\Parsing\GrammarParser())->parse($grammar, 'sql');
            eval('?>' . (new \Charm\Parsing\Compiler\GrammarCompiler())->compile($g, $class));
        }
        try {
            (new $class())->parse($input, ['params' => 0]);
            $message = 'no error';
        } catch (\RuntimeException $e) {
            $message = $e->getMessage();
        }
        return [$message, $i === $c && str_starts_with((string) $i, 'ERROR@')];
    }
    if ($kind === 'grammar') {
        // example-grammars/sql.cpeg has exactly the rules and header the tutorial ends with, in any order
        $rules = function (string $text): array {
            $text = preg_replace('/^[ \t]*#.*$/m', '', $text);
            preg_match('/^@prec\s*\{.*?\}/ms', $text, $h);
            $body = substr($text, strpos($text, $h[0]) + strlen($h[0]));
            $map = ['@prec' => preg_replace('/\s+/', ' ', $h[0])];
            foreach (preg_split('/^(?=[A-Za-z_]\w*(?:[ \t]+"[^"]*")?[ \t]*(?:=|::=|:=|->)(?!>))/m', $body, -1, PREG_SPLIT_NO_EMPTY) as $chunk) {
                if (preg_match('/^(\w+)/', trim($chunk), $n)) {
                    $map[$n[1]] = preg_replace('/\s+/', ' ', trim($chunk));
                }
            }
            ksort($map);
            return $map;
        };
        return $rules($grammar) === $rules(file_get_contents(dirname(__DIR__, 2) . '/example-grammars/sql.cpeg'));
    }
    if ($kind === 'standalone') {
        // the compiled class loads and runs with nothing else loaded (no autoloader, no library)
        $file = tempnam(sys_get_temp_dir(), 'sqlparser') . '.php';
        file_put_contents($file, (new \Charm\Parsing\Compiler\GrammarCompiler())->compile(
            (new \Charm\Parsing\GrammarParser())->parse($grammar, 'sql'), 'StandaloneSqlParser', ['namespace' => 'App\\Sql']
        ));
        $script = 'require ' . var_export($file, true) . '; echo json_encode((new App\\Sql\\StandaloneSqlParser())->parse(' . var_export($input, true) . ", ['params' => 0]));";
        $out = shell_exec('php -n -r ' . escapeshellarg($script) . ' 2>&1');
        unlink($file);
        return $out === json_encode(\Charm\cpeg_parse($grammar, $input, ['params' => 0]));
    }
    chdir(dirname(__DIR__, 2));
    ob_start();
    try {
        (function () use ($input) { eval($input); })();
    } finally {
        $out = ob_get_clean();
    }
    return rtrim($out, "\n");
}
