<?php
namespace Charm\Parsing\Util;

class Benchmark {

    protected $testee;
    protected $statistics = [];

    public function __construct(string $testee, bool $output=true) {
        $this->testee = $testee;
        $this->output = $output;
    }

    public function measure(int $repetitionCount, string $name): callable {
        if (!key_exists($name, $this->statistics)) {
            $this->statistics[$name] = [
                'total_time' => 0.0,
                'invocations' => [],
            ];
        }
        $invocationId = count($this->statistics[$name]['invocations']);
        $this->statistics[$name]['invocations'][$invocationId]['start_time'] = microtime(true);
        return function() use ($name, $invocationId, $repetitionCount) {
            $this->statistics[$name]['invocations'][$invocationId]['end_time'] = microtime(true);
            $timeSpent =
                $this->statistics[$name]['invocations'][$invocationId]['end_time'] -
                $this->statistics[$name]['invocations'][$invocationId]['start_time'];

            $this->statistics[$name]['total_time'] += $timeSpent;
            $opsPerSec = 1 / ($this->statistics[$name]['total_time'] / $repetitionCount);
            $this->output(
                self::formatString($this->testee, 20),
                self::formatInt($repetitionCount, 9, ' times'),
                self::formatString($name),
                self::formatFloat($timeSpent, 5, ' sec'),
                self::formatInt($opsPerSec, 9, ' per sec')
            );
        };
    }

    private function output(string ...$cols) {
        if (!$this->output) {
            return;
        }
        $line = '| ';
        foreach ($cols as $col) {
            $line .= $col." | ";
        }
        echo $line."\n";
    }

    private static function formatFloat(float $time, int $decimals=5, string $unit=''): string {
        return str_pad(number_format($time, $decimals, '.', '').$unit, $decimals+4, ' ', STR_PAD_LEFT);
    }

    private static function formatInt(int $count, int $width=9, string $unit=''): string {
        return str_pad((string) $count, $width-1, ' ', STR_PAD_LEFT).$unit;
    }

    private static function formatString(string $name, int $length=40): string {
        if (mb_strlen($name) > $length - 1) {
            $name = mb_substr($name, 0, $length - 4)."...";
        }
        return str_pad($name, $length);
    }
}
