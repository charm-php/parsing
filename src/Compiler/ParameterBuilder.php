<?php
namespace Charm\Parsing\Compiler;

class ParameterBuilder extends AbstractBuilder {
    use Traits\Modifiers;
    use Traits\Name;
    use Traits\Type;
    use Traits\Value;

    const IS_BYREF = 1;
    const IS_NULLABLE = 2;

    public function setByReference(bool $value): static {
        return $this->setModifier(self::IS_BYREF, $value);
    }

    public function isByReference(): bool {
        return $this->hasModifier(self::IS_BYREF);
    }

    public function isNullable(): bool {
        return $this->hasModifier(self::IS_NULLABLE);
    }

    public function asByReference(): static {
        return (clone $this)->setByReference(false);
    }

    public function asNotByReference(): static {
        return (clone $this)->setByReference(false);
    }

    public function setNullable(bool $value): static {
        return $this->setModifier(self::IS_NULLABLE, $value);
    }

    public function asNullable(): static {
        return (clone $this)->setNullable(true);
    }

    public function asNotNullable(): static {
        return (clone $this)->setNullable(false);
    }
}
