<?php
namespace Charm\Parsing\Clause;

use Charm\Parsing\ParserInterface;
use Charm\Parsing\Grammar;
use Charm\Parsing\Clause;

class Any extends Terminal {

    public function parse(ParserInterface $parser, array &$context, array $args=null) {
        $parser->traceStart($this, $parser->offset);
        $offset = $parser->offset;
        if ($parser->offset === $parser->length) {
            $parser->traceEnd($this, false);
            return false;
        }
        $matched = mb_substr(substr($parser->source, $parser->offset, 4), 0, 1);
        $length = strlen($matched);
        $parser->traceEnd($this, $matched);
        $parser->offset += $length;
        return $matched;
    }

    public function asString(): string {
        return '.';
    }
}
