<?php
namespace Charm\Parsing\Clause;

use Charm\Parsing\State;
use Charm\Parsing\Clause;
use Charm\Parsing\Signal;

/**
 * A clause that always succeeds without consuming any input.
 *
 * This is the "epsilon" (ε) production from formal grammar theory.
 * Written as \nothing in grammar syntax.
 */
class Nothing extends Terminal {

    public function toGrammarString(): string {
        return $this->getPredicateString().'\\nothing'.$this->getOperatorString();
    }

    public function asString(): string {
        return '\\nothing';
    }

    public function interpret(State $state, array $context) {
        return Signal::HIT;
    }

    public function getMinByteLength(): int {
        return 0;
    }

    public function getFirstBytes(): ?string {
        return null;
    }
}
