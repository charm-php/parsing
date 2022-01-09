<?php
namespace Charm\Parsing\Clause;

use Charm\Parsing\State;
use Charm\Parsing\GrammarError;
use Charm\Parsing\Error;
use Charm\Parsing\{
    ClauseInterface,
    GrammarInterface,
    Grammar
};

/**
 * Identifier clause (references another rule in a grammar)
 */
class Identifier extends NonTerminal {

    private $identifier;
    private $grammar;
    private $targetClause;

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
        $this->targetClause = $grammar[$this->getIdentifier()]->getClause();
    }

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

    public function interpret(State $state, array $context, array $args=null) {
        $offset = $state->offset;

        // Use args as context for the rule clause, if provided
        $result = $this->targetClause->parse($state, $args ?? []);
        return $result;
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

}
