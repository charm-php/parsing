<?php
namespace Charm\Parsing\Clause;

use Charm\Parsing\State;
use Charm\Parsing\Error;
use Charm\Parsing\{
    ClauseInterface,
    GrammarInterface,
    Grammar,
};

/**
 * Identifier clause (references another rule in a grammar)
 */
class Identifier extends NonTerminal {

    private $identifier;
    private $grammar;
    private $targetClause;
    private ?int $tailFloor = null;

    public function __construct(string $identifier, int $modifier, ?string $name) {
        parent::__construct($modifier, $name);
        $this->identifier = $identifier;
    }

    /**
     * Prepare for parsing by recursively asking child clauses to prepare.
     */
    public function prepare(Grammar $grammar) {
        if (!isset($grammar[$this->getIdentifier()])) {
            throw new Error\CustomError("Unknown identifier '".$this->getIdentifier()."' in grammar");
        }
        $this->targetRule = $grammar[$this->getIdentifier()];
        $this->targetClause = $this->targetRule->getClause();
    }

    private ?\Charm\Parsing\Rule $targetRule = null;

    public function getDescription(): string {
        $str = $this->getModifierDescription();
        $targetRule = $grammar[$this->getIdentifier()];
        if (null !== ($description = $targetRule->getDescription())) {
            return $str . $description;
        }
        return $str . "`" . $this->getIdentifier() . "`";
    }

    public function toGrammarString(): string {
        return $this->getPredicateString().$this->identifier.$this->getOperatorString();
    }

    public function asString(): string {
        return $this->identifier;
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
                return "[".$name.$this->getKind()." in ".$this->getParent()->getClauseName(false).$offset."]";
            } else {
                return $this->getParent()->getClauseName(false)."$offset -> ".$name.$this->getKind();
            }
        } elseif ($this->getRule()) {
            return $this->getRule()->getRuleName().": ".$this->asString();
        } else {
            throw new Error\InternalError("Clause has neither parent nor rule associated");
        }
    }

    public function getIdentifier(): string {
        return $this->identifier;
    }

    /**
     * Check if the referenced rule is marked as %silent
     */
    public function isSilent(): bool {
        return $this->targetClause !== null && $this->targetClause->pragma('silent') !== null;
    }

    /**
     * Mark this reference as the operand of a prefix operator in a left-recursive rule: the rule is
     * parsed with this precedence as its floor, so it only takes operators that bind tighter.
     */
    public function setTailFloor(int $floor): void {
        $this->tailFloor = $floor;
    }

    public function interpret(State $state, array $context, array $args=null) {
        $offset = $state->offset;
        if ($this->tailFloor !== null) {
            $state->extra['lrFloor'] = $this->tailFloor;
        }

        // Use args as context for the rule clause, if provided
        return $this->targetRule->parse($state, $args ?? []);
    }

    public function jsonSerialize() {
        return [ $this->getPredicateString().$this->getKind().$this->getOperatorString() => $this->getIdentifier() ];
    }

    public function __debugInfo() {
        $di = parent::__debugInfo();
        unset($di['children']);
        $di['identifier'] = $this->getIdentifier();
        return $di;
    }

    private static array $visitedMinLength = [];
    private static array $visitedFirstChars = [];
    // Caches are per referenced clause: rule names are not unique across grammars
    private static ?\WeakMap $minLengthCache = null;
    private static ?\WeakMap $firstBytesCache = null;

    public function getMinByteLength(): int {
        self::$minLengthCache ??= new \WeakMap();
        // Return cached result if available
        if ($this->targetClause !== null && isset(self::$minLengthCache[$this->targetClause])) {
            return self::$minLengthCache[$this->targetClause];
        }
        // Cycle detection for left-recursive rules
        if (isset(self::$visitedMinLength[$this->identifier])) {
            return 0; // Conservative for recursive references
        }
        if ($this->targetClause !== null) {
            self::$visitedMinLength[$this->identifier] = true;
            try {
                $result = $this->targetClause->getMinByteLength();
                self::$minLengthCache[$this->targetClause] = $result;
                return $result;
            } finally {
                unset(self::$visitedMinLength[$this->identifier]);
            }
        }
        return 0;
    }

    public function getFirstBytes(): ?string {
        self::$firstBytesCache ??= new \WeakMap();
        // Return cached result if available (use isset on the map entry's existence since null is valid)
        if ($this->targetClause !== null && self::$firstBytesCache->offsetExists($this->targetClause)) {
            return self::$firstBytesCache[$this->targetClause];
        }
        // Cycle detection for left-recursive rules
        if (isset(self::$visitedFirstChars[$this->identifier])) {
            return null; // Unknown for recursive references
        }
        if ($this->targetClause !== null) {
            self::$visitedFirstChars[$this->identifier] = true;
            try {
                $result = $this->targetClause->getFirstBytes();
                self::$firstBytesCache[$this->targetClause] = $result;
                return $result;
            } finally {
                unset(self::$visitedFirstChars[$this->identifier]);
            }
        }
        return null;
    }
}
