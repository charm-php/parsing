<?php
namespace Charm\Parsing\Compiler\Traits;

trait Value {

    private $hasValue = false;
    private $value = null;

    public function getValue() {
        return $this->value;
    }

    protected function setValue($value): static {
        $this->hasValue = true;
        $this->value = $value;
        return $this;
    }

    public function hasValue(): bool {
        return $this->hasValue;
    }

    public function removeValue(): static {
        $this->hasValue = false;
        $this->value = null;
        return $this;
    }

    public function withValue(?string $name): static {
        return (clone $this)->setValue($name);
    }

    public function withoutValue(): static {
        return (clone $this)->removeValue();
    }

}
