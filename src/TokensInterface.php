<?php
namespace Charm\Parsing;

/**
 * Interface for tokenized source that can be parsed.
 *
 * Provides a tokens array for parsing, plus methods to extract
 * metadata (values, line numbers, offsets) on demand.
 *
 * Note: In PHP 8.4+ this could declare `public array $tokens` directly.
 * For now, implementations should expose it as a public readonly property.
 */
interface TokensInterface {

    /**
     * Get the tokens array for parsing.
     * Each element is int (token ID) or string (literal char).
     *
     * This is the pre-generated array used for fast lookup during parsing.
     *
     * @return array<int|string>
     */
    public function getTokensArray(): array;

    /**
     * The original source string.
     */
    public function getSource(): string;

    /**
     * Get the token at position $index.
     * Returns int (token ID) or string (literal char), or null if out of bounds.
     */
    public function getToken(int $index): int|string|null;

    /**
     * Get the string value of the token at position $index.
     */
    public function getValue(int $index): ?string;

    /**
     * Get the byte offset in source of the token at position $index.
     */
    public function getOffset(int $index): ?int;

    /**
     * Get the line number (1-based) of the token at position $index.
     */
    public function getLine(int $index): ?int;

    /**
     * Get the number of tokens.
     */
    public function count(): int;

    /**
     * Get a debug description of the token at position $index.
     */
    public function describe(int $index): string;
}
