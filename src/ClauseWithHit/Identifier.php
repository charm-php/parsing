<?php
namespace Charm\Parsing\Clause;

use Charm\Parsing\ParserInterface;
use Charm\Parsing\Hit;
use Charm\Parsing\GrammarError;

/**
 * Identifier clause (references another rule in a grammar)
 */
class Identifier extends NonTerminal {

    private $ruleName;

    public function __construct(string $ruleName, int $modifier, ?string $name) {
        parent::__construct($modifier, $name);
        $this->ruleName = $ruleName;
    }

    public function getRuleName(): string {
        return $this->ruleName;
    }

    public function parse(ParserInterface $parser): ?Hit {
        $parser->trace($this);
        $grammar = $parser->getGrammar();
        if (!isset($grammar[$this->getRuleName()])) {
            throw new GrammarError("Unknown identifier '".$this->getRuleName()."' in grammar");
        }
        $offset = $parser->offset;
        $rule = $grammar[$this->getRuleName()];
        $clause = $rule->getClause();

        $result = $this->processSubClause($parser, $clause);

        if ($result !== null) {
            // must wrap the result to generate the full node tree
            $result = new Hit($this, $result, $offset, $parser->offset - $offset);
            $parser->traceHit($this, $offset, is_array($result->value) ? implode("", $result->value) : (string) $result->value);
        } else {
            $parser->traceMiss($this, $offset);
        }
        return $result;
    }

    public function asString(): string {
        return $this->getRuleName();
    }

    public function debugInfo() {
        if ($this->getModifier() !== 0) {
            return [ 'modifier' => $this->getModifier(), 'identifier' => $this->getRuleName() ];
        }
        return [
            'identifier' => $this->getRuleName(),
        ];
    }
}
