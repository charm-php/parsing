<?php
namespace Charm\Parsing\Clause;

use Charm\Parsing\ParserInterface;

/**
 * A rule which matches nothing (epsilon)
 */
class Epsilon extends Terminal {

    public function parse(ParserInterface $parser, array &$context, array $args=null) {
        $parser->traceStart($this, $parser->offset);
        $parser->traceEnd($this, true);
        return true;
    }

    public static function create() {
        return new static();
    }

    public function asString(): string {
        return 'ε';
    }
}
