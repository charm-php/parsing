<?php
namespace Charm\Parsing;

/**
 * Pragmas represent directives which can be applied to rules and clauses through
 * the string `%directive-name` or `%directive-name((param ("," param)*)?)`
 */
final class Pragma implements \ArrayAccess {
    private $name, $params;

    public static function create(string $name, array $params=[]) {

        switch ($name) {
            case 'assoc':
                if ($params[0] === null || !in_array((string) $params[0], ['left', 'right', 'none'])) {
                    throw new \LogicException("Pragma directive %assoc requires an argument 'left', 'right' or 'none', e.g. '%assoc=right'");
                }
                $params[0] = (string) $params[0];
                break;
            default:
                throw new \LogicException("Unknown pragma directive %$name. Currently only %assoc=left and %assoc=right is supported.");
        }
        return new self($name, $params);
    }

    private function __construct(string $name, array $params=[]) {
        $this->name = $name;
        $this->params = $params;
    }

    public function getName(): string {
        return $this->name;
    }

    public function offsetGet($offset) {
        return $this->params[$offset] ?? null;
    }

    public function offsetExists($offset) {
        return key_exists($offset, $this->params);
    }

    public function offsetUnset($offset) {
        throw new \LogicException("Can't unset params on a Pragma directive");
    }

    public function offsetSet($offset, $value) {
        throw new \LogicException("Can't set params on a Pragma directive, they are added using Pragma::addParam() or in the constructor");
    }

    public function addParam($param) {
        $this->params[] = $param;
    }

    public function getDebugString() {
        return (string) $this;
    }

    public function __toString() {
        $result = '%'.$this->name;
        if ($this->params !== []) {
            $result .= "(".implode(", ", $this->params).")";
        }
        return $result;
    }
}
