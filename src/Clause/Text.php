<?php
namespace Charm\Parsing\Clause;

use Charm\Parsing\State;
use Charm\Parsing\Clause;
use Charm\Parsing\Signal;

class Text extends Terminal {

    private $text;
    private $length;
    /** For a non-ASCII case-insensitive literal: the regex that matches it, anchored with \G */
    private ?string $unicodePattern = null;

    public function __construct(string $text, int $modifier, ?string $name) {
        parent::__construct($modifier, $name);
        $this->text = $text;
        $this->length = strlen($text);
        if ($this->isCaseInsensitive()) {
            // ASCII literals fold ASCII letters only; others use PCRE's Unicode folding (as compiled parsers do)
            if (preg_match('/^[\x00-\x7F]*$/', $text)) {
                $this->text = strtolower($text);
            } else {
                $this->text = mb_strtolower($text);
                $this->unicodePattern = '/\G' . preg_quote($this->text, '/') . '/iu';
                $this->length = mb_strlen($this->text); // fewest bytes it can match
            }
        }
    }

    public function toGrammarString(): string {
        return $this->getPredicateString().'"'.strtr($this->text, ["\"" => "\\\""]).'"'.$this->getOperatorString();
    }

    public function asString(): string {
        $i = $this->isCaseInsensitive() ? 'i' : '';
        if (strpos($this->text, '"') === false) {
            return '"'.$this->text.'"'.$i;
        } elseif (strpos($this->text, "'") === false) {
            return "'".$this->text."'".$i;
        } else {
            return '"'.strtr($this->text, ['"' => '""']).'"'.$i;
        }
    }

    public function interpret(State $state, array $context) {
        if ($this->unicodePattern !== null) {
            if (!preg_match($this->unicodePattern, $state->source, $m, 0, $state->offset)) {
                return Signal::MISS;
            }
            $state->offset += strlen($m[0]);
            return $m[0];
        }
        $source = substr($state->source, $state->offset, $this->length);
        if ($this->isCaseInsensitive()) {
            if (strtolower($source) !== $this->text) {
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
        if ($this->unicodePattern !== null) {
            return null; // upper and lower case may start with different bytes
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
