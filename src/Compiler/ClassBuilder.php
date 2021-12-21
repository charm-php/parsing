<?php
namespace Charm\Parsing\Compiler;

class ClassBuilder extends AbstractBuilder {
    use Traits\Name;
    use Traits\Members;
    use Traits\Modifiers;
    use Traits\Inheritance;

    const IS_CLASS = 0;
    const IS_TRAIT = 1;
    const IS_ABSTRACT = 2;

    private $interfaceNames = [];
    private $traits = [];

    public function __construct(int $type, ?string $name) {
        $this->setModifier($type);
        $this->setName($name);
    }

    public function validate() {
        foreach ($this->traits as $usedTrait) {
            if (!$usedTrait->isTrait()) {
                throw new ValidationError("$this uses $usedTrait which is not a trait");
            }
            $usedTrait->validate();
        }
        foreach ($this->getMembers() as $member) {
            $member->validate();
        }
        $this->getParent()->validate();
    }

    public function withTrait(ClassBuilder $traitBuilder): static {
        return (clone $this)->addTrait($traitBuilder);
    }

    public function addTrait(ClassBuilder $traitBuilder): static {
        foreach ($this->traits as $t) {
            if ($t === $traitBuilder) {
                return $this;
            }
        }
        $this->traits[] = $traitBuilder;
        return $this;
    }

    public function withInterface(string $name) {
        return (clone $this)->addInterface($name);
    }

    public function addInterface(string $name) {
        if (!in_array($name, $this->interfaceNames)) {
            $this->interfaceNames[] = $name;
        }
        return $this;
    }

    public function withoutInterface(string $name) {
        return (clone $this)->removeInterface($name);
    }

    public function removeInterface(string $name) {
        foreach ($this->interfaceNames as $key => $value) {
            if ($value === $name) {
                unset($this->interfaceNames[$key]);
                break;
            }
        }
        $this->interfaceNames = array_values($this->interfaceNames);
        return $this;
    }

    public function hasInterface(string $name): bool {
        if (in_array($name, $this->interfaceNames)) {
            return true;
        }
        if (null !== ($parent = $this->getParent())) {
            return $parent->hasInterface($name);
        }
        return false;
    }

    public function getInterfaceNames(): array {
        return $this->interfaceNames;
    }

    public function getAllInterfaceNames(): array {
        if ($this->getParent()) {
            $interfaceNames = array_merge($this->getInterfaceNames(), $this->getParent()->getInterfaceNames());
            return array_unique($interfaceNames);
        } else {
            return $this->getInterfaceNames();
        }
    }

    public function isAnonymous(): bool {
        return $this->getName() === null;
    }

    public function create(?string $name): static {
        return new static($name);
    }

    public function isAbstract(): bool {
        return $this->hasModifier(self::ABSTRACT);
    }

    public function isConcrete(): bool {
        return !$this->isAbstract();
    }

    public function isTrait(): bool {
        return $this->hasModifier(self::IS_TRAIT);
    }

    public function isClass(): bool {
        return !$this->isTrait();
    }

    public function asAbstract(): static {
        return $this->withModifier(self::ABSTRACT);
    }

    public function asConcrete(): static {
        return $this->withoutModifier(self::ABSTRACT);
    }

    public function __clone() {
        $this->cloneMembers();
        $this->cloneInheritance();
        $this->interfaceNames = array_values($this->interfaceNames);
    }

    public function __toString() {
        $parts = [];
        if ($this->isAbstract()) {
            $parts[] = 'abstract';
        }
        if ($this->isTrait()) {
            $parts[] = 'trait';
        } elseif ($this->isClass()) {
            $parts[] = 'class';
        }
        if ($this->getParent()) {
            $parts[] = 'extends '.$this->getParent()->getName();
        }
        if ([] !== ($interfaceNames = $this->getInterfaceNames())) {
            $parts[] = 'implements '.implode(",", $interfaceNames);
        }
        return implode(" ", $parts);
    }

}
