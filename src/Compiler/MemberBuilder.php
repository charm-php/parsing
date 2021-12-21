<?php
namespace Charm\Parsing\Compiler;

class MemberBuilder extends AbstractBuilder {
    use Traits\Modifiers;
    use Traits\Name;
    use Traits\Parameters;
    use Traits\Type;
    use Traits\Value;

    const IS_METHOD = 1;
    const IS_PROPERTY = 2;
    const IS_CONST = 4;

    const IS_PUBLIC = 1;
    const IS_PROTECTED = 2;
    const IS_PRIVATE = 4;
    const IS_STATIC = 8;
    const IS_FINAL = 16;
    const IS_ABSTRACT = 32;

    private $variant;
    private $returnType;
    private $code;

    public function __construct(int $variant, string $name, int $modifiers=0) {
        assert(in_array($variant, [ self::IS_METHOD, self::IS_PROPERTY, self::IS_CONST ]), "\$variant must be either MemberBuilder::IS_METHOD, MemberBuilder::IS_PROPERTY or MemberBuilder::IS_CONST");
        parent::__construct();

        $this->variant = $variant;
        $this->setName($name);
        $this->setModifiers($modifiers);
    }

    public function getCode(): ?CodeBuilder {
        return $this->code;
    }

    public function setCode(?CodeBuilder $code): static {
        $this->code = $code;
        return $this;
    }

    public function hasCode(): bool {
        return $this->code !== null;
    }

    public function isMethod(): bool {
        return 0 !== $this->variant & self::IS_METHOD;
    }

    public function isProperty(): bool {
        return 0 !== $this->variant & self::IS_PROPERTY;
    }

    public function isConst(): bool {
        return 0 !== $this->variant & self::IS_CONST;
    }

    public function isPublic(): bool {
        return $this->hasModifier(self::IS_PUBLIC);
    }

    public function isProtected(): bool {
        return $this->hasModifier(self::IS_PROTECTED);
    }

    public function isPrivate(): bool {
        return $this->hasModifier(self::IS_PRIVATE);
    }

    public function isAbstract(): bool {
        return $this->hasModifier(self::IS_ABSTRACT);
    }

    public function isImplemented(): bool {
        return !$this->isAbstract();
    }

    public function isStatic(): bool {
        return $this->hasModifier(self::IS_STATIC);
    }

    public function isDynamic(): bool {
        return !$this->isStatic();
    }

    public function setReturnType(?string $type): static {
        $this->returnType = $type;
        return $this;
    }

    public function getReturnType(): ?string {
        return $this->returnType;
    }

    public function hasReturnType(): bool {
        return $this->returnType !== null;
    }

    public function removeReturnType(): static {
        return $this->setReturnType(null);
    }

    public function withReturnType(?string $type): static {
        return (clone $this)->setReturnType($type);
    }

    public function withoutReturnType(): static {
        return (clone $this)->removeReturnType();
    }

    public function setPublic(): static {
        return $this->setModifiers(($this->getModifiers() & ~(self::IS_PROTECTED | self::IS_PRIVATE) | self::IS_PUBLIC) | self::IS_PUBLIC);
    }

    public function setProtected(): static {
        return $this->setModifiers(($this->getModifiers() & ~(self::IS_PROTECTED | self::IS_PRIVATE) | self::IS_PUBLIC) | self::IS_PROTECTED);
    }

    public function setPrivate(): static {
        return $this->setModifiers(($this->getModifiers() & ~(self::IS_PROTECTED | self::IS_PRIVATE) | self::IS_PUBLIC) | self::IS_PRIVATE);
    }

    public function setAbstract(bool $value): static {
        return $this->setModifier(self::IS_ABSTRACT, $value);
    }

    public function setStatic(bool $value): static {
        return $this->setModifier(self::IS_STATIC, $value);
    }


    public function asPublic(): static {
        return (clone $this)->setPublic();
    }

    public function asProtected(): static {
        return (clone $this)->setProtected();
    }

    public function asPrivate(): static {
        return (clone $this)->setPrivate();
    }

    public function asAbstract(): static {
        return (clone $this)->setAbstract(true);
    }

    public function asImplemented(): static {
        return (clone $this)->setAbstract(false);
    }

    public function asStatic(): static {
        return (clone $this)->setStatic(true);
    }

    public function asDynamic(): static {
        return (clone $this)->setStatic(false);
    }

    public function __clone() {
        $this->cloneMembers();
        $this->cloneInheritance();
    }
}
