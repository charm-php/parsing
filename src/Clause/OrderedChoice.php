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
        if ($this->isLeftRecursive) {


        //echo "************** STARTING PRATT ************\n";
        //echo $this->ruleName." ::=\n";
        //foreach ($this->prepared as $prep) {
            //echo " (p=".$prep->precedence.")   {$prep->clause}\n";
        //}
        //echo "******************************************\n";
            $result = $this->lrParse($state);
            /*
            foreach ($this->prepared as $prep) {
                $result = $this->lrParse($state, 0, $prep->clause->pragma('assoc') !== null && $prep->clause->pragma('assoc')[0] === 'right');
                if ($result !== null) {
                    break;
                }
            }
            */
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

        // Track which rule is currently in its "finding start" phase via State->extra.
        // This allows:
        // - Prefix operators: when Expr re-enters itself while finding start, return early
        // - Parentheses: when Expr is entered from Primary (different rule), do full parse
        $findingStartKey = 'lrFindingStart';
        $prevFinding = $state->extra[$findingStartKey] ?? null;
        $wasAlreadyFindingThis = ($prevFinding === $this->ruleName);
        $state->extra[$findingStartKey] = $this->ruleName;

        foreach ($this->prepared as $i => $prep) {
            if ($prep->isLeftRecursive) {
                continue;
            }

            // Check if this non-LR clause is a simple reference to a different rule.
            // If so, clear finding-start to allow fresh expression contexts (like
            // parentheses). If the clause directly contains Expr references (like
            // prefix operators), keep finding-start set to prevent them from
            // capturing operators meant for the outer expression.
            $isSimpleRuleRef = ($prep->clause instanceof Identifier &&
                                $prep->clause->getIdentifier() !== $this->ruleName);

            if ($isSimpleRuleRef) {
                $state->extra[$findingStartKey] = $prevFinding;
            }

            $start = $this->parsePreparedClause($state, $prep, null);

            if ($isSimpleRuleRef) {
                $state->extra[$findingStartKey] = $this->ruleName;
            }

            if ($start !== null) {
                break;
            }
        }

        // Restore previous state
        $state->extra[$findingStartKey] = $prevFinding;

        if ($wasAlreadyFindingThis) {
            // We re-entered this rule's lrParse while it was already finding start
            // (e.g., prefix operator's right-hand side) - return just the start
            return $start;
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
            $result = [
                ($prep->clause[0]->getName() ?? 0) => $start->value,
            ];
            $length = count($prep->clause);
            for ($i = 1; $i < $length; $i++) {
                $subClause = $prep->clause[$i];

                if ($subClause->getModifier() === Clause::NONE && $subClause instanceof Identifier && $subClause->getIdentifier() === $this->ruleName) {
                    $match = $this->lrParse($state, $prep->precedence, $prep->clause->pragma('assoc') !== null && $prep->clause->pragma('assoc')[0] === 'right');

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
                    // Snippets always terminate the sequence (return the result)
                    //echo $prefix()." - snippet finished the sequence\n";
                    $result = $match;
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
                    } else {
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

            $refersBack = function(Clause $clause, array $path=[]) use ($rule, &$refersBack, $grammar) {
                if (in_array($clause, $path)) {
                    return null;
                }
                $path[] = $clause;
                if ($clause === $this) {
                    return $path;
                }
                if ($clause instanceof Identifier) {
                    $nextRule = $grammar[$clause->getIdentifier()];
                    if ($nextRule === null) {
                        throw new CustomError("Rule `".$clause->getIdentifier()."` does not exist in the grammar", 0);
                    }
                    return $refersBack($nextRule->getClause(), $path);
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
            foreach ($this as $index => $child) {
                if ($path = $refersBack($child)) {
                    // left recursive clause
                    $this->prepared[] = new PreparedClause(true, $child, $length-$index);
                    $this->isLeftRecursive = true;
                } else {
                    // not recursive clause
                    $this->prepared[] = new PreparedClause(false, $child, $length-$index);
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
            $choiceMin = $choice->getMinByteLength();
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
    public function __construct(bool $isLeftRecursive, Clause $clause, int $precedence) {
        $this->isLeftRecursive = $isLeftRecursive;
        $this->clause = $clause;
        $this->precedence = $precedence;
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


