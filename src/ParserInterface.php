<?php
namespace Charm\Parsing;

/**
 * When parsing a source, these properties are defined and contains the string being parsed. The Parser instance
 * is passed to the Clause instances to perform the actual parsing.
 *
 * @property $source The source currently being parsed
 * @property $offset The current parse offset
 * @property $length The full length of the source
 */
interface ParserInterface {

    /**
     * Create a parser instance from a grammar; normally using the `Charm\Parsing\GrammarParser` to parse
     */
    public static function fromString(string $grammar): static;

    /**
     * Parse the string and return the result
     */
    public function parse(string $source);

    /**
     * Get the grammar
     */
    public function getGrammar(): GrammarInterface;

}
