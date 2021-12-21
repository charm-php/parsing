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

    public function checkParentRefs() {
        $t = microtime(true);
        foreach ($this->getAllClausesRecursively() as $clause) {
            if ($clause instanceof NonTerminal) {
                foreach ($clause as $child) {
                    if ($child->getParent() !== $clause) {
                        die("PROBLEM: child with wrong parent\n");
                    }
                }
            }

            




            $childrenByParent = iterator_to_array($this->childrenByParent($clause));
            $childrenByClause = is_iterable($clause) ? iterator_to_array($clause) : [];

            $missing = $this->findMissingItems($childrenByParent, $childrenByClause);

            if (count($missing->left) > 0 || count($missing->right) > 0) {
                echo "PROBLEM WITH CLAUSE ".$this->renderClause($clause)."\n";
print_r($clause);
var_dump($childrenByParent);
var_dump(iterator_to_array($this->childrenByParent($clause)));
die();


                echo " - checking rule\n";

                $grammar = $this->getGrammar();
                $foundRule = false;
                foreach ($grammar as $rule) {
                    if ($rule === $clause->getRule()) {
                        $foundRule = true;
                        break;
                    }
                }
                if (!$foundRule) {
                    die(" - FATAL: rule for this clause is not in grammar\n");
                }
                echo " - checking ancestors\n";
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
                        die(" - FATAL:  clause $ancestor does not have $current as a child\n");
                    }
                }
            }
        }

        $childrenByParent = iterator_to_array($this->childrenByParent());
        $rootClauses = iterator_to_array($this->getRootClauses());

        $missing = $this->findMissingItems($childrenByParent, $rootClauses);

        $map = new Map();
        foreach ($this->getAllClausesRecursively() as $subClause) {
            $map[$subClause] = [ $subClause, $subClause->getParent() ];
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
