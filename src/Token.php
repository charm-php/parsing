<?php
namespace Charm\Parsing;


class Token {

    public $text;
    public $kind;
    public $offset;
    public $name = null;
    public $isAnd = false;
    public $isNot = false;
    public $isOptional = false;
    public $isOneOrMore = false;
    public $isZeroOrMore = false;
    public $isCaseInsensitive = false;

    public function __construct(string $text, string $kind, int $offset, array $settings) {
        $this->text = $text;
        $this->kind = $kind;
        $this->offset = $offset;
        foreach ($settings as $k => $v) {
            if (property_exists($this, $k)) {
                $this->$k = $v;
            } else {
                throw new InternalError("Unknown property '$k'");
            }
        }
    }

    public function getModifier(): int {
        return
            ($this->isAnd ? Clause::AND : 0) |
            ($this->isNot ? Clause::NOT : 0) |
            ($this->isOptional ? Clause::OPTIONAL : 0) |
            ($this->isOneOrMore ? Clause::REPEATING : 0) |
            ($this->isZeroOrMore ? Clause::OPTIONAL | Clause::REPEATING : 0) |
            ($this->isCaseInsensitive ? Clause::CASE_INSENSITIVE : 0);
    }

}
