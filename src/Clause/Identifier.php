<?php
namespace Charm\Parsing\Clause;

use Charm\Parsing\ParserInterface;
use Charm\Parsing\GrammarError;
use Charm\Parsing\{
    SnippetFail,
    SnippetNothing,
    SnippetResult
};

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

    public function parse(ParserInterface $parser, &$parentResult) {
        $grammar = $parser->getGrammar();
        if (!isset($grammar[$this->getRuleName()])) {
            throw new GrammarError("Unknown identifier '".$this->getRuleName()."' in grammar");
        }
        $offset = $parser->offset;
        $rule = $grammar[$this->getRuleName()];
        $clause = $rule->getClause();

        $context = null;

        if ($clause instanceof Snippet) {
            $result = $clause->parse($parser, $context);
            if ($result instanceof SnippetFail) {
                return false;
            } elseif ($result instanceof SnippetNothing) {
                return true;
            } elseif ($result instanceof SnippetResult) {
                if (is_bool($result->value)) {
                    return $result;
                }
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
