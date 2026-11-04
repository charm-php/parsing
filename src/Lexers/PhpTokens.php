<?php
namespace Charm\Parsing\Lexers;

use Charm\Parsing\TokensInterface;
use PhpParser\Token;

/**
 * PHP tokens from nikic/php-parser v5 Lexer.
 *
 * Wraps nikic's Token objects and provides:
 * - $tokens: array of int for parsing (token IDs)
 * - Methods to extract values, line numbers, offsets on demand
 */
class PhpTokens implements TokensInterface {

    /**
     * The tokens array for parsing.
     * Each element is the token ID (int).
     *
     * @var array<int>
     */
    private readonly array $tokens;

    /**
     * The original source string.
     */
    private string $source;

    /**
     * Filtered Token objects from nikic's Lexer.
     * @var array<Token>
     */
    private array $raw;

    /**
     * Tokens that nikic's parser drops during parsing.
     * We filter these out to match nikic's parser behavior.
     */
    private const DROP_TOKENS = [
        0 => true,  // EOF marker
        T_WHITESPACE => true,
        T_OPEN_TAG => true,
        T_COMMENT => true,
        T_DOC_COMMENT => true,
        T_BAD_CHARACTER => true,
    ];

    /**
     * @param string $source Original source code
     * @param array<Token> $nikicTokens Tokens from nikic's Lexer::tokenize()
     */
    public function __construct(string $source, array $nikicTokens) {
        $this->source = $source;

        // Filter tokens to match nikic's parser behavior (drop whitespace, open tag, etc.)
        $filtered = [];
        $ids = [];

        foreach ($nikicTokens as $token) {
            // Skip dropped tokens
            if (isset(self::DROP_TOKENS[$token->id])) {
                continue;
            }

            $filtered[] = $token;
            // Single-char tokens (id < 256) store as string, others as int
            $ids[] = $token->id < 256 ? $token->text : $token->id;
        }

        $this->raw = $filtered;
        $this->tokens = $ids;
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
        return $this->raw[$index]->text ?? null;
    }

    public function getOffset(int $index): ?int {
        return $this->raw[$index]->pos ?? null;
    }

    public function getLine(int $index): ?int {
        return $this->raw[$index]->line ?? null;
    }

    public function count(): int {
        return count($this->tokens);
    }

    public function describe(int $index): string {
        $token = $this->raw[$index] ?? null;

        if ($token === null) {
            return 'end-of-input';
        }

        $display = strlen($token->text) > 20 ? substr($token->text, 0, 17) . '...' : $token->text;

        return token_name($token->id) . " `$display`";
    }
}
