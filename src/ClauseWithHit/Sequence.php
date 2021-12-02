<?php
namespace Charm\Parsing\Clause;

use Charm\Parsing\ParserInterface;
use Charm\Parsing\Clause;
use Charm\Parsing\Hit;

/**
 * Sequence: e1 e2
 */
class Sequence extends NonTerminal implements \IteratorAggregate, \Countable, \ArrayAccess {

    private $clauses = [];

    public function offsetGet($offset) {
        return $this->clauses[$offset] ?? null;
    }

    public function offsetSet($offset, $clause) {
        $this->clauses[$offset] = $clause;
    }

    public function offsetExists($offset) {
        return array_key_exists($offset, $this->clauses);
    }

    public function offsetUnset($offset) {
        throw new Exception("Can't unset a clause from OrderedChoice");
    }

    public function parse(ParserInterface $parser): ?Hit {
        $offset = $parser->offset;
        $parser->trace($this);
        $matches = [];
        foreach ($this as $subClause) {

            $match = $this->processSubClause($parser, $subClause);
            if ($match === null) {
echo "--- MATCH SUBCLAUSE: $match\n";
                $parser->offset = $offset;
                $parser->traceMiss($this, $offset);
                return null;
            }

            $matches[] = $match;
        }

        $length = $parser->offset - $offset;
        $parser->traceHit($this, $offset, implode("", $matches));
        return new Hit($this, $matches, $offset, $length);
    }

    public function count() {
        return count($this->clauses);
    }

    public function addClause(Clause $clause) {
        $this->clauses[] = $clause;
    }

    public function getIterator() {
        yield from $this->clauses;
    }

    public function asString(): string {
        return implode(" ", $this->clauses);
    }

    public function __debugInfo() {
        if ($this->getModifier() !== 0) {
            return [
                'modifier' => $this->getModifier(),
                'sequence' => $this->clauses,
            ];
        }

        return [
            'sequence' => $this->clauses,
        ];
    }

}
