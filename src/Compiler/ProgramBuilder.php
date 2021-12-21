<?php
namespace Charm\Parsing\Compiler;

class ProgramBuilder extends AbstractBuilder {

    private $classes = [];
    private $constants = [];
    private $functions = [];

    public function addClass(ClassBuilder $classBuilder): static {
        if ($this->hasClass($classBuilder)) {
            return $this;
        }
        if ($this->hasClassByName($classBuilder->getName())) {
            throw new ConsistencyError("Class with this name already exists in this program");
        }
        $this->classes[] = $classBuilder;
        return $this;
    }

    public function removeClass(ClassBuilder $classBuilder): static {
        foreach ($this->classes as $key => $cb) {
            if ($cb === $classBuilder) {
                unset($this->classes[$key]);
                $this->classes = array_values($this->classes);
                return$this;
            }
        }
        throw new ConsistencyError("Class not found");
        return $this;
    }

    public function removeClassByName(string $name): static {
        if (!$this->hasClassByName($name)) {
            throw new ConsistencyError("Class not found");
            return $this;
        }
        $c = $this->getClassByName($name);
        $this->removeClass($c);
        return $this;
    }

    public function getClassByName(string $name): ?ClassBuilder {
        foreach ($this->classes as $cb) {
            if ($cb->getName() === $name) {
                return $cb;
            }
        }
        return null;
    }

    public function hasClassByName(string $name): bool {
        return $this->getClassByName($name) !== null;
    }

    public function withClass(ClassBuilder $classBuilder): static {
        return (clone $this)->addClass($classBuilder);
    }

    public function withoutClass(ClassBuilder $classBuilder): static {
        return (clone $this)->removeClass($classBuilder);
    }


    public function addConstant(ConstantBuilder $constantBuilder): static {
        if ($this->hasConstant($constantBuilder)) {
            return $this;
        }
        if ($this->hasConstantByName($constantBuilder->getName())) {
            throw new ConsistencyError("Constant with same name already exists");
        }
        $this->constants[] = $constantBuilder;
        return $this;
    }

    public function removeConstant(ConstantBuilder $constantBuilder): static {
        foreach ($this->constants as $key => $cb) {
            if ($cb === $constantBuilder) {
                unset($this->constants[$key]);
                $this->constants = array_values($this->constants);
                return $this;
            }
        }
        throw new ConsistencyError("Constant not found");
        return $this;
    }

    public function removeConstantByName(string $name): static {
        if (!$this->hasConstantByName($name)) {
            throw new ConsistencyError("Constant not found");
        }
        $c = $this->getConstantByName($name);
        $this->removeConstant($c);
        return $this;
    }

    public function getConstantByName(string $name): ?ConstantBuilder {
        foreach ($this->constants as $cb) {
            if ($cb->getName() === $name) {
                return $cb;
            }
        }
        return null;
    }

    public function hasConstantByName(string $name): bool {
        return $this->getConstantByName($name) !== null;
    }

    public function withConstant(ConstantBuilder $constantBuilder): static {
        return (clone $this)->addConstant($constantBuilder);
    }

    public function withoutConstant(ConstantBuilder $constantBuilder): static {
        return (clone $this)->removeConstant($constantBuilder);
    }





}
