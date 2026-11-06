<?php
namespace Charm\Parsing\Clause;

use Charm\Parsing\{
    GrammarInterface,
    State,
    Clause,
    Rule,
    Signal,
    Grammar
};
use Charm\Parsing\Error\CustomError;
use Charm\Parsing\Clause\OrderedChoice\{
    PreparedClause,
    Result
};

/**
 * OrderedChoice: e1 / e2 / e3
 */
class OrderedChoice extends NonTerminal {
    const GRAMMAR_LOOP_ERROR = 30;

    public function interpret(State $state, array $context) {
        $offset = $state->offset;
        // A reference to this rule that ends a prefix-operator alternative sets the floor (see Identifier)
        $floor = $state->extra['lrFloor'] ?? 0;
        unset($state->extra['lrFloor']);
        if ($this->isLeftRecursive) {
            $result = $this->lrParse($state, $floor);
            if ($result === null) {
                return Signal::MISS;
            }
            return $result->value;
        } else {
            foreach ($this as $clause) {
                $state->offset = $offset;

                $result = $clause->parse($state, []);
                if ($result === Signal::MISS) {
                    // try the next choice
                    continue;
                }
                return $result;
            }
            $state->offset = $offset;
            return Signal::MISS;
        }
    }

    /**
     * Parse a full expression by first parsing a left operator then the rest of
     * the expression.
     *
     * @param $floor The lowest precedence we're allowed to return
     */
    private function lrParse(State $state, int $floor=0, bool $rightAssociative=false): ?Result {
        $offset = $state->offset;

        // First, find a non-left-recursive "start" value (e.g., a number or parenthesized expr)
        $start = null;

        // The start value is the first non-left-recursive alternative that matches. A reference to this
        // rule inside such an alternative is a fresh expression (floor 0), except when it ends the
        // alternative (a prefix operator): that one only binds operators tighter than the alternative.
        foreach ($this->prepared as $prep) {
            if ($prep->isLeftRecursive) {
                continue;
            }
            $start = $this->parsePreparedClause($state, $prep, null);
            if ($start !== null) {
                break;
            }
        }

        if ($start === null) {
            assert($state->offset === $offset, "Offset not rewound after failed parse");
            return null;
        }

        /**
         * With the $start result, we can now parse left recursive clauses. We'll
         * "wrap" the $start result (making $start the left child of the $newStart)
         * for any prep with a precedence lower, or equal+left-associative.
         * When we find a prep which succeeds, $start = $newStart. If no preps
         * succeed, we'll return $start.
         */
        //echo $prefix()." ! trying only left recursive clauses\n";
        $rightOffset = $state->offset;
        for(;;) {
            assert($state->offset === $rightOffset, "Incorrect offset in state after parse");
            $newStart = null;
            foreach ($this->prepared as $i => $prep) {
                if (!$prep->isLeftRecursive) {
                    continue;
                }

                //echo $prefix()." - choice $i p=".$prep->precedence." at ".$state->offset." `".$prep->clause."`\n";

                if ($prep->precedence === $floor && $rightAssociative) {
                    //echo $prefix()." - right associative, so we'll permit the floor level\n";
                } elseif ($prep->precedence <= $floor) {

                    //echo $prefix()." - can't use because precedence below floor (".$prep->precedence.")\n";
                    continue;
                }

                $newStart = $this->parsePreparedClause($state, $prep, $start);
                if ($newStart !== null) {
                    //echo $prefix()." - matched\n";
                    break;
                }
                //echo $prefix()." - missed\n";
            }
            if ($newStart === null) {
                //echo $prefix()." - no more parses succeeded\n";
                break;
            }
            // since we've consumed another, update the offset
            $rightOffset = $state->offset;
            $start = $newStart;
        }

        //echo $prefix()."ending lrParse: result = $start\n";
        return $start;
    }

    /** Spread `$match` into `$result` the way Sequence does: skip null, flatten two levels. */
    private static function spreadInto(array &$result, mixed $match): void {
        if ($match === null) {
            return;
        }
        if (!is_array($match)) {
            $result[] = $match;
            return;
        }
        foreach ($match as $item) {
            if (is_array($item)) {
                foreach ($item as $subItem) {
                    $result[] = $subItem;
                }
            } else {
                $result[] = $item;
            }
        }
    }

    private function parsePreparedClause(State $state, PreparedClause $prep, ?Result $start): ?Result {
        $prefix = function() use ($state) {
            return substr($state->source, 0, $state->offset)."¤".substr($state->source, $state->offset).str_repeat("  ", count(debug_backtrace())-8)." ";
        };
        //echo $prefix()."starting parsePreparedClause(floor=".$prep->precedence.") with clause `".$prep->clause."`\n";
        $floor = $prep->precedence;
        $offset = $state->offset;
        if (!$prep->isLeftRecursive) {
            // for non-left-recursive clauses, we can use the standard parsing algorithm
            $result = $prep->clause->parse($state, []);
            if ($result === Signal::MISS) {
                //echo $prefix()."ending parsePreparedClause: parse failed\n";
                return null;
            }
            //echo $prefix()."ending parsePreparedClause: result = ".json_encode($result)."\n";
            return new Result($result, $prep->clause, $offset, $prep->precedence);
        } elseif ($start !== null) {
            //echo $prefix()." - trying to wrap `$start` with clause `{$prep->clause}`(pr={$prep->precedence})\n";
            $first = $prep->clause[0];
            $result = [];
            if (null !== ($name = $first->getName())) {
                $result[$name] = $start->value;
            }
            if ($first->hasSpread()) {
                self::spreadInto($result, $start->value);
            } elseif ($name === null) {
                $result[] = $start->value;
            }
            $length = count($prep->clause);
            for ($i = 1; $i < $length; $i++) {
                $subClause = $prep->clause[$i];

                if ($subClause->getModifier() === Clause::NONE && $subClause instanceof Identifier && $subClause->getIdentifier() === $this->ruleName) {
                    $match = $this->lrParse($state, $prep->precedence, $prep->rightAssoc);

                    if ($match !== null) {
                        //echo $prefix()." - right recursive clause $i `$subClause` matched with result `$match`\n";
                        $match = $match->value;
                    } else {
                        //echo $prefix()." - not a match\n";
                        $result = null;
                        goto done;
                    }
                } elseif ($subClause instanceof Snippet) {
                    //echo $prefix()." - parsing snippet $i\n";
                    $match = $subClause->parse($state, $result);
                    // Snippets always terminate the sequence (return the result); $MISS fails it
                    $result = $match === Signal::MISS ? null : $match;
                    goto done;
                } else {
                    //echo $prefix()." - parsing normal sub-clause $i `$subClause`\n";
                    $match = $subClause->parse($state, $result);
                }

                if ($match === Signal::MISS) {
                    //echo $prefix()." - not a match\n";
                    $result = null;
                    goto done;
                } elseif ($match === Signal::HIT) {
                    //echo $prefix()." - terminal matched with no value
                    continue;
                } else {
                    //echo $prefix()." - matched\n";
                    if (null !== ($name = $subClause->getName())) {
                        // Named elements always get captured
                        $result[$name] = $match;
                    }
                    if ($subClause->hasSpread()) {
                        self::spreadInto($result, $match);
                    } elseif ($name === null) {
                        // Check if this is a reference to a %silent rule
                        $isSilent = ($subClause instanceof Identifier && $subClause->isSilent());
                        if (!$isSilent) {
                            $result[] = $match;
                        }
                    }
                }
            }
        done:
            //echo $prefix()." - final result: ".json_encode($result)."\n";
            if ($result === null) {
                $state->offset = $offset;
                //echo $prefix()."ending parsePreparedClause: parse failed\n";
                return null;
            }
            //echo $prefix()."ending parsePreparedClause: result = ".json_encode($result)."\n";
            return new Result($result, $prep->clause, $offset, $prep->precedence);
        } else {
            throw new \LogicException("Can't parse a left-recursive choice without a \$start provided");
        }
    }

    // track parsing state at every offset
    private $parseState = [];

    private $ruleName;

    // is this clause left-recursive?
    private $isLeftRecursive = false;

    // recursive sub clauses (if the clause is left-recursive)
    private $prepared = null;

    public function prepare(Grammar $grammar) {
        if ($this->getParent() === null) {
            // this clause may be left recursive, so we must check
            $rule = $this->getRule();
            $this->ruleName = $rule->getRuleName();

            // Does the alternative start with a reference to this rule itself? Only such direct left
            // recursion is parsed with the precedence loop; a cycle through another rule is grown by
            // Rule::parse() and its alternative is an ordinary one here.
            $refersBack = function(Clause $clause, array $path=[]) use (&$refersBack, $grammar) {
                if (in_array($clause, $path)) {
                    return null;
                }
                $path[] = $clause;
                if ($clause === $this) {
                    return $path;
                }
                if ($clause instanceof Identifier) {
                    if (!isset($grammar[$clause->getIdentifier()])) {
                        throw new CustomError("Rule `".$clause->getIdentifier()."` does not exist in the grammar", 0);
                    }
                    return $clause->getIdentifier() === $this->ruleName ? $path : null;
                }
                if ($clause instanceof Sequence) {
                    foreach ($clause as $subClause) {
                        // only needs to check the first sub-clause
                        return $refersBack($subClause, $path);
                    }
                } elseif ($clause instanceof OrderedChoice) {
                    // an ordered choice might be left recursive, but we must check all choices
                    foreach ($clause as $subClause) {
                        $subClauseRecursive = $refersBack($subClause, $path);
                        if ($subClauseRecursive !== null) {
                            return $subClauseRecursive;
                        }
                    }
                } elseif ($clause instanceof NonTerminal) {
                    throw new \LogicException("Unexpected NonTerminal clause in lrParse: " . $clause::class . " $clause - may be left recursive?");
                }
                return null;
            };

            $length = count($this);
            $this->prepared = [];
            $this->isLeftRecursive = false;
            foreach ($this as $index => $child) {
                [$precedence, $assoc] = $grammar->resolveAlternativePrecedence($child, $this->ruleName, $index, $length);
                $isLeftRecursive = $refersBack($child) !== null;
                $this->prepared[] = new PreparedClause($isLeftRecursive, $child, $precedence, $assoc === 'right');
                if ($isLeftRecursive) {
                    $this->isLeftRecursive = true;
                } elseif ($child instanceof Sequence) {
                    // a trailing reference to this rule (a prefix operator's operand) binds tighter than the alternative
                    $last = null;
                    foreach ($child as $element) {
                        if (!($element instanceof Snippet) && !($element instanceof Commit)) {
                            $last = $element;
                        }
                    }
                    if ($last instanceof Identifier && $last->getIdentifier() === $this->ruleName && $last->getModifier() === Clause::NONE) {
                        $last->setTailFloor($precedence);
                    }
                }
            }
        }
        foreach ($this as $child) {
            $child->prepare($grammar);
        }
    }


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

    public function getMinByteLength(): int {
        $min = PHP_INT_MAX;
        foreach ($this as $choice) {
            // an optional alternative or a predicate can succeed without consuming input
            $choiceMin = ($choice->isOptional() || $choice->hasAndPredicate() || $choice->hasNotPredicate()) ? 0 : $choice->getMinByteLength();
            if ($choiceMin < $min) {
                $min = $choiceMin;
            }
        }
        return $min === PHP_INT_MAX ? 0 : $min;
    }

    public function getFirstBytes(): ?string {
        $chars = '';
        foreach ($this as $choice) {
            $choiceChars = $choice->getFirstBytes();
            if ($choiceChars === null) {
                return null; // One unknown means we can't be certain
            }
            $chars .= $choiceChars;
        }
        // Deduplicate characters
        return $chars !== '' ? count_chars($chars, 3) : null;
    }
}

namespace Charm\Parsing\Clause\OrderedChoice;

use Charm\Parsing\Clause;

class PreparedClause {
    public $isLeftRecursive;
    public $clause;
    public $precedence;
    public $rightAssoc;
    public function __construct(bool $isLeftRecursive, Clause $clause, int $precedence, bool $rightAssoc = false) {
        $this->isLeftRecursive = $isLeftRecursive;
        $this->clause = $clause;
        $this->precedence = $precedence;
        $this->rightAssoc = $rightAssoc;
    }

    public function __toString() {
        return json_encode($this);
    }
}

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
        return json_encode($this->value)."(pr=".$this->precedence.")";
        if (is_array($this->value)) {
            return implode(" ", $this->value);
        } else {
            return json_encode($this->value);
        }

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


