<?php
namespace Charm\Parsing;

use Charm\Parsing\Util\StringTools;

/**
 * Class contains state related to a parse that is being performed,
 * such as the source code, the file name, the offset and the length.
 */
class State implements StateInterface {

    /**
     * The source string (string mode only).
     */
    public ?string $source = null;

    /**
     * The tokens array (token mode only).
     * Each element is int (token type ID) or string (literal char).
     *
     * @var array<int|string>|null
     */
    public ?array $tokens = null;

    /**
     * Token metadata when in token mode.
     * Provides access to values, offsets, and line numbers on demand.
     */
    public ?TokensInterface $tokenData = null;

    /**
     * The offset we're about to parse (token number if the source was tokenized)
     */
    public int $offset = 0;

    /**
     * The length (token count if the source was tokenized)
     */
    public int $length = -1;

    /**
     * For recording arbitrary state information during parsing.
     */
    public array $extra = [];

    /**
     * Set this to true, and repeat the parse if it fails.
     */
    public bool $trace = false;

    /**
     * Storage of globals which is accessible throughout parsing in snippets via $globals
     */
    public array $globals = [];

    /**
     * @internal
     * Counter holds the position of the next available offset in stack
     */
    private int $stackLength = 0;

    /**
     * @internal
     * Records the hierarchy of clauses that are being parsed throughout
     * the parsing process.
     */
    private ?Trace $traceRoot = null;

    /**
     * A reference to the node in {@see self::$traceRoot} which is being parsed
     * at the moment.
     */
    private ?Trace $traceCurrent = null;

    /**
     * {@see StateInterface::getSourceString()}
     */
    public function getSourceString(): string {
        if ($this->tokens !== null) {
            return $this->tokenData->getSource();
        }
        return $this->source;
    }

    /**
     * {@see StateInterface::getByteOffset()}
     */
    public function getByteOffset(): int {
        return $this->getByteOffsetAt($this->offset);
    }

    /**
     * {@see StateInterface::getByteOffsetAt()}
     */
    public function getByteOffsetAt(int $offset): int {
        assert($offset >= 0, "Invalid negative offset");
        assert($offset <= $this->length, "Invalid offset after end of file");

        // Token mode
        if ($this->tokens !== null) {
            return $this->tokenData->getOffset($offset) ?? 0;
        }

        // String mode: offset is byte offset
        return $offset;
    }

    /**
     * {@see StateInterface::getByteLength()}
     */
    public function getByteLength(): int {
        if ($this->tokens !== null) {
            return strlen($this->tokenData->getSource());
        }
        return $this->length;
    }

    /**
     * {@see StateInterface::describeOffsetAt()}
     */
    public function describeOffsetAt(int $offset): string {
        assert($offset >= 0, "Offset out of range ($offset)");
        assert($offset <= $this->length, "Offset out of range ($offset)");

        // Token mode
        if ($this->tokens !== null) {
            return $this->tokenData->describe($offset);
        }

        // String mode
        $st = new StringTools($this->source);
        return $st->describeOffsetAt($offset);
    }

    public function getTrace(): ?Trace {
        return $this->traceRoot;
    }

    /**
     * Set state for string-mode parsing.
     *
     * @param string $source The source string to parse
     */
    public function setStateString(string $source): void {
        $this->offset = 0;
        $this->source = $source;
        $this->tokens = null;
        $this->length = strlen($source);
        $this->tokenData = null;
        $this->traceRoot = null;
        $this->traceCurrent = null;
    }

    /**
     * Set state for token-mode parsing.
     *
     * @param TokensInterface $tokenData The tokenized source
     */
    public function setStateTokens(TokensInterface $tokenData): void {
        $this->offset = 0;
        $this->source = null;
        $this->tokens = $tokenData->getTokensArray();
        $this->length = count($this->tokens);
        $this->tokenData = $tokenData;
        $this->traceRoot = null;
        $this->traceCurrent = null;
    }

    /**
     * Check if we're in token mode.
     */
    public function isTokenMode(): bool {
        return $this->tokens !== null;
    }

    /**
     * Record when a clause is starting to parse
     */
    public function traceStart(Clause $clause): void {
        if ($this->stackLength++ === 0) {
            $this->traceRoot = $this->traceCurrent = new Trace($clause, $this->offset);
        } else {
            $trace = new Trace($clause, $this->offset);
            $trace->parent = $this->traceCurrent;
            $this->traceCurrent->children[] = $trace;
            $this->traceCurrent = $trace;
        }
    }

    /**
     * Record when a clause finishes its parse
     */
    public function traceEnd(Clause $clause, $result): void {
        $current = $this->traceCurrent;
        $this->traceCurrent = $current->parent;

        $current->result = $result;
        $current->endOffset = $this->offset;
        $current->hasResult = true;
    }

    public function __set($key, $value) {
        throw new \LogicException("Undeclared property `".static::class."::$key`");
    }
}
