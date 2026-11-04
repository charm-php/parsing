<?php
namespace Charm\Parsing;

/**
 * Interface for lexers that convert source strings to token arrays.
 *
 * A lexer provides two things:
 * 1. A lex() method that converts a source string to an array of tokens
 * 2. A symbol map that translates names (like "T_LNUMBER") to token values
 *
 * The token array contains int|string values:
 * - int: Token type ID (like T_LNUMBER = 315)
 * - string: Literal characters (like "+", "(", ";")
 *
 * The symbol map allows grammars to reference tokens by name.
 */
interface LexerInterface {

    /**
     * Lex a source string into a tokens object.
     *
     * @param string $source The source code to lex
     * @return TokensInterface Contains tokens array and metadata accessors
     */
    public function lex(string $source): TokensInterface;

    /**
     * Get the symbol map: token names to their values.
     *
     * Example: ["T_LNUMBER" => 315, "T_STRING" => 319, ...]
     *
     * This map is used by the grammar parser to resolve token references.
     *
     * @return array<string, int|string>
     */
    public function getSymbolMap(): array;
}
