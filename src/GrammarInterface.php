<?php
namespace Charm\Parsing;

use Traversable, ArrayAccess, Countable;

/**
 * The grammar allows access to each of the RuleInterface instances.
 *
 * If two rules with the same name is added, they should be combined
 * into one rule.
 *
 * Rules are available via ArrayAccess by their name.
 */
interface GrammarInterface extends Traversable, ArrayAccess, Countable {

    /**
     * Create a grammar from a set of rules.
     *
     * @param iterable<RuleInterface>
     */
    public function __construct(iterable $rules);

    /**
     * Return an optimized version of the grammar. If no optimizations are
     * performed, return a clone of this.
     */
    public function optimize(): static;

}
