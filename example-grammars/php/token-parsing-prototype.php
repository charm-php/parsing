<?php
/**
 * Token-mode parsing prototype.
 *
 * Goal: Parse PHP expressions using token_get_all() output.
 * This validates the approach before integrating into Charm.
 */

/**
 * Wraps token_get_all() output for parsing.
 */
class TokenStream {
    private array $tokens;
    private int $position = 0;
    private int $length;

    public function __construct(array $tokens, bool $skipWhitespace = true) {
        if ($skipWhitespace) {
            $this->tokens = array_values(array_filter($tokens, function($tok) {
                return !is_array($tok) || $tok[0] !== T_WHITESPACE;
            }));
        } else {
            $this->tokens = $tokens;
        }
        $this->length = count($this->tokens);
    }

    /**
     * Get the current token.
     */
    public function current(): mixed {
        return $this->tokens[$this->position] ?? null;
    }

    /**
     * Peek at the token type/value.
     * For array tokens: returns token ID (int)
     * For string tokens: returns the string itself
     */
    public function peek(): int|string|null {
        $tok = $this->current();
        if ($tok === null) return null;
        return is_array($tok) ? $tok[0] : $tok;
    }

    /**
     * Get the token value (the actual string content).
     */
    public function value(): ?string {
        $tok = $this->current();
        if ($tok === null) return null;
        return is_array($tok) ? $tok[1] : $tok;
    }

    /**
     * Advance to next token.
     */
    public function advance(): void {
        $this->position++;
    }

    /**
     * Check if at end.
     */
    public function isEof(): bool {
        return $this->position >= $this->length;
    }

    /**
     * Get current position (for backtracking).
     */
    public function mark(): int {
        return $this->position;
    }

    /**
     * Restore position (backtrack).
     */
    public function restore(int $position): void {
        $this->position = $position;
    }

    /**
     * Match a token type or literal.
     * @param int|string $expected Token ID (T_LNUMBER) or literal string ("+")
     * @return string|null The token value if matched, null otherwise
     */
    public function match(int|string $expected): ?string {
        if ($this->peek() === $expected) {
            $value = $this->value();
            $this->advance();
            return $value;
        }
        return null;
    }
}

/**
 * Simple Pratt-style expression parser operating on tokens.
 */
class TokenExprParser {
    private TokenStream $tokens;

    // Binding powers (precedence)
    private const BP = [
        '=' => 10,   // assignment (right assoc)
        '+=' => 10, '-=' => 10, '*=' => 10, '/=' => 10,
        '?' => 20,   // ternary
        '||' => 30, T_BOOLEAN_OR => 30,
        '&&' => 40, T_BOOLEAN_AND => 40,
        '==' => 50, '!=' => 50, '===' => 50, '!==' => 50,
        '<' => 60, '>' => 60, '<=' => 60, '>=' => 60,
        '+' => 70, '-' => 70, '.' => 70,
        '*' => 80, '/' => 80, '%' => 80,
        T_POW => 90, '**' => 90,  // power (right assoc)
    ];

    public function parse(string $code): mixed {
        $rawTokens = token_get_all($code);
        $this->tokens = new TokenStream($rawTokens);

        // Skip opening tag
        if ($this->tokens->peek() === T_OPEN_TAG ||
            $this->tokens->peek() === T_OPEN_TAG_WITH_ECHO) {
            $this->tokens->advance();
        }

        $result = $this->expression(0);

        return $result;
    }

    private function expression(int $minBp): mixed {
        // Parse prefix (nud)
        $left = $this->prefix();

        // Parse infix (led)
        while (!$this->tokens->isEof()) {
            $op = $this->tokens->peek();

            // Check for semicolon or closing tag (end of expression)
            if ($op === ';' || $op === T_CLOSE_TAG || $op === ')' || $op === ',' || $op === ':') {
                break;
            }

            $bp = self::BP[$op] ?? 0;
            if ($bp <= $minBp) {
                break;
            }

            $this->tokens->advance();

            // Handle ternary
            if ($op === '?') {
                $then = $this->expression(0);
                if ($this->tokens->match(':') === null) {
                    throw new Exception("Expected ':' in ternary");
                }
                $else = $this->expression($bp - 1);
                $left = ['?:', $left, $then, $else];
                continue;
            }

            // Right associative operators
            $rightAssoc = in_array($op, ['=', '+=', '-=', '*=', '/=', '**', T_POW], true);
            $right = $this->expression($rightAssoc ? $bp - 1 : $bp);

            $opStr = is_int($op) ? token_name($op) : $op;
            $left = [$opStr, $left, $right];
        }

        return $left;
    }

    private function prefix(): mixed {
        $tok = $this->tokens->peek();

        // Numbers
        if ($tok === T_LNUMBER) {
            $val = $this->tokens->value();
            $this->tokens->advance();
            return ['int', (int)$val];
        }

        if ($tok === T_DNUMBER) {
            $val = $this->tokens->value();
            $this->tokens->advance();
            return ['float', (float)$val];
        }

        // Variables
        if ($tok === T_VARIABLE) {
            $val = $this->tokens->value();
            $this->tokens->advance();
            return ['var', $val];
        }

        // Strings
        if ($tok === T_CONSTANT_ENCAPSED_STRING) {
            $val = $this->tokens->value();
            $this->tokens->advance();
            // Remove quotes and unescape
            $inner = substr($val, 1, -1);
            return ['string', $inner];
        }

        // Parentheses
        if ($tok === '(') {
            $this->tokens->advance();
            $expr = $this->expression(0);
            if ($this->tokens->match(')') === null) {
                throw new Exception("Expected ')'");
            }
            return $expr;
        }

        // Unary minus
        if ($tok === '-') {
            $this->tokens->advance();
            $operand = $this->prefix();
            return ['neg', $operand];
        }

        // Unary plus (no-op)
        if ($tok === '+') {
            $this->tokens->advance();
            return $this->prefix();
        }

        // Boolean not
        if ($tok === '!') {
            $this->tokens->advance();
            $operand = $this->prefix();
            return ['not', $operand];
        }

        // true, false, null
        if ($tok === T_STRING) {
            $val = strtolower($this->tokens->value());
            $this->tokens->advance();
            if ($val === 'true') return ['bool', true];
            if ($val === 'false') return ['bool', false];
            if ($val === 'null') return ['null'];
            return ['const', $val];
        }

        throw new Exception("Unexpected token: " . (is_int($tok) ? token_name($tok) : json_encode($tok)));
    }
}

// Test it
echo "Token-mode Expression Parser Prototype\n";
echo str_repeat("=", 50) . "\n\n";

$parser = new TokenExprParser();

$tests = [
    '<?= 1 + 2 ?>',
    '<?= 1 + 2 * 3 ?>',
    '<?= (1 + 2) * 3 ?>',
    '<?= $x + $y ?>',
    '<?= $a = $b = 5 ?>',
    '<?= $x > 0 ? $x : -$x ?>',
    '<?= "hello" . " " . "world" ?>',
    '<?= 2 ** 3 ** 2 ?>',  // right assoc: 2^(3^2) = 512
    '<?= true || false && true ?>',
];

foreach ($tests as $code) {
    echo "Input: $code\n";
    try {
        $result = $parser->parse($code);
        echo "AST:   " . json_encode($result) . "\n";
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage() . "\n";
    }
    echo "\n";
}
