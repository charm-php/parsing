<?php
namespace Charm\Parsing\Clause;

use Charm\Parsing\ParserInterface;
use Charm\Parsing\Clause;

class SOF extends Terminal {

    public function parse(ParserInterface $parser, &$parentResult) {
        if ($parser->offset !== 0) {
            $parser->traceMiss($this, $parser->offset);
            return false;
        }
        $parser->traceHit($this, $parser->offset, true);
        return true;
    }

    public function asString(): string {
        return '^';
    }

    public function __debugInfo() {
        if ($this->getModifier() !== 0) {
            return [ 'modifier' => $this->getModifier(), 'SOF' => '^' ];
        }
        return ['SOF' => '^'];
    }
}
