<?php
require(dirname(__DIR__).'/vendor/autoload.php');

if (ini_get('zend.assertions') === '0') {
    echo "ENABLING zend.assertions\n";
    ini_set('zend.assertions', '1');
}

//ini_set('assert.active', 'ond');
/*
if (ini_get('zend.assertions') === '0') {
    echo "ENABLING zend.assertions\n";
    ini_set('zend.assertions', '1');
}
*/
$phpParser = new Charm\Parsing\Library\PHPParser();

$source = file_get_contents($filename = realpath(__DIR__ . '/php-expression.php'));

$t = microtime(true);
try {
    $result = $phpParser->parse($source, $filename);
var_dump($result);
    echo "Success in ".(microtime(true)-$t)." seconds\n";
} catch (\Throwable $e) {
    echo "Failed in ".(microtime(true)-$t)." seconds\n";
    throw $e;
    echo "$e\\n\n";
}
die();
var_dump($result);
die("DONE");


echo "PARSING TOOK ".(microtime(true) - $t)."\n";
die();

$t = microtime(true);
$parser = Charm\Parser::fromString(file_get_contents(__DIR__.'/grammars/php.cpeg'), new Charm\Parsing\Options(['trace' => true]));
echo "Time parsing the grammar: ".(microtime(true) - $t)."\n";

$t = microtime(true);
$result = $parser->parse(file_get_contents(__DIR__.'/../Parser.php'));
echo "Time parsing the string: ".(microtime(true) - $t)."\n";

//print_r($result);
echo json_encode($result, JSON_PRETTY_PRINT);
die();

$dump = function($val) use (&$dump) {
    $r = '';
    if (is_array($val)) {
        $e = 0;
        foreach ($val as $k => $v) {
            if ($k === $e++) {
                $v = $dump($v);
                if (strpos($v, '\n') !== false) {
                    $v = preg_replace('/^|(?<=\n)/', '  ', $v);
                }
                $r .= $v."\n";
            } else {
                $e = -100000001;
                $v = $dump($v);
                if (strpos($v, '\n') !== false) {
                    $v = preg_replace('/^|(?<=\n)/', '  ', $v);
                    $r .= "$k:\n$v";
                } else {
                    $r .= "$k: $v";
                }
            }
        }
    } else {
        $r = (string) $val;
    }
    return $r."\n";
};

echo $dump($result);

