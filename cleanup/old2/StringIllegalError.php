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
class StringIllegalError extends AdaptableError {

    public function __construct(string $source, int $offset, string $unexpected=null) {
        $this->_source = $source;
        $this->_offset = $offset;
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
        $unexpected = $this->getUnexpected();
        return sprintf("Illegal %s encountered", $unexpected);
    }

}
