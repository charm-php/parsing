<?php
namespace Charm\Parsing;

use Charm\Parsing\Lexers\LexerTokens;
use Charm\Parsing\Util\{
    StringTools,
    ExceptionTools,
};

/**
 * Base class for a generic recursive descent parser
 */
abstract class RecursiveDescentParser extends State implements ParserInterface {

    private static array $cache = [];

    /**
     * Clear the parse result cache. Useful for benchmarking or freeing memory.
     */
    public static function clearCache(): void {
        self::$cache = [];
    }

    /**
     * The initial parsing function. It normally just calls the first production rule function.
     */
    abstract protected function start();

    /**
     * Provide a string tokenizer for tokenizing the source
     */
    abstract protected function getLexer(): LexerInterface;

    /**
     * Get the byte offset of the source we're about to parse.
     */
    protected final function byteOffset(): int {
        if ($this->offset >= $this->length) {
            return strlen($this->getSourceString());
        }
        return $this->tokenData->getOffset($this->offset) ?? strlen($this->getSourceString());
    }

    /**
     * Are there more tokens to parse?
     */
    protected final function eof(): bool {
        return $this->offset >= $this->length;
    }

    /**
     * Load and tokenize a file for parsing
     */
    public final function parseFile(string $filename) {
        return $this->parse(file_get_contents($filename), $filename);
    }

    /**
     * Parse a string. The optional `$filename` argument is used to improve error
     * messages if provided.
     *
     * @param $grammarLineNumber The line number where the grammar definition begins
     */
    public function parse(string $source, string $filename, int $lineOffset=0, int $columnOffset=0) {
        $hash = md5($source."\0".$filename."\0".$lineOffset."\0".$columnOffset);
        if (isset(self::$cache[$hash])) {
            return self::$cache[$hash];
        }
        $lexer = $this->getLexer();
        $this->setStateTokens($lexer->lex($source));
        $this->filename = $filename;
        $this->lineOffset = $lineOffset;
        $this->columnOffset = $columnOffset;

        try {
            $result = $this->start();
            self::$cache[$hash] = $result;
            return $result;
        } catch (ExceptionInterface $e) {
            /**
             * Rewrite the exception with correct location information.
             */
            $tokenIndex = max(0, $this->offset - 1);
            $tokenOffset = $this->tokenData->getOffset($tokenIndex) ?? 0;
            $st = new StringTools($source);
            $et = new ExceptionTools($e);
            $et->setFileName($filename);
            $et->setLine($st->getLineNumberAt($tokenOffset) + $lineOffset);
            throw $e;
        }
    }

    /**
     * Return the LexerToken and move one step forward. If there are no more tokens
     * to parse, PHP's return type handling will trigger a TypeError.
     */
    protected final function accept(): LexerToken {
        return $this->getLexerTokens()->getLexerToken($this->offset++);
    }

    /**
     * Return the LexerToken type at the current offset or at a different offset
     * relative to the current token (for implementing lookahead).
     */
    protected final function type(int $offset=0): int {
        return $this->tokenData->getToken($offset + $this->offset) ?? -1;
    }

    /**
     * Return the literal text string at the current offset, or at a different
     * offset relative to the current token (for implementing lookahead).
     */
    protected final function text(int $offset=0): string {
        return $this->tokenData->getValue($offset + $this->offset) ?? '';
    }

    /**
     * Utility function to check the token type at the current offset.
     */
    protected final function typeIs(int $type, int $offset=0): bool {
        $index = $this->offset + $offset;
        return $index < $this->length && $this->tokenData->getToken($index) === $type;
    }

    /**
     * Utility function to check that the text at the current offset matches
     * a particular text.
     */
    protected final function textIs(string $text, int $offset=0): bool {
        $index = $this->offset + $offset;
        return $index < $this->length && $this->tokenData->getValue($index) === $text;
    }

    /**
     * Get the lexer token at an offset relative to the current offset.
     */
    protected final function peek(int $offset): ?LexerToken {
        return $this->getLexerTokens()->getLexerToken($this->offset + $offset);
    }

    /**
     * Get the LexerTokens instance. Only valid for parsers using the internal Lexer.
     */
    private function getLexerTokens(): LexerTokens {
        assert($this->tokenData instanceof LexerTokens);
        return $this->tokenData;
    }

    /**
     * The filename we're parsing
     */
    protected string $filename;

    /**
     * The line offset (added to error message offset descriptions)
     */
    protected int $lineOffset = 0;

    /**
     * The column offset (added to error message offset descriptions)
     */
    protected int $columnOffset = 0;
}


