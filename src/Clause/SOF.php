<?php
namespace Charm\Parsing\Clause;

use Charm\Parsing\State;
use Charm\Parsing\Clause;

class SOF extends Terminal {

    public function asString(): string {
        return '^';
    }

    public function interpret(State $state, array $context) {
        if ($state->offset !== 0) {
            return false;
        }
        return true;
    }

}
