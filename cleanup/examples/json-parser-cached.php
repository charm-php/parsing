<?php
require(dirname(__DIR__).'/vendor/autoload.php');

$t = microtime(true);
$parser = require(__DIR__.'/cached/json-parser.php');

echo "Time parsing the grammar: ".(microtime(true) - $t)."\n";

$t = microtime(true);
$result = $parser->parse('[{},{"F\"rode":"Borli","ball":"333","byu":123}, 1,2,[1,23,true,false],null]');
echo "Time parsing the string: ".(microtime(true) - $t)."\n";

var_dump($result);

