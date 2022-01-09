<?php
function t() {

    yield 1;
    yield 2;
    return true;

}

$g = t();
foreach ($g as $v) {
    echo "- ".$v."\n";
}
var_dump($g->getReturn());

die("OK");
