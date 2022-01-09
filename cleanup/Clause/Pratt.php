<?php
namespace Charm\Parsing\Clause;
use Charm\Parsing\State;
use Charm\Parsing\Clause;
use Charm\Parsing\GrammarError;
use Charm\Parsing\{
    ExpectedError,
    ExceptionInterface
};

class Pratt extends NonTerminal {

    private $absorbedClause;
    private $ruleName;              // The name of the rule we'll parse
    private $leftDenotations=[];    // Clauses which have their own 'left' token
    private $nullDenotations=[];    // Clauses which need a 'left' token to be provided

    public function toGrammarString(): string {
        $childrenString = $this->asString();
        if ($this->getParent() !== null || $this->getModifier() !== static::NONE) {
            $childrenString = "($childrenString)";
        }
        return $this->getPredicateString().$childrenString.$this->getOperatorString();
    }

    public function asString(): string {
        $children = array_map(function($child) {
            return $child->toGrammarString();
        }, iterator_to_array($this));
        return implode(" | ", $children);
    }

    public function __construct(Clause $clause, string $ruleName) {
        parent::__construct($clause->getModifier(), $clause->getName());
        if ($clause->getParent() !== null) {
            throw new InternalError("Can't absorb a clause which already has a parent");
        }
        $checkForCommits = function(Clause $clause) use (&$checkForCommits) {
            if ($clause instanceof Commit) {
                throw new GrammarError("Left recursive rules can't use the '@' committ symbol");
            }
            if ($clause instanceof NonTerminal) {
                foreach ($clause as $subClause) {
                    $checkForCommits($subClause);
                }
            }
        };
        $checkForCommits($clause);
        $this->absorbedClause = $clause;
        $this->ruleName = $ruleName;
        foreach($clause as $subClause) {
            $this[] = $subClause;
        }

        foreach ($this as $subClause) {
            if ($this->isLeftRecursive($subClause)) {
                $this->nullDenotations[] = $subClause;
            } else {
                $this->leftDenotations[] = $subClause;
            }
            if ($subClause->getParent() !== $this) die("SUBCLAUSE $subClause HAS WRONG PARENT");
        }
//        $this[0] = clone $clause;
    }

    protected function filterSubClause(int $offset, Clause $subClause): Clause {
        $subClause = clone $subClause;
        $subClause->setParent(null);
        return $subClause;

        if ($offset !== 0) {
            throw new InternalError("Can only use sub-clause offset 0");
        }
        // Validate the grammar we received
        if (!($clause instanceof OrderedChoice)) {
            throw new GrammarError("Left recursive clause `$clause` does not have any non-recursive choices");
        }

        $this->nullDenotations = [];
        $this->leftDenotations = [];

        foreach ($clause as $subClause) {
//            $subClause = clone $subClause;
//            $subClause->setParent($this);
            if ($this->isLeftRecursive($subClause)) {
                $this->nullDenotations[] = $subClause;
            } else {
                $this->leftDenotations[] = $subClause;
            }
        }

        return $clause;
    }

    public function interpret(State $state, array &$context, array $args=null) {
        assert($context === [], "The initial context must be []");

        $offset = $state->offset;

        $result = $this->parseExpression($state, 0, $args);
        if ($result === null) {
            $state->offset = $offset;
            return false;
        }

        $result = $result->value;
        if (is_bool($result)) {
            $result = new BoxedResult($result);
        }
        return $result;
    }

    /**
     * @param $precedence The precedence level which we won't adopt
     */
    private function parseExpression(State $state, int $precedence, array $args=null): ?Pratt\Result {
        $left = $this->parseLeft($state, $args);

        if ($left === null) {
            return null;
        }
        while (true) {
            if ($state->offset === $state->length) {
                // stop if we've reached EOF
                break;
            }
            $subParse = $this->parseRight($state, $left);

            $rp = null;
            while ($subParse->valid()) {
                $rp = $subParse->current();
                if (!($precedence < $rp)) {
                    $subParse->send(false);
                    break 2;
                } else {
                    $subParse->send(true);
                }
            }
            $right = $subParse->getReturn();
            if ($right === null) {
                break;
            }
            $left = $right;
        }
        return $left;
    }

    private function parseLeft(State $state, array &$args=null): ?Pratt\Result {
        $offset = $state->offset;
        foreach ($this->leftDenotations as $clauseIndex => $clause) {
            $context = [];
            $left = $clause->parse($state, $context);
            if ($left !== false) {
                return new Pratt\Result($left, $clause, $offset, 0); // count($this->leftDenotations) - $clauseIndex);
            }
        }
        assert($state->offset === $offset);
        return null;
    }

    private function parseRight(State $state, Pratt\Result $left): \Generator {
        $offset = $state->offset;
        foreach ($this->nullDenotations as $clauseIndex => $clause) {
            assert($clause[0] instanceof Identifier, "First sub clause should be an identifier");
            assert($clause[0]->getIdentifier() === $this->ruleName, "First sub clause seems to be not recursive");

            // $result may be needed by snippets in the sequence
            $result = [ ($this[0]->getName() ?? 0) => $left->value ];

            $length = count($clause);
            for ($index = 1; $index < $length; $index++) {
                $subClause = $clause[$index];

                if ($subClause instanceof Identifier && $subClause->getIdentifier() === $this->ruleName) {

                    // We are about to go recursive, but here we must allow the caller to decide if we should continue
                    $t = $state->offset;
                    $state->offset = $offset;
                    $shouldResume = yield count($this->nullDenotations) - $clauseIndex;
                    if (!$shouldResume) {
                        return false;
                    }
                    $state->offset = $t;

                    $match = $this->parseExpression($state, count($this->nullDenotations) - $clauseIndex) ?? false;
                    if ($match !== false) {
                        $match = $match->value;
                    }
                } else {
                    try {
                        $match = $subClause->parse($state, $result);
                    } catch (ExceptionInterface $e) {
                        $match = false;
                    }
//                    $match = $this->processSubClause($state, $subClause, $result);

                    if ($subClause instanceof Snippet) {
                        // Snippets may provide instructions that determine how this sequence continues
                        if ($match instanceof SequenceIgnore) {
                            // The sequence should terminate with a successful result
                            throw new GrammarError("In left recursive rules a snippet can't return `ignore()`");
                        } elseif ($match instanceof SequenceResume) {
                            // The sequence should resume parsing as if nothing happened
                            $match = true;
                        } else {
                            // Snippets always terminate the sequence unless they instruct it to resume.
                            return new Pratt\Result($match, $clause, $offset, count($this->nullDenotations) - $clauseIndex);
                        }
                    }
                }

                if ($match === false) {
                    // The sub clause failed, so the sequence fails
                    $state->offset = $offset;
                    continue 2;
                } elseif ($match === true) {
                    // The sub clause returned a successful empty parse, so we continue to the next clause
                    continue;
                }

                if ($match instanceof BoxedResult) {
                    $match = $match->value;
                }

                if (null !== ($name = $subClause->getName())) {
                    $result[$name] = $match;
                } else {
                    $result[] = $match;
                }
            }
            return new Pratt\Result(array_values($result), $clause, $offset, count($this->nullDenotations) - $clauseIndex);
        }
        return null;
    }

    private function getPrecedence(Clause $clause): int {
        $index = array_search($clause, $this->nullDenotations);
        return count($this->nullDenotations) - $index;
    }

    private function isLeftRecursive(Clause $clause) {
        // checks all sub-clauses for left recursion, with special handling of OrderedChoice clauses
        if ($clause instanceof Identifier) {
            if ($clause->getIdentifier() === $this->ruleName) {
                return true;
            } else {
                return false;
            }
        } elseif ($clause instanceof OrderedChoice) {
            foreach ($clause as $choice) {
                if ($this->isLeftRecursive($choice, $this->ruleName)) {
                    return true;
                }
            }
            return false;
        } elseif ($clause instanceof \Traversable) {
            return $this->isLeftRecursive($clause[0], $this->ruleName);
        } else {
            return false;
        }
    }
}

namespace Charm\Parsing\Clause\Pratt;
use Charm\Parsing\Clause;

class Result {
    public $value;
    public Clause $clause;
    public int $offset;
    public int $precedence;
    public function __construct($value, Clause $clause, int $offset, int $precedence) {
        $this->value = $value;
        $this->clause = $clause;
        $this->offset = $offset;
        $this->precedence = $precedence;
    }

    public function __debugInfo() {
        return [
            'value' => $this->value,
            'precedence' => $this->precedence,
            'clause' => ''.$this->clause,
        ];
    }

    public function __toString() {
        if (is_array($this->value)) {
            $res = "  (\t\tp=".$this->precedence."\n";
            foreach ($this->value as $value) {
                foreach (explode("\n", $value) as $line) {
                    $res .= "  ".$line."\n";
                }
            }
            $res .= "  )\n";
            return $res;
        } else {
            return $this->value."";
        }
    }
}

