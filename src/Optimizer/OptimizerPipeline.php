<?php
namespace Charm\Parsing\Optimizer;

use Charm\Parsing\Grammar;

/**
 * Chains multiple grammar optimizers together.
 *
 * Optimizers are applied in the order they are added,
 * similar to middleware pipelines.
 *
 * Example:
 *   $pipeline = new OptimizerPipeline([
 *       new ConsecutiveTerminalsOptimizer(),
 *       new SomeOtherOptimizer(),
 *   ]);
 *   $optimized = $pipeline->optimize($grammar);
 */
class OptimizerPipeline implements GrammarOptimizerInterface {

    /** @var GrammarOptimizerInterface[] */
    private array $optimizers = [];

    /**
     * @param GrammarOptimizerInterface[] $optimizers Initial optimizers
     */
    public function __construct(array $optimizers = []) {
        foreach ($optimizers as $optimizer) {
            $this->add($optimizer);
        }
    }

    /**
     * Add an optimizer to the end of the pipeline.
     */
    public function add(GrammarOptimizerInterface $optimizer): self {
        $this->optimizers[] = $optimizer;
        return $this;
    }

    /**
     * Prepend an optimizer to the beginning of the pipeline.
     */
    public function prepend(GrammarOptimizerInterface $optimizer): self {
        array_unshift($this->optimizers, $optimizer);
        return $this;
    }

    /**
     * Get all optimizers in the pipeline.
     * @return GrammarOptimizerInterface[]
     */
    public function getOptimizers(): array {
        return $this->optimizers;
    }

    /**
     * Apply all optimizers in sequence.
     */
    public function optimize(Grammar $grammar): Grammar {
        foreach ($this->optimizers as $optimizer) {
            $grammar = $optimizer->optimize($grammar);
        }
        return $grammar;
    }
}
