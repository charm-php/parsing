<?php
namespace Charm\Parsing;

/**
 * Parsing state is used and updated while a parse is being performed.
 *
 * @property string|array $source   The source (string for string-mode, array for token-mode)
 * @property int $offset            The offset we're about to begin parsing (byte offset or token offset)
 * @property int $length            The total length of the source (byte length or token count)
 * @property array $extra           Store arbitrary state information for the parser implementation.
 */
interface StateInterface {

    /**
     * Return the full source as a string
     */
    public function getSourceString(): string;

    /**
     * Return the current parse offset as a number of bytes from the
     * start of the source string.
     */
    public function getByteOffset(): int;

    /**
     * Return the byte offset at a given parse offset
     */
    public function getByteOffsetAt(int $offset): int;

    /**
     * Returns the total byte length of the source being parsed.
     */
    public function getByteLength(): int;

    /**
     * Returns a description of the content at a given offset
     */
    public function describeOffsetAt(int $offset): string;

    /**
     * Set state for string-mode parsing.
     */
    public function setStateString(string $source): void;

    /**
     * Set state for token-mode parsing.
     */
    public function setStateTokens(TokensInterface $tokenData): void;
}
