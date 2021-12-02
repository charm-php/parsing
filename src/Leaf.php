<?php
namespace Charm\Parsing;

/**
 * Represents a leaf node from the parse tree.
 */
class Leaf extends AbstractNode {

    private $kind;
    private $offset;
    private $length;

    public function __construct(string $kind, string $text, int $offset, int $length=null) {
        parent::__construct($kind);
        $this->text = $text;
        $this->offset = $offset;
        $this->length = $length ?? strlen($text);
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
