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
        // interpreter: "... on parsed source:L:C ..." with a 0-based column
        return $position && preg_match('/:(\d+):(\d+)/', $e->getMessage(), $m) ? "ERROR@$m[1]:" . ($m[2] + 1) : 'PARSE_ERROR';
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

function conformance_run(array $args, bool $position = false): array {
    static $compiled = [];
    [$grammar, $input] = $args;
    $interpreted = conformance_outcome(fn() => \Charm\cpeg_parse($grammar, $input), $position);
    $compiledResult = conformance_outcome(function () use (&$compiled, $grammar, $input) {
        if (!isset($compiled[$grammar])) {
            $className = 'ConformanceParser_' . count($compiled) . '_' . substr(md5($grammar), 0, 8);
            $g = (new GrammarParser())->parse((new GrammarPreprocessor())->process($grammar), 'test');
            eval('?>' . (new GrammarCompiler())->compile($g, $className));
            $compiled[$grammar] = $className;
        }
        $parser = new $compiled[$grammar]();
        $result = $parser->parse($input);
        return $result === \Charm\Parsing\Signal::HIT ? null : $result;
    }, $position);
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
