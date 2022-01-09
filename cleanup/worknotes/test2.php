<?php
require('vendor/autoload.php');
class_exists(Charm\Parser::class);
class_exists(Charm\Parsing\Grammar::class);
$t = microtime(true);


$parser = Charm\Parser::fromString(<<<'PEG'
    JSON:
        value:Value
    Value:
        String
    String = '"' a_char:( "\\" / '\"' / [^"] )* '"'
    _ = /\s*/
    PEG);

$grammarTime = floor(10000 * (microtime(true) - $t)) / 10;


$t = microtime(true);
$ast = $parser->parse('"Frode"');
$parsingTime = floor(10000*(microtime(true) - $t)) / 10;

if ($ast === null) {
    echo "Parsing error\n";
} else {
    var_dump($ast);
}
echo "Time parsing the grammar: ".$grammarTime." ms\n";
echo "Time parsing source: ".$parsingTime." ms\n";

