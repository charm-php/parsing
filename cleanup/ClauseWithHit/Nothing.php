<?php
namespace Charm\Parsing\Clause;

use Charm\Parsing\ParserInterface;
use Charm\Parsing\Hit;

/**
 * A rule which matches nothing (epsilon)
 */
class Epsilon extends Terminal {

    public function parse(ParserInterface $parser): ?Hit {
        $parser->trace($this);
        $parser->traceHit($this, $parser->offset);
        return new Hit($this, '', $parser->offset, 0);
    }

    public static function create() {
        return new static();
    }

    public function asString(): string {
        return 'ε';
    }

    public function debugInfo() {
        if ($this->getModifier() !== 0) {
            return [ 'modifier' => $this->getModifier(), 'Epsilon' => 'ε' ];
        }
        return ['Epsilon' => 'ε'];
    }
}
