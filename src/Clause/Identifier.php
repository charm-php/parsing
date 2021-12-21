<?php
namespace Charm\Parsing\Clause;

use Charm\Parsing\ParserInterface;
use Charm\Parsing\GrammarError;
use Charm\Parsing\{
    ClauseInterface,
    GrammarInterface
};

/**
 * Identifier clause (references another rule in a grammar)
 */
class Identifier extends NonTerminal {

    private $identifier;

    public function __construct(string $identifier, int $modifier, ?string $name) {
        parent::__construct($modifier, $name);
        $this->identifier = $identifier;
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
            throw new InternalError("Clause has neither parent nor rule associated");
        }
    }

    public function getIdentifier(): string {
        return $this->identifier;
    }

    public function parse(ParserInterface $parser, array &$context, array $args=null) {
        $parser->traceStart($this, $parser->offset);
        $grammar = $parser->getGrammar();
        if (!isset($grammar[$this->getIdentifier()])) {
            throw new GrammarError("Unknown identifier '".$this->getIdentifier()."' in grammar");
        }
        $offset = $parser->offset;
        $rule = $grammar[$this->getIdentifier()];
        $clause = $rule->getClause();

        // Use args as context for the rule clause, if provided
        $subContext = $args ?? [];
        $result = $this->processSubClause($parser, $clause, $subContext);
        $parser->traceEnd($this, $result);
        return $result;
    }

}
