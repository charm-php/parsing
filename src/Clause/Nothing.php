<?php
namespace Charm\Parsing\Clause;

use Charm\Parsing\ParserInterface;

/**
 * A rule which matches nothing (epsilon)
 */
class Epsilon extends Terminal {

    public function parse(ParserInterface $parser, &$parentResult) {
        $parser->traceHit($this, $parser->offset, true);
        return true;
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
