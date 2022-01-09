<?php
namespace Charm\Parsing\Error;

use Charm\Parsing\StateInterface;

class StateParseError extends BaseError {

    private
        $_state,
        $_stateOffset;

    public function __construct(StateInterface $state, string $messageTemplate, $code = 0, \Throwable $previous = null) {
        $this->_state = $state;
        $this->_stateOffset = $state->offset;
        parent::__construct($messageTemplate, $code, $previous);

        $this->setSourceInfo($state->getSourceString(), $state->getByteOffset());
    }

    public function getSourceDescription() {
        if ($this->_stateOffset < 0) {
            return 'invalid negative offset';
        }
        if ($this->_state->tokens !== null) {
            if ($this->_stateOffset >= count($this->_state->tokens)) {
                return 'end of file';
            } else {
                return $this->_state->tokens[$this->_stateOffset]->getDebugString();
            }
        } else {

        }
    }

}
