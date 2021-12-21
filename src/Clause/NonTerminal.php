<?php
namespace Charm\Parsing\Clause;

use Charm\Parsing\Clause;
use Charm\Parsing\ParserInterface;
use Charm\Parsing\{
    GrammarError,
    InternalError
};
use Charm\Parsing\Clause\{
    Commit
};

abstract class NonTerminal extends Clause implements \ArrayAccess, \IteratorAggregate, \Countable {

    private $children = [];

    protected function filterSubClause(int $offset, Clause $clause): Clause {
        return $clause;
    }

    public function __clone() {
        parent::__clone();
        foreach ($this->children as $i => $child) {
            $this[$i] = clone $child;
        }
    }

    public final function count() {
        return count($this->children);
    }

    public final function getIterator() {
        yield from $this->children;
    }

    public final function offsetGet($offset) {
        return $this->children[$offset] ?? null;
    }

    public final function offsetSet($offset, $child) {
        if (!$child instanceof Clause) {
            throw new InternalError("Children must be instance of Clause");
        }
        if ($offset === null) {
            $offset = count($this->children);
        }
        $child = $this->filterSubClause($offset, $child);
        if ($child->getParent() !== null) {
            throw new InternalError("Children can't already have a parent");
        }
        if (is_int($offset) && $offset >= 0 && $offset <= count($this->children)) {
            if (($this->children[$offset] ?? null) instanceof Clause) {
                $this->children[$offset]->setParent(null);
            }
            $this->children[$offset] = $child;
        } else {
            throw new InternalError("Illegal offset '$offset' for child node");
        }
        $child->setParent($this);
    }

    public final function offsetExists($offset) {
        return array_key_exists($offset, $this->children);
    }

    public final function offsetUnset($offset) {
        if ($offset !== count($this->children)-1 || !isset($this[$offset])) {
            throw new InternalError("Can't unset child at offset '$offset'");
        }
        $this->children[$offset]->setParent(null);
        $c = count($this->children);
        for ($i = $offset; $i < $c-1; $i++) {
            $this->children[$i] = $this->children[$i+1];
        }
        unset($this->children[$c-1]);
    }


/*
    private $_hasLeftMostResult = false;
    private $_leftMostResult = null;
    private $_ruleName = null;
*/
/*    protected function processLeftRecursiveSubClause(ParserInterface $parser, Clause $subClause, &$parentResult, $leftMostResult, $ruleName) {
        $hasLeftMostResult = $this->_hasLeftMostResult;
        $leftMostResult = $this->_leftMostResult;
        $ruleName = $this->_ruleName;
        $this->_hasLeftMostResult = true;
        $this->_leftMostResult = $leftMostResult;
        $this->_ruleName = $ruleName;
        $result = $this->_processSubClause($parser, $subClause, $parentResult);
        $this->_leftMostResult = $leftMostResult;
        $this->_hasLeftMostResult = $hasLeftMostResult;
        $this->_ruleName = $ruleName;
        return $result;
    }*/

    /**
     * Handles processing of modifiers and labels from sub clauses.
     */
/*    protected function processSubClause(ParserInterface $parser, Clause $subClause, &$parentResult) {
        $hasLeftMostResult = $this->_hasLeftMostResult;
        $leftMostResult = $this->_leftMostResult;
        $ruleName = $this->_leftMostResult;
        $this->_hasLeftMostResult = false;
        $this->_leftMostResult = null;
        $this->_ruleName = null;
        $result = $this->_processSubClause($parser, $subClause, $parentResult);
        $this->_leftMostResult = $leftMostResult;
        $this->_hasLeftMostResult = $hasLeftMostResult;
        $this->_ruleName = $ruleName;
        return $result;
    }*/

    protected function processSubClause(ParserInterface $parser, Clause $subClause, array &$context, array $args=null) {

        if ($subClause instanceof Commit) {
            throw new InternalError("Commit not processed in class ".get_class($this));
        }

        if ($subClause instanceof Snippet) {
            if ($subClause->getModifier() !== static::NONE) {
                throw new GrammarError("Snippets don't support operators '".$subClause->getOperatorString()."'");
            }
            return $subClause->parse($parser, $context, $args);
        }

        $offset = $parser->offset;

        $result = null;

        if ($subClause->isRepeating()) {
            /**
             * The reptition expression e* succeeds for zero or more matches, and the
             * repetition expression e+ succeeds for one or more matches.
             */
            while (true) {
                $subResult = $subClause->parse($parser, $context, $args);

                if ($subResult === false) {
                    $subResult = false;
                    break;
                }
                if ($subResult === true) {
                    if ($result === null) {
                        // make result into an array because it no longer has no matches
                        $result = [];
                    }
                } else {
                    $result[] = $subResult;
                }
            }

            if ($result === null) {
                if ($subClause->isOptional()) {
                    $result = [];
                } else {
                    $result = false;
                }
            }
        } else {
            $result = $subClause->parse($parser, $context, $args);
            if ($result === false && $subClause->isOptional()) {
                $result = null;
            }
        }

        if ($subClause->hasAndPredicate()) {
            /**
             * The and-predicate expression &e invokes the sub-expression e, and then succeeds
             * if e succeeds and fails if e fails, but in either case never consumes any input.
             */
            $parser->offset = $offset;
            if ($result !== false) {
                // all non-failed results are simply true
                $result = true;
            }
        }

        if ($subClause->hasNotPredicate()) {
            /**
             * The not-predicate expression !e succeeds if e fails and fails if e succeeds,
             * consuming no input in either case.
             */
            $parser->offset = $offset;
            if ($result === false) {
                // only failed results are true
                $result = true;
            } else {
                $result = false;
            }
        }

        return $result;
    }

    public final function isTerminal(): bool {
        return false;
    }

}
