<?php
namespace Charm\Parsing\Compiler\Portable;

/**
 * Parser for "portable snippets": the small, declarative subset of PHP snippets that the C, JavaScript and
 * Python targets can run. A portable snippet is a single `return` of one expression:
 *
 *   expr    = value | `[` element, ... `]` | `$label ?? ` default
 *   element = expr | `'key' =>` expr          (string keys only; an element without a key gets the next integer key)
 *   value   = 'string' | "string" | 123 | -123 | true | false | null
 *           | $label          a labelled value of the same sequence
 *           | $_offset        byte offset where the enclosing rule started
 *           | $_line, $_column  1-based line and column (in bytes) of $_offset
 *   default = a value (not a variable) or `[]`
 *
 * Anything else is rejected: the PHP engines still run it, the portable targets cannot.
 *
 * The result is an expression tree of arrays: ['null'], ['bool', bool], ['int', int], ['str', string],
 * ['label', name], ['offset'], ['line'], ['column'], ['list', [[?key, expr], ...]], ['coalesce', labelExpr, default].
 */
final class SnippetSubset
{
    private array $tokens = [];
    private int $i = 0;

    /**
     * @param list<string> $labels the labels of the sequence the snippet ends
     * @throws UnsupportedGrammar (without the rule name: the caller adds it)
     */
    public static function parse(string $code, array $labels): array
    {
        $p = new self();
        return $p->run($code, $labels);
    }

    private function run(string $code, array $labels): array
    {
        foreach (token_get_all('<?php ' . $code) as $t) {
            if (is_array($t) && in_array($t[0], [T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $this->tokens[] = $t;
        }
        if (!$this->isTok(T_RETURN)) {
            $this->fail('only `return <expression>;` is portable');
        }
        $this->i++;
        $e = $this->expr($labels);
        if ($this->isChar(';')) {
            $this->i++;
        }
        if ($this->i < count($this->tokens)) {
            $this->fail('unexpected ' . $this->describe());
        }
        return $e;
    }

    private function fail(string $why): never
    {
        throw new UnsupportedGrammar($why);
    }

    private function describe(): string
    {
        $t = $this->tokens[$this->i] ?? null;
        return $t === null ? 'end of the snippet' : '`' . (is_array($t) ? $t[1] : $t) . '`';
    }

    private function isTok(int $id): bool
    {
        $t = $this->tokens[$this->i] ?? null;
        return is_array($t) && $t[0] === $id;
    }

    private function isChar(string $c): bool
    {
        return ($this->tokens[$this->i] ?? null) === $c;
    }

    private function expr(array $labels, bool $allowVariable = true): array
    {
        $t = $this->tokens[$this->i] ?? null;
        if ($t === null) {
            $this->fail('the expression ends too soon');
        }
        if ($t === '[') {
            return $this->arrayLiteral($labels);
        }
        if ($t === '-') {
            $this->i++;
            if (!$this->isTok(T_LNUMBER)) {
                $this->fail('a `-` must be followed by an integer');
            }
            $v = -$this->intValue();
            return ['int', $v];
        }
        if (is_array($t)) {
            switch ($t[0]) {
                case T_CONSTANT_ENCAPSED_STRING:
                    $this->i++;
                    return ['str', $this->stringValue($t[1])];
                case T_LNUMBER:
                    return ['int', $this->intValue()];
                case T_STRING:
                    $w = strtolower($t[1]);
                    if (in_array($w, ['true', 'false', 'null'], true)) {
                        $this->i++;
                        return $w === 'null' ? ['null'] : ['bool', $w === 'true'];
                    }
                    break;
                case T_VARIABLE:
                    if (!$allowVariable) {
                        $this->fail('a default value cannot be a variable');
                    }
                    return $this->variable($labels);
            }
        }
        $this->fail('unsupported ' . $this->describe());
    }

    private function variable(array $labels): array
    {
        $name = substr($this->tokens[$this->i++][1], 1);
        $v = match ($name) {
            '_offset' => ['offset'], '_line' => ['line'], '_column' => ['column'],
            default => null,
        };
        if ($v === null) {
            if (preg_match('/^_\d+$/', $name) || in_array($name, ['context', 'state', 'HIT', 'MISS'], true)) {
                $this->fail("`\$$name` is not portable; use a label (`label:Element`) and `\$label`");
            }
            $v = ['label', $name];
            if ($this->isTok(T_COALESCE)) {
                $this->i++;
                return ['coalesce', $v, $this->expr($labels, false)];
            }
            if (!in_array($name, $labels, true)) {
                $this->fail("`\$$name` is not a label of the sequence the snippet ends (labels: " . ($labels ? implode(', ', $labels) : 'none') . ')');
            }
        }
        return $v;
    }

    private function arrayLiteral(array $labels): array
    {
        $this->i++; // [
        $items = [];
        $keys = [];
        while (!$this->isChar(']')) {
            $key = null;
            $t = $this->tokens[$this->i] ?? null;
            $next = $this->tokens[$this->i + 1] ?? null;
            if (is_array($t) && $t[0] === T_CONSTANT_ENCAPSED_STRING && is_array($next) && $next[0] === T_DOUBLE_ARROW) {
                $key = $this->stringValue($t[1]);
                if (isset($keys[$key])) {
                    $this->fail("the key '$key' appears twice");
                }
                $keys[$key] = true;
                $this->i += 2;
            }
            $items[] = [$key, $this->expr($labels)];
            if ($this->isChar(',')) {
                $this->i++;
            } elseif (!$this->isChar(']')) {
                $this->fail('expected `,` or `]` but found ' . $this->describe());
            }
        }
        $this->i++; // ]
        return ['list', $items];
    }

    private function intValue(): int
    {
        $text = $this->tokens[$this->i++][1];
        if (!preg_match('/^(0|[1-9][0-9]*)$/', $text) || (int) $text > 2147483647) {
            $this->fail("the integer $text is not portable (decimal, at most 2147483647)");
        }
        return (int) $text;
    }

    private function stringValue(string $literal): string
    {
        $q = $literal[0];
        $body = substr($literal, 1, -1);
        if ($q === "'") {
            return strtr($body, ['\\\\' => '\\', "\\'" => "'"]);
        }
        $out = '';
        for ($i = 0, $n = strlen($body); $i < $n; $i++) {
            $c = $body[$i];
            if ($c !== '\\') {
                $out .= $c;
                continue;
            }
            $d = $body[++$i] ?? '';
            $out .= match ($d) {
                'n' => "\n", 'r' => "\r", 't' => "\t", '\\' => '\\', '"' => '"', '$' => '$',
                default => $this->fail("the escape \\$d in a double-quoted string is not portable; use a single-quoted string"),
            };
        }
        return $out;
    }
}
