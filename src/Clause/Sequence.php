<?php
namespace Charm\Parsing\Clause;

use Charm\Parsing\ParserInterface;
use Charm\Parsing\Clause;
use Charm\Parsing\Rule;
use Charm\Parsing\{
    SequenceInstruction,
    SequenceResume,
    SequenceIgnore,
    NodeInterface,
    ClauseInterface,
    GrammarInterface,
    BoxedResult
};
/**
 * Sequence: e1 e2
 */
class Sequence extends NonTerminal implements \IteratorAggregate, \Countable, \ArrayAccess {

    private $clauses = [];

    public function __clone() {
        parent::__clone();
        foreach ($this->clauses as $k => $v) {
            $this->clauses[$k] = clone $v;
            $this->clauses[$k]->setParent($this);
        }
    }

    public function parse(ParserInterface $parser, array &$context, array $args=null) {
        $parser->traceStart($this, $parser->offset);
        $offset = $parser->offset;

        $result = null;

        foreach ($this as $subClause) {
            $match = $this->processSubClause($parser, $subClause, $context);

            if ($subClause instanceof Snippet) {
                // Snippets may provide instructions that determine how this sequence continues
                if ($match instanceof SequenceIgnore) {
                    // The sequence should terminate with a successful result
                    $parser->traceEnd($this, true);
                    return true;
                } elseif ($match instanceof SequenceResume) {
                    // The sequence should resume parsing as if nothing happened
                    $match = true;
                } else {
                    // Snippets always terminate the sequence unless they instruct it to resume.
                    $parser->traceEnd($this, $match);
                    return $match;
                }
            }

            if ($match === false) {
                // The sub clause failed, so the sequence fails
                $parser->offset = $offset;
                $parser->traceEnd($this, false);
                return false;
            } elseif ($match === true) {
                // The sub clause returned a successful empty parse, so we continue to the next clause
                continue;
            }

            if ($match instanceof BoxedResult) {
                $match = $match->value;
            }

            if (null !== ($name = $subClause->getName())) {
                // the subclause is labeled, so we don't merge the arrays
                $result[$name] = $match;
            } else {
                $result[] = $match;
/*
                if ($subClause->isRepeating()) {
                    foreach ($match as $subMatch) {
                        $result[] = $subMatch;
                    }
                } else {
                    $result[] = $match;
                }
*/
            }
        }

        $result = array_values($result);

/*
        if (is_array($result)) {
            if (count($result) === 1 && is_int(key($result))) {
                $result = current($result);
            } else {
                $result = array_values($result);
            }
        }
*/
/*
        if (is_array($result) && count($result) === 1 && is_int(key($result))) {
            $result = current($result);
        } else {
        
        }
*/
/*
        $parser->traceHit($this, $offset, $result);
*/
        $parser->traceEnd($this, $result);
        return $result;
    }

    public function optimize(GrammarInterface $grammar): ClauseInterface {
        if ($this->getName() !== null || $this->getModifier() !== static::NONE) {
            return clone $this;
        }
        if (count($this->clauses) === 1) {
            $c = $this->clauses[0];
            $cl = $c->optimize($grammar);
            return $cl;
        } else {
            $c = clone $this;
            foreach ($c->clauses as $k => $clause) {
                $subClause = $clause->optimize($grammar);
                $subClause->setParent($c);
                $c->clauses[$k] = $subClause;
            }
            return $c;
        }
    }

    public function offsetGet($offset) {
        return $this->clauses[$offset] ?? null;
    }

    public function offsetSet($offset, $clause) {
        $clause->setParent($this);
        $this->clauses[$offset] = $clause;
    }

    public function offsetExists($offset) {
        return array_key_exists($offset, $this->clauses);
    }

    public function offsetUnset($offset) {
        throw new Exception("Can't unset a clause from Sequence");
    }

    public function count() {
        return count($this->clauses);
    }

    public function addClause(Clause $clause) {
        $clause->setParent($this);
        $this->clauses[] = $clause;
    }

    public function getIterator() {
        yield from $this->clauses;
    }

    public function asString(): string {
        return implode(" ", $this->clauses)."(SEQ)";
    }

    public function __debugInfo() {
        $di = parent::__debugInfo();
        $di['Sequence'] = $this->clauses;
        return $di;
    }

}
