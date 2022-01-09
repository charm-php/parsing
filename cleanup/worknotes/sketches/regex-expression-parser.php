<?php
$re = '/(?<INPUT>(?&ASSIGNMENT))
(
  (?<ASSIGNMENT>(?&VARIABLE) =\s* (?&EXPRESSION))
  (?<EXPRESSION>(?&TERM) ([+-]\s* (?&TERM))* )
  (?<TERM>(?&FACTOR) ([*\/%^]\s*(?&FACTOR))* )
  (?<FACTOR>(?&NUMBER)|(?&VARIABLE)|\(\s* \-?\s* (?&EXPRESSION) \)\s*)
  (?<VARIABLE>[a-zA-Z][a-zA-Z0-9]*\s*)
  (?<NUMBER>([1-9][0-9]*|[0-9])\s*)
){0}/mxsJ';


$str = 'a=b*((c^123)-d)+d
a=1+2+3+(4*(5234))';

preg_match_all($re, $str, $matches, PREG_PATTERN_ORDER, 0);

// Print the entire match result
var_dump($matches);
