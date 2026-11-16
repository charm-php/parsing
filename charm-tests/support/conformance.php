<?php
/**
 * Shared helper for the 0030-conformance-*.php files: runs one grammar/input pair through the
 * interpreter (cpeg_parse) and the compiled parser and returns [interpreted, compiled].
 * A parse failure becomes 'PARSE_ERROR' on both paths (they throw different classes); anything
 * else thrown is returned as "Class: message" so a crash never looks like an expected failure.
 * With $position = true a parse failure becomes 'ERROR@line:column' (1-based column) instead.
 */

use Charm\Parsing\Compiler\GrammarCompiler;
use Charm\Parsing\Error\AbstractParseError;
use Charm\Parsing\GrammarParser;
use Charm\Parsing\GrammarPreprocessor;

final class ConformanceTimeout extends \Exception {}

function conformance_outcome(callable $run, bool $position): mixed {
    if (function_exists('pcntl_alarm')) { // guard against grammars that loop forever
        pcntl_async_signals(true);
        pcntl_signal(SIGALRM, fn() => throw new ConformanceTimeout());
        pcntl_alarm(2);
    }
    try {
        return $run();
    } catch (ConformanceTimeout) {
        return 'HANG';
    } catch (AbstractParseError $e) {
        if ($e instanceof \Charm\Parsing\Error\State\ParseError && !$e instanceof \Charm\Parsing\Error\State\CommittedError) {
            return 'GRAMMAR_ERROR'; // the grammar text itself is invalid
        }
        // interpreter: "... on parsed source:L:C ..." with a 1-based column
        return $position && preg_match('/:(\d+):(\d+)/', $e->getMessage(), $m) ? "ERROR@$m[1]:$m[2]" : 'PARSE_ERROR';
    } catch (\RuntimeException $e) {
        // compiled fail(): "Parse error at line L, column C: ..." (a plain RuntimeException)
        if (!str_starts_with($e->getMessage(), 'Parse error at line ')) {
            return get_class($e) . ': ' . $e->getMessage();
        }
        return $position && preg_match('/line (\d+), column (\d+)/', $e->getMessage(), $m) ? "ERROR@$m[1]:$m[2]" : 'PARSE_ERROR';
    } catch (\Throwable $e) {
        return get_class($e) . ': ' . $e->getMessage();
    } finally {
        function_exists('pcntl_alarm') && pcntl_alarm(0);
    }
}

function conformance_run(array $args, bool $position = false, array $state = []): array {
    static $compiled = [];
    [$grammar, $input] = $args;
    $detail = null; // the compiled parser's outcome in full, for the portable targets
    $interpreted = conformance_outcome(fn() => \Charm\cpeg_parse($grammar, $input, $state), $position);
    $compiledResult = conformance_outcome(function () use (&$compiled, $grammar, $input, $state, &$detail) {
        if (!isset($compiled[$grammar])) {
            $className = 'ConformanceParser_' . count($compiled) . '_' . substr(md5($grammar), 0, 8);
            $g = (new GrammarParser())->parse((new GrammarPreprocessor())->process($grammar), 'test');
            eval('?>' . (new GrammarCompiler())->compile($g, $className));
            $compiled[$grammar] = $className;
        }
        $parser = new $compiled[$grammar]();
        try {
            $result = $parser->parse($input, $state);
        } catch (\RuntimeException $e) {
            if (isset($e->committed, $e->sourceLine)) {
                $detail = ['err', $e->committed, $e->sourceLine, $e->sourceColumn, $e->offset, $e->found, $e->expected];
            }
            throw $e;
        }
        $result = $result === \Charm\Parsing\Signal::HIT ? null : $result;
        $detail = ['ok', $result];
        return $result;
    }, $position);
    if ($detail !== null && $state === []) {
        $compiledResult = conformance_targets($grammar, $input, $detail, $compiledResult);
    }
    return [$interpreted, $compiledResult];
}

/** Test function for the conformance files: a case row is [[grammar, input], [expectedInterpreted, expectedCompiled]]. */
function conformance_case(array $args): array {
    return conformance_run($args);
}

/** The usual expectation: both paths produce the same value. */
function both(mixed $v): array {
    return [$v, $v];
}

/** Like conformance_case, but a parse failure is reported as 'ERROR@line:column' (1-based, byte column). */
function conformance_position(array $args): array {
    return conformance_run($args, true);
}


/**
 * Run the row through the portable targets (C, JavaScript, Python). A difference from the PHP compiled parser
 * is appended to the compiled outcome, so the row fails and shows it; rows with snippets are skipped.
 */
function conformance_targets(string $grammarText, string $input, array $php, mixed $compiledResult): mixed {
    require_once __DIR__ . '/targets.php';
    static $parsed = [];
    $g = $parsed[$grammarText] ??= (new GrammarParser())->parse((new GrammarPreprocessor())->process($grammarText), 'test');
    $problems = [];
    foreach (targets_enabled() as $target) {
        $t = targets_run($target, $grammarText, $g, $input);
        if ($t[0] === 'skip') {
            targets_log("$target skip");
            continue;
        }
        $diff = targets_compare($target, $php, $t);
        targets_log("$target " . ($diff === null ? 'pass' : 'FAIL ' . $diff));
        if ($diff !== null) {
            $problems[] = $diff;
        }
    }
    return $problems ? [$compiledResult, 'TARGET MISMATCH', $problems] : $compiledResult;
}
