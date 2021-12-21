<?php
namespace Charm\Parsing;

/**
 * A ParserInterface defines the API that a ready-to-use parser should expose for end users.
 *
 * @property $source The source currently being parsed
 * @property $offset The current parse offset
 * @property $length The full length of the source
 */
interface ParserInterface {

    /**
     * Parse a string into a representation as defined by the grammar. Throws exceptions
     * on errors in the source string that's being parsed.
     *
     * @throws LexingError
     * @throws SyntaxError
     *
     * @param $source       The string to parse
     * @param $filename     A file name to use in error messages
     */
    public function parse(string $source, string $filename=null);

    /**
     * Parse a file into an AST.
     */
    public function parseFile(string $filename);

    /**
     * Return an array for storing state information during parsing
     */
    public function &getState(): array;
}
