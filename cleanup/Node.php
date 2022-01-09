<?php
namespace Charm\Parsing;

/**
 * Represents an inner node (that can have children) in a parse tree.
 */
class Node extends AbstractNode implements \Countable, \IteratorAggregate, \ArrayAccess {

    private $children = [];

    public function __construct(string $kind, array $children=[]) {
        parent::__construct($kind);
        $this->children = $children;
    }

    public function __debugInfo() {
        return [ $this->getKind() => count($this->children) === 1 ? current($this->children) : $this->children ];
    }

    public function isLeafNode(): bool {
        return false;
    }

    public function addChild(NodeInterface $node) {
        $this->children[] = $node;
    }

    public function count() {
        return count($this->children);
    }

    public function getIterator() {
        yield from $this->children;
    }

    public function offsetGet($offset) {
        return $this->children[$offset] ?? null;
    }

    public function offsetSet($offset, $value) {
        if (!is_int($offset)) {
            throw new InternalError("Can't set non-integer offsets");
        }
        $this->children[$offset] = $value;
    }

    public function offsetUnset($offset) {
        unset($this->children[$offset]);
    }

    public function offsetExists($offset) {
        return array_key_exists($offset, $this->children);
    }

    public function __toString() {
        $children = [];
        foreach ($this->children as $k => $v) {
            $children[] = "$k=$v";
        }
        return $this->kind.'['.implode(" ",$children).']';
    }
}
