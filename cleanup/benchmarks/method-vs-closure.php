<?php
use Charm\Parsing\Util\Benchmark;

require(__DIR__.'/../vendor/autoload.php');

/**
 * Measures the performance of using closures instead of static class
 * members. It seems static class members are much faster and they benefit
 * a lot more from JIT.
 */
class Tester {

    public $closure;
    private $methodCalls = 0;
    private $closureCalls = 0;

    public function __construct() {
        $this->closure = function() {
            $this->closureCalls++;
        };

        $this->closureWithArg = function(int $arg) {
            $this->closureCalls += $arg;
        };

        $this->closureWithIfStatement = function(int $arg) {
            if ($arg % 2 === 0) {
                $this->methodCalls += 1;
            } else {
                $this->methodCalls -= 1;
            }
        };
    }

    public function benchmark(Benchmark $b) {
        $timer = $b->measure(REPETITION_COUNT, ' $this closure calls');
        for ($i = 0; $i < REPETITION_COUNT; $i++) {
            ($this->closure)();
        }
        $timer();

        $timer = $b->measure(REPETITION_COUNT, ' $this closure with arg calls');
        for ($i = 0; $i < REPETITION_COUNT; $i++) {
            ($this->closureWithArg)($i);
        }
        $timer();

        $timer = $b->measure(REPETITION_COUNT, ' $this method calls');
        for ($i = 0; $i < REPETITION_COUNT; $i++) {
            $this->method();
        }
        $timer();

        $timer = $b->measure(REPETITION_COUNT, ' $this method with arg calls');
        for ($i = 0; $i < REPETITION_COUNT; $i++) {
            $this->methodWithArg($i);
        }
        $timer();
    }

    public function method() {
        $this->methodCalls++;
    }

    public function methodWithIfStatement(int $arg) {
        if ($arg % 2 === 0) {
            $this->methodCalls += 1;
        } else {
            $this->methodCalls -= 1;
        }
    }

    public function methodWithArg(int $arg) {
        $this->methodCalls += $arg;
    }
}

$t = new Tester();

$b = new Charm\Parsing\Util\Benchmark();

const REPETITION_COUNT = 10000000;


$timer = $b->measure(REPETITION_COUNT, ' closure calls');
for ($i = 0; $i < REPETITION_COUNT; $i++) {
    ($t->closure)();
}
$timer();

$timer = $b->measure(REPETITION_COUNT, ' closure with arg calls');
for ($i = 0; $i < REPETITION_COUNT; $i++) {
    ($t->closureWithArg)($i);
}
$timer();

$timer = $b->measure(REPETITION_COUNT, ' closure with if statement');
for ($i = 0; $i < REPETITION_COUNT; $i++) {
    ($t->closureWithIfStatement)($i);
}
$timer();

$timer = $b->measure(REPETITION_COUNT, ' cached closure calls');
$cachedClosure = \Closure::fromCallable($t->closure);
for ($i = 0; $i < REPETITION_COUNT; $i++) {
    $cachedClosure();
}
$timer();

$timer = $b->measure(REPETITION_COUNT, ' referenced closure calls');
$cachedClosure = $t->closure;
for ($i = 0; $i < REPETITION_COUNT; $i++) {
    $cachedClosure();
}
$timer();

$timer = $b->measure(REPETITION_COUNT, ' method calls');
for ($i = 0; $i < REPETITION_COUNT; $i++) {
    $t->method();
}
$timer();

$timer = $b->measure(REPETITION_COUNT, ' method calls with if statement');
for ($i = 0; $i < REPETITION_COUNT; $i++) {
    $t->methodWithIfStatement($i);
}
$timer();
