<?php
require('vendor/autoload.php');

use function Charm\cpeg_parse;

$expression = '
    Value               =   Number | String | "true" | "false" | "null" | Object | Array
    Array "array"       =   "[" (Value ("," Value)*)? "]"
    Object "object"     =   "{" (String ":" Value ("," String ":" Value)*)? "}"
    Number              =   /(0|[1-9][0-9]*)(\.[0-9]+)?/
    String              =   /"(\\[\\"]|[^"])*"/
';

$result = cpeg_parse($expression, '[1,2,"A string",{"key":[3,4]}]');

var_dump($result);
