<?php
namespace Charm\Parsing\Clause;

use Charm\Parsing\State;
use Charm\Parsing\Grammar;
use Charm\Parsing\Clause;

class Any extends Terminal {

    public function toGrammarString(): string {
        return $this->getPredicateString().'.'.$this->getOperatorString();
    }

    public function asString(): string {
        return '.';
    }

    public function parse(State $state, array $context) {
        $offset = $state->offset;
        if ($state->offset === $state->length) {
            return false;
        }
        $matched = mb_substr(substr($state->source, $state->offset, 4), 0, 1);
        $length = strlen($matched);
        $state->offset += $length;
        return $matched;
    }

}
