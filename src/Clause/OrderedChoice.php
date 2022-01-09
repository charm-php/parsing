<?php
namespace Charm\Parsing\Clause;

use Charm\Parsing\{
    GrammarInterface,
    State,
    Clause,
    Rule,
    BoxedResult,
    Grammar,
    GrammarError,
    InternalError,
    CommittedError,
    ErrorInfo,
};
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



            foreach ($this->prepared as $prep) {
                $result = $this->lrParse($state, 0, $prep->clause->pragma('assoc') !== null && $prep->clause->pragma('assoc')[0] === 'right');
                if ($result !== null) {
                    break;
                }
            }
            if ($result === null) {
                return false;
            }
            return $result->value;
        } else {
            foreach ($this as $clause) {
                $state->offset = $offset;

                $result = $clause->parse($state, []);
                if ($result === false) {
                    // try the next choice
                    continue;
                }
                return $result;
            }
            $state->offset = $offset;
            return false;
        }
    }

    /**
     * Parse a full expression by first parsing a left operator then the rest of
     * the expression.
     *
     * @param $floor The lowest precedence we're allowed to return
     */
    private function lrParse(State $state, int $floor=0, bool $rightAssociative=false): ?Result {
        $prefix = function() use ($state) {
            return substr($state->source, 0, $state->offset)."¤".substr($state->source, $state->offset).str_repeat("  ", count(debug_backtrace())-8)." ";
        };
        //echo $prefix()."starting lrParse(floor=$floor)\n";

        $offset = $state->offset;

        //echo $prefix()." - searching for a \$start clause\n";
        $start = null;

        $didSkip = false;
        foreach ($this->prepared as $i => $prep) {
            if ($prep->isLeftRecursive) {
                $didSkip = true;
                continue;
            }
            //echo $prefix()." - trying $i at ".$state->offset." `".$prep->clause."`\n";
            $start = $this->parsePreparedClause($state, $prep, null);
            if ($start !== null) {
                //echo $prefix()." - found a \$start result to use\n";
                break;
            } else {
                //echo $prefix()." - did not match\n";
            }
        }

        if ($start === null) {
            //echo $prefix()."ending lrParse: no parses matched\n";
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
            if ($result === false) {
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
                    //echo $prefix()." - parsing right recursive clause $i `$subClause`\n";

/*
 && $prep->clause->pragma('assoc')[0] === 'right') {
    die("RIGHT ASS");
}
*/
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
                    if ($match instanceof BoxedResult) {
                        $match = $match->value;
                    } else {
                        //echo $prefix()." - snippet finished the sequence\n";
                        $result = $match;
                        goto done;
                    }
                } else {
                    //echo $prefix()." - parsing normal sub-clause $i `$subClause`\n";
                    $match = $subClause->parse($state, $result);
                }

                if ($match === false) {
                    //echo $prefix()." - not a match\n";
                    $result = null;
                    goto done;
                } elseif ($match === true) {
                    //echo $prefix()." - an ignored match\n";
                    continue;
                } else {
                    //echo $prefix()." - matched\n";
                    if (null !== ($name = $subClause->getName())) {
                        $result[$name] = $match;
                    } else {
                        $result[] = $match;
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
            die("Can't parse a left-recursive choice without a \$start provided\n");
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
                    throw new GrammarError("Found loop in grammar: ".implode("\n -> ", array_map(function($c) { return $c->asString(); }, $path)), self::GRAMMAR_LOOP_ERROR);
                    return null;
                }
                $path[] = $clause;
                if ($clause === $this) {
                    return $path;
                }
                if ($clause instanceof Identifier) {
                    $nextRule = $grammar[$clause->getIdentifier()];
                    if ($nextRule === null) {
                        throw new GrammarError("Rule `".$clause->getIdentifier()."` does not exist in the grammar", 0);
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
                    die("Might a ".$clause::class." $clause be left recursive?");
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


