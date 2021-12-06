<?php
namespace Charm\Parsing\Clause;

use Charm\Parsing\ParserInterface;
use Charm\Parsing\Clause;

class EOF extends Terminal {

    public function parse(ParserInterface $parser, &$parentResult) {
        $parser->traceStart($this, $parser->offset);
        if ($parser->offset === $parser->length) {
            $parser->traceEnd($this, true);
            return true;
        }
        $parser->traceEnd($this, false);
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
