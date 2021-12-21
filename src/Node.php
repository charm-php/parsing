<?php
namespace Charm\Parsing;

use JsonSerializable, ArrayAccess;

class Node implements JsonSerializable, ArrayAccess {
    private $name;
    private array $members;

    public function __construct(...$members) {
        foreach ($members as $k => $member) {
            if (is_array($member)) {
                $members[$k] = new Node(...$member);
            }
        }
        $this->members = $members;
    }

    public function offsetSet($offset, $value) {
        if (!is_int($offset) && $offset !== null) {
            throw new InternalError("Offset ($offset) out of range");
        }
        if ($offset === null) {
            $offset = count($this->members);
        }
        if ($offset < 0) {
            throw new InternalError("Offset ($offset) is negative");
        }
        if ($offset > count($this->members)) {
            throw new InternalError("Offset ($offset) is out of range");
        }
        $this->members[$offset] = $value;
    }

    public function offsetGet($offset) {
        return $this->members[$offset] ?? null;
    }

    public function offsetExists($offset) {
        return key_exists($offset, $this->members);
    }

    public function offsetUnset($offset) {
        throw new InternalError("Can't unset offset ($offset)");
    }

    public function jsonSerialize() {
        return $this->members;
    }

    public function sx(): string {
        return "(".implode(" ", array_map(function($member) {
            if ($member instanceof Node) {
                return $node->sx();
            } elseif (is_int($member) || is_string($member) || is_float($member)) {
                return (string) $member;
            } elseif (is_bool($member)) {
                return $member ? 1 : 0;
            } else {
                throw new InternalError("Generating s-expression from type `".gettype($member)."` is not supported");
            }
        }, $this->members)).")";
    }

    public function __debugInfo() {
        return $this->members;
    }

}
