<?php
namespace Charm\Parsing\Error;

use Charm\Parsing\StateInterface;
use Charm\Parsing\Util\StringTools;

/**
 * Automatically built error message when parsing does not find
 * what is expected.
 */
class UnexpectedError extends AdaptableError {
    private $_state, $_offset, $_expected;

    /**
     * @param $state The state for the current parse
     * @param $offset The token offset where the unexpected was found
     * @param array<string> $expected An array of strings saying what was anticipated.
     */
    public function __construct(StateInterface $state, int $offset, array $expected) {
        assert($offset >= 0, "Argument #2 must be >= 0");
        assert($offset <= $state->length, "Argument #2 must be <= source length");

        $this->_state = $state;
        $this->_offset = $offset;
        $this->_expected = $expected;

        parent::__construct($this->describeError(), 0);
    }

    public function getState(): State {
        return $this->_state;
    }

    public function getByteOffset(): int {
        return $this->getState()->getByteOffsetAt($this->_offset);
    }

    public function getExpected(): array {
        return $this->_expected;
    }

    public function getUnexpected(): string {
        return self::describeOffset($this->getState()->source, $this->getOffset());
    }

    protected function onAdapt() {
        $this->setMessage($this->describeError());
    }

    private function describeError() {
        if ($this->_offset === $this->getState()->length) {
            $unexpected = 'end of file';
        } else {
            $unexpected = self::describeOffset($state->source, $offset);
        }
        $expected = implode(" or ", $this->_expected);
        return sprintf("Unexpected %s, expected %s", $unexpected, $expected);
    }

    private static function describeOffset($source, int $offset): string {
        if (is_string($source)) {
            $st = new StringTools($source);
            return $st->describeOffset($offset);
        }
        assert(is_array($source), "Expecting parameter \$source to be string or array of LexerToken objects");
        if ($offset === count($source)) {
            return 'end of file';
        } elseif ($offset > count($source)) {
            return 'invalid offset after end of file';
        } elseif ($offset === 0) {
            return 'start of file';
        } else {
            return 'invalid negative offset';
        }
        assert($source[$offset] instanceof LexerToken, "Expecting \$source to be a string or an array of LexerToken objects");
        return $source[$offset]->getDebugString();
    }
}
