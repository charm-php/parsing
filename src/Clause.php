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

    protected $modifier;
    protected $name = null;
    protected $rule = null;
    protected $parent = null;
    protected $args = null; // arguments passed to the rule (for example presedence information)

    /**
     * Should return the string needed to write this clause in an expression, without predicates and
     * modifiers.
     */
    abstract protected function asString(): string;

    /**
     * Match the clause against the source at offset.
     * - If the rule does not match, the function returns `null`.
     * - If the rule matches, it returns a Match instance.
     *
     * @param $parser The parser instance (which holds the source, offset and grammar)
     * @param $context An array which is shared among all clauses in the current rule
     */
    abstract public function parse(ParserInterface $parser, array &$context);

    /**
     * Annotate if this clause is a terminal clause, or if it is a clause which may recursively
     * invoke other clauses.
     */
    abstract public function isTerminal(): bool;

    public function __construct(int $modifier, ?string $name) {
        $this->modifier = $modifier;
        $this->name = $name;
    }

    public function optimize(GrammarInterface $grammar): ClauseInterface {
        $res = clone $this;
        if ($res->getParent() !== null) {
            throw new \Exception("After cloning parent should be NULL");
        }
        return $res;
    }

    public function __serialize() {
        $res = [];
        $rc = new \ReflectionClass($this);
        foreach ($rc->getProperties() as $prop) {
            $name = $prop->getName();
            $prop->setAccessible(true);
            $res[$name] = $prop->getValue($this);
            $prop->setAccessible(false);
        }
        return $res;
    }

    public function __unserialize($data) {
        $rc = new \ReflectionClass($this);
        foreach ($rc->getProperties() as $prop) {
            $name = $prop->getName();
            if (array_key_exists($name, $data)) {
                $prop->setAccessible(true);
                $prop->setValue($this, $data[$name]);
                $prop->setAccessible(false);
            }
        }
    }

    public function __clone() {
        $this->rule = null;
        $this->parent = null;
    }

    public function getClauseName(bool $_leaf=true): string {
        $name = $this->getName() ? $this->getName() . ":" : "";
        if ($this->getParent()) {
            $offset = '';
            if ($this->getParent() instanceof \Traversable) {
                foreach ($this->getParent() as $index => $subClause) {
                    if ($subClause === $this) {
                        $offset = '['.$index.']';
                    }
                }
            }
            if ($_leaf) {
                return "[".$this->asString()." in ".$this->getParent()->getClauseName(false).$offset."]";
            } else {
                return $this->getParent()->getClauseName(false)."$offset -> ".$name.$this->getKind();
            }
        } elseif ($this->getRule()) {
            return "[".$this->getRule()->getRuleName().": ".$name.$this->getKind()."]";
        } else {
            throw new InternalError("Clause has neither parent nor rule associated");
        }
    }

    public function setRule(?Rule $rule) {
        if ($this->parent !== null) {
            throw new InternalError("A clause can't have both parent and rule");
        }
        if ($this->rule !== null && $rule !== null) {
            throw new InternalError("A clause can't be associated with two rules");
        }
        $this->rule = $rule;
    }

    public function getRule(): Rule {
        if ($this->getParent()) {
            return $this->getParent()->getRule();
        } elseif ($this->rule) {
            return $this->rule;
        } else {
            throw new InternalError("Clause $this has neither rule nor parent associated");
        }
    }

    public function setParent(?Clause $parent) {
        if ($this->rule !== null) {
            throw new InternalError("Clause `$this` can't have both rule and parent. Rule was already `".$this->rule."` when setting parent `$parent`");
        }
        if ($this->parent !== null && $parent !== null) {
            throw new InternalError("A clause can't have two parents (already have ".$this->parent.")");
        }
        $this->parent = $parent;
    }

    public function getParent(): ?Clause {
        return $this->parent;
    }

    /**
     * Returns the class name without namespace
     */
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

    public function getModifierString(): string {
        if ($this->isCaseInsensitive()) {
            $str = 'i ';
        } else {
            $str = '';
        }
        if ($this->isOptional() && $this->isRepeating()) {
            return $str.'*';
        } elseif ($this->isOptional()) {
            return $str.'?';
        } elseif ($this->isRepeating()) {
            return $str.'+';
        } else {
            return $str;
        }
    }

    public function getPredicateString(): string {
        if ($this->hasAndPredicate() && $this->hasNotPredicate()) {
            return '!&';
        } elseif ($this->hasAndPredicate()) {
            return '&';
        } elseif ($this->hasNotPredicate()) {
            return '!';
        } else {
            return '';
        }
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
            if ($this->args !== null) {
                $res .= '('.substr(json_encode($this->args), 1, -1).')';
            }
        }
        if ($this instanceof Sequence || $this instanceof OrderedChoice) {
            $res = '( '.$res.' )';
        } else {
            $res = "`$res`";
        }
        $res = $this->getPredicateString().$res.$this->getModifierString();
        $this->_depth--;
        return $res;
    }

    public function __debugInfo() {
        return [
            'kind' => $this->getKind(),
            'modifier' => $this->getModifierString(),
            'predicate' => $this->getPredicateString(),
        ];
    }

}
