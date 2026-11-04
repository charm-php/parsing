<?php
namespace Charm\Parsing\Lexers;

use Charm\Parsing\TokensInterface;
use Charm\Parsing\LexerToken;

/**
 * TokensInterface wrapper for LexerToken[] arrays.
 *
 * Bridges the old Lexer output (array of LexerToken objects) to the new
 * TokensInterface used by token-mode parsing.
 */
class LexerTokens implements TokensInterface {

    /**
     * The tokens array for parsing.
     * Each element is the LexerToken's type (int).
     *
     * @var array<int>
     */
    private readonly array $tokens;

    /**
     * The original source string.
     */
    private string $source;

    /**
     * The raw LexerToken objects.
     *
     * @var array<LexerToken>
     */
    private array $lexerTokens;

    /**
     * @param string $source The original source string
     * @param array<LexerToken> $lexerTokens The tokenized output
     */
    public function __construct(string $source, array $lexerTokens) {
        $this->source = $source;
        $this->lexerTokens = $lexerTokens;
        $this->tokens = array_map(fn(LexerToken $t) => $t->type, $lexerTokens);
    }

    public function getTokensArray(): array {
        return $this->tokens;
    }

    public function getSource(): string {
        return $this->source;
    }

    public function getToken(int $index): int|string|null {
        return $this->tokens[$index] ?? null;
    }

    public function getValue(int $index): ?string {
        return $this->lexerTokens[$index]->text ?? null;
    }

    public function getOffset(int $index): ?int {
        return $this->lexerTokens[$index]->offset ?? null;
    }

    public function getLine(int $index): ?int {
        if (!isset($this->lexerTokens[$index])) {
            return null;
        }
        // Calculate line number from offset
        $offset = $this->lexerTokens[$index]->offset;
        return substr_count($this->source, "\n", 0, $offset) + 1;
    }

    public function count(): int {
        return count($this->tokens);
    }

    public function describe(int $index): string {
        if (!isset($this->lexerTokens[$index])) {
            return 'end-of-input';
        }
        return $this->lexerTokens[$index]->getDebugString();
    }

    /**
     * Get the raw LexerToken at the given index.
     * Useful for backward compatibility during transition.
     */
    public function getLexerToken(int $index): ?LexerToken {
        return $this->lexerTokens[$index] ?? null;
    }

    /**
     * Get the raw LexerToken array.
     * Useful for backward compatibility during transition.
     *
     * @return array<LexerToken>
     */
    public function getLexerTokens(): array {
        return $this->lexerTokens;
    }
}
