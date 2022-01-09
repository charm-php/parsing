<?php
namespace Charm\Parsing;

interface LexerInterface {

    /**
     * Tokenize a string
     *
     * @throws RegexError
     * @throws LexerError
     *
     * @return array<LexerToken>
     */
    public function tokenize(string $source, string $filename, int $lineOffset=0, int $columnOffset=0): array;
}
