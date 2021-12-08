<?php
namespace Charm;

use Charm\Parsing\Terminal\CSI;
use Charm\Parsing\{
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
    Terminal,
    NonTerminal,
    Sequence,
    OrderedChoice,
    Text,
    Regex,
    Any,
    EOF,
    SOF,
    Snippet,
    RegexInterface
};
use Charm\Vector;

class Parser implements ParserInterface {

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

    // traces every clause in a tree structure
    private $trace;
    private $traceNow;
    private $latestMissClauses = [];
    private $latestMissOffset;

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
        $this->grammar = $grammar->optimize();

        if ($this->options->trace) {
            echo CSI::clear();
        }
    }

    public function parse(string $source) {
        $this->source = $source;
        $this->offset = 0;
        $this->length = strlen($source);
        $this->latestMissClauses = [];
        $this->latestMissOffset = 0;

        $goal = $this->grammar[$this->goal];
        $vars = [];
        $result = $goal->getClause()->parse($this, $vars, []);
return $result;

        if (is_array($result)) {
            return $result;
            array_walk_recursive($result, function(&$e) {
                if ($e instanceof Result) {
                    $e = $e->value;
                }
            });
            echo CSI::clear();
            return $result;
        } elseif ($result === false) {
            sleep(4);
            fwrite(STDERR, CSI::clear()."
-----------------------------------------------------
Parse error at ".$this->latestMissOffset.":
Error position: `".str_replace("\n", "\\n", substr($this->source, $this->latestMissOffset - 20, 20))."` (HERE) `".str_replace("\n", "\\n", mb_substr(substr($this->source, $this->latestMissOffset, 100), 0, 20))."`
Expected:");
            foreach ($this->latestMissClauses as $clause) {
                fwrite(STDERR, "
                - ".str_replace("\n", "\\n", mb_substr($clause, 0, 30))." (".$clause->getClauseName().")");
            }
            fwrite(STDERR, "
-----------------------------------------------------
");
        }
        return $result;
    }

    public function renderStep($step) {
        if (!$this->options->trace) {
            return;
        }
        $res = '';
        $res .= CSI::cursorTo(0,0);
        $offset = $this->offset;
        $prevLength = min(40, $offset);
        $txtLeft = str_pad(str_replace("\n", "\\n", mb_substr(substr($this->source, $this->offset - $prevLength, $prevLength), -30)), 30, " ", STR_PAD_LEFT);
        $txtRight = str_replace("\n", "\\n", mb_substr(substr($this->source, $this->offset, 80), 0, 30));

        $res .= "<!green>$txtLeft<!underline white>$txtRight<!><!>".CSI::clearRight()."\n\n";

        foreach ($this->getGrammar() as $rule) {
            $res .= $this->renderRule($rule, $step);
        }
        $res = str_replace("\n", "\n".CSI::clearRight(), $res);

        return CSI::parse($res);
    }

    private function renderRule($rule, $step) {
        $res = '';
        if ($step->clause->getRule() === $rule) {
            $linePrefix = '<!bwhite>*<!> ';
        } else {
            $linePrefix = "  ";
        }
        $res .= $linePrefix." ".$rule->getRuleName();

        if ($rule->getClause() instanceof Sequence || $rule->getClause() instanceof OrderedChoice) {
            $first = true;
            $totalLength = 0;
            foreach ($rule->getClause() as $clause) {
                $totalLength += mb_strlen($clause)."   ";
            }
            $multiLine = $totalLength >= 80;
            foreach ($rule->getClause() as $clause) {
                if ($first) {
                    if ($multiLine) {
                        $res .= "\n  = ";
                    } else {
                        $res .= " = ";
                    }
                    $first = false;
                } else {
                    if ($rule->getClause() instanceof OrderedChoice) {
                        if ($multiLine) {
                            $res .= "  / ";
                        } else {
                            $res .= " / ";
                        }
                    } else {
                        if ($multiLine) {
                            $res .= "    ";
                        } else {
                            $res .= " ";
                        }
                    }
                }
                $res .= $this->renderClause($clause, $step);
                if ($multiLine) {
                    $res .= "\n";
                }
            }
            $res .= "\n";
        } else {
            $res .= " = ";
            if ($rule->getClause() === $step->clause) {
                $res .= '<!bteal>'.$this->renderClause($rule->getClause(), $step).'<!>';
            } else {
                $res .= $this->renderClause($rule->getClause(), $step);
            }
            $res .= "\n";
        }
        return $res;
    }

    private function renderClause($clause, $step) {

        $ancestor = false;
        $ancestorDepth = 0;
        $ancestorResult = null;

        $here = $step;
        while($here->parent !== null) {
            if ($here->parent->clause === $clause) {
                $ancestor = true;
                $ancestorResult = $here->parent->result;
                break;
            } else {
                $ancestorDepth++;
                $here = $here->parent;
            }
        }

        if ($clause instanceof \Traversable) {

            $countVisible = 0;
            foreach ($clause as $subClause) {
                if ($subClause instanceof Snippet) {
                    continue;
                }
                $countVisible++;
            }

            $parts = [];
            foreach ($clause as $subClause) {
                $parts[] = $this->renderClause($subClause, $step);
            }
            if ($clause instanceof Sequence) {
                $line = implode(" ", $parts);
            } elseif ($clause instanceof OrderedChoice) {
                $line = implode(" / ", $parts);
            } else {
                $line = implode("!!!!!!", $parts);
            }
            if ($clause->getParent() !== null && $countVisible > 1) {
                $line = "( ".$line." )";
            }
            if ($clause === $step->clause) {
                if ($step->result === false) {
                    return '<!bred>'.$line.'<!>';
                } elseif ($step->result === "\0") {
                    return '<!byellow>'.$line.'<!>';
                } else {
                    return '<!bgreen>'.$line.'<!>';
                }
            } elseif ($ancestor) {
                if ($ancestorResult === "\0") {
                    return "<!blue>$line<!>";
                } else {
                    return '<!bgreen>'.$line.'<!>';
                }
            }
            return $line;
        } elseif ($clause === $step->clause) {
            if ($step->result === false) {
                return '<!bred>'.$clause.'<!>';
            } elseif ($step->result === "\0") {
                return '<!byellow>'.$clause.'<!>';
            } else {
                return '<!bgreen>'.$clause.'<!>';
            }
        } elseif ($ancestor) {
            return '<!byellow>'.$clause.'<!>';
        } else {
            return (string) $clause;
        }
    }

    public function traceStart(Clause $clause, int $offset) {
echo "Start: '$clause'\n";
        if (!$this->options->trace) {
            return;
        }
        $next = new class($clause, $offset) {
            public
                $ts,
                $offset,
                $clause,
                $parent,
                $children = [],
                $result = "\0";
            public function __construct($clause, $offset) {
                $this->clause = $clause;
                $this->offset = $offset;
            }
            public function __debugInfo() {
                return [
                    'term' => $this->clause->getClauseName(),
                    'result' => $this->result,
                    'children' => $this->children,
                ];
            }
        };
        if ($this->traceNow !== null) {
            if (null !== ($referrerShouldBe = $clause->getParent())) {
                if ($referrerShouldBe !== $this->traceNow->clause) {
                    throw new InternalError("Expected referrer for {$clause->getClauseName()} to be {$referrerShouldBe->getClauseName()} but referrer seems to be {$this->traceNow->clause->getClauseName()}");
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
        usleep(100000);
    }

    public function traceEnd(Clause $clause, $result) {
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
        $this->traceNow->result = $result;
        echo $this->renderStep($this->traceNow);
        if ($result === false) {
            usleep(100000);
        }
        usleep(100000);
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

    private function tokenize(string $source) {
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

    /**
     * Parse a PEG style grammar and return a parser
     */
    public static function fromString(string $grammar, Options $options=null): static {
        return new static(Grammar::fromString($grammar), null, $options);
    }


}
