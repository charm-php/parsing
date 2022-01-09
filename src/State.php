<?php
namespace Charm\Parsing;

/**
 * Class contains state related to a parse that is being performed,
 * such as the source code, the file name, the offset and the length.
 */
class State implements StateInterface {

    /**
     * The full unprocessed source code we're parsing.
     *
     * @var string
     */
    public $source = null;

    /**
     * An array of LexerToken objects, if we're parsing using tokens
     */
    public $tokens = null;

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
     * Stack contains clauses being processed and is used to control
     * the order which clauses are being interpreted in recursively.
     *
     * @var array<int, array{0: int, 1: Clause}>
     */
    private array $stack = [];

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
    private ?Trace $traceRoot=null;

    /**
     * A reference to the node in {@see self::$traceRoot} which is being parsed
     * at the moment.
     */
    private ?Trace $traceCurrent=null;

    private $transactions = [];
    private $transactionLength = 0;

    /**
     * {@see StateInterface::getSourceString()}
     */
    public function getSourceString(): string {
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
        if ($this->tokens === null) {
            return $offset;
        }
        return $this->tokens[$offset-1]->offset + $this->tokens[$offset-1]->length;
    }

    /**
     * {@see StateInterface::getByteLength()}
     */
    public function getByteLength(): int {
        if ($this->tokens === null) {
            return $this->length;
        }
        return strlen($this->source);
    }

    /**
     * {@see StateInterface::describeOffsetAt()}
     */
    public function describeOffsetAt(int $offset): string {
        assert($offset >= 0, "Offset out of range ($offset)");
        assert($offset <= $this->length, "Offset out of range ($offset)");
        if ($this->tokens !== null) {
            return $this->tokens[$offset]->getDebugString();
        } else {
            $st = new StringTools($this->source);
            return $st->describeOffsetAt($offset);
        }
    }


    public function getTrace(): ?Trace {
        return $this->traceRoot;
    }

    /**
     * @param string|array $source  The source we're parsing (string or array of tokens)
     * @param $length               The length of the source we're parsing (number of tokens or string length)
     */
    public function setState($source, array $tokens = null) {
        $this->offset = 0;
        $this->source = $source;
        $this->tokens = $tokens;
        if ($tokens !== null) {
            $this->length = count($tokens);
        } else {
            $this->length = strlen($source);
        }
        $this->traceRoot = null;
        $this->traceCurrent = null;
    }

    /**
     * Record when a clause is starting to parse
     */
    public function traceStart(Clause $clause): void {
        /**
         * Maintain the the stack. We're reusing arrays on the stack
         * as a performance optimization since creating and discarding
         * arrays is expensive.
         */
/*
        $this->stack[$this->stackLength][0] = $clause;
        $this->stack[$this->stackLength][1] = $this->offset;
        $this->stack[$this->stackLength][2] = null;

        /**
         * Start or extend the trace tree
         */
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

        /**
         * Make some assertions to assist in detecting difficult to spot bugs in the parser implementation.
         */
//        assert($this->stackLength > 0, $clause->getDebugName()." called State::traceEnd() without calling a corresponding State::traceStart(). Did it already invoke State::traceEnd()?");

/*
        $oldStack = $this->stack[--$this->stackLength];
*/
//        assert($clause === $oldStack[0], $oldStack[0]->getDebugName()." did not call State::traceEnd() before the parent ".$clause->getDebugName()." did.\n".$this->_dumpStack());

//        assert($result !== false || $oldStack[1] === $this->offset, $clause->getDebugName()." did not properly backtrack when failing to parse.\n".$this->_dumpStack());


        /**
         * Update the trace tree
         */
        $current = $this->traceCurrent;
//        assert($current->clause === $clause, "Inconsistency in trace tree. The current trace did not refer to the clause that ended.");
//        assert(!$current->hasResult, "The current trace seems to already have a result");
        $this->traceCurrent = $current->parent;

        $current->result = $result;
        $current->endOffset = $this->offset;
        $current->hasResult = true;
    }

    private function _dumpStack(): string {
die("dump stack");
        $r = '';
        $r .= "Stack length: ".$this->stackLength."\n";
        foreach ($this->stack as $i => $s) {
            if ($i === $this->stackLength) {
                $r .= "---\n";
            }
            $r .= $i."\t".$s[0]->getDebugName()."\n";
            $r .= " - offset: ".$s[1]."\n";
            $r .= " - initer: ".json_encode($s[2])."\n";
            $r .= " - initer2: ".json_encode($s[4])."\n";
            $r .= " - ender: ".json_encode($s[3]??null)."\n";
        }
        return $r;
    }

    public function __set($key, $value) {
        throw new \LogicException("Undeclared property `".static::class."::$key`");
    }
}
