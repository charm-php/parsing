<?php
namespace Charm\Parsing\Clause;

use Charm\Parsing\Clause;
use Charm\Parsing\ParserInterface;

/**
 * A rule which simply wraps another clause and presents it as if it was this clause.
 */
class Pointer extends Terminal {

    private $clause = null;
    private $methodClass, $methodName;

    public function __construct(Clause $clause) {
        parent::__construct(self::NONE, null);
        $this->clause = $clause;
    }

    public function parse(ParserInterface $parser, array &$context, array $args=null) {
        $parser->traceStart($this, $parser->offset);

        $result = $this->clause->parse($parser, $context, $args);

        $parser->traceEnd($this, $result);
        return $result;
    }

    public function asString(): string {
        return '*'.$this->clause->asString();
    }

    public function __debugInfo() {
        $di = parent::__debugInfo();
        $di['Pointer'] = $this->clause;
        return $di;
    }
}
