<?php
namespace Charm\Parsing\Compiler\Portable;

use Charm\Parsing\Clause;
use Charm\Parsing\Clause\{Any, Commit, EOF, Identifier, Literal, Nothing, OrderedChoice, Regex, Sequence, SOF, Snippet, Text, Token};
use Charm\Parsing\Grammar;

/**
 * A grammar lowered to flat integer tables: the input of the C, JavaScript and Python generators.
 *
 * Every portable parser is the same small engine (written once per language) running over these tables,
 * which is what makes the three targets produce the same parse tree. The engine implements the semantics
 * of the PHP compiled parser (see `GrammarCompiler`), so anything a decision depends on that the PHP
 * compiler reads off the grammar (whether a sequence collapses, how deep a spread flattens, the
 * precedence of an alternative, the first bytes of a rule) is decided here and stored in the tables.
 *
 * Node table (stride 6): `op, mods, name, a, b, c`
 *  - `mods`: OPT, REP, AND, NOT, SPREAD, SPREAD1 (a spread flattens one level, not two)
 *  - `name`: string index of the capture label, or -1
 *  - TEXT: a = string (the bytes to match; lower-cased for CI), b = 1 if ASCII case-insensitive, c = expected-text string
 *  - REGEX: a = regex index, c = expected-text string
 *  - ANY: c = expected-text string
 *  - REF: a = rule, b = guard index or -1, c = 1 if the rule is %silent
 *  - PREF: a = rule (Pratt rule), b = minimum precedence, c = 1 if silent
 *  - SEQ: a = first kid in KIDS, b = count, c = flags (SEQ_COLLAPSE, SEQ_BUMP) | (index of the enclosing rule << 8)
 *  - PRATTOP: like SEQ; the first kid stands for the left operand (only its name and spread flags are used)
 *  - SNIP: a = expression (a portable snippet, always the last kid of a SEQ or PRATTOP)
 *  - CHOICE: a = first kid, b = count, c = flags (CH_BUMP)
 *
 * Rule table (stride 12): `body, kind, grown, name, desc, expect, silent, primStart, primCount, opStart, opCount, expname`
 */
final class Program
{
    public const OP_TEXT = 0, OP_REGEX = 1, OP_ANY = 2, OP_SOF = 3, OP_EOF = 4, OP_NOTHING = 5, OP_COMMIT = 6,
        OP_REF = 7, OP_SEQ = 8, OP_CHOICE = 9, OP_PREF = 10, OP_PRATTOP = 11, OP_SNIP = 12;
    public const E_NULL = 0, E_TRUE = 1, E_FALSE = 2, E_INT = 3, E_STR = 4, E_LABEL = 5, E_OFFSET = 6, E_LINE = 7, E_COLUMN = 8, E_LIST = 9, E_COALESCE = 10;
    public const M_OPT = 1, M_REP = 2, M_AND = 4, M_NOT = 8, M_SPREAD = 16, M_SPREAD1 = 32;
    public const SEQ_COLLAPSE = 1, SEQ_BUMP = 2, CH_BUMP = 1;
    public const NODE_STRIDE = 6, RULE_STRIDE = 12, PALT_STRIDE = 3;
    public const R_PRATT = 1;

    /** @var list<string> */
    public array $strings = [];
    /** @var list<int> */
    public array $nodes = [];
    /** @var list<int> */
    public array $kids = [];
    /** @var list<int> */
    public array $rules = [];
    /** @var list<int> */
    public array $palts = [];
    /** @var list<string> 32-byte bitsets */
    public array $guards = [];
    /** @var list<int> portable snippet expressions, stride 3: kind, x, y (see the E_ constants) */
    public array $exprs = [];
    /** @var list<int> list elements of E_LIST expressions, pairs: key string (-1: none), expression */
    public array $ekids = [];
    /** @var list<array> range lists */
    public array $sets = [];
    /** @var list<int> all regex bytecode */
    public array $rxcode = [];
    /** @var list<array{int,int}> per regex: start in rxcode, number of loop registers */
    public array $regexes = [];
    /** @var list<string> */
    public array $ruleNames = [];
    public int $goalDescription = 0;
    public bool $hasCommit = false;
    public string $filename = '';

    private Grammar $grammar;
    private array $ruleIndex = [];
    private array $stringIndex = [];
    private array $setIndex = [];
    private array $guardIndex = [];
    private array $pratt = [];
    private ?string $currentRule = null;

    public static function fromGrammar(Grammar $grammar): self
    {
        $p = new self();
        $p->build($grammar);
        return $p;
    }

    private function str(string $s): int
    {
        return $this->stringIndex[$s] ??= array_push($this->strings, $s) - 1;
    }

    private function set(array $ranges): int
    {
        $key = json_encode($ranges);
        return $this->setIndex[$key] ??= array_push($this->sets, $ranges) - 1;
    }

    private function guard(string $bytes): int
    {
        $bits = array_fill(0, 32, 0);
        foreach (str_split($bytes) as $b) {
            $bits[ord($b) >> 3] |= 1 << (ord($b) & 7);
        }
        $bin = pack('C*', ...$bits);
        return $this->guardIndex[$bin] ??= array_push($this->guards, $bin) - 1;
    }

    private function node(int $op, int $mods = 0, int $name = -1, int $a = 0, int $b = 0, int $c = 0): int
    {
        $id = intdiv(count($this->nodes), self::NODE_STRIDE);
        array_push($this->nodes, $op, $mods, $name, $a, $b, $c);
        return $id;
    }

    private function setNode(int $id, int $field, int $v): void
    {
        $this->nodes[$id * self::NODE_STRIDE + $field] = $v;
    }

    private function unsupported(string $what): never
    {
        $rule = $this->currentRule !== null ? "Rule {$this->currentRule}: " : '';
        throw new UnsupportedGrammar($rule . $what);
    }

    private function build(Grammar $grammar): void
    {
        $this->grammar = $grammar;
        $this->filename = (string) ($grammar->filename ?? '');
        if ($grammar->isTokenMode()) {
            throw new UnsupportedGrammar('The grammar uses token mode (@lexer ' . $grammar->getLexerClass() . '); the portable targets parse text only');
        }
        $i = 0;
        foreach ($grammar as $name => $rule) {
            $this->ruleIndex[$name] = $i++;
            $this->ruleNames[] = $name;
        }
        if ($i === 0) {
            throw new UnsupportedGrammar('The grammar has no rules');
        }
        foreach ($grammar as $name => $rule) {
            $clause = $rule->getClause();
            if ($clause instanceof OrderedChoice) {
                foreach ($clause as $alt) {
                    if ($this->isLeftRecursive($alt, $name)) {
                        $this->pratt[$name] = true;
                        break;
                    }
                }
            } elseif ($this->isLeftRecursive($clause, $name)) {
                $this->currentRule = $name;
                $this->unsupported('the rule is left-recursive but has no alternatives, so it can never match; write `' . $name . ' = ' . $name . ' ... | base`');
            }
        }
        foreach ($grammar as $name => $rule) {
            $this->currentRule = $name;
            $this->buildRule($name, $rule);
        }
        $this->currentRule = null;
        $first = null;
        foreach ($grammar as $name => $rule) {
            $first = $rule->getDescription() ?? $name;
            break;
        }
        $this->goalDescription = $this->str($first);
    }

    private function buildRule(string $name, \Charm\Parsing\Rule $rule): void
    {
        $clause = $rule->getClause();
        $isPratt = isset($this->pratt[$name]);
        $desc = $rule->getDescription();
        $row = [
            -1, $isPratt ? self::R_PRATT : 0, $this->grammar->isInLeftRecursiveCycle($name) ? 1 : 0, $this->str($name),
            $desc === null ? -1 : $this->str($desc),
            $desc !== null && $clause->getMinByteLength() > 0 ? 1 : 0,
            $clause->pragma('silent') !== null ? 1 : 0,
            0, 0, 0, 0, $this->str($desc ?? $name),
        ];
        if ($isPratt) {
            $prim = [];
            $ops = [];
            $n = count($clause);
            foreach ($clause as $index => $alt) {
                [$prec, $assoc] = $this->grammar->resolveAlternativePrecedence($alt, $name, $index, $n);
                if ($this->mustBeLeftRecursive($alt, $name)) {
                    $ops[] = [$this->buildPrattOp($alt, $name, $prec, $assoc === 'right'), $prec, $assoc === 'right' ? 1 : 0];
                } else {
                    $prim[] = [$this->containsRecursiveReference($alt, $name) ? $this->buildPrattPrimary($alt, $name, $prec) : $this->build_clause($alt), $prec, 0];
                }
            }
            $row[7] = intdiv(count($this->palts), self::PALT_STRIDE);
            $row[8] = count($prim);
            foreach ([...$prim, ...$ops] as $alt) {
                array_push($this->palts, ...$alt);
            }
            $row[9] = $row[7] + count($prim);
            $row[10] = count($ops);
        } else {
            $row[0] = $this->build_clause($clause);
        }
        array_push($this->rules, ...$row);
    }

    // ---- left recursion analysis (the same questions GrammarCompiler asks) -----------------------------

    private function isLeftRecursive(Clause $clause, string $rule, array $visited = []): bool
    {
        if (in_array($clause, $visited, true)) {
            return false;
        }
        $visited[] = $clause;
        if ($clause instanceof Identifier) {
            return $clause->getIdentifier() === $rule;
        }
        if ($clause instanceof Sequence) {
            foreach ($clause as $first) {
                return $this->isLeftRecursive($first, $rule, $visited);
            }
        } elseif ($clause instanceof OrderedChoice) {
            foreach ($clause as $choice) {
                if ($this->isLeftRecursive($choice, $rule, $visited)) {
                    return true;
                }
            }
        }
        return false;
    }

    private function mustBeLeftRecursive(Clause $clause, string $rule, array $visited = []): bool
    {
        if (in_array($clause, $visited, true)) {
            return false;
        }
        $visited[] = $clause;
        if ($clause instanceof Identifier) {
            return $clause->getIdentifier() === $rule;
        }
        if ($clause instanceof Sequence) {
            foreach ($clause as $first) {
                return $this->mustBeLeftRecursive($first, $rule, $visited);
            }
        } elseif ($clause instanceof OrderedChoice) {
            foreach ($clause as $choice) {
                if (!$this->mustBeLeftRecursive($choice, $rule, $visited)) {
                    return false;
                }
            }
            return true;
        }
        return false;
    }

    private function containsRecursiveReference(Clause $clause, string $rule): bool
    {
        if ($clause instanceof Identifier && $clause->getIdentifier() === $rule) {
            return true;
        }
        if ($clause instanceof Sequence) {
            foreach ($clause as $sub) {
                if ($this->containsRecursiveReference($sub, $rule)) {
                    return true;
                }
            }
        }
        return false;
    }

    // ---- nodes -------------------------------------------------------------------------------------------

    private function mods(Clause $c): int
    {
        $m = 0;
        $m |= $c->isOptional() ? self::M_OPT : 0;
        $m |= $c->isRepeating() ? self::M_REP : 0;
        $m |= $c->hasAndPredicate() ? self::M_AND : 0;
        $m |= $c->hasNotPredicate() ? self::M_NOT : 0;
        if ($c->hasSpread()) {
            $m |= self::M_SPREAD | (Sequence::spreadDepth($c) > 1 ? 0 : self::M_SPREAD1);
        }
        return $m;
    }

    private function nameOf(Clause $c): int
    {
        return $c->getName() === null ? -1 : $this->str($c->getName());
    }

    private function isSilent(Identifier $c): int
    {
        $r = $this->grammar[$c->getIdentifier()] ?? null;
        return $r !== null && $r->getClause()->pragma('silent') !== null ? 1 : 0;
    }

    private function expectedText(string $text, bool $ci): int
    {
        $json = json_encode($text, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return $this->str(($json === false ? '"' . $text . '"' : $json) . ($ci ? 'i' : ''));
    }

    private function compileRegex(string $pattern, bool $ci, string $what): int
    {
        $rc = new RegexCompiler(fn(array $r) => $this->set($r));
        $out = $rc->compile($pattern, $ci, $what);
        $start = count($this->rxcode);
        array_push($this->rxcode, ...RegexCompiler::relocate($out['code'], $start));
        $this->regexes[] = [$start, $out['regs']];
        return count($this->regexes) - 1;
    }

    private function build_clause(Clause $c): int
    {
        $mods = $this->mods($c);
        $name = $this->nameOf($c);
        if ($c instanceof Snippet) { // a snippet that is not in a sequence
            $id = $this->node(self::OP_SEQ, 0, -1, 0, 1, $this->ruleIndex[$this->currentRule] << 8);
            $this->setNode($id, 3, count($this->kids));
            $this->kids[] = $this->snippetNode($c, []);
            return $id;
        }
        if ($c instanceof Token || $c instanceof Literal) {
            $this->unsupported('token mode clauses are not supported by the portable targets');
        }
        if ($c instanceof Text) {
            $text = $c->getText();
            $ci = $c->isCaseInsensitive();
            if ($ci && preg_match('/[^\x00-\x7F]/', $text)) {
                // Unicode case folding: match it as a sequence of character sets, like PCRE's /iu does
                $code = [];
                $rc = new RegexCompiler(fn(array $r) => $this->set($r));
                $re = '';
                foreach (mb_str_split($text, 1, 'UTF-8') as $ch) {
                    $re .= '\\x{' . dechex(mb_ord($ch, 'UTF-8')) . '}';
                }
                return $this->node(self::OP_REGEX, $mods, $name, $this->compileRegex($re, true, $c->asString()), 0, $this->expectedText($text, true));
            }
            return $this->node(self::OP_TEXT, $mods, $name, $this->str($text), $ci ? 1 : 0, $this->expectedText($text, $ci));
        }
        if ($c instanceof Regex) {
            $pattern = $c->getInnerPattern();
            return $this->node(self::OP_REGEX, $mods, $name, $this->compileRegex($pattern, $c->isCaseInsensitive(), $c->asString()), 0, $this->str($c->asString()));
        }
        if ($c instanceof Any) {
            return $this->node(self::OP_ANY, $mods, $name, 0, 0, $this->str('any character'));
        }
        if ($c instanceof SOF) {
            return $this->node(self::OP_SOF, $mods, $name, 0, 0, $this->str('start of input'));
        }
        if ($c instanceof EOF) {
            return $this->node(self::OP_EOF, $mods, $name);
        }
        if ($c instanceof Nothing) {
            return $this->node(self::OP_NOTHING, $mods, $name);
        }
        if ($c instanceof Commit) {
            $this->hasCommit = true;
            return $this->node(self::OP_COMMIT, $mods, $name);
        }
        if ($c instanceof Identifier) {
            $target = $this->grammar[$c->getIdentifier()] ?? null;
            $guard = -1;
            if ($target !== null) {
                $tc = $target->getClause();
                $min = $tc->isOptional() ? 0 : $tc->getMinByteLength();
                $first = $min > 0 && $target->getDescription() !== null ? $tc->getFirstBytes() : null;
                if ($first !== null) {
                    $guard = $this->guard($first);
                }
            }
            return $this->node(self::OP_REF, $mods, $name, $this->ruleIndex[$c->getIdentifier()], $guard, $this->isSilent($c));
        }
        if ($c instanceof Sequence) {
            return $this->buildSequence($c, $mods, $name, null);
        }
        if ($c instanceof OrderedChoice) {
            $bump = false;
            foreach ($c as $alt) {
                $bump = $bump || $alt->hasAndPredicate() || $alt->hasNotPredicate();
            }
            $id = $this->node(self::OP_CHOICE, $mods, $name, 0, count($c), $bump ? self::CH_BUMP : 0);
            $kids = [];
            foreach ($c as $alt) {
                $kids[] = $this->build_clause($alt);
            }
            $this->setNode($id, 3, count($this->kids));
            array_push($this->kids, ...$kids);
            return $id;
        }
        $this->unsupported('unsupported clause ' . get_class($c));
    }

    /** @param array{rule: string, prec: int, last: int}|null $pratt set for the alternatives of a Pratt rule that contain the rule itself */
    private function buildSequence(Sequence $c, int $mods, int $name, ?array $pratt): int
    {
        $bump = false;
        foreach ($c as $e) {
            $bump = $bump || $e->hasAndPredicate() || $e->hasNotPredicate();
        }
        $flags = ($c->collapses() ? self::SEQ_COLLAPSE : 0) | ($bump && $pratt === null ? self::SEQ_BUMP : 0) | ($this->ruleIndex[$this->currentRule] << 8);
        $id = $this->node(self::OP_SEQ, $mods, $name, 0, count($c), $flags);
        $kids = [];
        $labels = $this->labels($c);
        foreach ($c as $i => $e) {
            if ($e instanceof Snippet) {
                $kids[] = $this->snippetNode($e, $labels);
            } elseif ($pratt !== null && $e instanceof Identifier && $e->getIdentifier() === $pratt['rule'] && $e->getModifier() === Clause::NONE) {
                $kids[] = $this->node(self::OP_PREF, 0, $this->nameOf($e), $this->ruleIndex[$pratt['rule']], $i === $pratt['last'] ? $pratt['prec'] : 0, $this->isSilent($e));
            } else {
                $kids[] = $this->build_clause($e);
            }
        }
        $this->setNode($id, 3, count($this->kids));
        array_push($this->kids, ...$kids);
        return $id;
    }

    /** @return list<string> */
    private function labels(Sequence $seq): array
    {
        $labels = [];
        foreach ($seq as $e) {
            if (!$e instanceof Snippet && $e->getName() !== null) {
                $labels[] = $e->getName();
            }
        }
        return $labels;
    }

    private function snippetNode(Snippet $snippet, array $labels): int
    {
        try {
            $tree = SnippetSubset::parse($snippet->getOriginalSource(), $labels);
        } catch (UnsupportedGrammar $e) {
            $this->unsupported('the snippet `<? ' . trim($snippet->getOriginalSource()) . ' ?>` is not portable: ' . $e->getMessage()
                . '. The C, JavaScript and Python targets accept snippet-free grammars and portable snippets only (see "Portable snippets" in REFERENCE.md)');
        }
        return $this->node(self::OP_SNIP, 0, -1, $this->expr($tree));
    }

    private function expr(array $e): int
    {
        [$kind, $x, $y] = match ($e[0]) {
            'null' => [self::E_NULL, 0, 0],
            'bool' => [$e[1] ? self::E_TRUE : self::E_FALSE, 0, 0],
            'int' => [self::E_INT, $e[1], 0],
            'str' => [self::E_STR, $this->str($e[1]), 0],
            'label' => [self::E_LABEL, $this->str($e[1]), 0],
            'offset' => [self::E_OFFSET, 0, 0],
            'line' => [self::E_LINE, 0, 0],
            'column' => [self::E_COLUMN, 0, 0],
            'coalesce' => [self::E_COALESCE, $this->expr($e[1]), $this->expr($e[2])],
            'list' => $this->exprList($e[1]),
        };
        $id = intdiv(count($this->exprs), 3);
        array_push($this->exprs, $kind, $x, $y);
        return $id;
    }

    private function exprList(array $items): array
    {
        $kids = [];
        foreach ($items as [$key, $item]) {
            $kids[] = [$key === null ? -1 : $this->str($key), $this->expr($item)];
        }
        $start = intdiv(count($this->ekids), 2);
        foreach ($kids as [$k, $x]) {
            array_push($this->ekids, $k, $x);
        }
        return [self::E_LIST, $start, count($kids)];
    }

    private function buildPrattPrimary(Clause $alt, string $rule, int $prec): int
    {
        if (!$alt instanceof Sequence) {
            return $this->build_clause($alt);
        }
        $last = -1;
        foreach ($alt as $i => $e) {
            if (!$e instanceof Commit && !$e instanceof Snippet) {
                $last = $i;
            }
        }
        return $this->buildSequence($alt, $this->mods($alt), $this->nameOf($alt), ['rule' => $rule, 'prec' => $prec, 'last' => $last]);
    }

    private function buildPrattOp(Clause $alt, string $rule, int $prec, bool $right): int
    {
        if (!$alt instanceof Sequence) {
            $this->unsupported("the alternative `$alt` of a left-recursive rule must be a sequence that starts with the rule itself");
        }
        $parts = iterator_to_array($alt);
        if (!$parts[0] instanceof Identifier || $parts[0]->getIdentifier() !== $rule) {
            $this->unsupported("the left-recursive alternative `$alt` must start directly with `$rule`");
        }
        $id = $this->node(self::OP_PRATTOP, 0, -1, 0, count($parts), $this->ruleIndex[$rule] << 8);
        $kids = [];
        $labels = $this->labels($alt);
        foreach ($parts as $i => $e) {
            if ($e instanceof Snippet) {
                $kids[] = $this->snippetNode($e, $labels);
            } elseif ($i > 0 && $e instanceof Identifier && $e->getIdentifier() === $rule && $e->getModifier() === Clause::NONE) {
                $kids[] = $this->node(self::OP_PREF, 0, $this->nameOf($e), $this->ruleIndex[$rule], $right ? $prec - 1 : $prec, $this->isSilent($e));
            } else {
                $kids[] = $this->build_clause($e);
            }
        }
        $this->setNode($id, 3, count($this->kids));
        array_push($this->kids, ...$kids);
        return $id;
    }
}
