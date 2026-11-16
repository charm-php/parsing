<?php
namespace Charm\Parsing;

use Charm\Parsing\Util\StringTools;
use Charm\Parsing\{
    Grammar,
    ParserInterface,
    Options,
    Signal,
    SequenceIgnore
};
use Charm\Parsing\Error\ParseError;
use Charm\Parsing\Util\CSI;

class CPEGParser implements ParserInterface {
    public $options;

    private $grammar;
    private $goal;

    /**
     * Properties only used while parsing.
     */
    public function __construct(Grammar $grammar, string $goal=null, Options $options=null) {

        $this->options = $options ?? new Options();

        if ($goal === null) {
            foreach ($grammar as $goal => $clause) {
                $this->goal = $goal;
                break;
            }
        } else {
            $this->goal = $goal;
        }

        $this->grammar = $grammar;
    }


    public function parseFile(string $filename, array $initialState=[]) {
        return $this->parse(file_get_contents($filename), $filename, 0, 0, $bindThis);
    }

    public function parse(string $source, string $filename, int $lineOffset = 0, int $columnOffset = 0, array $initialState=[]) {
        $state = new State();

    start_over_with_trace:
        $state->setStateString($source);
        $state->globals = $initialState;

        $goal = $this->grammar[$this->goal];
        $vars = [];

        $result = $goal->parse($state, $vars);

        if ($result !== Signal::MISS) {
//            echo "Got final result:\n".json_encode($result, JSON_PRETTY_PRINT)."\n";
            return $result;
        }

        if (!$state->trace) {
            $state->trace = true;
            goto start_over_with_trace;
        }

//        echo "\n\n\n".CSI::parse($this->dumpTrace($source, $state->getTrace()))."\n";
        /**
         * Traverse the trace to generate an error message.
         */
        $trace = $state->getTrace();

        /**
         * First find which node failed. We do this by searching for the
         * deepest partially successful node and by ignoring all successfully
         * parsed nodes.
         */
        $failingNode = null;

        // Search for candidates
        $deepestPartialNodes = [];
        $deepestPartialOffset = -1;
        foreach ($trace->visitPreOrder() as $node) {
            if ($node->result === Signal::MISS && $node->isPartialSuccess()) {
                if ($node->offset < $deepestPartialOffset) {
                    continue;
                }
                if ($node->offset > $deepestPartialOffset) {
                    $deepestPartialNodes = [];
                    $deepestPartialOffset = $node->offset;
                }
                if ($node->offset === $deepestPartialOffset) {
                    $deepestPartialNodes[] = $node;
                }
            }
        }

        if (count($deepestPartialNodes) === 0) {
            // We have no candidates, so we select the root node
            $failingNode = $trace;
        } elseif (count($deepestPartialNodes) === 1) {
            // There were only one candidate, so we select it
            $failingNode = $deepestPartialNodes[0];
        } else {
            // Which of the candidates is the best?
            $bestOffset = null;
            foreach ($deepestPartialNodes as $failingCandidate) {
                $candidateMaxOffset = $failingCandidate->getMaxLength() + $failingCandidate->offset;
                if ($bestOffset === null || $candidateMaxOffset > $bestOffset) {
                    $bestOffset = $candidateMaxOffset;
                    $failingNode = $failingCandidate;
                }
            }
        }

        $failingRule = $failingNode->clause->getRule();

        $st = new StringTools($source);
        $startOffset = $failingNode->offset;
        $startLine = 1 + $st->getLineNumberAt($failingNode->offset);
        $startCol = $st->getColumnNumberAt($failingNode->offset);
        $startDesc = $failingRule->getDescription() ?? json_encode($failingRule->getRuleName());

        $endOffset = $startOffset + $failingNode->getMaxLength();
        $endLine = 1 + $st->getLineNumberAt($endOffset);
        $endCol = $st->getColumnNumberAt($endOffset);
        if ($endOffset === $state->length) {
            $endDesc = "end-of-input";
        } elseif ($source[$endOffset] instanceof LexerToken) {
            $endDesc = "'".$source[$endOffset]->pattern->name."'";
        } else {
            $endDesc = json_encode($source[$endOffset]);
        }

        $expected = $this->expectedAt($trace, $endOffset);
        throw new ParseError($state->source, $failingNode->offset, "Failed parsing $startDesc on :location, unexpected $endDesc"
            . ($expected !== [] ? ', expected ' . implode(' or ', $expected) : ''));
    }

    /**
     * What was expected where the parse failed: the descriptions of the described rules entered at that offset,
     * and the terminals that failed there outside of such a rule (a description stands for what its terminals
     * expect). Same idea as the `expected` of a compiled parser's ParseError.
     *
     * @return list<string>
     */
    private function expectedAt(Trace $trace, int $offset): array {
        $expected = [];
        foreach ($trace->visitPreOrder() as $node) {
            if ($node->offset !== $offset) {
                continue;
            }
            $clause = $node->clause;
            if ($clause->getParent() === null) {
                $rule = $clause->getRule();
                if ($rule->getDescription() !== null && $clause->getMinByteLength() > 0) {
                    $expected[] = $rule->getDescription();
                }
                continue;
            }
            if ($node->result !== Signal::MISS || !$clause->isTerminal() || $clause->hasAndPredicate() || $clause->hasNotPredicate()
                || $clause instanceof Clause\Commit || $clause instanceof Clause\Nothing || $clause instanceof Clause\SOF) {
                continue;
            }
            // inside a described rule that was entered at this offset?
            for ($ancestor = $node->parent; $ancestor !== null; $ancestor = $ancestor->parent) {
                $c = $ancestor->clause;
                if ($ancestor->offset === $offset && $c->getParent() === null && $c->getRule()->getDescription() !== null && $c->getMinByteLength() > 0) {
                    continue 2;
                }
            }
            $expected[] = $clause instanceof Clause\EOF ? 'end of input'
                : ($clause instanceof Clause\Text ? (json_encode($clause->getText()) ?: '"' . $clause->getText() . '"') . ($clause->isCaseInsensitive() ? 'i' : '') : $clause->asString());
        }
        return array_values(array_unique($expected));
    }

    private function dumpTrace(string $source, Trace $trace): string {
        $clause = $trace->clause;
        $clauseStr = $clause->toGrammarString();

        $rule = $clause->getRule();
        $ruleName = $rule->getRuleName();
        $ruleDesc = $rule->getDescription();
        $ruleStr = '<!bblue>'.($ruleDesc !== null ? json_encode($ruleDesc). ' <!blue>'.$ruleName.'<!>' : $ruleName).'<!>';

        if ($clause->getParent() !== null) {
            $ruleStr = "[$ruleStr]";
        }

        if ($trace->result === Signal::MISS) {
            if ($trace->isPartialSuccess()) {
                $clauseStr = '<!bred>'.$clauseStr.' (partial)<!>';
            } else {
                $clauseStr = '<!red>'.$clauseStr.'<!>';
            }
            $result = '<!bred>FALSE<!>';
        } else {
            $clauseStr = '<!bwhite>'.$clauseStr.'<!>';
            $result = '<!bgreen>res='.json_encode($trace->result)."<!>";
        }

        $result = "$clauseStr $result <!byellow>{$trace->offset}-{$trace->endOffset}<!> rule=$ruleStr";

        if ($trace->isPartialSuccess()) {
            foreach ($trace->children as $child) {
                foreach (explode("\n", $this->dumpTrace($source, $child)) as $line) {
                    $result .= "\n  ".$line;
                }
            }
        }

        return $result;
    }
}
