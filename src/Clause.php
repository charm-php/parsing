<?php
namespace Charm\Parsing;

use Charm\Parsing\Clause\{
    Sequence,
    OrderedChoice,
    NonTerminal
};
use Charm\Parsing\Error\State\CommittedError;
use JSONSerializable;

/**
 * Abstract superclass of all PEG operators and terminals
 */
abstract class Clause implements ClauseInterface, JSONSerializable {

    const NONE = 0;         // no operators attached to the clause
    const OPTIONAL = 1;     // if pattern does not match, it is still a successful parse
    const REPEATING = 2;    // same pattern can be matched many times
    const AND = 4;          // the pattern does not consume input
    const NOT = 8;          // negate the result, and don't consume input
    const CASE_INSENSITIVE = 16;
    const SPREAD = 32;      // spread array results into parent sequence

    private static $_grammarIdCounter = 0;
    protected $grammarId;   // unique number to identifiy this particular clause in the grammar
    protected $modifier;
    protected $name = null;
    protected $rule = null;
    protected $parent = null;
    private $pragmas = [];

    public string $cacheKey;

    /**
     * Prepare for parsing by recursively asking child clauses to prepare.
     */
    public function prepare(Grammar $grammar) {
    }

    /**
     * Should return this clause as it would have been written in the grammar.
     */
    public function toGrammarString(): string {
        return $this->getPredicateString().$this->asString().$this->getOperatorString();
    }

    /**
     * Return a string describing this clause in the context of the rule
     */
    public function toLocationString(): string {
        $rule = $this->getRule();

        $str = $rule->getRuleName().spl_object_id($rule);

        if (null !== ($desc = $rule->getDescription())) {
            $str .= ' '.json_encode($desc);
        }
        $str .= ' ::= ';

        if ($this->getParent() === null) {
            return $str."[[[RULE]]]";
        }

        $recurse = function(Clause $clause, int $depth=0) use (&$recurse) {
            $parts = [];
            if ($clause instanceof NonTerminal) {
                foreach ($clause as $child) {
                    foreach ($recurse($child, $depth+1) as $part) {
                        $part[] = $clause;
                        $parts[] = $part;
                    }
                }
            } else {
                $parts[] = [$depth, $clause];
            }
            return $parts;
        };

        $level = 0;

        $clauseStr = '';
        $path = [ $this ];
        $me = $this;
        while (null !== ($me = $me->getParent())) {
            $path[] = $me;
        }

        foreach ($recurse($rule->getClause()) as $index => $def) {
            while ($def[0] > $level) {
                if (in_array($def[1]->getParent(), $path)) {
                    $clauseStr .= " *(";
                } else {
                    $clauseStr .= " (";
                }
                $level++;
            }
            while ($def[0] < $level) {
                $clauseStr .= " )";
                $level--;
            }

            $part = $def[1]->toGrammarString();

            if ($def[1] === $this) {
                $part = "**$part**";
            } elseif (in_array($def[1], $path)) {
                $part = "*$part";
            }
/*
            if ($def[1] === $this) {
                $part = "[[[$part]]]";
            }
*/
            $clauseStr .= " ".$part;
        }
        while ($def[0] < $level) {
            $clauseStr .= ")";
            $level--;
        }

        return $str.$clauseStr." ".$this->getKind().spl_object_id($this);
    }


    /**
     * Should return the string needed to write this clause in an expression, without predicates and
     * modifiers.
     */
    abstract protected function asString(): string;


    /**
     * Function to evaluate the clause. Return values have the
     * following meanings:
     *
     * - `Signal::MISS` means that the clause did not match the source.
     * - `Signal::HIT` means that the clause matched the source but
     *   does not have a value.
     * - `instanceof SequenceIgnore` instructs the calling clause
     *   that it should return `Signal::HIT`.
     * - any other value (including true/false) is the match result.
     */
    public final function parse(State $parser, array $context) {
        $offset = $parser->offset;
        $doTrace = $parser->trace;
        if ($doTrace) $parser->traceStart($this, $parser->offset);

        // Handle predicates (& and !) - these don't consume input
        $isPredicate = ($this->modifier & (self::AND | self::NOT)) !== 0;

        if ($isPredicate) {
            try {
                $result = $this->interpret($parser, $context);
                // Restore offset - predicates never consume input
                $parser->offset = $offset;

                $matched = ($result !== Signal::MISS);

                // NOT predicate inverts the result
                if ($this->modifier & self::NOT) {
                    $matched = !$matched;
                }

                if ($doTrace) $parser->traceEnd($this, $matched);
                return $matched ? Signal::HIT : Signal::MISS;
            } catch (CommittedError $e) {
                $parser->offset = $offset;
                if ($doTrace) $parser->traceEnd($this, false);
                // A failure after a commit inside a predicate just means the predicate didn't match
                $matched = false;
                if ($this->modifier & self::NOT) {
                    return Signal::HIT; // NOT predicate succeeds if inner failed
                }
                return Signal::MISS;
            }
        }

        // Handle quantifiers (?, *, +) - SPREAD doesn't affect parsing, only result collection
        $isOptional = ($this->modifier & self::OPTIONAL) !== 0;
        $isRepeating = ($this->modifier & self::REPEATING) !== 0;

        // No quantifiers - simple case (SPREAD and CASE_INSENSITIVE don't affect parsing flow)
        if (!$isOptional && !$isRepeating) {
            try {
                $result = $this->interpret($parser, $context);

                if ($doTrace) $parser->traceEnd($this, $result);
                assert($result !== Signal::MISS || $parser->offset === $offset,
                    "Parser offset not restored after fail in ".$this::class);
                return $result;
            } catch (ExceptionInterface $e) {
                $parser->offset = $offset;
                if ($doTrace) $parser->traceEnd($this, false);
                throw $e;
            }
        }

        // Handle quantifiers (?, *, +)
        try {
            if ($isOptional && !$isRepeating) {
                // ? - zero or one
                $result = $this->interpret($parser, $context);
                if ($result !== Signal::MISS) {
                    if ($doTrace) $parser->traceEnd($this, $result);
                    return $result;
                }
                if ($doTrace) $parser->traceEnd($this, null);
                return null;
            } elseif ($isOptional && $isRepeating) {
                // * - zero or more
                $result = [];
                do {
                    $loopOffset = $parser->offset;
                    $partial = $this->interpret($parser, $context);
                    if ($partial !== Signal::MISS) {
                        $result[] = $partial;
                    } else {
                        if ($doTrace) $parser->traceEnd($this, $result);
                        return $result;
                    }
                } while(true);
            } elseif ($isRepeating) {
                // + - one or more
                $result = [];
                do {
                    $loopOffset = $parser->offset;
                    $partial = $this->interpret($parser, $context);
                    if ($partial !== Signal::MISS) {
                        $result[] = $partial;
                    } else {
                        // Return Signal::MISS if no matches, otherwise return the array
                        if ($result === []) {
                            $result = Signal::MISS;
                        }
                        if ($doTrace) $parser->traceEnd($this, $result);
                        return $result;
                    }
                } while(true);
            } else {
                throw new \LogicException("Unhandled modifier combination: ".$this->modifier);
            }
        } catch (ExceptionInterface $e) {
            $parser->offset = $offset;
            if ($doTrace) $parser->traceEnd($this, false);
            throw $e;
        }
    }

    /**
     * Match the clause against the source at offset.
     * - If the rule does not match, the function returns `null`.
     * - If the rule matches, it returns a Match instance.
     *
     * @param $parser The parser instance (which holds the source, offset and grammar)
     * @param $context An array which is shared among all clauses in the current rule
     */
    abstract public function interpret(State $parser, array $context);

    /**
     * Annotate if this clause is a terminal clause, or if it is a clause which may recursively
     * invoke other clauses.
     */
    abstract public function isTerminal(): bool;

    /**
     * Returns the minimum number of bytes this clause will consume if it matches.
     * Used for optimization - if remaining input is shorter, we can fail fast.
     */
    abstract public function getMinByteLength(): int;

    /**
     * Returns the set of possible first bytes this clause could match,
     * or null if unknown/any byte is possible.
     * Used for optimization - if first byte isn't in set, we can fail fast.
     */
    abstract public function getFirstBytes(): ?string;

    public function __construct(int $modifier, ?string $name) {
        $this->grammarId = self::$_grammarIdCounter++;
        $this->cacheKey = get_class($this).' '.$this->grammarId;
        $this->modifier = $modifier;
        $this->name = $name;
    }

    public function optimize(GrammarInterface $grammar): ClauseInterface {
        $res = clone $this;
        assert($res->getParent() === null, "After cloning, the parent should be NULL. Fix ".static::class."::__clone()");
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
        $this->grammarId = self::$_grammarIdCounter++;
        $this->cacheKey = $this->cacheKey.' -> '.$this->grammarId;
        $this->rule = null;
        $this->parent = null;

    }

    protected function getModifierDescription(): string {
        $str = '';
        if (0 !== ($this->modifier & self::REPEATING & self::OPTIONAL)) {
            $str = 'zero or more ';
        } elseif (0 !== ($this->modifier & self::REPEATING)) {
            $str = 'one or more ';
        } elseif (0 !== ($this->modifier & self::OPTIONAL)) {
            $str = 'an optional ';
        }
        if (0 !== ($this->modifier & self::NOT)) {
            $str = 'not matching '.$str;
        }
        return $str;
    }

    public final function addPragma(Pragma $pragma) {
        assert(!key_exists($pragma->getName(), $this->pragmas), "Pragma '".$pragma->getName()."' already declared");
        $this->pragmas[$pragma->getName()] = $pragma;
    }

    public final function pragma(string $name): ?Pragma {
        return $this->pragmas[$name] ?? null;
    }

    /**
     * Get all pragmas attached to this clause.
     * @return array<string, Pragma>
     */
    public final function getPragmas(): array {
        return $this->pragmas;
    }

    public function getDescription(): string {
        $str = $this->getModifierDescription();
        $str .= $this->asString();
        return $str;
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
                return "[#".spl_object_id($this)." ".$this->asString()." in ".$this->getParent()->getClauseName(false).$offset."]";
            } else {
                return $this->getParent()->getClauseName(false)."$offset -> ".$name.$this->getKind();
            }
        } elseif ($this->getRule()) {
            return "[#".spl_object_id($this)." ".$this->getRule()->getRuleName().": ".$name.$this->getKind()."]";
        } else {
            throw new InternalError("Clause has neither parent nor rule associated");
        }
    }

    public final function setRule(?Rule $rule) {
        if ($this->parent !== null) {
            throw new InternalError("Remove ".static::class."->parent before calling ".static::class."::setRule()");
        }
        if ($this->rule !== null && $rule !== null) {
            throw new InternalError(static::class."->rule is already set");
        }
        $this->rule = $rule;
    }

    public final function getRule(): Rule {
        if ($this->getParent()) {
            return $this->getParent()->getRule();
        } elseif ($this->rule) {
            return $this->rule;
        } else {
            throw new InternalError(static::class." is not associated with a rule");
        }
    }

    public final function setParent(?NonTerminal $parent) {
        if ($parent !== null) {
            // check for loops, just in case
            $ancestor = $parent;
            while ($ancestor) {
                if ($ancestor === $this) {
                    throw new InternalError("Loop detected when calling ".static::class."::setParent()");
                }
                $ancestor = $ancestor->getParent();
            }
        }
        if ($this->rule !== null) {
            throw new InternalError(static::class."->rule is already set");
        }
        if ($this->parent !== null && $parent !== null) {
            throw new InternalError(static::class."->parent is already set");
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

    public function getDebugName(): string {
        return $this->getRule()->getRuleName().':'.$this->getKind().'#'.spl_object_id($this);
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

    public function getOperatorString(): string {
        if ($this->isCaseInsensitive()) {
            $str = 'i';
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

    public final function hasSpread(): bool {
        return 0 < ($this->modifier & self::SPREAD);
    }

    public function jsonSerialize() {
        if ($this instanceof \Traversable) {
            return [ $this->getPredicateString().$this->getKind().$this->getOperatorString() => iterator_to_array($this) ];
        } else {
            return $this->toGrammarString();
        }
    }

    public final function __toString() {
        return $this->toGrammarString();
    }

    public function __debugInfo() {
        if ($this instanceof \Traversable) {
            $children = iterator_to_array($this);
            return [
                'kind' => $this->getKind(),
                'children' => $children,
                ];
        } else {
            return [$this->toGrammarString()];
        }
        $parentId = null;
        if ($this->getParent()) {
            $parentId = $this->getParent()->__debugInfo()['id'];
        }
        $children = null;
        if (is_iterable($this)) {
            $children = implode(" ", array_map(function($e) { return $e->getKind().'#'.$e->grammarId; }, iterator_to_array($this)));
        }
        return [
            'id' => $this->getKind()."#".$this->grammarId,
            'parent_id' => $parentId,
            'children_ids' => $children,
            'string' => $this->__toString(),
            'operator' => $this->getOperatorString(),
            'predicate' => $this->getPredicateString(),
            'ruleName' => $this->getRule()->getRuleName(),
            'ruleDescription' => $this->getRule()->getDescription(),
        ];
    }

}
