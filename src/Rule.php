<?php
namespace Charm\Parsing;

use Charm\Parsing\Clause\OrderedChoice;
use Charm\Parsing\Clause\Sequence;
use Charm\Parsing\Clause\Snippet;

class Rule implements \JSONSerializable {

    const ASSOC_LEFT = -1;
    const ASSOC_NONE = 0;
    const ASSOC_RIGHT = 1;

    private $ruleName;
    private $clause;
    private $description;
    public $associativity;

    public function __construct(string $ruleName, Clause $clause, int $associativity=self::ASSOC_NONE) {
        $this->ruleName = $ruleName;
        $this->clause = $clause;
        $this->clause->setRule($this);
        $this->associativity = $associativity;
    }

    public function prepare(Grammar $grammar) {
        $this->grown = $grammar->isInLeftRecursiveCycle($this->ruleName);
        $this->clause->prepare($grammar);
    }

    /** True when the rule is in an indirect left-recursive cycle (Grammar::isInLeftRecursiveCycle()) */
    private bool $grown = false;

    /**
     * Parse this rule at the state's offset. A rule in an indirect left-recursive cycle is seed-grown:
     * while it is being parsed at an offset, a nested call at the same offset returns the best result so
     * far (initially a miss, so the recursive alternatives fail and a non-recursive one provides the
     * seed), and the rule is re-parsed until the match stops getting longer.
     */
    public function parse(State $state, array $context) {
        // snippets can read where the rule started (`$_offset`, `$_line`, `$_column`)
        $previousStart = $state->extra['ruleStart'] ?? null;
        $state->extra['ruleStart'] = $state->offset;
        try {
            return $this->parseRule($state, $context);
        } finally {
            $state->extra['ruleStart'] = $previousStart;
        }
    }

    private function parseRule(State $state, array $context) {
        if (!$this->grown) {
            return $this->clause->parse($state, $context);
        }
        $offset = $state->offset;
        $seeds = &$state->extra['lrSeeds'];
        if (isset($seeds[$offset][$this->ruleName])) {
            [$value, $state->offset] = $seeds[$offset][$this->ruleName];
            return $value;
        }
        $seed = [Signal::MISS, $offset];
        $seeds[$offset][$this->ruleName] = $seed;
        try {
            for (;;) {
                $state->offset = $offset;
                $value = $this->clause->parse($state, $context);
                if ($value === Signal::MISS || $state->offset <= $seed[1]) {
                    break;
                }
                $seeds[$offset][$this->ruleName] = $seed = [$value, $state->offset];
            }
        } finally {
            unset($seeds[$offset][$this->ruleName]);
        }
        $state->offset = $seed[1];
        return $seed[0];
    }

    public function getRuleName(): string {
        return $this->ruleName;
    }

    public function setName(string $ruleName) {
        $this->ruleName = $ruleName;
    }

    public function getClause(): Clause {
        return $this->clause;
    }

    public function setClause(Clause $clause) {
        $clause->setRule($this);
        $this->clause = $clause;
    }

    public function getAssociativity(): int {
        return $this->associativity;
    }

    public function setAssociativity(int $associativity) {
        $this->associativity = $associativity;
    }

    public function getDescription(): ?string {
        return $this->description;
    }

    public function setDescription(?string $description) {
        $this->description = $description;
    }

    public function __toString() {
        $result = $this->ruleName." ".($this->description !== null ? json_encode($this->description)." " : "")."::=";
        if ($this->clause instanceof OrderedChoice) {
            $strlen = 0;
            foreach ($this->clause as $i => $clause) {
                $strlen += mb_strlen($clause);
            }
            if ($strlen > 30) {
                foreach ($this->clause as $i => $clause) {
                    if ($i === 0) {
                        $result .= "\n    ".$clause;
                    } else {
                        $result .= "\n  | ".$clause;
                    }
                }
            } else {
                foreach ($this->clause as $i => $clause) {
                    if ($i === 0) {
                        $result .= " ".$clause;
                    } else {
                        $result .= " | ".$clause;
                    }
                }
            }
        } else {
            $result .= "\n    ".$this->clause;
        }
        return $result."\n";
    }

    public function __debugInfo() {
        return [
            'ruleName' => $this->getRuleName(),
            'clause' => $this->getClause(),
            ];
    }

    public function jsonSerialize() {
        return [
            "name" => $this->getRuleName(),
            "description" => $this->getDescription(),
            "clause" => $this->getClause(),
        ];
    }
}
