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
        $this->clause->prepare($grammar);
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
