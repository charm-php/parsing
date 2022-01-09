<?php

$src = <<<'PHP'
    text here
    <?php
    Hei;
    Hei;
    PHP;

$r = preg_match('/((?!<\?php)[\s\S])*/uA', $src, $matches, 0);
var_dump($r);

var_dump($matches);
