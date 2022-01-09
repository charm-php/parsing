<?php
namespace Charm\Parsing\Error;

use Charm\Parsing\{
    StateInterface
};
use Charm\Parsing\Errors\{
    ExceptionTools,
    StringTools
};

/**
 * Automatically built error message when parsing does not find
 * what is expected.
 */
class IllegalError extends AdaptableError {

    protected $_state, $_offset, $_unexpected;

    public function __construct(StateInterface $state, int $offset, string $unexpected=null) {
        $this->_state = $state;
        $this->_offset = $offset;
        $this->_unexpected = $unexpected;
        $st = new StringTools($state->source);
        $byteOffset = $state->getByteOffsetAt($offset);
        $this->setLine($st->getLineNumberAt($byteOffset));
        parent::__construct($this->describeError());
    }

    public function getState(): State {
        return $this->_state;
    }

    public function getByteOffset(): int {
        return $this->getState()->getByteOffsetAt($this->_offset);
    }

    public function getUnexpected(): string {
        if ($this->_unexpected !== null) {
            return $this->_unexpected;
        }
        $state = $this->getState();
        if ($state->tokens !== null) {
            if ($this->_offset < 0) {
                return 'illegal negative offset';
            } elseif ($this->_offset === $this->getState()->length) {
                return 'end of file';
            } else {
                return $this->tokens[$this->_offset]->getDebugString();
            }
        }
        $st = new StringTools($state->source);
        return $st->describeOffset($this->getByteOffset());
    }

    protected function onAdapt() {
        $this->setMessage($this->describeError());
    }

    private function describeError(): string {
        $unexpected = $this->getUnexpected();
        return sprintf("Illegal %s encountered", $unexpected);
    }

}
