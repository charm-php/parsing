<?php
namespace Charm\Parsing\Clause;

use Charm\Parsing\ParserInterface;
use Charm\Parsing\Clause;

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

    public function parse(ParserInterface $parser, &$parentResult) {
        $offset = $parser->offset;
        $source = substr($parser->source, $parser->offset, $this->length);
        if ($this->isCaseInsensitive()) {
            if (mb_strtolower($source) !== $this->text) {
                $parser->traceMiss($this, $parser->offset);
                return false;
            }
        } elseif ($source !== $this->text) {
            $parser->traceMiss($this, $parser->offset);
            return false;
        }
        $parser->offset += $this->length;
        $parser->traceHit($this, $offset, $source);
        return $source;
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


    public function __debugInfo() {
        if ($this->getModifier() !== 0) {
            return [ 'modifier' => $this->getModifier(), 'text' => $this->text ];
        }
        return ['text' => $this->text];
    }
}
