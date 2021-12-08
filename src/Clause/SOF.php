<?php
namespace Charm\Parsing\Clause;

use Charm\Parsing\ParserInterface;
use Charm\Parsing\Clause;

class SOF extends Terminal {

    public function parse(ParserInterface $parser, array &$context, array $args=null) {
        $parser->traceStart($this, $parser->offset);
        if ($parser->offset !== 0) {
            $parser->traceEnd($this, false);
            return false;
        }
        $parser->traceEnd($this, true);
        return true;
    }

    public function asString(): string {
        return '^';
    }
}
