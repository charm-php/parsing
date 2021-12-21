<?php
namespace Charm\Parsing\Compiler\Traits;

use Charm\Parsing\Compiler\ParameterBuilder;

trait Parameters {

    private $hasParameters = false;
    private $parameters = [];

    public function getParameters(): ?array {
        if ($this->hasParameters) {
            return $this->parameters;
        }
        return null;
    }

    public function hasParameter(ParameterBuilder $parameter): bool {
        if (!$this->hasParameters) return false;
        foreach ($this->parameters as $ep) {
            if ($ep === $parameter) {
                return true;
            }
        }
        return false;
    }

    public function getParameterByName(string $name): ?ParameterBuilder {
        if (!$this->hasParameters) return null;
        foreach ($this->parameters as $ep) {
            if ($ep->getName() === $name) {
                return $ep;
            }
        }
        return null;
    }

    public function hasParameterByName(string $name): bool {
        return $this->getParameterByName($name) !== null;
    }

    public function addParameter(ParameterBuilder $parameter): static {
        $this->hasParameters = true;
        $this->parameters[] = $parameter;
        return $this;
    }

    public function removeParameters(): static {
        $this->hasParameters = false;
        $this->parameters = [];
        return $this;
    }

    public function withParameter(ParameterBuilder $parameter): static {
        return (clone $this)->addParameter($parameter);
    }

    public function withoutParameters(): static {
        return (clone $this)->removeParameters();
    }

}
