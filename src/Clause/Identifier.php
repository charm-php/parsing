<?php
namespace Charm\Parsing\Clause;

use Charm\Parsing\ParserInterface;
use Charm\Parsing\GrammarError;
use Charm\Parsing\{
    SequenceFail,
    SequenceNothing,
    SequenceResult
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

    public function parse(ParserInterface $parser, &$parentResult) {
        $parser->traceStart($this, $parser->offset);
        $grammar = $parser->getGrammar();
        if (!isset($grammar[$this->getIdentifier()])) {
            throw new GrammarError("Unknown identifier '".$this->getIdentifier()."' in grammar");
        }
        $offset = $parser->offset;
        $rule = $grammar[$this->getIdentifier()];
        $clause = $rule->getClause();

        $context = null;

        if ($clause instanceof Snippet) {
            $result = $clause->parse($parser, $context);
            if ($result instanceof SequenceFail) {
                $parser->traceEnd($this, false);
                return false;
            } elseif ($result instanceof SequenceIgnore) {
                $parser->traceEnd($this, true);
                return true;
            } elseif ($result instanceof SequenceResult) {
                if (is_bool($result->value)) {
                    $parser->traceEnd($this, $result);
                    return $result;
                }
                $parser->traceEnd($this, $result->value);
                return $result->value;
            }
        } else {
            $result = $this->processSubClause($parser, $clause, $context);
        }
/*
        if ($result !== false) {
            // must wrap the result to generate the full node tree
            $parser->traceHit($this, $offset, $result);
        } else {
            $parser->traceMiss($this, $offset);
        }
*/
        $parser->traceEnd($this, $result);
        return $result;
    }

    public function asString(): string {
        return $this->getIdentifier();
    }

    public function debugInfo() {
        if ($this->getModifier() !== 0) {
            return [ 'modifier' => $this->getModifier(), 'identifier' => $this->getIdentifier() ];
        }
        return [
            'identifier' => $this->getIdentifier(),
        ];
    }
}
