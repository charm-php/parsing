<?php
namespace Charm\Parsing\Util;

use Charm\{
    Map,
    Vector
};
use Charm\Parsing\{
    GrammarInterface,
    Clause
};
use Charm\Parsing\Clause\{
    NonTerminal
};

class GrammarTools {

    private $grammar;

    public function __construct(GrammarInterface $grammar) {
        $this->grammar = $grammar;
    }

    public function getGrammar(): GrammarInterface {
        return $this->grammar;
    }

    public function getRootClauses(): iterable {
        foreach ($this->getGrammar() as $rule) {
            yield $rule->getClause();
        }
    }

    public function getAllClausesRecursively(): iterable {
        $getAllClausesRecursively = function(Clause $root) use (&$getAllClausesRecursively) {
            yield $root;
            if (is_iterable($root)) {
                foreach ($root as $subClause) {
                    yield from $getAllClausesRecursively($subClause);
                }
            }
        };
        $seen = new Map();
        foreach ($this->getGrammar() as $rule) {
            yield from $getAllClausesRecursively($rule->getClause());
        }
    }

    /**
     * Validates that all parent-child relationships in the grammar are correct.
     * This is a diagnostic tool for development - throws exceptions on invalid state.
     *
     * @throws \LogicException if parent references are inconsistent
     */
    public function checkParentRefs(): void {
        foreach ($this->getAllClausesRecursively() as $clause) {
            if ($clause instanceof NonTerminal) {
                foreach ($clause as $child) {
                    if ($child->getParent() !== $clause) {
                        throw new \LogicException(
                            "Parent reference mismatch: child clause has wrong parent in " .
                            $this->renderClause($clause)
                        );
                    }
                }
            }

            $childrenByParent = iterator_to_array($this->childrenByParent($clause));
            $childrenByClause = is_iterable($clause) ? iterator_to_array($clause) : [];

            $missing = $this->findMissingItems($childrenByParent, $childrenByClause);

            if (count($missing->left) > 0 || count($missing->right) > 0) {
                // Check if clause's rule exists in grammar
                $grammar = $this->getGrammar();
                $foundRule = false;
                foreach ($grammar as $rule) {
                    if ($rule === $clause->getRule()) {
                        $foundRule = true;
                        break;
                    }
                }
                if (!$foundRule) {
                    throw new \LogicException(
                        "Clause's rule not found in grammar: " . $this->renderClause($clause)
                    );
                }

                // Check ancestor chain
                $ancestors = $this->getAncestors($clause);
                $current = $clause;
                foreach ($ancestors as $ancestor) {
                    $foundChild = false;
                    foreach ($ancestor as $child) {
                        if ($child === $current) {
                            $foundChild = true;
                            break;
                        }
                    }
                    if ($foundChild === false) {
                        throw new \LogicException(
                            "Broken ancestor chain: ancestor does not have clause as child in " .
                            $this->renderClause($clause)
                        );
                    }
                    $current = $ancestor;
                }

                throw new \LogicException(
                    "Parent-child mismatch in clause: " . $this->renderClause($clause)
                );
            }
        }
    }

    public function childrenByParent(Clause $parent=null) {
        foreach ($this->getAllClausesRecursively() as $subClause) {
            if ($subClause->getParent() === $parent) {
                yield $subClause;
            }
        }
    }

    public function getAncestors(Clause $clause) {
        $ancestors = [];
        while ($clause->getParent() !== null) {
            $ancestors[] = $clause->getParent();
            $clause = $clause->getParent();
        }
        return $ancestors;
    }

    public function renderClause(Clause $clause) {
        return $clause::class."`$clause` in ".$clause->getRule()?->getRuleName();
    }

    public function renderClausePath(Clause $clause) {
        $root = 'CLAUSE -> '.$this->renderClause($clause);
        foreach ($this->getAncestors($clause) as $parent) {
            $root = preg_replace('/^|(?<=\n)/', '  ', $root);
            $root = 'ANCESTOR -> '.$this->renderClause($clause)."\n".$root;
        }
        return $root;
    }

    private function findMissingItems(iterable $left, iterable $right): object {
        $allLeft = is_array($left) ? $left : iterator_to_array($left);
        $allRight = is_array($right) ? $right : iterator_to_array($right);

        $findMissing = function(array $needles, array $haystack) {
            $missing = [];
            foreach ($needles as $needle) {
                $foundOffsets = [];
                foreach ($haystack as $i => $straw) {
                    if ($straw === $needle) {
                        $foundOffsets[] = $i;
                    }
                }
                if ($foundOffsets === []) {
                    $missing[] = $needle;
                } else {
                    // found a needle, remove it from haystack to avoid finding a duplicate item
                    foreach ($foundOffsets as $i) {
                        unset($haystack[$i]);
                        break;
                    }
                }
            }
            return $missing;
        };

        $missingLeft = $findMissing($allRight, $allLeft);
        $missingRight = $findMissing($allLeft, $allRight);
        return (object) ['left' => $missingLeft, 'right' => $missingRight];
    }
}
