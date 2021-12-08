<?php
namespace Charm\Parsing\Clause;

use Charm\Parsing\ParserInterface;
use Charm\Parsing\Clause;

class Text extends Terminal implements RegexInterface {

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

    public function getRegex(): string {
        return preg_quote($this->text, "/");
    }

    public function getRegexPriority(): int {
        return -mb_strlen($this->text);
    }

    public function parse(ParserInterface $parser, array &$context, array $args=null) {
        $parser->traceStart($this, $parser->offset);
        $offset = $parser->offset;
        $source = substr($parser->source, $parser->offset, $this->length);
        if ($this->isCaseInsensitive()) {
            if (mb_strtolower($source) !== $this->text) {
                $parser->traceEnd($this, false);
                return false;
            }
        } elseif ($source !== $this->text) {
            $parser->traceEnd($this, false);
            return false;
        }
        $parser->offset += $this->length;
        $parser->traceEnd($this, $source);
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
}
