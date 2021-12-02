<?php
namespace Charm\Parsing\Clause;

use Charm\Parsing\ParserInterface;
use Charm\Parsing\Grammar;
use Charm\Parsing\Clause;
use Charm\Parsing\Rule;
/**
 * OrderedChoice: e1 / e2 / e3
 */
class OrderedChoice extends NonTerminal implements \IteratorAggregate, \Countable, \ArrayAccess {

    private $clauses = [];

    public function setRule(Rule $rule) {
        foreach ($this->clauses as $clause) {
            $clause->setRule($rule);
        }
        parent::setRule($rule);
    }

    public function parse(ParserInterface $parser, &$parentResult) {
        $offset = $parser->offset;

        foreach ($this->clauses as $clause) {
            $parser->offset = $offset;

            $context = null;

            if ($clause instanceof Snippet) {
                $result = $clause->parse($parser, $context);
            } else {
                $result = $this->processSubClause($parser, $clause, $context);
            }

            if ($result === false) {
                // try the next choice
                continue;
            }

/*
            $parser->traceHit($this, $offset, $result);
*/
            return $result;
        }
        $parser->offset = $offset;
/*
        $parser->traceMiss($this, $offset);
*/
        return false;
    }

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

    public function count() {
        return count($this->clauses);
    }

    public function addClause(Clause $choice) {
        $this->clauses[] = $choice;
    }

    public function getIterator() {
        yield from $this->clauses;
    }

    public function asString(): string {
        return implode(" / ", $this->clauses);
    }

    public function __debugInfo() {
        if ($this->getModifier() !== 0) {
            return [
                'modifier' => $this->getModifier(),
                'ordered-choice' => $this->clauses,
            ];
        }
        return [
            'ordered-choice' => $this->clauses
        ];
    }
}
