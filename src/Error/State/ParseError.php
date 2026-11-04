<?php
namespace Charm\Parsing\Error\State;

use Charm\Parsing\Error\AbstractParseError;
use Charm\Parsing\Util\StringTools;
use Charm\Parsing\State;

class ParseError extends AbstractParseError {

    private $_state, $_offset;

    public function __construct(State $state, string $messageTemplate, $code = 0, \Throwable $previous = null) {
        $this->_state = $state;
        $this->_offset = $state->offset;
        parent::__construct($messageTemplate, $code, $previous);
    }

    public function getSource(): ?string {
        return $this->_state->source;
    }

    public function getInternalLineNumber(): int {
        if ($this->_state->source === null) {
            return 0;
        }
        $st = new StringTools($this->_state->source);
        return $st->getLineNumberAt($this->_state->getByteOffsetAt($this->_offset));
    }

    public function getInternalColumnNumber(): int {
        if ($this->_state->source === null) {
            return 0;
        }
        $st = new StringTools($this->_state->source);
        return $st->getColumnNumberAt($this->_state->getByteOffsetAt($this->_offset));
    }

    public function getSourceDescription(): string {
        return $this->_state->describeOffsetAt($this->_offset);
    }

}
