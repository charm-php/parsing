<?php
require(dirname(__DIR__).'/vendor/autoload.php');

$t = microtime(true);

$parser = Charm\Parser::fromString(<<<'JSONGrammar'
JSON
    =   Number
    |   String
    |   "null" _                                {{ return null; }}
    |   "true" _                                {{ return true; }}
    |   "false" _                               {{ return false; }}
    |   Object
    |   Array

Object
    =   '{' _ Members? '}' _
Array
    =   '[' _ List? ']' _
Members
    =   Member (',' Member)*
Member
    =   Key ':' _ JSON
Key
    =   String | Number
List
    =   JSON (',' JSON)*
Number
    =   /(0|[1-9][0-9]*)(\.[0-9]+)?/ _          {{ return floatval($_0); }}
String
    =   '"' ( '\\' | '\"' | !'"' . ) '"' _      {{ return implode("", $context); }}
_
    = /\s*/                                 {{ return ignore(); }}
JSONGrammar);

echo "Time parsing the grammar: ".(microtime(true) - $t)."\n";

file_put_contents(__DIR__.'/cached/json-parser.php', '<?php return unserialize('.var_export(serialize($parser), true).');');

$t = microtime(true);
$result = $parser->parse('[{},{"F\"rode":"Borli","ball":"333","byu":123}, 1,2,[1,23,true,false],null]');
echo "Time parsing the string: ".(microtime(true) - $t)."\n";

var_dump($result);

