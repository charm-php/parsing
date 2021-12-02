<?php
namespace Charm\Parsing\Clause;

use Charm\Parsing\ParserInterface;
use Charm\Parsing\Clause;
use Charm\Parsing\Rule;
use Charm\Parsing\{
    SnippetInstruction,
    SnippetNothing,
    SnippetFail,
    SnippetResult
};
/**
 * Sequence: e1 e2
 */
class Sequence extends NonTerminal implements \IteratorAggregate, \Countable, \ArrayAccess {

    private $clauses = [];

    public function setRule(Rule $rule) {
        foreach ($this->clauses as $clause) {
            $clause->setRule($rule);
        }
        parent::setRule($rule);
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

    public function parse(ParserInterface $parser, &$parentResult) {
        $offset = $parser->offset;
        $result = null;
        foreach ($this as $subClause) {

            if ($subClause instanceof Snippet) {
                $match = $subClause->parse($parser, $result);

                // snippets that belong directly in this sequence can send instructions
                if ($match instanceof SnippetFail) {
                    $parser->offset = $offset;
                    return false;
                } elseif ($match instanceof SnippetNothing) {
                    return $match;
                } elseif ($match instanceof SnippetResult) {
                    if (is_bool($match->value)) {
                        return $match;
                    } else {
                        return $match->value;
                    }
                }
                // any other result will be interpreted by the sequence parser like normal
            } else {
                $match = $this->processSubClause($parser, $subClause, $result);
                if ($match instanceof SnippetNothing) {
                    // Prevent the result from being part of this result
                    if ($result === null) {
                        $result = [];
                    }
                    continue;
                } elseif ($match instanceof SnippetFail) {
                    throw new InternalError("A SnippetFail leaked through from $subClause");
                }
            }

            if ($match === false) {
                // a rule or snippet failed the sequence so we backtrack and break
                $parser->offset = $offset;
/*
                $parser->traceMiss($this, $offset);
*/
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


        if (is_array($result) && count($result) === 1 && is_int(key($result))) {
            $result = current($result);
        }

/*
        $parser->traceHit($this, $offset, $result);
*/
        return $result;
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
