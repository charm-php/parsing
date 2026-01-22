<?php
namespace Charm\Parsing\Optimizer;

use Charm\Parsing\Grammar;

/**
 * Interface for grammar optimizers.
 *
 * Each optimizer performs a specific transformation on a grammar
 * to improve parsing performance or simplify the grammar structure.
 */
interface GrammarOptimizerInterface {
    /**
     * Optimize a grammar.
     *
     * @param Grammar $grammar The grammar to optimize
     * @return Grammar The optimized grammar (may be the same instance, mutated)
     */
    public function optimize(Grammar $grammar): Grammar;
}
