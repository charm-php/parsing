<?php
namespace Charm\Parsing;

use JSONSerializable;

/**
 * Values such as boolean true/false cause side effects when returned from
 * clauses. This class wraps these result values so that the side effect
 * is prevented.
 */
class BoxedResult implements JSONSerializable{
    public $value;

    public function __construct($value) {
        $this->value = $value;
    }

    public function jsonSerialize() {
        return $this->value;
    }
}
