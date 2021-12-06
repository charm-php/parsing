<?php
namespace Charm\Parsing;

/**
 * The sequence should complete successfully with a return value.
 */
class SequenceResult implements SequenceInstruction {
    public $value;

    public function __construct($value) {
        $this->value = $value;
    }
}
