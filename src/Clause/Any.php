<?php
namespace Charm\Parsing\Clause;

use Charm\Parsing\State;
use Charm\Parsing\Grammar;
use Charm\Parsing\Clause;
use Charm\Parsing\Signal;

class Any extends Terminal {

    public function toGrammarString(): string {
        return $this->getPredicateString().'.'.$this->getOperatorString();
    }

    public function asString(): string {
        return '.';
    }

    public function interpret(State $state, array $context) {
        if ($state->offset === $state->length) {
            return Signal::MISS;
        }

        // Token mode: return the token at current offset
        if ($state->tokens !== null) {
            return $state->tokens[$state->offset++];
        }

        // String mode: return next UTF-8 character
        $matched = mb_substr(substr($state->source, $state->offset, 4), 0, 1);
        $state->offset += strlen($matched);
        return $matched;
    }

    public function getMinByteLength(): int {
        return 1;
    }

    public function getFirstBytes(): ?string {
        return null; // matches any character
    }
}
