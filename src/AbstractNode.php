<?php
namespace Charm\Parsing;

/**
 * Represents a leaf node from the parse tree.
 */
abstract class AbstractNode implements NodeInterface {

    private $kind;

    public function __construct(string $kind) {
        $this->kind = $kind;
    }

    abstract public function isLeafNode(): bool;

    public function getKind(): string {
        return $this->kind;
    }

    abstract public function __toString();

}
