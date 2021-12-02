<?php
namespace Charm;

use Charm\Parsing\{
    Grammar,
    Clause,
    Rule,
    ParserInterface,
    Options,
    Hit,
    Result
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
    SOF
};

class Parser implements ParserInterface {

    public $options;
    private $originalGrammar;
    private $grammar;
    private $goal;
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
    }

    public function trace(Clause $clause, string $message=null, string $linePrefix='') {
        if (!$this->options->trace) {
            return;
        }
        $prefix = ($clause->hasAndPredicate() ? "&" : "").($clause->hasNotPredicate() ? "!" : "");
        $suffix = ($clause->isKleeneCross() ? "+" : "") . ($clause->isKleeneStar() ? "*" : "") . ($clause->isOptional() ? "?" : "");
        $kind = str_pad($prefix.$clause->getKind().$suffix."@".$this->offset, 20);
        $linePrefix = str_pad($linePrefix, 10)." ";

        $rule = $clause->getRule();
        if ($rule) {
            $ruleName = $rule->getRuleName() ?? '';
        } else {
            $ruleName = '*NULL';
        }
        $linePrefix .= str_pad($ruleName, 12)." ";

        $next = mb_substr(str_replace("\n", "\\n", substr($this->source, $this->offset, 100)), 0, 7);
        if (mb_strlen($next) === 7) {
            $next .= "..";
        }
        $linePrefix .= str_pad($this->offset, 4, " ", STR_PAD_LEFT)." ".str_pad($next, 10);

        $depth = 0;
        $prev = null;
        $stack = [];
        foreach (debug_backtrace() as $t) {
            if (!empty($t['object']) && $t['object'] !== $prev && $t['object'] instanceof Clause) {
                $stack[] = $t['object'];
                $depth++;
                $prev = $t['object'];
            }
        }
        $linePrefix .= str_pad($depth, 5);
        $indent = str_repeat("  ", $depth);


        if ($message !== null) {
            fwrite(STDERR, $linePrefix.$kind.$indent." $message\n");
        } else {
            fwrite(STDERR, $linePrefix.$kind.$indent." $clause\n");
        }
    }

    public function traceMiss(Clause $clause, int $offset, string $reason=null) {
        if ($offset > $this->latestMissOffset) {
            $this->latestMissOffset = $offset;
            $this->latestMissClauses = [];
        }
        if ($offset >= $this->latestMissOffset) {
            $this->latestMissClauses[] = $clause;
        }
        if (!$this->options->trace) {
            return;
        }
        $o = $this->offset;
        $this->offset = $offset;
        if ($this->offset === $this->length) {
            $expectedToMatch = "*EOF";
        } else {
            $expectedToMatch = str_replace("\n", "\\n", mb_substr(substr($this->source, $this->offset, 100), 0, 5));
        }
        $this->trace($clause, $reason ?? "Expected ".$clause." to match `".$expectedToMatch."`", "MISS");
        $this->offset = $o;

        $pos = '';
        $pRule = null;
        $prev = null;
        $stack = [];
        foreach (debug_backtrace() as $t) {
            if (!empty($t['object']) && $t['object'] !== $prev && $t['object'] instanceof Clause) {
                $stack[] = $t['object'];
                $prev = $t['object'];
            }
        }
        $stack = array_reverse($stack);
        $hadTerminal = false;
        foreach ($stack as $i => $s) {
            if ($s->getRule() !== $pRule) {
                $pRule = $s->getRule();
                $pos .= "$i ".$pRule->getRuleName(); // .": ".$pRule->getClause()->getKind();
            } else {
                $pos = rtrim($pos);
            }
            if ($s instanceof Terminal) {
                $hadTerminal = true;
            }
            if ($s === $pRule->getClause()) {
                continue;
            }
            $pos .= "\n    $s\n";
        }
        $pos = str_replace("\n", "\n    ", "    ".trim($pos));
        if ($hadTerminal) {
            fwrite(STDERR, $pos."\n\n\n");
        }

    }

    public function traceHit(Clause $clause, int $offset, $value) {
        if (!$this->options->trace) {
            return;
        }

        $value = json_encode($value);
        $o = $this->offset;
        $this->offset = $offset;
        $valueText = $value;
        if (is_int(strpos($valueText, "\n"))) {
            $valueText = str_replace("\n", "\\n", $valueText);
        }
        $this->trace($clause, $valueText, "HIT");
        $this->offset = $o;
        return;

        if ($value instanceof Hit) {
            $value = (string) $value;
        } elseif (is_string($value)) {
            $value = "`$value`";
        } elseif (is_array($value)) {
            foreach ($value as &$val) {
                if ($val instanceof Hit) {
                    $val = (string) $val;
                } elseif ($val === null) {
                    $val = "*NULL";
                } elseif (is_string($val)) {
                    $val = "`$val`";
                } else {
                    throw new \Exception("traceHit($clause) received invalid value: ".json_encode($val));
                }
            }
            unset($val);
            $value = '['.implode(",", $value).']';
        } elseif ($value === null) {
            $value = '*NULL';
        } else {
var_dump($value);die();
            throw new \Exception("traceHit($clause) received invalid value: ".json_encode($value));
        }
        $o = $this->offset;
        $this->offset = $offset;
        $this->trace($clause, "HIT $value");
        $this->offset = $o;
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

    /**
     * Properties only used while parsing. Thank god we're single threaded, although
     * we could've worked on a clone of the parser. ;-)
     */
    public $source;
    public $offset;
    public $length;

    public function parse(string $source) {
        $this->source = $source;
        $this->offset = 0;
        $this->length = strlen($source);
        $this->latestMissClauses = [];
        $this->latestMissOffset = 0;

        $goal = $this->grammar[$this->goal];
        $vars = [];
        $result = $goal->getClause()->parse($this, $vars);

        if (is_array($result)) {
            array_walk_recursive($result, function(&$e) {
                if ($e instanceof Result) {
                    $e = $e->value;
                }
            });
            return $result;
        } elseif ($result === false) {
            fwrite(STDERR, "
-----------------------------------------------------
Parse error at ".$this->latestMissOffset.":
Error position: `".str_replace("\n", "\\n", substr($this->source, $this->latestMissOffset - 20, 20))."` (HERE) `".str_replace("\n", "\\n", mb_substr(substr($this->source, $this->latestMissOffset, 100), 0, 20))."`
Expected:");
            foreach ($this->latestMissClauses as $clause) {
                fwrite(STDERR, "
                - ".str_replace("\n", "\\n", mb_substr($clause, 0, 30))." (".$clause->getRule()->getRuleName().")");
            }
            fwrite(STDERR, "
-----------------------------------------------------
");
        }
        return $result;
    }

    /**
     * Parse a PEG style grammar and return a parser
     */
    public static function fromString(string $grammar, Options $options=null): static {
        return new static(Grammar::fromString($grammar), null, $options);
    }


}
