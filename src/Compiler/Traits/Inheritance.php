<?php
namespace Charm\Parsing\Compiler;

trait Inheritance {
    private $parent = null;

    public function isChildOf(object $ancestor): bool {
        $current = $this;
        while (null !== ($current = $current->getParent())) {
            if ($current === $ancestor) {
                return true;
            }
        }
        return false;
    }

    public function isParentOf(object $child): bool {
        return $child->isChildOf($this);
    }

    public function getParent(): ?static {
        return $this->parent;
    }

    public function setParent(?object $parent): static {
        $this->parent = $parent;
        return $this;
    }

    public function hasParent(): bool {
        return $this->parent !== null;
    }

    public function withParent(?object $parent): static {
        return (clone $this)->setParent($parent);
    }

    public function withoutParent(): static {
        return (clone $this)->setParent(null);
    }
}
