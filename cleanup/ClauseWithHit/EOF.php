<?php
namespace Charm\Parsing\Clause;

use Charm\Parsing\ParserInterface;
use Charm\Parsing\Clause;
use Charm\Parsing\Hit;

class EOF extends Terminal {

    public function parse(ParserInterface $parser): ?Hit {
        $parser->trace($this);
        if ($parser->offset === $parser->length) {
            $parser->traceHit($this);
            return new Hit($this, null, $parser->offset, 0);
        }
        $parser->traceMiss($this, $parser->offset);
        return null;
    }

    public function asString(): string {
        return '$';
    }

    public function debugInfo() {
        if ($this->getModifier() !== 0) {
            return [ 'modifier' => $this->getModifier(), 'EOF' => '$' ];
        }
        return ['EOF' => '$'];
    }
}
