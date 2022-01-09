<?php
namespace Charm\Parsing\Clause;

use Charm\Parsing\State;
use Charm\Parsing\Clause;
use Charm\Parsing\Clause\Commit;
use Charm\Parsing\Rule;
use Charm\Parsing\{
    SequenceInstruction,
    SequenceResume,
    SequenceIgnore,
    ClauseInterface,
    GrammarInterface,
    BoxedResult,
    ExpectedError,
};
/**
 * Sequence: e1 e2
 */
class Sequence extends NonTerminal {

    public function asString(): string {
        $children = array_map(function($child) {
            return $child->toGrammarString();
        }, iterator_to_array($this));

        if ($this->getModifier() === Clause::NONE && $this->getParent() === null) {
            return implode(" ", $children);
        } else {
            return '('.implode(" ", $children).')';
        }
    }

    public function interpret(State $state, array $context) {
        if (isset($state->cache[$state->offset][$key = get_class($this)." ".$this])) {
            var_dump("Sequence was here:", $state->cache[$state->offset][$key]);
            die();
        }
        $offset = $state->offset;
        $committed = null;

        $result = [];

        try {
            foreach ($this as $index => $subClause) {
                if ($subClause instanceof Commit) {
                    $committed = $state->offset;
                    continue;
                }

                $match = $subClause->parse($state, $result);
    //            $match = $this->processSubClause($state, $subClause, $result);

                if ($subClause instanceof Snippet) {
                    // Snippets may provide instructions that determine how this sequence continues
                    if ($match instanceof SequenceIgnore) {
                        // The sequence should terminate with a successful result
                        return true;
                    } elseif ($match instanceof SequenceResume) {
                        // The sequence should resume parsing as if nothing happened
                        $match = true;
                    } else {
                        if ($match === false) {
                            if ($committed !== null) {
                                throw new CommittedError($state, $this, $subClause);
//                                throw new CommittedError($this, $subClause, new ErrorInfo(['offset' => $state->offset, 'source' => $state->source, 'functionName' => 'OrderedChoice']));
                            }
                        }

                        // Snippets always terminate the sequence unless they instruct it to resume.
                        return $match;
                    }
                }

                if ($match === false) {
                    // The sub clause failed, so the sequence fails
                    if ($committed !== null) {
                        throw new CommittedError($state, $this, $subClause);
                    }
                    $state->offset = $offset;
                    return false;
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
            if ($result === []) {
                $result = null;
            } else {
                $result = array_values($result);
            }
        } catch (ExceptionInterface $e) {
            $state->offset = $offset;
            throw $e;
        }

        return $result;
    }

    public function optimize(GrammarInterface $grammar): ClauseInterface {
        if ($this->getName() !== null || $this->getModifier() !== static::NONE) {
            $res = clone $this;
            return $res;
        }
        if (count($this) === 1) {
            $c = $this[0];
            $cl = $c->optimize($grammar);
            if ($cl->getParent() !== null) {
                die("HAS REF AFTER CLONE");
            }
            return $cl;
        } else {
            $c = clone $this;
            foreach ($c as $k => $clause) {
                $subClause = $clause->optimize($grammar);
                if ($subClause->getParent() !== null) {
                    die("SUB HAS REF AFTER CLONE");
                }
                $c[$k] = $subClause;
            }
            return $c;
        }
    }

    public function __debugInfo() {
        $di = parent::__debugInfo();
        $di['Sequence'] = iterator_to_array($this);
        return $di;
    }

}
