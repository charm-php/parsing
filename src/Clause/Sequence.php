<?php
namespace Charm\Parsing\Clause;

use Charm\Parsing\ParserInterface;
use Charm\Parsing\Clause;
use Charm\Parsing\Rule;
use Charm\Parsing\{
    SequenceInstruction,
    SequenceIgnore,
    SequenceFail,
    SequenceResult,
    NodeInterface
};
/**
 * Sequence: e1 e2
 */
class Sequence extends NonTerminal implements \IteratorAggregate, \Countable, \ArrayAccess {

    private $clauses = [];

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

    public function parse(ParserInterface $parser, &$parentResult) {
        $parser->traceStart($this, $parser->offset);
        $offset = $parser->offset;
        $result = null;
        foreach ($this as $subClause) {

            if ($subClause instanceof Snippet) {
                $match = $subClause->parse($parser, $result);

                // snippets that belong directly in this sequence can send instructions
                if ($match instanceof SequenceFail) {
                    $parser->offset = $offset;
                    $parser->traceEnd($this, false);
                    return false;
                } elseif ($match instanceof SequenceIgnore) {
                    $parser->traceEnd($this, $match);
                    return $match;
                } elseif ($match instanceof SequenceResult) {
                    if (is_bool($match->value)) {
                        $parser->traceEnd($this, $match);
                        return $match;
                    } else {
                        $parser->traceEnd($this, $match->value);
                        return $match->value;
                    }
                }
                // any other result will be interpreted by the sequence parser like normal
            } else {
                $match = $this->processSubClause($parser, $subClause, $result);
                if ($match instanceof SequenceIgnore) {
                    // Prevent the result from being part of this result
                    if ($result === null) {
                        $result = [];
                    }
                    continue;
                } elseif ($match instanceof SequenceFail) {
                    throw new InternalError("A SequenceFail leaked through from $subClause");
                }
            }

            if ($match === false) {
                // a rule or snippet failed the sequence so we backtrack and break
                $parser->offset = $offset;
/*
                $parser->traceMiss($this, $offset);
*/
                $parser->traceEnd($this, false);
                return false;
            } elseif ($match === true) {
                // this is a non-capturing match (use new Result(true) to actually return true)
                continue;
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


        if (is_array($result)) {
            if (count($result) === 1 && is_int(key($result))) {
                $result = current($result);
            } else {
                $result = array_values($result);
            }
        }
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
