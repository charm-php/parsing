<?php
namespace Charm\Parsing\Compiler\Traits;

trait Modifiers {

    private $modifiers = 0;

    public function getModifiers(): int {
        return $this->modifiers;
    }

    public function setModifiers(int $modifiers): static {
        $this->modifiers = $modifiers;
        return $this;
    }

    public function setModifier(int $mask, bool $value): static {
        if ($value) {
            $this->modifiers |= $mask;
        } else {
            $this->modifiers &= ~$mask;
        }
        return $this;
    }

    public function hasModifier(int $modifier): bool {
        return 0 !== ($this->modifiers & $modifier);
    }

    public function withoutModifiers(): static {
        return (clone $this)->setModifiers(0);
    }

    public function withModifier(int $modifier): static {
        return (clone $this)->setModifier($modifier, true);
    }

    public function withoutModifier(int $modifier): static {
        return (clone $this)->setModifier($modifier, false);
    }

}
