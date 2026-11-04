<?php
namespace Charm\Parsing\Lexers;

use Charm\Parsing\LexerInterface;
use Charm\Parsing\TokensInterface;
use PhpParser\Lexer;

/**
 * PHP lexer using nikic/php-parser's Lexer.
 *
 * Wraps nikic's lexer for use with Charm Parser.
 * This ensures we handle tokens exactly like nikic/php-parser does.
 */
class PhpLexer implements LexerInterface {

    private Lexer $nikicLexer;
    private ?array $symbolMap = null;

    public function __construct() {
        $this->nikicLexer = new Lexer();
    }

    public function lex(string $source): TokensInterface {
        $tokens = $this->nikicLexer->tokenize($source);
        return new PhpTokens($source, $tokens);
    }

    public function getSymbolMap(): array {
        if ($this->symbolMap === null) {
            $this->symbolMap = $this->buildSymbolMap();
        }
        return $this->symbolMap;
    }

    /**
     * Build the symbol map from PHP's token constants.
     */
    private function buildSymbolMap(): array {
        $map = [];

        // Get all defined constants
        $constants = get_defined_constants(true);
        $tokenConstants = $constants['tokenizer'] ?? [];

        foreach ($tokenConstants as $name => $value) {
            if (str_starts_with($name, 'T_')) {
                $map[$name] = $value;
            }
        }

        return $map;
    }
}
