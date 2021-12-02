<?php
namespace Charm\Parsing\Clause;

use Charm\Parsing\ParserInterface;
use Charm\Parsing\Clause;
use Charm\Parsing\Hit;

class SOF extends Terminal {

    public function parse(ParserInterface $parser): ?Hit {
        $parser->trace($this);
        if ($parser->offset !== 0) {
            $parser->traceMiss($this, $parser->offset);
            return null;
        }
        $parser->traceHit($this, $parser->offset, '');
        return new Hit($this, '', $parser->offset, 0);
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
