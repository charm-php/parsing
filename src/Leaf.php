<?php
namespace Charm\Parsing;

/**
 * Represents a leaf node from the parse tree.
 */
class Leaf extends AbstractNode {

    private $kind;
    private $offset;

    public function __construct($value, int $offset) {
        parent::__construct("terminal");
        $this->value = $value;
        $this->offset = $offset;
    }

    public function isLeafNode(): bool {
        return true;
    }

    public function getText(): string {
        return $this->text;
    }

    public function getOffset(): int {
        return $this->offset;
    }

    public function getLength(): int {
        return $this->length;
    }

    public function __toString() {
        return $this->text;
    }

}
