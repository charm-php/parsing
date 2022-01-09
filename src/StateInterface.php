<?php
namespace Charm\Parsing;

/**
 * Parsing state is used and updated while a parse is being performed.
 *
 * @property string $source             The source string we're parsing.
 * @property ?array<LexerToken> $tokens The source string as tokens, if using tokenized parsing.
 * @property string $filename           The filename which contains the string being parsed.
 * @property int $offset                The offset we're about to begin parsing (byte offset or token offset if parsing tokens)
 * @property int $length                The total length of the source (byte length or token count if parsing tokens)
 * @property array $extra               Store arbitrary state information for the parser implementation.
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
     * Reset the state for a new parse to begin.
     */
    public function setState(string $source, array $tokens = null);

}
