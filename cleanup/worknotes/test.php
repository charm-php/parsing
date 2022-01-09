<?php
require('vendor/autoload.php');
class_exists(Charm\Parser::class);
class_exists(Charm\Parsing\Grammar::class);
$t = microtime(true);


$parser = Charm\Parser::fromString(<<<'PEG'
    JSON:
        value:Value
    Value:
        Number /
        Array
    Array = "[" _ (JSON (_ "," _ JSON)* )? _ "]"
    Number = int:([1-9] [0-9]* / [0-9]) dec:("." [0-9]+)? { var_dump($int, $dec); }
    _ = (" " / "\t" / "\n" / "\r")*
    PEG);

$grammarTime = floor(10000 * (microtime(true) - $t)) / 10;


$t = microtime(true);
$ast = $parser->parse('[12345.03123, [1,2,3]]');
$ast = $parser->parse('[12345.03123, [1,2,3]]');
$ast = $parser->parse('[12345.03123, [1,2,3]]');
$ast = $parser->parse('[12345.03123, [1,2,3]]');
$ast = $parser->parse('[12345.03123, [1,2,3]]');
$ast = $parser->parse('[12345.03123, [1,2,3]]');
$ast = $parser->parse('[12345.03123, [1,2,3]]');
$ast = $parser->parse('[12345.03123, [1,2,3]]');
$ast = $parser->parse('[12345.03123, [1,2,3]]');
$ast = $parser->parse('[12345.03123, [1,2,3]]');
$parsingTime = floor(10000*(microtime(true) - $t)) / 10;

if ($ast === null) {
    echo "UNABLE TO PARSE\n";
} else {
    echo json_encode($ast, JSON_PRETTY_PRINT);
}
echo "Time parsing the grammar: ".$grammarTime." ms\n";
echo "Time parsing source: ".$parsingTime." ms\n";

