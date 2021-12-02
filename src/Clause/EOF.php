<?php
namespace Charm\Parsing\Clause;

use Charm\Parsing\ParserInterface;
use Charm\Parsing\Clause;

class EOF extends Terminal {

    public function parse(ParserInterface $parser, &$parentResult) {
        if ($parser->offset === $parser->length) {
            $parser->traceHit($this, $parser->offset, '*EOF');
            return true;
        }
        $parser->traceMiss($this, $parser->offset);
        return false;
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
