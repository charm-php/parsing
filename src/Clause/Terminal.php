<?php
namespace Charm\Parsing\Clause;

use Charm\Parsing\Clause;

abstract class Terminal extends Clause {
    public final function isTerminal(): bool {
        return true;
    }
}
