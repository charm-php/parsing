<?php
namespace Charm\Parsing\Compiler\Portable;

/**
 * Compiles a PCRE regex (the subset described below) to the bytecode the portable targets run with their
 * own backtracking matcher, so that generated parsers need no regex library and match exactly what
 * PHP's `preg_match('/.../uA', ...)` matches.
 *
 * Supported: literals and escapes (`\n \r \t \f \e \a \0 \xHH \x{H..} \.` etc.), `.`, character classes
 * (ranges, negation, `\d \w \s \h \v` and their negations, `\p{..}`/`\P{..}`, POSIX `[:alpha:]`),
 * alternation, groups (capturing groups are treated as non-capturing: only the whole match is returned),
 * the quantifiers `* + ? {n} {n,} {n,m}` in greedy, lazy (`?`) and possessive (`+`) form, atomic groups
 * `(?>..)`, lookahead `(?=..) (?!..)`, lookbehind `(?<=..) (?<!..)` of one fixed length, inline flags
 * `i s m` (`(?i)`, `(?i:..)`, `(?-i)`), comments `(?#..)`, and the assertions `^ $ \A \z \Z \b \B`.
 * Matching is UTF-8 aware (code points, Unicode `\w \d \s \b`, Unicode case folding), like PHP's `u` modifier.
 *
 * Rejected with an UnsupportedGrammar exception: back references, recursion and subroutine calls,
 * conditionals, `\R \X \K \G \Q..\E`, the `x` flag, branch reset, verbs, and variable-length lookbehind.
 *
 * Bytecode: a flat list of integers, `[opcode, operands...]`; jump targets are absolute positions in the
 * shared code array. See the runtimes for the semantics of each opcode.
 */
final class RegexCompiler
{
    public const MATCH = 0;    // success
    public const BYTES = 1;    // n, b1..bn: literal bytes
    public const SET = 2;      // set: one code point from the set
    public const SPLIT = 3;    // a, b: try a first, then b
    public const JMP = 4;      // a
    public const ASSERT = 5;   // kind, (wordset)
    public const LOOK = 6;     // neg, end: lookahead; sub-program follows, ends in SUBOK; `end` is the continuation
    public const ATOMIC = 7;   // end: atomic group
    public const SUBOK = 8;    // end of a sub-program
    public const REGSET = 9;   // r: reg[r] = pos
    public const EMPTYJMP = 10; // r, a: if pos == reg[r] goto a
    public const REPSET = 11;  // set, min, max(-1 = unbounded), mode(0 greedy, 1 lazy, 2 possessive)
    public const LOOKB = 12;   // neg, chars, end: lookbehind

    public const A_BOL = 0, A_MBOL = 1, A_EOL = 2, A_MEOL = 3, A_EOS = 4, A_WORDB = 5, A_NWORDB = 6;

    private string $src = '';
    private int $pos = 0;
    private array $flags = ['i' => false, 's' => false, 'm' => false];
    /** @var callable(array): int */
    private $addSet;
    private array $code = [];
    private int $regs = 0;
    private string $what = '';

    /** @param callable(array): int $addSet registers a range list in the shared set table and returns its id */
    public function __construct(callable $addSet)
    {
        $this->addSet = $addSet;
    }

    /**
     * Compile the pattern (without delimiters). Returns the instructions with jump targets relative to the
     * start of the returned list (the caller relocates them); the number of registers is `$regs`.
     *
     * @return array{code: list<int>, regs: int}
     */
    public function compile(string $pattern, bool $caseInsensitive = false, string $what = ''): array
    {
        $this->src = $pattern;
        $this->pos = 0;
        $this->what = $what !== '' ? $what : '/' . $pattern . '/';
        $this->flags = ['i' => $caseInsensitive, 's' => false, 'm' => false];
        $this->code = [];
        $this->regs = 0;
        $ast = $this->parseAlt();
        if ($this->pos < strlen($this->src)) {
            $this->fail('unmatched )');
        }
        $this->emit($ast);
        $this->code[] = self::MATCH;
        return ['code' => $this->code, 'regs' => $this->regs];
    }

    /** Opcodes with the operand positions that hold jump targets, for relocation. */
    public static function relocate(array $code, int $base): array
    {
        $out = $code;
        $i = 0;
        $n = count($code);
        while ($i < $n) {
            switch ($code[$i]) {
                case self::MATCH: case self::SUBOK: $i += 1; break;
                case self::BYTES: $i += 2 + $code[$i + 1]; break;
                case self::SET: case self::REGSET: $i += 2; break;
                case self::SPLIT: $out[$i + 1] += $base; $out[$i + 2] += $base; $i += 3; break;
                case self::JMP: $out[$i + 1] += $base; $i += 2; break;
                case self::ASSERT: $i += 3; break;
                case self::LOOK: $out[$i + 2] += $base; $i += 3; break;
                case self::ATOMIC: $out[$i + 1] += $base; $i += 2; break;
                case self::EMPTYJMP: $out[$i + 2] += $base; $i += 3; break;
                case self::REPSET: $i += 5; break;
                case self::LOOKB: $out[$i + 3] += $base; $i += 4; break;
                default: throw new \LogicException("bad opcode {$code[$i]}");
            }
        }
        return $out;
    }

    private function fail(string $why): never
    {
        throw new UnsupportedGrammar("Unsupported regex {$this->what}: $why");
    }

    // ---- parsing -------------------------------------------------------------------------------------

    private function peek(): ?string
    {
        return $this->src[$this->pos] ?? null;
    }

    private function parseAlt(): array
    {
        $branches = [$this->parseCat()];
        while ($this->peek() === '|') {
            $this->pos++;
            $branches[] = $this->parseCat();
        }
        return count($branches) === 1 ? $branches[0] : ['alt', $branches];
    }

    private function parseCat(): array
    {
        $items = [];
        while (($c = $this->peek()) !== null && $c !== '|' && $c !== ')') {
            $atom = $this->parseAtom();
            if ($atom === null) { // a comment or an inline flag setting
                continue;
            }
            $items[] = $this->parseQuantifier($atom);
        }
        return ['cat', $items];
    }

    private function parseQuantifier(array $atom): array
    {
        $c = $this->peek();
        $min = $max = null;
        if ($c === '*') { $min = 0; $max = -1; $this->pos++; }
        elseif ($c === '+') { $min = 1; $max = -1; $this->pos++; }
        elseif ($c === '?') { $min = 0; $max = 1; $this->pos++; }
        elseif ($c === '{' && preg_match('/\G\{(\d+)(?:(,)(\d*))?\}/', $this->src, $m, 0, $this->pos)) {
            $min = (int) $m[1];
            $max = isset($m[2]) ? ($m[3] === '' ? -1 : (int) $m[3]) : $min;
            if ($max !== -1 && $max < $min) {
                $this->fail('numbers out of order in {} quantifier');
            }
            if ($min > 1000 || $max > 1000) {
                $this->fail('repeat count above 1000');
            }
            $this->pos += strlen($m[0]);
        } else {
            return $atom;
        }
        if (in_array($atom[0], ['assert'], true)) {
            return $atom;
        }
        $mode = 0;
        if ($this->peek() === '?') { $mode = 1; $this->pos++; }
        elseif ($this->peek() === '+') { $mode = 2; $this->pos++; }
        return ['rep', $atom, $min, $max, $mode];
    }

    private function char(int $cp): array
    {
        if ($this->flags['i']) {
            $v = Unicode::caseVariants($cp);
            if (count($v) > 1) {
                return ['set', Unicode::normalize(array_map(fn($x) => [$x, $x], $v))];
            }
        }
        return ['char', $cp];
    }

    private function nextCp(): int
    {
        $b = ord($this->src[$this->pos]);
        $len = $b < 0x80 ? 1 : ($b >= 0xF0 ? 4 : ($b >= 0xE0 ? 3 : 2));
        $cp = mb_ord(substr($this->src, $this->pos, $len), 'UTF-8');
        if ($cp === false) {
            $this->fail('invalid UTF-8 in the pattern');
        }
        $this->pos += $len;
        return $cp;
    }

    private function parseAtom(): ?array
    {
        $c = $this->src[$this->pos];
        switch ($c) {
            case '(':
                return $this->parseGroup();
            case '[':
                return $this->parseClass();
            case '.':
                $this->pos++;
                return ['any', $this->flags['s']];
            case '^':
                $this->pos++;
                return ['assert', $this->flags['m'] ? self::A_MBOL : self::A_BOL];
            case '$':
                $this->pos++;
                return ['assert', $this->flags['m'] ? self::A_MEOL : self::A_EOL];
            case '\\':
                $r = $this->parseEscape(false);
                return $r[0] === 'char' ? $this->char($r[1]) : $r;
            case '*': case '+': case '?':
                $this->fail('quantifier does not follow a repeatable item');
        }
        return $this->char($this->nextCp());
    }

    private function parseGroup(): ?array
    {
        $this->pos++; // (
        $saved = $this->flags;
        $kind = 'group';
        $neg = false;
        $behind = false;
        if ($this->peek() === '?') {
            $this->pos++;
            $c = $this->peek();
            if ($c === ':') { $this->pos++; }
            elseif ($c === '=' || $c === '!') { $kind = 'look'; $neg = $c === '!'; $this->pos++; }
            elseif ($c === '>') { $kind = 'atomic'; $this->pos++; }
            elseif ($c === '#') {
                $end = strpos($this->src, ')', $this->pos);
                if ($end === false) {
                    $this->fail('missing ) after comment');
                }
                $this->pos = $end + 1;
                return null;
            } elseif ($c === '<' && in_array($this->src[$this->pos + 1] ?? '', ['=', '!'], true)) {
                $kind = 'look'; $behind = true; $neg = $this->src[$this->pos + 1] === '!'; $this->pos += 2;
            } elseif ($c === 'P' && ($this->src[$this->pos + 1] ?? '') === '<' || $c === '<' || $c === "'") {
                if ($c === 'P') { $this->pos++; }
                if (!preg_match('/\G[<\'](\w+)[>\']/', $this->src, $m, 0, $this->pos)) {
                    $this->fail('bad group name');
                }
                $this->pos += strlen($m[0]);
            } elseif ($c !== null && preg_match('/\G([imsxUXJn]*)(?:-([imsxUXJn]*))?([:)])/', $this->src, $m, 0, $this->pos)) {
                foreach (str_split($m[1] . ($m[2] ?? '')) as $f) {
                    if (!in_array($f, ['i', 's', 'm'], true)) {
                        $this->fail("the flag $f is not supported");
                    }
                }
                $new = $this->flags;
                foreach (str_split($m[1]) as $f) { $new[$f] = true; }
                foreach (str_split($m[2] ?? '') as $f) { $new[$f] = false; }
                $this->pos += strlen($m[0]);
                if ($m[3] === ')') { // applies to the rest of the enclosing group
                    $this->flags = $new;
                    return null;
                }
                $this->flags = $new;
            } else {
                $this->fail('the group construct (?' . ($c ?? '') . ' is not supported');
            }
        }
        $inner = $this->parseAlt();
        if ($this->peek() !== ')') {
            $this->fail('missing )');
        }
        $this->pos++;
        $this->flags = $saved;
        if ($kind === 'look') {
            if ($behind) {
                $len = $this->fixedLength($inner);
                if ($len === null) {
                    $this->fail('lookbehind must have a single fixed length');
                }
                return ['lookb', $neg, $inner, $len];
            }
            return ['look', $neg, $inner];
        }
        return $kind === 'atomic' ? ['atomic', $inner] : ['group', $inner];
    }

    /** The length in characters of every match of the node, or null if it varies. */
    private function fixedLength(array $n): ?int
    {
        switch ($n[0]) {
            case 'char': case 'set': case 'any': return 1;
            case 'assert': case 'look': case 'lookb': return 0;
            case 'group': case 'atomic': return $this->fixedLength($n[1]);
            case 'cat':
                $t = 0;
                foreach ($n[1] as $x) {
                    $l = $this->fixedLength($x);
                    if ($l === null) { return null; }
                    $t += $l;
                }
                return $t;
            case 'alt':
                $l = null;
                foreach ($n[1] as $x) {
                    $m = $this->fixedLength($x);
                    if ($m === null || ($l !== null && $l !== $m)) { return null; }
                    $l = $m;
                }
                return $l;
            case 'rep':
                $l = $this->fixedLength($n[1]);
                return $l !== null && $n[2] === $n[3] ? $l * $n[2] : null;
        }
        return null;
    }

    /** Escape sequence outside ($inClass false) or inside a character class. Returns char, set or assert nodes. */
    private function parseEscape(bool $inClass): array
    {
        $this->pos++; // backslash
        if ($this->pos >= strlen($this->src)) {
            $this->fail('\\ at end of pattern');
        }
        $c = $this->src[$this->pos++];
        switch ($c) {
            case 'n': return ['char', 10];
            case 'r': return ['char', 13];
            case 't': return ['char', 9];
            case 'f': return ['char', 12];
            case 'e': return ['char', 27];
            case 'a': return ['char', 7];
            case 'd': case 'D': case 'w': case 'W': case 's': case 'S': case 'h': case 'H': case 'v': case 'V':
                $r = Unicode::ranges('\\' . strtolower($c));
                return ['set', ctype_upper($c) ? Unicode::complement($r) : $r];
            case 'p': case 'P':
                if ($this->peek() === '{') {
                    $end = strpos($this->src, '}', $this->pos);
                    if ($end === false) {
                        $this->fail('malformed \\p');
                    }
                    $name = substr($this->src, $this->pos, $end - $this->pos + 1);
                    $this->pos = $end + 1;
                } else {
                    $name = '{' . $this->src[$this->pos++] . '}';
                }
                $r = Unicode::ranges('\\p' . $name);
                return ['set', $c === 'P' ? Unicode::complement($r) : $r];
            case 'x':
                if ($this->peek() === '{') {
                    $end = strpos($this->src, '}', $this->pos);
                    $hex = $end === false ? '' : substr($this->src, $this->pos + 1, $end - $this->pos - 1);
                    if (!preg_match('/^[0-9a-fA-F]+$/', $hex) || hexdec($hex) > Unicode::MAX) {
                        $this->fail('bad \\x{...}');
                    }
                    $this->pos = $end + 1;
                    return ['char', hexdec($hex)];
                }
                preg_match('/\G[0-9a-fA-F]{0,2}/', $this->src, $m, 0, $this->pos);
                $this->pos += strlen($m[0]);
                return ['char', $m[0] === '' ? 0 : hexdec($m[0])];
            case '0':
                preg_match('/\G[0-7]{0,2}/', $this->src, $m, 0, $this->pos);
                $this->pos += strlen($m[0]);
                return ['char', octdec('0' . $m[0])];
            case 'b':
                if ($inClass) { return ['char', 8]; }
                return ['assert', self::A_WORDB];
            case 'B': case 'A': case 'z': case 'Z':
                if ($inClass) { $this->fail("\\$c in a character class"); }
                return ['assert', match ($c) { 'B' => self::A_NWORDB, 'A' => self::A_BOL, 'z' => self::A_EOS, 'Z' => self::A_EOL }];
        }
        if (ctype_digit($c)) {
            $this->fail('back references are not supported');
        }
        if (ctype_alpha($c)) {
            $this->fail("the escape \\$c is not supported");
        }
        // an escaped punctuation character (or a multi-byte one) is itself
        $this->pos--;
        return ['char', $this->nextCp()];
    }

    private function parseClass(): array
    {
        $this->pos++; // [
        $neg = false;
        if ($this->peek() === '^') { $neg = true; $this->pos++; }
        $ranges = [];
        $first = true;
        while (true) {
            $c = $this->peek();
            if ($c === null) {
                $this->fail('missing terminating ]');
            }
            if ($c === ']' && !$first) {
                $this->pos++;
                break;
            }
            $first = false;
            if ($c === '[' && ($this->src[$this->pos + 1] ?? '') === ':' && preg_match('/\G\[:(\^?)([a-z]+):\]/', $this->src, $m, 0, $this->pos)) {
                $this->pos += strlen($m[0]);
                $r = Unicode::ranges('[[:' . $m[2] . ':]]');
                $ranges = array_merge($ranges, $m[1] === '^' ? Unicode::complement($r) : $r);
                continue;
            }
            $lo = $this->classAtom();
            if ($lo[0] === 'set') {
                $ranges = array_merge($ranges, $lo[1]);
                continue;
            }
            $loCp = $lo[1];
            if ($this->peek() === '-' && ($this->src[$this->pos + 1] ?? ']') !== ']') {
                $save = $this->pos;
                $this->pos++;
                $hi = $this->classAtom();
                if ($hi[0] === 'set') { // `a-\d`: the dash is literal
                    $ranges[] = [$loCp, $loCp];
                    $ranges[] = [45, 45];
                    $ranges = array_merge($ranges, $hi[1]);
                    continue;
                }
                if ($hi[1] < $loCp) {
                    $this->fail('range out of order in character class');
                }
                $ranges[] = [$loCp, $hi[1]];
                continue;
            }
            $ranges[] = [$loCp, $loCp];
        }
        $ranges = Unicode::normalize($ranges);
        if ($this->flags['i']) {
            $ranges = Unicode::closeCase($ranges);
        }
        if ($neg) {
            $ranges = Unicode::complement($ranges);
        }
        return ['set', $ranges];
    }

    private function classAtom(): array
    {
        if ($this->peek() === '\\') {
            $r = $this->parseEscape(true);
            return $r[0] === 'assert' ? $this->fail('assertion in a character class') : $r;
        }
        return ['char', $this->nextCp()];
    }

    // ---- emission ------------------------------------------------------------------------------------

    private function minLen(array $n): int
    {
        switch ($n[0]) {
            case 'char': case 'set': case 'any': return 1;
            case 'assert': case 'look': case 'lookb': return 0;
            case 'group': case 'atomic': return $this->minLen($n[1]);
            case 'cat': return array_sum(array_map([$this, 'minLen'], $n[1]));
            case 'alt': return min(array_map([$this, 'minLen'], $n[1]));
            case 'rep': return $n[2] * $this->minLen($n[1]);
        }
        return 0;
    }

    private function singleSet(array $n): ?array
    {
        switch ($n[0]) {
            case 'char': return [[$n[1], $n[1]]];
            case 'set': return $n[1];
            case 'any': return $n[1] ? [[0, Unicode::MAX]] : [[0, 9], [11, Unicode::MAX]];
            case 'group': return $this->singleSet($n[1]);
        }
        return null;
    }

    private function here(): int
    {
        return count($this->code);
    }

    private function emit(array $n): void
    {
        switch ($n[0]) {
            case 'cat':
                $bytes = '';
                foreach ($n[1] as $x) {
                    if ($x[0] === 'char') {
                        $bytes .= mb_chr($x[1], 'UTF-8');
                        continue;
                    }
                    $this->flushBytes($bytes);
                    $this->emit($x);
                }
                $this->flushBytes($bytes);
                return;
            case 'char':
                $s = mb_chr($n[1], 'UTF-8');
                $this->flushBytes($s);
                return;
            case 'set':
                $this->code[] = self::SET;
                $this->code[] = ($this->addSet)($n[1]);
                return;
            case 'any':
                $this->code[] = self::SET;
                $this->code[] = ($this->addSet)($this->singleSet($n));
                return;
            case 'group':
                $this->emit($n[1]);
                return;
            case 'alt':
                $jumps = [];
                $last = count($n[1]) - 1;
                foreach ($n[1] as $i => $branch) {
                    if ($i < $last) {
                        $split = $this->here();
                        array_push($this->code, self::SPLIT, $split + 3, 0);
                        $this->emit($branch);
                        $jumps[] = $this->here() + 1;
                        array_push($this->code, self::JMP, 0);
                        $this->code[$split + 2] = $this->here();
                    } else {
                        $this->emit($branch);
                    }
                }
                foreach ($jumps as $j) {
                    $this->code[$j] = $this->here();
                }
                return;
            case 'assert':
                array_push($this->code, self::ASSERT, $n[1], $n[1] === self::A_WORDB || $n[1] === self::A_NWORDB ? ($this->addSet)(Unicode::ranges('\\w')) : 0);
                return;
            case 'look':
                $at = $this->here();
                array_push($this->code, self::LOOK, $n[1] ? 1 : 0, 0);
                $this->emit($n[2]);
                $this->code[] = self::SUBOK;
                $this->code[$at + 2] = $this->here();
                return;
            case 'lookb':
                $at = $this->here();
                array_push($this->code, self::LOOKB, $n[1] ? 1 : 0, $n[3], 0);
                $this->emit($n[2]);
                $this->code[] = self::SUBOK;
                $this->code[$at + 3] = $this->here();
                return;
            case 'atomic':
                $at = $this->here();
                array_push($this->code, self::ATOMIC, 0);
                $this->emit($n[1]);
                $this->code[] = self::SUBOK;
                $this->code[$at + 1] = $this->here();
                return;
            case 'rep':
                $this->emitRep($n);
                return;
        }
        throw new \LogicException('regex node ' . $n[0]);
    }

    private function flushBytes(string &$bytes): void
    {
        if ($bytes === '') {
            return;
        }
        $this->code[] = self::BYTES;
        $this->code[] = strlen($bytes);
        foreach (str_split($bytes) as $b) {
            $this->code[] = ord($b);
        }
        $bytes = '';
    }

    private function emitRep(array $n): void
    {
        [, $x, $min, $max, $mode] = $n;
        if ($max === 0) {
            return;
        }
        if (($set = $this->singleSet($x)) !== null) {
            array_push($this->code, self::REPSET, ($this->addSet)($set), $min, $max, $mode);
            return;
        }
        if ($mode === 2) { // possessive: an atomic group around the greedy repeat
            $at = $this->here();
            array_push($this->code, self::ATOMIC, 0);
            $this->emitRep(['rep', $x, $min, $max, 0]);
            $this->code[] = self::SUBOK;
            $this->code[$at + 1] = $this->here();
            return;
        }
        $lazy = $mode === 1;
        for ($i = 0; $i < $min; $i++) {
            $this->emit($x);
            if (count($this->code) > 200000) {
                $this->fail('the repeat expands to too much code');
            }
        }
        if ($max === -1) {
            $loop = $this->here();
            $emptyable = $this->minLen($x) === 0;
            $reg = $emptyable ? $this->regs++ : 0;
            // L1: SPLIT body, exit
            array_push($this->code, self::SPLIT, 0, 0);
            $body = $this->here();
            if ($emptyable) {
                array_push($this->code, self::REGSET, $reg);
            }
            $this->emit($x);
            $emptyAt = null;
            if ($emptyable) {
                $emptyAt = $this->here();
                array_push($this->code, self::EMPTYJMP, $reg, 0);
            }
            array_push($this->code, self::JMP, $loop);
            $exit = $this->here();
            $this->code[$loop + 1] = $lazy ? $exit : $body;
            $this->code[$loop + 2] = $lazy ? $body : $exit;
            if ($emptyAt !== null) {
                $this->code[$emptyAt + 2] = $exit;
            }
            return;
        }
        $splits = [];
        for ($i = $min; $i < $max; $i++) {
            $at = $this->here();
            array_push($this->code, self::SPLIT, 0, 0);
            $splits[] = $at;
            $this->code[$at + ($lazy ? 2 : 1)] = $this->here();
            $this->emit($x);
            if (count($this->code) > 200000) {
                $this->fail('the repeat expands to too much code');
            }
        }
        foreach ($splits as $at) {
            $this->code[$at + ($lazy ? 1 : 2)] = $this->here();
        }
    }
}
