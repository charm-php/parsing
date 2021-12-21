<?php
namespace Charm\Parsing;

use Charm\Parsing\Util\CSI;
use Charm\Parsing\Util\GrammarTools;
use Charm\Parsing\Util\StringTools;
use Charm\Parsing\{
    Trace,
    Debug,
    ParseError,
    Token,
    Grammar,
    Clause,
    Rule,
    ParserInterface,
    Options,
    Hit,
    Result,
    InternalError
};
use Charm\Parsing\Clause\{
    Any,
    EOF,
    Identifier,
    NonTerminal,
    OrderedChoice,
    Pratt,
    RegexInterface,
    Regex,
    Sequence,
    Snippet,
    SOF,
    Terminal,
    Text
};
use Charm\Vector;
use Charm\Map;

class CPEGParser implements ParserInterface {

    public $options;
    private $originalGrammar;
    private $grammar;
    private $goal;

    /**
     * Properties only used while parsing.
     */

    /**
     * Source code of the string being currently parsed.
     */
    public $source;

    /**
     * Offset we're currently parsing at
     */
    public $offset;

    /**
     * The total length of the string we're currently parsing
     */
    public $length;

    /**
     * A list of tokens when parsing in tokenized mode
     */
    public $tokens;

    public $filename;

    // traces every clause in a tree structure
    private $trace;
    private $traceNow;

    // caches [$offset][(string) $clause][] = $result
    public $cache = [];
    private $longestParse = -1;

    public function __construct(Grammar $grammar, string $goal=null, Options $options=null) {

        $this->options = $options ?? new Options();
        $this->originalGrammar = $grammar;
        if ($goal === null) {
            foreach ($this->originalGrammar as $goal => $clause) {
                $this->goal = $goal;
                break;
            }
        } else {
            $this->goal = $goal;
        }

        $this->grammar = $this->optimizeGrammar($grammar);

/*
        $tool = new GrammarTools($this->grammar);
        $tool->checkParentRefs();
*/
    }

    protected final function getSource(): ?string {
        return $this->source;
    }


    /**
     * Parse a PEG style grammar and return a parser
     */
    public static function fromString(string $grammar, Options $options=null): static {
        return new static(Grammar::fromString($grammar), null, $options);
    }

    /**
     * Optimize and pre-process the grammar for performance and handling left recursion
     */
    private function optimizeGrammar(Grammar $grammar) {
        $g = [];
        $optimize = function(Clause $clause) use (&$optimize, &$g) {
            if ($clause instanceof Sequence) {

                // sequence with only one member and no modifier - we can skip the sequence
                if (count($clause) === 1 && $clause[0]->getModifier() === Clause::NONE) {
                    $result = clone $clause[0];
                    $result->setName($clause->getName());
//                    $result->setModifier($clause->getModifier());
                    return $optimize($result);
                }
            }

            if ($clause instanceof OrderedChoice) {
                // ordered choice with only one member, should not happen
                if (count($clause) === 1 && $clause[0]->getModifier() === Clause::NONE) {
                    $result = clone $clause[0];
                    $result->setName($clause->getName());
                    $result->setModifier($clause->getModifier());
                    return $optimize($result);
                }
            }

            if ($clause instanceof Identifier) {
                // identifiers linking to rules with only a terminal can be imported directly
                if (isset($g[$clause->getIdentifier()])) {
                    $target = $g[$clause->getIdentifier()];
                    if ($target instanceof Terminal) {
                        if ($target->getModifier() === Clause::NONE) {
                            $result = $optimize($target);
                            $result->setName($clause->getName());
                            $result->setModifier($clause->getModifier());
                            return $result;
                        }
                    }
                }
            }

            if (is_iterable($clause)) {
                // recursively optimize sub-clauses
                foreach ($clause as $k => $child) {
                    $clause[$k] = $optimize($child);
                }
            }

            return clone $clause;

        };

        // pass 1
        foreach ($grammar as $rule) {
            $g[$rule->getRuleName()] = $optimize($rule->getClause());
        }

        // pass 2 (optimization of pointers to other rules can't work in the first pass)
        foreach ($g as $ruleName => $clause) {
            $g[$ruleName] = $optimize($clause);
        }

        // function detects if a clause has direct left recursion
        $isLeftRecursive = function(Clause $clause, string $ruleName) use (&$isLeftRecursive) {
            // checks all sub-clauses for left recursion, with special handling of OrderedChoice clauses
            if ($clause instanceof Identifier) {
                if ($clause->getIdentifier() === $ruleName) {
                    return true;
                } else {
                    return false;
                }
            } elseif ($clause instanceof OrderedChoice) {
                foreach ($clause as $choice) {
                    if ($isLeftRecursive($choice, $ruleName)) {
                        return true;
                    }
                }
                return false;
            } elseif ($clause instanceof \Traversable) {
                return $isLeftRecursive($clause[0], $ruleName);
            } else {
                return false;
            }
        };

        // identify left-recursive rules
        $leftRecursiveRules = [];
        foreach ($g as $ruleName => $clause) {
            if ($isLeftRecursive($clause, $ruleName)) {
                $leftRecursiveRules[] = $ruleName;
            }
        }

        // replace any left recursive rules with a Pratt clause
        foreach ($leftRecursiveRules as $ruleName) {
            $g[$ruleName] = new Pratt($g[$ruleName], $ruleName);
        }

        return new Grammar((function() use ($g, $grammar) {
            foreach ($g as $n => $c) {
//                Debug::validateClauseTree($c);
                $rule = new Rule($n, $c);
                $oldRule = $grammar[$n];
                if ($oldRule->getDescription()) {
                    $rule->setDescription($oldRule->getDescription());
                }
                yield $rule;
            }
        })(), $grammar->filename);
    }

    public function parseFile(string $filename) {
        return $this->parse(file_get_contents($filename), $filename);
    }

    public function parse(string $source, string $filename = null) {
        $this->source = $source;
        $this->offset = 0;
        $this->length = strlen($source);
        $this->lastRenderStep = null;
        $this->filename = $filename;
        $this->state = [];

        $goal = $this->grammar[$this->goal];
        $vars = [];

        try {
            $result = $goal->getClause()->parse($this, $vars, []);

            if ($this->options->trace) {
                echo "\n";
            }

            if ($result !== false) {
                return $result;
            }
        } catch (ExceptionInterface $e) {
            die("Exception thrown while parsing");
        }

        /**
         * Try to present a proper and helpful error message when parsing failed.
         */
        $fixException = function(ExceptionInterface $e, int $offset) use ($filename, $source) {
            $et = new Util\ExceptionTools($e);
            $st = new Util\StringTools($source);
            $lineNumber = $st->getLineNumberAt($offset);
            $et->setLine($lineNumber);
            if ($filename !== null) {
                $et->setFileName($filename);
            } else {
                $rc = new \ReflectionClass($this);
                $myFileName = $rc->getFileName();
                $et->setFileName($myFileName.' : parsed source');
            }
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
print_r(array_keys($this->cache));
        foreach (array_reverse(array_keys($this->cache)) as $offset) {
            $cache = $this->cache[$offset];

            $anyFailed = false;
            foreach ($cache as $parse) {
                if ($parse[2] === false) {
                    if ($parse[1]->getRule()->getDescription()) {
echo "found rule ".$parse[1]->getRule()->getDescription()." at offset $offset\n";
                        $anyFailed = true;
                    }
                    break;
                }
            }
            if (!$anyFailed) {
echo "no failed clauses with description at $offset\n";
                continue;
            }

echo "Offset $offset is the best candidate\n";

            usort($cache, function($a, $b) {
                return $a[0] - $b[0];
            });

var_dump($cache);die();



//var_dump($cache);die();

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
                throw $fixException(new SyntaxError("1unexpected $unexpected, expecting ".implode(" or ", $descriptions), 2), $offset);
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
                if ($depth[1] instanceof NonTerminal) {
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

    public function &getState(): array {
        return $this->state;
    }

    public function renderStep(Trace $step) {
        if ($step->clause instanceof NonTerminal && $step->haveResult && $step->result === false) {
            return;
        }
        $width = 250;
        $depth = 0;
        $t = $step;
        while ($t->parent) {
            if ($t->parent->clause instanceof Identifier) {
                $depth++;
            }
            $t = $t->parent;
        }
        $indentStr = str_repeat(' ', $depth);
        $matchedBytes = $this->offset - $step->offset;
        $source = $this->renderSourceAt($this->offset, min(40, floor($width / 2)), $matchedBytes);

        $wrapColor = function(Clause $clause, string $text) use ($step) {
            if ($step->clause === $clause) {
                if ($clause instanceof NonTerminal) {
                    // the currently active clause
                    if (!$step->haveResult) {
                        return "<!byellow>$text<!>";
                    } elseif ($step->result !== false) {
                        return "<!bgreen>$text<!>";
                    } else {
                        return "<!bred>$text<!>";
                    }
                } else {
                    // the currently active clause
                    if (!$step->haveResult) {
                        return "<!byellow>$text<!>";
                    } elseif ($step->result !== false) {
                        return "<!bgreen>$text<!>";
                    } else {
                        return "<!bred>$text<!>";
                    }
                }
            }
            $isClauseAncestor = false;
            if ($clause instanceof NonTerminal) {
                // only non-terminals can be ancestor
                $_clause = $step->clause;
                while (null !== ($_clause = $_clause->getParent())) {
                    if ($_clause === $clause) {
                        $isClauseAncestor = true;
                        break;
                    }
                }
            }
            $myParent = $clause->getParent();
            if ($myParent === null) {
                return $text;
            }
            $siblingStep = $step;
            while (null !== ($siblingStep = $siblingStep->parent)) {
                if ($siblingStep->clause === $myParent) {
                    foreach ($siblingStep->children as $child) {
                        if ($child->clause === $clause) {
                            $siblingStep = $child;
                            break 2;
                        }
                    }
                }
            }

            if ($siblingStep && $siblingStep->clause === $clause) {
                if (!$siblingStep->haveResult) {
                    return $text;
                } elseif ($siblingStep->result !== false) {
                    return "<!green>$text<!>";
                } else {
                    return "<!red>$text<!>";
                }
            }

            $_parent = $step->clause;
            while (null !== ($_parent = $_parent->getParent())) {
                if ($_parent === $clause->getParent()) {
                    $isClauseSibling = true;
                    break;
                }
            }
            return $text;
        };

        $rClause = function(Clause $clause) use ($wrapColor, &$rClause){
            if ($clause instanceof Terminal || $clause instanceof Identifier) {
                return $wrapColor($clause, $clause);
            } else {
                $parts = [];
                foreach ($clause as $subClause) {
                    $subRender = $rClause($subClause);
                    if ($subClause instanceof Sequence || $subClause instanceof OrderedChoice) {
                        $subRender = "($subRender)";
                    }
                    if ($subClause->isKleeneCross()) {
                        $subRender = "$subRender+";
                    } elseif ($subClause->isKleeneStar()) {
                        $subRender = "$subRender*";
                    } elseif ($subClause->isOptional()) {
                        $subRender = "$subRender?";
                    }
                    $parts[] = $subRender;
                }
                if ($clause instanceof OrderedChoice) {
                    $result = implode("|", $parts);
                } else {
                    $result = implode(" ", $parts);
                }
                $result = $wrapColor($clause, $result);
                return $result;
            }
        };

        $rootClause = $step->clause;
        $path = [ $rootClause ];
        while ($rootClause->getParent() !== null) {
            $rootClause = $rootClause->getParent();
            $path[] = $rootClause;
        }
        $availableWidth = $width - CSI::strlen("$source ");
        $text = $indentStr."<!teal>".str_pad(mb_substr($rootClause->getRule()->getRuleName(), 0, 15), 15)."<!> ".$rClause($rootClause);
        $indent = 0;
        while ($path !== [] && CSI::strlen($text) > $availableWidth) {
            $indent++;
            $rootClause = array_pop($path);
            $text = $indentStr.'|'.str_repeat(" ", $indent*2-1).$rClause($rootClause);
        }
        $result = CSI::substr($source." ".$text, 0, $width);
        $strippedResult = CSI::strip($result);
        if ($strippedResult === $this->lastRenderStep) {
            $result = "\r".$result;
        } else {
            $result = "\n".$result;
        }
        $this->lastRenderStep = $strippedResult;
        return CSI::parse($result);

        return CSI::parse(CSI::substr("$source ".$rootClause->getRule()->getRuleName()." ".$rClause($rootClause), 0, $width))."\n";

        if ($step->clause instanceof NonTerminal) {
            return CSI::parse(CSI::substr("N1 $source $indent ".$rClause($step), 0, $width))."\n";
        } else {
            if ($step->parent) {
                return CSI::parse(CSI::substr("T1 $source $indent".$rClause($step->parent), 0, $width))."\n";
            } else {
                return "WEIRD\n";
            }
        }


        if ($step->haveResult) {
            $bytesParsed = $this->offset - $step->offset;

            if ($step->clause instanceof NonTerminal) {
                return CSI::parse(CSI::substr("N1 $source $indent".$rClause($step), 0, $width))."\n";
                if ($step->result !== false) {
                    return CSI::parse(CSI::substr("N1 $source <!bgreen>".$indent.$step->clause."".($bytesParsed > 0 ? " ($bytesParsed bytes)" : "")."<!>", 0, $width))."\n";
                } else {
                    return CSI::parse(CSI::substr("N2 $source <!bred>".$indent." ".$step->clause."<!>", 0, $width))."\n";
                }
            } else {
                if ($step->parent) {
                    return CSI::parse(CSI::substr("N1 $source $indent".$rClause($step->parent), 0, $width))."\n";
                }

                if ($step->result !== false) {
                    return CSI::parse(CSI::substr("--T2 $source <!bgreen>".$indent.$step->clause."".($bytesParsed > 0 ? " ($bytesParsed bytes)" : "")."<!>", 0, $width))."\n";
                } else {
                    return CSI::parse(CSI::substr("--T3 $source <!bred>{$indent}{$step->clause}<!>", 0, $width))."\n".$tail;
                }
            }
        } else {
            if ($step->clause instanceof NonTerminal) {
                return CSI::parse(CSI::substr("N3 $source <!bblue>{$indent}{$step->clause}<!>", 0, $width)).CSI::clearRight()."\n";
            } else {
                return CSI::parse(CSI::substr("T5 $source <!byellow>{$indent}{$step->clause}<!>", 0, $width)).CSI::clearRight()."\n";
            }
        }
        return CSI::parse($line);
    }

    private function renderSourceAt(int $offset, int $maxLength=70, int $matchedLength=0) {
        $start = max(0, $offset - floor($maxLength / 2));
        $offset -= $start;
        $source = self::substr($this->source, $start, $maxLength);

        $chunk1 = mb_substr($source, 0, $offset - $matchedLength);
        $chunk2 = mb_substr($source, $offset - $matchedLength, $matchedLength);
        $leftL1 = mb_strlen($chunk1.$chunk2);
        $leftL2 = mb_strlen(str_replace("\n", "\\n", "$chunk1$chunk2"));
        $leftExtra = $leftL2 - $leftL1;
        $left = str_replace("\n", "\\n", "<!green>$chunk1<!><!bwhite underline>$chunk2<!>");
        if ($leftExtra > 0) {
            $left =  CSI::substr($left, $leftExtra);
        }
        $chunk3 = mb_substr($source, $offset, 1);
        $chunk4 = mb_substr($source, $offset + 1);
        $right = str_replace("\n", "\\n", "<!byellow>$chunk3<!>$chunk4");
        return CSI::substr($left.$right, 0, $maxLength);
    }

    private static function escapeString(string $source) {
        return strtr($source, [
            "\n" => "\\n",
            "\0" => "\\0",
        ]);
    }

    private static function substr(string $source, int $startOffset, int $length = null) {
        $source = substr($source, $startOffset, ($length ?? 10000) * 4);
        $source = mb_substr($source, 0, $length);
        return $source;
    }

    private $stack = [], $stackDepth = 0, $currentOffset, $currentClause, $failOffset = -1, $successOffset = -1, $successClauses = [];
    private $ruleStack = [];
    public function traceStart(Clause $clause, int $offset) {
        // optimization; instead of creating new arrays every time the stack moves up or down, we write directly into an existing array
        // and we rely on PHP's implicit array creation
        $this->stack[$this->stackDepth][0] = $clause;
        $this->stack[$this->stackDepth++][1] = $offset;

        // record which clause is currently being traced
        if (!$this->options->trace) {
            return;
        }
        $next = new Trace($clause, $offset);
        if ($this->traceNow !== null) {
            if (null !== ($expectedReferrer = $clause->getParent())) {
                if (false && $expectedReferrer !== $this->traceNow->clause) {
/*
echo "\n------------------------------\n";
                    var_dump($expectedReferrer);
echo "\n------------------------------\n";
                    var_dump($this->traceNow->clause);
die();
*/
                    $current = "".$clause->getClauseName()." (object id=".spl_object_id($clause).")";
                    $expected = "".$expectedReferrer->getClauseName()." (object id=".spl_object_id($this->traceNow->clause).")";
                    $actual = "".$this->traceNow->clause->getClauseName()." (object id=".spl_object_id($this->traceNow->clause).")";

echo "


    Starting to parse `$clause`.
      - parent is `".$clause->getParent()."` ".spl_object_id($clause->getParent())."
      - referrer is `".$this->traceNow->clause."` ".spl_object_id($this->traceNow->clause)."
";
die("ERROR");
                    throw new InternalError("Expected referrer for '$current' to be '$expected' but actual referrer is '$actual'");
                }
            }
            $next->parent = $this->traceNow;
            $this->traceNow->children[] = $next;
            $this->traceNow = $next;
        } elseif ($this->trace === null) {
            $this->trace = $next;
            $this->traceNow = $next;
        } else {
            throw new InternalError("There is a problem");
        }
        echo $this->renderStep($this->traceNow);
    }

    public function traceEnd(Clause $clause, $result) {
        $this->stackDepth--;
        $oldStack = &$this->stack[$this->stackDepth];   // the stack entry by the clause that started the trace
        if ($clause !== $oldStack[0]) {
            throw new InternalError("Did not receive a 'traceEnd' call from '".get_class($this->stack[$this->stackDepth][0])."'. '".get_class($clause)."' called 'traceEnd' first.");
        }

        if ($result !== false) {
            if ($this->longestParse < $this->offset) {
                $this->longestParse = $this->offset;
            }
        }

//        if ($result === false && $clause->getParent() === null) {
        if ($clause->getParent() === null) {
            $this->cache[$oldStack[1]][] = [ $this->stackDepth, $clause, $result ];
        }

        if ($result === false) {
            if ($this->currentOffset > $this->failOffset) {
                // failure at a new offset
                $this->failClauses = [];
            }
            if ($this->currentOffset >= $this->failOffset) {
                // failure at new and same offset
                $this->failClauses[] = $clause;
                $this->failOffset = $this->currentOffset;
            }
        } else {
            $this->successClauses[$this->currentOffset."-".$this->offset][] = [ $result, $clause ];
/*
            if ($this->offset > $this->successOffset) {
                echo "success with $clause at ".$this->offset."\n";
                $this->successOffset = $this->offset;
                // success at new offset
                $this->successClauses = [ $clause ];
            } elseif ($this->offset === $this->successOffset) {
                $this->successClauses[] = $clause;
            }
*/
        }
        if (!$this->options->trace) {
            return;
        }
        if ($this->traceNow->clause !== $clause) {
            throw new InternalError("Inconsistency in trace! {$this->traceNow->clause->getClauseName()} didn't end trace");
        }
        if ($result === false && $this->traceNow->offset !== $this->offset) {
            echo "ERROR IN CLAUSE ".$this->traceNow->clause." OFFSET IS WRONG AFTER FAILING!\n";
            sleep(5);
        }
        $this->traceNow->haveResult = true;
        $this->traceNow->result = $result;
        echo $this->renderStep($this->traceNow);
        $this->traceNow = $this->traceNow->parent;
    }

    public function warn(string $message, int $offset=null) {
        if ($offset !== null) {
            fwrite(STDERR, "warn@$offset $message\n");
        } else {
            fwrite(STDERR, "warn $message\n");
        }
    }

    public function getGrammar(): Grammar {
        return $this->grammar;
    }

    private function niu_tokenize(string $source) {
        // extract all regular expressions and tokens from the grammar
        $regexToNames = [];
        $nameToClause = [];
        $regexGroups = [];
        $regexNames = [];
        $queue = new Vector();

        // instead of recursion use a queue
        foreach ($this->grammar as $rule) {
            $queue->push($rule->getClause());
        }

        // recursively find all terminals
        while (count($queue) > 0) {
            $clause = $queue->shift();
            if ($clause instanceof RegexInterface) {
                $count = isset($regexGroups[$clause->getKind()]) ? count($regexGroups[$clause->getKind()]) : 0;
                $clauseName = '_'.$clause->getKind().'_'.$count;

                $regexToNames[$clause->getRegex()][] = $clauseName;
                $nameToClause[$clauseName] = $clause;

                $regexGroups[$clause->getKind()][] = [ $clause, $clauseName ];
            }
            if ($clause instanceof \Traversable) {
                foreach ($clause as $subClause) {
                    $queue->push($subClause);
                }
            }
        }

        $patterns = [];
        foreach (['Regex', 'Text'] as $kind) {
            $clauses = $regexGroups[$kind];
            usort($clauses, function($a, $b) {
                $av = $a[0]->getRegexPriority();
                $bv = $b[0]->getRegexPriority();
                return $av - $bv;
            });

            foreach ($clauses as $c) {
                $patterns[] = '(?<'.$c[1].'>'.$c[0]->getRegex().')';
            }
        }
        $patterns[] = '(?<__FAIL__>[\S\s])';

        $regex = implode("|", $patterns);
        $count = preg_match_all('/'.$regex.'/u', $source, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        $tokens = [];
        foreach ($matches as $match) {
            $clauseName = array_search($match[0], array_slice($match, 1));
            if ($clauseName === '__FAIL__') {
                throw new ParseError("Unrecognized '".$match[0][0]."' at offset ".$match[0][1]);
            }
            $clause = $nameToClause[$clauseName];
            $regex = $clause->getRegex();
            $aliases = $regexToNames[$regex];

            if (count($aliases) > 1) {
                $a = [];
                foreach ($aliases as $clauseName) {
                    $a[] = new Token($match[0][0], $clauseName, $match[0][1], [ 'clause' => $nameToClause[$clauseName] ]);
                }
                $tokens[] = $a;
            } else {
                $tokens[] = new Token($match[0][0], $clauseName, $match[0][1], [ 'clause' => $clause ]);
            }
        }

        return $tokens;
    }

}
