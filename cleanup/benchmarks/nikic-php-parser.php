<?php
require("../vendor/autoload.php");

use PhpParser\ParserFactory;
use PhpParser\NodeDumper;
use Charm\Parsing\Util\Benchmark;

$nodeDumper = new NodeDumper();

$nikic = new Benchmark("nikic/php-parser");
$charm = new Benchmark("charm/php-parser");

$done = $nikic->measure(1, 'First time construction');
$nikicParser = (new ParserFactory)->create(ParserFactory::ONLY_PHP7);
$done();

$done = $nikic->measure(1000, 'Consecutive construction');
for($i = 0; $i < 1000; $i++) {
    $nikicParser = (new ParserFactory)->create(ParserFactory::ONLY_PHP7);
}
$done();

$done = $charm->measure(1, 'First time construction');
$charmParser = new Charm\Parsing\Library\ExpressionParser();
$done();

$done = $charm->measure(1000, 'Consecutive construction');
for($i = 0; $i < 1000; $i++) {
    $charmParser = new Charm\Parsing\Library\ExpressionParser();
}
$done();



$expression = '1*2**3^4>=5+6-7-8/9';

$code = '<?php '.$expression.';';
$nast = $nikicParser->parse($code);
$cast = $charmParser->parse($expression, __FILE__);

/*
echo $nodeDumper->dump($nast);
echo json_encode($cast, JSON_PRETTY_PRINT);
*/

