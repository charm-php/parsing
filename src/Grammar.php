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
    Start,
    OrderedChoice,
    EOF,
    SOF,
    Any
};

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
class Grammar implements IteratorAggregate, GrammarInterface {

    private $ruleMap = [];

    public function __construct(iterable $rules) {
        foreach ($rules as $rule) {
            if (isset($this->ruleMap[$rule->getRuleName()])) {
                throw new Exception("Rule '".$rule->getRuleName()."' is already declared in this grammar. Use the Choice operator `/` to add multiple rules to a grammar");
            }
            $this->ruleMap[$rule->getRuleName()] = $rule;
        }
    }

    public function optimize(): static {
        // create a new optimized grammar
        return $this;
        $grammar = new Grammar([]);

        /**
         * Get all rules from the original grammar and add them to the $rules array for optimizations
         */
        foreach ($this as $name => $rule) {
            $clause = $rule->getClause();
            while ($clause instanceof Sequence && count($clause) === 1 && $clause->getModifier() === 0) {
                $clause = $clause[0];
            }
            $clause = clone $clause;
            $grammar->ruleMap[$name] = new Rule($rule->getRuleName(), $clause, $rule->getAssociativity());
        }

        /**
         * Traverse all clauses and:
         * - rewrite any Identifier clauses so that they refer directly to the clause of the grammar
         * - rewrite any sequences which only has one element
         */
        $queue = new Vector();
        foreach ($grammar as $rule) {
            if ($rule->getClause() instanceof Traversable) {
                $queue->push($rule->getClause());
            }
        }
        while (count($queue) > 0) {
            $traversable = $queue->shift();
            foreach ($traversable as $key => $clause) {
                if ($clause instanceof Sequence && count($clause) === 1 && $clause->getModifier() === 0) {
                    $traversable[$key] = $clause[0];
                    $clause = $clause[0];
                }
                if ($clause instanceof Traversable) {
                    $queue->push($clause);
                }
            }
        }
        return $grammar;
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

    public static function fromString(string $grammarString) {
        $parser = new GrammarParser(static::class);
        return $parser->parse($grammarString);
    }

    public function __toString() {

        $arr = [];
        foreach ($this as $rule) {
            $row = [ $rule->getRuleName(), trim($rule->getClause()) ];
            $arr[] = $row;
        }
        return (string) new Terminal\Table($arr);
    }

}
