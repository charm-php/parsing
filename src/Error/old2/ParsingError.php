<?php
namespace Charm\Parsing\Error;

use Charm\Parsing\StateInterface;
use Charm\Parsing\Util\{
    StringTools
};

/**
 * Automatically built error message when parsing does not find
 * what is expected.
 */
class ParsingError extends AdaptableError {

    public function __construct(StateInterface $state, int $offset, array $expected=null, string $unexpected=null) {
        $this->_state = $state;
        $this->_offset = $offset;
        if ($expected === []) {
            $expected = null;
        }
        $this->_expected = $expected;
        $this->_unexpected = $unexpected;

        $st = new StringTools($state->source);
        $byteOffset = $state->getByteOffsetAt($offset);
        $this->setLine($st->getLineNumberAt($byteOffset));

        parent::__construct($this->describeError());
    }

    public function getState(): StateInterface {
        return $this->_state;
    }

    public function getSource(): string {
        return $this->getState()->source;
    }

    public function getByteOffset(): int {
        return $this->getState()->getByteOffsetAt($this->_offset);
    }

    public function getExpected(): ?array {
        return $this->_expected;
    }

    public function getUnexpected(): string {
        if ($this->_unexpected !== null) {
            return $this->_unexpected;
        }
        $st = new StringTools($this->getSource());
        return $st->describeOffset($this->getByteOffset());
    }

    protected function onAdapt() {
        $this->setMessage($this->describeError());
    }

    private function describeError(): string {
        if ($this->getExpected() !== null && $this->getUnexpected() !== null) {
            $expected = implode(" or ", $this->getExpected());
            $unexpected = $this->getUnexpected();
            return sprintf("Unexpected %s, expected %s", $unexpected, $expected);
        } elseif ($this->getExpected() !== null) {
            $expected = implode(" or ", $this->getExpected());
            return sprintf("Expected %s", $expected);
        } elseif ($this->getUnexpected()) {
            $unexpected = $this->getUnexpected();
            return sprintf("Unexpected %s", $unexpected);
        } else {
            return "Unknown parsing error";
        }
    }

}
