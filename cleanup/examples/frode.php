<?php
$stack = new class implements \ArrayAccess {
    public function __construct() {
        $this->arr = [];
    }
    public function offsetSet($o, $v) {
        echo "- set stack $o = $v\n";
        $this->arr[$o] = $v;
    }
    public function offsetGet($o) {
        echo "- get stack $o (".($this->arr[$o]??"NULL").")\n";
        return $this->arr[$o] ?? null;
    }
    public function offsetUnset($o) {
    }
    public function offsetExists($o) {
    }
};
$so = 0;
$stack[$so++] = 1; // 1
$stack[$so++] = 2; // 2
$stack[$so-=2] = $stack[$so] * $stack[++$so]; // 1 * 2

var_dump($stack, $so);die();

$stack[$so++] = 3; // 3
$stack[$so++] = 4; // 4
$stack[$so-=2] = $stack[$so-2] * $stack[$so-1]; // 3 * 4
$stack[$so-=2] = $stack[$so-2] + $stack[$so-1]; // 2 + 12
$stack[$so++] = 5; // 5
$stack[$so++] = 4; // 4
$stack[$so-=2] = $stack[$so-2] / $stack[$so-1]; // 5 / 4
$stack[$so++] = 3; // 3
$stack[$so-=2] = $stack[$so-2] / $stack[$so-1]; // 1.25 / 3
$stack[$so-=2] = $stack[$so-2] - $stack[$so-1]; // 14 - 0.41666666666667

