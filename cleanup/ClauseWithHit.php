<?php
namespace Charm\Parsing;

use Charm\Parsing\Clause\{
    Sequence,
    OrderedChoice
};

/**
 * Abstract superclass of all PEG operators and terminals
 */
abstract class Clause implements ClauseInterface {

    const NONE = 0;         // no operators attached to the clause
    const OPTIONAL = 1;     // if pattern does not match, it is still a successful parse
    const REPEATING = 2;    // same pattern can be matched many times
    const AND = 4;          // the pattern does not consume input
    const NOT = 8;          // negate the result, and don't consume input
    const CASE_INSENSITIVE = 16;

    private $modifier;
    private $name = null;

    abstract protected function asString(): string;

    public function __construct(int $modifier, ?string $name) {
        $this->modifier = $modifier;
        $this->name = $name;
    }

    /**
     * Match the clause against the source at offset.
     * - If the rule does not match, the function returns `null`.
     * - If the rule matches, it returns a Match instance.
     */
    abstract public function parse(ParserInterface $parser): ?Hit;

    public function getKind(): string {
        return substr(static::class, strrpos(static::class, '\\')+1);
    }

    public final function getName(): ?string {
        return $this->name;
    }

    public final function setName(?string $name) {
        $this->name = $name;
    }

    public final function getModifier(): int {
        return $this->modifier;
    }

    public final function setModifier(int $modifier) {
        $this->modifier = $modifier;
    }

    public final function isOptional(): bool {
        return 0 < ($this->modifier & self::OPTIONAL);
    }

    public final function isRepeating(): bool {
        return 0 < ($this->modifier & self::REPEATING);
    }

    public final function hasAndPredicate(): bool {
        return 0 < ($this->modifier & self::AND);
    }

    public final function hasNotPredicate(): bool {
        return 0 < ($this->modifier & self::NOT);
    }

    public final function isKleeneStar(): bool {
        return $this->isOptional() && $this->isRepeating();
    }

    public final function isKleeneCross(): bool {
        return !$this->isOptional() && $this->isRepeating();
    }

    public final function isCaseInsensitive(): bool {
        return 0 < ($this->modifier & self::CASE_INSENSITIVE);
    }

    private $_depth = 0;
    public final function __toString() {
        if ($this->_depth++ > 0) {
            $res = '*RECURSION*';
        } else {
            $res = $this->asString();
        }
        if ($this instanceof Sequence || $this instanceof OrderedChoice) {
            $res = '( '.$res.' )';
        } else {
            $res = "`$res`";
        }
        if ($this->isKleeneStar()) {
            $res = "$res*";
        } elseif ($this->isKleeneCross()) {
            $res = "$res+";
        } elseif ($this->isOptional()) {
            $res = "$res?";
        }
        if ($this->isCaseInsensitive()) {
            $res .= "i";
        }

        if ($this->hasAndPredicate()) {
            $res = "&".$res;
        }
        if ($this->hasNotPredicate()) {
            $res = "!".$res;
        }
        if ($this->name !== null) {
            $res = $this->name.':'.$res;
        }
        $this->_depth--;
        return $res.'#'.\spl_object_id($this);
    }

}
