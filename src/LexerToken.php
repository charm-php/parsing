<?php
namespace Charm\Parsing;

class LexerToken implements \JSONSerializable {
    private $pattern;
    public int $type;
    public string $text;
    public int $offset;

    /**
     * The length of this token. This also includes the length of any consecutive
     * tokens that were removed. To get the real string length use `strlen($token->text)`.
     */
    public int $length;

    public function __construct(LexerPattern $pattern, string $text, int $offset) {
        $this->pattern = $pattern;
        $this->type = $this->pattern->type;
        $this->text = $text;
        $this->offset = $offset;
        $this->length = strlen($text);
    }

    public function getDebugString(): string {
        $text = $this->text;
        if (mb_strlen($text) > 20) {
            $text = "`".mb_substr($text, 0, 17).'`...';
        } else {
            $text = "`$text`";
        }
        return "$text (".$this->pattern->name.")";
    }

    public function getValue() {
        return $this->pattern->process($this);
    }

    public function jsonSerialize() {
        return $this->getValue();
    }

    public function __toString() {
        return $this->text;
    }
}
