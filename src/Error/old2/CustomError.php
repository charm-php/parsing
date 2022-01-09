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
class CustomError extends AdaptableError {

    public function __construct(StateInterface $state, int $offset, string $message) {
        $this->_state = $state;
        $this->_offset = $offset;

        $st = new StringTools($state->source);
        $byteOffset = $state->getByteOffsetAt($offset);
        $this->setLine($st->getLineNumberAt($byteOffset));

        parent::__construct($message);
    }

    public function getByteOffset(): int {
        return $this->
    }

    protected function interpolateString(string $message) {

        $vars = [
            ':offset' => $this->getByteOffset(),
            ':offsetLine' => $this->getOffsetLine(),
            ':offsetColumn' => $this->getOffsetColumn(),
            ':offsetDescription' => $this->getOffsetDescription(),
        ];
        return strtr($message, $vars);
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

    protected function onAdapt() {
        $this->setMessage($this->describeError());
    }
}

