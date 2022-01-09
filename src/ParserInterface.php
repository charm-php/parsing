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
     * @param $lineOffset   A number to add to line numbers in errors and offset information
     * @param $columnOffset A number to add to column numbers in errors and offset information
     */
    public function parse(string $source, string $filename, int $lineOffset=0, int $columnOffset=0);

    /**
     * Parse a file into an AST.
     */
    public function parseFile(string $filename);
}
