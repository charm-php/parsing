<?php
namespace Charm\Parsing;

use Traversable;
use Countable;
use IteratorAggregate;
use ArrayAccess;
use Charm\Vector;
use Charm\Vector\TypedVector;
use Charm\Parsing\Clause\{
    Identifier,
    Nothing,
    Regex,
    Text,
    Sequence,
    Snippet,
    Start,
    OrderedChoice,
    EOF,
    SOF,
    Any
};
use Charm\Parsing\Error\CustomError;

/**
 * Represents a Parsing Expression Grammar, which is a formal grammar for describing
 * a context free language.
 *
 * PEGs have the form:
 *
 * $symbol <- $ordered_set_of_rules
 *
 * Where $symbol is a non-terminal (reference to another symbol) or a terminal (string
 * matching the source).
 *
 * A mathematical expression in PEG could be described like this:
 *
 * Expr     <- Sum                                      # Expr is a tree node which contains a Sum node
 * Sum      <- Product (('+' / '-') Product)*           # Sum is a tree node which contains a sequence of additions and subtractions
 * Product  <- Power (('*' / '/') Power)*               # Product is a tree node which contains a sequence of multiplications and divisions
 * Power    <- Value ('^' Power)?                       # Power node is a tree node which contains an exponent
 * Value    <- [0-9]+ / '(' Expr ')'                    # A Value is a node which matches the regular expression /[0-9]+ or a parenthesized expression.
 *
 * The structure of the above grammar would be:
 *
 * Grammar[
 *   Rule[ "Expr", Clause[] ],
 *   Rule[ "Sum", Clause[] ],
 *   ...
 * ]
 *
 * Grammar Prefix Operators
 *
 *      !<term>             Do not match the term
 *      &
 * Grammar parsing giant regex
 *
 */
class Grammar implements IteratorAggregate, GrammarInterface, \JSONSerializable {

    /**
     * The filename for the grammar, if available
     */
    public $filename;

    /**
     * Associative array of rules
     */
    private $ruleMap = [];

    /**
     * Has the grammar been optimized?
     */
    private $optimized = false;

    /**
     * Registry of all snippets in the grammar, indexed by name.
     * Names follow the pattern: RuleName_1, RuleName_2, etc.
     * @var array<string, Snippet>
     */
    private array $snippets = [];

    public function __construct(iterable $rules, string $filename=null) {
        $this->filename = $filename;
        foreach ($rules as $rule) {
            if (isset($this->ruleMap[$rule->getRuleName()])) {
                throw new CustomError("Rule '".$rule->getRuleName()."' is already declared in this grammar. Use the Choice operator `/` to add multiple rules to a grammar");
            }
            $this->ruleMap[$rule->getRuleName()] = $rule;
        }
        $this->prepare();
    }

    protected function prepare() {
        foreach ($this->ruleMap as $ruleName => $rule) {
            $rule->prepare($this);
            $this->collectSnippets($ruleName, $rule->getClause());
        }
    }

    /**
     * Recursively collect all snippets from a clause tree and register them.
     */
    private function collectSnippets(string $ruleName, Clause $clause): void {
        static $counters = [];

        if ($clause instanceof Snippet) {
            if (!isset($counters[$ruleName])) {
                $counters[$ruleName] = 0;
            }
            $snippetName = $ruleName . '_' . (++$counters[$ruleName]);
            $clause->setSnippetName($snippetName);
            $this->snippets[$snippetName] = $clause;
        }

        // Recurse into child clauses
        if ($clause instanceof \Traversable) {
            foreach ($clause as $child) {
                $this->collectSnippets($ruleName, $child);
            }
        }
    }

    /**
     * Get a snippet by name.
     */
    public function getSnippet(string $name): ?Snippet {
        return $this->snippets[$name] ?? null;
    }

    /**
     * Get all registered snippets.
     * @return array<string, Snippet>
     */
    public function getSnippets(): array {
        return $this->snippets;
    }

    public function count() {
        return count($this->ruleMap);
    }

    public function offsetGet($offset) {
        return $this->ruleMap[$offset] ?? null;
    }

    public function offsetSet($offset, $value) {
        throw new Exception("Can't set rules on grammar");
    }

    public function offsetUnset($offset) {
        throw new Exception("Can't set rules on grammar");
    }

    public function offsetExists($offset) {
        return array_key_exists($offset, $this->ruleMap);
    }

    public function getIterator() {
        yield from $this->ruleMap;
    }

    public function jsonSerialize() {
        return iterator_to_array($this);
    }

    public function __toString() {

        $arr = [];

        foreach ($this as $rule) {
            $arr[] = (string) $rule;
        }

        return implode("\n", $arr);

        foreach ($this as $rule) {
            $row = [ $rule->getRuleName(), trim($rule->getClause()) ];
            $arr[] = $row;
        }
        return (string) new Terminal\Table($arr);
    }

}
