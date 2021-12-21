<?php
namespace Charm\Parsing;

class LexerToken {
    public $type;
    public $text;
    public $offset;
    public $length;
    private $processor;

    public function __construct(string $text, int $type, int $offset, callable $processor = null) {
        $this->text = $text;
        $this->type = $type;
        $this->offset = $offset;
        $this->length = strlen($text);
        $this->processor = $processor;
    }

    public function value() {
        if ($this->processor !== null) {
            return ($this->processor)($this);
        }
        return $this->text;
    }

    public function jsonSerialize() {
        return $this->value;
    }


    public function __toString() {
        return $this->text;
    }
}
