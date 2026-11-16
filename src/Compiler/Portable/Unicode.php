<?php
namespace Charm\Parsing\Compiler\Portable;

/**
 * Unicode data for the portable regex matcher, computed from PHP's own PCRE (so that it is by construction
 * what `preg_match('/.../u')` accepts: PHP's `u` modifier enables UTF-8 *and* Unicode properties for
 * `\w`, `\d`, `\s`, `\b`) and cached in the system temporary directory.
 *
 * A "range list" is a sorted list of non-overlapping, non-adjacent `[lo, hi]` code point pairs.
 */
final class Unicode
{
    public const MAX = 0x10FFFF;

    /** @var array<string, list<array{int,int}>> */
    private static array $memo = [];
    /** @var array<int, list<int>>|null code point => the other code points of its case-equivalence class */
    private static ?array $caseClasses = null;

    /** The code point ranges `$pcre` (a single-character PCRE expression such as `\w` or `\p{Lu}`) matches. */
    public static function ranges(string $pcre): array
    {
        if (isset(self::$memo[$pcre])) {
            return self::$memo[$pcre];
        }
        $file = sys_get_temp_dir() . '/charm-unicode-' . md5(PCRE_VERSION . "\0" . $pcre) . '.php';
        if (is_file($file) && is_array($r = @include $file)) {
            return self::$memo[$pcre] = $r;
        }
        $re = '/(?:' . $pcre . ')+/u';
        if (@preg_match($re, '') === false) {
            throw new UnsupportedGrammar("Unsupported regex construct $pcre: " . preg_last_error_msg());
        }
        $u = self::universe();
        preg_match_all($re, $u, $m, PREG_OFFSET_CAPTURE);
        $out = [];
        foreach ($m[0] as [$text, $byte]) {
            $lo = self::cpAtByte($byte);
            $hi = self::cpAtByte($byte + strlen($text)) - 1;
            if ($hi >= 0xD800 && $lo < 0xD800) { // a run that spans the surrogate gap
                $out[] = [$lo, 0xD7FF];
                $lo = 0xE000;
            }
            $out[] = [$lo, $hi];
        }
        $out = self::normalize($out);
        @file_put_contents($file, '<?php return ' . var_export($out, true) . ';');
        return self::$memo[$pcre] = $out;
    }

    private static ?string $universe = null;

    /** Every code point but the surrogates, in order, as UTF-8. */
    private static function universe(): string
    {
        if (self::$universe === null) {
            $parts = [];
            for ($lo = 0; $lo <= self::MAX; $lo += 0x1000) {
                $chunk = '';
                for ($c = $lo; $c < $lo + 0x1000 && $c <= self::MAX; $c++) {
                    if ($c < 0xD800 || $c > 0xDFFF) {
                        $chunk .= mb_chr($c, 'UTF-8');
                    }
                }
                $parts[] = $chunk;
            }
            self::$universe = implode('', $parts);
        }
        return self::$universe;
    }

    /** The code point that starts at byte offset $b of universe() (or the one that would follow it). */
    private static function cpAtByte(int $b): int
    {
        if ($b <= 0x80) {
            return $b;
        }
        $b -= 0x80;
        if ($b <= 2 * 0x780) {
            return 0x80 + intdiv($b, 2);
        }
        $b -= 2 * 0x780;
        $bmp = 3 * (0xD800 - 0x800 + 0x2000);
        if ($b <= $bmp) {
            $i = intdiv($b, 3);
            return 0x800 + ($i < 0xD800 - 0x800 ? $i : $i + 0x800);
        }
        $b -= $bmp;
        return 0x10000 + intdiv($b, 4);
    }

    /** Sort and merge a list of ranges. */
    public static function normalize(array $ranges): array
    {
        usort($ranges, fn($a, $b) => $a[0] <=> $b[0]);
        $out = [];
        foreach ($ranges as [$lo, $hi]) {
            $n = count($out);
            if ($n && $lo <= $out[$n - 1][1] + 1) {
                $out[$n - 1][1] = max($out[$n - 1][1], $hi);
            } else {
                $out[] = [$lo, $hi];
            }
        }
        return $out;
    }

    public static function complement(array $ranges): array
    {
        $ranges = self::normalize($ranges);
        $out = [];
        $next = 0;
        foreach ($ranges as [$lo, $hi]) {
            if ($lo > $next) {
                $out[] = [$next, $lo - 1];
            }
            $next = $hi + 1;
        }
        if ($next <= self::MAX) {
            $out[] = [$next, self::MAX];
        }
        return $out;
    }

    /**
     * Add every case variant of every code point in the ranges, as PCRE's caseless matching does
     * (Unicode simple case folding, CaseFolding.txt status C and S: `k` also matches U+212A KELVIN SIGN, `s` also U+017F).
     */
    public static function closeCase(array $ranges): array
    {
        $classes = self::caseClasses();
        $cased = array_keys($classes);
        $extra = [];
        foreach ($ranges as [$lo, $hi]) {
            // binary search for the first cased code point >= $lo
            $a = 0;
            $b = count($cased);
            while ($a < $b) {
                $mid = ($a + $b) >> 1;
                if ($cased[$mid] < $lo) {
                    $a = $mid + 1;
                } else {
                    $b = $mid;
                }
            }
            for ($i = $a; $i < count($cased) && $cased[$i] <= $hi; $i++) {
                foreach ($classes[$cased[$i]] as $v) {
                    $extra[] = [$v, $v];
                }
            }
        }
        return self::normalize(array_merge($ranges, $extra));
    }

    /** All code points that match caselessly the same as $cp (including $cp). */
    public static function caseVariants(int $cp): array
    {
        return array_merge([$cp], self::caseClasses()[$cp] ?? []);
    }

    private static function caseClasses(): array
    {
        if (self::$caseClasses !== null) {
            return self::$caseClasses;
        }
        $file = sys_get_temp_dir() . '/charm-unicode-case-' . md5(PHP_VERSION . PCRE_VERSION) . '.php';
        if (is_file($file) && is_array($r = @include $file)) {
            return self::$caseClasses = $r;
        }
        $parent = [];
        $find = function (int $x) use (&$parent, &$find): int {
            while (($parent[$x] ?? $x) !== $x) {
                $parent[$x] = $parent[$parent[$x]] ?? $parent[$x];
                $x = $parent[$x];
            }
            return $x;
        };
        $union = function (int $a, int $b) use (&$parent, $find): void {
            $parent[$a] ??= $a;
            $parent[$b] ??= $b;
            $ra = $find($a);
            $rb = $find($b);
            if ($ra !== $rb) {
                $parent[$ra] = $rb;
            }
        };
        for ($c = 0; $c < 0x20000; $c++) {
            if ($c >= 0xD800 && $c <= 0xDFFF) {
                continue;
            }
            $s = mb_chr($c, 'UTF-8');
            $t = mb_convert_case($s, MB_CASE_FOLD_SIMPLE, 'UTF-8');
            if ($t !== $s && mb_strlen($t, 'UTF-8') === 1) {
                $union($c, mb_ord($t, 'UTF-8'));
            }
        }
        $groups = [];
        foreach (array_keys($parent) as $c) {
            $groups[$find($c)][] = $c;
        }
        $classes = [];
        foreach ($groups as $members) {
            foreach ($members as $c) {
                $classes[$c] = array_values(array_diff($members, [$c]));
            }
        }
        ksort($classes);
        @file_put_contents($file, '<?php return ' . var_export($classes, true) . ';');
        return self::$caseClasses = $classes;
    }
}
