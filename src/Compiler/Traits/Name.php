<?php
namespace Charm\Parsing\Compiler\Traits;

trait Name {

    private $name = null;

    public function getName(): ?string {
        return $this->className;
    }

    protected function setName(?string $name): static {
        $this->name = $name;
        return $this;
    }

    public function hasName(): bool {
        return $this->name !== null;
    }

    public function withName(?string $name) {
        return (clone $this)->setName($name);
    }

}
