<?php
$re = '/(?<Rule>[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*(?=\s*(=|<-)))
    |(?<Suffix>(?<!\s)[+*?i])
    |(?<Identifier>[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*|\$|\^|\.)
    |(?<String>"(\\\\\\\\|\\\\"|[^"])*"|\'(\\\\\\\\|\\\\\'|[^\'])*\')
    |(?<Regex>\/(\\\\\\\\|\\\\\/|[^\/ ])*\/|\[(\\\\\\\\|\\\\]|[^]])+])
    |(?<Comment>\/\/[^\n]*|\/\*((?&Comment)|.)*?\*\/)
    |(?<Or>\/)
    |(?<Arrow>=|<-)
    |(?<Prefix>[!&])
    |(?<Sequence>\((?R)+\))
    |(?<Whitespace>\s+)
    /xm';

$str = <<<'STR'
S? '"' '\\' / '\"' / . * '"' S?
STR;

preg_match_all($re, $str, $matches, PREG_SET_ORDER, 0);

echo $str."\n";

foreach ($matches as $match) {
    echo "|".$match[0]."|\n";
}
