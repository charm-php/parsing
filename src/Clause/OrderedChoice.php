<?php
namespace Charm\Parsing\Clause;

use Charm\Parsing\GrammarInterface;
use Charm\Parsing\ParserInterface;
use Charm\Parsing\Grammar;
use Charm\Parsing\Clause;
use Charm\Parsing\Rule;
use Charm\Parsing\ClauseInterface;
use Charm\Parsing\{
    GrammarError,
    InternalError
};

/**
 * OrderedChoice: e1 / e2 / e3
 */
class OrderedChoice extends NonTerminal implements \IteratorAggregate, \Countable, \ArrayAccess {

    private $clauses = [];

    public function __clone() {
        parent::__clone();
        foreach ($this->clauses as $k => $v) {
            $this->clauses[$k] = clone $v;
            $this->clauses[$k]->setParent($this);
        }
    }

    public function optimize(GrammarInterface $grammar): ClauseInterface {
        if ($this->getName() !== null || $this->getModifier() !== static::NONE) {
            return clone $this;
        }
        $c = clone $this;
        foreach ($c->clauses as $k => $clause) {
            $subClause = $clause->optimize($grammar);
            if ($subClause->getParent() !== null) {
                throw new InternalError(get_class($clause)."::optimize() returned a clause which still has a parent");
            }
            $subClause->setParent($this);
            $c->clauses[$k] = $subClause;
        }
        return $c;
    }

    // ?bool - null means undetermined
    private $leftRecursive = null;
    private $leftRecursionClause = null;

    public function isLeftRecursive(): bool {
        $this->processLeftRecursiveClauses();
        return $this->leftRecursive;
    }

    public function getNonRecursive() {
        if (!$this->isLeftRecursive()) {
            throw new InternalError("Not a recursive clause, don't do this");
        }
        return $this->leftRecursionClause;
    }

    private function processLeftRecursiveClauses() {
        if ($this->leftRecursive !== null) {
            return;
        }

        // Trying to imitate antlr4 processing of left recursive rules https://github.com/antlr/antlr4/blob/master/doc/left-recursion.md
        $clauses = [];
        $this->leftRecursive = false;
        if ($this->getParent() === null && ($rule = $this->getRule())) {
            // the rule must have at least one clause that is not left-recursive, so we check for that
            $hasNonLeftRecursives = false;
            $ruleName = $rule->getRuleName();
            foreach ($this->clauses as $clause) {
                if ($clause instanceof Sequence && $clause[0] instanceof Identifier && $clause[0]->getIdentifier() === $ruleName) {
                    $this->leftRecursive = true;
                    $clauses[] = [ 'left-recursive', $clause ];
                } else {
                    $hasNonLeftRecursives = true;
                    $kind = 'non-recursive';
                    foreach ($clause as $subClause) {
                        if ($subClause instanceof Identifier && $subClause->getIdentifier() === $ruleName) {
                            $kind = 'right-recursive';
                            break;
                        }
                    }
                    $clauses[] = [ $kind, $clause ];
                }
            }
            if (!$hasNonLeftRecursives) {
                throw new GrammarError("Rule `$ruleName` contains only left-recursive clauses and can't be processed");
            }
        }
        if (!$this->leftRecursive) {
            return;
        }

        // This OrderedChoice(1) will be rewritten to a Sequence = OrderedChoice(1) OrderedChoice(2)
        // OrderedChoice(2) will be built similarly to this:
        /*
            expr[int pr] : id
                           ( {4 >= $pr}? '*' expr[5]
                           | {3 >= $pr}? '+' expr[4]
                           | {2 >= $pr}? '(' expr[0] ')'
                           )*
                         ;
        */

        $sequence = new Sequence(static::NONE, null);
        $headClauseCandidates = [];
        foreach ($clauses as list($recursiveness, $clause)) {
            if ($recursiveness !== 'left-recursive') {
                $headClauseCandidates[] = $clause;
            }
        }
        if (count($headClauseCandidates) === 1) {
            $headClause = clone $headClauseCandidates[0];
        } else {
            $headClause = new OrderedChoice(static::NONE, null);
            foreach ($headClauseCandidates as $clause) {
                $headClause->addClause($clause);
            }
        }

        $tailClauseCandidates = [];
        // rewrite and add all left recursive rules
        $presedence = count($this);
        foreach ($clauses as list($recursiveness, $clause)) {
            if ($recursiveness === 'left-recursive') {
                $newClause = clone $clause;
//                    if (!isset(\$pr)) error("Expected '\$pr' argument");
                $newClause[0] = new Snippet(<<<CODE
                    return $presedence >= \$pr ? resume() : false;
                    CODE, static::NONE, null);

                foreach ($newClause as $k => $subClause) {
                    if ($subClause instanceof Identifier && $subClause->getIdentifier() === $ruleName) {
                        $newClause[$k] = $newClause[$k]->withArgs(['pr' => $presedence + 1]);
                        // Not sure if we should break here. I believe we want to rewrite all (for example in the case of Expr = Expr "?" Expr ":" Expr)
                    }
                }

                $presedence--;
                $tailClauseCandidates[] = $newClause;
            }
        }

        if (count($tailClauseCandidates) > 1) {
            $tailClause = new OrderedChoice(static::REPEATING | static::OPTIONAL, null);
            foreach ($tailClauseCandidates as $subClause) {
                $tailClause->addClause($subClause);
            }
        } else {
            $tailClause = $tailClauseCandidates[0];
            $tailClause->setModifier(static::REPEATING | static::OPTIONAL);
        }

        $nonRecursiveClause = new Sequence(static::NONE, null);
        $nonRecursiveClause->addClause($headClause);
        $nonRecursiveClause->addClause($tailClause);

        $this->leftRecursionClause = $nonRecursiveClause;
    }

    public function parse(ParserInterface $parser, array &$context, array $args=null) {
        $this->processLeftRecursiveClauses();

        if ($this->leftRecursive) {
die("I should have been optimized away");
            if (null !== ($args = $this->getArgs())) {
                return $this->leftRecursionClause->withArgs($args)->parse($parser, $parentResult);
            } else {
                return $this->leftRecursionClause->withArgs(['pr' => 0])->parse($parser, $parentResult);
            }
        }
$indent = str_repeat(" ", count(debug_backtrace()));

        $parser->traceStart($this, $parser->offset);
        $offset = $parser->offset;

        foreach ($this->clauses as $clause) {
            $parser->offset = $offset;

            $context = null;

            if ($clause instanceof Snippet) {
                $result = $clause->parse($parser, $context);
            } else {
                $result = $this->processSubClause($parser, $clause, $context);
            }

            if ($result === false) {
                // try the next choice
                continue;
            }

/*
            $parser->traceHit($this, $offset, $result);
*/
            $parser->traceEnd($this, $result);
            return $result;
        }
        $parser->offset = $offset;
/*
        $parser->traceMiss($this, $offset);
*/
        $parser->traceEnd($this, false);
        return false;
    }

    public function offsetGet($offset) {
        return $this->clauses[$offset] ?? null;
    }

    public function offsetSet($offset, $clause) {
        $clause->setParent($this);
        $this->clauses[$offset] = $clause;
    }

    public function offsetExists($offset) {
        return array_key_exists($offset, $this->clauses);
    }

    public function offsetUnset($offset) {
        throw new Exception("Can't unset a clause from OrderedChoice");
    }

    public function count() {
        return count($this->clauses);
    }

    public function addClause(Clause $choice) {
        $choice->setParent($this);
        $this->clauses[] = $choice;
    }

    public function getIterator() {
        yield from $this->clauses;
    }

    public function asString(): string {
        return implode(" / ", $this->clauses);
    }

    public function __debugInfo() {
        $di = parent::__debugInfo();
        $di['OrderedChoice'] = $this->clauses;
        return $di;
    }
}
