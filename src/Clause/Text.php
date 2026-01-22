<?php
namespace Charm\Parsing\Clause;

use Charm\Parsing\State;
use Charm\Parsing\Clause;
use Charm\Parsing\Signal;

class Text extends Terminal {

    private $text;
    private $length;

    public function __construct(string $text, int $modifier, ?string $name) {
        parent::__construct($modifier, $name);
        $this->text = $text;
        $this->length = strlen($text);
        if ($this->isCaseInsensitive()) {
            $this->text = mb_strtolower($this->text);
        }
    }

    public function toGrammarString(): string {
        return $this->getPredicateString().'"'.strtr($this->text, ["\"" => "\\\""]).'"'.$this->getOperatorString();
    }

    public function asString(): string {
        if (strpos($this->text, '"') === false) {
            return '"'.$this->text.'"';
        } elseif (strpos($this->text, "'") === false) {
            return "'".$this->text."'";
        } else {
            return '"'.strtr($this->text, ['"' => '""']).'"';
        }
    }

    public function interpret(State $state, array $context) {
        $offset = $state->offset;
        $source = substr($state->source, $state->offset, $this->length);
        if ($this->isCaseInsensitive()) {
            if (mb_strtolower($source) !== $this->text) {
                return Signal::MISS;
            }
        } elseif ($source !== $this->text) {
            return Signal::MISS;
        }
        $state->offset += $this->length;
        return $source;
    }

    public function getMinByteLength(): int {
        return $this->length;
    }

    public function getFirstBytes(): ?string {
        if ($this->length === 0) {
            return null;
        }
        $char = $this->text[0];
        if ($this->isCaseInsensitive()) {
            return strtolower($char) . strtoupper($char);
        }
        return $char;
    }

    /**
     * Get the literal text this clause matches.
     */
    public function getText(): string {
        return $this->text;
    }
}
