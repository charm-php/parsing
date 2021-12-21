<?php
namespace Charm\Parsing\Clause;

use Charm\Parsing\ParserInterface;
use Charm\Parsing\Clause;

class EOF extends Terminal {

    public function toGrammarString(): string {
        return $this->getPredicateString().'$'.$this->getOperatorString();
    }

    public function asString(): string {
        return '$';
    }

    public function parse(ParserInterface $parser, array &$context, array $args=null) {
        $parser->traceStart($this, $parser->offset);
        if ($parser->offset === $parser->length) {
            $parser->traceEnd($this, true);
            return true;
        }
        $parser->traceEnd($this, false);
        return false;
    }
}
