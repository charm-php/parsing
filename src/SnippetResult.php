<?php
namespace Charm\Parsing;

/**
 * The snippet wants the production to return a particular value
 */
class SnippetResult implements SnippetInstruction {
    public $value;

    public function __construct($value) {
        $this->value = $value;
    }
}
