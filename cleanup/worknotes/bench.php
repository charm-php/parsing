<?php
// Generator vs new hit objects

class Hit {
    public $a, $b, $c, $d;
    public function __construct($a, $b, $c, $d) {
        $this->a = $a;
        $this->b = $b;
        $this->c = $c;
        $this->d = $d;
    }
}

function makeHit() {
    return new Hit("123", 0, 1, 2);
}

function generateHit() {
    while(true) {
        yield "123";
    }
}


$t = microtime(true);
for ($i = 0; $i < 1000000; $i++) {
    $hit = makeHit();
}
echo "Construct took ".(microtime(true)-$t)."s\n";

$t = microtime(true);
$g = generateHit();
for ($i = 0; $i < 1000000; $i++) {
    if ($g->valid()) {
        $hit = $g->current();
        $g->next();
    }
}
echo "Generator took ".(microtime(true)-$t)."s\n";
