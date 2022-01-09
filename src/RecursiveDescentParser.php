<?php
namespace Charm\Parsing;

use Charm\Vector;
use Charm\Parsing\Util\{
    StringTools,
    ExceptionTools,
};

/**
 * Base class for a generic recursive descent parser
 */
abstract class RecursiveDescentParser extends State implements ParserInterface {

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
            return strlen($this->source);
        }
        return $this->tokens[$this->offset]->offset;
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
        static $cache = [];
        $hash = md5($source.$filename.$lineOffset.$columnOffset);
        if (isset($cache[$hash])) {
            return $cache[$hash];
        }
        $lexer = $this->getLexer();
        $tokens = $lexer->tokenize($source, $filename, $lineOffset, $columnOffset);
        // yes we invoke the constructor
        $this->setState($source, $tokens);
        $this->filename = $filename;
        $this->lineOffset = $lineOffset;
        $this->columnOffset = $columnOffset;

        try {
            $result = $this->start();
            $cache[$hash] = $result;
            return $result;
        } catch (ExceptionInterface $e) {
            /**
             * Rewrite the exception with correct location information.
             */
            $offendingToken = $this->tokens[$this->offset - 1];
            $st = new StringTools($source);
            $et = new ExceptionTools($e);
            $et->setFileName($filename);
            $et->setLine($st->getLineNumberAt($offendingToken->offset) + $lineOffset);
            throw $e;
        }
    }

    /**
     * Return the LexerToken and move one step forward. If there are no more tokens
     * to parse, PHP's return type handling will trigger a TypeError.
     */
    protected final function accept(): LexerToken {
        return $this->tokens[$this->offset++];
    }

    /**
     * Return the LexerToken type at the current offset or at a different offset
     * relative to the current token (for implementing lookahead).
     */
    protected final function type(int $offset=0): int {
        return $this->tokens[$offset + $this->offset]->type ?? -1;
    }

    /**
     * Return the literal text string at the current offset, or at a different
     * offset relative to the current token (for implementing lookahead).
     */
    protected final function text(int $offset=0): string {
        return $this->tokens[$offset + $this->offset]->text ?? '';
    }

    /**
     * Utility function to check the token type at the current offset.
     */
    protected final function typeIs(int $type, int $offset=0): bool {
        if ($this->offset + $offset < $this->length) {
            return $this->tokens[$this->offset + $offset]->type === $type;
        }
        return false;
    }

    /**
     * Utility function to check that the text at the current offset matches
     * a particular text.
     */
    protected final function textIs(string $text, int $offset=0): bool {
        if ($this->offset + $offset < $this->length) {
            return $this->tokens[$this->offset + $offset]->text === $text;
        }
        return false;
    }

    /**
     * Get the lexer token at an offset relative to the current offset.
     */
    protected final function peek(int $offset): ?LexerToken {
        return $this->tokens[$this->offset + $offset] ?? null;
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

    /**
     * A map of terminal regex patterns for the lexer. Added by {@see self::addTerminal()}.
     *
     * @var array<int, string>
     */
    private array $terminals = [];    // terminal patterns

    /**
     * An array of terminal names which should be removed by the tokenizer. Added by
     * {@see self::addIgnore()}
     *
     * @var array<int, bool>
     */
    private array $ignores = [];      // ignore patterns

    /**
     * Array of LexerToken objects from tokenizing the source code passed to {@see self::parse()}
     *
     * @var array<int, LexerToken>
     */
    public $tokens;            // regex matches

}


