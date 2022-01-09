<?php
namespace Charm\Parsing;

/**
 * Represents a parse call during parsing.
 */
class Trace {
    public
        $ts,
        $offset,
        $endOffset,
        $clause,
        $parent,
        $children = [],
        $hasResult = false,
        $result = null;

    public function __construct($clause, $offset) {
        $this->clause = $clause;
        $this->offset = $offset;
    }

    // calculate how far this clause got
    public function getLength(): int {
        $this->assertHasResult();
        return $this->endOffset - $this->offset;
    }

    /**
     * Get the longest parse that was attempted before any backtracking.
     */
    public function getMaxLength(): int {
        $maxOffset = $this->offset;
        foreach ($this->visitPreOrder() as $node) {
            if ($node->hasResult && $node->endOffset > $maxOffset) {
                $maxOffset = $node->endOffset;
            }
        }
        return $maxOffset - $this->offset;
    }

    /**
     * Visit nodes in the order they were added
     */
    public function visitPreOrder(): \Generator {
        yield $this;
        foreach ($this->children as $child) {
            yield from $child->visitPreOrder();
        }
    }

    /**
     * Visit leaf nodes in the order they were added
     */
    public function visitPostOrder(): \Generator {
        foreach ($this->children as $child) {
            yield from $child->walk();
        }
        yield $this;
    }

    /**
     * Visit nodes level by level
     */
    public function visitRootFirst(): \Generator {
        $queue = [ $this ];
        $i = 0;
        while (key_exists($i, $queue)) {
            $current = $queue[$i];
            unset($queue[$i++]);
            yield $current;
            foreach ($current->children as $child) {
                $queue[] = $child;
            }
        }
    }

    /**
     * Visit nodes level by level starting at the
     * deepest nodes
     */
    public function visitRootLast(): \Generator {
        $buffer = [ $this ];
        $i = 0;
        while (key_exists($i, $buffer)) {
            $current = $buffer[$i++];
            // adding children in reverse since we're going to yield the buffer in reverse
            foreach (array_reverse($current->children) as $child) {
                $buffer[] = $child;
            }
        }
        while ($i > 0) {
            yield $buffer[--$i];
            unset($buffer[$i]);
        }
    }

    /**
     * Returns true if this clause was partially successful
     */
    public function isPartialSuccess(): bool {
        $this->assertHasResult();
        if ($this->result !== false) {
            return true;
        }
        foreach ($this->children as $child) {
            if ($child->isPartialSuccess()) {
                return true;
            }
        }
        return false;
    }

    private function assertHasResult() {
        if (!$this->hasResult) {
            throw new Error\InternalError("Trace does not have a result");
        }
    }

    public function __debugInfo() {
        $res = [
            'term' => $this->clause->getClauseName(),
        ];
        if ($this->hasResult) {
            $res['result'] = $this->result;
        }
        if ($this->children !== []) {
            $res['children'] = $this->children;
        }
        return $res;
    }

    public function __toString() {
        $result = $this->clause->toGrammarString();
        foreach ($this->children as $child) {
            $child = (string) $child;
            foreach (explode("\n", $child) as $line) {
                $result .= "\n  ".$line;
            }
        }
        return $result;
    }
}
