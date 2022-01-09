<?php
namespace Charm\Parsing\Clause;

use Charm\Parsing\State;
use Charm\Parsing\Clause;

class EOF extends Terminal {

    public function toGrammarString(): string {
        return $this->getPredicateString().'$'.$this->getOperatorString();
    }

    public function asString(): string {
        return '$';
    }

    public function interpret(State $state, array $context) {
        if ($state->offset === $state->length) {
            return true;
        }
        return false;
    }
}
