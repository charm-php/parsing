<?php
namespace Charm\Parsing\Optimizer;

use Charm\Parsing\Grammar;
use Charm\Parsing\Clause;
use Charm\Parsing\Clause\{
    OrderedChoice,
    Sequence,
    Text,
    Regex,
    Identifier
};

/**
 * Optimizes consecutive terminal alternatives in OrderedChoice clauses.
 *
 * Transforms patterns like:
 *   "break" | "case" | "catch" | SomeRule | "default" | "delete"
 * Into:
 *   /break|case|catch/ | SomeRule | /default|delete/
 *
 * This reduces the number of alternatives that need to be tried sequentially.
 */
class ConsecutiveTerminalsOptimizer implements GrammarOptimizerInterface {

    private int $mergeCount = 0;

    public function optimize(Grammar $grammar): Grammar {
        $this->mergeCount = 0;

        foreach ($grammar as $rule) {
            $clause = $rule->getClause();
            $optimized = $this->optimizeClause($clause);
            if ($optimized !== $clause) {
                $rule->setClause($optimized);
            }
        }

        return $grammar;
    }

    /**
     * Get the number of merges performed in the last optimization.
     */
    public function getMergeCount(): int {
        return $this->mergeCount;
    }

    private function optimizeClause(Clause $clause): Clause {
        if ($clause instanceof OrderedChoice) {
            return $this->optimizeOrderedChoice($clause);
        }

        if ($clause instanceof Sequence) {
            return $this->optimizeSequence($clause);
        }

        // For other clause types that may contain children (like Identifier),
        // we don't recurse - the Grammar already resolved references
        return $clause;
    }

    private function optimizeSequence(Sequence $seq): Sequence {
        $changed = false;
        $newChildren = [];

        foreach ($seq as $child) {
            $optimized = $this->optimizeClause($child);
            $newChildren[] = $optimized;
            if ($optimized !== $child) {
                $changed = true;
            }
        }

        if (!$changed) {
            return $seq;
        }

        $newSeq = new Sequence($seq->getModifier(), $seq->getName());
        foreach ($seq->getPragmas() as $pragma) {
            $newSeq->addPragma($pragma);
        }
        foreach ($newChildren as $child) {
            // Clear parent before adding to new structure
            $child->setParent(null);
            $newSeq[] = $child;
        }
        return $newSeq;
    }

    private function optimizeOrderedChoice(OrderedChoice $oc): Clause {
        // First, recursively optimize children
        $children = [];
        foreach ($oc as $child) {
            $children[] = $this->optimizeClause($child);
        }

        // Now merge consecutive terminals
        $result = [];
        $currentRun = [];

        foreach ($children as $child) {
            if ($this->isMergeableTerminal($child)) {
                $currentRun[] = $child;
            } else {
                $this->flushRun($currentRun, $result);
                $currentRun = [];
                $result[] = $child;
            }
        }
        $this->flushRun($currentRun, $result);

        // If nothing changed, return original
        if (count($result) === count($oc)) {
            $allSame = true;
            $i = 0;
            foreach ($oc as $original) {
                if ($result[$i] !== $original) {
                    $allSame = false;
                    break;
                }
                $i++;
            }
            if ($allSame) {
                return $oc;
            }
        }

        // Build new OrderedChoice
        $newOc = new OrderedChoice($oc->getModifier(), $oc->getName());
        foreach ($oc->getPragmas() as $pragma) {
            $newOc->addPragma($pragma);
        }
        foreach ($result as $child) {
            // Clear parent before adding to new structure
            $child->setParent(null);
            $newOc[] = $child;
        }
        return $newOc;
    }

    /**
     * Check if a clause can be merged into a combined regex.
     */
    private function isMergeableTerminal(Clause $clause): bool {
        // Must be Text or Regex
        if (!($clause instanceof Text) && !($clause instanceof Regex)) {
            return false;
        }

        // Must not have modifiers (?, +, *)
        if ($clause->getModifier() !== Clause::NONE) {
            return false;
        }

        // Must not have a name (capture)
        if ($clause->getName() !== null) {
            return false;
        }

        // Must not have predicates (& or !)
        if ($clause->hasAndPredicate() || $clause->hasNotPredicate()) {
            return false;
        }

        return true;
    }

    /**
     * Flush a run of terminals - merge if 2+, otherwise add as-is.
     */
    private function flushRun(array $run, array &$result): void {
        if (count($run) === 0) {
            return;
        }

        if (count($run) === 1) {
            $result[] = $run[0];
            return;
        }

        // Merge into a single regex
        $result[] = $this->mergeTerminals($run);
        $this->mergeCount++;
    }

    /**
     * Merge multiple Text/Regex clauses into a single Regex.
     */
    private function mergeTerminals(array $terminals): Regex {
        $patterns = [];

        foreach ($terminals as $term) {
            if ($term instanceof Text) {
                // Escape the text for use in regex
                $patterns[] = preg_quote($term->getText(), '/');
            } elseif ($term instanceof Regex) {
                // Use the inner pattern (without delimiters)
                // Wrap in non-capturing group to preserve any alternation
                $inner = $term->getInnerPattern();
                if (strpos($inner, '|') !== false) {
                    $patterns[] = '(?:' . $inner . ')';
                } else {
                    $patterns[] = $inner;
                }
            }
        }

        // Build combined pattern - use non-capturing group for alternation
        $combined = '/(?:' . implode('|', $patterns) . ')/';

        return new Regex($combined, Clause::NONE, null);
    }
}
