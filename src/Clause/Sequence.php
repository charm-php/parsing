<?php
namespace Charm\Parsing\Clause;

use Charm\Parsing\State;
use Charm\Parsing\Clause;
use Charm\Parsing\Clause\Commit;
use Charm\Parsing\Error\State\CommittedError;
use Charm\Parsing\Rule;
use Charm\Parsing\{
    SequenceInstruction,
    SequenceResume,
    SequenceIgnore,
    ClauseInterface,
    GrammarInterface,
    Signal,
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

                if ($subClause instanceof Snippet) {
                    // Snippets may provide instructions that determine how this sequence continues
                    if ($match instanceof SequenceIgnore) {
                        // The sequence should terminate with a successful result
                        return Signal::HIT;
                    } elseif ($match instanceof SequenceResume) {
                        // The sequence should resume parsing as if nothing happened
                        $match = Signal::HIT;
                    } elseif ($match === Signal::MISS) {
                        if ($committed !== null) {
                            throw new CommittedError($state, $this, $subClause);
                        }
                        // Snippets that return Signal::MISS cause the sequence to fail
                        $state->offset = $offset;
                        return Signal::MISS;
                    } else {
                        // Snippets return the final value for the sequence
                        return $match;
                    }
                }

                if ($match === Signal::MISS) {
                    // The sub clause failed, so the sequence fails
                    if ($committed !== null) {
                        throw new CommittedError($state, $this, $subClause);
                    }
                    $state->offset = $offset;
                    return Signal::MISS;
                } elseif ($match === Signal::HIT) {
                    // Terminal clause matched but has no value (e.g., \nothing, $, ^)
                    continue;
                }

                // Handle named capture (before spread, so we capture the original value)
                if (null !== ($name = $subClause->getName())) {
                    $result[$name] = $match;
                }

                if ($subClause->hasSpread()) {
                    // Spread arrays into the result, skip null
                    if ($match === null) {
                        continue;
                    } elseif (is_array($match)) {
                        foreach ($match as $item) {
                            if (is_array($item)) {
                                foreach ($item as $subItem) {
                                    $result[] = $subItem;
                                }
                            } else {
                                $result[] = $item;
                            }
                        }
                    } else {
                        $result[] = $match;
                    }
                } elseif ($subClause->getName() === null) {
                    // No spread, no name - add as positional
                    // Check if this is a reference to a %silent rule
                    $isSilent = ($subClause instanceof Identifier && $subClause->isSilent());
                    if (!$isSilent) {
                        $result[] = $match;
                    }
                }
            }
            if ($result === []) {
                $result = null;
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
            assert($cl->getParent() === null, "Optimized clause should not have parent reference");
            return $cl;
        } else {
            $c = clone $this;
            foreach ($c as $k => $clause) {
                $subClause = $clause->optimize($grammar);
                assert($subClause->getParent() === null, "Optimized sub-clause should not have parent reference");
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

    public function getMinByteLength(): int {
        $total = 0;
        foreach ($this as $subClause) {
            if ($subClause instanceof Snippet || $subClause instanceof Commit) {
                continue;
            }
            // Predicates don't consume input
            if ($subClause->hasAndPredicate() || $subClause->hasNotPredicate()) {
                continue;
            }
            // Optional elements don't contribute to minimum
            if ($subClause->isOptional()) {
                continue;
            }
            $total += $subClause->getMinByteLength();
        }
        return $total;
    }

    public function getFirstBytes(): ?string {
        $chars = '';
        foreach ($this as $subClause) {
            if ($subClause instanceof Snippet || $subClause instanceof Commit) {
                continue;
            }
            // Predicates don't consume input
            if ($subClause->hasAndPredicate() || $subClause->hasNotPredicate()) {
                continue;
            }
            // Skip optional elements - they might not be present
            if ($subClause->isOptional()) {
                continue;
            }

            $subChars = $subClause->getFirstBytes();
            if ($subChars === null) {
                return null; // Unknown chars, can't optimize
            }
            $chars .= $subChars;

            // If this clause requires at least 1 char, don't look further
            if ($subClause->getMinByteLength() > 0) {
                break;
            }
            // Otherwise this clause can match empty, continue to next
        }
        return $chars !== '' ? count_chars($chars, 3) : null;
    }
}
