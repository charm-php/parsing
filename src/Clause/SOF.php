<?php
namespace Charm\Parsing\Clause;

use Charm\Parsing\State;
use Charm\Parsing\Clause;
use Charm\Parsing\Signal;

class SOF extends Terminal {

    public function asString(): string {
        return '^';
    }

    public function interpret(State $state, array $context) {
        if ($state->offset !== 0) {
            return Signal::MISS;
        }
        return Signal::HIT;
    }

    public function getMinByteLength(): int {
        return 0;
    }

    public function getFirstBytes(): ?string {
        return null;
    }
}
