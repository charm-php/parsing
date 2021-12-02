<?php
namespace Charm\Parsing;

use Charm\Parsing\Clause\OrderedChoice;
use Charm\Parsing\Clause\Sequence;

class Rule {

    const ASSOC_LEFT = -1;
    const ASSOC_NONE = 0;
    const ASSOC_RIGHT = 1;

    private $ruleName;
    private $clause;
    public $associativity;

    public function __construct(string $ruleName, Clause $clause, int $associativity=self::ASSOC_NONE) {
        $this->ruleName = $ruleName;
        $this->clause = $clause;
        $this->associativity = $associativity;
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
        $this->clause = $clause;
    }

    public function getAssociativity(): int {
        return $this->associativity;
    }

    public function setAssociativity(int $associativity) {
        $this->associativity = $associativity;
    }

    /**
     * Convert this rule to an ordered choice if needed, and append a new rule.
     */
    public function addAlternativeClause(Rule $rule) {
        if (!($this->clause instanceof OrderedChoice)) {
            $clause = new OrderedChoice(Clause::NONE);
            $clause->addClause($this->clause);
            $this->clause = $clause;
        }
        $this->clause->addClause($rule->clause);
    }

    public function __toString() {
        $result = $this->ruleName." <-";
        if ($this->clause instanceof OrderedChoice) {
            $result .= "\n";
            foreach ($this->clause as $i => $clause) {
                if ($i === 0) {
                    $result .= "       ".$clause."\n";
                } else {
                    $result .= "     / ".$clause."\n";
                }
            }
        } else {
            $result .= " ".$this->clause."\n";
        }
        return $result;
    }

    public function __debugInfo() {
        return [
            'rule' => $this->getRuleName(),
            'clause' => $this->getClause(),
        ];
    }
}
