<?php
/**
 * Compiled Parser Stub
 *
 * This file demonstrates what a compiled parser class should look like.
 * The grammar compiler (to be implemented) would generate code like this.
 *
 * Key properties:
 * - No dependency on Charm\Parsing library at runtime
 * - Self-contained: all parsing logic is baked into PHP code
 * - Can be opcache preloaded for instant startup
 * - Each grammar produces a standalone class file
 */

/**
 * Example: What a compiled simple expression parser would look like.
 *
 * Grammar:
 *   Expr = Expr "*" Expr | Expr "/" Expr | Expr "+" Expr | Expr "-" Expr | Number
 *   Number = /[0-9]+/
 */
class CompiledExpressionParser
{
    private string $source;
    private int $offset;
    private int $length;

    public function parse(string $source): mixed
    {
        $this->source = $source;
        $this->offset = 0;
        $this->length = strlen($source);

        $result = $this->parseExpr(0);

        if ($this->offset !== $this->length) {
            throw new \RuntimeException("Unexpected input at offset {$this->offset}");
        }

        return $result;
    }

    /**
     * Pratt parser for left-recursive expression rule.
     * Precedence levels: * / = 2, + - = 1
     */
    private function parseExpr(int $minPrec): mixed
    {
        // Parse the left-hand side (a number or parenthesized expression)
        $left = $this->parsePrimary();

        // While there are operators with sufficient precedence, parse them
        while (true) {
            if ($this->offset >= $this->length) {
                break;
            }

            $op = $this->source[$this->offset];
            $prec = match($op) {
                '*', '/' => 2,
                '+', '-' => 1,
                default => 0,
            };

            if ($prec <= $minPrec) {
                break;
            }

            $this->offset++; // consume operator

            // Parse right-hand side with higher precedence (left-associative)
            $right = $this->parseExpr($prec);

            // Apply operator
            $left = match($op) {
                '*' => $left * $right,
                '/' => $left / $right,
                '+' => $left + $right,
                '-' => $left - $right,
            };
        }

        return $left;
    }

    private function parsePrimary(): mixed
    {
        // Match /[0-9]+/
        $start = $this->offset;
        while ($this->offset < $this->length && ctype_digit($this->source[$this->offset])) {
            $this->offset++;
        }

        if ($this->offset === $start) {
            throw new \RuntimeException("Expected number at offset {$this->offset}");
        }

        return (int) substr($this->source, $start, $this->offset - $start);
    }
}

// Benchmark the stub if run directly
if (basename(__FILE__) === basename($_SERVER['PHP_SELF'] ?? '')) {
    echo "Compiled Parser Stub Benchmark\n";
    echo str_repeat("=", 70)."\n\n";

    $parser = new CompiledExpressionParser();

    $tests = [
        ['1+2', 3],
        ['1+2*3', 7],
        ['10-3-2', 5],
        ['1*2+3*4', 14],
        ['1+2+3+4+5', 15],
    ];

    // Verify correctness
    echo "Correctness check:\n";
    foreach ($tests as [$expr, $expected]) {
        $result = $parser->parse($expr);
        $status = $result === $expected ? '✓' : '✗';
        echo "  $status $expr = $result (expected $expected)\n";
    }
    echo "\n";

    // Benchmark
    echo "Performance:\n";
    $iterations = 50000;

    foreach ($tests as [$expr, $expected]) {
        $start = hrtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            $parser->parse($expr);
        }
        $elapsed = (hrtime(true) - $start) / 1e9;
        $msPerParse = ($elapsed / $iterations) * 1000;
        $perSec = $iterations / $elapsed;

        printf("  %-20s %.4f ms/parse  %d/sec\n", $expr, $msPerParse, (int)$perSec);
    }

    echo "\nThis stub shows the target performance for compiled parsers.\n";
    echo "The grammar compiler should generate code at least this fast.\n";
}
