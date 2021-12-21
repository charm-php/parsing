<?php
namespace Charm\Parsing\Compiler\Traits;

trait Type {
    private $type = null;

    public function setType(?string $type): static {
        $this->type = $type;
        return $this;
    }

    public function getType(): ?string {
        return $this->type;
    }

    public function hasType(): bool {
         return $this->type !== null;
    }

    public function removeType(): static {
        return $this->setType(null);
    }

    public function withType(string $type): static {
        return (clone $this)->setType($type);
    }

    public function withoutType(): static {
        return (clone $this)->removeType();
    }

}
