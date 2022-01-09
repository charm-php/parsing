<?php
namespace Charm\Parsing\Clause;

use Charm\Parsing\Clause;
use Charm\Parsing\State;
use Charm\Parsing\{
    Grammar,
    GrammarError,
    InternalError
};
use Charm\Parsing\Clause\{
    Commit
};

abstract class NonTerminal extends Clause implements \ArrayAccess, \IteratorAggregate, \Countable {

    private $children = [];

    public function prepare(Grammar $grammar) {
        foreach ($this as $subClause) {
            $subClause->prepare($grammar);
        }
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
        assert($child instanceof Clause, "Children must be an instance of Clause, '".$child::class."' received");
        if ($offset === null) {
            $offset = count($this->children);
        }
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

    public final function isTerminal(): bool {
        return false;
    }

}
