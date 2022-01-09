<?php
namespace Charm\Parsing\Clause;

use Charm\Parsing\ParserInterface;
use Charm\Parsing\Grammar;
use Charm\Parsing\Clause;
use Charm\Parsing\Hit;

class Any extends Terminal {

    public function parse(ParserInterface $parser): ?Hit {
        $parser->trace($this);
        $offset = $parser->offset;
        if ($parser->offset === $parser->length) {
            $parser->traceMiss($this, $parser->offset);
            return null;
        }
        $matched = mb_substr(substr($parser->source, $parser->offset, 4), 0, 1);
        $length = strlen($matched);
        $parser->traceHit($this, $parser->offset, $matched);
        $parser->offset += $length;
        return new Hit($this, $matched, $offset, $length);
    }

    public function asString(): string {
        return '.';
    }

    public function __debugInfo() {
        if ($this->getModifier() !== 0) {
            return [ 'modifier' => $this->getModifier(), 'any' => '.' ];
        }
        return [ 'any' => '.' ];
    }

}
