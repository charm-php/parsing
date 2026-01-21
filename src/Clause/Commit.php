<?php
namespace Charm\Parsing\Clause;

use Charm\Parsing\State;
use Charm\Parsing\InternalError;

class Commit extends Terminal {

    public function asString(): string {
        return '@';
    }

    public function interpret(State $state, array $context) {
        throw new InternalError("`".$this->toLocationString()."` commit symbol not handled");
    }

    public function getMinByteLength(): int {
        return 0; // Commit doesn't consume input
    }

    public function getFirstBytes(): ?string {
        return null; // Commit doesn't consume input
    }
}
