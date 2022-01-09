<?php
namespace Charm\Parsing\Error;

use Charm\Parsing\Errors\{
    ExceptionTools,
    StringTools
};

/**
 * Automatically built error message when parsing does not find
 * what is expected.
 */
class StringParsingError extends AdaptableError {

    public function __construct(string $source, int $offset, array $expected=null, string $unexpected=null) {
        $this->_source = $source;
        $this->_offset = $offset;
        if ($expected === []) {
            $expected = null;
        }
        $this->_expected = $expected;
        $this->_unexpected = $unexpected;
        $st = new StringTools($source);
        $this->setLine($st->getLineNumberAt($offset));
        parent::__construct($this->describeError());
    }

    public function getSource(): string {
        return $this->_source;
    }

    public function getByteOffset(): int {
        return $this->_offset;
    }

    public function getExpected(): array {
        return $this->_expected;
    }

    public function getUnexpected(): string {
        if ($this->_unexpected !== null) {
            return $this->_unexpected;
        }
        $st = new StringTools($this->_source);
        return $st->describeOffset($this->_offset);
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
