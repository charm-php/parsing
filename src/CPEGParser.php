<?php
namespace Charm\Parsing;

use Charm\Parsing\Util\StringTools;
use Charm\Parsing\{
    Grammar,
    ParserInterface,
    Options,
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
        $state->setState($source);
        $state->globals = $initialState;

        $goal = $this->grammar[$this->goal];
        $vars = [];

        $result = $goal->getClause()->parse($state, $vars, []);

        if ($result !== false) {
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
            if ($node->result === false && $node->isPartialSuccess()) {
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

        $errorString = "Line $startLine, column $startCol: Failed parsing $startDesc, unexpected $endDesc";
        throw new ParseError($state->source, $failingNode->offset, "Failed parsing $startDesc on :location, unexpected $endDesc");



        echo $errorString."\n";die();




        $describeNodes = function(array $nodes) {
            $names = [];
            foreach ($nodes as $node) {
                $name = $node->clause->getRule()->getRuleName();
                $description = $node->clause->getRule()->getDescription();
                $names[$name] = $description ?? json_encode($name);
            }
            return implode(" or ", $names);
        };


        // select the deepest partial node that got furthest
        $deepestPartialNode = $deepestPartialNodes;

        $failedParsing = $describeNodes($deepestPartialNodes);
        $failedStartOffset = $deepestPartialOffset;

        echo "Failed parsing $failedParsing at offset $failedStartOffset\n";




        

die();



        /**
         * Try to present a proper and helpful error message when parsing failed.
         */
        $fixException = function(ExceptionInterface $e, int $offset) use ($filename, $source, $lineOffset, $columnOffset) {
            $et = new Util\ExceptionTools($e);
            $st = new Util\StringTools($source);
            $lineNumber = $st->getLineNumberAt($offset);
            $et->setLine($lineNumber + $lineOffset);
            $et->setFileName($filename);
            return $e;
        };

        /**
         * prepare a string from the source file for being included in an error message
         */
        $fixSource = function(string $source) {
            if ($source === '') {
                return 'END-OF-FILE';
            }
            if (trim($source) === '') {
                return "whitespace";
            }
            $st = new StringTools($source);
            return '`'.$st->escape().'`';
        };

        /**
         * Prefer a labeled clause if we can find one
         */
        foreach (array_reverse(array_keys($this->cache)) as $offset) {
            $cache = $this->cache[$offset];

            $anyFailed = false;
            foreach ($cache as $parse) {
                if ($parse[2] === false) {
                    if ($parse[1]->getRule()->getDescription()) {
                        $anyFailed = true;
                        break;
                    }
                }
            }
            if (!$anyFailed) {
                continue;
            }

            usort($cache, function($a, $b) {
                return $a[0] - $b[0];
            });

            $shallowest = null;
            foreach ($cache as $depth) {
                if ($depth[1]->getRule()->getDescription()) {
                    if ($shallowest === null || ($shallowest[0] > $depth[0])) {
                        $shallowest = [ $depth[0], [ $depth[1] ] ];
                    } elseif ($shallowest[0] === $depth[0]) {
                        $shallowest[1][] = $depth[1];
                    }
                }
            }

            if ($shallowest !== null) {
                $descriptions = array_map(function($clause) {
                    return $clause->getRule()->getDescription();
                }, $shallowest[1]);
                $this->offset = $offset;
                $unexpected = $fixSource((new StringTools(substr($this->source, $offset, 1000)))->first());

echo "SUCCESSFULLY PARSED:
`".substr($this->source, 0, $offset)."`
FAILED:
`".substr($this->source, $offset, 10)."`

";
                throw $fixException(new SyntaxError("unexpected $unexpected, expecting ".implode(" or ", $descriptions), 2), $offset);
            }
        }
        /**
         * Prefer shallowest non-terminal clause
         */
        foreach (array_reverse(array_keys($this->cache)) as $offset) {
            $cache = $this->cache[$offset];
            usort($cache, function($a, $b) {
                return $a[0] - $b[0];
            });

            $shallowest = null;
            foreach ($cache as $depth) {
                if ($depth[1] instanceof Clause\NonTerminal) {
                    if ($shallowest === null || ($shallowest[0] > $depth[0])) {
                        $shallowest = [ $depth[0], [ $depth[1] ] ];
                    } elseif ($shallowest[0] === $depth[0]) {
                        $shallowest[1][] = $depth[1];
                    }
                }
            }

            if ($shallowest !== null) {
                $descriptions = array_map(function($clause) {
                    return $clause->getRule()->getRuleName();
                }, $shallowest[1]);
                $this->offset = $offset;
                $unexpected = $fixSource((new StringTools(substr($this->source, $offset, 1000)))->first());
                throw $fixException(new SyntaxError("2unexpected $unexpected, expecting `".implode("` or `", $descriptions)."`", 2), $offset);
            }
        }

        /**
         * Get longest successful parse as fallback error message
         */
        if ($this->longestParse >= 0) {
            $this->offset = $offset = $this->longestParse;
            $unexpected = $fixSource((new StringTools(substr($this->source, $offset, 1000)))->first());
            throw $fixException(new SyntaxError("3unexpected $unexpected", 0), $offset);
        }

        return null;
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

        if ($trace->result === false) {
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
