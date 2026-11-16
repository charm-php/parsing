<?php
/**
 * Runs a grammar and an input through the portable code generators (C, JavaScript, Python) for the
 * conformance suite. Generated parsers are cached on disk (keyed by their source), so only the first run of
 * the suite pays for compiling.
 *
 * Outcome of a target run, normalized so it can be compared with the PHP compiled parser:
 *   ['ok', mixed $tree]                                   the parse tree as decoded JSON
 *   ['err', bool $committed, int $line, int $col, int $offset, string $found, string $expected]
 *   ['skip', string $why]                                 the grammar is outside what the target supports
 *   ['broken', string $why]                               the generated parser failed to build or run
 */

use Charm\Parsing\Compiler\Portable\{CGenerator, JsGenerator, Program, PythonGenerator, UnsupportedGrammar};
use Charm\Parsing\Grammar;

function targets_cache_dir(): string
{
    $d = sys_get_temp_dir() . '/charm-targets-' . get_current_user();
    @mkdir($d, 0777, true);
    return $d;
}

function targets_log(string $line): void
{
    @file_put_contents(sys_get_temp_dir() . '/charm-targets-stats.log', $line . "\n", FILE_APPEND | LOCK_EX);
}

/** Which targets to run: CHARM_TARGETS=c,js,python (default: all whose toolchain exists). */
function targets_enabled(): array
{
    static $t = null;
    if ($t !== null) {
        return $t;
    }
    $want = getenv('CHARM_TARGETS');
    $want = $want === false || $want === '' ? ['c', 'js', 'python'] : explode(',', $want);
    $tools = ['c' => 'gcc', 'js' => 'node', 'python' => 'python3'];
    $t = [];
    foreach ($want as $w) {
        if (isset($tools[$w]) && trim((string) shell_exec('command -v ' . $tools[$w])) !== '') {
            $t[] = $w;
        }
    }
    return $t;
}

function targets_exec(array $cmd, string $stdin, ?string $cwd = null, int $timeout = 10): array
{
    $p = proc_open(['timeout', '-k', '1', (string) $timeout, ...$cmd], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);
    fwrite($pipes[0], $stdin);
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($p), $out, $err];
}

function targets_program(string $grammarText, Grammar $g): Program|UnsupportedGrammar
{
    static $cache = [];
    $k = md5($grammarText);
    if (!isset($cache[$k])) {
        try {
            $cache[$k] = Program::fromGrammar($g);
        } catch (UnsupportedGrammar $e) {
            $cache[$k] = $e;
        }
    }
    return $cache[$k];
}

/** Build (or find in the cache) the executable for a target; returns [command array, error]. */
function targets_build(string $target, Program $p): array
{
    $dir = targets_cache_dir();
    if ($target === 'c') {
        $o = (new CGenerator())->generate($p, 'cp', 'cp.h');
        $harness = strtr(file_get_contents(__DIR__ . '/c_harness.c.tpl'), ['@n@' => 'cp']);
        $key = md5($o['c'] . $o['h'] . $harness);
        $bin = "$dir/c-$key";
        if (!is_file($bin)) {
            $w = "$dir/w-$key-" . getmypid();
            mkdir($w);
            file_put_contents("$w/cp.c", $o['c']);
            file_put_contents("$w/cp.h", $o['h']);
            file_put_contents("$w/h.c", $harness);
            [$rc, , $err] = targets_exec(['gcc', '-std=c99', '-Wall', '-Wextra', '-Werror', '-O0', '-o', "$w/bin", 'h.c', 'cp.c'], '', $w, 60);
            if ($rc !== 0) {
                @file_put_contents("$dir/failed-$key.c", $o['c']);
                return [null, "gcc failed ($key): " . substr($err, 0, 400)];
            }
            rename("$w/bin", $bin);
            array_map('unlink', glob("$w/*"));
            rmdir($w);
        }
        return [[$bin], null];
    }
    if ($target === 'js') {
        $src = (new JsGenerator())->generate($p, 'cp');
        $key = md5($src);
        $f = "$dir/js-$key.cjs";
        is_file($f) || file_put_contents($f, $src);
        return [['node', __DIR__ . '/js_harness.cjs', $f], null];
    }
    $src = (new PythonGenerator())->generate($p, 'cp');
    $key = md5($src);
    $f = "$dir/py-$key.py";
    is_file($f) || file_put_contents($f, $src);
    return [['python3', '-I', __DIR__ . '/py_harness.py', $f], null];
}

function targets_run(string $target, string $grammarText, Grammar $g, string $input): array
{
    $p = targets_program($grammarText, $g);
    if ($p instanceof UnsupportedGrammar) {
        return ['skip', $p->getMessage()];
    }
    static $built = [];
    $k = $target . md5($grammarText);
    $built[$k] ??= targets_build($target, $p);
    [$cmd, $error] = $built[$k];
    if ($cmd === null) {
        return ['broken', $error];
    }
    [$rc, $out, $err] = targets_exec($cmd, $input);
    $out = rtrim($out, "\n");
    $f = explode("\t", $out, 8);
    if ($rc === 0 && $f[0] === 'OK') {
        return ['ok', json_decode($f[1], true, 4096)];
    }
    if ($rc === 0 && $f[0] === 'ERR') {
        return ['err', $f[1] === '1', (int) $f[2], (int) $f[3], (int) $f[4], $f[5], $f[6]];
    }
    return ['broken', "$target exit $rc: " . substr($out . $err, 0, 400)];
}

/**
 * Compare a target's outcome with the PHP compiled parser's. $php is ['ok', value] or ['err', committed, line, col, offset, found, expected].
 * Returns null if they agree, otherwise a description of the difference.
 */
function targets_compare(string $target, array $php, array $t): ?string
{
    if ($t[0] === 'broken') {
        return "$target: " . $t[1];
    }
    if ($php[0] === 'ok') {
        if ($t[0] !== 'ok') {
            return "$target: failed (" . json_encode(array_slice($t, 1)) . ") where PHP parsed";
        }
        return targets_normalize($target, $php[1]) === $t[1] ? null : "$target tree " . json_encode($t[1]) . ' != PHP ' . json_encode($php[1]);
    }
    if ($t[0] !== 'err') {
        return "$target: parsed " . json_encode($t[1]) . ' where PHP failed';
    }
    $names = ['committed', 'line', 'column', 'offset', 'found', 'expected'];
    $diff = [];
    foreach ($names as $i => $n) {
        if ($php[$i + 1] !== $t[$i + 1]) {
            $diff[] = "$n " . json_encode($t[$i + 1]) . ' != PHP ' . json_encode($php[$i + 1]);
        }
    }
    return $diff ? "$target error: " . implode(', ', $diff) : null;
}

/** JavaScript objects list integer-like keys first; PHP's order is "as inserted". Put PHP's tree in JS order. */
function targets_normalize(string $target, mixed $v): mixed
{
    if ($target !== 'js' || !is_array($v)) {
        return $v;
    }
    if (array_is_list($v)) {
        return array_map(fn($x) => targets_normalize($target, $x), $v);
    }
    $ints = $strs = [];
    foreach ($v as $k => $x) {
        if (is_int($k)) {
            $ints[$k] = targets_normalize($target, $x);
        } else {
            $strs[$k] = targets_normalize($target, $x);
        }
    }
    return $ints + $strs;
}
